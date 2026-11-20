<?php
declare(strict_types=1);

/*
 * site-map — core plugin (ADR-002 Phase D). Live, read-only 3D map of the
 * page tree. A page opts in by calling:
 *
 *     <?php papyr_plugin('site-map'); ?>
 *
 * Runs in the loader's isolated scope with $p (the read-only context),
 * $options and $plugin. Nothing here writes to disk; the graph is derived
 * per request from the Scanner's pages, so it can never drift from the
 * filesystem. Drafts come only from $p->tree, which carries them for the
 * signed-in admin alone.
 *
 * Extra nodes: other plugins register providers on the `map.nodes` hook
 * (see MapTree::attach for the node shape); private nodes are dropped
 * server-side unless the viewer is admin.
 */

use Papyr\Core\MapTree;

// Entry runs only through the plugin loader (papyr_plugin() / boot). Requested
// directly over HTTP it would run without a context — answer 404 and stop.
if (!isset($p) || !$p instanceof \Papyr\Core\Context) { http_response_code(404); return; }

$_psmAdmin    = $p->isAdmin();
$_psmDefaults = $p->theme['defaults'];

// Each page's node colour is the accent that page actually renders with:
// its saved theme if it has one, otherwise the built-in default.
$_psmThemeFor = static function (string $relPath) use ($p): array {
    $t = $p->pageTheme($relPath);
    return [
        'themed' => isset($t['saved']['accent-h']),
        'color'  => [
            'h' => (float) $t['values']['accent-h'],
            's' => (float) $t['values']['accent-s'],
            'l' => (float) $t['values']['accent-l'],
        ],
    ];
};

$_psmTree = MapTree::fromNav($p->tree['nested'], $p->site['label'], $_psmThemeFor);

// Nodes contributed by boot plugins (e.g. a site's own data blocks).
$_psmExtra = [];
foreach ($p->providers('map.nodes') as $_psmOut) {
    if (is_array($_psmOut)) {
        foreach ($_psmOut as $_psmNode) {
            $_psmExtra[] = $_psmNode;
        }
    }
}
if ($_psmExtra !== []) {
    $_psmTree = MapTree::attach($_psmTree, $_psmExtra, $_psmAdmin);
}

$_psmJson = json_encode(
    ['base' => $p->site['basePath'], 'admin' => $_psmAdmin, 'root' => $_psmTree],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);
if ($_psmJson === false) {
    error_log('[PapyrCMS] site-map: json_encode failed: ' . json_last_error_msg());
    $_psmJson = 'null';
}

$_psmAsset = static fn (string $rel): string => htmlspecialchars($p->asset('site-map', $rel), ENT_QUOTES, 'UTF-8');
?>
<style>
#psm-stage {
  position: fixed; top: 0; right: 0; bottom: 0; left: 220px;
  overflow: hidden;
  background:
    radial-gradient(ellipse at 50% 45%, color-mix(in srgb, var(--accent) 7%, transparent), transparent 65%),
    var(--bg);
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
}
#psm-canvas { position: absolute; inset: 0; }
#psm-canvas canvas { display: block; width: 100%; height: 100%; touch-action: none; }
#psm-canvas.is-hover canvas { cursor: pointer; }

#psm-labels { position: absolute; inset: 0; pointer-events: none; }
.psm-label {
  position: absolute; left: 0; top: 0;
  padding: .12rem .45rem;
  font-size: .66rem; letter-spacing: .04em; white-space: nowrap;
  color: var(--text);
  background: color-mix(in srgb, var(--bg) 70%, transparent);
  border: 1px solid var(--border);
  border-radius: 3px;
  will-change: transform, opacity;
}
.psm-label.is-root { font-size: .78rem; color: var(--accent); border-color: var(--border-bright); }
.psm-label.is-dir  { text-transform: uppercase; letter-spacing: .2em; font-size: .56rem; color: var(--text-dim); }
.psm-label.is-draft { font-style: italic; opacity: .7; }
.psm-label.is-node  { font-size: .6rem; color: var(--text-dim); }
.psm-label.is-selected { color: var(--accent); border-color: var(--accent); }

.psm-head {
  position: absolute; top: 1rem; left: 1.2rem; right: 1.2rem;
  display: flex; flex-wrap: wrap; gap: .4rem 1rem; align-items: baseline;
  pointer-events: none;
}
.psm-head h1 {
  margin: 0; font-size: .7rem; letter-spacing: .38em; text-transform: uppercase;
  color: var(--accent); font-weight: 700;
}
.psm-head p { margin: 0; font-size: .62rem; color: var(--text-dim); letter-spacing: .05em; }

