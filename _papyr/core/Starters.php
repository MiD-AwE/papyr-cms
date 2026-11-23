<?php
declare(strict_types=1);

namespace Papyr\Core;

/**
 * Page starters for Add Page (ADR-002 §7.3).
 *
 * A starter is a folder holding page.html.twig and starter.json:
 *
 *   { "label": "Landing", "description": "…",
 *     "fields": [ { "name": "title", "label": "Title", "type": "text",
 *                   "required": true, "help": "…", "default": "…" }, … ] }
 *
 * Locations, first wins on a name clash:
 *   {siteRoot}/_templates/starters/{name}/   — site-owned, survives engine upgrades
 *   {engineDir}/templates/starters/{name}/   — engine-owned, replaced with _papyr/
 *
 * Like plugins, a starter that fails validation is logged and skipped, and a
 * broken site copy does NOT fall back to the engine starter of the same name:
 * the site asked for its own version, so silently using the engine's would
 * hide the error. Starters are used at generation time only; a page made from
 * one has no runtime dependency on it.
 */
final class Starters
{
    public const DIR = 'starters';

    /** Field types the Add Page form knows how to render. */
    public const FIELD_TYPES = ['text', 'textarea', 'html', 'url'];

    /** Variables PageWriter or pages.php set themselves; a field may not claim them. */
    public const RESERVED = ['root', 'draft', 'starter', 'theme', 'sections'];

    private const NAME_RE  = '/^[a-z0-9-]{1,40}$/';
    private const FIELD_RE = '/^[a-z][a-z0-9_]{0,39}$/';
    private const MAX_FIELD_BYTES = 200000;

    /** @var array<string, array>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $siteRoot,
        private readonly string $engineDir,
    ) {
    }

    /**
     * Valid starters by name, sorted by name.
     * Each: name, label, description, fields, source ('site'|'engine'), template.
     *
     * @return array<string, array>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $found = [];
        $siteDir = TemplateEngine::siteDir($this->siteRoot);
        $roots = [
            'site'   => $siteDir === null ? null : $siteDir . '/' . self::DIR,
            'engine' => rtrim($this->engineDir, '/') . '/templates/' . self::DIR,
        ];
        foreach ($roots as $source => $dir) {
            if ($dir === null || !is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $name) {
                if ($name[0] === '.' || $name[0] === '_' || !is_dir("$dir/$name") || array_key_exists($name, $found)) {
                    continue;
                }
                // Claimed by the first root that has the folder, valid or not (no fallback).
                $found[$name] = $this->load($name, "$dir/$name", $source);
            }
        }
        $this->cache = array_filter($found);
        ksort($this->cache);
        return $this->cache;
    }

    public function get(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Clean submitted values against a starter's fields: undeclared keys are
     * dropped, values become trimmed strings (html keeps its inner whitespace),
     * empty fields take their default.
     *
     * @throws \InvalidArgumentException unknown starter, or a required field empty
     * @return array<string, string>
     */
    public function vars(string $name, array $input): array
    {
        $starter = $this->get($name);
        if ($starter === null) {
            throw new \InvalidArgumentException("Unknown starter: {$name}");
        }
        $out = [];
        $missing = [];
        foreach ($starter['fields'] as $f) {
            $raw = $input[$f['name']] ?? '';
            $val = is_scalar($raw) ? (string) $raw : '';
            $val = str_replace("\0", '', $f['type'] === 'html' ? $val : trim($val));
            if (strlen($val) > self::MAX_FIELD_BYTES) {
                throw new \InvalidArgumentException("Field too long: {$f['label']}");
            }
            if (trim($val) === '') {
                $val = $f['default'];
            }
            if ($f['required'] && trim($val) === '') {
                $missing[] = $f['label'];
            }
            $out[$f['name']] = $val;
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException('Required: ' . implode(', ', $missing));
        }
        return $out;
    }

    /** Validate one starter folder; null (and a log line) when it is unusable. */
    private function load(string $name, string $dir, string $source): ?array
    {
        $refuse = static function (string $why) use ($name, $source): ?array {
            error_log("[PapyrCMS] Starter '{$name}' ({$source}) refused: {$why}");
            return null;
        };

        if (!preg_match(self::NAME_RE, $name)) {
            return $refuse('folder name must match [a-z0-9-]{1,40}');
        }
        if (!is_file("$dir/page.html.twig")) {
            return $refuse('page.html.twig missing');
        }
        $raw = @file_get_contents("$dir/starter.json");
        if ($raw === false) {
            return $refuse('starter.json missing or unreadable');
        }
        $m = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($m)) {
            return $refuse('starter.json is not valid JSON: ' . json_last_error_msg());
        }
        if (!isset($m['label']) || !is_string($m['label']) || trim($m['label']) === '') {
            return $refuse('label missing');
        }
        if (!isset($m['fields']) || !is_array($m['fields']) || !array_is_list($m['fields'])) {
            return $refuse('fields must be a list');
        }

        $fields = [];
        foreach ($m['fields'] as $i => $f) {
            $fname = is_array($f) ? ($f['name'] ?? null) : null;
            if (!is_string($fname) || !preg_match(self::FIELD_RE, $fname)) {
                return $refuse("field #{$i}: name must match [a-z][a-z0-9_]{0,39}");
            }
            if (in_array($fname, self::RESERVED, true)) {
                return $refuse("field '{$fname}' is reserved");
            }
            if (isset($fields[$fname])) {
                return $refuse("field '{$fname}' declared twice");
            }
            $type = $f['type'] ?? 'text';
            if (!in_array($type, self::FIELD_TYPES, true)) {
                return $refuse("field '{$fname}': unknown type '" . (is_string($type) ? $type : gettype($type)) . "'");
            }
            $fields[$fname] = [
                'name'     => $fname,
                'label'    => is_string($f['label'] ?? null) && $f['label'] !== '' ? $f['label'] : $fname,
                'type'     => $type,
                'required' => ($f['required'] ?? false) === true,
                'help'     => is_string($f['help'] ?? null) ? $f['help'] : '',
                'default'  => is_scalar($f['default'] ?? null) ? (string) $f['default'] : '',
            ];
        }
        if (!isset($fields['title'])) {
            return $refuse("a 'title' field is required (the page title and nav label)");
        }

        return [
            'name'        => $name,
            'label'       => trim($m['label']),
            'description' => is_string($m['description'] ?? null) ? $m['description'] : '',
            'fields'      => array_values($fields),
            'source'      => $source,
            'template'    => self::DIR . '/' . $name . '/page.html.twig',
        ];
    }
}
