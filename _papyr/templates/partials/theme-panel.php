<?php
declare(strict_types=1);

/*
 * PapyrCMS theme panel partial — include anywhere inside <body>.
 *
 * Usage in a site page (e.g. index.php):
 *
 *     <?php include '_papyr/templates/partials/theme-panel.php'; ?>
 *
 * What it provides:
 *   - A fixed ⚙ toggle button (bottom-left).
 *   - A slide-up panel that discovers HSL token triplets (--*-h/s/l)
 *     from the page's computed :root and builds a slider group for each,
 *     with a typed colour field (the shared component in hsl-sliders.php).
 *   - Dark/Light polarity flip (hues preserved, luminance inverted).
 *   - Reset: restores original :root values captured on open.
 *   - Commit: POSTs current component values to save-theme.php and writes
 *     data/themes/{pageKey}.json at the site root. PHP can inject those
 *     saved values back into :root on next load via load-theme.php.
 *
 * Requires:
 *   - The site's :root must define HSL component vars as --{name}-h/s/l
 *     triplets (e.g. --accent-h, --accent-s, --accent-l). The panel
 *     discovers them automatically; unknown triplets get auto-labeled.
 *   - api/save-theme.php must be reachable. The URL is built from
 *     the base path from the request context (papyr()->basePath, computed
 *     once by nav.php via UrlResolver) — this partial does not re-derive it.
 */

// All request state comes from the one read-only context: papyr() on the
// server, window.PAPYR in the browser (both registered by nav.php). Never
// from variables in the including file's scope.
// Included standalone (no nav.php boot), the context is an inert visitor and
// a minimal window.PAPYR is emitted below with a request-derived base path.
$_tp = \Papyr\Core\Context::current();
$_tpBase = $_tp->ready ? $_tp->site['basePath'] : (function (): string {
    $root = realpath(__DIR__ . '/../../..');
    return $root !== false ? \Papyr\Core\UrlResolver::basePath($root) : '';
})();
$_tpAdmin  = $_tp->isAdmin();
$_tpMember = $_tp->isMember();
$_tpLogin  = htmlspecialchars($_tpBase . '/_papyr/login.php', ENT_QUOTES, 'UTF-8');
?>
<style>
/* ---- PapyrCMS theme panel ---- */   
#theme-toggle {
  position: fixed; left: 14px; bottom: 14px; z-index: 500;
  width: 38px; height: 38px; border-radius: 50%;
  background: var(--card-bg, rgba(10,12,18,.92));
  border: 1px solid var(--border-bright, rgba(100,120,160,.22));
  color: var(--accent, #5ad1c5); font-size: 1rem; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 0 20px rgba(0,0,0,.65);
  transition: transform .2s, box-shadow .2s;
  font-family: inherit;
}
#theme-toggle:hover {
  transform: scale(1.08);
  box-shadow: 0 0 28px var(--qr-glow, rgba(90,209,197,.15));
}

