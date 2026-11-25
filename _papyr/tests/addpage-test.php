<?php
declare(strict_types=1);

/*
 * Admin → Add Page in a real browser (ADR-002 §7.3 acceptance).
 * Run:  php _papyr/tests/addpage-test.php
 * Needs headless Chromium (as hsl-test.php); prints SKIP and exits 0 without it.
 *
 * A same-origin harness page carries the admin session id into the browser
 * (a script-set cookie of the same name; the real one is HttpOnly) and drives
 * the dashboard inside an iframe.
 *
 * Covers:
 *   - the form lists every starter, site starters included, with their fields;
 *     only the chosen layout's fields are active
 *   - the page name follows the title until edited; the path preview follows
 *   - Landing + own colours (typed hsl()) + draft → api/pages.php creates the
 *     page with its sections, the theme file holds the typed colour, the page
 *     is a draft, and the dashboard shows the new page with an open link
 *   - an existing path is refused with an inline message and nothing changes
 *   - the old template-variable form and its POST "create" are gone
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';

$chrome = getenv('PAPYR_TEST_CHROME') ?: '';
foreach (['chromium', 'chromium-browser', 'google-chrome'] as $c) {
    if ($chrome === '' && trim((string) shell_exec('command -v ' . $c . ' 2>/dev/null')) !== '') { $chrome = $c; }
}

[$tmp, $site, $base] = buildSite('test-admin-pass');
mkdir("$site/_templates/starters/research", 0755, true);
file_put_contents("$site/_templates/starters/research/starter.json",
    '{"label":"Research","description":"A site starter","fields":[{"name":"title","label":"Title","required":true},{"name":"question","label":"Question","type":"textarea"}]}');
file_put_contents("$site/_templates/starters/research/page.html.twig",
    "{% extends 'starters/_base.html.twig' %}{% block content %}<h1>{{ title }}</h1><p class=\"q\">{{ question }}</p>{% endblock %}");
file_put_contents("$site/existing.php", bootPage('<p>here first</p>'));

[$srv, $port] = startServer($tmp);
$U = "http://127.0.0.1:$port/$base";
$admin = adminCookie($U, 'test-admin-pass');

try {
    echo "dashboard HTML\n";
    [$st, , $html] = http("$U/_papyr/index.php", $admin);
    check('dashboard renders for admin', $st === 200, "HTTP $st");
    foreach (['landing', 'article', 'docs', 'blank', 'research'] as $n) {
        check("starter listed: $n", str_contains($html, 'name="layout" value="' . $n . '"'));
    }
    check('site starter marked', (bool) preg_match('#<b>Research</b> <span class="tag">site</span>#', $html));
    check('starter fields rendered', str_contains($html, 'data-layout="research"') && str_contains($html, 'name="question"'));
    check('shared HSL component included', str_contains($html, 'window.PapyrHsl'));
    check('old template-variable form gone', !str_contains($html, 'name="vars['));
    preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $cm);
    [$st, , $after] = http("$U/_papyr/index.php", $admin, ['csrf' => $cm[1] ?? '', 'action' => 'create', 'site' => 't', 'page_path' => 'old.php']);
    check('old POST create no longer writes', !file_exists("$site/old.php"));

    if ($chrome === '') {
        echo "SKIP  browser part: no headless Chromium on PATH\n";
    } else {
        echo "browser\n";
        [$cookieName, $cookieVal] = explode('=', $admin, 2);
        $harness = <<<HTML
<!DOCTYPE html><html><body><pre id="out">not run</pre>
<script>
document.cookie = "$cookieName=$cookieVal; path=/$base";
var R = {}, f = document.createElement('iframe');
f.style.width = '1000px'; f.style.height = '2000px';
document.body.appendChild(f);
function wait(test, then, n) { n = n || 0; if (test()) return then(); if (n > 100) return then('timeout'); setTimeout(function () { wait(test, then, n + 1); }, 50); }
var step = 0;
f.onload = function () {
  var d = f.contentDocument, w = f.contentWindow;
  if (step === 0) {
    step = 1;
    var form = d.querySelector('form.add-page-form');
    R.activeFieldsets = [].filter.call(form.querySelectorAll('.ap-fields'), function (x) { return !x.disabled; }).map(function (x) { return x.dataset.layout; });
    form.querySelector('input[value=landing]').click();
    R.afterSwitch = [].filter.call(form.querySelectorAll('.ap-fields'), function (x) { return !x.disabled; }).map(function (x) { return x.dataset.layout; });
    var fs = form.querySelector('.ap-fields[data-layout=landing]');
    var t = fs.querySelector('[data-title]');
    t.value = 'Café Launch Day!'; t.dispatchEvent(new Event('input', { bubbles: true }));
    R.slug = form.elements.slug.value;
    form.elements.dir.value = 'events'; form.elements.dir.dispatchEvent(new Event('input', { bubbles: true }));
    R.preview = form.querySelector('.ap-path').textContent;
    var h = fs.querySelector('[name=headline]'); if (h) h.value = 'Doors at nine';
    var own = form.querySelector('.ap-own'); own.click();
    R.sliderGroups = form.querySelectorAll('.ap-sliders .tp-group').length;
    var typed = form.querySelector('.ap-sliders .tp-group[data-token=accent] .tp-typed');
    typed.value = 'hsl(149 57% 43%)'; typed.dispatchEvent(new Event('input'));
    R.previewLink = form.querySelector('.ap-preview a').style.color;
    form.requestSubmit();
  } else if (step === 1) {
    step = 2;
    R.landed = f.contentWindow.location.search;
    R.flash = (d.querySelector('.flash') || {}).textContent || '';
    R.openLink = !!d.querySelector('.flash a[href$="/events/cafe-launch-day.php"]');
    // Second try at the same path: refused inline, no navigation.
    var form = d.querySelector('form.add-page-form');
    form.elements.slug.value = 'existing'; form.elements.slug.dispatchEvent(new Event('input', { bubbles: true }));
    form.querySelector('.ap-fields:not([disabled]) [data-title]').value = 'Again';
    form.requestSubmit();
    wait(function () { var m = form.querySelector('.ap-msg').textContent; return m && m !== 'Creating…'; }, function () {
      R.dupMsg = form.querySelector('.ap-msg').textContent;
      document.getElementById('out').textContent = JSON.stringify(R);
    });
  }
};
f.src = '/$base/_papyr/index.php';
</script></body></html>
HTML;
        file_put_contents("$site/harness.html", $harness);
        $dom = (string) shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --virtual-time-budget=10000 --dump-dom '
            . escapeshellarg("$U/harness.html") . ' 2>/dev/null');
        preg_match('#<pre id="out">(.*?)</pre>#s', $dom, $m);
        $R = (array) json_decode(html_entity_decode($m[1] ?? ''), true);
        check('browser ran the flow', isset($R['dupMsg']), substr($m[1] ?? '', 0, 200));
        check('one layout active at a time', count($R['activeFieldsets'] ?? []) === 1 && ($R['afterSwitch'] ?? []) === ['landing'], json_encode([$R['activeFieldsets'] ?? null, $R['afterSwitch'] ?? null]));
        check('page name follows the title (accents folded)', ($R['slug'] ?? '') === 'cafe-launch-day', (string) ($R['slug'] ?? ''));
        check('path preview', ($R['preview'] ?? '') === 'events/cafe-launch-day.php', (string) ($R['preview'] ?? ''));
        check('colour groups from ThemeStore::defaults()', ($R['sliderGroups'] ?? 0) === 4, (string) ($R['sliderGroups'] ?? ''));
        check('typed colour reaches the preview', str_starts_with((string) ($R['previewLink'] ?? ''), 'rgb('), (string) ($R['previewLink'] ?? ''));

        $page = (string) @file_get_contents("$site/events/cafe-launch-day.php");
        check('page created in the new folder', $page !== '' && str_contains($page, 'class="ps-hero') && str_contains($page, 'Doors at nine'));
        check('… as a draft', str_contains($page, '<!-- papyr:draft -->'));
        $theme = json_decode((string) @file_get_contents("$site/data/themes/events_cafe-launch-day.json"), true);
        check('… in the typed colour', ($theme['accent-h'] ?? '') === '149' && ($theme['accent-s'] ?? '') === '57%' && ($theme['accent-l'] ?? '') === '43%', json_encode($theme));
        check('dashboard says so, with an open link', str_contains((string) ($R['flash'] ?? ''), 'Created events/cafe-launch-day.php as a draft') && ($R['openLink'] ?? false), (string) ($R['flash'] ?? ''));
        check('existing path refused inline', str_contains((string) ($R['dupMsg'] ?? ''), 'existing.php already exists'), (string) ($R['dupMsg'] ?? ''));
        check('… existing page untouched', str_contains((string) file_get_contents("$site/existing.php"), 'here first'));
    }
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
