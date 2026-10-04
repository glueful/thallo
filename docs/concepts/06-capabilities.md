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
| **Collections** (`thallo.collections`) | Your own backend: define tables in the admin and each gets an instant REST API, with filters, relations and per-operation access rules. | Off on a new install |
| **Commerce** (`thallo.commerce`) | Adopts `glueful/commerce` and links Commerce products to Thallo entries. | Off |
| **Content importers** (`thallo.importers`) | CSV, Markdown and WordPress content/user import adapters. | On |
| **Navigation** (`thallo.navigation`) | Menu trees served headless and to themes. | On |
| **Payments** (`thallo.payments`) | Online payments for orders and plans, through the gateways in **Settings › Payments**. | Off |
| **Rendered delivery** (`thallo.render`) | Server-rendered pages from published content via filesystem Twig themes. | On |
| **Search** (`thallo.search`) | Public, delivery-parity content search, over PostgreSQL or Meilisearch. | Off |
| **SEO** (`thallo.seo`) | Sitemaps, per-entry SEO meta, and robots.txt. | On |
| **Subscriptions** (`thallo.subscriptions`) | Workspace SaaS billing: platform plans and per-workspace subscriptions. | Off on a new install |
| **Multi-tenancy** (`thallo.tenancy`) | Tenant-owned content model + data, scoping, seed/sync and enablement. | Off |
| **Approval workflow** (`thallo.workflow`) | Single-stage editorial review over draft/publish. | On |

