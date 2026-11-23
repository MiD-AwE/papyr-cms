<?php
declare(strict_types=1);

/*
 * ThemeStore — the one theme writer (ADR-002 §7.3) — and save-theme.php on top of it.
 * Run:  php _papyr/tests/themes-test.php
 *
 * Covers:
 *   - sanitize(): unknown keys dropped, numeric parts cleaned, mode coerced
 *   - isValidKey(): separators, NUL, hidden and reserved ("_site") keys refused
 *   - write(): pretty JSON at data/themes/{key}.json; refuses junk-only payloads
 *   - defaults(): the engine defaults, the same values papyr_theme_defaults() returns
 *   - save-theme.php over HTTP: visitor 403, bad CSRF 403, cross-origin 403,
 *     admin writes through ThemeStore, reserved key and junk payload 400
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Papyr\Core\ThemeStore;

[$tmp, $site, $base] = buildSite('test-admin-pass');

echo "ThemeStore (unit)\n";
$clean = ThemeStore::sanitize(['accent-h' => '149deg', 'accent-s' => '57%', 'evil' => '<script>', 'mode' => 'neon', 'bg-l' => ['x']]);
check('unknown keys dropped', !isset($clean['evil']));
check('numeric parts keep digits . %', ($clean['accent-h'] ?? '') === '149' && ($clean['accent-s'] ?? '') === '57%');
check('mode coerced to dark', ($clean['mode'] ?? '') === 'dark');
check('non-scalar values dropped', !isset($clean['bg-l']));
check('plain key valid', ThemeStore::isValidKey('docs_overview'));
foreach (['', 'a/b', 'a\\b', "a\0b", '.hidden', '_site', str_repeat('a', 121)] as $bad) {
    check('key refused: ' . json_encode($bad), !ThemeStore::isValidKey($bad));
}
$store = new ThemeStore($site);
$rel = $store->write('unit', ['accent-h' => '10', 'mode' => 'light']);
$saved = json_decode((string) file_get_contents("$site/$rel"), true);
check('write() returns the site-relative path', $rel === 'data/themes/unit.json');
check('write() stores the clean values', $saved === ['accent-h' => '10', 'mode' => 'light']);
$threw = false;
try { $store->write('unit', ['nope' => '1']); } catch (\InvalidArgumentException) { $threw = true; }
check('junk-only payload refused, file untouched', $threw && json_decode((string) file_get_contents("$site/$rel"), true) === $saved);
require_once dirname(__DIR__) . '/api/load-theme.php';
check('defaults() = engine defaults', $store->defaults() === papyr_theme_defaults() && isset($store->defaults()['accent-h']));

[$srv, $port] = startServer($tmp);
$U   = "http://127.0.0.1:$port/$base";
$api = "$U/_papyr/api/save-theme.php";
$theme = ['accent-h' => '149', 'accent-s' => '57%', 'accent-l' => '43%', 'mode' => 'dark'];

try {
    echo "save-theme.php (HTTP)\n";
    file_put_contents("$site/plain.php", bootPage());
    [$st] = httpJson($api, '', ['page' => 'plain', 'csrf' => 'x', 'theme' => $theme]);
    check('visitor: 403', $st === 403, "HTTP $st");

    $cookie = adminCookie($U, 'test-admin-pass');
    [, , $html] = http("$U/plain.php", $cookie);
    $csrf = (string) (papyrJs($html)['csrf'] ?? '');
    check('admin gets a CSRF token', $csrf !== '');

    [$st] = httpJson($api, $cookie, ['page' => 'plain', 'csrf' => 'wrong', 'theme' => $theme]);
    check('admin, bad CSRF: 403', $st === 403, "HTTP $st");
    [$st] = httpJson($api, $cookie, ['page' => 'plain', 'csrf' => $csrf, 'theme' => $theme], 'http://evil.example');
    check('admin, cross-origin: 403', $st === 403, "HTTP $st");

    [$st, , $out] = httpJson($api, $cookie, ['page' => 'plain', 'csrf' => $csrf, 'theme' => $theme + ['x' => 'y']], "http://127.0.0.1:$port");
    $r = (array) json_decode($out, true);
    check('admin: saved', $st === 200 && ($r['ok'] ?? false) === true && ($r['file'] ?? '') === 'data/themes/plain.json', "HTTP $st $out");
    check('file holds sanitized values', json_decode((string) @file_get_contents("$site/data/themes/plain.json"), true) === $theme);
    [, , $html] = http("$U/plain.php", $cookie);
    check('page renders with the saved accent', str_contains($html, '--accent-h: 149;'));

    [$st] = httpJson($api, $cookie, ['page' => '_site', 'csrf' => $csrf, 'theme' => $theme]);
    check('reserved key "_site": 400', $st === 400, "HTTP $st");
    [$st] = httpJson($api, $cookie, ['page' => 'a/b', 'csrf' => $csrf, 'theme' => $theme]);
    check('path in key: 400', $st === 400, "HTTP $st");
    [$st] = httpJson($api, $cookie, ['page' => 'plain', 'csrf' => $csrf, 'theme' => ['only' => 'junk']]);
    check('junk-only theme: 400', $st === 400, "HTTP $st");
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
