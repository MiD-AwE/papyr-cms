<?php
declare(strict_types=1);

/*
 * PapyrCMS site spine — include at the top of each page's <body>:
 *
 *     <?php include __DIR__ . '/nav.php'; ?>  (from site root or subdirectory)
 *
 * The engine always lives at _papyr/ relative to this file's directory.
 */

$_papyrEngine = __DIR__ . '/_papyr';
if (!is_dir($_papyrEngine)) {
    die('[PapyrCMS] Engine not found at _papyr/. Is it installed?');
}

require_once $_papyrEngine . '/vendor/autoload.php';
require_once $_papyrEngine . '/bootstrap.php';   // $sites, $scanner, $config
require_once $_papyrEngine . '/api/load-theme.php';

// Admin session — read existing session regardless of whether headers were already sent.
// Pages that output HTML before including nav.php can't start a new session, but the
// existing session cookie from the browser is still readable via @session_start().
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_papyrIsAdmin = ($_SESSION['papyr_admin'] ?? false) === true;

use Papyr\Core\MemberAuth;
use Papyr\Core\NavBuilder;
use Papyr\Core\UrlResolver;

// Participant session — invite password unlocks comments (never admin rights).
// Admins count as participants. See Papyr\Core\MemberAuth.
$_papyrIsMember     = MemberAuth::isMember();
$_papyrInviteReady  = MemberAuth::configured();
$_papyrCsrf         = $_papyrIsMember ? MemberAuth::csrfToken() : '';

// Single managed site
$_papyrSiteKey  = (string) array_key_first($sites);
$_papyrSiteConf = $sites[$_papyrSiteKey] ?? [];
$_papyrSiteRoot = rtrim($_papyrSiteConf['root'] ?? __DIR__, '/');
$_papyrLabel    = $_papyrSiteConf['label'] ?? ($config['cms']['name'] ?? 'Papyr CMS');

// URL base path for nav links, assets, and API calls — resolved once from
// the request environment (SCRIPT_NAME vs. the site root's filesystem path),
// never from sites.json's "url" field. Same code yields "/papyr-cms" locally
// and "" on a production vhost rooted at the site.
$_papyrBasePath = UrlResolver::basePath($_papyrSiteRoot);
$_papyrUrlBase  = $_papyrBasePath . '/';

// Live page tree (Scanner respects TTL; won't re-scan within scan_ttl_seconds)
$_papyrPages = $scanner->getPages($_papyrSiteKey);
$_papyrTree  = NavBuilder::tree($_papyrPages);

// Server-inject saved theme into :root — eliminates flash of default colors
$_papyrPageFile = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
$_papyrTheme    = papyr_load_theme($_papyrPageFile, $_papyrSiteRoot);
$_papyrPageKey  = papyr_page_key($_papyrPageFile, $_papyrSiteRoot);   // same key themes and comments use

// Active path (relative to site root) for active-link highlighting
$_papyrActive = ltrim(
    str_replace($_papyrSiteRoot, '', realpath($_papyrPageFile) ?: ''),
    '/'
);

