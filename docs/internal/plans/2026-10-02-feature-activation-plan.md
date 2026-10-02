# One place to turn each feature on — Implementation Plan

> Amended 2026-10-02 after plan review. Changes:
> - **F1:** captures a context-scoped state snapshot before any route registration.
> - **Capability writes:** every capability state write happens inside an atomic, fenced operation transition.
> - **Lock order:** one order across finalization and workspace creation (activation rows first, sorted; workspace creation takes shared row locks and never writes them).
> - **Grants:** run inside the fenced activation lock.
> - **Enabled list:** read/modify/write is serialized with its cache rebuild.
> - **Block inserts:** use savepoints.
> - **Engine enabling:** an invocation that enables an engine always stops at the boot boundary.
> - **Order:** the management policy moves before the runner; Browse's import goes with its file.
>
> Amended again after the second review:
> - **One extension-state lock, framework included:** every enabled-list mutation, the framework CLI's too, takes one lock (new framework Task F2).
> - **`thallo:capabilities`:** refuses turning an activation capability on, and turns it off through supersession.
> - **Workspace seeding:** takes the activation share locks at the start of the starter transaction, before any block write, for new workspaces and repairs alike.
> - **Cancel:** carries an expected generation, checked inside the row lock.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each feature is managed in one place. Commerce and Subscriptions turn on with one user action, which prepares the engine, seeds blocks into every workspace, grants permissions through the ledger, and only then makes the feature effective, with stale route tables unusable. Turning a feature off disables only the capability. The Extensions page becomes **Features**, with the capabilities first and a guarded **Installed packages** view. Browse and the web installer are removed.

**Architecture:**
- **Activations.** A system table, `capability_activations`, holds one row per activation capability. It's pre-created by its migration, so a lock target always exists. That row is the coordination point. Every capability state change is published inside a transaction that holds that row's lock, and runner writes are fenced on generation and lease-owner token.
- **One state snapshot per application context.** At `register()` time, before any route registration, core reads every capability switch and `capability.state_version` in one statement. The capability registry decides from that snapshot, and (with glueful/framework 1.88) the same version becomes a context-scoped input to the route-table signature. A table compiled under one state is then rejected by a context booted under another.
- **Engines.** The engine is prepared through tenancy's owning-flow pattern. The enabled-list write and cache rebuild are serialized under one advisory lock.
- **Management policy.** One policy in core feeds the admin, Thallo's endpoints, and the framework's generic surfaces, through `mergeConfig('extensions', …)`.

**Tech Stack:** PHP 8.3 (Glueful framework, PostgreSQL, PHPUnit), a framework change in `../framework` (released by the user as `glueful/framework` 1.88.0), Nuxt UI admin (Vue 3, pinia-colada, vitest, Playwright e2e in `admin/e2e`).

**Spec:** `docs/internal/superpowers/specs/2026-10-02-feature-activation-design.md` (approved at `a3c90f8d`). Approval note honoured by the crash tests: **before the finalization commit the capability remains off; after it commits it remains on, even if the response is lost.**

## Rulings made while planning (from the code)

- **The route seam is context-scoped and eager (framework Task F1, the release gate).** `RouteCache::computeSignature()` is private and hashes only source files. `Router::__construct` creates its own `RouteCache($context)` and calls `load()`, which returns early on a cache miss without computing a signature. When a table is loaded from the cache, routes no longer registered are never removed. So:
  - **F1 adds two methods to `ApplicationContext`:** `setRouteSignatureInput(string $name, string $value): void` and `routeSignatureInputs(): array` (sorted by name). `RouteCache::computeSignature()` appends them.
  - **The inputs are values set by the application before routes are registered, not resolvers.** A cold cache, or a `save()` at the end of boot, uses exactly the value the context was given. Consecutive contexts in one process each carry their own inputs.
  - **Thallo sets the input in `CoreServiceProvider::register()`** from the same snapshot the registry decides from (Task 11).
  - **The gate:** the user publishes `glueful/framework` 1.88.0 before Task 11.
