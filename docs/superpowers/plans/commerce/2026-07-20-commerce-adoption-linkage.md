# Commerce Adoption + Content Linkage Foundation — Implementation Plan (Slice 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install `glueful/commerce` into Thallo per-workspace, with the three-mode tenant-resolution seam, the canonical product→entry link table + service/API, lifecycle cleanup, adoption/purge integration, and the starter Product Page — per `docs/superpowers/specs/commerce/2026-07-20-commerce-adoption-linkage-design.md` (authoritative; re-read the section named in each task).

**Architecture:** Three codebases. `glueful/commerce` gains four additive seams (Commerce-local tenant resolution, `ProductDeleted` event, `CatalogReader`, `CommerceTenantPurge`) — commerce is **published**, so its changes are a real additive minor with no schema changes. Thallo gains two host seams (adoption contributors in `thallo-tenancy`, starter content-type contributors in `thallo-contracts`). The new `packages/thallo-commerce` pack composes everything: capability, link table, `ProductLinkService`, listeners, purge handler, adoption contributor, starter kind, admin API.

**Tech Stack:** PHP 8.3, Glueful framework 1.71.0, PHPUnit, PostgreSQL (Thallo is PG-only; commerce repo tests on SQLite + gated pgsql).

## Global Constraints (verbatim from spec)