// Recursive renderer — outputs <li> elements into the <ul> below
function papyrNavTree(array $nodes, string $active, int $depth = 0, string $urlBase = '/'): void
{
    foreach ($nodes as $node) {
        if ($node['is_dir']) {
            echo '<li class="pn-dir">';
            echo '<span class="pn-dir-label">'
                . htmlspecialchars($node['title'], ENT_QUOTES, 'UTF-8')
                . '</span>';
            if (!empty($node['children'])) {
                echo '<ul>';
                papyrNavTree($node['children'], $active, $depth + 1, $urlBase);
                echo '</ul>';
            }
            echo '</li>';
        } else {
            $href  = $urlBase . htmlspecialchars($node['path'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars($node['title'], ENT_QUOTES, 'UTF-8');
            $cls   = ($node['path'] === $active) ? ' class="pn-active"' : '';
            echo "<li{$cls}><a href=\"{$href}\">{$label}</a></li>";
        }
    }
}
?>
<style>
/* Token defaults — canonical source (cascade: papyr_theme_css() overrides below) */
<?php echo file_get_contents($_papyrEngine . '/templates/cms/tokens.css'); ?>

/* Component overrides: saved theme or defaults from papyr_theme_css() */
:root {
  /* theme tokens — injected by papyr_theme_css() */
<?php echo papyr_theme_css($_papyrTheme); ?>
}

/* ==============================================================
   Nav sidebar
   ============================================================== */
#papyr-nav {
  position: fixed; top: 0; left: 0; bottom: 0; z-index: 100;
  width: 220px;
  background: var(--card-bg);
  border-right: 1px solid var(--border-bright);
  backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
  display: flex; flex-direction: column;
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
  overflow-y: auto;
  padding-bottom: 3.5rem;
}

.pn-brand {
  padding: 1.2rem 1rem 1rem;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.pn-brand a {
  color: var(--accent);
  text-decoration: none;
  font-size: .6rem;
  letter-spacing: .38em;
  text-transform: uppercase;
  font-weight: 700;
  text-shadow: 0 0 14px color-mix(in srgb, var(--accent) 45%, transparent);
  display: block;
}
.pn-brand a:hover { opacity: .8; }

.pn-tree, .pn-tree ul { list-style: none; margin: 0; padding: 0; }
.pn-tree { padding: .4rem 0; flex: 1; }

.pn-tree a {
  display: block;
  padding: .38rem .9rem .38rem 1rem;
  font-size: .68rem;
  letter-spacing: .05em;
  color: var(--text-dim);
  text-decoration: none;
  border-left: 2px solid transparent;
  transition: color .15s, border-color .15s, background .15s;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  animation: pn-slide-in .35s ease backwards;
}

/* Staggered CSS animation — works without GSAP */
.pn-tree > li:nth-child(1)   a { animation-delay: .03s; }
.pn-tree > li:nth-child(2)   a { animation-delay: .07s; }
.pn-tree > li:nth-child(3)   a { animation-delay: .11s; }
.pn-tree > li:nth-child(4)   a { animation-delay: .15s; }
.pn-tree > li:nth-child(5)   a { animation-delay: .19s; }
.pn-tree > li:nth-child(6)   a { animation-delay: .23s; }
.pn-tree > li:nth-child(7)   a { animation-delay: .27s; }
.pn-tree > li:nth-child(8)   a { animation-delay: .31s; }
.pn-tree > li:nth-child(n+9) a { animation-delay: .34s; }

@keyframes pn-slide-in {
  from { opacity: 0; transform: translateX(-8px); }
  to   { opacity: 1; transform: none; }
}

.pn-tree a:hover {
  color: var(--text);
  border-left-color: color-mix(in srgb, var(--accent) 45%, transparent);
  background: color-mix(in srgb, var(--accent) 4%, transparent);
}
.pn-tree li.pn-active > a {
  color: var(--accent);
  border-left-color: var(--accent);
  background: color-mix(in srgb, var(--accent) 6%, transparent);
  text-shadow: 0 0 8px color-mix(in srgb, var(--accent) 30%, transparent);
}

.pn-dir-label {
  display: block;
  padding: .65rem .9rem .18rem 1rem;
  font-size: .5rem;
  letter-spacing: .3em;
  text-transform: uppercase;
  color: var(--text-dim);
  opacity: .42;
  cursor: default;
  user-select: none;
}
.pn-dir > ul { padding-left: .7rem; }

/* Offset page content to the right of the sidebar */
body {
  background: var(--bg);
  color: var(--text);
  margin-left: 220px;
}

/* Drawer footer: settings / unlock entry point (shown in the mobile drawer) */
.pn-foot { display: none; }
.pn-foot button {
  width: 100%; text-align: left; font: inherit; font-size: .68rem; letter-spacing: .05em;
  color: var(--text-dim); background: none; border: 0; border-top: 1px solid var(--border);
  padding: .8rem 1rem; cursor: pointer;
}
.pn-foot button:hover { color: var(--accent); }

#pn-burger, #pn-scrim { display: none; }

/* ==============================================================
   Mobile: off-canvas drawer behind a hamburger
   ============================================================== */
@media (max-width: 640px) {
  body { margin-left: 0; }

  #pn-burger {
    display: grid; place-items: center;
    position: fixed; top: 10px; left: 10px; z-index: 600;
    width: 42px; height: 42px; border-radius: 10px;
    background: var(--card-bg); color: var(--accent);
    border: 1px solid var(--border-bright);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    font-size: 1.25rem; line-height: 1; cursor: pointer;
  }
  #papyr-nav {
    width: min(280px, 82vw); z-index: 550; padding-bottom: 0;
    transform: translateX(-100%); transition: transform .25s ease;
    padding-top: 52px; background: var(--bg-deep, var(--bg));
  }
  body.pn-open #papyr-nav { transform: none; box-shadow: 0 0 40px rgb(0 0 0 / .5); }
  body.pn-open #pn-scrim {
    display: block; position: fixed; inset: 0; z-index: 540; background: rgb(0 0 0 / .45);
  }
  .pn-tree a { font-size: .85rem; padding: .7rem 1rem; }
  .pn-foot { display: block; }

  /* Settings live in the drawer on phones; the floating gear steps aside.
     (html body … so these beat theme-panel.php's own rules, which load later.) */
  html body #theme-toggle { display: none; }
  html body #theme-panel { left: 10px; right: 10px; bottom: 10px; width: auto; z-index: 610; }
}
@media (prefers-reduced-motion: reduce) { #papyr-nav { transition: none; } }
</style>

<button id="pn-burger" type="button" aria-label="Open menu" aria-controls="papyr-nav" aria-expanded="false" onclick="papyrToggleNav()">☰</button>
<div id="pn-scrim" onclick="papyrToggleNav(false)"></div>

<nav id="papyr-nav" aria-label="Site navigation">
  <div class="pn-brand">
    <a href="<?= htmlspecialchars($_papyrUrlBase, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($_papyrLabel, ENT_QUOTES, 'UTF-8') ?></a>
  </div>
  <ul class="pn-tree" id="pn-tree">
    <?php papyrNavTree($_papyrTree, $_papyrActive, 0, $_papyrUrlBase) ?>
  </ul>
  <div class="pn-foot">
    <button type="button" onclick="papyrToggleNav(false); toggleThemePanel()">⚙ Settings</button>
    <?php if (!$_papyrIsMember && $_papyrInviteReady): ?>
    <button type="button" onclick="papyrToggleNav(false); papyrOpenUnlock()">🔒 Unlock discussion</button>
    <?php endif; ?>
  </div>
</nav>

<?php include $_papyrEngine . '/templates/partials/theme-panel.php'; ?>
<?php include_once $_papyrEngine . '/templates/partials/comments.php'; ?>

<script>
/* Mobile drawer — no-op on desktop, where the sidebar is always visible. */
(function () {
  var btn = document.getElementById('pn-burger');
  window.papyrToggleNav = function (force) {
    var open = typeof force === 'boolean' ? force : !document.body.classList.contains('pn-open');
    document.body.classList.toggle('pn-open', open);
    if (btn) { btn.setAttribute('aria-expanded', String(open)); btn.textContent = open ? '✕' : '☰'; }
  };
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') papyrToggleNav(false); });
}());
</script>

<script>
/* GSAP enhancement — progressive: the CSS animation above handles it if GSAP
   isn't available. If the page loads GSAP (e.g. an n01z album page), this
   takes over and runs a smoother stagger. */
(function () {
  if (typeof gsap === 'undefined') return;
  var items = document.querySelectorAll('#pn-tree li a');
  items.forEach(function (el) { el.style.animationName = 'none'; });
  gsap.from(items, {
    opacity: 0, x: -8, duration: 0.35,
    stagger: 0.04, ease: 'power2.out', clearProps: 'transform,opacity'
  });
}());
</script>
