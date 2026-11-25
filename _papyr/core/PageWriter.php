<?php
declare(strict_types=1);

namespace Papyr\Core;

class PageWriter
{
    /** Exception codes callers may map to HTTP statuses (pages.php does). */
    public const ERR_EXISTS    = 409;   // create: the path is already taken
    public const ERR_NOT_FOUND = 404;   // setDraft / trash: no such page
    public const ERR_REFUSED   = 422;   // the write would be unsafe or cannot be placed

    /** The marker Scanner looks for in a page's first 4 KB. */
    public const DRAFT_MARKER = '<!-- papyr:draft -->';
    private const DRAFT_RE    = '/<!--\s*papyr:draft\s*-->/i';
    private const SCAN_BYTES  = 4096;   // Scanner::TITLE_READ_BYTES

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
     * Return the variable names the current template expects as form inputs.
     * Excludes Twig built-ins and variables set internally via {% set %}.
     * Used by the Add Page form to auto-generate one field per variable.
     */
    public function getTemplateVarNames(): array
    {
        return $this->engine->extractVarNames($this->template);
    }

    /*
     * Create a new page from web-submitted values (ADR-002 §7.1, E2 decisions).
     *
     * - Never overwrites: if anything exists at $relPath, throws with code
     *   ERR_EXISTS and touches nothing. The file is opened with mode "x", so
     *   two requests racing for one path cannot both win.
     * - Never produces executable PHP from a field: every string value has
     *   "<?" neutralised to "&lt;?" before rendering, and the output may hold
     *   no more "<?" than the template renders with every field empty
     *   (ERR_REFUSED otherwise). Raw PHP stays a manual-edit feature.
     * - Only .php pages, through guardPath() like write().
     *
     * Returns the absolute path (never show it in the UI).
     */
    public function create(string $relPath, array $vars, ?string $template = null): string
    {
        $this->guardPath($relPath);
        if (!str_ends_with(strtolower($relPath), '.php')) {
            throw new \InvalidArgumentException("PageWriter: a new page must end in .php — {$relPath}");
        }
        $absPath = $this->siteRoot . '/' . $relPath;
        if (file_exists($absPath) || is_link($absPath)) {
            $this->fail("{$relPath} already exists", $absPath, null, self::ERR_EXISTS);
        }

        $name = $template ?? $this->template;
        $vars = self::neutralize($vars);
        $vars['root'] = self::rootPrefix($relPath);
        try {
            $output   = $this->engine->render($name, $vars);
            $baseline = $this->engine->render($name, self::blank($vars));
        } catch (\RuntimeException $e) {
            $this->fail("could not render template {$name}", $e->getMessage(), $e);
        }
        if (substr_count($output, '<?') > substr_count($baseline, '<?')) {
            $this->fail('the page would contain PHP from a form field; refused', "{$relPath} via {$name}", null, self::ERR_REFUSED);
        }

        $dir = dirname($absPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('could not create folder ' . $this->rel($dir), $dir);
        }
        $fh = @fopen($absPath, 'x');
        if ($fh === false) {
            if (file_exists($absPath)) {
                $this->fail("{$relPath} already exists", $absPath, null, self::ERR_EXISTS);
            }
            $this->fail("could not write {$relPath}", $absPath);
        }
        $ok = fwrite($fh, $output) === strlen($output);
        fclose($fh);
        if (!$ok) {
            @unlink($absPath);
            $this->fail("could not write {$relPath}", $absPath);
        }

        $this->scanner->getPages($this->siteKey, true);
        $this->scanner->markManaged($this->siteKey, $relPath);
        return $absPath;
    }

    /** "<?" → "&lt;?" in every string, recursively; other scalars untouched. */
    public static function neutralize(array $vars): array
    {
        foreach ($vars as $k => $v) {
            if (is_string($v)) {
                $vars[$k] = str_replace('<?', '&lt;?', $v);
            } elseif (is_array($v)) {
                $vars[$k] = self::neutralize($v);
            }
        }
        return $vars;
    }

    /** Same keys, every string emptied: the template's own output, for the PHP-tag baseline. */
    private static function blank(array $vars): array
    {
        foreach ($vars as $k => $v) {
            if ($k === 'root') {
                continue;
            }
            if (is_string($v)) {
                $vars[$k] = '';
            } elseif (is_array($v)) {
                $vars[$k] = self::blank($v);
            }
        }
        return $vars;
    }

