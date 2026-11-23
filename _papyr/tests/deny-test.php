<?php
declare(strict_types=1);

/*
 * Deny rules under a real Apache (PHP's built-in server ignores .htaccess).
 * Run:  php _papyr/tests/deny-test.php
 * Needs XAMPP's httpd + mod_php (/opt/lampp, or PAPYR_TEST_LAMPP); runs
 * unprivileged on a free port. Prints SKIP and exits 0 when Apache is absent.
 *
 * Covers (each must be 403, and PHP inside must never run):
 *   - Twig sources in _papyr/templates/ (tracked .htaccess) — other files there still 200
 *   - Twig sources in a hand-made _templates/ — rule added on the first page boot,
 *     an existing .htaccess left alone
 *   - _config/ (sites.json, secret.php), data/comments/*.json, _trash/ (any file)
 *   - plugin entries and manifests (Phase D rule), plugin assets still 200
 *   - a normal page still renders with the nav under Apache
 */

if (PHP_SAPI !== 'cli') { exit('CLI only.'); }

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Papyr\Core\Comments;
use Papyr\Core\DenyRule;
use Papyr\Core\PageWriter;
use Papyr\Core\Scanner;

[$tmp, $site, $base] = buildSite('test-admin-pass');
ini_set('error_log', "$tmp/cli-errors.log");
[$port, $skip] = startApache($tmp);
if ($skip !== null) { echo "SKIP  $skip\n"; rmTree($tmp); exit(0); }
$U = "http://127.0.0.1:$port/$base";
$status = fn(string $path) => http("$U/$path")[0];

try {
    file_put_contents("$site/plain.php", bootPage('<p>plain</p>'));
    mkdir("$site/_templates/starters/mine", 0755, true);
    file_put_contents("$site/_templates/starters/mine/page.html.twig", '{{ secret_template }}');
    file_put_contents("$site/_templates/starters/mine/starter.json", '{"label":"Mine","fields":[{"name":"title"}]}');

    echo "control\n";
    check('_templates/ has no rule before any page boots', !is_file("$site/_templates/.htaccess"));
    [$st, , $html] = http("$U/plain.php");
    check('normal page renders under Apache, with the nav', $st === 200 && str_contains($html, 'window.PAPYR'), "HTTP $st");

    echo "templates\n";
    check('engine page template (.twig): 403', $status('_papyr/templates/sites/papyr-cms/page.html.twig') === 403);
    check('engine starter (.twig): 403', $status('_papyr/templates/starters/landing/page.html.twig') === 403);
    check('engine section (.twig): 403', $status('_papyr/templates/sections/hero.html.twig') === 403);
    rename("$site/_papyr/templates/.htaccess", "$tmp/ht-away");
    check('negative control: without the rule the same file is 200', $status('_papyr/templates/sections/hero.html.twig') === 200);
    rename("$tmp/ht-away", "$site/_papyr/templates/.htaccess");
    check('rule is narrow: tokens.css still 200', $status('_papyr/templates/cms/tokens.css') === 200);
    check('first page boot wrote _templates/.htaccess', is_file("$site/_templates/.htaccess"));
    check('site template (.twig): 403', $status('_templates/starters/mine/page.html.twig') === 403);
    file_put_contents("$site/_templates/.htaccess", "# hand-edited\n");
    http("$U/plain.php");
    check('an existing _templates/.htaccess is never overwritten', file_get_contents("$site/_templates/.htaccess") === "# hand-edited\n");

    echo "site-owned data\n";
    check('_config/.htaccess present', is_file("$site/_config/.htaccess"));
    check('_config/sites.json: 403', $status('_config/sites.json') === 403);
    check('_config/secret.php: 403', $status('_config/secret.php') === 403);

    (new Comments($site))->add('plain', 'demo', 'Tester', 'hello');
    check('data/comments/ created with its rule', is_file("$site/data/comments/.htaccess") && is_file("$site/data/comments/plain.json"));
    check('data/comments/plain.json: 403', $status('data/comments/plain.json') === 403);

    $scanner = new Scanner(['t' => ['root' => $site, 'label' => 'T']], "$site/_papyr/cache/manifest.json", 0);
    $trash = (new PageWriter($scanner, 't', ['root' => $site], "$site/_papyr"))->trashDir();
    file_put_contents("$trash/20261123-120000_old.php", '<?php echo "RAN";');
    file_put_contents("$trash/20261123-120000_notes.txt", 'old');
    [$st, , $body] = http("$U/_trash/20261123-120000_old.php");
    check('_trash/*.php: 403 and never executed', $st === 403 && !str_contains($body, 'RAN'), "HTTP $st");
    check('_trash/*.txt: 403', $status('_trash/20261123-120000_notes.txt') === 403);
    check('_trash/ listing: 403', $status('_trash/') === 403);

    echo "plugins (Phase D rule)\n";
    check('plugin entry: 403', $status('_papyr/plugins/site-map/plugin.php') === 403);
    check('plugin manifest: 403', $status('_papyr/plugins/site-map/plugin.json') === 403);
    check('plugin asset: 200', $status('_papyr/plugins/site-map/site-map.js') === 200);

    echo "DenyRule\n";
    check('body() for a file pattern wraps FilesMatch', str_contains(DenyRule::body('x', '\.twig$'), '<FilesMatch "\.twig$">'));
    check('ensure() refuses a missing folder', @DenyRule::ensure("$tmp/nope", 'x') === false);
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    echo "\n$checks checks, $fails failed\n";
}
exit($fails > 0 ? 1 : 0);
