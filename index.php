<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PapyrCMS — Your Website. Your Files. Your Control.</title>
  <meta name="description" content="A flat-file PHP CMS that drops into your existing site and stays out of your way. No database, no build step. The CMS adapts to your website — not the other way around." />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inconsolata:wght@400;500;700&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php
/* Find nav.php by walking up — works at any directory depth. */
for ($_d = __DIR__; !file_exists($_d . '/nav.php') && dirname($_d) !== $_d; $_d = dirname($_d));
if (file_exists($_d . '/nav.php')) { require_once $_d . '/nav.php'; }
unset($_d);
$__base = isset($_papyrBasePath) ? htmlspecialchars($_papyrBasePath, ENT_QUOTES, 'UTF-8') : '';
?>

<main class="pk">

  <!-- ═══════════ BAND 1 — HERO ═══════════ -->
  <section class="pk-hero">
    <div class="pk-hero-inner">

      <div class="pk-hero-copy">
        <p class="pk-logo">
          <span class="pk-logo-papyr">Papyr</span><span class="pk-logo-cms">CMS</span>
        </p>
        <p class="pk-logo-sub">A paper-thin PHP CMS</p>

        <h1 class="pk-headline">
          <span>Your website.</span>
          <span>Your files.</span>
          <span class="pk-accent">Your control.</span>
        </h1>

        <p class="pk-lede">
          A flat-file PHP CMS that drops into your existing site and stays out of your way.
        </p>

        <ul class="pk-pills">
          <li>
            <span class="pk-pill-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>
            </span>
            <span class="pk-pill-t"><strong>Drop it in</strong>Works alongside your existing PHP/HTML site.</span>
          </li>
          <li>
            <span class="pk-pill-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18"><ellipse cx="12" cy="6" rx="7" ry="3" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/></svg>
            </span>
            <span class="pk-pill-t"><strong>No database</strong>Your filesystem remains the source of truth.</span>
          </li>
          <li>
            <span class="pk-pill-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M8 8l-4 4 4 4m8-8l4 4-4 4"/></svg>
            </span>
            <span class="pk-pill-t"><strong>No build step</strong>PHP runs where PHP already runs.</span>
          </li>
          <li>
            <span class="pk-pill-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18"><rect x="5" y="11" width="14" height="9" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" d="M8 11V8a4 4 0 018 0v3"/></svg>
            </span>
            <span class="pk-pill-t"><strong>You stay in control</strong>Edit your files. Use your workflow. Your way.</span>
          </li>
        </ul>
      </div>

      <div class="pk-hero-art">
        <img src="<?= $__base ?>/assets/images/hero-laptop-bird.webp"
             alt="The PapyrCMS admin dashboard on a laptop, beside the PapyrCMS origami bird"
             width="1536" height="1024" loading="eager" decoding="async" />
      </div>

    </div>
  </section>

  <!-- ═══════════ BAND 2 — ARCHITECTURE ═══════════ -->
  <section class="pk-arch">
    <h2 class="pk-arch-title">
      PapyrCMS architecture: the CMS adapts to your website — not the other way around.
    </h2>

    <div class="pk-arch-flow">

      <div class="pk-arch-card">
        <div class="pk-arch-head pk-head-a">Your existing website</div>
        <div class="pk-arch-body">
          <ul class="pk-tree">
            <li class="pk-dir">/ your-site</li>
            <li class="pk-dir pk-in">assets/</li>
            <li class="pk-dir pk-in">includes/</li>
            <li class="pk-dir pk-in">images/</li>
            <li class="pk-file pk-in">index.php</li>
            <li class="pk-file pk-in">about.php</li>
            <li class="pk-file pk-in">services.php</li>
            <li class="pk-file pk-in">contact.php</li>
            <li class="pk-more pk-in">…</li>
          </ul>
          <p class="pk-arch-note">Your PHP/HTML site. Your structure. Your workflow.</p>
        </div>
      </div>

      <div class="pk-arch-arrow" aria-hidden="true">
        <span class="pk-arrow-label">Add<br>PapyrCMS</span>
        <svg viewBox="0 0 40 16" width="40" height="16"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M2 8h34m0 0l-6-5m6 5l-6 5"/></svg>
      </div>

      <div class="pk-arch-card pk-arch-engine">
        <div class="pk-arch-head pk-head-b">PapyrCMS engine <span>(self-contained)</span></div>
        <div class="pk-arch-body">
          <ul class="pk-tree">
            <li class="pk-dir">_papyr/</li>
            <li class="pk-dir pk-in">admin/</li>
            <li class="pk-dir pk-in">core/</li>
            <li class="pk-dir pk-in">includes/</li>
            <li class="pk-dir pk-in">assets/</li>
            <li class="pk-file pk-in">bootstrap.php</li>
            <li class="pk-file pk-in">config.php</li>
            <li class="pk-file pk-in">functions.php</li>
          </ul>
          <p class="pk-arch-note">The engine lives in its own sealed directory.</p>
        </div>
      </div>

      <div class="pk-arch-arrow" aria-hidden="true">
        <span class="pk-arrow-label">Site<br>enhanced,<br>not taken<br>over</span>
        <svg viewBox="0 0 40 16" width="40" height="16"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M2 8h34m0 0l-6-5m6 5l-6 5"/></svg>
      </div>

      <div class="pk-arch-card">
        <div class="pk-arch-head pk-head-c">Your website remains yours</div>
        <div class="pk-arch-body">
          <ul class="pk-tree">
            <li class="pk-dir">/ your-site</li>
            <li class="pk-dir pk-in">assets/</li>
            <li class="pk-dir pk-in">includes/</li>
            <li class="pk-dir pk-in">images/</li>
            <li class="pk-file pk-in">index.php</li>
            <li class="pk-file pk-in">about.php</li>
            <li class="pk-file pk-in">services.php</li>
            <li class="pk-file pk-in">contact.php</li>
          </ul>
          <p class="pk-arch-note pk-intact"><strong>Intact.</strong> Remove PapyrCMS and your site is unchanged.</p>
        </div>
      </div>

    </div>
    <p class="pk-arch-foot">Remove <code>_papyr/</code> and your site continues to run exactly as it always has.</p>
  </section>

  <!-- ═══════════ BAND 3 — FEATURE ROW ═══════════ -->
  <section class="pk-feats">
    <ul class="pk-feat-row">
      <li>
        <span class="pk-feat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22"><ellipse cx="12" cy="6" rx="7" ry="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path fill="none" stroke="currentColor" stroke-width="1.8" d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M9.5 14.5l1.8 1.8 3.5-3.8"/></svg>
        </span>
        <span class="pk-feat-t"><strong>No database</strong>Flat-file. Simple. Portable.</span>
      </li>
      <li>
        <span class="pk-feat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M8 8l-4 4 4 4m8-8l4 4-4 4"/></svg>
        </span>
        <span class="pk-feat-t"><strong>No node runtime</strong>Just PHP.</span>
      </li>
      <li>
        <span class="pk-feat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22"><circle cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M12 4v2.2M12 17.8V20M4 12h2.2M17.8 12H20M6.3 6.3l1.6 1.6M16.1 16.1l1.6 1.6M17.7 6.3l-1.6 1.6M7.9 16.1l-1.6 1.6"/></svg>
        </span>
        <span class="pk-feat-t"><strong>No build step</strong>Deploy anywhere PHP runs.</span>
      </li>
      <li>
        <span class="pk-feat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 20l4-1L19 8a2 2 0 00-3-3L5 16l-1 4z"/></svg>
        </span>
        <span class="pk-feat-t"><strong>Manual edits OK</strong>Your files. Your workflow.</span>
      </li>
      <li>
        <span class="pk-feat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M9 6a3 3 0 116 0m-6 0a3 3 0 00-3 3m3-3v12m6-12a3 3 0 013 3m-3-3v12m-6 0a3 3 0 006 0M6 9a3 3 0 000 6m12-6a3 3 0 010 6"/></svg>
        </span>
        <span class="pk-feat-t"><strong>AI is optional</strong>Use it when you want.</span>
      </li>
    </ul>
  </section>

