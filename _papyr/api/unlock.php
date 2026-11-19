<?php
declare(strict_types=1);

/* ================================================================
   Participant unlock / lock endpoint.

   Deployed at:   {site_root}/_papyr/api/unlock.php
   Called by:     the Access section of theme-panel.php via fetch()

   POST body (JSON):
     { "action": "unlock", "pass": "…" }
     { "action": "lock" }

   Response (JSON):
     { "ok": true, "member": true }  or  { "ok": false, "error": "…", "retry_after"?: 60 }

   The invite password grants participant access only (comments).
   It never grants admin rights. See Papyr\Core\MemberAuth.
================================================================ */

require __DIR__ . '/../vendor/autoload.php';

use Papyr\Core\MemberAuth;
use Papyr\Core\Session;

Session::start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function papyr_unlock_fail(int $code, string $error, array $extra = []): void
{
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $error] + $extra));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    papyr_unlock_fail(405, 'Method not allowed');
}

/* Same-origin check — browsers always send Origin on cross-site fetch POSTs.
   No CSRF token here: an unlock is the step that *creates* the participant
   session, and the password itself is the proof of intent. */
Session::requireSameOrigin();

$body = json_decode((string) file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    papyr_unlock_fail(400, 'Invalid JSON body');
}

$action = $body['action'] ?? '';

if ($action === 'lock') {
    MemberAuth::revoke();
    exit(json_encode(['ok' => true, 'member' => MemberAuth::isMember()]));
}

if ($action !== 'unlock') {
    papyr_unlock_fail(400, 'Unknown action');
}

if (!MemberAuth::configured()) {
    papyr_unlock_fail(503, 'Invitations are not set up on this site yet.');
}

$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$wait = MemberAuth::lockedFor($ip);
if ($wait > 0) {
    papyr_unlock_fail(429, 'Too many attempts. Try again in ' . ceil($wait / 60) . ' min.', ['retry_after' => $wait]);
}

$pass = $body['pass'] ?? '';
if (!is_string($pass) || $pass === '' || strlen($pass) > 200 || !MemberAuth::verify($pass)) {
    MemberAuth::recordFailure($ip);
    usleep(400000); // blunt rapid guessing
    papyr_unlock_fail(401, 'That invite password didn’t work.');
}

MemberAuth::clearFailures($ip);
MemberAuth::grant();
echo json_encode(['ok' => true, 'member' => true]);