- Commerce 1.2.x is **published**: additive minor only, no shipped-migration edits, immutable changelog sections; new CHANGELOG entries go under `[Unreleased]`. No version/pin bumps in commits (user's release step).
- Provider order: **tenancy enforcement → Commerce → thallo-commerce** in `config/extensions.php`.
- The pack gates user-facing routes + starter contribution on **`CapabilityRegistry::isEnabled('thallo.commerce')`** — no bespoke enable switch. Migrations, link-table registration, lifecycle-cleanup listeners, purge-handler registration, diagnostics all live **outside** the gate.
- `thallo-commerce` **requires** `glueful/commerce` (hard Composer dep). "Inactive Commerce" = package installed, provider inactive — pack user-facing surface inert + diagnostics, never a crash.
- **Never bind or replace the shared `CurrentTenantResolver`** contract id from the pack (reserved for the tenancy implementation). The three-mode resolver binds the **Commerce-local** `CommerceTenantResolution` only.
- Link rows are **active links only** — no status/retirement columns; history is append-only audit events, dispatched **after-commit only**.
- Purge **fails closed** when any Commerce schema marker (at minimum `commerce_products`) exists but `CommerceTenantPurge` is unavailable.
- HTTP semantics: unknown/cross-tenant/tombstoned → non-revealing **404**; malformed input → **422**; uniqueness/concurrency conflict (incl. expected-entry mismatch) → **409**.
- **No DB FKs** into Commerce tables from pack tables (and none into Thallo entries either).
- Packs must not reference `App\` classes (verified layering rule) — pack-consumable contracts live in `thallo-contracts`.
- `thallo-contracts` contains only the starter VO + contributor/registry interfaces; the mutable starter registry implementation remains app-owned.
- Link mutations use sorted, deduplicated 64-bit `hashtextextended(?, 0)` transaction locks over every affected product/entry identity; no late out-of-order lock acquisition.
- Provider `boot()` never performs tenant-data writes; starter sync for existing workspaces is the explicit `thallo:tenant:sync --all --kind=content_type` step.
- Tenant is resolved from the active context only — never accepted from request input.
- No AI/Anthropic attribution in commits. Work on `dev` in each repo; commits batched per the task groupings below; spec/plan docs stay uncommitted.

**Plan deviation note (reviewed and approved):** Spec §9 says "packs contribute StarterKinds". Verified: no pack references `App\` (strict layering), and `StarterKind` is `App\Content\Starter\StarterKind`. Literal compliance would move the whole orchestration interface into contracts. Instead, `thallo-contracts` exposes a data-level `StarterContentTypeContributor`, a readonly `StarterContentTypeDefinition` VO, and a `StarterContributorRegistry` interface. The mutable registry implementation stays in Thallo core. The app's existing `ContentTypeKind` (already one of the six fixed kinds, syncable) validates and appends contributed definitions in `definitions()`. All four flows (fresh install, future tenants, repair sync, explicit sync) still run through `ContentTypeKind`, preserving the spec outcome without leaking app orchestration into contracts.

---

## Repo A: glueful/commerce (BASE: dev tip; one commit after Task 3)

### Task 1: `CommerceTenantResolution` seam

**Files:**
- Create: `src/Tenancy/CommerceTenantResolution.php`
- Modify: `src/CommerceServiceProvider.php` (`makeTenantResolver()`, currently the binary sentinel-or-shared selection)
- Test: `tests/Unit/Tenancy/CommerceTenantResolutionTest.php`

**Interfaces (produces):**
```php
namespace Glueful\Extensions\Commerce\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

interface CommerceTenantResolution
{
    /** The tenant uuid Commerce operates under for the current call ('' = sentinel). */
    public function tenantUuid(ApplicationContext $context): string;
}
```

- [ ] **RED:** with a bound `CommerceTenantResolution` stub returning `'tenantX00001'`, the resolver Commerce services receive yields `'tenantX00001'` — and re-binding a stub that returns a different value changes subsequent calls (per-call evaluation, no latch). Byte-parity: with NOTHING bound, `commerce.tenancy.enabled=false` → `SentinelTenantResolver` (`''`) and `=true` without a shared resolver → the existing `RuntimeException`, exactly as 1.2.x.
- [ ] **Implement:** in `makeTenantResolver()`, FIRST check `$container->has(CommerceTenantResolution::class)`; when bound, return an anonymous `CurrentTenantResolver` adapter whose `tenantUuid($context)` delegates to the seam **per call** (no captured value). When not bound, the existing branches run byte-identically.
- [ ] **GREEN + gates:** targeted test file, then full `composer test`, `composer phpcs`, `composer analyze`. **No commit yet.**

### Task 2: `ProductDeleted` event + `CatalogReader`

**Files:**
- Create: `src/Events/ProductDeleted.php`, `src/Catalog/CatalogReader.php`, `src/Catalog/ProductRepositoryCatalogReader.php`
- Modify: `src/Catalog/CatalogService.php` (`deleteProduct()`, the verified event-less tombstone), `src/CommerceServiceProvider.php` (bind `CatalogReader`)
- Test: `tests/Integration/Catalog/ProductDeletedEventTest.php`, `tests/Integration/Catalog/CatalogReaderTest.php`

**Interfaces (produces):**
```php
namespace Glueful\Extensions\Commerce\Events;

final class ProductDeleted extends \Glueful\Events\Contracts\BaseEvent
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $productUuid,
    ) {
        parent::__construct();
    }
}
```
```php
namespace Glueful\Extensions\Commerce\Catalog;

interface CatalogReader
{
    /** Live product row for this tenant, or null (never a tombstone). */
    public function findLiveProduct(ApplicationContext $context, string $tenant, string $productUuid): ?array;

