<?php
declare(strict_types=1);

/* Deliberately requires only the autoloader, not the full bootstrap.php
   chain (Scanner/AIBridge/sites.json parsing) — login must stay reachable
   even if the site's config is broken, since it's also the lockout
   recovery path. */
require __DIR__ . '/vendor/autoload.php';

use Papyr\Core\AdminAuth;
use Papyr\Core\Session;

Session::start();

/* Logout — POST only, with CSRF + same-origin. A GET (a crafted <img>, a
   prefetch, a link on another site) must never end someone's session. */
if (($_POST['action'] ?? '') === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Session::sameOrigin() && Session::checkCsrf($_POST['csrf'] ?? null)) {
        Session::destroy();
        header('Location: ' . _papyrSiteUrl());
        exit;
    }
    http_response_code(403);
    exit('Logout refused: missing or invalid token. Go back, reload the page and try again.');
}

/* Already in — send to admin dashboard */
if (Session::isAdmin()) {
    header('Location: ' . _papyrSiteUrl() . '_papyr/');
    exit;
}

$error      = '';
$configured = AdminAuth::source() !== null;

if (!$configured) {
    /* Say *why* (missing vs. unreadable vs. broken) — the usual cause of a
       "lockout" on a fresh host is file permissions, not a lost password. */
    $error = match (AdminAuth::configState()) {
        'unreadable' => 'The admin secret file exists but the web server cannot read it. '
            . 'Make _config/secret.php readable by the web server\'s group (mode 640), '
            . 'or re-run "php _papyr/bin/set-admin-pass.php".',
        'invalid'    => 'The admin secret file is readable but does not contain a valid hash. '
            . 'Re-run "php _papyr/bin/set-admin-pass.php".',
        default      => 'Admin secret not configured: _config/secret.php is missing. '
            . 'Run "php _papyr/bin/set-admin-pass.php", '
            . 'or set the PAPYR_ADMIN_PASS environment variable.',
    };
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = $_POST['pass'] ?? '';

    if (!Session::sameOrigin() || !Session::checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!is_string($submitted) || !AdminAuth::verify($submitted)) {
        $error = 'Incorrect password.';
    } else {
        Session::grantAdmin();
        /* Return to the page that linked here (carried through the form, since
           the POST's own Referer is login.php), or fall back to the dashboard.
           Hosts are compared without ports, so :88 dev installs work too. */
        $ret  = (string) ($_POST['return'] ?? '');
        $safe = $ret !== ''
            && Session::hostOf($ret) !== ''
            && Session::hostOf($ret) === Session::hostOf((string) ($_SERVER['HTTP_HOST'] ?? ''))
            && !str_contains($ret, '/_papyr/login.php');
        header('Location: ' . ($safe ? $ret : _papyrSiteUrl() . '_papyr/'));
        exit;
    }
}

/* Where to land after a successful login: the page that linked here. */
$returnTo = (string) ($_POST['return'] ?? $_SERVER['HTTP_REFERER'] ?? '');

/*
 * Derive the absolute site root URL from this file's location.
 * __DIR__ = {site_root}/_papyr   →  parent = site root.
 */
function _papyrSiteUrl(): string
{
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/_papyr/login.php';
    $base   = rtrim(dirname(dirname($script)), '/') . '/';
    return $scheme . '://' . $host . $base;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PapyrCMS — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
      background: #07090f; color: #c8cfe0; font-family: ui-sans-serif, system-ui, sans-serif;
    }
    .login-box {
      width: 100%; max-width: 340px; padding: 2.5rem 2rem;
      background: rgba(12,16,28,.97);
      border: 1px solid rgba(100,120,160,.18);
      border-radius: 8px;
    }
    .login-box h1 {
      font-size: .62rem; letter-spacing: .3em; text-transform: uppercase;
      color: #5b8def; margin-bottom: 1.75rem; font-weight: 700;
    }
    .login-box input[type=password] {
      width: 100%; padding: .65rem .85rem;
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(100,120,160,.22);
      border-radius: 4px; color: inherit; font-size: .92rem;
      outline: none; transition: border-color .15s;
    }
    .login-box input[type=password]:focus { border-color: #5b8def; }
    .login-box button {
      margin-top: .85rem; width: 100%; padding: .65rem;
      background: none; border: 1px solid rgba(100,120,160,.3);
      color: #c8cfe0; font-size: .75rem; letter-spacing: .14em;
      text-transform: uppercase; border-radius: 4px; cursor: pointer;
      transition: border-color .15s, color .15s;
    }
    .login-box button:hover { border-color: #5b8def; color: #5b8def; }
    .error {
      font-size: .78rem; color: #c4304a; margin-bottom: 1rem; line-height: 1.5;
    }
  </style>
</head>
<body>
<div class="login-box">
  <h1>PapyrCMS &middot; Admin</h1>
  <?php if ($error !== '') : ?>
  <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(Session::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
    <input type="password" name="pass" autofocus placeholder="Admin password" autocomplete="current-password">
    <button type="submit">Enter</button>
  </form>
</div>
</body>
</html>
