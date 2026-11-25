<?php
declare(strict_types=1);

/* ================================================================
   Page writes from the map and the dashboard (ADR-002 §7.1, Phase E2).

   Deployed at:   {site_root}/_papyr/api/pages.php
   Called by:     the site map card and Add Page, via fetch()

   POST body (JSON), every request carries "csrf":
     { "action": "create",  "dir": "docs", "slug": "intro", "layout": "article",
       "fields": { "title": "…", … }, "draft": true, "theme": { "accent-h": "149", … } }
     { "action": "promote", "path": "docs/intro.php" }    removes the draft marker
     { "action": "demote",  "path": "docs/intro.php" }    adds the draft marker
     { "action": "trash",   "path": "docs/intro.php" }    pages created in Papyr only

   Gate: admin session + CSRF + same-origin (Papyr\Core\Session).
   Writes: only through PageWriter (create never overwrites; no PHP from
   fields) and ThemeStore (the one theme writer).

   Response (JSON):
     { "ok": true, "action": "…", "path": "…", "node": {…}|null, "root": {…},
       "trashed"?: "_trash/…", "warning"?: "…" }
     { "ok": false, "error": "…" }   errors name site-relative paths only
   "root" is the whole map graph after the write, as the site map builds it
   for an admin, so the map can update without a reload.
================================================================ */

require __DIR__ . '/../vendor/autoload.php';

use Papyr\Core\MapTree;
use Papyr\Core\NavBuilder;
use Papyr\Core\PageWriter;
use Papyr\Core\Session;
use Papyr\Core\Starters;
use Papyr\Core\ThemeStore;

