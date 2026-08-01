# lemma-search — Design

**Status:** Approved (direction + refinements), pending spec review.
**Scope:** A removable **search** capability pack for [Lemma](https://getlemma.dev): a public, content-aware search API over **published** content, backed by Meilisearch. lemma-search owns Lemma semantics (delivery visibility, href/title, lifecycle, the `ContentReindexer` seam); Meilisearch owns the search mechanics.
**Follows:** lemma-seo (same capability-pack conventions).

> **Scope note.** lemma-search is deliberately **content-only**. Searching collections rows and any generic admin-facing Meilisearch console are **separate future efforts** (their own specs) — a generic surface would lack this pack's delivery-parity visibility and lifecycle sync, and the Meilisearch extension's indexing is Model-oriented, so neither is a free graft onto this pack.

---

## 1. Purpose & scope

**In (v1):** a public `GET /v1/search` endpoint returning **ranked hits with highlighted snippets** over published content, per-locale, optionally filtered by content type, with **delivery-parity visibility** (search never surfaces what the delivery API would not). Live index maintenance via the existing `ContentReindexer` seam, plus operator commands (`search:reindex`, `search:status`).

**Out (deferred):**
- **Collections-row search** — a separate future effort (its own content-aware pipeline or a lemma-collections concern), not a graft onto this pack.
- **Generic admin-facing Meilisearch console** — a distinct, separately-justified product (internal/ungoverned search); its own brainstorm.
- Admin search UI (SPA) — a later pass, like lemma-seo's admin UI.
- `PostgresFtsBackend` — a second adapter behind the same port, added once the Meilisearch path proves the document shape and contract. **No dual-backend in v1.**
- Admin status **HTTP** endpoint + a `search.manage`/`search.read` permission — added only when a real `/v1/admin/search/*` route exists. **No permission migration in v1.**
- Facets/aggregations; synonyms/custom-ranking tuning; multi-locale single query.

## 2. Architecture & boundary

lemma-search is a **content-aware adapter**, not a search engine. It owns an internal engine-neutral **port** and ships one adapter (Meilisearch) in v1.

- **`SearchBackend` port** (pack-owned interface) — every unit except the adapter depends only on this.
- **`MeilisearchBackend` adapter** — the **only** class that imports `Glueful\Extensions\Meilisearch\*`. Talks to a `lemma_content` index via the extension's `IndexManager` (create/settings/flush/stats) and the underlying `MeilisearchClient` (add/delete/search raw documents). Confining the external dep here lets a `PostgresFtsBackend` plug in later without touching the reindexer, controller, or document builder.
- **Capability** `lemma.search`. Pack `composer require`s `glueful/framework`, `glueful/lemma-contracts`, and `glueful/meilisearch`.
- **Boundary:** the guard (`scripts/check-pack-boundaries.php`) only forbids a `glueful/lemma` dependency and `App\` source references — so the `glueful/meilisearch` dep and `Glueful\Extensions\Meilisearch\*` refs in the adapter are allowed. **No allowlist change needed.**

### 2.1 Contract addition (dogfooding — like lemma-seo's `enumeratePublishedForSitemap`)

A new **search-specific** reader in `packages/lemma-contracts/src/Search/`, implemented App-side (App owns entry→type resolution, routing, and the `href`). The reindex seam passes identity only, so the pack needs a lookup and an enumeration:

```php
namespace Glueful\Lemma\Contracts\Search;

/** A published entry+locale, normalized for indexing. App builds `href` (ready absolute path). */
final class IndexableContent
{
    /** @param array<string,mixed> $fields decoded field values (all fields, per locale) */
    public function __construct(
        public readonly string $entryUuid,
        public readonly string $locale,
        public readonly string $contentTypeUuid,
        public readonly string $contentTypeSlug,
        public readonly bool $publicDelivery,
        public readonly string $href,
        public readonly ?string $entryLabel,   // for the title fallback chain
        public readonly array $fields,
        public readonly ?string $lastmod = null,
    ) {}
}

/** A page of IndexableContent for backfill. */
final class IndexablePage
{
    /** @param list<IndexableContent> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {}
}

interface IndexableContentReader
{
    /** Live reindex: the published record for one entry+locale, or null if not published/visible. */
    public function getIndexablePublished(string $entryUuid, string $locale): ?IndexableContent;

    /** Backfill: one page of published records, optionally scoped by type slug / locale. */
    public function enumerateIndexablePublished(
        int $limit,
        int $offset = 0,
        ?string $typeSlug = null,
        ?string $locale = null,
    ): IndexablePage;
}
```

The App implementation reuses `DeliveryRepository` (the leak-proof published spine) — `getIndexablePublished` returns `null` for draft-only/archived/unpublished entries, so unpublish and hide are handled without the pack knowing content rules.

## 3. Components

```
packages/lemma-search/
  composer.json                     # Glueful\Lemma\Search\; requires framework, lemma-contracts, glueful/meilisearch
  config/lemma-search.php           # index name, per-type field config, snippet length, default/max limit, language(s)
  src/LemmaSearchServiceProvider.php # services(); register()=mergeConfig; boot()=capability + routes gate + bind ContentReindexer + SearchBackend
  src/Engine/SearchBackend.php      # the port (interface)
  src/Engine/MeilisearchBackend.php # the ONLY meilisearch-importing class
  src/Index/DocumentBuilder.php     # IndexableContent + schema + config -> search document
  src/Index/SearchContentReindexer.php # implements ContentReindexer: getIndexablePublished -> upsert | deleteEntry
  src/Query/SearchRequest.php       # q, locale, ?type, limit, offset, VisibilityFilter
  src/Query/SearchResults.php       # hits[], total, limit, offset
  src/Query/VisibilityResolver.php  # Request -> accessible-type filter (public_delivery + api_key_scopes)
  src/Http/SearchController.php     # GET /v1/search -> SearchBackend -> stable contract
  src/Console/StatusCommand.php     # lemma search:status  (doctor)
  src/Console/ReindexCommand.php    # lemma search:reindex (ensureIndex + backfill)
  routes/public-routes.php          # GET /v1/search  (optional-api-key + delivery-parity)
  README.md
```
No pack-owned index **table** (Meilisearch owns storage) and **no migrations in v1** (no permission seed). App-side: the `IndexableContentReader` implementation + its binding in `app/Providers/LemmaServiceProvider.php`, plus a new contracts file.

### 3.1 The `SearchBackend` port

```php
namespace Glueful\Lemma\Search\Engine;

interface SearchBackend
{
    /** Create the index if absent and apply settings (searchable/filterable/sortable/highlight). Idempotent. */
    public function ensureIndex(): void;

    /** Upsert (replace by document id "{entryUuid}:{locale}"). */
    public function upsert(iterable $documents): void;

    /**
     * locale != null  -> delete document id "{entryUuid}:{locale}".
     * locale == null  -> delete ALL documents whose entry_uuid == entryUuid (hard delete).
     */
    public function deleteEntry(string $entryUuid, ?string $locale = null): void;

    public function search(SearchRequest $request): SearchResults;

    /** True when the backend is reachable and the index exists. Drives the 503 + doctor. */
    public function health(): bool;
}
```

`SearchResults` carries `list<Hit>` where a `Hit` is `{entryUuid, contentTypeSlug, locale, href, title, snippet, score}` plus `total, limit, offset`. The controller maps `Hit` → the public JSON (`uuid`, `type`, …).

### 3.2 `DocumentBuilder` (field selection: convention + optional override)

Input: an `IndexableContent` + the content-type schema (via `ContentTypeReader::schemaFor(uuid)->fields()` for field types) + `config('lemma_search.types.<slug>')`.

- **Default (zero-config):** index every **string/text** schema field. **Title** = the `title` field if present and non-empty, else the fallback chain **`entryLabel` (App-supplied, from the entry's route/label) → first indexed string field**. Title is a distinct searchable attribute ranked **above** body (Meilisearch ranks by searchable-attribute order, not A–D).
- **Optional per-type override** (`config/lemma-search.php`):
  ```php
  'types' => [
    'blog' => [
      'title_field'   => 'headline',
      'body_fields'   => ['summary', 'body'],
      'exclude_fields'=> ['seo_description'],
      'weights'       => ['headline' => 5, 'summary' => 2, 'body' => 1], // relative importance -> attribute order
    ],
  ],
  ```
  `weights` express relative importance; the builder orders searchable attributes by descending weight (Meilisearch has no numeric per-field weight). Higher weight ⇒ earlier attribute ⇒ higher rank.
- **Validation (non-fatal):** unknown configured fields and non-string fields listed in config are **skipped at runtime** and **reported by `search:status`** — never a runtime error.

Output document: `{ id: "{entryUuid}:{locale}", entry_uuid, locale, content_type_uuid, content_type_slug, public_delivery, href, title, body }` (`body` = concatenated indexed non-title text, used for highlight/snippet). Filterable attributes: `content_type_uuid, content_type_slug, public_delivery, locale`.

### 3.3 `SearchContentReindexer implements ContentReindexer`

**Required contract change (v1):** widen `ContentReindexer::reindexEntry` from `string $locale` to **`?string $locale`**. This is not cosmetic — the whole-entry delete path (`EntryRepository::softDelete`) emits `EntryDeleted` with **`locale: null`**, and that event is already wired to the App's `ReindexSearchListener`, which passes `$event->locale` (a `?string`) straight through. With the current non-null signature, a bound reindexer would `TypeError` on every entry delete. The App listener already passes the nullable value, so **only the contract interface changes** (a lemma-contracts edit — dogfooding, like lemma-seo's delivery addition); no App-listener change is needed.

```php
public function reindexEntry(string $entryUuid, ?string $locale): void
{
    // Whole-entry delete (EntryDeleted carries locale=null): purge every locale doc.
    if ($locale === null) {
        $this->backend->deleteEntry($entryUuid, null);
        return;
    }
    // Per-locale publish/unpublish/update: re-read and upsert, or delete this locale if gone.
    $record = $this->reader->getIndexablePublished($entryUuid, $locale);
    if ($record === null) {
        $this->backend->deleteEntry($entryUuid, $locale); // unpublished/hidden this locale
        return;
    }
    $this->backend->upsert([$this->builder->build($record)]);
}
```
Resilient: invoked in the pipeline's `afterCommit`; the pack's binding wraps the call so a backend failure is caught + logged and never breaks publishing. `lemma:resync` and `search:reindex` recover.

## 4. Data flow

**Live reindex:** entry publish/unpublish/update/delete → App emits `EntryPublished`/etc → the already-wired `ReindexSearchListener` sees `ContentReindexer` is bound → `SearchContentReindexer::reindexEntry(uuid, ?locale)` (§3.3). Per-locale events carry their locale (re-read → upsert/delete-this-locale); the whole-entry `EntryDeleted` carries `locale = null` (→ delete all locale docs). No separate deletion listener is needed — the existing seam covers both once the contract locale is nullable.

**Backfill** (`php lemma search:reindex [--type=<slug>] [--locale=<code>]`): `ensureIndex()` → page `enumerateIndexablePublished(limit, offset, type?, locale?)` → batch `upsert`. Reports counts.

**Query** (`GET /v1/search?q=&locale=&type=&limit=&offset=`): optional-api-key middleware populates `api_key_scopes` → `SearchController` validates → `VisibilityResolver` → `SearchBackend::search` → contract mapping.

## 5. Visibility & the public contract

**Response:**
```json
{ "hits": [ { "uuid": "...", "type": "blog", "locale": "en",
             "href": "/blog/...", "title": "...",
             "snippet": "...the <mark>climate</mark> crisis...", "score": 0.98 } ],
  "total": 42, "limit": 20, "offset": 0 }
```

**Snippet safety (fixed highlight tag + escaping):** the highlight tag is a fixed **`<mark>`…`</mark>`** (the adapter sets Meilisearch `highlightPreTag`/`highlightPostTag` accordingly). The `snippet` value is **HTML-escaped except for the highlight tags** — the adapter escapes any markup present in the source field text so the only literal tags a client ever receives are `<mark>`/`</mark>`. `title` is returned as **plain text** (no highlighting). This keeps the snippet safe to render without the frontend having to sanitize arbitrary HTML.

**Visibility is enforced inside the backend `filter`** (never post-filtered, so `total`/pagination stay correct):
`locale = <req>` **AND** `(public_delivery = true OR content_type_uuid IN [<scoped>])`, with `content_type_slug = <type>` added when a `type` is given and accessible. `content_type_uuid` and `public_delivery` are filterable attributes on each doc. Snippets are generated only from the visible entry's own indexed fields → no leak.

**`VisibilityResolver`** mirrors `DeliveryAccessMiddleware` using the framework's `ApiKeyService::scopeSatisfies`:
- `read:content` in scopes ⇒ **all-access** (no type restriction).
- else collect `read:content:{slug}` scopes → resolve each slug to a uuid via `ContentTypeReader::findUuidBySlug` → `scoped` uuid set.
- anonymous / unscoped ⇒ `scoped` is empty ⇒ only `public_delivery = true` docs match.

**403 vs empty (explicit):**
- **`type` omitted** → inaccessible types are **silently excluded** (the visibility filter simply doesn't match them). Result is a normal (possibly empty) hit list.
- **`type` provided and inaccessible** → **403** (same denial as delivery: "requires a scoped API key"). Unknown `type` → **404**. Accessible `type` → filtered to it.

**Visibility drift (documented edge):** `public_delivery` is denormalized into each doc at index time, so **flipping a content-type's `public_delivery` flag requires `lemma search:reindex --type=<slug>`** to take effect (no content-type lifecycle event is wired in v1). `search:status` surfaces this: it warns when it can detect indexed docs for a type whose current delivery flag differs from the indexed value; if that detection is not cheap, it instead restates the documented reindex requirement in its output.

## 6. Error handling (fail-closed)

- **Meilisearch missing/unhealthy** (extension absent/disabled, or host unreachable): `SearchBackend::health()` false ⇒ `GET /v1/search` returns **503** ("Search is temporarily unavailable"); **live reindex** catches + logs and no-ops (never breaks publish); **`search:reindex`** exits non-zero with a clear message.
- **`StatusCommand` (`lemma search:status`)** — the operator doctor: host reachability, index existence, settings drift, doc count, config-field warnings, and the visibility-drift warning (§5).
- **Capability disabled** → routes not registered (**404**), `ContentReindexer` unbound (listener no-ops) — the lemma-seo gating pattern.
- **Validation:** empty `q` → **422**; missing `locale` → **422**; unknown `type` → **404**; inaccessible `type` → **403**; `limit`/`offset` clamped to configured bounds.

## 7. Testing

- **Unit — `DocumentBuilder`:** title fallback chain (`title` field → `entryLabel` → first indexed string field), `exclude_fields`, weight→attribute order, non-string fields skipped, config warnings collected.
- **Unit — `VisibilityResolver`:** public-only (anonymous), scoped single type, `read:content` all-access; and the **403-vs-empty distinction** — omitted-type silently excludes inaccessible types; provided-inaccessible-type raises 403.
- **Adapter — `MeilisearchBackend`:** against a **fake** of the meilisearch primitives (no live server) — index settings (searchable/filterable/highlight), upsert doc shape + id, `deleteEntry(uuid, locale)` deletes `"{uuid}:{locale}"`, `deleteEntry(uuid, null)` deletes all `entry_uuid = uuid`, the built search filter expression, `health()`.
- **Reindexer:** `reindexEntry(uuid, null)` (whole-entry delete) → `deleteEntry(uuid, null)`; `reindexEntry(uuid, locale)` with `getIndexablePublished` null → `deleteEntry(uuid, locale)`; non-null → `upsert` with the built doc (fake reader + fake backend).
- **Endpoint (LemmaTestCase, bound fake `SearchBackend`):** happy path → mapped contract (incl. snippet); `q` missing → 422; `locale` missing → 422; provided inaccessible `type` → 403; unknown `type` → 404; backend unhealthy → 503; **removability** — `lemma.search` disabled → route 404 + reindexer unbound.

## 8. Deliverables summary

`packages/lemma-search/*` (provider, `SearchBackend` port + `MeilisearchBackend`, `DocumentBuilder`, `SearchContentReindexer`, `VisibilityResolver`, `SearchController`, `SearchRequest`/`SearchResults`, `StatusCommand`, `ReindexCommand`, `config/lemma-search.php`, `routes/public-routes.php`, README); `packages/lemma-contracts/src/Search/IndexableContentReader.php` (+ `IndexableContent`, `IndexablePage`); the **`ContentReindexer::reindexEntry` signature widened to `?string $locale`** (`packages/lemma-contracts/src/Search/ContentReindexer.php`); App-side `IndexableContentReader` implementation + binding; pack wiring in `composer.json`/`config/extensions.php`; tests. **No migrations, no admin UI, no Postgres backend in v1.**
