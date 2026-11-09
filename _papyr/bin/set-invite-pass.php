#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Set or rotate the shared invite password that unlocks participant
 * features (comments). Writes _papyr/config/invite.php with a fresh
 * password_hash(). Rotating it locks out everyone who unlocked with
 * the old password.
 *
 * Usage:
 *   php _papyr/bin/set-invite-pass.php
 *   php _papyr/bin/set-invite-pass.php 'new-invite-password'
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require __DIR__ . '/../vendor/autoload.php';

use Papyr\Core\MemberAuth;

$password = $argv[1] ?? null;

if ($password === null) {
    fwrite(STDOUT, "New invite password: ");
    $canHide = stripos(PHP_OS, 'WIN') === false && shell_exec('stty -a 2>/dev/null') !== null;
    if ($canHide) shell_exec('stty -echo');
    $password = fgets(STDIN);
    if ($canHide) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
}

$password = trim((string) $password);
if ($password === '') {
    fwrite(STDERR, "No password given. Aborting.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Use at least 8 characters. Aborting.\n");
    exit(1);
}

try {
    MemberAuth::writeSecretFile($password);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Invite password saved to " . realpath(MemberAuth::secretFilePath()) . "\n");
fwrite(STDOUT, "Anyone unlocked with a previous invite password is now locked out.\n");
