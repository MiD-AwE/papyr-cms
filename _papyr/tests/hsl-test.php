<?php
declare(strict_types=1);

/*
 * The shared HSL component (partials/hsl-sliders.php) in a real browser.
 * Run:  php _papyr/tests/hsl-test.php
 * Needs headless Chromium (chromium, chromium-browser or google-chrome on PATH,
 * or PAPYR_TEST_CHROME); prints SKIP and exits 0 without it.
 *
 * Covers:
 *   - PapyrHsl.parse: "149 57% 43%", "149, 57%, 43%", "hsl(149 57% 43%)",
 *     "hsl(149, 57%, 43%)", "#2fae6b", "#2a6"; clamping; rejects
 *   - hsl(149 57% 43%) and its hex equivalent give the same sliders (±1)
 *   - a built group: typing moves the sliders and writes through the host's
 *     write(); invalid input leaves them alone and shows a message; Enter
 *     never submits the surrounding form; moving a slider rewrites the field
 *   - the theme panel host: one typed field per discovered group; typing
 *     changes :root; groups the page does not define are not built
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';

$chrome = getenv('PAPYR_TEST_CHROME') ?: '';
foreach (['chromium', 'chromium-browser', 'google-chrome'] as $c) {
    if ($chrome === '' && trim((string) shell_exec('command -v ' . $c . ' 2>/dev/null')) !== '') { $chrome = $c; }
}
if ($chrome === '') { echo "SKIP  no headless Chromium on PATH\n"; exit(0); }

[$tmp, $site, $base] = buildSite('test-admin-pass');

$js = <<<'JS'
(function () {
  var R = {};
  function q(s) { return JSON.stringify(PapyrHsl.parse(s)); }
  R.parse = {
    spaces: q('149 57% 43%'), commas: q('149, 57%, 43%'), fnSpaces: q('hsl(149 57% 43%)'),
    fnCommas: q('hsl(149, 57%, 43%)'), hex6: q('#2fae6b'), hex3: q('#2a6'), noPct: q('149 57 43'),
    upper: q('HSL(149 57% 43%)'), clampHi: q('400 120% 50%'), deg: q('149deg 57% 43%'),
    bad1: q('nope'), bad2: q('149 57%'), bad3: q('#12345'), bad4: q('hsl(149 57% 43%'), bad5: q('149 57% -4%'),
    bad6: q('rgb(1,2,3)'), empty: q('')
  };
  // hsl(149 57% 43%) ↔ hex: compute the hex here, then parse it back.
  function hslToHex(h, s, l) {
    s /= 100; l /= 100;
    var k = function (n) { return (n + h / 30) % 12; }, a = s * Math.min(l, 1 - l);
    var f = function (n) { return l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1))); };
    return '#' + [f(0), f(8), f(4)].map(function (x) { return ('0' + Math.round(x * 255).toString(16)).slice(-2); }).join('');
  }
  R.hex = hslToHex(149, 57, 43);
  R.hexBack = PapyrHsl.parse(R.hex);

  // A host with its own store (as Add Page uses it).
  var store = { accent: { h: '10', s: '20%', l: '30%' } }, writes = 0, submitted = 0;
  var form = document.createElement('form');
  form.addEventListener('submit', function (e) { e.preventDefault(); submitted++; });
  var host = document.createElement('div'); form.appendChild(host); document.body.appendChild(form);
  var c = PapyrHsl.build(host, {
    tokens: ['accent'], label: function () { return 'Accent'; },
    read: function (n, p) { return store[n][p]; }, write: function (n, p, v) { store[n][p] = v; writes++; }
  });
  var g = host.querySelector('.tp-group'), typed = g.querySelector('.tp-typed'), msg = g.querySelector('.tp-typed-msg');
  var sl = function () { return [].map.call(g.querySelectorAll('input[type=range]'), function (i) { return +i.value; }); };
  R.initial = { sliders: sl(), typed: typed.value };
  typed.focus();
  typed.value = 'hsl(149 57% 43%)'; typed.dispatchEvent(new Event('input'));
  R.typedFn = { sliders: sl(), store: JSON.parse(JSON.stringify(store.accent)), swatch: g.querySelector('.tp-swatch').style.background };
  typed.value = R.hex; typed.dispatchEvent(new Event('input'));
  R.typedHex = sl();
  var before = sl().join(','), w = writes;
  typed.value = 'bogus'; typed.dispatchEvent(new Event('input'));
  R.badWhileTyping = { same: sl().join(',') === before, msg: msg.textContent, writes: writes - w };
  typed.dispatchEvent(new Event('change'));
  R.badOnChange = { same: sl().join(',') === before, msg: msg.textContent, bad: typed.classList.contains('is-bad') };
  typed.value = '#2a6';
  typed.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
  R.enter = { submitted: submitted, sliders: sl(), msg: msg.textContent };
  typed.blur();
  var h = g.querySelector('input[type=range]'); h.value = 200; h.dispatchEvent(new Event('input'));
  R.sliderRewritesField = typed.value;
  R.values = c.values();

  // The theme panel host (built by papyr_nav on this page).
  var panel = document.querySelectorAll('#tp-token-groups .tp-group');
  R.panel = { groups: [].map.call(panel, function (x) { return x.dataset.token; }),
              typedFields: document.querySelectorAll('#tp-token-groups .tp-typed').length };
  var acc = document.querySelector('#tp-token-groups .tp-group[data-token="accent"] .tp-typed');
  if (acc) {
    acc.focus(); acc.value = '#2fae6b'; acc.dispatchEvent(new Event('input'));
    var cs = getComputedStyle(document.documentElement);
    R.panel.root = [cs.getPropertyValue('--accent-h').trim(), cs.getPropertyValue('--accent-s').trim(), cs.getPropertyValue('--accent-l').trim()];
  }
  document.getElementById('out').textContent = JSON.stringify(R);
}());
JS;

file_put_contents("$site/hsltest.php", bootPage('<pre id="out">not run</pre><script>window.addEventListener("load", function () {' . $js . '});</script>'));
[$srv, $port] = startServer($tmp);
$dom = (string) shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --virtual-time-budget=4000 --dump-dom '
    . escapeshellarg("http://127.0.0.1:$port/$base/hsltest.php") . ' 2>/dev/null');
preg_match('#<pre id="out">(.*?)</pre>#s', $dom, $m);
$R = json_decode(html_entity_decode($m[1] ?? ''), true);

try {
    check('browser ran the checks', is_array($R), substr($m[1] ?? $dom, 0, 200));
    $R = (array) $R;
    $want = '{"h":149,"s":57,"l":43}';
    echo "parse\n";
    foreach (['spaces', 'commas', 'fnSpaces', 'fnCommas', 'noPct', 'upper', 'deg'] as $k) {
        check("parse $k", ($R['parse'][$k] ?? '') === $want, (string) ($R['parse'][$k] ?? ''));
    }
    $near = function (?array $v, array $t): bool {
        return $v !== null && abs($v['h'] - $t[0]) <= 1 && abs($v['s'] - $t[1]) <= 1 && abs($v['l'] - $t[2]) <= 1;
    };
    check('parse #2fae6b', $near(json_decode($R['parse']['hex6'] ?? 'null', true), [148, 57, 43]), (string) ($R['parse']['hex6'] ?? ''));
    check('parse #2a6 (= #22aa66)', $near(json_decode($R['parse']['hex3'] ?? 'null', true), [150, 67, 40]), (string) ($R['parse']['hex3'] ?? ''));
    check('clamped to 360 / 100%', ($R['parse']['clampHi'] ?? '') === '{"h":360,"s":100,"l":50}', (string) ($R['parse']['clampHi'] ?? ''));
    foreach (['bad1', 'bad2', 'bad3', 'bad4', 'bad5', 'bad6', 'empty'] as $k) {
        check("reject $k", ($R['parse'][$k] ?? '') === 'null', (string) ($R['parse'][$k] ?? ''));
    }
    check('hsl(149 57% 43%) and ' . ($R['hex'] ?? '?') . ' agree (±1)', $near($R['hexBack'] ?? null, [149, 57, 43]), json_encode($R['hexBack'] ?? null));

    echo "component\n";
    check('initial sliders from read()', ($R['initial']['sliders'] ?? []) === [10, 20, 30] && ($R['initial']['typed'] ?? '') === '10 20% 30%', json_encode($R['initial'] ?? null));
    check('typing moves the sliders live', ($R['typedFn']['sliders'] ?? []) === [149, 57, 43]);
    check('… and writes through the host', ($R['typedFn']['store'] ?? []) === ['h' => '149', 's' => '57%', 'l' => '43%'], json_encode($R['typedFn']['store'] ?? null));
    check('… swatch follows', str_contains((string) ($R['typedFn']['swatch'] ?? ''), 'rgb('), (string) ($R['typedFn']['swatch'] ?? ''));
    $hs = $R['typedHex'] ?? [0, 0, 0];
    check('hex gives the same sliders (±1)', abs($hs[0] - 149) <= 1 && abs($hs[1] - 57) <= 1 && abs($hs[2] - 43) <= 1, json_encode($hs));
    check('invalid while typing: sliders untouched, no nagging', ($R['badWhileTyping']['same'] ?? false) && ($R['badWhileTyping']['msg'] ?? 'x') === '' && ($R['badWhileTyping']['writes'] ?? 1) === 0);
    check('invalid on change: message shown, sliders untouched', ($R['badOnChange']['same'] ?? false) && ($R['badOnChange']['bad'] ?? false) && ($R['badOnChange']['msg'] ?? '') !== '');
    check('Enter applies and does not submit the form', ($R['enter']['submitted'] ?? 1) === 0 && ($R['enter']['sliders'] ?? []) === [150, 67, 40] && ($R['enter']['msg'] ?? 'x') === '', json_encode($R['enter'] ?? null));
    check('moving a slider rewrites the field', ($R['sliderRewritesField'] ?? '') === '200 67% 40%', (string) ($R['sliderRewritesField'] ?? ''));
    check('values() reports the group', ($R['values']['accent'] ?? null) === ['h' => 200, 's' => 67, 'l' => 40]);

    echo "theme panel host\n";
    $groups = $R['panel']['groups'] ?? [];
    check('panel built groups from the page tokens', in_array('accent', $groups, true) && in_array('bg', $groups, true), json_encode($groups));
    check('one typed field per group', ($R['panel']['typedFields'] ?? 0) === count($groups) && $groups !== []);
    $hx = json_decode($R['parse']['hex6'] ?? 'null', true) ?? ['h' => -1, 's' => -1, 'l' => -1];
    check('typing in the panel changes :root', ($R['panel']['root'] ?? []) === [(string) $hx['h'], $hx['s'] . '%', $hx['l'] . '%'], json_encode($R['panel']['root'] ?? null));
    check('no group for a token the page lacks', !in_array('nonexistent', $groups, true));
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
