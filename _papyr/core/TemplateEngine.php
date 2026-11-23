<?php
declare(strict_types=1);

namespace Papyr\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class TemplateEngine
{
    private Environment $twig;

    /** @var string[] absolute loader root paths, kept so template names can be normalized against them */
    private array $templateDirs;

    /*
     * $templateDirs — ordered list of directories Twig searches for templates.
     * Typically: ['templates/sites/{siteKey}', 'templates/partials', 'templates/cms']
     * The first directory containing the requested template wins.
     *
     * $cacheDir — set to a writable path for compiled-template caching,
     * or null to disable (safe for dev; use caching in production).
     */
    public function __construct(array $templateDirs, ?string $cacheDir = null)
    {
        $this->templateDirs = array_map(
            static fn(string $d): string => rtrim(str_replace('\\', '/', $d), '/'),
            $templateDirs
        );

        $loader = new FilesystemLoader($templateDirs);

        $this->twig = new Environment($loader, [
            'cache'       => $cacheDir ?? false,
            'auto_reload' => true,
            'debug'       => false,
        ]);
    }

    /** Site-owned template root, relative to the site root (ADR-002 §7.3). */
    public const SITE_DIR = '_templates';

    /*
     * The loader roots for a site, in lookup order:
     *   {siteRoot}/_templates/   — site-owned, survives engine upgrades (only if it exists)
     *   {engineDir}/templates/   — engine-owned, replaced with _papyr/
     * The first root holding a template name wins, so a site copy overrides
     * the engine's on a name clash. Twig refuses roots that do not exist,
     * which is why the site root is only added when the folder is there.
     *
     * @return string[]
     */
    public static function rootsFor(string $siteRoot, string $engineDir): array
    {
        $roots = [];
        $site  = self::siteDir($siteRoot);
        if ($site !== null) {
            $roots[] = $site;
        }
        $roots[] = rtrim($engineDir, '/') . '/templates';
        return $roots;
    }

    /**
     * {siteRoot}/_templates when it exists, else null. The folder is made by
     * hand, so the first time the engine finds it without a deny rule it adds
     * one for Twig sources (DenyRule); an existing .htaccess is left alone.
     */
    public static function siteDir(string $siteRoot): ?string
    {
        if ($siteRoot === '') {
            return null;
        }
        $dir = rtrim($siteRoot, '/') . '/' . self::SITE_DIR;
        if (!is_dir($dir)) {
            return null;
        }
        DenyRule::ensure($dir, 'PapyrCMS: site templates are rendered by the engine, never served as source.', '\\.twig$');
        return $dir;
    }

    /** An engine over rootsFor($siteRoot, $engineDir). */
    public static function forSite(string $siteRoot, string $engineDir, ?string $cacheDir = null): self
    {
        return new self(self::rootsFor($siteRoot, $engineDir), $cacheDir);
    }

    /*
     * Template names must be relative to a loader root, but a caller may
     * accidentally pass a name that already repeats part of that root
     * (e.g. config authored pre-migration as an engine-relative path).
     * Strip a duplicated leading segment if the loader root itself ends
     * with that same segment, so a doubled path self-corrects instead of
     * producing "looked into: .../templates" with no such template found.
     */
    private function normalize(string $template): string
    {
        $parts = explode('/', ltrim(str_replace('\\', '/', $template), '/'));

        foreach ($this->templateDirs as $dir) {
            $prefix = '';
            foreach ($parts as $i => $segment) {
                $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                if (str_ends_with($dir, '/' . $prefix) && $i + 1 < count($parts)) {
                    return implode('/', array_slice($parts, $i + 1));
                }
            }
        }

        return $template;
    }

    /*
     * Render a template and return the output as a string.
     *
     * $template — filename relative to any registered template dir, e.g. 'page.html.twig'
     * $vars     — variables injected into the template context
     *
     * Throws \RuntimeException on load, compile, or render failure.
     */
    public function render(string $template, array $vars = []): string
    {
        $template = $this->normalize($template);
        try {
            return $this->twig->render($template, $vars);
        } catch (LoaderError $e) {
            throw new \RuntimeException("TemplateEngine: template not found — {$template}: " . $e->getMessage(), 0, $e);
        } catch (SyntaxError $e) {
            throw new \RuntimeException("TemplateEngine: syntax error in {$template}: " . $e->getMessage(), 0, $e);
        } catch (RuntimeError $e) {
            throw new \RuntimeException("TemplateEngine: runtime error in {$template}: " . $e->getMessage(), 0, $e);
        }
    }

    /*
     * Return the list of variable names declared in a template via {% set %}
     * or referenced as {{ varName }} at the top level.
     *
     * Used by the Add Page form to auto-generate one input per variable.
     * Scans the raw template source with regex — intentionally simple.
     * Only top-level names (no dots) are returned; block names are excluded.
     */
    public function extractVarNames(string $template): array
    {
        $template = $this->normalize($template);
        $source = $this->twig->getLoader()->getSourceContext($template)->getCode();

        // Strip Twig comments so {# ... {{ example }} ... #} doesn't pollute results.
        $source = (string) preg_replace('/{#.*?#}/s', '', $source);

        $names = [];

        // {{ varName }} — simple variable references
        preg_match_all('/{{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:\|[^}]*)?\s*}}/', $source, $m);
        foreach ($m[1] as $name) {
            $names[$name] = true;
        }

        // {% set varName = ... %} — explicitly set variables
        preg_match_all('/{%\s*set\s+([a-zA-Z_][a-zA-Z0-9_]*)\s/', $source, $m);
        foreach ($m[1] as $name) {
            unset($names[$name]); // set internally — not a form input
        }

        // Remove Twig built-ins and block/macro names
        $builtins = ['loop', 'block', 'parent', 'attribute', 'range', 'constant',
                     'cycle', 'random', 'date', 'include', 'source'];
        foreach ($builtins as $b) {
            unset($names[$b]);
        }

        return array_keys($names);
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }
}