- **The state snapshot (Task 1).** `CapabilityStateSnapshot` is taken once per context, in `CoreServiceProvider::register()`. It runs one statement, `SELECT key, value FROM thallo_system_flags WHERE key LIKE 'capability.%' OR key = 'search_enabled'`, so the rows and the version come from one database snapshot.
  - **The registry factory** (`makeCapabilityRegistry`) decides from the snapshot's rows, applying `CapabilityStateStore`'s existing rules: canonical key, legacy `search_enabled`, then the config map.
  - **Fresh reads** (the admin, the activation runner, workspace creation) use `CapabilityStateStore::fresh(string $id): ?bool`, a direct read.
  - **The fallback is narrow:**
    - SQLSTATE `42P01` (`thallo_system_flags` doesn't exist yet), or a console boot whose database is unconfigured or unreachable (SQLSTATE class `08`), gives an **unavailable** snapshot: decisions fall back to the config map, and the version is the literal `'unavailable'`. A table compiled then never matches a healthy state.
    - Every other database error is rethrown.
- **Every capability state write is a fenced, atomic transition** (`ActivationStore`). Each of these happens in one transaction holding the activation row lock (`SELECT … FOR UPDATE`):
  - **Starting a new generation** also publishes the capability `false` (`CapabilityStateStore::put`, which advances the version in the same transaction).
  - **Superseding** (turn off or cancel) also publishes `false`.
  - **Finalizing** publishes `true`, under the runner's fence.

  There's no unfenced `put` for an activation capability anywhere. `CapabilityAdminController::update()` routes turning off through `supersede`. A paused runner that resumes after a newer generation can't write the capability, because its only state write is inside `withinFenced`, which refuses it. `MARK_PREPARING` is therefore completed by `startOrJoin` itself, not by a runner step.
- **One lock order: activation rows, then everything else.**
  1. `capability_activations` row locks, in ascending `capability` order (several rows are locked at once only by workspace creation);
  2. block inserts;
  3. the grants lock, `pg_advisory_xact_lock(hashtext('thallo:install-role-grants'))`;
  4. the extension-state lock (Task 5).

  - **Finalization** takes `FOR UPDATE` on its row only (inside `withinFenced`), seeds missing workspace blocks, then publishes.
  - **Workspace creation** (`TenantSeeder::seed`, inside its starter transaction) takes `FOR SHARE` on **every** activation row, sorted. It reads each capability's state fresh, seeds the explicit contributions of those that are on or preparing, and **never writes the activation rows**. That avoids lock upgrades: two creations share, and finalization waits for them.
  - **Why finalization's re-check finds everything:** finalization lists active workspaces after taking `FOR UPDATE`, and seeds any missing contribution. A workspace mid-creation holds `FOR SHARE` until its transaction, which includes `markActive`, commits. So finalization sees it as active.
  - **The rows always exist:** migration 040 pre-creates a row for each activation capability (`thallo.commerce`, `thallo.subscriptions`), status `idle`, generation 0. `startOrJoin` also inserts a missing row (`ON CONFLICT DO NOTHING`) for a capability added later.
  - **Readiness** (`workspaces` in the row) is written only by fenced runner steps.
- **Grants run inside the fenced activation lock.** The grant step runs inside `withinFenced` (activation row `FOR UPDATE`, fence checked), which then calls `InstallRoleGrants::apply()`. `apply()` opens its own transaction; on the same `Connection`, a nested transaction delegates to the outer one. Inside it, it takes the grants lock, reads the ledger fresh, grants, and writes the ledger. The order holds: activation row, then grants lock.
  - **Provision and setup** call `apply()` with no activation lock, taking the grants lock only, and never touch activation rows while holding it.
  - **Ownership** is held for the whole grant transaction, because the row lock is.
- **The owning flow and the enabled list** (`EngineActivation`, tenancy's pattern):
  1. `ExtensionSchemaExecutor::migrateProtected($package, $actor)`, which works only for protected providers. Task 5 protects managed engines **before** the runner (Task 6) uses it. It releases its lock on return.
  2. Under `ExtensionStateLock::within()`, a session-level `pg_advisory_lock(hashtext('glueful:extension-state'))` with unlock in `finally`: `ExtensionStateWriter->enable(config_path('extensions.php'), $provider)`, then `clearConfigCache()`, then `ExtensionManager::writeCacheNow()`. The writer reads the list inside the lock. The key is the framework's (Task F2), not Thallo's, so Thallo's writers and the framework's own commands share one lock.

  Every Thallo writer of the enabled list takes `ExtensionStateLock`:
  - `EngineActivation`;
  - provision's required-provider repair (Task 8);
  - `ExtensionAdminController::toggle()` around its executor call;
  - tenancy's `ExtensionActivation::activate()` and `deactivate()`, through a contract (Task 5).

  **The framework's writers take the same lock (framework Task F2).** `ExtensionStateWriter` rewrites the **whole** enabled list. So an independent package's `extensions:enable` that read the list before Commerce was added, then wrote after, would remove Commerce without targeting it. F2 adds `Glueful\Extensions\ExtensionStateMutex::within(ApplicationContext, callable)`: `pg_advisory_lock(hashtext('glueful:extension-state'))`, with a flock fallback on non-PostgreSQL drivers at `storage/framework/locks/extension-state.lock`. The executor's `enable()` and `disable()` wrap their writer call and cache rebuild in it, and `extensions:cache` does too.

  Thallo's `ExtensionStateLock` uses the same key from Task 5 on, so Thallo's writers coordinate among themselves before 1.88. The mixed proof (a Commerce activation alongside an independent package's CLI) runs in Task 11, once 1.88 is required. Writability: `HostCapability::forToggle()`.
- **Block inserts during activation and workspace creation are savepointed.** `CapabilityBlockSeeder` inserts through `BlockInsert::ifAbsent(Connection $db, callable $insert)`:
  1. `SAVEPOINT thallo_block_seed`;
  2. `BlockTypeRepository::create`;
  3. `RELEASE SAVEPOINT`.

  On `PDOException` SQLSTATE `23505` **whose constraint is `uniq_block_type_slug`**, it does `ROLLBACK TO SAVEPOINT` and returns `false` (skipped). Any other unique violation is rolled back to the savepoint and rethrown. The enclosing transaction stays usable.
- **The boot boundary.** An invocation that performs `ENABLE_ENGINE` (changing provider activation) **always stops right after it**, whatever its `$freshBoot`. `VERIFY_BOOT` runs only when `$freshBoot` is true **and** this invocation didn't run `ENABLE_ENGINE`.
  - **HTTP:** the admin then makes another `continue` call.
  - **CLI:** `features:enable` and `features:resume` start a fresh child process whenever the runner returns `needsBoot`.
- **The operation store is a table.** `SystemFlags` has no compare-and-set. Migration `core/database/migrations/040_CreateCapabilityActivationsTable.php` creates `capability_activations` and `capability_activation_events`. Both are system tables (not in `ThalloTenantTables`).
- **The fresh-boot gate checks:** `ExtensionManager::hasProvider($provider)` and `CapabilityRegistry::availability($id)->available`, in a context that booted after the engine was enabled. A cache-stale outcome stays off until the gate passes.
- **Activation capabilities:** `thallo.commerce` and `thallo.subscriptions`. `thallo.tenancy` links to Settings › Workspaces. Accounts and Content importers follow the audit (Task 5).
- **Removed with Browse:**
  - `GET /extensions/registry` and `POST /extensions/install`;
  - `useExtensionCatalog`, `fetchExtensionCatalog`, `CatalogExtension`, `useExtensionInstall`, `installExtension`, `InstallStatus` and `InstallResult`;
  - `BrowseExtensions.vue`, together with its import and tab in `pages/extensions/index.vue`, in the same commit.
- **The admin's permission refresh** means invalidating `['me']`, `['capabilities']` and `['extensions']`, then `useCapabilitiesStore().refreshUntilChanged()`. There's no client permissions store; gating is on the server.
- **`/extensions` → `/features`** through a component that calls `router.replace` (the `reset-password.vue` pattern).
- **Raw PDO sites** are classified in `tests/Unit/Tenancy/RawPdoScopingLintTest.php` (`SYSTEM_READERS`, `SYSTEM_WRITERS`).

## Global Constraints

- Never push, tag or split. Work ends at local commits: Thallo on `dev`, the framework (Task F1) on its default branch. Releasing is the user's.
- No Co-Authored-By trailers.
- PHP gates:
  - the full suite once, unprefixed, run attached in parts: reset, `tests/Feature tests/Unit`, then the four `INTEGRATION_SHARD_*` lists from ci.yml;
  - phpcs judged by its exit code;
  - `composer boundaries`;
  - `composer test:skeleton`.
- Admin gates: `pnpm type-check`, `pnpm lint`, `pnpm fmt:check`, vitest, e2e on 8 workers.
- The changelog bullet rides with its change, under `## [Unreleased]`. There's no heading yet; the first bullet adds it.
- New tests go in existing `tests/Integration/*` directories: Capabilities, Setup, Tenancy, Http, Console, Routing. No new shard entries.
- Packs reference only `Thallo\Contracts\…` (`scripts/check-pack-boundaries.php`).
- Copy:
  - **Turn-on confirmation:** "**Turn on Commerce?** This prepares your store and adds products, orders, shop blocks and templates. Your existing content is kept."
  - **Turn-off confirmation:** "**Turn off Commerce?** Commerce's pages, blocks and menu are hidden. Products, orders and your content are kept, and you can turn it on again."
  - **Summaries** are built from `activation.result`, never hard-coded.

## Review Focus

1. **A browser left open on Features while another operator turns the feature off.** The tab's **Continue** is refused with "a newer decision exists", and its next poll shows off. Pinned in Task 7 (`testAContinueForASupersededGenerationIsRefused`) and Task 10 (`superseded continue shows off`).
2. **An operator's own `extensions.protected` entry for a package core also declares.** The operator's reason wins in refusals, and the package is still refused. Pinned in Task 5 (`testAnOperatorProtectedEntryWins`).
3. **A workspace that fails seeding during activation, then is deleted before Retry.** Retry drops it instead of failing forever. Pinned in Task 4 (`testRetryDropsAWorkspaceDeletedSinceItFailed`).
4. **Turning on an already-prepared feature with read-only application files.** It completes; no application-file step is pending. Pinned in Task 6 (`testAPreparedEngineActivatesOnAReadOnlyHost`).
5. **The engine disabled behind Thallo's back while the capability is on.** Features shows it as unavailable with the remedy, and turning it on runs a normal activation. Pinned in Task 7 (`testAnEngineDisabledOutsideThalloShowsUnavailable`).

---

## Shared contracts (named once, used by every task)

```php
// core/src/Capabilities/CapabilityStateSnapshot.php — one per application context
final class CapabilityStateSnapshot
{
    public const UNAVAILABLE = 'unavailable';
    /** @param array<string,string> $rows key => value from thallo_system_flags */
    public function __construct(public readonly array $rows, public readonly string $version, public readonly bool $available) {}
    public static function take(Connection $db, bool $console): self;   // one SELECT; narrow fallback
}

// core/src/Capabilities/CapabilityStateStore.php (existing) — additions
//   public function explicitFrom(array $rows, string $id): ?bool   // the existing read rules, over given rows
//   public function fresh(string $id): ?bool                       // direct read, never memoised
//   public function put(string $id, bool $enabled): void           // now advances the version in the same transaction

// core/src/Capabilities/CapabilityStateVersion.php
final class CapabilityStateVersion
{
    public const KEY = 'capability.state_version';
    public function current(): string;   // fresh; throws on any error but 42P01 (then '0')
    public function advance(): void;     // inside the caller's transaction
}

// core/src/Capabilities/Activation/ActivationStep.php
final class ActivationStep
{
    public const MARK_PREPARING = 'mark_preparing';      // completed by ActivationStore::startOrJoin
    public const ENABLE_ENGINE = 'enable_engine';        // changes provider activation: boot boundary after
    public const VERIFY_BOOT = 'verify_boot';
    public const SEED_BLOCKS = 'seed_blocks';
    public const GRANT_PERMISSIONS = 'grant_permissions';
    public const FINALIZE = 'finalize';
    public const ALL = [self::MARK_PREPARING, self::ENABLE_ENGINE, self::VERIFY_BOOT,
        self::SEED_BLOCKS, self::GRANT_PERMISSIONS, self::FINALIZE];
    public const WRITES_APPLICATION_FILES = [self::ENABLE_ENGINE];
}

// core/src/Capabilities/Activation/ActivationStatus.php
final class ActivationStatus
{
    public const IDLE = 'idle';             // pre-created, never started
    public const PREPARING = 'preparing';   // open
    public const FAILED = 'failed';         // open, resumable
    public const SUCCEEDED = 'succeeded';   // closed: on
    public const SUPERSEDED = 'superseded'; // closed: turned off or cancelled
    public const OPEN = [self::PREPARING, self::FAILED];
}

// core/src/Capabilities/Activation/ActivationRecord.php — readonly snapshot of a row
final class ActivationRecord
{
    public function __construct(
        public readonly string $capability, public readonly int $generation, public readonly string $status,
        /** @var list<string> */ public readonly array $stepsDone,
        public readonly ?string $failedStep, public readonly ?string $error, public readonly ?string $remedy,
        public readonly ?string $ownerToken, public readonly ?string $leaseExpiresAt,
        /** @var array<string,string> tenant => ready|failed */ public readonly array $workspaces,
        /** @var array<string,mixed> */ public readonly array $result,
        public readonly string $actor, public readonly string $updatedAt,
    ) {}
    public function isOpen(): bool;
    public function nextStep(): ?string;    // first of ActivationStep::ALL not in stepsDone
    /** @return array<string,mixed> API shape: snake_case, no owner token */
    public function toArray(): array;
}

// core/src/Capabilities/Activation/ActivationLease.php
final class ActivationLease
{
    public function __construct(public readonly string $capability, public readonly int $generation,
        public readonly string $ownerToken) {}
}

// core/src/Capabilities/Activation/ActivationSuperseded.php
final class ActivationSuperseded extends \RuntimeException {}

// core/src/Capabilities/Activation/ActivationStore.php
final class ActivationStore
{
    public const LEASE_SECONDS = 120;
    /** Locks the row. Open → join. Otherwise generation+1, MARK_PREPARING done, and the capability
     *  is published false (CapabilityStateStore::put) — all in this one transaction. */
    public function startOrJoin(string $capability, string $actor): ActivationRecord;
    public function find(string $capability): ?ActivationRecord;
    /** Takes the lease when free or expired; null when another runner holds a live one. */
    public function acquire(string $capability, int $generation): ?ActivationLease;
    /** One transaction: row FOR UPDATE, fence check (generation + owner token + live lease), $fn(),
     *  then lease extension. Throws ActivationSuperseded, before $fn runs, when the fence fails. */
    public function withinFenced(ActivationLease $lease, callable $fn): mixed;
    public function completeStep(ActivationLease $lease, string $step, array $resultPatch = []): ActivationRecord;
    public function failStep(ActivationLease $lease, string $step, string $error, ?string $remedy): ActivationRecord;
    public function markWorkspace(ActivationLease $lease, string $tenantUuid, ?string $state): void; // null drops it
    /** Locks the row; generation+1, status superseded, lease cleared, and the capability published
     *  false — one transaction. Returns the new generation. */
    public function supersede(string $capability, string $actor, ?int $expectedGeneration = null): int;
    // $expectedGeneration (cancel): checked inside the row lock; when the row's generation differs or
    // its status is not open, throws ActivationSuperseded and changes nothing. null (turn off the
    // current feature): supersedes whatever generation is current.
    public function release(ActivationLease $lease): void;
    /** Workspace creation: FOR SHARE on every activation row, ascending, inside the caller's
     *  transaction. Returns capability => fresh state. */
    public function shareAll(): array;   // array<string, array{on:bool, preparing:bool}>
}

// core/src/Capabilities/Activation/ExtensionStateLock.php (implements the contract below)
final class ExtensionStateLock implements \Thallo\Contracts\Extensions\ExtensionStateCoordinator
{
    /** pg_advisory_lock(hashtext('glueful:extension-state')) for $fn, unlocked in finally — the framework's
     *  ExtensionStateMutex key (F2), so Thallo's writers and the framework's commands share one lock. */
    public function within(callable $fn): mixed;
}

// core/src/Capabilities/Activation/BlockInsert.php
final class BlockInsert
{
    /** Savepointed insert; false when the slug already exists (uniq_block_type_slug), rethrows others. */
    public static function ifAbsent(Connection $db, callable $insert): bool;
}

// core/src/Capabilities/Activation/CapabilityBlockSeeder.php
final class CapabilityBlockSeeder
{
    /** Explicit contributions into the current store (BlockInsert); returns created slugs. */
    public function seedCurrent(string $capability): array;
    /** Every active workspace (tenancy on) or the current store; tenant => created slugs.
     *  @param list<string> $only Retry: only these tenants (a deleted one is dropped) */
    public function seedAll(string $capability, ?ActivationLease $lease = null, array $only = []): array;
    /** First statement of TenantSeeder's starter transaction (new workspace or repair), before any
     *  kind writes: shareAll(), held to commit. Returns capability => fresh state. */
    public function beginWorkspaceSeed(): array;
    /** After the kinds, same transaction: seeds every capability on or preparing in $states.
     *  Writes no activation row. */
    public function seedNewWorkspace(string $tenantUuid, array $states): void;
}

// core/src/Capabilities/Activation/EngineActivation.php
final class EngineActivation
{
    /** migrateProtected, then ExtensionStateLock::within(writer->enable + clearConfigCache + writeCacheNow).
     *  @return array{status:'prepared'|'cache_stale'|'already', error:?string} */
    public function prepare(string $package, string $provider, string $actor): array;
    public function applicationFilesWritable(): bool;
}

// core/src/Capabilities/Activation/ActivationRunner.php
final class ActivationRunner
{
    /** @var ?\Closure(string): void test seam: 'before_commit' | 'after_commit' around finalization */
    public static ?\Closure $crashProbe = null;
    /** Runs from the next step. Returns after ENABLE_ENGINE (needs a boot), a failure, or the end.
     *  $freshBoot: this context booted after the last ENABLE_ENGINE completed. */
    public function run(string $capability, int $generation, bool $freshBoot): ActivationOutcome;
}

// core/src/Capabilities/Activation/ActivationOutcome.php
final class ActivationOutcome
{
    public function __construct(public readonly ActivationRecord $record, public readonly bool $needsBoot) {}
}

// core/src/Capabilities/FeatureManagementPolicy.php
final class FeatureManagementPolicy
{
    public const REQUIRED = 'required';
    public const MANAGED = 'managed';
    public const INDEPENDENT = 'independent';
    /** @return array{class:string, capability:?string, reason:string, link:?string} */
    public function managementOf(string $package): array;
    /** @return array<class-string, array{reason:string, managed_by:string}> */
    public function protectedProviders(): array;
    /** @return list<string> sorted ascending */
    public function activationCapabilities(): array;
    /** @return array{package:string, provider:class-string}|null */
    public function engineOf(string $capability): ?array;
}

// packages/thallo-contracts/src/Extensions/ExtensionStateCoordinator.php
interface ExtensionStateCoordinator
{
    /** Runs $fn while holding the one lock every enabled-list writer takes. */
    public function within(callable $fn): mixed;
}
```

---

## Task F1 (framework repo): context-scoped route-table signature inputs

**Repo:** `/Users/michaeltawiahsowah/Sites/glueful/framework` (clean at `2c4b86a5`, 1.87.0).

**Files:**
- Modify: `src/Bootstrap/ApplicationContext.php`: `setRouteSignatureInput()` and `routeSignatureInputs()`
- Modify: `src/Routing/RouteCache.php`: `computeSignature()` (:228-249) appends `$this->context->routeSignatureInputs()`
- Test: `tests/Unit/Routing/RouteSignatureInputsTest.php`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces:
  - `ApplicationContext::setRouteSignatureInput(string $name, string $value): void`
  - `ApplicationContext::routeSignatureInputs(): array<string,string>`, sorted by name

  With no inputs, the signature is byte-identical to today's.

- [ ] **Step 1: Write the failing test**

```php
final class RouteSignatureInputsTest extends TestCase
{
    public function testAColdCacheIsSavedUnderTheContextsInputNotALaterValue(): void
    {
        // no cache file: load() misses without computing the signature; save() must still embed '1'
        [$ctxA, $routerA] = $this->freshContextWithOneRoute();
        $ctxA->setRouteSignatureInput('capability_state', '1');
        self::assertNull((new RouteCache($ctxA))->load());
        self::assertTrue((new RouteCache($ctxA))->save($routerA));
        [$ctxB] = $this->freshContextWithOneRoute($ctxA->getBasePath());
        $ctxB->setRouteSignatureInput('capability_state', '2');
        self::assertNull((new RouteCache($ctxB))->load(), 'built under 1, unusable under 2');
    }

    public function testConsecutiveContextsInOneProcessKeepTheirOwnInputs(): void
    {
        [$ctxA, $routerA] = $this->freshContextWithOneRoute();
        $ctxA->setRouteSignatureInput('capability_state', '1');
        (new RouteCache($ctxA))->save($routerA);
        [$ctxB] = $this->freshContextWithOneRoute($ctxA->getBasePath());
        $ctxB->setRouteSignatureInput('capability_state', '1');
        self::assertNotNull((new RouteCache($ctxB))->load(), 'same state, same table');
        [$ctxC] = $this->freshContextWithOneRoute($ctxA->getBasePath());
        self::assertNull((new RouteCache($ctxC))->load(), 'a context without the input is a different state');
    }

    public function testNoInputsKeepTodaysSignature(): void
    {
        [$ctx] = $this->freshContextWithOneRoute();
        self::assertSame(self::todaysSignatureFor($ctx), (new RouteCache($ctx))->getSignature());
    }
}
```

`todaysSignatureFor()` copies the current `computeSignature()` body into the test as a private static helper, so the "unchanged" claim is checked against the old algorithm. `freshContextWithOneRoute(?string $root = null)` builds a temp app root (or reuses `$root`) with `storage/cache`, a new `ApplicationContext`, and a `Router` with one array-handler route (no closures).

- [ ] **Step 2: Run it.** `vendor/bin/phpunit tests/Unit/Routing/RouteSignatureInputsTest.php`. Expected: FAIL (`Call to undefined method …setRouteSignatureInput()`).
- [ ] **Step 3: Implement.** In `ApplicationContext`: `private array $routeSignatureInputs = [];`. The setter assigns, and the getter returns a sorted copy (`ksort`). In `computeSignature()`, after the file parts: `foreach ($this->context->routeSignatureInputs() as $name => $value) { $parts[] = 'input:' . $name . '=' . $value; }`. Nothing is appended when it's empty.
- [ ] **Step 4: Run it**, plus the framework's full suite and phpcs. Expected: PASS.
- [ ] **Step 5: Changelog and commit.** Added: "**Route-table signature inputs.** `ApplicationContext::setRouteSignatureInput($name, $value)` adds application state to the compiled route table's signature. A table compiled under one state is rejected by a context booted under another, including a table first built on a cold cache." Commit: `feat(routing): context-scoped route-table signature inputs`. **Stop.** The user releases 1.88.0. Task 11 waits for it.

---

## Task F2 (framework repo): one lock for every enabled-list mutation, from resolution to cache

**Repo:** the framework, after F1 and in the same release (1.88.0).

**Files:**
- Create: `src/Extensions/ExtensionStateMutex.php`
- Modify:
  - `src/Extensions/Schema/ExtensionSchemaExecutor.php`: in `enable()` and `disable()`, the mutex is held from just before the `ExtensionStateWriter` call (:90 in `enable`) through `finishWithCacheRecompile()` / `writeCacheNow()`. The writer reads the list inside the mutex, and `writeCacheNow(null)` re-resolves providers inside it.
  - `src/Console/Commands/Extensions/CacheCommand.php` (:73-87): take the mutex **before resolving `$classes`**, then `$context->clearConfigCache()` inside it, then resolve, delete the file and `writeCacheNow($classes)`, all inside. Today it resolves first and writes later, so a list resolved before a concurrent enable could overwrite the newer cache.
- Test: `tests/Integration/Extensions/ExtensionStateMutexTest.php`, `tests/fixtures/extension_state_child.php`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces: `ExtensionStateMutex::within(ApplicationContext $context, callable $fn): mixed`.
  - **On PostgreSQL:** a session-level `pg_advisory_lock(hashtext('glueful:extension-state'))`, waiting up to `extensions.state_lock_wait` seconds (default 30), with `pg_advisory_unlock` in `finally`.
  - **Otherwise:** `flock(LOCK_EX)` on `storage/framework/locks/extension-state.lock`.
  - **The key is public API.**
- **The test seams:**
  - `ExtensionStateMutex`: `/** @internal test seam */ public static ?\Closure $afterAcquire = null;`, called with the context right after the lock is acquired and before `$fn`;
  - `ExtensionSchemaExecutor`: `/** @internal test seam */ public static ?\Closure $afterMigrationLocks = null;`, called in `enable()` right after `$this->lock->acquireAll(...)` (:80) returns and before the mutex is taken. Task 11's order test uses it. `ExtensionStateWriter` is final, with private helpers, and the executor constructs it directly, so the seam sits in the mutex, not the writer. Production never sets it.

- [ ] **Step 1: Write the failing tests.** The child fixture boots a temp app whose `config/extensions.php` is shared by both children. Given `--pause`, it sets `ExtensionStateMutex::$afterAcquire` to print `holding` and block until it reads `resume` on stdin. It then runs its action (`enable <package>` through the executor, or `cache` through `CacheCommand`) and prints `done`.

```php
public function testASecondEnableWaitsOnTheMutexAndBothProvidersSurvive(): void
{
    $a = $this->startChild('extension_state_child.php', ['enable', 'fixture/alpha', '--pause']);
    $a->waitFor('holding');                                   // A holds the mutex
    $b = $this->startChild('extension_state_child.php', ['enable', 'fixture/beta']);
    $this->waitUntilAWaiterOnTheExtensionStateLock();         // B is blocked on THIS mutex (pg_locks)
    self::assertFalse($b->isFinished(), 'B must not complete while A holds the lock');
    $a->signal('resume');
    $a->finish(); $b->finish();
    $enabled = (require $this->configPath())['enabled'];
    self::assertContains('Fixture\\Alpha\\Provider', $enabled);
    self::assertContains('Fixture\\Beta\\Provider', $enabled);
    self::assertEqualsCanonicalizing($enabled, $this->providersInExtensionCache());
}

public function testExtensionsCacheResolvesProvidersOnlyAfterTakingTheMutex(): void
{
    // A (enable fixture/alpha) holds the mutex before writing; B (extensions:cache) starts and must wait
    // BEFORE resolving; A resumes and writes alpha; B then resolves and rebuilds a cache that has alpha
    $a = $this->startChild('extension_state_child.php', ['enable', 'fixture/alpha', '--pause']);
    $a->waitFor('holding');
    $b = $this->startChild('extension_state_child.php', ['cache']);
    $this->waitUntilAWaiterOnTheExtensionStateLock();
    $a->signal('resume');
    $a->finish(); $b->finish();
    self::assertContains('Fixture\\Alpha\\Provider', $this->providersInExtensionCache());
}
```

`waitUntilAWaiterOnTheExtensionStateLock()` polls, for up to 10 s, `SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND objid = (hashtext('glueful:extension-state')::bigint & 4294967295)::oid` (with `classid` matching the high bits, as PostgreSQL splits the 64-bit key) until it's at least 1. That's the same handshake the framework's migration-lock tests use. The framework has no `ChildProcesses` trait (that's Thallo's, Task 2), so the test carries its own small `proc_open` helper with the same `startChild` / `waitFor` / `signal` / `finish` / `isFinished` shape. `fixture/alpha` and `fixture/beta` are two minimal packages under `tests/fixtures/packages/` with `extra.glueful.provider`, `migrations: none` and a path-repository manifest the test app reads.

- [ ] **Step 2: Run them.** Expected: FAIL. The first loses a provider or B finishes early; the second leaves a cache without alpha, because `CacheCommand` resolved before waiting.
- [ ] **Step 3: Implement** the mutex, the executor wrapping, and `CacheCommand`'s lock-then-clear-then-resolve order.
- [ ] **Step 4: Run them**, plus the full framework suite. Expected: PASS.
- [ ] **Step 5: Changelog and commit.** Added: "**One lock for every enabled-list change.** `extensions:enable`, `extensions:disable`, `extensions:cache` and the schema executor take `ExtensionStateMutex`, from resolving providers through rebuilding the cache, so two changes can't overwrite each other's edits to `config/extensions.php` or the extension cache. Applications that write the list take the same lock." Commit: `feat(extensions): serialize enabled-list mutations from resolution to cache`. **Stop.** F1 and F2 ship together as 1.88.0, released by the user.

---

## Task 1: one capability-state snapshot per context, and a version that advances with every state write

**Files:**
- Create: `core/src/Capabilities/CapabilityStateVersion.php`, `core/src/Capabilities/CapabilityStateSnapshot.php`
- Modify:
  - `core/src/Capabilities/CapabilityStateStore.php`: `put()` :63-77 is transactional and advances the version; add `explicitFrom()` and `fresh()`;
  - `core/src/Providers/CoreServiceProvider.php`:
    - `register()`: take the snapshot (bound as a value);
    - `makeCapabilityRegistry` :2738-2755: decide from `explicitFrom($snapshot->rows, $id)`;
  - `tests/Unit/Tenancy/RawPdoScopingLintTest.php`: `CapabilityStateVersion` and `CapabilityStateSnapshot` as system readers/writers.
- Test: `tests/Integration/Capabilities/CapabilityStateSnapshotTest.php`

**Interfaces:**
- Produces: as in the shared contracts.

- [ ] **Step 1: Write the failing tests**

```php
final class CapabilityStateSnapshotTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec(
            "DELETE FROM thallo_system_flags WHERE key IN ('capability.state_version', 'capability.test.flip.enabled')"
        );
        parent::tearDown();
    }

    public function testAStateWriteAdvancesTheVersionInTheSameTransaction(): void
    {
        $version = $this->container()->get(CapabilityStateVersion::class);
        $before = (int) $version->current();
        $this->container()->get(CapabilityStateStore::class)->put('test.flip', true);
        self::assertSame((string) ($before + 1), $version->current());
    }

    public function testACrashBeforeCommitMovesNeitherTheStateNorTheVersion(): void
    {
        $version = $this->container()->get(CapabilityStateVersion::class);
        $before = $version->current();
        try {
            $this->connection()->transaction(function (): void {
                $this->container()->get(CapabilityStateStore::class)->put('test.flip', true);
                throw new \RuntimeException('killed');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame($before, $version->current());
        self::assertNull($this->container()->get(CapabilityStateStore::class)->fresh('test.flip'));
    }

    public function testTheSnapshotHoldsTheStateAndTheVersionTogether(): void
    {
        $this->container()->get(CapabilityStateStore::class)->put('test.flip', true);
        $snapshot = CapabilityStateSnapshot::take($this->connection(), console: false);
        self::assertSame('true', $snapshot->rows['capability.test.flip.enabled']);
        self::assertSame($this->container()->get(CapabilityStateVersion::class)->current(), $snapshot->version);
    }

    public function testOnlyAMissingTableFallsBackAndItIsMarkedUnavailable(): void
    {
        $missing = CapabilityStateSnapshot::take($this->connectionWithEmptySchema(), console: true);
        self::assertFalse($missing->available);
        self::assertSame(CapabilityStateSnapshot::UNAVAILABLE, $missing->version);
        $this->expectException(\PDOException::class);   // a permission error is not masked
        CapabilityStateSnapshot::take($this->connectionAsRoleWithoutSelect(), console: false);
    }

    public function testTheRegistryDecidesFromTheContextsSnapshotNotALaterWrite(): void
    {
        $ctx = self::bootAppWithConfigOverride('thallo', []);                 // snapshot: test.flip absent
        $this->container()->get(CapabilityStateStore::class)->put('test.flip', false);
        $registry = $ctx->getContainer()->get(CapabilityRegistry::class);
        $registry->register(new Capability('test.flip'));
        self::assertTrue($registry->isEnabled('test.flip'), 'decided from the snapshot (absent → on)');
    }
}
```

`connectionWithEmptySchema()` and `connectionAsRoleWithoutSelect()` are test helpers:
- **the empty schema:** a `Connection` with `search_path` set to a fresh temporary schema (`CREATE SCHEMA tmp_snapshot_<rand>`; dropped in `tearDown`);
- **the restricted role:** `SET ROLE` to a role created with no grant on `thallo_system_flags`, reset in `tearDown`. If the test database user can't create roles, `markTestSkipped` that half with the reason.

- [ ] **Step 2: Run it.** Expected: FAIL (missing classes).
- [ ] **Step 3: Implement.**
  - **`CapabilityStateSnapshot::take`:** one prepared `SELECT key, value FROM thallo_system_flags WHERE key LIKE 'capability.%' OR key = 'search_enabled'`. Catch `PDOException`:
    - SQLSTATE `42P01` → `new self([], self::UNAVAILABLE, false)`;
    - `$console` and SQLSTATE class `08` → the same;
    - anything else → rethrow.
  - **`version`** is `$rows['capability.state_version'] ?? '0'`.
  - **`CapabilityStateVersion::advance`:** `INSERT … ON CONFLICT (key) DO UPDATE SET value = (COALESCE(NULLIF(thallo_system_flags.value,''),'0')::bigint + 1)::text`.
  - **`put()`** runs the system write, the read-back and `advance()` inside `$db->transaction()`.
  - **`register()`** binds the snapshot as a value, `take($db, PHP_SAPI === 'cli')`, following the shape of the existing `register()` bindings.
- [ ] **Step 4: Run it**, plus `tests/Integration/Capabilities` and the RawPdo lint. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(capabilities): one capability-state snapshot per context, and a version that advances with every state write`.

---

## Task 2: the activation store: atomic transitions, fenced ownership

**Files:**
- Create: `core/database/migrations/040_CreateCapabilityActivationsTable.php`. It creates both tables (`hasTable` guards), inserts the `idle` rows for `thallo.commerce` and `thallo.subscriptions` (`ON CONFLICT DO NOTHING`), and its `down()` drops both.
- Create: `core/src/Capabilities/Activation/{ActivationStep,ActivationStatus,ActivationRecord,ActivationLease,ActivationSuperseded,ActivationStore}.php`
- Modify: `CoreServiceProvider` (shared, autowired), and the RawPdo lint (`ActivationStore`: system writer)
- Test: `tests/Integration/Capabilities/ActivationStoreTest.php`, `tests/fixtures/activation_start_race_child.php`, `tests/fixtures/activation_paused_runner_child.php`

**Schema:**
- `capability_activations`:
  - `capability` varchar(64) primary key
  - `generation` bigint, not null, default 0
  - `status` varchar(16), not null
  - `steps_done` jsonb, not null, default `'[]'`
  - `failed_step` varchar(32), null
  - `error` text, null
  - `remedy` text, null
  - `owner_token` varchar(32), null
  - `lease_expires_at` timestamptz, null
  - `workspaces` jsonb, not null, default `'{}'`
  - `result` jsonb, not null, default `'{}'`
  - `actor` varchar(120), not null, default `''`
  - `started_at` timestamptz, null
  - `updated_at` timestamptz, not null
- `capability_activation_events`:
  - `id` bigserial
  - `capability` varchar(64)
  - `generation` bigint
  - `event` varchar(32)
  - `detail` jsonb
  - `actor` varchar(120)
  - `created_at` timestamptz
  - indexed on `(capability, generation)`

- [ ] **Step 1: Write the failing tests**

```php
final class ActivationStoreTest extends AppTestCase
{
    private function store(): ActivationStore { return $this->container()->get(ActivationStore::class); }
    private function states(): CapabilityStateStore { return $this->container()->get(CapabilityStateStore::class); }

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM capability_activation_events WHERE capability LIKE 'test.%'");
        $pdo->exec("DELETE FROM capability_activations WHERE capability LIKE 'test.%'");
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key LIKE 'capability.test.%'");
        parent::tearDown();
    }

    public function testStartingPublishesTheCapabilityOffInTheSameTransaction(): void
    {
        $this->states()->put('test.shop', true);
        $record = $this->store()->startOrJoin('test.shop', 'op');
        self::assertSame(1, $record->generation);
        self::assertContains(ActivationStep::MARK_PREPARING, $record->stepsDone);
        self::assertFalse($this->states()->fresh('test.shop'));
    }

    public function testASecondStartJoins(): void
    {
        $a = $this->store()->startOrJoin('test.shop', 'a');
        self::assertSame($a->generation, $this->store()->startOrJoin('test.shop', 'b')->generation);
    }

    public function testSupersedingPublishesOffAndRefusesTheOldRunner(): void
    {
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen);
        self::assertSame($gen + 1, $this->store()->supersede('test.shop', 'off'));
        self::assertFalse($this->states()->fresh('test.shop'));
        $this->expectException(ActivationSuperseded::class);
        $this->store()->withinFenced($lease, fn () => $this->states()->put('test.shop', true));
    }

    public function testACancelForAStaleGenerationChangesNothing(): void
    {
        $gen1 = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $this->store()->supersede('test.shop', 'off');
        $gen3 = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen3);
        $this->store()->withinFenced($lease, fn () => $this->states()->put('test.shop', true));
        try {
            $this->store()->supersede('test.shop', 'late-cancel', expectedGeneration: $gen1);
            self::fail('a stale cancel superseded the current generation');
        } catch (ActivationSuperseded) {
        }
        self::assertSame($gen3, $this->store()->find('test.shop')->generation);
        self::assertTrue($this->states()->fresh('test.shop'));
    }

    public function testAPausedOldRunnerCannotDisableANewerSuccessfulActivation(): void
    {
        // generation 1's runner pauses; it is superseded; generation 3 finishes on; runner 1 resumes
        $gen1 = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $old = $this->store()->acquire('test.shop', $gen1);
        $this->store()->supersede('test.shop', 'off');
        $gen3 = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $new = $this->store()->acquire('test.shop', $gen3);
        $this->store()->withinFenced($new, fn () => $this->states()->put('test.shop', true));
        try {
            $this->store()->withinFenced($old, fn () => $this->states()->put('test.shop', false));
            self::fail('the old runner wrote');
        } catch (ActivationSuperseded) {
        }
        self::assertTrue($this->states()->fresh('test.shop'), 'still on');
    }

    public function testAnExpiredLeaseIsTakenOverAndTheOldOwnerIsFenced(): void
    {
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $a = $this->store()->acquire('test.shop', $gen);
        self::assertNull($this->store()->acquire('test.shop', $gen), 'a live lease is exclusive');
        $this->expireLease('test.shop');
        $b = $this->store()->acquire('test.shop', $gen);
        $this->store()->completeStep($b, ActivationStep::ENABLE_ENGINE);
        $this->expectException(ActivationSuperseded::class);
        $this->store()->completeStep($a, ActivationStep::ENABLE_ENGINE);
    }

    public function testAPausedRunnerProcessResumingAfterTakeoverIsRefused(): void
    {
        // child acquires, signals, waits; parent expires the lease, takes over, finishes; child resumes
        $child = $this->startChild('activation_paused_runner_child.php', ['test.shop']);
        $child->waitFor('acquired');
        $this->expireLease('test.shop');
        $b = $this->store()->acquire('test.shop', $this->store()->find('test.shop')->generation);
        $this->store()->completeStep($b, ActivationStep::ENABLE_ENGINE);
        $child->signal('resume');
        self::assertStringContainsString('superseded', $child->finish());
    }

    public function testSimultaneousStartsInTwoProcessesMakeOneOperation(): void
    {
        self::assertSame(['1', '1'], $this->runChildren('activation_start_race_child.php', [['test.race'], ['test.race']]));
    }

    public function testShareAllLocksEveryActivationRowInOrderAndReadsFreshState(): void
    {
        $this->connection()->transaction(function (): void {
            $states = $this->store()->shareAll();
            self::assertSame(['thallo.commerce', 'thallo.subscriptions'], array_keys($states));
        });
    }

    private function expireLease(string $capability): void
    {
        $this->connection()->getPDO()->prepare(
            "UPDATE capability_activations SET lease_expires_at = NOW() - INTERVAL '1 minute' WHERE capability = ?"
        )->execute([$capability]);
    }
}
```

**The child helpers** (`startChild` returning an object with `waitFor(string)`, `signal(string)` and `finish(): string`; `runChildren(string $fixture, list<list<string>> $argsPerChild): list<string>`): `proc_open` with stdin/stdout pipes. The child writes markers to stdout and reads a line from stdin to resume. Children boot through `Framework::create`, as `tests/fixtures/checkout_attempt_race_child.php:53` does. Put the helper in `tests/Support/ChildProcesses.php` (a trait) for Tasks 3, 4 and 6.

- [ ] **Step 2: Run it.** Expected: FAIL. Run `DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/run-test-migrations.php` after adding 040.
- [ ] **Step 3: Implement.** Every method runs in `Connection::transaction` and begins with `SELECT … FROM capability_activations WHERE capability = ? FOR UPDATE` (after `INSERT … ON CONFLICT DO NOTHING`).
  - **`startOrJoin`:**
    - An open row joins (event `joined`).
    - Otherwise: generation+1, status `preparing`, `steps_done = ["mark_preparing"]`; failure fields, workspaces, result and lease cleared; `actor` and `started_at` set. Then **`CapabilityStateStore::put($capability, false)` in the same transaction**, which advances the version. Event `started`.
  - **`acquire`:** the generation must match and the status must be open. Take the lease when `owner_token IS NULL OR lease_expires_at < NOW()`: a 32-hex `owner_token`, and `lease_expires_at = NOW() + LEASE_SECONDS`.
  - **`withinFenced`:**
    1. Lock the row.
    2. Check `generation = lease.generation AND owner_token = lease.ownerToken AND lease_expires_at >= NOW()`, or throw `ActivationSuperseded`.
    3. Run `$fn`.
    4. Extend the lease.
  - **Every fenced write** (`completeStep`, `failStep`, `markWorkspace`, `release`) is a `withinFenced` call.
  - **`supersede`:** when `$expectedGeneration` is given and differs from the locked row's generation, or the row isn't open, throw `ActivationSuperseded` before any write. Otherwise: generation+1, status `superseded`, lease cleared, `put($capability, false)` in the same transaction. Event `superseded`.
  - **`shareAll`:** `SELECT capability, status FROM capability_activations ORDER BY capability FOR SHARE`. It requires an enclosing transaction (`withinTransaction()`, or throw `LogicException`). For each row: `on` is `CapabilityStateStore::fresh() === true`, and `preparing` is the status being open.
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(capabilities): an activation store whose state transitions are atomic and fenced`.

