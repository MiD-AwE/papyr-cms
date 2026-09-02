<?php
declare(strict_types=1);

namespace Papyr\Core;

class Scanner
{
    private array  $sites;
    private string $manifestPath;
    private int    $scanTtl;
    private array  $manifest = [];

    private const DEFAULT_IGNORE_FILES = ['nav.php','footer.php','header.php','functions.php','config.php'];
    private const DEFAULT_IGNORE_DIRS  = ['vendor','node_modules','.git','cache','assets','css','js','img','images','fonts'];
    private const TITLE_READ_BYTES     = 4096;

    public function __construct(array $sites, string $manifestPath, int $scanTtl = 300)
    {
        $this->sites        = $sites;
        $this->manifestPath = $manifestPath;
        $this->scanTtl      = $scanTtl;
        $this->loadManifest();
    }

    public function scan(): array
    {
        $report = ['new' => [], 'modified' => [], 'deleted' => []];

        foreach ($this->sites as $siteKey => $siteConfig) {
            $root        = rtrim($siteConfig['root'] ?? '', '/');
            $ignoreFiles = array_unique(array_merge(self::DEFAULT_IGNORE_FILES, $siteConfig['ignore_files'] ?? []));
            $ignoreDirs  = array_unique(array_merge(self::DEFAULT_IGNORE_DIRS,  $siteConfig['ignore_dirs']  ?? []));
            $label       = $siteConfig['label'] ?? $siteKey;

            if (!is_dir($root)) {
                error_log("Papyr\\Core\\Scanner: site root not found — {$root}");
                continue;
            }

            $existingPages = $this->manifest['sites'][$siteKey]['pages'] ?? [];
            $scannedPaths  = [];

            $dirIter  = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
            $filtered = new \RecursiveCallbackFilterIterator(
                $dirIter,
                static function (\SplFileInfo $current) use ($ignoreDirs): bool {
                    if (!$current->isDir()) return true;
                    $name = $current->getFilename();
                    if (str_starts_with($name, '_')) return false;
                    return !in_array($name, $ignoreDirs, true);
                }
            );
            $iter = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::SELF_FIRST);

            foreach ($iter as $file) {
                if ($file->isDir()) continue;

                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['php','html','htm'], true)) continue;
                if (in_array($file->getFilename(), $ignoreFiles, true)) continue;

                $relPath = ltrim(str_replace($root, '', $file->getPathname()), '/');
                $mtime   = $file->getMTime();
                $size    = $file->getSize();
                $scannedPaths[$relPath] = true;

