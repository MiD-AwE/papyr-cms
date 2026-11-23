# Upgrading Papyr

1. Back up the site folder.
2. First time only (0.1.x → 0.2.0): `php _papyr/bin/migrate-config.php` — moves `sites.json`,
   `secret.php`, `invite.php` from `_papyr/config/` to `_config/` and writes `_config/.htaccess`.
   Use `--dry-run` to preview. Safe to re-run.
3. Replace `_papyr/` with the new release.
4. In `_papyr/`: `composer install`, then `composer dump-autoload -o`.
5. Open the site, sign in, confirm the theme panel's Commit button shows.

## Web server permissions

Secret files are `0640`, group = the web server's group (XAMPP: `daemon`; Debian/Ubuntu: `www-data`).
If login says the secret file is *unreadable*: `sudo chgrp <web-group> _config/secret.php`.
Apache: the engine writes the deny rules itself, never overwriting an existing `.htaccess`.
`_config/`, `data/comments/` and `_trash/` deny everything; `_papyr/templates/` (shipped) and
`_templates/` (written the first time a page boots after you create it) deny `*.twig` sources.
Nginx ignores `.htaccess`; add the equivalents yourself:

```nginx
location ^~ /_config/       { deny all; }
location ^~ /data/comments/ { deny all; }
location ^~ /_trash/        { deny all; }
location ~ \.twig$          { deny all; }
location ~ ^/(_papyr/plugins|_plugins)/.*\.(php|phtml|json)$ { deny all; }
```

`data/themes/`, `data/comments/` and `_papyr/cache/` must be writable by daemon. It’s the same lesson as the secret files, and the next install shouldn’t have to rediscover it.