---

## Task 3: install-role grants decide and record in one serialized transaction

**Files:**
- Modify: `core/src/Setup/InstallRoleGrants.php` (`apply()` :58-71)
- Test: `tests/Integration/Setup/InstallRoleGrantsTest.php` (add), `tests/fixtures/install_role_grants_child.php`

**Interfaces:**
- Produces: `InstallRoleGrants::apply(): InstallRoleGrantsReport`. The signature is unchanged; activation calls it inside `withinFenced` (Task 6).

- [ ] **Step 1: Write the failing tests**

```php
public function testARevocationMadeWhileAnotherRunHoldsTheLockIsNotUndone(): void
{
    // the child enters apply(), takes the lock, reads the ledger and pauses (THALLO_TEST_PAUSE_AFTER_LEDGER_READ);
    // the parent revokes; the child resumes and finishes; the revocation stands
    $this->freshInstall();
    $this->grants()->apply();
    $child = $this->startChild('install_role_grants_child.php', ['--pause-after-ledger-read']);
    $child->waitFor('ledger-read');
    $this->revoke('administrator', 'commerce.view');
    $child->signal('resume');
    $child->finish();
    self::assertNotContains('commerce.view', $this->roleSlugs('administrator'));
}

public function testConcurrentRunsContributingDifferentPermissionsKeepBothLedgerEntries(): void
{
    $this->freshInstall();
    $this->channel()->put('installed', '1');
    try {
        $this->runChildren('install_role_grants_child.php', [['--add-permission=race.alpha'], ['--add-permission=race.beta']]);
    } finally {
        $this->channel()->forget('installed');
        $this->dropPermission('race.alpha');
        $this->dropPermission('race.beta');
    }
    $ledger = json_decode((string) $this->channel()->get(InstallRoleGrants::LEDGER_KEY), true);
    self::assertContains('race.alpha', $ledger['superuser']);
    self::assertContains('race.beta', $ledger['superuser']);
}
```