                if (!isset($existingPages[$relPath])) {
                    ['title' => $title, 'draft' => $draft] = $this->extractMeta($file->getPathname());
                    $entry = [
                        'mtime'       => $mtime,
                        'size'        => $size,
                        'ext'         => $ext,
                        'filename'    => $file->getFilename(),
                        'title'       => $title,
                        'cms_managed' => false,
                    ];
                    if ($draft) $entry['draft'] = true;
                    $this->manifest['sites'][$siteKey]['pages'][$relPath] = $entry;
                    $report['new'][] = ['site' => $siteKey, 'path' => $relPath, 'title' => $title];
                } elseif ($existingPages[$relPath]['mtime'] !== $mtime || $existingPages[$relPath]['size'] !== $size) {
                    $existing = $existingPages[$relPath];
                    ['title' => $title, 'draft' => $draft] = $this->extractMeta($file->getPathname());
                    $update = array_merge($existing, [
                        'mtime' => $mtime,
                        'size'  => $size,
                        'title' => $title,
                    ]);
                    if ($draft) {
                        $update['draft'] = true;
                    } else {
                        unset($update['draft']);
                    }
                    if ($existing['cms_managed'] ?? false) {
                        $update['manually_edited'] = true;
                    }
                    $this->manifest['sites'][$siteKey]['pages'][$relPath] = $update;
                    $report['modified'][] = ['site' => $siteKey, 'path' => $relPath];
                }
            }

            foreach (array_keys($existingPages) as $relPath) {
                if (!isset($scannedPaths[$relPath])) {
                    unset($this->manifest['sites'][$siteKey]['pages'][$relPath]);
                    $report['deleted'][] = ['site' => $siteKey, 'path' => $relPath];
                }
            }

            $this->manifest['sites'][$siteKey]['root']         = $root;
            $this->manifest['sites'][$siteKey]['label']        = $label;
            $this->manifest['sites'][$siteKey]['last_scanned'] = date('c');
        }

        $this->manifest['last_scanned'] = date('c');
        $this->saveManifest();
        return $report;
    }

    public function getPages(string $siteKey, bool $forceScan = false): array
    {
        if ($forceScan || $this->isScanStale($siteKey)) {
            $this->scan();
        }
        return $this->manifest['sites'][$siteKey]['pages'] ?? [];
    }

    public function markManaged(string $siteKey, string $relPath, array $meta = []): void
    {
        if (!isset($this->manifest['sites'][$siteKey]['pages'][$relPath])) {
            throw new \RuntimeException("Page not found in manifest: {$siteKey}/{$relPath}");
        }
        $this->manifest['sites'][$siteKey]['pages'][$relPath] = array_merge(
            $this->manifest['sites'][$siteKey]['pages'][$relPath],
            $meta,
            ['cms_managed' => true]
        );
        $this->saveManifest();
    }

    public function clearManifest(): bool
    {
        $this->manifest = [];
        if (file_exists($this->manifestPath)) {
            return (bool) unlink($this->manifestPath);
        }
        return true;
    }

    public function getManifest(): array
    {
        return $this->manifest;
    }

    private function isScanStale(string $siteKey): bool
    {
        $lastScanned = $this->manifest['sites'][$siteKey]['last_scanned'] ?? null;
        if (!$lastScanned) return true;
        return (time() - (int) strtotime($lastScanned)) > $this->scanTtl;
    }

    private function loadManifest(): void
    {
        if (!file_exists($this->manifestPath)) return;

        $json = file_get_contents($this->manifestPath);
        if ($json === false) {
            error_log("Papyr\\Core\\Scanner: could not read manifest — {$this->manifestPath}");
            return;
        }
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Papyr\\Core\\Scanner: manifest JSON parse error — " . json_last_error_msg());
            return;
        }
        $this->manifest = $decoded;
    }

    private function saveManifest(): void
    {
        $dir = dirname($this->manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $json = json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($this->manifestPath, $json) === false) {
            error_log("Papyr\\Core\\Scanner: could not write manifest — {$this->manifestPath}");
        }
    }

    private function extractMeta(string $filePath): array
    {
        $fh = fopen($filePath, 'r');
        if ($fh === false) return ['title' => $this->humanizeFilename(basename($filePath)), 'draft' => false];
        $chunk = fread($fh, self::TITLE_READ_BYTES);
        fclose($fh);
        if ($chunk === false || $chunk === '') return ['title' => $this->humanizeFilename(basename($filePath)), 'draft' => false];

        $draft = (bool) preg_match('/<!--\s*papyr:draft\s*-->/i', $chunk);

        // <title> tag
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $chunk, $m)) {
            return ['title' => trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'draft' => $draft];
        }

        // <h1> tag
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $chunk, $m)) {
            return ['title' => trim(strip_tags($m[1])), 'draft' => $draft];
        }

        // <!-- papyr:title: ... -->
        if (preg_match('/<!--\s*papyr:title:\s*(.+?)\s*-->/i', $chunk, $m)) {
            return ['title' => trim($m[1]), 'draft' => $draft];
        }

        return ['title' => $this->humanizeFilename(basename($filePath)), 'draft' => $draft];
    }

    private function humanizeFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/[-_]+/', ' ', $name) ?? $name;
        return ucwords($name);
    }
}
