<?php
declare(strict_types=1);

namespace Papyr\Core;

/*
 * Plugin discovery, validation and execution (ADR-002 Phase D).
 *
 * Two locations, both `_`-prefixed (Scanner-invisible, PageWriter-protected):
 *
 *   {siteRoot}/_plugins/{name}/   the site's own — survives engine upgrades
 *   _papyr/plugins/{name}/        core plugins — replaced with the engine
 *
 * The site's copy wins on a name clash, so a site can deliberately override
 * a core plugin.
 *
 * Every plugin folder holds plugin.json:
 *
 *   { "name": "site-map", "version": "1.0.0", "requires": { "papyr": ">=0.2.0" },
 *     "entry": "plugin.php", "description": "…" }
 *
 * A plugin is refused — logged, nothing rendered — when the name is not
 * ^[a-z0-9-]{1,40}$ or differs from its folder, the manifest is unreadable,
 * the engine version does not satisfy requires.papyr, or the entry is
 * missing or points outside the plugin folder.
 *
 * Two ways in:
 *   - render: a page calls papyr_plugin('name', $options); the entry runs
 *     where it was called. Calling it is the opt-in.
 *   - boot:   sites.json lists the plugin under "plugins"; the entry runs
 *     once during nav.php's boot, before any output, to register providers.
 *     Anything a boot entry prints is discarded (and logged) so it can never
 *     break headers or the session.
 *
 * The entry runs in its own scope with exactly three variables:
 *   $p        the read-only Context (same as papyr())
 *   $options  array passed to papyr_plugin() ([] at boot)
 *   $plugin   ['name', 'version', 'mode' => 'render'|'boot']
 * Plugins read and present; anything that writes goes through an engine
 * endpoint (ADR-002 §6.4).
 */
final class Plugins
{
    public const NAME_PATTERN = '/^[a-z0-9-]{1,40}$/';

    /** @var array<string, array|false> name => resolved plugin or false (refused), per request */
    private static array $cache = [];

    /** @var array<string, true> boot plugins already run this request */
    private static array $booted = [];

    /** Site plugins folder. */
    public static function siteDir(): string
    {
        return Session::siteRoot() . '/_plugins';
    }

    /** Core plugins folder. */
    public static function coreDir(): string
    {
        return dirname(__DIR__) . '/plugins';
    }

