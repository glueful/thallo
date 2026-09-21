---
title: "What Thallo is"
slug: introduction
section: getting-started
order: 1
summary: "What Thallo is, who it is for, and what a site built with it is made of."
---

Thallo is a self-hosted CMS you install with Composer: one package that brings an admin at
`/admin`, a visual page builder, a theme layer of Twig templates that renders the site, and a
JSON API over the same content. It is built for developers making content-rich sites and
storefronts, on the Glueful PHP framework, with a Vue 3 admin. This page says what a Thallo site
is made of and what is switched on the day you install it.

## Pages and JSON come from the same entries

Thallo is hybrid: one entry is both a rendered page and an API resource. Nothing is copied
between the two and nothing is exported. The published version of an entry is read once, and the
theme's templates turn it into HTML at `/{type}/{slug}` while the delivery API returns the same
shape as JSON at `/v1/content/{type}/{slug}`. The SEO object the API carries is the object the
rendered page's head is built from. A content type can be **mounted at root**, and the starter
**Pages** type is: its entries live at `/{slug}`.

One switch governs both surfaces. A content type has a **Public delivery** setting
(**Settings › Content Types**). With it on, anyone may read the type's published entries, as a
page and as JSON. With it off, the API answers only a request carrying an API key with
`read:content` or `read:content:{type}`, and the rendered path is a 404 — there is no anonymous
back door through the theme.

Rendered delivery is itself a capability. Switch it off and the install is purely headless:
public paths fall through to the router's standard JSON 404, and the API is the only way out.

## What a site is made of

- **Content types, entries and fields.** A content type is the model an entry follows. A new
  install is seeded with three: **Pages**, **Posts** and **Categories**.
- **Blocks and block types.** A page's body is an ordered list of blocks stored as data, never
  as HTML. See [blocks and block types](../concepts/02-blocks.md).
- **The Design view.** The visual page builder: the stage shows the real page, rendered by the
  theme, and every edit is an operation on the page's data. See
  [the Design view](../concepts/03-design-view.md).
- **A theme.** `themes/{name}/` with `theme.json`, Twig templates and assets. The site's owner
  changes colours, typefaces and logos under **Site › Appearance** without editing a template.
  See [themes](../concepts/04-themes.md).
- **Regions and menus.** The header and the footer are edited under **Site › Header & footer**;
  menus are data, served to the theme and over the API.

## What is switched on when you install

A fresh install has the CMS active: content and rendered delivery, data collections, navigation,
SEO, analytics, the approval workflow, storefront accounts, content importers and workspace
subscriptions billing, alongside the framework extensions for media, languages, users and roles,
the audit log and import/export.

Three things ship installed but not switched on:

- **Content search** is off. Turn it on under **Settings › General**, then build the index.
- **Commerce** and **Payvia** (payments) are installed and disabled. Enable them from
  **Extensions**, then run the migrations they bring.
- **Meilisearch** is installed and disabled. Content search does not need it: it runs on the
  PostgreSQL database the site already has.

Workspaces — several sites on one install — are not a plain toggle. They are turned on by their
own flow under **Settings › Workspaces**, and the generic extension switch refuses the tenancy
provider by design.

Switching a capability off does not uninstall it. The code and its tables stay; its routes,
menus and blocks go. **Extensions › Capabilities** is where the switches are, and
[capabilities and packs](../concepts/06-capabilities.md) explains what each one costs to run.

## A Developer Preview

Thallo is is in a Developer Preview. The packaging, the documentation and the defaults are young, and
the boundaries are deliberate rather than accidental: one merchant account per installation,
placed orders that cannot be edited, PostgreSQL as the only tested database. Read
[known limitations](../limitations.md) before you build on it, and before you take real money.

## Next

[Install Thallo](02-install.md), then build a page.
