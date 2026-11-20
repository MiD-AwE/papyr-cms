<?php
declare(strict_types=1);

/*
 * Plugin loader, papyr_plugin(), context hooks and the site-map plugin,
 * over real HTTP against a throw-away subfolder site (see lib.php).
 *
 *   php _papyr/tests/plugins-test.php
 *
 * Covers (ADR-002 Phase D):
 *   - papyr_plugin('site-map') renders the map; assets come from the plugin folder
 *   - boot-only plugins from sites.json register map.nodes providers:
 *     nodes attach (also chained, in any order), private nodes reach admin
 *     only, unsafe links / orphans / duplicates are dropped, a throwing
 *     provider never breaks the page, boot output is discarded
 *   - refused plugins render nothing and log why: bad name, name/folder
 *     mismatch, engine too old, missing entry, entry escaping the folder
 *   - plugin scope is exactly $p, $options, $plugin
 *   - $p->asset(): plugin-relative URLs, unsafe names refused
 *   - a site plugin overrides a core plugin of the same name
 *   - Plugins::satisfies() constraint grammar
 *   - a plugin entry requested directly over HTTP answers 404 (entry guard)
 *   - with _papyr/ removed, a page calling papyr_plugin() still renders
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';

[$tmp, $site, $base] = buildSite('test-admin-pass', [], ['plugins' => ['demo-nodes', 'noisy']]);

/* ---------- site plugins ---------- */
function plugin(string $site, string $dir, array $manifest, ?string $entryCode, string $entry = 'plugin.php'): void {
    @mkdir("$site/_plugins/$dir", 0755, true);
    file_put_contents("$site/_plugins/$dir/plugin.json", json_encode($manifest));
    if ($entryCode !== null) { file_put_contents("$site/_plugins/$dir/$entry", $entryCode); }
}
$ok = fn(string $n, array $extra = []) => array_merge(['name' => $n, 'version' => '1.0.0', 'requires' => ['papyr' => '>=0.2.0'], 'entry' => 'plugin.php'], $extra);

plugin($site, 'demo-nodes', $ok('demo-nodes'), <<<'PHP'
<?php
$p->provide('map.nodes', fn($p, $args) => [
    ['id' => 'd:chain',   'parent' => 'd:public', 'text' => 'Chained item', 'status' => 'caution'],   // parent listed later
    ['id' => 'd:public',  'parent' => 'about.php', 'text' => 'Public item', 'status' => 'proven', 'link' => 'about.php#x'],
    ['id' => 'd:private', 'parent' => 'about.php', 'text' => 'Secret item', 'visibility' => 'private'],
    ['id' => 'd:evil',    'parent' => 'about.php', 'text' => 'Evil link', 'link' => 'javascript:alert(1)'],
    ['id' => 'd:far',     'parent' => 'about.php', 'text' => 'Offsite', 'link' => '//evil.example/x'],
    ['id' => 'd:orphan',  'parent' => 'nope.php',  'text' => 'Orphan item'],
    ['id' => 'd:public',  'parent' => 'about.php', 'text' => 'Duplicate item'],
    ['id' => 'd:weird',   'parent' => 'about.php', 'text' => 'Weird status', 'status' => 'nonsense'],
    'not-a-node',
]);
$p->provide('map.nodes', function () { throw new RuntimeException('provider boom'); });
PHP);
plugin($site, 'noisy', $ok('noisy'), "<?php echo 'NOISE-FROM-BOOT';\n");
plugin($site, 'scope-probe', $ok('scope-probe'),
    "<?php echo '<pre id=\"probe\">' . htmlspecialchars(json_encode(['vars' => array_keys(get_defined_vars()), 'options' => \$options, 'plugin' => \$plugin, 'ctx' => \$p instanceof Papyr\\Core\\Context])) . '</pre>';\n");
plugin($site, 'mismatch', $ok('other'), "<?php echo 'MISMATCH-RAN';\n");
plugin($site, 'too-new', $ok('too-new', ['requires' => ['papyr' => '>=9.0.0']]), "<?php echo 'TOO-NEW-RAN';\n");
plugin($site, 'no-entry', $ok('no-entry', ['entry' => 'missing.php']), null);
plugin($site, 'escape', $ok('escape', ['entry' => '../demo-nodes/plugin.php']), null);
plugin($site, 'broken-json', [], null);
file_put_contents("$site/_plugins/broken-json/plugin.json", '{ not json');

