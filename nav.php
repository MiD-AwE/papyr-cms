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

use Papyr\Core\NavBuilder;
use Papyr\Core\UrlResolver;

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

@media (max-width: 640px) {
  #papyr-nav {
    position: relative; width: 100%;
    height: auto; bottom: auto; padding-bottom: 0;
    border-right: none; border-bottom: 1px solid var(--border-bright);
  }
  body { margin-left: 0; }
  .pn-tree { display: flex; flex-wrap: wrap; padding: .3rem .5rem; }
  .pn-tree li { display: inline-block; }
  .pn-dir-label { display: none; }
  .pn-dir > ul { display: inline-flex; padding-left: 0; }
}
</style>

<nav id="papyr-nav" aria-label="Site navigation">
  <div class="pn-brand">
    <a href="<?= htmlspecialchars($_papyrUrlBase, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($_papyrLabel, ENT_QUOTES, 'UTF-8') ?></a>
  </div>
  <ul class="pn-tree" id="pn-tree">
    <?php papyrNavTree($_papyrTree, $_papyrActive, 0, $_papyrUrlBase) ?>
  </ul>
</nav>

<?php include $_papyrEngine . '/templates/partials/theme-panel.php'; ?>

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
