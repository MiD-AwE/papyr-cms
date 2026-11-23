<?php
declare(strict_types=1);

namespace Papyr\Core;

class PageWriter
{
    private TemplateEngine $engine;
    private string $siteRoot;
    private string $template;

    /*
     * $siteConfig — the entry from sites.json['sites'][$siteKey]
     * $engineDir  — absolute path to the papyrCMS engine directory
     *
     * Templates are resolved under {siteRoot}/_templates/ first (site-owned),
     * then $engineDir/templates/ — see TemplateEngine::rootsFor().
     */
    public function __construct(
        private Scanner $scanner,
        private string $siteKey,
        array $siteConfig,
        string $engineDir,
    ) {
        $this->siteRoot = rtrim($siteConfig['root'] ?? '', '/');
        $this->template = $siteConfig['template'] ?? 'page.html.twig';

        $this->engine = TemplateEngine::forSite($this->siteRoot, $engineDir);
    }

    /*
     * Render a template with $vars and write the result to $siteRoot/$relPath.
     * $template defaults to the site's configured template; pass a starter's
     * template (Starters::get()['template']) to render that instead.
     * Creates intermediate directories as needed. Forces a manifest re-scan
     * so Scanner can see the new file, then calls markManaged() to flag it
     * as CMS-owned.
     *
     * The template also receives `root`: the relative prefix from the new
     * file back to the site root ("" at the top level, "../" one folder
     * down), so links written into the page work on any base path, with or
     * without the engine.
     *
     * Returns the absolute path of the written file (for the caller's own
     * use; never show it in the UI).
     * Throws \InvalidArgumentException on unsafe path.
     * Throws \RuntimeException on render or write failure; its message holds
     * only site-relative paths, and the full path is logged (see fail()).
     */
    public function write(string $relPath, array $vars, ?string $template = null): string
    {
        $this->guardPath($relPath);

        $vars['root'] = self::rootPrefix($relPath);
        $name = $template ?? $this->template;
        try {
            $output = $this->engine->render($name, $vars);
        } catch (\RuntimeException $e) {
            // Twig's messages can name the loader's absolute directories.
            $this->fail("could not render template {$name}", $e->getMessage(), $e);
        }
        $absPath = $this->siteRoot . '/' . $relPath;

        $dir = dirname($absPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('could not create folder ' . $this->rel($dir), $dir);
        }

        if (@file_put_contents($absPath, $output) === false) {
            $this->fail("could not write {$relPath}", $absPath);
        }

        // Rescan so the manifest includes the new file, then mark it managed.
        $this->scanner->getPages($this->siteKey, true);
        $this->scanner->markManaged($this->siteKey, $relPath);

        return $absPath;
    }

    /*
     * Delete a CMS-managed page and force the manifest to update.
     * Returns false if the file does not exist (already gone — not an error).
     * Throws \RuntimeException if the file exists but cannot be deleted.
     */
    public function delete(string $relPath): bool
    {
        $this->guardPath($relPath);

        $absPath = $this->siteRoot . '/' . $relPath;

        if (!file_exists($absPath)) {
            return false;
        }

        if (!@unlink($absPath)) {
            $this->fail("could not delete {$relPath}", $absPath);
        }

        // Rescan so the manifest reflects the deletion.
        $this->scanner->getPages($this->siteKey, true);

        return true;
    }

    /*
     * Return the variable names the current template expects as form inputs.
     * Excludes Twig built-ins and variables set internally via {% set %}.
     * Used by the Add Page form to auto-generate one field per variable.
     */
    public function getTemplateVarNames(): array
    {
        return $this->engine->extractVarNames($this->template);
    }

    /** Site-owned trash folder (ADR-002 §7.1): survives engine upgrades, never served. */
    public const TRASH_DIR = '_trash';

    /**
     * {siteRoot}/_trash, created with its deny rule if missing. PageWriter's
     * trash action (E2) moves pages here; restore is by hand.
     *
     * @throws \RuntimeException the folder or its deny rule cannot be written
     */
    public function trashDir(): string
    {
        $dir = $this->siteRoot . '/' . self::TRASH_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('could not create ' . self::TRASH_DIR . '/', $dir);
        }
        if (!DenyRule::ensure($dir, 'PapyrCMS trash: restore by moving a file back by hand. Never served.')) {
            $this->fail('could not protect ' . self::TRASH_DIR . '/', $dir);
        }
        return $dir;
    }

    /**
     * Throw a RuntimeException whose message is safe to show in the UI (site-
     * relative paths only) and log the full detail, absolute paths included.
     * Every PageWriter failure goes through here, so callers may display
     * getMessage() as is.
     */
    private function fail(string $public, string $detail, ?\Throwable $previous = null): never
    {
        error_log("[PapyrCMS] PageWriter: {$public} — {$detail}");
        throw new \RuntimeException("PageWriter: {$public}", 0, $previous);
    }

    /** $abs relative to the site root ("." for the root itself); never an absolute path. */
    private function rel(string $abs): string
    {
        $root = rtrim($this->siteRoot, '/');
        if ($abs === $root) {
            return '.';
        }
        return str_starts_with($abs, $root . '/') ? substr($abs, strlen($root) + 1) : basename($abs);
    }

    /** "" for "page.php", "../" for "docs/page.php", "../../" for "a/b/page.php". */
    public static function rootPrefix(string $relPath): string
    {
        return str_repeat('../', substr_count(trim($relPath, '/'), '/'));
    }

    /*
     * Throws \InvalidArgumentException if $relPath is unsafe.
     *
     * Blocked: empty paths, directory traversal (..), absolute paths (/...),
     * null bytes, and any path component that starts with _ (sealed engine
     * directories). The Scanner ignores _-prefixed dirs; PageWriter must refuse
     * to write into them — same discipline, both halves of the boundary.
     */
    private function guardPath(string $relPath): void
    {
        if ($relPath === ''
            || str_contains($relPath, '..')
            || str_starts_with($relPath, '/')
            || str_contains($relPath, "\0")
        ) {
            throw new \InvalidArgumentException("PageWriter: unsafe path — {$relPath}");
        }

        foreach (explode('/', $relPath) as $segment) {
            if ($segment !== '' && str_starts_with($segment, '_')) {
                throw new \InvalidArgumentException(
                    "PageWriter: cannot write into sealed directory — {$relPath}"
                );
            }
        }
    }
}
