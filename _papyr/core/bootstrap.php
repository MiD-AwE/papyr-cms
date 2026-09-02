<?php

/**
 * Papyr CMS - bootstrap.php
 *
 * Wires Scanner and AIBridge together from sites.json.
 * Include this at the top of cms/index.php.
 *
 * Usage:
 *   require_once __DIR__ . '/bootstrap.php';
 *   $pages  = $scanner->getPages('n01z');
 *   $result = $ai->draftSocialPost('RAZOR', 'https://n01z.mid-awe.com/15_razor.html', 'Hope-Punk Gospel');
 *   echo $result->text;
 */

declare(strict_types=1);

// Autoload — run `composer dump-autoload` once you have composer.json set up.
// Until then, manual requires work fine for three files.
require_once __DIR__ . '/core/Scanner.php';
require_once __DIR__ . '/core/AIBridge.php';

use Papyr\Core\Scanner;
use Papyr\Core\AIBridge;

// ---------------------------------------------------------------------------
// Load configuration
// ---------------------------------------------------------------------------

$configPath = __DIR__ . '/config/sites.json';

if (!file_exists($configPath)) {
    die('Papyr: config/sites.json not found. Copy sites.json.example and edit it.');
}

$config = json_decode(file_get_contents($configPath), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die('Papyr: sites.json is not valid JSON — ' . json_last_error_msg());
}

// ---------------------------------------------------------------------------
// Timezone
// ---------------------------------------------------------------------------

date_default_timezone_set($config['cms']['timezone'] ?? 'UTC');

// ---------------------------------------------------------------------------
// Build Scanner
// ---------------------------------------------------------------------------

$manifestPath = __DIR__ . '/' . ltrim($config['cms']['manifest_path'] ?? 'cache/manifest.json', '/');

$scanner = new Scanner(
    $config['sites'] ?? [],
    $manifestPath
);

// ---------------------------------------------------------------------------
// Build AIBridge
// ---------------------------------------------------------------------------

$ai = new AIBridge($config['ai'] ?? []);

// ---------------------------------------------------------------------------
// Optional: expose a quick status check at ?papyr_status=1
// Remove this block before going public.
// ---------------------------------------------------------------------------

if (isset($_GET['papyr_status']) && php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');

    $report = $scanner->scan();
    $status = $ai->status();

    echo json_encode([
        'papyr'    => $config['cms']['version'] ?? '0.1.0',
        'scan'     => $report,
        'ai'       => $status,
        'manifest' => $scanner->getManifest(),
    ], JSON_PRETTY_PRINT);

    exit;
}
