# PapyrCMS — Swatch/Panel Dialect Unification + Commit-Gate Stopgap
**Source:** Design-alignment session, week of June 25, 2026
**Two items.** Item 1 is safe — ship now. Item 2 is URGENT — it closes an empirically-confirmed open write hole on the live public site.

---

# ITEM 1 — Swatch/Panel dialect unification (SHIP NOW, safe)

## The bug (confirmed on papyrcms.mid-awe.com)
Selecting a hero swatch breaks the theme panel's background slider. After a swatch is applied, the background no longer responds to the panel's background slider, though the nav still responds. Root cause: **the swatch system and the theme panel speak two different token dialects for the same surfaces.**

- The **theme panel** (`templates/partials/theme-panel.php`) works in **HSL component triplets**: `--bg-h`, `--bg-s`, `--bg-l`, and paints the background as `hsl(var(--bg-h) var(--bg-s) var(--bg-l))`. Same for `--accent-h/s/l`, `--text-h/s/l`, etc.
- The **swatches** previously wrote **flat hex** into `--bg-deep`, `--accent`, `--text`, `--border`.

So a swatch set `--bg-deep` (hex), but the panel's slider drives `--bg-h/s/l`. Two different variables, same visual surface → after a swatch, the slider moves vars that nothing is painting from. The nav kept responding because it still read the triplet vars.

## The fix — make swatches speak the panel's dialect
Swatches now store **HSL triplets** (`--{name}-h/s/l`), the exact vars the panel drives. A swatch becomes a *preset position for the sliders*. No hex→HSL translation, no dual representation to drift. A swatch is, conceptually and now literally, a savestate of the slider positions.

### 1a. Replace the five swatch JSON files
New files are provided alongside this doc in `swatches/` (midnight, crimson-thread, verdant, amber-glass, paper). Each now looks like:

```json
{
  "name": "Verdant",
  "tokens": {
    "accent-h": "149", "accent-s": "57%", "accent-l": "43%",
    "text-h": "140",   "text-s": "29%",   "text-l": "92%",
    "bg-h": "147",     "bg-s": "43%",     "bg-l": "4%",
    "border-h": "145", "border-s": "43%", "border-l": "13%"
  }
}
```

Drop these into `data/swatches/`, replacing the old hex versions. Note `bg-deep` → `bg-h/s/l`: the swatch now drives the **same background triplet the panel reads**, which is the actual fix.

### 1b. Swatch apply loop — unchanged, plus one line
The existing apply loop already iterates whatever keys are in the JSON, so it works as-is with triplets:

```js
for (var key in tokens) {
  if (Object.prototype.hasOwnProperty.call(tokens, key)) {
    root.style.setProperty('--' + key, tokens[key]);
  }
}
```

**Add after the loop:** re-sync the panel sliders so they catch up to the swatch's new values. Do NOT call `buildGroups()` — that rebuilds the slider DOM, a sledgehammer. Use the existing scalpel: `syncSlidersFromRoot()`.

### 1c. Expose `syncSlidersFromRoot()` so the swatch script can call it
Currently `syncSlidersFromRoot()` lives inside the theme-panel IIFE and isn't reachable from the hero swatch script. Expose it minimally, matching how the panel already exposes `toggleMode`, `resetTheme`, etc. on `window`:

```js
window.papyrSyncSliders = syncSlidersFromRoot;
```

Then in the swatch click handler, after the apply loop:

```js
if (typeof window.papyrSyncSliders === 'function') window.papyrSyncSliders();
```

Guarded so the swatch row still works on pages that don't include the theme panel (the home page may or may not load the panel).

## Ephemerality is preserved (and is what makes this safe)
The swatch apply still writes only to `:root` in the browser. No POST, no file, no auth. Refresh restores the saved theme. Per-session fiddling means worst case a user makes *their own view* ugly — "self-sabotage no one else sees." That's the wall doing its job. Confirm nothing in this item introduces a write.

---

# ITEM 2 — Commit-gate stopgap (URGENT — closes a live public hole)

## The confirmed liability
On papyrcms.mid-awe.com, **anyone** can open the theme panel, move sliders, press **Commit**, and the change writes to the server and appears **across all devices, immediately.** This was tested and reproduced. `api/save-theme.php` is an **unauthenticated write endpoint open to the world.** This is the auth-ADR concern, now empirically verified as exploitable:
- A stranger can deface the live hosted theme.
- It's a file-writing endpoint accepting unauthenticated POSTs — treat as a probe surface.

The swatch *preview* is safe (never writes). The panel's *Commit* is the open door.

## The distinction that makes the fix clean
There are two theming tiers, and the line between them already exists architecturally:
- **User tier (ephemeral):** open panel, move sliders, click swatches — all `:root`-only, session-scoped, no write. Safe for anyone. Keep it fully open.
- **Admin tier (persistent):** **Commit** — writes `data/themes/{pageKey}.json`, changes what everyone sees. Must be gated.

We are NOT removing the user's ability to fiddle. We are gating only the one action that crosses into "everyone sees this": Commit.

## Stopgap (do the lightest one that's real, until the full auth ADR lands)
Pick based on how soon full auth lands. Any of these closes the hole today:

1. **Preferred if quick:** require an admin session/flag for the `save-theme.php` POST handler to act. If absent → return 403, write nothing. This is the real fix in miniature and forward-compatible with the auth ADR.
2. **Shared-secret check:** `save-theme.php` requires a secret (header or token) that only the admin context supplies. Crude but closes public write.
3. **Hide the trigger + guard the endpoint:** only render the panel's **Commit** button when an admin flag is present, AND still guard the endpoint server-side (never trust the hidden button alone — the endpoint is the real boundary).
4. **Last resort:** don't include the theme-panel partial on the public site until auth lands. Loses the live-theming demo publicly but is instant and total.

**Server-side is the boundary.** Hiding the Commit button is not sufficient alone — the endpoint must refuse unauthenticated writes, because the POST can be sent directly. Whatever option, `save-theme.php` itself must reject the write when the caller isn't admin.

## Scope
This is a stopgap, not the auth system. The full auth gate (covering `index.php` POST, `api/ai.php`, `api/save-theme.php`, and the `/_papyr/` admin entry point) remains the next ADR. This just closes the one door we've confirmed is standing open on the public box. Capture the user/admin theming-tier model in the auth ADR (see staging note).

---

# Sequence
1. Item 1 (swatch unify) — ship now, safe, fixes the visible background bug.
2. Item 2 (commit gate) — ship now, urgent, closes the public write hole.
3. Full auth ADR — next, with the theming-tier model folded in.