**The child** creates its permission row first (`--add-permission`), then calls `apply()`. The pause hook is a test-only env check, `THALLO_TEST_PAUSE_AFTER_LEDGER_READ`, read only when `APP_ENV=testing`, right after the ledger read inside the transaction. Without the lock (today), the second test loses an entry: each run writes the ledger it read.

Activation versus provision is covered in Task 6 (`testActivationGrantsAndAConcurrentProvisionKeepEachOthersEntries`).

- [ ] **Step 2: Run it.** Expected: the second test FAILs (one entry lost).
- [ ] **Step 3: Implement.** Keep `$before` and `syncCatalog()` outside. Then `$db->transaction(...)`:
  1. `SELECT pg_advisory_xact_lock(hashtext('thallo:install-role-grants'))`;
  2. `if ($channel instanceof SystemFlags) $channel->clearCache()`;
  3. `$ledger = $this->ledger()`;
  4. the test pause hook;
  5. the grant loop;
  6. the ledger `put`.

  Classify the raw `exec` in the lint.
- [ ] **Step 4: Run it.** `tests/Integration/Setup`. Expected: PASS.
- [ ] **Step 5: Commit.** `fix(setup): install-role grants decide and record inside one serialized transaction`, with a changelog bullet (adding `[Unreleased]` → Fixed): "**Overlapping provisions keep every role grant**, and a permission you revoke stays revoked."

---

## Task 4: blocks reach every workspace, with savepointed inserts and one lock order

**Files:**
- Create: `core/src/Capabilities/Activation/BlockInsert.php`, `CapabilityBlockSeeder.php`
- Create: `core/src/Capabilities/FeatureManagementPolicy.php` with `activationCapabilities()` and `engineOf()` only. Task 5 completes it.
- Modify: `core/src/Content/Starter/TenantSeeder.php` (`seed()` :55, used by `seedAndActivate` and `repair`). Inside the starter transaction:
  1. **first statement, before any kind writes:** `$states = CapabilityBlockSeeder::beginWorkspaceSeed()`, which takes the share locks;
  2. then the kinds;
  3. then `seedNewWorkspace($tenantUuid, $states)`.

  The locks are held to commit. This keeps the lock order (activation rows, then block writes) for new workspaces and repairs of active ones alike.
- Modify: the RawPdo lint (savepoint statements)
- Test: `tests/Integration/Tenancy/CapabilityBlockSeedingTest.php` (`extends RetrofittedTenantTestCase`, `use ChildProcesses`), `tests/fixtures/workspace_create_child.php`, `tests/fixtures/activation_finalize_child.php`

- [ ] **Step 1: Write the failing tests**

```php
final class CapabilityBlockSeedingTest extends RetrofittedTenantTestCase
{
    use ChildProcesses;

    public function testPreparationSeedsEveryWorkspaceWhileTheCapabilityIsOff(): void
    {
        $this->publish('thallo.commerce', false);
        $created = $this->seeder()->seedAll('thallo.commerce');
        foreach ([self::$tenantAUuid, self::$tenantBUuid] as $t) {
            self::assertContains('product-grid', $created[$t]);
        }
    }

    public function testARacingInsertInsideATransactionLeavesItCommittable(): void
    {
        $this->runAsTenant(self::$tenantAUuid, function (): void {
            $this->connection()->transaction(function (): void {
                $this->insertBlockRowDirectly('product-grid');            // the other runner's row
                self::assertFalse(BlockInsert::ifAbsent($this->connection(), fn () => $this->blocks()->create(
                    $this->contribution('product-grid')
                )));
                $this->connection()->getPDO()->exec('SELECT 1');          // the transaction is not aborted
                $this->writeAProbeFlagInSameTransaction();                 // a later write still succeeds
            });
        });
        self::assertSame(1, $this->countSlug(self::$tenantAUuid, 'product-grid'));
        self::assertTrue($this->probeFlagCommitted());
    }

    public function testAnotherUniqueViolationIsNotTakenForAnExistingSlug(): void
    {
        $this->expectException(\PDOException::class);
        $this->connection()->transaction(fn () => BlockInsert::ifAbsent($this->connection(), fn () =>
            $this->blocks()->create($this->contributionWithUuidOf('hero'))));       // duplicate uuid
    }

    public function testAWorkspaceCreatedWhilePreparingGetsTheBlocks(): void
    {
        $this->startPreparing('thallo.commerce');
        $tenant = $this->createWorkspace('mid-prep');
        self::assertSame(1, $this->countSlug($tenant, 'product-grid'));
    }

    public function testAWorkspaceRequestThatBootedBeforeTheSwitchReadsFreshState(): void
    {
        $stale = self::bootAppWithConfigOverride('thallo', []);   // its snapshot says off
        $this->startPreparing('thallo.commerce');
        $this->finishActivation('thallo.commerce');
        $tenant = $this->createWorkspaceWith($stale, 'after-switch');
        self::assertSame(1, $this->countSlug($tenant, 'product-grid'));
    }

    public function testFinalizationAndAWorkspaceCreationInTwoProcessesDoNotDeadlockAndMissNothing(): void
    {
        // child 1 creates a workspace and pauses inside TenantSeeder's transaction AFTER shareAll() and
        // its seeding (THALLO_TEST_PAUSE_IN_WORKSPACE_SEED); child 2 runs the real FINALIZE step, whose
        // FOR UPDATE waits on child 1's FOR SHARE; child 1 resumes and commits; child 2 completes
        $this->startPreparingThroughSeedAndGrants('thallo.commerce');
        $creator = $this->startChild('workspace_create_child.php', ['paused-ws']);
        $creator->waitFor('kinds-written');                 // paused AFTER its starter kinds wrote block rows
        $finalizer = $this->startChild('activation_finalize_child.php', ['thallo.commerce']);
        $finalizer->waitFor('waiting-for-row');
        $creator->signal('resume');
        $tenant = trim($creator->finish());
        self::assertStringContainsString('succeeded', $finalizer->finish(timeoutSeconds: 20));   // no deadlock
        self::assertSame(1, $this->countSlug($tenant, 'product-grid'));
        self::assertTrue($this->states()->fresh('thallo.commerce'));
    }

    public function testAnActiveWorkspaceRepairRacingFinalizationDoesNotDeadlock(): void
    {
        // TenantSeedRepair::repair on active tenant A pauses after its kinds wrote block rows
        // (THALLO_TEST_PAUSE_IN_WORKSPACE_SEED=after-kinds); finalization runs in another child; the
        // repair resumes; both finish, and A has Commerce's blocks exactly once
        $this->startPreparingThroughSeedAndGrants('thallo.commerce');
        $this->deleteBlockRow(self::$tenantAUuid, 'hero');            // something for the repair to write
        $repair = $this->startChild('workspace_repair_child.php', [self::$tenantAUuid]);
        $repair->waitFor('kinds-written');
        $finalizer = $this->startChild('activation_finalize_child.php', ['thallo.commerce']);
        $finalizer->waitFor('waiting-for-row');
        $repair->signal('resume');
        $repair->finish();
        self::assertStringContainsString('succeeded', $finalizer->finish(timeoutSeconds: 20));
        self::assertSame(1, $this->countSlug(self::$tenantAUuid, 'product-grid'));
    }

    public function testRetryDropsAWorkspaceDeletedSinceItFailed(): void
    {
        $lease = $this->startPreparingWithLease('thallo.commerce');
        $this->store()->markWorkspace($lease, 'gone00000001', 'failed');
        self::assertSame([], $this->seeder()->seedAll('thallo.commerce', $lease, ['gone00000001']));
        self::assertArrayNotHasKey('gone00000001', $this->store()->find('thallo.commerce')->workspaces);
    }
}
```

**The finalize child** prints `waiting-for-row` just before `withinFenced` and the record's status at the end. The helpers in the test class (`publish`, `startPreparing`, `startPreparingWithLease`, `startPreparingThroughSeedAndGrants`, `finishActivation`, `createWorkspace`, `createWorkspaceWith`, `countSlug`, `insertBlockRowDirectly`, `contribution`, `contributionWithUuidOf`, `writeAProbeFlagInSameTransaction`, `probeFlagCommitted`) wrap:
- `ActivationStore`;
- `TenantAdministration::create`, then `TenantSeedActivator::seedAndActivate`;
- raw SQL against `block_types` and `thallo_system_flags`.

- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement.**
  - **`BlockInsert::ifAbsent`:**
    1. `exec('SAVEPOINT thallo_block_seed')`.
    2. Try the insert, then `RELEASE SAVEPOINT thallo_block_seed` → true.
    3. On `PDOException` with SQLSTATE `23505` and the message naming `uniq_block_type_slug`: `ROLLBACK TO SAVEPOINT thallo_block_seed` → false.
    4. On any other exception: `ROLLBACK TO SAVEPOINT thallo_block_seed`, then rethrow.

    It requires a transaction; outside one, it opens one around itself.
  - **`seedAll`:**
    - **Tenancy on:** `TenantContextRunner::forEachTenant`, or `runAsTenant` for each of `$only` (a missing tenant → `markWorkspace($lease, $t, null)` and skip).
    - **Tenancy off:** the current store, keyed by `SingleStoreTenant::defaultUuidOrNull() ?? 'single'`.
    - Each workspace runs `seedCurrent` in its own transaction, then a fenced `markWorkspace($lease, $t, 'ready' | 'failed')` when `$lease` is given.
  - **`beginWorkspaceSeed`:** `return $store->shareAll();`. It requires the enclosing transaction.
  - **`seedNewWorkspace`:** `foreach ($states as $cap => $s) if ($s['on'] || $s['preparing']) $this->seedCurrent($cap);`. It writes no activation row.
  - **The test pause hook** `THALLO_TEST_PAUSE_IN_WORKSPACE_SEED=after-kinds` pauses after the kinds have written (printing `kinds-written`). It's read only when `APP_ENV=testing`. Add `tests/fixtures/workspace_repair_child.php`, which calls `TenantSeedRepair::repair($uuid)`.
