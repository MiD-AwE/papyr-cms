<?php
declare(strict_types=1);

namespace Papyr\Core;

/*
 * One read-only picture of the current request (ADR-002 Phase C, §5.1).
 *
 * nav.php's boot half builds it once and registers it with Context::boot();
 * everything that renders — papyr_nav(), the theme panel, the comments
 * partial, and (Phase D) plugins — reads it through papyr(). Static state, so
 * it works however nav.php was required (file scope or inside a function),
 * which loose globals did not.
 *
 *   $p->site   key, label, basePath, url, engineVersion, invites
 *   $p->page   key, path, title, draft, cmsManaged, modified, theme{values, source}
 *   $p->tree   pages (flat manifest rows), nested (NavBuilder tree) — drafts only for admin
 *   $p->user   role ('visitor'|'member'|'admin'), displayName, csrf ('' for visitors)
 *   $p->theme  tokens (component token names), defaults, swatches
 *   $p->ai     ['local' => bool, 'cloud' => bool] — a provider is configured (no pings)
 *
 *   $p->url($rel)              base-path-safe link
 *   $p->asset($plugin, $file)  URL of a file inside a plugin folder (Phase D)
 *   $p->provide($hook, $fn)    register a provider, e.g. 'map.nodes' (Phase D)
 *   $p->providers($hook, $args) collect every provider's output, in registration order
 *   $p->pageTheme($relPath)    another page's theme {values, source} (read-only)
 *
 * Read-only: readonly properties, arrays handed out by value, no setters, no
 * write capability. Never holds secrets, hashes, filesystem paths, AI
 * hosts/ports/models, or raw session contents.
 *
 * Deprecated flat aliases (0.2.x, remove in 0.3): basePath, label, pageKey,
 * activePath, role, csrf, inviteReady. They mirror the groups above. The
 * earlier flat `theme` (saved values) and `tree` (nested only) were
 * superseded by the groups of the same name: use page['theme']['values']
 * and tree['nested'].
 */
final class Context
{
    private static ?self $current = null;

    /** @var array<string, list<callable>> hook => providers (Phase D) */
    private static array $providers = [];

    /** @deprecated 0.2.x — use site['basePath'] */
    public readonly string $basePath;
    /** @deprecated 0.2.x — use site['label'] */
    public readonly string $label;
    /** @deprecated 0.2.x — use page['key'] */
    public readonly string $pageKey;
    /** @deprecated 0.2.x — use page['path'] */
    public readonly string $activePath;
    /** @deprecated 0.2.x — use user['role'] */
    public readonly string $role;
    /** @deprecated 0.2.x — use user['csrf'] */
    public readonly string $csrf;
    /** @deprecated 0.2.x — use site['invites'] */
    public readonly bool $inviteReady;

    public function __construct(
        public readonly bool  $ready,
        public readonly array $site,
        public readonly array $page,
        public readonly array $tree,
        public readonly array $user,
        public readonly array $theme,
        public readonly array $ai,
        private readonly string $root = '',   // site root — engine-internal, never exposed
    ) {
        $this->basePath    = (string) ($site['basePath'] ?? '');
        $this->label       = (string) ($site['label'] ?? '');
        $this->pageKey     = (string) ($page['key'] ?? '');
        $this->activePath  = (string) ($page['path'] ?? '');
        $this->role        = (string) ($user['role'] ?? 'visitor');
        $this->csrf        = (string) ($user['csrf'] ?? '');
        $this->inviteReady = (bool) ($site['invites'] ?? false);
    }

    public static function boot(self $ctx): void
    {
        self::$current = $ctx;
    }

