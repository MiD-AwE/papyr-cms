# Papyr CMS — Project Memory for Claude Code

## What This Project Is

Papyr is a paper-thin flat-file CMS built in PHP. It drops into a site that
already exists as standard PHP/HTML files on disk. The CMS **never owns the
files** — it reads what is there, writes when asked, and survives manual
edits gracefully.

This is a solo project by Chuck (MiD-AWE). It is intended to eventually become
an open-source GitHub project. Code should be clean enough for a public audience
while remaining practical for a single developer.

---

## Core Philosophy (Read Before Writing Any Code)

1. **The filesystem is always the source of truth.** The manifest is a cache.
   Never write logic that would break if `_papyr/cache/manifest.json` were deleted.

2. **Manual edits are always safe.** If a user edits a file outside the CMS,
   the CMS notices on next scan and updates gracefully. No conflicts, no errors,
   no "out of sync" states.

3. **cms_managed is a flag, not a lock.** A file marked `cms_managed: true` was
   created through the Add Page flow. It can still be edited by hand. The flag
   only affects what the CMS *offers* to do with the file, not what it *allows*.

4. **AI is optional, never required.** The CMS must work fully without any AI
   provider configured. All AI calls go through AIBridge. Never call Ollama or
   Cerebras directly from UI or page logic.

5. **Two AI channels, two purposes.**
   - **Local (Ollama / gemma3, or the hermes openai_compat channel)** — file-touching
     tasks, diff analysis, content review. Private. Never send file contents to the
     cloud channel.
   - **Cloud (Cerebras)** — generative outreach, social media drafts, meta
     descriptions. Fast. Never send raw source code or PHP/HTML markup to the
     cloud channel — `api/ai.php` strips tags before sending cloud tasks.

6. **PHP because it's everywhere.** No build step. No Node runtime. Runs on
   any shared host. Keep dependencies minimal and Composer-managed.

