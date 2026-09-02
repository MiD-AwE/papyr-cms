<?php
declare(strict_types=1);

namespace Papyr\Core;

class UrlResolver
{
    /**
     * The URL path prefix the site is currently served under, derived purely
     * from the request environment — never from config. Diffs the requested
     * script's filesystem path (SCRIPT_FILENAME) against the site root to get
     * a path relative to the root, then strips that same suffix off the
     * requested script's URL path (SCRIPT_NAME). Whatever's left is the prefix.
     *
     * Same code returns "/papyr-cms" when the site lives in a subfolder
     * (e.g. local XAMPP) and "" when a vhost roots the site at "/" (production).
     */
    public static function basePath(string $siteRoot): string
    {
        $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        $realScript = $scriptFile !== '' ? realpath($scriptFile) : false;
        $realRoot   = realpath($siteRoot);

        if ($realScript === false || $realRoot === false || $scriptName === '') {
            return '';
        }

        $realRoot   = rtrim(str_replace('\\', '/', $realRoot), '/');
        $realScript = str_replace('\\', '/', $realScript);

        $relPath = ltrim(str_replace($realRoot, '', $realScript), '/');

        if ($relPath === '' || !str_ends_with($scriptName, $relPath)) {
            return '';
        }

        $base = substr($scriptName, 0, -strlen($relPath));

        return rtrim($base, '/');
    }
}
