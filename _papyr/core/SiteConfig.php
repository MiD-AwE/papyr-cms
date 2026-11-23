<?php
declare(strict_types=1);

namespace Papyr\Core;

/*
 * Where a site's own configuration and secrets live (ADR-002 Phase B).
 *
 *   {siteRoot}/_config/   site-owned, survives engine upgrades
 *   _papyr/config/        legacy location — still read, with a one-time notice
 *
 * Resolution is per file, so a half-migrated install (sites.json moved, the
 * password hash not yet) never loses a secret and locks the admin out. A file
 * in _config/ always wins over the same file in the legacy folder. Writes
 * always go to _config/.
 *
 * Secret files are written 0640 and handed to the web server's group, so the
 * PHP process can read them while other local users cannot. 0600 would lock
 * out a web server running as a different user (XAMPP runs as `daemon`);
 * 0644 would expose the hash to every local account.
 */
final class SiteConfig
{
    public const DIR = '_config';
    public const MANAGED = ['sites.json', 'secret.php', 'invite.php'];

    /** Groups tried, in order, when nothing more specific says which one the web server runs as. */
    private const COMMON_WEB_GROUPS = ['www-data', 'apache', 'nginx', 'http', '_www', 'nobody'];

    public static function siteRoot(): string
    {
        return Session::siteRoot();
    }

    public static function dir(): string
    {
        return self::siteRoot() . '/' . self::DIR;
    }

    public static function legacyDir(): string
    {
        return dirname(__DIR__) . '/config';
    }

    /** Resolved path for reading (and the canonical path for writing when the file does not exist yet). */
    public static function path(string $name): string
    {
        $new = self::dir() . '/' . $name;
        if (is_file($new)) {
            return $new;
        }
        $old = self::legacyDir() . '/' . $name;
        if (is_file($old)) {
            self::noteLegacy($name);
            return $old;
        }
        return $new;
    }

    /** Path new files are created at. Always _config/. */
    public static function writePath(string $name): string
    {
        return self::dir() . '/' . $name;
    }

    /** Create _config/ (and its deny .htaccess) if missing. Returns notes for the CLI to print. */
    public static function ensureDir(): array
    {
        $notes = [];
        $dir = self::dir();
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("SiteConfig: could not create {$dir}");
            }
            $notes[] = "created {$dir}";
        }
        $ht = $dir . '/' . DenyRule::FILE;
        if (!is_file($ht)) {
            if (!DenyRule::ensure($dir, 'Site-owned config and secrets — never served over HTTP.')) {
                throw new \RuntimeException("SiteConfig: could not write {$ht}");
            }
            $notes[] = "wrote {$ht} (deny all)";
        }
        return $notes;
    }

    /**
     * Group the web server runs as, or null when it cannot be determined.
     * Order: explicit override → sites.json cms.web_group → XAMPP → common names.
     */
    public static function webGroup(?string $override = null): ?string
    {
        $candidates = [$override, getenv('PAPYR_WEB_GROUP') ?: null, self::configuredGroup()];
        if (str_starts_with(self::siteRoot(), '/opt/lampp/')) {
            $candidates[] = 'daemon';
        }
        foreach ($candidates as $g) {
            if (is_string($g) && $g !== '' && self::groupExists($g)) {
                return $g;
            }
        }
        foreach (self::COMMON_WEB_GROUPS as $g) {
            if (self::groupExists($g)) {
                return $g;
            }
        }
        return null;
    }

    /**
     * Write a secret file: mode 0640, group = web server's group.
     *
     * An existing file is rewritten in place so its owner/group survive (an
     * unprivileged CLI user usually cannot chgrp to the web group, but an
     * admin may have set it once). A new file is chgrp'd when allowed.
     *
     * @return array{path:string, mode:string, group:string, warnings:string[], notes:string[]}
     */
    public static function writeSecret(string $name, string $contents, ?string $groupOverride = null): array
    {
        $notes    = self::ensureDir();
        $warnings = [];
        $path     = self::writePath($name);
        $existed  = is_file($path);

        $old = umask(0027);                      // new file never starts world-readable
        $ok  = file_put_contents($path, $contents, LOCK_EX);
        umask($old);
        if ($ok === false) {
            throw new \RuntimeException("SiteConfig: could not write {$path}");
        }
        if (!@chmod($path, 0640)) {
            $warnings[] = "could not chmod 640 {$path}";
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $want = self::webGroup($groupOverride);
        $have = self::groupName((int) filegroup($path));
        if ($want === null) {
            $warnings[] = 'could not determine the web server group; pass --group=NAME or set cms.web_group in sites.json';
        } elseif ($have !== $want) {
            if (!@chgrp($path, $want)) {
                $warnings[] = "file group is '{$have}', not '{$want}' (this user may not chgrp). "
                    . "The web server may not be able to read it — run: sudo chgrp {$want} " . escapeshellarg($path);
            } else {
                $have = $want;
            }
        } elseif ($existed) {
            $notes[] = "kept existing group '{$have}'";
        }

        clearstatcache(true, $path);
        return [
            'path'     => $path,
            'mode'     => substr(sprintf('%o', fileperms($path) & 0777), -3),
            'group'    => $have,
            'warnings' => $warnings,
            'notes'    => $notes,
        ];
    }

    /**
     * Why a secret file can or cannot be used.
     * 'ok' | 'missing' | 'unreadable' (exists, PHP cannot read it — permissions) | 'invalid' (readable, no usable hash)
     */
    public static function secretState(string $name): string
    {
        $path = self::path($name);
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return 'missing';
        }
        if (!is_readable($path)) {
            return 'unreadable';
        }
        return self::readHash($path) === null ? 'invalid' : 'ok';
    }

    /** The stored hash, or null. Never throws on a broken file. */
    public static function secretHash(string $name): ?string
    {
        $path = self::path($name);
        return is_readable($path) ? self::readHash($path) : null;
    }

    /* ---------------- internals ---------------- */

    private static function readHash(string $path): ?string
    {
        // A rotated password must take effect on the very next request.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, false);
        }
        try {
            $data = require $path;
        } catch (\Throwable) {
            return null;
        }
        $hash = is_array($data) ? ($data['hash'] ?? null) : null;
        return (is_string($hash) && $hash !== '') ? $hash : null;
    }

    private static function configuredGroup(): ?string
    {
        $p = self::path('sites.json');
        $j = is_readable($p) ? json_decode((string) file_get_contents($p), true) : null;
        $g = is_array($j) ? ($j['cms']['web_group'] ?? null) : null;
        return is_string($g) ? $g : null;
    }

    private static function groupExists(string $g): bool
    {
        return function_exists('posix_getgrnam') && posix_getgrnam($g) !== false;
    }

    private static function groupName(int $gid): string
    {
        $info = function_exists('posix_getgrgid') ? posix_getgrgid($gid) : false;
        return is_array($info) ? $info['name'] : (string) $gid;
    }

    /** One deprecation notice per install, not per request: a marker in the (regenerable) cache records it. */
    private static function noteLegacy(string $name): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $marker = dirname(__DIR__) . '/cache/.legacy-config-noted';
        if (is_file($marker)) {
            return;
        }
        error_log("Papyr: reading {$name} from the legacy _papyr/config/ folder. "
            . 'Run "php _papyr/bin/migrate-config.php" to move site config to _config/ (survives engine upgrades).');
        if (is_dir(dirname($marker))) {
            @file_put_contents($marker, date('c') . "\n");
        }
    }
}