    /** True when the uuid exists for this tenant only as a tombstone. */
    public function isTombstoned(ApplicationContext $context, string $tenant, string $productUuid): bool;
}
```

- [ ] **RED:** `deleteProduct()` on a live product dispatches exactly one `ProductDeleted` (correct tenant + uuid) **after commit** (register a listener; assert it fires post-txn — force a rollback via a colliding claim and assert NO event). Repeat-delete (404 path) dispatches nothing. `CatalogReader`: live product → row; tombstoned → `findLiveProduct` null + `isTombstoned` true; unknown/cross-tenant → null + false.
- [ ] **Implement:** in `deleteProduct()`, after the existing transaction closure body succeeds, dispatch via the house idiom (`container($context)->has(EventService::class)` → `dispatch`) registered through `db($context)->afterCommit(...)` INSIDE the transaction (fault-isolated `dispatch()`, NOT `dispatchOrFail` — reconciliation is the backstop). `ProductRepositoryCatalogReader` delegates to `ProductRepository::findLiveByUuid()` / `findIncludingDeletedByUuid()` (use the existing repo methods; do not add SQL). Bind `CatalogReader::class => ProductRepositoryCatalogReader` in the provider.
- [ ] **GREEN + gates.** **No commit yet.**

### Task 3: `CommerceTenantPurge` + CHANGELOG → **Commerce commit**

**Files:**
- Create: `src/Tenancy/CommerceTenantPurge.php`
- Modify: `src/CommerceServiceProvider.php` (register), `CHANGELOG.md` (`[Unreleased]`)
- Test: `tests/Integration/Tenancy/CommerceTenantPurgeTest.php`

**Interfaces (produces):**
```php
namespace Glueful\Extensions\Commerce\Tenancy;

final class CommerceTenantPurge
{
    /** Delete every commerce row for this tenant. Returns rows-deleted per table. */
    public function purgeTenant(ApplicationContext $context, string $tenantUuid): array;

    /** Remaining commerce rows for this tenant, per table (verify step). */
    public function countTenantRows(ApplicationContext $context, string $tenantUuid): array;
}
```

- [ ] **RED:** seed rows across several commerce tenant tables for tenants A and B → `purgeTenant(A)` removes ONLY A's rows across **every** table in `DiagnosticsReport::tenantTables()`; `countTenantRows(A)` all-zero, B untouched; sentinel `''` purge refuses (guard: purging the sentinel tenant is a programming error — throw `\InvalidArgumentException`).
- [ ] **Implement:** iterate `DiagnosticsReport::tenantTables()` (commerce keeps table-list ownership), `DELETE ... WHERE tenant_uuid = ?` per table inside one transaction; counts via the same list. Register as a shared service.
- [ ] **GREEN + gates**, CHANGELOG `[Unreleased]` entry (host tenant-resolution seam, ProductDeleted event, CatalogReader, tenant purge service — additive, no schema changes, no env vars). **COMMIT (commerce, all of T1–T3):** explicit `git add` of the new/modified src+tests+CHANGELOG → `feat(tenancy): host tenant-resolution seam, product lifecycle event, catalog reader, and tenant purge service`.

---

## Repo B: thallo (seams; one commit after Task 5)

### Task 4: Adoption-contributor seam (`thallo-tenancy`)

**Files:**
- Create: `packages/thallo-tenancy/src/Adoption/AdoptionContributor.php`, `packages/thallo-tenancy/src/Adoption/AdoptionContributorRegistry.php`
- Modify: `packages/thallo-tenancy/src/Enablement/TenancyEnablement.php` (invoke after `$retrofit->run(...)` inside the RETROFITTING try, ~line 153), `packages/thallo-tenancy/src/Enablement/FinalizationProbe.php` (`allOwnedTablesAreRegistered()` also iterates contributor tables), `packages/thallo-tenancy/src/TenancyServiceProvider.php` (register the registry)
- Test: `tests/Integration/Tenancy/AdoptionContributorTest.php`

**Interfaces (produces):**
```php
namespace Thallo\Tenancy\Adoption;

use Glueful\Bootstrap\ApplicationContext;

interface AdoptionContributor
{
    public function id(): string;

    /** Tenant tables this contributor owns — finalization verifies each is registry-known. */
    public function tables(): array;

    /** Adopt sentinel rows into $tenantUuid. Runs as system work during RETROFITTING. */
    public function adopt(ApplicationContext $context, string $tenantUuid): void;
}