/* ---------- pages ---------- */
file_put_contents("$site/about.php", bootPage('<p>about</p>'));
// Must exist before the first scan: the Scanner caches the manifest for scan_ttl_seconds.
file_put_contents("$site/wip.php", "<!-- papyr:draft -->\n<html><head><title>Work in progress</title></head><body></body></html>\n");
file_put_contents("$site/map.php", bootPage("<?php papyr_plugin('site-map'); ?>"));
file_put_contents("$site/plugins.php", bootPage(
    "<div id=\"refused\"><?php foreach (['Bad_Name', 'mismatch', 'too-new', 'no-entry', 'escape', 'broken-json', 'nope'] as \$n) papyr_plugin(\$n); ?></div>\n"
    . "<?php papyr_plugin('scope-probe', ['height' => '80vh']); ?>\n"
    . "<?php papyr_plugin('demo-nodes'); ?><div id=\"after-boot-only\"></div>\n"
    . "<pre id=\"assets\"><?= htmlspecialchars(json_encode([papyr()->asset('site-map', 'three/three.min.js'), papyr()->asset('site-map', '../x.js'), papyr()->asset('site-map', '/etc/passwd'), papyr()->asset('nope', 'a.js')])) ?></pre>"
));

[$srv, $port] = startServer($tmp);
$U   = "http://127.0.0.1:$port/$base";
$log = fn() => (string) @file_get_contents("$tmp/server.log");
$mapData = function (string $html): array {
    return preg_match('#<script type="application/json" id="psm-data">(.*?)</script>#s', $html, $m) ? (array) json_decode($m[1], true) : [];
};
$find = function (array $node, string $id) use (&$find): ?array {
    if (($node['id'] ?? null) === $id) return $node;
    foreach ($node['children'] ?? [] as $c) { if (($r = $find($c, $id)) !== null) return $r; }
    return null;
};
$parentOf = function (array $node, string $id) use (&$parentOf): ?string {
    foreach ($node['children'] ?? [] as $c) {
        if (($c['id'] ?? null) === $id) return $node['id'];
        if (($r = $parentOf($c, $id)) !== null) return $r;
    }
    return null;
};

