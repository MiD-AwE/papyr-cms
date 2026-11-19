# ADR-002 — Sessions, Site Config, Plugin Context & Page Writes

**Status:** Proposed · **Date:** 2026-11-18 · **For:** Claude Code (implement in local XAMPP on Omarchy, phase by phase)
**Author intent captured by:** design conversation with Chuck (MiD-AWE)
**Builds on:** ADR-001 (drop-in foundation, nav spine, theming)

> Read `CLAUDE.md` first, then ADR-001. Nothing here overrides the Core Philosophy.
> Where this ADR and CLAUDE.md disagree, CLAUDE.md wins and this file is wrong — flag it.
> Implement **one phase at a time, in order**. Each phase ends with its own commit and
> must leave every live site working (see §8 acceptance checks).

---

## 0. One-paragraph summary

PapyrCMS now runs three real sites (papyrcms.mid-awe.com, mosquito.mid-awe.com,
n01z.mid-awe.com planned) and has grown two significant features since ADR-001: a
read-only 3D site map and invite-only participant comments. Both exposed the same
weaknesses: the session starts after output, sessions leak between sites on one host,
CSRF lives inside one feature, site secrets live inside the engine folder (so upgrades
are destructive), and features reach engine state through loose globals. This ADR fixes
those foundations **first**, then adds a small plugin system with one read-only context
object, moves the site map into the first plugin, lets sites contribute their own map
nodes (SAL is the worked example), and only then adds the gated page-write endpoint that
ADR-001 §7 deferred.

**The rule that governs every phase: plugins read and present; only the engine writes.**

---

## 1. Where the engine stands (master @ 88ba341)