#theme-panel {
  position: fixed; left: 14px; bottom: 60px; z-index: 500;
  width: 236px; max-height: 0; overflow: hidden; opacity: 0;
  background: var(--overlay-bg, rgba(10,12,18,.97));
  border: 1px solid var(--border-bright, rgba(100,120,160,.22));
  border-radius: 8px;
  backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
  box-shadow: 0 0 40px rgba(0,0,0,.65);
  transition: max-height .3s ease, opacity .25s ease;
  font-family: inherit;
}
#theme-panel.open { max-height: 74dvh; overflow-y: auto; opacity: 1; }
#theme-panel .tp-pad { position: relative; }
.tp-close {
  position: absolute; top: .55rem; right: .6rem; background: none; border: 0;
  color: var(--text-dim, #889); font-size: .85rem; cursor: pointer; line-height: 1;
}
.tp-close:hover { color: var(--accent, #5ad1c5); }
.tp-access { margin-bottom: 1rem; padding-bottom: .9rem; border-bottom: 1px solid var(--border, rgba(100,120,160,.15)); }
.tp-access-state { display: block; font-size: .72rem; color: var(--text, #dde); margin: 0 0 .45rem; }
.tp-access-row { display: flex; gap: .4rem; }
.tp-access-row input {
  flex: 1; min-width: 0; font: inherit; font-size: .75rem; padding: .35rem .5rem; border-radius: 4px;
  color: var(--text, #dde); background: var(--bg, #0a0c12); border: 1px solid var(--border-bright, rgba(100,120,160,.22));
}
.tp-access a.tp-btn { display: inline-block; text-decoration: none; }
.tp-admin-link { display: inline-block; margin-top: .5rem; font-size: .62rem; color: var(--text-dim, #889); }
.tp-admin-link:hover { color: var(--accent, #5ad1c5); }
.tp-access-msg { font-size: .68rem; color: var(--text-dim, #889); margin: .4rem 0 0; min-height: 1em; }
#theme-panel .tp-pad { padding: .9rem 1rem 1rem; }
#theme-panel h4 {
  font-size: .55rem; letter-spacing: .28em; text-transform: uppercase;
  color: var(--accent, #5ad1c5); margin-bottom: .7rem; font-weight: 700;
}

.tp-mode { display: flex; align-items: center; gap: .5rem; margin-bottom: .85rem; }
.tp-mode-switch {
  position: relative; width: 38px; height: 20px; flex-shrink: 0;
  background: var(--tp-track, rgba(90, 209, 197, .18));
  border: 1px solid var(--border-bright, rgba(100,120,160,.22));
  border-radius: 10px; cursor: pointer; transition: background .2s;
}
.tp-mode-switch::after {
  content: ''; position: absolute; top: 1px; left: 1px;
  width: 16px; height: 16px; border-radius: 50%;
  background: var(--accent, #5ad1c5); transition: transform .2s;
}
.tp-mode-switch.light::after { transform: translateX(18px); }
.tp-mode label {
  font-size: .5rem; letter-spacing: .16em; text-transform: uppercase;
  color: var(--text-dim, rgba(200,210,226,.45));
}

/* Slider groups, swatches and typed colour fields: partials/hsl-sliders.php (shared with Add Page). */

.tp-controls { display: flex; gap: .4rem; margin-top: .5rem; flex-wrap: wrap; }
.tp-btn {
  flex: 1; min-width: 62px; font-family: inherit; font-size: .5rem;
  letter-spacing: .14em; text-transform: uppercase;
  background: none; border: 1px solid var(--border-bright, rgba(100,120,160,.22));
  color: var(--text-dim, rgba(200,210,226,.45)); border-radius: 3px; padding: .4rem .3rem;
  cursor: pointer; transition: border-color .15s, color .15s;
}
.tp-btn:hover { border-color: var(--accent, #5ad1c5); color: var(--accent, #5ad1c5); }
#theme-panel.saved-flash { box-shadow: 0 0 40px var(--accent, #5ad1c5); }
</style>

<button id="theme-toggle" onclick="toggleThemePanel()" title="Theme settings" aria-label="Theme settings">⚙</button>

<div id="theme-panel" role="dialog" aria-label="Theme settings">
  <div class="tp-pad">
    <button type="button" class="tp-close" onclick="toggleThemePanel()" aria-label="Close settings">✕</button>

    <h4>Access</h4>
    <div class="tp-access">
      <?php if ($_tpAdmin): ?>
        <p class="tp-access-state">Signed in as admin. Discussion unlocked.</p>
        <form method="POST" action="<?= $_tpLogin ?>">
          <input type="hidden" name="action" value="logout">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_tp->user['csrf'], ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="tp-btn">Log out</button>
        </form>
      <?php else: ?>
        <?php if ($_tpMember): ?>
          <p class="tp-access-state">🔓 Discussion unlocked</p>
          <button type="button" class="tp-btn" onclick="papyrLock()">Lock</button>
        <?php elseif ($_tp->site['invites']): ?>
          <form onsubmit="return papyrUnlock(event)" autocomplete="off">
            <label for="tp-invite" class="tp-access-state">🔒 Invite password</label>
            <div class="tp-access-row">
              <input id="tp-invite" type="password" required maxlength="200" autocomplete="current-password">
              <button type="submit" class="tp-btn">Unlock</button>
            </div>
          </form>
        <?php endif; ?>
        <?php /* Always offered to signed-out visitors — the admin login must stay
                 reachable even when no invite password is configured. */ ?>
        <a class="tp-admin-link" href="<?= $_tpLogin ?>">Site admin? Log in</a>
      <?php endif; ?>
      <p id="tp-access-msg" class="tp-access-msg" role="status"></p>
    </div>

    <h4>Palette</h4>
    <div class="tp-mode">
      <div id="tp-mode-switch" class="tp-mode-switch" onclick="toggleMode()" role="switch" aria-label="Light / dark"></div>
      <label id="tp-mode-label">Dark</label>
    </div>
    <div id="tp-token-groups"></div>
    <div class="tp-controls">
      <button class="tp-btn" onclick="resetTheme()">Reset</button>
      <?php if ($_tpAdmin) : ?>
      <button class="tp-btn" onclick="saveTheme()">Commit</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include_once __DIR__ . '/hsl-sliders.php'; ?>
<?php if (!$_tp->ready): ?>
<script>
window.PAPYR = window.PAPYR || Object.freeze(<?= json_encode(
    ['basePath' => $_tpBase, 'pageKey' => '', 'role' => 'visitor', 'csrf' => '',
     'api' => ['theme' => $_tpBase . '/_papyr/api/save-theme.php', 'unlock' => $_tpBase . '/_papyr/api/unlock.php',
               'comments' => $_tpBase . '/_papyr/api/comments.php', 'pages' => $_tpBase . '/_papyr/api/pages.php']],
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
</script>
<?php endif; ?>
<script>
(function () {
  'use strict';

  var ROOT     = document.documentElement;
  var PANEL    = document.getElementById('theme-panel');
  // Endpoints, page key and CSRF token come from window.PAPYR (nav.php).
  var P        = window.PAPYR || { basePath: '', pageKey: '', role: 'visitor', csrf: '', api: {} };
  var SAVE_URL   = P.api.theme;
  var UNLOCK_URL = P.api.unlock;
  var PAGE_KEY = P.pageKey;
  var CSRF     = P.role === 'admin' ? P.csrf : '';

  /* ---- Token discovery ----
     Scans stylesheet :root rules for --*-h properties, then confirms the
     matching --*-s and --*-l exist in computed style. Falls back to the
     n01z four-token set if nothing is found (e.g. cross-origin sheets). */
  function discoverTokens() {
    var found = [];
    var seen  = {};
    try {
      for (var si = 0; si < document.styleSheets.length; si++) {
        var rules;
        try { rules = document.styleSheets[si].cssRules; } catch (e) { continue; }
        if (!rules) continue;
        for (var ri = 0; ri < rules.length; ri++) {
          var rule = rules[ri];
          if (!(rule instanceof CSSStyleRule) || rule.selectorText !== ':root') continue;
          var style = rule.style;
          for (var pi = 0; pi < style.length; pi++) {
            var prop = style[pi];
            if (!prop.endsWith('-h')) continue;
            var base = prop.slice(0, -2);
            if (seen[base]) continue;
            seen[base] = true;
            var cs = getComputedStyle(ROOT);
            if (cs.getPropertyValue(base + '-s').trim() && cs.getPropertyValue(base + '-l').trim()) {
              found.push(base.replace(/^--/, ''));
            }
          }
        }
      }
    } catch (e) { /* silent */ }
    return found.length > 0 ? found : ['accent', 'accent2', 'bg', 'text'];
  }

  function tokenLabel(name) {
    var labels = { accent: 'Accent', accent2: 'Accent 2', bg: 'Background', text: 'Text' };
    return labels[name] || name.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  /* ---- Slider groups: the shared HSL component (hsl-sliders.php) ----
     Reads and writes :root only — the ephemeral user tier. Commit is separate. */
  var TOKENS = discoverTokens();
  var SLIDERS = null;

  function buildGroups() {
    SLIDERS = window.PapyrHsl.build(document.getElementById('tp-token-groups'), {
      tokens: TOKENS,
      label:  tokenLabel,
      read:   function (name, part) { return getComputedStyle(ROOT).getPropertyValue('--' + name + '-' + part).trim(); },
      write:  function (name, part, value) { ROOT.style.setProperty('--' + name + '-' + part, value); }
    });
  }

  /* ---- Capture and sync ---- */
  var DEFAULTS = {};

  function captureDefaults() {
    var cs = getComputedStyle(ROOT);
    TOKENS.forEach(function (name) {
      ['h', 's', 'l'].forEach(function (c) {
        var key = '--' + name + '-' + c;
        DEFAULTS[key] = cs.getPropertyValue(key).trim();
      });
    });
  }

  function syncSlidersFromRoot() {
    if (SLIDERS) SLIDERS.sync();
  }

  /* ---- Dark / light polarity flip ---- */
  var MODE = 'dark';

  window.toggleMode = function () {
    MODE = MODE === 'dark' ? 'light' : 'dark';
    document.getElementById('tp-mode-switch').classList.toggle('light', MODE === 'light');
    document.getElementById('tp-mode-label').textContent = MODE === 'light' ? 'Light' : 'Dark';

    var cs = getComputedStyle(ROOT);
    if (MODE === 'light') {
      var bgL = parseFloat(cs.getPropertyValue('--bg-l'));
      var txL = parseFloat(cs.getPropertyValue('--text-l'));
      ROOT.style.setProperty('--bg-l',   (100 - bgL) + '%');
      ROOT.style.setProperty('--text-l', (100 - txL) + '%');
      ['--accent-l', '--accent2-l'].forEach(function (v) {
        var L = parseFloat(cs.getPropertyValue(v));
        if (L > 55) ROOT.style.setProperty(v, Math.max(38, L - 30) + '%');
      });
    } else {
      ['--bg-l', '--text-l', '--accent-l', '--accent2-l'].forEach(function (v) {
        if (DEFAULTS[v] !== undefined) ROOT.style.setProperty(v, DEFAULTS[v]);
      });
    }
    syncSlidersFromRoot();
  };

  /* ---- Panel toggle ---- */
  window.toggleThemePanel = function () {
    PANEL.classList.toggle('open');
    if (PANEL.classList.contains('open')) syncSlidersFromRoot();
  };

  /* ---- Access: invite-only participant unlock ---- */
  window.papyrOpenUnlock = function () {
    if (!PANEL.classList.contains('open')) toggleThemePanel();
    var input = document.getElementById('tp-invite');
    if (input) { setTimeout(function () { input.focus(); }, 150); }
    PANEL.scrollTop = 0;
  };

  function accessCall(payload, msgEl) {
    if (msgEl) msgEl.textContent = '…';
    return fetch(UNLOCK_URL, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) { location.reload(); return; }
        if (msgEl) msgEl.textContent = d.error || 'Something went wrong.';
      })
      .catch(function () { if (msgEl) msgEl.textContent = 'Network error — try again.'; });
  }

  window.papyrUnlock = function (ev) {
    ev.preventDefault();
    var input = document.getElementById('tp-invite');
    accessCall({ action: 'unlock', pass: input.value }, document.getElementById('tp-access-msg'));
    input.value = '';
    return false;
  };

  window.papyrLock = function () {
    accessCall({ action: 'lock' }, document.getElementById('tp-access-msg'));
  };

  /* ---- Reset ---- */
  window.resetTheme = function () {
    Object.keys(DEFAULTS).forEach(function (v) {
      ROOT.style.setProperty(v, DEFAULTS[v]);
    });
    MODE = 'dark';
    document.getElementById('tp-mode-switch').classList.remove('light');
    document.getElementById('tp-mode-label').textContent = 'Dark';
    syncSlidersFromRoot();
  };

  /* ---- Commit ---- */
  window.saveTheme = function () {
    var theme = { mode: MODE };
    var cs    = getComputedStyle(ROOT);
    TOKENS.forEach(function (name) {
      ['h', 's', 'l'].forEach(function (c) {
        var key = name + '-' + c;
        theme[key] = cs.getPropertyValue('--' + key).trim();
      });
    });

    /* pageKey comes from the server (papyr_page_key, relative to the SITE ROOT),
       so subfolder installs like /SolarPoweredBugZapper/research.php save to
       "research", matching what load-theme.php reads. Deriving it from
       location.pathname would include the install folder in the key. */
    var pageKey = PAGE_KEY || 'index';

    fetch(SAVE_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ page: pageKey, csrf: CSRF, theme: theme })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) flashSaved(); else console.error('[papyr] save-theme:', d.error); })
      .catch(function (e) { console.error('[papyr] save-theme failed', e); });
  };

  function flashSaved() {
    PANEL.classList.add('saved-flash');
    setTimeout(function () { PANEL.classList.remove('saved-flash'); }, 500);
  }

  /* ---- Init ---- */
  buildGroups();
  captureDefaults();
  window.papyrSyncSliders = syncSlidersFromRoot;

}());
</script>
