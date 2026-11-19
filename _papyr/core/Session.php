<?php
declare(strict_types=1);

namespace Papyr\Core;

/*
 * The one owner of the PHP session for the whole engine (ADR-002 Phase A).
 * Nothing else calls session_start().
 *
 * Isolation: the session name is derived from this install's site root and
 * the cookie is scoped to the site's URL base path, so two installs on one
 * host (localhost/papyr-cms, localhost/SolarPoweredBugZapper, or two
 * subfolder installs on a shared host) never share a session.
 *
 * Identity is the site root alone, not "siteKey|siteRoot" as the ADR first
 * sketched: login.php and the JSON endpoints deliberately skip bootstrap.php
 * (login is the lockout-recovery path and must survive a broken sites.json),
 * so the one value every entry point can derive identically, with no config,
 * is the directory _papyr/ sits in. Under the single-site model one install
 * is one site, so the root is a sufficient identity.
 *
 * CSRF: one token per session, shared by every gated endpoint. It lives
 * under the same session key MemberAuth used, so tokens already issued keep
 * working.
 */
final class Session
{
    private const CSRF_KEY  = 'papyr_csrf';
    private const ADMIN_KEY = 'papyr_admin';

    /** Set when start() was refused because output had already begun. */
    private static ?string $failedAt = null;

    /**
     * Start (or join) this install's session. Idempotent.
     *
     * @param string|null $basePath URL base path if the caller already has it
     *                              (nav.php does); derived from the request otherwise.
     * @return bool true when a session is active after the call.
     */
    public static function start(?string $basePath = null): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (session_status() === PHP_SESSION_DISABLED) {
            self::$failedAt = 'sessions disabled';
            return false;
        }
        if (headers_sent($file, $line)) {
            // Can't send the cookie any more, and PHP refuses to start. Callers
            // see "no session" (visitor); papyr_nav() logs which page did this.
            self::$failedAt = $file !== '' ? "{$file}:{$line}" : 'unknown';
            return false;
        }

        $siteRoot = self::siteRoot();
        $basePath ??= UrlResolver::basePath($siteRoot);

        ini_set('session.use_strict_mode', '1');
        session_name(self::name());
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $basePath !== '' ? $basePath : '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            self::$failedAt = 'session_start() failed';
            return false;
        }
        self::$failedAt = null;
        return true;
    }

    public static function active(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /** Where/why the last start() failed, or null. */
    public static function failure(): ?string
    {
        return self::$failedAt;
    }

    /** Per-install cookie name, e.g. PAPYR_3f9a0c12d4e5. */
    public static function name(): string
    {
        return 'PAPYR_' . substr(hash('sha256', self::siteRoot()), 0, 12);
    }

    /** The site root: the directory _papyr/ sits in. */
    public static function siteRoot(): string
    {
        $root = dirname(__DIR__, 2);
        $real = realpath($root);
        return $real !== false ? $real : $root;
    }

    /* ---------------- roles ---------------- */

    public static function isAdmin(): bool
    {
        return ($_SESSION[self::ADMIN_KEY] ?? false) === true;
    }

    public static function grantAdmin(): void
    {
        session_regenerate_id(true);
        $_SESSION[self::ADMIN_KEY] = true;
        self::rotateCsrf();
    }

    /** End the session entirely (logout) and expire its cookie. */
    public static function destroy(): void
    {
        if (!self::active()) {
            return;
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
        session_destroy();
    }

    /* ---------------- CSRF ---------------- */

    /** Create-once token for this session; '' when no session is active. */
    public static function csrfToken(): string
    {
        if (!self::active()) {
            return '';
        }
        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(16));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    /** Issue a fresh token — call on every privilege change. */
    public static function rotateCsrf(): void
    {
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(16));
    }

    public static function checkCsrf(mixed $token): bool
    {
        $have = $_SESSION[self::CSRF_KEY] ?? '';
        return is_string($have) && $have !== '' && is_string($token) && hash_equals($have, $token);
    }

    /* ---------------- same-origin ---------------- */

    /**
     * Host part of a URL or a bare "host[:port]", lower-cased, port dropped.
     * Ports are ignored on both sides so a dev server on :88 compares equal.
     */
    public static function hostOf(string $urlOrHost): string
    {
        $urlOrHost = trim($urlOrHost);
        if ($urlOrHost === '') {
            return '';
        }
        $host = str_contains($urlOrHost, '://')
            ? parse_url($urlOrHost, PHP_URL_HOST)
            : parse_url('//' . $urlOrHost, PHP_URL_HOST);
        return is_string($host) ? strtolower(trim($host, '[]')) : '';
    }

    /**
     * True when the request did not come from another site. Uses Origin, then
     * Referer; a request carrying neither is allowed (non-browser clients),
     * because every gated write also needs a session and a CSRF token.
     */
    public static function sameOrigin(): bool
    {
        $self = self::hostOf((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($self === '') {
            return false;
        }
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '') {
            return self::hostOf($origin) === $self;   // "null" origin → '' → refused
        }
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref !== '') {
            return self::hostOf($ref) === $self;
        }
        return true;
    }

    /** JSON endpoints: 403 and exit unless same-origin. */
    public static function requireSameOrigin(): void
    {
        if (self::sameOrigin()) {
            return;
        }
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json');
            header('X-Content-Type-Options: nosniff');
        }
        exit(json_encode(['ok' => false, 'error' => 'Cross-origin request refused']));
    }

    /* ---------------- internals ---------------- */

    private static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        // Behind a TLS-terminating proxy (common on shared hosts). Only ever
        // used to *add* the Secure flag, so a spoofed header can't weaken anything.
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
