#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Moves a site's own config out of the engine folder (ADR-002 Phase B):
 *
 *   _papyr/config/{sites.json,secret.php,invite.php}  →  {siteRoot}/_config/
 *
 * so replacing _papyr/ with a new release never wipes site config or
 * passwords. Also writes _config/.htaccess (deny all). Idempotent: run it
 * as often as you like; it never overwrites a differing file in _config/.
 * Moves keep owner, group and mode (so a hash already handed to the web
 * server's group stays readable); secret files looser than 640 are tightened.
 *
 * The site root is wherever this _papyr/ folder sits — nothing is hardcoded,
 * so the same script works on every install.
 *
 * Usage:
 *   php _papyr/bin/migrate-config.php [--dry-run]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require __DIR__ . '/../vendor/autoload.php';

use Papyr\Core\SiteConfig;

$dry = in_array('--dry-run', $argv, true);
$say = static function (string $m) use ($dry): void { fwrite(STDOUT, ($dry ? '[dry-run] ' : '') . $m . "\n"); };

$legacy = SiteConfig::legacyDir();
$target = SiteConfig::dir();
$say('Site root: ' . SiteConfig::siteRoot());

if (!$dry) {
    foreach (SiteConfig::ensureDir() as $n) { $say($n); }
} elseif (!is_dir($target)) {
    $say("would create {$target} and its deny .htaccess");
}

$moved = $skipped = $conflicts = 0;
foreach (SiteConfig::MANAGED as $name) {
    $src = $legacy . '/' . $name;
    $dst = $target . '/' . $name;

    if (!is_file($src)) {
        $say("{$name}: nothing in legacy folder" . (is_file($dst) ? ' (already in _config/)' : ''));
        $skipped++;
        continue;
    }
    if (is_file($dst)) {
        if (file_get_contents($src) === file_get_contents($dst)) {
            $say("{$name}: identical copy already in _config/ — removing the legacy copy");
            if (!$dry && !unlink($src)) { fwrite(STDERR, "  could not remove {$src}\n"); }
            $moved++;
        } else {
            fwrite(STDERR, "CONFLICT {$name}: differs from _config/{$name}. Left both untouched — _config/ wins. Compare and delete one by hand.\n");
            $conflicts++;
        }
        continue;
    }

    $say("{$name}: moving to _config/");
    if ($dry) { $moved++; continue; }

    if (!@rename($src, $dst)) {                    // cross-device: copy, then remove
        if (!copy($src, $dst)) { fwrite(STDERR, "  FAILED to copy {$src}\n"); $conflicts++; continue; }
        @chmod($dst, fileperms($src) & 0777);
        @chgrp($dst, (int) filegroup($src));
        unlink($src);
    }
    $moved++;
}

/* Secrets: report (and tighten) mode and group so a misconfigured host is visible now, not at first login. */
foreach (['secret.php', 'invite.php'] as $name) {
    $f = $target . '/' . $name;
    if ($dry || !is_file($f)) { continue; }
    if ((fileperms($f) & 0007) !== 0) {
        @chmod($f, 0640);
        $say("{$name}: was world-readable — set to 640");
    }
    $grp = (($g = posix_getgrgid((int) filegroup($f))) ? $g['name'] : '?');
    $web = SiteConfig::webGroup();
    $say(sprintf('%s: mode %s, group %s', $name, substr(sprintf('%o', fileperms($f) & 0777), -3), $grp));
    if ($web !== null && $grp !== $web) {
        fwrite(STDERR, "WARNING: {$name} is group '{$grp}', web server group looks like '{$web}'. "
            . "If login or unlock reports an unreadable secret, run: sudo chgrp {$web} " . escapeshellarg($f) . "\n");
    }
}

/* .gitignore advice — not edited for you, it's your file. */
$gi = SiteConfig::siteRoot() . '/.gitignore';
if (is_file($gi)) {
    $txt = (string) file_get_contents($gi);
    foreach (['secret.php', 'invite.php'] as $name) {
        if (!str_contains($txt, "_config/{$name}")) {
            fwrite(STDERR, "NOTE: add \"/_config/{$name}\" to {$gi} so the hash is never committed.\n");
        }
    }
}
if (is_file($legacy . '/sites_rem.json')) {
    $say('Note: sites_rem.json is not a file Papyr reads; left where it is.');
}

$say("Done: {$moved} moved/cleaned, {$skipped} nothing to do, {$conflicts} conflicts.");
exit($conflicts > 0 ? 1 : 0);
