<?php
declare(strict_types=1);

/* ================================================================
   Theme persistence endpoint.

   Deployed at:   {site_root}/_papyr/api/save-theme.php
   Called by:     saveTheme() in theme-panel.php via fetch()
   Writes to:     {site_root}/data/themes/{page}.json

   POST body (JSON):
     { "page": "index", "csrf": "…", "theme": { "accent-h": "194", ... } }

   Gate: admin session + CSRF token + same-origin (Papyr\Core\Session).
   Write: Papyr\Core\ThemeStore::write() — the only theme writer.

   Response (JSON):
     { "ok": true }  or  { "ok": false, "error": "..." }
================================================================ */

require __DIR__ . '/../vendor/autoload.php';

use Papyr\Core\Session;
use Papyr\Core\ThemeStore;

Session::start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/* Auth gate — admin session required to write. */
if (!Session::isAdmin()) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

/* Only accept POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

/* Parse JSON body */
$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid JSON body']));
}

Session::requireSameOrigin();
if (!Session::checkCsrf($body['csrf'] ?? null)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.']));
}

/* Validate and write through the one theme writer (Papyr\Core\ThemeStore). */
$page  = $body['page'] ?? '';
$theme = $body['theme'] ?? null;
if (!is_string($page) || !ThemeStore::isValidKey($page)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid page name']));
}
if (!is_array($theme) || $theme === []) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Missing or empty theme data']));
}

try {
    $file = (new ThemeStore(Session::siteRoot()))->write($page, $theme);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
} catch (\RuntimeException $e) {
    error_log('[PapyrCMS] save-theme: ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}

echo json_encode(['ok' => true, 'file' => $file]);
