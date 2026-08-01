# lemma-search (content-only v1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `packages/lemma-search` — a removable Lemma capability pack exposing a public, delivery-parity `GET /v1/search` over published content, backed by Meilisearch, with live index maintenance through the existing `ContentReindexer` seam plus operator commands.

**Architecture:** lemma-search is a **content-aware adapter**, not a search engine. Every unit depends on a pack-owned `SearchBackend` port; a single Meilisearch-confined class talks to the external extension. Lemma semantics (published visibility, href/title, lifecycle) live in the pack and in a new App-side reader; search mechanics live in `glueful/meilisearch`.

**Tech Stack:** PHP 8.3, Glueful framework (NOT Laravel), Meilisearch via `glueful/meilisearch`, PHPUnit, capability-pack conventions mirrored from `lemma-seo`.

## Global Constraints

Every task's requirements implicitly include this section. Values are exact.

- **Not Laravel.** Verify every framework method before use; context-first APIs (`config($context, …)`, `app($context, …)`). Console commands extend `Glueful\Console\BaseCommand`.
- **PostgreSQL only.** The App content store is Postgres (JSONB `entry_versions.fields`). Do not introduce SQLite-only or MySQL-only SQL.
- **Meilisearch confinement.** Exactly **one** class may import `Glueful\Extensions\Meilisearch\*`: `Glueful\Lemma\Search\Engine\LiveMeilisearchIndex`. No other pack class, and **not** `LemmaSearchServiceProvider`, may reference the `Glueful\Extensions\Meilisearch\*` namespace. (See the flagged deviation below — the spec named `MeilisearchBackend` as the confined class; this plan splits the raw-index seam out so `MeilisearchBackend` is fake-testable per spec §7.)
- **Pack boundary.** `packages/lemma-search` must not `composer require glueful/lemma` and must not reference the `App\` namespace in `src/`. `scripts/check-pack-boundaries.php` must stay green. The `glueful/meilisearch` dependency and `Glueful\Extensions\Meilisearch\*` refs (in `LiveMeilisearchIndex` only) are allowed — no allowlist change.
- **Provider code style.** In `services()`/factory code use `use` imports and short class names, never inline FQCNs (PHP6601).
- **Capability id** `lemma.search`; the real enable/disable switch is the host `lemma.capabilities` map read by `App\Capabilities\DefaultCapabilityRegistry` (absent id ⇒ enabled). Disabled ⇒ routes unregistered (404) **and** `ContentReindexer` resolves to a no-op.
- **Fail closed.** Meilisearch missing/unhealthy ⇒ `health()` false ⇒ `GET /v1/search` returns **503**; live reindex catches + logs and never breaks publishing; `search:reindex` exits non-zero.
- **No migrations, no admin UI, no Postgres backend in v1.**
- **Highlight/snippet safety:** highlight tag is a fixed `<mark>`…`</mark>`; snippet text is HTML-escaped except the highlight tags; `title` is returned as plain text (no highlighting).
- **Commits:** work on `dev` directly. **Commit only when the user authorizes it** (review-artifact workflow — do not commit after each task automatically). Each task's "Stage" step lists the exact `git add` set and a ready commit command, but hold the `git commit` until given the go-ahead; batch at logical groupings if the user prefers. **No Claude/Anthropic attribution** anywhere (no `Co-Authored-By`, no "Generated with Claude Code"). Never stage/commit `CLAUDE.md`. Do not push.

### Deviations from the spec (flagged for your plan review)

Two small, load-bearing refinements the spec's §8 deliverables list does not spell out. Both are called out here so you can veto during review:

1. **Meilisearch-confined class renamed/split.** Spec §2 calls `MeilisearchBackend` "the only class that imports meilisearch," but §7 also requires `MeilisearchBackend` to be **fake-tested with no live server**. Those conflict — you cannot fake meilisearch primitives inside the class that imports them. Resolution: introduce a pack-owned seam `MeilisearchIndex` (interface). `MeilisearchBackend implements SearchBackend` depends on that seam and imports **no** meilisearch (fully fake-testable). `LiveMeilisearchIndex implements MeilisearchIndex` is the single meilisearch-importing class. Same confinement, testable adapter.
2. **One extra contract method:** `ContentTypeReader::isPublicDelivery(string $uuid): bool`. Required so the provided-inaccessible-type **403** decision keeps delivery parity — a `public_delivery=true` type must remain reachable anonymously (matching `DeliveryAccessMiddleware`), which the pack cannot determine from scopes alone. Implemented App-side on `EngineContentTypeReader`.

---

## File Structure

**New — `packages/lemma-contracts/src/Search/`:**
- `IndexableContent.php` — value object (published entry+locale normalized for indexing)
- `IndexablePage.php` — value object (a backfill page)
- `IndexableContentReader.php` — interface (App implements)

**Modified — `packages/lemma-contracts/src/`:**
- `Search/ContentReindexer.php` — widen `reindexEntry` locale to `?string`
- `Schema/ContentTypeReader.php` — add `isPublicDelivery(string $uuid): bool`

**New — `packages/lemma-search/`:**
- `composer.json`, `README.md`, `config/lemma-search.php`
- `src/LemmaSearchServiceProvider.php`
- `src/Engine/SearchBackend.php` (port), `src/Engine/MeilisearchIndex.php` (seam), `src/Engine/MeilisearchBackend.php`, `src/Engine/LiveMeilisearchIndex.php`
- `src/Index/DocumentBuilder.php`, `src/Index/SearchContentReindexer.php`, `src/Index/ResilientContentReindexer.php`, `src/Index/NullContentReindexer.php`
- `src/Query/SearchRequest.php`, `src/Query/SearchResults.php`, `src/Query/Hit.php`, `src/Query/VisibilityResolver.php`, `src/Query/VisibilityContext.php`
- `src/Http/SearchController.php`, `routes/public-routes.php`
- `src/Console/ReindexCommand.php`, `src/Console/StatusCommand.php`

**Modified — App + wiring:**
- `app/Content/Delivery/DeliveryRepository.php` — add `enumerateIndexable(...)`
- `app/Content/Delivery/EngineIndexableContentReader.php` — **new**, implements the contract
- `app/Content/Schema/EngineContentTypeReader.php` — add `isPublicDelivery`
- `app/Providers/LemmaServiceProvider.php` — bind `IndexableContentReader`
- `composer.json` (root) — path repo + require `glueful/lemma-search`
- `config/extensions.php` — add provider FQCN

**Test doubles (pack tests):** `FakeMeilisearchIndex`, `FakeSearchBackend`, `FakeIndexableContentReader`, `FakeContentTypeReader` — defined inline in the test files that use them (mirroring how `lemma-seo` builds collaborators directly in tests).

---

## Task 1: Contracts + App-side reader (foundation, no Meilisearch)

Establishes the dogfooding seam the whole pack reads through, and the reindex-signature fix. No pack code yet.

**Files:**
- Create: `packages/lemma-contracts/src/Search/IndexableContent.php`
- Create: `packages/lemma-contracts/src/Search/IndexablePage.php`
- Create: `packages/lemma-contracts/src/Search/IndexableContentReader.php`
- Modify: `packages/lemma-contracts/src/Search/ContentReindexer.php`
- Modify: `packages/lemma-contracts/src/Schema/ContentTypeReader.php:14`
- Modify: `app/Content/Delivery/DeliveryRepository.php` (add `enumerateIndexable`)
- Create: `app/Content/Delivery/EngineIndexableContentReader.php`
- Modify: `app/Content/Schema/EngineContentTypeReader.php` (add `isPublicDelivery`)
- Modify: `app/Providers/LemmaServiceProvider.php` (register binding in `contentServices()` + a factory)
- Test: `tests/Integration/Search/IndexableContentReaderTest.php`

**Interfaces:**
- Produces: `Glueful\Lemma\Contracts\Search\IndexableContent`, `IndexablePage`, `IndexableContentReader`; `ContentReindexer::reindexEntry(string, ?string): void`; `ContentTypeReader::isPublicDelivery(string): bool`; `DeliveryRepository::enumerateIndexable(int,int,?string,?string): array{rows:list<array<string,mixed>>,total:int}`.
- Consumes: `DeliveryRepository::publishedPinsForEntry`, `findPublishedByUuid`; `RouteRepository::forEntry`; `ContentTypeRepository::findByUuid`; `PathRenderer::render`.

- [ ] **Step 1: Write the value objects and interfaces**

Create `packages/lemma-contracts/src/Search/IndexableContent.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Search;

/** A published entry+locale, normalized for indexing. App builds `href` (ready absolute/relative path). */
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
        public readonly ?string $entryLabel,
        public readonly array $fields,
        public readonly ?string $lastmod = null,
    ) {
    }
}
```

Create `packages/lemma-contracts/src/Search/IndexablePage.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Search;

/** A page of IndexableContent for backfill. */
final class IndexablePage
{
    /** @param list<IndexableContent> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
```

Create `packages/lemma-contracts/src/Search/IndexableContentReader.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Search;

/**
 * Reads PUBLISHED content, normalized for a search index. Implemented App-side over the
 * leak-proof delivery spine, so drafts/unpublished/archived entries are never returned.
 */
interface IndexableContentReader
{
    /** The published record for one entry+locale, or null if not published/visible. */
    public function getIndexablePublished(string $entryUuid, string $locale): ?IndexableContent;

