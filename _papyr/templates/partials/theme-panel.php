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
 *     from the page's computed :root and builds a slider group for each.
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
 *     $_papyrBasePath, the same request-derived base path nav.php computes
 *     via UrlResolver — this partial does not re-derive it independently.
 */

// Reuse the base path nav.php already resolved (the normal include path).
// If this partial is ever included standalone, fall back to resolving it
// here via the same UrlResolver so the two never disagree.
if (!isset($_papyrBasePath)) {
    $_papyrFallbackRoot = realpath(__DIR__ . '/../../..');
    $_papyrBasePath = $_papyrFallbackRoot !== false
        ? \Papyr\Core\UrlResolver::basePath($_papyrFallbackRoot)
        : '';
    unset($_papyrFallbackRoot);
}
$_papyrSaveUrl = $_papyrBasePath . '/_papyr/api/save-theme.php';
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

.tp-group { margin-bottom: .85rem; }
.tp-group-label {
  font-size: .5rem; letter-spacing: .2em; text-transform: uppercase;
  color: var(--text-dim, rgba(200,210,226,.45)); margin-bottom: .35rem; display: block;
}
.tp-row { display: flex; align-items: center; gap: .5rem; margin-bottom: .3rem; }
.tp-row label {
  font-size: .5rem; letter-spacing: .12em;
  color: var(--text-faint, rgba(200,210,226,.22));
  width: 14px; flex-shrink: 0; text-transform: uppercase;
}
.tp-row input[type=range] {
  -webkit-appearance: none; appearance: none; flex: 1; height: 3px;
  background: var(--tp-track, rgba(90, 209, 197, .2)); border-radius: 2px; outline: none; cursor: pointer;
}
.tp-row input[type=range]::-webkit-slider-thumb {
  -webkit-appearance: none; width: 12px; height: 12px;
  border-radius: 50%; background: var(--accent, #5ad1c5); cursor: pointer;
  box-shadow: 0 0 6px var(--tp-track, rgba(90, 209, 197, .4));
}
.tp-row input[type=range]::-moz-range-thumb {
  width: 12px; height: 12px; border: none; border-radius: 50%;
  background: var(--accent, #5ad1c5); cursor: pointer;
}
.tp-row .tp-val {
  font-size: .48rem; color: var(--text-faint, rgba(200,210,226,.22));
  width: 30px; text-align: right; flex-shrink: 0; font-variant-numeric: tabular-nums;
}
.tp-swatch {
  width: 100%; height: 8px; border-radius: 2px; margin-top: .25rem;
  border: 1px solid var(--border, rgba(90,140,200,.09));
}

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

    <?php if (($_papyrInviteReady ?? false) || ($_papyrIsAdmin ?? false)): ?>
    <h4>Access</h4>
    <div class="tp-access">
      <?php if ($_papyrIsAdmin ?? false): ?>
        <p class="tp-access-state">Signed in as admin. Discussion unlocked.</p>
        <a class="tp-btn" href="<?= htmlspecialchars(($_papyrBasePath ?? '') . '/_papyr/login.php?action=logout', ENT_QUOTES, 'UTF-8') ?>">Log out</a>
      <?php elseif ($_papyrIsMember ?? false): ?>
        <p class="tp-access-state">🔓 Discussion unlocked</p>
        <button type="button" class="tp-btn" onclick="papyrLock()">Lock</button>
      <?php else: ?>
        <form onsubmit="return papyrUnlock(event)" autocomplete="off">
          <label for="tp-invite" class="tp-access-state">🔒 Invite password</label>
          <div class="tp-access-row">
            <input id="tp-invite" type="password" required maxlength="200" autocomplete="current-password">
            <button type="submit" class="tp-btn">Unlock</button>
          </div>
        </form>
      <?php endif; ?>
      <?php if (!($_papyrIsAdmin ?? false)): ?>
        <a class="tp-admin-link" href="<?= htmlspecialchars(($_papyrBasePath ?? '') . '/_papyr/login.php', ENT_QUOTES, 'UTF-8') ?>">Site admin? Log in</a>
      <?php endif; ?>
      <p id="tp-access-msg" class="tp-access-msg" role="status"></p>
    </div>
    <?php endif; ?>

    <h4>Palette</h4>
    <div class="tp-mode">
      <div id="tp-mode-switch" class="tp-mode-switch" onclick="toggleMode()" role="switch" aria-label="Light / dark"></div>
      <label id="tp-mode-label">Dark</label>
    </div>
    <div id="tp-token-groups"></div>
    <div class="tp-controls">
      <button class="tp-btn" onclick="resetTheme()">Reset</button>
      <?php if ($_papyrIsAdmin ?? false) : ?>
      <button class="tp-btn" onclick="saveTheme()">Commit</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var ROOT     = document.documentElement;
  var PANEL    = document.getElementById('theme-panel');
  var SAVE_URL = <?= json_encode($_papyrSaveUrl) ?>;
  var UNLOCK_URL = <?= json_encode($_papyrBasePath . '/_papyr/api/unlock.php') ?>;
  var PAGE_KEY = <?= json_encode($_papyrPageKey ?? '') ?>;

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

  /* ---- Build slider groups from discovered tokens ---- */
  var TOKENS = discoverTokens();

  function buildGroups() {
    var container = document.getElementById('tp-token-groups');
    container.innerHTML = '';
    TOKENS.forEach(function (name) {
      var base  = '--' + name;
      var group = document.createElement('div');
      group.className        = 'tp-group';
      group.dataset.token    = name;

      var lbl = document.createElement('span');
      lbl.className   = 'tp-group-label';
      lbl.textContent = tokenLabel(name);
      group.appendChild(lbl);

      [['H', base + '-h', 0, 360, ''],
       ['S', base + '-s', 0, 100, '%'],
       ['L', base + '-l', 0, 100, '%']].forEach(function (def) {
        var cssVar = def[1];
        var unit   = def[4];
        var row    = document.createElement('div');
        row.className = 'tp-row';

        var label = document.createElement('label');
        label.textContent = def[0];

        var inp      = document.createElement('input');
        inp.type     = 'range';
        inp.min      = def[2];
        inp.max      = def[3];
        inp.dataset.var  = cssVar;
        inp.dataset.unit = unit;

        var valSpan = document.createElement('span');
        valSpan.className = 'tp-val';

        inp.addEventListener('input', function () {
          ROOT.style.setProperty(cssVar, inp.value + unit);
          valSpan.textContent = inp.value + unit;
        });

        row.appendChild(label);
        row.appendChild(inp);
        row.appendChild(valSpan);
        group.appendChild(row);
      });

      var swatch = document.createElement('div');
      swatch.className = 'tp-swatch';
      swatch.style.background = 'hsl(var(' + base + '-h) var(' + base + '-s) var(' + base + '-l))';
      group.appendChild(swatch);
      container.appendChild(group);
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
    var cs = getComputedStyle(ROOT);
    document.querySelectorAll('#tp-token-groups input[type=range]').forEach(function (inp) {
      var raw = cs.getPropertyValue(inp.dataset.var).trim();
      inp.value = parseFloat(raw);
      inp.nextElementSibling.textContent = inp.value + (inp.dataset.unit || '');
    });
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
      body:    JSON.stringify({ page: pageKey, theme: theme })
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