- [ ] **Step 4: Run it.** `tests/Integration/Tenancy tests/Integration/Blocks tests/Integration/Content/SeedBlockTypesTest.php`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(capabilities): capability blocks reach every workspace, coordinated with workspace creation`.

---

## Task 5: one management policy, enforced on the server and the CLI, from an audit

**(Before the runner: `migrateProtected` accepts only protected providers, so managed engines must be protected first.)**

**Files:**
- Modify: `core/src/Capabilities/FeatureManagementPolicy.php` (complete)
- Modify: `core/src/Providers/CoreServiceProvider.php`: in `register()`, `$this->mergeConfig('extensions', ['protected' => $policy->protectedProviders()])`, next to :2697-2710; bind `ExtensionStateCoordinator` to `ExtensionStateLock`
- Create: `core/src/Capabilities/Activation/ExtensionStateLock.php` (key `hashtext('glueful:extension-state')`, the framework's, from F2; until 1.88 it serializes Thallo's writers, and after it the framework's too), `packages/thallo-contracts/src/Extensions/ExtensionStateCoordinator.php`
- Modify:
  - `core/src/Http/Controllers/ExtensionAdminController.php`: `installed()` :371 adds `management`. `toggle()` :273 refuses non-independent packages from the policy first, then, **as an interim measure until Task 11**, wraps the executor call in `ExtensionStateLock::within`. Before 1.88 the executor takes no extension-state mutex, so the wrapper is the only coordination and creates no inversion. On 1.88 the executor takes its migration locks and then the mutex itself, so Task 11 removes this wrapper. A docblock on the wrapper says so;
  - `packages/thallo-tenancy/src/Enablement/ExtensionActivation.php`: `activate()` and `deactivate()` run their writer-plus-cache sequence inside `ExtensionStateCoordinator::within` when the container has it (soft-resolved; otherwise unlocked).
- Test: `tests/Integration/Capabilities/FeatureManagementPolicyTest.php`, `tests/Integration/Console/ManagedEngineCliTest.php`

**Step 0, the audit,** recorded in the class docblock with evidence:

```bash
for p in Aegis Users I18n Media Audit EmailNotification ImportExport Commerce Subscriptions Tenancy Payvia Meilisearch; do
  echo "== $p"; grep -rln "Glueful\\\\Extensions\\\\$p\\\\" core/src packages/*/src | head
done
```

The rule: **required** when referenced without a `has()`/`class_exists()` guard on a path every install runs (`SetupService`, `ProvisionCommand`, `InstallRoleGrants`, authentication, the `/v1/admin` bootstrap). Otherwise **managed** (it backs a capability) or **independent**. The tests pin the result.

**Expected:**
- **required:** `glueful/aegis`, `glueful/users`;
- **managed:** `glueful/commerce` → `thallo.commerce`, `glueful/subscriptions` → `thallo.subscriptions`, `glueful/tenancy` → Workspaces;
- **decided by the audit:** i18n, media, audit, email-notification, import-export.

- [ ] **Step 1: Write the failing tests**

```php
public function testManagedAndRequiredProvidersAreProtectedWithoutTheOperatorsConfig(): void
{
    $protected = (array) config($this->appContext(), 'extensions.protected', []);
    self::assertArrayHasKey('Glueful\\Extensions\\Commerce\\CommerceServiceProvider', $protected);
    self::assertArrayHasKey('Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider', $protected);
    self::assertStringContainsString('thallo:features:enable', $protected['Glueful\\Extensions\\Commerce\\CommerceServiceProvider']['reason']);
}

public function testAnOperatorProtectedEntryWins(): void
{
    $ctx = self::bootAppWithConfigOverride('extensions', ['protected' => [
        'Glueful\\Extensions\\Commerce\\CommerceServiceProvider' => ['reason' => 'Ours.', 'managed_by' => 'ops'],
    ]]);
    self::assertStringStartsWith('Ours.', (string) ProtectedProviders::refusalFor($ctx, 'Glueful\\Extensions\\Commerce\\CommerceServiceProvider'));
}

public function testTheAdminToggleRefusesManagedAndRequiredPackages(): void
{
    foreach (['glueful/commerce', 'glueful/aegis'] as $package) {
        self::assertSame(409, $this->controller()->disable($this->jsonRequest(['name' => $package]))->getStatusCode(), $package);
    }
}

public function testInstalledReportsWhoManagesEachPackage(): void
{
    $byName = array_column($this->installedRows(), null, 'name');
    self::assertSame('managed', $byName['glueful/commerce']['management']['class']);
    self::assertSame('thallo.commerce', $byName['glueful/commerce']['management']['capability']);
    self::assertSame('required', $byName['glueful/aegis']['management']['class']);
}

public function testMigrateProtectedAcceptsAManagedEngine(): void
{
    $op = $this->container()->get(ExtensionSchemaExecutor::class)->migrateProtected('glueful/commerce', 'test');
    self::assertNotSame(ExtensionOperation::STATUS_FAILED, $op->status);
}
```

`ManagedEngineCliTest` runs `php glueful extensions:disable glueful/aegis` and `extensions:enable glueful/commerce` as subprocesses (`APP_ENV=testing`). It asserts a non-zero exit code and output naming the reason.

- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement.** The reasons:
  - **managed:** "Managed by Commerce: turn it on in Features, or run `php glueful thallo:features:enable thallo.commerce`.";
  - **required:** "Required by Thallo.";
  - `managed_by` is `thallo features` or `thallo (required)`.
- [ ] **Step 4: Run it**, plus `tests/Integration/Http` and `tests/Integration/Tenancy`. Expected: PASS.
- [ ] **Step 5: Commit**, with a Changed bullet: "**Thallo's own engines can't be switched off by accident.** Packages Thallo needs, and engines a feature manages (Commerce, Subscriptions), refuse the generic extension switch and `extensions:enable` / `extensions:disable`, and name the right place instead." Commit message: `feat(extensions): one management policy, enforced on the admin and the framework CLI`.

---

## Task 6: the activation runner: boot boundary, fresh-boot gate, fenced grants, all-or-nothing finalization

**Files:**
- Create: `core/src/Capabilities/Activation/{ActivationRunner,ActivationOutcome,EngineActivation}.php`
- Modify: `CoreServiceProvider` (shared, autowired)
- Test: `tests/Integration/Capabilities/ActivationRunnerTest.php` (`use ChildProcesses`), `tests/fixtures/engine_prepare_child.php`, `tests/fixtures/activation_vs_provision_child.php`

**Steps** (each takes the lease with `acquire()`, records with `completeStep`/`failStep`, and catches `ActivationSuperseded` to rethrow):

1. **`ENABLE_ENGINE`:**
   - **Skipped** (recorded done, `result.engine = 'already'`) when `hasProvider($provider)` and the engine's schema is ready.
   - **Read-only:** if application files aren't writable and the engine isn't enabled → `failStep` with the remedy `php glueful thallo:features:enable <id> --prepare` (at deploy time).
   - **Otherwise** `EngineActivation::prepare()`. A failure of `writeCacheNow` means done with `result.cache_stale = true`.
   - **Then return `needsBoot = true`, always**, even when `$freshBoot` is true.
2. **`VERIFY_BOOT`:** only when `$freshBoot` and this invocation didn't run `ENABLE_ENGINE`. It requires `hasProvider($provider)` and `availability($id)->available`.
   - **Failure:** `failStep(VERIFY_BOOT, reason, 'php glueful extensions:cache')`. The capability stays off.
   - **Success:** clears `result.cache_stale`.
3. **`SEED_BLOCKS`:** `seedAll($id, $lease, $failedOnly)`; record `result.blocks_created` (the total). It fails if any workspace failed.
4. **`GRANT_PERMISSIONS`:** `withinFenced($lease, fn () => $grants->apply())`, holding the row lock through the grant transaction. Record `result.grants`.
5. **`FINALIZE`:** `withinFenced($lease, function () { … })`. Inside that one transaction:
   1. list active workspaces and seed missing contributions (savepointed);
   2. `crashProbe('before_commit')`;
   3. `CapabilityStateStore::put($id, true)`, which advances the version;
   4. set status `succeeded` and record `FINALIZE` done.

   After the commit: `crashProbe('after_commit')`, then release.

- [ ] **Step 1: Write the failing tests**

```php
public function testTheEngineStepAlwaysStopsAtTheBootBoundary(): void
{
    $gen = $this->store()->startOrJoin('thallo.commerce', 't')->generation;
    $out = $this->runner()->run('thallo.commerce', $gen, freshBoot: true);   // even "fresh"
    self::assertTrue($out->needsBoot);
    self::assertSame(ActivationStep::VERIFY_BOOT, $out->record->nextStep());
    self::assertFalse($this->states()->fresh('thallo.commerce'));
}

public function testARetriedEngineStepNeedsAnotherBoot(): void
{
    $this->failEngineStepOnce();                                  // ENABLE_ENGINE fails (seam)
    $gen = $this->startAndRun('thallo.commerce');
    $out = $this->freshBootRunner()->run('thallo.commerce', $gen, freshBoot: true); // retries the engine
    self::assertTrue($out->needsBoot, 'verification waits for a context booted after the retry');
    self::assertNotContains(ActivationStep::VERIFY_BOOT, $out->record->stepsDone);
}

public function testTheStepsCompleteAcrossABootAndTheCapabilityIsEffectiveOnlyAtTheEnd(): void
{
    $gen = $this->startAndRun('thallo.commerce');
    $out = $this->freshBootRunner()->run('thallo.commerce', $gen, freshBoot: true);
    self::assertSame(ActivationStatus::SUCCEEDED, $out->record->status);
    self::assertTrue($this->states()->fresh('thallo.commerce'));
    self::assertGreaterThan(0, $out->record->result['blocks_created']);
}

public function testACrashBeforeTheFinalizationCommitLeavesItOff(): void
{
    ActivationRunner::$crashProbe = static function (string $at): void {
        if ($at === 'before_commit') { throw new \RuntimeException('killed'); }
    };
    $version = $this->version()->current();
    $this->runToFinalize('thallo.commerce');
    self::assertFalse($this->states()->fresh('thallo.commerce'));
    self::assertSame($version, $this->version()->current());
    self::assertTrue($this->store()->find('thallo.commerce')->isOpen());
}

public function testALostResponseAfterTheCommitDoesNotUndoTheActivation(): void
{
    ActivationRunner::$crashProbe = static function (string $at): void {
        if ($at === 'after_commit') { throw new \RuntimeException('lost'); }
    };
    $this->runToFinalize('thallo.commerce');
    ActivationRunner::$crashProbe = null;
    self::assertTrue($this->states()->fresh('thallo.commerce'));
    self::assertSame(ActivationStatus::SUCCEEDED, $this->store()->find('thallo.commerce')->status);
}

public function testActivationGrantsAndAConcurrentProvisionKeepEachOthersEntries(): void
{
    // child A runs an activation through GRANT_PERMISSIONS after adding race.act; child B runs a
    // provision apply() after adding race.prov; both at once
    $this->runChildren('activation_vs_provision_child.php', [['activation', 'race.act'], ['provision', 'race.prov']]);
    $ledger = $this->ledger();
    self::assertContains('race.act', $ledger['superuser']);
    self::assertContains('race.prov', $ledger['superuser']);
}

public function testATakenOverRunnerGrantsNothing(): void
{
    // child pauses BEFORE entering the grant step's withinFenced; lease expires; a new owner completes
    // the grants; the operator revokes commerce.view; child resumes: its fence fails, nothing re-granted
    $child = $this->startChild('activation_paused_runner_child.php', ['thallo.commerce', '--pause-before=grant_permissions']);
    $child->waitFor('paused');
    $this->expireLease('thallo.commerce');
    $this->completeGrantStepAsNewOwner('thallo.commerce');
    $this->revoke('administrator', 'commerce.view');
    $child->signal('resume');
    self::assertStringContainsString('superseded', $child->finish());
    self::assertNotContains('commerce.view', $this->roleSlugs('administrator'));
}

public function testTwoEnginesPreparedAtOnceBothSurviveInTheEnabledList(): void
{
    $config = $this->tempExtensionsConfig();
    $this->runChildren('engine_prepare_child.php', [['glueful/commerce', $config], ['glueful/subscriptions', $config]]);
    $enabled = (require $config)['enabled'];
    self::assertContains('Glueful\\Extensions\\Commerce\\CommerceServiceProvider', $enabled);
    self::assertContains('Glueful\\Extensions\\Subscriptions\\SubscriptionsServiceProvider', $enabled);
}
```

Plus four more, written in the same shape:
- **`testTheFreshBootGateRefusesAMissingProvider`:** a fresh boot whose extension cache lacks the provider. Assert: `VERIFY_BOOT` failed with the `extensions:cache` remedy, and the capability is off.
- **`testCacheStaleStaysOffUntilTheGatePasses`:** the `writeCacheNow` seam throws, giving `result.cache_stale`. A continue fails the gate. Rebuild the cache; the next continue passes, on the same generation.
- **`testAReadOnlyHostRefusesEnablingAnEngineUpFront`:** the writable seam returns false and the engine isn't enabled. Assert: `failStep(ENABLE_ENGINE)` with the `--prepare` remedy.
- **`testAPreparedEngineActivatesOnAReadOnlyHost`:** the engine is already enabled and the writable seam returns false. Assert: it completes.

**The engine seams:** `EngineActivation`'s constructor takes `?string $configPath`, `?\Closure $writable` and `?\Closure $writeCache`; production passes nulls. The two-engine child calls `EngineActivation::prepare()` against the shared temp config path. Without `ExtensionStateLock`, that test loses one provider.

- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement**, following the step list. `EngineActivation::prepare()` runs `migrateProtected`, then `ExtensionStateLock::within(fn () => writer->enable + clearConfigCache + writeCacheNow)`.
- [ ] **Step 4: Run it.** `tests/Integration/Capabilities`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(capabilities): the activation runner, with a boot boundary, a fresh-boot gate and an all-or-nothing finalization`.

---

## Task 7: the activation API

**Files:**
- Create: `core/src/Http/Controllers/CapabilityActivationController.php`
- Modify:
  - `core/routes/admin.php`, all `content_permission:system.access`:
    - `POST /capabilities/{id}/activation` → `start`
    - `POST /capabilities/{id}/activation/continue` → `continue`, body `{generation}`
    - `DELETE /capabilities/{id}/activation` → `cancel`, body `{generation}`
  - `core/src/Http/Controllers/CapabilityAdminController.php`:
    - `manage()` adds per capability `management` (`activation` | `simple` | `workspaces`), `activation` (`toArray()` or null), `application_files_writable` and `engine_enabled`;
    - `update()` with `enabled: true` on an activation capability → 409 `{reason: 'use_activation'}`;
    - `update()` with `enabled: false` → `ActivationStore::supersede()`. That's the only off path for activation capabilities; it publishes off atomically.
  - `docs/openapi.json` (`CACHE_DRIVER=array composer docs:openapi`; hand-splice if it dies on Redis), then `cd admin && pnpm gen:api`.
- Test: `tests/Integration/Http/CapabilityActivationApiTest.php`

**Responses:**
- `start` → 202 `{activation, continue}`, with `continue = outcome.needsBoot`;
- `continue` → 200 `{activation, continue}`, or 409 `{reason: 'superseded'}`, or 409 `{reason: 'in_progress'}`;
- `cancel` → 200 `{activation}`.

- [ ] **Step 1: Write the failing tests** (status code and body via `$this->handle($this->jsonRequest(...))`, as the existing `tests/Integration/Http` tests do):
  - `testStartAsksForAContinue`;
  - `testContinueFinishesInAFreshRequest`, where the continue goes through `bootAppWithConfigOverride`;
  - `testContinueAfterARetriedEngineStepAsksForAnotherContinue`;
  - `testAContinueForASupersededGenerationIsRefused`;
  - `testADelayedCancelOfAnOldGenerationLeavesTheCurrentOneOn`: generation 1 is cancelled-to-be; it's superseded, generation 3 completes on, then the delayed `DELETE …/activation {generation: 1}` arrives → 409, and the feature is still on with generation 3;
  - `testTwoStartsReturnTheSameGeneration`;
  - `testTheUpdateEndpointCannotTurnAnActivationCapabilityOnDirectly`;
  - `testTurningOffPublishesOffAtomicallyAndAdvancesTheVersion`;
  - `testAnEngineDisabledOutsideThalloShowsUnavailable`.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement.** `start` calls `startOrJoin`, then `run(freshBoot: false)`. `continue` calls `run(freshBoot: true)`; the runner itself enforces the boundary. `cancel` calls `supersede($id, $actor, expectedGeneration: $body['generation'])`, and a stale generation → 409 `superseded`, changing nothing. Turning off the current feature (`PUT /capabilities/{id}` with `enabled: false`) calls `supersede($id, $actor)` with no expected generation. `ActivationSuperseded` → 409 `superseded`; a null `acquire` → 409 `in_progress`.
- [ ] **Step 4: Run it**, plus the OpenAPI regeneration and `pnpm type-check`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(capabilities): the activation API`.

---

## Task 8: the feature-owned CLI, and provision's part

**Files:**
- Create: `core/src/Capabilities/Console/{FeaturesEnableCommand,FeaturesResumeCommand,FeaturesStatusCommand}.php`, following `CapabilitiesCommand`'s conventions (`#[AsCommand]`, `BaseCommand`, `getService`, `Table`)
- Modify: `core/src/Capabilities/Console/CapabilitiesCommand.php` (`flip()` :84). For an activation capability (`FeatureManagementPolicy::activationCapabilities()`):
  - **enable is refused** with exit 1 and "Turn Commerce on with `php glueful thallo:features:enable thallo.commerce`: it prepares the store first.";
  - **disable goes through `ActivationStore::supersede($id, 'cli')`**, which publishes off and fences any outstanding runner.

  Other capabilities keep `CapabilityStateStore::put()`.
- Modify:
  - `CoreServiceProvider`: DI definitions and the `commands([...])` list at :2956;
  - `core/src/Setup/Console/ProvisionCommand.php`:
    - after role grants (:157), resume every open activation by **running `thallo:features:resume` as a child process**, so provision's own process never verifies a boot it changed;
    - after the extension cache step (:207), re-enable disabled required providers under `ExtensionStateLock` (writer, then `writeCacheNow`) when writable, each with a line of output.
- Test: `tests/Integration/Console/FeaturesCommandsTest.php`

**Behaviour:**
- **`features:enable {capability} {--prepare}`:** `startOrJoin`, then `run(freshBoot: false)`. While `outcome.needsBoot` and not `--prepare`, it starts `PHP_BINARY base_path('glueful') thallo:features:resume <id>` as a fresh child, streams its output and loops on the child's result, up to 3 hops. `--prepare` stops at the first `needsBoot` and prints "Prepared. Finish on the running site: Features, or `php glueful thallo:features:resume <id>`."
- **`features:resume {capability?}`:** `run(freshBoot: true)` per open activation. When `needsBoot` (it retried the engine step), it starts another fresh child, up to 3 hops.
- **`features:status`:** a table of capability, state, step, error and remedy.

- [ ] **Step 1: Write the failing tests:**
  - `testEnableRunsToTheEndThroughAFreshProcess`;
  - `testResumeAfterAnEngineFailureRetriesInOneProcessAndVerifiesInAnother`;
  - `testPrepareStopsBeforeTheRuntimeSteps`;
  - `testResumeFinishesWithApplicationFilesReadOnly`;
  - `testExtensionsEnableOnAManagedEngineNamesThisCommand`;
  - `testProvisionResumesAnOpenActivationInAChildProcess`;
  - `testProvisionReEnablesADisabledRequiredProvider`;
  - `testCapabilitiesCommandCannotEnableAnActivationCapabilityWithoutPreparation`: `thallo:capabilities --enable=thallo.commerce` exits 1, and the capability is still off with no activation started;
  - `testCapabilitiesCommandDisableFencesAnOutstandingRunner`: a runner holds a lease mid-activation; `--disable=thallo.commerce`; the runner's next fenced write throws `ActivationSuperseded`, and the capability stays off.

  For read-only application files, pass `THALLO_TEST_APP_FILES_READONLY=1`, which `EngineActivation` reads only when `APP_ENV=testing`.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run it**, plus `tests/Integration/Setup`. Expected: PASS.
- [ ] **Step 5: Commit**, with an Added bullet: "**`thallo:features:enable`, `resume` and `status`.** Turn a feature on from the terminal, prepare it at deploy time with `--prepare` and finish on the running site, and see where every feature stands." Commit message: `feat(capabilities): a feature-owned CLI, and provision resumes activations and repairs required providers`.

---

## Task 9: Browse and the web installer are removed

**Files:**
- Delete: `admin/src/pages/extensions/components/BrowseExtensions.vue`
- Modify:
  - `admin/src/pages/extensions/index.vue`: **remove the Browse import and its tab, in this commit**;
  - `admin/src/queries/extensions.ts`: remove the catalog and installer exports;
  - `admin/src/queries/extensions.spec.ts`: drop the install tests;
  - `core/src/Http/Controllers/ExtensionAdminController.php`: remove `registry()`, `install()`, `PACKAGIST_SEARCH` and the unused imports;
  - `core/routes/admin.php`: remove the two routes;
  - `docs/openapi.json` and `admin/src/api/schema.d.ts`, regenerated;
  - `docs/concepts/06-capabilities.md:121-122`;
  - `docs/reference/02-configuration.md:307` and `.env.example:216-219` (the installer variables stay, since the framework reads them; say so).
- Test: the extension admin API test: both routes 404.

- [ ] **Step 1:** Write the failing route test (`GET /v1/admin/extensions/registry` and `POST /v1/admin/extensions/install` → 404).
- [ ] **Step 2:** Run it. Expected: FAIL.
- [ ] **Step 3:** Remove all of the above. Then `grep -rn "extensions/registry\|useExtensionInstall\|CatalogExtension\|BrowseExtensions\|extensions/install" admin/src core/src core/routes docs` must find only changelog history.
- [ ] **Step 4:** Run the PHP test, vitest, `pnpm type-check`, `pnpm lint` and `pnpm build`. Expected: PASS.
- [ ] **Step 5: Commit**, with a Removed bullet: "**The Extensions page's Browse tab and the in-admin installer.** It listed framework packages, not Thallo features, and offered switches without the checks the rest of the page uses." Commit message: `refactor(extensions): remove Browse and the web installer`.

---

## Task 10: the Features page

**Files:**
- Create:
  - `admin/src/pages/features/index.vue`: heading **Features**; views **Features** (default) and **Installed packages**;
  - `admin/src/pages/features/components/{FeatureCard,ActivationProgress,InstalledPackages}.vue`. `InstalledPackages` moves from `InstalledExtensions.vue` and adds the management line; no raw switch for required or managed packages;
  - `admin/src/queries/capabilityActivation.ts`: `startActivation(id)`, `continueActivation(id, generation)`, `cancelActivation(id, generation)`, `useActivationFlow(id)`.
- Modify:
  - `admin/src/pages/extensions/index.vue`: a redirect (`router.replace('/features')` in `onMounted`);
  - `admin/src/registry/coreModule.ts:28-32`: `{ label: 'Features', icon: 'i-lucide-toggle-right', to: '/features' }`;
  - `admin/src/queries/capabilityManagement.ts`: the type gains `management`, `activation`, `application_files_writable` and `engine_enabled`.
- Delete: `admin/src/pages/extensions/components/{CapabilityManagement,InstalledExtensions}.vue`
- Test: `admin/src/__tests__/features-page.spec.ts`, `admin/e2e/tests/features-activation.spec.ts` (+ fixtures)

**Card states** (data-test `feature-<id>`):

| State | What the card shows |
|---|---|
| off | A switch |
| confirm | `UModal` (data-test `feature-confirm`) with the copy from the Global Constraints |
| preparing | "Turning on Commerce…" (data-test `feature-preparing`). It calls `continueActivation` while the response says `continue: true`, which may happen twice after an engine retry, and polls `manage` every 1.5 s while open |
| failed | The step message, **Retry** (data-test `feature-retry`), and **Cancel** |
| on | The summary from `activation.result` |
| read-only | When `!application_files_writable && !engine_enabled`, the `--prepare` command; no button |
| workspaces | A link to Settings › Workspaces |
| open operation on load | **Continue** and **Cancel** |

**After completion:** invalidate `['capabilities']`, `['me']` and `['extensions']`, then `refreshUntilChanged()`.

- [ ] **Step 1: Write the failing vitest cases:**
  - `confirm then turn on calls start then continue until continue is false`;
  - `a failed step shows its message and Retry continues the same generation`;
  - `superseded continue shows off`;
  - `read-only host offers the prepare command, no button`;
  - `an open operation on load offers Continue`;
  - `the summary reads the result count`;
  - `required package shows Required by Thallo and no switch`;
  - `/extensions redirects to /features`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them**, plus e2e (a fixture-backed Commerce activation: `start` → `{continue: true}`, `continue` → succeeded, with `manage` before and after) and the full admin gate set. Expected: PASS.
- [ ] **Step 5: Commit**, with a Changed bullet: "**Extensions is now Features.** Turn features on and off in one place. Commerce and Subscriptions turn on with one action that prepares everything, shows what was added, and can be retried if a step fails. Installed packages shows who manages each package." Commit message: `feat(admin): the Features page`.

---

## Task 11: route tables keyed by the capability-state snapshot (needs glueful/framework 1.88)

**Gate:** only after the user has published `glueful/framework` 1.88.0 (Task F1).

**Files:**
- Modify: `composer.json` and `core/composer.json` (`"glueful/framework": "^1.88"`), then `composer update glueful/framework`
- Modify: `core/src/Providers/CoreServiceProvider.php` (`register()`, right after taking the snapshot): `$context->setRouteSignatureInput('thallo.capability_state', $snapshot->version);`
- Modify: `core/src/Http/Controllers/ExtensionAdminController.php` (`toggle()`): **the lock handover.** Remove Task 5's interim `ExtensionStateLock` wrapper around the executor call; on 1.88 the executor owns its locking (migration locks, then the extension-state mutex). `ExtensionStateLock` stays around Thallo's **direct** writer-and-cache sequences, which take no migration locks while holding it:
  - `EngineActivation`, where `migrateProtected` runs and releases its locks before the mutex;
  - provision's required-provider repair;
  - tenancy's `ExtensionActivation::activate()` and `deactivate()`.
- Test: `tests/Integration/Routing/CapabilityRouteTableTest.php`

- [ ] **Step 1: Write the failing tests:**
  - `testTurningCommerceOnServesItsRoutesOnTheNextContext`: compile with Commerce off; activate through the runner; a fresh boot (`ROUTE_CACHE` on) matches the commerce admin route;
  - `testTurningOffRemovesAccessOnTheNextContext`;
  - `testAContextThatBootedBeforeTheSwitchCannotMakeItsRebuiltTableUsable`: context A boots under N with no cache file (cold); the switch advances to N+1; A saves; context B under N+1 rejects A's table;
  - `testAFailureMidFinalizationKeepsTheOldRoutesAndItStaysOff`: the crash probe fires `before_commit`;
  - `testAnAdminEnableAndACliEnableInTheConflictingOrderBothFinish` (`tests/Integration/Console`, with framework 1.88): the package is `glueful/payvia`, which declares migrations and backs no capability; if the Task 5 audit classified it as required, use the first independent package with migrations from the audit's table. The test forces the conflicting order with two pause points:
    1. **The CLI child** runs `extensions:enable <package>` with `ExtensionSchemaExecutor::$afterMigrationLocks` set to print `migration-locks-held` and block on stdin. It now holds the migration locks and not the mutex.
    2. **The admin child** runs `POST /v1/admin/extensions/enable {name}` in-process. A test-only hook in `ExtensionAdminController::toggle()`, `THALLO_TEST_PAUSE_BEFORE_EXECUTOR` (read only when `APP_ENV=testing`), sits immediately before the executor call and **after** any outer wrapper has taken its mutex. It prints `before-executor` and blocks. It now holds the mutex, if a wrapper exists, and not the migration locks.
    3. **The parent** waits for both markers, then resumes both.
    4. **Assert:** both children finish successfully within 30 s, the admin with a 200 and not a 409 `LockContentionException`, and the package is enabled once in `config/extensions.php` (a temp copy).

    With Task 5's interim wrapper still present, each child waits on the other, and the admin's migration-lock wait times out with a 409. That's the failing run. With the wrapper removed (the handover), the CLI takes the mutex, finishes, and the admin follows;
  - `testACommerceActivationAndAnIndependentPackageCliKeepBothProviders` (`tests/Integration/Console`, with framework 1.88): a child runs Commerce's `EngineActivation::prepare()` and pauses inside the lock; another child runs `php glueful extensions:enable glueful/meilisearch` (independent), which waits on the same mutex; the first resumes. Assert that both providers are in `config/extensions.php` (a temp copy).
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** the one call.
- [ ] **Step 4: Run them**, plus the full PHP gate set. Expected: PASS.
- [ ] **Step 5: Commit**, with a Fixed bullet: "**Turning a feature off removes its pages at once.** A compiled route table built before the switch is never served after it." Commit message: `feat(capabilities): a route table compiled under an older capability state is never served`.

---

## Task 12: docs, changelog, and an end-to-end proof

**Files:**
- Modify:
  - `docs/concepts/06-capabilities.md`;
  - `docs/getting-started/01-introduction.md:58-68`, `02-install.md:157`;
  - every "Extensions › Capabilities" mention (`documentation-sites.md:29`, `guides/17-accounts.md:22`, `18-commerce.md:36`, `19-subscriptions.md:31`, `02-header-and-footer.md:112`, `11-search.md:25`, `10-seo.md:14`, `12-import-content.md:15`, `concepts/03-design-view.md:28`, `reference/01-cli.md:23,802`) becomes "Features";
  - `docs/reference/01-cli.md`: the three commands;
  - `docs/operations/05-troubleshooting.md`: each failure step, Retry, read-only hosts, `--prepare`;
  - `scripts/skeleton-smoke`: after provision and create-admin, run `php glueful thallo:features:enable thallo.commerce` (exit 0), then `thallo:features:status` showing `thallo.commerce` on.
- Test: `composer test:skeleton`; `tests/Unit/Docs`.

- [ ] **Step 1:** Write the docs, and add the smoke assertions.
- [ ] **Step 2:** Run `composer test:skeleton`. Expected: PASS.
- [ ] **Step 3: The production repro.** On a kept skeleton install in production mode:
  1. Activate Commerce through `start` and `continue` boots.
  2. Check that `product-grid` exists.
  3. Check that the capability is on.
  4. Check that a pre-existing `routes_prod.php` was rejected by the next context.

  Run no suite in between: they share `app_test`.
- [ ] **Step 4: Commit.** `docs(features): the Features page, activation, and the feature CLI`.

---

## Final

- Run the full gates.
- Review the whole branch with one fresh reviewer on the most capable model, against this plan and the spec.
- Collect the rulings and deferred minors into the final message.
- No beta cut until asked. The framework release (F1) is the user's.

## Self-review

- **Spec coverage:**
  - §3.1 → Task 10. §3.2 → Tasks 6 and 11. §3.3 → Tasks 2, 6, 7 and 8. §3.4 → Task 4.
  - §3.5 → Tasks 3 and 6. §3.6 → Tasks 2, 7 and 11. §3.7 → Task 5. §3.8 → Task 9.
  - §3.10 → Task 8. §4 → each task. §5 → Task 12.
- **The review's seven blockers:**
  1. F1 inputs are eager, context-scoped values from the registry's snapshot; cold-cache and consecutive-context tests; the narrow fallback (Task 1).
  2. Atomic transitions publish state under the row lock, and the paused-old-runner test (Task 2).
  3. One lock order; workspace creation shares and never writes; a two-process test with the real seed and the real finalize step (Task 4).
  4. Grants inside `withinFenced`; paused-runner and different-entry concurrency tests (Tasks 3 and 6).
  5. `ExtensionStateLock` across Thallo writers, and the two-engine test (Tasks 5 and 6).
  6. Savepointed inserts, constraint-specific, with a later write committed after the conflict (Task 4).
  7. The boot boundary after every engine step, with HTTP and CLI retry tests (Tasks 6, 7 and 8).

  Order fixes: the policy (Task 5) precedes the runner (Task 6); Browse's import goes with its file (Task 9).
- **Placeholders:** Task 6's last four tests are stated as arrange, act and assert; the executor writes them in the shape of those shown. Task 5's audit decides five packages, and the tests pin the result.
- **Types:**
  - `ActivationRunner::run` returns `ActivationOutcome` (Tasks 6, 7 and 8).
  - `ActivationStore::withinFenced` (Tasks 2, 4 and 6).
  - `CapabilityStateStore::fresh` (Tasks 1, 2 and 4).
  - `CapabilityStateSnapshot::version` (Tasks 1 and 11).
  - `FeatureManagementPolicy::activationCapabilities()`, sorted (Tasks 4 and 5).
  - `ExtensionStateCoordinator` (Task 5) is implemented by `ExtensionStateLock`.
  - `ChildProcesses` (Task 2) is used by Tasks 3, 4 and 6.
- **Second review:**
  - every enabled-list mutation, the framework's included, takes one lock (F2, plus Task 5's key and Task 11's mixed proof);
  - `thallo:capabilities` routes or refuses activation capabilities (Task 8);
  - workspace seeding takes its share locks before any block write, with a repair-versus-finalization race test (Task 4);
  - cancel checks its generation inside the row lock, with a delayed-cancel test (Tasks 2 and 7).
- **Release gate:** F1 and F2 ship together as glueful/framework 1.88.0. Task 11 requires it.

---

# Amendment: spec §7 (Extensions, declared management, Payments)

**Spec:** §7 of `docs/internal/superpowers/specs/2026-10-02-feature-activation-design.md` (approved at
67141412). Tasks 1–10 and 12 shipped as written (commits ac86744f..0831164c). Task 11 stays gated on
glueful/framework 1.88.0 and is unchanged except for its CLI and page names (A1). The tasks below
amend the shipped code; where they disagree with Tasks 1–12, they win.

**Execution:** inline, as before. Global Constraints and the Review Focus above still apply; the
additions below join them.

## Amendment Global Constraints

- Nothing in core names a capability, package or provider class to give it a management mode. The
  one exception is the upgrade adoption step (A5), whose candidate list is historical data for the capabilities
  that existed before this release.
- `thallo-contracts` changes are additive: existing `new Capability(...)` calls keep compiling.
- New tests go in existing `tests/Integration/*` directories (Capabilities, Setup, Tenancy, Http,
  Console, Commerce, Subscriptions, Payments). No new shard entries.
- The test-only fixture package lives at `tests/fixtures/packages/acme-bookings` and is installed by
  the dev root's `composer.json` (`repositories` path + `require-dev`), so it sits in `vendor/` and the
  package manifest like a real third-party extension. It never ships: the skeleton doesn't require it.

## Amendment Review Focus

1. **An upgraded install whose Commerce was on by following its engine.** It is still on after the
   upgrade, with no visible change; one that the configuration switched off stays off. Pinned in A5.
2. **A third-party extension installed with its engine already enabled.** Its capability reads off,
   with no blocks or grants, until its activation finalizes. Pinned in A4 and A8.
3. **Two packages claiming the same engine.** Neither registration order nor a raw package toggle gets
   around the refusal; an already-enabled engine keeps running. Pinned in A3.
4. **Payments turned off with payments in flight.** A payment started before the switch still
   settles, a refund still works, and a renewal webhook still settles. Pinned in A7.
5. **The first turn-on of a capability that has no activation row, while a workspace is being
   created.** The workspace either finishes before the row exists or sees it. Pinned in A6.

## Amendment Interfaces

```php
// packages/thallo-contracts/src/Capability/ManagementMode.php
enum ManagementMode: string { case Simple = 'simple'; case Activation = 'activation'; case ExternalFlow = 'external_flow'; }

// packages/thallo-contracts/src/Capability/ExternalFlowDestination.php
final class ExternalFlowDestination { public function __construct(public readonly string $path, public readonly string $label) {} }

// packages/thallo-contracts/src/Capability/ActivationCopy.php
final class ActivationCopy {
    /** @param list<array{label:string, to:string}> $links */
    public function __construct(public readonly ?string $turnOn = null, public readonly ?string $turnOff = null, public readonly array $links = []) {}
}

