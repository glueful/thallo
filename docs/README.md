---
title: "About these docs"
publish: false
---

# Thallo's documentation

These files are Thallo's documentation. They are published at <https://thallo.dev/docs> by
Thallo's own Markdown import, described in [documentation-sites.md](documentation-sites.md), and
they read as they are on GitHub.

| Folder | Holds |
|---|---|
| `getting-started/` | First contact: install, a first page, a first content type. |
| `concepts/` | How Thallo thinks: the ideas behind the screens. |
| `guides/` | How to do one job, start to finish. |
| `reference/` | Things you look up: commands, settings, template functions, blocks. |
| `operations/` | Running a live site: deploys, cron, queues, backups, upgrades. |

Four pages sit at the top of this folder because other files link to them by path:
[production.md](production.md), [upgrading.md](upgrading.md), [limitations.md](limitations.md)
and [documentation-sites.md](documentation-sites.md). Their front matter puts each in its
section.

`openapi.json` and `index.html` are the generated API reference, not pages.

**Writing a page.** In Thallo's repository, `docs/internal/docs-writing/GUIDE.md` is the contract
for a page and `PAGES.md` beside it lists every page, what it must cover and where its facts
are. `internal/` is not documentation: it is never imported and is not part of a release.

This file is marked `publish: false`, so the import passes over it.
