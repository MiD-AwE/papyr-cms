# PapyrCMS — Swatch System (build now) + Page-Builder (staged)
**Source:** Design-alignment session, week of June 25, 2026
**Two documents in one:** Part 1 is a build-now direction. Part 2 is a staging note for the next ADR — do NOT implement Part 2 now.

---

# PART 1 — Swatch System (BUILD NOW)

## The principle
A swatch is a **named, file-backed set of theme-token values** — the savestate of a theme. This is the same enumerate-from-disk pattern PapyrCMS already uses everywhere: the Scanner enumerates pages, the token registry enumerates tokens, and now the swatch loader enumerates saved themes. **The filesystem is the source of truth.** Nothing about swatches is hardcoded into a page; the engine renders whatever swatch files it finds.

The relationship to remember: **swatches : tokens :: templates : pages.** Both are "enumerate saved configurations, let the user pick one, apply it." We're building the swatch half now; the template half is staged in Part 2.

## Storage
Swatches live as flat JSON files:

```
data/swatches/crimson-thread.json
data/swatches/midnight.json
data/swatches/paper.json
```

Each file is a named set of the four live theme tokens (match the exact token names the page template and theme panel already use — `--accent`, `--text`, `--bg-deep`, `--border`):

```json
{
  "name": "Crimson Thread",
  "tokens": {
    "accent":  "#c4304a",
    "text":    "#e8e6e0",
    "bg-deep": "#0a0608",
    "border":  "#2a1418"
  }
}
```

Adding a swatch = dropping a JSON file in `data/swatches/`. No code change. That IS the reusability.

## Engine side — enumerate + read
Add a small loader, same shape as the existing theme/registry reads:

```php
/**
 * papyr_swatches_enumerate(string $swatchDir): array
 * Returns [ slug => ['name' => ..., 'tokens' => [...]], ... ]
 * Reads every *.json in data/swatches/. Skips unreadable/malformed files
 * (log + continue — a bad swatch file must never break the page).
 */
function papyr_swatches_enumerate(string $swatchDir): array {
    $out = [];
    foreach (glob(rtrim($swatchDir, '/') . '/*.json') as $f) {
        if (!is_readable($f)) continue;
        $data = json_decode(file_get_contents($f), true);
        if (!is_array($data) || empty($data['tokens'])) continue;  // malformed → skip
        $slug = pathinfo($f, PATHINFO_FILENAME);
        $out[$slug] = [
            'name'   => $data['name'] ?? $slug,
            'tokens' => $data['tokens'],
        ];
    }
    ksort($out);
    return $out;
}
```

## Hero swatch row — EPHEMERAL apply (this is the key constraint)
The hero on the home page renders one button per enumerated swatch. Clicking a swatch recolors the **whole page in real time** by writing the token values straight onto `:root` via JS:

```js
document.documentElement.style.setProperty('--accent', tokens.accent);
// ...one per token
```

**Critical: the hero apply is demo-only and writes NOTHING.**
- No POST to `save-theme.php`. No file written. No auth required.
- Refresh restores the saved theme. The visitor gets the *feel* of live theming without changing what anyone else sees.
- This keeps the home page honestly "paper thin" — it demonstrates the token system without *being* the theme system, and it sidesteps the auth question entirely because it never writes.

A stranger should be able to **play** without being able to **change** what everyone else sees. The toy teaches; the tool persists.

## Honest signposting
Include one quiet line near the swatch row, e.g.:
> *Live preview · save and manage themes from the nav panel.*

This is true AND points the dev to the real feature. The hero is the curated demo; persistent theming stays in the authenticated nav theme panel (which reads the **same** `data/swatches/` directory — same source, two privilege levels: read-and-preview here, save-and-apply there).

