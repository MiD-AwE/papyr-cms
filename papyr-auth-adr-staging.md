# PapyrCMS — Auth Gating ADR (staging note)
**Source:** Design-alignment sessions, week of June 25, 2026
**Status:** STAGED for the next ADR. This note captures scope + the theming-tier model so nothing is lost. Do not implement piecemeal beyond the confirmed stopgap (see `papyr-swatch-unify-and-commit-gate.md`, Item 2).

---

## Why this is the next ADR
Auth is the single load-bearing pre-launch item — the line between "solid showcase" and "safe to hand to a stranger." One of its concerns has now been **empirically confirmed exploitable** on the live public box (see below), which is why a stopgap is being applied immediately ahead of the full build.

## Endpoints/surfaces the full auth gate must cover
1. `index.php` POST handlers (Add Page, Delete) — write operations.
2. `api/ai.php` — open = Cerebras cost-abuse vector.
3. `api/save-theme.php` — **CONFIRMED open write hole.** Anyone on papyrcms.mid-awe.com can move sliders, press Commit, and change the hosted theme across all devices immediately. Tested and reproduced. A stopgap gate is being applied now; the full gate supersedes it.
4. `/_papyr/index.php` (admin entry point) — currently reachable by anyone who guesses the path. The sealed engine is sealed against the Scanner and PageWriter, NOT against a browser. Admin must sit behind auth.

## The theming-tier model (fold into the ADR)
Theming has two tiers, and the auth gate enforces the line between them. This model emerged from confirming the save-theme hole and is the clean way to think about user vs admin rights generally:

### User tier — ephemeral, OPEN to anyone
- Open the theme panel, move sliders, click hero swatches.
- All changes are `:root`-only, browser-session-scoped, **no server write**.
- Refresh restores the saved theme.
- Worst case: a visitor makes *their own view* ugly — "self-sabotage no one else sees."
- **Keep this fully open.** The ability to fiddle and find a personal flavor is a feature, not a risk, precisely because it never persists or touches other users.

### Admin tier — persistent, GATED
- **Commit** (writes `data/themes/{pageKey}.json`), Add Page, Delete, AI calls, admin dashboard access.
- These change what everyone sees, or cost money, or write files.
- All require an authenticated admin context.

### The principle
The auth gate is not "lock down theming." It's "let everyone preview ephemerally; let only admins persist." The boundary is **the act of writing/persisting**, not the act of adjusting. This generalizes: user-tier = read + ephemeral local state; admin-tier = anything that writes to disk, spends resources, or changes global state.

## Design constraints for the gate
- **Server-side is the boundary.** Hiding a button (e.g. Commit) is UX, not security — every gated endpoint must reject unauthenticated requests directly, because POSTs can be sent without the UI.
- **Drop-in friendly.** PapyrCMS is a paper-thin drop-in; the auth mechanism should not require a database (consistent with the flat-file ethos). Consider flat-file/session-based admin auth rather than a DB-backed user system, unless the ADR decides otherwise.
- **Forward-compatible with the stopgap.** Whatever stopgap closed the save-theme hole (session flag / shared secret) should be supersedable cleanly by the real gate, ideally the same session mechanism scaled up.

## Dependency note
The page-builder / Quill / template-picker work (separate staging note) is inherently a write surface and belongs **behind this auth gate**. Sequence: stopgap (now) → full auth ADR (next) → page-builder ADR (after, or folded in if scope allows).

## Open questions for the ADR session
1. Flat-file/session admin auth vs. something heavier — what fits a drop-in CMS with no DB?
2. Single admin, or multiple roles? (A drop-in single-site CMS may only need one admin tier.)
3. How does admin state get established (login page under `/_papyr/`? a config secret? first-run setup?) — note this ties to the deferred `-firstRun` / setup flow.
4. Does the user tier ever get to *save* anything (e.g. a personal preview to localStorage), or stay purely ephemeral? (Leaning purely ephemeral — simplest, safest.)
5. Rate-limiting / abuse protection on `api/ai.php` beyond auth, given it spends Cerebras credits even for authed users.
