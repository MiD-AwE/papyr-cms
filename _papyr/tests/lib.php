<?php
declare(strict_types=1);

/*
 * Shared harness for _papyr/tests/*-test.php — no PHPUnit, no Composer dev-deps.
 * check() counts into the globals $fails / $checks; each test prints the
 * summary and sets its exit code.
 */

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

function papyrJs(string $html): array {
    return preg_match('/window\.PAPYR = Object\.freeze\((\{.*?\})\);/s', $html, $m) ? (array) json_decode($m[1], true) : [];
}

/** Log in as admin on a running test site; returns the session cookie "NAME=value". */
function adminCookie(string $U, string $pass): string {
    [, $hd, $html] = http("$U/_papyr/login.php");
    preg_match('/^(PAPYR_\w+)=([^;]+)/mi', $hd['set-cookie'] ?? '', $m);
    preg_match('/name="csrf" value="([^"]+)"/', $html, $c);
    $cookie = ($m[1] ?? '') . '=' . ($m[2] ?? '');
    [, $hd] = http("$U/_papyr/login.php", $cookie, ['csrf' => $c[1] ?? '', 'pass' => $pass]);
    if (preg_match('/^(PAPYR_\w+)=([^;]+)/mi', $hd['set-cookie'] ?? '', $n)) { $cookie = $n[1] . '=' . $n[2]; }
    return $cookie;
}

/** Start PHP's built-in server (output_buffering=0) on a free port, docroot $tmp. Returns [proc, port]. */
function startServer(string $tmp): array {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    $port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
    fclose($sock);
    $env = array_filter(getenv(), fn($k) => !str_starts_with((string) $k, 'PAPYR_'), ARRAY_FILTER_USE_KEY);
    $srv = proc_open([PHP_BINARY, '-d', 'output_buffering=0', '-d', "session.save_path=$tmp", '-S', "127.0.0.1:$port", '-t', $tmp],
        [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']], $pipes, $tmp, $env);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    register_shutdown_function(function () use ($srv, $tmp) {
        if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
        rmTree($tmp);
    });
    return [$srv, $port];
}

/**
 * Build a throw-away subfolder site from this engine: {tmp}/t-site/ with a
 * copy of _papyr/ (no cache, tests or secrets), nav.php, _config/sites.json
 * and an admin password. $siteExtra merges into the site's sites.json entry,
 * $topExtra into the top level.
 * @return array{0:string,1:string,2:string} [tmp, site dir, base folder name]
 */
function buildSite(string $adminPass, array $siteExtra = [], array $topExtra = []): array {
    $engine = dirname(__DIR__);
    $spine  = dirname($engine) . '/nav.php';
    if (!is_file($spine)) { fwrite(STDERR, "nav.php not found next to _papyr/\n"); exit(2); }
    $tmp  = sys_get_temp_dir() . '/papyr-test-' . bin2hex(random_bytes(4));
    $base = 't-site';
    $site = "$tmp/$base";
    copyTree($engine, "$site/_papyr", ['cache', 'tests', 'secret.php', 'invite.php', 'sites.json']);
    mkdir("$site/_papyr/cache", 0755, true);
    copy($spine, "$site/nav.php");
    mkdir("$site/_config", 0755, true);
    $conf = json_decode((string) file_get_contents("$engine/config/sites.json.example"), true);
    $conf['sites'] = ['t' => array_merge(['root' => '..', 'url' => 'http://x/', 'label' => 'Test', 'template' => 'sites/papyr-cms/page.html.twig',
        'shared_partials' => ['nav.php'], 'ignore_files' => ['nav.php'], 'ignore_dirs' => ['vendor', '_papyr']], $siteExtra)];
    file_put_contents("$site/_config/sites.json", json_encode(array_merge($conf, $topExtra)));
    cliTool($site, 'set-admin-pass.php', $adminPass);
    return [$tmp, $site, $base];
}

/** Run one of the engine's CLI tools in a test site, as the current user's group. */
function cliTool(string $site, string $tool, string $pw): string {
    $grp = (string) (posix_getgrgid(posix_getegid())['name'] ?? '');
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . " $site/_papyr/bin/$tool " . escapeshellarg($pw)
        . ' --group=' . escapeshellarg($grp) . ' 2>&1');
}

/** A page that boots first and calls papyr_nav() after <body>, with $body inside. */
function bootPage(string $body = '', string $boot = "require_once __DIR__ . '/nav.php';"): string {
    return "<?php declare(strict_types=1);\ndefine('PAPYR_DEFER_NAV', true);\n{$boot}\n?>"
        . "<!DOCTYPE html><html><head><title>t</title></head><body>\n<?php papyr_nav(); ?>\n{$body}\n</body></html>\n";
}