// packages/thallo-contracts/src/Capability/Capability.php — three new trailing, defaulted params
public function __construct(string $id, array $requires = [], ?string $label = null, ?string $description = null,
    ?string $owningPackage = null, ManagementMode $management = ManagementMode::Simple,
    ?ExternalFlowDestination $destination = null, ?ActivationCopy $copy = null);
// activation requires owningPackage; external_flow requires destination (constructor throws otherwise)

// packages/thallo-contracts/src/Capability/DeclaresCapabilities.php — an always-loaded pack's provider implements it
interface DeclaresCapabilities { /** @return list<Capability> */ public function capabilities(): array; } // pure: no container, no DB

// packages/thallo-contracts/src/Payments/OnlinePaymentInitiation.php
interface OnlinePaymentInitiation { public function allowed(): bool; public function refusal(): ?string; }

// core/src/Capabilities/Declarations/CapabilityDeclaration.php — one declaration from one source
final class CapabilityDeclaration { public function __construct(public readonly Capability $capability, public readonly string $source) {} } // source: 'registry' | 'package:<name>'

// core/src/Capabilities/Declarations/DeclarationSet.php — validated as a whole, order-independent
final class DeclarationSet {
    /** @return array<string, Capability> valid declarations by id */ public function valid(): array;
    /** @return array<string, array{reason:string, packages:list<string>}> misconfigured by id */ public function misconfigured(): array;
    public function modeOf(string $id): ManagementMode;            // Simple for unknown ids
    public function engineOf(string $id): ?array;                  // {package, provider} for a valid activation capability; provider from the manifest
    /** @return array<string, array{class:string, capability:?string, reason:?string, link:?string}> */ public function packageManagement(): array; // managed / misconfigured packages
}

