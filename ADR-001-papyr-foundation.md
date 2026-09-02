# ADR-001 — PapyrCMS Drop-In Foundation, Nav Spine & Theming

**Status:** Accepted · **Date:** 2026-06-20 · **For:** Claude Code (implement in local LAMPP, test against scan)
**Author intent captured by:** design conversation with Chuck (MiD-AWE)

> Read `CLAUDE.md` first. This ADR refines the "Next Steps" section. Nothing here
> overrides the Core Philosophy. Where this ADR and CLAUDE.md disagree, CLAUDE.md wins
> and this file is wrong — flag it.

---

## 0. One-paragraph summary

PapyrCMS is installed **once per subdomain**, as a sealed `_papyr/` engine directory
dropped into the site it manages. The site's own pages live at the site root and nest
to any depth. The engine never owns site files. `nav.php` is the hand-authored **spine**:
it renders a live page tree (read-only, derived from the manifest, never a generated
file) and hosts the theming panel. Theming is a **base engine capability** (a reusable
`_papyr/` partial) that sites opt into simply by deriving their CSS from HSL global
tokens — use it or not, no config. Draft pages are marked in-file and excluded from the
public nav but remain servable for testing.

---

## 1. Directory contract (drop-in model)

Every managed site looks like this. The engine is one sealed directory; everything else
is the site's own content, owned by the developer.

```
somesite.mid-awe.com/
├── index.php                 ← site homepage (content page)
├── about.php
├── tutorials/                ← divisions nest to ANY depth, no limit
│   ├── index.php
│   ├── installation.php
│   └── advanced/
│       └── theming.php
├── nav.php                   ← THE SPINE — hand-authored, CMS NEVER writes it
├── footer.php                ← structural partial, ignored
├── assets/                   ← ignored (css/js/img/etc.)
├── data/
│   └── themes/               ← per-page theme JSON, OWNED BY THE SITE
│       ├── index.json
│       └── tutorials_installation.json
└── _papyr/                   ← THE ENGINE — sealed, drop-in, owns nothing outside itself
    ├── index.php             ← admin entry point (reached at /_papyr/)
    ├── bootstrap.php
    ├── core/   (Scanner, AIBridge, NavBuilder, PageWriter, TemplateEngine)
    ├── config/sites.json
    ├── templates/
    │   └── partials/
    │       └── theme-panel.*  ← extracted HSL slider UI (see §6)
    ├── api/    (load-theme.php, save-theme.php, ai.php)
    ├── vendor/
    └── cache/manifest.json
```

**Rules:**
- The engine lives **only** under `_papyr/`. It places **no** file at the site root.
  Dropping `_papyr/` into any subdomain makes that subdomain self-managing. Removing it
  leaves a fully working plain site (every page is standard PHP/HTML).
- Site content pages live at root and nest freely. Directory structure = division
  structure = nav tree structure (filesystem is truth, all the way to the menu).
- `data/themes/` is **site-owned content**, not engine state. It survives an engine swap.

---

## 2. Single site per install

- Each `_papyr/` install manages exactly **one** site: the one it is installed inside.
- The managed site's root is the **parent** of `_papyr/`. Derive it, never hardcode:
  ```php
  $siteRoot = realpath(__DIR__ . '/..');   // from within _papyr/bootstrap.php
  ```
- **Change `config/sites.json` to use relative roots** (e.g. `"root": ".."`), resolved
  to absolute via `realpath(__DIR__ . '/' . $relativeRoot)` at boot. This kills the
  current hardcoded `/var/www/...` paths, which are wrong on this host (confirmed:
  hosting is side-by-side subdomain dirs, not `/var/www`). Portable config, zero path
  editing on drop-in.
- The multi-site `sites` map in sites.json may remain as a structure, but a standard
  install populates exactly one entry, derived from `__DIR__`. (The `papyrcms.mid-awe.com`
  showcase instance is just a normal single-site install whose content happens to be
  *about* PapyrCMS.)

---

## 3. Scanner: ignore-by-convention

- **Ignore any directory whose name begins with `_`.** This seals `_papyr/` (and any
  other `_*` dir) out of the scan automatically, so the CMS never scans its own engine
  and never lists engine files as "pages."
