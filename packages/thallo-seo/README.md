# glueful/thallo-seo

A removable **SEO** capability pack for [Thallo](https://thallo.dev): **sitemaps**, **per-entry SEO
meta**, and **robots.txt**. Packaged as a capability pack that depends only on the framework and
`glueful/thallo-contracts` — never on `glueful/thallo` (the application).

Thallo is headless, so this pack emits **descriptors and feeds the frontend consumes**, not
server-rendered HTML: JSON meta for the `<head>`, plus crawler-standard `sitemap.xml` / `robots.txt`.
Canonical/hreflang are **not** here — they already ship on the core delivery `seo` object
(`GET /v1/content/{type}/{slug}`); the frontend composes both.

## What it provides

- **Per-entry SEO meta** — `GET /v1/seo/meta/{type}/{slug}?locale=` returns
  `{ title, description, og:{…}, twitter:{card}, robots }`, resolved **override → per-type fallback
  field → site default** (with `title_template` applied to field-derived titles; an explicit override
  is verbatim). Public; published content only.
- **Meta overrides (admin)** — `GET`/`PUT /v1/admin/seo/meta/{entryUuid}?locale=` behind `auth` +
  `content_permission:seo.manage`, backed by the `seo_meta` table (`(entry_uuid, locale)` unique).
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

The repo's `composer boundaries` check enforces this (no `App\` references in `src/`).

## The capability

The provider registers one capability in `boot()`:

```php
new Capability('thallo.seo', label: 'SEO', description: 'Sitemaps, per-entry SEO meta, and robots.txt.');
```

- **Enabled by default.** Disable it by setting `'thallo.seo' => false` in `config/thallo.php`'s
  `capabilities` switchboard.
- **Gated end-to-end.** When disabled, the meta, sitemap, robots, and admin routes are never
  registered (`404`) and the cache-invalidation listener is not wired. Migrations run on install (not
  enable), so disabling preserves `seo_meta`.
- **Permission.** The pack declares `seo.manage`; the host app grants it to `administrator` in its own
  dependent migration.

## Configuration

The pack's own config merges under `seo` (`config/seo.php`):

- `fallbacks` — per-type-slug map `{ title_field, description_field, image_field }`.
- `defaults` — `site_name`, `default_og_image`, `title_template` (e.g. `"{title} — {site_name}"`).
- `robots` — list of `{ user_agent, allow: [...], disallow: [...] }` groups.

The absolute origin for the feeds is the **existing core key** `config('thallo.seo.public_url_base')`
(env `PUBLIC_URL_BASE`) — the same key `PathRenderer` reads. **The feeds require it:**
`/sitemap.xml`, `/sitemap/{n}.xml`, and `/robots.txt` return **`409 Conflict`** (plain text) when it
is empty, rather than emitting crawler-invalid relative URLs. Meta is unaffected (it carries no
absolute URLs).

## Install

The pack is **bundled by default** in the Thallo create-project template. To add it to an existing app
(it lives as a path package in this monorepo):

1. `composer require glueful/thallo-seo`
2. `./thallo extensions:enable thallo-seo` (writes the provider into the `config/extensions.php`
   allow-list and recompiles the extension cache)
3. `./thallo migrate:run` to create `seo_meta` and declare the `seo.manage` permission.

Set `PUBLIC_URL_BASE` (e.g. `https://example.com`) so the feeds emit absolute URLs, and
optionally the `SEO_SITE_NAME` / `SEO_DEFAULT_OG_IMAGE` / `SEO_TITLE_TEMPLATE` defaults.

## Frontend integration

Thallo is headless, so the SPA/site wires these up:

- Fetch `/v1/seo/meta/{type}/{slug}` per page and inject the fields into `<head>` (compose
  canonical/hreflang from the core delivery `seo` object).
- Reverse-proxy `/sitemap.xml`, `/sitemap/*.xml`, and `/robots.txt` to the site root.

## Remove

`./thallo extensions:disable thallo-seo`, then `composer remove glueful/thallo-seo`. The CMS core boots
unchanged; the `thallo.seo` capability disappears and all SEO routes are gone. `seo_meta` remains on
disk (drop it manually if you want the data gone).

## Out of scope (deferred)

**JSON-LD / structured data** is a fast-follow — it expands the modeling surface (schema-type
selection, field mapping, breadcrumbs, validation) and deserves its own pass. Redirects, canonical,
and hreflang stay with core delivery.