final class AdoptionContributorRegistry   // mirrors PurgeResourceRegistry
{
    public function register(AdoptionContributor $contributor): void;
    /** @return list<AdoptionContributor> */
    public function all(): array;
}
```

- [ ] **RED:** zero contributors → `confirm()` behaves byte-identically to today (existing enablement tests still green). A registered stub contributor: (a) `adopt()` invoked exactly once with the retrofit's default-tenant uuid, AFTER `SchemaRetrofit::run` completed (schema widened, default tenant exists), BEFORE the CAS to `ENABLING_ENFORCEMENT`; (b) executed via `TenantContextRunner::runAsSystem()`; (c) a throwing contributor → `recordFailure(RETROFITTING)`, step stays retryable, retry re-runs it. FinalizationProbe: a contributor table missing from `TenantEnforcementProbe` → `passes()` false; present → true.
- [ ] **Implement:** in `TenancyEnablement::confirm()` extend the existing try around `$retrofit->run(...)`: after it returns, resolve the tenant uuid (`SystemFlags` default) and loop `registry->all()`, each inside `runAsSystem`. In `FinalizationProbe::allOwnedTablesAreRegistered()`, after the `ThalloTenantTables::tableNames()` loop, iterate `registry->all()` → `tables()` with the same `isRegistered` check. Registry bound shared in `TenancyServiceProvider` (always, not capability-gated).
- [ ] **GREEN + gates** (thallo suite + phpcs). **No commit yet.**

### Task 5: Starter content-type contributor seam (`thallo-contracts` + app) → **Thallo seams commit**

**Files:**
- Create: `packages/thallo-contracts/src/Starter/StarterContentTypeDefinition.php`, `packages/thallo-contracts/src/Starter/StarterContentTypeContributor.php`, `packages/thallo-contracts/src/Starter/StarterContributorRegistry.php` (**interface only**), `app/Content/Starter/DefaultStarterContributorRegistry.php` (mutable implementation)
- Modify: `app/Content/Starter/Kinds/ContentTypeKind.php` (`definitions()` validates + appends registry contributions), `app/Providers/ThalloServiceProvider.php` (bind registry interface to the shared app implementation; inject the interface into `ContentTypeKind`)
- Test: `tests/Integration/Content/Starter/StarterContributorTest.php`

**Interfaces (produces):**
```php
namespace Thallo\Contracts\Starter;

final readonly class StarterContentTypeDefinition
{
    /** @param list<array<string,mixed>> $schema */
    public function __construct(
        public string $sourceId,
        public string $slug,
        public string $name,
        public ?string $description,
        public ?int $cacheTtl,
        public bool $publicDelivery,
        public bool $mountAtRoot,
        public array $schema,
    ) {}
}

interface StarterContentTypeContributor
{
    /** @return list<StarterContentTypeDefinition> */
    public function contentTypeDefinitions(): array;
}

interface StarterContributorRegistry
{
    public function register(StarterContentTypeContributor $contributor): void;
    /** @return list<StarterContentTypeContributor> */
    public function all(): array;
}
```

- [ ] **RED:** zero contributors → `StarterDefinitions`/`ContentTypeKind` output byte-identical to today (existing starter/seed tests green). A stub contributor's typed definition appears in `ContentTypeKind::definitions()`; `TenantSeeder` provisioning a fresh tenant creates it; `thallo:tenant:sync --kind=content_type` adopts it into an existing tenant idempotently (second run: no-op). Duplicate `sourceId` or `slug` across fixed/contributed definitions throws before any write; malformed/invalid contributed schema is rejected before any write. The registry resolved by packs is the contracts interface while the concrete mutable object is app-owned.
- [ ] **Implement:** keep only the VO + contributor/registry interfaces in `thallo-contracts`; implement `DefaultStarterContributorRegistry` under `App\Content\Starter`. `ContentTypeKind::definitions()` converts each VO to the existing `StarterDefinition` shape, validating scalar fields and `ContentTypeSchema::fromArray($definition->schema)` first; detect duplicate `sourceId` and `slug` across the complete fixed+contributed set before returning. Wire the interface to the shared app implementation through the existing `makeStarterDefinitions`/autowire path.
- [ ] **GREEN + gates.** **COMMIT (thallo, T4+T5):** explicit add → `feat(tenancy): adoption and starter content-type contributor seams`.

---

## Repo B: thallo (the pack; commits per group)

### Task 6: Pack skeleton — capability, config, migration, registration

**Files:**
- Create: `packages/thallo-commerce/composer.json` (name `glueful/thallo-commerce`; requires `glueful/thallo-contracts`, `glueful/thallo-tenancy`, **`glueful/commerce`**), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php`, `packages/thallo-commerce/migrations/001_CreateProductLinkTable.php`, `packages/thallo-commerce/config/thallo-commerce.php`
- Modify: root `composer.json` (path-repo + require `glueful/thallo-commerce`), `config/extensions.php` (provider AFTER `TenancyServiceProvider` and after `CommerceServiceProvider`; add `CommerceServiceProvider` itself), root `config/commerce.php` shadow if the skeleton-parity convention requires one
- Test: `tests/Integration/Commerce/PackSkeletonTest.php`

