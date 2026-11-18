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
}