    /** One page of published records, optionally scoped by type slug / locale. */
    public function enumerateIndexablePublished(
        int $limit,
        int $offset = 0,
        ?string $typeSlug = null,
        ?string $locale = null,
    ): IndexablePage;
}
```

- [ ] **Step 2: Widen `ContentReindexer` and extend `ContentTypeReader`**

In `packages/lemma-contracts/src/Search/ContentReindexer.php`, change the method signature:

```php
    public function reindexEntry(string $entryUuid, ?string $locale): void;
```

Update the interface docblock line to note the nullable-locale contract:

```php
/**
 * Reindex a single published entry/locale into a pack-owned search index.
 * Implemented by a search pack when installed; unbound in core by default
 * (the reindex listener no-ops when nothing is bound).
 *
 * $locale === null means "whole entry" (all locales) — emitted by the whole-entry
 * delete path (EntryRepository::softDelete → EntryDeleted with locale null).
 */
```

In `packages/lemma-contracts/src/Schema/ContentTypeReader.php`, add to the interface:

```php
    /** True when the content type opts into anonymous public delivery. */
    public function isPublicDelivery(string $uuid): bool;
```

- [ ] **Step 3: Run PHPStan/analyse on contracts to prove the App listener still type-checks**

Run (from repo root): `composer run analyse:changed` (or `vendor/bin/phpstan analyse packages/lemma-contracts app/Content/Pipeline/Listeners/ReindexSearchListener.php`)
Expected: no new errors. `ReindexSearchListener::__invoke` already passes `$event->locale` (a `?string`) into `reindexEntry`, so widening removes a latent `TypeError` and introduces none.

- [ ] **Step 4: Implement `isPublicDelivery` on `EngineContentTypeReader`**

In `app/Content/Schema/EngineContentTypeReader.php`, add:

```php
    public function isPublicDelivery(string $uuid): bool
    {
        $row = $this->types->findByUuid($uuid);
        return $row !== null && (bool) ($row['public_delivery'] ?? false);
    }
```

- [ ] **Step 5: Add `DeliveryRepository::enumerateIndexable` (write the failing test first)**

Create `tests/Integration/Search/IndexableContentReaderTest.php`. Reuse the seeding helper other suites use (`App\Tests\Integration\Seo\Concerns\SeedsPublishedContent` publishes a bilingual `blog` entry with a route). Start with the enumerate test:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;

final class IndexableContentReaderTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    private function reader(): IndexableContentReader
    {
        return $this->container()->get(IndexableContentReader::class);
    }

    public function testGetIndexablePublishedReturnsPublishedRecord(): void
    {
        // Helper publishes a `blog` entry in en + fr and returns the entry uuid (a string).
        $entry = $this->seedBilingualPublishedEntry();

        $record = $this->reader()->getIndexablePublished($entry, 'en');

        self::assertNotNull($record);
        self::assertSame($entry, $record->entryUuid);
        self::assertSame('en', $record->locale);
        self::assertSame('blog', $record->contentTypeSlug);
        self::assertArrayHasKey('title', $record->fields);
        self::assertStringContainsString('/en/blog/', $record->href);
    }

    public function testGetIndexablePublishedReturnsNullForUnpublishedLocale(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        self::assertNull($this->reader()->getIndexablePublished($entry, 'zz'));
    }

    public function testEnumerateIndexablePublishedPagesAndScopesByType(): void
    {
        $this->seedBilingualPublishedEntry();

        $page = $this->reader()->enumerateIndexablePublished(limit: 10, offset: 0, typeSlug: 'blog');

        self::assertGreaterThanOrEqual(1, $page->total);
        self::assertNotSame([], $page->items);
        self::assertSame('blog', $page->items[0]->contentTypeSlug);

        // Unknown type slug yields an empty page (total 0), never an error.
        $empty = $this->reader()->enumerateIndexablePublished(limit: 10, offset: 0, typeSlug: 'no-such-type');
        self::assertSame(0, $empty->total);
        self::assertSame([], $empty->items);
    }
}
```

> `seedBilingualPublishedEntry(): string` returns the entry uuid (published in `en` + `fr`, content type `blog`, with a route). Before writing, open `tests/Integration/Seo/Concerns/SeedsPublishedContent.php` and confirm the seeded field name the record carries (the `assertArrayHasKey('title', …)` assumes the seeded `blog` schema has a `title` field). If the seed uses a different field name, assert that exact field — do not weaken the assertions to `assertNotEmpty`.

- [ ] **Step 6: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Search/IndexableContentReaderTest.php`
Expected: FAIL — `IndexableContentReader` is not bound in the container (and `enumerateIndexable` does not exist).

- [ ] **Step 7: Add `enumerateIndexable` to `DeliveryRepository`**

Add this method to `app/Content/Delivery/DeliveryRepository.php` (mirrors `enumeratePublishedForSitemap` but joins `content_types` for slug + `public_delivery` and returns fields; scoped by optional type slug / locale). Uses `->offset()`/`->limit()` chunked ≤1000 to respect the query validator's large-LIMIT guard:

```php
    /**
     * One page of published (entry, route, type, fields) rows across all/selected
     * types+locales, for search indexing. Joins the publication spine to the pinned
     * version, the entry (active guard), the entry_route (href slug), and the content
     * type (slug + public_delivery). Ordered stably (published_at DESC, entry_uuid ASC).
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function enumerateIndexable(
        int $limit,
        int $offset = 0,
        ?string $typeSlug = null,
        ?string $locale = null,
    ): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $apply = function (\Glueful\Database\QueryBuilder $q) use ($typeSlug, $locale): \Glueful\Database\QueryBuilder {
            $q->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
                ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
                ->join('entry_routes as r', 'r.entry_uuid', '=', 'p.entry_uuid')
                ->join('content_types as ct', 'ct.uuid', '=', 'e.content_type_uuid')
                ->where('e.status', '=', 'active')
                ->where('ct.status', '!=', 'deleted')
                ->whereRaw('r.content_type_uuid = e.content_type_uuid')
                ->whereRaw('r.locale = p.locale');
            if ($typeSlug !== null) {
                $q->where('ct.slug', '=', $typeSlug);
            }
            if ($locale !== null) {
                $q->where('p.locale', '=', $locale);
            }
            return $q;
        };

        $chunkSize = 1000;
        $rows = [];
        $remaining = $limit;
        $cursor = $offset;
        while ($remaining > 0) {
            $take = min($chunkSize, $remaining);
            $q = $apply($this->db->table('entry_publications as p'))
                ->select([
                    'p.entry_uuid', 'e.content_type_uuid', 'ct.slug as content_type_slug',
                    'ct.public_delivery', 'p.locale', 'r.slug', 'v.fields', 'p.published_at',
                ])
                ->orderByRaw('p.published_at DESC, p.entry_uuid ASC')
                ->limit($take)
                ->offset($cursor);
            $batch = $q->get();
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                $rows[] = $row;
            }
            $got = count($batch);
            $cursor += $got;
            $remaining -= $got;
            if ($got < $take) {
                break;
            }
        }

        $total = $apply($this->db->table('entry_publications as p'))->count();

        return ['rows' => $rows, 'total' => $total];
    }
```

- [ ] **Step 8: Implement `EngineIndexableContentReader`**

Create `app/Content/Delivery/EngineIndexableContentReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Seo\PathRenderer;
use Glueful\Lemma\Contracts\Search\IndexableContent;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Contracts\Search\IndexablePage;

/**
 * Adapts the delivery spine to the search IndexableContentReader contract. Reads only
 * PUBLISHED content (via DeliveryRepository), builds the href via PathRenderer, and never
 * exposes drafts/unpublished/archived entries.
 */
final class EngineIndexableContentReader implements IndexableContentReader
{
    public function __construct(
        private readonly DeliveryRepository $delivery,
        private readonly ContentTypeRepository $types,
        private readonly RouteRepository $routes,
        private readonly PathRenderer $paths,
    ) {
    }

    public function getIndexablePublished(string $entryUuid, string $locale): ?IndexableContent
    {
        // Published pins for this entry (one per published locale); null if none for $locale.
        $pin = null;
        foreach ($this->delivery->publishedPinsForEntry($entryUuid) as $p) {
            if ($p['locale'] === $locale) {
                $pin = $p;
                break;
            }
        }
        if ($pin === null) {
            return null;
        }

        $typeUuid = (string) $pin['type'];
        $row = $this->delivery->findPublishedByUuid($typeUuid, $locale, $entryUuid);
        if ($row === null) {
            return null;
        }

        $type = $this->types->findByUuid($typeUuid);
        if ($type === null) {
            return null; // orphaned type — treat as not indexable
        }
        $typeSlug = (string) $type['slug'];

        // Route slug for href (the entry's route in this locale).
        $slug = null;
        foreach ($this->routes->forEntry($entryUuid) as $route) {
            if (($route['locale'] ?? null) === $locale
                && (string) ($route['content_type_uuid'] ?? '') === $typeUuid) {
                $slug = (string) $route['slug'];
                break;
            }
        }
        if ($slug === null) {
            return null; // no public URL → not indexable
        }

        /** @var array<string,mixed> $fields */
        $fields = (array) ($row['fields'] ?? []);

        return new IndexableContent(
            entryUuid: $entryUuid,
            locale: $locale,
            contentTypeUuid: $typeUuid,
            contentTypeSlug: $typeSlug,
            publicDelivery: (bool) ($type['public_delivery'] ?? false),
            href: $this->paths->render($typeSlug, $locale, $slug),
            entryLabel: $slug,
            fields: $fields,
            lastmod: $this->iso($row['published_at'] ?? null),
        );
    }

    public function enumerateIndexablePublished(
        int $limit,
        int $offset = 0,
        ?string $typeSlug = null,
        ?string $locale = null,
    ): IndexablePage {
        $page = $this->delivery->enumerateIndexable($limit, $offset, $typeSlug, $locale);

        $items = [];
        foreach ($page['rows'] as $row) {
            $slug = (string) $row['slug'];
            $tSlug = (string) $row['content_type_slug'];
            $loc = (string) $row['locale'];
            $fields = is_string($row['fields'] ?? null)
                ? (json_decode((string) $row['fields'], true) ?? [])
                : (array) ($row['fields'] ?? []);

            $items[] = new IndexableContent(
                entryUuid: (string) $row['entry_uuid'],
                locale: $loc,
                contentTypeUuid: (string) $row['content_type_uuid'],
                contentTypeSlug: $tSlug,
                publicDelivery: (bool) ($row['public_delivery'] ?? false),
                href: $this->paths->render($tSlug, $loc, $slug),
                entryLabel: $slug,
                fields: (array) $fields,
                lastmod: $this->iso($row['published_at'] ?? null),
            );
        }

        return new IndexablePage($items, (int) $page['total'], $limit, $offset);
    }

    private function iso(mixed $publishedAt): ?string
    {
        if (!is_string($publishedAt) || $publishedAt === '') {
            return null;
        }
        $ts = strtotime($publishedAt);
        return $ts === false ? null : date('c', $ts);
    }
}
```

> Before writing, open `app/Content/Repositories/RouteRepository.php` and confirm `forEntry()` row keys (`locale`, `slug`, `content_type_uuid`). If a key differs, adjust the reader (do not change the repo).

- [ ] **Step 9: Bind `IndexableContentReader` in `LemmaServiceProvider`**

In `app/Providers/LemmaServiceProvider.php`, add the `use` import near the other delivery imports:

```php
use App\Content\Delivery\EngineIndexableContentReader;
use App\Content\Repositories\RouteRepository;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
```

Add to the `services()` array where `ContentDeliveryReader` is registered (near line 270):

```php
            IndexableContentReader::class => [
                'factory' => [self::class, 'makeIndexableContentReader'],
                'shared'  => true,
            ],
```

Add the factory (next to `makeContentDeliveryReader`, near line 732):

```php
    public static function makeIndexableContentReader(ContainerInterface $container): EngineIndexableContentReader
    {
        return new EngineIndexableContentReader(
            $container->get(DeliveryRepository::class),
            $container->get(ContentTypeRepository::class),
            $container->get(RouteRepository::class),
            $container->get(PathRenderer::class),
        );
    }
```

> `DeliveryRepository`, `ContentTypeRepository`, `PathRenderer` are already imported/used by neighboring factories; only add imports that are missing.

- [ ] **Step 10: Run the reader test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Search/IndexableContentReaderTest.php`
Expected: PASS (3 tests). If the container is compiled/cached, clear it first: `php glueful cache:clear` (or delete `storage/cache/container*`), then re-run.

- [ ] **Step 11: Stage — commit only when the user authorizes**

Hold the commit until the user gives the go-ahead (review-artifact workflow). When authorized:

```bash
git add packages/lemma-contracts/src/Search app/Content/Delivery/EngineIndexableContentReader.php app/Content/Delivery/DeliveryRepository.php app/Content/Schema/EngineContentTypeReader.php app/Providers/LemmaServiceProvider.php packages/lemma-contracts/src/Schema/ContentTypeReader.php tests/Integration/Search/IndexableContentReaderTest.php
git commit -m "search: add IndexableContentReader contract + App reader; widen ContentReindexer locale"
```

---

## Task 2: Pack scaffold — port, value objects, config, provider spine

Creates the autoloadable pack with its `SearchBackend` port, query value objects, config, and a provider that registers the `lemma.search` capability. No engine, no routes yet.

**Files:**
- Create: `packages/lemma-search/composer.json`
- Create: `packages/lemma-search/config/lemma-search.php`
- Create: `packages/lemma-search/src/Engine/SearchBackend.php`
- Create: `packages/lemma-search/src/Query/Hit.php`
- Create: `packages/lemma-search/src/Query/SearchResults.php`
- Create: `packages/lemma-search/src/Query/SearchRequest.php`
- Create: `packages/lemma-search/src/LemmaSearchServiceProvider.php`
- Modify: `composer.json` (root) — path repo + require
- Modify: `config/extensions.php` — provider FQCN
- Test: `packages/lemma-search/tests/Unit/CapabilityRegistrationTest.php`

**Interfaces:**
- Produces: `Glueful\Lemma\Search\Engine\SearchBackend`; `Glueful\Lemma\Search\Query\{Hit,SearchResults,SearchRequest}`; provider FQCN `Glueful\Lemma\Search\LemmaSearchServiceProvider`.

- [ ] **Step 1: Write `composer.json` and config**

`packages/lemma-search/composer.json`:

```json
{
  "name": "glueful/lemma-search",
  "description": "Search for Lemma: a public, delivery-parity content search API backed by Meilisearch, as a removable capability pack.",
  "type": "glueful-extension",
  "license": "MIT",
  "authors": [
    { "name": "Michael Tawiah Sowah", "email": "michael@glueful.dev" }
  ],
  "version": "0.1.0",
  "require": {
    "php": "^8.3",
    "glueful/lemma-contracts": "*",
    "glueful/framework": "^1.65.0",
    "glueful/meilisearch": "*"
  },
  "autoload": {
    "psr-4": { "Glueful\\Lemma\\Search\\": "src/" }
  },
  "extra": {
    "glueful": {
      "provider": "Glueful\\Lemma\\Search\\LemmaSearchServiceProvider"
    }
  },
  "minimum-stability": "stable"
}
```

> Confirm the `glueful/meilisearch` package name by reading `extensions/meilisearch/composer.json`'s `name`. If it differs, use the actual name and, if it is only available as a path/vcs repo (not Packagist), ensure the root `composer.json` already exposes it (it must, since the extension is installed) — otherwise add a `path` repository entry for it too.

`packages/lemma-search/config/lemma-search.php`:

```php
<?php

declare(strict_types=1);

return [
    // The real enable/disable switch is the host `lemma.capabilities` map (lemma.search).
    // Meilisearch index name (the pack owns ONE shared content index).
    'index' => env('SEARCH_INDEX', 'lemma_content'),

    // Snippet crop length, in words, for highlighted body excerpts.
    'snippet_length' => (int) env('SEARCH_SNIPPET_LENGTH', 40),

    // Query pagination bounds.
    'default_limit' => 20,
    'max_limit' => 50,

    // Optional per-type field selection override (keyed by content-type slug). When absent
    // for a type, the builder indexes every string/text schema field with a convention title.
    //   'blog' => [
    //     'title_field'    => 'headline',
    //     'body_fields'    => ['summary', 'body'],
    //     'exclude_fields' => ['seo_description'],
    //     'weights'        => ['headline' => 5, 'summary' => 2, 'body' => 1],
    //   ],
    'types' => [],
];
```

- [ ] **Step 2: Write the query value objects**

`packages/lemma-search/src/Query/Hit.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Query;

/** One search hit, engine-neutral. The controller maps this to the public JSON contract. */
final class Hit
{
    public function __construct(
        public readonly string $entryUuid,
        public readonly string $contentTypeSlug,
        public readonly string $locale,
        public readonly string $href,
        public readonly string $title,
        public readonly string $snippet,
        public readonly float $score,
    ) {
    }
}
```

`packages/lemma-search/src/Query/SearchResults.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Query;

final class SearchResults
{
    /** @param list<Hit> $hits */
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
```

`packages/lemma-search/src/Query/SearchRequest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Query;

/**
 * A validated, visibility-resolved search request. `typeSlug` is null (all accessible types)
 * or an already-validated, accessible slug. `allAccess` + `scopedTypeUuids` come from the
 * VisibilityResolver and are enforced inside the backend filter (never post-filtered).
 */
final class SearchRequest
{
    /** @param list<string> $scopedTypeUuids */
    public function __construct(
        public readonly string $q,
        public readonly string $locale,
        public readonly ?string $typeSlug,
        public readonly bool $allAccess,
        public readonly array $scopedTypeUuids,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
```

- [ ] **Step 3: Write the `SearchBackend` port**

`packages/lemma-search/src/Engine/SearchBackend.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Engine;

use Glueful\Lemma\Search\Query\SearchRequest;
use Glueful\Lemma\Search\Query\SearchResults;

/**
 * Engine-neutral search port. Every unit except the single Meilisearch-confined class
 * depends only on this, so a PostgresFtsBackend can plug in later untouched.
 */
interface SearchBackend
{
    /** Create the index if absent and apply settings (searchable/filterable). Idempotent. */
    public function ensureIndex(): void;

    /**
     * Upsert documents (replace by document id "{entryUuid}:{locale}").
     *
     * @param iterable<array<string,mixed>> $documents
     */
    public function upsert(iterable $documents): void;

    /**
     * locale != null → delete document id "{entryUuid}:{locale}".
     * locale == null → delete ALL documents whose entry_uuid == entryUuid (hard delete).
     */
    public function deleteEntry(string $entryUuid, ?string $locale = null): void;

    public function search(SearchRequest $request): SearchResults;

    /** True when the backend is reachable and the index exists. Drives the 503 + doctor. */
    public function health(): bool;
}
```

- [ ] **Step 4: Write the provider spine**

`packages/lemma-search/src/LemmaSearchServiceProvider.php` (services grow in later tasks; this task ships capability registration + config merge only):

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class LemmaSearchServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's own tree under 'lemma_search'.
        $this->mergeConfig('lemma_search', require __DIR__ . '/../config/lemma-search.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.search',
            label: 'Search',
            description: 'Public, delivery-parity content search backed by Meilisearch.',
        ));
    }
}
```

- [ ] **Step 5: Wire the pack into the app**

In root `composer.json`, add to the `repositories` array (keep alphabetical with the others):

```json
    { "type": "path", "url": "packages/lemma-search" },