// core/src/Capabilities/Declarations/PackageCapabilityDeclarations.php — reads extra.thallo.capabilities from the manifest, enabled or not
final class PackageCapabilityDeclarations { /** @return list<CapabilityDeclaration> */ public function all(): array; }

// core/src/Capabilities/RequiredPackages.php — created in A2 (core minimum); A4 adds the configured additions
final class RequiredPackages {
    public const CORE = ['glueful/aegis', 'glueful/users']; // beside the code that needs them (InstallRoleGrants, SetupService)
    /** @return array<string, class-string> package => provider (from the manifest); core ∪ config('thallo.required_packages') after A4 */ public function all(): array;
}

// core/src/Capabilities/FeatureManagementPolicy.php — becomes a thin reader over DeclarationSet + RequiredPackages
//   activationCapabilities(), engineOf(), managementOf(), capabilityManagement(), labelOf(), copyOf(), destinationOf(), protectedProviders()
//   (no ENGINES / REQUIRED_PACKAGES / TENANCY constants)

// core/src/Capabilities/Activation/ActivationStore.php — changes (A6)
public const WORKSPACE_SEED_LOCK = 'thallo:workspace-seed';
/** The ONE row initializer. Throws \LogicException when called inside an open transaction (the row must
 *  commit on its own, before anything uses it); otherwise its own transaction: exclusive workspace-seed
 *  advisory lock, then INSERT idle ON CONFLICT DO NOTHING. */
public function initializeRow(string $capability): void;
// lockRow() no longer inserts: a missing row throws ActivationRowMissing (every caller initializes first).
// supersede(): one transaction that first takes the workspace-seed lock SHARED (so initializeRow, which takes
// it exclusively, can't run until this commits), then: row exists → the normal row-locked supersession;
// no row → publish off and return 0. Lock order unchanged: workspace-seed lock → activation rows → …
// shareAll() is unchanged; CapabilityBlockSeeder::withinWorkspaceSeed() takes pg_advisory_xact_lock_shared(hashtext(WORKSPACE_SEED_LOCK)) first

// core/src/Capabilities/Activation/ActivationRowMissing.php
final class ActivationRowMissing extends \RuntimeException {}

