<?php
declare(strict_types=1);

namespace Papyr\Core;

/*
 * Flat-file comment store — one JSON file per page, threads keyed by block id.
 *
 *   {site_root}/data/comments/{pageKey}.json
 *   { "acoustic-larvicide": [ {"id":"…","name":"…","body":"…","ts":1731170000}, … ], … }
 *
 * The folder gets an .htaccess deny on creation so the raw files are never
 * served directly; comments only leave the server through api/comments.php,
 * which checks the participant session.
 */
class Comments
{
    public const MAX_BODY = 4000;
    public const MAX_NAME = 60;

    private string $dir;

    public function __construct(string $siteRoot)
    {
        $this->dir = rtrim($siteRoot, '/') . '/data/comments';
    }

    public const DEFAULT_PROMPT = 'Join the conversation. Comments are open to invited readers.';
    public const DEFAULT_NO_LINK = 'Ask the site owner for an invite.';

    /**
     * The invitation shown to locked visitors. The prompt comes from the site
     * entry's "comments" block in sites.json ("comments": { "prompt": "…" });
     * it is optional. howTo is fixed for now: an invite-request form may
     * replace it later.
     */
    public static function invitation(array $siteConf): array
    {
        $c = is_array($siteConf['comments'] ?? null) ? $siteConf['comments'] : [];
        $prompt = is_string($c['prompt'] ?? null) && trim($c['prompt']) !== ''
            ? trim(mb_substr($c['prompt'], 0, 300))
            : self::DEFAULT_PROMPT;
        return ['prompt' => $prompt, 'howTo' => self::DEFAULT_NO_LINK];
    }

    public static function validKey(string $s): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,120}$/', $s);
    }

    /** All threads for a page. */
    public function load(string $pageKey): array
    {
        if (!self::validKey($pageKey)) return [];
        $f = $this->file($pageKey);
        if (!is_file($f)) return [];
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    /** Comment count per block — safe to show to locked visitors. */
    public function counts(string $pageKey): array
    {
        return array_map('count', $this->load($pageKey));
    }

    public function thread(string $pageKey, string $block): array
    {
        return $this->load($pageKey)[$block] ?? [];
    }

    public function add(string $pageKey, string $block, string $name, string $body): array
    {
        $comment = [
            'id'   => bin2hex(random_bytes(6)),
            'name' => $name,
            'body' => $body,
            'ts'   => time(),
        ];
        $this->mutate($pageKey, function (array $data) use ($block, $comment) {
            $data[$block][] = $comment;
            return $data;
        });
        return $comment;
    }

    public function delete(string $pageKey, string $block, string $id): bool
    {
        $found = false;
        $this->mutate($pageKey, function (array $data) use ($block, $id, &$found) {
            $before = count($data[$block] ?? []);
            $data[$block] = array_values(array_filter($data[$block] ?? [], fn($c) => ($c['id'] ?? '') !== $id));
            $found = count($data[$block]) < $before;
            if (!$data[$block]) unset($data[$block]);
            return $data;
        });
        return $found;
    }

    /* Read-modify-write under an exclusive lock so simultaneous posts don't clobber. */
    private function mutate(string $pageKey, callable $fn): void
    {
        if (!self::validKey($pageKey)) throw new \InvalidArgumentException('bad page key');
        $this->ensureDir();
        $fh = fopen($this->file($pageKey), 'c+');
        if ($fh === false) throw new \RuntimeException('Comments: cannot open store');
        try {
            flock($fh, LOCK_EX);
            $raw  = stream_get_contents($fh);
            $data = json_decode($raw ?: '[]', true);
            $data = $fn(is_array($data) ? $data : []);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Comments: cannot create ' . $this->dir);
        }
        DenyRule::ensure($this->dir, 'PapyrCMS: comments are served only through _papyr/api/comments.php');
    }

    private function file(string $pageKey): string
    {
        return $this->dir . '/' . $pageKey . '.json';
    }
}
