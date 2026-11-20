<?php
declare(strict_types=1);

namespace Papyr\Core;

/**
 * Reshapes a NavBuilder tree into the single-rooted node graph the 3D site
 * map renders: { id, text, kind, path, color, themed, draft, cms_managed,
 * mtime, children }.
 *
 * Read-only, like NavBuilder — it derives everything from the tree it is
 * given and never touches disk. Theme lookup is injected by the caller so
 * this class stays independent of where themes are stored.
 *
 * Index pages fold into their container: the site's top-level index.php
 * becomes the root node, and docs/index.php becomes the "docs" folder node.
 * A folder with no index page stays a plain grouping node with no link.
 */
final class MapTree
{
    private const INDEX_PATTERN = '/^index\.(php|html?)$/i';

    /**
     * Fixed status colours for provider nodes (ADR-002 §6.6) — the same
     * meaning as SAL's badges, deliberately never re-themed.
     */
    public const STATUS_COLORS = [
        'proven'    => ['h' => 149.0, 's' => 57.0, 'l' => 55.0],
        'caution'   => ['h' => 40.0,  's' => 90.0, 'l' => 62.0],
        'disproven' => ['h' => 5.0,   's' => 80.0, 'l' => 66.0],
        'open'      => ['h' => 200.0, 's' => 70.0, 'l' => 70.0],
    ];

    /**
     * @param array    $tree      Output of NavBuilder::tree()
     * @param string   $rootLabel Text for the root node (the site's label)
     * @param callable $themeFor  fn(string $relPath): array{color: ?array, themed: bool}
     *                            color is ['h' => float, 's' => float, 'l' => float]
     */
    public static function fromNav(array $tree, string $rootLabel, callable $themeFor): array
    {
        $root = self::node('/', $rootLabel, 'root');
        self::fill($root, $tree, '', $themeFor);
        return $root;
    }

    private static function fill(array &$parent, array $nodes, string $prefix, callable $themeFor): void
    {
        foreach ($nodes as $key => $node) {
            $key = (string) $key;

            if (!empty($node['is_dir'])) {
                $dirPath = $prefix . $key . '/';
                $dir     = self::node($dirPath, (string) $node['title'], 'dir');
                self::fill($dir, $node['children'] ?? [], $dirPath, $themeFor);
                $parent['children'][] = $dir;
                continue;
            }

            $relPath = (string) $node['path'];
            $theme   = $themeFor($relPath);
            $page    = [
                'path'        => $relPath,
                'color'       => $theme['color'] ?? null,
                'themed'      => (bool) ($theme['themed'] ?? false),
                'draft'       => !empty($node['draft']),
                'cms_managed' => !empty($node['cms_managed']),
                'mtime'       => $node['mtime'] ?? null,
            ];

            // First index page in a container becomes that container's page.
            if ($parent['path'] === null && preg_match(self::INDEX_PATTERN, $key) === 1) {
                $parent = array_merge($parent, $page);
                $parent['page_title'] = (string) $node['title'];
                continue;
            }

            $parent['children'][] = array_merge(
                self::node($relPath, (string) $node['title'], 'page'),
                $page
            );
        }
    }

    private static function node(string $id, string $text, string $kind): array
    {
        return [
            'id'          => $id,
            'text'        => $text,
            'kind'        => $kind,
            'path'        => null,
            'page_title'  => null,
            'color'       => null,
            'themed'      => false,
            'draft'       => false,
            'cms_managed' => false,
            'mtime'       => null,
            'children'    => [],
        ];
    }

