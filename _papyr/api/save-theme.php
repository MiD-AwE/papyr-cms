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

   Response (JSON):
     { "ok": true }  or  { "ok": false, "error": "..." }
================================================================ */

require __DIR__ . '/../vendor/autoload.php';

use Papyr\Core\Session;

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

/* Validate page name — filename only, no path separators */
$page = $body['page'] ?? '';
if (!is_string($page) || $page === '' || preg_match('/[\/\\\\]/', $page) || strlen($page) > 120) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid page name']));
}
$page = basename($page); // belt + suspenders

/* Validate theme payload */
$theme = $body['theme'] ?? null;
if (!is_array($theme) || empty($theme)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Missing or empty theme data']));
}

/* Sanitize theme values — accept only HSL component keys we expect */
$allowed = [
    'accent-h','accent-s','accent-l',
    'accent2-h','accent2-s','accent2-l',
    'bg-h','bg-s','bg-l',
    'text-h','text-s','text-l',
    'qr-h','qr-s','qr-l',
    'qr-bg-h','qr-bg-s','qr-bg-l',
    'mode',
];
$clean = [];
foreach ($allowed as $key) {
    if (isset($theme[$key])) {
        /* numeric keys: strip % and keep as string; mode key: 'dark' or 'light' only */
        if ($key === 'mode') {
            $clean[$key] = ($theme[$key] === 'light') ? 'light' : 'dark';
        } else {
            $val = preg_replace('/[^0-9.%]/', '', (string) $theme[$key]);
            $clean[$key] = $val;
        }
    }
}

/* Resolve theme directory — two dirs up from this file is the site root */
$themeDir = dirname(__DIR__, 2) . '/data/themes';
if (!is_dir($themeDir) && !mkdir($themeDir, 0755, true)) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Could not create themes directory']));
}

$themeFile = $themeDir . '/' . $page . '.json';
$written   = file_put_contents(
    $themeFile,
    json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

if ($written === false) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Could not write theme file']));
}

echo json_encode(['ok' => true, 'file' => 'data/themes/' . $page . '.json']);
