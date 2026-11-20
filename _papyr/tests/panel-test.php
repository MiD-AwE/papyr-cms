<?php
declare(strict_types=1);

/*
 * Renders the theme panel over real HTTP and asserts what each role sees.
 * No PHPUnit, no Composer dev-deps — just:  php _papyr/tests/panel-test.php
 *
 * It builds a throw-away site in the system temp dir (a COPY of this engine,
 * served from a subfolder so cookie paths are meaningful), runs PHP's
 * built-in server with output_buffering=0, and talks to it. Nothing under
 * the real site is read or written. Exit code is non-zero on any failure.
 *
 * Covers:
 *   - admin sees the Access section, the Log out form (POST + CSRF) and Commit
 *   - the same when nav.php is booted from inside a function (partials must
 *     not depend on the including file's variable scope)
 *   - visitors never see Commit, and always get "Site admin? Log in",
 *     whether or not an invite password is configured
 *   - the session cookie path is the site base path, never /_papyr
 *   - window.PAPYR (§5.2) carries role, CSRF and endpoints; no filesystem paths
 *   - papyr() (§5.1): groups present, drafts only for admin, read-only,
 *     deprecated flat aliases mirror the groups, no filesystem paths
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';

/* ---------- build the throw-away site ---------- */
[$tmp, $site, $base] = buildSite('test-admin-pass');

$page = fn(string $boot) => "<?php declare(strict_types=1);\ndefine('PAPYR_DEFER_NAV', true);\n{$boot}\n?>"
    . "<!DOCTYPE html><html><head><title>t</title></head><body>\n<?php papyr_nav(); ?>\n</body></html>\n";
file_put_contents("$site/plain.php", $page("require_once __DIR__ . '/nav.php';"));
file_put_contents("$site/scoped.php", str_replace('</body>', "<?php papyr_comments('demo'); ?>\n</body>", $page("function boot_in_scope(): void { require_once __DIR__ . '/nav.php'; }\nboot_in_scope();")));

file_put_contents("$site/ctx.php", "<?php declare(strict_types=1);\ndefine('PAPYR_DEFER_NAV', true);\nrequire_once __DIR__ . '/nav.php';\n"
    . "\$p = papyr(); \$ro = 'writable';\n"
    . "try { (function () use (\$p) { \$p->user = []; })(); } catch (\\Error \$e) { \$ro = 'readonly'; }\n"
    . "header('Content-Type: application/json');\n"
    . "echo json_encode(['site' => \$p->site, 'page' => \$p->page, 'pages' => array_keys(\$p->tree['pages']),\n"
    . "  'nested' => \$p->tree['nested'], 'user' => \$p->user, 'theme' => \$p->theme, 'ai' => \$p->ai, 'ro' => \$ro,\n"
    . "  'aliases' => \$p->basePath === \$p->site['basePath'] && \$p->role === \$p->user['role'] && \$p->csrf === \$p->user['csrf']\n"
    . "     && \$p->pageKey === \$p->page['key'] && \$p->label === \$p->site['label'] && \$p->inviteReady === \$p->site['invites']]);\n");
file_put_contents("$site/secret-draft.php", "<!-- papyr:draft -->\n<html><head><title>Hidden draft</title></head><body></body></html>\n");

$cli = fn(string $tool, string $pw) => cliTool($site, $tool, $pw);

/* ---------- server ---------- */
[$srv, $port] = startServer($tmp);
$U = "http://127.0.0.1:$port/$base";

