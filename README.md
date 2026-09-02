# PapyrCMS

**A paper-thin, flat-file CMS for PHP.** It drops into a website that already exists as ordinary PHP/HTML files and manages it in place — without ever taking ownership of your files, without a database, and without a build step.

> Live demo: **[papyrcms.mid-awe.com](http://papyrcms.mid-awe.com)** &nbsp;·&nbsp; PHP · flat-file · no Node · no database

---

## What makes it different

Most content-management systems own your content — they put it in a database, or in a proprietary structure, and your site only works as long as the CMS does. PapyrCMS is the opposite. **The filesystem is the source of truth.** The CMS reads what's there, writes only when asked, and survives hand-edits gracefully. Remove it entirely and you're left with a fully working plain PHP/HTML site.

The whole engine lives in a single sealed directory — `_papyr/` — that you drop next to your site's existing files. It places no file at your site root. It owns nothing outside itself. Drop it in and the subdomain becomes self-managing; delete it and nothing breaks.

## Core principles

- **The filesystem is always the source of truth.** The manifest is just a cache — delete it and nothing breaks.
- **Manual edits are always safe.** Edit a file outside the CMS and it notices on the next scan and updates gracefully. No conflicts, no "out of sync" states.
- **AI is optional, never required.** The CMS runs fully with no AI provider configured. When enabled, all AI runs through a single bridge with strict local/cloud separation — source code and markup never leave your machine.
- **PHP because it's everywhere.** No build step, no Node runtime. Runs on any shared host. Minimal, Composer-managed dependencies.
- **The engine is sealed, and the boundary is real.** Everything under `_papyr/` is off-limits to the site, enforced identically on the read side (the scanner ignores it) and the write side (the writer refuses to touch it).

## How it works

```
yoursite.com/
├── index.php            ← your homepage (a normal content page)
├── about.php
├── docs/                ← content nests to any depth
│   └── overview.php
├── nav.php              ← the spine: you author it; the CMS never writes it
├── data/themes/         ← per-page theme settings, owned by your site
└── _papyr/              ← the engine: sealed, drop-in, owns nothing outside itself
```

- **Content pages** live at your site root and nest to any depth. Directory structure *is* your navigation structure — the filesystem, all the way to the menu.
- **`nav.php` is the spine.** You hand-author it, and the CMS never overwrites it. It asks the engine for the current page tree (derived live, never written to disk, so it can't drift out of sync) and renders it with your own markup and styling.
- **Theming is built in.** An HSL-token theming panel ships with the engine. Sites opt in simply by deriving their CSS from the global tokens — no configuration flags. Move the sliders and the whole page re-themes; per-page settings save as plain JSON your site owns.

## Status

PapyrCMS is an actively developed solo project by **Chuck Middaugh (MiD-AwE)**, built to be clean enough for a public audience while staying practical for a single developer. The read-side foundation, live nav derivation, sealed-engine boundary, and theming system are in place; page-authoring and admin write-flows are gated behind session auth.

See the architecture decision records in [`/docs`](./docs) for the design reasoning behind each piece.

## License

MIT — see [LICENSE](./LICENSE).

---

*Built with the conviction that you should always own your own site.*