.psm-card {
  position: absolute; top: 3.2rem; right: 1rem;
  width: min(300px, calc(100% - 2rem));
  max-height: calc(100% - 6.5rem); overflow-y: auto;
  padding: 1rem 1.1rem;
  background: var(--card-bg);
  border: 1px solid var(--border-bright);
  border-radius: 6px;
  backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
  color: var(--text); font-size: .7rem; line-height: 1.55;
}
.psm-card h2 { margin: 0 0 .15rem; font-size: .95rem; color: var(--accent); letter-spacing: .01em; }
.psm-card .psm-path { color: var(--text-dim); word-break: break-all; margin: 0 0 .7rem; }
.psm-card dl { display: grid; grid-template-columns: auto 1fr; gap: .2rem .8rem; margin: 0 0 .8rem; }
.psm-card dt { color: var(--text-dim); }
.psm-card dd { margin: 0; }
.psm-swatch {
  display: inline-block; width: .7em; height: .7em; border-radius: 50%;
  vertical-align: -.05em; margin-right: .35em; border: 1px solid var(--border-bright);
}
.psm-card h3 {
  margin: .9rem 0 .3rem; font-size: .55rem; letter-spacing: .3em;
  text-transform: uppercase; color: var(--text-dim); font-weight: 400;
}
.psm-card ul { list-style: none; margin: 0; padding: 0; }
.psm-card li button, .psm-btn {
  font: inherit; color: var(--text); background: none; cursor: pointer;
  border: 0; padding: .18rem 0; text-align: left;
}
.psm-card li button:hover, .psm-card li button:focus-visible { color: var(--accent); }
.psm-card li button::before { content: '→ '; color: var(--text-dim); }
.psm-card li button.is-up::before { content: '↑ '; }
.psm-open {
  display: inline-block; margin-top: .2rem;
  padding: .35rem .8rem; border-radius: 4px;
  color: var(--bg); background: var(--accent); text-decoration: none; font-weight: 700;
  letter-spacing: .04em;
}
.psm-open:hover { opacity: .85; }
.psm-badge {
  display: inline-block; padding: 0 .4rem; margin-left: .3rem; border-radius: 3px;
  font-size: .55rem; letter-spacing: .15em; text-transform: uppercase;
  border: 1px dashed var(--accent); color: var(--accent); vertical-align: .15em;
}

.psm-foot {
  position: absolute; left: 1.2rem; right: 1.2rem; bottom: 1rem;
  display: flex; flex-wrap: wrap; gap: .5rem 1.1rem; align-items: center;
  font-size: .6rem; color: var(--text-dim);
}
.psm-foot .psm-key { display: inline-flex; align-items: center; gap: .35rem; }
.psm-dot { width: .7rem; height: .7rem; border-radius: 50%; display: inline-block; }
.psm-dot.own   { background: var(--accent); box-shadow: 0 0 6px var(--accent); }
.psm-dot.def   {
  background: hsl(<?= (float) $_psmDefaults['accent-h'] ?> <?= (float) $_psmDefaults['accent-s'] ?>% <?= (float) $_psmDefaults['accent-l'] ?>%);
  opacity: .75;
}
.psm-dot.dir   { background: var(--text-dim); }
.psm-dot.draft { border: 1px dashed var(--text); }
.psm-foot .psm-btn {
  margin-left: auto; padding: .3rem .7rem;
  border: 1px solid var(--border-bright); border-radius: 4px; color: var(--text);
}
.psm-foot .psm-btn:hover { color: var(--accent); border-color: var(--accent); }

.psm-fallback {
  position: absolute; inset: 0; display: grid; place-items: center;
  padding: 2rem; text-align: center; color: var(--text-dim); font-size: .8rem;
}

.psm-close {
  position: absolute; top: .55rem; right: .6rem;
  font: inherit; font-size: 1rem; line-height: 1; padding: .2rem .4rem;
  background: none; border: 0; color: var(--text-dim); cursor: pointer;
}
.psm-close:hover, .psm-close:focus-visible { color: var(--accent); }
.psm-card h2 { padding-right: 1.4rem; }

@media (max-width: 640px) {
  #psm-stage { position: relative; left: auto; height: 82vh; min-height: 420px; }
  .psm-hint { display: none; }
  .psm-card { top: auto; bottom: 4.4rem; max-height: 48%; }
}
</style>

<main id="psm-stage" aria-label="3D site map">
  <div id="psm-canvas"></div>
  <div id="psm-labels" aria-hidden="true"></div>

  <header class="psm-head">
    <h1>Site Map</h1>
    <p id="psm-summary"></p>
  </header>

  <aside class="psm-card" id="psm-card" aria-live="polite" hidden></aside>

  <footer class="psm-foot">
    <span class="psm-key"><span class="psm-dot own"></span>own saved theme (glows)</span>
    <span class="psm-key"><span class="psm-dot def"></span>site default theme</span>
    <span class="psm-key"><span class="psm-dot dir"></span>folder</span>
    <?php if ($_psmAdmin): ?>
      <span class="psm-key"><span class="psm-dot draft"></span>draft (admin only)</span>
    <?php endif; ?>
    <?php if ($_psmExtra !== []): foreach (MapTree::STATUS_COLORS as $_psmStatus => $_psmC): ?>
      <span class="psm-key"><span class="psm-dot" style="background:hsl(<?= $_psmC['h'] ?> <?= $_psmC['s'] ?>% <?= $_psmC['l'] ?>%)"></span><?= htmlspecialchars($_psmStatus, ENT_QUOTES, 'UTF-8') ?></span>
    <?php endforeach; endif; ?>
    <span class="psm-hint">drag to orbit · scroll to zoom · click to inspect · double-click to open</span>
    <button type="button" class="psm-btn" id="psm-reset">Reset view</button>
  </footer>

  <noscript><div class="psm-fallback">The 3D map needs JavaScript. Every page is listed in the navigation.</div></noscript>
</main>

<script type="application/json" id="psm-data"><?= $_psmJson ?></script>
<script src="<?= $_psmAsset('three/three.min.js') ?>"></script>
<script src="<?= $_psmAsset('three/OrbitControls.js') ?>"></script>
<script src="<?= $_psmAsset('site-map.js') ?>"></script>
