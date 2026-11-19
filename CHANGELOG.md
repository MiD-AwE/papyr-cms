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

### Changed
- **Logout is a POST** with CSRF + same-origin (theme panel, dashboard, `login.php`). `GET login.php?action=logout` no longer logs anyone out.
- `core/AIBridge.php` is registered in Composer's classmap: it declares several classes, which PSR-4 cannot map.

## 0.1.x
See git history.
