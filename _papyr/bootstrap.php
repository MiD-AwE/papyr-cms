<?php
declare(strict_types=1);

use Papyr\Core\Scanner;
use Papyr\Core\AIBridge;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    exit('Papyr CMS: run `composer install` first.');
}
require $autoload;

/* ---- Config ---- */
$configPath = \Papyr\Core\SiteConfig::path('sites.json');   // _config/ first, legacy _papyr/config/ as fallback
if (!file_exists($configPath)) {
    http_response_code(500);
    exit('Papyr CMS: _config/sites.json not found (see _papyr/config/sites.json.example).');
}
$config = json_decode(file_get_contents($configPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    exit('Papyr CMS: _config/sites.json is not valid JSON.');
}

/* ---- Timezone ---- */
$tz = $config['cms']['timezone'] ?? 'UTC';
date_default_timezone_set($tz);

/* ---- Resolve relative site roots ---- */
$engineDir = __DIR__;
$sites = $config['sites'] ?? [];
foreach ($sites as $key => &$site) {
    $root = $site['root'] ?? '';
    if ($root !== '' && !str_starts_with($root, '/')) {
        $resolved = realpath($engineDir . '/' . $root);
        if ($resolved !== false) {
            $site['root'] = $resolved;
        }
    }
}
unset($site);

/* ---- Services ---- */
$manifestPath = __DIR__ . '/' . ltrim($config['cms']['manifest_path'] ?? 'cache/manifest.json', '/');
$scanTtl      = (int) ($config['cms']['scan_ttl_seconds'] ?? 300);

$scanner = new Scanner($sites, $manifestPath, $scanTtl);
$ai      = new AIBridge($config['ai'] ?? []);

