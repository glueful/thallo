# The Search block, and products in search — Implementation Plan

> Amended a third time 2026-10-04: confirmation keeps `PENDING` tasks and their ids, resolving them before any replacement (Tasks 8–10); the locking bullets match the lock-then-check implementation (Task 7).
>
> Amended again 2026-10-04: leases use `clock_timestamp()` and decide only after the row lock is held (Task 7); `replaceSource` with one receipt shape replaces `writeLive`/`deleteSource`, and a target is acknowledged only when its upsert and deletion both succeed (Tasks 8–10).
>
> Amended 2026-10-04 after plan review: Postgres acknowledgements are generation-qualified and live writes never lower a generation (Tasks 8, 10); the promoted fence and database-time leases are explicit (Tasks 7, 11); only a promoted build satisfies demand and versions (Tasks 11, 13); the API's `type` narrows entries and keeps its responses and hit fields (Task 15); contributors register while their capability is off (Tasks 12, 16); entry enumeration pages whole entries (Task 12); suggestions invalidate on input, not after the debounce (Task 18); CDN purge obligations carry an identity (Task 4); Task 9 keeps the old backend working; the Rebuild-permission test lives in Task 21.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A brandless Search block (a field or an icon that opens one) that can sit in the header, a `/search` results page, and one search over pages and products. Packs contribute result kinds, every workspace gets its own isolated index, display and visibility always come from current records, and rebuilds are coordinated, durable and visible in Settings › Search.

**Architecture:**
- **Contracts.** `thallo-contracts` gains a search-source contract: `SearchSourceContributor` with `documents` / `enumerate` / `visibilityFilter` / `present`. It also gains a field-options contract and an availability-fingerprint contract.
- **Search pack.** `thallo-search` is rebuilt around:
  - an index store per engine, fenced by a workspace/kind state row;
  - a journal appended under that row's lock;
  - per-target acknowledgements;
  - claim/lease rebuilds that promote atomically;
  - a durable demand ledger;
  - a shared `SearchQueryService` behind `/v1/search`, `/_search/suggest` and `/search`.
- **Core.** Records `capability.{id}.changed_at` with every switch, and keys rendered pages by an availability fingerprint. Purge on change moves into core, replacing Commerce's.
- **Commerce.** Contributes the `products` kind.
- **Admin.** Gains the Search panel and `options_source` fields.

**Tech Stack:**
- PHP 8.3: Glueful, PostgreSQL, PHPUnit.
- Meilisearch through `meilisearch/meilisearch-php` v1.17 (already locked: it has `multiSearch` with `MultiSearchFederation`, `version()`, `getIndexes()`, `deleteIndex()`, `waitForTask()`).
- Twig 3.
- Plain JS (`search.js`), with Playwright proofs in `tools/runtime-browser`.
- Nuxt UI admin: Vue 3, Pinia Colada, vitest, and Playwright e2e in `admin/e2e`.

**Spec:** `docs/internal/superpowers/specs/2026-10-04-search-block-design.md` (approved at `0282b38e`). Every section of it is in this release.

## Rulings made while planning (from the code)

- **The themed 404 while Search is off.** Render's catch-all answers a reserved path with the framework's JSON 404 (`RenderController::page()`), so a reservation alone gives no themed page. `GET /search` is therefore registered **unconditionally** (like the reservation). `SearchPageController` checks `thallo.search` first and renders `404.twig` through the page renderer while it is off. The routes for `/_search/suggest`, the assets and the admin endpoints stay inside the gate.
  - Cost if wrong: one route registered while off.
- **The 429 page.** The framework's `rate_limit` middleware always answers JSON (`RateLimitHeaders::createExceededResponse`). `/search` therefore does not use it. `SearchPageThrottle` (a fixed one-minute window per client IP in `CacheStore`, 60 requests, configurable as `search.page_rate_limit`) is checked in the controller, which renders the themed 429 with `Retry-After`. `/_search/suggest` keeps the middleware (JSON is right there) with `->rateLimit(120, 1, by: 'ip')`.
  - Cost if wrong: one small class.
