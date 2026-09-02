<?php
/**
 * papyr:tokens-enumerate
 *
 * Reads the theme token CONTRACT — names + bind-status only.
 * Never emits resolved values. The designer learns what hooks
 * exist and whether each is live; the engine keeps the contents.
 *
 * Source of truth = the token map (same place the engine reads),
 * so the readout can never lie about what's available.
 */

function papyr_tokens_enumerate($tokenMapPath, $templateGlob) {

    // 1. DEFINED — the declared contract.
    //    Parse the :root block for --custom-property names only.
    //    We deliberately discard the value side of the colon.
    $defined = [];
    if (is_readable($tokenMapPath)) {
        $css = file_get_contents($tokenMapPath);
        // match `--name:` inside :root { ... } ; capture name, drop value
        if (preg_match('/:root\s*\{(.*?)\}/s', $css, $block)) {
            preg_match_all('/(--[a-z0-9-]+)\s*:/i', $block[1], $m);
            $defined = array_unique($m[1]);
        }
    }

    // 2. REFERENCED — every var() actually used across templates.
    $referenced = [];
    foreach (glob($templateGlob) as $tpl) {
        if (!is_readable($tpl)) continue;
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', file_get_contents($tpl), $r);
        foreach ($r[1] as $name) $referenced[$name] = true;
    }

    // 3. RECONCILE — the contract vs. its usage.
    $registry = [];
    foreach ($defined as $name) {
        $registry[$name] = isset($referenced[$name])
            ? 'bound'        // declared AND used   ✓
            : 'unbound';     // declared, never used ⚠  (orphan token)
    }
    // referenced but never declared = a template asking for a hook
    // the engine doesn't provide. Surface it — that's the silent bug.
    foreach (array_keys($referenced) as $name) {
        if (!isset($registry[$name])) {
            $registry[$name] = 'missing'; // ⚠ used but undefined
        }
    }

    return $registry; // [ '--hue-base' => 'bound', '--lost-accent' => 'unbound', ... ]
}

function papyr_tokens_render($registry) {

    $glyph = ['bound' => '✓', 'unbound' => '⚠', 'missing' => '⚠'];
    $note  = [
        'bound'   => 'bound',
        'unbound' => 'unbound · defined, no template uses it',
        'missing' => 'missing · template references an undefined token',
    ];

    // group by component prefix: --hue-, --sat-, --lum-, --accent-
    $groups = [];
    foreach ($registry as $name => $state) {
        $prefix = preg_match('/^--([a-z0-9]+)-/i', $name, $p) ? $p[1] : 'other';
        $groups[$prefix][$name] = $state;
    }
    ksort($groups);

    $live = count(array_filter($registry, fn($s) => $s === 'bound'));
    echo "THEME TOKENS — {$live} live / " . count($registry) . " declared\n";

    foreach ($groups as $prefix => $tokens) {
        echo "\n  [{$prefix}]\n";
        foreach ($tokens as $name => $state) {
            printf("    %s  %-22s %s\n",
                $glyph[$state], $name, $note[$state]);
        }
    }
}