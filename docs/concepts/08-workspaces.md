---
title: "Workspaces"
slug: workspaces
section: concepts
order: 8
summary: "Running several sites on one install: what a workspace is and what it separates."
---

Thallo can run several independent sites from one install, one code base and one database. Each
site is a **workspace**: its own content, its own settings, its own members. This page says what a
workspace owns, what the install keeps in common, how a request finds the right workspace, and
what turning workspaces on costs you.

## A single site needs none of this

A new install is one site, and none of this applies to it. **Multi-tenancy** (`thallo.tenancy`) is
off, no content table carries a workspace column, and the admin has no **Workspaces** group in its
sidebar. Read [capabilities and packs](06-capabilities.md) for how that switch relates to the
others, and stop there if one site is all you need.

Everything below describes an install that has been through the enablement flow.

## What a workspace is

A workspace is a tenant: one site among several, sharing the install's PHP, its extensions and its
PostgreSQL database with the others. It is not a separate deployment, a separate database or a
separate copy of Thallo. Every row of a workspace-owned table carries the workspace's id, and
every query Thallo makes on your behalf is narrowed to the workspace the request resolved to.

A workspace has a slug and a name, and a status: **active**, **suspended**, **provisioning** or
**deleted**. Suspending one stops it serving without touching its data. **Workspaces › All
workspaces** lists them with those badges and the buttons that move between the states.

## What a workspace owns, and what the install shares

The split is not a matter of taste; it is a list in the code.

A workspace owns:

- **Content.** Entries, their drafts, versions, publications, routes, redirects, references and
  scheduled actions.
- **The content model.** Content types, block types, regions and style classes — so two workspaces
  on one install can model entirely different things.
- **The site's look and settings.** The `settings` rows, which include the chosen theme, the
  appearance values and the homepage. Each workspace picks its own [theme](04-themes.md) from the
  themes on disk.
- **Everything built on content.** Navigation menus, per-entry SEO meta, the search index, the
  analytics facts, the review workflow's state, form submissions, and templates stored in the
  database.
- **Media.** Uploaded assets and their metadata and usage records.
- **Its own roles.** Custom roles and permission overrides, described below.
- **Its cache.** Cached renders are kept under the workspace's own prefix, so one workspace can
  never be served another's page.

The install shares:

- **User accounts.** One person has one account and can be a member of several workspaces.
- **The code.** Packs, extensions and the themes on disk are the same files for everybody.
- **The capability switchboard.** `capability.<id>.enabled` is an install-wide row: switching
  **Search** off switches it off for every workspace.
- **A handful of install-wide settings** that have to be readable before a request knows its
  workspace: whether Thallo is installed, the scheduler and webhook switches, the admin's URL, the
  update notice, and the payment gateway credentials.
- **One merchant account.** Every workspace settles through the install's single gateway account.
  See [known limitations](../limitations.md).
- **API keys.** A key belongs to the install and is bound to one workspace.

## How a request finds its workspace

A public request is matched by its host, in two steps. First Thallo looks for a **domain**: a host
added to a workspace and verified, on a workspace that is active. If none matches, it takes the
left-most label of the host as a **subdomain** of the configured base domain — for base domain
`example.com`, a request to `acme.example.com` looks for the workspace `acme`. A host that matches
neither resolves to no workspace and is not served.

The admin does not go by host. It names the workspace it is working in on every request, with the
`X-Tenant-Id` header, and the server checks that you are a member of the workspace you asked for.
When you belong to more than one, a **Workspace** picker appears at the bottom of the sidebar and
switching it re-points the whole admin.

Adding a host is a proof of ownership, not a claim. **Workspaces › Domains** takes a host and
answers with a **TXT name** and a **TXT value**; you publish that record in your DNS and press
**Verify record**. Verified hosts are re-checked hourly by the `domain_reverification_sweep` job,
so a host whose record disappears eventually stops resolving and the admin marks it **Not
resolving**. That job runs on the scheduler's cron line, which
[the scheduler and the queue](../operations/03-scheduler-and-queues.md) sets up.

Host routing is optional and separate from workspaces themselves. Until it is activated, the
install serves exactly one workspace, and creating a second one is refused.

## Members and roles

Membership is per workspace. Adding someone in **Workspaces › Members** gives an existing account
a role in that workspace only; removing them there does not touch their account or their other
workspaces.

Four roles are built in:

| Role | What it reaches |
|---|---|
| `owner` | Everything `admin` reaches, plus members, domains, roles and billing. |
| `admin` | Content and its routes, the content model, navigation, SEO, templates, style classes, data collections, commerce, analytics, the review queue, and publishing without a review. |
| `member` | View, create and edit content. |
| `viewer` | View content. |

`owner` is the anti-lockout role: it is always assignable and can never be disabled. The other
three can be retired by a workspace that does not want them, and a workspace can define custom
roles of its own in **Workspaces › Roles**, granting each a chosen set of permissions from the
same catalogue. See the [permissions reference](../reference/06-permissions.md) for the catalogue
itself.

Membership decides access, and it decides it first. Someone with no membership is not in the
workspace, whatever they hold elsewhere. The one way past that is a platform operator: an account
holding `tenancy.access_any` can turn on **Operate as platform administrator** and act in a
workspace it does not belong to. Each escalation that is granted is written to the audit log.

## Turning workspaces on is a change, not a switch

Enabling workspaces rewrites the schema: it adds a workspace column to every table that holds a
site's content, model or settings, widens their unique constraints, and adopts everything already
there into a first workspace, which you name as part of the flow. It is staged and resumable —
**Settings › Workspaces**, or the same steps from the command line — and it pauses in the middle
for a restart. Writes are blocked while the schema changes. The generic
extension toggle refuses to do it: `php glueful extensions:enable glueful/tenancy` answers that
workspace enforcement is managed by the enablement flow.

Two things stop it before it starts. Thallo refuses to enable workspaces while any data collection
is defined, and it refuses on a cache driver that cannot purge by pattern.

There is a way back. Disabling turns scoping off and leaves the widened schema in place, so
nothing is lost and turning it on again is quick. Disabling requires exactly one workspace, so a
multi-workspace install cannot simply reverse out of it.

Deleting a workspace is two steps as well. **Move to trash** stops it resolving at once and holds
its data and its hosts in reserve; restoring it is possible for 30 days. **Purge** — typing the
slug to confirm — removes the content, media, memberships and domains for good, as a queued job.
A host released by a purge or by removing a domain is held in a 30-day cooldown before anyone else
may claim it.

## Where to go next

[Turn on workspaces](../operations/07-multi-site.md) has the stages, the commands, what each one
changes and how to undo it. Read it before you start, not during.
