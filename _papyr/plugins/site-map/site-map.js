/*
 * PapyrCMS 3D site map — renderer.
 *
 * Reads the node graph that the site-map plugin (plugin.php) embeds in
 * #psm-data and draws it with Three.js (r128, global THREE). Read-only:
 * nothing here writes anywhere. Layout is a small force simulation adapted
 * from the 3D Mind Map prototype: children spring toward their parent,
 * every node repels every other, and the root stays pinned at the origin.
 *
 * Node shape: { id, text, kind: root|dir|page|node, path, page_title, color: {h,s,l}|null,
 *               themed, draft, cms_managed, mtime, children: [] }
 * kind "node" comes from map.nodes providers (ADR-002 §6.6) and adds
 *   { status, link, model, visibility } — fixed status colour, optional link.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('psm-data');
  var stage  = document.getElementById('psm-stage');
  var host   = document.getElementById('psm-canvas');
  if (!dataEl || !stage || !host) return;

  var payload;
  try { payload = JSON.parse(dataEl.textContent); } catch (e) { payload = null; }
  if (!payload || !payload.root) return fail('The site map data could not be read.');
  if (typeof THREE === 'undefined' || typeof THREE.OrbitControls !== 'function') {
    return fail('The 3D library did not load. Every page is listed in the navigation.');
  }

  var BASE    = payload.base || '';
  var ADMIN   = payload.admin === true;
  var PAGES   = ADMIN && payload.pages ? payload.pages : null;   // { api, starters } for admin
  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Layout constants (tuned for sites of a handful to a few hundred pages).
  var RADIUS       = { root: 5, dir: 3.4, page: 2.5, node: 1.8 };
  var REST_LENGTH  = 26;
  var REPULSION    = 900;
  var SPRING       = 0.9;
  var DAMPING      = 0.86;
  var MAX_SPEED    = 60;
  var SETTLE_SPEED = 0.05;

  // ── Renderer, scene, camera ────────────────────────────────────────────
  var renderer;
  try {
    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  } catch (e) {
    return fail('This browser could not start WebGL. Every page is listed in the navigation.');
  }
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.setClearColor(0x000000, 0);
  host.appendChild(renderer.domElement);

  var scene  = new THREE.Scene();
  var camera = new THREE.PerspectiveCamera(55, 1, 0.1, 2000);
  camera.position.set(0, 30, 140);

  var controls = new THREE.OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;
  controls.minDistance = 12;
  controls.maxDistance = 600;

  scene.add(new THREE.HemisphereLight(0xffffff, 0x222233, 0.75));
  var key = new THREE.DirectionalLight(0xffffff, 0.7);
  key.position.set(40, 80, 60);
  scene.add(key);

  var group = new THREE.Group();
  scene.add(group);

  // ── Theme tokens of THIS page (labels, edges) ─────────────────────────
  var edgeMaterial = new THREE.LineBasicMaterial({ transparent: true, opacity: 0.55 });
  var dirColor = new THREE.Color();

  function cssNum(name, fallback) {
    var v = parseFloat(getComputedStyle(document.documentElement).getPropertyValue(name));
    return isNaN(v) ? fallback : v;
  }
  function hsl(h, s, l) { return new THREE.Color().setHSL(((h % 360) + 360) % 360 / 360, s / 100, l / 100); }

  function readTokens() {
    edgeMaterial.color.copy(hsl(cssNum('--accent2-h', 194), cssNum('--accent2-s', 76), cssNum('--accent2-l', 50)));
    dirColor.copy(hsl(cssNum('--text-h', 255), 12, cssNum('--bg-l', 9) < 50 ? 62 : 38));
    nodes.forEach(function (n) { if (!n.data.color) n.mesh.material.color.copy(dirColor); });
  }

  // ── Build the graph ────────────────────────────────────────────────────
  var nodes = [];          // { data, mesh, label, parent, children, vel, force }
  var byId  = {};
  var edges = [];          // { a, b, line }
  var labelLayer = document.getElementById('psm-labels');
  var geometries = {};

  function sphere(kind) {
    if (!geometries[kind]) geometries[kind] = new THREE.SphereGeometry(RADIUS[kind] || RADIUS.page, 32, 16);
    return geometries[kind];
  }

  function makeMaterial(d) {
    var color = d.color ? hsl(d.color.h, d.color.s, d.color.l) : dirColor.clone();
    var mat = new THREE.MeshStandardMaterial({
      color: color,
      metalness: d.kind === 'root' ? 0.45 : 0.25,
      roughness: 0.4,
      emissive: color.clone(),
      emissiveIntensity: d.themed ? 0.28 : 0.1
    });
    if (d.draft) { mat.transparent = true; mat.opacity = 0.35; mat.depthWrite = false; }
    return mat;
  }

  function build(d, parent, index, siblings, level) {
    var mesh = new THREE.Mesh(sphere(d.kind), makeMaterial(d));
    if (d.draft) {
      mesh.add(new THREE.Mesh(sphere(d.kind), new THREE.MeshBasicMaterial({
        color: mesh.material.color, wireframe: true, transparent: true, opacity: 0.6
      })));
    }

    var n = {
      data: d, mesh: mesh, parent: parent, children: [], level: level,
      vel: new THREE.Vector3(), force: new THREE.Vector3(),
      baseEmissive: mesh.material.emissiveIntensity
    };
    mesh.userData.node = n;

    // Initial placement: golden-angle spread on a shell around the parent.
    if (parent) {
      var r = REST_LENGTH * (1 + level * 0.25);
      var y = (1 - 2 * ((index + 0.5) / siblings)) * r;
      var rad = Math.sqrt(Math.max(0, r * r - y * y));
      var th = index * Math.PI * (3 - Math.sqrt(5)) + level;
      mesh.position.set(
        parent.mesh.position.x + rad * Math.cos(th) + jitter(),
        parent.mesh.position.y + y + jitter(),
        parent.mesh.position.z + rad * Math.sin(th) + jitter()
      );
      var geo = new THREE.BufferGeometry();
      geo.setAttribute('position', new THREE.BufferAttribute(new Float32Array(6), 3));
      var line = new THREE.Line(geo, edgeMaterial);
      group.add(line);
      edges.push({ a: parent, b: n, line: line });
      parent.children.push(n);
    }

    var label = document.createElement('div');
    label.className = 'psm-label is-' + d.kind + (d.draft ? ' is-draft' : '');
    label.textContent = d.text + (d.draft ? ' (draft)' : '');
    labelLayer.appendChild(label);
    n.label = label;

    group.add(mesh);
    nodes.push(n);
    byId[d.id] = n;

    var kids = d.children || [];
    for (var i = 0; i < kids.length; i++) build(kids[i], n, i, kids.length, level + 1);
    return n;
  }

  function jitter() { return (Math.random() - 0.5) * 4; }

  var root = build(payload.root, null, 0, 1, 0);
  readTokens();

  var summary = document.getElementById('psm-summary');
  function updateSummary() {
    if (!summary) return;
    var pageCount = nodes.filter(function (n) { return n.data.path; }).length;
    var dirCount  = nodes.filter(function (n) { return n.data.kind === 'dir'; }).length;
    var itemCount = nodes.filter(function (n) { return n.data.kind === 'node'; }).length;
    summary.textContent = pageCount + ' page' + (pageCount === 1 ? '' : 's') +
      (dirCount ? ' · ' + dirCount + ' folder' + (dirCount === 1 ? '' : 's') : '') +
      (itemCount ? ' · ' + itemCount + ' item' + (itemCount === 1 ? '' : 's') : '') +
      ' · live from the filesystem';
  }
  updateSummary();

  // Live theme panel edits restyle edges and folder nodes.
  new MutationObserver(readTokens).observe(document.documentElement, { attributes: true, attributeFilter: ['style', 'class', 'data-theme'] });

  // ── Physics ───────────────────────────────────────────────────────────
  var tmp = new THREE.Vector3();
  var settled = false;

  function step(dt) {
    var maxV = 0, i, j, a, b;
    for (i = 0; i < nodes.length; i++) nodes[i].force.set(0, 0, 0);

    for (i = 0; i < nodes.length; i++) {
      a = nodes[i];
      for (j = i + 1; j < nodes.length; j++) {
        b = nodes[j];
        tmp.subVectors(a.mesh.position, b.mesh.position);
        var d2 = tmp.lengthSq();
        if (d2 < 0.01) { tmp.set(Math.random() - 0.5, Math.random() - 0.5, Math.random() - 0.5); d2 = 0.01; }
        tmp.normalize().multiplyScalar(REPULSION / d2);
        a.force.add(tmp);
        b.force.sub(tmp);
      }
      if (a.parent) {
        tmp.subVectors(a.parent.mesh.position, a.mesh.position);
        var dist = tmp.length() || 0.001;
        tmp.multiplyScalar(SPRING * (dist - REST_LENGTH) / dist);
        a.force.add(tmp);
        a.parent.force.sub(tmp);
      }
    }

    for (i = 0; i < nodes.length; i++) {
      a = nodes[i];
      if (a === root) continue;
      a.vel.addScaledVector(a.force, dt).multiplyScalar(DAMPING);
      if (a.vel.length() > MAX_SPEED) a.vel.setLength(MAX_SPEED);
      a.mesh.position.addScaledVector(a.vel, dt);
      maxV = Math.max(maxV, a.vel.length());
    }
    return maxV;
  }

  function syncEdges() {
    for (var i = 0; i < edges.length; i++) {
      var p = edges[i].line.geometry.attributes.position;
      var A = edges[i].a.mesh.position, B = edges[i].b.mesh.position;
      p.setXYZ(0, A.x, A.y, A.z);
      p.setXYZ(1, B.x, B.y, B.z);
      p.needsUpdate = true;
      edges[i].line.geometry.computeBoundingSphere();
    }
  }

  if (REDUCED) {
    for (var k = 0; k < 600 && step(1 / 60) > SETTLE_SPEED; k++);
    settled = true;
  }
  syncEdges();

  // ── Labels ────────────────────────────────────────────────────────────
  var proj = new THREE.Vector3();
  var width = 1, height = 1;

  var placed = [];

  // Label boxes never change size (text is static), so measure once.
  function measure(n) {
    if (!n.labelSize) n.labelSize = { w: n.label.offsetWidth, h: n.label.offsetHeight };
    return n.labelSize;
  }

  function updateLabels() {
    // Nearest first, so a label hidden behind a closer one is the one that fades.
    var order = nodes.slice().sort(function (a, b) {
      return camera.position.distanceToSquared(a.mesh.position) - camera.position.distanceToSquared(b.mesh.position);
    });
    placed.length = 0;

    for (var i = 0; i < order.length; i++) {
      var n = order[i];
      proj.copy(n.mesh.position).project(camera);
      if (proj.z > 1 || proj.z < -1) { n.label.style.opacity = '0'; continue; }
      var dist = camera.position.distanceTo(n.mesh.position);
      var fade = n === selected || n.data.kind === 'root' ? 1 : Math.max(0, Math.min(1, (420 - dist) / 180));
      var scale = Math.max(0.6, Math.min(1, 120 / dist + 0.35));
      // Lift the label just above the sphere's on-screen top edge.
      var screenR = (RADIUS[n.data.kind] || RADIUS.page) * n.mesh.scale.x /
        (Math.max(dist, 1) * Math.tan(camera.fov * Math.PI / 360)) * (height / 2);
      var x = (proj.x * 0.5 + 0.5) * width;
      var y = (-proj.y * 0.5 + 0.5) * height - screenR - 4;
      var size = measure(n);
      var box = { l: x - size.w * scale / 2, r: x + size.w * scale / 2, t: y - size.h * scale, b: y };
      var blocked = placed.some(function (p) { return box.l < p.r && box.r > p.l && box.t < p.b && box.b > p.t; });
      if (blocked && n !== selected) fade *= 0.12;
      else if (fade > 0.3) placed.push(box);

      n.label.style.opacity = fade.toFixed(2);
      n.label.style.transform = 'translate(-50%, -100%) translate(' + Math.round(x) + 'px,' + Math.round(y) + 'px) scale(' + scale.toFixed(2) + ')';
      n.label.style.transformOrigin = '50% 100%';
    }
  }

  // ── Selection + info card (the public "CONT" tier) ─────────────────────
  var card = document.getElementById('psm-card');
  var selected = null, hovered = null;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function href(n) { return BASE + '/' + n.data.path.split('/').map(encodeURIComponent).join('/'); }
  // Provider-node links are validated server-side (site-relative, optional #fragment).
  function linkHref(n) {
    var parts = n.data.link.split('#');
    return BASE + '/' + parts[0].split('/').map(encodeURIComponent).join('/') +
      (parts.length > 1 ? '#' + encodeURIComponent(parts.slice(1).join('#')) : '');
  }
  function openUrl(n) { return n.data.path ? href(n) : (n.data.kind === 'node' && n.data.link ? linkHref(n) : null); }
  function kindLabel(n) {
    if (n.data.kind === 'root') return 'Site root';
    if (n.data.kind === 'dir') return 'Folder';
    if (n.data.kind === 'node') return 'Item';
    return 'Page';
  }

  function renderCard(n) {
    if (!n) { card.hidden = true; card.innerHTML = ''; return; }
    var d = n.data, html = '';
    html += '<button type="button" class="psm-close" data-close aria-label="Close">×</button>';
    html += '<h2>' + esc(d.text) + (d.draft ? '<span class="psm-badge">draft</span>' : '') + '</h2>';
    if (d.kind === 'node') html += '<p class="psm-path">' + esc(d.link ? '/' + d.link : d.id) + '</p>';
    else html += '<p class="psm-path">' + esc(d.path ? '/' + d.path : (d.kind === 'dir' ? '/' + d.id : '/')) + '</p>';
    html += '<dl><dt>Type</dt><dd>' + kindLabel(n) + '</dd>';
    if (d.page_title && d.page_title !== d.text) html += '<dt>Page</dt><dd>' + esc(d.page_title) + '</dd>';
    if (d.path && ADMIN) {
      // File metadata is admin-only (ADR-002 §7.2); the server leaves it out for visitors.
      var c = d.color ? hsl(d.color.h, d.color.s, d.color.l).getStyle() : '';
      html += '<dt>Theme</dt><dd><span class="psm-swatch" style="background:' + c + '"></span>' +
        (d.themed ? 'own saved theme' : 'site default') + '</dd>';
      if (d.mtime) html += '<dt>Modified</dt><dd>' + esc(new Date(d.mtime * 1000).toLocaleString()) + '</dd>';
      html += '<dt>Origin</dt><dd>' + (d.cms_managed ? 'created in Papyr' : 'hand-made file') + '</dd>';
    } else if (d.path) {
      // visitors: title, Open page and connections only
    } else if (d.kind === 'dir') {
      html += '<dt>Index</dt><dd>none — a grouping folder</dd>';
    } else if (d.kind === 'node') {
      var sc = d.color ? hsl(d.color.h, d.color.s, d.color.l).getStyle() : '';
      html += '<dt>Status</dt><dd><span class="psm-swatch" style="background:' + sc + '"></span>' + esc(d.status || 'open') + '</dd>';
      if (d.visibility === 'private') html += '<dt>Visibility</dt><dd>private (admin only)</dd>';
    }
    html += '</dl>';
    if (d.path) html += '<a class="psm-open" href="' + esc(href(n)) + '">Open page</a>';
    else if (d.kind === 'node' && d.link) html += '<a class="psm-open" href="' + esc(linkHref(n)) + '">Open</a>';
    if (PAGES && d.kind !== 'node') html += actionsHtml(n);

    if (n.parent || n.children.length) {
      html += '<h3>Connections</h3><ul>';
      if (n.parent) html += '<li><button type="button" class="is-up" data-id="' + esc(n.parent.data.id) + '">' + esc(n.parent.data.text) + '</button></li>';
      n.children.forEach(function (ch) {
        html += '<li><button type="button" data-id="' + esc(ch.data.id) + '">' + esc(ch.data.text) + '</button></li>';
      });
      html += '</ul>';
    }
    card.innerHTML = html;
    card.hidden = false;
  }

  card.addEventListener('click', function (e) {
    if (e.target.closest('[data-close]')) { select(null); return; }
    var act = e.target.closest('[data-act]');
    if (act && selected) { onAction(act, selected); return; }
    var btn = e.target.closest('button[data-id]');
    if (btn && byId[btn.getAttribute('data-id')]) select(byId[btn.getAttribute('data-id')], true);
  });

  function setGlow(n, amount) {
    if (n) n.mesh.material.emissiveIntensity = n.baseEmissive + amount;
  }

  function select(n, fly) {
    if (selected) {
      selected.mesh.scale.setScalar(1);
      setGlow(selected, 0);
      selected.label.classList.remove('is-selected');
    }
    selected = n;
    if (n) {
      n.mesh.scale.setScalar(1.3);
      setGlow(n, 0.45);
      n.label.classList.add('is-selected');
      if (fly) flyTo(n.mesh.position, 70);
    }
    renderCard(n);
  }

  // ── Admin actions → api/pages.php (ADR-002 §7.1–7.2) ──────────────────
  function slugify(t) {
    return String(t).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  }

  function actionsHtml(n) {
    var d = n.data, html = '<div class="psm-actions">';
    if (d.path) {
      html += d.draft
        ? '<button type="button" class="psm-act" data-act="promote">Publish</button>'
        : '<button type="button" class="psm-act" data-act="demote">Make draft</button>';
      // cms_managed decides what is offered (a flag, not a lock): hand-made files are moved by hand.
      if (d.cms_managed) html += '<button type="button" class="psm-act is-danger" data-act="trash">Move to trash</button>';
    }
    if (d.kind === 'root' || d.kind === 'dir') {
      html += '<button type="button" class="psm-act" data-act="new">New page here</button>';
    }
    html += '</div>';
    if (d.kind === 'root' || d.kind === 'dir') {
      var opts = (PAGES.starters || []).map(function (s) {
        return '<option value="' + esc(s.name) + '"' + (s.name === 'article' ? ' selected' : '') + '>' + esc(s.label) + '</option>';
      }).join('');
      html += '<form class="psm-new" hidden>' +
        '<label>Title<input name="title" required maxlength="200" autocomplete="off"></label>' +
        '<label>Page name<input name="slug" required pattern="[a-z0-9][a-z0-9\\-]*" autocomplete="off" spellcheck="false"></label>' +
        '<label>Layout<select name="layout">' + opts + '</select></label>' +
        '<label class="psm-inline"><input type="checkbox" name="draft" checked> Start as a draft</label>' +
        '<p class="psm-path psm-new-path"></p>' +
        '<div class="psm-actions"><button type="submit" class="psm-act">Create</button>' +
        '<button type="button" class="psm-act" data-act="cancel">Cancel</button></div></form>';
    }
    html += '<p class="psm-msg" role="status"></p>';
    return html;
  }

  function dirOf(n) { return n.data.kind === 'root' ? '' : String(n.data.id).replace(/\/+$/, ''); }

  function say(text, ok) {
    var m = card.querySelector('.psm-msg');
    if (m) { m.textContent = text; m.classList.toggle('is-ok', !!ok); }
  }

  function call(body) {
    body.csrf = (window.PAPYR && window.PAPYR.csrf) || '';
    card.querySelectorAll('.psm-act').forEach(function (b) { b.disabled = true; });
    say('Working…', true);
    return fetch(PAGES.api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Network error. Try again.' }; })
      .then(function (d) {
        card.querySelectorAll('.psm-act').forEach(function (b) { b.disabled = false; });
        if (!d || !d.ok) { say((d && d.error) || 'Something went wrong.'); return null; }
        return d;
      });
  }

  function onAction(btn, n) {
    var act = btn.getAttribute('data-act'), form = card.querySelector('.psm-new');
    if (act === 'new') { form.hidden = false; form.elements.title.focus(); newPath(form, n); return; }
    if (act === 'cancel') { form.hidden = true; return; }
    if (act === 'trash' && !btn.hasAttribute('data-armed')) {
      btn.setAttribute('data-armed', '');
      btn.textContent = 'Click again to move to _trash/';
      setTimeout(function () { if (btn.isConnected) { btn.removeAttribute('data-armed'); btn.textContent = 'Move to trash'; } }, 4000);
      return;
    }
    var path = n.data.path, parent = n.parent;
    call({ action: act, path: path }).then(function (d) {
      if (!d) return;
      reconcile(d.root);
      if (act === 'trash') {
        var p = parent && nodes.indexOf(parent) >= 0 ? parent : root;
        select(p, true);
        say('Moved to ' + d.trashed + '. Restore it by moving the file back.', true);
      } else {
        select(findByPath(path) || n, false);
        say(act === 'promote' ? 'Published: it shows in the nav now.' : 'Now a draft: hidden from the nav, still reachable by its link.', true);
      }
    });
  }

  function newPath(form, n) {
    var d = dirOf(n);
    form.querySelector('.psm-new-path').textContent = '/' + (d ? d + '/' : '') + (form.elements.slug.value || '…') + '.php';
  }

  card.addEventListener('input', function (e) {
    var form = e.target.form;
    if (!form || !form.classList.contains('psm-new') || !selected) return;
    if (e.target.name === 'slug') form.dataset.touched = form.elements.slug.value ? '1' : '';
    if (e.target.name === 'title' && !form.dataset.touched) form.elements.slug.value = slugify(e.target.value);
    newPath(form, selected);
  });

  card.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.classList.contains('psm-new') || !selected) return;
    e.preventDefault();
    if (!form.reportValidity()) return;
    var dir = dirOf(selected), draft = form.elements.draft.checked;
    call({ action: 'create', dir: dir, slug: form.elements.slug.value.trim(), layout: form.elements.layout.value,
           fields: { title: form.elements.title.value }, draft: draft }).then(function (d) {
      if (!d) return;
      reconcile(d.root);
      var made = findByPath(d.path);
      if (made) select(made, true);
      say('Created /' + d.path + (draft ? ' as a draft' : '') + '. Open it to fill in the rest, or edit the file.', true);
    });
  });

  function findByPath(path) {
    for (var i = 0; i < nodes.length; i++) if (nodes[i].data.path === path) return nodes[i];
    return null;
  }

  // Merge a fresh graph from the server into the live one: page/folder nodes
  // are updated in place, added next to their parent, or removed; provider
  // nodes (kind "node") stay unless their parent went away. No reload, and
  // the layout only re-settles around what changed.
  function restyle(n) {
    var d = n.data;
    n.mesh.geometry = sphere(d.kind);
    n.mesh.material.dispose();
    n.mesh.material = makeMaterial(d);
    n.baseEmissive = n.mesh.material.emissiveIntensity;
    n.mesh.children.slice().forEach(function (c) { n.mesh.remove(c); });
    if (d.draft) {
      n.mesh.add(new THREE.Mesh(sphere(d.kind), new THREE.MeshBasicMaterial({
        color: n.mesh.material.color, wireframe: true, transparent: true, opacity: 0.6
      })));
    }
    n.label.className = 'psm-label is-' + d.kind + (d.draft ? ' is-draft' : '') + (n === selected ? ' is-selected' : '');
    n.label.textContent = d.text + (d.draft ? ' (draft)' : '');
    n.labelSize = null;
    if (n === selected) { n.mesh.scale.setScalar(1.3); setGlow(n, 0.45); }
  }

  function removeNode(n) {
    n.children.slice().forEach(removeNode);
    if (n === selected) select(null);
    if (n === hovered) hovered = null;
    group.remove(n.mesh);
    n.mesh.material.dispose();
    for (var i = edges.length - 1; i >= 0; i--) {
      if (edges[i].a === n || edges[i].b === n) {
        group.remove(edges[i].line);
        edges[i].line.geometry.dispose();
        edges.splice(i, 1);
      }
    }
    if (n.label.parentNode) n.label.parentNode.removeChild(n.label);
    if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1);
    nodes.splice(nodes.indexOf(n), 1);
    delete byId[n.data.id];
  }

  function reconcile(fresh) {
    var seen = {};
    (function walk(d, parentNode) {
      seen[d.id] = true;
      var n = byId[d.id];
      if (n) {
        var kids = d.children || [];
        n.data = Object.assign({}, d, { children: [] });
        restyle(n);
        kids.forEach(function (k, i) { walk(k, n); });
      } else if (parentNode) {
        var made = build(d, parentNode, parentNode.children.length, parentNode.children.length + 1, parentNode.level + 1);
        (function mark(x) { seen[x.id] = true; (x.children || []).forEach(mark); })(d);
        return made;
      }
    })(fresh, null);
    nodes.slice().forEach(function (n) {
      if (n.data.kind !== 'node' && !seen[n.data.id] && nodes.indexOf(n) >= 0) removeNode(n);
    });
    meshes = nodes.map(function (n) { return n.mesh; });
    readTokens();
    settled = false;
    updateSummary();
  }

  // ── Camera glide ──────────────────────────────────────────────────────
  var flight = null;

  function flyTo(target, distance) {
    var dir = new THREE.Vector3().subVectors(camera.position, controls.target).normalize();
    var endCam = target.clone().addScaledVector(dir, distance);
    if (REDUCED) {
      controls.target.copy(target); camera.position.copy(endCam); return;
    }
    flight = {
      t: 0, fromTarget: controls.target.clone(), toTarget: target.clone(),
      fromCam: camera.position.clone(), toCam: endCam
    };
  }

  function updateFlight(dt) {
    if (!flight) return;
    flight.t = Math.min(1, flight.t + dt / 0.8);
    var e = 1 - Math.pow(1 - flight.t, 3);
    controls.target.lerpVectors(flight.fromTarget, flight.toTarget, e);
    camera.position.lerpVectors(flight.fromCam, flight.toCam, e);
    if (flight.t >= 1) flight = null;
  }

  function resetView() {
    select(null);
    flyTo(new THREE.Vector3(0, 0, 0), Math.max(120, Math.sqrt(nodes.length) * 45));
  }
  var resetBtn = document.getElementById('psm-reset');
  if (resetBtn) resetBtn.addEventListener('click', resetView);

  // ── Pointer input ─────────────────────────────────────────────────────
  var raycaster = new THREE.Raycaster();
  var pointer = new THREE.Vector2();
  var meshes = nodes.map(function (n) { return n.mesh; });   // refreshed by reconcile()
  var downAt = null;

  function pick(e) {
    var r = renderer.domElement.getBoundingClientRect();
    pointer.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
    raycaster.setFromCamera(pointer, camera);
    var hit = raycaster.intersectObjects(meshes, false)[0];
    return hit ? hit.object.userData.node : null;
  }

  renderer.domElement.addEventListener('pointermove', function (e) {
    if (e.pointerType !== 'mouse') return;
    var n = pick(e);
    if (n === hovered) return;
    if (hovered && hovered !== selected) setGlow(hovered, 0);
    hovered = n;
    if (n && n !== selected) setGlow(n, 0.25);
    host.classList.toggle('is-hover', !!n);
  });

  renderer.domElement.addEventListener('pointerdown', function (e) { downAt = { x: e.clientX, y: e.clientY }; });
  renderer.domElement.addEventListener('pointerup', function (e) {
    if (!downAt || Math.hypot(e.clientX - downAt.x, e.clientY - downAt.y) > 5) { downAt = null; return; }
    downAt = null;
    var n = pick(e);
    if (n) select(n, true); else select(null);
  });
  renderer.domElement.addEventListener('dblclick', function (e) {
    var n = pick(e);
    var url = n && openUrl(n);
    if (url) window.location.href = url;
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && selected) select(null);
  });

  // ── Size + loop ───────────────────────────────────────────────────────
  function resize() {
    width = stage.clientWidth || 1;
    height = stage.clientHeight || 1;
    renderer.setSize(width, height, false);
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
  }
  if (typeof ResizeObserver === 'function') new ResizeObserver(resize).observe(stage);
  else window.addEventListener('resize', resize);
  resize();

  // Frame the whole site on load.
  camera.position.set(0, 25, Math.max(120, Math.sqrt(nodes.length) * 45));

  // Deep link: map.php#node=docs/overview.php selects that node once the layout settles.
  function selectFromHash() {
    var m = /^#node=(.+)$/.exec(window.location.hash);
    var n = m && byId[decodeURIComponent(m[1])];
    if (n) setTimeout(function () { select(n, true); }, REDUCED ? 0 : 900);
  }
  window.addEventListener('hashchange', selectFromHash);
  selectFromHash();

  var last = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - last) / 1000);
    last = now;
    if (!settled) {
      settled = step(1 / 60) < SETTLE_SPEED;
      syncEdges();
    }
    updateFlight(dt);
    controls.update();
    renderer.render(scene, camera);
    updateLabels();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);

  function fail(msg) {
    var el = document.createElement('div');
    el.className = 'psm-fallback';
    el.textContent = msg;
    (stage || document.body).appendChild(el);
  }
}());
