<?php
declare(strict_types=1);

/*
 * PapyrCMS HSL colour component — one component, two hosts (ADR-002 §7.3):
 * the theme panel (theme-panel.php) and Admin → Add Page (_papyr/index.php).
 * Include it with include_once; it emits its CSS and window.PapyrHsl once.
 *
 *   var c = PapyrHsl.build(containerEl, {
 *     tokens: ['accent', 'bg'],                    // groups to build, in order
 *     label:  function (name) { return 'Accent'; },
 *     read:   function (name, part) { return '149' | '57%'; },   // part: h|s|l
 *     write:  function (name, part, value) { … }   // value: '149' | '57%'
 *   });
 *   c.sync();                                       // re-read every group
 *
 *   PapyrHsl.parse('hsl(149 57% 43%)') → { h: 149, s: 57, l: 43 } | null
 *
 * Each group has H/S/L sliders, a swatch and a typed field. Accepted input:
 * "149 57% 43%", "149, 57%, 43%", "hsl(149 57% 43%)", "hsl(149, 57%, 43%)",
 * "#2fae6b", "#2a6". H is clamped to 0–360, S and L to 0–100. Valid input moves
 * the sliders as you type; invalid input leaves them alone and shows a short
 * message. Enter never submits a surrounding form.
 */