**Interfaces (produces):** provider registers capability `thallo.commerce` (`CapabilityRegistry::register(new Capability('thallo.commerce', label: 'Commerce', description: ...))`); migration creates `thallo_commerce_product_links` exactly per spec §5.1 (`id` PK autoincrement, `uuid` 12, `tenant_uuid` 12 default `''`, `product_uuid` 12, `entry_uuid` 12, timestamps; unique `(tenant_uuid, product_uuid)`, unique `(tenant_uuid, entry_uuid)`, index `(tenant_uuid, product_uuid)`; **no FKs**).

- [ ] **RED (shape):** migration creates the table with both uniques + index (probe via information_schema); `MigrationPriority::DEPENDENT` + migrations load with the capability DISABLED; the capability registers; with `TenantTableRegistry` bound the provider registers `thallo_commerce_product_links` exactly once (boot twice → still once); `commerce.marketplace.enabled` default false in the Thallo install; Thallo boots with the pack present and Commerce provider inactive (no crash).
- [ ] **Implement:** mirror `CollectionsServiceProvider::boot()` verbatim structure: capability registration + `loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEPENDENT, 'thallo-commerce')` + table registration OUTSIDE the gate; routes (Task 8) inside `isEnabled('thallo.commerce')`.
- [ ] **GREEN + gates.** **No commit yet** (commit with Task 7).

### Task 7: Three-mode `CommerceTenantResolution` binding → **pack foundation commit**