// core/src/Setup/CapabilityAdoption.php — the one-time upgrade adoption (A5), run by provision
final class CapabilityAdoption { /** @return list<string> adopted ids */ public function run(): array; }
```

---

## Task A1: the page is Extensions, the CLI is `thallo:capabilities:*`

**Files:**
- Move `admin/src/pages/features/` → `admin/src/pages/extensions/` (index + components + `featureCopy.ts` deleted in A3, kept here); the redirect file goes; `admin/src/registry/coreModule.ts` → `{ label: 'Extensions', icon: 'i-lucide-blocks', to: '/extensions' }`; views **Capabilities** (default) and **Installed**; data-test ids `capability-<id>` (was `feature-<id>`).
- Rename `core/src/Capabilities/Console/Features{Enable,Resume,Status}Command.php` → `Capabilities{Enable,Resume,Status}Command.php`, names `thallo:capabilities:enable|resume|status`; `ContinuesActivations` and `FeatureProvisioning` follow; every message naming `thallo:features:*`, every "Features" string in PHP (`FeatureManagementPolicy` reasons, `link => '/features'`), the admin (the Subscriptions notice, the three "in Features" hints), `scripts/skeleton-smoke`, docs, READMEs and the unreleased CHANGELOG bullets.
- Tests: rename `features-page.spec.ts` → `extensions-page.spec.ts`, `featuresSimpleCapabilities.spec.ts` → `extensionsSimpleCapabilities.spec.ts`, `FeaturesCommandsTest` → `CapabilitiesCommandsTest`, the e2e spec → `extensions-activation.spec.ts`; drop the `/extensions → /features` redirect test and e2e case; add `the Extensions page opens on Capabilities`.

- [ ] **Step 1:** Change the tests' names and expectations first (paths, command names, copy); run vitest + the PHP CLI/API tests. Expected: FAIL.
- [ ] **Step 2:** Rename and rewrite. `grep -rn "thallo:features\|/features\|Features page\|in Features\|feature-\b" core packages admin/src admin/e2e/tests docs README.md scripts` finds only changelog history of released versions and `docs/internal`.
- [ ] **Step 3:** PHP tests (Console, Http, Capabilities, Setup), vitest, type-check, lint, fmt:check, e2e. Expected: PASS.
- [ ] **Step 4: Commit.** CHANGELOG: the unreleased "Extensions is now Features" bullet becomes "**Extensions turns features on in one place.** …" and the CLI bullet names `thallo:capabilities:enable`, `resume` and `status`. `refactor(admin): the page is Extensions, the CLI is thallo:capabilities:*`.

## Task A2: management is declared on the capability (contracts, both sources, the set)

**Files:**
- Create the contract types above in `packages/thallo-contracts/src/Capability/`; extend `Capability`.
- Create `core/src/Capabilities/Declarations/{CapabilityDeclaration,DeclarationSet,PackageCapabilityDeclarations,DeclarationCollector}.php` and the `DeclaresCapabilities` contract.
- **The collection-and-validation boundary is before any provider's `boot()`.** The framework runs every provider's `register()` before any `boot()` (`ExtensionManager::registerProviders()` then `boot()`), and packs consult capabilities in `boot()` (Commerce: `isEnabled('thallo.commerce')` for routes and listeners). So:
  - packs **declare** in the provider, not in `boot()`: every pack that today calls `$registry->register(new Capability(…))` in `boot()` implements `DeclaresCapabilities` instead (all twelve: Account, Analytics, Collections, Commerce, Importers, Navigation, Render, Search, SEO, Subscriptions, Tenancy, Workflow — `grep -rln "new Capability(" packages/*/src core/src` lists exactly these — and core for Payments in A7). Registries a test constructs by hand are never sealed;
  - `DeclarationCollector` builds the `DeclarationSet` once, from `ExtensionManager::getProviders()` (`DeclaresCapabilities` implementers, in provider order) plus `PackageCapabilityDeclarations` (manifest `extra.thallo.capabilities`, read through `PackageManifest` for installed packages whether enabled or not), validated as a whole. It is built by the `CapabilityRegistry` factory, i.e. at the first capability decision, which is always inside some provider's `boot()` — after every `register()`;
  - the set is then **sealed**: `CapabilityRegistry::register()` after that accepts an identical re-declaration as a no-op, and refuses anything else (`\LogicException` outside production; logged and ignored in production, never applied), so no declaration can arrive after capability-dependent work has started;
  - **protection is derived at the same boundary**: when the set is built, the engines of its `activation` and `external_flow` declarations (and the packages of misconfigured ones, A3) are merged into `extensions.protected` defaults, before any refusal path can read them.
- Move the first-party declarations into their packs: Commerce and Subscriptions register `ManagementMode::Activation` with their copy (from `featureCopy.ts`); tenancy registers `ManagementMode::ExternalFlow` → `new ExternalFlowDestination('/settings/workspaces', 'Settings › Workspaces')`.
- Create `RequiredPackages` with the core minimum (A4 adds configured additions), and rewrite `FeatureManagementPolicy` over `DeclarationSet` + `RequiredPackages` (no constants). `protectedProviders()` is computed from the sealed set (above); `CoreServiceProvider::register()` no longer merges a fixed list.
- `CapabilityAdminController::manage()` reports `management`, `destination`, `copy` from the set; the admin card reads them (delete `featureCopy.ts`).
- Test: `tests/Integration/Capabilities/CapabilityDeclarationsTest.php`, `admin/src/__tests__/extensions-page.spec.ts` (copy from the capability).

**Tests:**
- `testFirstPartyModesComeFromTheirPacks` (Commerce/Subscriptions activation with their engine package and manifest-resolved provider; tenancy external_flow with its destination; Search simple).
- `testAPackageMetadataDeclarationAppearsWhileItsPackageIsDisabled` (a manifest entry in a temp manifest seam).
- `testDeclarationsAreCompleteBeforeAnyProviderBoots` (two fixture providers in `tests/Support/Capabilities/`: `EarlyConsumerProvider` declares `test.early` and, in `boot()`, records `isEnabled('test.early')` and registers a route only when on; `LateDeclarerProvider` declares `test.late`; boot an app with both in each order via `bootAppWithConfigOverride('serviceproviders', …)`: the early provider's recorded decision sees both declarations in both orders).
- `testTheRealBootDeclaresEveryFirstPartyCapability` (the test app's registry `all()` ids, sorted, equal exactly: thallo.accounts, thallo.analytics, thallo.collections, thallo.commerce, thallo.importers, thallo.navigation, thallo.render, thallo.search, thallo.seo, thallo.subscriptions, thallo.workflow, plus thallo.tenancy on a harness boot and thallo.payments after A7 — and no sealing refusal was logged).
- `testARegistrationAfterTheSetIsSealedIsRefused` (`register()` of a new id after the first decision throws outside production; an identical re-declaration is a no-op).
- `testAProviderIsResolvedFromTheManifestNotFromThallo` (the policy file has no `ServiceProvider` class strings: assert with a source scan).
- `testTheContractRejectsAnActivationWithoutAnOwnerAndAnExternalFlowWithoutADestination`.
- vitest: `the confirmation copy comes from the capability`, `an external flow links to its destination`.

- [ ] Steps: failing tests → implement → Capabilities, Http, Console, Tenancy (harness per class), Unit/Tenancy, lint, phpcs, boundaries → commit `feat(capabilities): management is declared by the package that contributes the capability`. No changelog bullet (internal; A1's bullet stands).

## Task A3: misconfiguration is rejected and blocks every path

**Files:** `DeclarationSet` validation; `DefaultCapabilityRegistry::resolveAvailability()` (misconfigured → unavailable "misconfigured: …"); `CapabilityActivationController`, `CapabilityAdminController::update()`, `CapabilitiesCommand::flip()`, `ActivationRunner::run()` (refuse a misconfigured id); `FeatureManagementPolicy::protectedProviders()` (packages of a conflict protected with the misconfigured reason); `DoctorCommand` (a `capability-declarations` check); the card's `misconfigured` state.
**Test:** `tests/Integration/Capabilities/MisconfiguredCapabilityTest.php`, a case in `CapabilitiesCommandsTest`, vitest `a misconfigured capability says why and offers nothing`.

**Tests:**
- `testEachAmbiguityIsRejected`: one package owned by two non-simple capabilities; one id declared twice with different owners or modes; an activation engine that is required; an activation engine that isn't installed.
- `testAConflictMakesACapabilityStoredOnIneffectiveInEitherRegistrationOrder` (store on; register A then B, and B then A, in two fresh registries; `isEnabled` false both times).
- `testAConflictRefusesTheCapabilityAndItsRoutesInEitherBootOrder` (real boots: `EarlyConsumerProvider` declares and consumes `test.contested` owned by `acme/one`; `ConflictingProvider` declares `test.contested` owned by `acme/two`; the state stored on; boot both orders via `bootAppWithConfigOverride('serviceproviders', …)`: in both, `isEnabled('test.contested')` is false, the early provider's gated route is **not** registered (`findRoute` null), and the capability reports misconfigured).
- `testEveryManagementPathRefusesAMisconfiguredCapability` (start, continue, `PUT /capabilities/{id}`, `thallo:capabilities --enable/--disable`, `thallo:capabilities:enable`).
- `testTheConflictingPackagesAreRefusedByTheGenericSwitches` (`extensions:enable/disable` in a real process with `--dry-run`, and the admin toggle: 409 with the misconfigured reason; never "independent").
- `testAnAlreadyEnabledEngineStaysLoaded` (hasProvider still true).
- `testDoctorReportsIt`.

- [ ] Steps: failing → implement → suites above → commit `feat(capabilities): a misconfigured declaration blocks the capability and its packages everywhere`.

## Task A4: required packages have a mandatory minimum

**Files:** `RequiredPackages` (created in A2 with the core minimum) gains the configured additions: `core/config/thallo.php` gets `'required_packages' => []` (additions only); the policy and `FeatureProvisioning::repairRequiredProviders()` read `all()`.
**Test:** `tests/Integration/Capabilities/RequiredPackagesTest.php`: `testConfigurationAddsARequiredPackage`, `testConfigurationCannotRemoveAegisOrUsers` (config set to `[]` and to a list without them), `testARequiredPackageIsRepairedByProvision` (existing, now over the union).

- [ ] Steps → commit `feat(extensions): required packages have a mandatory minimum`.

## Task A5: activation capabilities are off until finalized, with a one-time adoption

**Files:**
- `DefaultCapabilityRegistry::resolveRequested()`: for `ManagementMode::Activation`, requested = the **stored** row only (`CapabilityStateStore::storedFrom($rows, $id)`, the snapshot rows without the config fallback); no stored state → off; a config `false` still reads off while nothing is stored; config `true` never makes it requested. Simple capabilities unchanged.
- **Eligibility is captured before provision changes the schema.** `CapabilityAdoption` has two phases: `capture()` runs in provision **before** the Installer (migrations), and `run()` after them. `capture()` does nothing when the marker exists (`capability.adoption.v1`), when the database isn't reachable, or when `thallo_system_flags` doesn't exist yet (a fresh install, nothing to adopt: it then stores the marker `done` after migrations). Otherwise it evaluates each candidate's eligibility **as it is now** (the rules below) and stores the marker `captured` with the eligible ids, in one write. `run()` adopts only the captured ids, then stores `done`. An interrupted provision keeps `captured` with its list, so a retry skips capture (the schema may have changed in between) and applies the list it captured. A schema that becomes ready during that provision therefore never makes a capability eligible. (An operator who runs `migrate:run` themselves before the first provision on upgraded code has made the schema ready by their own action; it counts as ready.)
- `core/src/Setup/CapabilityAdoption.php`, its `run()` invoked by provision right after its migrations and before the role grants, **not** a migration: it needs `ActivationStore::initializeRow()` (A6), which must commit on its own and so can't run inside a migration's transaction. Per capability, in this order: `initializeRow()` (committed); then one transaction holding the row lock (`lockRow`): skip when a state row exists or the activation is open; for `thallo.commerce` / `thallo.subscriptions` adopt when **old-rule effective** — requested by the config map (`thallo.capabilities.<id>`, default true; explicit false → not requested) and the engine enabled (`EnabledProviders`) and schema-ready (`SchemaReadiness::forPackage`); for `thallo.payments` adopt when `glueful/payvia` is enabled and schema-ready. Adopting writes the state on (advancing `capability.state_version` in the same transaction) and records the row `succeeded` with `result = {"adopted": true}`. **One-time:** after its first complete run it stores `capability.adoption.v1 = done` in the same channel; later runs return at once. The absent-only rule makes a rerun harmless anyway. The ids it adopts are the one hard-coded list the Amendment Global Constraints allow (historical data).
- Until provision has run on upgraded code, an activation capability with no stored state reads off (the documented upgrade step is provision; a production boot already needs it for the extension cache). The upgrade notes say so.
- The admin summary for `result.adopted` is "<label> is on." (A1's no-record rule already covers it; pin it).
- Test: `tests/Integration/Capabilities/ActivationAdoptionTest.php` (`capture()` + `run()` against a prepared state, marker cleared per test), `tests/Integration/Setup/ProvisionAdoptionTest.php` for the real provision sequence, plus a case in `ActivationRunnerTest`. The provision test drives `ProvisionCommand`'s own sequence with the Installer behind a new protected seam (`ProvisionCommand::installer()`, overridden in a test subclass, as `ExtensionAdminController`'s seams are, so no `.env` is written and no real install runs): the fake installer runs a callback standing in for the migrations (making Payvia's schema ready), and can throw after it to simulate an interruption.

**Tests:**
- `testANewActivationDeclarationOverAnEnabledEngineReadsOffUntilFinalized` (Commerce's row and state cleared, engine enabled and ready: `isEnabled` false, no `product-grid`, no grants; after an activation: on).
- `testASimpleCapabilityStillFollowsItsEngine`.
- `testCommerceEffectiveBeforeTheUpgradeIsOnAfterIt`.
- `testAConfigurationOffIsNotAdopted` (no state, `thallo.capabilities.thallo.commerce = false`, engine ready → off, no row change).
- `testAStoredOffAndAnOpenActivationAreUntouched`.
- `testPaymentsIsAdoptedWhenPayviaIsEnabledAndReady`, `testPaymentsIsNotAdoptedWhenPayviaSchemaIsPending`.
- `testUpgradeThenOffThenProvisionStaysOff` (run adoption, store off, clear the marker and run adoption again, then `FeatureProvisioning::syncRows()` → off).
- `testPayviaReadyOnlyDuringProvisionIsNotAdopted` (ProvisionAdoptionTest: Payvia enabled, schema pending; provision whose installer step migrates Payvia → Payments off, marker done).
- `testARetryAfterMigrationsButBeforeAdoptionAppliesTheCapturedList` (ProvisionAdoptionTest: first provision captures with Payvia pending, its installer step migrates Payvia and then throws; second provision: no new capture, Payments still not adopted; Commerce, captured eligible, adopted).
- `testAFreshInstallCapturesNothing` (no system table before the installer → nothing adopted, marker done).
- `testAdoptionRunsOnceAndCreatesItsRowsThroughTheInitializer` (Payments has no row before: after, its row exists via `initializeRow`; a second run with the marker set touches nothing).
- `testAdoptionAdvancesTheStateVersionOnce`.

- [ ] Steps → commit, with a CHANGELOG Changed bullet (and an upgrade line: run `php glueful thallo:provision` after updating, as usual): "**Commerce and Subscriptions stay as they were on upgrade.** A site where they were on keeps them on; from now on they turn on through Extensions, which prepares everything first." `feat(capabilities): activation capabilities are off until they finalize, with a one-time upgrade adoption`.

## Task A6: rows are initialized by the turn-on itself, after workspace seeds in flight

**Files:**
- `ActivationStore`: `initializeRow()` is the **only** code that inserts a row (the interface above); `lockRow()` stops inserting and throws `ActivationRowMissing`; `startOrJoin()`, `acquire()` and `release()` go through `lockRow()`. `supersede()` holds the workspace-seed lock **shared** from its absence check through its off write (one transaction), so no row can be initialized, and no runner started, inside that interval: with a row it supersedes under the row lock as before; with none it publishes off and returns 0. A test seam (`THALLO_TEST_PAUSE_IN_SUPERSEDE=after-absence-check`, testing only) pauses after the check. `initializeRow()` throws `\LogicException` when `Connection::withinTransaction()` is true, so a caller can't fold the row into its own transaction; the separate commit is enforced, not assumed.
- `CapabilityBlockSeeder::withinWorkspaceSeed()` takes the shared workspace-seed lock before `shareAll()`.
- Every creation path calls `initializeRow()` first, outside any transaction: `CapabilityActivationController::start`, `CapabilitiesEnableCommand`, `FeatureProvisioning::resumeOpenActivations` (for each id it resumes), `FeatureProvisioning::syncRows()` (provision, every valid activation capability). Adoption (A5) uses it too.
- Migration 040's two seeded rows stay (harmless: `initializeRow` is idempotent).
- Reads (`manage`, `thallo:capabilities:status`) never write rows (`find()` null → off, no activation). `RawPdoScopingLint` classifies the lock.
- Tests that set up an activation call `initializeRow()` first (the shared `ResetsCommerceActivation`/`ActivationRunners` helpers do it; `ActivationStoreTest`'s `test.*` capabilities call it in their setup).
**Test:** `tests/Integration/Capabilities/ActivationRowInitializationTest.php` (`use ChildProcesses`), fixture `tests/fixtures/row_init_child.php`.

**Tests:**
- `testAWorkspaceSeedInFlightFinishesBeforeTheRowIsCreated` (child A in `withinWorkspaceSeed` paused after kinds holding the shared lock; child B `ensureRow` for a new capability is observed waiting (pg_locks) and unfinished; A resumes; both finish, attempts=1).
- `testASeedStartedAfterTheRowExistsSeedsAPreparingCapability`.
- `testTheFirstTurnOnCommitsItsRowThenStarts` (no row → start → row exists, generation 1).
- `testReadsWriteNoRow` (manage + status on a declared capability without a row: still no row).
- `testLockingAMissingRowThrowsInsteadOfInserting` (`startOrJoin` on a capability with no row → `ActivationRowMissing`; no row inserted).
- `testInitializeRowRefusesAnOpenTransaction` (inside `Connection::transaction()` → `\LogicException`, nothing inserted).
- `testSupersedingACapabilityWithNoRowPublishesOff`.
- `testAFirstStartWaitsForATurnOffInFlight` (two processes; fixture `tests/fixtures/turn_off_child.php`: child A supersedes a capability with no row and pauses after its absence check, holding the lock; child B's first start (`initializeRow` → `startOrJoin`) is observed waiting on the advisory lock (pg_locks) and unfinished; A resumes and commits off; B then starts generation 1. Assert: the state was off when B's generation began, B's runner is the only one, and no runner existed before the off write; attempts=1 on both).
- `testProvisionSyncsRowsForEveryDeclaredActivationCapability`.

- [ ] Steps → commit `feat(capabilities): activation rows are created by the turn-on, after workspace seeds in flight`.

## Task A7: Payments, with an explicit off contract

**Files:**
- Core registers `new Capability('thallo.payments', label: 'Payments', description: …, owningPackage: 'glueful/payvia', management: ManagementMode::Activation, copy: new ActivationCopy(turnOn: 'This prepares online payments: Payvia and its gateways, configured in Settings › Payments. Your orders and plans are kept.', turnOff: 'New online payments stop, and customers pay by manual collection. Payments already started still settle, refunds still work, and subscriptions already billed by your payment provider keep renewing; turning Payments off doesn't cancel them.', links: [['label' => 'Settings › Payments', 'to' => '/settings/payments']]))`.
- `packages/thallo-contracts/src/Payments/OnlinePaymentInitiation.php` + `core/src/Payments/CapabilityOnlinePaymentInitiation.php` (allowed ⇔ `thallo.payments` effective; refusal "Payments is off: customers pay by manual collection."), bound in CoreServiceProvider.
- The initiation points consult it before anything else, and answer as their manual-collection path does today:
  - `packages/thallo-commerce/src/Http/AdminPaymentLinkSendController.php` (send);
  - `packages/thallo-commerce/src/Http/Shop/ShopPaymentLinkController::initiate()` (→ `CHECKOUT_MANUAL`);
  - Commerce's online checkout entry (`grep -rn "PaymentLink\|checkout_url\|createIntent" packages/thallo-commerce/src` in Step 0 lists every one; each gets the check);
  - `packages/thallo-subscriptions/src/Http/SelfBillingController::checkout()` (starting a new self-serve checkout) and `AdminBillingPlanCheckoutUrlResolver::resolve()` (minting a plan checkout URL).
- **Engine availability stays separate from permission to initiate.** `PayviaCheckoutGateway::isAvailable()/unavailableReason()` are **not** changed: `originations()`, `guards()`, `reconciliation()` and `subscriptions:checkout:resolve` depend on them and must keep working while Payments is off. The initiation gate is consulted only at the boundaries listed above. `SelfBillingController::meta()` reports `payments_enabled` so the billing page hides **Subscribe** while off; `cancel()`, `changePlan()` on an existing provider subscription (the provider bills it, like a renewal) and `abandon()` stay allowed. A plan change that would need a **new** checkout (from no provider subscription) goes through `checkout()` and is refused.
- Untouched by the gate (asserted by tests, not edited): `WebhookOrderSettlementListener`, Payvia's webhook handling, `thallo:commerce:links:reconcile`, refunds (`AdminOrderPaymentsController`), payment records, `PlatformPaymentsSettingsController` reads and saves.
- `PlatformPaymentsSettingsController::state()` adds `payments_enabled`; `admin/src/pages/settings/payments.vue` shows "Payments is off" with a link to Extensions › Capabilities when false (instead of "install a gateway extension").
- Payvia is no longer independent: its Installed row reads "Managed by Payments" (derived).
- Test: `tests/Integration/Payments/PaymentsCapabilityTest.php`, cases in `tests/Integration/Commerce` and `tests/Integration/Subscriptions`, vitest for the settings page.

**Tests:**
- `testEachInitiationPointRefusesWithManualCollectionWhileOff` (send link, initiate link, online checkout, self-serve checkout, plan checkout URL).
- `testAPaymentStartedBeforeTheSwitchStillSettles` (create the intent while on, turn off, deliver the webhook fixture → order paid).
- `testARefundStillWorksWhileOff`.
- `testARenewalWebhookStillSettlesWhileOff` (subscriptions renewal fixture).
- `testSavedGatewaySettingsAreKeptAndReadableWhileOff`.
- `testSubscriptionCheckoutReconciliationStillRunsWhileOff` (an origination created while on; Payments off; `subscriptions:checkout:resolve` / `reconciliation()` resolves it).
- `testExistingOriginationsStayVisibleWhileOff` (`meta()` still reports the pending origination).
- `testAPendingCheckoutCanBeAbandonedWhileOff` (`abandon()` succeeds).
- `testTheGatewayAvailabilityCheckIsUnchanged` (`PayviaCheckoutGateway::isAvailable()` true while Payments is off and the engine is ready).
- `testPaymentsTurnsOnThroughTheActivationFlow` (engine prepared from the test overlay; finalize → initiation allowed).
- vitest: `settings payments says Payments is off and links to Extensions`.

- [ ] Steps → commit, with an Added bullet: "**Payments is a capability.** Turn it on in Extensions to take online payments; turning it off stops new online payments but keeps settling the ones in flight, refunds and renewals already billed by your provider. Sites with Payvia enabled keep Payments on." `feat(payments): Payments is a capability, and off stops new online payments only`.

## Task A8: the third-party proof

**Files:**
- `tests/fixtures/packages/acme-bookings/` — a real composer package (`type: glueful-extension`, `extra.glueful.provider: Acme\\Bookings\\BookingsServiceProvider`, `extra.glueful.migrations`, `extra.thallo.capabilities: [{id: acme.bookings, label: Bookings, mode: activation, copy: …}]`). Its provider registers one block-type contribution gated by `acme.bookings` (through `Thallo\Contracts\…` only), declares one permission (`bookings.manage`), and a migration creating `acme_bookings`.
- The dev root `composer.json`: `repositories` path entry + `require-dev: {"acme/bookings": "*"}`; `config/testing/extensions.php` does **not** list its provider (disabled at the start). `scripts/check-pack-boundaries.php` and the skeleton are untouched.
- Test: `tests/Integration/Capabilities/ThirdPartyActivationTest.php` (single store) and `tests/Integration/Tenancy/ThirdPartyActivationTenancyTest.php` (harness), both using a temp enabled list (the `ActivationRunners` seams) so the engine step writes no application file.

**Tests (single store):**
- `testItAppearsAndIsActionableWhileItsEngineIsDisabled` (manage: activation, engine_enabled false, available false, no row).
- `testTheFirstTurnOnSyncsItsRowThenPreparesTheEngine` (start → row, engine listed in the temp list, needsBoot).
- `testTheActivationSeedsTheEnginesBlocksAndGrantsItsPermission` (continue in a context booted with the engine → its block exists, `bookings.manage` granted to the install roles, on).
- `testAnEngineAlreadyEnabledStillReadsOffUntilItsActivation` (Review Focus 2).
- `testTurningOffHidesItAndKeepsTheEngineAndItsData` (supersede → off, block row kept and hidden, `acme_bookings` table and rows kept, provider still listed).
**Tests (workspaces, harness):** `testEveryWorkspaceGetsItsBlocks`, `testAWorkspaceCreatedWhilePreparingGetsThem`.

- [ ] Steps → commit `test(capabilities): a third-party extension activates end to end from a disabled engine`.

## Task A9: docs, and the gates

**Files:** `docs/concepts/06-capabilities.md` (the three modes; both declaration sources; off-until-finalized; adoption in one paragraph), a new `docs/extending/` page or the existing extension-authoring page (`grep -rln "extra.glueful" docs`) for `extra.thallo.capabilities`, `docs/guides/` payments guide (what off stops and keeps), `docs/reference/01-cli.md`, `docs/operations/05-troubleshooting.md` (misconfigured capability), READMEs; CHANGELOG checked once more for "Features".

- [ ] **Step 1:** Write the docs. `tests/Unit/Docs` passes.
- [ ] **Step 2:** `composer test:skeleton` (with `thallo:capabilities:enable thallo.commerce`).
- [ ] **Step 3:** Full gates (Global Constraints), the harness classes each in their own process, then one fresh reviewer on the most capable model over the amendment's range, against spec §7 and this amendment.
- [ ] **Step 4: Commit** `docs(capabilities): declared management, Payments, and extension authoring`.

## Amendment self-review

- **Spec §7 coverage:** 7.2 → A1. 7.3 → A2 (modes, both sources, provider from the manifest), A3 (rejection). 7.3a → A5. 7.4 → A2, A8. 7.5 → A6. 7.6 → A4. 7.7 → A5 (adoption), A7. 7.8 → unchanged code (asserted by the existing suites). 7.9 → A3, A5, A6, A7, A8. 7.10 → A1, A9.
- **Execution order:** A1, A2, A4, A3, **A6, A5**, A7, A8, A9. A1 (names) is independent. A2 creates the declaration boundary and `RequiredPackages` (core minimum); A4 adds the configured additions; A3 (rejection) needs both. A6 establishes the single row initializer and the workspace coordination **before** A5, whose adoption creates Payments' row through it and whose tests use `syncRows()`. A7 needs A2 and A5 (Payments adoption). A8 needs A2, A5 and A6. A9 is last.
- **Upgrade safety:** A5 lands the off-until-finalized rule and the adoption step in one commit. On an upgraded install, an activation capability with no stored state reads off from the code update until provision runs (capture before migrations, adoption after); provision is the documented upgrade step, and the upgrade notes say so.
- **Types:** `ManagementMode` (A2, A3, A5, A7, A8); `DeclaresCapabilities` (A2, A7, A8); `DeclarationSet::engineOf` (A2, A6, A8); `ActivationStore::initializeRow` / `ActivationRowMissing` (A6, A5, A8); `CapabilityAdoption` (A5, A7); `OnlinePaymentInitiation` (A7); `RequiredPackages::all` (A2, A4, A3).