?>
<style>
/* ---- PapyrCMS HSL component (shared by the theme panel and Add Page) ---- */
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
.tp-typed {
  display: block; width: 100%; box-sizing: border-box; margin-top: .35rem;
  font: inherit; font-size: .62rem; padding: .25rem .4rem; border-radius: 3px;
  color: var(--text, #dde); background: transparent;
  border: 1px solid var(--border, rgba(100,120,160,.15));
}
.tp-typed:focus { outline: none; border-color: var(--accent, #5ad1c5); }
.tp-typed.is-bad { border-color: hsl(5 80% 66%); }
.tp-typed-msg { display: block; min-height: 0; font-size: .55rem; color: hsl(5 80% 70%); margin-top: .15rem; }
.tp-typed-msg:empty { display: none; }
</style>
<script>
(function () {
  'use strict';
  if (window.PapyrHsl) return;

  function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }

  function hexToHsl(hex) {
    if (hex.length === 3) hex = hex.replace(/./g, '$&$&');
    var r = parseInt(hex.slice(0, 2), 16) / 255,
        g = parseInt(hex.slice(2, 4), 16) / 255,
        b = parseInt(hex.slice(4, 6), 16) / 255;
    var max = Math.max(r, g, b), min = Math.min(r, g, b);
    var h = 0, s = 0, l = (max + min) / 2, d = max - min;
    if (d !== 0) {
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
      else if (max === g) h = (b - r) / d + 2;
      else h = (r - g) / d + 4;
      h *= 60;
    }
    return { h: Math.round(h), s: Math.round(s * 100), l: Math.round(l * 100) };
  }

  /* Typed colour → {h, s, l} (integers, clamped) or null. */
  function parse(text) {
    var t = String(text == null ? '' : text).trim().toLowerCase();
    if (!t) return null;
    var hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/.exec(t);
    if (hex) return hexToHsl(hex[1]);
    var fn = /^hsl\((.*)\)$/.exec(t);
    if (fn) t = fn[1].trim();
    else if (/[()]/.test(t)) return null;
    var parts = t.split(/\s*,\s*|\s+/);
    if (parts.length !== 3) return null;
    var res = [/^(-?\d+(?:\.\d+)?)(?:deg)?$/, /^(\d+(?:\.\d+)?)%?$/, /^(\d+(?:\.\d+)?)%?$/], v = [];
    for (var i = 0; i < 3; i++) {
      var m = res[i].exec(parts[i]);
      if (!m) return null;
      v.push(parseFloat(m[1]));
    }
    return { h: Math.round(clamp(v[0], 0, 360)), s: Math.round(clamp(v[1], 0, 100)), l: Math.round(clamp(v[2], 0, 100)) };
  }

  var PARTS = [['h', 'H', 360, ''], ['s', 'S', 100, '%'], ['l', 'L', 100, '%']];

  function build(container, o) {
    var groups = [];
    container.innerHTML = '';

    (o.tokens || []).forEach(function (name) {
      var label = o.label ? o.label(name) : name;
      var group = document.createElement('div');
      group.className = 'tp-group';
      group.dataset.token = name;

      var lbl = document.createElement('span');
      lbl.className = 'tp-group-label';
      lbl.textContent = label;
      group.appendChild(lbl);

      var rows = {};
      PARTS.forEach(function (def) {
        var part = def[0], unit = def[3];
        var row = document.createElement('div');
        row.className = 'tp-row';
        var l = document.createElement('label');
        l.textContent = def[1];
        var inp = document.createElement('input');
        inp.type = 'range'; inp.min = 0; inp.max = def[2];
        inp.dataset.part = part; inp.dataset.unit = unit;
        inp.setAttribute('aria-label', label + ' ' + def[1]);
        var val = document.createElement('span');
        val.className = 'tp-val';
        inp.addEventListener('input', function () {
          o.write(name, part, inp.value + unit);
          val.textContent = inp.value + unit;
          refresh(true);
        });
        row.appendChild(l); row.appendChild(inp); row.appendChild(val);
        group.appendChild(row);
        rows[part] = { inp: inp, val: val, unit: unit };
      });

      var swatch = document.createElement('div');
      swatch.className = 'tp-swatch';
      group.appendChild(swatch);

      var typed = document.createElement('input');
      typed.type = 'text';
      typed.className = 'tp-typed';
      typed.spellcheck = false;
      typed.autocomplete = 'off';
      typed.placeholder = '149 57% 43% · #2fae6b';
      typed.setAttribute('aria-label', 'Type a colour for ' + label);
      var msg = document.createElement('span');
      msg.className = 'tp-typed-msg';
      msg.setAttribute('role', 'status');
      group.appendChild(typed);
      group.appendChild(msg);

      function current() { return { h: +rows.h.inp.value, s: +rows.s.inp.value, l: +rows.l.inp.value }; }
      function text(v) { return v.h + ' ' + v.s + '% ' + v.l + '%'; }
      function refresh(fromSlider) {
        var v = current();
        swatch.style.background = 'hsl(' + v.h + ' ' + v.s + '% ' + v.l + '%)';
        if (fromSlider || document.activeElement !== typed) {
          typed.value = text(v);
          typed.classList.remove('is-bad');
          msg.textContent = '';
        }
      }
      function apply(v) {
        PARTS.forEach(function (def) {
          var r = rows[def[0]];
          r.inp.value = v[def[0]];
          r.val.textContent = v[def[0]] + r.unit;
          o.write(name, def[0], v[def[0]] + r.unit);
        });
        refresh(false);
      }
      function check(final) {
        var v = parse(typed.value);
        if (v) { apply(v); typed.classList.remove('is-bad'); msg.textContent = ''; return; }
        if (final && typed.value.trim() !== '') {
          typed.classList.add('is-bad');
          msg.textContent = 'Use H S% L% or #hex, e.g. 149 57% 43%';
        }
      }
      typed.addEventListener('input', function () { check(false); });
      typed.addEventListener('change', function () { check(true); });
      typed.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); check(true); }
      });

      function sync() {
        PARTS.forEach(function (def) {
          var r = rows[def[0]];
          var n = parseFloat(o.read(name, def[0]));
          r.inp.value = isNaN(n) ? 0 : n;
          r.val.textContent = r.inp.value + r.unit;
        });
        refresh(false);
      }

      container.appendChild(group);
      groups.push({ name: name, sync: sync, current: current });
      sync();
    });

    return {
      sync: function () { groups.forEach(function (g) { g.sync(); }); },
      values: function () {
        var out = {};
        groups.forEach(function (g) { out[g.name] = g.current(); });
        return out;
      }
    };
  }

  window.PapyrHsl = Object.freeze({ parse: parse, build: build });
}());
</script>