**Files:**
- Create: `packages/thallo-commerce/src/Tenancy/ThalloCommerceTenantResolution.php`
- Modify: `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (bind `CommerceTenantResolution::class`, outside the gate — resolution is infrastructure)
- Test: `tests/Integration/Commerce/TenantResolutionModesTest.php`

**Interfaces (consumes):** commerce Task 1's `CommerceTenantResolution`; `Thallo\Tenancy\System\SystemFlags` (`schemaState(): 'none'|'widened'`, `enforcementActive()`, `defaultTenantUuid()`); shared `CurrentTenantResolver` (delegated to, never rebound).

```php
public function tenantUuid(ApplicationContext $context): string
{
    if ($this->flags->enforcementActive()) {
        return $this->sharedResolver()->tenantUuid($context);   // mode (c)
    }
    if ($this->flags->schemaState() === 'widened') {
        $default = $this->flags->defaultTenantUuid();           // mode (b)
        if ($default === null || $default === '') {
            throw new \RuntimeException('Widened tenancy schema without a persisted default tenant.');
        }
        return $default;
    }
    return '';                                                   // mode (a)
}
```

- [ ] **RED:** all three modes return per the table in spec §4.1; widened-without-default throws (fail-closed); flipping flags mid-process changes the NEXT call's result (no latch — per-call evaluation proven); commerce services resolve through it (e.g. a `CatalogReader` read lands in the mode's tenant).
- [ ] **GREEN + gates.** **COMMIT (thallo, T6+T7):** `feat(commerce): thallo-commerce pack skeleton with three-mode tenant resolution`.

### Task 8: `ProductLinkService` + admin API → **link-service commit**

**Files:**
- Create: `packages/thallo-commerce/src/Links/ProductLinkRepository.php`, `src/Links/ProductLinkService.php`, `src/Links/LinkConflictException.php`, `src/Events/ProductLinkChanged.php` (one audit event class, `action: link|relink|unlink`, old/new entry uuids), `src/Http/ProductLinkController.php`, `routes/admin-routes.php`
- Modify: provider (services; routes INSIDE the capability gate)
- Test: `tests/Integration/Commerce/ProductLinkServiceTest.php`, `tests/Integration/Commerce/ProductLinkApiTest.php`, `tests/Integration/Commerce/ProductLinkRaceTest.php` (+ `tests/fixtures/product_link_race_child.php`)

**Interfaces (produces):**
```php
final class ProductLinkService
{
    /** @throws LinkConflictException (409) on existing link without matching expectation */
    public function link(ApplicationContext $c, string $productUuid, string $entryUuid, ?string $expectedEntryUuid = null): array;
    public function unlink(ApplicationContext $c, string $productUuid): void;
    public function resolveByProduct(ApplicationContext $c, string $productUuid): ?array;
    public function resolveByEntry(ApplicationContext $c, string $entryUuid): ?array;
}
```
Semantics (spec §5.2, verbatim requirements): tenant from `CommerceTenantResolution` only; product validated live via commerce `CatalogReader::findLiveProduct` (tombstoned/unknown → non-revealing `NotFoundException`); entry validated via `EntryRepository::findEntry` under the active tenant. **Serialization:** build the complete affected identity set — product, requested/new entry, and `expectedEntryUuid` when present — as `thallo_commerce_link:{tenant}:product:{productUuid}` / `...:entry:{entryUuid}` keys; deduplicate, sort lexicographically, then acquire each with `SELECT pg_advisory_xact_lock(hashtextextended(?, 0))` inside the transaction. Re-read only after every lock is held. Existing link + no/mismatched `expectedEntryUuid` → `LinkConflictException` (409); unique-violation racers → 409; audit `ProductLinkChanged` dispatched via `db($c)->afterCommit()` only (rolled-back mutation emits nothing). For `unlink`, take an unlocked snapshot to discover the current entry, open the transaction, lock product + snapshot entry in sorted order, and re-read; if the entry changed, roll back and retry the entire snapshot/lock/re-read sequence with a bounded retry budget rather than acquiring a newly-discovered out-of-order lock. Routes per spec §5.3 (`PUT/DELETE/GET /commerce/products/{productUuid}/link`, `GET /commerce/entries/{entryUuid}/link`) under the Thallo admin group's auth+tenant middleware; error mapping 404/422/409 per the Global Constraints table.

- [ ] **RED (service):** link happy-path row + audit event after commit; cross-tenant/unknown/tombstoned product → 404-shaped exception, no row; unknown/cross-tenant entry → same; second link same product no-expectation → 409; relink with correct `expectedEntryUuid` → replaces + audit carries old→new; wrong expectation → 409; entry already linked to another product → 409 (entry unique); relink's advisory-lock capture includes product + old entry + new entry in sorted, deduplicated order; unlink snapshot drift retries from scratch and never acquires a late out-of-order lock; unlink removes + audits; resolves return row/null; rolled-back txn → zero audit events.
- [ ] **RED (API):** each route's 200/201 shape; 404/422/409 matrix incl. malformed body; routes ABSENT (404) with the capability disabled.
- [ ] **RED (race):** two real PG connections (proc_open fixture-child, mirroring the commerce `SellerWebhookPgsqlTest` harness): concurrent `link` on one product → exactly one winner, loser 409, one row; concurrent relink with the same stale expectation → one winner; relink-away from entry A racing another product's claim of A serializes without deadlock or corruption; unlink racing relink exercises snapshot retry; BOTH orderings for every pair.
- [ ] **GREEN + gates.** **COMMIT (thallo, T8):** `feat(commerce): canonical product-entry linkage service with audited relink and admin API`.

### Task 9: Lifecycle listeners + reconcile + diagnostics

**Files:**
- Create: `src/Listeners/EntryDeletedListener.php`, `src/Listeners/ProductDeletedListener.php`, `src/Console/ReconcileLinksCommand.php` (`thallo:commerce:links:reconcile`), `src/Diagnostics/CommerceIntegrationDiagnostics.php`
- Modify: provider — listeners wired via `EventService::addListener` (analytics-pack precedent) **outside** the capability gate, but only when the source provider's event class exists (`class_exists` guard for `ProductDeleted`)
- Test: `tests/Integration/Commerce/LinkLifecycleTest.php`

**Interfaces (consumes):** `App\Content\Events\EntryDeleted` (`entry`, `type` — **no tenant**: listener resolves tenant from the active `CommerceTenantResolution` context, correct because entry deletion executes inside the tenant-scoped request); commerce `ProductDeleted` (`tenantUuid`, `productUuid` — explicit tenant from the event).

- [ ] **RED:** deleting a linked entry (real Thallo delete path) removes the link + after-commit audit; a real commerce tombstone (`CatalogService::deleteProduct`) removes the link, entry PRESERVED; both listeners still fire with the capability DISABLED; reconcile command converges seeded drift (link→tombstoned product gone; link→vanished entry gone; healthy links untouched), batch-limited, tenant-safe, prints counts; diagnostics report stale-link count, marketplace-enabled flag, inactive-Commerce state.
- [ ] **GREEN + gates.** **No commit yet.**

### Task 10: Purge handler + adoption contributor

**Files:**
- Create: `src/Purge/CommercePurgeHandler.php` (implements `Thallo\Tenancy\Purge\PurgeHandler`), `src/Adoption/CommerceAdoptionContributor.php` (implements `Thallo\Tenancy\Adoption\AdoptionContributor`)
- Modify: provider — both registered **outside** the capability gate (`PurgeResourceRegistry`, `AdoptionContributorRegistry`)
- Test: `tests/Integration/Commerce/PurgeAdoptionTest.php`

**Interfaces (consumes):** commerce `CommerceTenantPurge` + `TenantAdopter::adopt($context, $tenantUuid)`; Task 4's contracts.

Purge handler semantics (spec §8.2, verbatim): `purge()` deletes the tenant's link rows, then delegates to `CommerceTenantPurge->purgeTenant()`; `verify()` asserts zero link rows AND `countTenantRows()` all-zero. **Fail-closed rule:** factory soft-resolves `CommerceTenantPurge`; if unavailable → probe for a Commerce schema marker (`commerce_products` table exists): absent → link-only cleanup may complete; present → `prepare()`/`purge()`/`verify()` all throw (the run must never report success leaving Commerce tenant data behind). Adoption contributor: `tables()` = `['thallo_commerce_product_links', ...Commerce's DiagnosticsReport::tenantTables()]`; `adopt()` = one system-context operation adopting sentinel link rows (`UPDATE ... SET tenant_uuid = ? WHERE tenant_uuid = ''`) then `TenantAdopter::adopt()` (each package owns its own adoption semantics).

- [ ] **RED:** full purge run (real `PurgeCoordinator`) removes link + commerce rows for the tenant only, `verify()` green; Commerce schema present + `CommerceTenantPurge` unbound → prepare/purge/verify throw (fail-closed proven); no Commerce schema + unbound → link-only cleanup completes; adoption walk: seed sentinel link + commerce rows → `confirm()` adopts BOTH into the default tenant before enforcement; contributor failure → retryable RETROFITTING failure; finalization probe fails if the link table is unregistered.
- [ ] **GREEN + gates.** **No commit yet.**

### Task 11: Starter Product Page + explicit sync → **lifecycle commit**

**Files:**
- Create: `src/Starter/ProductPageContributor.php` (implements `Thallo\Contracts\Starter\StarterContentTypeContributor`)
- Modify: provider — contribution registered INSIDE the capability gate (spec: starter contribution is gated); `docs/` pack README noting the install step `php glueful thallo:tenant:sync --all --kind=content_type`
- Test: `tests/Integration/Commerce/ProductPageStarterTest.php`

Definition: return one typed `StarterContentTypeDefinition` for `product_page`, with a stable source id independent of future slug renames, localized editorial fields (`headline` text, `summary` rich/long text), a blocks region, and NO SEO storage fields (thallo-seo owns SEO). The app-owned `ContentTypeKind` performs schema validation and conversion to its internal `StarterDefinition`; the pack never constructs or references an `App\` type.

- [ ] **RED:** fresh tenant provisioning creates `product_page`; `thallo:tenant:sync --all --kind=content_type` adopts it into a pre-existing tenant, idempotently (second run no-op); provider boot performs zero tenant-data writes (query-log assertion around boot); capability disabled → not contributed; a `product_page` entry links to a product via Task 8's service end-to-end.
- [ ] **GREEN + gates.** **COMMIT (thallo, T9+T10+T11):** `feat(commerce): link lifecycle, workspace adoption and purge, and the starter product page`.

### Task 12: Gates — the full walk + inertness matrix → **gates commit**

**Files:**
- Create: `tests/Integration/Commerce/AdoptionWalkTest.php`, `tests/Integration/Commerce/InertnessTest.php`
- Modify: whatever the tests reveal (fix-forward); `CHANGELOG.md` (thallo, if the repo keeps one — check; otherwise skip)
- Test: as above + full-suite runs

- [ ] **Sentinel→widened→enforced walk:** single-store install with commerce products + links on `''` → enablement `confirm()` (retrofit + contributors) → `finalize()` (probe passes incl. link + commerce tables) → enforcement-active reads resolve the default tenant's data; `commerce:tenancy:adopt`'s mixed-data refusal preserved.
- [ ] **Inertness matrix:** capability disabled → no routes/starter, but migrations + registration + listeners + purge handler active; Commerce provider inactive → user-facing inert + diagnostics + purge fail-closed path; pack absent → Thallo boots clean (no core references); Commerce with no `CommerceTenantResolution` bound (commerce repo suite) behaves as 1.2.x — already proven in Task 1, re-assert here from Thallo's side with the binding removed.
- [ ] **Marketplace:** `commerce.marketplace.enabled=true` → diagnostics flag, no behavioral fork.
- [ ] Full thallo suite green + phpcs; full commerce suite green (both repos). **COMMIT (thallo, T12):** `test(commerce): adoption walk, inertness matrix, and marketplace diagnostics gates`.

---

## Self-Review

- **Spec coverage:** §1 decisions → T6 (capability/pins) + T7 (modes); §2 release surface → T1–T3 / T4–T5 / T6–T12; §3 → T6; §4 → T1+T7; §5 → T6 (schema) + T8 (service/API); §6 → T2 (event) + T9 (listeners/reconcile); §7 → T2; §8 → T4 (adoption seam) + T10 (handler/contributor) + T6 (table registration); §9 → T5 (seam) + T11 (kind + sync); §10 → distributed per task + T12; §11 honored (nothing out-of-scope planned).
- **Type consistency:** `CommerceTenantResolution::tenantUuid(ApplicationContext): string` (T1=T7); `AdoptionContributor{id,tables,adopt}` (T4=T10); `StarterContentTypeContributor::contentTypeDefinitions(): list<StarterContentTypeDefinition>` + registry interface→app implementation (T5=T11); `CatalogReader` (T2=T8/T9); `CommerceTenantPurge{purgeTenant,countTenantRows}` (T3=T10); `ProductLinkService.link(..., ?string $expectedEntryUuid)` (T8=T9/T11 consumers).
- **Placeholders:** none; the one intentionally-deferred literal (Product Page field array) is pinned to "mirror an existing ContentTypeKind definition" with the exact source named.
