# glueful/thallo-seo

An **SEO** capability pack for [Thallo](https://thallo.dev): **sitemaps**, **per-entry SEO
meta**, and **robots.txt**. Packaged as a capability pack that depends only on the framework and
`glueful/thallo-contracts` — never on `glueful/thallo` (the application).

The pack itself emits **descriptors and feeds**, not HTML: JSON meta for the `<head>`, plus
crawler-standard `sitemap.xml` / `robots.txt`. Canonical/hreflang are **not** here — they ship on
the core delivery `seo` object (`GET /v1/content/{type}/{slug}`). Both kinds of consumer are
served: on a rendered site, core's `EngineSeoHeadProvider` composes this pack's meta with the
canonical/hreflang data and `thallo-render` prints it through the `seo_head()` Twig function; a
headless frontend fetches the meta endpoint and composes the two itself.

## What it provides

- **Per-entry SEO meta** — `GET /v1/seo/meta/{type}/{slug}?locale=` returns
  `{ title, description, og:{…}, twitter:{card}, robots }`, resolved **override → per-type fallback
  field → site default** (with `title_template` applied to field-derived titles; an explicit override
  is verbatim). Public; published content only.
- **Meta overrides (admin)** — `GET`/`PUT /v1/admin/seo/meta/{entryUuid}?locale=` behind `auth` +
  `content_permission:seo.manage`, backed by the `seo_meta` table (`(entry_uuid, locale)` unique).
  The entry editor's SEO panel edits them.
- **Sitemaps** — `GET /sitemap.xml` is **adaptive**: a single `<urlset>` at or below 50 000 URLs, a
  `<sitemapindex>` listing page files above it; `GET /sitemap/{n}.xml` serves each page (with
  `<lastmod>` + `xhtml:link` hreflang alternates). Rendered XML is cached and dropped on any content
  lifecycle change.
- **robots.txt** — `GET /robots.txt` from `config('seo.robots')` groups, with the
  `Sitemap:` line appended from the site origin.

## Content access & the boundary

The pack never touches routing, `PathRenderer`, or a DB table for published content — it reaches it
only through `glueful/thallo-contracts`:

- `ContentDeliveryReader::findPublished()` — the entry row for meta fallback.
- `ContentDeliveryReader::enumeratePublishedForSitemap()` — one page of published URLs, where the App
  impl (`EngineContentDeliveryReader`) returns **ready-made absolute** `href`/`alternates`; the pack
  only serializes. All URL/routing knowledge stays in App.
- `ContentTypeReader::findUuidBySlug()` — resolves the meta endpoint's `{type}` slug.
- `ContentLifecycleEvent` — the pure event the pack listens to for sitemap cache invalidation.

The repo's `composer boundaries` check enforces this (no `Thallo\Core\` references in `src/` or
`routes/`).

## The capability

The provider registers one capability in `boot()`:

```php
new Capability('thallo.seo', label: 'SEO', description: 'Sitemaps, per-entry SEO meta, and robots.txt.');
```

- **Enabled by default.** An operator turns it off or on in the admin under **Extensions ›
  Capabilities**. The switch is stored system-wide and overrides the deploy-time
  `thallo.capabilities` config map.
- **Gated end-to-end.** When disabled, the meta, sitemap, robots, and admin routes are never
  registered (`404`) and the cache-invalidation listener is not wired. Migrations run on install (not
  enable), so disabling preserves `seo_meta`.
- **Permission.** The pack declares `seo.manage`; the host app grants it to `administrator` in its own
  dependent migration.

## Configuration

The pack's own config merges under `seo` (`config/seo.php`):

- `fallbacks` — per-type-slug map `{ title_field, description_field, image_field }`.
- `defaults` — `default_og_image` (`SEO_DEFAULT_OG_IMAGE`) and `title_template`
  (`SEO_TITLE_TEMPLATE`, default `"{title} — {site_name}"`). `{site_name}` is the Site name from
  Settings › General, read at request time.
- `robots` — list of `{ user_agent, allow: [...], disallow: [...] }` groups.

The absolute origin for the feeds is resolved per request. `config('thallo.seo.public_url_base')`
(env `PUBLIC_URL_BASE`) wins when set; otherwise it is the site's canonical public origin from
`CanonicalPublicOriginResolver` (`BASE_URL`, or the workspace's own origin). A localhost or
non-http(s) origin counts as unset. **The feeds require an origin:** `/sitemap.xml`,
`/sitemap/{n}.xml`, and `/robots.txt` return **`409 Conflict`** (plain text) without one, rather
than emitting crawler-invalid relative URLs. Meta is unaffected (it carries no absolute URLs).

## Install

The pack ships with Thallo: `glueful/thallo-core` requires it at the same version and the project's
`config/serviceproviders.php` loads its provider, so there is nothing to install or enable per pack.
`php glueful migrate:run` creates `seo_meta` and declares the `seo.manage` permission with the rest
of the schema.

Set `BASE_URL` to the public site origin (e.g. `https://example.com`) so the feeds emit absolute
URLs; set `PUBLIC_URL_BASE` only to override it. Optionally set the `SEO_DEFAULT_OG_IMAGE` /
`SEO_TITLE_TEMPLATE` defaults.

## Headless frontends

A frontend that does not use `thallo-render` wires these up itself:

- Fetch `/v1/seo/meta/{type}/{slug}` per page and inject the fields into `<head>` (compose
  canonical/hreflang from the core delivery `seo` object).
- Reverse-proxy `/sitemap.xml`, `/sitemap/*.xml`, and `/robots.txt` to the site root.

Switching the capability off (Extensions › Capabilities) removes every SEO route. `seo_meta`
stays on disk.

## Out of scope (deferred)

**JSON-LD / structured data** is a fast-follow — it expands the modeling surface (schema-type
selection, field mapping, breadcrumbs, validation) and deserves its own pass. Redirects, canonical,
and hreflang stay with core delivery.

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-seo/`.
