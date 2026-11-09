<?php
declare(strict_types=1);

/* ================================================================
   Comments endpoint — participant-gated.

   Deployed at:   {site_root}/_papyr/api/comments.php
   Called by:     the comments partial via fetch()
   Stores in:     {site_root}/data/comments/{pageKey}.json

   GET  ?page=index&block=acoustic-larvicide
        → { "ok": true, "comments": [ … ], "admin": false }

   POST (JSON, all require "csrf"):
     { "action": "add",    "page": "…", "block": "…", "name": "…", "body": "…" }
     { "action": "delete", "page": "…", "block": "…", "id": "…" }      (admin only)

   Every request re-checks the session server-side. Hiding the
   thread in HTML is UX; this file is the real gate.
================================================================ */

require __DIR__ . '/../vendor/autoload.php';

use Papyr\Core\Comments;
use Papyr\Core\MemberAuth;

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function papyr_comments_fail(int $code, string $error): void
{
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $error]));
}

if (!MemberAuth::isMember()) {
    papyr_comments_fail(403, 'Unlock with your invite password to see and join the discussion.');
}

$isAdmin  = ($_SESSION['papyr_admin'] ?? false) === true;
$siteRoot = dirname(__DIR__, 2);
$store    = new Comments($siteRoot);
$method   = $_SERVER['REQUEST_METHOD'];

/* ---------- read ---------- */
if ($method === 'GET') {
    $page  = (string) ($_GET['page'] ?? '');
    $block = (string) ($_GET['block'] ?? '');
    if (!Comments::validKey($page) || !Comments::validKey($block)) {
        papyr_comments_fail(400, 'Invalid page or block');
    }
    exit(json_encode(['ok' => true, 'comments' => $store->thread($page, $block), 'admin' => $isAdmin]));
}

if ($method !== 'POST') {
    papyr_comments_fail(405, 'Method not allowed');
}

/* ---------- write ---------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    papyr_comments_fail(403, 'Cross-origin request refused');
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    papyr_comments_fail(400, 'Invalid JSON body');
}
if (!MemberAuth::checkCsrf($body['csrf'] ?? null)) {
    papyr_comments_fail(403, 'Your session expired. Reload the page and try again.');
}

$page  = (string) ($body['page'] ?? '');
$block = (string) ($body['block'] ?? '');
if (!Comments::validKey($page) || !Comments::validKey($block)) {
    papyr_comments_fail(400, 'Invalid page or block');
}

$action = $body['action'] ?? '';

if ($action === 'add') {
    /* One post per 10 s per session — enough for real conversation, too slow for floods. */
    $last = (int) ($_SESSION['papyr_last_comment'] ?? 0);
    if (!$isAdmin && time() - $last < 10) {
        papyr_comments_fail(429, 'Give it a few seconds before posting again.');
    }

    $name = trim(preg_replace('/\s+/u', ' ', (string) ($body['name'] ?? '')) ?? '');
    $text = trim(str_replace("\r\n", "\n", (string) ($body['body'] ?? '')));
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

    if ($name === '' || mb_strlen($name) > Comments::MAX_NAME) {
        papyr_comments_fail(400, 'Please add your name (up to ' . Comments::MAX_NAME . ' characters).');
    }
    if ($text === '' || mb_strlen($text) > Comments::MAX_BODY) {
        papyr_comments_fail(400, 'Comments can be 1 to ' . Comments::MAX_BODY . ' characters.');
    }

    try {
        $comment = $store->add($page, $block, $name, $text);
    } catch (\Throwable $e) {
        error_log('Papyr comments: ' . $e->getMessage());
        papyr_comments_fail(500, 'Could not save the comment. Is data/comments/ writable?');
    }

    MemberAuth::setDisplayName($name);
    $_SESSION['papyr_last_comment'] = time();
    exit(json_encode(['ok' => true, 'comment' => $comment, 'count' => count($store->thread($page, $block))]));
}

if ($action === 'delete') {
    if (!$isAdmin) {
        papyr_comments_fail(403, 'Only the site admin can remove comments.');
    }
    $id = (string) ($body['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        papyr_comments_fail(400, 'Invalid comment id');
    }
    $ok = $store->delete($page, $block, $id);
    exit(json_encode(['ok' => $ok, 'count' => count($store->thread($page, $block))]));
}

papyr_comments_fail(400, 'Unknown action');
