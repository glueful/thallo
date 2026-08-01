# glueful/lemma-seo — Design

**Status:** Approved (direction + refinements), pending spec review.
**Depends on:** the shipped SEO/routing module (`docs/superpowers/specs/2026-06-16-seo-routing-module-design.md`) which owns **redirects + canonical/hreflang** — those are NOT re-implemented here.
**Scope:** a removable Lemma capability pack for the SEO concerns that module deferred — **sitemaps, per-entry SEO meta, and robots.txt**. JSON-LD/structured data is deferred (§9).

---

## 1. Scope

**In (v1):**
- **Sitemaps** — crawler-standard `sitemap.xml` (adaptive: a `<urlset>` for a single page, a `<sitemapindex>` when paginated) + paginated page files, with `<lastmod>` and hreflang alternates.
- **Per-entry SEO meta** — title / description / Open Graph / Twitter card per entry+locale, resolved (override → per-type fallback → site defaults) and served on a pack-owned endpoint for the frontend's `<head>`.
- **robots.txt** — config-driven allow/disallow groups + the sitemap URL.

**Out (owned elsewhere / deferred):**
- **Redirects, canonical, hreflang** — already shipped in core delivery's `seo` object (`CanonicalProjector`); the frontend already receives them from `GET /v1/content/{type}/{slug}`. Not duplicated here.
- **JSON-LD / structured data** — deferred to a fast-follow (§9).

## 2. Architecture & boundary

