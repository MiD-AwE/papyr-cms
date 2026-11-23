<?php
declare(strict_types=1);

namespace Papyr\Core;

/**
 * The one theme writer (ADR-002 §7.3).
 *
 * Saved page themes live at {siteRoot}/data/themes/{pageKey}.json. Every
 * write — the theme panel's Commit (api/save-theme.php) and, from Phase E2,
 * a page created with a theme (api/pages.php) — goes through write(), so the
 * allowed keys and value sanitising exist in exactly one place.
 *
 * defaults() is the one source of *starting* values for a new theme. For now
 * it returns the engine defaults; the site default theme (ADR-002 §9,
 * data/themes/_site.json) will be added inside it, so callers never change.
 * Keys starting with "_" are reserved for that kind of site-level file and
 * are refused as page keys.
 */
final class ThemeStore
{
    /** HSL component keys (without the leading --) plus "mode". */
    public const KEYS = [
        'accent-h', 'accent-s', 'accent-l',
        'accent2-h', 'accent2-s', 'accent2-l',
        'bg-h', 'bg-s', 'bg-l',
        'text-h', 'text-s', 'text-l',
        'qr-h', 'qr-s', 'qr-l',
        'qr-bg-h', 'qr-bg-s', 'qr-bg-l',
        'mode',
    ];

    private const MAX_KEY_LENGTH = 120;

    public function __construct(private readonly string $siteRoot)
    {
    }

    /** Starting values for a new theme: engine defaults (site default added by §9). */
    public function defaults(): array
    {
        if (!function_exists('papyr_theme_defaults')) {
            require_once dirname(__DIR__) . '/api/load-theme.php';
        }
        return papyr_theme_defaults();
    }

    /** A page key is a single filename stem: no separators, no NUL, not hidden or reserved. */
    public static function isValidKey(string $pageKey): bool
    {
        return $pageKey !== ''
            && strlen($pageKey) <= self::MAX_KEY_LENGTH
            && !preg_match('/[\/\\\\\x00]/', $pageKey)
            && $pageKey[0] !== '.'
            && $pageKey[0] !== '_';
    }

    /**
     * Keep only known keys; numeric parts keep digits, "." and "%"; mode is
     * "light" or "dark".
     */
    public static function sanitize(array $values): array
    {
        $clean = [];
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $values) || !is_scalar($values[$key])) {
                continue;
            }
            $clean[$key] = $key === 'mode'
                ? ($values[$key] === 'light' ? 'light' : 'dark')
                : (string) preg_replace('/[^0-9.%]/', '', (string) $values[$key]);
        }
        return $clean;
    }

    /**
     * Write a page's theme. Returns the site-relative file path.
     *
     * @throws \InvalidArgumentException bad page key, or no recognised values
     * @throws \RuntimeException         the folder or file could not be written
     */
    public function write(string $pageKey, array $values): string
    {
        if (!self::isValidKey($pageKey)) {
            throw new \InvalidArgumentException('Invalid page name');
        }
        $clean = self::sanitize($values);
        if ($clean === []) {
            throw new \InvalidArgumentException('No recognised theme values');
        }

        $dir = rtrim($this->siteRoot, '/') . '/data/themes';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create themes directory');
        }

        $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($dir . '/' . $pageKey . '.json', $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write theme file');
        }
        return 'data/themes/' . $pageKey . '.json';
    }
}
