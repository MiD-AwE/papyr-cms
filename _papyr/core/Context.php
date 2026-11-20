<?php
declare(strict_types=1);

namespace Papyr\Core;

/*
 * The one read-only picture of "this request" that every partial reads
 * (ADR-002 Phase C, first slice). nav.php's boot half builds it once and
 * registers it with Context::boot(); partials call papyr() instead of
 * reaching for loose $_papyr* variables.
 *
 * Why not globals: a variable set while nav.php was required inside a
 * function lives in that function's scope, and `global $x` in a partial
 * then silently reads null — which looked exactly like "signed out" and hid
 * the Access section and Commit button from an admin. Static state doesn't
 * care what scope boot ran in.
 *
 * Holds only what is safe to print: no secrets, hashes or filesystem paths
 * beyond engineDir/siteRoot (server-side use only), and no write capability.
 * The CSRF token is '' for visitors.
 */
final class Context
{
    private static ?self $current = null;

    public function __construct(
        public readonly bool   $ready,
        public readonly string $engineDir,
        public readonly string $siteRoot,
        public readonly string $basePath,      // '' on a vhost, '/papyr-cms' in a subfolder
        public readonly string $label,
        public readonly string $pageKey,
        public readonly string $activePath,    // page path relative to the site root
        public readonly string $role,          // 'visitor' | 'member' | 'admin'
        public readonly string $csrf,          // '' unless member/admin
        public readonly bool   $inviteReady,   // an invite password is configured
        public readonly array  $theme,
        public readonly array  $tree,
    ) {
    }

    public static function boot(self $ctx): void
    {
        self::$current = $ctx;
    }

    /** The booted context, or an inert signed-out one if nav.php never booted. */
    public static function current(): self
    {
        return self::$current ?? new self(false, '', '', '', '', '', '', 'visitor', '', false, [], []);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Admins count as members (they can read and write comments). */
    public function isMember(): bool
    {
        return $this->role === 'member' || $this->role === 'admin';
    }

    /** Base-path-safe link to a path relative to the site root. */
    public function url(string $relPath = ''): string
    {
        return $this->basePath . '/' . ltrim($relPath, '/');
    }
}