    /** The booted context, or an inert signed-out one if nav.php never booted. */
    public static function current(): self
    {
        return self::$current ?? new self(
            false,
            ['key' => '', 'label' => '', 'basePath' => '', 'url' => '', 'engineVersion' => Version::ENGINE, 'invites' => false],
            ['key' => '', 'path' => '', 'title' => '', 'draft' => false, 'cmsManaged' => false, 'modified' => 0,
             'theme' => ['values' => [], 'source' => 'default']],
            ['pages' => [], 'nested' => []],
            ['role' => 'visitor', 'displayName' => '', 'csrf' => ''],
            ['tokens' => [], 'defaults' => [], 'swatches' => []],
            ['local' => false, 'cloud' => false],
        );
    }

    public function isAdmin(): bool
    {
        return $this->user['role'] === 'admin';
    }

    /** Admins count as members (they can read and write comments). */
    public function isMember(): bool
    {
        return in_array($this->user['role'], ['member', 'admin'], true);
    }

    /** Base-path-safe link to a path relative to the site root. */
    public function url(string $relPath = ''): string
    {
        return $this->site['basePath'] . '/' . ltrim($relPath, '/');
    }

    /**
     * URL of a file inside a plugin folder, e.g. asset('site-map', 'three/three.min.js').
     * Resolves the site copy before the core copy, like the loader. '' when the
     * plugin is unusable or the file name is unsafe.
     */
    public function asset(string $plugin, string $file): string
    {
        $segments = explode('/', $file);
        foreach ($segments as $seg) {
            if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $seg) !== 1 || $seg === '..') {
                return '';
            }
        }
        $found = Plugins::find($plugin);
        if ($found === null) {
            return '';
        }
        return $this->site['basePath'] . $found['urlPrefix'] . '/' . implode('/', array_map('rawurlencode', $segments));
    }

    /** Register a provider for a hook (ADR-002 §6.6). Hook names: ^[a-z0-9.-]{1,60}$. */
    public function provide(string $hook, callable $fn): void
    {
        if (preg_match('/^[a-z0-9.-]{1,60}$/', $hook) !== 1) {
            error_log("[PapyrCMS] provide(): invalid hook name '{$hook}' ignored.");
            return;
        }
        self::$providers[$hook][] = $fn;
    }

    /**
     * Call every provider for $hook as fn(Context $p, array $args) and return
     * their results in registration order. A provider that throws is logged
     * and skipped — one broken plugin never takes a page down.
     */
    public function providers(string $hook, array $args = []): array
    {
        $out = [];
        foreach (self::$providers[$hook] ?? [] as $i => $fn) {
            try {
                $out[] = $fn($this, $args);
            } catch (\Throwable $e) {
                error_log("[PapyrCMS] provider #{$i} for '{$hook}' failed: " . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Theme of another page on this site — what it renders with: its saved
     * values over the defaults. {values, source: 'page'|'default', saved}
     * where `saved` is exactly what the page's theme file holds ([] if none).
     */
    public function pageTheme(string $relPath): array
    {
        $defaults = $this->theme['defaults'];
        $relPath  = ltrim($relPath, '/');
        if ($this->root === '' || $relPath === '' || str_contains($relPath, '..') || !function_exists('papyr_load_theme')) {
            return ['values' => $defaults, 'source' => 'default', 'saved' => []];
        }
        $saved = papyr_load_theme($this->root . '/' . $relPath, $this->root);
        return ['values' => array_merge($defaults, $saved), 'source' => $saved === [] ? 'default' : 'page', 'saved' => $saved];
    }

    /** Engine endpoint URLs — the same map window.PAPYR.api carries. */
    public function api(): array
    {
        $b = $this->site['basePath'] . '/_papyr/api/';
        return [
            'theme'    => $b . 'save-theme.php',
            'unlock'   => $b . 'unlock.php',
            'comments' => $b . 'comments.php',
            'pages'    => $b . 'pages.php',      // Phase E — not built yet
        ];
    }

    /** The browser-side mirror (§5.2). Only what the page's own scripts need. */
    public function browser(): array
    {
        return [
            'basePath' => $this->site['basePath'],
            'pageKey'  => $this->page['key'],
            'role'     => $this->user['role'],
            'csrf'     => $this->user['csrf'],
            'api'      => $this->api(),
        ];
    }
}