```

and to `require`:

```json
    "glueful/lemma-search": "*",
```

In `config/extensions.php`, add to the `enabled` list (after the Seo provider):

```php
        'Glueful\Lemma\Search\LemmaSearchServiceProvider',
```

Then run: `composer update glueful/lemma-search --no-interaction` (symlinks the path package) and `php glueful cache:clear`.

- [ ] **Step 6: Write the failing capability test**

`packages/lemma-search/tests/Unit/CapabilityRegistrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Tests\Unit;

use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistrationTest extends TestCase
{
    public function testProviderExposesMeilisearchProviderFqcnInComposer(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        self::assertSame(
            'Glueful\\Lemma\\Search\\LemmaSearchServiceProvider',
            $composer['extra']['glueful']['provider'] ?? null,
        );
    }

    public function testCapabilityValueObjectIdentity(): void
    {
        $cap = new Capability('lemma.search', label: 'Search');
        self::assertSame('lemma.search', $cap->id);
        self::assertInstanceOf(CapabilityRegistry::class, new class implements CapabilityRegistry {
            public function register(Capability $c): void {}
            public function all(): array { return []; }
            public function enabled(): array { return []; }
            public function isEnabled(string $id): bool { return true; }
        });
    }
}
```

> This task's deliverable is scaffolding; the capability's *runtime* registration is proven end-to-end by the removability test in Task 7. Keep this unit test lightweight.

- [ ] **Step 7: Run tests**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/CapabilityRegistrationTest.php`
Expected: PASS (2 tests).

Also confirm the app still boots and the boundary check passes:
Run: `php scripts/check-pack-boundaries.php` (Expected: green — the `glueful/meilisearch` require is allowed) and `php glueful extensions:list` (Expected: lists lemma-search).

- [ ] **Step 8: Stage — commit only when the user authorizes**

Hold the commit until the user gives the go-ahead (review-artifact workflow). When authorized:

```bash
git add packages/lemma-search composer.json composer.lock config/extensions.php
git commit -m "search: scaffold lemma-search pack (port, value objects, capability provider)"
```

---

## Task 3: DocumentBuilder (field selection: convention + override)

Pure, dependency-light builder: `IndexableContent` + schema (+ optional per-type config) → a Meilisearch document. Fully unit-testable.

**Files:**
- Create: `packages/lemma-search/src/Index/DocumentBuilder.php`
- Test: `packages/lemma-search/tests/Unit/DocumentBuilderTest.php`

**Interfaces:**
- Consumes: `IndexableContent`; `Glueful\Lemma\Contracts\Schema\ContentSchemaReader` (→ `FieldDescriptor::name()`, `::type()`).
- Produces: `DocumentBuilder::__construct(array $typeConfig)`; `build(IndexableContent, ContentSchemaReader): array`; `validate(string $slug, ContentSchemaReader): list<string>`.

- [ ] **Step 1: Write the failing test**

`packages/lemma-search/tests/Unit/DocumentBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Tests\Unit;

use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Schema\FieldDescriptor;
use Glueful\Lemma\Contracts\Search\IndexableContent;
use Glueful\Lemma\Search\Index\DocumentBuilder;
use PHPUnit\Framework\TestCase;

final class DocumentBuilderTest extends TestCase
{
    /** @param array<string,string> $fieldTypes name => type */
    private function schema(array $fieldTypes): ContentSchemaReader
    {
        $fields = [];
        foreach ($fieldTypes as $name => $type) {
            $fields[$name] = new class ($name, $type) implements FieldDescriptor {
                public function __construct(private string $n, private string $t) {}
                public function name(): string { return $this->n; }
                public function type(): string { return $this->t; }
                public function isMultiple(): bool { return false; }
                public function referenceType(): ?string { return null; }
                public function referenceSlugField(): ?string { return null; }
                public function format(): ?string { return null; }
            };
        }
        return new class ($fields) implements ContentSchemaReader {
            /** @param array<string,FieldDescriptor> $fields */
            public function __construct(private array $fields) {}
            public function fields(): array { return array_values($this->fields); }
            public function field(string $name): ?FieldDescriptor { return $this->fields[$name] ?? null; }
        };
    }

    private function content(array $fields, string $type = 'blog', ?string $label = 'my-slug'): IndexableContent
    {
        return new IndexableContent(
            entryUuid: 'e-1', locale: 'en', contentTypeUuid: 'ct-1', contentTypeSlug: $type,
            publicDelivery: true, href: '/en/blog/my-slug', entryLabel: $label, fields: $fields,
        );
    }

    public function testConventionIndexesStringAndTextFieldsWithTitleField(): void
    {
        $builder = new DocumentBuilder([]);
        $doc = $builder->build(
            $this->content(['title' => 'Hello', 'body' => 'World', 'views' => 5]),
            $this->schema(['title' => 'string', 'body' => 'text', 'views' => 'number']),
        );

        self::assertSame('e-1:en', $doc['id']);
        self::assertSame('e-1', $doc['entry_uuid']);
        self::assertSame('en', $doc['locale']);
        self::assertSame('blog', $doc['content_type_slug']);
        self::assertSame('ct-1', $doc['content_type_uuid']);
        self::assertTrue($doc['public_delivery']);
        self::assertSame('Hello', $doc['title']);
        self::assertStringContainsString('World', $doc['body']);
        self::assertStringNotContainsString('5', $doc['body']); // number field skipped
    }

    public function testTitleFallbackChainUsesEntryLabelThenFirstStringField(): void
    {
        $builder = new DocumentBuilder([]);

        // No `title` field → entryLabel.
        $doc = $builder->build(
            $this->content(['body' => 'text here'], label: 'the-label'),
            $this->schema(['body' => 'text']),
        );
        self::assertSame('the-label', $doc['title']);

        // No `title` field and no entryLabel → first indexed string field value.
        $doc2 = $builder->build(
            $this->content(['headline' => 'First', 'body' => 'text'], label: null),
            $this->schema(['headline' => 'string', 'body' => 'text']),
        );
        self::assertSame('First', $doc2['title']);
    }

    public function testPerTypeOverrideTitleBodyExcludeAndWeightOrder(): void
    {
        $builder = new DocumentBuilder([
            'blog' => [
                'title_field' => 'headline',
                'body_fields' => ['summary', 'body'],
                'exclude_fields' => ['secret'],
                'weights' => ['summary' => 5, 'body' => 1],
            ],
        ]);

        $doc = $builder->build(
            $this->content([
                'headline' => 'H', 'summary' => 'SUM', 'body' => 'BODY', 'secret' => 'nope',
            ]),
            $this->schema(['headline' => 'string', 'summary' => 'text', 'body' => 'text', 'secret' => 'string']),
        );

        self::assertSame('H', $doc['title']);
        self::assertStringNotContainsString('nope', $doc['body']);   // excluded
        // Higher weight first: summary before body.
        self::assertLessThan(strpos($doc['body'], 'BODY'), strpos($doc['body'], 'SUM'));
    }

    public function testValidateReportsUnknownAndNonStringConfiguredFields(): void
    {
        $builder = new DocumentBuilder([
            'blog' => ['title_field' => 'ghost', 'body_fields' => ['views']],
        ]);
        $warnings = $builder->validate('blog', $this->schema(['title' => 'string', 'views' => 'number']));

        self::assertNotSame([], $warnings);
        $joined = implode(' | ', $warnings);
        self::assertStringContainsString('ghost', $joined);  // unknown field
        self::assertStringContainsString('views', $joined);  // non-string field
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/DocumentBuilderTest.php`
Expected: FAIL — `DocumentBuilder` does not exist.

- [ ] **Step 3: Implement `DocumentBuilder`**

