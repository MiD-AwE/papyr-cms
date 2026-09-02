<?php
declare(strict_types=1);

/*
 * AI task proxy — called by the admin AI panel via JS fetch.
 * Always returns JSON: { ok, text, provider, channel } | { ok: false, error }
 *
 * Data-routing rules (per CLAUDE.md §Core Philosophy):
 *   cloud tasks  → strip_tags() before sending — no source code to cloud
 *   local tasks  → raw file content (stays on-device)
 */

/* Auth gate — admin session required. AI calls spend Cerebras credits and
   are a cost-abuse vector left open, so this must reject before any task
   runs, not just hide the panel in the UI. */
session_start();
if (!($_SESSION['papyr_admin'] ?? false)) {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function jsonOut(array $payload, int $status = 200): void
{
    if ($status !== 200) {
        http_response_code($status);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$task    = (string) ($_POST['task']  ?? $_GET['task']  ?? '');
$siteKey = (string) ($_POST['site']  ?? $_GET['site']  ?? '');
$relPath = (string) ($_POST['path']  ?? $_GET['path']  ?? '');

$cloudTasks = ['social_media_draft', 'meta_description', 'page_summary'];
$localTasks = ['content_review'];
$allTasks   = [...$cloudTasks, ...$localTasks];

if (!in_array($task, $allTasks, true)) {
    jsonOut(['ok' => false, 'error' => 'Invalid task. Allowed: ' . implode(', ', $allTasks)], 400);
}

if (!isset($sites[$siteKey])) {
    jsonOut(['ok' => false, 'error' => 'Unknown site key.'], 400);
}

if ($relPath === '' || str_contains($relPath, '..') || str_starts_with($relPath, '/')) {
    jsonOut(['ok' => false, 'error' => 'Invalid page path.'], 400);
}

$siteRoot = rtrim($sites[$siteKey]['root'] ?? '', '/');
$absPath  = $siteRoot . '/' . $relPath;

if (!file_exists($absPath)) {
    jsonOut(['ok' => false, 'error' => 'Page file not found.'], 404);
}

// Read file — cap at 8 KB for prompt context
$raw = (string) file_get_contents($absPath, false, null, 0, 8192);

// Cloud tasks: strip PHP and HTML so no source code leaves the machine
$textContent = in_array($task, $cloudTasks, true)
    ? trim(strip_tags($raw))
    : $raw;

// Page title from manifest (avoids re-parsing the file)
$pages = $scanner->getPages($siteKey);
$page  = $pages[$relPath] ?? [];
$title = (string) ($page['title'] ?? pathinfo($relPath, PATHINFO_FILENAME));

try {
    $result = match($task) {
        'social_media_draft' => $ai->draftSocialPost($title, $textContent),
        'meta_description'   => $ai->suggestMeta($title, $textContent),
        'page_summary'       => $ai->run('page_summary',
            "Summarize this page in 2–3 sentences. Return only the summary, no preamble.\n\n"
            . "Title: {$title}\n\nContent:\n{$textContent}"),
        'content_review'     => $ai->run('content_review',
            "Review this page for quality, clarity, and completeness. "
            . "Return a concise bullet-list of observations.\n\n"
            . "Title: {$title}\n\nContent:\n{$textContent}"),
    };

    if ($result->success) {
        jsonOut([
            'ok'       => true,
            'text'     => $result->text,
            'provider' => $result->provider,
            'channel'  => $result->channel,
        ]);
    } else {
        jsonOut(['ok' => false, 'error' => $result->error ?? 'AI provider returned no result.']);
    }
} catch (\Throwable $e) {
    error_log('[papyr ai] task failed: ' . $e->getMessage());
    jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
}
