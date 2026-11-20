# Changelog

## 0.2.0 — unreleased

### Added
- **Site-owned `_config/`** (ADR-002 Phase B): `sites.json`, `secret.php` and `invite.php` live in
  `{siteRoot}/_config/`, so replacing `_papyr/` never wipes site config or passwords. Legacy
  `_papyr/config/` is still read (per file) with a one-time log notice.
- `php _papyr/bin/migrate-config.php [--dry-run]` — idempotent move to `_config/`, writes the deny `.htaccess`.
- `Papyr\Core\SiteConfig` (config/secret path resolution and secret writes), `Papyr\Core\Version::ENGINE`.
- Secret files are written `0640` with the web server's group (XAMPP: `daemon`); `--group=NAME`,
  `PAPYR_WEB_GROUP` or `cms.web_group` override detection. The CLI warns, with the exact `chgrp` command,
  when it is not allowed to set the group.
- Login page says whether the admin secret file is **missing**, **unreadable** or **invalid**.
- `_papyr/config/sites.json.example`.
- **`papyr()` request context** (ADR-002 Phase C): one read-only `Papyr\Core\Context` with
  `site`, `page`, `tree`, `user`, `theme`, `ai` groups; works from any scope. Drafts only for admin;
  no secrets or filesystem paths.
- **`window.PAPYR`** — `{basePath, pageKey, role, csrf, api}`, emitted once per page; the theme panel
  and comments read it instead of building their own config.
- `AIBridge::configured()` — which channels have a provider, without network calls.
- `_papyr/tests/panel-test.php` — HTTP test of the panel, context and cookie path.
- **Plugins** (ADR-002 Phase D): `Papyr\Core\Plugins` loader with `plugin.json` manifests
  (name, `requires.papyr`, entry), `{siteRoot}/_plugins/` overriding `_papyr/plugins/`,
  `papyr_plugin('name', $options)` to render, `sites.json` → `"plugins"` for boot-only plugins.
- Context hooks: `asset()`, `provide()`, `providers()`, plus `pageTheme()`.
- **`map.nodes`** provider hook: `MapTree::attach()` puts plugin-supplied nodes on the site map with
  fixed status colours; private nodes reach admin only.
- `_papyr/tests/plugins-test.php` (shared harness in `tests/lib.php`).

### Changed
- **Logout is a POST** with CSRF + same-origin (theme panel, dashboard, `login.php`). `GET login.php?action=logout` no longer logs anyone out.
- Theme panel: the Access section always renders; signed-out visitors always get "Site admin? Log in".
- PHP 8.1+ (Twig 3 already required it).

- **The 3D site map is a core plugin**: `_papyr/plugins/site-map/` (was `templates/partials/site-map.php`
  + `assets/js` + `assets/three`). `map.php` is now `papyr_plugin('site-map')`. Same map, same data.
- Plugin entries refuse direct HTTP requests (404) and plugin folders ship an `.htaccess` denying `.php`/`.json`.
- `about.php` renders without `_papyr/`.

### Deprecated
- `$_papyr*` globals and Context's flat properties (`basePath`, `label`, `pageKey`, `activePath`,
  `role`, `csrf`, `inviteReady`) — use `papyr()`. Removed in 0.3.

### Fixed
- Theme panel and comments showed an admin as signed out when nav.php was required inside a function.
- `core/AIBridge.php` is registered in Composer's classmap: it declares several classes, which PSR-4 cannot map.

## 0.1.x
See git history.