- Continue honoring existing `ignore_files` (nav.php, footer.php, header.php,
  functions.php, config.php) and `ignore_dirs` (vendor, node_modules, .git, cache,
  assets, css, js, img, images, fonts, data, …) from sites.json.
- Net effect: the Scanner sees only real content pages, at any nesting depth.

---

## 4. NavBuilder — read-only, render-time, NEVER writes a file

This is the crux. The nav must reflect **only the truth** and must never create a
sync/merge problem.

- **`NavBuilder::tree(string $siteRoot): array`** — returns the current page tree as a
  **nested** data structure (children nested under their directory parents), computed
  **fresh from the manifest** on each call (triggering a scan if `scan_ttl_seconds`
  expired). Pure read. **Returns data; writes nothing.**
- There is **no generated nav file.** The menu is *derived at render time*, not *built
  to disk*. This dissolves the read-then-diff-then-merge problem entirely: there is no
  stored artifact to drift from the filesystem.
- `nav.php` (hand-authored, in the site root, CMS never touches it) calls
  `NavBuilder::tree()` and renders the result with the site's own markup/CSS/GSAP.
  The **data** is dynamic (manifest); the **presentation file** is developer-owned and
  manual-edit-safe. The two CLAUDE.md principles (dynamic + manual-edits-safe) stop
  conflicting because they no longer touch the same artifact.
- Tree honors §3 ignores and §5 draft exclusion.

---

## 5. Draft / under-construction convention

- A page is marked draft by an **in-file marker comment**: `<!-- papyr:draft -->`
  (Scanner already reads the first 4KB per file for title extraction — pick this up in
  the same read, no extra I/O).
- A draft page is: **scanned** (CMS knows it exists), **servable** (loadable by direct
  URL for testing), **badged "draft" in the admin**, and **excluded from
  `NavBuilder::tree()`** (not in the public menu).
- **Promote to public = delete the marker line.** The filename and URL never change, so
  links don't break. (Contrast with a filename-based convention, which would rename and
  break references — rejected for that reason.)
- **Known property, document it:** drafts are *unlisted, not access-controlled*. Anyone
  with the URL can load a draft. That is acceptable for the current use case (testing a
  contact page). True access control is a future concern, not this ADR.
- Do **not** overload the `_` prefix for drafts — `_` means "engine/ignored directory"
  only. One symbol, one meaning.

---

## 6. Theming — base engine capability, opt-in by adoption

Theming is a **base system function** (gets a page presentable in seconds), so it ships
**in the engine** as a reusable partial, not per-site.

- **Extract the HSL token system + slider panel from the n01z `tpl.php`** (the album-page
  template that currently contains it inline) into a reusable engine partial at
  `_papyr/templates/partials/theme-panel.*` (markup + JS + minimal CSS). This is a
  Claude Code task for this ADR.
- **Token model (already proven in tpl.php):** colors derive from HSL **component**
  globals — `--accent-h/s/l`, `--accent2-h/s/l`, `--bg-h/s/l`, `--text-h/s/l`, plus
  alpha knobs — and every composed color is built from them. Sliders write the component
  vars; the whole UI re-themes from one change. (See tpl.php for the working reference,
  including the contrast-locked, theme-independent QR tokens and the dark/light
  luminance-flip that preserves hue.)
- **Opt-in by adoption, NOT by config:** a site benefits from theming exactly to the
  degree its CSS derives from the global tokens. If it does, the sliders move everything.
  If it hardcodes colors, the panel still opens and the globals still move, but nothing
  visible changes — no error, no breakage. "Use it or not" with zero flags.
- **Token discovery at runtime (reusability goal):** the panel JS should **discover** the
  available `--*-h/s/l` triplets from the page's computed `:root` (it already uses
  `getComputedStyle`) and build a slider group per discovered token, rather than
  hardcoding the n01z four-token set. This lets the same partial serve any site's token
  set. Design goal, not a blocker — a hardcoded fallback set is acceptable for v1.
