<?php
declare(strict_types=1);

/*
 * Invite-only comments, end to end under a real Apache (deny rules apply).
 * Run:  php _papyr/tests/comments-test.php
 * Needs XAMPP's httpd + mod_php (/opt/lampp, or PAPYR_TEST_LAMPP); prints SKIP
 * and exits 0 when Apache is absent. Builds its own draft page with two blocks.
 *
 * Covers:
 *   - visitor: locked count + unlock link per block, no thread or form; API 403
 *   - unlock: wrong password 401, cross-origin 403, right password → member
 *   - member: form shown, no Remove; post needs CSRF and same origin; the
 *     comment lands in data/comments/{pageKey}.json (with its deny rule) and
 *     renders escaped; data/comments/*.json is 403 over the web; a second post
 *     within 10 s is 429; delete is 403
 *   - visitor now sees the count; lock returns the member to visitor
 *   - admin: sees the thread and Remove, API reports admin, delete empties the thread
 *   - an unwritable data/comments/ gives a clear 500, not a PHP error
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';

[$tmp, $site, $base] = buildSite('test-admin-pass');
ini_set('error_log', "$tmp/cli-errors.log");
// A draft page with two comment blocks; it boots first, like a real page.
file_put_contents("$site/comments-check.php", str_replace('<body>', "<body>\n<!-- papyr:draft -->", bootPage(
    "<h1>Comments check</h1>\n<?php papyr_comments('first'); ?>\n<?php papyr_comments('second'); ?>")));
[$port, $skip] = startApache($tmp);
if ($skip !== null) { echo "SKIP  $skip\n"; rmTree($tmp); exit(0); }

$U      = "http://127.0.0.1:$port/$base";
$origin = "http://127.0.0.1:$port";
$page   = "$U/comments-check.php";
$api    = "$U/_papyr/api/comments.php";
$unlock = "$U/_papyr/api/unlock.php";
$store  = "$site/data/comments/comments-check.json";
$cookieOf = fn(array $hd): string => preg_match('/^(PAPYR_\w+)=([^;]+)/mi', $hd['set-cookie'] ?? '', $m) ? "$m[1]=$m[2]" : '';
$json = fn(array $r): array => (array) json_decode($r[2], true);

try {
    cliTool($site, 'set-invite-pass.php', 'test-invite-pass');

    echo "visitor\n";
    [$st, $hd, $html] = http($page);
    $visitor = $cookieOf($hd);
    check('page renders', $st === 200, "HTTP $st");
    check('session cookie issued', $visitor !== '');
    check('two locked blocks with an unlock link', substr_count($html, 'class="pc-unlock-link"') === 2);
    check('no thread or form for a visitor', !str_contains($html, 'class="pc-form"') && !str_contains($html, 'class="pc-list"'));
    check('unlock form in the panel', str_contains($html, 'id="tp-invite"'));
    check('draft page kept out of the nav', !preg_match('/<a[^>]+comments-check\.php/', $html));
    [$st] = http("$api?page=comments-check&block=first", $visitor);
    check('API read refused for a visitor', $st === 403, "HTTP $st");

    echo "unlock\n";
    $r = httpJson($unlock, $visitor, ['action' => 'unlock', 'pass' => 'nope'], $origin);
    check('wrong invite password → 401', $r[0] === 401, "HTTP {$r[0]}");
    $r = httpJson($unlock, $visitor, ['action' => 'unlock', 'pass' => 'test-invite-pass'], 'http://evil.example');
    check('cross-origin unlock → 403', $r[0] === 403, "HTTP {$r[0]}");
    $r = httpJson($unlock, $visitor, ['action' => 'unlock', 'pass' => 'test-invite-pass'], $origin);
    $member = $cookieOf($r[1]) ?: $visitor;
    check('right invite password → member', $r[0] === 200 && ($json($r)['member'] ?? false) === true, $r[2]);

    echo "member\n";
    [, , $html] = http($page, $member);
    $csrf = papyrJs($html)['csrf'] ?? '';
    check('thread + form shown', substr_count($html, 'class="pc-form"') === 2 && !str_contains($html, 'class="pc-unlock-link"'));
    check('no Remove for a member', !str_contains($html, 'class="pc-del"'));
    check('member has a CSRF token', $csrf !== '');
    $post = ['action' => 'add', 'page' => 'comments-check', 'block' => 'first', 'name' => 'Ada', 'body' => 'Hello <b>there</b>'];
    $r = httpJson($api, $member, $post, $origin);
    check('post without CSRF → 403', $r[0] === 403, "HTTP {$r[0]}");
    $r = httpJson($api, $member, $post + ['csrf' => $csrf], 'http://evil.example');
    check('cross-origin post → 403', $r[0] === 403, "HTTP {$r[0]}");
    $r = httpJson($api, $member, $post + ['csrf' => $csrf], $origin);
    $id = (string) ($json($r)['comment']['id'] ?? '');
    check('post accepted', $r[0] === 200 && $id !== '', $r[2]);
    $saved = json_decode((string) @file_get_contents($store), true);
    check('stored in data/comments/comments-check.json', ($saved['first'][0]['body'] ?? '') === 'Hello <b>there</b>');
    check('deny rule written beside it', is_file("$site/data/comments/.htaccess"));
    [$st] = http("$U/data/comments/comments-check.json");
    check('comment file 403 over the web', $st === 403, "HTTP $st");
    [, , $html] = http($page, $member);
    check('comment rendered, escaped', str_contains($html, 'Hello &lt;b&gt;there&lt;/b&gt;') && str_contains($html, '<strong>Ada</strong>'));
    $r = httpJson($api, $member, $post + ['csrf' => $csrf], $origin);
    check('second post within 10 s → 429', $r[0] === 429, "HTTP {$r[0]}");
    $r = httpJson($api, $member, ['action' => 'delete', 'page' => 'comments-check', 'block' => 'first', 'id' => $id, 'csrf' => $csrf], $origin);
    check('member delete → 403', $r[0] === 403, "HTTP {$r[0]}");

    echo "visitor again\n";
    [, , $html] = http($page);
    check('visitor sees the count only', str_contains($html, '1 comment · Unlock to read') && !str_contains($html, 'Hello'));
    $r = httpJson($unlock, $member, ['action' => 'lock'], $origin);
    check('lock → no longer a member', $r[0] === 200 && ($json($r)['member'] ?? true) === false, $r[2]);
    [$st] = http("$api?page=comments-check&block=first", $member);
    check('API read refused after lock', $st === 403, "HTTP $st");

    echo "admin\n";
    $admin = adminCookie($U, 'test-admin-pass');
    [, , $html] = http($page, $admin);
    $acsrf = papyrJs($html)['csrf'] ?? '';
    check('admin sees the thread without unlocking', str_contains($html, 'Hello &lt;b&gt;') && !str_contains($html, 'class="pc-unlock-link"'));
    check('admin gets Remove', str_contains($html, 'class="pc-del"'));
    $r = http("$api?page=comments-check&block=first", $admin);
    check('API reports admin', ($json($r)['admin'] ?? false) === true && count($json($r)['comments'] ?? []) === 1, $r[2]);
    $r = httpJson($api, $admin, ['action' => 'delete', 'page' => 'comments-check', 'block' => 'first', 'id' => $id, 'csrf' => $acsrf], $origin);
    check('admin delete', $r[0] === 200 && ($json($r)['ok'] ?? false) === true && ($json($r)['count'] ?? -1) === 0, $r[2]);
    $saved = json_decode((string) @file_get_contents($store), true);
    check('thread removed from the file', is_array($saved) && !isset($saved['first']));

    echo "unwritable data/comments/\n";
    chmod("$site/data/comments", 0555);
    $r = httpJson($api, $admin, ['action' => 'add', 'page' => 'elsewhere', 'block' => 'x', 'name' => 'A', 'body' => 'b', 'csrf' => $acsrf], $origin);
    chmod("$site/data/comments", 0775);
    check('clear 500 naming data/comments/', $r[0] === 500 && str_contains($json($r)['error'] ?? '', 'data/comments/ writable'), $r[2]);
    check('no absolute path in the error', !str_contains($r[2], $tmp));
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
