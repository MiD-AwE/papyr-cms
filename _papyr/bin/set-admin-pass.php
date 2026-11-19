#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Admin password (re)set — the lockout escape hatch. Writes
 * _config/secret.php with a fresh password_hash(). Works over SSH
 * on any host, without touching the web server's environment variable
 * config — this is the fix for hosts where PAPYR_ADMIN_PASS never
 * reliably reaches PHP.
 *
 * Usage:
 *   php _papyr/bin/set-admin-pass.php [--group=NAME]
 *   php _papyr/bin/set-admin-pass.php 'new-password'
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require __DIR__ . '/../vendor/autoload.php';

use Papyr\Core\AdminAuth;
use Papyr\Core\SiteConfig;

// Options: --group=NAME overrides web-server group detection.
$group = null;
$args  = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--group=')) { $group = substr($a, 8); } else { $args[] = $a; }
}
$password = $args[0] ?? null;

if ($password === null) {
    fwrite(STDOUT, "New admin password: ");

    // Best-effort hide input where stty is available; falls back to
    // visible input on hosts without it rather than failing outright.
    $canHideInput = stripos(PHP_OS, 'WIN') === false && shell_exec('stty -a 2>/dev/null') !== null;
    if ($canHideInput) {
        shell_exec('stty -echo');
    }

    $password = fgets(STDIN);

    if ($canHideInput) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
}

$password = trim((string) $password);

if ($password === '') {
    fwrite(STDERR, "No password given. Aborting.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters. Aborting.\n");
    exit(1);
}

try {
    $r = AdminAuth::writeSecretFile($password, $group);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

foreach ($r['notes'] as $n) { fwrite(STDOUT, "  - {$n}\n"); }
fwrite(STDOUT, "Wrote {$r['path']}  (mode {$r['mode']}, group {$r['group']})\n");
foreach ($r['warnings'] as $w) { fwrite(STDERR, "WARNING: {$w}\n"); }

$legacy = SiteConfig::legacyDir() . '/secret.php';
if (is_file($legacy)) {
    fwrite(STDERR, "WARNING: a stale copy remains at {$legacy}. _config/ wins, but run migrate-config.php to clean it up.\n");
}

fwrite(STDOUT, "Note: if PAPYR_ADMIN_PASS is also set in the environment, it takes\n");
fwrite(STDOUT, "precedence over this file. Unset it if you want this password to apply.\n");
