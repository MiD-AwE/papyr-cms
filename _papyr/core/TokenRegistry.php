<?php
declare(strict_types=1);

namespace Papyr\Core;

class TokenRegistry
{
    /*
     * Build the token contract from two sources:
     *   DEFINED   — property names found inside :root { } in $tokenMapPath
     *   REFERENCED — every var(--*) usage found in $templatePaths
     *
     * Returns a flat array: [ '--token-name' => 'bound'|'unbound'|'missing' ]
     *
     * bound   — defined AND referenced in at least one template ✓
     * unbound — defined but no template references it (orphan token)
     * missing — template references it but it isn't defined (silent bug)
     *
     * The regex parse is intentionally simple. It is suitable for the
     * controlled, single-author CSS file that PapyrCMS owns; swap to a
     * proper tokeniser if the format ever goes multi-author or nested.
     */
    public static function enumerate(string $tokenMapPath, array $templatePaths): array
    {
        // ── 1. DEFINED — names only, not values ───────────────────
        $defined = [];
        if (is_readable($tokenMapPath)) {
            $css = (string) file_get_contents($tokenMapPath);
            if (preg_match('/:root\s*\{(.*?)\}/s', $css, $block)) {
                preg_match_all('/(--[a-z0-9][a-z0-9-]*)\s*:/i', $block[1], $m);
                $defined = array_unique($m[1]);
            }
        }

        // ── 2. REFERENCED — every var(--*) across templates ───────
        $referenced = [];
        foreach ($templatePaths as $path) {
            if (!is_readable($path)) continue;
            $src = (string) file_get_contents($path);
            preg_match_all('/var\(\s*(--[a-z0-9][a-z0-9-]*)/i', $src, $r);
            foreach ($r[1] as $name) {
                $referenced[$name] = true;
            }
        }

        // ── 3. RECONCILE ───────────────────────────────────────────
        $registry = [];
        foreach ($defined as $name) {
            $registry[$name] = isset($referenced[$name]) ? 'bound' : 'unbound';
        }
        foreach (array_keys($referenced) as $name) {
            if (!isset($registry[$name])) {
                $registry[$name] = 'missing';
            }
        }

        ksort($registry);
        return $registry;
    }

    /*
     * Render the registry as an HTML table fragment (no surrounding <table>).
     * Caller wraps in whatever container fits the UI.
     *
     * Returns:  [ 'rows' => string (HTML), 'counts' => ['bound'=>n, 'unbound'=>n, 'missing'=>n] ]
     */
    public static function render(array $registry): array
    {
        $glyph = ['bound' => '✓', 'unbound' => '○', 'missing' => '✕'];
        $note  = [
            'bound'   => 'bound',
            'unbound' => 'defined · no template uses it',
            'missing' => 'missing · template references an undefined token',
        ];

        $counts = ['bound' => 0, 'unbound' => 0, 'missing' => 0];
        foreach ($registry as $status) {
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        // Group by prefix (first segment after --)
        $groups = [];
        foreach ($registry as $name => $status) {
            preg_match('/^--([a-z0-9]+)/i', $name, $p);
            $prefix = $p[1] ?? 'other';
            $groups[$prefix][$name] = $status;
        }
        ksort($groups);

        $rows = '';
        foreach ($groups as $prefix => $tokens) {
            $rows .= '<tr class="tr-group"><td colspan="3" class="td-group">' . htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') . '</td></tr>';
            foreach ($tokens as $name => $status) {
                $rows .= '<tr class="tr-token tr-' . $status . '">'
                    . '<td class="td-glyph">' . $glyph[$status] . '</td>'
                    . '<td class="td-name"><code>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</code></td>'
                    . '<td class="td-note">' . htmlspecialchars($note[$status], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '</tr>';
            }
        }

        return ['rows' => $rows, 'counts' => $counts];
    }
}