try {
    /* ---------- visitor, no invite password configured ---------- */
    echo "visitor (invite not configured)\n";
    [$st, $hd, $html] = http("$U/plain.php");
    check('page renders', $st === 200, "HTTP $st");
    check('theme panel present', str_contains($html, 'id="theme-panel"'));
    check('no Commit button', !str_contains($html, 'saveTheme()">Commit'));
    check('"Site admin? Log in" link shown', str_contains($html, 'Site admin? Log in'));
    check('no unlock form without an invite password', !str_contains($html, 'id="tp-invite"'));
    check('Reset still there', str_contains($html, 'resetTheme()'));
    preg_match('/^(PAPYR_\w+)=[^;]+;.*?path=([^;]*)/mi', $hd['set-cookie'] ?? '', $m);
    $wp = papyrJs($html);
    check('window.PAPYR.role is visitor, csrf empty', ($wp['role'] ?? '') === 'visitor' && ($wp['csrf'] ?? null) === '');

    [, , $json] = http("$U/ctx.php");
    $cx = (array) json_decode($json, true);
    echo "context (visitor)\n";
    check('all §5.1 groups present', isset($cx['site'], $cx['page'], $cx['user'], $cx['theme'], $cx['ai'], $cx['pages']));
    check('site group', ($cx['site']['basePath'] ?? '') === "/$base" && ($cx['site']['engineVersion'] ?? '') !== '');
    check('page group', ($cx['page']['key'] ?? '') === 'ctx' && ($cx['page']['path'] ?? '') === 'ctx.php'
        && in_array($cx['page']['theme']['source'] ?? '', ['page', 'default'], true));
    check('user group: visitor, no csrf', ($cx['user']['role'] ?? '') === 'visitor' && ($cx['user']['csrf'] ?? null) === '');
    check('theme group: tokens, defaults, swatches', !empty($cx['theme']['tokens']) && !empty($cx['theme']['defaults']) && isset($cx['theme']['swatches']));
    check('ai group is two booleans', is_bool($cx['ai']['local'] ?? null) && is_bool($cx['ai']['cloud'] ?? null));
    check('drafts hidden from visitors', !in_array('secret-draft.php', $cx['pages'] ?? [], true) && !str_contains($json, 'Hidden draft'));
    check('context is read-only', ($cx['ro'] ?? '') === 'readonly');
    check('deprecated flat aliases mirror the groups', ($cx['aliases'] ?? false) === true);
    check('no filesystem path in the context', !str_contains($json, $tmp));
    echo "visitor (invite not configured), continued\n";
    check('visitor session cookie path is the site base path', ($m[2] ?? '') === "/$base", 'got ' . ($m[2] ?? 'none'));

    /* ---------- login ---------- */
    echo "login\n";
    [$st, $hd, $html] = http("$U/_papyr/login.php");
    preg_match('/^(PAPYR_\w+)=([^;]+);.*?path=([^;]*)/mi', $hd['set-cookie'] ?? '', $m);
    check('login.php cookie path is the site base path, not /_papyr', ($m[3] ?? '') === "/$base", 'got ' . ($m[3] ?? 'none'));
    preg_match('/name="csrf" value="([^"]+)"/', $html, $c);
    $cookie = ($m[1] ?? '') . '=' . ($m[2] ?? '');
    [$st, $hd] = http("$U/_papyr/login.php", $cookie, ['csrf' => $c[1] ?? '', 'pass' => 'test-admin-pass']);
    check('admin login succeeds (302)', $st === 302, "HTTP $st");
    if (preg_match('/^(PAPYR_\w+)=([^;]+)/mi', $hd['set-cookie'] ?? '', $n)) { $cookie = $n[1] . '=' . $n[2]; }
    [$st] = http("$U/_papyr/index.php", $cookie);
    check('dashboard accepts the session', $st === 200, "HTTP $st");

    /* ---------- admin: normal boot, and boot inside a function ---------- */
    foreach (['plain.php' => 'boot at file scope', 'scoped.php' => 'boot inside a function'] as $file => $label) {
        echo "admin — $label\n";
        [$st, , $html] = http("$U/$file", $cookie);
        check('page renders', $st === 200, "HTTP $st");
        check('Access section present', str_contains($html, '<h4>Access</h4>'));
        check('"Signed in as admin" shown', str_contains($html, 'Signed in as admin'));
        check('Commit button present', str_contains($html, 'saveTheme()">Commit'));
        check('Log out is a POST form', (bool) preg_match('#<form method="POST" action="[^"]*/_papyr/login\.php">\s*<input type="hidden" name="action" value="logout">#', $html));
        check('Log out form carries a CSRF token', (bool) preg_match('/name="action" value="logout">\s*<input type="hidden" name="csrf" value="[0-9a-f]{32}"/', $html));
        check('no "Site admin? Log in" link when signed in', !str_contains($html, 'Site admin? Log in'));
        check('comments partial sees the admin too', $file !== 'scoped.php' || str_contains($html, 'pc-thread') && !str_contains($html, 'pc-locked'));
        $wp = papyrJs($html);
        check('window.PAPYR emitted once', substr_count($html, 'window.PAPYR = Object.freeze(') === 1);
        check('window.PAPYR.role is admin', ($wp['role'] ?? '') === 'admin');
        check('window.PAPYR.csrf is the session token', (bool) preg_match('/^[0-9a-f]{32}$/', (string) ($wp['csrf'] ?? '')));
        check('window.PAPYR.pageKey is this page', ($wp['pageKey'] ?? '') === basename($file, '.php'));
        check('window.PAPYR.api.theme under the base path', ($wp['api']['theme'] ?? '') === "/$base/_papyr/api/save-theme.php");
        check('theme panel reads endpoints from window.PAPYR', str_contains($html, 'var SAVE_URL   = P.api.theme'));
        check('no filesystem path in window.PAPYR', !str_contains(json_encode($wp), $tmp));
    }

    echo "context (admin)\n";
    [, , $json] = http("$U/ctx.php", $cookie);
    $cx = (array) json_decode($json, true);
    check('user group: admin with csrf', ($cx['user']['role'] ?? '') === 'admin' && strlen((string) ($cx['user']['csrf'] ?? '')) === 32);
    check('drafts included for admin', in_array('secret-draft.php', $cx['pages'] ?? [], true));
    check('no filesystem path in the admin context', !str_contains($json, $tmp));

    /* ---------- visitor with an invite password configured ---------- */
    $cli('set-invite-pass.php', 'test-invite-pass');
    echo "visitor (invite configured)\n";
    [, , $html] = http("$U/plain.php");
    check('unlock form shown', str_contains($html, 'id="tp-invite"'));
    check('"Site admin? Log in" link still shown', str_contains($html, 'Site admin? Log in'));
    check('still no Commit', !str_contains($html, 'saveTheme()">Commit'));
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
