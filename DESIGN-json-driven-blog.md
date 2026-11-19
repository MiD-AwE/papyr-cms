# DESIGN — JSON-Driven Blog Engine

**Status:** Draft / captured for review · **Date:** 2026-09-02
**Author:** Chuck (MiD-AwE) · **Context:** Adapting the n01z album-player pattern into a blog

> This is a captured design sketch, not yet an accepted ADR. Review with fresh eyes
> before implementing. Known issues from the first draft are flagged in §6.

---

## 0. One-paragraph summary

The n01z.mid-awe.com album player already solves the blog problem: a single dynamic
shell that ingests flat-file JSON and renders it, instead of hand-built static pages.
Reusing that pattern for a blog bypasses the layout-fatigue cycle that kills most
blogs — you drop a JSON file into a folder and walk away. The system is two pages: an
index feed and an individual entry viewer.

---

## 1. Core architecture (two pages)

- **`blog.php` (index / feed)** — reads a master index JSON listing posts by date,
  topic, slug, and excerpt, then loops to generate a catalog or masonry grid. Reads
  only the lightweight index, never parses every post file on load.
- **`entry.php` (post viewer)** — the direct equivalent of `player.php`. Grabs a query
  parameter (`?p=my-first-post`), loads the matching
  `assets/data/posts/{slug}.json`, and populates the layout dynamically.

---

## 2. Data structure — `assets/data/posts/{slug}.json`

Instead of tracks, lyrics, and liner notes, a post JSON holds metadata, tags, palette,
and content blocks.

```json
{
  "slug": "chasing-the-tail",
  "title": "Chasing the Tail: Overcoming Layout Paralysis",
  "date": "2026-09-02",
  "topic": "Workflow",
  "excerpt": "Why building a custom dynamic shell beats fighting static templates every time.",
  "palette": {
    "accent-h": 38,  "accent-s": "92%", "accent-l": "58%",
    "accent2-h": 214, "accent2-s": "60%", "accent2-l": "48%",
    "bg-h": 230,      "bg-s": "24%",      "bg-l": "8%",
    "text-h": 40,     "text-s": "20%",    "text-l": "92%"
  },
  "content": [
    { "type": "p", "text": "It always starts with wanting to write, getting bogged down in CSS grids, and abandoning the project before a single word is published." },
    { "type": "heading", "text": "The Flat-File Solution" },
    { "type": "p", "text": "By treating blog entries just like albums, you drop a JSON file into a folder and walk away." }
  ]
}
```

The `palette` block reuses the same HSL-component token model as the n01z template and
the PapyrCMS theme panel — per-post theming for free.

---

## 3. Master index — `assets/data/blog-index.json`

`blog.php` reads this lightweight array to populate the feed without opening every post
file:

```json
[
  {
    "slug": "chasing-the-tail",
    "title": "Chasing the Tail: Overcoming Layout Paralysis",
    "date": "2026-09-02",
    "topic": "Workflow",
    "excerpt": "Why building a custom dynamic shell beats fighting static templates."
  }
]
```

---

## 4. Reference implementation — `entry.php`

Drops into the current ecosystem style: same slug-validation, palette injection, and
asset-management patterns as the n01z player. **See §6 for corrections applied to the
first draft.**

