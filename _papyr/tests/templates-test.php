<?php
declare(strict_types=1);

/*
 * TemplateEngine's two loader roots (ADR-002 §7.3) and PageWriter on top of them.
 * Run:  php _papyr/tests/templates-test.php   (no server needed)
 *
 * Covers:
 *   - without {siteRoot}/_templates/ the engine root is the only root (Twig refuses missing dirs)
 *   - with it, the site copy wins on a name clash; other names fall through to the engine
 *   - a doubled engine-relative name (_papyr/templates/…) still self-corrects
 *   - PageWriter renders the site's template override and writes a normal file
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Papyr\Core\PageWriter;
use Papyr\Core\Scanner;
use Papyr\Core\TemplateEngine;

$engine = sys_get_temp_dir() . '/papyr-tpl-' . bin2hex(random_bytes(4));
$site   = "$engine-site";
register_shutdown_function(function () use ($engine, $site) { rmTree($engine); rmTree($site); });
mkdir("$engine/templates/sites/t", 0755, true);
mkdir($site, 0755, true);
file_put_contents("$engine/templates/sites/t/page.html.twig", 'engine:{{ title }}');
file_put_contents("$engine/templates/only-engine.twig", 'engine-only');

try {
    echo "roots\n";
    check('no _templates/: engine root only', TemplateEngine::rootsFor($site, $engine) === ["$engine/templates"]);
    $t = TemplateEngine::forSite($site, $engine);
    check('engine template renders', $t->render('sites/t/page.html.twig', ['title' => 'A']) === 'engine:A');

    mkdir("$site/_templates/sites/t", 0755, true);
    file_put_contents("$site/_templates/sites/t/page.html.twig", 'site:{{ title }}');
    check('_templates/ comes first', TemplateEngine::rootsFor($site, $engine) === ["$site/_templates", "$engine/templates"]);
    $t = TemplateEngine::forSite($site, $engine);
    check('site copy wins on a name clash', $t->render('sites/t/page.html.twig', ['title' => 'B']) === 'site:B');
    check('other names fall through to the engine', $t->render('only-engine.twig') === 'engine-only');
    check('doubled name self-corrects', $t->render('templates/only-engine.twig') === 'engine-only');
    check('extractVarNames reads the winning copy', $t->extractVarNames('sites/t/page.html.twig') === ['title']);

    echo "PageWriter\n";
    $manifest = "$engine/cache/manifest.json";
    mkdir(dirname($manifest), 0755, true);
    $scanner = new Scanner(['t' => ['root' => $site, 'label' => 'T']], $manifest, 0);
    $writer  = new PageWriter($scanner, 't', ['root' => $site, 'template' => 'sites/t/page.html.twig'], $engine);
    $writer->write('hello.php', ['title' => 'Hi']);
    check('writes with the site template', file_get_contents("$site/hello.php") === 'site:Hi');
    check('written page is cms_managed', ($scanner->getPages('t')['hello.php']['cms_managed'] ?? false) === true);
    check('_templates/ itself is invisible to the scanner', !array_filter(array_keys($scanner->getPages('t', true)), fn($k) => str_starts_with($k, '_templates')));
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