7. **The engine is sealed, and the boundary is real.** Everything under
   `_papyr/` is the CMS itself — sealed off from the managed site by the
   `_`-prefix convention (Scanner ignores any `_`-prefixed directory, and
   PageWriter's `guardPath()` refuses to write into one). This is a single
   boundary enforced on both the read side and the write side, not two
   different rules that could drift apart.

---

## Project Structure

The engine (`_papyr/`) is a single sealed directory that drops in next to a
site's existing files. The site root is whatever directory `_papyr/` sits in.

```
papyr-cms/                     ← site root (this repo doubles as the demo site)
├── CLAUDE.md                  ← You are here
├── nav.php                    ← site spine: wires the engine, renders nav + theme panel
├── index.php                  ← site homepage (a normal managed-ish content page)
├── docs/                      ← managed content pages (e.g. docs/overview.php)
├── data/
│   ├── themes/                ← {pageKey}.json — saved theme overrides, one per page
│   └── swatches/               ← preset HSL triplet "savestates" for the theme panel
├── assets/                    ← GSAP etc. (ignored by Scanner)
├── map.php                    ← 3D site map page: papyr_plugin('site-map')
├── _config/                   ← SITE-OWNED: sites.json, secret.php, invite.php (+ deny .htaccess)
├── _plugins/                  ← SITE-OWNED plugins (optional; wins over a core plugin of the same name)
│
└── _papyr/                    ← the engine — sealed, self-contained
    ├── bootstrap.php          ← loads sites.json, instantiates $scanner / $ai
    ├── index.php              ← CMS admin dashboard (gated — see Auth below)
    ├── login.php              ← admin login/logout (session-based)
    ├── composer.json          ← Twig; PSR-4: Papyr\Core\ → core/
    │
    ├── api/
    │   ├── ai.php             ← JSON proxy to AIBridge (gated)
    │   ├── save-theme.php     ← POST: writes data/themes/{pageKey}.json (gated)
    │   └── load-theme.php     ← papyr_load_theme() / papyr_theme_css() helpers
    │
    ├── cache/
    │   └── manifest.json      ← regenerable. Deleting is always safe.
    │
    ├── config/
    │   └── sites.json.example ← shape of _config/sites.json (the live file is site-owned)
    │
    ├── plugins/               ← core plugins (replaced with the engine); .htaccess denies .php/.json
    │   └── site-map/          ← plugin.json, plugin.php, site-map.js, three/ (Three.js r128)
    │
    ├── tests/                 ← php _papyr/tests/<name>-test.php (lib.php = shared harness; see Tests below)
    │
    ├── core/
    │   ├── Scanner.php        ← filesystem walker, manifest builder, draft detection
    │   ├── NavBuilder.php     ← read-only nested tree from manifest pages
    │   ├── AIBridge.php       ← provider-agnostic AI routing
    │   ├── PageWriter.php     ← Add Page → Twig render → write file; guardPath()
    │   ├── TemplateEngine.php ← Twig wrapper; extractVarNames()
    │   ├── TokenRegistry.php  ← theme token bound/unbound/missing reconciliation
    │   ├── UrlResolver.php    ← request-derived URL base path (no config, no hardcoding)
    │   ├── Context.php        ← papyr(): the read-only request context
    │   ├── Plugins.php        ← plugin discovery, manifest validation, boot/render runs
    │   └── MapTree.php        ← nav tree → map graph; attach() for map.nodes providers
    │
    ├── templates/
    │   ├── cms/
    │   │   └── tokens.css     ← canonical theme token map (component + composed)
    │   ├── partials/
    │   │   └── theme-panel.php ← HSL slider panel, dynamic token discovery
    │   └── sites/
    │       ├── papyr-cms/     ← active template (page.html.twig) for this single-site install
    │       ├── mid-awe/       ← page.html.twig for the mid-awe site variant
    │       ├── n01z/          ← legacy per-site template — NOT wired into current routing
    │       └── vbm/           ← legacy per-site template — NOT wired into current routing
    │
    └── vendor/                ← Composer (Twig, Symfony polyfills)
```

**Single-site model.** `config/sites.json` currently defines one managed site
(root `".."`, resolving to the repo root). The original multi-site design
(n01z.mid-awe.com, vbm.mid-awe.com as separately managed sites) is still the
long-term goal but is not the current shape — their templates remain under
`_papyr/templates/sites/` as legacy/reference material, not part of the live
render path. Don't assume multi-site behavior exists until sites.json actually
lists more than one entry.

---

## Existing Files — What They Do

### `_papyr/core/Scanner.php`
- Namespace: `Papyr\Core`. Constructor: `__construct(array $sites, string $manifestPath, int $scanTtlSeconds)`
- `scan(): array` — full scan, diff report (new/modified/deleted)
- `getPages(string $siteKey, bool $forceScan = false): array`
- `markManaged(string $siteKey, string $relPath, array $meta = []): void`
- Title extraction priority: `<title>` → `<h1>` → `<!-- papyr:title: ... -->` → humanized filename
- Draft detection: `<!-- papyr:draft -->` in first 4KB → `draft: true` in manifest; excluded from nav, still servable
- Reads only first 4KB of each file (performance)
- **Any `_`-prefixed directory is ignored automatically** — this is how the engine stays invisible to its own scanner without a hand-maintained ignore list
- `isScanStale()` — real TTL comparison against `scan_ttl_seconds`

### `_papyr/core/NavBuilder.php`
- `NavBuilder::tree(array $pages): array` — builds a nested tree from flat manifest pages
- Excludes drafts, sorts keys, nests by `/`-separated path segments
- Read-only: does not build hrefs itself — callers (`nav.php`) combine `node['path']` with their own resolved base path
- `tree($pages, includeDrafts: true)` keeps drafts (with a `draft` flag on each page node). Opt-in only — the nav never passes it; the admin's 3D site map does.

### Plugins (ADR-002 Phase D) — `_papyr/core/Plugins.php`, `papyr_plugin()`
- Locations: `{siteRoot}/_plugins/{name}/` (site-owned, wins) then `_papyr/plugins/{name}/` (core). Each has `plugin.json` `{name, version, requires:{papyr}, entry, description}`; refused (logged, renders nothing) on bad name (`^[a-z0-9-]{1,40}$`), name ≠ folder, `requires.papyr` not met by `Version::ENGINE`, broken JSON, or an entry missing / outside the folder. A broken site copy does **not** fall back to the core copy.
- **Render:** `papyr_plugin('name', $options)` (defined in nav.php — a no-op when the engine is missing). **Boot-only:** `sites.json` → `"plugins": [...]` (top level or inside the site entry) runs the entry during boot; anything it prints is discarded and logged.
- Entry scope is exactly `$p` (Context), `$options`, `$plugin` (`name`, `version`, `mode`). Every entry starts with the guard `if (!isset($p) || !$p instanceof \Papyr\Core\Context) { http_response_code(404); return; }`, and plugin folders carry an `.htaccess` denying `.php`/`.json` (assets still served).
- Hooks: `$p->provide($hook, fn(Context $p, array $args))`, `$p->providers($hook, $args)` → results in registration order; a throwing provider is logged and skipped. `$p->asset($plugin, $file)` → URL in whichever folder won. `$p->pageTheme($relPath)` → `{values, source, saved}` so plugins never need filesystem paths.
- Rules (§6.4): read through `papyr()`, present through own assets, no write endpoints, no `global`.

### `_papyr/core/MapTree.php` + the `site-map` core plugin (`_papyr/plugins/site-map/`)
- Read-only 3D site map (step 1 of the 3D Mind Map → Papyr plan, 2026-11-18; a plugin since Phase D). `map.php` at the site root is a thin page: boots `nav.php`, then `papyr_plugin('site-map')`.
- `MapTree::attach($root, $nodes, $admin)` adds `map.nodes` provider nodes (`kind: node`): `{id, parent (page path or node id; chains allowed), text, status, color, model, link, visibility}`. Status → fixed colours (`MapTree::STATUS_COLORS`: proven/caution/disproven/open, unknown → open, never re-themed). Private nodes dropped unless admin; links site-relative only; duplicates/orphans dropped and logged; `model` carried for ADR-003 (sphere drawn).
- `MapTree::fromNav($tree, $rootLabel, $themeFor)` reshapes a NavBuilder tree into one rooted graph (`{id, text, kind: root|dir|page, path, color, themed, draft, …, children}`). Index pages fold into their container (top-level `index.php` → root node, `docs/index.php` → Docs folder node). Theme lookup is injected, so core never reads theme files itself.
- Node colour = the accent that page actually renders with (its saved theme, else `papyr_theme_defaults()` from load-theme.php). Drafts appear only for admin (they come from `papyr()->tree`).
- Renderer uses vendored Three.js r128 (UMD, global `THREE`) + classic `OrbitControls` in `_papyr/plugins/site-map/three/` — no CDN, no build step. `map.php#node=<relPath>` deep-links a selection.

### `_papyr/core/UrlResolver.php`
- `UrlResolver::basePath(string $siteRoot): string` — the URL path prefix the site is currently served under, resolved **purely from the request environment** (diffs `SCRIPT_NAME` against `SCRIPT_FILENAME`'s path relative to the site root)
- Never reads config. Same code returns `/papyr-cms` on a local subfolder install and `""` on a production vhost rooted at the site — no environment-specific edits needed anywhere
- `nav.php` computes this once per request into `$_papyrBasePath`; every other link-building surface (theme-panel.php's save-theme URL, asset `<script>` tags) reuses that one value instead of re-deriving it

### `_papyr/core/AIBridge.php`
- Interface: `AIProvider` — `complete()`, `label()`, `ping()`
- Providers: `OllamaProvider`, `CerebrasProvider`, `OpenAICompatProvider` (used for the `hermes` channel), `NullProvider`
- `AIBridge::run(string $task, string $prompt, array $options = []): AIResult`, plus convenience methods `draftSocialPost()`, `suggestMeta()`
- `AIResult` — `success`, `text`, `provider`, `channel`, `error`
- `status(): array` — `['local' => ['provider'=>..., 'online'=>bool], 'cloud' => [...]]` — UI reads only the `online` key, never prints hosts/ports (public health block shows verdicts only)

### `_papyr/core/PageWriter.php` / `TemplateEngine.php`
- `TemplateEngine::render(string $template, array $vars)`, `extractVarNames()` (strips Twig comments before scanning)
- `TemplateEngine::normalize()` (private) — self-corrects a template name whose leading segments duplicate the loader root (e.g. a config `template` value accidentally authored as `_papyr/templates/sites/...` against a loader already rooted at `_papyr/templates`). Fixed 2026-07-09 after this exact doubling broke Add Page; derives the overlap from the loader's actual configured directories, no hardcoded path segments — same principle as `UrlResolver`.
- `PageWriter::create($relPath, $vars, $template)` — the web path (Add Page, map card): never overwrites (409, file opened with mode `x`); `<?` in every submitted value becomes `&lt;?`, and output holding more `<?` than the template renders with empty fields is refused (422). Raw PHP stays a manual-edit feature.
- `setDraft($relPath, bool)` — promote/demote by editing only the `<!-- papyr:draft -->` marker: after the real `<body>` (the opening PHP block is skipped), else `<head>`, else after that block, inside the first 4 KB (422 otherwise). Keeps the page's `manually_edited` flag as it was.
- `trash($relPath)` — moves to `{siteRoot}/_trash/{YYYYmmdd-His}_{path_with_underscores}` (deny rule via `trashDir()`); restore by hand. There is no delete.
- `write($relPath, $vars, ?$template)` — internal/tests only (overwrites). `getTemplateVarNames()` is legacy, unused.
- Every failure goes through `fail()`: the message names site-relative paths only (safe to show), the absolute path is logged; codes `ERR_EXISTS` 409, `ERR_NOT_FOUND` 404, `ERR_REFUSED` 422.
- `guardPath()` blocks `..`, absolute paths, null bytes, **and any path segment starting with `_`** — the write-side half of the same boundary Scanner enforces on the read side

### Page writes (ADR-002 Phase E2)
- `_papyr/api/pages.php` — POST JSON `{action, csrf, …}`: `create` (`dir`, `slug`, `layout` = a starter, `fields`, `draft`, optional `theme` written through `ThemeStore`), `promote`, `demote`, `trash`. Writes only through PageWriter; every reply carries `node` and the whole admin map graph (`root`) so the map merges it without a reload.
- Starters (`Papyr\Core\Starters`): `{site}/_templates/starters/{name}/` wins over `_papyr/templates/starters/{name}/`; `page.html.twig` + `starter.json` (fields: text, textarea, html, url; `title` required). `papyr()->starters()` gives the admin names/labels, never paths.
- Add Page (dashboard) renders the chosen starter's fields and posts to `pages.php`. The site-map card (admin) offers Publish / Make draft, Move to trash (Papyr-made pages, second click confirms) and New page here on folders; visitors get no file metadata (`mtime`, `cms_managed` dropped server-side).
- `_papyr/templates/partials/hsl-sliders.php` — the one HSL slider component (`window.PapyrHsl.build/parse`), hosted by the theme panel and Add Page. Typed colours: `149 57% 43%`, commas, `hsl(…)`, `#rrggbb`, `#rgb`.

### Tests
`php _papyr/tests/<name>-test.php` for panel, plugins, themes, templates, sections, starters, deny, comments, pages, hsl, addpage, mapcard. `deny` and `comments` run XAMPP's Apache unprivileged (`startApache()`); `hsl`, `addpage` and `mapcard` need headless Chromium (mapcard with SwiftShader WebGL). Each prints SKIP and exits 0 when its tool is missing.

### `_papyr/core/AdminAuth.php`
- `AdminAuth::verify(string $submitted): bool` — the one converged check both `login.php` and (indirectly, via session) every gated endpoint rely on
- `AdminAuth::source(): ?string` — `'env'`, `'config'`, or `null`, used to show a setup message without revealing which store was checked
- `AdminAuth::writeSecretFile(string $plaintext): void` — hashes and writes `_papyr/config/secret.php`, `chmod 0600`; called by the CLI reset tool, never from a web-reachable path
- See Auth Model below for the full resolution order

### `_papyr/core/TokenRegistry.php`
- `TokenRegistry::enumerate(string $tokenMapPath, array $templatePaths): array` — reconciles DEFINED (names parsed from one `:root{}` block in `$tokenMapPath`) against REFERENCED (`var(--*)` usage across `$templatePaths`)
- Three states: `bound` (both), `unbound` (defined, unused), `missing` (referenced, undefined)
- **Known scope gap (diagnosed 2026-07-09, not yet fixed):** DEFINED only ever reads `templates/cms/tokens.css`; it does not see tokens a partial defines in its own local `:root` (e.g. theme-panel.php's `--tp-track`). REFERENCED excludes `tokens.css` itself (extension filter is twig/php only), so the component triplets' real usage inside tokens.css's own composed-token formulas is invisible to the scanner — that's why they read `unbound` despite being genuinely used. There's also a stray dead path (`_papyr/nav.php`, which doesn't exist — the real `nav.php` is at the site root) baked into `_papyr/index.php`'s `$templatePaths` construction, so the site's actual nav.php is never scanned.

### `_papyr/bootstrap.php`
- Loads `config/sites.json`, resolves relative site roots via `realpath()`, instantiates `$scanner` and `$ai`, sets timezone
- No session handling here — auth is each endpoint's own concern (see below)

### `_config/sites.json` (ADR-002 Phase B — was `_papyr/config/`)
- Resolved by `SiteConfig::path()`: `_config/` first, legacy `_papyr/config/` per-file fallback. `bin/migrate-config.php` moves it. `SiteConfig::writeSecret()` is the only secret writer (0640 + web group).
- `cms` block: name, version, manifest_path, scan_ttl_seconds, timezone
- `ai` block: `local` (Ollama), `hermes` (openai_compat, port 7860), `cloud` (Cerebras) — API keys via `api_key_env` only, never hardcoded
- `sites` block: currently one entry (`mid_awe`), root `".."`, `url` used only for the admin dashboard's external "visit site" link — **not** used for internal link-building (that's `UrlResolver`'s job)

### `_papyr/core/Session.php` (ADR-002 Phase A)
- The **only** place that calls `session_start()`. `Session::start(?string $basePath)` — idempotent; session name `PAPYR_` + hash of the site root, cookie path = the site's base path, httponly, SameSite=Lax, Secure on HTTPS. Two installs on one host never share a session.
- Identity is the site root only (the ADR sketched `siteKey|siteRoot`): login.php and the JSON endpoints skip bootstrap.php, so the root is the one value every entry point derives identically without config.
- `isAdmin()`, `grantAdmin()` (regenerates id + rotates CSRF), `destroy()`; `csrfToken()` / `rotateCsrf()` / `checkCsrf()` (one token per session, key `papyr_csrf`); `sameOrigin()` / `requireSameOrigin()` (Origin, then Referer; hosts compared **without ports** via `hostOf()`).
- If output already started, `start()` refuses and records where (`failure()`); `papyr_nav()` then `error_log()`s one warning naming the page. `MemberAuth::csrfToken()`/`checkCsrf()` are deprecated wrappers (0.2.x).

### `nav.php` (site root)
- The site spine every content page includes. Two halves:
  - **Boot** (top level, prints nothing): engine requires, `Session::start()`, roles, `$_papyrBasePath` via `UrlResolver`, page key, theme, tree. Sets `$_papyrIsAdmin`, `$_papyrIsMember`, `$_papyrCsrf` — pages and partials read these rather than re-checking the session.
  - **Render** — `papyr_nav()`: emits `window.PAPYR`, then sidebar, drawer, theme panel, comments assets. Reads only `papyr()` — no `global`.
- **Request context (ADR-002 Phase C):** boot registers one read-only `Papyr\Core\Context`; `papyr()` returns it from any scope (it works even when nav.php is required inside a function, which globals did not).
  - Groups (§5.1): `site` (key, label, basePath, url, engineVersion, invites), `page` (key, path, title, draft, cmsManaged, modified, theme{values, source}), `tree` (pages, nested — drafts only for admin), `user` (role, displayName, csrf — `''` for visitors), `theme` (tokens, defaults, swatches), `ai` (local/cloud: a provider is *configured* — no pings on page render; the dashboard's `status()` pings). Methods: `isAdmin()`, `isMember()`, `url()`, `api()`, `browser()`.
  - Never holds secrets, hashes, filesystem paths, AI hosts/models, or raw session data. Partials needing a path derive it themselves (`__DIR__`, `Session::siteRoot()`).
  - `window.PAPYR` (§5.2) = `{basePath, pageKey, role, csrf, api:{theme, unlock, comments, pages}}`, frozen, emitted once by `papyr_nav()`. Theme panel, comments and the site-map card read it.
  - **Deprecated (0.2.x, remove in 0.3):** the `$_papyr*` globals (still set at file scope for old pages; nothing in the engine reads them) and Context's flat properties `basePath`, `label`, `pageKey`, `activePath`, `role`, `csrf`, `inviteReady`. New code uses `papyr()` groups.
- Test: `php _papyr/tests/panel-test.php` — throw-away subfolder site over HTTP; panel per role, `window.PAPYR`, context groups, drafts, read-only, cookie path.
- Pages boot before output: `define('PAPYR_DEFER_NAV', true);` + walk-up `require_once` above `<!DOCTYPE>`, then `<?php papyr_nav(); ?>` after `<body>`. Without the define, the last line of nav.php renders immediately (legacy pages keep working, but only get a session while PHP is still buffering output).
- Missing `_papyr/` → `error_log()` and return; `papyr_nav()` renders nothing (no fatal).

---

## Auth Model

**Two theming tiers** (this is the load-bearing design decision — see
`papyr-auth-adr-staging.md` and `papyr-swatch-unify-and-commit-gate.md` for
the full history):

- **User tier — ephemeral, open to anyone.** Opening the theme panel, moving
  sliders, clicking swatches. All `:root`-only, browser-session-scoped, no
  server write. Refresh restores the saved theme. Keep this fully open — the
  ability to fiddle without consequence is a feature.
- **Admin tier — persistent, gated.** Commit (writes `data/themes/{pageKey}.json`),
  Add Page, Delete, AI calls, admin dashboard access. Anything that writes to
  disk, spends AI credits, or changes what every visitor sees requires an
  authenticated admin session.

**Mechanism:** flat-file/session-based, no database — consistent with the
flat-file ethos. `_papyr/login.php` resolves the admin secret through
`Papyr\Core\AdminAuth::verify()` — one converged path regardless of source:
1. Env var `PAPYR_ADMIN_PASS` (checked via `getenv()`, `$_SERVER`, and `$_ENV`,
   first non-empty wins — shared hosts often populate only one of the three).
   Plaintext, compared with `hash_equals()`.
2. Config file `_config/secret.php` (site-owned, git-ignored, mode 640 + web-server group; holds a
   `password_hash()` output) — the portable fallback for hosts that don't
   reliably surface env vars to PHP at all (the original motivation: a
   shared cPanel/LiteSpeed host silently never set the env var, which nearly
   caused a production lockout). Compared with `password_verify()`.
3. Neither present — `verify()` fails closed; `login.php` shows a generic
   "not configured" message without revealing which store was checked.

Env var wins when both are present. `php _papyr/bin/set-admin-pass.php` is
the CLI lockout-recovery tool — writes `secret.php` over SSH without
touching the web server's env config. The login form carries a CSRF token
and is same-origin checked; on success `Session::grantAdmin()` regenerates
the id and sets `$_SESSION['papyr_admin'] = true`. Logout is a POST to
`login.php` (`action=logout` + CSRF, same-origin; GET does nothing) → `Session::destroy()`. `_papyr/config/secret.php.example` and
`.htaccess.example` are the committed templates; the real files are
git-ignored.

**The boundary is server-side, always.** Hiding a UI control (e.g. the theme
panel's Commit button, shown only when `papyr()->isAdmin()` in theme-panel.php)
is UX, not security. Every gated endpoint independently rejects unauthenticated
requests — a POST can always be sent without going through the UI.

**Gate status per endpoint:**
| Endpoint | Gated? |
|---|---|
| `_papyr/api/save-theme.php` | ✅ admin + CSRF + same-origin, 403 JSON |
| `_papyr/plugins/*/plugin.php`, `_plugins/*/plugin.php` | ✅ not web-runnable: entry guard (404) + folder `.htaccess` denying `.php`/`.json` (403) |
| `_papyr/templates/partials/theme-panel.php` Commit button | ✅ hidden unless `papyr()->isAdmin()` (UX layer only — the real gate is save-theme.php) |
| `_papyr/index.php` (dashboard; its only POST is the table's trash button) | ✅ admin (302 to login.php); POSTs also CSRF + same-origin |
| `_papyr/api/pages.php` (create / promote / demote / trash) | ✅ admin + POST only + CSRF + same-origin, 403/405 JSON; trash only for `cms_managed` pages (409) |
| `_papyr/api/ai.php` | ✅ admin + POST only + CSRF + same-origin, 403/405 JSON |
| `_papyr/api/unlock.php` | ✅ same-origin + per-IP throttle (no CSRF: the invite password is the proof) |
| `_papyr/api/comments.php` | ✅ member to read; writes add CSRF + same-origin; delete admin only |
| `_papyr/login.php` | ✅ CSRF + same-origin on the login POST |

---

## Coding Conventions

### PHP
- PHP 8.1+ (Twig 3 already requires it; `Context` uses `readonly`). Use typed properties, `match`, named arguments where they
  add clarity.
- Declare `strict_types=1` at the top of every file.
- Namespace everything under `Papyr\Core` (core classes). `Papyr\UI` is
  reserved in composer.json's PSR-4 map but no `ui/` directory exists yet —
  don't assume UI controller classes exist until they're actually built.
- Class names: PascalCase. Methods: camelCase. Constants: UPPER_SNAKE.
- No magic methods except `__construct`. Avoid `__get`/`__set`.
- Errors: throw `\RuntimeException` for hard failures. Log soft failures with
  `error_log()` and return gracefully. Never let a scan failure crash the CMS.
- No inline HTML in PHP classes (`core/`). Templates/partials/admin pages are
  the one place PHP+HTML mixing is expected in this codebase.
- `composer.json` has `optimize-autoloader: true` — a new class file under
  `Papyr\Core\` needs `composer dump-autoload -o` (run from `_papyr/`) before
  it's autoloadable, since the classmap is static, not scanned at runtime.

### File Operations
- Always use absolute paths. Derive them from `__DIR__` or the configured root.
- Create directories with `mkdir($path, 0755, true)` before writing.
- Read with `file_get_contents()`, write with `file_put_contents()`.
- For large files or first-N-bytes reads, use `fopen`/`fread`/`fclose`.

### JSON / Manifest
- Always `json_encode` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`.
- Always check `json_last_error()` after `json_decode`.
- Manifest schema: `{ last_scanned, sites: { [siteKey]: { root, label,
  last_scanned, pages: { [relPath]: { mtime, size, ext, filename, title,
  cms_managed, draft?, manually_edited? } } } } }`

### Twig
- Twig 3.x via Composer. Template files: `.html.twig`.
- CMS-internal templates live in `_papyr/templates/cms/`.
- Site page templates live in `_papyr/templates/sites/{siteKey}/`.
- Variables injected by PageWriter should match template block names exactly.

### URL / base-path building
- **Never hardcode a path prefix, and never derive it from `sites.json`'s
  `url` field.** That field is for the admin dashboard's external "visit
  site" link only. Any internal link (nav, assets, API calls) must go through
  `UrlResolver::basePath()` (or reuse `$_papyrBasePath` if already in scope
  from `nav.php`'s include chain) so the same code works unmodified on
  localhost and production.

### AI Prompts
- Keep prompts in the method that uses them (AIBridge convenience methods, or
  PageWriter). Do not store prompts in config files.
- Always instruct the model to return only the requested content — no preamble,
  no explanation, no markdown fences — unless structured output is needed.
- For structured output, instruct the model to return valid JSON only, then
  parse with `json_decode`.

---

## Environment

- **OS:** Omarchy (Arch-based), full-time since late 2026 (was PopOS)
- **Local dev server:** XAMPP (`/opt/lampp`), served at `http://localhost/papyr-cms/` (port 80; the old `:88` was a PopOS-era workaround)
- **Production:** `papyrcms.mid-awe.com` — vhost rooted at the site (no path prefix)
- **Local AI:** Ollama at `http://localhost:11434`, model `gemma3:latest`; also a `hermes` openai_compat channel at `127.0.0.1:7860`
- **Cloud AI:** Cerebras API, key in env var `CEREBRAS_API_KEY`
- **Admin auth:** password in env var `PAPYR_ADMIN_PASS`
- **PHP:** 8.x, Composer available
- **Future:** GStarCAD Linux migration planned; cmplCAD (AutoLISP tool) will
  need Linux path adaptation — unrelated to Papyr but context for the developer

---

## What Is NOT Built Yet (Next Steps)

In priority order:

1. **Admin password rotation — pending, deliberately held.** The portable
   secret mechanism (`AdminAuth`, `secret.php` fallback, CLI reset tool) is
   built and verified, but the actual password has not been rotated yet —
   the current `PAPYR_ADMIN_PASS` value (exposed in cleartext in the
   now-git-ignored `.htaccess`) is still live on both localhost and
   production until Chuck says go. Once rotated, the remote box can drop
   the `.htaccess` `SetEnv` line entirely and rely on `secret.php`.
2. **Token Registry scope fix** — see the known gap noted under
   `TokenRegistry.php` above. Diagnosed 2026-07-09; fix is a separate pass
   (deliberately not bundled with diagnosis).
3. **Lost & found / uncategorized-page handling** — pages Scanner finds that
   NavBuilder can't cleanly place. Touches the read-only NavBuilder mandate,
   deferred from the June 25 design reconciliation.
4. **`templates/sites/n01z` and `templates/sites/vbm` cleanup** — legacy,
   not wired into current single-site routing. Either revive properly when
   multi-site returns, or delete.
5. **README / `sites.json.example`** for open-source release. `.gitignore`,
   `secret.php.example`, and `.htaccess.example` are already in place ahead
   of `git init`.

---

## Open Source Notes (For When This Goes Public)

- License: TBD (MIT likely)
- The `AIBridge` provider interface is the main extensibility point. Document it well.
- `config/sites.json` should ship as `sites.json.example` with no real paths.
- `_papyr/cache/` should be in `.gitignore`.
- README should lead with the philosophy: zero lock-in, manual edits always safe,
  AI optional, PHP everywhere.
- Target audience includes developers in low-resource hosting environments
  (West Africa, shared hosting) — keep the stack lean.