| Area | State |
|---|---|
| Foundation (ADR-001) | Scanner, NavBuilder, UrlResolver, AIBridge, PageWriter, TemplateEngine, TokenRegistry, AdminAuth — as documented in CLAUDE.md |
| 3D site map | `map.php` → `_papyr/templates/partials/site-map.php` + `_papyr/assets/js/site-map.js`; `Papyr\Core\MapTree` turns `NavBuilder::tree()` into mind-map nodes; Three.js r128 in `_papyr/assets/three/`; `NavBuilder` has opt-in `includeDrafts`; `papyr_theme_defaults()` exposed by `load-theme.php` |
| Invite comments | `MemberAuth` (second role, invite password, fingerprint rotation, per-IP throttle), `Comments` (flat JSON per page, flock'd, `.htaccess`-denied folder), `api/unlock.php`, `api/comments.php`, `partials/comments.php` (`papyr_comments($block)`), mobile drawer in `nav.php`, theme-panel Access section, server page key for theme saves |
| Live sites | papyrcms.mid-awe.com (demo, new landing page), mosquito.mid-awe.com (SAL — JSON blocks, comments in use by invitees) |

---

## 2. Problems this ADR solves

1. **Session starts after output.** Every page includes `nav.php` after `<!DOCTYPE>` and
   `<head>`; `nav.php` calls `@session_start()`. Its comment claims an existing session is
   still readable after output — it is not. With `output_buffering = 0` (PHP's built-in
   server, some hosts) or a `<head>` over 4 KB, the session never starts:
   `$_papyrIsAdmin` and `isMember()` are always false and every CSRF check fails. It works
   today only because XAMPP and the current hosts buffer 4 KB.
2. **Sessions leak between sites on one host.** `api/unlock.php` and `api/comments.php`
   set the cookie path to `/`. Two installs on one host (`localhost/papyr-cms`,
   `localhost/SolarPoweredBugZapper`, or any subfolder install) share one session: admin
   on one is admin on all; unlocking one unlocks all.
3. **CSRF is a feature detail, not an engine service.** The token lives in `MemberAuth`.
   `save-theme.php` has no CSRF check. The coming page-write endpoint needs one.
4. **Site secrets live inside the engine.** `_papyr/config/sites.json`, `secret.php`,
   `invite.php`. "Replace `_papyr/` to upgrade" would wipe a site's config and passwords.
5. **Loose globals.** ~15 `$_papyr*` variables; the comments partial reaches them with
   `global`; theme panel and comments each build their own JS config. No contract for
   plugins.
6. **The map is engine-resident but optional.** It ships Three.js (~600 KB) inside the
   core engine for every site, and SAL needs nodes the Scanner can't see (its JSON blocks).
7. **No way to change pages from the site.** ADR-001 §7's "nav as control panel" is still
   deferred; the map card is where those controls belong.

Minor, fixed in passing: `login.php` compares referrer host against `HTTP_HOST` including
the port, so a non-standard port always redirects to the dashboard.

---

## 3. Phase A — Session & CSRF as one engine service

### 3.1 `Papyr\Core\Session` (new)

One class owns the session for the whole engine. Nothing else calls `session_start()`.

```php
Session::start(string $siteKey, string $basePath): void
  // idempotent; no-op if already active
  // session_name('PAPYR_' . substr(hash('sha256', $siteKey . '|' . $siteRoot), 0, 12))
  // cookie: path = $basePath ?: '/', httponly, samesite=Lax, secure when HTTPS
Session::csrfToken(): string          // create-once per session
Session::checkCsrf(?string $t): bool  // hash_equals
Session::requireSameOrigin(): void    // Origin host (no port) must match HTTP_HOST host (no port), else 403
Session::isAdmin(): bool              // reads $_SESSION['papyr_admin']
```

- **Per-site session name + base-path cookie** fixes problem 2 in both directions
  (subfolder installs and same-host dev).
- Host comparisons everywhere strip the port (`parse_url(..., PHP_URL_HOST)` on both
  sides). Apply the same helper in `login.php` (fixes the redirect bug).
- `MemberAuth::csrfToken()` / `checkCsrf()` become thin wrappers over `Session` (keep the
  method names one release for compatibility).
- **Every gated endpoint** (`save-theme.php`, `ai.php`, `unlock.php`, `comments.php`,
  `login.php`, dashboard POSTs, and Phase E's `pages.php`) boots through the same helper,
  then checks role + CSRF + same-origin as appropriate. `save-theme.php` gains CSRF.

### 3.2 Split `nav.php` into boot and render

`nav.php` stays the hand-authored site spine, but becomes two halves:

- **Top half (boot):** engine requires, `Session::start()`, roles, page key, theme
  lookup, tree, `papyr()` context (Phase C). **Prints nothing.** Safe under `require_once`.
- **Bottom half (render):** wrapped in `function papyr_nav(): void { … }` — the drawer,
  sidebar, theme panel, comments partial include, `window.PAPYR` (Phase C).
- **Backward compatible:** the last line of `nav.php` is
  `if (!defined('PAPYR_DEFER_NAV')) { papyr_nav(); }`
  so every existing page keeps working unchanged.

New / updated pages boot before output:

```php
<?php declare(strict_types=1);
define('PAPYR_DEFER_NAV', true);
for ($_d = __DIR__; !file_exists($_d . '/nav.php') && dirname($_d) !== $_d; $_d = dirname($_d));
require_once $_d . '/nav.php';
unset($_d);
?>
<!DOCTYPE html>
…
<body>
<?php papyr_nav(); ?>
```

Migrate the demo site's pages and SAL's three pages in this phase. Remove the `@` and the
incorrect comment. If `papyr_nav()` runs while the session could not start (headers
already sent), `error_log()` one clear warning naming the page — never a visible error.

---

## 4. Phase B — Site-owned `_config/`

### 4.1 Layout

```
somesite/
├── _config/                  ← SITE-OWNED, survives engine upgrades
│   ├── .htaccess             ← "Require all denied" (created by the engine if missing)
│   ├── sites.json            ← was _papyr/config/sites.json
│   ├── secret.php            ← was _papyr/config/secret.php   (git-ignored)
│   └── invite.php            ← was _papyr/config/invite.php   (git-ignored)
├── _papyr/                   ← ENGINE, replaceable as a whole folder
│   └── config/
│       └── sites.json.example
└── …
```

- The `_` prefix means the Scanner already ignores it and `PageWriter::guardPath()`
  already refuses to write into it — no new rules needed.
- `.htaccess` deny is **required**, not optional: `sites.json` is plain JSON. Document the
  nginx equivalent in the README (`location ^~ /_config/ { deny all; }`, same for
  `/data/comments/`).

### 4.2 Resolution & migration

- `bootstrap.php` looks for `{siteRoot}/_config/` first; if absent, falls back to
  `_papyr/config/` and `error_log()`s a one-time deprecation notice. No site breaks on
  upgrade day.
- `AdminAuth::secretFilePath()`, `MemberAuth::secretFilePath()` and both CLI tools
  (`set-admin-pass.php`, `set-invite-pass.php`) resolve through the same lookup and
  **write** to `_config/`.
- New CLI: `php _papyr/bin/migrate-config.php` — moves the three files, creates the deny
  `.htaccess`, prints what it did. Idempotent.
- `.gitignore`: `/_config/secret.php`, `/_config/invite.php`.
- Per-site secrets: each site hashes its own admin password. Chuck may still choose the
  same password on several sites; the ADR recommends distinct ones via a password manager
  so one leak stays one site.

### 4.3 Upgrade path (goes in `UPGRADING.md`)

1. Back up the site folder.
2. First time only: `php _papyr/bin/migrate-config.php`.
3. Replace `_papyr/` with the new release.
4. `composer install` in `_papyr/` (or upload the release's `vendor/`); then
   `composer dump-autoload -o`.
5. Open the site, sign in, confirm the theme panel's Commit button shows.

Add `CHANGELOG.md` and an engine version constant `Papyr\Core\Version::ENGINE`
(start at `0.2.0` with this ADR).

---

## 5. Phase C — One read-only context: `papyr()`

### 5.1 Server side

Built once during boot. Read-only (no setters; arrays returned by value).

```php
$p = papyr();
$p->site;    // key, label, basePath, url, engineVersion
$p->page;    // key, path, title, draft, cmsManaged, modified, theme (values + source: 'page'|'default')
$p->tree;    // pages (flat) + nested tree; drafts included only when role = admin
$p->user;    // role: 'visitor'|'member'|'admin', displayName, csrf (member/admin only, else '')
$p->theme;   // tokens, defaults, swatches
$p->ai;      // ['local' => bool, 'cloud' => bool]   — verdicts only
$p->url(string $relPath): string                       // base-path-safe link
$p->asset(string $plugin, string $file): string        // plugin asset URL
$p->provide(string $hook, callable $fn): void          // register a provider (Phase D)
$p->providers(string $hook, array $args = []): array   // collect provider output
```

**Never exposed:** secrets or hashes, filesystem paths, AI hosts/ports/models, raw
session contents, any write capability. Writes stay behind endpoints (endpoint URLs are
in `window.PAPYR.api`; the CSRF token is in `$p->user`).

Existing `$_papyr*` globals remain as aliases populated from the context for **one minor
version** (0.2.x), marked deprecated in CLAUDE.md. The comments partial and theme panel
switch to `papyr()` in this phase.

### 5.2 Browser side

`papyr_nav()` emits one block, once per page:

```js
window.PAPYR = {
  basePath, pageKey, role,               // role: 'visitor' | 'member' | 'admin'
  csrf,                                  // '' for visitors
  api: { theme, unlock, comments, pages }
};
```

The theme panel, comments script and every plugin read from `window.PAPYR` instead of
building their own config objects.

---

## 6. Phase D — Plugin loader, the map as the first plugin, node providers

### 6.1 Locations

| Path | Owner | Survives engine upgrade |
|---|---|---|
| `_papyr/plugins/{name}/` | core plugins shipped with the engine | replaced with the engine (by design) |
| `{siteRoot}/_plugins/{name}/` | the site's own / third-party plugins | yes |

Both are `_`-prefixed: Scanner-invisible and PageWriter-protected for free. Site plugins
win on name collision (lets a site override a core plugin deliberately).

### 6.2 Manifest

`plugin.json`:

```json
{ "name": "site-map", "version": "1.0.0", "requires": { "papyr": ">=0.2.0" },
  "entry": "plugin.php", "description": "Live 3D map of the site's pages." }
```

`name` must match `^[a-z0-9-]{1,40}$` and the folder name. The loader refuses (logs, renders
nothing) on a version mismatch, bad name, or missing entry.

### 6.3 Opt-in by adoption

```php
<?php papyr_plugin('site-map'); ?>              // render where called
<?php papyr_plugin('site-map', ['height' => '80vh']); ?>
```

Calling it **is** the opt-in — the same principle as theming. No registry file, no
enable flag. A site with no `map.php` never loads Three.js.

Plugins may also be **boot-only** (register providers, render nothing): a site lists them
in `_config/sites.json` → `"plugins": ["sal-blocks"]`, loaded during boot. This is the one
config-driven case, because providers must exist before any page renders.

### 6.4 Rules for plugins (v1)

- Read through `papyr()`; present through their own partials/assets.
- **No plugin-owned write endpoints in v1.** Anything that changes disk goes through an
  engine endpoint (`theme`, `comments`, `pages`). Revisit only with a real need.
- Assets live in the plugin folder and are linked with `$p->asset()`.
- Output escaped; no inline secrets; no `global`.

### 6.5 Move the map

`_papyr/plugins/site-map/` receives `site-map.php` (partial → `plugin.php` render),
`site-map.js`, `three/`. `MapTree` stays in core (it is a pure tree transform other
plugins may use). `map.php` becomes:

```php
<?php papyr_plugin('site-map'); ?>
```

**Acceptance:** the map behaves identically before and after the move (§8).

### 6.6 Node providers — hook `map.nodes`

A provider returns extra nodes to attach to the page tree. Node shape (shared with the
3D Mind Map JSON so the two stay one format):

```json
{
  "id": "research:acoustic-larvicide",
  "parent": "research.php",
  "text": "Acoustic larvicide",
  "status": "proven",
  "color": null,
  "model": { "src": "data/media/models/acoustic-transducer.glb", "scale": 1, "rotation": [0,0,0] },
  "link": "research.php#acoustic-larvicide",
  "visibility": "public"
}
```

- `parent` is a page path from the tree or another node's `id`.
- `status` maps to fixed colors (proven / caution / disproven / open) — same meaning as
  SAL's badges, never re-themed.
- `model` is optional; the sphere is the fallback and stays as the invisible hit target
  when a model loads. (Model loading itself is ADR-003; v1 may ignore `model`.)
- `visibility: "private"` nodes are dropped server-side unless role = admin.

### 6.7 Worked example — SAL

`mosquito.mid-awe.com/_plugins/sal-blocks/plugin.php` (boot-only):

- Reads `data/research/*.json` and `data/designs/*.json`; skips `"hidden": true`.
- Emits one node per block under `research.php` / `designs.php`, `status` from the block,
  `link` to the block's anchor.
- For design blocks, the first `files[]` entry ending in `.glb` becomes `model`.
- `_config/sites.json` → `"plugins": ["sal-blocks"]`; `map.php` → `papyr_plugin('site-map')`.

Result: SAL's map shows the research as a colored status board and the designs with
their parts.

---

## 7. Phase E — Page writes from the map (ADR-001 §7, step 2)

### 7.1 `_papyr/api/pages.php`

POST JSON, admin only, CSRF + same-origin via `Session`.

| action | Args | Effect | Offered for |
|---|---|---|---|
| `create` | `dir`, `slug`, `title`, `draft` | `PageWriter::write()` from the site template; `cms_managed: true` | any folder node ("New page here") |
| `promote` | `path` | removes the `<!-- papyr:draft -->` line only | drafts |
| `demote` | `path` | inserts the draft marker after `<body>` (or first line) only | any page |
| `trash` | `path` | moves file to `{siteRoot}/_trash/{YYYYmmdd-His}_{path-with-underscores}` | **cms_managed pages only** |

- `cms_managed` decides what the card **offers** (flag, not lock — CLAUDE.md principle 3):
  hand-made pages get promote/demote only; Papyr-made pages also get trash.
- **Trash is site-owned** (`_trash/`, not `_papyr/trash/`) so an engine upgrade never
  deletes it. `PageWriter::trash()` is its own engine method; `guardPath()` is **not**
  loosened.
- Every response rescans and returns the updated node so the map updates without reload.
- Restore from trash is manual for v1 (move the file back); documented in the README.

### 7.2 Map card

The card's action row (admin only, via `window.PAPYR.role`) calls `pages.php`. Visitors
see only title, Open page, and parent/child links; file metadata (modified time, origin,
theme source) is shown to admin only.

---

## 8. Acceptance checks (run after every phase)

- [ ] Home, about, docs/overview and `map.php` render on papyr-cms dev.
- [ ] SAL's three pages render; comments still unlock, post and delete.
- [ ] With `php -d output_buffering=0 -S …` (built-in server, no buffering): admin login
      sticks, Commit button shows, a comment posts. **(Phase A onward.)**
- [ ] Two installs on one host: signing in on one does **not** sign in on the other.
- [ ] Signed out: `view-source:` of `map.php` contains no draft paths and no private nodes.
- [ ] `/_config/sites.json` and `/data/comments/*.json` return 403 in the browser.
- [ ] Deleting `_papyr/cache/` breaks nothing.
- [ ] Removing `_papyr/` (temporarily) leaves pages rendering as plain PHP — the
      `file_exists` guard on the nav include holds.
- [ ] `composer dump-autoload -o` run; no class loads through the fallback.

---

## 9. Explicitly NOT in this ADR

- **ADR-003 candidates:** media upload endpoint (`data/media/`), GLB `model` loading in
  the map (Draco + load-when-near), theme cascade with background-effect tokens,
  AI node expansion through `AIBridge` (JSON output), mind maps as a stored content type
  (`data/mindmaps/`, library view, `link` between maps), private-map storage.
- Multi-site from one engine install.
- Plugin-owned write endpoints.
- TokenRegistry scope fix (still open from CLAUDE.md; independent).

---

## 10. Housekeeping riding along (small, do whenever convenient)

- Map labels: strip a prefix shared by every title (e.g. "PapyrCMS- "), or prefer `<h1>` /
  `<!-- papyr:title -->` for map labels only.
- Map camera FOV 75° → ~45–50° to stop edge stretching.
- GitHub ribbon → `_partials/github-ribbon.html`, included from the page (Scanner-invisible).
- Merge `assets/images/` into `assets/img/`.
- Rotate the admin password on papyrcms.mid-awe.com and remove the `.htaccess` `SetEnv`
  line (CLAUDE.md "Next Steps" #1).
- Update CLAUDE.md: Session, `_config/`, `papyr()`, plugins, deprecated globals, endpoint
  gate table (add `pages.php`, `unlock.php`, `comments.php`).

---

## 11. Philosophy check (must remain true after this ADR)

- [ ] The filesystem is the source of truth; providers read files, the manifest stays a cache.
- [ ] Manual edits are always safe — including hand-edited `_config/sites.json` and plugin JSON.
- [ ] `cms_managed` changes what is offered, never what is allowed by hand.
- [ ] The CMS works with no AI and with no plugins.
- [ ] `_papyr/` is replaceable as a whole folder; nothing a site owns lives inside it.
- [ ] Every write is behind a server-side gate: role + CSRF + same-origin.
- [ ] No build step, no Node runtime, no CDN dependency for core features.