try {
    /* ---------- the map as a plugin, visitor ---------- */
    echo "site-map plugin (visitor)\n";
    [$st, , $html] = http("$U/map.php");
    check('map.php renders', $st === 200, "HTTP $st");
    check('map stage present', str_contains($html, 'id="psm-stage"'));
    foreach (['three/three.min.js', 'three/OrbitControls.js', 'site-map.js'] as $a) {
        check("asset $a linked from the plugin folder", str_contains($html, "src=\"/$base/_papyr/plugins/site-map/$a\""));
        [$ast] = http("$U/_papyr/plugins/site-map/$a");
        check("asset $a served", $ast === 200, "HTTP $ast");
    }
    check('old engine asset path no longer used', !str_contains($html, '/_papyr/assets/'));
    [$dst, , $dbody] = http("$U/_papyr/plugins/site-map/plugin.php");
    check('plugin entry requested directly: 404, no output', $dst === 404 && trim($dbody) === '', "HTTP $dst");
    $d = $mapData($html);
    $root = $d['root'] ?? [];
    check('map payload parses', $root !== []);
    check('boot provider node attached under its page', $parentOf($root, 'd:public') === 'about.php');
    check('chained node attached under a provider node (any order)', $parentOf($root, 'd:chain') === 'd:public');
    check('status maps to its fixed colour', ($find($root, 'd:public')['color'] ?? null) == ['h' => 149, 's' => 57, 'l' => 55]);
    check('unknown status falls back to open', ($find($root, 'd:weird')['status'] ?? '') === 'open');
    check('safe link kept', ($find($root, 'd:public')['link'] ?? '') === 'about.php#x');
    check('javascript: link removed', ($find($root, 'd:evil') ?? ['link' => 'x'])['link'] === null && !str_contains($html, 'javascript:alert'));
    check('protocol-relative link removed', ($find($root, 'd:far') ?? ['link' => 'x'])['link'] === null && !str_contains($html, 'evil.example'));
    check('private node hidden from visitors', $find($root, 'd:private') === null && !str_contains($html, 'Secret item'));
    check('orphan node dropped', $find($root, 'd:orphan') === null);
    check('duplicate id dropped (first wins)', ($find($root, 'd:public')['text'] ?? '') === 'Public item' && !str_contains($html, 'Duplicate item'));
    check('status legend shown when provider nodes exist', str_contains($html, '>proven</span>'));
    check('throwing provider logged, page still rendered', str_contains($log(), "provider #1 for 'map.nodes' failed: provider boom"));
    check('boot output discarded', !str_contains($html, 'NOISE-FROM-BOOT') && str_contains($log(), "plugin 'noisy': output during boot was discarded"));
    check('no filesystem path in the map', !str_contains($html, $tmp));
    check('drafts not on the visitor map', !str_contains($html, '"draft":true'));

    /* ---------- admin ---------- */
    echo "site-map plugin (admin)\n";
    $cookie = adminCookie($U, 'test-admin-pass');
    [$st, , $html] = http("$U/map.php", $cookie);
    $root = $mapData($html)['root'] ?? [];
    check('admin session survives boot plugins (noisy one included)', ($mapData($html)['admin'] ?? false) === true);
    check('private node shown to admin', ($find($root, 'd:private')['visibility'] ?? '') === 'private');
    check('drafts on the admin map', str_contains($html, 'Work in progress'));
    [, , $vhtml] = http("$U/map.php");
    check('draft still hidden from visitors', !str_contains($vhtml, 'Work in progress'));

    /* ---------- loader rules ---------- */
    echo "loader\n";
    [$st, , $html] = http("$U/plugins.php");
    check('plugins page renders', $st === 200, "HTTP $st");
    check('refused plugins render nothing', (bool) preg_match('#<div id="refused"></div>#', $html));
    $l = $log();
    foreach ([
        "plugin '?' refused: invalid plugin name"                          => 'bad name refused',
        "plugin 'mismatch' refused: site plugin.json name does not match"  => 'name/folder mismatch refused',
        "plugin 'too-new' refused: requires papyr >=9.0.0"                 => 'engine version mismatch refused',
        "plugin 'no-entry' refused: site entry missing.php is missing"     => 'missing entry refused',
        "plugin 'escape' refused: site entry must be a .php path inside"   => 'entry escaping the folder refused',
        "plugin 'broken-json' refused: site plugin.json is not valid JSON" => 'broken manifest refused',
        "plugin 'nope' refused: not found"                                 => 'unknown plugin refused',
    ] as $needle => $label) {
        check($label, str_contains($l, $needle));
    }
    check('no refused plugin code ran', !str_contains($html, 'MISMATCH-RAN') && !str_contains($html, 'TOO-NEW-RAN'));
    preg_match('#<pre id="probe">(.*?)</pre>#s', $html, $m);
    $probe = (array) json_decode(html_entity_decode($m[1] ?? '{}'), true);
    check('plugin scope is exactly $p, $options, $plugin', ($probe['vars'] ?? []) === ['p', 'options', 'plugin'], json_encode($probe['vars'] ?? null));
    check('$options passed through', ($probe['options'] ?? []) === ['height' => '80vh']);
    check('$plugin describes the render call', ($probe['plugin'] ?? []) === ['name' => 'scope-probe', 'version' => '1.0.0', 'mode' => 'render']);
    check('$p is the context', ($probe['ctx'] ?? false) === true);
    check('papyr_plugin() on a boot-only plugin does not run it again', substr_count($l, 'provider boom') >= 1
        && !str_contains($html, 'NOISE-FROM-BOOT'));
    preg_match('#<pre id="assets">(.*?)</pre>#s', $html, $m);
    $assets = (array) json_decode(html_entity_decode($m[1] ?? '[]'), true);
    check('asset() builds the plugin URL', ($assets[0] ?? '') === "/$base/_papyr/plugins/site-map/three/three.min.js");
    check('asset() refuses .. and absolute names', ($assets[1] ?? 'x') === '' && ($assets[2] ?? 'x') === '');
    check('asset() of an unknown plugin is empty', ($assets[3] ?? 'x') === '');

    /* ---------- constraint grammar (in-process) ---------- */
    echo "version constraints\n";
    require "$site/_papyr/vendor/autoload.php";
    $S = [Papyr\Core\Plugins::class, 'satisfies'];
    foreach ([
        ['0.2.0', '>=0.2.0', true], ['0.2.0', '>0.2.0', false], ['0.2.0', '*', true], ['0.2.0', '', true],
        ['0.2.0', '^0.2', true], ['0.3.0', '^0.2', false], ['1.4.0', '^1.2', true], ['2.0.0', '^1.2', false],
        ['0.2.5', '~0.2', true], ['0.3.0', '~0.2', false], ['0.2.0', '>=0.1 <0.3', true], ['0.3.0', '>=0.1, <0.3', false],
        ['0.2.0', '=0.2.0', true], ['0.2.0', '!=0.2.0', false], ['0.2.0', 'banana', false],
    ] as [$have, $c, $want]) {
        check("'$have' vs '$c' → " . ($want ? 'yes' : 'no'), $S($have, $c) === $want);
    }

    /* ---------- a site plugin overrides the core one ---------- */
    echo "override\n";
    plugin($site, 'site-map', $ok('site-map'), "<?php echo '<p id=\"site-override\">site copy</p>';\n");
    [, , $html] = http("$U/map.php");
    check('site _plugins/site-map wins over core', str_contains($html, 'id="site-override"') && !str_contains($html, 'id="psm-stage"'));
    [, , $html] = http("$U/plugins.php");
    preg_match('#<pre id="assets">(.*?)</pre>#s', $html, $m);
    $assets = (array) json_decode(html_entity_decode($m[1] ?? '[]'), true);
    check('asset() follows the override', ($assets[0] ?? '') === "/$base/_plugins/site-map/three/three.min.js", (string) ($assets[0] ?? ''));

    /* ---------- engine removed ---------- */
    echo "engine removed\n";
    rename("$site/_papyr", "$site/_papyr.off");
    [$st, , $html] = http("$U/map.php");
    check('map.php still renders as plain PHP', $st === 200 && str_contains($html, '</html>'), "HTTP $st");
    check('no fatal in the log', !preg_match('/fatal/i', substr($log(), -2000)));
    rename("$site/_papyr.off", "$site/_papyr");
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