`packages/lemma-search/src/Index/DocumentBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Index;

use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Search\IndexableContent;

/**
 * Builds the shared-index search document for one published entry+locale.
 *
 * The `lemma_content` index has two searchable attributes, `title` (ranked first) and
 * `body`. Per-type `weights` cannot re-order index-global searchable attributes, so they
 * instead order the fields concatenated into `body` (highest weight first).
 */
final class DocumentBuilder
{
    private const INDEXABLE_TYPES = ['string', 'text'];

    /** @param array<string,array<string,mixed>> $typeConfig config('lemma_search.types') */
    public function __construct(private readonly array $typeConfig)
    {
    }

    /** @return array<string,mixed> */
    public function build(IndexableContent $content, ContentSchemaReader $schema): array
    {
        $stringFields = $this->stringFieldNames($schema);
        $cfg = $this->typeConfig[$content->contentTypeSlug] ?? [];

        $exclude = array_map('strval', (array) ($cfg['exclude_fields'] ?? []));
        $selectable = array_values(array_diff($stringFields, $exclude));

        // Title.
        $titleField = isset($cfg['title_field']) ? (string) $cfg['title_field'] : 'title';
        $title = $this->stringValue($content->fields, $titleField);
        if ($title === null && $titleField === 'title') {
            // Convention chain: entryLabel → first indexed string field.
            $title = $content->entryLabel;
            if ($title === null || $title === '') {
                foreach ($selectable as $name) {
                    $v = $this->stringValue($content->fields, $name);
                    if ($v !== null && $v !== '') {
                        $title = $v;
                        break;
                    }
                }
            }
        }

        // Body field ordering.
        if (isset($cfg['body_fields'])) {
            $bodyFields = array_values(array_filter(
                array_map('strval', (array) $cfg['body_fields']),
                fn (string $f): bool => in_array($f, $selectable, true),
            ));
        } else {
            $bodyFields = array_values(array_filter($selectable, fn (string $f): bool => $f !== $titleField));
        }
        $bodyFields = $this->orderByWeight($bodyFields, (array) ($cfg['weights'] ?? []));

        $bodyParts = [];
        foreach ($bodyFields as $name) {
            $v = $this->stringValue($content->fields, $name);
            if ($v !== null && $v !== '') {
                $bodyParts[] = $v;
            }
        }

        return [
            'id' => $content->entryUuid . ':' . $content->locale,
            'entry_uuid' => $content->entryUuid,
            'locale' => $content->locale,
            'content_type_uuid' => $content->contentTypeUuid,
            'content_type_slug' => $content->contentTypeSlug,
            'public_delivery' => $content->publicDelivery,
            'href' => $content->href,
            'title' => (string) ($title ?? ''),
            'body' => implode("\n\n", $bodyParts),
        ];
    }

    /** @return list<string> Non-fatal config warnings for `search:status`. */
    public function validate(string $typeSlug, ContentSchemaReader $schema): array
    {
        $cfg = $this->typeConfig[$typeSlug] ?? [];
        $warnings = [];

        $configured = [];
        if (isset($cfg['title_field'])) {
            $configured[] = (string) $cfg['title_field'];
        }
        foreach ((array) ($cfg['body_fields'] ?? []) as $f) {
            $configured[] = (string) $f;
        }
        foreach ((array) ($cfg['exclude_fields'] ?? []) as $f) {
            $configured[] = (string) $f;
        }

        foreach ($configured as $name) {
            $field = $schema->field($name);
            if ($field === null) {
                $warnings[] = "[{$typeSlug}] configured field '{$name}' does not exist in the schema (skipped).";
                continue;
            }
            if (!in_array($field->type(), self::INDEXABLE_TYPES, true)) {
                $warnings[] = "[{$typeSlug}] configured field '{$name}' is type '{$field->type()}', not string/text (skipped).";
            }
        }

        return $warnings;
    }

    /** @return list<string> */
    private function stringFieldNames(ContentSchemaReader $schema): array
    {
        $names = [];
        foreach ($schema->fields() as $field) {
            if (in_array($field->type(), self::INDEXABLE_TYPES, true)) {
                $names[] = $field->name();
            }
        }
        return $names;
    }

    /**
     * @param list<string> $fields
     * @param array<string,mixed> $weights
     * @return list<string>
     */
    private function orderByWeight(array $fields, array $weights): array
    {
        if ($weights === []) {
            return $fields;
        }
        $keyed = array_values($fields);
        usort($keyed, function (string $a, string $b) use ($weights, $fields): int {
            $wa = (int) ($weights[$a] ?? 0);
            $wb = (int) ($weights[$b] ?? 0);
            if ($wa === $wb) {
                return array_search($a, $fields, true) <=> array_search($b, $fields, true);
            }
            return $wb <=> $wa; // higher weight first
        });
        return $keyed;
    }

    /** @param array<string,mixed> $fields */
    private function stringValue(array $fields, string $name): ?string
    {
        $v = $fields[$name] ?? null;
        return is_string($v) ? $v : null;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/DocumentBuilderTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Stage — commit only when the user authorizes**

Hold the commit until the user gives the go-ahead (review-artifact workflow). When authorized:

```bash
git add packages/lemma-search/src/Index/DocumentBuilder.php packages/lemma-search/tests/Unit/DocumentBuilderTest.php
git commit -m "search: add DocumentBuilder (convention + per-type override, config validation)"
```

---

## Task 4: MeilisearchBackend (fake-tested) + LiveMeilisearchIndex seam

The content-aware adapter and the single Meilisearch-confined class. `MeilisearchBackend` imports no meilisearch and is tested against a `FakeMeilisearchIndex`.

**Files:**
- Create: `packages/lemma-search/src/Engine/MeilisearchIndex.php` (seam)
- Create: `packages/lemma-search/src/Engine/MeilisearchBackend.php`
- Create: `packages/lemma-search/src/Engine/LiveMeilisearchIndex.php` (meilisearch-confined)
- Test: `packages/lemma-search/tests/Unit/MeilisearchBackendTest.php`

**Interfaces:**
- Consumes: `SearchBackend` (implements); `SearchRequest`; `MeilisearchIndex` (seam); meilisearch `IndexManager`, `MeilisearchClient` (in `LiveMeilisearchIndex` only).
- Produces: `MeilisearchIndex` (ensureIndex/addDocuments/deleteDocument/deleteByFilter/rawSearch/stats/reachable); `MeilisearchBackend::__construct(MeilisearchIndex $index, int $snippetLength)`; `LiveMeilisearchIndex::fromContainer(ContainerInterface, string $indexName): self`.

- [ ] **Step 1: Write the `MeilisearchIndex` seam**

`packages/lemma-search/src/Engine/MeilisearchIndex.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Engine;

/**
 * The low-level Meilisearch primitives the backend needs, as a pack-owned seam. The live
 * implementation is the ONLY class importing Glueful\Extensions\Meilisearch\*; tests use a fake.
 */
interface MeilisearchIndex
{
    /** @param array<string,mixed> $settings */
    public function ensureIndex(array $settings): void;

    /** @param list<array<string,mixed>> $documents */
    public function addDocuments(array $documents): void;

    public function deleteDocument(string $id): void;

    /** Delete every document matching a Meilisearch filter expression. */
    public function deleteByFilter(string $filter): void;

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> raw Meilisearch result (hits, estimatedTotalHits, …)
     */
    public function rawSearch(string $query, array $params): array;

    /** @return array<string,mixed> */
    public function stats(): array;

    public function reachable(): bool;
}
```

- [ ] **Step 2: Write the failing backend test (fake index)**

`packages/lemma-search/tests/Unit/MeilisearchBackendTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Tests\Unit;

use Glueful\Lemma\Search\Engine\MeilisearchBackend;
use Glueful\Lemma\Search\Engine\MeilisearchIndex;
use Glueful\Lemma\Search\Query\SearchRequest;
use PHPUnit\Framework\TestCase;

final class MeilisearchBackendTest extends TestCase
{
    private function fakeIndex(): MeilisearchIndex
    {
        return new class implements MeilisearchIndex {
            /** @var array<string,mixed> */
            public array $settings = [];
            /** @var list<array<string,mixed>> */
            public array $added = [];
            /** @var list<string> */
            public array $deletedIds = [];
            /** @var list<string> */
            public array $deletedFilters = [];
            public ?string $lastQuery = null;
            /** @var array<string,mixed> */
            public array $lastParams = [];
            public array $searchResult = ['hits' => [], 'estimatedTotalHits' => 0];
            public bool $up = true;

            public function ensureIndex(array $settings): void { $this->settings = $settings; }
            public function addDocuments(array $documents): void { foreach ($documents as $d) { $this->added[] = $d; } }
            public function deleteDocument(string $id): void { $this->deletedIds[] = $id; }
            public function deleteByFilter(string $filter): void { $this->deletedFilters[] = $filter; }
            public function rawSearch(string $query, array $params): array
            {
                $this->lastQuery = $query;
                $this->lastParams = $params;
                return $this->searchResult;
            }
            public function stats(): array { return ['numberOfDocuments' => count($this->added)]; }
            public function reachable(): bool { return $this->up; }
        };
    }

    public function testEnsureIndexAppliesSearchableAndFilterableSettings(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->ensureIndex();

        self::assertSame(['title', 'body'], $index->settings['searchableAttributes']);
        self::assertContains('content_type_uuid', $index->settings['filterableAttributes']);
        self::assertContains('public_delivery', $index->settings['filterableAttributes']);
        self::assertContains('locale', $index->settings['filterableAttributes']);
        self::assertContains('content_type_slug', $index->settings['filterableAttributes']);
    }

    public function testUpsertForwardsDocuments(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->upsert([['id' => 'e-1:en', 'title' => 'x']]);
        self::assertSame('e-1:en', $index->added[0]['id']);
    }

    public function testDeleteEntryWithLocaleDeletesSingleDocumentId(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->deleteEntry('e-1', 'en');
        self::assertSame(['e-1:en'], $index->deletedIds);
        self::assertSame([], $index->deletedFilters);
    }

    public function testDeleteEntryWithNullLocaleDeletesAllEntryDocumentsByFilter(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->deleteEntry('e-1', null);
        self::assertSame([], $index->deletedIds);
        self::assertSame(['entry_uuid = "e-1"'], $index->deletedFilters);
    }

    public function testSearchBuildsVisibilityFilterAndMapsHits(): void
    {
        $index = $this->fakeIndex();
        $index->searchResult = [
            'estimatedTotalHits' => 1,
            'hits' => [[
                'entry_uuid' => 'e-9', 'content_type_slug' => 'blog', 'locale' => 'en',
                'href' => '/en/blog/x', 'title' => 'Clean Title',
                '_rankingScore' => 0.87,
                '_formatted' => ['body' => "the \x02climate\x03 crisis <script>"],
            ]],
        ];
        $backend = new MeilisearchBackend($index, 40);

        $req = new SearchRequest('climate', 'en', null, false, ['ct-a', 'ct-b'], 20, 0);
        $results = $backend->search($req);

        // Filter: locale AND (public OR scoped-in).
        $filter = $index->lastParams['filter'];
        self::assertStringContainsString('locale = "en"', $filter);
        self::assertStringContainsString('public_delivery = true', $filter);
        self::assertStringContainsString('content_type_uuid IN ["ct-a", "ct-b"]', $filter);

        self::assertSame(1, $results->total);
        $hit = $results->hits[0];
        self::assertSame('e-9', $hit->entryUuid);
        self::assertSame('Clean Title', $hit->title); // plain text, no highlight tags
        self::assertSame(0.87, $hit->score);
        // Snippet: highlight sentinels → <mark>, surrounding markup escaped.
        self::assertStringContainsString('<mark>climate</mark>', $hit->snippet);
        self::assertStringContainsString('&lt;script&gt;', $hit->snippet);
        self::assertStringNotContainsString('<script>', $hit->snippet);
    }

    public function testSearchAllAccessOmitsTypeUuidClauseAndTypeSlugWhenProvided(): void
    {
        $index = $this->fakeIndex();
        $backend = new MeilisearchBackend($index, 40);
        $backend->search(new SearchRequest('x', 'en', 'blog', true, [], 20, 0));

        $filter = $index->lastParams['filter'];
        self::assertStringContainsString('content_type_slug = "blog"', $filter);
        // all-access ⇒ no visibility narrowing to public/scoped.
        self::assertStringNotContainsString('public_delivery = true', $filter);
        self::assertStringNotContainsString('content_type_uuid IN', $filter);
    }

