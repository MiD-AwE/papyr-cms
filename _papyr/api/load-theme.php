<?php
declare(strict_types=1);

/*
 * PapyrCMS theme loader — include near the top of any managed page to
 * inject saved HSL token values server-side, eliminating flash of default
 * colors on load.
 *
 * Theme files live at: {site_root}/data/themes/{pageKey}.json
 *
 * {pageKey} = relative path from site root with slashes → underscores and
 * extension stripped.  e.g. tutorials/installation.php → tutorials_installation
 *
 * Usage in a site page:
 *
 *     <?php
 *     require_once '_papyr/api/load-theme.php';
 *     $siteRoot = realpath(__DIR__ . '/..');   // or however your site knows its root
 *     $theme    = papyr_load_theme(__FILE__, $siteRoot);
 *     ?>
 *     <style>
 *       :root {
 *         <?php echo papyr_theme_css($theme); ?>
 *         (fallback defaults below — overridden by saved values above)
 *         --accent-h: 194; ...
 *       }
 *     </style>
 */

/**
 * Enumerate saved swatches from {siteRoot}/data/swatches/*.json.
 * Returns [ slug => ['name' => string, 'tokens' => [...]] ]
 * sorted by slug. Unreadable or malformed files are silently skipped.
 */
function papyr_swatches_enumerate(string $swatchDir): array
{
    $out = [];
    foreach (glob(rtrim($swatchDir, '/') . '/*.json') as $f) {
        if (!is_readable($f)) continue;
        $data = json_decode(file_get_contents($f), true);
        if (!is_array($data) || empty($data['tokens'])) continue;
        $slug        = pathinfo($f, PATHINFO_FILENAME);
        $out[$slug]  = [
            'name'   => $data['name'] ?? $slug,
            'tokens' => $data['tokens'],
        ];
    }
    ksort($out);
    return $out;
}

function papyr_page_key(string $pageFile, string $siteRoot): string
{
    $abs  = realpath($pageFile) ?: $pageFile;
    $root = rtrim(realpath($siteRoot) ?: $siteRoot, '/');
    $rel  = ltrim(str_replace($root, '', $abs), '/');
    return pathinfo(str_replace('/', '_', $rel), PATHINFO_FILENAME);
}

function papyr_load_theme(string $pageFile, string $siteRoot): array
{
    $key       = papyr_page_key($pageFile, $siteRoot);
    $themeFile = rtrim(realpath($siteRoot) ?: $siteRoot, '/') . '/data/themes/' . $key . '.json';
    if (!file_exists($themeFile)) return [];

    $json    = file_get_contents($themeFile);
    $decoded = $json !== false ? json_decode($json, true) : null;
    return is_array($decoded) ? $decoded : [];
}

/*
 * Returns a string of CSS custom-property declarations for injection into :root.
 * Keys must match save-theme.php's allowed list (HSL component names without --).
 * $defaults lets callers override the built-in n01z fallback values per-site.
 */
/*
 * Built-in component token values a page renders with when it has no saved
 * theme. Mirrors the defaults in templates/cms/tokens.css.
 */
function papyr_theme_defaults(): array
{
    return [
        'accent-h'  => '194',  'accent-s'  => '100%', 'accent-l'  => '84%',
        'accent2-h' => '194',  'accent2-s' => '76%',  'accent2-l' => '50%',
        'bg-h'      => '205',  'bg-s'      => '71%',  'bg-l'      => '9%',
        'text-h'    => '255',  'text-s'    => '100%', 'text-l'    => '84%',
    ];
}

function papyr_theme_css(array $theme, array $defaults = []): string
{
    $keys   = papyr_theme_defaults();
    $merged = array_merge($keys, $defaults, $theme);

    $lines = [];
    foreach ($keys as $key => $_) {
        $val     = htmlspecialchars((string) ($merged[$key] ?? $keys[$key]), ENT_QUOTES, 'UTF-8');
        $lines[] = "      --{$key}: {$val};";
    }
    return implode("\n", $lines) . "\n";
}
