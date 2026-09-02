<?php
declare(strict_types=1);

namespace Papyr\Core;

class NavBuilder
{
    public static function tree(array $pages): array
    {
        $tree = [];
        ksort($pages);

        foreach ($pages as $relPath => $page) {
            if (!empty($page['draft'])) continue;

            $parts = explode('/', $relPath);
            self::insert($tree, $parts, $relPath, $page);
        }

        return $tree;
    }

    private static function insert(array &$node, array $parts, string $fullPath, array $page): void
    {
        $part = array_shift($parts);

        if (empty($parts)) {
            $node[$part] = [
                'path'        => $fullPath,
                'title'       => $page['title'] ?? self::humanize($part),
                'cms_managed' => $page['cms_managed'] ?? false,
                'mtime'       => $page['mtime'] ?? null,
                'is_dir'      => false,
                'children'    => [],
            ];
        } else {
            if (!isset($node[$part])) {
                $node[$part] = [
                    'path'    => '',
                    'title'   => self::humanize($part),
                    'is_dir'  => true,
                    'children' => [],
                ];
            }
            self::insert($node[$part]['children'], $parts, $fullPath, $page);
        }
    }

    private static function humanize(string $name): string
    {
        $name = pathinfo($name, PATHINFO_FILENAME);
        $name = preg_replace('/[-_]+/', ' ', $name) ?? $name;
        return ucwords($name);
    }
}
