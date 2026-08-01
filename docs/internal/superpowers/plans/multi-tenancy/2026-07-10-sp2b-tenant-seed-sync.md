# SP2b — Tenant Seed/Sync + starter_provenance — Implementation Plan (rev 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A created tenant becomes a usable site in one action (seed under `runAsTenant` + `markActive` in one PG transaction), and starter upgrades propagate to every tenant without clobbering divergence, tracked in `starter_provenance`.

**Architecture:** App-owned engine in `app/Content/Starter/` — a `StarterKind` registry (fingerprinted, adoption-key-aware definitions extracted from `SetupService` + `StarterBlockTypes` + new homepage/menu-aggregate), a `TenantSeeder` and three-way `StarterSync` running through `StarterTransaction` (Throwable-safe level-restoring wrapper over the framework's Exception-only TransactionManager). The `thallo-tenancy` pack consumes the seeder only through its own `TenantSeedActivator` interface, bound by the app provider. Execution added the neutral `TenantProvisioningRunner` contract + tenancy bridge because the existing `TenantContextRunner` correctly rejects provisioning tenants. Spec: `docs/superpowers/specs/multi-tenancy/2026-07-10-sp2b-tenant-seed-sync-design.md`; invariants: `SP2-README.md` §3 (esp. §3.11, §3.15).

**Tech Stack:** PHP 8.3, Glueful framework (as held), PostgreSQL app suite (SQLite never runs these — Thallo-only), PHPUnit.

## Global Constraints

- **Repo:** `/Users/michaeltawiahsowah/Sites/glueful/thallo` only. **HOLD ALL COMMITS**; work on `dev`; NO AI/Anthropic attribution.
- `declare(strict_types=1)`, `final class`, constructor DI, `use`-imports; `composer phpcs` clean (120-char, warnings fail).
- **Boundary:** the pack (`packages/thallo-tenancy/`) never imports `App\…`; it declares `Thallo\Tenancy\Contracts\TenantSeedActivator` and the app binds it. App code never imports `Glueful\Extensions\Tenancy\*` (contract-only rule, SP2 index §3.2).
- **Transaction pins (spec §5):** seed = ONE PG transaction on ONE connection, nested INSIDE `runAsTenant` so tenant context remains active through commit callbacks; tenant row (committed at create) never rolls back; `markActive()` is the final in-transaction statement (verified: `transition()` is a bare CAS UPDATE — joins plainly); cache purges/events/external effects register through `Connection::afterCommit()` only. Sync = one atomic transaction per `(tenant, kind)`.
- **Verified framework facts:** `Connection::transactionLevel(): int` (`Connection.php:858`), `getTransactionManager()` (`:781`), `TransactionManager::rollback()` rolls back ONE level (savepoint-per-level, NO `rollbackTo`) (`TransactionManager.php:176,196`), catches `Exception` not `Throwable` — hence `StarterTransaction`.
- **Verified as-built hook slots:** controller create at `TenantManagementController.php:52-54`; CLI create at `TenantManageCommand.php:57-64`; `listMembers` ordering `created_at ASC, uuid ASC` (`ContractTenantAdministration.php:105-123`); `MenuRepository::replaceTree` owns a raw-PDO transaction (`MenuRepository.php:183,203`) — the menu applier MUST NOT call it.
- Repair (`thallo:tenant:seed`) accepts `provisioning` (recovery path) and re-runs the **complete idempotent seed**; sync/`--all` default to active tenants; legacy `thallo:blocks:*` under tenancy-ON require `--tenant=`/`--all`, tenancy-OFF byte-identical.
- Test locations: `tests/Unit/Content/Starter/`, `tests/Integration/Tenancy/` (harness: `RetrofittedTenantTestCase` — boots tenancy ON, exposes `$defaultTenantUuid`, `seedAdditionalTenants()`).
- Sequencing: T1→T2→T3→(T4,T5,T6)→T7→T8→T9→T10→T11. Definition kinds (T4–T6) depend on T3's interface; nothing depends on kind internals.

## File Structure

**Create (app engine):** `app/Content/Starter/{SeedContext,StarterApplyResult,StarterKind,StarterDefinition,StarterDefinitions,Fingerprint,StarterProvenanceRepository,StarterTransaction,StarterSeedFailpoint,TenantSeeder,StarterSync,SyncReport}.php`, `app/Content/Starter/Kinds/{ContentTypeKind,SettingKind,RegionKind,BlockTypeKind,NavigationMenuKind,HomepageEntryKind}.php`, `database/dependent-migrations/012_CreateStarterProvenanceTable.php`.
**Create (pack seam):** `packages/thallo-tenancy/src/Contracts/{TenantSeedActivator,TenantSeedRepair,TenantStarterSync}.php` + `StarterSeedException.php`.
**Create (CLI):** `packages/thallo-tenancy/src/Console/{TenantSeedCommand,TenantSyncCommand,TenantBlockSyncCommand}.php` (pack-owned, contract-only).
**Modify:** `app/Setup/SetupService.php` (extract content-seeding core), `app/Providers/ThalloServiceProvider.php` (bindings), `packages/thallo-tenancy/src/Http/Controllers/TenantManagementController.php:52-54` + `packages/thallo-tenancy/src/Console/TenantManageCommand.php:57-64` (seed hook), `app/Content/Console/{SeedBlockTypesCommand,SyncBlockTypesCommand}.php` (delegates).
**Tests:** `tests/Unit/Content/Starter/{FingerprintFreezeTest,StarterTransactionTest,ActorResolutionTest}.php`, `tests/Integration/Tenancy/{StarterSeedTransactionTest,StarterSyncTest,TenantSeedActivationTest,StarterCommandMatrixTest,SeededTenantAcceptanceTest}.php`, `tests/Unit/Tenancy/SeedBoundaryArchitectureTest.php`.

---

### Task 1: `starter_provenance` migration + repository

**Files:**
- Create: `database/dependent-migrations/012_CreateStarterProvenanceTable.php`, `app/Content/Starter/StarterProvenanceRepository.php`
- Test: `tests/Integration/Tenancy/StarterProvenanceRepositoryTest.php`

**Interfaces:**
- Consumes: namespace-less dependent-migration convention as `011_CreateMediaAssetsTable.php`; `db($context)` builder; `Glueful\Helpers\Utils::generateNanoID(12)`; `thallo_system_flags` key `tenancy.schema_state` used only to distinguish clean-off from widened/on or `disabled_widened` databases.
- Produces: table per spec §4 (both uniques); repository —
  `findBySource(string $kind, string $sourceId): ?array` · `findByKey(string $kind, string $definitionKey): ?array` ·
  `recordApplied(string $kind, string $definitionKey, string $sourceId, string $fingerprint): void` (upsert by source identity) ·
  `markState(string $uuid, string $state): void` · `renameKey(string $uuid, string $newKey): void` ·
  `sourceIdsFor(string $kind): array` (list of `{uuid,source_id,state}` for removed-source discovery) ·
  `allFor(string $kind): array`. All queries are tenant-scoped automatically (tenant-owned table + read hook/stamper); rows return `array{uuid,definition_kind,definition_key,source_id,fingerprint,state}`.

- [ ] **Step 1: Failing tests** — (a) clean-off migration succeeds without a `tenants` table and leaves `tenant_uuid` nullable; (b) an already-widened database (`tenancy.schema_state=widened`, including enabled and `disabled_widened`) creates the empty table with `tenant_uuid NOT NULL`; (c) RetrofittedTenantTestCase under `runAsTenant($defaultTenantUuid)`: recordApplied → findBySource/findByKey round-trip; second recordApplied same source new fingerprint → updates not duplicates and resets state to `applied`; duplicate `(kind, definition_key)` for a DIFFERENT source → DB unique violation; markState/renameKey persist; rows created under tenant A invisible under tenant B.
- [ ] **Step 2: Migration** — namespace-less `CreateStarterProvenanceTable`, matching every dependent-migration sibling. Create `tenant_uuid(12)` **nullable initially**, no FK to the extension-owned `tenants` table, plus `uuid(12)` unique, tenant index, fields/timestamps, and both compound uniques from spec §4. Clean-off remains nullable and writes no provenance. If persisted `tenancy.schema_state` is `widened`, the newly-created table is empty: alter `tenant_uuid` to NOT NULL immediately. A later clean-off→on transition remains handled by the existing Phase-C retrofit because `starter_provenance` is already in `ThalloTenantTables`. `down()`: `dropTableIfExists`.
- [ ] **Step 3: Repository** — thin `db($this->context)->table('starter_provenance')` builder calls; `recordApplied` = update-by-`(kind,source_id)` if exists else insert (`uuid` = generateNanoID(12)); `updated_at` refreshed on every mutation (spec pin). No raw PDO (the stamper must see every write).
- [ ] **Step 4: Run → PASS; phpcs clean. Commit SKIPPED (HELD); ledger.**

---

### Task 2: `StarterTransaction`

**Files:**
- Create: `app/Content/Starter/StarterTransaction.php`
- Test: `tests/Unit/Content/Starter/StarterTransactionTest.php` (PG — needs real savepoints; place under Integration if the unit harness lacks a DB: `tests/Integration/Tenancy/StarterTransactionTest.php`)

**Interfaces:**
- Consumes (verified): `Connection::transactionLevel(): int`, `Connection::transaction(callable): mixed`, `getTransactionManager()->rollback()` (one level per call, savepoints), TransactionManager catches `Exception` only.
- Produces: `StarterTransaction::__construct(Connection $connection)`; `run(callable $fn): mixed` — records `$baseline = $connection->transactionLevel()`, delegates to `$connection->transaction($fn)`, and in a `catch (\Throwable $e)` loops `while ($this->connection->transactionLevel() > $baseline) { $this->connection->getTransactionManager()->rollback(); }` then rethrows. `afterCommit(callable $effect): void` delegates directly to `Connection::afterCommit()` while the transaction is active; it never owns or flushes a private queue.

- [ ] **Step 1: Failing tests** — (a) `\RuntimeException` inside `run()` → rolled back, level restored, a subsequent write on the SAME connection succeeds; (b) **`\Error` (TypeError) inside `run()`** → same guarantees; (c) nested `run()` inside `run()` → inner failure unwinds to the inner baseline only; (d) an effect registered inside `StarterTransaction::run()` nested within an unrelated outer `Connection::transaction()` does NOT fire when the starter transaction returns and fires exactly once only after the real outer commit; rollback fires nothing.
- [ ] **Step 2: Implement exactly the Produces contract.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 3: `SeedContext` + `StarterKind` contract + registry + fingerprint canonicalization

**Files:**
- Create: `app/Content/Starter/SeedContext.php`, `StarterApplyResult.php`, `StarterKind.php`, `StarterDefinition.php`, `StarterDefinitions.php`, `Fingerprint.php`
- Test: `tests/Unit/Content/Starter/FingerprintFreezeTest.php`

**Interfaces:**
- Produces (every later task depends on these exact shapes):

```php
/** One definition as supplied by a kind's source. */
final class StarterDefinition
{
    /** @param list<string> $adoptionKeys ordered OLDEST-first historical natural keys (excl. current) */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $definitionKey,
        public readonly array $payload,
        public readonly array $adoptionKeys = [],
    ) {
    }
}

final class SeedContext
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $tenantName,
        public readonly string $defaultLocale,
        public readonly ?string $actorUuid,
    ) {
    }
}

enum StarterApplyResult
{
    case Applied;
    case SkippedCollision;
}

interface StarterKind
{
    public function kind(): string;                       // 'content_type' | 'block_type' | ...
    /** @return list<StarterDefinition> */
    public function definitions(): array;
    /** sha256 over canonical JSON of the payload (sorted keys, volatile fields stripped) */
    public function fingerprint(StarterDefinition $def): string;
    /** Exact natural-key lookup only. Returns [rowKey, fingerprint], or null. */
    public function locateExact(string $definitionKey): ?array;
    /** Adoption lookup: current key, then adoptionKeys in order. */
    public function locateForAdoption(StarterDefinition $def): ?array;
    /** Insert on the ambient connection; collision skips MUST return SkippedCollision. */
    public function apply(StarterDefinition $def, SeedContext $seed): StarterApplyResult;
    /** Update the existing row (fingerprint-unchanged sync path). */
    public function updateTo(StarterDefinition $def, string $rowKey, SeedContext $seed): void;
    /** Rename the tenant row from $oldKey to $def->definitionKey (sync rename path). */
    public function rename(StarterDefinition $def, string $oldKey): void;
    public function syncable(): bool;                     // false: setting, navigation_menu, entry
}
```

`SeedContext::$actorUuid` is non-null for install/tenant seed and null for automated sync; all repositories already accept nullable creator/updater identity. `StarterDefinitions` = ordered registry: `kinds(): list<StarterKind>` in seed order (content_type, block_type, setting, region, navigation_menu, entry); `syncKinds()` filters `syncable()` and re-orders to sync dependency order (content_type, block_type, region). Fingerprint helper recursively sorts keys **only for associative maps** and preserves list order at every depth, then hashes canonical JSON with `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`. Volatile stripping is each kind's job BEFORE hashing (actor uuids, timestamps, generated ids never enter the payload).

- [ ] **Step 1: Failing freeze test** — for a fixed sample payload per kind-shape, assert the exact sha256 hex. Associative key order is irrelevant at every depth; list order is preserved and swapping schema fields/menu items MUST change the hash; unicode/slashes remain stable. The literals freeze the canonical form.
- [ ] **Step 2: Implement the six files** (registry constructor takes the six kinds — DI; T4–T6 register them). Run → PASS; phpcs. Commit SKIPPED.

---

### Task 4: SetupService extraction → `ContentTypeKind`, `SettingKind`, `RegionKind`

**Files:**
- Create: `app/Content/Starter/Kinds/ContentTypeKind.php`, `Kinds/SettingKind.php`, `Kinds/RegionKind.php`
- Modify: `app/Setup/SetupService.php` (`:105-111,121-167,173,181-191` — replace inline seeding with kind applications)
- Test: `tests/Unit/Content/Starter/StarterCoreKindsTest.php` + existing `tests/Integration/Setup/SetupServiceTest.php` (MUST stay green unmodified — the install-output-unchanged proof)

**Interfaces:**
- Consumes (verified): `ContentTypeRepository::create(array): string` (`:19-39`) + `updateSchema(string,array): void` (additive-only, `:42-66`) + `updateMeta` (`:74`) + `findBySlug` (`:100`); `RegionRepository::save(string $slug, array $blocks, array $settings, ?string $updatedBy): void` (`:39-54`); SetupService `put()` routing (`SystemKeys::isSystem` → SystemChannel else `settings` table, `:206-233`); exact install payloads (pages `:121-132`, category `:140-151`, post `:152-167`, regions `:182-191`, settings keys `site_name/default_locale/admin_url/listing_types`).
- Produces: the three kinds registered in `StarterDefinitions`. `source_id`s: `content_type:pages|category|post`, `setting:site_name|default_locale|listing_types` (note: `installed` + `admin_url` are NOT starter definitions — install-only, stay in SetupService), `region:header|footer`. `definition_key`s = slug/key. Payloads = the exact install payloads with volatile fields excluded from fingerprints (`created_by`, generated block ids — RegionKind fingerprints block STRUCTURE with ids stripped; apply() generates fresh NanoIDs). `SettingKind::syncable() === false`; settings seed writes tenant `settings` rows only when the key is absent, resolving `site_name`/`default_locale` from T3's `SeedContext`.
- SetupService refactor: `install()` keeps admin user/role + `installed` + `admin_url`, constructs `SeedContext(tenantUuid:'', tenantName:$siteName, defaultLocale:$locale, actorUuid:$userUuid)`, and calls the SAME kind appliers for content types/settings/regions. `SetupServiceTest` (`:56-174`) must pass UNMODIFIED.

- [ ] **Step 1: Failing unit test** — each kind: definitions() count + source_ids; fingerprint excludes actor/ids (two applies with different contexts → same fingerprint); ContentTypeKind::locateExact finds by slug; locateForAdoption honors current key then history; RegionKind payload round-trip.
- [ ] **Step 2: Implement kinds** (appliers call the verified repository APIs; `SettingKind::apply` mirrors `put()`'s check-then-insert on `settings` for non-system keys — system keys are not starter definitions).
- [ ] **Step 3: Refactor `SetupService::install()`** to consume the kinds; run `vendor/bin/phpunit tests/Integration/Setup/` → **PASS unmodified**. Run new test → PASS; phpcs. Commit SKIPPED.

---

### Task 5: `BlockTypeKind`

**Files:**
- Create: `app/Content/Starter/Kinds/BlockTypeKind.php`
- Test: extend `tests/Unit/Content/Starter/StarterCoreKindsTest.php`

**Interfaces:**
- Consumes (verified): `StarterBlockTypes::definitions()` (assoc arrays: `slug,label,icon,category,description,schema` — `StarterBlockTypes.php:32-46`); `BlockTypeRepository::create(array): string` (`:35`), `findBySlug` (`:67`), `updateSchema(uuid, schema, label, icon?, desc?, category?)` (`:96`).
- Produces: `BlockTypeKind` — `source_id` = `block_type:{slug}`, `definition_key` = slug, payload = the full definition array (no volatile fields — StarterBlockTypes is pure data), `syncable() === true`; exact-key and adoption lookup implement T3's split contract; `updateTo()` maps to `updateSchema(...)` with the FULL source schema (fingerprint-equal precondition means the tenant row is un-diverged, so full replacement is safe and non-lossy); `rename()` = single builder UPDATE of `block_types.slug` (no repository rename exists — direct `db()->table('block_types')` update, stamper-visible).

- [ ] **Step 1: Failing test** — definitions() mirrors StarterBlockTypes 1:1 (count + slugs); apply creates via repository (assert findBySlug post-apply); fingerprint literal frozen for the `section` block.
- [ ] **Step 2: Implement.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 6: `NavigationMenuKind` (aggregate) + `HomepageEntryKind`

**Files:**
- Create: `app/Content/Starter/Kinds/NavigationMenuKind.php`, `Kinds/HomepageEntryKind.php`
- Test: extend `tests/Unit/Content/Starter/StarterCoreKindsTest.php` + integration coverage lands in T7/T11

**Interfaces:**
- Consumes (verified): menu row shape from `MenuRepository::createMenu` (`MenuRepository.php:30-56` — uuid NanoID, `lock_version=0`, position scan) but **applies via builder inserts on the ambient connection — NEVER `replaceTree()`**; entry composition `EntryRepository::createEntry` + `saveDraft`, `RouteRepository::assign($entryUuid,$typeUuid,$locale,'home')`, then `PublishService::publish`; `SettingsStore::putMany(['homepage_entry' => $entryUuid])`; `EngineHomepageEntryProvider` proves `/` is selected by the tenant's `homepage_entry` setting, while a root-mounted `pages` route slug `home` is canonically `/home`.
- Produces: `NavigationMenuKind` — ONE aggregate definition: `source_id` = `navigation_menu:main`, key `main`, semantic payload = `{slug:'main', name:'Main', items:[{label:'Home', url:'/', position:0}]}`; apply maps the label to `labels[$seed->defaultLocale]`, inserts the real menu/item row shapes, and returns `SkippedCollision` for an existing `main`. `HomepageEntryKind` — `source_id` = `entry:homepage`, conceptual key `/`; collision lookup first checks whether a stored `homepage_entry` override already exists, then the explicit routed `pages/home` fallback, and returns `SkippedCollision` without provenance rather than replacing tenant-authored content. Fresh apply creates the entry + draft, assigns route slug `home`, publishes a schema-valid block-list body, then writes the generated entry UUID to `settings.homepage_entry` **inside the same outer transaction** and returns `Applied`. `syncable() === false`.
- **Event timing is verified, not deferred:** `PublishService::publish()` registers `EntryPublished` through `Connection::afterCommit()`. Its nested transaction promotes that callback to the outer seed level. Because T7 wraps the transaction inside `runAsTenant`, listeners execute after the real commit while the tenant context is still active.

- [ ] **Step 1: Failing tests** — aggregate payload fingerprint frozen; pre-inserted `main` skips menu + Home item; a stored `homepage_entry` or pre-existing `pages/home` skips without overwrite/provenance; fresh apply publishes `/home`, stores its UUID in `homepage_entry`, and the real root controller serves it at `/`; rollback removes entry/publication/setting together.
- [ ] **Step 2: Implement both kinds** using T3's `SeedContext` and split exact/adoption lookup.
- [ ] **Step 3: Run → PASS; phpcs. Commit SKIPPED.**

---

### Task 7: `TenantSeeder`

**Files:**
- Create: `app/Content/Starter/TenantSeeder.php`, `app/Content/Starter/StarterSeedFailpoint.php` (test seam; production binding absent)
- Test: `tests/Integration/Tenancy/StarterSeedTransactionTest.php`, `tests/Unit/Content/Starter/ActorResolutionTest.php`

**Interfaces:**
- Consumes: `StarterDefinitions`, `StarterProvenanceRepository`, `StarterTransaction`, `SeedContext`, `TenantProvisioningRunner::runAsProvisioningTenant`, `TenantAdministration::{markActive,listMembers,getTenant}`, `GeneralSettings::defaultLocale()`, and optional `StarterSeedFailpoint`.
- Produces: `TenantSeeder::seedAndActivate(string $tenantUuid, string $ownerUserUuid): void` and `repair(string $tenantUuid): void`. Refuses unless `getTenant()` reports provisioning/active. Creation passes owner; repair resolves the first active owner in contract order before writing. Flow is **`runAsTenant($uuid, fn() => StarterTransaction::run(...))`**: build `SeedContext`; for each definition, provenance hit skips, otherwise call `apply()` and record provenance only for `StarterApplyResult::Applied` (collision skips never claim provenance); call `markActive()` for provisioning as the final database statement; invoke optional `StarterSeedFailpoint::afterMarkActive()`; return. Effects use `Connection::afterCommit`; tenant context remains active through their execution.

- [ ] **Step 1: Failing integration tests** (RetrofittedTenantTestCase + a provisioning tenant created via `TenantAdministration::create` under a full-resolution readiness stub):
  - happy path: seedAndActivate → tenant `active`; content types/block types/settings/regions/menu+Home item/homepage exist under that tenant; one `applied` provenance row per definition; NOTHING leaked to other tenants.
  - **transaction participation (spec §9 pin):** the post-markActive failpoint first reads status `active` on the transaction's PDO, records PDO identity, then throws; after rollback the tenant is `provisioning` with ZERO starter/provenance rows. This proves the CAS executed and rolled back, not merely that a pre-CAS failure left status untouched.
  - **`\Error` failpoint:** TypeError-throwing kind → same rollback guarantees + a subsequent write on the same connection succeeds (StarterTransaction level restoration end-to-end).
  - repair: after the failure, `repair($uuid)` completes the full seed and activates; repair on suspended → refuses; repair on active → no-op-completing (idempotent skips).
  - actor: creation actor threads to `created_by` on content types; repair resolves first active owner; ownerless tenant → fails before any write (assert zero rows).
  - post-commit effects: callback fires on success only and observes the same tenant UUID from `CurrentTenantResolver`.
- [ ] **Step 2: Implement `TenantSeeder` + failpoint seam.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 8: seed seams + HTTP/CLI create integration

**Files:**
- Create: `packages/thallo-tenancy/src/Contracts/TenantSeedActivator.php`, `TenantSeedRepair.php`, `StarterSeedException.php`
- Modify: `app/Content/Starter/TenantSeeder.php` (implements it), `app/Providers/ThalloServiceProvider.php` (bind interface → shared `TenantSeeder`), `packages/thallo-tenancy/src/Http/Controllers/TenantManagementController.php:52-54`, `packages/thallo-tenancy/src/Console/TenantManageCommand.php:57-64`, `packages/thallo-tenancy/src/TenancyServiceProvider.php` (nullable ctor threading)
- Test: `tests/Integration/Tenancy/TenantSeedActivationTest.php`, `tests/Unit/Tenancy/SeedBoundaryArchitectureTest.php`

**Interfaces:**
- Produces (pack-declared, verbatim):

```php
<?php // packages/thallo-tenancy/src/Contracts/TenantSeedActivator.php

declare(strict_types=1);

namespace Thallo\Tenancy\Contracts;

/**
 * Seeds a committed provisioning tenant's starter surface and activates it. Declared by the
 * pack, implemented by the app (dependency inversion): the pack's creation surfaces never
 * import App\ classes or starter logic. Throws on failure; the tenant remains provisioning.
 */
interface TenantSeedActivator
{
    public function seedAndActivate(string $tenantUuid, string $ownerUserUuid): void;
}
```

- `TenantSeedRepair` is a separate one-method interface (`repair(string $tenantUuid): void`) implemented by the same shared `TenantSeeder`. The creation controller receives only `TenantSeedActivator`; the repair command receives only `TenantSeedRepair` (interface segregation; neither surface gains authority it does not use).
- Controller integration keeps nullable autowiring for pack boot compatibility but **fails before tenant creation** when the seam is absent:

```php
if ($this->seeder === null) {
    return Response::error(
        'Tenant starter seeding is unavailable.',
        Response::HTTP_SERVICE_UNAVAILABLE,
    );
}
$uuid = $this->tenants->create($this->context, $slug, $name, $owner);
try {
    $this->seeder->seedAndActivate($uuid, $owner);
} catch (\Throwable $e) {
    return Response::error(
        'Tenant was created but starter seeding failed.',
        Response::HTTP_INTERNAL_SERVER_ERROR,
        [
            'tenant_uuid' => $uuid,
            'status' => 'provisioning',
            'failed_definition' => $e instanceof StarterSeedException ? $e->definitionLabel : null,
            'repair_command' => 'php glueful thallo:tenant:seed ' . $uuid,
        ],
    );
}
return Response::created(['uuid' => $uuid, 'status' => 'active']);
```

with `StarterSeedException` (pack-declared beside the interface, `\RuntimeException` subclass carrying `public readonly ?string $definitionLabel`) so the pack surface can expose the failing definition without importing app classes; `TenantSeeder` throws it. Validation failures BEFORE `create()` stay 422 (unchanged). CLI create mirrors: seed after create; on failure print the structured payload + exit FAILURE.
- Architecture test: scan `packages/thallo-tenancy/src/**` for `use App\\` / `App\\` references → assert zero (the fake-activator boundary proof); binding test: container resolves `TenantSeedActivator` to the same shared `TenantSeeder` instance.

- [ ] **Step 1: Failing tests** — missing activator → 503 and ZERO tenant rows; fake activator success → 201 active; `StarterSeedException('block_type:section')` → 500 whose `error.details` contains the exact four recovery keys; pre-create validation remains 422; CLI parallel; architecture scan; both interfaces resolve to the same shared `TenantSeeder`.
- [ ] **Step 2: Implement.** Run → PASS (incl. SP2a `TenantManagementApiTest` updated expectations — creation now returns `active` on success); phpcs. Commit SKIPPED.

---

### Task 9: `StarterSync` (three-way, per-kind atomic) + `SyncReport`

**Files:**
- Create: `app/Content/Starter/StarterSync.php`, `app/Content/Starter/SyncReport.php`
- Test: `tests/Integration/Tenancy/StarterSyncTest.php`

**Interfaces:**
- Consumes: `StarterDefinitions::syncKinds()` (T3), `StarterProvenanceRepository`, `StarterTransaction`, `TenantContextRunner::{runAsTenant,forEachTenant}`, `TenantAdministration::getTenant`, and tenant-scoped `GeneralSettings::defaultLocale()`.
- Produces: `StarterSync::syncTenant(string $tenantUuid): SyncReport` and `syncAll(): list<SyncReport>` (via `forEachTenant`). Explicit sync refuses non-active tenants via `TenantAdministration::getTenant`; `syncAll` remains active-only by contract. `SyncReport` actions: `added|updated|renamed|rejoined_applied|skipped_customized|skipped_rename_collision|orphaned_source|adopted_applied|adopted_customized|unchanged`.
- `syncTenant` first resolves the active tenant projection, then runs **`runAsTenant($uuid, fn() => ...)`**. Inside that context it builds `SeedContext(tenantUuid, tenantName, defaultLocale, actorUuid:null)` and runs ONE `StarterTransaction::run()` per kind, keeping tenant context active through each commit callback. Algorithm per kind:
  1. For each source definition: provenance `findBySource` → row known:
     - load the provenance row's tenant row via `locateExact(provenance.definition_key)`. If its fingerprint equals the recorded fingerprint, it is safe regardless of the stored state: reset `customized|orphaned_source → applied`, then source fingerprint differs? `updateTo` + refresh (`updated`); equal? `rejoined_applied` when state changed, otherwise `unchanged`. Without an explicit reset command, state cannot be permanently sticky after the data has genuinely reconverged.
     - tenant row fingerprint != recorded → `markState('customized')`, report `skipped_customized`.
     - rename is handled before the generic update path: inspect the old row with `locateExact(provenance.definition_key)` and target occupancy with `locateExact(source.definitionKey)`; aliases never participate in collision detection. When unchanged + free, rename the tenant row, apply any source payload update against the new key, then update provenance key + fingerprint/state in that order inside the kind transaction. Customized or occupied target skips without mutation.
  2. No provenance row → adoption through `locateForAdoption`; matching fingerprint adopts applied, diverged adopts customized, absent applies + records.
  3. Removed-source pass: `sourceIdsFor(kind)` minus the source_ids encountered in 1–2 → `markState('orphaned_source')` for each (`orphaned_source`); never delete rows.
  Report emission + external effects strictly after the kind's transaction commits.

- [ ] **Step 1: Failing sync matrix** (RetrofittedTenantTestCase, seeded tenants A/B via T7):
  - source change (test-registry kind with bumped payload) → A and B both `updated`, fingerprints refreshed.
  - customize A's row (direct edit) → sync reports `skipped_customized` for A, `updated` for B; A's content untouched; provenance state `customized`.
  - restore A exactly to its recorded fingerprint → next sync resets it to applied and reports `rejoined_applied`/`updated`; customization is not an irreversible tombstone.
  - remove a source from the registry → both tenants' provenance rows → `orphaned_source`; a tenant that NEVER had the definition gets no false orphan (no provenance row → nothing to mark).
  - rename happy path: bump source `definitionKey`, keep payload → row renamed + provenance key renamed; rename of customized → skip; rename onto an occupied key → `skipped_rename_collision`.
  - adoption: strip provenance rows for the default tenant (simulating install-era rows) → sync adopts `applied` where fingerprints match, `customized` where the row was edited, and leaves a purely tenant-authored row unprovenanced; **adoption via adoption_keys**: registry definition renamed BEFORE first sync → old-key row adopted, not duplicated (frozen adoption_keys history test — an emptied `adoptionKeys` array for a renamed starter FAILS a dedicated unit assertion).
  - **kind-atomicity failpoint (spec §6):** injected failure between a definition mutation and its provenance refresh → whole kind rolled back; retry reports `updated`, NOT `skipped_customized` (the misclassification the atomicity pin exists to prevent).
  - explicit sync refuses provisioning/suspended; `syncAll` skips them; mid-sweep failure raises `TenantIterationException` naming the tenant.
- [ ] **Step 2: Implement `StarterSync` + `SyncReport`.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 10: CLI — `thallo:tenant:seed|sync`, blocks subcommand, legacy delegates

**Files:**
- Create: `packages/thallo-tenancy/src/Contracts/TenantStarterSync.php` (pack-declared structured sync seam), `packages/thallo-tenancy/src/Console/TenantSeedCommand.php`, `TenantSyncCommand.php`, `TenantBlockSyncCommand.php`
- Modify: `app/Content/Starter/StarterSync.php` (implements `TenantStarterSync`, mapping `SyncReport::toLines()`), `app/Providers/ThalloServiceProvider.php` (bind), `app/Content/Console/SeedBlockTypesCommand.php`, `app/Content/Console/SyncBlockTypesCommand.php`
- Test: `tests/Integration/Tenancy/StarterCommandMatrixTest.php`

**Interfaces:**
- Consumes: T8's separate `TenantSeedRepair`, `TenantStarterSync`, CLI patterns, current legacy command implementations/output, `SystemFlags`, and `TenantContextRunner`.
- Produces: `thallo:tenant:seed <uuid>`; `thallo:tenant:sync [<uuid>|--all] [--kind=block_type]`; and the exact documented `thallo:tenant:blocks:sync [<uuid>|--all]` as a thin command delegating to the same block-kind sync operation (the option may coexist, but never replaces the command). Legacy behavior:
  - tenancy OFF → execute the existing create-only seed and additive field-append sync algorithms byte-for-byte and write NO provenance. This preserves operator-added fields/metadata and avoids null-tenant provenance.
  - tenancy ON → bare invocation fails with guidance; `--tenant`/`--all` run those SAME legacy create-only/additive algorithms inside explicit tenant contexts. They do not route through fingerprint-based `StarterSync`, because a customized row must still receive newly-added starter fields under the legacy additive contract.

- [ ] **Step 1: Failing command matrix** — every off/on cell; operator-added block field + new starter field proves legacy sync preserves the former and appends the latter; no provenance written off; exact `thallo:tenant:blocks:sync` registration and behavior equals `tenant:sync --kind=block_type`; tenant seed repair/suspended refusal.
- [ ] **Step 2: Implement** the three pack commands and mode-aware legacy commands without changing their off-mode algorithm/output. Run → PASS; phpcs. Commit SKIPPED.

---

### Task 11: Acceptance + regression gates

**Files:**
- Create: `tests/Integration/Tenancy/SeededTenantAcceptanceTest.php`
- Test: this task.

- [ ] **Step 1: Acceptance script (spec §9):** under the SP2a full-resolution harness: `POST /v1/admin/tenancy/tenants` → 201 active → assert `settings.homepage_entry` names the published starter entry, `/home` is its canonical route, and the tenant host's `/` renders that entry with logo + visible Home menu item + footer. Failpoint create → 500 with the four recovery fields under `error.details` → repair command → site serves. Sync smoke updates both tenants.
- [ ] **Step 2: Gate runs (user directive — all before any release/pinning):** thallo tenancy-OFF full suite; tenancy-ON full suite; SP2a inert + full-resolution acceptance untouched; `SetupServiceTest` + `SetupApiTest` unmodified-green; `composer phpcs`. Record all counts in the ledger.
- [ ] **Step 3: Ledger. Commits remain HELD** (batch shape at go-ahead: engine+migration · kinds+SetupService · seeder+seam+HTTP/CLI · sync+commands · tests).

---

## Self-Review (rev 2)

**Spec coverage:** §1/§5 seed flow → T7 (+T2 transaction, T8 surfaces); §2 ownership + segregated activation/repair seams → T8; §3 components → T1–T6; §4 clean-off + widened-on data model → T1; §5 actor/CAS/context rules → T7; §5.2 menu aggregate + homepage setting → T6; structured 500 → T8 using the real Response signature; §6 exact/adoption lookup + reconvergence + removed-source + per-kind atomicity → T9; exact command + legacy pins → T10; §8 failure modes → T1/T2/T7/T9; §9 acceptance proves both `homepage_entry` and `/`; §10 out-of-scope respected.

**Review rulings incorporated:** exact `thallo:tenant:blocks:sync` ships (the `--kind` option is only an alias); repair uses a separate `TenantSeedRepair` interface; customized state reconverges automatically when the row again matches its recorded fingerprint; associative fingerprint keys sort while list order remains semantic.

**Verified-contract basis:** every Consumes block cites source from the sweep: transaction mechanics; controller/CLI hook slots; SetupService extraction ranges; repositories; homepage provider/setting; `Response::error(string,int,mixed)`; listMembers ordering; legacy command algorithms; and RetrofittedTenantTestCase. T6's former event-timing uncertainty is resolved: PublishService already registers through Connection::afterCommit.

**Type consistency:** `SeedContext` + split lookup contract land in T3 before every kind; T4–T7 consume that exact shape. Provenance methods match T1/T7/T9. `TenantSeedActivator` and `TenantSeedRepair` remain one-method interfaces implemented by the same shared seeder. SyncReport includes reconvergence once. StarterSeedException details map to `Response::error(..., details)` and T11 asserts the framework envelope.

## Execution Record (2026-07-10)

- Implemented all starter kinds, provenance migration/repository, Throwable-safe transaction,
  seed/repair, three-way sync, HTTP/CLI activation, exact tenant commands, and mode-aware legacy
  block commands. Commits remain held.
- Execution correction: active-only `TenantContextRunner` cannot enter a provisioning tenant.
  Added neutral `TenantProvisioningRunner` in extension-contracts and its tenancy-extension bridge;
  it accepts only provisioning/active tenants and is consumed only by `TenantSeeder`.
- Execution correction: workflow editorial gates correctly reject a draft starter homepage. Added
  explicit `PublishService::publishStarter()` so bootstrap publication still validates, snapshots,
  projects references, and emits after commit while omitting optional editorial approval gates.
- PostgreSQL rollback proof passes after a failpoint throws after `markActive()`: lifecycle CAS,
  starter rows, and provenance all roll back, and the same connection remains writable.
- Verification: contracts 25 tests / 61 assertions; tenancy extension 170 / 407; Thallo tenancy-off
  1,658 / 17,451 (51 expected skips); Thallo tenancy-on 1,766 / 17,955 (1 expected skip); full
  Thallo PHPCS 1,039 files; pack-boundary check green.
