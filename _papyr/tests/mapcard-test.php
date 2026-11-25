<?php
declare(strict_types=1);

/*
 * Site map card actions (ADR-002 §7.2) — server payload over HTTP, the card
 * in a real browser.
 * Run:  php _papyr/tests/mapcard-test.php
 * The browser part needs headless Chromium with WebGL (SwiftShader); it SKIPs
 * without it. A same-origin harness carries the admin session into an iframe
 * and selects nodes through the map's #node= deep link.
 *
 * Covers:
 *   - visitor payload: no mtime / cms_managed, no pages API, no drafts
 *   - admin payload: pages API and the starters (names and labels, no paths)
 *   - card for a draft Papyr page: Publish + Move to trash; publishing
 *     updates the label in place (no reload) and the file loses its marker;
 *     Make draft puts it back
 *   - hand-made page: Make draft only, never trash
 *   - trash needs a second click; then the node disappears and the file is
 *     in _trash/
 *   - folder card: New page here → title → name follows → Create → the new
 *     node appears and the file exists, as a draft
 *   - visitor card: no Modified / Origin / actions
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Papyr\Core\PageWriter;
use Papyr\Core\Scanner;
use Papyr\Core\Starters;

$chrome = getenv('PAPYR_TEST_CHROME') ?: '';
foreach (['chromium', 'chromium-browser', 'google-chrome'] as $c) {
    if ($chrome === '' && trim((string) shell_exec('command -v ' . $c . ' 2>/dev/null')) !== '') { $chrome = $c; }
}

[$tmp, $site, $base] = buildSite('test-admin-pass');
ini_set('error_log', "$tmp/cli-errors.log");
copy(dirname(__DIR__, 2) . '/map.php', "$site/map.php");
file_put_contents("$site/index.php", bootPage('<h1>Home</h1>'));
file_put_contents("$site/hand.php", bootPage('<h1>Hand</h1>'));
$scanner = new Scanner(['t' => ['root' => $site, 'label' => 'T', 'ignore_files' => ['nav.php']]], "$site/_papyr/cache/manifest.json", 0);
$writer  = new PageWriter($scanner, 't', ['root' => $site, 'template' => 'sites/papyr-cms/page.html.twig'], "$site/_papyr");
$st      = new Starters($site, "$site/_papyr");
$writer->create('guides/start.php', $st->vars('article', ['title' => 'Start']) + ['draft' => true], $st->get('article')['template']);
$writer->create('guides/keep.php', $st->vars('article', ['title' => 'Keep']), $st->get('article')['template']);

[$srv, $port] = startServer($tmp);
$U = "http://127.0.0.1:$port/$base";
$payload = function (string $html): array {
    preg_match('#<script type="application/json" id="psm-data">(.*?)</script>#s', $html, $m);
    return (array) json_decode($m[1] ?? 'null', true);
};

try {
    echo "payload\n";
    $v = $payload(http("$U/map.php")[2]);
    $vj = json_encode($v, JSON_UNESCAPED_SLASHES);
    check('visitor: no mtime, no cms_managed', $vj !== 'null' && !str_contains($vj, '"mtime"') && !str_contains($vj, '"cms_managed"'), substr($vj, 0, 120));
    check('visitor: no pages API', array_key_exists('pages', $v) && $v['pages'] === null);
    check('visitor: no drafts', !str_contains($vj, 'guides/start.php') && str_contains($vj, 'guides/keep.php'));
    $admin = adminCookie($U, 'test-admin-pass');
    $a = $payload(http("$U/map.php", $admin)[2]);
    $aj = json_encode($a, JSON_UNESCAPED_SLASHES);
    check('admin: pages API', ($a['pages']['api'] ?? '') === "/$base/_papyr/api/pages.php");
    check('admin: starters, no paths', count($a['pages']['starters'] ?? []) === 4 && !str_contains($aj, $tmp) && !str_contains($aj, 'page.html.twig'));
    check('admin: drafts and metadata', str_contains($aj, '"guides/start.php"') && str_contains($aj, '"cms_managed":true'));

    if ($chrome === '') {
        echo "SKIP  browser part: no headless Chromium on PATH\n";
    } else {
        echo "browser\n";
        [$cn, $cv] = explode('=', $admin, 2);
        $harness = <<<HTML
<!DOCTYPE html><html><body><pre id="out">not run</pre>
<script>
var R = { steps: [] }, f = document.createElement('iframe');
f.style.width = '1200px'; f.style.height = '900px';
document.body.appendChild(f);
function wait(test, then, n) { n = n || 0; var v; try { v = test(); } catch (e) { v = false; } if (v) return then(); if (n > 160) { R.steps.push('timeout'); return then(); } setTimeout(function () { wait(test, then, n + 1); }, 50); }
function d() { return f.contentDocument; }
function card() { return d().getElementById('psm-card'); }
function cardFor(path) { return function () { var c = card(); return c && !c.hidden && c.querySelector('.psm-path') && c.querySelector('.psm-path').textContent === path; }; }
function labels() { return [].map.call(d().querySelectorAll('.psm-label'), function (l) { return l.textContent; }); }
function btn(act) { return card().querySelector('[data-act="' + act + '"]'); }
function msg() { var m = card().querySelector('.psm-msg'); return m ? m.textContent : ''; }
function go(hash, path, then) { f.contentWindow.location.hash = hash; wait(cardFor(path), then); }
function done() { document.getElementById('out').textContent = JSON.stringify(R); }

document.cookie = "$cn=$cv; path=/$base";
f.onload = function () {
  f.onload = null;
  R.webgl = !d().querySelector('.psm-fallback');
  if (!R.webgl) { R.fallback = d().querySelector('.psm-fallback').textContent; return done(); }
  wait(cardFor('/guides/start.php'), function () {
    R.draftCard = { publish: !!btn('promote'), trash: !!btn('trash'), demote: !!btn('demote'), text: card().textContent };
    btn('promote').click();
    wait(function () { return /Published/.test(msg()); }, function () {
      R.afterPublish = { labels: labels(), msg: msg(), demote: !!btn('demote') };
      btn('demote').click();
      wait(function () { return /Now a draft/.test(msg()); }, function () {
        R.afterDemote = { labels: labels() };
        go('#node=hand.php', '/hand.php', function () {
          R.handCard = { demote: !!btn('demote'), trash: !!btn('trash') };
          go('#node=guides/keep.php', '/guides/keep.php', function () {
            btn('trash').click();
            R.armed = btn('trash').textContent;
            R.stillThere = labels().indexOf('Keep') >= 0;
            btn('trash').click();
            wait(function () { return /Moved to/.test(msg()); }, function () {
              R.afterTrash = { labels: labels(), msg: msg() };
              go('#node=guides/', '/guides/', function () {
                btn('new').click();
                var form = card().querySelector('.psm-new');
                R.formShown = !form.hidden;
                form.elements.title.value = 'Fresh Ideas';
                form.elements.title.dispatchEvent(new Event('input', { bubbles: true }));
                R.slug = form.elements.slug.value;
                R.newPath = card().querySelector('.psm-new-path').textContent;
                form.requestSubmit();
                wait(function () { return /Created/.test(msg()); }, function () {
                  R.afterCreate = { labels: labels(), msg: msg(), selected: card().querySelector('.psm-path').textContent };
                  done();
                });
              });
            });
          });
        });
      });
    });
  });
};
f.src = '/$base/map.php#node=guides/start.php';
</script></body></html>
HTML;
        file_put_contents("$site/harness.html", $harness);
        $dom = (string) shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --use-angle=swiftshader --enable-unsafe-swiftshader'
            . ' --virtual-time-budget=30000 --dump-dom ' . escapeshellarg("$U/harness.html") . ' 2>/dev/null');
        preg_match('#<pre id="out">(.*?)</pre>#s', $dom, $m);
        $R = (array) json_decode(html_entity_decode($m[1] ?? ''), true);
        if (($R['webgl'] ?? null) === false) {
            echo 'SKIP  browser part: no WebGL in headless Chromium (' . ($R['fallback'] ?? '') . ")\n";
        } else {
            check('browser ran the flow', isset($R['afterCreate']), substr($m[1] ?? '', 0, 300));
            check('steps finished without a timeout', !in_array('timeout', $R['steps'] ?? [], true), json_encode($R['steps'] ?? null));
            check('draft card: Publish + Move to trash', ($R['draftCard']['publish'] ?? false) && ($R['draftCard']['trash'] ?? false) && !($R['draftCard']['demote'] ?? true));
            check('admin card shows file metadata', str_contains((string) ($R['draftCard']['text'] ?? ''), 'Origin') && str_contains((string) ($R['draftCard']['text'] ?? ''), 'created in Papyr'));
            check('publish: label updated in place', in_array('Start', $R['afterPublish']['labels'] ?? [], true) && !in_array('Start (draft)', $R['afterPublish']['labels'] ?? [], true), json_encode($R['afterPublish']['labels'] ?? null));
            check('… card now offers Make draft', $R['afterPublish']['demote'] ?? false);
            check('make draft: label back to draft', in_array('Start (draft)', $R['afterDemote']['labels'] ?? [], true));
            check('… file has the marker again', str_contains((string) file_get_contents("$site/guides/start.php"), '<!-- papyr:draft -->'));
            check('hand-made card: Make draft, no trash', ($R['handCard']['demote'] ?? false) && !($R['handCard']['trash'] ?? true));
            check('trash needs a second click', str_contains((string) ($R['armed'] ?? ''), 'Click again') && ($R['stillThere'] ?? false));
            check('trash: node gone, file in _trash/', !in_array('Keep', $R['afterTrash']['labels'] ?? ['Keep'], true) && !file_exists("$site/guides/keep.php")
                && glob("$site/_trash/*_guides_keep.php") !== [], json_encode($R['afterTrash'] ?? null));
            check('new page form opens on a folder', $R['formShown'] ?? false);
            check('name follows the title', ($R['slug'] ?? '') === 'fresh-ideas' && ($R['newPath'] ?? '') === '/guides/fresh-ideas.php', json_encode([$R['slug'] ?? null, $R['newPath'] ?? null]));
            check('created: node appears and is selected', in_array('Fresh Ideas (draft)', $R['afterCreate']['labels'] ?? [], true)
                && ($R['afterCreate']['selected'] ?? '') === '/guides/fresh-ideas.php', json_encode($R['afterCreate'] ?? null));
            check('… file written as a draft', str_contains((string) @file_get_contents("$site/guides/fresh-ideas.php"), '<!-- papyr:draft -->'));
            // Visitor: a fresh browser session with no cookie.
            file_put_contents("$site/harness-visitor.html", '<!DOCTYPE html><html><body><pre id="out">not run</pre><script>'
                . 'var f=document.createElement("iframe");f.style.cssText="width:1200px;height:900px";document.body.appendChild(f);'
                . 'var n=0;f.onload=function(){(function w(){var c=f.contentDocument.getElementById("psm-card");'
                . 'if(c&&!c.hidden){document.getElementById("out").textContent=JSON.stringify({text:c.textContent,actions:!!c.querySelector(".psm-actions")});return;}'
                . 'if(++n<160)setTimeout(w,50);})();};f.src="/' . $base . '/map.php#node=hand.php";</script></body></html>');
            $vdom = (string) shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --use-angle=swiftshader --enable-unsafe-swiftshader'
                . ' --virtual-time-budget=15000 --dump-dom ' . escapeshellarg("$U/harness-visitor.html") . ' 2>/dev/null');
            preg_match('#<pre id="out">(.*?)</pre>#s', $vdom, $vm);
            $R['visitorCard'] = (array) json_decode(html_entity_decode($vm[1] ?? ''), true);
            $vt = (string) ($R['visitorCard']['text'] ?? '');
            check('visitor card: no metadata, no actions', $vt !== '' && !str_contains($vt, 'Modified') && !str_contains($vt, 'Origin')
                && !($R['visitorCard']['actions'] ?? true) && str_contains($vt, 'Open page'), $vt);
        }
    }
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    echo "\n$checks checks, $fails failed\n";
    if ($fails) { echo @file_get_contents("$tmp/cli-errors.log"); }
}
exit($fails > 0 ? 1 : 0);