## Swatch row — design notes
- **Curated swatches, not a freeform picker.** 4–6 presets max. They show *taste*, and they prevent a visitor landing on an ugly color mid-pitch. (We explicitly chose swatches over a hue slider for this reason.)
- Render each as a small button/chip showing the swatch's accent (and maybe a hint of bg), labeled with `name`.
- Mark the currently-active swatch.
- Place it directly under the acrostic in the hero — it's the page's **signature element**. Spend the boldness here; keep proof cards and posture quiet around it.
- Because the acrostic letters, card headings, and posture border all already pull `var(--accent)`, one swatch click cascades across the whole page at once. That cascade IS the demo — one control moves, a dozen things respond.

## Type note (while dressing the hero)
- Keep Inconsolata mono for the acrostic (terminal heritage).
- Bring in Rajdhani for the proof-card headings — technical but warmer, distinct from the mono, already in the n01z stack so it loads clean. Body stays as-is.

## Reduced-motion / no-JS
- Swatch apply is JS-only by nature; with no JS the page simply shows its saved theme (fine — swatches are enhancement, not requirement).
- Don't animate the recolor in a way that fights `prefers-reduced-motion`; an instant or very short transition is correct.

## Scope guard
This whole item is **read-and-apply only**. If any part starts wanting to write a file or gate on auth, stop — that's Part 2 / the auth ADR, not this.

---

# PART 2 — Page-Builder + Templates + Quill (STAGE — do NOT build now)

Captured so it travels to the next design-alignment session. This is next-ADR-sized and touches the write side, the Add Page flow, and possibly auth. Do not bolt it onto the home-page work.

## The vision
Chuck wants to build Twig page templates for many page types — blog posts, documentation, slideshows — not just the single page template currently used. Plus a custom "page-builder" surface for knocking out new pages quickly, possibly with Quill.js for rich-text editing.

## The unifying pattern (same as swatches)
**templates : pages :: swatches : tokens.** The Add Page form should NOT assume one template. It should **enumerate** the `.twig` files in `templates/sites/papyr-cms/` and offer them as a picker — exactly how the swatch row enumerates `data/swatches/`. Add a template type by dropping a `.twig` file. The swatch system built in Part 1 is the proof-of-concept for this; the template picker is the same code shape pointed at a different directory.

## The Quill fork (the real design question)
Quill produces HTML, so it's compatible with the current `{{ body_content|raw }}` blob. BUT Quill is a rich-text editor for *prose*, and the more ambitious templates aren't prose:
- **Prose-shaped templates** (blog, docs, basic page) → Quill as the body editor is an excellent, low-risk win over pasting raw HTML.
- **Structured templates** (slideshow, anything with repeating parts) → want *fields* ("slide 1 title, slide 1 body, slide 2 title…"), not one rich-text blob. That's a different builder.

## Proposed elegant unification (for discussion, not decision)
Let each template **declare what editor it wants.** The Twig header already lists required vars; extend that into a tiny field manifest, e.g.:

```twig
{# papyr:fields title:text, body:rich, slides:repeat #}
```

The Add Page form reads the manifest of the chosen template and builds the matching editor surface: plain input where it says `text`, a Quill box where it says `rich`, a repeater where it says `repeat`. The template defines its own editing surface. Same enumerate-and-adapt pattern, now for *editing* — the form hardcodes nothing, it reads what the template asks for.

## Why staged, not built now
- Touches the write side, the Add Page flow, and likely auth (rich editors + new write paths).
- It's exactly the kind of implicit behavioral decision Chuck defers to dedicated alignment sessions.
- It deserves its own ADR and real design energy, not a home-page bolt-on.

## Open questions for the alignment session
1. Quill for prose templates only, or a single editor that adapts per field-type?
2. Does the field manifest live in the Twig comment header, or a sibling `.json` next to each template?
3. How does the structured/`repeat` editor serialize into something the Twig template renders cleanly?
4. Auth: the page-builder is a write surface — it belongs behind the same auth gate as the other write endpoints. Confirm it's covered by the auth ADR.
5. Does Quill's HTML output need sanitizing before write (it's user-authored HTML landing in a served page)?

## Dependency note
The auth-gating ADR should land before (or alongside) the page-builder, since the builder is inherently a write surface. Sequence: swatches (now) → auth gating (next ADR) → page-builder/templates/Quill (the ADR after, or folded into the auth one if scope allows).