Pack `packages/lemma-seo`, namespace `Glueful\Lemma\Seo`, capability `lemma.seo`, provider in `extra.glueful.provider` — dependency-pure (framework + `lemma-contracts` only, **never `App\`**), mirroring `lemma-analytics`/`lemma-collections`. The repo's `composer boundaries` check enforces it.

**Division of labor (what keeps it decoupled):**
- **App owns URLs and content reads.** The pack never calls `PathRenderer`, `CanonicalProjector`, a route repository, or a DB table directly. It reaches published content only through `lemma-contracts`, and the contract hands back **ready-made absolute URLs** — so all routing/URL knowledge stays in App.
- **Pack owns SEO output.** Meta resolution + fallback logic, sitemap XML serialization, robots text, its own `seo_meta` override storage, response shapes, and cache.

## 3. Contracts (`lemma-contracts`)

**Reused as-is** (already present, App-implemented):
- `Delivery\ContentDeliveryReader::findPublished(string $typeUuid, string $locale, string $slugOrUuid): ?array` — the published row (incl. fields) for meta fallback. (`listPublished` exists but is not used for sitemaps — see below.)
- `Schema\ContentTypeReader::findUuidBySlug(string $slug): ?string` — resolves the meta endpoint's `{type}` slug → type uuid (no App-table access in the pack).
- `Schema\ContentSchemaReader::fields()` / `field(string)` — validates that a per-type fallback field mapping references real fields.
- `Events\ContentLifecycleEvent` (`name()`, `payload()`) — the pure lifecycle event the pack subscribes to for cache invalidation (App's `BaseContentEvent` already `implements ContentLifecycleEvent`, so no App bridge is required).

**One addition** to `ContentDeliveryReader` — page-aware sitemap enumeration so the pack can decide index-vs-single-urlset and build page links without probing or leaking query assumptions:

```php
/**
 * One page of published URLs for sitemap generation. Rows carry READY ABSOLUTE URLs —
 * the App impl builds them via PathRenderer/public_url_base; the pack only serializes.
 *
 * @return array{
 *   items: list<array{
 *     href: string,                                  // absolute, e.g. https://site.com/en/blog/post
 *     lastmod: ?string,                              // ISO-8601, from published_at/updated_at
 *     alternates: list<array{locale:string, href:string}>  // hreflang, absolute
 *   }>,
 *   total: int, limit: int, offset: int
 * }
 */
public function enumeratePublishedForSitemap(int $limit, int $offset = 0): array;
```

**App impl** (`EngineContentDeliveryReader`): builds a page from `DeliveryRepository::paginatePublished` across published types/locales, rendering `href`/`alternates` via `PathRenderer` (absolute — `public_url_base`) and reusing the existing alternates logic from the canonical/hreflang path. `total` lets the pack compute page count.

## 4. Data model

Pack migration (flat `packages/lemma-seo/migrations/`, `MigrationPriority::DEPENDENT`) — `seo_meta`:

| column | notes |
|---|---|
| `id` | pk |
| `entry_uuid`, `locale` | **unique together** |
| `title`, `description` | per-entry overrides (nullable) |
| `og_title`, `og_description`, `og_image` | Open Graph overrides (nullable) |
| `twitter_card` | e.g. `summary_large_image` (nullable) |
| `robots` | per-page directive: `index` (default) / `noindex` (± `nofollow`) |
| `created_at`, `updated_at` | |

`config/lemma-seo.php`:
- `fallbacks` — per-type map `{ <type_slug>: { title_field, description_field, image_field } }`.
- `defaults` — `site_name`, `default_og_image`, `title_template` (e.g. `"{title} — {site_name}"`).
- `robots` — list of groups `[{ user_agent, allow: [...], disallow: [...] }]`.

## 5. Surfaces

### 5.1 Per-entry meta — public read
`GET /v1/seo/meta/{type}/{slug}?locale=` → `200` `{ title, description, og:{title,description,image}, twitter:{card}, robots }`.

Resolution order per field: **`seo_meta` override → per-type fallback** (read the field named in `config.fallbacks[type]` off the entry row from `findPublished`) **→ site default** (+ `title_template` applied to `title`). `{type}`→uuid via `findUuidBySlug`; unknown type or no published entry → `404`. Canonical/hreflang are intentionally absent — they live on the core delivery `seo` object; the frontend composes both.

### 5.2 Meta overrides — admin write
`GET /v1/admin/seo/meta/{entryUuid}?locale=` and `PUT …` behind `auth` + `lemma_permission:seo.manage`. This is the write path that makes the override table usable; the admin SPA UI is deferred (backend-only pack, like analytics Phase 1). `PUT` upserts the `(entry_uuid, locale)` row.

### 5.3 Sitemaps — public
- `GET /sitemap.xml` — the single well-known entry. **Adaptive:** when `total ≤ 50000` it is one `<urlset>`; when `total > 50000` it is a `<sitemapindex>` listing the page files (so `/sitemap.xml` *is* the index when needed — no separate route required).
- `GET /sitemap/{n}.xml` — page `n` (`1`-based), 50 000 URLs max per file (sitemap-protocol cap). Each `<url>`: `<loc>` (absolute), `<lastmod>`, and `xhtml:link rel="alternate" hreflang` per alternate.
- Content type is `application/xml`.

### 5.4 robots.txt — public
`GET /robots.txt` → `text/plain` from `config.robots` groups, auto-appending `Sitemap: <origin>/sitemap.xml` where `<origin>` is `config('lemma.seo.public_url_base')`.

### 5.5 Status codes
`sitemap.xml`, `sitemap/{n}.xml`, and `robots.txt` require an absolute origin. The origin is the **existing core key** `config('lemma.seo.public_url_base')` (the same key `PathRenderer` reads — not redefined by the pack). When it is empty they return **`409 Conflict`** with a clear config-error message — never relative-URL (crawler-invalid) output or silent empty. Meta is unaffected (it carries no absolute URLs).

## 6. Caching & invalidation

- **Sitemap cache keys:** `lemma_seo:sitemap:root` (the adaptive `/sitemap.xml`) and `lemma_seo:sitemap:page:{n}`. Rendered XML is cached in the framework cache.
- **Invalidation:** the pack ships a plain listener class (`SitemapCacheInvalidator`) with an `onContentChanged(ContentLifecycleEvent $event): void` handler, wired in the provider's enabled gate via `EventService::addListener(ContentLifecycleEvent::class, [$listener, 'onContentChanged'])`. The framework's `getEventTypes()` includes a concrete event's implemented interfaces, so App's `BaseContentEvent` (which `implements ContentLifecycleEvent`) dispatches to this interface-typed listener — no App bridge, no App event classes, and it matches the `addListener` pattern the sibling `lemma-analytics` pack already uses. The handler clears **all** `lemma_seo:sitemap:*` keys on any `entry.published` / `entry.unpublished` / `entry.updated` / `entry.deleted` (any can change the published-URL set or a `lastmod`). The listener type-hints the pure contract interface only.
- **Meta:** resolved per request (one `findPublished` + config lookup — cheap); no cache required in v1. If a TTL cache is later added, a `PUT` override busts only that `(entry_uuid, locale)` meta key.

## 7. Gating & states

- **Gated on `lemma.seo`:** the public feed routes, the meta endpoint, and the admin routes register only when the capability is enabled (else `404`). Migrations run on **install**, so disabling preserves `seo_meta`.
- **Permission:** the pack declares `seo.manage`; the host app grants it to `administrator` in its own dependent migration.
- **Empty site:** zero published entries → `/sitemap.xml` is a valid empty `<urlset>` (not an error).

## 8. Testing

- **Meta resolution precedence** — override wins; absent override falls back to the mapped content field; absent field falls back to site default; `title_template` applied.
- **Sitemap** — valid XML; single-`urlset` under the cap and `sitemapindex` + page files over it; `lastmod` + hreflang alternates present; `409` when `public_url_base` unset; empty-but-valid urlset for an empty site.
- **robots.txt** — groups render; `Sitemap:` line appended; `409` when `public_url_base` unset.
- **Gating** — all route groups `404` when `lemma.seo` disabled; admin write behind `auth` + `seo.manage` (401/403).
- **Invalidation** — a dispatched `ContentLifecycleEvent` clears all `lemma_seo:sitemap:*` keys.
- **Contract** — the App `enumeratePublishedForSitemap` impl returns absolute `href`/`alternates` + correct `total` for pagination.

## 9. Out of scope (deferred)

**JSON-LD / structured data** — deferred to a fast-follow. It expands the modeling surface quickly (schema-type selection, field mapping, required/recommended schema.org properties, locale behavior, breadcrumbs, validation, preview, per-content-type defaults) and deserves its own pass once sitemap + meta prove the content hooks and the invalidation path. Also out: server-rendered HTML (Lemma is headless — the frontend injects meta and proxies the feeds), and redirects/canonical/hreflang (core delivery).

## 10. Deliverables summary

**`lemma-contracts`:** add `ContentDeliveryReader::enumeratePublishedForSitemap()`; App implements it in `EngineContentDeliveryReader`.
**`lemma-seo` pack:** `seo_meta` migration; `config/lemma-seo.php`; `SeoMetaResolver`; `SitemapBuilder` (+ page/index XML); `RobotsBuilder`; controllers + routes (public meta, admin meta, sitemaps, robots); `ContentLifecycleEvent` listener for cache invalidation; capability registration; `seo.manage` permission (host-app grant migration).
**Frontend integration (documented, not built):** call `/v1/seo/meta/...` for `<head>`; reverse-proxy `/sitemap.xml`, `/sitemap/*.xml`, `/robots.txt` to the site root.