Search is off by a deliberate default in Thallo's own configuration. Commerce, Subscriptions and
Payments are off until you turn them on: each one prepares its engine first (see
[off until its activation finishes](#off-until-its-activation-finishes)). Multi-tenancy is off
because its engine is a framework extension a fresh install leaves disabled. Collections is
switched off by the first-run setup: most sites need no data API of their own.

An installed extension can add capabilities of its own to this list; see
[who declares a capability](#who-declares-a-capability).

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

Go to **Extensions › Capabilities**. It lists every capability, and each one is switched the way
its declaration says it is managed:

- **A plain switch** (most capabilities) with a badge: **On**, **Off**, or
  **Requested · engine unavailable**, which means you asked for it but its engine cannot back it.
  A flip saves immediately and takes effect on the next request, so reload the admin after
  switching something.
- **An activation** (Commerce, Subscriptions, Payments, and any an extension declares): one action
  that prepares everything the capability needs, then switches it on. See
  [turning on a capability with an activation](#turning-on-a-capability-with-an-activation).
- **A flow of its own**: the card links to the page that runs it. Multi-tenancy links to
  **Settings › Workspaces**, which has its own staged flow.

A card that reads **Misconfigured** has no switch: two declarations of it conflict. See
[troubleshooting](../operations/05-troubleshooting.md#a-capability-is-misconfigured).

Reading and changing this list needs the `system.access` permission; without it the page shows
"Operator access required".

From a shell, `php glueful thallo:capabilities` prints the same list, and `--enable=ID` or
`--disable=ID` flips one under the same rules. It refuses to turn an activation capability on:
use `php glueful thallo:capabilities:enable` for an activation capability. Long-running workers keep the old state until
they restart. See the [command reference](../reference/01-cli.md#thallocapabilities).

**Content search** also appears in **Settings › General**. It is not a second switch: both write
the same `thallo.search` state.

## When a capability depends on an engine

Six capabilities name an owning engine — the framework extension that does the work. Accounts
needs `glueful/users`, Content importers `glueful/import-export`, Commerce `glueful/commerce`,
Subscriptions `glueful/subscriptions`, Payments `glueful/payvia` and Multi-tenancy
`glueful/tenancy`.

Such a capability is on only when it is both requested and backed: the engine must be installed,
enabled, and have its schema migrated. If any of that is missing, the row says so and names what
fixes it.

For an activation capability you never enable the engine yourself: turning the feature on does
it. If the engine is disabled behind Thallo's back while the feature is on, Extensions shows the
feature as unavailable, and turning it on again runs a normal activation.

## Who declares a capability

Every capability is declared by the package that contributes it, together with how it is managed:
a plain switch, an activation, or a flow of its own. Thallo's packs declare theirs in their service
providers; Payments is declared by Thallo's core. An extension declares its own in its
`composer.json`, under `extra.thallo.capabilities`, which Thallo reads while the extension is still
disabled — so it appears here before anything is enabled. An activation's engine is the package
that declares it, and that package is managed by the capability from then on.
[Package a feature as an extension](../guides/22-make-an-extension.md) shows a whole one.

## Off until its activation finishes

A capability with an activation is on only when its activation has finished. Enabling its engine
some other way doesn't turn it on, and neither does `true` in `config/thallo.php`: the capability
reads off, and its blocks stay hidden, until it is turned on in Extensions or with
`thallo:capabilities:enable`.

An upgraded site keeps what it had. `php glueful thallo:provision`, run after an update, keeps
Commerce and Subscriptions on where they were on (unless `config/thallo.php` switched them off),
and keeps Payments on where Payvia was enabled and ready. It decides once, from the database as it
was before that provision's migrations ran. Until provision runs, they read off.

## Turning on a capability with an activation

Switch the feature on in **Extensions › Capabilities** and confirm. The card shows one "Turning on Commerce…"
state while Thallo works through these steps, each safe to run again:

1. **Prepare.** The feature is marked as preparing, which keeps it off until the end.
2. **Enable the engine.** Its tables are migrated, then it is added to `config/extensions.php` and
   the extension cache. Skipped when the engine is already enabled and ready.
3. **Check the engine in a fresh request.** Its provider must be loaded and its schema ready. The
   admin sends that request itself; you don't reload anything.
4. **Add its blocks**, in every workspace when workspaces are on.
5. **Grant its permissions** to the superuser and administrator roles. A permission you revoked
   stays revoked.
6. **Switch it on.**

When it finishes, the card says what was added, for example "Commerce is on. Added 18 blocks and
granted 7 new permissions.", and the sidebar shows the new section.

If a step fails, the card names the step and the error, and **Retry** resumes from that step. The
feature stays off until a retry succeeds. Closing the browser doesn't lose anything: the next
visit to Extensions offers **Continue** and **Cancel**. Turning the feature off while it is being
turned on cancels the activation.

Turning a feature off keeps its data: Commerce's products and orders, and your content, are kept,
and turning it on again skips the steps that are already done.

Payments has no blocks or permissions of its own, so its activation enables Payvia and switches it
on. [Take online payments](../guides/21-payments.md) says what turning it off stops and keeps.

**Hosts whose application files are read-only.** Only step 2 writes application files
(`config/extensions.php` and `bootstrap/cache/`). On a host where those are read-only at runtime,
Extensions shows the command to run at deploy time instead of a switch:

```bash
$ php glueful thallo:capabilities:enable thallo.commerce --prepare
```

It stops after the engine step. Finish on the running site in Extensions, or with
`php glueful thallo:capabilities:resume thallo.commerce`. A feature whose engine is already enabled
turns on without writing any application file.

From a shell, `php glueful thallo:capabilities:enable thallo.commerce` runs the whole activation,
continuing in a fresh process for the engine check, and `php glueful thallo:capabilities:status` shows
where each feature stands. `php glueful thallo:provision` resumes any activation left unfinished.
See the [command reference](../reference/01-cli.md#thallocapabilitiesenable) and
[troubleshooting](../operations/05-troubleshooting.md#a-feature-did-not-finish-turning-on).

## What switching one on can ask for

- **A migration.** An engine's tables have to exist before the capability it backs can be on.
  Turning on an activation capability migrates its engine; for another extension,
  `php glueful extensions:enable <package>` migrates its schema first. If a schema is pending or
  divergent the row tells you and names `php glueful migrate:run` or `php glueful migrate:verify`.
- **A config value.** Search picks its engine from `SEARCH_ENGINE` — `auto`, `postgres` or
  `meilisearch`. `auto` uses Meilisearch when `MEILISEARCH_HOST` is set and the site's own
  PostgreSQL otherwise, so search needs nothing else installed.
- **The scheduler.** Switching Search on asks for the index to be built; the scheduler and the
  queue worker build it and keep it in step, and **Settings › Search** shows its progress.
- **A cron line.** Analytics keeps raw facts for `ANALYTICS_RETENTION_DAYS` days (90 by default)
  and `php glueful analytics:prune` deletes the rest. No job in `config/schedule.php` runs it, so
  schedule it yourself alongside [the scheduler](../operations/03-scheduler-and-queues.md).

## Extensions are the other kind of package

Thallo's packs are libraries: always loaded, never separately enabled, governed only by their
capability switch. A **Glueful extension** is a different thing — a Composer package that
Composer discovers and that does nothing until its provider is listed in `config/extensions.php`.

An install ships with eight enabled: `aegis`, `audit`, `email-notification`, `i18n`,
`import-export`, `media`, `subscriptions` and `users`. Three ship installed and disabled:
`commerce`, `payvia` (enabled by turning on Payments) and `meilisearch`.

**Extensions › Installed** lists what Composer found, with each one's version, provider,
schema state, and who manages it:

- **Required by Thallo:** `glueful/aegis` and `glueful/users`, plus any listed in
  `required_packages` in `config/thallo.php`. They have no switch, and
  `php glueful thallo:provision` puts one back if it was removed from the enabled list.
- **Managed by a feature:** `glueful/commerce`, `glueful/subscriptions` and `glueful/payvia` are
  enabled when their feature turns on, and an extension's own package when the capability it
  declares turns on. Turning the feature off hides it and leaves the engine enabled, with its
  tables and data. `glueful/tenancy` is managed by **Settings › Workspaces**.
- **Misconfigured:** a package that conflicting capability declarations claim. It has no switch
  until the declarations are fixed; see
  [troubleshooting](../operations/05-troubleshooting.md#a-capability-is-misconfigured).
- **Everything else** has an **Enable** or **Disable** button.

Every generic enable and disable (the admin, the API and `extensions:enable` / `extensions:disable`)
refuses a required or managed package and names the right place instead. To add an extension,
`composer require` it. From a shell:

```bash
$ php glueful extensions:list
$ php glueful extensions:enable glueful/meilisearch
```

Enabling migrates the extension's schema first. `php glueful extensions:disable` reverses it, and
preserves the schema and the data. See [workspaces](08-workspaces.md) and
[turn on workspaces](../operations/07-multi-site.md) for Multi-tenancy's own flow.

An operator can protect another package, or change the reason shown, with an entry under
`protected` in `config/extensions.php`; an entry there wins over Thallo's.

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
**Extensions › Capabilities**, the stored state answers and this file no longer decides. A
capability with an activation never turns on from this file.

## Where to go next

The guides switch individual capabilities on and use them:
[add search to the site](../guides/11-search.md), [sell products](../guides/18-commerce.md),
[let visitors sign up and sign in](../guides/17-accounts.md),
[take online payments](../guides/21-payments.md).
