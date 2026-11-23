<?php
declare(strict_types=1);

/*
 * Page starters (ADR-002 §7.3): discovery, manifest validation, PageWriter
 * output, and the generated pages served over HTTP.
 * Run:  php _papyr/tests/starters-test.php
 *
 * Covers:
 *   - the four engine starters (landing, article, docs, blank) are found and valid
 *   - refusals: bad folder name, missing template, broken JSON, reserved/duplicate
 *     field, unknown type, no title field; a broken site copy does not fall back
 *   - a site starter in _templates/starters/ is listed and wins on a name clash
 *   - vars(): undeclared keys dropped, defaults, required fields
 *   - each starter writes a page that begins with "<?php", passes php -l, has no
 *     Twig left, is cms_managed, carries the draft marker only when asked, and
 *     prefixes site-relative links with the right root
 *   - over HTTP: the landing page renders with nav and its sections; it still
 *     renders with the starter folder removed, and with _papyr/ removed
 *   - a site starter survives replacing _papyr/
 *   - PageWriter errors name site-relative paths only; the full path is logged
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Papyr\Core\PageWriter;
use Papyr\Core\Scanner;
use Papyr\Core\Starters;

[$tmp, $site, $base] = buildSite('test-admin-pass');
$engine = "$site/_papyr";
$logFile = "$tmp/php-errors.log";
ini_set('error_log', $logFile);

$starter = function (string $root, string $name, ?string $json, ?string $tpl = '{% extends \'starters/_base.html.twig\' %}{% block content %}<p>{{ title }}</p>{% endblock %}') {
    @mkdir("$root/$name", 0755, true);
    if ($json !== null) { file_put_contents("$root/$name/starter.json", $json); }
    if ($tpl !== null) { file_put_contents("$root/$name/page.html.twig", $tpl); }
};
$title = '{"name":"title","type":"text","required":true}';

try {
    echo "discovery\n";
    $s = new Starters($site, $engine);
    check('engine starters found', array_keys($s->all()) === ['article', 'blank', 'docs', 'landing']);
    check('all engine-sourced', array_unique(array_column($s->all(), 'source')) === ['engine']);
    check('landing manifest: label, fields, template', ($s->get('landing')['label'] ?? '') === 'Landing'
        && ($s->get('landing')['fields'][0]['name'] ?? '') === 'title' && ($s->get('landing')['template'] ?? '') === 'starters/landing/page.html.twig');
    check('no filesystem path in a manifest', !str_contains(json_encode($s->all()), $tmp));

    echo "validation\n";
    $er = "$engine/templates/starters";
    $starter($er, 'Bad_Name', '{"label":"x","fields":[' . $title . ']}');
    $starter($er, 'no-tpl', '{"label":"x","fields":[' . $title . ']}', null);
    $starter($er, 'broken-json', '{"label":');
    $starter($er, 'reserved', '{"label":"x","fields":[' . $title . ',{"name":"root"}]}');
    $starter($er, 'dupe', '{"label":"x","fields":[' . $title . ',' . $title . ']}');
    $starter($er, 'bad-type', '{"label":"x","fields":[' . $title . ',{"name":"x","type":"php"}]}');
    $starter($er, 'no-title', '{"label":"x","fields":[{"name":"body","type":"html"}]}');
    $starter($er, 'no-label', '{"fields":[' . $title . ']}');
    $s = new Starters($site, $engine);
    foreach (['Bad_Name', 'no-tpl', 'broken-json', 'reserved', 'dupe', 'bad-type', 'no-title', 'no-label'] as $bad) {
        check("refused: $bad", $s->get($bad) === null);
        rmTree("$er/$bad");
    }
    check('refusals are logged', substr_count((string) @file_get_contents($logFile), "refused") >= 8);

    echo "site starters\n";
    $sr = "$site/_templates/starters";
    $starter($sr, 'research', '{"label":"Research page","fields":[' . $title . ',{"name":"finding","type":"textarea","default":"TBD"}]}');
    $starter($sr, 'blank', '{"label":"Site blank","fields":[' . $title . ']}', '{% extends \'starters/_base.html.twig\' %}{% block content %}<p class="site-blank">{{ title }}</p>{% endblock %}');
    $starter($sr, 'docs', '{"label":', null);
    $s = new Starters($site, $engine);
    check('site starter listed', ($s->get('research')['source'] ?? '') === 'site');
    check('site copy wins on a name clash', ($s->get('blank')['label'] ?? '') === 'Site blank' && $s->get('blank')['source'] === 'site');
    check('broken site copy does not fall back to the engine', $s->get('docs') === null);
    rmTree("$sr/docs");

    echo "vars()\n";
    $v = $s->vars('research', ['title' => '  Mosquito trial  ', 'evil' => 'x', 'finding' => '']);
    check('trimmed, undeclared dropped, default used', $v === ['title' => 'Mosquito trial', 'finding' => 'TBD']);
    $threw = '';
    try { $s->vars('research', ['title' => '   ']); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage(); }
    check('required field enforced', str_contains($threw, 'Required'));
    $threw = false;
    try { $s->vars('nope', []); } catch (\InvalidArgumentException) { $threw = true; }
    check('unknown starter refused', $threw);

    echo "PageWriter\n";
    $scanner = new Scanner(['t' => ['root' => $site, 'label' => 'T', 'ignore_files' => ['nav.php']]], "$engine/cache/manifest.json", 0);
    $writer  = new PageWriter($scanner, 't', ['root' => $site, 'template' => 'sites/papyr-cms/page.html.twig'], $engine);
    check('rootPrefix', PageWriter::rootPrefix('a.php') === '' && PageWriter::rootPrefix('docs/a.php') === '../' && PageWriter::rootPrefix('a/b/c.php') === '../../');
    $s = new Starters($site, $engine);
    $inputs = [
        'landing' => ['title' => 'Launch <Day>', 'headline' => "Fast.\nSimple.", 'cta_href' => 'docs/overview.php', 'cta_label' => 'Read', 'hero_image' => 'assets/img/x.webp'],
        'article' => ['title' => 'An article', 'standfirst' => 'Short', 'body_content' => '<p>Body <b>bold</b></p>'],
        'docs'    => ['title' => 'A doc', 'body_content' => '<pre><code>x</code></pre>'],
        'blank'   => ['title' => 'Blank site'],
        'research'=> ['title' => 'Research'],
    ];
    foreach ($inputs as $name => $in) {
        $rel = "made/$name.php";
        $writer->write($rel, $s->vars($name, $in) + ['draft' => $name === 'docs'], $s->get($name)['template']);
        $out = (string) file_get_contents("$site/$rel");
        $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$site/$rel") . ' 2>&1');
        check("$name: begins with <?php", str_starts_with($out, '<?php'));
        check("$name: php -l clean", str_contains((string) $lint, 'No syntax errors'), trim((string) $lint));
        check("$name: no Twig left", !preg_match('/\{\{|\{%|\{#/', $out));
        check("$name: draft marker " . ($name === 'docs' ? 'present' : 'absent'), str_contains($out, '<!-- papyr:draft -->') === ($name === 'docs'));
    }
    $pages = $scanner->getPages('t', true);
    check('written pages are cms_managed', ($pages['made/landing.php']['cms_managed'] ?? false) === true);
    check('draft page flagged by Scanner', ($pages['made/docs.php']['draft'] ?? false) === true);
    check('Scanner title from <title>', ($pages['made/landing.php']['title'] ?? '') === 'Launch <Day>' || ($pages['made/landing.php']['title'] ?? '') === 'Launch &lt;Day&gt;');
    $landing = (string) file_get_contents("$site/made/landing.php");
    check('landing: title escaped', str_contains($landing, '<title>Launch &lt;Day&gt;</title>'));
    check('landing: links prefixed with ../', str_contains($landing, 'href="../docs/overview.php"') && str_contains($landing, 'src="../assets/img/x.webp"'));
    check('landing: four sections', str_contains($landing, 'class="ps-hero') && str_contains($landing, 'class="ps-cards"')
        && str_contains($landing, 'class="ps-feats"') && str_contains($landing, 'class="ps-cta"'));
    check('landing: placeholder copy fills empty fields', str_contains($landing, 'How it works'));
    check('article: html body kept raw', str_contains((string) file_get_contents("$site/made/article.php"), '<p>Body <b>bold</b></p>'));
    check('site blank starter used', str_contains((string) file_get_contents("$site/made/blank.php"), 'class="site-blank"'));

    echo "errors: site-relative in the message, full path in the log\n";
    $err = function (callable $fn): string {
        try { $fn(); return ''; } catch (\RuntimeException $e) { return $e->getMessage(); }
    };
    mkdir("$site/locked", 0555);
    $msg = $err(fn() => $writer->write('locked/sub/page.php', ['title' => 'x'], $s->get('blank')['template']));
    check('unwritable folder: message names locked/sub', str_contains($msg, 'locked/sub'), $msg);
    check('unwritable folder: no absolute path in message', $msg !== '' && !str_contains($msg, $tmp), $msg);
    check('unwritable folder: absolute path logged', str_contains((string) @file_get_contents($logFile), "$site/locked/sub"));
    chmod("$site/locked", 0755);
    $msg = $err(fn() => $writer->write('made/x.php', ['title' => 'x'], 'starters/nope/page.html.twig'));
    check('missing template: message names the template', str_contains($msg, 'starters/nope/page.html.twig'), $msg);
    check('missing template: no absolute path in message', $msg !== '' && !str_contains($msg, $tmp), $msg);
    check('missing template: Twig detail (with paths) logged', str_contains((string) @file_get_contents($logFile), "$engine/templates"));
    check('missing template: nothing written', !file_exists("$site/made/x.php"));

    echo "HTTP\n";
    [$srv, $port] = startServer($tmp);
    $U = "http://127.0.0.1:$port/$base";
    [$st, , $html] = http("$U/made/landing.php");
    check('landing renders', $st === 200, "HTTP $st");
    check('with the nav', str_contains($html, 'window.PAPYR'));
    check('and its sections', str_contains($html, 'ps-hero') && str_contains($html, 'Fast.'));
    check('no PHP warnings in the page', !preg_match('/(Warning|Notice|Fatal error|Deprecated):/', $html));

    rename("$engine/templates/starters", "$tmp/starters-away");
    [$st, , $html] = http("$U/made/landing.php");
    check('still renders with the starter folder removed', $st === 200 && str_contains($html, 'ps-hero'), "HTTP $st");
    rename("$tmp/starters-away", "$engine/templates/starters");

    rename($engine, "$tmp/engine-away");
    [$st, , $html] = http("$U/made/landing.php");
    check('renders as plain PHP with _papyr/ removed', $st === 200 && str_contains($html, 'ps-hero') && !str_contains($html, 'window.PAPYR'), "HTTP $st");
    check('no fatal without the engine', !preg_match('/Fatal error/', $html));
    copyTree("$tmp/engine-away", $engine);   // "replace _papyr/ with a fresh copy"
    check('site starter survives replacing _papyr/', (new Starters($site, $engine))->get('research') !== null);
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