    public function testHealthReflectsIndexReachability(): void
    {
        $index = $this->fakeIndex();
        $backend = new MeilisearchBackend($index, 40);
        self::assertTrue($backend->health());
        $index->up = false;
        self::assertFalse($backend->health());
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/MeilisearchBackendTest.php`
Expected: FAIL — `MeilisearchBackend` does not exist.

- [ ] **Step 4: Implement `MeilisearchBackend`**

`packages/lemma-search/src/Engine/MeilisearchBackend.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Engine;

use Glueful\Lemma\Search\Query\Hit;
use Glueful\Lemma\Search\Query\SearchRequest;
use Glueful\Lemma\Search\Query\SearchResults;

/**
 * Content-aware SearchBackend over the MeilisearchIndex seam. Imports no meilisearch —
 * translation of SearchRequest → filter/params and hits → Hit lives here and is fully
 * fake-testable. Visibility is enforced INSIDE the filter (never post-filtered).
 */
final class MeilisearchBackend implements SearchBackend
{
    // Non-printable sentinels wrap highlights so we can HTML-escape everything else safely.
    private const HL_PRE = "\x02";
    private const HL_POST = "\x03";

    private const SEARCHABLE = ['title', 'body'];
    private const FILTERABLE = ['content_type_uuid', 'content_type_slug', 'public_delivery', 'locale'];

    public function __construct(
        private readonly MeilisearchIndex $index,
        private readonly int $snippetLength,
    ) {
    }

    public function ensureIndex(): void
    {
        $this->index->ensureIndex([
            'searchableAttributes' => self::SEARCHABLE,
            'filterableAttributes' => self::FILTERABLE,
        ]);
    }

    public function upsert(iterable $documents): void
    {
        $docs = is_array($documents) ? array_values($documents) : iterator_to_array($documents, false);
        if ($docs === []) {
            return;
        }
        $this->index->addDocuments($docs);
    }

    public function deleteEntry(string $entryUuid, ?string $locale = null): void
    {
        if ($locale !== null) {
            $this->index->deleteDocument($entryUuid . ':' . $locale);
            return;
        }
        $this->index->deleteByFilter('entry_uuid = ' . $this->quote($entryUuid));
    }

    public function search(SearchRequest $request): SearchResults
    {
        $params = [
            'limit' => $request->limit,
            'offset' => $request->offset,
            'filter' => $this->buildFilter($request),
            'attributesToRetrieve' => ['entry_uuid', 'content_type_slug', 'locale', 'href', 'title'],
            'attributesToHighlight' => ['body'],
            'highlightPreTag' => self::HL_PRE,
            'highlightPostTag' => self::HL_POST,
            'attributesToCrop' => ['body'],
            'cropLength' => $this->snippetLength,
            'cropMarker' => '…',
            'showRankingScore' => true,
        ];

        $raw = $this->index->rawSearch($request->q, $params);

        $hits = [];
        foreach ((array) ($raw['hits'] ?? []) as $row) {
            $formatted = (array) ($row['_formatted'] ?? []);
            $hits[] = new Hit(
                entryUuid: (string) ($row['entry_uuid'] ?? ''),
                contentTypeSlug: (string) ($row['content_type_slug'] ?? ''),
                locale: (string) ($row['locale'] ?? ''),
                href: (string) ($row['href'] ?? ''),
                title: (string) ($row['title'] ?? ''),
                snippet: $this->safeSnippet((string) ($formatted['body'] ?? ($row['body'] ?? ''))),
                score: (float) ($row['_rankingScore'] ?? 0.0),
            );
        }

        return new SearchResults(
            hits: $hits,
            total: (int) ($raw['estimatedTotalHits'] ?? count($hits)),
            limit: $request->limit,
            offset: $request->offset,
        );
    }

    public function health(): bool
    {
        return $this->index->reachable();
    }

    private function buildFilter(SearchRequest $request): string
    {
        $clauses = ['locale = ' . $this->quote($request->locale)];

        if (!$request->allAccess) {
            $visible = ['public_delivery = true'];
            if ($request->scopedTypeUuids !== []) {
                $ids = implode(', ', array_map([$this, 'quote'], $request->scopedTypeUuids));
                $visible[] = 'content_type_uuid IN [' . $ids . ']';
            }
            $clauses[] = '(' . implode(' OR ', $visible) . ')';
        }

        if ($request->typeSlug !== null) {
            $clauses[] = 'content_type_slug = ' . $this->quote($request->typeSlug);
        }

        return implode(' AND ', $clauses);
    }

    /** HTML-escape everything except the highlight sentinels, which become <mark></mark>. */
    private function safeSnippet(string $formatted): string
    {
        $escaped = htmlspecialchars($formatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return str_replace(
            [self::HL_PRE, self::HL_POST],
            ['<mark>', '</mark>'],
            $escaped,
        );
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/MeilisearchBackendTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Implement `LiveMeilisearchIndex` (the single Meilisearch-confined class)**

`packages/lemma-search/src/Engine/LiveMeilisearchIndex.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Engine;

use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The ONLY class in this pack that imports Glueful\Extensions\Meilisearch\*. Wraps the
 * extension's IndexManager (lifecycle/settings/stats) and the raw index endpoint
 * (documents + search) for the pack-owned MeilisearchIndex seam.
 */
final class LiveMeilisearchIndex implements MeilisearchIndex
{
    public function __construct(
        private readonly IndexManager $manager,
        private readonly string $indexName,
    ) {
    }

    public static function fromContainer(ContainerInterface $container, string $indexName): self
    {
        return new self($container->get(IndexManager::class), $indexName);
    }

    public function ensureIndex(array $settings): void
    {
        $this->manager->getOrCreateIndex($this->indexName);
        $this->manager->updateSettings($this->indexName, $settings);
    }

    public function addDocuments(array $documents): void
    {
        // 'id' is the Meilisearch primary key by convention (see IndexManager::createIndex).
        $this->manager->getOrCreateIndex($this->indexName)->addDocuments($documents, 'id');
    }

    public function deleteDocument(string $id): void
    {
        $this->manager->getOrCreateIndex($this->indexName)->deleteDocument($id);
    }

    public function deleteByFilter(string $filter): void
    {
        // meilisearch-php: filtered delete via deleteDocuments(['filter' => …]).
        $this->manager->getOrCreateIndex($this->indexName)->deleteDocuments(['filter' => $filter]);
    }

    public function rawSearch(string $query, array $params): array
    {
        return $this->manager->getOrCreateIndex($this->indexName)->search($query, $params)->toArray();
    }

    public function stats(): array
    {
        return $this->manager->getStats($this->indexName);
    }

    public function reachable(): bool
    {
        try {
            $this->manager->getStats($this->indexName);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
```

> Verify against the installed `meilisearch/meilisearch-php` SDK that `Indexes` exposes `addDocuments(array, ?string)`, `deleteDocument(string)`, `deleteDocuments(array)`, and `search(?string, array): SearchResult` with `toArray()`. If a method name differs in the pinned version, adapt this wrapper only (it is the confinement point). This class has no unit test (it is pure delegation to a live server); it is exercised by manual `search:status`/`search:reindex` against a real Meilisearch and by the fake in the backend tests.

- [ ] **Step 7: Stage — commit only when the user authorizes**

Hold the commit until the user gives the go-ahead (review-artifact workflow). When authorized:

```bash
git add packages/lemma-search/src/Engine/MeilisearchIndex.php packages/lemma-search/src/Engine/MeilisearchBackend.php packages/lemma-search/src/Engine/LiveMeilisearchIndex.php packages/lemma-search/tests/Unit/MeilisearchBackendTest.php
git commit -m "search: add MeilisearchBackend (fake-tested) + Meilisearch-confined LiveMeilisearchIndex"
```

---

## Task 5: SearchContentReindexer + resilient/null decorators + provider bindings

Wires the live-reindex seam and registers `SearchBackend`, `DocumentBuilder`, and a capability-gated `ContentReindexer`.

**Files:**
- Create: `packages/lemma-search/src/Index/SearchContentReindexer.php`
- Create: `packages/lemma-search/src/Index/ResilientContentReindexer.php`
- Create: `packages/lemma-search/src/Index/NullContentReindexer.php`
- Modify: `packages/lemma-search/src/LemmaSearchServiceProvider.php`
- Test: `packages/lemma-search/tests/Unit/SearchContentReindexerTest.php`

**Interfaces:**
- Consumes: `ContentReindexer` (implements); `IndexableContentReader`; `DocumentBuilder`; `SearchBackend`; `ContentTypeReader`; `CapabilityRegistry`; meilisearch `IndexManager` (via `LiveMeilisearchIndex::fromContainer`); `Psr\Log\LoggerInterface`.
- Produces: `SearchContentReindexer`; `ResilientContentReindexer`; `NullContentReindexer`; provider factories `makeSearchBackend`, `makeDocumentBuilder`, `makeContentReindexer`.

- [ ] **Step 1: Write the failing reindexer test**

`packages/lemma-search/tests/Unit/SearchContentReindexerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Tests\Unit;

use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Contracts\Search\IndexableContent;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Contracts\Search\IndexablePage;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Index\DocumentBuilder;
use Glueful\Lemma\Search\Index\ResilientContentReindexer;
use Glueful\Lemma\Search\Index\SearchContentReindexer;
use Glueful\Lemma\Search\Query\SearchRequest;
use Glueful\Lemma\Search\Query\SearchResults;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class SearchContentReindexerTest extends TestCase
{
    /** A SearchBackend spy recording upserts/deletes. */
    private function backend(): SearchBackend
    {
        return new class implements SearchBackend {
            public array $upserted = [];
            public array $deletes = [];
            public bool $throwOnUpsert = false;
            public function ensureIndex(): void {}
            public function upsert(iterable $documents): void
            {
                if ($this->throwOnUpsert) { throw new RuntimeException('meili down'); }
                foreach ($documents as $d) { $this->upserted[] = $d; }
            }
            public function deleteEntry(string $entryUuid, ?string $locale = null): void
            {
                $this->deletes[] = [$entryUuid, $locale];
            }
            public function search(SearchRequest $r): SearchResults { return new SearchResults([], 0, 20, 0); }
            public function health(): bool { return true; }
        };
    }

    private function reader(?IndexableContent $record): IndexableContentReader
    {
        return new class ($record) implements IndexableContentReader {
            public function __construct(private ?IndexableContent $record) {}
            public function getIndexablePublished(string $e, string $l): ?IndexableContent { return $this->record; }
            public function enumerateIndexablePublished(int $limit, int $offset = 0, ?string $t = null, ?string $l = null): IndexablePage
            {
                return new IndexablePage([], 0, $limit, $offset);
            }
        };
    }

    private function types(): ContentTypeReader
    {
        return new class implements ContentTypeReader {
            public function findUuidBySlug(string $slug): ?string { return null; }
            public function isPublicDelivery(string $uuid): bool { return true; }
            public function schemaFor(string $uuid): ?ContentSchemaReader
            {
                return new class implements ContentSchemaReader {
                    public function fields(): array { return []; }
                    public function field(string $name): ?\Glueful\Lemma\Contracts\Schema\FieldDescriptor { return null; }
                };
            }
        };
    }

    private function record(): IndexableContent
    {
        return new IndexableContent('e-1', 'en', 'ct-1', 'blog', true, '/en/blog/x', 'x', ['title' => 'T']);
    }

    public function testNullLocaleDeletesAllEntryDocuments(): void
    {
        $backend = $this->backend();
        $r = new SearchContentReindexer($this->reader(null), new DocumentBuilder([]), $backend, $this->types());
        $r->reindexEntry('e-1', null);
        self::assertSame([['e-1', null]], $backend->deletes);
        self::assertSame([], $backend->upserted);
    }

    public function testMissingRecordForLocaleDeletesThatLocaleDocument(): void
    {
        $backend = $this->backend();
        $r = new SearchContentReindexer($this->reader(null), new DocumentBuilder([]), $backend, $this->types());
        $r->reindexEntry('e-1', 'en');
        self::assertSame([['e-1', 'en']], $backend->deletes);
        self::assertSame([], $backend->upserted);
    }

    public function testPresentRecordUpsertsBuiltDocument(): void
    {
        $backend = $this->backend();
        $r = new SearchContentReindexer($this->reader($this->record()), new DocumentBuilder([]), $backend, $this->types());
        $r->reindexEntry('e-1', 'en');
        self::assertSame([], $backend->deletes);
        self::assertSame('e-1:en', $backend->upserted[0]['id']);
    }

    public function testResilientDecoratorSwallowsBackendFailures(): void
    {
        $backend = $this->backend();
        $backend->throwOnUpsert = true;
        $inner = new SearchContentReindexer($this->reader($this->record()), new DocumentBuilder([]), $backend, $this->types());
        $resilient = new ResilientContentReindexer($inner, new NullLogger());

        // Must NOT throw — publishing must never break on a search failure.
        $resilient->reindexEntry('e-1', 'en');
        self::assertTrue(true);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/SearchContentReindexerTest.php`
Expected: FAIL — reindexer classes do not exist.

- [ ] **Step 3: Implement the three reindexer classes**

`packages/lemma-search/src/Index/SearchContentReindexer.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Index;

use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Contracts\Search\ContentReindexer;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Search\Engine\SearchBackend;

/**
 * Reindexes a published entry/locale via the delivery-backed reader and the search backend.
 * locale === null (whole-entry delete) purges every locale doc; otherwise re-reads and
 * upserts, or deletes this locale's doc if the entry is no longer published/visible.
 */
final class SearchContentReindexer implements ContentReindexer
{
    public function __construct(
        private readonly IndexableContentReader $reader,
        private readonly DocumentBuilder $builder,
        private readonly SearchBackend $backend,
        private readonly ContentTypeReader $types,
    ) {
    }

    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
        if ($locale === null) {
            $this->backend->deleteEntry($entryUuid, null);
            return;
        }

        $record = $this->reader->getIndexablePublished($entryUuid, $locale);
        if ($record === null) {
            $this->backend->deleteEntry($entryUuid, $locale);
            return;
        }

        $schema = $this->types->schemaFor($record->contentTypeUuid);
        if ($schema === null) {
            $this->backend->deleteEntry($entryUuid, $locale);
            return;
        }

        $this->backend->upsert([$this->builder->build($record, $schema)]);
    }
}
```

`packages/lemma-search/src/Index/ResilientContentReindexer.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Index;

use Glueful\Lemma\Contracts\Search\ContentReindexer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wraps the real reindexer so a search-backend failure is caught + logged and NEVER breaks
 * publishing (the seam runs in the pipeline's afterCommit). `search:reindex` recovers later.
 */
final class ResilientContentReindexer implements ContentReindexer
{
    public function __construct(
        private readonly ContentReindexer $inner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
        try {
            $this->inner->reindexEntry($entryUuid, $locale);
        } catch (Throwable $e) {
            $this->logger->warning('lemma-search reindex failed; skipping (recover via search:reindex).', [
                'entry' => $entryUuid,
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

`packages/lemma-search/src/Index/NullContentReindexer.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Index;

use Glueful\Lemma\Contracts\Search\ContentReindexer;

/** Bound when lemma.search is disabled: the reindex listener resolves this and no-ops. */
final class NullContentReindexer implements ContentReindexer
{
    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/SearchContentReindexerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Register services + capability-gated `ContentReindexer` in the provider**

Replace the `services()` body and add factories in `packages/lemma-search/src/LemmaSearchServiceProvider.php`. Add the `use` imports:

```php
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Contracts\Search\ContentReindexer;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Search\Engine\LiveMeilisearchIndex;
use Glueful\Lemma\Search\Engine\MeilisearchBackend;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Index\DocumentBuilder;
use Glueful\Lemma\Search\Index\NullContentReindexer;
use Glueful\Lemma\Search\Index\ResilientContentReindexer;
use Glueful\Lemma\Search\Index\SearchContentReindexer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
```

`services()`:

```php
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            SearchBackend::class => [
                'shared' => true, 'factory' => [self::class, 'makeSearchBackend'],
            ],
            DocumentBuilder::class => [
                'shared' => true, 'factory' => [self::class, 'makeDocumentBuilder'],
            ],
            SearchContentReindexer::class => [
                'class' => SearchContentReindexer::class, 'shared' => true, 'autowire' => true,
            ],
            ContentReindexer::class => [
                'shared' => true, 'factory' => [self::class, 'makeContentReindexer'],
            ],
        ];
    }

    public static function makeSearchBackend(ContainerInterface $container): MeilisearchBackend
    {
        $context = $container->get(ApplicationContext::class);
        $indexName = (string) config($context, 'lemma_search.index', 'lemma_content');
        $snippetLength = (int) config($context, 'lemma_search.snippet_length', 40);

        return new MeilisearchBackend(
            LiveMeilisearchIndex::fromContainer($container, $indexName),
            $snippetLength,
        );
    }

    public static function makeDocumentBuilder(ContainerInterface $container): DocumentBuilder
    {
        $context = $container->get(ApplicationContext::class);
        /** @var array<string,array<string,mixed>> $types */
        $types = (array) config($context, 'lemma_search.types', []);
        return new DocumentBuilder($types);
    }

    public static function makeContentReindexer(ContainerInterface $container): ContentReindexer
    {
        $context = $container->get(ApplicationContext::class);
        $registry = app($context, CapabilityRegistry::class);

        // Disabled ⇒ no-op reindexer (the App listener resolves this and does nothing).
        if (!$registry->isEnabled('lemma.search')) {
            return new NullContentReindexer();
        }

        $inner = new SearchContentReindexer(
            $container->get(IndexableContentReader::class),
            $container->get(DocumentBuilder::class),
            $container->get(SearchBackend::class),
            $container->get(ContentTypeReader::class),
        );

        return new ResilientContentReindexer($inner, $container->get(LoggerInterface::class));
    }
```

> `SearchContentReindexer` autowiring requires all four constructor deps to be resolvable by type — they are (contracts bound App-side + pack services). If the container cannot autowire it, switch its definition to a factory mirroring `makeContentReindexer`'s `$inner` construction.

- [ ] **Step 6: Run the pack unit suite + rebuild container**

Run: `php glueful cache:clear && vendor/bin/phpunit packages/lemma-search/tests/Unit`
Expected: PASS (all unit tests across Tasks 2–5).

- [ ] **Step 7: Stage — commit only when the user authorizes**

Hold the commit until the user gives the go-ahead (review-artifact workflow). When authorized:

```bash
git add packages/lemma-search/src/Index/SearchContentReindexer.php packages/lemma-search/src/Index/ResilientContentReindexer.php packages/lemma-search/src/Index/NullContentReindexer.php packages/lemma-search/src/LemmaSearchServiceProvider.php packages/lemma-search/tests/Unit/SearchContentReindexerTest.php
git commit -m "search: add reindexer + resilient/null decorators + capability-gated bindings"
```

---

## Task 6: VisibilityResolver

Maps request scopes to a delivery-parity visibility decision, and encodes the 403-vs-empty distinction.

**Files:**
- Create: `packages/lemma-search/src/Query/VisibilityContext.php`
- Create: `packages/lemma-search/src/Query/VisibilityResolver.php`
- Test: `packages/lemma-search/tests/Unit/VisibilityResolverTest.php`

**Interfaces:**
- Consumes: `ContentTypeReader` (`findUuidBySlug`, `isPublicDelivery`); `Glueful\Auth\ApiKey\ApiKeyService::scopeSatisfies`.
- Produces: `VisibilityContext` (readonly `bool $allAccess`, `list<string> $scopedTypeUuids`); `VisibilityResolver::resolve(?array $grantedScopes): VisibilityContext`; `VisibilityResolver::isTypeAccessible(VisibilityContext, string $uuid): bool`.

- [ ] **Step 1: Write the failing test**

`packages/lemma-search/tests/Unit/VisibilityResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Tests\Unit;

use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Search\Query\VisibilityResolver;
use PHPUnit\Framework\TestCase;

final class VisibilityResolverTest extends TestCase
{
    /**
     * @param array<string,string> $slugToUuid
     * @param list<string> $publicUuids
     */
    private function types(array $slugToUuid, array $publicUuids = []): ContentTypeReader
    {
        return new class ($slugToUuid, $publicUuids) implements ContentTypeReader {
            /** @param array<string,string> $map @param list<string> $public */
            public function __construct(private array $map, private array $public) {}
            public function findUuidBySlug(string $slug): ?string { return $this->map[$slug] ?? null; }
            public function isPublicDelivery(string $uuid): bool { return in_array($uuid, $this->public, true); }
            public function schemaFor(string $uuid): ?ContentSchemaReader { return null; }
        };
    }

    public function testAnonymousHasNoAccess(): void
    {
        $ctx = (new VisibilityResolver($this->types([])))->resolve(null);
        self::assertFalse($ctx->allAccess);
        self::assertSame([], $ctx->scopedTypeUuids);
    }

    public function testReadContentGrantsAllAccess(): void
    {
        $ctx = (new VisibilityResolver($this->types([])))->resolve(['read:content']);
        self::assertTrue($ctx->allAccess);
    }

    public function testEmptyScopesArrayIsFullAccessKey(): void
    {
        // A key with NO scope restriction ([]) satisfies everything (ApiKeyService semantics).
        $ctx = (new VisibilityResolver($this->types([])))->resolve([]);
        self::assertTrue($ctx->allAccess);
    }

    public function testScopedSlugsResolveToUuids(): void
    {
        $resolver = new VisibilityResolver($this->types(['blog' => 'ct-blog', 'news' => 'ct-news']));
        $ctx = $resolver->resolve(['read:content:blog', 'read:content:news', 'read:other']);
        self::assertFalse($ctx->allAccess);
        self::assertContains('ct-blog', $ctx->scopedTypeUuids);
        self::assertContains('ct-news', $ctx->scopedTypeUuids);
    }

    public function testIsTypeAccessibleMirrorsDeliveryParity(): void
    {
        $resolver = new VisibilityResolver(
            $this->types(['blog' => 'ct-blog', 'secret' => 'ct-secret'], publicUuids: ['ct-pub']),
        );

        $scoped = $resolver->resolve(['read:content:blog']);
        self::assertTrue($resolver->isTypeAccessible($scoped, 'ct-blog'));    // scoped
        self::assertTrue($resolver->isTypeAccessible($scoped, 'ct-pub'));     // public_delivery
        self::assertFalse($resolver->isTypeAccessible($scoped, 'ct-secret')); // neither → 403

        $all = $resolver->resolve(['read:content']);
        self::assertTrue($resolver->isTypeAccessible($all, 'ct-secret'));     // all-access
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/VisibilityResolverTest.php`
Expected: FAIL — classes do not exist.

- [ ] **Step 3: Implement `VisibilityContext` and `VisibilityResolver`**

`packages/lemma-search/src/Query/VisibilityContext.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Query;

/** The resolved visibility for one request. */
final class VisibilityContext
{
    /** @param list<string> $scopedTypeUuids */
    public function __construct(
        public readonly bool $allAccess,
        public readonly array $scopedTypeUuids,
    ) {
    }
}
```

`packages/lemma-search/src/Query/VisibilityResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Query;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;

/**
 * Mirrors DeliveryAccessMiddleware for search: read:content ⇒ all-access; else explicit
 * read:content:{slug} scopes → scoped type uuids; anonymous (null scopes) ⇒ public-only.
 */
final class VisibilityResolver
{
    public function __construct(private readonly ContentTypeReader $types)
    {
    }

    /** @param list<string>|null $grantedScopes null = anonymous (no api_key_scopes attribute). */
    public function resolve(?array $grantedScopes): VisibilityContext
    {
        if ($grantedScopes === null) {
            return new VisibilityContext(false, []);
        }

        if (ApiKeyService::scopeSatisfies($grantedScopes, 'read:content')) {
            return new VisibilityContext(true, []);
        }

        $scoped = [];
        foreach ($grantedScopes as $scope) {
            if (!is_string($scope) || !str_starts_with($scope, 'read:content:')) {
                continue;
            }
            $slug = substr($scope, strlen('read:content:'));
            // Only explicit slugs (no wildcard) resolve to a scoped uuid.
            if ($slug === '' || str_contains($slug, '*')) {
                continue;
            }
            $uuid = $this->types->findUuidBySlug($slug);
            if ($uuid !== null && !in_array($uuid, $scoped, true)) {
                $scoped[] = $uuid;
            }
        }

        return new VisibilityContext(false, $scoped);
    }

    /** Delivery-parity accessibility for a PROVIDED type: all-access, scoped, or public. */
    public function isTypeAccessible(VisibilityContext $ctx, string $typeUuid): bool
    {
        return $ctx->allAccess
            || in_array($typeUuid, $ctx->scopedTypeUuids, true)
            || $this->types->isPublicDelivery($typeUuid);
    }
}
```

> `VisibilityResolver` autowires (single `ContentTypeReader` dep). Register it in `services()` in Task 7 alongside the controller.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/VisibilityResolverTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Stage — commit only when the user authorizes**

Hold the commit until the user gives the go-ahead (review-artifact workflow). When authorized:

```bash
git add packages/lemma-search/src/Query/VisibilityContext.php packages/lemma-search/src/Query/VisibilityResolver.php packages/lemma-search/tests/Unit/VisibilityResolverTest.php
git commit -m "search: add VisibilityResolver (delivery-parity, 403-vs-empty)"
```

---

## Task 7: SearchController + route + endpoint/removability tests

The public HTTP surface, wired behind `optional_api_key`, with the full validation/visibility/503 contract, and the removability gate.

**Files:**
- Create: `packages/lemma-search/src/Http/SearchController.php`
- Create: `packages/lemma-search/routes/public-routes.php`
- Modify: `packages/lemma-search/src/LemmaSearchServiceProvider.php` (register controller + resolver; gate routes in `boot()`)
- Test: `tests/Integration/Search/SearchEndpointTest.php`
- Test: `tests/Integration/Search/SearchRemovabilityTest.php`

**Interfaces:**
- Consumes: `SearchBackend`, `VisibilityResolver`, `ContentTypeReader`; `Symfony\Component\HttpFoundation\Request`; `Glueful\Http\Response`.
- Produces: `SearchController::search(Request): Response`; route `GET /v1/search`; provider `makeSearchController` factory.

- [ ] **Step 1: Write the failing endpoint test (bound fake backend)**

`tests/Integration/Search/SearchEndpointTest.php`. It binds a fake `SearchBackend` into the live container so no Meilisearch server is needed, and drives the real kernel via `LemmaTestCase::handle()`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Query\Hit;
use Glueful\Lemma\Search\Query\SearchRequest;
use Glueful\Lemma\Search\Query\SearchResults;
use Symfony\Component\HttpFoundation\Request;

final class SearchEndpointTest extends LemmaTestCase
{
    private function bindBackend(SearchBackend $backend): void
    {
        // The container is a compiled Symfony container; set() replaces the shared instance
        // for this process. If set() is unavailable, see the note below.
        $this->container()->set(SearchBackend::class, $backend);
    }

    private function fakeBackend(bool $healthy = true, array $hits = [], int $total = 0): SearchBackend
    {
        return new class ($healthy, $hits, $total) implements SearchBackend {
            /** @param list<Hit> $hits */
            public function __construct(private bool $healthy, private array $hits, private int $total) {}
            public function ensureIndex(): void {}
            public function upsert(iterable $documents): void {}
            public function deleteEntry(string $entryUuid, ?string $locale = null): void {}
            public function search(SearchRequest $r): SearchResults
            {
                return new SearchResults($this->hits, $this->total, $r->limit, $r->offset);
            }
            public function health(): bool { return $this->healthy; }
        };
    }

    private function get(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return $this->handle(Request::create($path, 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]));
    }

    public function testHappyPathMapsHitsToContract(): void
    {
        $hit = new Hit('e-1', 'blog', 'en', '/en/blog/x', 'Title', 'a <mark>climate</mark> b', 0.9);
        $this->bindBackend($this->fakeBackend(hits: [$hit], total: 1));

        $resp = $this->get('/v1/search?q=climate&locale=en');
        self::assertSame(200, $resp->getStatusCode());

        // Glueful\Http\Response::success() nests the payload under `data` (verified against the
        // framework). The search payload {hits,total,limit,offset} lives at $body['data'].
        $body = json_decode((string) $resp->getContent(), true);
        self::assertSame(1, $body['data']['total']);
        self::assertSame('e-1', $body['data']['hits'][0]['uuid']);
        self::assertSame('blog', $body['data']['hits'][0]['type']);
        self::assertSame('en', $body['data']['hits'][0]['locale']);
        self::assertSame('/en/blog/x', $body['data']['hits'][0]['href']);
        self::assertSame('Title', $body['data']['hits'][0]['title']);
        self::assertStringContainsString('<mark>climate</mark>', $body['data']['hits'][0]['snippet']);
        self::assertSame(0.9, $body['data']['hits'][0]['score']);
    }

    public function testMissingQueryReturns422(): void
    {
        $this->bindBackend($this->fakeBackend());
        self::assertSame(422, $this->get('/v1/search?locale=en')->getStatusCode());
        self::assertSame(422, $this->get('/v1/search?q=&locale=en')->getStatusCode());
    }

    public function testMissingLocaleReturns422(): void
    {
        $this->bindBackend($this->fakeBackend());
        self::assertSame(422, $this->get('/v1/search?q=hello')->getStatusCode());
    }

    public function testUnknownTypeReturns404(): void
    {
        $this->bindBackend($this->fakeBackend());
        self::assertSame(404, $this->get('/v1/search?q=hi&locale=en&type=no-such-type')->getStatusCode());
    }

    public function testProvidedInaccessibleTypeReturns403(): void
    {
        // Seed a NON-public content type; anonymous request provides it → 403 (delivery parity).
        $this->connection()->table('content_types')->insert([
            'uuid' => 'ct-secret', 'slug' => 'secret', 'name' => 'Secret',
            'public_delivery' => false, 'status' => 'active',
            'schema' => json_encode(['fields' => []]), 'schema_version' => 1,
            'created_at' => date('c'), 'updated_at' => date('c'),
        ]);
        $this->bindBackend($this->fakeBackend());
        self::assertSame(403, $this->get('/v1/search?q=hi&locale=en&type=secret')->getStatusCode());
    }

    public function testUnhealthyBackendReturns503(): void
    {
        $this->bindBackend($this->fakeBackend(healthy: false));
        self::assertSame(503, $this->get('/v1/search?q=hi&locale=en')->getStatusCode());
    }
}
```

> **If `ContainerInterface::set()` is not available** on the compiled container: register a test-only override the way other Lemma integration tests substitute services (grep `tests/Integration` for how they replace a bound service — e.g. constructing the controller directly like `SitemapEndpointTest` does with `SitemapController`). Fall back to directly constructing `SearchController` with the fake backend + real `VisibilityResolver`/`ContentTypeReader` from the container and calling `->search()`, for the 422/403/404/503/happy assertions; keep the removability route-registration checks in the separate removability test. Do not weaken any assertion.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Search/SearchEndpointTest.php`
Expected: FAIL — `/v1/search` route + controller do not exist.

- [ ] **Step 3: Implement `SearchController`**

`packages/lemma-search/src/Http/SearchController.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Http;

use Glueful\Http\Response;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Query\SearchRequest;
use Glueful\Lemma\Search\Query\VisibilityResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public content search. Behind `optional_api_key`: an authenticated key narrows visibility
 * to its scopes; anonymous sees only public_delivery content. Visibility is enforced inside
 * the backend filter, so `total`/pagination are correct.
 */
final class SearchController
{
    public function __construct(
        private readonly SearchBackend $backend,
        private readonly VisibilityResolver $visibility,
        private readonly ContentTypeReader $types,
        private readonly int $defaultLimit,
        private readonly int $maxLimit,
    ) {
    }

    public function search(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return Response::error('A non-empty `q` query parameter is required.', 422);
        }

        $locale = trim((string) $request->query->get('locale', ''));
        if ($locale === '') {
            return Response::error('A `locale` query parameter is required.', 422);
        }

        // null = anonymous (no key); an array (possibly empty) = an authenticated key.
        $grantedScopes = $request->attributes->has('api_key_scopes')
            ? array_values(array_filter((array) $request->attributes->get('api_key_scopes', []), 'is_string'))
            : null;
        $ctx = $this->visibility->resolve($grantedScopes);

        $typeSlug = trim((string) $request->query->get('type', ''));
        if ($typeSlug !== '') {
            $typeUuid = $this->types->findUuidBySlug($typeSlug);
            if ($typeUuid === null) {
                return Response::notFound('Content type not found.');
            }
            if (!$this->visibility->isTypeAccessible($ctx, $typeUuid)) {
                return Response::forbidden('This content type requires a scoped API key');
            }
        } else {
            $typeSlug = null;
        }

        if (!$this->backend->health()) {
            return Response::error('Search is temporarily unavailable.', 503);
        }

        $limit = $this->clamp((int) $request->query->get('limit', $this->defaultLimit), 1, $this->maxLimit);
        $offset = max(0, (int) $request->query->get('offset', 0));

        $results = $this->backend->search(new SearchRequest(
            q: $q,
            locale: $locale,
            typeSlug: $typeSlug,
            allAccess: $ctx->allAccess,
            scopedTypeUuids: $ctx->scopedTypeUuids,
            limit: $limit,
            offset: $offset,
        ));

        $hits = [];
        foreach ($results->hits as $hit) {
            $hits[] = [
                'uuid' => $hit->entryUuid,
                'type' => $hit->contentTypeSlug,
                'locale' => $hit->locale,
                'href' => $hit->href,
                'title' => $hit->title,
                'snippet' => $hit->snippet,
                'score' => $hit->score,
            ];
        }

        return Response::success([
            'hits' => $hits,
            'total' => $results->total,
            'limit' => $results->limit,
            'offset' => $results->offset,
        ]);
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
```

> **Response envelope (verified):** `Glueful\Http\Response::success($data)` wraps the payload under a top-level `data` key — the standard Lemma/framework JSON envelope every other endpoint uses. So `Response::success(['hits'=>…,'total'=>…,'limit'=>…,'offset'=>…])` serializes as `{ …, "data": { "hits": […], "total": …, "limit": …, "offset": … } }`. This is intentional for API consistency with the delivery endpoints; it supersedes spec §5's *illustrative* top-level snippet (which showed the payload un-enveloped). The endpoint test (Step 1) and the README (Task 8) both assert/document the `data`-wrapped shape. `Response::error(string, int)`, `Response::notFound(string)`, and `Response::forbidden(string)` are confirmed present (`forbidden`/`notFound` are used by `DeliveryAccessMiddleware`; `success`/`error` verified in `src/Http/Response.php`). If `error(string,int)` turns out to take arguments in a different order, adjust the controller calls only — keep the HTTP status codes (422/404/403/503) exact.

- [ ] **Step 4: Write the route and register controller/resolver; gate routes in `boot()`**

`packages/lemma-search/routes/public-routes.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Lemma\Search\Http\SearchController;
use Glueful\Routing\Router;

/** @var Router $router */

// Public content search. Optional API key narrows visibility; anonymous sees public content.
$router->get('/v1/search', [SearchController::class, 'search'])
    ->middleware('optional_api_key')
    ->middleware('rate_limit')
    ->rateLimit(120, 1, by: 'user');
```

In `LemmaSearchServiceProvider`, add imports:

```php
use Glueful\Lemma\Search\Http\SearchController;
use Glueful\Lemma\Search\Query\VisibilityResolver;
```

Add to `services()`:

```php
            VisibilityResolver::class => [
                'class' => VisibilityResolver::class, 'shared' => true, 'autowire' => true,
            ],
            SearchController::class => [
                'shared' => true, 'factory' => [self::class, 'makeSearchController'],
            ],
```

Add the factory:

```php
    public static function makeSearchController(ContainerInterface $container): SearchController
    {
        $context = $container->get(ApplicationContext::class);
        return new SearchController(
            $container->get(SearchBackend::class),
            $container->get(VisibilityResolver::class),
            $container->get(ContentTypeReader::class),
            (int) config($context, 'lemma_search.default_limit', 20),
            (int) config($context, 'lemma_search.max_limit', 50),
        );
    }
```

Extend `boot()` to gate the route file on the capability (append after the `register(new Capability(...))` call):

```php
        if ($registry->isEnabled('lemma.search')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/public-routes.php');
        }
```

- [ ] **Step 5: Run the endpoint test**

Run: `php glueful cache:clear && vendor/bin/phpunit tests/Integration/Search/SearchEndpointTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Write and run the removability test**

`tests/Integration/Search/SearchRemovabilityTest.php` (mirror `SeoRemovabilityTest` exactly — dedicated disabled boot with a temp `config/testing/lemma.php` override, cleaned in `finally`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Tests\Support\LemmaTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Lemma\Contracts\Search\ContentReindexer;
use Glueful\Lemma\Search\Index\NullContentReindexer;
use Glueful\Routing\RouteManifest;
use Symfony\Component\HttpFoundation\Request;

final class SearchRemovabilityTest extends LemmaTestCase
{
    private static ?ApplicationContext $disabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$disabledApp !== null) {
            return;
        }

        $root = dirname(__DIR__, 3);
        $overrideDir = $root . '/config/testing';
        $overrideFile = $overrideDir . '/lemma.php';
        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }
        file_put_contents(
            $overrideFile,
            "<?php\nreturn ['capabilities' => ['lemma.search' => false]];\n",
        );

        RouteManifest::reset();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        try {
            self::$disabledApp = Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot()
                ->getContext();
        } finally {
            @unlink($overrideFile);
            if (is_dir($overrideDir) && count((array) scandir($overrideDir)) === 2) {
                @rmdir($overrideDir);
            }
        }

        RouteManifest::reset();
    }

    public function testSearchRouteAbsentWhenDisabled(): void
    {
        $status = (new Application(self::$disabledApp))->handle(
            Request::create('/v1/search?q=x&locale=en', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        )->getStatusCode();
        self::assertSame(404, $status);
    }

    public function testReindexerResolvesToNoOpWhenDisabled(): void
    {
        $reindexer = self::$disabledApp->getContainer()->get(ContentReindexer::class);
        self::assertInstanceOf(NullContentReindexer::class, $reindexer);
    }
}
```

Run: `vendor/bin/phpunit tests/Integration/Search/SearchRemovabilityTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Stage — commit only when the user authorizes**

Hold the commit until the user gives the go-ahead (review-artifact workflow). When authorized:

```bash
git add packages/lemma-search/src/Http/SearchController.php packages/lemma-search/routes/public-routes.php packages/lemma-search/src/LemmaSearchServiceProvider.php tests/Integration/Search/SearchEndpointTest.php tests/Integration/Search/SearchRemovabilityTest.php
git commit -m "search: add public GET /v1/search endpoint + route gate + removability tests"
```

---

## Task 8: Operator commands (search:reindex, search:status) + README

Backfill and doctor CLI, registered under the capability gate; pack documentation.

**Files:**
- Create: `packages/lemma-search/src/Console/ReindexCommand.php`
- Create: `packages/lemma-search/src/Console/StatusCommand.php`
- Create: `packages/lemma-search/README.md`
- Modify: `packages/lemma-search/src/LemmaSearchServiceProvider.php` (register + gate commands)
- Test: `packages/lemma-search/tests/Unit/ReindexCommandTest.php`

**Interfaces:**
- Consumes: `IndexableContentReader`, `DocumentBuilder`, `SearchBackend`, `ContentTypeReader`; `Glueful\Console\BaseCommand`.
- Produces: `ReindexCommand::backfill(?string $type, ?string $locale): int` (documents indexed); commands `search:reindex`, `search:status`.

- [ ] **Step 1: Write the failing reindex-logic test**

`ReindexCommand` exposes a pure `backfill()` method (like `PruneAnalyticsCommand::prune`) so its paging/build/upsert loop is unit-testable without the console runner.

`packages/lemma-search/tests/Unit/ReindexCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Tests\Unit;

use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Contracts\Search\IndexableContent;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Contracts\Search\IndexablePage;
use Glueful\Lemma\Search\Console\ReindexCommand;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Index\DocumentBuilder;
use Glueful\Lemma\Search\Query\SearchRequest;
use Glueful\Lemma\Search\Query\SearchResults;
use PHPUnit\Framework\TestCase;

final class ReindexCommandTest extends TestCase
{
    public function testBackfillEnsuresIndexPagesAndUpsertsAllRecords(): void
    {
        $records = [
            new IndexableContent('e-1', 'en', 'ct-1', 'blog', true, '/en/blog/a', 'a', ['title' => 'A']),
            new IndexableContent('e-2', 'en', 'ct-1', 'blog', true, '/en/blog/b', 'b', ['title' => 'B']),
            new IndexableContent('e-3', 'en', 'ct-1', 'blog', true, '/en/blog/c', 'c', ['title' => 'C']),
        ];

        // Reader returns 2 then 1 then empty (paging), total 3.
        $reader = new class ($records) implements IndexableContentReader {
            private int $call = 0;
            /** @param list<IndexableContent> $records */
            public function __construct(private array $records) {}
            public function getIndexablePublished(string $e, string $l): ?IndexableContent { return null; }
            public function enumerateIndexablePublished(int $limit, int $offset = 0, ?string $t = null, ?string $l = null): IndexablePage
            {
                $slice = array_slice($this->records, $offset, $limit);
                return new IndexablePage(array_values($slice), count($this->records), $limit, $offset);
            }
        };

        $backend = new class implements SearchBackend {
            public bool $ensured = false;
            public array $upserted = [];
            public function ensureIndex(): void { $this->ensured = true; }
            public function upsert(iterable $documents): void { foreach ($documents as $d) { $this->upserted[] = $d; } }
            public function deleteEntry(string $e, ?string $l = null): void {}
            public function search(SearchRequest $r): SearchResults { return new SearchResults([], 0, 20, 0); }
            public function health(): bool { return true; }
        };

        $types = new class implements ContentTypeReader {
            public function findUuidBySlug(string $slug): ?string { return 'ct-1'; }
            public function isPublicDelivery(string $uuid): bool { return true; }
            public function schemaFor(string $uuid): ?ContentSchemaReader
            {
                return new class implements ContentSchemaReader {
                    public function fields(): array { return []; }
                    public function field(string $name): ?\Glueful\Lemma\Contracts\Schema\FieldDescriptor { return null; }
                };
            }
        };

        $cmd = new ReindexCommand($reader, new DocumentBuilder([]), $backend, $types);
        $count = $cmd->backfill(type: null, locale: null, pageSize: 2);

        self::assertTrue($backend->ensured);
        self::assertSame(3, $count);
        self::assertCount(3, $backend->upserted);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/ReindexCommandTest.php`
Expected: FAIL — `ReindexCommand` does not exist.

- [ ] **Step 3: Implement `ReindexCommand`**

`packages/lemma-search/src/Console/ReindexCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Console;

use Glueful\Console\BaseCommand;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Index\DocumentBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:reindex', description: 'Backfill the search index from published content.')]
final class ReindexCommand extends BaseCommand
{
    public function __construct(
        private readonly IndexableContentReader $reader,
        private readonly DocumentBuilder $builder,
        private readonly SearchBackend $backend,
        private readonly ContentTypeReader $types,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Limit to a content-type slug.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Limit to a locale.');
    }

    /** Ensure the index, page through published records, and upsert. Returns documents indexed. */
    public function backfill(?string $type, ?string $locale, int $pageSize = 200): int
    {
        $this->backend->ensureIndex();

        $offset = 0;
        $indexed = 0;
        do {
            $page = $this->reader->enumerateIndexablePublished($pageSize, $offset, $type, $locale);
            $docs = [];
            foreach ($page->items as $record) {
                $schema = $this->types->schemaFor($record->contentTypeUuid);
                if ($schema === null) {
                    continue;
                }
                $docs[] = $this->builder->build($record, $schema);
            }
            if ($docs !== []) {
                $this->backend->upsert($docs);
                $indexed += count($docs);
            }
            $offset += $pageSize;
        } while ($offset < $page->total && $page->items !== []);

        return $indexed;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->backend->health()) {
            $this->error('Search backend is unavailable — is Meilisearch running and configured?');
            return self::FAILURE;
        }

        $type = $input->getOption('type');
        $locale = $input->getOption('locale');
        $indexed = $this->backfill(
            is_string($type) ? $type : null,
            is_string($locale) ? $locale : null,
        );

        $this->success(sprintf('Indexed %d document(s).', $indexed));
        return self::SUCCESS;
    }
}
```

> Confirm `BaseCommand` exposes `error()` and `success()` (memory: it provides `success` and getContext/getService helpers; `PruneAnalyticsCommand` uses `success`). If `error()` is named differently, use the available failure-output helper.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit packages/lemma-search/tests/Unit/ReindexCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Implement `StatusCommand`**

`packages/lemma-search/src/Console/StatusCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Console;

use Glueful\Console\BaseCommand;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Index\DocumentBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:status', description: 'Report search backend health and configuration warnings.')]
final class StatusCommand extends BaseCommand
{
    public function __construct(
        private readonly SearchBackend $backend,
        private readonly DocumentBuilder $builder,
        private readonly ContentTypeReader $types,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $healthy = $this->backend->health();
        $output->writeln($healthy
            ? '<info>Backend: reachable, index present.</info>'
            : '<error>Backend: UNREACHABLE (GET /v1/search will return 503).</error>');

        // Per-type config-field validation (only configured types need checking).
        /** @var array<string,mixed> $typeConfig */
        $typeConfig = (array) config($this->getContext(), 'lemma_search.types', []);
        $warnings = [];
        foreach (array_keys($typeConfig) as $slug) {
            $slug = (string) $slug;
            $uuid = $this->types->findUuidBySlug($slug);
            if ($uuid === null) {
                $warnings[] = "[{$slug}] configured type has no matching content type (skipped).";
                continue;
            }
            $schema = $this->types->schemaFor($uuid);
            if ($schema !== null) {
                $warnings = array_merge($warnings, $this->builder->validate($slug, $schema));
            }
        }

        foreach ($warnings as $w) {
            $output->writeln('<comment>' . $w . '</comment>');
        }

        // Visibility drift is a documented edge: flipping a type's public_delivery flag needs a
        // reindex to take effect (public_delivery is denormalized into each doc at index time).
        $output->writeln(
            '<comment>Note: after changing a content type\'s public_delivery flag, run '
            . '`php glueful search:reindex --type=<slug>` for search visibility to match delivery.</comment>',
        );

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
```

- [ ] **Step 6: Register + gate the commands in the provider**

In `LemmaSearchServiceProvider`, add imports:

```php
use Glueful\Lemma\Search\Console\ReindexCommand;
use Glueful\Lemma\Search\Console\StatusCommand;
```

Add to `services()`:

```php
            ReindexCommand::class => [
                'class' => ReindexCommand::class, 'shared' => true, 'autowire' => true,
            ],
            StatusCommand::class => [
                'class' => StatusCommand::class, 'shared' => true, 'autowire' => true,
            ],
```

Inside the `if ($registry->isEnabled('lemma.search'))` block in `boot()`, register the commands:

```php
            $this->commands([
                ReindexCommand::class,
                StatusCommand::class,
            ]);
```

> `ReindexCommand`/`StatusCommand` autowire from bound contracts + pack services. If autowiring the console classes fails (constructor deps not all type-resolvable), add factories mirroring the pattern in Task 5.

- [ ] **Step 7: Write the README**

`packages/lemma-search/README.md` — cover: what the pack does (public delivery-parity content search over published content, Meilisearch-backed); install/enable (`config/extensions.php` + `lemma.capabilities`); `GET /v1/search?q=&locale=&type=&limit=&offset=` request/response contract — the payload is wrapped in the framework's standard `data` envelope: `{ "data": { "hits": [{uuid,type,locale,href,title,snippet,score}], "total", "limit", "offset" } }` — plus `<mark>` highlighting, 422/403/404/503 semantics, delivery-parity visibility, 403-vs-empty rule); config keys (`lemma_search.index`, `snippet_length`, `default_limit`, `max_limit`, per-type `types.<slug>` override with `title_field`/`body_fields`/`exclude_fields`/`weights`); commands (`search:reindex [--type= --locale=]`, `search:status`); the visibility-drift note (reindex after flipping `public_delivery`); and the v1 scope boundaries (no collections search, no admin UI, no Postgres backend, no migrations). Keep it consistent in tone with `packages/lemma-seo/README.md`.

- [ ] **Step 8: Run the full pack + search integration suites**

Run: `php glueful cache:clear && vendor/bin/phpunit packages/lemma-search tests/Integration/Search`
Expected: PASS (all unit + integration tests).

Then confirm the commands are discoverable: `php glueful list | grep search` → shows `search:reindex` and `search:status`.

- [ ] **Step 9: Stage — commit only when the user authorizes**

Hold the commit until the user gives the go-ahead (review-artifact workflow). When authorized:

```bash
git add packages/lemma-search/src/Console packages/lemma-search/README.md packages/lemma-search/src/LemmaSearchServiceProvider.php packages/lemma-search/tests/Unit/ReindexCommandTest.php
git commit -m "search: add search:reindex + search:status commands + README"
```

---

## Final verification (after all tasks)

- [ ] `php glueful cache:clear && composer test` (or at minimum `vendor/bin/phpunit packages/lemma-search tests/Integration/Search`) — all green.
- [ ] `php scripts/check-pack-boundaries.php` — green.
- [ ] `composer run analyse:changed` — no new PHPStan errors.
- [ ] `composer run phpcs` (or `phpcbf`) — style clean for new files.
- [ ] Grep confirms confinement: `grep -rn "Glueful\\\\Extensions\\\\Meilisearch" packages/lemma-search/src` returns **only** `LiveMeilisearchIndex.php`.
- [ ] CHANGELOG `[Unreleased]` updated to cover the new pack + the two contract changes (`IndexableContentReader`, `ContentReindexer` locale widening, `ContentTypeReader::isPublicDelivery`), committed with the work.

---

## Self-Review (completed against the spec)

- **§1 Purpose/scope** → Tasks 4–8 (endpoint, ranked hits + `<mark>` snippet, per-locale, optional type, delivery parity, live seam, commands). Deferred items stay deferred (no collections, admin UI, Postgres backend, permission migration).
- **§2 Architecture/boundary** → Task 2 (port), Task 4 (confined `LiveMeilisearchIndex`); boundary check in final verification. Confinement class renamed — flagged deviation #1.
- **§2.1 Contract addition** → Task 1 (`IndexableContent`/`IndexablePage`/`IndexableContentReader` + App impl over `DeliveryRepository`).
- **§3 Components** → every listed file mapped to a task; no migrations.
- **§3.1 Port** → Task 2, exact `ensureIndex/upsert/deleteEntry/search/health` signatures; `Hit` shape matches §3.1.
- **§3.2 DocumentBuilder** → Task 3 (convention, title fallback chain, per-type override, weight→order, non-fatal validation, exact doc shape + filterable attrs). Weight→searchable-attribute nuance documented (single shared index → body concatenation order).
- **§3.3 Reindexer + contract widening** → Task 1 (widen) + Task 5 (`SearchContentReindexer` + resilient wrapper).
- **§4 Data flow** → Task 5 (live seam) + Task 8 (backfill) + Task 7 (query).
- **§5 Visibility/contract** → Task 6 (`VisibilityResolver`, 403-vs-empty, all-access/scoped/public) + Task 4 (in-filter enforcement, `<mark>` + escaping, title plain) + Task 7 (403/404 decision) + Task 8 (visibility-drift note in `search:status`).
- **§6 Error handling** → Task 7 (422/404/403/503), Task 5 (resilient live reindex), Task 8 (`search:reindex` non-zero exit, `search:status` doctor), Task 7 removability (404 + no-op reindexer).
- **§7 Testing** → DocumentBuilder (T3), VisibilityResolver incl. 403-vs-empty (T6), MeilisearchBackend against a fake incl. deleteEntry id/filter + filter expr + health (T4), reindexer null/missing/present (T5), endpoint happy/422/403/404/503 + removability (T7).
- **§8 Deliverables** → all covered; the two additions beyond §8's list (`isPublicDelivery`, the `LiveMeilisearchIndex` split) are flagged as deviations for review.