Session::start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function papyr_pages_fail(int $code, string $error): never
{
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

if (!Session::isAdmin()) {
    papyr_pages_fail(403, 'Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    papyr_pages_fail(405, 'Method not allowed');
}
Session::requireSameOrigin();

$body = json_decode((string) file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    papyr_pages_fail(400, 'Invalid JSON body');
}
if (!Session::checkCsrf($body['csrf'] ?? null)) {
    papyr_pages_fail(403, 'Your session expired. Reload the page and try again.');
}

require dirname(__DIR__) . '/bootstrap.php';        // $config, $sites, $scanner
require_once dirname(__DIR__) . '/api/load-theme.php';

$siteKey  = (string) array_key_first($sites);       // single managed site, as nav.php
$siteConf = $sites[$siteKey] ?? [];
$siteRoot = rtrim((string) ($siteConf['root'] ?? ''), '/');
if ($siteRoot === '' || !is_dir($siteRoot)) {
    error_log('[PapyrCMS] pages.php: site root not found for ' . $siteKey);
    papyr_pages_fail(500, 'Site root not found. Check _config/sites.json.');
}
$engineDir = dirname(__DIR__);
$writer    = new PageWriter($scanner, $siteKey, $siteConf, $engineDir);
$themes    = new ThemeStore($siteRoot);
$action    = (string) ($body['action'] ?? '');

/** The map graph for an admin, the same shape the site-map plugin renders. */
$graph = static function () use ($scanner, $siteKey, $siteConf, $siteRoot, $config, $themes): array {
    $defaults = $themes->defaults();
    $themeFor = static function (string $rel) use ($siteRoot, $defaults): array {
        $saved  = papyr_load_theme($siteRoot . '/' . $rel, $siteRoot);
        $values = array_merge($defaults, $saved);
        return [
            'themed' => isset($saved['accent-h']),
            'color'  => ['h' => (float) $values['accent-h'], 's' => (float) $values['accent-s'], 'l' => (float) $values['accent-l']],
        ];
    };
    $label = (string) ($siteConf['label'] ?? ($config['cms']['name'] ?? 'Papyr CMS'));
    return MapTree::fromNav(NavBuilder::tree($scanner->getPages($siteKey), true), $label, $themeFor);
};

/** The graph node that shows $path (an index page folds into its folder), or null. */
$find = static function (array $node, string $path) use (&$find): ?array {
    if (($node['path'] ?? null) === $path) {
        $copy = $node;
        unset($copy['children']);
        return $copy;
    }
    foreach ($node['children'] ?? [] as $child) {
        $hit = $find($child, $path);
        if ($hit !== null) {
            return $hit;
        }
    }
    return null;
};

$respond = static function (string $path, array $extra = []) use ($graph, $find, $action): never {
    $root = $graph();
    echo json_encode(
        ['ok' => true, 'action' => $action, 'path' => $path, 'node' => $find($root, $path), 'root' => $root] + $extra,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
};

/** An existing page's path from the request, checked against the manifest. */
$pagePath = static function () use ($body, $scanner, $siteKey): array {
    $path = $body['path'] ?? null;
    if (!is_string($path) || $path === '' || strlen($path) > 255) {
        papyr_pages_fail(400, 'A page path is required.');
    }
    $path  = ltrim($path, '/');
    $pages = $scanner->getPages($siteKey, true);
    if (!isset($pages[$path])) {
        papyr_pages_fail(404, "No page at {$path}.");
    }
    return [$path, $pages[$path]];
};

try {
    switch ($action) {
        case 'create':
            $dir = trim((string) ($body['dir'] ?? ''), '/');
            if ($dir !== '' && !preg_match('#^[a-z0-9][a-z0-9-]{0,59}(/[a-z0-9][a-z0-9-]{0,59}){0,4}$#', $dir)) {
                papyr_pages_fail(400, 'Folder names use a–z, 0–9 and "-" (up to 5 levels).');
            }
            $slug = preg_replace('/\.php$/i', '', trim((string) ($body['slug'] ?? '')));
            if (!is_string($slug) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $slug)) {
                papyr_pages_fail(400, 'The page name uses a–z, 0–9 and "-", and starts with a letter or digit.');
            }
            $path = ($dir === '' ? '' : $dir . '/') . $slug . '.php';

            $starters = new Starters($siteRoot, $engineDir);
            $layout   = (string) ($body['layout'] ?? 'blank');
            $starter  = $starters->get($layout);
            if ($starter === null) {
                papyr_pages_fail(400, "Unknown layout: {$layout}");
            }
            $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
            if (!isset($fields['title']) && isset($body['title'])) {
                $fields['title'] = $body['title'];
            }
            $vars = $starters->vars($layout, $fields) + ['draft' => ($body['draft'] ?? false) === true];

            // Validate the theme before anything is written.
            $theme = null;
            if (isset($body['theme']) && $body['theme'] !== []) {
                $theme = is_array($body['theme']) ? ThemeStore::sanitize($body['theme']) : [];
                if ($theme === []) {
                    papyr_pages_fail(400, 'The theme has no recognised values.');
                }
            }

            $writer->create($path, $vars, $starter['template']);

            $extra = [];
            if ($theme !== null) {
                try {
                    $extra['theme'] = $themes->write(pathinfo(str_replace('/', '_', $path), PATHINFO_FILENAME), $theme);
                } catch (\Throwable $e) {
                    error_log('[PapyrCMS] pages.php: theme write after create failed: ' . $e->getMessage());
                    $extra['warning'] = "The page was created, but its colours could not be saved. Use the theme panel's Commit on the new page.";
                }
            }
            $respond($path, $extra);

        case 'promote':
        case 'demote':
            [$path] = $pagePath();
            $changed = $writer->setDraft($path, $action === 'demote');
            $respond($path, ['changed' => $changed]);

        case 'trash':
            [$path, $row] = $pagePath();
            // cms_managed decides what Papyr offers (a flag, not a lock): hand-made
            // files are moved by hand, never by a click.
            if (empty($row['cms_managed'])) {
                papyr_pages_fail(409, "{$path} is a hand-made page. Papyr only moves pages it created; move this one yourself.");
            }
            $respond($path, ['trashed' => $writer->trash($path)]);

        default:
            papyr_pages_fail(400, 'Unknown action');
    }
} catch (\InvalidArgumentException $e) {
    // Starters::vars() and guardPath(): messages name fields or the submitted path only.
    papyr_pages_fail(400, $e->getMessage());
} catch (\RuntimeException $e) {
    // PageWriter's messages are site-relative by contract (PageWriter::fail()).
    $status = in_array($e->getCode(), [PageWriter::ERR_EXISTS, PageWriter::ERR_NOT_FOUND, PageWriter::ERR_REFUSED], true) ? $e->getCode() : 500;
    if ($status === 500 && !str_starts_with($e->getMessage(), 'PageWriter: ')) {
        error_log('[PapyrCMS] pages.php: ' . $e->getMessage());
        papyr_pages_fail(500, 'The page could not be written. The details are in the PHP error log.');
    }
    papyr_pages_fail($status, $e->getMessage());
} catch (\Throwable $e) {
    error_log('[PapyrCMS] pages.php: ' . get_class($e) . ': ' . $e->getMessage());
    papyr_pages_fail(500, 'The page could not be written. The details are in the PHP error log.');
}