    /*
     * Promote ($draft = false) or demote ($draft = true) a page by editing
     * only its draft marker (ADR-002 §7.1). Works on any page, hand-made or not.
     *
     * Demote puts the marker right after <body>, else after <head>, else after
     * the page's opening PHP block, else at the top of a file with no leading
     * PHP; the spot must lie in the first 4 KB, where Scanner looks
     * (ERR_REFUSED otherwise). Promote removes every marker (a marker on its
     * own line takes the line with it).
     *
     * The page's "manually edited" flag is kept as it was: this edit is
     * Papyr's own, not a hand edit. Returns false when nothing needed to change.
     */
    public function setDraft(string $relPath, bool $draft): bool
    {
        $this->guardPath($relPath);
        $absPath = $this->siteRoot . '/' . $relPath;
        if (!is_file($absPath)) {
            $this->fail("{$relPath} not found", $absPath, null, self::ERR_NOT_FOUND);
        }
        $before = $this->scanner->getPages($this->siteKey, true)[$relPath] ?? [];
        $src = @file_get_contents($absPath);
        if ($src === false) {
            $this->fail("could not read {$relPath}", $absPath);
        }
        $isDraft = (bool) preg_match(self::DRAFT_RE, substr($src, 0, self::SCAN_BYTES));

        if ($draft) {
            if ($isDraft) {
                return false;
            }
            $new = self::insertMarker($src);
            if ($new === null) {
                $this->fail("no place for the draft marker in the first 4 KB of {$relPath}; add " . self::DRAFT_MARKER . ' by hand', $absPath, null, self::ERR_REFUSED);
            }
        } else {
            if (!preg_match(self::DRAFT_RE, $src)) {
                return false;
            }
            $new = (string) preg_replace('/^[ \t]*<!--\s*papyr:draft\s*-->[ \t]*\R/mi', '', $src);
            $new = (string) preg_replace(self::DRAFT_RE, '', $new);
        }

        if (@file_put_contents($absPath, $new, LOCK_EX) === false) {
            $this->fail("could not write {$relPath}", $absPath);
        }
        $after = $this->scanner->getPages($this->siteKey, true)[$relPath] ?? [];
        if (!empty($after['draft']) !== $draft) {
            @file_put_contents($absPath, $src, LOCK_EX);
            $this->scanner->getPages($this->siteKey, true);
            $this->fail("the draft marker in {$relPath} was not picked up; file restored", $absPath, null, self::ERR_REFUSED);
        }
        if (!empty($before['cms_managed']) && empty($before['manually_edited'])) {
            $this->scanner->markManaged($this->siteKey, $relPath, ['manually_edited' => false]);
        }
        return true;
    }

    /** $src with the draft marker inserted where Scanner will see it, or null. */
    private static function insertMarker(string $src): ?string
    {
        $mark  = self::DRAFT_MARKER;
        $limit = self::SCAN_BYTES - strlen($mark) - 1;
        // Skip a leading PHP block: its comments may mention "<body>" (the starters' do).
        $phpEnd = null;
        if (str_starts_with(ltrim($src), '<?')) {
            $close = strpos($src, '?>');
            if ($close === false) {
                return null;   // PHP all the way down
            }
            $phpEnd = $close + 2;
        }
        foreach (['/<body\b[^>]*>/i', '/<head\b[^>]*>/i'] as $re) {
            if (preg_match($re, $src, $m, PREG_OFFSET_CAPTURE, $phpEnd ?? 0)) {
                $at = $m[0][1] + strlen($m[0][0]);
                if ($at <= $limit) {
                    return substr($src, 0, $at) . "\n" . $mark . substr($src, $at);
                }
            }
        }
        if ($phpEnd !== null) {
            return $phpEnd <= $limit ? substr($src, 0, $phpEnd) . "\n" . $mark . substr($src, $phpEnd) : null;
        }
        return $mark . "\n" . $src;
    }

    /*
     * Move a page to {siteRoot}/_trash/{YYYYmmdd-His}_{path-with-underscores}
     * (ADR-002 §7.1). Restore is by hand: move the file back. guardPath() is
     * not loosened: the source is checked as usual and the destination is
     * only ever built here. Returns the site-relative trash path.
     */
    public function trash(string $relPath): string
    {
        $this->guardPath($relPath);
        $absPath = $this->siteRoot . '/' . $relPath;
        if (!is_file($absPath)) {
            $this->fail("{$relPath} not found", $absPath, null, self::ERR_NOT_FOUND);
        }
        $dir  = $this->trashDir();
        $stem = date('Ymd-His') . '_' . str_replace('/', '_', $relPath);
        $dest = "{$dir}/{$stem}";
        for ($i = 2; file_exists($dest); $i++) {
            $dest = "{$dir}/" . preg_replace('/(\.[^.]+)$/', "-{$i}$1", $stem);
        }
        if (!@rename($absPath, $dest)) {
            $this->fail("could not move {$relPath} to the trash", "{$absPath} → {$dest}");
        }
        $this->scanner->getPages($this->siteKey, true);
        return self::TRASH_DIR . '/' . basename($dest);
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
    private function fail(string $public, string $detail, ?\Throwable $previous = null, int $code = 0): never
    {
        error_log("[PapyrCMS] PageWriter: {$public} — {$detail}");
        throw new \RuntimeException("PageWriter: {$public}", $code, $previous);
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