- **Packs cannot register Twig functions.** Every `shop_*` function is hard-coded in `RenderContextExtension` behind a soft-bound contract. The block's only server-side question is "is this scope available, and if not, why?". It is answered by a new contract, `Thallo\Contracts\Search\SearchScopeStatus`, which the search pack binds. Render exposes it as `search_scope_state(scope)`, allowlisted in `TemplatePolicy::FUNCTIONS` with `CACHE_VERSION` bumped. The page's locale is `site.locale`.
- **The block's assets are served by the search pack** at `/_thallo/search/{file}`, by `SearchAssetMap` plus `SearchAssetController`. These are verbatim copies of the shop's fingerprinting pattern (`ShopAssetMap`, `ShopAssetController::serve`). Render's closed `block_script()` list is not used.
- **The template lint gate's roots are hard-coded**, so `packages/thallo-search/templates` is added to `ShippedTemplatesLintGateTest`'s `$roots`.
- **The existing docs search box** (`_docs_search.twig`, `block-docs-search.js`) calls `/v1/search`, whose default stays `kind=entries`. It is untouched, and its Playwright proof `tools/runtime-browser/tests/docs-search.spec.js` must stay green.
- **Purges.**
  - `packages/thallo-account/src/CapabilityFlipPurge.php` also purges `thallo:shop:catalog`. The spec retires only Commerce's reconciler, so the accounts one stays.
  - The core purge covers `thallo:render:page`, every shop catalog page (through the fingerprint now in `ShopPageCache`'s key), and the edge.
  - Cost if wrong: one redundant purge on an accounts flip.
- **`ShopPageCache` gets the fingerprint too.** Shop catalog pages include the header region, so a Search block in the header would otherwise outlive a Search flip there. `ShopPageCache::key()` gains the fingerprint next to its appearance segment, and so does `RenderErrorCache`.
- **Durable core state lives in `thallo_system_flags`** (`SystemChannel`):
  - the last-purged fingerprint: `render.availability.purged`;
  - the delayed edge purge obligation: `render.availability.edge_purge_due`, a UTC timestamp;
  - the installation-wide legacy-index state: `search.legacy_index`, either `present` or `retired`.

  No new core table is needed.
- **Workspaces in jobs.** No job propagates the workspace today. `SearchWakeJob` carries `workspace` in its data. It runs its work through `TenantContextRunner::runAsTenant()` only when enforcement is active, and directly otherwise. This is the `LinkReconciler::runInTenant` pattern, because `runAsTenant` throws for a non-active tenant.
- **Dispatching after commit** uses `Connection::afterCommit()` plus `QueueManager::push()`, as `StyleClassJobService` does. A rolled-back transaction discards the callback.
- **Scheduling** goes in the root `config/schedule.php`, through `RunConsoleCommandJob`:
  - `search_reconcile`, every minute: picks up demand;
  - `search_reconcile_full`, at `30 3 * * *`: the full reconcile;
  - `render_availability_purge`, every minute: completes a due edge purge.
- **Schema.**
  - Migration `002_SearchIndexLifecycle.php` adds the columns and the four lifecycle tables, all registered as workspace-owned instance rows in `ThalloTenantTables`.
  - On Postgres it widens `doc_id` to 128 and makes the legacy columns (`entry_uuid`, `content_type_uuid`, `content_type_slug`) nullable, through pending raw operations. On other drivers the table is unused (the Postgres engine is pgsql-only), so the pending operations are skipped there.
  - `SearchSchemaVerifier` moves to the `CREATED_TABLES` / `ADDED_COLUMNS` map shape of `NavigationSchemaVerifier`.
  - `AppTestCase::TABLES` gains the four tables.
- **The uuid shapes fit the identity rules.** Commerce product uuids are `[A-Za-z0-9]{12}` and entry uuids `[A-Za-z0-9]{32}`, both inside `sourceId`'s `[A-Za-z0-9]{1,64}`.
- **The products contributor uses the storefront's own reads:**
  - `ProductRepository::activeFilteredQuery()` for enumeration, keyset on `uuid`;
  - `findActiveBuyerAvailableByUuids()` (capped at 100, with a missing key meaning not eligible) for `present()`;
  - `ShopProductCardAssembler` for the title, URL, cover and price (`ShopMoney::display`);
  - the description as `safe_html` then stripped of tags, through `RenderContextExtension::safeHtml()`'s sanitizer, which is exposed to packs as `Thallo\Contracts\Delivery\HtmlTextExtractor`. The ruling: the products contributor needs plain text and has no Twig. Render binds the contract.
- **Category and tag names** have no batch read, so the contributor joins `commerce_product_categories` / `commerce_product_tags` to their taxonomy tables, filtered by `tenant_uuid`, as `CategoryRepository::firstCategoryProjectionsForProducts` does.
- **Admin permissions.**
  - The admin has no client-side permission gating; it reacts to 403.
  - The panel's queries treat a 403 as "Ask an administrator for access" (the `AccountEmailsSection` pattern).
  - `/v1/admin/search/*` requires `content.manage` (as Settings › General does). `/v1/admin/field-options/{source}` requires `content.edit` at the route and then the source's own `permission()`.
- **Polling.** Pinia Colada has no `refetchInterval`, so the panel uses `useIntervalFn` (the import/export page pattern) at 5 s when active and 60 s when idle, plus a `visibilitychange`/`focus` refresh.
- **`options_source` survives the server** (`FieldDefinition::fromArray` ignores unknown keys, and the raw schema is persisted), but the admin's `toFieldDef` whitelist drops it. The admin maps it to `optionsSource`. `BlockFields.vue` dispatches a string field carrying it to a new `OptionsSourceField.vue`, like its existing `menu` branch.

## Global Constraints

- Packs reference only `Thallo\Contracts\…`, never `Thallo\Core\…` (`composer boundaries`).
- **Kinds:** `[a-z][a-z0-9]{0,15}`. **Source ids:** `[A-Za-z0-9]{1,64}`. **Locales:** `[A-Za-z0-9-]{1,12}` or `*`. **Document id:** `{kind}_{sourceId}_{loc}`, where `loc` is `L` + locale or `A`; at most 95 bytes.
- **Meilisearch index names:** `{prefix}{index}_v2_{workspace}_{kind}_g{G}`, without `{workspace}_` on a single-store site. The server must be 1.10 or newer. The exact readiness copy: "Meilisearch 1.10 or newer is required for search across kinds (the server reports {version}). Upgrade the server, or set `SEARCH_ENGINE=postgres`."
- **`q`:** at most 200 code points. **Page size:** 10 (`search.page_size`). **Refill:** at most 3 extra batches. **`present()` text:** capped at 20 KB.
- **Every query response** carries `total_approximate: true`.
- **Copy** (exact):
  - "Search is being rebuilt. Please try again later."
  - "Search is temporarily unavailable."
  - "No available results in this batch"
  - "No results for “{q}”"
  - "Suggestions are unavailable"
  - "Too many searches"
  - "Products search isn't available: Commerce is off." (editor placeholder)
  - "Products search isn't available right now" (results page)
  - "Background processing hasn't picked this up. Make sure the queue worker and scheduler are running, or run `php glueful search:reindex --wait`."
- **`/v1/search`** keeps its entries-only default. `type` with a conflicting `kind`, `offset` with `cursor`, and a malformed `kind` are 422. A bad cursor is 400.
- **Copy style:** sentence case, typographic quotes and dashes.
- **Changelog:** a bullet rides in the commit of the change, under `## [Unreleased]`.
- **Gates:**
  - `COMPOSER_PROCESS_TIMEOUT=0 composer test`, run once unprefixed and once with `API_USE_PREFIX=true`, in shards, never concurrently.
  - phpcs, judged by exit code; `composer boundaries`.
  - Admin: `pnpm type-check`, `pnpm lint`, `pnpm fmt:check`, `pnpm test`, and e2e (`pnpm --dir e2e test`) after rebuilding fixtures with `CACHE_DRIVER=array php scripts/build-builder-proof-fixtures`.
  - `tools/runtime-browser`: `npm test`.
  - `composer test:distribution` and `composer test:skeleton`.
  - A new top-level `tests/Integration` entry joins an `INTEGRATION_SHARD_*` list; shards stay under about 5 minutes locally.

## Review Focus

1. **A workspace created after Search was turned on** (no state rows yet). Its first suggestion request should get a rebuilding answer, never another workspace's results and never a 500, and its build should start from the new-workspace demand. Pinned in Task 13 (`testANewWorkspaceGetsDemandAndRebuildsAlone`).
2. **A product renamed while its index update fails** (Meilisearch down for one write). The results page shows the new name (from `present()`). The old name may still match until the next reconcile, but it is never displayed. Pinned in Task 16 (`testARenamedProductShowsItsCurrentNameWhileTheIndexIsStale`).
3. **Search turned off with a header Search block on cached pages**, both render-cached and shop-catalog-cached. After the flip, no cached page carries the block markup. Pinned in Task 3 (`testAFlipChangesTheShopCatalogKeyToo`) and Task 17 (`testTurningSearchOffRemovesTheHeaderBlockFromCachedPages`).
4. **A malicious `q`** (`"><script>`, `&amp;`, a lone surrogate or invalid UTF-8 bytes). It must be escaped everywhere it is echoed (title, heading, input value, snippet) and never cause a 5xx. Pinned in Task 5 (`testInvalidUtf8IsTreatedAsEmpty`) and Task 19 (`testAHostileQueryIsEscapedEverywhere`).
5. **`search:reindex` run while the scheduler's rebuild holds the lease.** The command adds demand and, with `--wait`, waits for the lease rather than starting a second builder, then reports. Pinned in Task 13 (`testReindexWaitDoesNotStartASecondBuilder`).

## Shared contracts (named once, used by every task)

```php
namespace Thallo\Contracts\Search;

/** One kind of search result a pack provides (spec §3.2). */
interface SearchSourceContributor
{
    /** [a-z][a-z0-9]{0,15}, stable. */
    public function kind(): string;
    public function label(): string;
    /** @return list<string> capability ids beyond thallo.search */
    public function requiredCapabilities(): array;
    public function schemaVersion(): int;
    /** Current documents for one item, one per locale; [] means remove. @return list<SearchDocument> */
    public function documents(string $sourceId): array;
    /** Publicly listed items ordered by sourceId, strictly after $after. */
    public function enumerate(?string $after, int $size): SearchDocumentPage;
    public function visibilityFilter(SearchAudience $audience): KindFilter;
    /**
     * The authority on what is shown, read from current records.
     * @param list<string> $sourceIds
     * @return array<string, ?ResultDisplay> null drops the candidate
     */
    public function present(SearchAudience $audience, string $locale, array $sourceIds): array;
}

final class SearchDocument
{
    /** @param array{image?:string,price?:string} $meta */
    public function __construct(
        public readonly string $kind,
        public readonly string $sourceId,
        public readonly string $locale,   // BCP 47 or '*'
        public readonly ?string $subtype,
        public readonly string $href,
        public readonly string $title,
        public readonly string $body,
        public readonly array $meta = [],
    ) {
        // Constructor validates kind/sourceId/locale against SearchIdentity's patterns
        // and throws \InvalidArgumentException naming the bad field.
    }
}

final class SearchDocumentPage
{
    /** @param list<SearchDocument> $documents */
    public function __construct(public readonly array $documents, public readonly ?string $nextAfter) {}
}

final class KindFilter
{
    public const NONE = 'none';
    public const ALL = 'all';
    public const SUBTYPES = 'subtypes';
    /** @param list<string> $subtypes */
    private function __construct(public readonly string $mode, public readonly array $subtypes) {}
    public static function none(): self { return new self(self::NONE, []); }
    public static function all(): self { return new self(self::ALL, []); }
    /** @param list<string> $subtypes */
    public static function subtypes(array $subtypes): self
    {
        return $subtypes === [] ? self::none() : new self(self::SUBTYPES, array_values(array_unique($subtypes)));
    }
}

final class SearchAudience
{
    /** @param list<string>|null $apiKeyScopes null = the public */
    private function __construct(public readonly ?array $apiKeyScopes) {}
    public static function public(): self { return new self(null); }
    /** @param list<string> $scopes */
    public static function apiKey(array $scopes): self { return new self(array_values($scopes)); }
    public function isPublic(): bool { return $this->apiKeyScopes === null; }
    /** Stable string for cursor binding. */
    public function fingerprint(): string
    {
        if ($this->apiKeyScopes === null) {
            return 'public';
        }
        $s = $this->apiKeyScopes;
        sort($s);
        return 'key:' . hash('sha256', implode("\n", $s));
    }
}

final class ResultDisplay
{
    public function __construct(
        public readonly string $title,
        public readonly string $href,
        public readonly string $text,       // contributor-approved plain text, ≤ 20 KB
        public readonly ?string $image = null,
        public readonly ?string $price = null,
    ) {}
}

/** Registry of contributors; the search pack implements it. */
interface SearchSourceRegistry
{
    /** @throws \LogicException on a duplicate or invalid kind */
    public function register(SearchSourceContributor $contributor): void;
    /** @return array<string, SearchSourceContributor> kind => contributor, registration order */
    public function all(): array;
}

/** A pack reports a change to one item (after commit); bound to a no-op while Search is off. */
interface SearchIndex
{
    public function changed(string $kind, string $sourceId): void;
    /** A change with no single item (a taxonomy edit): demand a rebuild of the kind. */
    public function kindChanged(string $kind, string $reason): void;
}

/** For render's `search_scope_state()`: is a block's scope available, and if not, why. */
interface SearchScopeStatus
{
    /** @return array{available: bool, label: ?string, reason: ?string} '' = all kinds */
    public function stateOf(string $scope): array;
}
```

```php
namespace Thallo\Contracts\Fields;

/** Dynamic choices for a block schema field's `options_source` (spec §3.9). */
interface FieldOptionSource
{
    /** e.g. 'thallo-search.scopes' */
    public function id(): string;
    /** The permission a caller needs, checked after the route's content.edit. */
    public function permission(): string;
    /**
     * @return list<array{value: string, label: string, available: bool, reason: ?string}>
     */
    public function options(): array;
}

interface FieldOptionSourceRegistry
{
    /** @throws \LogicException on a duplicate id */
    public function register(FieldOptionSource $source): void;
    public function find(string $id): ?FieldOptionSource;
}
```

```php
namespace Thallo\Contracts\Capability;

/** A hash of every registered capability's evaluated enabled state (spec §3.6). */
interface AvailabilityFingerprint
{
    /** 12 hex chars; stable for one evaluated state; computed from the request's snapshot. */
    public function current(): string;
}
```

```php
namespace Thallo\Contracts\Delivery;

/** Sanitised rich text to plain text, for packs without Twig. */
interface HtmlTextExtractor
{
    public function text(string $html): string;
}
```

**Search-pack internal types** (named here so tasks agree):

- `Thallo\Search\Identity\DocumentId::encode(string $kind, string $sourceId, string $locale): string` and `::decode(string $id): array{kind,sourceId,locale}`.
- `Thallo\Search\Store\Target`: a value object `(string $engine, string $name, int $generation)`. `$name` is the Meilisearch index uid, or `'pg'` on Postgres.
  - `Target::key(): string` is the **acknowledgement identity**: the uid on Meilisearch (already unique per attempt), and **`pg:g{G}`** on Postgres. The name alone is never used to identify a target, because on Postgres every generation shares the physical name `pg`.
- `Thallo\Search\Store\IndexStore`, the engine port:
  - `write(Target $t, list<SearchDocument> $docs, Fence $f): list<TargetReceipt>`: a build batch into one target. Its documents are all new, so it never deletes.
  - `replaceSource(list<Target> $targets, string $kind, string $sourceId, list<SearchDocument> $docs, Fence $f): list<TargetReceipt>`: makes each target hold **exactly** `$docs` for that source. It upserts the given locales and deletes every other locale of `(kind, sourceId)`; an empty `$docs` removes the source entirely. The source id is carried even when `$docs` is empty. Used by live applies and replays.
  - `sweep(string $kind, int $belowGeneration, Fence $f): void` (Postgres)
  - `confirm(TargetReceipt $r): ReceiptOutcome`, where `ReceiptOutcome` is `(ConfirmResult $result, list<int> $outstandingTaskUids)`:
    - **`PENDING`** whenever any task is still enqueued or processing after the bounded wait, **even if another task in the receipt already failed**. `outstandingTaskUids` lists them.
    - **`FAILED`** when every task has finished and at least one failed or was canceled.
    - **`SUCCEEDED`** only when every task succeeded.

    Postgres receipts are always `SUCCEEDED` with no outstanding uids.
  - **`TargetReceipt`**, the same shape on both engines: `(string $targetKey, list<int> $taskUids)`. Postgres returns one receipt per satisfied target key with `taskUids = []`, meaning committed and therefore succeeded. Meilisearch returns one receipt per target, naming every task that target needed (the upsert and the deletion). A target is acknowledged only from a receipt whose `confirm` is `SUCCEEDED`.
  - `search(list<Target> $targets, StoreQuery $q): StoreResult`
  - `createTarget(Target $t): void`
  - `dropTarget(Target $t): void`
  - `listTargets(string $prefix): list<string>`
  - `readiness(): Readiness`
- `Thallo\Search\Lifecycle\Fence`: `(string $kind, ?string $token, ?int $generation, string $role)`, where role is `builder`, `drainer` or `promoted`, with named constructors `builder()`, `drainer()` and `promoted()` (Task 7).
- `Thallo\Search\Lifecycle\StateRepository`: the only writer of `search_index_state`. Its methods are named in Task 8.
- `Thallo\Search\Query\SearchInput`, `Cursor`, `CursorSigner`, `SearchQueryService`, `SearchOutcome`: Tasks 5 and 14.

---
## Task 1: the search, field-option and fingerprint contracts, the document id, and the source registry

**Files:**
- Create: `packages/thallo-contracts/src/Search/{SearchSourceContributor,SearchDocument,SearchDocumentPage,KindFilter,SearchAudience,ResultDisplay,SearchSourceRegistry,SearchIndex,SearchScopeStatus,SearchIdentity}.php`
- Create: `packages/thallo-contracts/src/Fields/{FieldOptionSource,FieldOptionSourceRegistry}.php`, `packages/thallo-contracts/src/Capability/AvailabilityFingerprint.php`, `packages/thallo-contracts/src/Delivery/HtmlTextExtractor.php`
- Create: `packages/thallo-search/src/Identity/DocumentId.php`, `packages/thallo-search/src/Sources/DefaultSearchSourceRegistry.php`
- Modify: `packages/thallo-search/src/SearchServiceProvider.php` (`services()`: bind `SearchSourceRegistry::class` → `DefaultSearchSourceRegistry`, shared)
- Test: `tests/Unit/Search/SearchIdentityTest.php`, `tests/Unit/Search/DocumentIdTest.php`, `tests/Unit/Search/SearchSourceRegistryTest.php`

**Interfaces:** Produces everything in Shared contracts, plus:
- `SearchIdentity::KIND = '/\A[a-z][a-z0-9]{0,15}\z/'`, `SOURCE_ID = '/\A[A-Za-z0-9]{1,64}\z/'`, `LOCALE = '/\A(?:[A-Za-z0-9-]{1,12}|\*)\z/'`, and `SearchIdentity::assertKind/assertSourceId/assertLocale(string): void`, each throwing `\InvalidArgumentException`.
- `DocumentId::encode()` / `decode()`.
- `DefaultSearchSourceRegistry::register()` / `all()`.

- [ ] **Step 1: Write the failing tests.**
  ```php
  // tests/Unit/Search/DocumentIdTest.php
  public function testEncodesAndDecodesBothLocaleForms(): void
  {
      self::assertSame('products_Ab12Cd34Ef56_A', DocumentId::encode('products', 'Ab12Cd34Ef56', '*'));
      self::assertSame('entries_' . str_repeat('a', 32) . '_Len-US', DocumentId::encode('entries', str_repeat('a', 32), 'en-US'));
      self::assertSame(['kind' => 'products', 'sourceId' => 'Ab12Cd34Ef56', 'locale' => '*'], DocumentId::decode('products_Ab12Cd34Ef56_A'));
      self::assertSame(['kind' => 'entries', 'sourceId' => 'x1', 'locale' => 'fr-CA'], DocumentId::decode('entries_x1_Lfr-CA'));
  }
  public function testEveryIdIsAValidMeilisearchKeyAndAtMost95Bytes(): void
  {
      $id = DocumentId::encode('abcdefghijklmnop', str_repeat('Z', 64), 'zh-Hant-TW12');
      self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $id);
      self::assertLessThanOrEqual(95, strlen($id));
  }
  public function testRejectsAnyPartOutsideItsPattern(): void
  {
      foreach ([['Products', 'a', 'en'], ['p', 'a_b', 'en'], ['p', 'a', 'en_US'], ['p', '', 'en']] as [$k, $s, $l]) {
          try { DocumentId::encode($k, $s, $l); self::fail("accepted {$k}/{$s}/{$l}"); }
          catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
      }
      $this->expectException(\InvalidArgumentException::class);
      DocumentId::decode('products_only');
  }
  ```
  ```php
  // tests/Unit/Search/SearchSourceRegistryTest.php
  public function testASecondContributorForAKindIsRefused(): void
  {
      $r = new DefaultSearchSourceRegistry();
      $r->register($this->contributor('entries'));
      $this->expectException(\LogicException::class);
      $this->expectExceptionMessage("'entries'");
      $r->register($this->contributor('entries'));
  }
  public function testAnInvalidKindIsRefusedAndOrderIsKept(): void
  {
      $r = new DefaultSearchSourceRegistry();
      $r->register($this->contributor('entries'));
      $r->register($this->contributor('products'));
      self::assertSame(['entries', 'products'], array_keys($r->all()));
      $this->expectException(\LogicException::class);
      $r->register($this->contributor('Bad_Kind'));
  }
  ```
  `contributor(string $kind)` returns an anonymous `SearchSourceContributor` whose `kind()` is `$kind` and whose other methods return empty values. `SearchIdentityTest` checks that `new SearchDocument('products', 'a b', '*', null, '/x', 't', 'b')` throws, naming `sourceId`, and that `KindFilter::subtypes([])` is `NONE`.
- [ ] **Step 2: Run them.** `vendor/bin/phpunit tests/Unit/Search/DocumentIdTest.php tests/Unit/Search/SearchSourceRegistryTest.php tests/Unit/Search/SearchIdentityTest.php`. Expected: FAIL, classes not found.
- [ ] **Step 3: Create the contract files** exactly as in Shared contracts, plus `SearchIdentity`:
  ```php
  final class SearchIdentity
  {
      public const KIND = '/\A[a-z][a-z0-9]{0,15}\z/';
      public const SOURCE_ID = '/\A[A-Za-z0-9]{1,64}\z/';
      public const LOCALE = '/\A(?:[A-Za-z0-9-]{1,12}|\*)\z/';
      public static function assertKind(string $v): void { self::check(self::KIND, $v, 'kind'); }
      public static function assertSourceId(string $v): void { self::check(self::SOURCE_ID, $v, 'sourceId'); }
      public static function assertLocale(string $v): void { self::check(self::LOCALE, $v, 'locale'); }
      private static function check(string $re, string $v, string $field): void
      {
          if (preg_match($re, $v) !== 1) {
              throw new \InvalidArgumentException("Invalid search {$field}: '{$v}'.");
          }
      }
  }
  ```
  `SearchDocument`'s constructor calls the three asserts.
- [ ] **Step 4: Implement `DocumentId`.**
  ```php
  final class DocumentId
  {
      public static function encode(string $kind, string $sourceId, string $locale): string
      {
          SearchIdentity::assertKind($kind);
          SearchIdentity::assertSourceId($sourceId);
          SearchIdentity::assertLocale($locale);
          return $kind . '_' . $sourceId . '_' . ($locale === '*' ? 'A' : 'L' . $locale);
      }
      /** @return array{kind: string, sourceId: string, locale: string} */
      public static function decode(string $id): array
      {
          $parts = explode('_', $id, 3);
          if (count($parts) !== 3 || $parts[2] === '' || !in_array($parts[2][0], ['A', 'L'], true)) {
              throw new \InvalidArgumentException("Not a search document id: '{$id}'.");
          }
          $locale = $parts[2] === 'A' ? '*' : substr($parts[2], 1);
          self::encode($parts[0], $parts[1], $locale); // validates
          return ['kind' => $parts[0], 'sourceId' => $parts[1], 'locale' => $locale];
      }
  }
  ```
  `DefaultSearchSourceRegistry` keeps `array<string, SearchSourceContributor>`. `register()` validates the kind against `SearchIdentity::KIND` and refuses a duplicate with a `\LogicException`: "Search kind '{kind}' is already provided."
- [ ] **Step 5: Run the tests.** Expected: PASS.
- [ ] **Step 6: Commit.**
  ```bash
  vendor/bin/phpcs packages/thallo-contracts/src packages/thallo-search/src tests/Unit/Search; echo "phpcs=$?"
  composer boundaries
  git add packages/thallo-contracts/src packages/thallo-search/src tests/Unit/Search
  git commit -m "feat(search): the search source contract, document identity and source registry"
  ```

## Task 2: every capability switch records when it changed

**Files:**
- Modify: `core/src/Capabilities/CapabilityStateStore.php` (`put()`)
- Test: `tests/Integration/Capabilities/CapabilityChangedAtTest.php`

**Interfaces:** Produces the `thallo_system_flags` row `capability.{id}.changed_at` holding the state version that the same transaction advanced to. `CapabilityStateSnapshot::rows` already includes it (it matches `capability.%`).

- [ ] **Step 1: Write the failing test.**
  ```php
  final class CapabilityChangedAtTest extends AppTestCase
  {
      public function testAWriteRecordsTheVersionItAdvancedTo(): void
      {
          $store = $this->container()->get(CapabilityStateStore::class);
          $version = $this->container()->get(CapabilityStateVersion::class);
          $store->put('thallo.search', true);
          $v1 = $version->current();
          self::assertSame($v1, $this->flags()->get('capability.thallo.search.changed_at'));
          $store->put('thallo.search', false);
          $store->put('thallo.search', true);
          self::assertSame((string) ((int) $v1 + 2), $this->flags()->get('capability.thallo.search.changed_at'));
      }

      public function testARolledBackWriteRecordsNothing(): void
      {
          $before = $this->flags()->get('capability.thallo.search.changed_at');
          try {
              $this->connection()->transaction(function (): void {
                  $this->container()->get(CapabilityStateStore::class)->put('thallo.search', true);
                  throw new \RuntimeException('rollback');
              });
          } catch (\RuntimeException) {
          }
          SystemFlags::clearCache();
          self::assertSame($before, $this->flags()->get('capability.thallo.search.changed_at'));
      }
  }
  ```
  `flags()` is the `SystemChannel`, the same helper `InertnessTest` uses.
- [ ] **Step 2: Run it.** `vendor/bin/phpunit tests/Integration/Capabilities/CapabilityChangedAtTest.php`. Expected: FAIL; the row is null.
- [ ] **Step 3: Implement.** In `put()`, after `$version->advance();` and still inside the transaction:
  ```php
  // The version this switch advanced to, kept per capability: consumers that must notice
  // every change (search's rebuild demand) compare against it, so an off/on cycle they
  // never observed still reads as a change.
  $this->system->put(self::PREFIX . $id . '.changed_at', $version->current());
  ```
  `CapabilityStateVersion::current()` reads through the same connection, so it sees the advanced value inside the transaction.
- [ ] **Step 4: Run the test plus `tests/Integration/Capabilities`.** Expected: PASS.
- [ ] **Step 5: Commit**, with a changelog bullet under `### Changed`: "**Every capability switch records the state version it changed at** (`capability.{id}.changed_at`), so a consumer can notice an off/on cycle it never saw." Message: `feat(capabilities): every switch records the version it changed at`.

## Task 3: rendered and shop-catalog pages are keyed by an availability fingerprint

**Files:**
- Create: `core/src/Capabilities/RegistryAvailabilityFingerprint.php`
- Modify: `core/src/Providers/CoreServiceProvider.php` (bind `AvailabilityFingerprint::class`, shared, factory)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (`makeRenderPageCache`, `makeRenderErrorCache`: append the fingerprint to the appearance closure's output); `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (the `ShopPageCache` factory: the same)
- Test: `tests/Integration/Render/AvailabilityFingerprintKeyTest.php`

**Interfaces:** Consumes `CapabilityRegistry::all()` and `isEnabled()`. Produces `AvailabilityFingerprint::current(): string`, 12 hex chars.

- [ ] **Step 1: Write the failing tests.**
  - `testTheFingerprintIsStableAndChangesWithAnEvaluatedState`: `current()` called twice is equal. A second boot with `bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => true]])` gives a different value. That is a **config-only** change, with no switch written.
  - `testThePageKeyCarriesTheFingerprint`: render `/` through `RenderPageCache` in the default boot and in the Search-on boot, and assert the stored keys differ in the segment after the appearance (use `CacheStore` key listing as `RenderPageCacheAppearanceTest` does).
  - `testAFlipChangesTheShopCatalogKeyToo`: the same with `ShopPageCache` and `/shop`.
  - `testAnOldRenderFinishingAfterTheChangeIsNotServed`: in the default boot, compute the page key (key A) **before** calling the next handler, using a test double for the next handler that first switches the fingerprint (it swaps the registry snapshot by booting the Search-on app) and then returns HTML. Then request `/` in the Search-on boot and assert it misses (the body comes from a fresh render). This follows the middleware: the key is computed before `$next`.
- [ ] **Step 2: Run them.** Expected: FAIL; the class is missing and the keys are equal.
- [ ] **Step 3: Implement the fingerprint.**
  ```php
  final class RegistryAvailabilityFingerprint implements AvailabilityFingerprint
  {
      private ?string $memo = null;
      public function __construct(private readonly CapabilityRegistry $registry) {}
      public function current(): string
      {
          if ($this->memo !== null) {
              return $this->memo;
          }
          $state = [];
          foreach ($this->registry->all() as $capability) {
              $state[$capability->id] = $this->registry->isEnabled($capability->id);
          }
          ksort($state);
          return $this->memo = substr(hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 0, 12);
      }
  }
  ```
  The registry is per context and built from the context's snapshot, so the fingerprint is the one the request renders with.
- [ ] **Step 4: Put it in the keys.** In `makeRenderPageCache` and `makeRenderErrorCache`:
  ```php
  $availability = $container->has(AvailabilityFingerprint::class) ? $container->get(AvailabilityFingerprint::class) : null;
  static fn (): string => $appearance->fingerprint() . ($availability !== null ? '-a' . $availability->current() : ''),
  ```
  Do the same in Commerce's `ShopPageCache` factory closure. The key shapes stay `render:{theme}:{appearance}:{path}` with the appearance segment extended, so `RenderCachePurge::purgeAll()`'s patterns still match.
- [ ] **Step 5: Run the new tests plus `tests/Integration/Render/RenderPageCacheTest.php tests/Integration/Render/RenderPageCacheAppearanceTest.php tests/Integration/Commerce/ShopCacheTest.php`.** Expected: PASS. If an appearance test pins an exact key string, update the expected key to include `-a{fingerprint}`, read from the container.
- [ ] **Step 6: Commit**, with a changelog bullet: "**Cached pages are keyed by which features are on**, so a page rendered before a feature was switched on or off is never served after it." Message: `feat(render): cached pages are keyed by an availability fingerprint`.

## Task 4: core purges on an availability change, durably, and Commerce's reconciler retires

**Files:**
- Create: `core/src/Capabilities/AvailabilityPurge.php`, `core/src/Capabilities/Console/AvailabilityPurgeCommand.php` (`thallo:availability:purge`)
- Modify: `core/src/Providers/CoreServiceProvider.php` (bind; `onBeginRequest` hook calls `AvailabilityPurge::reconcile()` inside a try/catch, logged like `ContributedBlockTypeReconciler`; register the command)
- Modify: `config/schedule.php` (entry `render_availability_purge`, `* * * * *`, `RunConsoleCommandJob`, `parameters.command` = the command class)
- Delete: `packages/thallo-commerce/src/Shop/CapabilityFlipPurge.php`, its call in `CommerceIntegrationServiceProvider::boot()` (L1222) and `reconcileCapabilityState()`
- Modify: `tests/Integration/Commerce/StorefrontInertnessTest.php` (L256-262: assert the core marker instead of `CapabilityFlipPurge::MARKER_KEY`)
- Delete: `tests/Integration/Commerce/CapabilityFlipPurgeTest.php`
- Test: `tests/Integration/Capabilities/AvailabilityPurgeTest.php`

**Interfaces:** Consumes `AvailabilityFingerprint`, `RenderedPageCachePurge` (soft), `EdgeCacheInterface` (soft), `SystemChannel`. Produces `AvailabilityPurge::reconcile(): void` and `AvailabilityPurge::completeDue(\DateTimeImmutable $now): void`.

- [ ] **Step 1: Write the failing tests.** Use a fake edge recording calls and able to fail on demand, like `CapabilityFlipPurgeTest`'s:
  - `testFirstSightRecordsWithoutPurging`: no `render.availability.purged` flag. `reconcile()` sets it to `current()`, with no purges.
  - `testAChangePurgesPagesAndEdgeAndSchedulesTheRetry`: flag = `'old'`. `reconcile()` invalidates `thallo:render:page` (a tagged probe key is gone, an untagged neighbour stays), calls `purgeAll()` once, sets `render.availability.edge_purge_due` to a time at or after now plus `search.edge_purge_grace` (default 300 s), and sets the flag to `current()`.
  - `testAFailedEdgePurgeLeavesTheMarkerUnadvanced`: edge `purgeAll()` returns false. The flag stays `'old'`, and a second `reconcile()` purges again.
  - `testAnOldCompletionCannotEraseANewerObligation`: read obligation O1 (fingerprint f1) into `completeDue`. A hook in the fake edge's `purgeAll` simulates a new flip that writes O2 (f2). After `completeDue` returns, `EDGE_DUE` still holds O2.
  - `testTheMarkerAdvancesOnlyAfterTheRequiredPurges`: the local purge throws, so the marker is unchanged and no obligation is written. Then the edge fails, with the same result. Then both succeed, so the marker advances and the obligation exists. Finally, a fault injected into the obligation write rolls back the transaction, leaving the marker unchanged too.
  - `testTheDelayedPurgeSurvivesARestartAndClearsOnSuccess`: set the due flag in the past, then rebuild `AvailabilityPurge` from a fresh container (a "restart"). `completeDue(now)` calls `purgeAll()` and forgets the due flag. Failing once keeps it.
  - `testAConfigOnlyCommerceFlipPurgesAndPagesLoseTheShop`: the parity test that replaces `CapabilityFlipPurgeTest`. Warm `/` with a mini-cart in the header in the default boot. Boot `bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]])` and handle one request; `onBeginRequest` reconciles. `/` renders without `data-shop-mini-cart`, and the fake edge saw `purgeAll()`.
  - `testTurningSearchOffPurges`: the same with `thallo.search`, seeded on, then off.
- [ ] **Step 2: Run them.** Expected: FAIL, class not found.
- [ ] **Step 3: Implement.**
  ```php
  final class AvailabilityPurge
  {
      public const MARKER = 'render.availability.purged';
      public const EDGE_DUE = 'render.availability.edge_purge_due'; // value: "{fingerprint}|{due UTC}"

      public function __construct(
          private readonly AvailabilityFingerprint $fingerprint,
          private readonly SystemChannel $system,
          private readonly Connection $db,
          private readonly CacheStore $cache,
          private readonly int $graceSeconds,
          private readonly ?RenderedPageCachePurge $pages = null,
          private readonly ?EdgeCacheInterface $edge = null,
      ) {}

      public function reconcile(): void
      {
          $current = $this->fingerprint->current();
          $last = $this->system->get(self::MARKER);
          if ($last === $current) {
              return;
          }
          if ($last === null) {                       // first sight: nothing cached under an older state
              $this->system->put(self::MARKER, $current);
              return;
          }
          // The required purges: local pages, then the first edge purge. Either failing leaves the
          // marker where it is, so the next request or tick repeats both.
          $this->pages !== null ? $this->pages->purge(['thallo:render:page'])
              : $this->cache->invalidateTags(['thallo:render:page']);
          $edgeOn = $this->edge !== null && $this->edge->isEnabled();
          if ($edgeOn && !$this->edge->purgeAll()) {
              return;
          }
          // Only now does the marker advance, in one transaction with the retry obligation, which is
          // named by this fingerprint so an older completion can never clear it.
          $this->db->transaction(function () use ($current, $edgeOn): void {
              if ($edgeOn) {
                  $this->system->put(self::EDGE_DUE, $current . '|' . gmdate('Y-m-d H:i:s', time() + $this->graceSeconds));
              }
              $this->system->put(self::MARKER, $current);
          });
      }

      /** The retry. It clears only the obligation it read, and only if it is still there unchanged. */
      public function completeDue(\DateTimeImmutable $now): void
      {
          $value = $this->system->get(self::EDGE_DUE);
          if ($value === null || !str_contains($value, '|')) {
              return;
          }
          [, $due] = explode('|', $value, 2);
          if ($now < new \DateTimeImmutable($due . ' UTC')) {
              return;
          }
          if ($this->edge !== null && $this->edge->isEnabled() && !$this->edge->purgeAll()) {
              return;                                  // stays due; the next tick retries
          }
          // Conditional delete: a newer flip that replaced the obligation meanwhile is left alone.
          $this->db->table('thallo_system_flags')->where('key', '=', self::EDGE_DUE)->where('value', '=', $value)->delete();
          SystemFlags::clearCache();
      }
  }
  ```
  `AvailabilityPurgeCommand::execute()` calls `completeDue(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))` and then `reconcile()`. `graceSeconds` comes from config `render.availability_edge_grace`, default 300. Add that key to `packages/thallo-render/config/render.php` with a comment saying it is a retry window, not a staleness bound.
- [ ] **Step 4: Retire Commerce's reconciler.** Delete the class, the boot call and the private method. Delete `CapabilityFlipPurgeTest`. In `StorefrontInertnessTest`, replace the marker assertion with: after the disabled boot's request, `SystemChannel::get(AvailabilityPurge::MARKER)` equals that boot's `AvailabilityFingerprint::current()`.
- [ ] **Step 5: Add the schedule entry:**
  ```php
  ['name' => 'render_availability_purge', 'schedule' => '* * * * *',
   'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
   'parameters' => ['command' => \Thallo\Core\Capabilities\Console\AvailabilityPurgeCommand::class],
   'description' => 'Finish the delayed edge purge after a feature was switched on or off.',
   'enabled' => true],
  ```
- [ ] **Step 6: Run the new test plus `tests/Integration/Commerce/StorefrontInertnessTest.php tests/Integration/Commerce/InertnessTest.php tests/Integration/Account`.** Expected: PASS.
- [ ] **Step 7: Commit**, with a changelog bullet under `### Changed`: "**Switching any feature on or off purges cached pages and the CDN from core**, including a change made only in configuration. A second CDN purge retries after five minutes and survives a restart. Commerce's own purge is retired." Message: `feat(capabilities): core purges cached pages when the features in use change`.

## Task 5: one input normaliser and a signed cursor

**Files:**
- Create: `packages/thallo-search/src/Query/{SearchInput,Surface,ScopeChoice,Cursor,CursorSigner,InvalidSearchInput}.php`
- Test: `tests/Unit/Search/SearchInputTest.php`, `tests/Unit/Search/CursorSignerTest.php`

**Interfaces:**
- **Consumes:** `Thallo\Contracts\Context\Context::enabledLocales()` and `defaultLocale()`, passed in as plain arrays so the class stays pure.
- **Produces:**
  - `Surface::PUBLIC` and `Surface::API` (an enum).
  - `SearchInput::from(array $query, Surface $surface, list<string> $locales, string $defaultLocale, list<string> $registeredKinds): SearchInput`. Readonly fields: `string $q`, `ScopeChoice $scope`, `string $locale`, `?string $type`, `?string $rawCursor`, `?int $offset`.
  - `ScopeChoice`: `allKinds()`, `kind(string)` or `unavailable(string $supplied)`, `isAll()`, `isUnavailable()`, `?string $kind`, `string $binding`. `binding` is `''` for all, the kind name, or `'!'` + the supplied value.
  - `InvalidSearchInput extends \InvalidArgumentException`, with `public readonly int $status` (422 or 400), thrown on the API surface only.
  - `CursorSigner::sign(Cursor $c): string` and `verify(mixed $token, string $binding): ?Cursor`.
  - `Cursor`: `(string $binding, int $rawOffset)`.
  - `CursorBinding::of(SearchInput $in, string $workspace, SearchAudience $a): string`, a sha256 over q, scope binding, type, locale, workspace and audience fingerprint.

- [ ] **Step 1: Write the failing tests.**
  ```php
  public function testQIsNormalisedOneWayEverywhere(): void
  {
      $in = $this->public(['q' => "  Cafe\u{0301}\t\x07 noir\u{3000} "]);
      self::assertSame("Caf\u{00E9} noir", $in->q); // NFC, control removed, Unicode spaces collapsed/trimmed
      self::assertSame(200, mb_strlen($this->public(['q' => str_repeat('é', 250)])->q));
      self::assertSame('', $this->public(['q' => ['a']])->q); // array-valued → absent
  }
  public function testInvalidUtf8IsTreatedAsEmpty(): void
  {
      self::assertSame('', $this->public(['q' => "\xC3\x28"])->q);
  }
  public function testPublicScopeMapping(): void
  {
      self::assertTrue($this->public([])->scope->isAll());
      self::assertTrue($this->public(['scope' => ''])->scope->isAll());
      self::assertSame('products', $this->public(['scope' => 'Products'])->scope->kind);
      self::assertTrue($this->public(['scope' => 'reviews'])->scope->isUnavailable());
      self::assertTrue($this->public(['scope' => ['x']])->scope->isUnavailable());
      self::assertTrue($this->public(['scope' => 'a_b'])->scope->isUnavailable());
  }
  public function testApiKindMapping(): void
  {
      self::assertSame('entries', $this->api([])->scope->kind);
      self::assertTrue($this->api(['kind' => 'all'])->scope->isAll());
      $this->assertApiError(['kind' => 'reviews'], 422);
      $this->assertApiError(['kind' => ['x']], 422);
      $this->assertApiError(['type' => 'post', 'kind' => 'products'], 422);
      self::assertSame('entries', $this->api(['type' => 'post'])->scope->kind);
      $this->assertApiError(['offset' => '10', 'cursor' => 'abc'], 422);
  }
  public function testLocaleIsCanonicalOrDefault(): void
  {
      self::assertSame('fr-CA', $this->public(['locale' => 'FR-ca'])->locale);
      self::assertSame('en', $this->public(['locale' => 'xx'])->locale);
      self::assertSame('en', $this->public(['locale' => ['en']])->locale);
      $this->assertApiError(['locale' => 'xx'], 422);
  }
  ```
  The helpers are `public(array $q)` = `SearchInput::from($q, Surface::PUBLIC, ['en', 'fr-CA'], 'en', ['entries', 'products'])` and `api()`, the same with `Surface::API`; the API surface additionally requires a non-empty `q` (422).
  `CursorSignerTest`:
  - a round trip;
  - a token whose binding differs (another `q`, scope, `type`, locale, workspace, or audience) returns null;
  - a flipped byte returns null;
  - a non-string, empty or over-long (more than 512 bytes) token returns null.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement `SearchInput::from`.**
  - **`q`:**
    - non-string → `''`;
    - `mb_check_encoding($q, 'UTF-8')` false → `''`;
    - `\Normalizer::normalize($q, \Normalizer::FORM_C)` when `class_exists(\Normalizer::class)`;
    - `preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', …)`;
    - `preg_replace('/[\p{Z}\s]+/u', ' ', …)`;
    - `trim`;
    - `mb_substr(…, 0, 200)`.
  - **Scope:** on `Surface::PUBLIC`, read `scope`. Absent or `''` gives `allKinds()`. A non-string gives `unavailable('')`. Otherwise lowercase it; it must match `SearchIdentity::KIND` and be in `$registeredKinds`, or it's `unavailable($value)`. Whether a registered kind is *available* is the query service's question, not this class's.
  - **Kind:** on `Surface::API`, read `kind`. Absent gives `kind('entries')`. `all` gives `allKinds()`. A registered kind gives `kind($k)`. Anything else throws 422. A non-empty `type` with a kind other than `entries` throws 422. `type` must match `/\A[a-z0-9_-]{1,191}\z/`, else 422.
  - **Locale:** a case-insensitive match against `$locales` returns the configured spelling. On the public surface a miss returns the default; on the API surface a miss or a missing locale throws 422.
  - **`cursor` and `offset`:**
    - both present on the API surface → 422;
    - `offset` must be a non-negative integer string, else 422;
    - public `offset` is ignored.
- [ ] **Step 4: Implement the signer.**
  ```php
  final class CursorSigner
  {
      public function __construct(private readonly string $key) {}
      public function sign(Cursor $c): string
      {
          $payload = rtrim(strtr(base64_encode(json_encode([$c->binding, $c->rawOffset], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
          return $payload . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->key, true)), '+/', '-_'), '=');
      }
      public function verify(mixed $token, string $binding): ?Cursor
      {
          if (!is_string($token) || $token === '' || strlen($token) > 512 || substr_count($token, '.') !== 1) {
              return null;
          }
          [$payload, $mac] = explode('.', $token);
          $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->key, true)), '+/', '-_'), '=');
          if (!hash_equals($expected, $mac)) {
              return null;
          }
          $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
          if (!is_array($data) || ($data[0] ?? null) !== $binding || !is_int($data[1] ?? null) || $data[1] < 0) {
              return null;
          }
          return new Cursor($binding, $data[1]);
      }
  }
  ```
  The key comes from `app.key` with the `base64:` prefix decoded, as in `ResolvesPreviewKey`, plus the domain string `"search-cursor\0"` prepended. A missing key throws at construction (the provider factory), as preview tokens do.
- [ ] **Step 5: Run the tests.** Expected: PASS.
- [ ] **Step 6: Commit** `feat(search): one way to read a search query, and signed cursors`.

## Task 6: the lifecycle schema

**Files:**
- Create: `packages/thallo-search/migrations/002_SearchIndexLifecycle.php`
- Modify: `packages/thallo-search/src/Schema/SearchSchemaVerifier.php` (map shape: `CREATED_TABLES`, `ADDED_COLUMNS`, and a sorted `migrationBasenames()`, as in `NavigationSchemaVerifier`)
- Modify: `packages/thallo-tenancy/src/ThalloTenantTables.php` (four rows; widen the `search_documents` unique stays `['tenant_uuid','doc_id']`)
- Modify: `tests/Support/AppTestCase.php` (`TABLES` += the four tables), `tests/Unit/Tenancy/ThalloTenantTablesTest.php`
- Test: `tests/Integration/Search/SearchLifecycleSchemaTest.php`

**Interfaces:** These are the tables later tasks read and write.

| Table | Columns |
|---|---|
| `search_documents` (new columns) | `kind` string(16) nullable; `source_id` string(64) nullable; `subtype` string(64) nullable; `meta` text nullable (JSON); `generation` bigInteger default 0. `doc_id` is widened to 128, and `entry_uuid`, `content_type_uuid`, `content_type_slug` become nullable (Postgres pending operations). Index `idx_search_documents_kind_gen (kind, generation)`. |
| `search_index_state` | `id` pk; `kind` string(16); `generation` bigInteger default 0 (active); `generation_counter` bigInteger default 0; `active_target` string(191) nullable; `building_generation` bigInteger nullable; `building_target` string(191) nullable; `owner_token` string(32) nullable; `lease_until` string(32) nullable (UTC `Y-m-d H:i:s`); `cursor` string(64) nullable; `journal_start_seq` bigInteger nullable; `demand_seq_at_start` bigInteger nullable; `satisfied_seq` bigInteger default 0; `reconciled_version` bigInteger default 0; `schema_version` integer default 0; `status` string(16) default `'pending'`; `format` string(8) default `'legacy'`; `processed` integer default 0; `documents` integer default 0; `last_success_at` string(32) nullable; `last_error` text nullable; `drainer_token` string(32) nullable; `drainer_lease_until` string(32) nullable; `journal_head` bigInteger default 0; `retired_targets` text nullable (JSON list of `{name, retired_at}`); `locked_at` string(32) nullable; `updated_at` string(32) nullable. Unique `(kind)`, widened to `(tenant_uuid, kind)`. |
| `search_index_changes` | `id` pk; `kind` string(16); `source_id` string(64); `seq` bigInteger; `resolved` smallInteger default 0; `failed_at` string(32) nullable; `error` text nullable; `created_at` string(32). Unique `(kind, seq)`, widened to `(tenant_uuid, kind, seq)`; index `(kind, resolved)`. |
| `search_index_acks` | `id` pk; `kind` string(16); `entry_seq` bigInteger; `target` string(191); `task_uid` string(191) nullable (comma-joined uids); `status` string(12) (`pending`, `succeeded`, `failed`); `writer_token` string(32); `updated_at` string(32). Unique `(kind, entry_seq, target)`, widened with `tenant_uuid`. |
| `search_index_demand` | `id` pk; `kind` string(16); `seq` bigInteger; `reason` string(24); `created_at` string(32). Unique `(kind, seq)`, widened. |

- [ ] **Step 1: Write the failing test.** `SearchLifecycleSchemaTest`:
  - each table and each new column exists (`hasTable` / `hasColumn`);
  - `ThalloTenantTables::all()` registers each new table as `instance` with the widened uniques above;
  - `SearchSchemaVerifier` reports both migrations applied.
  Add the four names to `ThalloTenantTablesTest`'s expected list.
- [ ] **Step 2: Run it.** `vendor/bin/phpunit tests/Integration/Search/SearchLifecycleSchemaTest.php`. Expected: FAIL.
- [ ] **Step 3: Write the migration.** Guard every create with `hasTable` and every column with `hasColumn`. Use `alterTable('search_documents', fn ($t) => …)` for the additions. On `pgsql` only, add pending operations:
  ```php
  $schema->addPendingOperation('ALTER TABLE search_documents ALTER COLUMN doc_id TYPE varchar(128)');
  foreach (['entry_uuid', 'content_type_uuid', 'content_type_slug'] as $c) {
      $schema->addPendingOperation("ALTER TABLE search_documents ALTER COLUMN {$c} DROP NOT NULL");
  }
  ```
  `down()` drops the four tables and leaves `search_documents`' columns: a forward-only widening, said in its docblock.
- [ ] **Step 4: Register the tables** in `ThalloTenantTables::all()` beside `search_documents`:
  ```php
  'search_index_state' => self::row($inst, [['uniq_search_index_state_kind', ['tenant_uuid', 'kind']]]),
  'search_index_changes' => self::row($inst, [['uniq_search_index_changes_seq', ['tenant_uuid', 'kind', 'seq']]]),
  'search_index_acks' => self::row($inst, [['uniq_search_index_acks_target', ['tenant_uuid', 'kind', 'entry_seq', 'target']]]),
  'search_index_demand' => self::row($inst, [['uniq_search_index_demand_seq', ['tenant_uuid', 'kind', 'seq']]]),
  ```
  Rework `SearchSchemaVerifier` into the map shape, listing `001_CreateSearchDocumentsTable.php` and `002_SearchIndexLifecycle.php`.
- [ ] **Step 5: Run `composer test:migrate`**, then the test plus `tests/Unit/Tenancy tests/Unit/Schema/PackManifestsTest.php tests/Integration/Search`. Expected: PASS. Run `composer test:distribution`: the baseline changes because of a new pack migration.
- [ ] **Step 6: Commit** `feat(search): the index lifecycle schema`.

## Task 7: the state repository — claims, leases, fences, the journal, demand and acknowledgements

**Files:**
- Create: `packages/thallo-search/src/Lifecycle/{StateRepository,Fence,Claim,StaleFence,JournalEntry,Clock,DatabaseClock}.php`
- Test: `tests/Integration/Search/StateRepositoryTest.php`

**Interfaces:**
- **Consumes:** `Connection` (query builder only; there is no `lockForUpdate`, so a **lock is taken by updating the row's `locked_at`**, which holds the row's write lock until the transaction ends on Postgres and on any row-locking engine).
- **`Clock` reads the database's current wall-clock time, not the transaction's start time.** `CURRENT_TIMESTAMP` is fixed at the start of a Postgres transaction, so it can't be used. `DatabaseClock::now()` runs, per driver:
  - `pgsql`: `SELECT clock_timestamp()`;
  - `mysql`: `SELECT SYSDATE(6)`;
  - `sqlite`: `SELECT strftime('%Y-%m-%d %H:%M:%f','now')`.

  It runs on the connection (no table, so no tenancy scoping applies) and is normalised to UTC `Y-m-d H:i:s`. Worker clocks are never consulted. Tests bind a fake `Clock`.
- **Timing rule for every lease decision** (fenced sections, claims, renewals, drainer claims): **take the row lock first, then read the clock, then decide.**
  1. Lock the row with an unconditional `update(['locked_at' => …])` matched on `kind` only. This may wait for another holder.
  2. Once the lock is held, read `DatabaseClock::now()` and re-read the row.
  3. Check ownership and expiry in PHP against that fresh time.

  If the check fails, the transaction rolls back and the callback never runs. A lock wait that crosses the lease's expiry, or a long outer transaction, can therefore never let an expired holder through.
- **Produces** `StateRepository`:
  - `ensure(string $kind): array` returns the row, creating it with `status = 'pending'` if missing.
  - `locked(string $kind, callable $fn): mixed`: begins a transaction, locks the row by kind (`update(['locked_at' => …])` matched on `kind` only), then reads `clock_timestamp()` and re-reads the row, and calls `$fn($row, $now)`; commits.
  - `fenced(Fence $f, callable $fn): mixed`: the same lock by kind, then the fence is checked in PHP against the **fresh row and fresh time** read after the lock is held: owner token, `building_generation` and `lease_until > now` for a builder; drainer token and `drainer_lease_until > now` for a drainer; `generation = G` for promoted. A failed check throws `StaleFence` before the callback runs.
  - `appendChange(string $kind, string $sourceId): int` runs inside `locked()`: `journal_head + 1`, inserts the change, returns the seq.
  - `claimBuild(string $kind, int $leaseSeconds, int $versionAtStart, int $schemaVersionAtStart): ?Claim`: under the timing rule (lock, then `clock_timestamp()`, then decide), it claims only when `owner_token` is null or `lease_until <= now`; allocates `generation_counter + 1` and records `building_generation`, `building_target = null`, `owner_token`, `journal_start_seq = journal_head`, `demand_seq_at_start = max(demand seq)`, `cursor = null`. Returns `Claim(kind, token, generation, journalStartSeq, demandSeqAtStart, versionAtStart, schemaVersionAtStart)` or null. `versionAtStart` is the highest relevant `changed_at` (Search plus the kind's required capabilities), read from the state-version rows **at claim time**, and `schemaVersionAtStart` is the contributor's `schemaVersion()`; `claimBuild` takes both as arguments from the caller.
  - `renewBuild(Fence $f, int $leaseSeconds): void` and `releaseBuild(Fence $f): void`, both fenced.
  - `claimDrainer(string $kind, int $leaseSeconds, int $quiescenceSeconds): ?string`: succeeds when `drainer_token IS NULL` or `drainer_lease_until + quiescence < now`. Returns the token.
  - `addDemand(string $kind, string $reason): int` (the seq).
  - `maxDemandSeq(string $kind): int`.
  - `recordAck(Fence $f, int $entrySeq, string $targetKey, list<int> $taskUids, string $status): void`: fenced, upsert on `(kind, entry_seq, target)`, where the `target` column holds `Target::key()` and `task_uid` holds the uids comma-joined (widen it to string(191) in Task 6).
  - `unresolvedEntries(string $kind, ?int $afterSeq = null): list<JournalEntry>`: entries with `resolved = 0`, plus every entry with `seq > afterSeq` when given.
  - `ackedFor(string $kind, string $targetKey, list<int> $seqs): list<int>`: the seqs with a `succeeded` ack for that `Target::key()`.
  - `resolveIfComplete(Fence $drainer, int $seq): bool`: in **one** fenced section (the row lock), it re-reads the current targets, checks `ackedFor` for each target key, and sets `resolved = 1` only if every current target has a succeeded ack. Returns whether it resolved. There is no unfenced `markResolved`.
  - `setStatus(Fence $f, string $status, ?string $error = null): void` (fenced).
  - `markOutOfDate(string $kind, string $error): void`: unfenced, because it is called from the live path. It never overwrites `building` with `ready`.
  - `recordBuildTarget(Fence $f, string $target): void`: sets `building_target` (fenced), so drainers include it from then on.
  - `advanceCursor(Fence $f, ?string $after, int $count): void`: sets `cursor` and adds to `processed` (fenced).
  - `satisfy(Fence $promoted, int $demandSeq, int $reconciledVersion, int $schemaVersion): void`: under the **promoted** fence, sets `satisfied_seq`, `reconciled_version` and `schema_version` to the values captured at claim time (Task 13).
  - `releaseDrainer(Fence $f): void`: clears the drainer token (fenced).
  - **`Fence` roles:** `Fence::builder(kind, token, G)`, `Fence::drainer(kind, token)`, and `Fence::promoted(kind, G)`. In `fenced()`, after locking by kind, the promoted role checks only that the fresh row's `generation = G`; it is used after promotion clears the owner token.

- [ ] **Step 1: Write the failing tests** (`AppTestCase`, using a fake `Clock`):
  - `testOneClaimAtATimeAndAnExpiredLeaseCanBeTakenWithANewGeneration`: claim A gives G1, and a second claim is null. Advance the clock past the lease. Claim B gives G2 (≠ G1) with a different token.
  - `testAStaleBuilderIsFencedOut`: after B's claim, `fenced(Fence(A))` throws `StaleFence` and the callable never runs.
  - `testAppendsAreSerialisedWithLockedSections`: open a transaction and run `locked('entries', …)` in it (holding the lock). From a **second PDO connection** (a separate `Connection` built from the test config), `appendChange` blocks until the first commits. Prove it with `statement_timeout` = 1 s on the second connection: the append fails with a lock timeout while the first holds the lock, and succeeds after commit.
  - `testDemandAndAcknowledgements`: `addDemand` seqs increase. `recordAck` upserts. `ackedFor` returns only succeeded acks. A fenced `recordAck` with a stale drainer throws.
  - `testUnresolvedIncludesOldFailures`: entries 1 (failed, unresolved), 2 (resolved), 3 (new); `unresolvedEntries('k', 2)` gives `[1, 3]`.
  - `testThePromotedFenceCompletesAndGoesStaleAfterANewerPromotion`: promote G1, and `satisfy(Fence::promoted(k, G1), …)` succeeds. Promote G2, and `satisfy` with G1 throws `StaleFence`.
  - `testResolveIfCompleteChecksTargetsUnderTheLock`: an entry acked on the active target only, while a build target exists, is not resolved. After acking the build target it resolves.
  - `testLeasesUseDatabaseTime`: with `DatabaseClock` bound, `claimBuild` writes `lease_until` equal to `clock_timestamp()` (read back in the same test) plus the lease, to within one second, whatever PHP's own clock says.
  - `testAnExpiredHolderInsideALongOuterTransactionIsRefused` (real `DatabaseClock`): claim with a 1 s lease. Open an outer transaction on the same connection and wait 1.5 s inside it (`usleep`), then call `fenced(builder fence, $callback)`: it throws `StaleFence` and `$callback` never ran (a flag stays false). This proves the check uses `clock_timestamp()`, not the transaction start.
  - `testALockWaitThatCrossesExpiryIsRefused` (real `DatabaseClock`, two connections): claim with a 1 s lease. Connection 2 locks the state row and holds it for 1.5 s, while connection 1 calls `fenced(builder fence, $callback)`, which waits on the lock. After the wait it throws `StaleFence` and `$callback` never ran. The same holds for `renewBuild`, and for `claimDrainer` succeeding only after quiescence measured after the wait.
  - `testDrainerTakeoverWaitsForQuiescence`: drainer A's lease ends at T. A claim at T + 1 with quiescence 30 is null; at T + 31 it succeeds.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.** The core of the lock and the fence:
  ```php
  public function fenced(Fence $f, callable $fn): mixed
  {
      return $this->db->transaction(function () use ($f, $fn) {
          // 1. Lock first. This may wait behind another holder; no decision has been made yet.
          $locked = $this->db->table('search_index_state')->where('kind', '=', $f->kind)
              ->update(['locked_at' => $this->clock->now()]);
          if ($locked !== 1) {
              throw new StaleFence("No state row for '{$f->kind}'.");
          }
          // 2. Then read the time and the row, both after the lock is held.
          $now = $this->clock->now();                      // clock_timestamp(), not the transaction start
          $row = $this->db->table('search_index_state')->where('kind', '=', $f->kind)->first();
          // 3. Then decide.
          $held = match ($f->role) {
              Fence::BUILDER => $row['owner_token'] === $f->token
                  && (int) $row['building_generation'] === $f->generation
                  && (string) $row['lease_until'] > $now,
              Fence::DRAINER => $row['drainer_token'] === $f->token && (string) $row['drainer_lease_until'] > $now,
              // After promotion the owner token is cleared; completion is fenced on the generation it
              // promoted, so a newer build that already promoted makes it stale.
              Fence::PROMOTED => (int) $row['generation'] === $f->generation,
          };
          if (!$held) {
              throw new StaleFence("Fence for '{$f->kind}' ({$f->role}) is no longer held.");
          }
          return $fn($row, $now);
      });
  }
  ```
  `claimBuild`, `renewBuild` and `claimDrainer` follow the same three steps inside their own `locked()` transaction. For example, `claimBuild`:
  1. lock;
  2. `$now = clock->now()`, then re-read the row;
  3. claim only if `owner_token === null || lease_until <= $now`; otherwise roll back and return null.

  `renewBuild` extends `lease_until` from that fresh `$now` only if the fence still holds by the same check.

  Timestamps are database-time strings from `DatabaseClock`, in UTC `Y-m-d H:i:s`, compared as strings (they sort). Tokens are `bin2hex(random_bytes(16))`. Every write goes through the query builder, so the tenancy hook scopes and stamps it.
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(search): claims, leases, fences and a journal for the search index`.

## Task 8: the Postgres index store, fenced

**Files:**
- Create: `packages/thallo-search/src/Store/{IndexStore,Target,TargetReceipt,ConfirmResult,ReceiptOutcome,StoreQuery,StoreResult,StoreHit,Readiness}.php`, `packages/thallo-search/src/Store/PostgresIndexStore.php`
- Modify: `packages/thallo-search/src/Engine/PostgresFtsBackend.php`. Keep its `words()` / `configFor()` as public statics; the legacy read for `format = legacy` moves into `PostgresIndexStore::searchLegacy()`.
- Test: `tests/Integration/Search/PostgresIndexStoreTest.php`

**Interfaces:**
- **Consumes:** `StateRepository::fenced()`, `DocumentId`.
- **Produces** `PostgresIndexStore implements IndexStore`:
  - `write(Target, list<SearchDocument>, Fence): list<TargetReceipt>`: inside `fenced()`, delete by `doc_id` and insert each row with `kind`, `source_id`, `subtype`, `meta` (JSON), `ts_config = PostgresFtsBackend::configFor($locale === '*' ? 'simple' : $locale)`, and `generation = max($target->generation, the existing row's generation)`, read in the same transaction before the delete. A write never lowers a row's generation, so a live write can't push a row the build already stamped below the sweep line. It returns `[TargetReceipt($target->key(), [])]`.
  - `replaceSource(list<Target>, string $kind, string $sourceId, list<SearchDocument>, Fence)`: Postgres has **one physical row** per document for every generation. In one fenced transaction it:
    1. deletes the rows of `(kind, source_id)` whose locale is not among `$docs`' locales (all of them when `$docs` is empty);
    2. upserts `$docs`, stamped `max(generation of each target, the existing row's generation)`.

    It returns one receipt per target key it satisfies (`pg:g{active}` and `pg:g{building}`), each with `taskUids = []`. Either all of it commits or none does.
  - `sweep(string $kind, int $below, Fence)`: `DELETE WHERE kind = ? AND generation < ?`.
  - `confirm()` is always `SUCCEEDED`.
  - `search(list<Target>, StoreQuery): StoreResult`.
  - `StoreQuery`: `(string $q, string $locale, array<string, KindFilter> $kinds, int $limit, int $offset, bool $legacy)`.
  - `StoreResult`: `(list<StoreHit> $hits, int $total)`.
  - `StoreHit`: `(string $kind, string $sourceId, string $locale, ?string $subtype, float $score)`; `subtype` is `subtype ?? content_type_uuid`.

- [ ] **Step 1: Write the failing tests:**
  - `testWritesAreFencedAndAStaleBuilderRollsBack`: claim A, then let the lease expire and claim B. `write(…, Fence A)` throws `StaleFence` and leaves no row. B's write lands.
  - `testSearchFiltersKindsSubtypesAndLocale`:
    - seed `entries` rows in en (subtypes t1, t2) and fr, plus a `products` row with locale `*`;
    - `kinds = ['entries' => subtypes(['t1']), 'products' => all()]` with locale en returns the t1 entry and the product, never the t2 or fr ones;
    - `products => none()` excludes products.
  - `testSweepRemovesOnlyOlderGenerationsOfOneKind`.
  - `testALiveWriteNeverLowersAGeneration`: the build writes `a` at G2; a live write targeting only the active G1 (a drainer that read targets before the build started) keeps `a` at G2, and the sweep below G2 keeps it.
  - `testOneReplacementSatisfiesBothGenerations`: with active G1 and building G2, `replaceSource` returns receipts for `pg:g1` and `pg:g2`.
  - `testReplacingKeepsRetainedLocalesAndRemovesAbsentOnes`: a source with en and fr rows, replaced with only the en document, keeps en (updated) and removes fr.
  - `testReplacingWithNoDocumentsRemovesTheSource`: `replaceSource(…, [], …)` removes every locale row of that source and none of another source's.
  - `testTheSameSourceIdInTwoWorkspacesStaysApart`: under `runAsTenant('ws1…')` and `runAsTenant('ws2…')` (enforcement on, the `TenancyFixture` pattern used by the tenancy tests), write the same `entries_x_Len` in each. Each workspace's search sees one hit. Sweeping ws1 leaves ws2's row in place.
  - `testLegacyRowsAreReadOnlyWhenAskedAndOnlyForEntries`: a row with `kind IS NULL` appears only when `legacy: true`, and only as kind `entries`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the search**, reusing `PostgresFtsBackend`'s tsquery construction (`words()`, the `$perWord` fragment, `ts_rank_cd`), without `ts_headline`, because snippets come from `present()`:
  ```php
  $scoped = $this->db->table('search_documents')->whereIn('locale', [$q->locale, '*'])->whereRaw($match, $terms);
  if ($q->legacy) {
      $scoped->whereRaw('kind IS NULL');
      // legacy rows: entries only, filtered by the entries KindFilter's subtypes on content_type_uuid
  } else {
      $scoped->where(function ($w) use ($q): void {
          foreach ($q->kinds as $kind => $filter) {
              if ($filter->mode === KindFilter::NONE) { continue; }
              $w->orWhere(function ($k) use ($kind, $filter): void {
                  $k->where('kind', '=', $kind);
                  if ($filter->mode === KindFilter::SUBTYPES) { $k->whereIn('subtype', $filter->subtypes); }
              });
          }
      });
  }
  ```
  If every filter is `NONE`, return an empty result without querying. `total` is the scoped `count()`. Hits are ordered `score DESC, id ASC` and carry `kind ?? 'entries'`, `source_id ?? entry_uuid`, and locale. Check that the builder supports nested closures with `orWhere(Closure)`; `PostgresFtsBackend` already uses `where` chains. If it doesn't, build one `whereRaw` with bound parameters.
- [ ] **Step 4: Run the tests plus `tests/Integration/Search/PostgresFtsBackendTest.php`.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(search): a fenced Postgres index store across kinds`.

## Task 9: the Meilisearch index store — per-attempt indexes, confirmed tasks, federated search, server version

**Files:**
- Modify: `packages/thallo-search/src/Engine/{MeilisearchIndex,LiveMeilisearchIndex}.php`. The seam becomes multi-index and task-aware.
- Create: `packages/thallo-search/src/Store/MeilisearchIndexStore.php`, `packages/thallo-search/src/Store/IndexNameFor.php`
- Create: `tests/Support/FakeMeilisearch.php`. An in-memory server: indexes, documents, filterable attributes, an enqueue-ordered task queue whose tasks can be held, failed or completed on demand, `version`, `multiSearch` with federation, and `index_not_found`.
- Test: `tests/Unit/Search/MeilisearchIndexStoreTest.php`

**Interfaces:**
- **Produces the seam** (`MeilisearchIndex`):
  ```php
  public function serverVersion(): string;
  /** @param array<string,mixed> $settings @return int task uid */
  public function createIndex(string $uid, array $settings): int;
  /** @param list<array<string,mixed>> $docs @return int task uid */
  public function addDocuments(string $uid, array $docs): int;
  /** @param list<string> $ids @return int task uid */
  public function deleteDocuments(string $uid, array $ids): int;
  public function deleteIndex(string $uid): int;
  /** @return array{status: string, error: ?string} status: enqueued|processing|succeeded|failed|canceled */
  public function task(int $uid): array;
  /** @return list<string> uids with this prefix */
  public function listIndexes(string $prefix): array;
  /** @param list<array{indexUid: string, q: string, filter: string}> $queries @return array{hits: list<array>, estimatedTotalHits: int} */
  public function federatedSearch(array $queries, int $limit, int $offset): array;
  /** Legacy shared index read (format = legacy, single-store only). */
  public function rawSearch(string $uid, string $query, array $params): array;
  ```
  The live implementation uses meilisearch-php:
  - `$client->version()['pkgVersion']`;
  - `createIndex($uid, ['primaryKey' => 'id'])` then `updateSettings`;
  - `index($uid)->addDocuments($docs, 'id')['taskUid']`;
  - `deleteDocuments($ids)`;
  - `getTask($uid)`;
  - `getIndexes((new IndexesQuery())->setLimit(1000))`, filtered by prefix;
  - `multiSearch($queries, (new MultiSearchFederation())->setLimit($limit)->setOffset($offset))`.

  Names go through the extension's `prefixedIndexName()`, so `IndexManager`'s prefix applies.
- **Produces** `IndexNameFor::target(string $index, ?string $workspace, string $kind, int $g): string`, which gives `{index}_v2_{workspace}_{kind}_g{g}`, or `{index}_v2_{kind}_g{g}` when `$workspace === null`.
- **Produces** `MeilisearchIndexStore implements IndexStore`:
  - `write` returns `[TargetReceipt($target->key(), [$addTaskUid])]` and **never waits**.
  - `replaceSource` works per target. When `$docs` is non-empty it calls `addDocuments`, then `deleteByFilter($uid, 'kind = "k" AND source_id = "s" AND locale NOT IN ["en", …]')`, or with no locale clause when `$docs` is empty. It returns `TargetReceipt($target->key(), [$addTaskUid?, $deleteTaskUid])`. `source_id` and `kind` join `subtype` and `locale` in the index's filterable attributes (set by `createTarget`).
  - The seam's `deleteByFilter(string $uid, string $filter): int` (task uid) is permanent, not a transition method.
  - `confirm()` polls `task()` for every uid in the receipt with a bounded wait (`search.meilisearch_task_timeout`, default 10 s, poll 50 ms), and returns the `ReceiptOutcome` defined in Shared contracts. A timeout is `PENDING` with the unfinished uids, never `FAILED`.
  - `readiness()` returns unavailable with the Global Constraints copy when `version_compare(serverVersion, '1.10.0', '<')`.
  - `search()` builds one federated query per target, with `filter` = `kind = "k" [AND subtype IN [...]] AND (locale = "L" OR locale = "*")`, using the existing `quote()`.

- [ ] **Step 1: Write the failing tests:**
  - `testWritesReturnTasksAndConfirmReportsTheRealOutcome`: a held task makes `confirm` return `PENDING` within the timeout (set it to 100 ms in the test); after completion it returns `SUCCEEDED`; an asynchronously failed task returns `FAILED`.
  - `testIndexNamesAreUniquePerAttemptAndValid`: `IndexNameFor::target('content', 'ws1abcdefghi', 'products', 7)` gives `'content_v2_ws1abcdefghi_products_g7'`, which matches `/\A[A-Za-z0-9_-]+\z/`; the single-store form omits the workspace.
  - `testFederatedSearchSpansTargetsWithFilters`: two targets (entries and products), the expected filter strings on each query, and hits merged in the fake's score order.
  - `testAnOldServerIsNotReady`: the fake version is 1.9.2, so `readiness()->available` is false and its message is exactly the readiness copy with `1.9.2`.
  - `testAWriteThatSucceedsWithAFailedDeletionIsNotConfirmed`: `replaceSource` whose add task succeeds and whose delete task fails gives `confirm` = `FAILED` for that target.
  - `testReplacingRemovesOneLocaleOrTheWholeSource`: the same two cases as the Postgres store's tests, on the fake.
  - `testAMissingIndexSurfacesAsIndexNotFound`: searching a dropped target throws `IndexNotFound`, which carries the uid.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the fake, the seam and the store.** The fake applies tasks only when the test calls `complete($uid)` / `fail($uid)`, or immediately when `autoComplete` is on (the default). Searching the fake filters by the parsed `kind` / `subtype` / `locale` clauses (a small parser over the exact strings the store emits) and scores by term count.
- [ ] **Step 4: Keep the old backend working through the transition.** `MeilisearchBackend` (still the `SearchBackend` that `SearchController`, `SearchContentReindexer` and `ReindexCommand` use until Tasks 12, 13 and 15 replace them) is updated in **this** commit to call the new seam with the legacy uid (`prefixed search.index`):
  - `addDocuments($legacyUid, …)` and `deleteDocuments($legacyUid, …)`;
  - a filtered delete through the seam's `deleteByFilter(string $uid, string $filter): int`;
  - `rawSearch($legacyUid, …)`.

  Its behaviour is unchanged: it doesn't wait on tasks, as before. `tests/Unit/Search/MeilisearchBackendTest.php` keeps every assertion, with the fake swapped for `FakeMeilisearch`. Run it with the new tests, plus `tests/Integration/Search/SearchEndpointTest.php` and `tests/Integration/Search/MeilisearchSmokeTest.php` (which skips without a server). Expected: PASS.
- [ ] **Step 5: Commit** `feat(search): a Meilisearch store with an index per workspace, kind and attempt`.

## Task 10: the locator and the drainer — live changes acknowledged per target

**Files:**
- Create: `packages/thallo-search/src/Lifecycle/{SearchIndexLocator,Drainer,LiveSearchIndex,NullSearchIndex}.php`
- Modify: `packages/thallo-search/src/SearchServiceProvider.php`. Bind `SearchIndex::class` to `LiveSearchIndex` when the capability is on, `NullSearchIndex` otherwise (the `makeContentReindexer` pattern). Bind the store by engine choice: `PostgresIndexStore`, `MeilisearchIndexStore`, or the unavailable store.
- Test: `tests/Integration/Search/DrainerTest.php` (Postgres), `tests/Unit/Search/DrainerMeilisearchTest.php` (`FakeMeilisearch`)

**Interfaces:**
- **Consumes:** `StateRepository`, `IndexStore`, `SearchSourceRegistry`.
- **Produces:**
  - `SearchIndexLocator::targets(string $kind): array{active: ?Target, building: ?Target}`. It reads the state row. On Postgres, a target is `Target('pg', 'pg', generation)`.
  - `SearchIndexLocator::buildTarget(string $kind, int $generation): Target`: on Meilisearch, `IndexNameFor::target(prefixed search.index, workspace(), kind, G)`; on Postgres, `Target('pg', 'pg', G)`.
  - `SearchIndexLocator::workspace(): ?string`: the current tenant uuid, from `Glueful\Extensions\Tenancy\Context\TenantContext::currentTenantUuid()` when enforcement is active, else null.
  - `LiveSearchIndex::changed(kind, sourceId)`: appends the change under the lock (`StateRepository::appendChange`), then calls `Drainer::drain($kind)` after commit. Any `\Throwable` from draining is caught, logged and turned into `markOutOfDate`, never thrown into the caller's request.
  - `LiveSearchIndex::kindChanged(kind, reason)`: `addDemand`.
  - `Drainer::drain(string $kind, int $budget = 50): void`.

- [ ] **Step 1: Write the failing tests:**
  - `testAPendingDeletionIsConfirmedBeforeAnyRetry` (Meilisearch): a source drops a locale. Its add task succeeds and its delete task is held past the confirm timeout, so the ack stays `pending` with the delete uid. Drainer A stops. Drainer B takes over after quiescence and **first** confirms the original delete uid: still held, B submits nothing for that target and the entry stays unresolved. Release the task as succeeded; B's next pass acknowledges the target from the original receipt, without a second `replaceSource`, and resolves the entry. The fake records exactly one delete task for that source.
  - `testAPendingTaskIsKeptWhenItsSiblingFailed` (Meilisearch): add fails, delete is still pending. The ack stays `pending` with the delete uid only, and no retry is submitted until the delete finishes. Then a retry replaces the uids.
  - `testAMeilisearchDeletionFailureKeepsTheEntryUnresolved`: the source loses a locale, the add task succeeds and the delete task fails. The ack is `failed`, the journal entry stays unresolved, and the kind is `out_of_date`. On the next drain, with deletion succeeding, it resolves.
  - `testRemovingOneOfTwoLocalesAndRemovingTheSource`, on both engines: through `LiveSearchIndex::changed`, a source that drops fr keeps en; a source that disappears is removed from every current target, and only then does its entry resolve.
  - `testAnOldGenerationAckCannotSatisfyTheBuild` (Postgres): an entry acked only as `pg:g1` is not counted for build target `pg:g2`, and promotion of G2 replays it first.
  - `testAnEntryIsAppliedOnlyWhenEveryCurrentTargetHasIt`: with an active and a building target, draining a change writes to both and records two acks; `resolved = 1`. If the building target's write fails (the fake fails its task), the entry stays unresolved and the kind is `out_of_date`.
  - `testABuildStartingBetweenTargetReadAndAckIsCaughtUp`: inject a hook after the target read that claims a build (a new building target). After the ack, the drainer re-reads targets, sees the new one, applies there, and only then resolves.
  - `testAStaleDrainerCannotAcknowledgeOrSetStatus`: drainer A's lease expires and B takes over after quiescence. A's `recordAck` and `setStatus` throw `StaleFence` and change no row.
  - `testTakeoverResolvesTheOldDrainersTasksFirst` (Meilisearch): A records an ack `pending` with a task uid and stops. B claims after quiescence and first calls `confirm` on A's pending task uids: succeeded becomes an ack, failed stays pending and is re-applied.
  - `testAPausedLiveWriterOnPostgresIsRejected`: A's fenced write after B's takeover throws `StaleFence`.
  - `testDrainingFailureNeverReachesTheRequest`: the store throws; `changed()` returns normally; the status is `out_of_date` with the error recorded (sanitised: no host or credentials, so test that a DSN in the message becomes `[redacted]`).
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement `Drainer::drain`:**
  ```php
  $token = $this->state->claimDrainer($kind, $this->lease, $this->requestTimeout + $this->margin);
  if ($token === null) { return; }                          // another drainer holds it
  $fence = Fence::drainer($kind, $token);
  $this->resolveOutstandingTasks($fence);                   // confirm previous owner's pending task uids
  foreach (array_slice($this->state->unresolvedEntries($kind), 0, $budget) as $entry) {
      $this->applyToAllTargets($fence, $entry);
  }
  $this->state->releaseDrainer($fence);
  ```
  `applyToAllTargets` loops:
  1. Read the targets.
  2. Find the target keys without a succeeded ack.
     - **A key whose ack is `pending` with task uids is resolved first:** `confirm` a receipt built from those stored uids. If it's still `PENDING`, that target is skipped this round, with no replacement submitted, because the original tasks may still run. `SUCCEEDED` acknowledges it. `FAILED` makes it eligible for a retry below.
     - Check that the lease's remaining time, in database time, exceeds `requestTimeout + margin`; if not, stop.
  3. Read `documents($sourceId)` and call `store->replaceSource(missing targets, $kind, $sourceId, documents, fence)`. That one operation upserts retained locales and deletes absent ones; with no documents, it removes the source.
  4. For each receipt, record a `pending` ack holding its task uids **before** confirming, then `confirm`:
     - **`SUCCEEDED`:** record `succeeded`.
     - **`PENDING`:** keep the ack `pending` with its **outstanding** uids, even if a sibling task failed. Do not retry that target until those uids finish.
     - **`FAILED`:** every task finished; record `failed`. Only then may a later round submit a replacement, whose receipt replaces the ack's uids.

     `recordAck` refuses to overwrite a `pending` ack's uids with a new receipt's uids unless the stored ones have been confirmed finished.
  5. Call `StateRepository::resolveIfComplete($fence, $seq)`, which re-checks the current targets under the lock.
  6. If it didn't resolve (a target appeared, or a write failed), repeat from step 1, at most 3 rounds, then leave the entry unresolved and `markOutOfDate`.
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(search): live changes are acknowledged per index, and stale drainers are fenced out`.

## Task 11: rebuild, replay, promotion, sweep and retirement

**Files:**
- Create: `packages/thallo-search/src/Lifecycle/{Rebuilder,Promotion,IndexRetirement}.php`
- Test: `tests/Integration/Search/RebuilderTest.php`, `tests/Unit/Search/RebuilderMeilisearchTest.php`

**Interfaces:**
- **Consumes:** `StateRepository`, `IndexStore`, `SearchIndexLocator`, `SearchSourceRegistry`.
- **Produces:**
  - `Rebuilder::run(string $kind): RebuildOutcome`. `RebuildOutcome` is `BUSY` (someone else holds the claim), `PROMOTED`, `FAILED`, or `LOST` (the fence was lost mid-run).
  - `IndexRetirement::retire(Fence $f, Target $t)`, which appends `{name, retired_at}` to the state row's `retired_targets` (Task 6).
  - `IndexRetirement::collect(\DateTimeImmutable $now)`, which drops retired targets past the grace period (`search.retire_grace`, default 120 s, which must exceed `search.query_timeout`, default 10 s) and orphans.

- [ ] **Step 1: Write the failing tests.** Most run on both engines through a data provider (Postgres store, or `MeilisearchIndexStore` over `FakeMeilisearch`):
  - `testAnUpdateDeletionAndInsertionDuringABuildAllLand`: a contributor double enumerates `a, b, c`. During enumeration (a hook after batch 1): update `a`, delete `b`, insert `d`, each through `LiveSearchIndex::changed`. After `run()`: `a` is current, `b` is gone, `d` is present, `c` is present.
  - `testAFailedDeletionFromBeforeTheBuildIsReplayedAndResolved`: an unresolved change for `z` whose earlier delete failed, and `z` no longer exists. After `run()`, `z` is gone and its entry is resolved.
  - `testCompletionUsesThePromotedFence`: a successful run ends with `satisfied_seq`, `reconciled_version` and `schema_version` equal to the claim-time values, never the end-time ones (bump a capability's `changed_at` mid-run: `reconciled_version` keeps the start value, so demand stays pending).
  - `testOverlappingRequestsCoalesce`: two `run()` calls while the first holds the claim: the second returns `BUSY`. Demand added mid-build leaves `satisfied_seq` at the start value, so the kind is still pending.
  - `testAKilledBuildIsRestartedNotResumed`: run A to batch 2, then stop it (the hook throws out of the process loop without releasing). Advance past the lease. Run B: a new generation, enumeration from the start, a different target name on Meilisearch, and `processed` counting from 0.
  - `testAPausedBuilderCannotTouchTheReplacement` (Meilisearch only, as the spec's test correction says): A pauses before a write (hook), B takes over, builds and promotes, A resumes and writes. A's index name differs from B's, B's promoted index documents are unchanged, and A's write landed only in `…_g{G_A}`. The Postgres twin, `testAPausedBuilderIsRejectedByTheRowLock`: A's resumed write throws `StaleFence` and rolls back.
  - `testPromotionWaitsForAnEntryAcknowledgedOnlyOnTheOldTarget`: after replay, append a change and drain it with the build target hidden from the drainer (a hook), so it is acked only on the old active target. Promotion aborts, replays it into the build target, retries and promotes. The new active target has the change.
  - `testADrainerWaitingOnATaskDuringPromotionLeavesTheEntryPendingForTheNewTarget` (Meilisearch): hold the drainer's task, promote, then complete the task. The entry has an ack only for the old target, stays unresolved, and the next drain applies it to the new active target.
  - `testAppendVersusPromotionInBothOrders`: with a barrier (two connections, `statement_timeout`):
    - an append holding the lock first is committed, then included in S, then required;
    - a promotion holding the lock first makes the append wait, and the append gets a seq above S and stays pending for the new target.
  - `testAnAsynchronousBatchFailureFailsTheBuildAndNeverSweeps` (Meilisearch): fail the task of batch 2. The outcome is `FAILED`, the status `failed`, the previous active target is untouched and still active, and no target was retired.
  - `testANewerFailedUpdateOutlivesAnOlderBuild`: a live update fails after the build's last replay point. The build promotes, but the status is `out_of_date`, not `ready`.
  - `testRetirementGraceAndOrphans` (Meilisearch): after promotion the old active target is retired, not dropped. `collect(now)` keeps it; `collect(now + grace + 1)` drops it. An abandoned build index recreated by a late write (the fake creates it on write) is dropped by `collect` once it is neither active, nor claimed, nor in grace.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement `Rebuilder::run`:**
  ```php
  $claim = $this->state->claimBuild($kind, $this->lease, $this->demand->relevantVersion($kind), $this->contributor($kind)->schemaVersion());
  if ($claim === null) { return RebuildOutcome::BUSY; }
  $fence = Fence::builder($kind, $claim->token, $claim->generation);
  $target = $this->locator->buildTarget($kind, $claim->generation);   // Meilisearch: new uid; Postgres: 'pg'
  try {
      $this->store->createTarget($target);
      $this->state->recordBuildTarget($fence, $target->name);           // drainers now include it
      for ($after = null; ;) {
          $page = $this->contributor($kind)->enumerate($after, $this->batch);
          [$receipt] = $this->store->write($target, $page->documents, $fence);
          if ($this->store->confirm($receipt) !== ConfirmResult::SUCCEEDED) {
              $this->state->setStatus($fence, 'failed', 'A batch did not complete.');
              return RebuildOutcome::FAILED;                             // no sweep, no promotion
          }
          $this->state->advanceCursor($fence, $page->nextAfter, count($page->documents));
          if ($page->nextAfter === null) { break; }
          $after = $page->nextAfter;
          $this->state->renewBuild($fence, $this->lease);
      }
      $this->replay($fence, $target, $claim->journalStartSeq);
      $this->promotion->promote($fence, $target, $claim);              // loops replay until S is covered
      $promoted = Fence::promoted($kind, $claim->generation);          // the owner token is gone now
      $this->sweepOrRetire($promoted, $kind, $target);
      $this->state->satisfy($promoted, $claim->demandSeqAtStart, $claim->versionAtStart, $claim->schemaVersionAtStart);
      return RebuildOutcome::PROMOTED;
  } catch (StaleFence) {
      return RebuildOutcome::LOST;
  }
  ```
  `replay()` takes every entry with `seq > journalStartSeq` plus every unresolved entry. Each one goes through `store->replaceSource([$target], $kind, $sourceId, documents($sourceId), $fence)` and is acknowledged for the build target key only from a `SUCCEEDED` receipt, so a source that vanished is deleted, and a failed deletion keeps the entry unacknowledged for that target.

  `Promotion::promote` runs inside `StateRepository::fenced()`, which takes the same row lock as `appendChange`:
  1. read `S = journal_head`;
  2. compute the relevant seqs: unresolved, or `seq > journalStartSeq`, and `≤ S`;
  3. `missing = relevant - ackedFor(build target->key())`. If non-empty, leave the transaction, replay the missing entries, and retry (at most 5 rounds, then `FAILED`);
  4. otherwise set `generation = G`, `active_target = build target`, `building_* = null`, `owner_token = null`, `status = (has unresolved failed entries newer than journalStartSeq ? 'out_of_date' : 'ready')`, `last_success_at = now`.

  `sweepOrRetire` runs after promotion, in a fenced section keyed on the promoted generation: `Fence::promoted($kind, G)` checks `generation = G`, because promotion clears the owner token. A newer build that has already promoted is therefore never swept by an older one.
  - **Postgres:** `store->sweep($kind, G, $fence)`.
  - **Meilisearch:** retire the previous active target, and any of this kind's targets that are neither active nor claimed.
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(search): rebuilds that restart on takeover, replay, promote atomically and retire safely`.

## Task 12: the entries contributor, and entry events feed the journal

**Files:**
- Create: `packages/thallo-search/src/Sources/EntriesContributor.php`
- Modify: `packages/thallo-search/src/Index/DocumentBuilder.php`. Add `text(IndexableContent, ContentSchemaReader): string`, the body words `build()` already joins, so `present()` and the index agree on what an entry says.
- Modify: `packages/thallo-search/src/Index/SearchContentReindexer.php`. `reindexEntry($uuid, $locale)` becomes `SearchIndex::changed('entries', $uuid)` (all locales are recomputed by `documents()`). It remains the `ContentReindexer` the core listener calls, so `ReindexSearchListener` is unchanged.
- Modify: `packages/thallo-search/src/SearchServiceProvider.php`. Register `EntriesContributor` into the registry at boot, outside the gate (metadata is always discoverable; `KindAvailability` decides availability, and every kind is unavailable while `thallo.search` is off).
- Test: `tests/Integration/Search/EntriesContributorTest.php`

**Interfaces:**
- **Consumes:** `IndexableContentReader`, `DocumentBuilder`, `ContentTypeReader`, `VisibilityResolver`.
- **Produces** `EntriesContributor implements SearchSourceContributor`:
  - `kind() = 'entries'`, `label() = 'Pages & posts'`, `requiredCapabilities() = []`, `schemaVersion() = 1`.
  - `documents()`: for each enabled locale, `getIndexablePublished` builds a `SearchDocument` with `subtype = contentTypeUuid`, `title`/`body` from `DocumentBuilder::build()` and no meta.
  - `enumerate()` pages **distinct entries**, never entry-and-locale records, so a page boundary cannot split one entry's translations.
    - **New reader method:** add `publishedEntryUuidsAfter(?string $afterUuid, int $limit): list<string>` to `IndexableContentReader`. It returns distinct entry uuids that have at least one published locale, ordered by uuid. Implement it in `EngineIndexableContentReader` with `SELECT DISTINCT entry uuid … WHERE uuid > ? ORDER BY uuid LIMIT ?`.
    - **Building the page:** the contributor calls `documents($uuid)` for each returned uuid (every published locale). `nextAfter` is the last uuid when `count === $limit`, else null.
    - It is a contracts change; the existing offset method stays for its other callers.
  - `visibilityFilter()`: `VisibilityResolver::resolve(audience scopes)` gives `all()` when `allAccess`, else `subtypes($visibleTypeUuids)`.
  - `present()`: for each id, `getIndexablePublished($id, $locale)`. Null means dropped. A type not accessible to the audience means dropped. Otherwise `ResultDisplay(title, href, mb_strcut(DocumentBuilder::text(...), 0, 20480))`.

- [ ] **Step 1: Write the failing tests:**
  - `testDocumentsCoverEveryPublishedLocaleAndNothingUnpublished`;
  - `testEnumerationIsOrderedAndResumable`: seed 5 entries; pages of 2 give all 5 in uuid order; `nextAfter` is null at the end;
  - `testATranslatedEntryIsNeverSplitAcrossPages`: three entries, the middle one published in en, fr and de. Pages of size **1** and of size 2 together yield every (entry, locale) pair exactly once;
  - `testPresentReadsCurrentRecords`: publish an entry, change its body text (removing a sentence) and republish **without** draining (`NullSearchIndex` bound). `present()` returns the new text, without the removed sentence;
  - `testPresentDropsUnpublishedAndPrivateTypes`: an unpublished entry gives null; a type flipped to private gives null for the public audience and a display for an api key with `read:content:{slug}`;
  - `testPublishingAppendsAChange`: publish gives one `search_index_changes` row for `('entries', uuid)`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** the contributor, the reader method (contract plus `EngineIndexableContentReader` plus its existing test file `tests/Integration/Search/IndexableContentReaderTest.php` gaining `testListsDistinctPublishedEntriesAfterAUuid`), `DocumentBuilder::text()`, and the reindexer change.
- [ ] **Step 4: Run the tests plus `tests/Unit/Search tests/Integration/Search`.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(search): entries become a search source, and edits feed the journal`.

## Task 13: durable demand and recovery — the wake-up job, reconcile, boot recovery, schedule, `search:reindex`

**Files:**
- Create: `packages/thallo-search/src/Lifecycle/{DemandResolver,Reconciler,SearchWakeJob,WorkspaceRunner,SearchDemand}.php`, `packages/thallo-search/src/Console/ReconcileCommand.php` (`search:reconcile [--full] [--all]`)
- Modify: `packages/thallo-search/src/Console/ReindexCommand.php` (rewritten), `packages/thallo-search/src/Console/StatusCommand.php` (the per-kind table; `--all`)
- Modify: `packages/thallo-search/src/SearchServiceProvider.php` (an `onBeginRequest` hook calls `Reconciler::recoverIfDue()`, at most once per 60 s per process, guarded by a `CacheStore` key; register the commands)
- Modify: `config/schedule.php`: entries `search_reconcile` (`* * * * *`, `search:reconcile`) and `search_reconcile_full` (`30 3 * * *`, `search:reconcile --full`, `enabled => env('SEARCH_FULL_RECONCILE', true)`)
- Test: `tests/Integration/Search/DemandAndRecoveryTest.php`, `tests/Unit/Search/ReindexCommandTest.php` (rewritten)

**Interfaces:**
- **Produces:**
  - `DemandResolver::pending(string $kind, array $snapshotRows, array $stateRow): ?string`, the reason or null. The order:
    1. `'new_workspace'` when there is no state row;
    2. `'capability'` when `max(changed_at of thallo.search + requiredCapabilities) > reconciled_version`;
    3. `'schema'` when `schemaVersion != schema_version`;
    4. `'demand'` when `maxDemandSeq > satisfied_seq`.

    Only available kinds are considered.
  - `Reconciler::runWorkspace(bool $full): void`. For each available kind, a full run or one with pending demand runs `Rebuilder::run`. **The reconciler writes no versions itself.** `reconciled_version`, `schema_version` and `satisfied_seq` are written only by `Rebuilder` on `PROMOTED`, under the promoted fence, with the claim-time values (Task 11). `BUSY`, `FAILED` and `LOST` leave all three unchanged, so the demand stays outstanding for the next scheduled run. Afterwards it runs `IndexRetirement::collect`, the drainer, and the legacy check (Task 14).
  - `DemandResolver::relevantVersion(string $kind): int`: the highest `capability.{id}.changed_at` across `thallo.search` and the kind's required capabilities, read fresh from `thallo_system_flags`.
  - `Reconciler::runAll(bool $full)` goes through `WorkspaceRunner::each()`: `ForEachTenant::run` when enforcement is active, else the single store.
  - `Reconciler::recoverIfDue()`: the boot recovery, which runs `runAll(false)` only when any workspace has demand. It also compares the evaluated availability of search kinds against `search.availability_marker` in `SystemChannel`, for config-only flips.
  - `SearchWakeJob extends Job`. `getData()['workspace']` is `?string`; `handle()` runs `Reconciler::runWorkspace(false)` inside `WorkspaceRunner::in($workspace)`.
  - `SearchDemand::request(string $kind|null, string $reason): array{recorded: true, queued: bool}`. It inserts demand row(s) in the current transaction, then `afterCommit(fn () => try push SearchWakeJob catch → queued=false)`. The response's `queued` is known only after commit, so the HTTP layer reports "recorded" and, separately, a later status read reports whether processing started.

- [ ] **Step 1: Write the failing tests:**
  - `testAnOffOnCycleWithNoSearchBootStillCreatesDemand`: with the kind `ready` and `reconciled_version = v`, put Commerce off then on through `CapabilityStateStore::put` without running the search pack. `pending('products', …)` returns `'capability'`.
  - `testAFailedQueueLeavesDemandForTheSchedule`: bind a `QueueManager` double whose `push` throws. `SearchDemand::request('entries', 'manual')` records the row and returns. `search:reconcile` then runs the build and satisfies it.
  - `testARolledBackRequestDispatchesNothing`: `request()` inside a transaction that throws gives no demand row and no push.
  - `testANewWorkspaceGetsDemandAndRebuildsAlone`: create workspace ws3 (`TenancyFixture`) after Search was on. Its first suggestion request (`/_search/suggest?q=a`, after Task 15; here assert `DemandResolver` directly) has `'new_workspace'`. `runAll()` builds ws3 and leaves ws1's generation unchanged.
  - `testTaxonomyAndManualDemandAndCompletionAcknowledgesOnlyStartDemand`: add demand during a run (a hook); afterwards `satisfied_seq` equals the start value and `pending` is still `'demand'`.
  - `testAFailedCapabilityTriggeredRebuildIsRetriedByTheSchedule`: create capability demand. Make the first run's batch fail (store double), so the outcome is `FAILED`, `reconciled_version` is unchanged, and `pending` is still `'capability'`. Let the store succeed and run `search:reconcile` (the scheduled command): it rebuilds and the demand clears.
  - `testAFailedSchemaTriggeredRebuildIsRetriedByTheSchedule`: the same with a bumped `schemaVersion()`.
  - `testBusyAndLostLeaveDemandOutstanding`: a held claim gives `BUSY`; a fence lost mid-run gives `LOST`. In both, `pending` is unchanged.
  - `testAFullReconcileRebuildsAReadyKind`: a ready kind with no demand, then `runWorkspace(true)`, gives a new generation.
  - `testAConfigOnlyFlipIsRecoveredAtBoot`: boot with `bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => true]])` (no switch written) and handle one request. `recoverIfDue` creates and runs demand for `entries`.
  - `ReindexCommandTest`:
    - `testRecordsDemandOnly`: without `--wait`, it prints "Rebuild requested for entries, products." and touches no documents;
    - `testWaitRunsUnderTheFences`: `--wait --kind=entries` runs `Reconciler::runWorkspace` and prints progress;
    - `testReindexWaitDoesNotStartASecondBuilder`: with a held claim, `--wait` polls until the claim is released (fake clock), never claims concurrently, then reports;
    - `testRemovedFiltersFailWithoutTouchingTheIndex`: `--type=post` exits 1 with exactly "`--type`/`--locale` are no longer supported: search rebuilds whole kinds. Use `search:reindex --kind=entries`." and the documents are unchanged.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** the classes, the commands and the schedule entries. `ReindexCommand` keeps `--type`/`--locale` declared (so the message can be printed) and returns `self::FAILURE` when either is given. It drops its direct backend writes. `StatusCommand` prints the engine and its readiness, then one row per kind (status, documents, processed, last success, last error, demand pending). `--all` loops workspaces and adds the installation-wide `search.legacy_index`.
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** with a changelog `### Changed` bullet: "**`search:reindex` now requests a rebuild** and returns. Add `--wait` to run it in the foreground. `--type` and `--locale` are removed: search rebuilds whole kinds; use `--kind=entries`." Message: `feat(search): durable rebuild demand, scheduled recovery, and search:reindex joins the lifecycle`.

## Task 14: cutover from the legacy index

**Files:**
- Create: `packages/thallo-search/src/Lifecycle/Cutover.php`
- Modify: `Reconciler` (the entries kind builds first while `format = legacy`; then the flip; then legacy cleanup), `SearchIndexLocator` (`readMode(): 'legacy'|'v2'|'rebuilding'`)
- Test: `tests/Integration/Search/CutoverTest.php`, `tests/Unit/Search/CutoverMeilisearchTest.php`

**Interfaces:**
- **Produces** `SearchIndexLocator::readMode(string $kind): string`:
  - **v2** when `format = 'v2'` and the kind has an active target;
  - **empty** when `format = 'v2'` and the kind has no active target (the kind returns nothing);
  - **legacy** when `format = 'legacy'` and the kind is `entries` and the read is allowed. Allowed means the Postgres engine, or Meilisearch on a single-store site (no enforcement);
  - **rebuilding** otherwise.
- **Produces** `Cutover::flipIfReady(): void`: inside `locked('entries')`, if `entries` is `ready` in v2, set `format = 'v2'` on **every** kind row of the workspace in one statement (`update` where `kind IS NOT NULL`). Then Postgres deletes `kind IS NULL` rows (idempotent).
- **Produces** `Cutover::retireLegacyMeilisearchIndex(): void`: under `runAsSystem`, reads every workspace's state rows. If none has `format = 'legacy'`, it calls `deleteIndex(prefixed search.index)` and sets `SystemChannel` `search.legacy_index = retired`. Failure leaves the flag and is retried by the next reconcile.

- [ ] **Step 1: Write the failing tests:**
  - `testAnUpgradeKeepsEveryEntryExactlyOnce` (Postgres): seed legacy rows, then run the reconciler. Searching each published title returns it exactly once, and afterwards there are no `kind IS NULL` rows.
  - `testEntriesStaySearchableThroughAnInterruptedUpgrade` (single-store, both engines): kill the build midway (hook). Searching still returns legacy results. Resume it, flip, and results come from v2.
  - `testTwoWorkspacesWhereOneFinishesAndOneIsInterrupted` (Meilisearch, enforcement on):
    - A finishes and B is interrupted;
    - the legacy index still exists, and `search.legacy_index` is not `retired`;
    - B's `readMode('entries')` is `rebuilding`, and a query never returns legacy hits;
    - Postgres twin: A's legacy rows are gone and B's are intact;
    - after B finishes, the legacy index is deleted and the flag is `retired`.
  - `testProductsAreEmptyUntilBuiltAfterTheFlip`: after the flip, before the products build, `readMode('products')` is `empty`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** the read mode, the flip, the legacy cleanup and the installation-wide retirement.
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** with a changelog bullet: "**Upgrading rebuilds the search index into its new format** with no gap. Entries keep answering from the old index until the new one is ready (where that is safe). Sites with workspaces on Meilisearch show “rebuilding” until each workspace's own index is ready." Message: `feat(search): cut over from the legacy index without a gap`.

## Task 15: the shared query service, snippets, `/v1/search` and `/_search/suggest`

**Files:**
- Create: `packages/thallo-search/src/Query/{SearchQueryService,SearchOutcome,SearchResultItem,SnippetBuilder,KindAvailability}.php`, `packages/thallo-search/src/Http/SuggestController.php`
- Modify: `packages/thallo-search/src/Http/SearchController.php` (a thin adapter), `packages/thallo-search/routes/public-routes.php` (add `GET /_search/suggest`, `tenant_profile:public`, `tenant_bootstrap`, `rate_limit`, `->rateLimit(120, 1, by: 'ip')`)
- Delete: `packages/thallo-search/src/Query/{SearchRequest,SearchResults,Hit}.php` and `src/Engine/SearchBackend.php`, plus their callers (superseded by `IndexStore` and the service). Move `tests/Unit/Search/MeilisearchBackendTest.php`'s remaining legacy-read assertions into Task 9's store test.
- Test: `tests/Unit/Search/SnippetBuilderTest.php`, `tests/Integration/Search/SearchQueryServiceTest.php`, `tests/Integration/Search/SearchEndpointTest.php` (rewritten), `tests/Integration/Search/SuggestEndpointTest.php`

**Interfaces:**
- **Consumes:** `SearchInput`, `CursorSigner`, `CursorBinding`, `SearchSourceRegistry`, `IndexStore`, `SearchIndexLocator`, `CapabilityRegistry`.
- **Produces:**
  - `KindAvailability::available(): array<string, SearchSourceContributor>`: registered contributors whose `requiredCapabilities` (plus `thallo.search`) are all enabled. `reasonFor()` names the first missing capability's label ("Requires Search" or "Requires Commerce").
  - `KindAvailability::reasonFor(string $kind): ?string`: "Requires Commerce" (the label of the first missing capability), or "No longer provided by any installed feature" for an unregistered kind.
  - `SearchQueryService::search(SearchInput $in, SearchAudience $a, int $limit, bool $refill, ?string $typeUuid = null): SearchOutcome`.
  - `SearchOutcome`:
    - `string $state`: `results`, `empty_batch`, `no_matches`, `scope_unavailable`, `rebuilding`, `unavailable` or `no_query`;
    - `list<SearchResultItem> $items`;
    - `?string $next`;
    - `int $total`;
    - `?string $unavailableReason`;
    - `?string $scopeLabel`.
  - `SearchResultItem`: `(string $kind, string $kindLabel, string $sourceId, string $locale, ResultDisplay $display, string $snippetHtml, float $score)`.
  - `SnippetBuilder::build(string $text, string $q, int $maxChars = 160): string`: safe HTML with `<mark>`.

- [ ] **Step 1: Write the failing tests.**
  ```php
  // SnippetBuilderTest
  public function testFindsSpansInPlainTextThenEscapesEachSegment(): void
  {
      self::assertSame('Tom &amp; <mark>Jerry</mark> &lt;3 &quot;cats&quot;',
          SnippetBuilder::build('Tom & Jerry <3 "cats"', 'jer'));
      // Matching runs on plain text: "&" never becomes "&amp;" to be matched by "amp".
      self::assertSame('Tom &amp; Jerry', SnippetBuilder::build('Tom & Jerry', 'amp'));
  }
  public function testMultibyteAndPrefixAndNoMatch(): void
  {
      self::assertSame('Le <mark>Café</mark> noir', SnippetBuilder::build('Le Café noir', 'caf'));
      self::assertSame('Le Café noir', SnippetBuilder::build('Le Café noir', 'zzz')); // opening excerpt, no highlight
  }
  ```

  `SearchQueryServiceTest` (Postgres, with entries and a test products contributor registered):
  - `testAWithdrawnCandidateIsNeverShownAndTheBatchIsRefilled`: limit 2, where 3 matching products and the first 2 are withdrawn (`present` gives null). Results show the third, and `total_approximate` is true.
  - `testARunOfWithdrawnCandidatesBeyondTheRefillLimit`: limit 2 and 9 withdrawn followed by 1 valid. With batch 2 plus 3 refills, 8 candidates are examined. The state is `empty_batch` with a `next` cursor. Following `next` reaches the valid one.
  - `testTheCursorAdvancesOnlyPastExaminedCandidates`: the last batch fetched 2 and filled the page after examining 1, so the cursor's raw offset points at the unexamined second.
  - `testAnUnavailableScopeNeverWidens`: scope `products` with Commerce off gives `scope_unavailable` and no items.
  - `testRemovedTextNeverAppears`: a published entry loses a sentence and its index update fails (store double). Searching a word in the removed sentence may match, but the result's `snippetHtml` and `display` do not contain the sentence.
  - `testRebuildingAndUnavailable`: a workspace in `rebuilding` read mode gives `rebuilding`; `IndexStore::search` throwing gives `unavailable`; a missing Meilisearch index is retried once after reloading targets and succeeds (with a forced zero grace); a second miss gives `unavailable`.
  - `testAFederatedQueryRetriesAfterAMissingIndex` (Meilisearch).

  `SearchEndpointTest` (`/v1/search`):
  - `testTypeNarrowsToThatTypeOnly`: matching Posts and Pages both contain "rose"; `type=post` returns only Posts, and every hit's `type` is `post`;
  - `testUnknownAndInaccessibleTypesKeepTheirResponses`: `type=nope` gives 404 "Content type not found."; a private type without a key gives 403;
  - `testEntryHitsKeepTypeAndLocale` and `testAnEmptyQueryIs422`;
  - `testDefaultIsEntriesOnly`;
  - `testKindAllAndProducts`;
  - `testOffsetWindowsAreFixedAndNeverDuplicate`: offset 0 and 10 with withdrawn candidates inside the first window; no item appears on both pages;
  - `testCursorBindingIncludesType`: a `next` from `type=post` replayed with `type=page`, or without `type`, gives 400;
  - `testErrors`: 422 for `kind=reviews`, `type=post&kind=products`, `offset` with `cursor`, a missing `locale`, array-valued `q`/`kind`/`locale`/`cursor`; 400 for a tampered cursor; never a 5xx;
  - `testTheEnvelope`: `hits[].kind`, `uuid` (for entries) / `source_id`, `title`, `href`, `snippet`, `score`; `total`; `total_approximate: true`; `next`.

  `SuggestEndpointTest`:
  - `testPublicOnlyEvenForASignedInSession`: a session with full content access still gets no private-type entries;
  - `testNoStoreAndShape`: `Cache-Control: no-store`; `{state, items[{kind, kind_label, title, href, image, price, snippet}], see_all}`;
  - `testDistinctStates`: `rebuilding`, `unavailable` and `no_matches` are distinct values of `state`;
  - `testMalformedInputs`: array-valued `q`/`scope`/`locale` and a 250-character multibyte `q` give 200, never a 5xx.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement `SearchQueryService::search`:**
  1. `q === ''` gives `no_query`.
  2. If the scope is unavailable or names an unavailable kind, `scope_unavailable` with the label and reason.
  3. Kinds = all available kinds, or the one. Each read mode comes from `SearchIndexLocator`: `rebuilding` for any kind (and every requested kind rebuilding or empty) gives `rebuilding`; kinds in `empty` mode are skipped.
  4. Filters = `contributor->visibilityFilter($a)`, per kind. **The API's `type` narrows `entries`** (the adapter resolves it first, step 4 of the adapter below): the entries filter becomes `all() ∩ {typeUuid}` = `subtypes([typeUuid])`, or `subtypes(S) ∩ {typeUuid}`. An empty intersection can't happen, because the adapter refuses an inaccessible type first. `SearchQueryService::search` takes it as `?string $typeUuid`.
  5. Start from `cursor->rawOffset`, or `offset`, or 0.
  6. Loop over batches of `limit` (at most `1 + (refill ? 3 : 0)`): `store->search(targets, StoreQuery(...))`; group hits by kind, then `present($a, locale, ids)`; walk the hits in score order, counting examined; keep the non-null ones until `limit`. Stop when the page is full or the backend is exhausted (`offset + count(batch hits) >= total`).
  7. `next` = sign(binding, offset + examined) when not exhausted.
  8. The state is `results` (items exist), `empty_batch` (none, not exhausted) or `no_matches` (none, exhausted).
  9. The snippet is `SnippetBuilder::build(display->text, q)`.

  `IndexNotFound` reloads targets once and retries. Any other `\Throwable` gives `unavailable`, and the message is logged.

  `SnippetBuilder`:
  1. Lowercase the words of `q` with `mb_strtolower`, split on `[^\p{L}\p{N}]+`.
  2. Find the match spans in the **plain text**, using `preg_match_all('/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words)) . ')/iu', $text, $m, PREG_OFFSET_CAPTURE)`.
  3. Choose the window around the first span, cut on character boundaries (`mb_*` with the byte offsets converted).
  4. Then emit `htmlspecialchars(segment)` and `<mark>` + `htmlspecialchars(match)` + `</mark>` alternately.
- [ ] **Step 4: Implement the adapters.**
  - **`SearchController`** (`/v1/search`):
    1. `SearchInput::from($request->query->all(), Surface::API, …)`, catching `InvalidSearchInput` into `Response::error(msg, status)`. An empty or missing `q` keeps today's 422: "A non-empty `q` query parameter is required."
    2. The audience is `apiKey(scopes)` when the request carries `api_key_scopes`, else `public()`.
    3. The cursor is verified against `CursorBinding::of(...)`; a bad one gives 400.
    4. **Type:** when `type` is given, `ContentTypeReader::findUuidBySlug`. Null keeps today's `Response::notFound('Content type not found.')`, and a type the audience can't see keeps today's `Response::forbidden('This content type requires a scoped API key')` (`VisibilityResolver::isTypeAccessible`). The uuid is passed to the service.
    5. Search with `refill = (cursor present)`.
    6. Map to the envelope. Entry hits keep today's fields `uuid`, `type` (the content-type **slug**, from `ContentTypeReader::deliveryTypes()[subtype]['slug']`; `StoreHit` gains `?string $subtype` for this) and `locale`. Every hit gains `kind`, plus `source_id` for non-entries. `state` `unavailable` or `rebuilding` gives 503.
  - **`SuggestController`**: `Surface::PUBLIC`, `public()` audience, limit 6, refill on; adds `Cache-Control: no-store` and `see_all` = `/search?q=…&scope=…&locale=…`.
- [ ] **Step 5: Run the tests plus `tools/runtime-browser` `docs-search.spec.js`** (`cd tools/runtime-browser && npx playwright test tests/docs-search.spec.js`). Expected: PASS.
- [ ] **Step 6: Regenerate OpenAPI** for `/v1/search` (memory: `CACHE_DRIVER=array composer docs:openapi`, then splice only the changed operation into `docs/openapi.json` by hand), then `cd admin && pnpm gen:api`.
- [ ] **Step 7: Commit** with changelog bullets under `### Added` / `### Changed`:
  - `/v1/search` gains `kind` (`all`, `products`) and `cursor`/`next`, and every response says `total_approximate`. Snippets come from the current text.
  - Results are checked against current records, so a withdrawn item is never shown.

  Message: `feat(search): one query path for the API, suggestions and the results page`.

## Task 16: Commerce contributes products

**Files:**
- Create: `packages/thallo-commerce/src/Search/{ProductsSearchContributor,PushCatalogChangesToSearch}.php`
- Modify: `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php`:
  - `services()`: both classes, autowired;
  - `boot()`, **outside** the enabled gate (like `registerShopBlockTypeContributor`): register the contributor when the container has `SearchSourceRegistry`, with a plain `register()`. A duplicate `products` kind throws, as the registry intends; nothing is silently skipped. The kind's metadata (`kind`, `label`, `requiredCapabilities`, `schemaVersion`) is therefore discoverable while Commerce is off, which is what gives "Requires Commerce" in the scope picker and the block's unavailable message. The listener registration below stays where Commerce registers its other listeners;
  - `registerShopCachePurgeListeners()`: also listen to `StorefrontCatalogChanged` with `PushCatalogChangesToSearch::onCatalogChanged`.
- Create: `packages/thallo-render/src/Delivery/SanitizingHtmlTextExtractor.php` (implements `HtmlTextExtractor` with `RenderContextExtension::safeHtml()`'s sanitizer, then `strip_tags`, entity decode and whitespace collapse); bind it in `RenderServiceProvider::services()`.
- Test: `tests/Integration/Commerce/ProductsSearchContributorTest.php`

**Interfaces:**
- **Consumes:** `ProductRepository::activeFilteredQuery()` and `findActiveBuyerAvailableByUuids()`, `ShopProductCardAssembler`, `CommerceTenantResolution`, `HtmlTextExtractor` (soft), `SearchIndex`.
- **Engine work is resolved only when used.** The contributor takes the `ContainerInterface` and resolves `ProductRepository`, `ShopProductCardAssembler` and `CommerceTenantResolution` lazily inside `documents`/`enumerate`/`present`, guarded by `$container->has(ProductRepository::class)`, as `ShopBlockPreview` does. With the engine absent, it returns `[]`, an empty page, and all-null presentations.
- **Produces** `ProductsSearchContributor`:
  - `kind() = 'products'`, `label() = 'Products'`, `requiredCapabilities() = ['thallo.commerce']`, `schemaVersion() = 1`.
  - `documents($uuid)`: `[SearchDocument('products', $uuid, '*', null, url, name, body, meta)]` when eligible, else `[]`.
  - `enumerate()`: keyset on `uuid`.
  - `visibilityFilter() = KindFilter::all()`.
  - `present()`: chunks of 100 through `findActiveBuyerAvailableByUuids` and the card assembler.

  The body is name, then plain description, then category names, then tag names, joined with blank lines.

- [ ] **Step 1: Write the failing tests** (seeding with `CatalogService::createProduct`, as `ShopWishlistEndpointTest::seedProduct` does, cleaning the commerce tables in setUp):
  - `testOnlyListedProductsAreDocuments`: a draft, an archived and a deleted product give `[]`; an active one gives one document with locale `*`, `href` `/shop/products/{slug}`, and a body containing the category and tag names;
  - `testEnumerationIsKeysetOrderedByUuid`;
  - `testPresentIsCurrentAndDropsTheWithdrawn`: rename a product without draining; `present()` returns the new name. Archive one; `present()` returns null;
  - `testARenamedProductShowsItsCurrentNameWhileTheIndexIsStale`: through `SearchQueryService`, the index holds the old name (draining disabled), and a search for a word in the **old** name returns the item displaying the **new** name;
  - `testPresentReturnsPriceAndCover`: `price` is `ShopMoney::display` of the cheapest active variant, and `image` is the cover URL;
  - `testCatalogChangesReachTheJournal`: `StorefrontCatalogChanged(tenant, 'product.updated', uuid)` appends `('products', uuid)`; a null-uuid `category.changed` adds demand `taxonomy`;
  - `testTheKindIsDiscoverableWhileCommerceIsOff`: boot with Commerce off. `SearchSourceRegistry::all()` has `products`, `KindAvailability::reasonFor('products')` is "Requires Commerce", and `documents()` / `present()` return empty without touching the engine.
  - `testCommerceOffExcludesProductsWithoutDeletingThem`: build both kinds, then boot with Commerce off; `/_search/suggest?scope=` returns no products, and the `products` documents are still in `search_documents`;
  - `testCommerceBackOnReconcilesWhatChangedWhileOff`: while off, delete one product and rename another directly through `CatalogService` (the listener is not registered while off). Turn it back on and run `search:reconcile`: the deleted one is gone from the index, and the renamed one matches its new name.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
  - **Enumeration:**
    ```php
    $rows = $this->products->activeFilteredQuery($this->context, $tenant, null)
        ->select(['uuid'])->where('uuid', '>', $after ?? '')->orderBy('uuid', 'ASC')->limit($size)->get();
    ```
    then build documents for those uuids in one batch: the card assembler for name/url/price/cover, `HtmlTextExtractor` for the description, and the category and tag joins.
  - **`nextAfter`:** the last uuid when the count equals `$size`, else null.
  - **The listener** guards with `instanceof StorefrontCatalogChanged`. It runs inside `WorkspaceRunner::in($event->tenantUuid)`; Commerce reuses its existing `LinkReconciler::runInTenant` helper shape.
- [ ] **Step 4: Run the tests plus `tests/Integration/Commerce/ShopWishlistEndpointTest.php`.** Expected: PASS.
- [ ] **Step 5: Commit**, with the Commerce guide line added in Task 22 and a changelog `### Added` bullet: "**Products appear in search** when Search and Commerce are both on, always showing the current name, price and picture." Message: `feat(commerce): products are a search source`.

## Task 17: the Search block — definition, header palette, template, scope state, assets, cache

**Files:**
- Create: `packages/thallo-search/src/Starter/SearchBlockTypeContributor.php`, `packages/thallo-search/src/Render/{SearchTemplatePathContributor,SearchStylesheetContributor,SearchReservedPathContributor}.php`, `packages/thallo-search/src/Assets/{SearchAssetMap,SearchAssetController}.php`, `packages/thallo-search/src/Sources/RegistrySearchScopeStatus.php`
- Create: `packages/thallo-search/templates/blocks/search.twig`, `packages/thallo-search/templates/search/_form.twig`, `packages/thallo-search/assets/search.css`, `packages/thallo-search/assets/search.js` (a stub in this task that only guards `window.thalloSearch`; Task 18 fills it)
- Modify:
  - `packages/thallo-search/src/SearchServiceProvider.php`. Boot: the block contributor and the reserved path (`search`, exact) **outside** the gate; the template path, stylesheet and asset route `/_thallo/search/{file}` **inside** it; `SearchScopeStatus` bound.
  - `packages/thallo-search/composer.json`: require `glueful/thallo-render: self.version` (the pack now uses render's contribution interfaces, as `thallo-account` does).
- Modify: `core/src/Content/Regions/RegionDefinitions.php` (`'search'` in `header`, after `'auth-state'`, with a comment line like the others)
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (constructor gains `?SearchScopeStatus $searchScopes = null`; a `TwigFunction('search_scope_state', fn (string $scope = ''): array => $this->searchScopes?->stateOf($scope) ?? ['available' => false, 'label' => null, 'reason' => 'Search is off'])`), `packages/thallo-render/src/RenderServiceProvider.php` (`makeRenderContextExtension` passes it soft), `packages/thallo-render/src/Templates/TemplatePolicy.php` (`FUNCTIONS` += `search_scope_state`; `CACHE_VERSION` 29 → 30 with a log line)
- Modify: `tests/Integration/Render/ShippedTemplatesLintGateTest.php` (`$roots` += `packages/thallo-search/templates`)
- Test: `tests/Integration/Search/SearchBlockTest.php`, `tests/Integration/Http/RegionAdminApiTest.php` (one new test)

**Interfaces:**
- **Consumes:** `KindAvailability` (Task 15).
- **Produces** the block type `search`:
  ```php
  new StarterBlockTypeDefinition(
      sourceId: 'thallo-search:search', slug: 'search', label: 'Search', icon: 'i-lucide-search',
      category: 'Site', description: 'A search field, or an icon that opens one.',
      schema: [
          ['name' => 'display', 'type' => 'enum', 'enum' => ['field', 'icon']],
          ['name' => 'placeholder', 'type' => 'string'],
          // Which kind of result; empty for all. The choices come from the available sources.
          ['name' => 'scope', 'type' => 'string', 'options_source' => 'thallo-search.scopes'],
          ['name' => 'live_results', 'type' => 'boolean'],
      ],
      requiresCapability: 'thallo.search',
      styleCapabilities: ['spacing', 'width', 'visibility', 'layout.item'],
      styleTargets: StyleTargets::root('box', ['spacing', 'width', 'visibility', 'layout.item']),
  )
  ```
- **Produces** `RegistrySearchScopeStatus::stateOf($scope)`:
  - `''` gives `{available: true, label: 'All results', reason: null}`;
  - an available kind gives `{true, label, null}`;
  - a registered unavailable kind gives `{false, label, "requires Commerce"}`;
  - otherwise `{false, null, 'no longer provided by any installed feature'}`.

- [ ] **Step 1: Write the failing tests.** `SearchBlockTest` (render through the page pipeline, as `ShopBlocksTest` renders `product-grid`):
  - `testTheSchema`: the definition's fields as above; `requiresCapability` is `thallo.search`.
  - `testFieldMode`: `display: field` renders `<form method="get" action="/search" role="search">` with:
    - a visually hidden `<label for="thallo-search-input-{dom_key}">Search</label>`;
    - `<input id="thallo-search-input-{dom_key}" name="q" type="search" placeholder="Find a fragrance" role="combobox" aria-expanded="false" aria-controls="thallo-search-list-{dom_key}" aria-autocomplete="list" autocomplete="off">`;
    - `<input type="hidden" name="scope" value="">` and `<input type="hidden" name="locale" value="en">`;
    - `<ul id="thallo-search-list-{dom_key}" role="listbox" hidden>`;
    - `data-live="1"`.
  - `testIconMode`: `display: icon` renders `<a class="thallo-block-search__trigger" href="/search?scope=products&amp;locale=en" aria-label="Search" aria-expanded="false" aria-controls="thallo-search-panel-{dom_key}">` with an inline SVG (`aria-hidden`), plus a `hidden` panel holding the same form partial.
  - `testTwoBlocksHaveUniqueIds`: two blocks on one page share no id.
  - `testAnUnavailableScopeRendersNothingInPublicAndAPlaceholderOnTheStage`: scope `products` with Commerce off gives no `thallo-block-search` markup in public; on the canvas (`is_canvas()`), `<div class="thallo-block thallo-block-search thallo-field-empty">Products search isn't available: Commerce is off.</div>`.
  - `testAnEmptyIndexHidesNothing`: no documents at all, and the block renders.
  - `testTurningSearchOffRemovesTheHeaderBlockFromCachedPages`: put a header region with a Search block; warm `/` (render cache) and `/shop` (shop catalog cache); boot with Search off; neither response contains `thallo-block-search`. Then boot with Search on: both contain it again.
  - `testTheScopeStateHelperIsAllowlisted`: the lint gate accepts `search_scope_state`.

  `RegionAdminApiTest::testSearchIsInTheHeaderPaletteAndSavesIntoTheHeader`:
  1. seed the `search` block type through `BlockTypeRepository::create`;
  2. `search` is in the header palette;
  3. PUT a header with `['id' => 'apihdrsrch01', 'type' => 'search', 'data' => ['display' => 'icon']]` gives 200;
  4. `RegionRepository->find('header')` contains it;
  5. rendering `/` contains `thallo-block-search`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Write the templates.**
  ```twig
  {# search — a field, or an icon that opens one (spec §3.1). The markup is the same for every
     visitor; whether its scope is available is part of the page's availability fingerprint. #}
  {% set scope = data.scope|default('') %}
  {% set state = search_scope_state(scope) %}
  {% if not state.available %}
    {% if is_canvas() %}
  <div class="thallo-block thallo-block-search thallo-field-empty">{{ state.label|default('This') }} search isn't available: {{ state.reason == 'requires Commerce' ? 'Commerce is off' : state.reason }}.</div>
    {% endif %}
  {% else %}
  {% set display = data.display|default('field') %}
  {% set live = data.live_results is same as(false) ? '0' : '1' %}
  <div class="thallo-block thallo-block-search thallo-block-search--{{ display == 'icon' ? 'icon' : 'field' }}{{ style_classes('root') }}"{{ style_attrs('root') }}
       data-search-block data-live="{{ live }}" data-scope="{{ scope }}" data-locale="{{ site.locale }}" data-key="{{ block.dom_key }}">
    {% if display == 'icon' %}
    <a class="thallo-block-search__trigger" href="/search?scope={{ scope|url_encode }}&locale={{ site.locale|url_encode }}"
       aria-label="Search" aria-expanded="false" aria-controls="thallo-search-panel-{{ block.dom_key }}" data-search-trigger>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </a>
    <div id="thallo-search-panel-{{ block.dom_key }}" class="thallo-block-search__panel" data-search-panel hidden>
      {% include 'search/_form.twig' with {key: block.dom_key, scope: scope, placeholder: data.placeholder|default('Search')} %}
    </div>
    {% else %}
      {% include 'search/_form.twig' with {key: block.dom_key, scope: scope, placeholder: data.placeholder|default('Search')} %}
    {% endif %}
  </div>
  <link rel="stylesheet" href="/_thallo/search/search.css">
  <script src="/_thallo/search/search.js" defer></script>
  {% endif %}
  ```
  `_form.twig` holds the form markup asserted in `testFieldMode`, plus `<p class="thallo-block-search__status" role="status" aria-live="polite" data-search-status></p>`. The `value` attribute of `q` is `{{ q|default('') }}`, used by the results page. If the template lint's style-target rule requires the `layout.item` target on the outermost element, put `style_classes('root')` on the root, as above.
- [ ] **Step 4: Implement** the contributors, the asset map and controller (copied from the shop's, retargeted at `packages/thallo-search/assets` and `/_thallo/search/`), the scope status, the Twig function, the palette entry, the policy entry, and the lint root.
- [ ] **Step 5: Run the tests plus `tests/Integration/Render/ShippedTemplatesLintGateTest.php tests/Integration/Http/RegionAdminApiTest.php tests/Integration/Render/TemplatePolicyTest.php`** (if the policy has a pinned-list test, add the function there). Expected: PASS.
- [ ] **Step 6: Prove header insertion in the editor.** In `admin/e2e/tests/regions-stage.spec.ts`, add "a Search block dragged from the Blocks tab into the header inserts and saves":
  1. `openRegionsStage`;
  2. `queueIds(['e2esrch00001'])`;
  3. click `regions-switch-header`, then `tab('Blocks')`;
  4. `dragTileTo(page, 'search', host(...))`;
  5. `acceptedIs(page, recorded, 'search-inserted')`.

  Add the `search` block type to `admin/e2e/fixtures/api/block-types.json` (the fixture builder emits it once the block is registered: rebuild with `CACHE_DRIVER=array php scripts/build-builder-proof-fixtures`) and the `search-inserted` scenario to `admin/e2e/regions-scenarios.json`. Run `pnpm --dir e2e test regions-stage`. Expected: PASS. The site half of "sees it on the site" is `testSearchIsInTheHeaderPaletteAndSavesIntoTheHeader`'s render assertion above.
- [ ] **Step 7: Commit** with a changelog `### Added` bullet: "**A Search block**: a search field, or an icon that opens one. It can sit in the header, and its scope chooses all results or one kind (pages, products). It hides itself when its scope isn't available." Message: `feat(search): a Search block for pages and the header`.

## Task 18: `search.js` — suggestions, the combobox and the icon panel, proven in a browser

**Files:**
- Modify: `packages/thallo-search/assets/search.js`, `packages/thallo-search/assets/search.css`
- Create: `tools/runtime-browser/fixtures/search-block.html` (two blocks, field and icon, the exact markup from Task 17's template with fixed `dom_key`s), `tools/runtime-browser/tests/search-block.spec.js`

**Interfaces:**
- **Consumes:** `GET /_search/suggest?q=&scope=&locale=`, which returns `{state, items[{kind, kind_label, title, href, image, price, snippet}], see_all}` (Task 15).
- **Produces:** `window.thalloSearch = { init }`. It self-guards and runs once (the shop pattern), registers with `window.ThalloRuntime` when present, and otherwise runs on `DOMContentLoaded`.

- [ ] **Step 1: Write the failing browser specs**, routing `**/_thallo/search/search.js` to the real file and `**/_search/suggest*` to a handler that records the query and answers per test:
  - `the list opens with no active option, and Enter submits the typed query`: type "ros"; the listbox is visible; `aria-activedescendant` is empty; Enter navigates to `/search?q=ros&scope=&locale=en` (assert through `page.waitForRequest`).
  - `arrows move the active option while focus stays in the input, and Enter opens it`: ArrowDown twice; `document.activeElement` is still the input; `aria-activedescendant` names the second option; Enter navigates to its `href`.
  - `See all is the last selectable action; No results and headings are not options`: with `state: no_matches`, the list shows a "No results" status (`role="status"`, not `role="option"`) and "See all results for “zz”" as `role="option"`.
  - `Escape closes the list, then the panel, and focus returns to the icon`: in the icon block, click the trigger; the panel is visible and focus is in its input; type, list open; Escape closes the list; Escape again hides the panel and focuses the trigger.
  - `a late response never replaces a newer query's results`: answer "r" after 300 ms and "ro" at once; after both, the list shows "ro"'s items.
  - `an old response arriving inside the debounce window is not shown`: type "r" and let its request start. Type "o", and answer "r" within 150 ms, before "ro" dispatches: the list does not show "r"'s items, and `aria-activedescendant` is empty.
  - `Escape before dispatch cancels the pending request`: type, then press Escape within 150 ms. No request is sent, and the list stays closed after 300 ms.
  - `a response arriving after Escape does not reopen the list`: delay the answer, press Escape, then release it; the list stays closed.
  - `live_results off sends no request`: the block with `data-live="0"`; typing sends zero `/_search/suggest` requests.
  - `a failed request shows "Suggestions are unavailable" and Enter still submits`.
  - `rebuilding shows its message`: `state: rebuilding` shows "Search is being rebuilt. Please try again later.".
  - `Enter during composition does nothing`: dispatch `compositionstart`, then a keydown Enter with `isComposing: true`; no navigation.
  - `Tab moves on`: Tab from the input leaves the list closed and focuses the next control.
  - `the active option clears when the query changes`: ArrowDown, type another letter; `aria-activedescendant` is empty.
  - `snippets render as given and cannot inject markup`: an item whose snippet is `&lt;img src=x onerror=alert(1)&gt; <mark>ok</mark>` produces no `img` element. The server's escaping is trusted; the script sets `innerHTML` only from the `snippet` field, and titles via `textContent`.
- [ ] **Step 2: Run them.** `cd tools/runtime-browser && npx playwright test tests/search-block.spec.js`. Expected: FAIL.
- [ ] **Step 3: Implement `search.js`** (an IIFE, no dependencies):
  - **Per block:** state `{seq, shownSeq, dismissed, active: -1, items: []}`.
  - **On `input`, immediately:** `seq++`, `active = -1` (clear `aria-activedescendant`), `dismissed = false`, `clearTimeout(timer)`. Any response already in flight is now stale.
    - If `data-live="0"`, stop there.
    - **Only the dispatch is debounced:** `timer = setTimeout(dispatch, 150)` captures `mySeq = seq`. `dispatch` fetches `/_search/suggest?q&scope&locale` with `credentials: 'same-origin'`.
    - **On response:** if `mySeq !== state.seq || state.dismissed`, drop it; else render.
  - **Invalidation:** Escape, Tab and closing the panel all `clearTimeout(timer)`, `seq++` and set `dismissed = true`. A pending timer or a response in flight can then neither render nor clear `dismissed`.
  - **Render:** an option per item (`role="option"`, `id = list.id + '-' + i`, `aria-selected`), headings as `role="presentation"`, the status text into `[data-search-status]`, and the see-all option last.
  - **Keys:**
    - ArrowDown/ArrowUp change `active` and `aria-activedescendant`;
    - Enter with `e.isComposing` returns;
    - Enter with `active >= 0` goes to `location.assign(option href)`;
    - otherwise the form submits;
    - Escape closes the list and sets `dismissed`; with the list closed in icon mode, it hides the panel, sets `aria-expanded="false"` and focuses the trigger;
    - Tab closes the list without `preventDefault`.
  - **Trigger click:** `preventDefault`, show the panel, `aria-expanded="true"`, focus the input.
  - **Fetch failure:** show "Suggestions are unavailable" and keep the form.
- [ ] **Step 4: Run the specs plus the whole `tools/runtime-browser` suite.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(search): suggestions and the icon panel, accessible and race-safe`.

## Task 19: the `/search` results page

**Files:**
- Create: `packages/thallo-search/src/Http/{SearchPageController,SearchPageThrottle}.php`, `packages/thallo-search/templates/search/results.twig`
- Modify: `packages/thallo-search/src/SearchServiceProvider.php` (route `GET /search` **unconditionally**, with `tenant_profile:public` and `tenant_bootstrap`; no `RenderPageCache` and no `rate_limit` middleware)
- Test: `tests/Integration/Search/SearchPageTest.php`

**Interfaces:**
- **Consumes:** `SearchQueryService`, `SearchInput` (`Surface::PUBLIC`), `CursorSigner`, `TwigFactory` and `RenderContextExtension` through the same reset sequence as `ShopPageRenderer::render` (copied into a private `render()`; packs can't share Commerce's class), `CapabilityRegistry`.
- **Produces:** the page.

- [ ] **Step 1: Write the failing tests**, one per row of spec §3.7's table, each asserting status, headers and copy:
  - `testNoQueryShowsTheForm`: 200; contains `name="q"`, `name="scope" value="products"`, `name="locale" value="en"`; no results region.
  - `testResults`: 200; "Results for “rose”"; "About 3 matches"; three `<article>`s, each with a heading link; image and price for a product; the kind label "Product" when the scope is all; a `<mark>` in a snippet.
  - `testEmptyBatchKeepsMoreResults`: the withdrawn run from Task 15; "No available results in this batch"; a "More results" link whose `cursor` is valid.
  - `testNoMatches`: "No results for “zzz”"; with `scope=products`, a "Search everything" link to `/search?q=zzz&scope=&locale=en`.
  - `testScopeUnavailable`: "Products search isn't available right now" and "Search everything instead".
  - `testRebuilding`: 503 with `Retry-After: 120` and "Search is being rebuilt. Please try again later.", with the form present.
  - `testUnavailable`: 503 with `Retry-After` and "Search is temporarily unavailable.".
  - `testRateLimited`: 61 requests in one minute from one IP gives 429 with `Retry-After` and "Too many searches", with the form present.
  - `testOffShowsTheThemed404`: boot with Search off; `/search` gives 404 with the theme's 404 markup (not JSON).
  - `testHeaders`: every variant has `Cache-Control: no-store` and `X-Robots-Tag: noindex`; `<title>` is "Search results for “rose” — {site}" or "Search — {site}".
  - `testScopeAndLocaleSurviveEveryLinkAndForm`: the "More results" and "Search everything" links, and the form, carry scope and locale.
  - `testATamperedCursorShowsPageOne`.
  - `testAHostileQueryIsEscapedEverywhere`: `q` = `"><script>x</script>&amp;` appears only escaped in the title, heading, input value and snippet; no `<script>x` appears in the body.
  - `testMalformedInputs`: array-valued `q`/`scope`/`locale`/`cursor`, and a 250-character multibyte `q`, never give a 5xx.
  - `testThePageCacheNeverStoresSearch`: after two requests, no `render:*search*` key exists.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the controller:**
  1. Capability off: render `404.twig` with 404 through the render sequence.
  2. Throttle: `SearchPageThrottle::hit($ip)` false gives a 429 render with `Retry-After` equal to the seconds left in the window.
  3. Build the `SearchInput`, then search (limit `search.page_size`, refill on, the cursor verified or else ignored).
  4. Status: 503 for `rebuilding`/`unavailable` with `Retry-After: 120`; else 200.
  5. Render `search/results.twig` with `{q, scope, locale, outcome, more_url, everything_url}`.
  6. Set `Cache-Control: no-store`.

  The template extends `layout.twig` and overrides `title` and `content`. It includes `search/_form.twig` and renders the states by `outcome.state`. Snippets render with `{{ item.snippetHtml|safe_html }}`: `raw` is not an allowed filter, and the sanitizer keeps the server's escaped text and its `<mark>` tags, which `testResults` pins. Everything else is auto-escaped. `layout.twig` has no head block, so `noindex` is sent as the `X-Robots-Tag: noindex` header on every variant; `testHeaders` asserts the header, not a meta tag.
  `SearchPageThrottle` keys `search:page:{sha1(ip)}:{floor(time/60)}` in `CacheStore` with a TTL of 60.
- [ ] **Step 4: Run the tests plus the lint gate.** Expected: PASS.
- [ ] **Step 5: Commit** with a changelog bullet: "**A `/search` results page** in the theme, with clear messages for no results, an unavailable scope, rebuilding and too many searches. It works without JavaScript." Message: `feat(search): the /search results page`.

## Task 20: `options_source` — the endpoint, the search scopes source, and the editor field

**Files:**
- Create: `core/src/Content/Fields/{DefaultFieldOptionSourceRegistry,FieldOptionsController}.php`
- Modify:
  - `core/src/Providers/CoreServiceProvider.php`: bind the registry (shared) and the controller.
  - `core/routes/admin.php`: `$router->get('/field-options/{source}', [FieldOptionsController::class, 'show'])->middleware('content_permission:content.edit');` with an `@description` naming the permission, as the file's other routes do.
- Create: `packages/thallo-search/src/Sources/SearchScopesOptionSource.php`, registered at boot whenever the registry exists. It is not gated: with Search off the field is never shown, but saved blocks still need their stored value explained.
- Admin:
  - Modify: `src/queries/contentTypes.ts` (`ContentTypeField.options_source?: string`), `src/fields/types.ts` (`FieldDef.optionsSource?: string`), `src/fields/normalize.ts` (`toFieldDef` maps `options_source` to `optionsSource`), `src/fields/components/blocks/BlockFields.vue` (a branch before the generic component: `v-else-if="f.type === 'string' && f.options_source"` renders `OptionsSourceField`).
  - Create: `src/queries/fieldOptions.ts`, `src/fields/components/OptionsSourceField.vue`, `src/fields/components/UnavailableChoice.vue`.
- Test: `tests/Integration/Http/FieldOptionsApiTest.php`; `admin/src/__tests__/optionsSourceField.spec.ts`; `admin/e2e/tests/inspector-options-source.spec.ts` with the fixture `admin/e2e/fixtures/api/field-options-thallo-search.scopes.json`

**Interfaces:**
- **Consumes:** `FieldOptionSource`, `FieldOptionSourceRegistry` (Task 1), `PermissionRequirementAuthority::allows(Request, list<string>)`, and `KindAvailability` (for the search source).
- **Produces:**
  - `GET /v1/admin/field-options/{source}` returns `{"data": {"options": [{value, label, available, reason}]}}`. An unknown source is 404. A caller without the source's `permission()` gets 403.
  - Admin: `useFieldOptions(source: Ref<string>)` returns `{data, status, error}`, with key `['field-options', source]`.

- [ ] **Step 1: Write the failing server tests.**
  - `testAnAuthorWithEditButNotManageLoadsTheScopes`: create a role with `content.edit` only, plus a user, and log in (the `RegionAdminApiTest` helpers). `GET /v1/admin/field-options/thallo-search.scopes` returns 200 with:
    - `{value: '', label: 'All results', available: true}`;
    - `entries` available;
    - `products` with `available` equal to the Commerce capability state and `reason: 'Requires Commerce'` when off.
  - `testAnUnknownSourceIs404AndNoSessionIs401Or403`.
  - `testTheSourceIsResolvedInTheServersWorkspace`: with enforcement on, the response reflects the request's workspace capability state (the same as the panel's).
- [ ] **Step 2: Write the failing admin unit test.** `optionsSourceField.spec.ts` mocks `@/queries/fieldOptions` with plain refs, in the `generalSettingsPage.spec.ts` style:
  - loaded options render a `USelect` with "All results", "Pages & posts" and "Products (requires Commerce)" (disabled);
  - a stored `products` while it is unavailable renders `UnavailableChoice` with "Products (requires Commerce)", the stored value unchanged, and **Save not blocked** (the component emits no change and sets no validation error);
  - a stored `reviews` absent from the options shows "'reviews' is no longer provided by any installed feature";
  - `status: 'pending'` and `status: 'error'` show "Couldn't load the choices" with the stored value displayed, and emit nothing (never `''`).
- [ ] **Step 3: Run them.** `vendor/bin/phpunit tests/Integration/Http/FieldOptionsApiTest.php` and `cd admin && pnpm test optionsSourceField`. Expected: FAIL.
- [ ] **Step 4: Implement the server side.**
  ```php
  final class FieldOptionsController
  {
      public function __construct(
          private readonly FieldOptionSourceRegistry $sources,
          private readonly ApplicationContext $context,
      ) {}
      public function show(Request $request, string $source): Response
      {
          $found = $this->sources->find($source);
          if ($found === null) {
              return Response::notFound('Unknown option source.');
          }
          if (!(new PermissionRequirementAuthority($this->context))->allows($request, [$found->permission()])) {
              return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
          }
          return Response::success(['options' => $found->options()]);
      }
  }
  ```
  `SearchScopesOptionSource`: `id()` is `'thallo-search.scopes'` and `permission()` is `'content.edit'`. `options()` is "All results" followed by each **registered** kind (`SearchSourceRegistry::all()`; contributors register regardless of capabilities), each with `KindAvailability`'s `available` and `reasonFor()`. Kinds whose contributor isn't registered at all are absent, which is how the admin knows "no longer provided".
- [ ] **Step 5: Implement the admin side.** `OptionsSourceField.vue`:
  ```vue
  <script setup lang="ts">
  import { computed, toRef } from 'vue'
  import type { FieldDef } from '@/fields/types'
  import { useFieldOptions } from '@/queries/fieldOptions'
  import UnavailableChoice from './UnavailableChoice.vue'
  const props = defineProps<{ field: FieldDef & { optionsSource: string }; modelValue?: string | null }>()
  const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
  const { data, status } = useFieldOptions(toRef(() => props.field.optionsSource))
  const stored = computed(() => props.modelValue ?? '')
  const match = computed(() => data.value?.find((o) => o.value === stored.value))
  const unavailable = computed(() => {
    if (status.value !== 'success') return { kind: 'loading' as const }
    if (!match.value) return stored.value === '' ? null : { kind: 'removed' as const }
    return match.value.available ? null : { kind: 'disabled' as const, label: match.value.label, reason: match.value.reason }
  })
  const items = computed(() => (data.value ?? []).map((o) => ({
    label: o.available ? o.label : `${o.label} (${(o.reason ?? '').toLowerCase()})`, value: o.value, disabled: !o.available,
  })))
  </script>
  <template>
    <UFormField :label="field.label" :name="field.name">
      <UnavailableChoice v-if="unavailable" :state="unavailable" :value="stored" :data-test="`options-source-${field.name}`" />
      <USelect v-if="status === 'success'" :model-value="stored" :items="items"
        @update:model-value="(v: string) => emit('update:modelValue', v)" />
    </UFormField>
  </template>
  ```
  `UnavailableChoice.vue` reuses `InvalidChoiceNotice`'s visual classes (`rounded border border-warning/40 bg-warning/5 px-2 py-1.5`). It shows three copies, keyed by `state.kind`:
  - "Products (requires Commerce): the block keeps this choice and shows nothing on the site until Commerce is on.";
  - "'{value}' is no longer provided by any installed feature. The block keeps this choice.";
  - "Couldn't load the choices. The stored choice is '{value || All results}'."

  It has no buttons and never blocks saving. `useFieldOptions` uses the typed `client.GET('/field-options/{source}')` after `pnpm gen:api`.
- [ ] **Step 6: Write the e2e proof.** In `inspector-options-source.spec.ts`:
  1. `routeWorld(page)`;
  2. add a `search` block type to the fixture block types with the `options_source` field;
  3. route `**/v1/admin/field-options/thallo-search.scopes` to the fixture (products unavailable);
  4. select a Search block whose `scope` is `products`;
  5. assert the unavailable notice, then that saving sends `scope: 'products'` unchanged (the recorded save body).

  Rebuild the fixtures first (`CACHE_DRIVER=array php scripts/build-builder-proof-fixtures`).
- [ ] **Step 7: Run** the server tests, `cd admin && pnpm test && pnpm type-check && pnpm lint && pnpm exec oxfmt <touched files> && pnpm fmt:check`, and `pnpm --dir e2e test inspector-options-source`. Expected: PASS.
- [ ] **Step 8: Commit** with a changelog bullet: "**Block fields can draw their choices from the server** (`options_source`), keeping a stored choice that is no longer available and saying why. Authors who can edit pages see them." Message: `feat(blocks): fields with server-provided choices, starting with the search scope`.

## Task 21: Settings › Search, the Rebuild action, and the Capabilities pill

**Files:**
- Create: `packages/thallo-search/src/Http/SearchAdminController.php`, `packages/thallo-search/routes/admin-routes.php`. Both routes are loaded inside the gate, prefix `/v1/admin/search`, with `content_permission:content.manage`:
  - `GET /status` returns `{engine: {name, ready, message, version}, kinds: [{kind, label, available, reason, status, documents, processed, last_success_at, last_error, demand_pending, stalled}], cutover: {format}}`;
  - `POST /rebuild` takes `{kind?: string}` and returns 202 `{recorded: true, queued: bool|null}`.
- Admin:
  - Create: `src/pages/settings/search/index.vue`, `src/queries/searchStatus.ts`.
  - Modify: `src/registry/coreModule.ts` (Settings children: `{ label: 'Search', icon: 'i-lucide-search', to: '/settings/search' }`, after General), `src/pages/settings/general/index.vue` (the search switch's description), `src/pages/extensions/components/CapabilityCard.vue` (a pill when `capability.id === 'thallo.search' && capability.effective`).
- Test: `tests/Integration/Search/SearchAdminApiTest.php`; `admin/src/__tests__/searchSettingsPage.spec.ts`, `admin/src/__tests__/capabilityCardSearchPill.spec.ts`

**Interfaces:**
- **Consumes:** `StateRepository`, `DemandResolver`, `KindAvailability`, `IndexStore::readiness()`, `SearchDemand::request()`.
- **Produces:** `stalled` = demand pending for more than 10 minutes (`search.stall_after`, default 600 s) with no claim taken since, or the last wake-up failed to queue. The latter is recorded as `last_error = 'queue: …'` by `SearchDemand`'s after-commit catch.

- [ ] **Step 1: Write the failing server tests:**
  - `testStatusListsEveryRegisteredKind`: unavailable kinds carry their reason and no Rebuild (`available: false`). `last_error` is sanitised: a stored error containing `http://user:pass@meili:7700` comes back with `[redacted]`.
  - `testRebuildCommitsDemandBeforeDispatch`: bind a `QueueManager` spy. The demand row exists when `push` is called (assert inside the spy). The response is 202 `recorded: true`.
  - `testRebuildWithAFailingQueueIsReportedTruthfully`: `push` throws. The response is still 202 `recorded: true`. The next status has `stalled: true` and the error text begins `queue:`.
  - `testARolledBackRebuildDispatchesNothing`: wrap the controller call in a transaction that throws after it. No demand row and no push.
  - `testAnAuthorWhoCanEditButNotManageCannotRebuild`: the `content.edit`-only author from Task 20's test (same role helper) gets 403 on `POST /v1/admin/search/rebuild`, while `GET /v1/admin/field-options/thallo-search.scopes` still gives that author 200.
  - `testAnUnavailableKindCannotBeRebuilt`: `{kind: 'products'}` with Commerce off gives 422.
- [ ] **Step 2: Write the failing admin tests.** `searchSettingsPage.spec.ts` mocks `@/queries/searchStatus` with refs, and `useIntervalFn` with `vi.useFakeTimers()`:
  - the table renders one row per kind with status badges;
  - "Rebuild" calls the mutation with `{kind}`;
  - "Rebuild all" calls it with `{}`;
  - a `stalled` kind shows the exact "Background processing hasn't picked this up…" copy;
  - the engine message (the ≥ 1.10 copy) appears verbatim;
  - it polls every 5 s while any kind is `pending` or `building`, and every 60 s otherwise (assert refresh counts after advancing timers);
  - `window.dispatchEvent(new Event('focus'))` refreshes;
  - a 403 shows "Ask an administrator for access to search settings.".

  `capabilityCardSearchPill.spec.ts`: the Search card shows "Ready", "Rebuilding" or "Needs attention" (`out_of_date`/`failed`/`stalled`) and links to `/settings/search`. Other cards show no pill.
- [ ] **Step 3: Run them.** Expected: FAIL.
- [ ] **Step 4: Implement** the controller, the routes, the queries (typed client after `pnpm gen:api`), the page (a `UDashboardPanel` with a `UTable` of kinds, an engine card, and the cutover notice), the poller, the pill and the General help text: "Indexing runs automatically; progress and problems appear in Settings › Search." The pill's data comes from `useSearchStatus()`, enabled only for the Search card.
- [ ] **Step 5: Regenerate OpenAPI** for the two admin operations (with `CACHE_DRIVER=array`, splicing by hand), then `pnpm gen:api`.
- [ ] **Step 6: Run** the server tests and the admin gates. Expected: PASS.
- [ ] **Step 7: Commit** with a changelog bullet: "**Settings › Search** shows each kind's index status, progress and last error, with Rebuild buttons, and says when background processing isn't running. Extensions › Capabilities shows Search's state." Message: `feat(search): Settings › Search with status and Rebuild`.

## Task 22: docs, upgrade notes, and the release gates

**Files:**
- Modify: `docs/reference/04-block-library.md` (a Search block row: `display`, `placeholder`, `scope`, `live_results`; header placement; the unavailable-scope behaviour; no-JS behaviour)
- Modify: `docs/guides/11-search.md`:
  - "Build the index" is replaced by "Indexing runs on its own", covering the panel, Rebuild and `search:reindex [--wait] [--kind]`;
  - new sections: "Products in search"; "Put a Search block in the header"; "How fresh results are" (the consistency statement: matching is eventually consistent, and display is always current); "Meilisearch 1.10 and one index per workspace and kind"; "Behind a CDN" (the deployment condition about edge-TTL overrides).
- Modify: `docs/reference/01-cli.md` (`search:reindex` with `--wait` and `--kind`; `--type`/`--locale` **removed**; `search:reconcile [--full] [--all]`; `search:status --all`; `thallo:availability:purge`), `docs/reference/02-configuration.md` (`search.page_size`, `search.page_rate_limit`, `search.meilisearch_task_timeout`, `search.retire_grace`, `search.query_timeout`, `search.stall_after`, `render.availability_edge_grace`, `SEARCH_FULL_RECONCILE`), `docs/reference/03-template-functions.md` (`search_scope_state`)
- Create: the pack-author page `docs/reference/09-search-sources.md` (the next free number; register it wherever `tests/Unit/Docs/DocsCorpusTest.php` requires): `SearchSourceContributor`, `present()`, identity rules, `SearchIndex`, `FieldOptionSource`, with a minimal contributor example
- Modify: `docs/guides/18-commerce.md` (one line: products appear in search when both features are on), `packages/thallo-search/README.md` (the endpoint section gains `kind`, `cursor`, `next`, `total_approximate`, 422/400; the lifecycle section is rewritten)
- Modify: `CHANGELOG.md`. Under `[Unreleased]`, an `### Upgrade notes` sub-list:
  - `search:reindex` now only records a rebuild request by default; add `--wait` to run it in the foreground;
  - `--type` and `--locale` are **removed** and exit non-zero; use `search:reindex --kind=entries`;
  - `/v1/search` keeps its entries-only default and gains `kind` and `cursor`;
  - Meilisearch sites need server 1.10 or newer;
  - after upgrading, the index rebuilds itself; sites with workspaces on Meilisearch show "rebuilding" until each workspace's index is ready.
- Modify: `docs/reference/08-changelog.md` if it mirrors `CHANGELOG.md` (follow the existing mirroring rule in the docs tests).
- Test: `tests/Unit/Docs` (`vendor/bin/phpunit tests/Unit/Docs`)

- [ ] **Step 1: Write the docs** above, matching the guides' voice: second person, short sections, commands in fenced blocks.
- [ ] **Step 2: Run the docs tests.** `vendor/bin/phpunit tests/Unit/Docs`. Expected: PASS.
- [ ] **Step 3: Check shard coverage.** Every new integration test lives under an existing top-level directory (`Search`, `Commerce`, `Capabilities`, `Render`, `Http`), so `ci.yml` needs no new list entry. Run the guard locally: `bash -c "$(sed -n '/Shard coverage guard/,/^      - name/p' .github/workflows/ci.yml | sed -n '/run: |/,$p' | sed '1d;$d' | sed 's/^          //')"`. Expected: no output, exit 0. Then time `tests/Integration/Search` (shard A) locally. If shard A exceeds about 5 minutes, move `tests/Integration/Search` to the lightest shard and record the move in the commit message.
- [ ] **Step 4: Run the full gates in order, never concurrently.**
  1. `vendor/bin/phpcs; echo "phpcs=$?"`, which must print `phpcs=0`.
  2. `composer boundaries`.
  3. The suite, in shards, with MAMP PHP first on PATH: `COMPOSER_PROCESS_TIMEOUT=0 composer test` unprefixed, then once with `API_USE_PREFIX=true`.
  4. `composer test:distribution` and `composer test:skeleton`.
  5. `cd admin && pnpm type-check && pnpm lint && pnpm fmt:check && pnpm test`.
  6. Rebuild the e2e fixtures with `CACHE_DRIVER=array`, then `pnpm --dir e2e test` on eight workers.
  7. `cd tools/runtime-browser && npm test`.

  Expected: all green.
- [ ] **Step 5: Commit** `docs(search): the Search block, products in search, the index lifecycle, and upgrade notes`.

---

## Self-review (run while writing; kept for the reviewer)

Spec coverage, section by section:

| Spec section | Task(s) |
|---|---|
| §3.1 block: settings, rendering, accessibility, async behaviour, availability, caching, assets, header palette | 17, 18, 3/4 (cache) |
| §3.2 contract and the two contributors | 1, 12, 16 |
| §3.3 identity, storage, workspace isolation, Meilisearch version | 1, 6, 8, 9, 10 |
| §3.4 query service, normalisation, pagination and totals, surfaces | 5, 15, 19 |
| §3.5.1–3.5.5 state, journal, acks, demand, live changes, rebuild, promotion, fencing | 7, 10, 11 |
| §3.5.6 cutover | 14 |
| §3.5.7 demand and recovery | 2, 13 |
| §3.5.8 retirement | 11 (`IndexRetirement`), 15 (retry) |
| §3.5.9 reads while building | 14 (`readMode`), 11 (Meilisearch active-only reads), 8 (Postgres in place) |
| §3.5.10 `search:reindex` | 13 |
| §3.6 core invalidation | 2, 3, 4 |
| §3.7 results page | 19 |
| §3.8 admin panel | 21 |
| §3.9 `options_source` | 20 |
| §4 testing | each task's tests; header insertion: 17 (API + render, and the regions e2e), inspector scope: 20 |
| §5 docs and changelog | 22, plus each task's changelog bullet |