    /**
     * Resolve and validate a plugin. Returns
     *   ['name', 'version', 'description', 'dir', 'entry', 'urlPrefix', 'origin' => 'site'|'core']
     * or null (and one error_log line) when it cannot be used.
     * dir/entry are filesystem paths for the engine's own use — never handed to plugins or the browser.
     */
    public static function find(string $name): ?array
    {
        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name] ?: null;
        }
        self::$cache[$name] = false;

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return self::refuse($name, 'invalid plugin name');
        }

        foreach ([['site', self::siteDir(), '/_plugins/'], ['core', self::coreDir(), '/_papyr/plugins/']] as [$origin, $base, $url]) {
            $dir = $base . '/' . $name;
            if (!is_dir($dir)) {
                continue;
            }
            $plugin = self::validate($name, $dir, $origin);
            if ($plugin === null) {
                return null;                        // site copy exists but is broken: don't silently fall back
            }
            $plugin['urlPrefix'] = $url . $name;
            return (self::$cache[$name] = $plugin);
        }
        return self::refuse($name, 'not found in _plugins/ or _papyr/plugins/');
    }

    /** Render a plugin where called (papyr_plugin()). */
    public static function render(string $name, Context $p, array $options = []): void
    {
        $plugin = self::find($name);
        if ($plugin === null) {
            return;
        }
        if (isset(self::$booted[$name])) {
            return;                                  // boot-only plugin: providers already registered
        }
        self::run($plugin, $p, $options, 'render');
    }

    /**
     * Run the boot-only plugins a site lists in sites.json ("plugins": [...],
     * at the top level or inside the site's entry). Called once by nav.php.
     */
    public static function boot(array $names, Context $p): void
    {
        foreach (array_unique(array_filter($names, 'is_string')) as $name) {
            if (isset(self::$booted[$name])) {
                continue;
            }
            $plugin = self::find($name);
            if ($plugin === null) {
                continue;
            }
            self::$booted[$name] = true;
            ob_start();
            self::run($plugin, $p, [], 'boot');
            $stray = (string) ob_get_clean();
            if (trim($stray) !== '') {
                error_log("[PapyrCMS] plugin '{$name}': output during boot was discarded ("
                    . strlen($stray) . ' bytes). Boot plugins register providers; they must not print.');
            }
        }
    }

    /**
     * True when $have satisfies $constraint. Supports space- or comma-separated
     * comparators (>=, >, <=, <, =, !=), "*", and ^x.y / ~x.y shorthands.
     */
    public static function satisfies(string $have, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        foreach (preg_split('/[\s,]+/', $constraint) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $part, $m)) {
                $min  = ($m[1] ?? '0') . '.' . ($m[2] ?? '0') . '.' . ($m[3] ?? '0');
                $max  = (int) $m[1] > 0 ? ((int) $m[1] + 1) . '.0.0' : '0.' . ((int) ($m[2] ?? 0) + 1) . '.0';
                if (!(version_compare($have, $min, '>=') && version_compare($have, $max, '<'))) {
                    return false;
                }
                continue;
            }
            if (preg_match('/^~(\d+)\.(\d+)(?:\.(\d+))?$/', $part, $m)) {
                $min = $m[1] . '.' . $m[2] . '.' . ($m[3] ?? '0');
                $max = $m[1] . '.' . ((int) $m[2] + 1) . '.0';
                if (!(version_compare($have, $min, '>=') && version_compare($have, $max, '<'))) {
                    return false;
                }
                continue;
            }
            if (!preg_match('/^(>=|<=|!=|>|<|=)?v?(\d+(?:\.\d+){0,2})$/', $part, $m)) {
                return false;                       // unparseable constraint: refuse rather than guess
            }
            if (!version_compare($have, $m[2], ($m[1] ?? '') === '' ? '==' : ($m[1] === '=' ? '==' : $m[1]))) {
                return false;
            }
        }
        return true;
    }

    /** Test seam: forget everything resolved this request. */
    public static function reset(): void
    {
        self::$cache  = [];
        self::$booted = [];
    }

    /* ---------------- internals ---------------- */

    private static function validate(string $name, string $dir, string $origin): ?array
    {
        $file = $dir . '/plugin.json';
        if (!is_readable($file)) {
            return self::refuse($name, "{$origin} copy has no readable plugin.json");
        }
        $m = json_decode((string) file_get_contents($file), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($m)) {
            return self::refuse($name, "{$origin} plugin.json is not valid JSON");
        }
        if (($m['name'] ?? null) !== $name) {
            return self::refuse($name, "{$origin} plugin.json name does not match its folder");
        }
        $requires = $m['requires']['papyr'] ?? '*';
        if (!is_string($requires) || !self::satisfies(Version::ENGINE, $requires)) {
            return self::refuse($name, 'requires papyr ' . (is_string($requires) ? $requires : '?')
                . ', engine is ' . Version::ENGINE);
        }
        $entry = $m['entry'] ?? 'plugin.php';
        if (!is_string($entry) || preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./-]*\.php$#', $entry) !== 1 || str_contains($entry, '..')) {
            return self::refuse($name, "{$origin} entry must be a .php path inside the plugin folder");
        }
        $entryPath = realpath($dir . '/' . $entry);
        $realDir   = realpath($dir);
        if ($entryPath === false || $realDir === false || !str_starts_with($entryPath, $realDir . '/') || !is_file($entryPath)) {
            return self::refuse($name, "{$origin} entry {$entry} is missing");
        }
        return [
            'name'        => $name,
            'version'     => is_string($m['version'] ?? null) ? $m['version'] : '0.0.0',
            'description' => is_string($m['description'] ?? null) ? $m['description'] : '',
            'dir'         => $realDir,
            'entry'       => $entryPath,
            'origin'      => $origin,
        ];
    }

    private static function run(array $plugin, Context $p, array $options, string $mode): void
    {
        $info = ['name' => $plugin['name'], 'version' => $plugin['version'], 'mode' => $mode];
        try {
            // Exactly $p, $options and $plugin in scope; the entry path is passed
            // as an extra argument so no engine variable leaks into the plugin.
            (static function (Context $p, array $options, array $plugin): void {
                include func_get_arg(3);
            })($p, $options, $info, $plugin['entry']);
        } catch (\Throwable $e) {
            error_log("[PapyrCMS] plugin '{$plugin['name']}' ({$mode}) failed: " . $e->getMessage()
                . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    private static function refuse(string $name, string $why): ?array
    {
        error_log("[PapyrCMS] plugin '" . (preg_match(self::NAME_PATTERN, $name) ? $name : '?') . "' refused: {$why}.");
        return null;
    }
}
