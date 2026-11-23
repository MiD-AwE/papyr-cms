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
     * Render the site template with $vars and write the result to
     * $siteRoot/$relPath. Creates intermediate directories as needed.
     * Forces a manifest re-scan so Scanner can see the new file, then
     * calls markManaged() to flag it as CMS-owned.
     *
     * Returns the absolute path of the written file.
     * Throws \InvalidArgumentException on unsafe path.
     * Throws \RuntimeException on write failure.
     */
    public function write(string $relPath, array $vars): string
    {
        $this->guardPath($relPath);

        $output  = $this->engine->render($this->template, $vars);
        $absPath = $this->siteRoot . '/' . $relPath;

        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \RuntimeException("PageWriter: could not create directory {$dir}");
            }
        }

        if (file_put_contents($absPath, $output) === false) {
            throw new \RuntimeException("PageWriter: write failed for {$absPath}");
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

        if (!unlink($absPath)) {
            throw new \RuntimeException("PageWriter: delete failed for {$absPath}");
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
