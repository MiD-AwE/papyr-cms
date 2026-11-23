<?php
declare(strict_types=1);

namespace Papyr\Core;

/**
 * The one writer of Apache deny rules (.htaccess) for folders the web must
 * never serve: _config/, data/comments/, _trash/ (whole folder) and
 * _templates/ (Twig sources only).
 *
 * A rule is written only when the folder's .htaccess is missing; an existing
 * file is never touched, so a hand-edited rule always wins. Both Apache 2.4
 * (mod_authz_core) and 2.2-style (mod_access_compat) syntax are emitted.
 * Nginx ignores these files — UPGRADING.md lists the equivalent locations.
 */
final class DenyRule
{
    public const FILE = '.htaccess';

    /** Rule text: deny everything, or only files matching $filesRegex. */
    public static function body(string $comment, ?string $filesRegex = null): string
    {
        $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
        if ($filesRegex !== null) {
            $deny = "<FilesMatch \"{$filesRegex}\">\n"
                . preg_replace('/^/m', '    ', rtrim($deny)) . "\n</FilesMatch>\n";
        }
        return '# ' . $comment . "\n" . $deny;
    }

    /**
     * Ensure $dir/.htaccess exists. Does not create $dir.
     * Returns true when the file exists afterwards; a failed write is logged.
     */
    public static function ensure(string $dir, string $comment, ?string $filesRegex = null): bool
    {
        $ht = rtrim($dir, '/') . '/' . self::FILE;
        if (is_file($ht)) {
            return true;
        }
        if (!is_dir($dir) || @file_put_contents($ht, self::body($comment, $filesRegex)) === false) {
            error_log("[PapyrCMS] Could not write deny rule {$ht} — the folder may be web-readable.");
            return false;
        }
        @chmod($ht, 0644);
        return true;
    }
}
