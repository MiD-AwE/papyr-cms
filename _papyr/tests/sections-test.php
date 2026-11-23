<?php
declare(strict_types=1);

/*
 * Section partials (ADR-002 §7.3): hero, cards, features, cta, rendered at
 * generation time through sections/list.html.twig.
 * Run:  php _papyr/tests/sections-test.php   (no server needed)
 *
 * Covers:
 *   - each section renders its data; output carries no Twig syntax
 *   - text is HTML-escaped; unsafe href schemes become "#"; site-relative
 *     links and images get the root prefix, absolute ones are kept
 *   - one <style> with the shared palette and each used section's CSS once;
 *     unknown section types are skipped
 *   - a site copy in _templates/sections/ overrides the engine's
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Papyr\Core\TemplateEngine;

$engineDir = dirname(__DIR__);
$site = sys_get_temp_dir() . '/papyr-sec-' . bin2hex(random_bytes(4));
mkdir($site, 0755, true);
register_shutdown_function(fn() => rmTree($site));

$sections = [
    ['type' => 'hero', 'kicker' => 'Acme', 'kicker_sub' => 'tools', 'headline' => "Build it.\nShip it.\nOwn it.",
     'lede' => 'Fish & <chips>', 'pills' => [['icon' => 'database', 'title' => 'No DB', 'text' => 'Flat files.'], ['icon' => 'nope', 'title' => 'X']],
     'image' => ['src' => 'assets/img/hero.webp', 'alt' => 'A "hero"', 'width' => 10, 'height' => 5]],
    ['type' => 'cards', 'title' => 'How', 'cards' => [['heading' => 'One', 'tone' => 'b', 'body' => 'b1', 'items' => ['x/', 'y.php'], 'note' => 'n1'],
     ['heading' => 'Two', 'tone' => 'evil"'], ['heading' => 'Three', 'tone' => 'c']], 'foot' => 'Foot'],
    ['type' => 'features', 'items' => [['icon' => 'code', 'title' => 'Just PHP', 'text' => 'Runs anywhere.']]],
    ['type' => 'cta', 'title' => 'Go', 'text' => 'Now', 'button' => ['label' => 'Start', 'href' => 'docs/overview.php'],
     'secondary' => ['label' => 'Bad', 'href' => ' JavaScript:alert(1)']],
    ['type' => 'cta', 'title' => 'Again', 'button' => ['label' => 'Ext', 'href' => 'https://example.com/a?b=1&c=2']],
    ['type' => '../../secret', 'title' => 'skip me'],
];

try {
    $t = TemplateEngine::forSite($site, $engineDir);
    $html = $t->render('sections/list.html.twig', ['sections' => $sections, 'root' => '../']);

    echo "markup\n";
    check('no Twig syntax left', !preg_match('/\{\{|\{%|\{#/', $html));
    check('hero: kicker and sub', str_contains($html, '<p class="ps-kicker">Acme</p>') && str_contains($html, 'ps-kicker-sub">tools<'));
    check('hero: one span per line, last accented', substr_count($html, '<span>Build it.</span>') === 1 && str_contains($html, '<span class="ps-accent">Own it.</span>'));
    check('hero: text escaped', str_contains($html, 'Fish &amp; &lt;chips&gt;'));
    check('hero: image gets the root prefix, alt escaped', str_contains($html, 'src="../assets/img/hero.webp" alt="A &quot;hero&quot;" width="10" height="5"'));
    check('hero: known icon drawn, unknown icon empty', substr_count($html, '<svg') >= 1 && str_contains($html, '<span class="ps-pill-icon"></span>'));
    check('cards: three cards, tones', substr_count($html, 'class="ps-card"') === 3 && str_contains($html, 'ps-tone-b') && str_contains($html, 'ps-tone-c'));
    check('cards: unknown tone falls back to a', !str_contains($html, 'evil') && substr_count($html, 'ps-tone-a"') === 1);
    check('cards: items and foot', str_contains($html, '<li>y.php</li>') && str_contains($html, 'ps-cards-foot">Foot<'));
    check('features: item', str_contains($html, '<strong>Just PHP</strong>Runs anywhere.'));
    check('cta: site-relative href prefixed', str_contains($html, 'href="../docs/overview.php"'));
    check('cta: javascript: href neutralised', str_contains($html, 'ps-btn--ghost" href="#"') && stripos($html, 'javascript') === false);
    check('cta: absolute URL kept (escaped)', str_contains($html, 'href="https://example.com/a?b=1&amp;c=2"'));
    check('unknown section type skipped', !str_contains($html, 'skip me'));

    echo "styles\n";
    check('one <style> block', substr_count($html, '<style>') === 1);
    check('shared palette included once', substr_count($html, '--ps-accent:') === 1);
    foreach (['hero', 'cards', 'features', 'cta'] as $type) {
        check("$type CSS included once", substr_count($html, "/* section: $type */") === 1);
    }
    $only = $t->render('sections/list.html.twig', ['sections' => [['type' => 'features', 'items' => []]]]);
    check('only used sections\' CSS', str_contains($only, '/* section: features */') && !str_contains($only, '/* section: hero */'));
    check('no sections: no output', trim($t->render('sections/list.html.twig', ['sections' => []])) === '');
    check('top-level page: no root prefix', str_contains($t->render('sections/list.html.twig', ['sections' => [$sections[3]]]), 'href="docs/overview.php"'));

    echo "site override\n";
    mkdir("$site/_templates/sections", 0755, true);
    file_put_contents("$site/_templates/sections/cta.html.twig", '<section class="site-cta">{{ s.title }}</section>');
    file_put_contents("$site/_templates/sections/cta.css", '/* site cta css */');
    $t = TemplateEngine::forSite($site, $engineDir);
    $html = $t->render('sections/list.html.twig', ['sections' => [$sections[3], $sections[2]]]);
    check('site section markup wins', str_contains($html, '<section class="site-cta">Go</section>') && !str_contains($html, 'class="ps-cta"'));
    check('site section CSS wins', str_contains($html, '/* site cta css */') && !str_contains($html, '/* section: cta */'));
    check('other sections still from the engine', str_contains($html, 'ps-feat-row'));
} catch (\Throwable $e) {
    check('no exception', false, $e->getMessage());
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
