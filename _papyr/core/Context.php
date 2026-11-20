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
