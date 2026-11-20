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
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

$engine = dirname(__DIR__);
$spine  = dirname($engine) . '/nav.php';
if (!is_file($spine)) { fwrite(STDERR, "nav.php not found next to _papyr/\n"); exit(2); }

$fails = 0; $checks = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails, $checks; $checks++;
    echo ($ok ? "  ok    " : "  FAIL  ") . $name . ($ok || $detail === '' ? '' : "  — {$detail}") . "\n";
    if (!$ok) { $fails++; }
}
function copyTree(string $from, string $to, array $skip = []): void {
    @mkdir($to, 0755, true);
    foreach (scandir($from) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, $skip, true)) { continue; }
        is_dir("$from/$e") ? copyTree("$from/$e", "$to/$e", $skip) : copy("$from/$e", "$to/$e");
    }
}
function rmTree(string $d): void {
    foreach (scandir($d) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        is_dir("$d/$e") && !is_link("$d/$e") ? rmTree("$d/$e") : @unlink("$d/$e");
    }
    @rmdir($d);
}
/** @return array{0:int,1:array<string,string>,2:string} status, lowercase-header => value (Set-Cookie joined), body */
function http(string $url, string $cookie = '', ?array $post = null): array {
    $h = "Accept: text/html\r\n" . ($cookie !== '' ? "Cookie: {$cookie}\r\n" : '');
    $o = ['method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10];
    if ($post !== null) {
        $o['method'] = 'POST'; $o['content'] = http_build_query($post);
        $h .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $o['header'] = $h;
    $body = (string) @file_get_contents($url, false, stream_context_create(['http' => $o]));
    $status = 0; $hdr = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) { $status = (int) $m[1]; continue; }
        [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
        $k = strtolower(trim($k)); $hdr[$k] = isset($hdr[$k]) ? $hdr[$k] . "\n" . trim($v) : trim($v);
    }
    return [$status, $hdr, $body];
}

/* ---------- build the throw-away site ---------- */
$tmp  = sys_get_temp_dir() . '/papyr-test-' . bin2hex(random_bytes(4));
$base = 't-site';
$site = "$tmp/$base";
copyTree($engine, "$site/_papyr", ['cache', 'tests', 'secret.php', 'invite.php', 'sites.json']);
mkdir("$site/_papyr/cache", 0755, true);
copy($spine, "$site/nav.php");
mkdir("$site/_config", 0755, true);
$conf = json_decode((string) file_get_contents("$engine/config/sites.json.example"), true);
$conf['sites'] = ['t' => ['root' => '..', 'url' => 'http://x/', 'label' => 'Test', 'template' => 'sites/papyr-cms/page.html.twig',
                          'shared_partials' => ['nav.php'], 'ignore_files' => ['nav.php'], 'ignore_dirs' => ['vendor', '_papyr']]];
file_put_contents("$site/_config/sites.json", json_encode($conf));

$page = fn(string $boot) => "<?php declare(strict_types=1);\ndefine('PAPYR_DEFER_NAV', true);\n{$boot}\n?>"
    . "<!DOCTYPE html><html><head><title>t</title></head><body>\n<?php papyr_nav(); ?>\n</body></html>\n";
file_put_contents("$site/plain.php", $page("require_once __DIR__ . '/nav.php';"));
file_put_contents("$site/scoped.php", str_replace('</body>', "<?php papyr_comments('demo'); ?>\n</body>", $page("function boot_in_scope(): void { require_once __DIR__ . '/nav.php'; }\nboot_in_scope();")));

$grp = (string) (posix_getgrgid(posix_getegid())['name'] ?? '');
$cli = fn(string $tool, string $pw) => shell_exec(escapeshellarg(PHP_BINARY) . " $site/_papyr/bin/$tool " . escapeshellarg($pw)
    . ' --group=' . escapeshellarg($grp) . ' 2>&1');
$cli('set-admin-pass.php', 'test-admin-pass');

/* ---------- server ---------- */
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1]; fclose($sock);
$env = array_filter(getenv(), fn($k) => !str_starts_with((string) $k, 'PAPYR_'), ARRAY_FILTER_USE_KEY);
$srv = proc_open([PHP_BINARY, '-d', 'output_buffering=0', '-d', "session.save_path=$tmp", '-S', "127.0.0.1:$port", '-t', $tmp],
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']], $pipes, $tmp, $env);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
$U = "http://127.0.0.1:$port/$base";

register_shutdown_function(function () use ($srv, $tmp) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } rmTree($tmp); });

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
        check('JS gets the CSRF token for Commit', (bool) preg_match('/var CSRF\s*=\s*"[0-9a-f]{32}"/', $html));
    }

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
