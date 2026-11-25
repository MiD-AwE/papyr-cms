<?php
declare(strict_types=1);

/*
 * Page writes (ADR-002 Phase E2): PageWriter's create / setDraft / trash.
 * Run:  php _papyr/tests/pages-test.php
 *
 * Covers:
 *   - create: writes a managed page from a starter; refuses an existing file or
 *     folder (409, untouched); refuses non-.php and sealed paths
 *   - no PHP from web fields: "<?php", "<?=" and "<?" in any field (html
 *     included) come out as "&lt;?"; php -l clean; a template that would
 *     re-open PHP from a field is refused by the output check
 *   - setDraft: demote puts the marker after <body> (else <head>, else after the
 *     opening PHP block); promote removes it; no-ops return false; a page with
 *     nowhere to put it in the first 4 KB is refused and left untouched; the
 *     "manually edited" flag is kept as it was
 *   - trash: moves to _trash/{stamp}_{path_with_underscores}, deny rule in place,
 *     name clashes get -2, the page leaves the manifest; sealed paths refused
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Papyr\Core\PageWriter;
use Papyr\Core\Scanner;
use Papyr\Core\Starters;

[$tmp, $site, $base] = buildSite('test-admin-pass');
$engine = "$site/_papyr";
ini_set('error_log', "$tmp/cli-errors.log");

$code = function (callable $fn): int {
    try { $fn(); return 0; } catch (\RuntimeException $e) { return $e->getCode() ?: -1; } catch (\InvalidArgumentException $e) { return -2; }
};
$lint = fn(string $f): bool => str_contains((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1'), 'No syntax errors');

try {
    $scanner = new Scanner(['t' => ['root' => $site, 'label' => 'T', 'ignore_files' => ['nav.php']]], "$engine/cache/manifest.json", 0);
    $writer  = new PageWriter($scanner, 't', ['root' => $site, 'template' => 'sites/papyr-cms/page.html.twig'], $engine);
    $starters = new Starters($site, $engine);
    $tpl = fn(string $n) => $starters->get($n)['template'];

    echo "create\n";
    $writer->create('new/hello.php', $starters->vars('article', ['title' => 'Hello', 'body_content' => '<p>Hi</p>']), $tpl('article'));
    check('page written', is_file("$site/new/hello.php"));
    check('cms_managed', ($scanner->getPages('t')['new/hello.php']['cms_managed'] ?? false) === true);
    $before = file_get_contents("$site/new/hello.php");
    check('existing file → 409', $code(fn() => $writer->create('new/hello.php', ['title' => 'X'], $tpl('blank'))) === PageWriter::ERR_EXISTS);
    check('existing file untouched', file_get_contents("$site/new/hello.php") === $before);
    mkdir("$site/taken.php");
    check('folder named like the page → 409', $code(fn() => $writer->create('taken.php', ['title' => 'X'], $tpl('blank'))) === PageWriter::ERR_EXISTS);
    check('non-.php refused', $code(fn() => $writer->create('x.html', ['title' => 'X'], $tpl('blank'))) === -2);
    check('sealed path refused', $code(fn() => $writer->create('_config/x.php', ['title' => 'X'], $tpl('blank'))) === -2);
    check('traversal refused', $code(fn() => $writer->create('../x.php', ['title' => 'X'], $tpl('blank'))) === -2);

    echo "no PHP from web fields\n";
    $evil = '<?php echo "PWNED-A"; ?> <?= "PWNED-B" ?> <? echo "PWNED-C"; ?>';
    $writer->create('evil.php', $starters->vars('article', ['title' => $evil, 'standfirst' => $evil, 'body_content' => $evil]), $tpl('article'));
    $out = (string) file_get_contents("$site/evil.php");
    check('php -l clean', $lint("$site/evil.php"));
    check('html field neutralised', str_contains($out, '&lt;?php echo "PWNED-A"; ?>') && str_contains($out, '&lt;?= "PWNED-B" ?>') && str_contains($out, '&lt;? echo "PWNED-C"; ?>'));
    $ran = shell_exec('cd ' . escapeshellarg($site) . ' && ' . escapeshellarg(PHP_BINARY) . ' -d short_open_tag=1 evil.php 2>&1');
    // Run, every PWNED stays inside its own quoted literal; executed, it would print bare.
    check('no field code runs (even with short_open_tag on)', substr_count((string) $ran, 'PWNED') >= 3 && !preg_match('/(?<!")(?<!&quot;)PWNED-[ABC]/', (string) $ran));
    check('only the starter\'s own PHP blocks', substr_count($out, '<?') === substr_count((string) file_get_contents("$site/new/hello.php"), '<?'));
    // A template that undoes the escaping must still be caught by the output check.
    mkdir("$site/_templates/starters/undo", 0755, true);
    file_put_contents("$site/_templates/starters/undo/starter.json", '{"label":"Undo","fields":[{"name":"title"},{"name":"body","type":"html"}]}');
    file_put_contents("$site/_templates/starters/undo/page.html.twig", "<?php /* boot */ ?>\n<h1>{{ title }}</h1>{{ body|replace({'&lt;': '<'})|raw }}");
    $undo = new Starters($site, $engine);
    // Loader roots are fixed per PageWriter (one per request in real use); _templates/ is new here.
    $writer = new PageWriter($scanner, 't', ['root' => $site, 'template' => 'sites/papyr-cms/page.html.twig'], $engine);
    check('re-opened PHP → refused (422)', $code(fn() => $writer->create('undo.php', $undo->vars('undo', ['title' => 'U', 'body' => '<?php echo 1; ?>']), $undo->get('undo')['template'])) === PageWriter::ERR_REFUSED);
    check('refused page not written', !file_exists("$site/undo.php"));
    $writer->create('undo-ok.php', $undo->vars('undo', ['title' => 'U', 'body' => '<p>fine</p>']), $undo->get('undo')['template']);
    check('same template, harmless field → written', is_file("$site/undo-ok.php"));

    echo "setDraft\n";
    $p = 'new/hello.php';
    check('demote', $writer->setDraft($p, true) === true);
    $src = (string) file_get_contents("$site/$p");
    check('marker right after the real <body> tag', str_contains($src, "<body>\n<!-- papyr:draft -->\n<?php if (function_exists('papyr_nav'))"));
    check('the boot block\'s comment left alone', substr_count($src, 'papyr:draft') === 1);
    check('Scanner sees the draft', ($scanner->getPages('t', true)[$p]['draft'] ?? false) === true);
    check('demote again → no-op', $writer->setDraft($p, true) === false);
    check('still not "manually edited"', empty($scanner->getPages('t', true)[$p]['manually_edited']));
    check('php -l clean after demote', $lint("$site/$p"));
    check('promote', $writer->setDraft($p, false) === true);
    check('marker gone, file as before', (string) file_get_contents("$site/$p") === $before);
    check('promote again → no-op', $writer->setDraft($p, false) === false);

    // A hand edit is remembered through a later promote/demote.
    file_put_contents("$site/$p", str_replace('Hello', 'Hello again', $before));
    touch("$site/$p", time() + 5);
    $scanner->getPages('t', true);
    $writer->setDraft($p, true);
    check('"manually edited" kept when it was set', ($scanner->getPages('t', true)[$p]['manually_edited'] ?? false) === true);

    file_put_contents("$site/hand.php", "<?php\n// a hand-made page\n?>\n<html><head><title>Hand</title></head><body class=\"x\">\n<p>hi</p>\n</body></html>\n");
    check('hand-made page: demote works', $writer->setDraft('hand.php', true) && str_contains((string) file_get_contents("$site/hand.php"), "<body class=\"x\">\n<!-- papyr:draft -->"));
    check('hand-made page stays unmanaged', empty($scanner->getPages('t', true)['hand.php']['cms_managed']));
    file_put_contents("$site/nobody.php", "<?php\n\$x = 1;\n?>\n<h1>No body tag</h1>\n");
    check('no <body>: after the opening PHP block', $writer->setDraft('nobody.php', true) && str_contains((string) file_get_contents("$site/nobody.php"), "?>\n<!-- papyr:draft -->\n<h1>"));
    check('… still lints', $lint("$site/nobody.php"));
    file_put_contents("$site/plain.html", "<h1>Plain</h1>\n");
    check('plain HTML: marker on top', $writer->setDraft('plain.html', true) && str_starts_with((string) file_get_contents("$site/plain.html"), "<!-- papyr:draft -->\n<h1>"));
    $deep = "<html><head><title>Deep</title><style>" . str_repeat('/* padding */', 400) . "</style></head><body>\n<p>x</p></body></html>";
    file_put_contents("$site/deep.php", str_replace('<head>', '<head>', $deep));
    check('long <head>: marker goes after <head>', $writer->setDraft('deep.php', true) && str_contains((string) file_get_contents("$site/deep.php"), "<head>\n<!-- papyr:draft -->"));
    $nowhere = "<?php\n" . str_repeat("// filler\n", 500) . "echo '<html><body>x</body></html>';\n";
    file_put_contents("$site/nowhere.php", $nowhere);
    check('no spot in the first 4 KB → 422', $code(fn() => $writer->setDraft('nowhere.php', true)) === PageWriter::ERR_REFUSED);
    check('… file untouched', file_get_contents("$site/nowhere.php") === $nowhere);
    file_put_contents("$site/inline.php", "<html><body><!-- papyr:draft --><p>x</p></body></html>");
    check('promote: inline marker removed, line kept', $writer->setDraft('inline.php', false) && file_get_contents("$site/inline.php") === '<html><body><p>x</p></body></html>');
    check('missing page → 404', $code(fn() => $writer->setDraft('nope.php', true)) === PageWriter::ERR_NOT_FOUND);
    check('sealed path refused', $code(fn() => $writer->setDraft('_papyr/index.php', true)) === -2);

    echo "trash\n";
    $t = $writer->trash('evil.php');
    check('moved into _trash/', (bool) preg_match('#^_trash/\d{8}-\d{6}_evil\.php$#', $t) && is_file("$site/$t") && !file_exists("$site/evil.php"), $t);
    check('deny rule in _trash/', is_file("$site/_trash/.htaccess"));
    check('left the manifest', !isset($scanner->getPages('t')['evil.php']));
    $t2 = $writer->trash('new/hello.php');
    check('nested path flattened with underscores', str_ends_with($t2, '_new_hello.php'), $t2);
    file_put_contents("$site/evil.php", 'again');
    $t3 = $writer->trash('evil.php');
    check('name clash in the same second → -2', $t3 !== $t && is_file("$site/$t") && is_file("$site/$t3")
        && (substr($t3, 7, 15) !== substr($t, 7, 15) || str_ends_with($t3, '_evil-2.php')), $t3);
    check('missing page → 404', $code(fn() => $writer->trash('evil.php')) === PageWriter::ERR_NOT_FOUND);
    check('sealed source refused', $code(fn() => $writer->trash('_trash/x.php')) === -2);
    check('no absolute path in any message', !str_contains((string) (function () use ($writer) {
        try { $writer->trash('nope.php'); } catch (\Throwable $e) { return $e->getMessage(); } return '';
    })(), $tmp));
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    echo "\n$checks checks, $fails failed\n";
    if ($fails) { echo @file_get_contents("$tmp/cli-errors.log"); }
    rmTree($tmp);
}
exit($fails > 0 ? 1 : 0);
