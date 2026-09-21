---
title: "Capabilities and packs"
slug: capabilities
section: concepts
order: 6
summary: "How Thallo's features are packaged, and what switching one on or off does."
---

Thallo arrives as one Composer package, but most of the features it brings have a switch. This
page names them, says what a switch does to a running site, and separates Thallo's own packs from
the Glueful framework extensions beside them.

## A capability is a switch, a pack is a package

A **capability** is a feature with a switch: search, navigation menus, the approval workflow.
Each one has an id — `thallo.search`, `thallo.navigation` — and the id is what the admin, the
configuration and the code all use to name it.

A **pack** is the Composer package a capability ships in. Thallo's packs are named
`glueful/thallo-*` and every one of them is required by `glueful/thallo-core`, so a new project
already has them all on disk. You do not install a pack to get its feature; you switch its
capability on.

## The capabilities an install has

| Capability | What it covers | Default |
|---|---|---|
| **Accounts** (`thallo.accounts`) | Registration, sign-in and account pages for visitors of the site. | On |
| **Analytics** (`thallo.analytics`) | Product-analytics fact store fed by lifecycle events. | On |
| **Data collections** (`thallo.collections`) | Developer-defined data collections with a public CRUD/query API. | On |
| **Commerce** (`thallo.commerce`) | Adopts `glueful/commerce` and links Commerce products to Thallo entries. | Off |
| **Content importers** (`thallo.importers`) | CSV, Markdown and WordPress content/user import adapters. | On |
| **Navigation** (`thallo.navigation`) | Menu trees served headless and to themes. | On |
| **Rendered delivery** (`thallo.render`) | Server-rendered pages from published content via filesystem Twig themes. | On |
| **Search** (`thallo.search`) | Public, delivery-parity content search, over PostgreSQL or Meilisearch. | Off |
| **SEO** (`thallo.seo`) | Sitemaps, per-entry SEO meta, and robots.txt. | On |
| **Subscriptions** (`thallo.subscriptions`) | Workspace SaaS billing: platform plans and per-workspace subscriptions. | On |
| **Multi-tenancy** (`thallo.tenancy`) | Tenant-owned content model + data, scoping, seed/sync and enablement. | Off |
| **Approval workflow** (`thallo.workflow`) | Single-stage editorial review over draft/publish. | On |

Search is off by a deliberate default in Thallo's own configuration. Commerce and Multi-tenancy
are off because each depends on a framework extension that a fresh install leaves disabled.

## Off means inactive, not uninstalled

A capability that is off is still installed. Its code is loaded and its tables stay: a pack's
migrations run when the pack is installed, not when its capability is switched on. Nothing is
dropped, and switching back needs no rebuild. What goes away is the surface:

- **Routes.** They are never registered, so a request to one gets the router's standard JSON 404,
  not a handler that answers "disabled". The gate is in the backend, not only in the admin: a
  direct API call to a Thallo importer fails closed while **Content importers** is off.
  Switching **Rendered delivery** off is the extreme case — every public path falls through to
  that 404 and the install is purely headless.
- **Menus.** The admin sections a capability owns disappear from the sidebar — **Review queue**
  with the approval workflow, **Collections** with data collections, **Commerce** with commerce.
- **Block types.** A block type that belongs to a capability is hidden from
  **Settings › Block Types** while it is off, and its row is never deleted. It reappears, with
  the blocks already placed on pages, when the capability comes back.
- **Commands.** `php glueful search:reindex` exists only while **Search** is on.

## Where the switches are

Go to **Extensions › Capabilities**. Each row shows the capability's name, its id, its
description, the engine it depends on if it has one, and a badge: **On**, **Off**, or
**Requested · engine unavailable**, which means you asked for it but its engine cannot back it.

Reading and changing this list needs the `system.access` permission; without it the tab shows
"Operator access required". A flip saves immediately and takes effect on the next request, so
reload the admin after switching something.

**Content search** also appears in **Settings › General**. It is not a second switch: both write
the same `thallo.search` state.

## When a capability depends on an engine

Five capabilities name an owning engine — the framework extension that does the work. Accounts
needs `glueful/users`, Content importers `glueful/import-export`, Commerce `glueful/commerce`,
Subscriptions `glueful/subscriptions` and Multi-tenancy `glueful/tenancy`.

Such a capability is on only when it is both requested and backed: the engine must be installed,
enabled, and have its schema migrated. If any of that is missing, the row says so, names the
command that fixes it, and refuses to turn on until you have run it.

A capability nobody has ever touched follows its engine. That is why Commerce reads plainly
**Off** on a fresh install rather than "on, engine unavailable": enabling the engine is the
opt-in.

## What switching one on can ask for

- **A migration.** An engine's tables have to exist before the capability it backs can be on.
  `php glueful extensions:enable <package>` migrates the extension's schema first; if a schema is
  pending or divergent the capability row tells you and names `php glueful migrate:run` or
  `php glueful migrate:verify`.
- **A config value.** Search picks its engine from `SEARCH_ENGINE` — `auto`, `postgres` or
  `meilisearch`. `auto` uses Meilisearch when `MEILISEARCH_HOST` is set and the site's own
  PostgreSQL otherwise, so search needs nothing else installed.
- **A command.** Switching Search on indexes nothing by itself. Run `php glueful search:reindex`
  to build the index from published content.
- **A cron line.** Analytics keeps raw facts for `ANALYTICS_RETENTION_DAYS` days (90 by default)
  and `php glueful analytics:prune` deletes the rest. No job in `config/schedule.php` runs it, so
  schedule it yourself alongside [the scheduler](../operations/03-scheduler-and-queues.md).

## Extensions are the other kind of package

Thallo's packs are libraries: always loaded, never separately enabled, governed only by their
capability switch. A **Glueful extension** is a different thing — a Composer package that
Composer discovers and that does nothing until its provider is listed in `config/extensions.php`.

An install ships with eight enabled: `aegis`, `audit`, `email-notification`, `i18n`,
`import-export`, `media`, `subscriptions` and `users`. Three ship installed and disabled:
`commerce`, `payvia` (payments) and `meilisearch`.

**Extensions › Installed** lists what Composer found, with each one's version, provider, schema
state and an **Enable** or **Disable** button; **Extensions › Browse** searches the Glueful
catalogue. From a shell:

```bash
$ php glueful extensions:list
$ php glueful extensions:enable glueful/commerce
```

Enabling migrates the extension's schema first. `php glueful extensions:disable` reverses it, and
preserves the schema and the data.

One extension is not yours to toggle. `glueful/tenancy` is marked protected, and every generic
enable and disable — CLI, API and admin — refuses it: workspaces are turned on by their own
staged flow. See [workspaces](08-workspaces.md) and
[turn on workspaces](../operations/07-multi-site.md).

## Setting a default in configuration

The admin's switchboard is stored in the database and wins over everything else. Until a
capability has been switched there, Thallo reads the `capabilities` map in `config/thallo.php`.
That file is optional — Thallo ships its defaults with the package and your `config/` directory
holds only overrides — so create it to pin a default for a deployment:

```php
<?php

return [
    'capabilities' => [
        'thallo.analytics' => false,
    ],
];
```

Keys are full capability ids, with their dots. Once someone flips the same capability in
**Extensions › Capabilities**, the stored state answers and this file no longer decides.

## Where to go next

The guides switch individual capabilities on and use them:
[add search to the site](../guides/11-search.md), [sell products](../guides/18-commerce.md),
[let visitors sign up and sign in](../guides/17-accounts.md).