    /**
     * Attach provider nodes (hook `map.nodes`) to a fromNav() graph.
     *
     * Each node: {id, parent, text, status?, color?, model?, link?, visibility?}.
     * `parent` is a page path in the graph ("research.php") or another node's id;
     * nodes may chain, in any order. Invalid nodes, duplicate ids and nodes
     * whose parent never appears are dropped and logged. `visibility: private`
     * nodes are dropped unless $admin. `link` must be site-relative (no scheme,
     * no "//", no ".."); anything else is dropped from the node.
     */
    public static function attach(array $root, array $nodes, bool $admin): array
    {
        $index = [];
        self::indexGraph($root, [], $index);

        $pending = [];
        foreach ($nodes as $n) {
            $clean = is_array($n) ? self::cleanNode($n, $admin) : null;
            if ($clean === null) {
                continue;
            }
            if (isset($index[$clean['id']]) || isset($pending[$clean['id']])) {
                error_log("[PapyrCMS] map.nodes: duplicate id '{$clean['id']}' dropped.");
                continue;
            }
            $pending[$clean['id']] = $clean;
        }

        // Attach in passes so a node may name a parent that is itself a provider node.
        do {
            $progress = false;
            foreach ($pending as $id => $node) {
                $parentKey = $node['_parent'];
                if (!isset($index[$parentKey])) {
                    continue;
                }
                unset($node['_parent']);
                $ref = &self::at($root, $index[$parentKey]);
                $ref['children'][] = $node;
                $index[$id] = array_merge($index[$parentKey], [count($ref['children']) - 1]);
                unset($ref, $pending[$id]);
                $progress = true;
            }
        } while ($progress && $pending !== []);

        foreach ($pending as $id => $node) {
            error_log("[PapyrCMS] map.nodes: '{$id}' dropped — parent '{$node['_parent']}' is not on the map.");
        }
        return $root;
    }

    /** Index every node by id and by page path → child-index route from the root. */
    private static function indexGraph(array $node, array $route, array &$index): void
    {
        $index[$node['id']] = $route;
        if (!empty($node['path'])) {
            $index[$node['path']] = $route;
        }
        foreach ($node['children'] ?? [] as $i => $child) {
            self::indexGraph($child, array_merge($route, [$i]), $index);
        }
    }

    private static function &at(array &$root, array $route): array
    {
        $ref = &$root;
        foreach ($route as $i) {
            $ref = &$ref['children'][$i];
        }
        return $ref;
    }

    private static function cleanNode(array $n, bool $admin): ?array
    {
        $id     = $n['id'] ?? null;
        $parent = $n['parent'] ?? null;
        $text   = $n['text'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_.:\/#-]{1,200}$/', $id) !== 1
            || !is_string($parent) || $parent === '' || !is_string($text) || trim($text) === '') {
            error_log('[PapyrCMS] map.nodes: node without a valid id/parent/text dropped.');
            return null;
        }
        $visibility = ($n['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        if ($visibility === 'private' && !$admin) {
            return null;
        }
        $status = is_string($n['status'] ?? null) && isset(self::STATUS_COLORS[$n['status']]) ? $n['status'] : 'open';
        $color  = self::STATUS_COLORS[$status];
        if (is_array($n['color'] ?? null) && !isset($n['status'])
            && is_numeric($n['color']['h'] ?? null) && is_numeric($n['color']['s'] ?? null) && is_numeric($n['color']['l'] ?? null)) {
            $color = ['h' => (float) $n['color']['h'], 's' => (float) $n['color']['s'], 'l' => (float) $n['color']['l']];
        }
        $raw  = is_string($n['link'] ?? null) ? $n['link'] : null;
        $link = $raw === null ? null : (str_starts_with($raw, '//') ? '' : ltrim($raw, '/'));   // '//host' is off-site, not relative
        if ($link !== null && ($link === '' || preg_match('#^[A-Za-z0-9_./-]*(\#[A-Za-z0-9_.:-]*)?$#', $link) !== 1
                || str_contains($link, '..') || str_contains($link, '//'))) {
            error_log("[PapyrCMS] map.nodes: unsafe link on '{$id}' removed.");
            $link = null;
        }
        $model = null;
        if (is_array($n['model'] ?? null) && is_string($n['model']['src'] ?? null)) {
            $model = [                           // carried for ADR-003; v1 renders the sphere
                'src'      => $n['model']['src'],
                'scale'    => is_numeric($n['model']['scale'] ?? null) ? (float) $n['model']['scale'] : 1.0,
                'rotation' => array_map('floatval', array_slice(array_values((array) ($n['model']['rotation'] ?? [0, 0, 0])), 0, 3)),
            ];
        }
        return array_merge(self::node($id, trim($text), 'node'), [
            '_parent'    => $parent,
            'status'     => $status,
            'color'      => $color,
            'link'       => $link,
            'model'      => $model,
            'visibility' => $visibility,
        ]);
    }
}