```php
<?php
/* ============================================================
   entry.php — single consolidated blog shell
   ------------------------------------------------------------
   URL:   entry.php?p=chasing-the-tail
   Data:  assets/data/posts/{slug}.json
   ============================================================ */
$slug = isset($_GET['p']) ? strtolower(trim($_GET['p'])) : 'welcome';

// Corrected regex (closing delimiter) + basename hardening (see §6)
if (!preg_match('/^[a-z0-9_-]+$/', $slug)) { $slug = 'welcome'; }
$slug = basename($slug); // belt-and-suspenders against path traversal

$dataFile = __DIR__ . '/assets/data/posts/' . $slug . '.json';
if (!is_file($dataFile)) {
  http_response_code(404);
  $slug = 'welcome';
  $dataFile = __DIR__ . '/assets/data/posts/welcome.json';
}
$raw   = file_get_contents($dataFile);
$POST  = json_decode($raw, true);
if (!$POST) { http_response_code(500); die('Post data could not be parsed.'); }
$P = isset($POST['palette']) ? $POST['palette'] : [];
function pal($P, $k, $d) { return isset($P[$k]) ? $P[$k] : $d; }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= h($POST['title']) ?> — Blog</title>

  <style id="album-palette">
    :root {
      --accent-h:  <?= h(pal($P,'accent-h',38))  ?>; --accent-s:  <?= h(pal($P,'accent-s','92%'))  ?>; --accent-l:  <?= h(pal($P,'accent-l','58%'))  ?>;
      --accent2-h: <?= h(pal($P,'accent2-h',214)) ?>; --accent2-s: <?= h(pal($P,'accent2-s','60%')) ?>; --accent2-l: <?= h(pal($P,'accent2-l','48%')) ?>;
      --bg-h:      <?= h(pal($P,'bg-h',230))      ?>; --bg-s:      <?= h(pal($P,'bg-s','24%'))      ?>; --bg-l:      <?= h(pal($P,'bg-l','8%'))       ?>;
      --text-h:    <?= h(pal($P,'text-h',40))     ?>; --text-s:    <?= h(pal($P,'text-s','20%'))    ?>; --text-l:    <?= h(pal($P,'text-l','92%'))    ?>;
    }
  </style>
  <style>
    :root {
      --accent:  hsl(var(--accent-h) var(--accent-s) var(--accent-l));
      --accent2: hsl(var(--accent2-h) var(--accent2-s) var(--accent2-l));
      --bg:      hsl(var(--bg-h) var(--bg-s) var(--bg-l));
      --text:    hsl(var(--text-h) var(--text-s) var(--text-l));
    }
    body { background: var(--bg); color: var(--text); font-family: monospace; max-width: 720px; margin: 4rem auto; padding: 0 2rem; line-height: 1.6; }
    h1 { color: var(--accent); font-size: 2rem; margin-bottom: 0.5rem; }
    .meta { font-size: 0.75rem; color: var(--accent2); margin-bottom: 2rem; text-transform: uppercase; letter-spacing: 0.2em; }
    p { margin-bottom: 1.2rem; }
    a { color: var(--accent); }
  </style>
</head>
<body>
  <p><a href="blog.php">← Back to Index</a></p>
  <div class="meta"><?= h($POST['date']) ?> // [<?= h($POST['topic']) ?>]</div>
  <h1><?= h($POST['title']) ?></h1>

  <article>
    <?php foreach ($POST['content'] as $block): ?>
      <?php if ($block['type'] === 'p'): ?>
        <p><?= h($block['text']) ?></p>
      <?php elseif ($block['type'] === 'heading'): ?>
        <h3 style="color:var(--accent2);margin:1.5rem 0 .5rem;"><?= h($block['text']) ?></h3>
      <?php endif; ?>
    <?php endforeach; ?>
  </article>
</body>
</html>
```

---

## 5. Design goals

1. **Zero UI maintenance** — one shell, every post inherits it.
2. **Unified styling** — same HSL token model as the rest of the ecosystem; per-post
   palette overrides.
3. **Speed** — feed reads only the lightweight index; a post reads exactly one JSON file.

---

## 6. Review notes / corrections (before implementing)

- **Regex delimiter fixed.** First draft had `'/^[a-z0-9_-]+$'` — missing the closing
  `/`. Corrected to `'/^[a-z0-9_-]+$/'`. This check guards the slug against path
  traversal, so it must actually compile and run.
- **Added `basename($slug)`** on the resolved slug as a second line of defense,
  consistent with `guardPath()` discipline in CLAUDE.md.
- **Consider content-block types beyond `p` / `heading`** — images (link out to
  `assets/images/`, not embedded), code blocks, blockquotes, lists. Keep the renderer's
  `foreach` switch small and explicit; unknown types render nothing rather than raw data.
- **Admin UI assets: embed-vs-link toggle.** For engine chrome (dashboard icons,
  template skeleton previews) only an authenticated admin sees inside `_papyr/`, base64
  inlining sidesteps path-resolution and local-CORS headaches on drop-in installs —
  the template folder stays a self-contained portable bundle. Standard content images
  still point out to `assets/images/` where they belong. This is the same
  public-content / engine-UI boundary drawn everywhere else in the system.

## 7. Demo

Current demo: http://mid-awe.com/blog/
