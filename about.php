<?php declare(strict_types=1);
/* Boot the Papyr spine before any output (session, roles, theme); the nav renders after <body>. */
define('PAPYR_DEFER_NAV', true);
for ($_d = __DIR__; !file_exists($_d . '/nav.php') && dirname($_d) !== $_d; $_d = dirname($_d));
require_once $_d . '/nav.php';
unset($_d);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PapyrCMS- About</title>
  <meta name="description" content="Paper thin And Per Your Request. A drop-in flat-file CMS that adds navigation, theming, and AI-assisted content to a site you already have — and gets out of the way." />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inconsolata:wght@400;700&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php
papyr_nav();

// Engine-optional: without _papyr/ the swatch gallery is simply empty (ADR-002 §8).
$_swatches = function_exists('papyr_swatches_enumerate')
    ? papyr_swatches_enumerate(__DIR__ . '/data/swatches')
    : [];
?>
<main class="page-content about">
  <h1 class="visually-hidden">PapyrCMS — Paper thin And Per Your Request</h1>
  <div class="body-content">
    <p>About <em>PapyrCMS</em> </p>

    <!-- HERO: the acrostic, performed -->
    <section class="hero" aria-label="What PapyrCMS stands for">
      <ol class="acrostic" role="list">
        <li><span class="letter">P</span><span class="word">Paper&nbsp;thin</span></li>
        <li><span class="letter">A</span><span class="word">And</span></li>
        <li><span class="letter">P</span><span class="word">Per</span></li>
        <li><span class="letter">Y</span><span class="word">Your</span></li>
        <li><span class="letter">R</span><span class="word">Request</span></li>
      </ol>
      <p class="cms-deadpan">C.M.S.</p>
      <p class="tagline">It does exactly what you asked. Nothing more.</p>

      <p>A project started by guy tired of having to wrestle the CMS down in size. While wondering why so many hours are 
        focused on customization instead of actual work. Behold the simplicity. A Drop-In CMS..? Yes, that's right.</p>
        <p>PapyrCMS is a flat-file CMS that adds navigation, theming, and AI-assisted content to a site you already have — and gets out of the way.</p>
        <ul>
            <li>Flat-file, no database, no build step, no framework to fight.</li>
            <li>Drop it in, and the Scanner finds your pages; the nav builds itself.</li>
            <li>Flexibility in design and implementation for powerful global styling.</li>
            <ul><span style="text-decoration: underline;">Administer your content with ease:</span>
                <li>Write a page here, pull it into your own editor, change it, upload it back.</li>
                <li>PapyrCMS still tracks it — managed, and flagged as edited.</li>
                <li>The file is the source of truth, not a database row, also facilitating to a tidy workflow.</li>
            </ul>
        </ul>
      <?php if (!empty($_swatches)) : ?>
      <div class="swatch-row" role="group" aria-label="Theme preview">
        <?php foreach ($_swatches as $slug => $swatch) :
          $tokensJson = htmlspecialchars(json_encode($swatch['tokens'], JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
          $accentH    = $swatch['tokens']['accent-h'] ?? '0';
          $accentS    = $swatch['tokens']['accent-s'] ?? '0%';
          $accentL    = $swatch['tokens']['accent-l'] ?? '50%';
          $chipColor  = "hsl({$accentH},{$accentS},{$accentL})";
          $name       = htmlspecialchars($swatch['name'], ENT_QUOTES, 'UTF-8');
        ?>
        <button
          class="swatch-btn"
          data-swatch="<?= $tokensJson ?>"
          data-slug="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
          aria-label="Preview <?= $name ?> theme"
          title="<?= $name ?>"
        ><span class="swatch-chip" style="background:<?= $chipColor ?>"></span
          ><span class="swatch-name"><?= $name ?></span></button>
        <?php endforeach; ?>
      </div>
      <p class="swatch-hint">Live preview &middot; save and manage themes from the nav panel.</p>
      <?php endif; ?>
    </section>

    <!-- PROOF: three claims you actually validated this week -->
    <section class="proof" aria-label="What it does">
      <article class="card">
        <h2>Drop it in</h2>
        <p>One sealed <code>_papyr/</code> directory next to the site you already have. No database, no build step, no framework to fight. The Scanner finds your pages; the nav builds itself.</p>
      </article>
      <article class="card">
        <h2>Edit anywhere</h2>
        <p>Write a page here, pull it into your own editor, change it, upload it back. PapyrCMS still tracks it — managed, and flagged as edited. The file is the source of truth, not a database row.</p>
      </article>
      <article class="card">
        <h2>Theme by hooks</h2>
        <p>Every theme token is a named hook. The registry tells you which are live, which are unused, and which a template asked for but the theme never defined. The thing you always wished a CMS would just tell you.</p>
      </article>
    </section>

    <!-- POSTURE: the philosophy, stated plainly -->
    <section class="posture" aria-label="The idea">
      <p>Helpful, but not in the way. It refuses to invade or get too big for its britches. PapyrCMS waits to be asked — then does the one thing you asked, and stays small.</p>
    </section>

  </div>
</main>

<style>
  /* Swatch preview sets --bg-deep on :root; override body here so it cascades. */
  body { background: var(--bg-deep); }

  /* Honors the page template's four tokens: --accent, --text, --bg-deep, --border.
     Falls back gracefully if a token isn't bound yet. */
  .page-content.about {
    max-width: 760px;
    padding: 2rem 2.5rem 5rem;
  }
  .visually-hidden {
    position: absolute; width: 1px; height: 1px;
    padding: 0; margin: -1px; overflow: hidden;
    clip: rect(0 0 0 0); white-space: nowrap; border: 0;
  }

  /* HERO */
  .hero { margin: 1rem 0 3.5rem; }
  .acrostic {
    list-style: none; margin: 0; padding: 0;
    font-family: "Inconsolata", ui-monospace, "Cascadia Code", monospace;
  }
  .acrostic li {
    display: flex; align-items: baseline; gap: 1rem;
    padding: .12rem 0;
    opacity: 0;                 /* GSAP reveals these */
    transform: translateX(-12px);
  }
  .acrostic .letter {
    color: var(--accent);
    font-weight: 700;
    font-size: 2.4rem;
    line-height: 1;
    width: 1.4ch; text-align: center;
    text-shadow: 0 0 18px color-mix(in srgb, var(--accent) 35%, transparent);
  }
  .acrostic .word {
    color: var(--text);
    font-size: 1.15rem;
    letter-spacing: .02em;
    opacity: .9;
  }
  .cms-deadpan {
    margin: .6rem 0 0 0;
    padding-left: calc(1.4ch + 1rem);   /* aligns under the words, not the letters */
    font-family: "Inconsolata", ui-monospace, monospace;
    color: var(--text);
    opacity: .45;                        /* deadpan: deliberately quiet */
    letter-spacing: .35em;
    font-size: .95rem;
  }
  .tagline {
    margin: 1.6rem 0 0;
    padding-left: calc(1.4ch + 1rem);
    color: var(--text);
    opacity: .7;
    font-style: italic;
    font-size: 1rem;
  }

  /* PROOF cards */
  .proof {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 1rem;
    margin: 0 0 3rem;
  }
  .proof .card {
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 1.25rem 1.35rem;
    background: color-mix(in srgb, var(--bg-deep, #060810) 55%, transparent);
    opacity: 0;                          /* GSAP reveals on scroll */
    transform: translateY(14px);
  }
  .proof .card h2 {
    margin: 0 0 .5rem;
    font-family: 'Rajdhani', sans-serif;
    color: var(--accent);
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: .05em;
    text-transform: uppercase;
  }
  .proof .card p {
    margin: 0;
    color: var(--text);
    opacity: .82;
    line-height: 1.6;
    font-size: .94rem;
  }
  .proof .card code {
    background: var(--bg-deep, #060810);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: .03rem .3rem;
    font-size: .85em;
    color: var(--accent);
  }

  /* POSTURE */
  .posture {
    border-left: 2px solid var(--accent);
    padding: .2rem 0 .2rem 1.25rem;
    opacity: 0;                          /* GSAP */
  }
  .posture p {
    margin: 0;
    color: var(--text);
    opacity: .85;
    line-height: 1.7;
    font-size: 1rem;
    max-width: 56ch;
  }

  /* Swatch row */
  .swatch-row {
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
    margin: 2rem 0 .8rem;
  }
  .swatch-btn {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .35rem .75rem .35rem .5rem;
    background: color-mix(in srgb, var(--bg-deep) 60%, var(--border) 40%);
    border: 1px solid var(--border);
    border-radius: 2rem;
    color: var(--text);
    font-size: .78rem;
    letter-spacing: .03em;
    cursor: pointer;
  }
  .swatch-btn:hover {
    border-color: var(--accent);
    background: color-mix(in srgb, var(--bg-deep) 80%, var(--accent) 20%);
  }
  .swatch-btn.swatch-active {
    border-color: var(--accent);
    background: color-mix(in srgb, var(--bg-deep) 75%, var(--accent) 25%);
  }
  .swatch-chip {
    display: inline-block;
    width: .85rem;
    height: .85rem;
    border-radius: 50%;
    flex-shrink: 0;
  }
  .swatch-btn.swatch-active .swatch-chip {
    box-shadow: 0 0 0 2px var(--bg-deep), 0 0 0 3.5px var(--accent);
  }
  .swatch-name { line-height: 1; }
  .swatch-hint {
    font-size: .72rem;
    color: var(--text);
    opacity: .42;
    margin: 0 0 0 .1rem;
  }

  @media (max-width: 560px) {
    .page-content.about { padding: 1.5rem 1.25rem 4rem; }
    .acrostic .letter { font-size: 2rem; }
  }

  /* Respect reduced-motion: if GSAP is absent OR user opts out, show everything. */
  @media (prefers-reduced-motion: reduce) {
    .acrostic li, .proof .card, .posture { opacity: 1 !important; transform: none !important; }
  }
</style>

<!-- Progressive enhancement: load GSAP only if present in /assets/.
     If it fails to load, the no-JS fallback below reveals everything. -->
<script src="<?= htmlspecialchars($_papyrBasePath ?? '', ENT_QUOTES, 'UTF-8') ?>/assets/gsap/gsap.min.js"></script>
<script src="<?= htmlspecialchars($_papyrBasePath ?? '', ENT_QUOTES, 'UTF-8') ?>/assets/gsap/ScrollTrigger.min.js"></script>
<script>
(function () {
  var reduce = window.matchMedia &&
               window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Fallback: if GSAP didn't load, or motion is reduced, just show content.
  if (typeof window.gsap === 'undefined' || reduce) {
    document.querySelectorAll('.acrostic li, .proof .card, .posture')
      .forEach(function (el) { el.style.opacity = 1; el.style.transform = 'none'; });
    return;
  }

  var gsap = window.gsap;

  // 1. Acrostic assembles on load — each line slides its word in.
  gsap.to('.acrostic li', {
    opacity: 1, x: 0,
    duration: .5, ease: 'power2.out',
    stagger: .12, delay: .15
  });

  // 2. Deadpan C.M.S. + tagline fade after the letters land (handled by CSS opacity start).
  gsap.fromTo(['.cms-deadpan', '.tagline'],
    { opacity: 0, y: 6 },
    { opacity: function(i){ return i === 0 ? .45 : .7; }, y: 0,
      duration: .5, ease: 'power1.out', delay: .9, stagger: .12 });

  // 3. Proof cards reveal on scroll if ScrollTrigger is available; otherwise on load.
  if (gsap.core && window.ScrollTrigger) {
    gsap.registerPlugin(window.ScrollTrigger);
    gsap.utils.toArray('.proof .card').forEach(function (card, i) {
      gsap.to(card, {
        opacity: 1, y: 0, duration: .5, ease: 'power2.out',
        scrollTrigger: { trigger: card, start: 'top 88%' },
        delay: i * .06
      });
    });
    gsap.to('.posture', {
      opacity: 1, duration: .6, ease: 'power1.out',
      scrollTrigger: { trigger: '.posture', start: 'top 90%' }
    });
  } else {
    gsap.to('.proof .card', { opacity: 1, y: 0, duration: .5, stagger: .08, delay: 1.2 });
    gsap.to('.posture', { opacity: 1, duration: .6, delay: 1.5 });
  }
})();
</script>
<script>
/* Swatch preview — ephemeral. No POST, no file write, no auth required.
   Refresh restores the saved theme. The visitor plays; the theme stays. */
(function () {
  'use strict';
  var root = document.documentElement;
  var btns = document.querySelectorAll('.swatch-btn');
  btns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tokens;
      try { tokens = JSON.parse(btn.dataset.swatch); } catch (e) { return; }
      for (var key in tokens) {
        if (Object.prototype.hasOwnProperty.call(tokens, key)) {
          root.style.setProperty('--' + key, tokens[key]);
        }
      }
      btns.forEach(function (b) { b.classList.remove('swatch-active'); });
      btn.classList.add('swatch-active');
      if (typeof window.papyrSyncSliders === 'function') window.papyrSyncSliders();
    });
  });
}());
</script>
</body>
</html>