</main>

<style>
  /* ══════════════════════════════════════════════════════════════
     papyrcms.mid-awe.com home page.
     Faithful live rebuild of the brand marketing graphic.
     Themeable: colors derive from the engine's tokens.css (teal
     identity). Falls back to the graphic's literal colors if a token
     isn't present, so it renders correctly even before tokens load.
     ══════════════════════════════════════════════════════════════ */

  :root {
    /* Fallbacks mirror the marketing graphic's palette; tokens override. */
    --pk-accent:  var(--accent, #2bb8d6);      /* teal/cyan */
    --pk-orange:  #e0672a;                       /* origami orange (brand art, not themed) */
    --pk-ink:     var(--bg-deep, #0a0e14);
    --pk-text:    var(--text, #e8edf2);
    --pk-dim:     var(--text-dim, #9fb0bd);
    --pk-border:  var(--border, rgba(120,150,170,.22));
    --pk-panel:   color-mix(in srgb, var(--pk-ink) 78%, #1f1e1c 4%);

    /* ── Light-band palette (bands 2 & 3) — DERIVED from the theme hue ──
       The architecture + feature bands stay LIGHT (the graphic's rhythm),
       but their light surfaces and dark text are computed from the theme's
       background hue, so they hue-sync with the active swatch instead of
       being frozen gray. Teal theme → cool-white panels; warm swatch →
       warm-white panels. Fallback hue 205 (the engine's default) keeps it
       correct before tokens load.
       We read --bg-h/s if present; otherwise a neutral cool hue. */
    --pk-lh: var(--bg-h, 205);                  /* light-band hue = theme bg hue */
    --pk-ls: var(--bg-s, 18%);                  /* light-band saturation */

    /* Surfaces: very high lightness, low saturation tint of the theme hue */
    --pk-band-bg:    hsl(var(--pk-lh) calc(var(--pk-ls) * .5) 96%);  /* section bg */
    --pk-card-bg:    hsl(var(--pk-lh) calc(var(--pk-ls) * .4) 99%);  /* card face */
    --pk-band-alt:   hsl(var(--pk-lh) calc(var(--pk-ls) * .5) 93%);  /* feature strip bg */
    --pk-band-line:  hsl(var(--pk-lh) var(--pk-ls) 88%);             /* borders */
    /* Text: low-lightness tint of the same hue (reads near-black, warm/cool per theme) */
    --pk-band-ink:   hsl(var(--pk-lh) calc(var(--pk-ls) * .8) 16%);  /* headings */
    --pk-band-text:  hsl(var(--pk-lh) calc(var(--pk-ls) * .6) 36%);  /* body/notes */
    --pk-band-faint: hsl(var(--pk-lh) calc(var(--pk-ls) * .5) 60%);  /* the "…" / muted */
    --pk-code-bg:    hsl(var(--pk-lh) var(--pk-ls) 91%);             /* inline code chip */
  }

  .pk { color: var(--pk-text); }
  .pk * { box-sizing: border-box; }

  /* ── BAND 1 HERO ───────────────────────────── */
  .pk-hero {
    background: var(--pk-ink);
    padding: clamp(2rem, 5vw, 4.5rem) clamp(1.25rem, 5vw, 4rem) clamp(2.5rem, 5vw, 4rem);
    overflow: hidden;
  }
  .pk-hero-inner {
    max-width: 1240px; margin: 0 auto;
    display: grid;
    grid-template-columns: 1.05fr 1fr;
    gap: clamp(1.5rem, 4vw, 3.5rem);
    align-items: center;
  }
  .pk-logo {
    font-family: 'Rajdhani', sans-serif;
    font-weight: 700; font-size: clamp(1.8rem, 3.4vw, 2.6rem);
    letter-spacing: -.01em; margin: 0; line-height: 1;
  }
  .pk-logo-papyr { color: var(--pk-orange); }
  .pk-logo-cms   { color: var(--pk-accent); }
  .pk-logo-sub {
    font-family: 'Inconsolata', monospace;
    text-transform: uppercase; letter-spacing: .28em;
    font-size: .68rem; color: var(--pk-dim);
    margin: .35rem 0 clamp(1.4rem, 3vw, 2.2rem);
  }
  .pk-headline {
    font-family: 'Rajdhani', sans-serif;
    font-weight: 700; line-height: .98;
    font-size: clamp(2.4rem, 6.2vw, 4.4rem);
    letter-spacing: -.015em;
    margin: 0 0 1.1rem;
    text-transform: uppercase;
  }
  .pk-headline span { display: block; color: var(--pk-text); }
  .pk-headline .pk-accent { color: var(--pk-accent); }
  .pk-lede {
    font-family: 'Rajdhani', sans-serif;
    font-size: clamp(1rem, 1.6vw, 1.18rem);
    color: var(--pk-dim); max-width: 32ch;
    line-height: 1.5; margin: 0 0 clamp(1.6rem, 3vw, 2.2rem);
  }
  .pk-pills {
    list-style: none; margin: 0; padding: 0;
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 1.1rem;
  }
  .pk-pills li { display: flex; flex-direction: column; gap: .5rem; }
  .pk-pill-icon { color: var(--pk-accent); }
  .pk-pill-t {
    font-family: 'Rajdhani', sans-serif;
    font-size: .78rem; line-height: 1.35; color: var(--pk-dim);
    display: block;
  }
  .pk-pill-t strong {
    display: block; color: var(--pk-text);
    font-weight: 700; letter-spacing: .02em;
    text-transform: uppercase; font-size: .72rem;
    margin-bottom: .25rem;
  }
  .pk-hero-art { display: flex; justify-content: center; align-items: center; }
  .pk-hero-art img {
    width: 100%; max-width: 640px; height: auto;
    filter: drop-shadow(0 30px 60px rgba(0,0,0,.55));
  }

  /* ── BAND 2 ARCHITECTURE ───────────────────── */
  .pk-arch {
    background: var(--pk-band-bg);
    color: var(--pk-band-ink);
    padding: clamp(2.5rem, 5vw, 4rem) clamp(1.25rem, 5vw, 4rem);
  }
  .pk-arch-title {
    font-family: 'Rajdhani', sans-serif;
    text-align: center; font-weight: 600;
    font-size: clamp(1rem, 2vw, 1.35rem);
    letter-spacing: .01em; line-height: 1.4;
    max-width: 60rem; margin: 0 auto clamp(2rem, 4vw, 3rem);
    color: var(--pk-band-ink);
  }
  .pk-arch-flow {
    max-width: 1120px; margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr auto 1fr auto 1fr;
    gap: 1rem; align-items: stretch;
  }
  .pk-arch-card {
    background: var(--pk-card-bg); border: 1px solid var(--pk-band-line); border-radius: 10px;
    overflow: hidden; display: flex; flex-direction: column;
    box-shadow: 0 1px 3px rgba(20,30,45,.06);
  }
  .pk-arch-head {
    font-family: 'Rajdhani', sans-serif;
    font-weight: 700; text-transform: uppercase; letter-spacing: .1em;
    font-size: .74rem; color: #fff; text-align: center;
    padding: .7rem .8rem;
  }
  .pk-arch-head span { font-weight: 500; text-transform: none; letter-spacing: .02em; opacity: .85; }
  .pk-head-a { background: hsl(var(--pk-lh) calc(var(--pk-ls) * 1.1) 28%); } /* slate, theme-hued */
  .pk-head-b { background: var(--pk-orange); }
  .pk-head-c { background: var(--pk-accent); }
  .pk-arch-body { padding: 1.1rem 1.2rem 1.2rem; flex-grow: 1; display: flex; flex-direction: column; }
  .pk-tree {
    list-style: none; margin: 0 0 1rem; padding: 0;
    font-family: 'Inconsolata', monospace;
    font-size: .82rem; line-height: 1.7; color: var(--pk-band-text);
  }
  .pk-tree .pk-in { padding-left: 1.2rem; }
  .pk-tree .pk-dir { color: var(--pk-band-ink); }
  .pk-tree .pk-dir::before  { content: "📁 "; }
  .pk-tree .pk-file::before { content: "📄 "; }
  .pk-tree .pk-more { color: var(--pk-band-faint); }
  .pk-arch-note {
    font-family: 'Rajdhani', sans-serif;
    font-size: .82rem; color: var(--pk-band-text); line-height: 1.45;
    margin: auto 0 0; padding-top: .5rem;
  }
  .pk-intact strong { color: var(--pk-accent); }
  .pk-arch-arrow {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: .5rem; color: var(--pk-orange);
  }
  .pk-arrow-label {
    font-family: 'Rajdhani', sans-serif; font-weight: 700;
    text-transform: uppercase; letter-spacing: .08em;
    font-size: .64rem; text-align: center; color: var(--pk-orange);
    line-height: 1.25;
  }
  .pk-arch-foot {
    text-align: center; font-family: 'Rajdhani', sans-serif;
    color: var(--pk-band-text); font-size: .95rem; margin: clamp(1.5rem,3vw,2.2rem) auto 0;
  }
  .pk-arch-foot code {
    font-family: 'Inconsolata', monospace; font-size: .9em;
    background: var(--pk-code-bg); padding: .05rem .35rem; border-radius: 3px; color: var(--pk-band-ink);
  }

  /* ── BAND 3 FEATURE ROW ────────────────────── */
  .pk-feats {
    background: var(--pk-band-alt);
    padding: clamp(1.5rem, 3vw, 2.2rem) clamp(1.25rem, 5vw, 4rem);
    border-top: 1px solid var(--pk-band-line);
  }
  .pk-feat-row {
    max-width: 1120px; margin: 0 auto; list-style: none; padding: 0;
    display: grid; grid-template-columns: repeat(5, 1fr); gap: 1.5rem;
  }
  .pk-feat-row li { display: flex; align-items: center; gap: .7rem; }
  .pk-feat-icon {
    color: var(--pk-accent); flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
  }
  .pk-feat-t { font-family: 'Rajdhani', sans-serif; font-size: .82rem; color: var(--pk-band-text); line-height: 1.3; }
  .pk-feat-t strong { display: block; color: var(--pk-band-ink); font-weight: 700; font-size: .85rem; }

  /* ── RESPONSIVE ────────────────────────────── */
  @media (max-width: 900px) {
    .pk-hero-inner { grid-template-columns: 1fr; }
    .pk-hero-art { order: -1; }               /* art on top when stacked */
    .pk-hero-art img { max-width: 460px; }
    .pk-pills { grid-template-columns: repeat(2, 1fr); }
    .pk-arch-flow { grid-template-columns: 1fr; }
    .pk-arch-arrow { transform: rotate(90deg); padding: .4rem 0; }
    .pk-feat-row { grid-template-columns: repeat(2, 1fr); gap: 1.1rem; }
  }
  @media (max-width: 480px) {
    .pk-pills { grid-template-columns: 1fr; }
    .pk-feat-row { grid-template-columns: 1fr; }
  }
</style>
</body>
</html>