- **Persistence (per-page, site-owned):**
  - Load: on render, PHP reads `data/themes/{pageKey}.json` and injects the saved HSL
    component values into `:root` (no flash of default colors). Injection pattern already
    commented into the tpl.php `:root`.
  - Save: the panel "Commit" POSTs current component values to `_papyr/api/save-theme.php`,
    which writes `data/themes/{pageKey}.json` (one flat file per page,
    `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`). `api/load-theme.php` reads it.
  - `{pageKey}` derives from the page's path relative to site root (e.g.
    `tutorials/installation.php` → `tutorials_installation`). Keep it stable so the
    theme tracks the page.
  - The engine `api/` **reads and writes** `data/themes/` but the data is **site-owned**:
    it persists across engine swaps and is never placed inside `_papyr/`.

---

## 7. nav.php as the spine (and its future as a control panel)

- `nav.php` is the single tangible spine a developer includes. From one include they get:
  (a) the live page tree (§4), and (b) the theming **settings button** that opens the
  `_papyr/` theme-panel partial (§6).
- It is hand-authored and the CMS never writes it. It is where the site's identity,
  styling, and GSAP "spit-shine" live. The engine emits **structure and truth**; the
  site's nav.php provides **beauty**.

### Future phase — Nav as control panel (POST-foundation, do not build now)
nav.php is designed to **evolve** from a read-only spine into a context-aware control
surface (cf. TypeSetter CMS's in-place floating toolbar): a dynamic settings button, and
contextual "create page **in this division**" affordances that invoke `PageWriter`.

**Design constraint that the foundation MUST respect now so this is additive, not a
refactor:**
- **Read/display controls** (menu render, open-theme-panel, draft badges) may live in the
  public, hand-authored nav.php. Safe, idempotent, no auth.
- **Write controls** (create page, delete, promote-draft, save-theme) must route through
  **authenticated `_papyr/` endpoints**. The nav may host the *trigger* (button); the
  engine performs the *write* behind an auth gate.
- Therefore: a public visitor sees the menu and theme panel; only the authenticated owner
  sees/fires write controls. "Create page from the nav" must never mean "anyone on the
  internet can write files to the site."

Build the foundation knowing this is coming. Do not implement create/delete/PageWriter
wiring in this ADR.

---

## 8. Scope of THIS ADR (build list)

In order:
1. **bootstrap.php / sites.json** — relative-root derivation (`realpath(__DIR__.'/..')`);
   single-site-per-install. Remove hardcoded `/var/www/...`.
2. **Scanner.php** — ignore `_`-prefixed dirs; respect existing ignores; nested output.
3. **core/NavBuilder.php** — `tree(siteRoot): array`, read-only, nested, manifest-derived,
   honors draft exclusion. Writes nothing, ever.
4. **Draft marker** — `<!-- papyr:draft -->` recognized in scan; excluded from tree;
   badged in admin; servable.
5. **Theme partial** — extract HSL panel from n01z `tpl.php` → `_papyr/templates/partials/`;
   runtime token discovery (with fallback); `api/load-theme.php` + `api/save-theme.php`
   reading/writing site `data/themes/{pageKey}.json`.
6. **nav.php reference implementation** (for the showcase site) — renders `NavBuilder::tree()`
   + includes theme-panel partial + GSAP. This is *site* code, a reference example, not
   engine code.

**Explicitly NOT in this ADR:** PageWriter create/delete flow, the control-panel write
controls, auth gating implementation, TemplateEngine page generation. Those are the next
ADR. Keep this one to the read-side foundation + theming + the spine.

---

## 9. Philosophy check (must remain true after this ADR)

- [ ] Deleting `cache/manifest.json` breaks nothing (manifest is a cache; nav derives live).
- [ ] Hand-editing nav.php is always safe (CMS never writes it).
- [ ] Hand-editing any page is always safe (next scan notices gracefully).
- [ ] The CMS works fully with **no AI** configured (`ai:cloud none` is fine).
- [ ] Removing `_papyr/` leaves a working plain PHP/HTML site.
- [ ] No file outside `_papyr/` is owned by the engine except site-authored `data/themes/`
      JSON, which the site owns and the engine only reads/writes on request.
- [ ] `?papyr_status=1` debug endpoint removed before public release (per CLAUDE.md).
