# PapyrCMS — Design Reconciliation Handoff to Claude Code
**Source:** Design-alignment chat sessions, week of June 25, 2026
**Purpose:** Reconcile refined design intent against current development. None of this overrides ADR-001 or the write-side build that just landed — it is *additive intent* that needs checking against the code as it stands.

For each item: confirm whether it's already satisfied, implement it if it's a clean self-contained add, or flag it for the design-alignment / next ADR session if it touches nav behavior or auth.

**The deciding test, applied to every item:** *Does it touch nav behavior or auth?* If yes → stage. If it's a self-contained read-and-render → build now.

---

## 1. Public health block — status, not internals
**Verdict: BUILD NOW (self-contained)**

A `papyr:health()`-style readout that confirms subsystems resolved *without* echoing host strings, docroot paths, local AI endpoints (e.g. `127.0.0.1:5005`), server/software versions, or secrets.

Render verdicts only:
- Host ✓
- Engine (`_papyr/`) loaded ✓
- Scanner found N pages ✓
- Theme applied ✓

**Principle:** describe the contract, not the state. The actual values (host, path, page count) stay server-side where they're checked; the page renders only the verdict.

**Note:** `?papyr_status=1` was already removed from `bootstrap.php`. This block is the *intentional public-facing replacement* — not a revival of that debug endpoint. Confirm nothing in this block interpolates a path or host into output.

---

## 2. Theme token registry — names + bind-status only
**Verdict: BUILD NOW (self-contained) — this is the "earn its keep" feature**

Enumerate the theme token *contract* for template designers: which `--*` tokens exist and whether each is live — **never resolved values**.

Reconcile two sources:
- **DEFINED** — tokens declared in the `:root` / token map
- **REFERENCED** — tokens used via `var()` across templates

Three states:
- `bound` — defined AND used ✓
- `unbound` — defined, no template uses it ⚠ (orphan token)
- `missing` — template references an undefined token ⚠ (silent bug)

Group output by component prefix (`hue-` / `sat-` / `lum-` / `accent-`). Read from the **same token source the engine reads**, so the readout can't drift out of sync.

This is the gift feature — it catches the two silent bugs (forgot-to-wire, typo'd var) for free.

**Two functions were sketched in chat** (port/adapt as fits the codebase):

```php
function papyr_tokens_enumerate($tokenMapPath, $templateGlob) {
    // 1. DEFINED — parse :root block for --custom-property NAMES only (discard values)
    $defined = [];
    if (is_readable($tokenMapPath)) {
        $css = file_get_contents($tokenMapPath);
        if (preg_match('/:root\s*\{(.*?)\}/s', $css, $block)) {
            preg_match_all('/(--[a-z0-9-]+)\s*:/i', $block[1], $m);
            $defined = array_unique($m[1]);
        }
    }
    // 2. REFERENCED — every var() used across templates
    $referenced = [];
    foreach (glob($templateGlob) as $tpl) {
        if (!is_readable($tpl)) continue;
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', file_get_contents($tpl), $r);
        foreach ($r[1] as $name) $referenced[$name] = true;
    }
    // 3. RECONCILE
    $registry = [];
    foreach ($defined as $name) {
        $registry[$name] = isset($referenced[$name]) ? 'bound' : 'unbound';
    }
    foreach (array_keys($referenced) as $name) {
        if (!isset($registry[$name])) $registry[$name] = 'missing';
    }
    return $registry;
}

function papyr_tokens_render($registry) {
    $glyph = ['bound' => '✓', 'unbound' => '⚠', 'missing' => '⚠'];
    $note  = [
        'bound'   => 'bound',
        'unbound' => 'unbound · defined, no template uses it',
        'missing' => 'missing · template references an undefined token',
    ];
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
            printf("    %s  %-22s %s\n", $glyph[$state], $name, $note[$state]);
        }
    }
}
```

**Check:** how this relates to the existing dynamic token discovery in `theme-panel.php` (the `document.styleSheets` scan, with fallback to `['accent','accent2','bg','text']`). They're related but distinct — the registry is a **server-side designer tool**, the panel scan is **client-side runtime**. Decide whether one feeds the other or they stay separate. The regex `:root` parse is fine for the controlled, sealed token file PapyrCMS owns; if it ever parses arbitrary/nested CSS, swap to a real tokenizer.

---

## 3. Lost & found / uncategorized-page handling
**Verdict: STAGE — do NOT implement yet (touches nav + read-only NavBuilder mandate)**

Design intent is settled but deliberately deferred to the design-alignment session. Capturing it so it isn't lost:

- An uncategorized standalone page is treated as a **notice, not a warning** — a yellow notice-bubble: *"this page is uncategorized and will be listed in lost & found."* Never red / never "broken."
- **Rationale:** an uncategorized page *is* its own topic. Lost & found (living under **Help**) is an archive for topics that lost currency as new ideas emerged — sometimes carrying traces of the original thoughts. The page still works, still serves, still gets found; it lands in the archive instead of vanishing.
- Nav's only honest actions on an orphan are **timestamp + reposition** the reference in the menu — it must not overstep the read-only mandate.
- This is the **same notice pattern as the `unbound` token in item 2, in a different domain** — both mean *"exists but not yet placed."* When built, reuse the enumerate → reconcile → render shape from item 2.

**Claude Code:** prepare/note this as a staging point. Do not make behavioral nav decisions on it now.

---

## 4. Auth gating
**Verdict: STAGE — next ADR (already correctly scoped)**

Confirmed as the single load-bearing pre-launch item. Three endpoints currently act on unauthenticated requests:
- `index.php` POST handlers
- `api/ai.php`
- `api/save-theme.php`

Risk: open `save-theme.php` = theme-defacement vector; open `api/ai.php` = Cerebras cost-abuse vector. Fine for the showcase install, **not safe for a stranger's drop-in.** No action now — just confirm this is captured as the next ADR's primary item.

---

## Guiding principle for all four
Leanness is the goal, but **PapyrCMS has to earn its keep.** Items 1 and 2 are exactly the "prove its usefulness" utilities — prioritize landing those cleanly. Items 3 and 4 protect intent by *not* being rushed.

When in doubt between implement-now and stage: **does it touch nav behavior or auth? If yes, stage. If it's a self-contained read-and-render, build it.**
