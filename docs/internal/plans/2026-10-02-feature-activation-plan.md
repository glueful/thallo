# One place to turn each feature on — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each feature is managed in one place. Commerce and Subscriptions turn on with one user action, which prepares the engine, seeds blocks into every workspace, grants permissions through the ledger, and only then makes the feature effective, with stale route tables unusable. Turning a feature off disables only the capability. The Extensions page becomes **Features**, with the capabilities first and a guarded **Installed packages** view. The Browse tab and the web installer are removed.

**Architecture:**
- **Activations.** A new system table, `capability_activations`, holds one row per capability: its generation, status, steps done, lease, per-workspace readiness and result. That row is the coordination point. Every step's completion is a fenced write: current generation and current lease owner.
- **Effectiveness.** A capability becomes effective in one transaction that writes its state and advances `capability.state_version`.
- **Routes.** A framework seam lets Thallo add that version to the route-cache signature, so a table compiled under an older state is rejected.
- **Engines.** The engine is prepared through the owning-flow pattern the Workspaces flow already uses: `migrateProtected` under the executor lock, then `ExtensionStateWriter`, then `writeCacheNow`.
- **Management policy.** One policy in core (`FeatureManagementPolicy`) feeds the admin, Thallo's endpoints, and the framework's own enable/disable surfaces. It reaches those through `mergeConfig('extensions', ['protected' => …])`.

**Tech Stack:** PHP 8.3 (Glueful framework, PostgreSQL, PHPUnit), a framework change in `../framework` (released by the user as `glueful/framework` 1.88.0), Nuxt UI admin (Vue 3, pinia-colada, vitest, Playwright e2e in `admin/e2e`).

**Spec:** `docs/internal/superpowers/specs/2026-10-02-feature-activation-design.md` (approved at `a3c90f8d`). Approval note to honour in the crash tests: **before the finalization commit the capability remains off; after it commits it remains on, even if the response is lost — a lost response never undoes a successful activation.**

## Rulings made while planning (from the code)

- **The route-cache seam needs a framework change, and it is the release gate.** `RouteCache::computeSignature()` is private and hashes only source-file mtimes and a format constant. `Router::__construct` creates its own `RouteCache` and calls `load()` immediately, and package route files (`loadRoutesFrom`) are not signature inputs. When a table is loaded from the cache, routes a provider no longer registers are never removed, so a disabled capability's routes keep being served. No Thallo-side lever can satisfy §3.2's "stale tables are unusable" (spec, finalization contract).
  - **Framework Task F1** adds a public, static signature-contribution seam to `RouteCache`. Its contributions are resolved **once per process, on first use**, and memoised. Both `load()` (in the Router constructor) and `save()` (at the end of boot) therefore use the version the request booted with.
  - **Why that's enough:** a request that booted before the switch saves a table under the old version. Every request that sees the new version rejects that table and rebuilds.
  - **The gate:** you publish `glueful/framework` 1.88.0, and Thallo requires `^1.88` (Task 11). Tasks 1–10 don't depend on it, and Task 11 is last before docs. Cost if wrong: the release waits on a framework publish.
- **CLI enforcement of the policy needs no framework change.** `bin/glueful` boots the whole application, every provider's `register()` included, before building the console. Commands resolve the booted `ApplicationContext`. `mergeConfigDefaults('extensions', …)` invalidates `extensions.*` cached keys, merges beneath the file key by key, and survives `clearConfigCache()`. The executor's `enable`, `disable` and `migrateProtected` all consult `ProtectedProviders::refusalFor()`, so the framework's `extensions:enable` and `extensions:disable` see core's policy with no edit to an operator's `config/extensions.php`.
- **The owning flow is tenancy's pattern** (`packages/thallo-tenancy/src/Enablement/ExtensionActivation.php`):
  1. `ExtensionSchemaExecutor::migrateProtected($package, $actor)` takes the executor lock and migrates. It works only when the provider **is** protected, which managed engines now are.
  2. `(new ExtensionStateWriter())->enable(config_path('extensions.php'), $provider)`.
  3. `$context->clearConfigCache()` and `ExtensionManager::writeCacheNow()`.

  Writability is `Glueful\Extensions\Install\HostCapability::forToggle()`, the same check `ExtensionAdminController::hostToggleRefusal()` uses: `config/extensions.php` and `bootstrap/cache` writable.
- **The operation store is a table, not the system channel.** `SystemFlags` has no compare-and-set (`put` is select-then-insert/update). So migration `core/database/migrations/040_CreateCapabilityActivationsTable.php` creates `capability_activations`. It's an unscoped system table: not listed in `ThalloTenantTables`.
  - **One row per capability,** keyed by `capability`. `SELECT … FOR UPDATE` on that row serializes creation, joins, takeovers, supersession and finalization.
  - **History** is kept in `capability_activation_events` (append-only, for the audit and troubleshooting). Cost if wrong: one more table.
- **Fencing is a conditional write.** Every write that advances an activation is `UPDATE capability_activations SET … WHERE capability = ? AND generation = ? AND owner_token = ?`, inside a transaction that first locks the row. It must report exactly one affected row, or the runner stops with `ActivationSuperseded`. Side effects are overlap-safe:
  - **Engine work** runs under the executor's lock.
  - **Block inserts** rely on the `(tenant_uuid, slug)` uniqueness (`uniq_block_type_slug`, widened by the tenancy retrofit). A unique violation counts as "skipped".
  - **Grants** run serialized with the ledger (Task 3).
  - **Finalization** is its own conditional transaction (Task 5).
- **"Preparing" is stored as an explicit `false` switch, plus an open activation row.** `CapabilityStateStore` stays boolean. Step 1 writes `capability.<id>.enabled = false` before the engine is enabled, so an untouched capability can never follow its engine into a half-on state. The activation row's status says "preparing". The admin and CLI read both. Cost if wrong: one more state value to add later.
- **State changes advance `capability.state_version` in the same transaction.** `CapabilityStateStore::put()` now writes the state and advances the version (`UPDATE … SET value = (COALESCE(value,'0')::bigint + 1)::text`) in one transaction. Every caller gets the version bump, including the simple switches, the Settings search toggle, `thallo:capabilities` and the activation finalization. The existing `RouteCache::clear()` calls stay; they're harmless and keep pre-1.88 behaviour.
- **Workspace coordination uses advisory locks keyed per capability:** `hashtext('thallo:capability-activation:' || capability)`.
  - **Finalization** holds the **exclusive** transaction lock (`pg_advisory_xact_lock`).
  - **Workspace seeding** (`TenantSeeder::seed`) holds the **shared** one (`pg_advisory_xact_lock_shared`) for each activation capability, so parallel workspace creations don't block each other.
  - **Inside its lock,** the workspace seed reads capability state **fresh**, straight from `thallo_system_flags` and `capability_activations` with raw SQL, never from the request's memoised registry. If the capability is on or preparing, it seeds that capability's explicit contributions.
  - **Why finalization waits:** `forEachTenant` iterates `active` tenants only, and a new workspace stays `provisioning` until `markActive` in the same transaction. So finalization waits for an in-flight workspace's shared lock, then sees it active.
- **Grants run in one serialized transaction.** `InstallRoleGrants::apply()` keeps the catalog sync first: it's idempotent, and creating permission rows is outside the decision. Then, in one `Connection::transaction`:
  1. `pg_advisory_xact_lock(hashtext('thallo:install-role-grants'))`;
  2. a fresh ledger read (`SystemFlags::clearCache()`);
  3. the optional `$stillOwner` check;
  4. grants;
  5. the ledger write.

  The Aegis repositories share the process PDO session (`Connection::sharesStaticHandle`), so the transaction covers their writes. SetupService already relies on this.
- **Seeding while off** uses `BlockTypeKind::contributionsFor($capability)` explicitly, never `definitions()`, which filters a gated contribution out while its capability is off.
- **The fresh-boot gate** (step `verify_boot`) runs only in a request or process that booted **after** the engine was enabled. That's the admin's continue call, or the CLI's subprocess. It checks two things:
  - `ExtensionManager::hasProvider($engineProvider)`;
  - `CapabilityRegistry::availability($id)->available`. This is computed in the new boot, so its schema readiness is fresh.

  Failure is recorded as `failed_step = verify_boot` with the reason and a repair command (`php glueful extensions:cache`).
- **Which capabilities use the activation flow:** `thallo.commerce` and `thallo.subscriptions`. `thallo.tenancy` links to Settings › Workspaces. Accounts (`glueful/users`) and Content importers (`glueful/import-export`) follow the audit (Task 6). Its decision rule: a package is **required by Thallo** when core or a pack references its classes unconditionally on a path every install runs (setup, sign-in, the admin bootstrap, provision).
  - **A required engine's capability** stays a simple switch over an always-enabled engine.
  - **An unrequired engine's capability** gets the activation flow.

  The plan expects `glueful/users` required (`SetupService` injects `UserRepository`), so Accounts stays simple. The audit decides import/export.
- **Removed with Browse:**
  - `GET /extensions/registry` and `POST /extensions/install`, with their routes and OpenAPI operations;
  - `useExtensionCatalog`, `fetchExtensionCatalog`, `CatalogExtension`, `useExtensionInstall`, `installExtension`, `InstallStatus` and `InstallResult`, with their specs;
  - `BrowseExtensions.vue`.

  The framework's `ExtensionInstaller` and its config stay; they're the framework's.
- **The admin has no permissions store.** `useMe()` returns `ui.hidden` and `ui.landing` only, and permission gating is enforced on the server. "Refresh the current user's permissions" therefore means invalidating `['me']` and `['capabilities']` and calling `useCapabilitiesStore().refreshUntilChanged()`, after which a gated nav item appears or disappears.
- **`/extensions` redirects to `/features`.** The admin has no route-level redirects (file routes, `definePage` meta only). So `admin/src/pages/extensions/index.vue` becomes a component that runs `router.replace('/features')` (the `reset-password.vue` pattern).
- **Raw PDO sites must be classified.** Every new `getPDO()` call is added to `tests/Unit/Tenancy/RawPdoScopingLintTest.php`'s `SYSTEM_READERS` or `SYSTEM_WRITERS`. It's a system table, or a fresh state read. Otherwise `testNoUnclassifiedGetPdoSites` fails.

## Global Constraints

- Never push, tag or split. Work ends at local commits on `dev` (Thallo) and on the framework repo's default branch (Task F1). Releasing is the user's.
- No Co-Authored-By trailers.
- PHP gates:
  - the full suite once, unprefixed, run attached in parts: reset, `tests/Feature tests/Unit`, then the four `INTEGRATION_SHARD_*` lists from ci.yml;
  - phpcs judged by its exit code;
  - `composer boundaries`;
  - `composer test:skeleton`.
- Admin gates: `pnpm type-check`, `pnpm lint`, `pnpm fmt:check`, vitest, e2e on 8 workers.
- The changelog bullet rides with its change, under `## [Unreleased]`. The file currently has no `[Unreleased]` heading; the first task to add a bullet adds it.
- A new top-level `tests/Integration/*` directory needs a shard entry in `.github/workflows/ci.yml`. This plan uses existing directories only: Capabilities, Setup, Tenancy, Http, Console.
- Pack boundaries: packs reference `Thallo\Contracts\…` only, never `Thallo\Core\…` (`scripts/check-pack-boundaries.php`).
- Copy:
  - **Turn-on confirmation:** "**Turn on Commerce?** This prepares your store and adds products, orders, shop blocks and templates. Your existing content is kept."
  - **Turn-off confirmation:** "**Turn off Commerce?** Commerce's pages, blocks and menu are hidden. Products, orders and your content are kept, and you can turn it on again."
  - **Summaries** are built from the activation's `result`, never hard-coded.

## Review Focus

1. **A browser left open on Features while another operator turns the feature off.** The open tab must not show "on" or offer **Continue** for the superseded operation. Its next poll shows off, and its **Continue** is refused with "a newer decision exists". Pinned in Task 7 (`testAContinueForASupersededGenerationIsRefused`) and Task 10 (vitest `superseded continue shows off`).
2. **An operator's own `extensions.protected` entry for a package core also declares.** The operator's reason text wins in refusals, and the package is still refused. Pinned in Task 6 (`testAnOperatorProtectedEntryWins`).
3. **A workspace that fails seeding during activation, then is deleted before Retry.** Retry must not fail forever on a missing tenant. A deleted workspace is dropped from readiness. Pinned in Task 4 (`testRetryDropsAWorkspaceDeletedSinceItFailed`).
4. **Turning on an already-prepared feature on a host whose application files are read-only.** The engine is still enabled from before, so no application-file step is pending. Activation completes without the writability refusal. Pinned in Task 5 (`testAPreparedEngineActivatesOnAReadOnlyHost`).
5. **The engine disabled behind Thallo's back** (an operator edits `config/extensions.php`) **while the capability is on.** Features must show the capability as unavailable with the remedy, not as on. Turning it back on starts a normal activation that re-enables the engine. Pinned in Task 7 (`testAnEngineDisabledOutsideThalloShowsUnavailable`).

---

## Shared contracts (named once, used by every task)

```php
// core/src/Capabilities/Activation/ActivationStep.php
namespace Thallo\Core\Capabilities\Activation;
final class ActivationStep
{
    public const MARK_PREPARING = 'mark_preparing';
    public const ENABLE_ENGINE = 'enable_engine';
    public const VERIFY_BOOT = 'verify_boot';
    public const SEED_BLOCKS = 'seed_blocks';
    public const GRANT_PERMISSIONS = 'grant_permissions';
    public const FINALIZE = 'finalize';
    /** In order. */
    public const ALL = [self::MARK_PREPARING, self::ENABLE_ENGINE, self::VERIFY_BOOT,
        self::SEED_BLOCKS, self::GRANT_PERMISSIONS, self::FINALIZE];
    /** Steps that write application files (config/, bootstrap/cache). */
    public const WRITES_APPLICATION_FILES = [self::ENABLE_ENGINE];
}

// core/src/Capabilities/Activation/ActivationStatus.php
final class ActivationStatus
{
    public const PREPARING = 'preparing';   // open
    public const FAILED = 'failed';         // open, resumable
    public const SUCCEEDED = 'succeeded';   // closed: the capability is on
    public const SUPERSEDED = 'superseded'; // closed: turned off or cancelled
    public const OPEN = [self::PREPARING, self::FAILED];
}

// core/src/Capabilities/Activation/ActivationRecord.php — readonly snapshot of the row
final class ActivationRecord
{
    public function __construct(
        public readonly string $capability,
        public readonly int $generation,
        public readonly string $status,
        /** @var list<string> */ public readonly array $stepsDone,
        public readonly ?string $failedStep,
        public readonly ?string $error,
        public readonly ?string $remedy,
        public readonly ?string $ownerToken,
        public readonly ?string $leaseExpiresAt,
        /** @var array<string,string> tenant uuid => ready|failed */ public readonly array $workspaces,
        /** @var array<string,mixed> e.g. blocks_created, grants */ public readonly array $result,
        public readonly string $actor,
        public readonly string $updatedAt,
    ) {}
    public function isOpen(): bool { return in_array($this->status, ActivationStatus::OPEN, true); }
    public function nextStep(): ?string { /* first of ActivationStep::ALL not in stepsDone */ }
    /** @return array<string,mixed> the API shape (snake_case keys, no owner token) */
    public function toArray(): array;
}

// core/src/Capabilities/Activation/ActivationLease.php
final class ActivationLease
{
    public function __construct(public readonly string $capability, public readonly int $generation,
        public readonly string $ownerToken) {}
}

// core/src/Capabilities/Activation/ActivationSuperseded.php
final class ActivationSuperseded extends \RuntimeException {}   // a fenced write found a newer decision

// core/src/Capabilities/Activation/ActivationStore.php
final class ActivationStore
{
    public const LEASE_SECONDS = 120;
    /** Start or join: locks the row; an open activation is returned as is, otherwise generation+1. */
    public function startOrJoin(string $capability, string $actor): ActivationRecord;
    public function find(string $capability): ?ActivationRecord;
    /** Takes the lease when free or expired; null when another runner holds a live lease. */
    public function acquire(string $capability, int $generation): ?ActivationLease;
    /** Fenced: throws ActivationSuperseded unless generation and owner token are current. */
    public function completeStep(ActivationLease $lease, string $step, array $resultPatch = []): ActivationRecord;
    public function failStep(ActivationLease $lease, string $step, string $error, ?string $remedy): ActivationRecord;
    public function markWorkspace(ActivationLease $lease, string $tenantUuid, string $state): void;
    /** Workspace creation (not a runner): under the row lock, for an open activation only. */
    public function markWorkspaceUnfenced(string $capability, string $tenantUuid, string $state): void;
    /** Runs $fn inside a transaction holding the row lock, after checking the lease (fenced). */
    public function withinFenced(ActivationLease $lease, callable $fn): mixed;
    /** Supersede (turn off / cancel): generation+1, status superseded. Returns the new generation. */
    public function supersede(string $capability, string $actor): int;
    public function release(ActivationLease $lease): void;
}

// core/src/Capabilities/CapabilityStateVersion.php
final class CapabilityStateVersion
{
    public const KEY = 'capability.state_version';
    /** Read once per process (memoised) — what this request booted with. */
    public function booted(): string;
    /** Fresh read, never memoised. */
    public function current(): string;
    /** Advances the version; call inside the caller's transaction. */
    public function advance(): void;
}

// core/src/Capabilities/FeatureManagementPolicy.php
final class FeatureManagementPolicy
{
    public const REQUIRED = 'required';
    public const MANAGED = 'managed';
    public const INDEPENDENT = 'independent';
    /** @return array{class:string, capability:?string, reason:string, link:?string} for a composer package */
    public function managementOf(string $package): array;
    /** @return array<class-string, array{reason:string, managed_by:string}> for extensions.protected */
    public function protectedProviders(): array;
    /** @return list<string> capability ids that use the activation flow */
    public function activationCapabilities(): array;
    /** @return array{package:string, provider:class-string}|null the engine of an activation capability */
    public function engineOf(string $capability): ?array;
}

// core/src/Capabilities/Activation/CapabilityBlockSeeder.php
final class CapabilityBlockSeeder
{
    /** Seeds $capability's explicit contributions into the current store; returns created slugs. */
    public function seedCurrent(string $capability): array;
    /** Every active workspace (tenancy on) or the current store; returns tenant uuid => created slugs.
     *  @param list<string> $only limit to these tenants (Retry) */
    public function seedAll(string $capability, ?ActivationLease $lease = null, array $only = []): array;
}

// core/src/Capabilities/Activation/ActivationRunner.php
final class ActivationRunner
{
    /** Runs steps from the next unfinished one until done, a fresh boot is needed, or a failure.
     *  $freshBoot: true when this process booted after ENABLE_ENGINE completed. */
    public function run(string $capability, int $generation, bool $freshBoot): ActivationRecord;
}

// core/src/Capabilities/Activation/EngineActivation.php — the owning flow (tenancy's pattern)
final class EngineActivation
{
    /** @return array{status:'prepared'|'cache_stale'|'already', error:?string} */
    public function prepare(string $package, string $provider, string $actor): array;
    public function applicationFilesWritable(): bool;
}
```

Event table (append-only): `capability_activation_events(id bigserial, capability, generation, event, detail json, actor, created_at)`. Events: `started`, `joined`, `step_done`, `step_failed`, `superseded`, `succeeded`.

---

## Task F1 (framework repo): route-cache signature contributions

**Repo:** `/Users/michaeltawiahsowah/Sites/glueful/framework` (clean at `2c4b86a5`, 1.87.0).

**Files:**
- Modify: `src/Routing/RouteCache.php` (`computeSignature()` at :228-249)
- Test: `tests/Unit/Routing/RouteCacheSignatureContributionTest.php`
- Modify: `CHANGELOG.md` (`[Unreleased]` → Added)

**Interfaces:**
- Produces:
  - `public static function contributeSignature(string $name, callable $resolver): void`
  - `public static function resetSignatureContributions(): void`

  Resolvers return a string, run once per process on first signature computation, and are memoised. `computeSignature()` appends `name=value` lines sorted by name.

- [ ] **Step 1: Write the failing test**

```php
final class RouteCacheSignatureContributionTest extends TestCase
{
    protected function tearDown(): void { RouteCache::resetSignatureContributions(); }

    public function testATableSavedUnderOneContributionIsRejectedUnderAnother(): void
    {
        // a temp app root with storage/cache, a context, a Router with one route (no closures)
        [$context, $router] = $this->appWithOneRoute();
        RouteCache::contributeSignature('capability.state_version', static fn (): string => '1');
        self::assertTrue((new RouteCache($context))->save($router));
        RouteCache::resetSignatureContributions();               // a new request
        RouteCache::contributeSignature('capability.state_version', static fn (): string => '2');
        self::assertNull((new RouteCache($context))->load(), 'a table built under version 1 is unusable under 2');
    }

    public function testTheContributionIsResolvedOnceAndReusedBySave(): void
    {
        // an in-flight request booted under 1 and saves after the switch to 2
        [$context, $router] = $this->appWithOneRoute();
        $version = '1';
        RouteCache::contributeSignature('v', static function () use (&$version): string { return $version; });
        $cache = new RouteCache($context);
        self::assertNull($cache->load());       // first use memoises '1'
        $version = '2';                          // the switch happens mid-request
        self::assertTrue($cache->save($router)); // still embeds '1'
        RouteCache::resetSignatureContributions();
        RouteCache::contributeSignature('v', static fn (): string => '2');
        self::assertNull((new RouteCache($context))->load(), 'the stale save is rejected by the next request');
    }

    public function testNoContributionKeepsTodaysSignature(): void
    {
        [$context] = $this->appWithOneRoute();
        $before = (new RouteCache($context))->getSignature();
        RouteCache::resetSignatureContributions();
        self::assertSame($before, (new RouteCache($context))->getSignature());
    }
}
```

- [ ] **Step 2: Run it.** Command: `vendor/bin/phpunit tests/Unit/Routing/RouteCacheSignatureContributionTest.php`. Expected: FAIL with `Call to undefined method …contributeSignature()`.

- [ ] **Step 3: Implement**

```php
/** @var array<string, callable(): string> */
private static array $contributors = [];
/** @var array<string, string>|null resolved once per process */
private static ?array $contributed = null;

/**
 * Adds an input to the route-table signature. The resolver runs once per process, on the first
 * signature computation, and its value is reused by load() and save(): a request saves its table
 * under the state it booted with, and a request that booted under another state rejects it.
 */
public static function contributeSignature(string $name, callable $resolver): void
{
    self::$contributors[$name] = $resolver;
    self::$contributed = null;
}

public static function resetSignatureContributions(): void
{
    self::$contributors = [];
    self::$contributed = null;
}

/** @return array<string, string> */
private static function contributions(): array
{
    if (self::$contributed === null) {
        $values = [];
        foreach (self::$contributors as $name => $resolver) {
            $values[$name] = (string) $resolver();
        }
        ksort($values);
        self::$contributed = $values;
    }
    return self::$contributed;
}
```

In `computeSignature()`, before hashing, append `foreach (self::contributions() as $name => $value) { $parts[] = 'contrib:' . $name . '=' . $value; }`, matching the method's existing accumulation. With no contributors the signature must not change, so append nothing when empty.

- [ ] **Step 4: Run it.** Expected: PASS (3 tests). Then run the framework's full suite and phpcs as its README says.
- [ ] **Step 5: Changelog and commit.** Add under `[Unreleased]` → Added: "**Route-table signature contributions.** `RouteCache::contributeSignature($name, $resolver)` adds an input to the compiled route table's signature, resolved once per process, so a table compiled under one application state is rejected under another." Commit: `feat(routing): route-table signature contributions`. **Stop.** The user releases `glueful/framework` 1.88.0. Task 11 waits for that release.

---

## Task 1: capability state changes advance a version, in one transaction

**Files:**
- Create: `core/src/Capabilities/CapabilityStateVersion.php`
- Modify: `core/src/Capabilities/CapabilityStateStore.php` (`put()` at :63-77)
- Modify: `core/src/Providers/CoreServiceProvider.php` (register the service next to `CapabilityStateStore` at :2362)
- Modify: `tests/Unit/Tenancy/RawPdoScopingLintTest.php` (classify `CapabilityStateVersion` as a system writer and reader)
- Test: `tests/Integration/Capabilities/CapabilityStateVersionTest.php`

**Interfaces:**
- Produces: `CapabilityStateVersion` (shared contracts). `CapabilityStateStore::put(string $id, bool $enabled): void` now advances the version in the same transaction.

- [ ] **Step 1: Write the failing test**

```php
final class CapabilityStateVersionTest extends AppTestCase
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
        $before = $version->current();
        $this->container()->get(CapabilityStateStore::class)->put('test.flip', true);
        self::assertSame((string) ((int) $before + 1), $version->current());
    }

    public function testAFailedWriteLeavesTheVersionAlone(): void
    {
        $version = $this->container()->get(CapabilityStateVersion::class);
        $before = $version->current();
        try {
            $this->connection()->transaction(function (): void {
                $this->container()->get(CapabilityStateStore::class)->put('test.flip', true);
                throw new \RuntimeException('crash before commit');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame($before, $version->current());
        self::assertNull($this->container()->get(CapabilityStateStore::class)->explicit('test.flip'));
    }

    public function testBootedIsMemoisedAndCurrentIsFresh(): void
    {
        $version = $this->container()->get(CapabilityStateVersion::class);
        $booted = $version->booted();
        $version->advanceInOwnTransaction();
        self::assertSame($booted, $version->booted());
        self::assertNotSame($booted, $version->current());
    }
}
```

- [ ] **Step 2: Run it.** `php vendor/bin/phpunit tests/Integration/Capabilities/CapabilityStateVersionTest.php`. Expected: FAIL (`Class "Thallo\Core\Capabilities\CapabilityStateVersion" not found`).
- [ ] **Step 3: Implement**

```php
final class CapabilityStateVersion
{
    public const KEY = 'capability.state_version';
    private ?string $booted = null;

    public function __construct(private readonly Connection $db) {}

    public function booted(): string
    {
        return $this->booted ??= $this->current();
    }

    public function current(): string
    {
        try {
            $stmt = $this->db->getPDO()->prepare('SELECT value FROM thallo_system_flags WHERE key = ?');
            $stmt->execute([self::KEY]);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? '0' : (string) $value;
        } catch (\Throwable) {
            return '0'; // pre-provision boot: no table yet
        }
    }

    /** Inside the caller's transaction. */
    public function advance(): void
    {
        $pdo = $this->db->getPDO();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "INSERT INTO thallo_system_flags (key, value, updated_at) VALUES (?, '1', ?)
             ON CONFLICT (key) DO UPDATE SET value = (COALESCE(NULLIF(thallo_system_flags.value, ''), '0')::bigint + 1)::text,
             updated_at = EXCLUDED.updated_at"
        );
        $stmt->execute([self::KEY, $now]);
    }

    public function advanceInOwnTransaction(): void
    {
        $this->db->transaction(fn () => $this->advance());
    }
}
```

`CapabilityStateStore::put()` wraps its write, the read-back check and `advance()` in `$this->db->transaction(...)`. Inject `Connection $db` and `CapabilityStateVersion $version`; the constructor is autowired. `SystemFlags::put` uses the shared PDO session, so the transaction covers it.

- [ ] **Step 4: Run it**, then `tests/Integration/Capabilities` and `tests/Unit/Tenancy/RawPdoScopingLintTest.php`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(capabilities): a capability state write advances the state version in the same transaction`. No changelog bullet: nothing visible to a user yet.

---

## Task 2: the activation store, fenced

**Files:**
- Create: `core/database/migrations/040_CreateCapabilityActivationsTable.php` (both tables; `hasTable` guards; `down()` drops both)
- Create:
  - `core/src/Capabilities/Activation/ActivationStep.php`
  - `ActivationStatus.php`
  - `ActivationRecord.php`
  - `ActivationLease.php`
  - `ActivationSuperseded.php`
  - `ActivationStore.php`
- Modify: `core/src/Providers/CoreServiceProvider.php` (shared, autowired)
- Modify: `tests/Unit/Tenancy/RawPdoScopingLintTest.php` (`ActivationStore` as a system writer)
- Test: `tests/Integration/Capabilities/ActivationStoreTest.php`, and `tests/fixtures/activation_start_race_child.php`

**Interfaces:**
- Produces: `ActivationStore`, as in the shared contracts.

**Schema:** `capability_activations`:
- `capability` varchar(64) primary key
- `generation` bigint, not null, default 0
- `status` varchar(16), not null
- `steps_done` jsonb, not null, default `'[]'`
- `failed_step` varchar(32), null
- `error` text, null
- `remedy` text, null
- `owner_token` varchar(32), null
- `lease_expires_at` timestamp, null
- `workspaces` jsonb, not null, default `'{}'`
- `result` jsonb, not null, default `'{}'`
- `actor` varchar(120), not null
- `started_at` timestamp
- `updated_at` timestamp

`capability_activation_events`: as in the shared contracts, indexed on `(capability, generation)`.

- [ ] **Step 1: Write the failing tests**

```php
final class ActivationStoreTest extends AppTestCase
{
    private function store(): ActivationStore { return $this->container()->get(ActivationStore::class); }

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM capability_activation_events WHERE capability LIKE 'test.%'");
        $pdo->exec("DELETE FROM capability_activations WHERE capability LIKE 'test.%'");
        parent::tearDown();
    }

    public function testAStartCreatesGenerationOneAndASecondStartJoinsIt(): void
    {
        $a = $this->store()->startOrJoin('test.shop', 'op-a');
        $b = $this->store()->startOrJoin('test.shop', 'op-b');
        self::assertSame(1, $a->generation);
        self::assertSame($a->generation, $b->generation, 'the second start joins');
        self::assertSame(ActivationStatus::PREPARING, $b->status);
    }

    public function testALiveLeaseIsExclusiveAndAnExpiredOneCanBeTaken(): void
    {
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $a = $this->store()->acquire('test.shop', $gen);
        self::assertNotNull($a);
        self::assertNull($this->store()->acquire('test.shop', $gen), 'a live lease is exclusive');
        $this->expireLease('test.shop');
        self::assertNotNull($this->store()->acquire('test.shop', $gen), 'an expired lease is taken over');
    }

    public function testATakenOverRunnerCannotAdvanceTheOperation(): void
    {
        // A pauses mid-step, its lease expires, B takes over and finishes the step, then A resumes.
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $a = $this->store()->acquire('test.shop', $gen);
        $this->expireLease('test.shop');
        $b = $this->store()->acquire('test.shop', $gen);
        $this->store()->completeStep($b, ActivationStep::MARK_PREPARING);
        $this->expectException(ActivationSuperseded::class);
        $this->store()->completeStep($a, ActivationStep::ENABLE_ENGINE);
    }

    public function testSupersedingEndsTheGenerationAndRefusesItsRunner(): void
    {
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen);
        $newGen = $this->store()->supersede('test.shop', 'op-off');
        self::assertSame($gen + 1, $newGen);
        self::assertSame(ActivationStatus::SUPERSEDED, $this->store()->find('test.shop')->status);
        $this->expectException(ActivationSuperseded::class);
        $this->store()->completeStep($lease, ActivationStep::MARK_PREPARING);
    }

    public function testALostResponseIsHarmless(): void
    {
        // the step is recorded before any response; the next runner sees it done
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen);
        $this->store()->completeStep($lease, ActivationStep::MARK_PREPARING);
        $this->store()->release($lease);
        self::assertSame(ActivationStep::ENABLE_ENGINE, $this->store()->find('test.shop')->nextStep());
    }

    public function testSimultaneousStartsInTwoProcessesMakeOneOperation(): void
    {
        $results = $this->runChildren('activation_start_race_child.php', ['test.race'], 2);
        self::assertSame([1, 1], array_map('intval', $results), 'both joined generation 1');
    }

    private function expireLease(string $capability): void
    {
        $this->connection()->getPDO()->prepare(
            "UPDATE capability_activations SET lease_expires_at = NOW() - INTERVAL '1 minute' WHERE capability = ?"
        )->execute([$capability]);
    }
}
```

`runChildren()` follows `tests/Integration/Commerce/CompleteSaleTest.php:1050`: `proc_open([PHP_BINARY, fixture, ...args])` ×N in parallel, collecting stdout. The child boots through `Framework::create` as `tests/fixtures/checkout_attempt_race_child.php:53` does, then prints `startOrJoin($argv[1], 'child')->generation`.

- [ ] **Step 2: Run it.** Expected: FAIL (missing classes and table). Run `DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/run-test-migrations.php` after adding the migration.
- [ ] **Step 3: Implement.** Key rules:
  - `startOrJoin`, `acquire`, `completeStep`, `failStep`, `markWorkspace`, `withinFenced`, `supersede` and `release` each run inside `Connection::transaction`, and first run `SELECT … FROM capability_activations WHERE capability = ? FOR UPDATE`. Insert the row on first use (`INSERT … ON CONFLICT DO NOTHING`, then lock).
  - **`startOrJoin`:** an open status returns the row and logs `joined`. Otherwise:
    - set `generation = generation + 1`, `status = preparing`;
    - clear `steps_done`, `failed_step`, `error`, `remedy`, `workspaces` and `result`;
    - clear the lease, and set `actor` and `started_at`;
    - log `started`.
  - **`acquire`:** refuse unless `generation` matches and the status is open. Take the lease when `owner_token IS NULL OR lease_expires_at < NOW()`: `owner_token = bin2hex(random_bytes(16))`, `lease_expires_at = NOW() + LEASE_SECONDS`.
  - **The fence:** every fenced write executes `UPDATE … WHERE capability = ? AND generation = ? AND owner_token = ?`, and throws `ActivationSuperseded` when `rowCount() !== 1`. A successful fenced write also extends the lease. `completeStep` appends the step to `steps_done` only if it's absent; `failStep` sets `status = failed`.
  - **`supersede`:** `generation = generation + 1`, `status = superseded`, lease cleared; log `superseded`.

  `ActivationRecord::nextStep()` returns the first entry of `ActivationStep::ALL` missing from `stepsDone`, or null.
- [ ] **Step 4: Run it.** Expected: PASS (6 tests). Also run phpcs and the RawPdo lint.
- [ ] **Step 5: Commit.** `feat(capabilities): an activation store with fenced ownership`.

---

## Task 3: install-role grants are serialized with their ledger

**Files:**
- Modify: `core/src/Setup/InstallRoleGrants.php` (`apply()` :58-71, `grantNew()` :173-219)
- Test: `tests/Integration/Setup/InstallRoleGrantsTest.php` (add tests), `tests/fixtures/install_role_grants_child.php`

**Interfaces:**
- Produces: `InstallRoleGrants::apply(?callable $stillOwner = null): InstallRoleGrantsReport`. `$stillOwner` is called inside the transaction, after the lock and before any grant. When it returns false, nothing is granted and the ledger is untouched; the report shows zero.

- [ ] **Step 1: Write the failing tests**

```php
public function testATakenOverRunnerCannotRestoreARevokedPermission(): void
{
    // A reads, pauses; B takes over, grants and records; an operator revokes; A resumes.
    $this->freshInstall();
    $this->grants()->apply(fn () => true);                          // B's run: grants everything
    $this->revoke('administrator', 'commerce.view');                 // the operator's decision
    $this->grants()->apply(fn () => false);                          // A resumes: no longer the owner
    self::assertNotContains('commerce.view', $this->roleSlugs('administrator'));
}

public function testAStaleLedgerReadCannotReGrantARevocation(): void
{
    // Even when the runner is still the owner, the ledger is read fresh inside the transaction.
    $this->freshInstall();
    $this->grants()->apply();
    $this->revoke('administrator', 'commerce.view');
    $this->grants()->apply();
    self::assertNotContains('commerce.view', $this->roleSlugs('administrator'));
}

public function testConcurrentRunsKeepEveryLedgerEntry(): void
{
    $this->freshInstall();
    $this->channel()->put('installed', '1');
    try {
        $this->runChildren('install_role_grants_child.php', [], 2);   // two applies at once
    } finally {
        $this->channel()->forget('installed');
    }
    $ledger = json_decode((string) $this->channel()->get(InstallRoleGrants::LEDGER_KEY), true);
    self::assertSame(count($this->allSlugs()), count($ledger['superuser']), 'no run overwrote the other');
}
```

(The child boots the app and calls `InstallRoleGrants::apply()`, as in Task 2's pattern.)

- [ ] **Step 2: Run it.** Expected: the first test FAILs. A stale run regrants today, because no ownership check exists.
- [ ] **Step 3: Implement.** In `apply()`:
  1. Keep `$before = $this->permissionSlugs()` and `$declared = $this->syncCatalog()` outside the transaction.
  2. Wrap the rest in `$db->transaction(function () use (...) { … })`, with `$db = $this->context->getContainer()->get(Connection::class)`. Inside it:
     1. `$db->getPDO()->exec("SELECT pg_advisory_xact_lock(hashtext('thallo:install-role-grants'))")`;
     2. `$this->channel()->clearCache()` when the channel is `SystemFlags`. Add `clearCache()` to a local `instanceof` check, so the `SystemChannel` contract stays unchanged;
     3. `if ($stillOwner !== null && !$stillOwner()) { return zero report; }`;
     4. `$ledger = $this->ledger()`, the grant loop, and the ledger `put`.
  3. Classify the raw `exec` in the RawPdo lint as a system writer.
- [ ] **Step 4: Run it.** `tests/Integration/Setup`. Expected: PASS.
- [ ] **Step 5: Commit.** `fix(setup): install-role grants decide and record inside one serialized transaction`. Add a changelog bullet under `[Unreleased]` → Fixed (adding the heading): "**Concurrent provisions can't overwrite each other's role grants**, and a permission you revoke stays revoked even when two runs overlap."

---

## Task 4: blocks reach every workspace, and a workspace created mid-activation isn't missed

**Files:**
- Create: `core/src/Capabilities/Activation/CapabilityBlockSeeder.php`, `core/src/Capabilities/Activation/CapabilityActivationLock.php`
- Modify:
  - `core/src/Content/Starter/TenantSeeder.php` (`seed()` :55): call the coordinator inside the starter transaction
  - `core/src/Content/Blocks/StarterBlockTypeSeeder.php` (`seedMissingAmong` :41): a unique violation counts as skipped
  - `tests/Unit/Tenancy/RawPdoScopingLintTest.php`: classify the fresh state reads
- Test:
  - `tests/Integration/Tenancy/CapabilityBlockSeedingTest.php`, extending `RetrofittedTenantTestCase`, which creates tenants A and B
  - `tests/Integration/Blocks/ContributedBlockTypeReconcilerTest.php`: a duplicate-insert case

**Interfaces:**
- Produces:
  - **`CapabilityActivationLock`:**
    - `exclusive(string $capability): void` and `shared(string $capability): void`: transaction-level advisory locks, keyed `hashtext('thallo:capability-activation:' || ?)`;
    - `freshState(string $capability): array{on: bool, preparing: bool}`: raw reads of `thallo_system_flags` (`capability.<id>.enabled`) and `capability_activations.status`, never the registry.
  - **`CapabilityBlockSeeder`**, as in the shared contracts.
  - **`TenantSeeder::seed()`** now calls `CapabilityBlockSeeder::seedNewWorkspace(string $tenantUuid)`. For each `FeatureManagementPolicy::activationCapabilities()` (see the note below), it takes `shared()`, reads `freshState()`, and seeds the explicit contributions when the capability is on or preparing. When it's preparing, it records the workspace as ready in the activation row. That's not a fenced runner write: it goes through `ActivationStore::markWorkspaceUnfenced`, under the row lock.

  **Task order:** Task 4 needs `activationCapabilities()` before Task 6 exists. Create `FeatureManagementPolicy` here with only `activationCapabilities()` (returning `['thallo.commerce', 'thallo.subscriptions']`) and `engineOf()`. Task 6 extends it.

- [ ] **Step 1: Write the failing tests**

```php
final class CapabilityBlockSeedingTest extends RetrofittedTenantTestCase
{
    public function testPreparationSeedsEveryWorkspaceWhileTheCapabilityIsOff(): void
    {
        $this->setCapability('thallo.commerce', false); // effectively off: definitions() would filter
        $created = $this->container()->get(CapabilityBlockSeeder::class)->seedAll('thallo.commerce');
        foreach ([self::$tenantAUuid, self::$tenantBUuid] as $tenant) {
            self::assertContains('product-grid', $created[$tenant]);
            self::assertNotNull($this->runAsTenant($tenant, fn () => $this->blocks()->findBySlug('product-grid')));
        }
    }

    public function testAWorkspaceCreatedWhilePreparingGetsTheBlocks(): void
    {
        $this->startPreparing('thallo.commerce');
        $tenant = $this->createWorkspace('mid-prep');           // TenantSeeder::seedAndActivate path
        self::assertNotNull($this->runAsTenant($tenant, fn () => $this->blocks()->findBySlug('product-grid')));
        self::assertSame('ready', $this->activation('thallo.commerce')->workspaces[$tenant] ?? null);
    }

    public function testAWorkspaceRequestThatBootedBeforeTheSwitchReadsFreshState(): void
    {
        // its registry still says off; activation finishes; then it creates the workspace
        $staleRequest = $this->bootSecondApp();                 // boots while Commerce is preparing
        $this->startPreparing('thallo.commerce');
        $this->finishActivation('thallo.commerce');             // state on, version advanced
        $tenant = $this->createWorkspaceWith($staleRequest, 'after-switch');
        self::assertNotNull($this->runAsTenant($tenant, fn () => $this->blocks()->findBySlug('product-grid')));
    }

    public function testFinalizationWaitsForAWorkspaceStillSeeding(): void
    {
        // a child holds the shared lock mid-seed; the parent's exclusive lock waits, then lists it active
        $this->startPreparing('thallo.commerce');
        $child = $this->startChildHoldingSharedLock('thallo.commerce', 'slow-seed');
        $listed = $this->finalizeAndListWorkspaces('thallo.commerce'); // takes exclusive()
        self::assertContains($child->tenantUuid(), $listed);
    }

    public function testRetryDropsAWorkspaceDeletedSinceItFailed(): void
    {
        $this->startPreparing('thallo.commerce');
        $this->recordWorkspace('thallo.commerce', 'gone00000001', 'failed');
        $result = $this->container()->get(CapabilityBlockSeeder::class)
            ->seedAll('thallo.commerce', $this->lease('thallo.commerce'), ['gone00000001']);
        self::assertArrayNotHasKey('gone00000001', $this->activation('thallo.commerce')->workspaces);
        self::assertSame([], $result);
    }
}
```

(The helpers sit in the test class. They wrap `ActivationStore`, `TenantAdministration::create` plus `TenantSeedActivator::seedAndActivate`, `AppTestCase::bootAppWithConfigOverride` for the second boot, and a `proc_open` child for the held lock.)

- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement.**
  - **`seedAll`:**
    - **With tenancy on** (`SystemFlags::tenancyEnabled()`): `TenantContextRunner::forEachTenant(fn ($t) => …)`, or `runAsTenant` for each of `$only`. A tenant that no longer exists is dropped from `workspaces` and skipped.
    - **With tenancy off:** seed the current store, recorded under the default tenant uuid (`SingleStoreTenant::defaultUuidOrNull()`, or `'single'`).
    - Each workspace's result is recorded through `$lease` when it's given (fenced `markWorkspace`).
    - Seeding is `StarterBlockTypeSeeder::seedMissingAmong($kind->contributionsFor($capability))`.
  - **`seedMissingAmong`:** catch `PDOException` with SQLSTATE `23505` around `create()`, and count that slug as skipped.
- [ ] **Step 4: Run it.** `tests/Integration/Tenancy tests/Integration/Blocks tests/Integration/Content/SeedBlockTypesTest.php`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(capabilities): capability blocks reach every workspace, coordinated with workspace creation`.

---

## Task 5: the activation runner, with the fresh-boot gate and the finalization contract

**Files:**
- Create: `core/src/Capabilities/Activation/ActivationRunner.php`, `core/src/Capabilities/Activation/EngineActivation.php`
- Modify: `core/src/Providers/CoreServiceProvider.php` (shared, autowired)
- Test: `tests/Integration/Capabilities/ActivationRunnerTest.php`

**Interfaces:**
- Consumes:
  - `ActivationStore` (Task 2), `CapabilityStateStore` and `CapabilityStateVersion` (Task 1);
  - `InstallRoleGrants::apply(?callable)` (Task 3);
  - `CapabilityBlockSeeder` and `CapabilityActivationLock` (Task 4);
  - `FeatureManagementPolicy::engineOf()`.
- Produces: `ActivationRunner::run(string $capability, int $generation, bool $freshBoot): ActivationRecord`, and a test seam: `ActivationRunner::$crashProbe`, a public static `?\Closure` called with `('before_commit' | 'after_commit')` around finalization. It's null in production.

**Steps the runner executes,** each under `acquire()`, each recorded with `completeStep` or `failStep`:

1. **`MARK_PREPARING`:** `CapabilityStateStore::put($capability, false)`, unless the store already reads false.
2. **`ENABLE_ENGINE`:** skipped when `ExtensionManager::hasProvider($provider)` is already true and the engine's schema is ready.
   - If application files aren't writable and the engine isn't enabled: `failStep` with the remedy `php glueful thallo:features:enable <id> --prepare` (run at deploy time).
   - Otherwise `EngineActivation::prepare()`: `migrateProtected`, `ExtensionStateWriter->enable`, `clearConfigCache`, `writeCacheNow`. If `writeCacheNow` throws, the result is `cache_stale`: record the step done, with `result.cache_stale = true`.
   - Then the runner **returns** (needs a fresh boot) unless `$freshBoot`.
3. **`VERIFY_BOOT`:** only when `$freshBoot`. Requires `hasProvider($provider)` and `CapabilityRegistry::availability($capability)->available`.
   - **On failure:** `failStep(VERIFY_BOOT, reason, 'php glueful extensions:cache')`, and the capability stays off.
   - **On success:** clear `result.cache_stale`.
4. **`SEED_BLOCKS`:** `CapabilityBlockSeeder::seedAll($capability, $lease)`. Record `result.blocks_created` as the total count, and fail when any workspace failed.
5. **`GRANT_PERMISSIONS`:** `InstallRoleGrants::apply(fn () => $store->find($capability)?->ownerToken === $lease->ownerToken && …generation === $lease->generation)`. Record `result.grants`.
6. **`FINALIZE`:** `withinFenced($lease, function () { … })`. Inside that one transaction:
   1. `CapabilityActivationLock::exclusive($capability)`;
   2. seed any workspace not yet `ready` (the readiness re-check);
   3. `crashProbe('before_commit')`;
   4. `CapabilityStateStore::put($capability, true)`, which advances the version;
   5. set `status = succeeded` and record `FINALIZE` done.

   After the commit: `crashProbe('after_commit')`, then release the lease.

- [ ] **Step 1: Write the failing tests** (one per row; the code shape is shown for the hard ones)

```php
public function testTheStepsRunInOrderAndTheCapabilityIsEffectiveOnlyAtTheEnd(): void
{
    $gen = $this->store()->startOrJoin('thallo.commerce', 'test')->generation;
    $record = $this->runner()->run('thallo.commerce', $gen, freshBoot: false);
    self::assertSame(ActivationStep::VERIFY_BOOT, $record->nextStep(), 'stops for a fresh boot');
    self::assertFalse($this->stateStore()->explicit('thallo.commerce'), 'preparing is off');
    $record = $this->freshBootRunner()->run('thallo.commerce', $gen, freshBoot: true);
    self::assertSame(ActivationStatus::SUCCEEDED, $record->status);
    self::assertTrue($this->stateStore()->explicit('thallo.commerce'));
    self::assertGreaterThan(0, $record->result['blocks_created']);
}

public function testACrashBeforeTheFinalizationCommitLeavesItOff(): void
{
    ActivationRunner::$crashProbe = static function (string $at): void {
        if ($at === 'before_commit') { throw new \RuntimeException('killed'); }
    };
    $version = $this->version()->current();
    $this->runToFinalize('thallo.commerce');
    self::assertFalse($this->stateStore()->explicit('thallo.commerce'));
    self::assertSame($version, $this->version()->current(), 'the version did not move');
    self::assertTrue($this->store()->find('thallo.commerce')->isOpen(), 'resumable');
}

public function testALostResponseAfterTheCommitDoesNotUndoTheActivation(): void
{
    ActivationRunner::$crashProbe = static function (string $at): void {
        if ($at === 'after_commit') { throw new \RuntimeException('response lost'); }
    };
    $this->runToFinalize('thallo.commerce');
    ActivationRunner::$crashProbe = null;
    self::assertTrue($this->stateStore()->explicit('thallo.commerce'), 'committed = on');
    $again = $this->freshBootRunner()->run('thallo.commerce', $this->gen('thallo.commerce'), true);
    self::assertSame(ActivationStatus::SUCCEEDED, $again->status, 'a retry sees it done, unchanged');
}

public function testTheFreshBootGateRefusesAMissingProvider(): void { /* boot without the provider in the extension cache; VERIFY_BOOT fails; off */ }
public function testCacheStaleStaysOffUntilTheGatePasses(): void { /* writeCacheNow throws → result.cache_stale; continue without provider fails; after extensions:cache, continue succeeds; same generation */ }
public function testAReadOnlyHostRefusesEnablingAnEngineUpFront(): void { /* EngineActivation::applicationFilesWritable() false, engine disabled → failStep ENABLE_ENGINE with the --prepare remedy */ }
public function testAPreparedEngineActivatesOnAReadOnlyHost(): void { /* engine enabled already; not writable; completes */ }
public function testAContinueAfterTurningOffIsRefusedAndLeavesItOff(): void { /* supersede mid-run; run() throws ActivationSuperseded; explicit false */ }
public function testATakenOverRunnerNeitherAdvancesNorActivates(): void { /* A holds lease at SEED_BLOCKS, lease expires, B completes to SUCCEEDED, A's completeStep throws; blocks not duplicated (unique) */ }
```

Fresh boots use `bootAppWithConfigOverride()`, and `freshBootRunner()` resolves `ActivationRunner` from that context. `EngineActivation` gets two test seams on its constructor: a `?\Closure $writable` and a `?\Closure $writeCache`. Production passes null, which means "use `HostCapability::forToggle()`" and "use `ExtensionManager::writeCacheNow()`".

- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement**, following the step list above. Wrap each step's work in `try { … } catch (ActivationSuperseded $e) { throw $e; } catch (\Throwable $e) { $store->failStep($lease, $step, $e->getMessage(), remedyFor($step)); return $store->find($capability); }`.
- [ ] **Step 4: Run it.** `tests/Integration/Capabilities`. Expected: PASS (all rows).
- [ ] **Step 5: Commit.** `feat(capabilities): the activation runner, with a fresh-boot gate and an all-or-nothing finalization`.

---

## Task 6: one management policy, enforced on the server and the CLI, from an audit

**Files:**
- Modify: `core/src/Capabilities/FeatureManagementPolicy.php` (the full class)
- Modify: `core/src/Providers/CoreServiceProvider.php` (`register()`: `$this->mergeConfig('extensions', ['protected' => $policy->protectedProviders()])`, next to the existing `mergeConfig` calls at :2697-2710)
- Modify: `core/src/Http/Controllers/ExtensionAdminController.php` (`installed()` :371 gains `management`; `toggle()` :273 refuses from the policy before the executor)
- Test: `tests/Integration/Capabilities/FeatureManagementPolicyTest.php`, `tests/Integration/Console/ManagedEngineCliTest.php`

**The audit (Step 0), recorded in the class docblock with evidence:**

```bash
for p in Aegis Users I18n Media Audit EmailNotification ImportExport Commerce Subscriptions Tenancy Payvia Meilisearch; do
  echo "== $p"; grep -rln "Glueful\\\\Extensions\\\\$p\\\\" core/src packages/*/src | head
done
```

The rule: a package is **required** when it's referenced without a `has()`/`class_exists()` guard on a path every install runs:
- `SetupService`, `ProvisionCommand`, `InstallRoleGrants`;
- authentication, the admin's `/v1/admin` bootstrap.

Otherwise it's **managed** (it backs a capability) or **independent**. The test pins the decision.

**Expected starting point** (the audit may move entries, and the test then follows the audit):
- **required:** `glueful/aegis` (RBAC: `InstallRoleGrants`), `glueful/users` (`SetupService` → `UserRepository`);
- **managed:** `glueful/commerce` → `thallo.commerce`, `glueful/subscriptions` → `thallo.subscriptions`, `glueful/tenancy` → Settings › Workspaces (already protected by the operator config);
- **decided by the audit:** `glueful/i18n`, `glueful/media`, `glueful/audit`, `glueful/email-notification`, `glueful/import-export`.

- [ ] **Step 1: Write the failing tests**

```php
public function testRequiredAndManagedProvidersAreProtectedWithoutTouchingTheOperatorsConfig(): void
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
    self::assertStringStartsWith('Ours.', ProtectedProviders::refusalFor($ctx, 'Glueful\\Extensions\\Commerce\\CommerceServiceProvider'));
}

public function testTheAdminToggleRefusesAManagedOrRequiredPackage(): void
{
    foreach (['glueful/commerce', 'glueful/aegis'] as $package) {
        $response = $this->controller()->disable($this->jsonRequest(['name' => $package]));
        self::assertSame(409, $response->getStatusCode(), $package);
    }
}

public function testInstalledReportsWhoManagesEachPackage(): void
{
    $byName = array_column($this->installedRows(), null, 'name');
    self::assertSame('managed', $byName['glueful/commerce']['management']['class']);
    self::assertSame('thallo.commerce', $byName['glueful/commerce']['management']['capability']);
    self::assertSame('required', $byName['glueful/aegis']['management']['class']);
}
```

`ManagedEngineCliTest` runs `php glueful extensions:disable glueful/aegis` and `extensions:enable glueful/commerce` in a subprocess against the test app (`proc_open`, `base_path('glueful')`, `APP_ENV=testing`). It asserts a non-zero exit code and output naming the reason. This is the CLI enforcement proof, with no framework change.

- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement.** Each `protectedProviders()` entry:
  - **reason for managed packages:** "Managed by Commerce: turn it on in Features, or run `php glueful thallo:features:enable thallo.commerce`.";
  - **reason for required packages:** "Required by Thallo.";
  - `managed_by`: `'thallo features'` or `'thallo (required)'`.

  `toggle()` checks `$policy->managementOf($name)['class'] !== independent` and returns 409 with that reason, before `hostToggleRefusal()`.
- [ ] **Step 4: Run it**, plus `tests/Integration/Http` (the extension admin tests). Expected: PASS.
- [ ] **Step 5: Commit**, with a changelog bullet under Changed: "**Thallo's own engines can't be switched off by accident.** Packages Thallo needs, and engines a feature manages (Commerce, Subscriptions), refuse the generic extension switch and `extensions:enable` / `extensions:disable`, and name the right place instead." Commit message: `feat(extensions): one management policy, enforced on the admin and the framework CLI`.

---

## Task 7: the activation API

**Files:**
- Create: `core/src/Http/Controllers/CapabilityActivationController.php`
- Modify:
  - `core/routes/admin.php`: next to the capability routes, all `content_permission:system.access`:
    - `POST /capabilities/{id}/activation` → `start`
    - `POST /capabilities/{id}/activation/continue` → `continue`, body `{generation}`
    - `DELETE /capabilities/{id}/activation` → `cancel`, body `{generation}`
  - `core/src/Http/Controllers/CapabilityAdminController.php`:
    - `manage()` adds per capability `management` (`activation` | `simple` | `workspaces`), `activation` (`ActivationRecord::toArray()` or null), `application_files_writable` and `engine_enabled`;
    - `update()` with `enabled: true` for an activation capability returns 409 with `{reason: 'use_activation'}`;
    - `update()` with `enabled: false` supersedes the open activation, then puts false (which advances the version).
- Modify: `docs/openapi.json` (regenerated: `CACHE_DRIVER=array composer docs:openapi`; hand-splice the changed operations if it dies on Redis), then `cd admin && pnpm gen:api`
- Test: `tests/Integration/Http/CapabilityActivationApiTest.php`

**Interfaces:**
- Produces responses:
  - `start` → 202 `{activation, continue: bool}` (`continue` is true when the next step is `verify_boot`);
  - `continue` → 200 `{activation, continue: false}`, or 409 `{reason: 'superseded'}`, or 409 `{reason: 'in_progress'}` when another runner holds a live lease;
  - `cancel` → 200 `{activation}`.

- [ ] **Step 1: Write the failing tests:**
  - `testStartReturnsTheOperationAndAsksForAContinue`;
  - `testContinueFinishesInAFreshRequest`, where the second request goes through `bootAppWithConfigOverride`;
  - `testAContinueForASupersededGenerationIsRefused`;
  - `testTwoStartsReturnTheSameGeneration`;
  - `testTheUpdateEndpointCannotTurnAnActivationCapabilityOnDirectly`;
  - `testTurningOffSupersedesAndAdvancesTheVersion`;
  - `testAnEngineDisabledOutsideThalloShowsUnavailable`: with the provider missing from the enabled list, `manage()` shows `available: false` with the remedy, and `start` begins a normal activation.

  Each asserts status code and body via `$this->handle($this->jsonRequest(...))`, as the existing `tests/Integration/Http` tests do.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement.** The controller calls `ActivationStore::startOrJoin`, then `ActivationRunner::run(..., freshBoot: false)` for `start`, and `freshBoot: true` for `continue`. The continue request is a new HTTP request, so it booted after `ENABLE_ENGINE`; the gate still verifies it. It maps `ActivationSuperseded` to 409 `superseded`, and a null `acquire` to 409 `in_progress`.
- [ ] **Step 4: Run it**, plus regenerate the OpenAPI spec and types. Expected: PASS; `pnpm type-check` is clean.
- [ ] **Step 5: Commit.** `feat(capabilities): the activation API`.

---

## Task 8: the feature-owned CLI, and provision's part

**Files:**
- Create: `core/src/Capabilities/Console/FeaturesEnableCommand.php` (`thallo:features:enable {capability} {--prepare}`), `FeaturesResumeCommand.php` (`thallo:features:resume {capability?}`), `FeaturesStatusCommand.php` (`thallo:features:status`)
- Modify:
  - `core/src/Providers/CoreServiceProvider.php`: DI definitions and the `commands([...])` list (:2956);
  - `core/src/Setup/Console/ProvisionCommand.php`: after role grants (:157), resume every open activation (runtime steps always, `ENABLE_ENGINE` only when application files are writable). After the extension cache step (:207), re-enable required providers that are disabled (`ExtensionStateWriter->enable` plus `writeCacheNow`, when writable), each with a line of output.
- Test: `tests/Integration/Console/FeaturesCommandsTest.php`

**Behaviour:**
- **`features:enable`:** runs `startOrJoin` and `run(freshBoot: false)`. When the next step is `verify_boot`, it starts a fresh process, `PHP_BINARY base_path('glueful') thallo:features:resume <id>`, streams its output and returns its exit code.
- **`--prepare`:** stops after `ENABLE_ENGINE` and prints "Prepared. Finish on the running site: Features, or `php glueful thallo:features:resume <id>`."
- **`features:resume`:** runs `run(freshBoot: true)` for one capability, or for every open activation.
- **`features:status`:** prints a table: capability, state (on, off, preparing, failed), step, error, remedy.

- [ ] **Step 1: Write the failing tests:**
  - `testEnableRunsToTheEndThroughAFreshProcess`;
  - `testPrepareStopsBeforeTheRuntimeSteps`;
  - `testResumeFinishesWithApplicationFilesReadOnly`: run `chmod` on `config/extensions.php` in a copied temp project, or pass the writable seam through an env var the command reads in testing only, `THALLO_TEST_APP_FILES_READONLY=1`;
  - `testExtensionsEnableOnAManagedEngineNamesThisCommand`;
  - `testProvisionResumesAnOpenActivation`;
  - `testProvisionReEnablesADisabledRequiredProvider`.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement**, following `CapabilitiesCommand`'s conventions: `#[AsCommand]`, `extends BaseCommand`, `getService()`, `Table`.
- [ ] **Step 4: Run it**, plus `tests/Integration/Setup`. Expected: PASS.
- [ ] **Step 5: Commit**, with a changelog bullet under Added: "**`thallo:features:enable`, `resume` and `status`.** Turn a feature on from the terminal, prepare it at deploy time with `--prepare` and finish on the running site, and see where every feature stands." Commit message: `feat(capabilities): a feature-owned CLI, and provision resumes activations and repairs required providers`.

---

## Task 9: Browse and the web installer are removed

**Files:**
- Delete: `admin/src/pages/extensions/components/BrowseExtensions.vue`
- Modify:
  - `admin/src/queries/extensions.ts`: remove `CatalogExtension`, `fetchExtensionCatalog`, `useExtensionCatalog`, `InstallStatus`, `InstallResult`, `installExtension` and `useExtensionInstall`;
  - `admin/src/queries/extensions.spec.ts`: drop the install tests;
  - `core/src/Http/Controllers/ExtensionAdminController.php`: remove `registry()`, `install()`, `PACKAGIST_SEARCH` and the now-unused imports;
  - `core/routes/admin.php`: remove the two routes;
  - `docs/openapi.json` and `admin/src/api/schema.d.ts`, regenerated;
  - docs: `docs/concepts/06-capabilities.md:121-122` and `docs/reference/02-configuration.md:307` (`EXTENSIONS_INSTALL_PHP_BINARY` and `COMPOSER_BINARY` lose their admin installer purpose; keep the variables, since the framework still reads them, but say so);
  - `.env.example`, lines 216-219: the same.
- Test: `tests/Integration/Http/ExtensionAdminApiTest.php`, or the existing extension admin test file: assert both routes now 404.

- [ ] **Step 1:** Write the failing route test: `GET /v1/admin/extensions/registry` and `POST /v1/admin/extensions/install` → 404.
- [ ] **Step 2:** Run it. Expected: FAIL (200 or 4xx from the handler).
- [ ] **Step 3:** Remove all of the above. `grep -rn "registry\|useExtensionInstall\|CatalogExtension\|extensions/install" admin/src core/src core/routes docs --include=*` must find nothing but changelog history.
- [ ] **Step 4:** Run the PHP test, vitest, `pnpm type-check` and lint. Expected: PASS.
- [ ] **Step 5: Commit**, with a changelog bullet under Removed: "**The Extensions page's Browse tab and the in-admin installer.** It listed framework packages, not Thallo features, and offered switches without the checks the rest of the page uses. A curated list of tested add-ons may follow." Commit message: `refactor(extensions): remove Browse and the web installer`.

---

## Task 10: the Features page

**Files:**
- Create:
  - `admin/src/pages/features/index.vue`: heading **Features**; views **Features** (default) and **Installed packages**;
  - `admin/src/pages/features/components/FeatureCard.vue`;
  - `ActivationProgress.vue`;
  - `InstalledPackages.vue`, moved from `InstalledExtensions.vue` with the management line and no raw switch for required or managed packages;
  - `admin/src/queries/capabilityActivation.ts`: `startActivation(id)`, `continueActivation(id, generation)`, `cancelActivation(id, generation)`, `useActivationFlow(id)`.
- Modify:
  - `admin/src/pages/extensions/index.vue`: becomes a redirect (`router.replace('/features')` in `onMounted`);
  - `admin/src/registry/coreModule.ts:28-32`: `{ label: 'Features', icon: 'i-lucide-toggle-right', to: '/features' }`;
  - `admin/src/queries/capabilityManagement.ts`: the `ManagedCapability` type gains `management`, `activation`, `application_files_writable` and `engine_enabled`.
- Delete: `admin/src/pages/extensions/components/CapabilityManagement.vue` and `InstalledExtensions.vue`, after the move.
- Test: `admin/src/__tests__/features-page.spec.ts`, `admin/e2e/tests/features-activation.spec.ts` (+ fixtures)

**Card states** (data-test `feature-<id>`):

| State | What the card shows |
|---|---|
| off | A switch |
| confirm | `UModal` (data-test `feature-confirm`) with the copy from the Global Constraints, and **Turn on** / **Cancel** |
| preparing | "Turning on Commerce…" with a spinner (data-test `feature-preparing`). It automatically calls `continueActivation` when the response says `continue: true`, and polls `manage` every 1.5 s while open |
| failed | The step message, **Retry** (data-test `feature-retry`), and **Cancel** |
| on | The summary from `activation.result` (for example "Added {{ result.blocks_created }} blocks"), with links to Products and Settings › Block types |
| read-only | When `!application_files_writable && !engine_enabled`, a notice with the `--prepare` command; no **Turn on** |
| workspaces | A link to Settings › Workspaces |
| open operation found on load | **Continue** and **Cancel** |

**After any completion:** invalidate `['capabilities']`, `['me']` and `['extensions']`, then call `useCapabilitiesStore().refreshUntilChanged()`.

- [ ] **Step 1: Write the failing vitest cases:**
  - `confirm then turn on calls start then continue`;
  - `a failed step shows its message and Retry continues the same generation`;
  - `superseded continue shows off`;
  - `read-only host offers the prepare command, no button`;
  - `an open operation on load offers Continue`;
  - `the summary reads the result count`;
  - `required package shows Required by Thallo and no switch`;
  - `/extensions redirects to /features`.

  Mock `authFetch` responses, as the existing `extensionsCapabilityManagement.spec.ts` does.
- [ ] **Step 2: Run them.** `npx vitest run src/__tests__/features-page.spec.ts`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the tests.**
  - **e2e:** a fixture-backed full Commerce activation. Mock `POST …/activation` → `{continue: true}`, `POST …/continue` → succeeded, and `manage` before and after.
  - **The full admin gate set** (type-check, lint, fmt:check, vitest, e2e on 8 workers).
  - **Expected:** PASS.
- [ ] **Step 5: Commit**, with a changelog bullet under Changed: "**Extensions is now Features.** Turn features on and off in one place. Commerce and Subscriptions turn on with one action that prepares everything, shows what was added, and can be retried if a step fails. Installed packages shows who manages each package." Commit message: `feat(admin): the Features page`.

---

## Task 11: route tables keyed by capability state (needs glueful/framework 1.88)

**Gate:** start only after the user has published `glueful/framework` 1.88.0 (Task F1).

**Files:**
- Modify: `composer.json` and `core/composer.json` (`"glueful/framework": "^1.88"`), then `composer update glueful/framework`
- Modify: `core/src/Providers/CoreServiceProvider.php` (`register()`): `RouteCache::contributeSignature('thallo.capability_state', fn () => $container->get(CapabilityStateVersion::class)->booted());`
- Test: `tests/Integration/Routing/CapabilityRouteTableTest.php`

- [ ] **Step 1: Write the failing tests:**
  - `testTurningCommerceOnServesItsRoutesOnTheNextRequest`: compile a route table with Commerce off; activate through the runner; boot a fresh app (`bootAppWithConfigOverride` with `ROUTE_CACHE` on); assert the commerce admin route matches;
  - `testTurningOffRemovesAccessOnTheNextRequest`;
  - `testAnOldRequestRebuildingItsTableAfterTheSwitchCannotMakeItUsable`: a `RouteCache` memoised under version N saves after the version advances; a fresh boot under N+1 rejects it (`load()` null) and serves the new table;
  - `testAFailureMidFinalizationKeepsTheOldRoutes`: the crash probe fires `before_commit`; a fresh boot still serves the old table, and the capability is off.
- [ ] **Step 2: Run them.** Expected: FAIL (the seam is unused).
- [ ] **Step 3: Implement** the one `contributeSignature` call.
- [ ] **Step 4: Run them**, plus the full PHP gate set.
- [ ] **Step 5: Commit.** `feat(capabilities): a route table compiled under an older capability state is never served`. Add a changelog bullet under Fixed: "**Turning a feature off removes its pages at once.** A compiled route table built before the switch is no longer served."

---

## Task 12: docs, changelog, and an end-to-end proof

**Files:**
- Modify:
  - `docs/concepts/06-capabilities.md`: the Features page, the activation flow, turning off, the management lines;
  - `docs/getting-started/01-introduction.md:58-68`, `02-install.md:157`;
  - every "Extensions › Capabilities" mention (`docs/documentation-sites.md:29`, `guides/17-accounts.md:22`, `18-commerce.md:36`, `19-subscriptions.md:31`, `02-header-and-footer.md:112`, `11-search.md:25`, `10-seo.md:14`, `12-import-content.md:15`, `concepts/03-design-view.md:28`, `reference/01-cli.md:23,802`) becomes "Features";
  - `docs/reference/01-cli.md`: the three new commands;
  - `docs/operations/05-troubleshooting.md`: each failure step, Retry, read-only hosts, `--prepare`;
  - `scripts/skeleton-smoke`: after provision and create-admin, run `php glueful thallo:features:enable thallo.commerce`, assert exit 0, and assert `thallo:features:status` shows `thallo.commerce` on.
- Test: `composer test:skeleton` (production-mode install, CLI activation end to end); `tests/Unit/Docs`.

- [ ] **Step 1:** Write the docs, and add the skeleton-smoke assertion.
- [ ] **Step 2:** Run `composer test:skeleton`. Expected: PASS, showing Commerce on with its blocks.
- [ ] **Step 3: The production repro.** On a kept skeleton install in production mode:
  1. Activate Commerce through `POST …/activation` and `…/continue` (via `php -r` boots).
  2. Check that `product-grid` exists.
  3. Check that the capability is on.
  4. Check that a pre-existing `routes_prod.php` was rejected.

  Record the result in the final message. Run no test suite in between: they share `app_test`.
- [ ] **Step 4: Commit.** `docs(features): the Features page, activation, and the feature CLI`.

---

## Final

- Run the full gates (Global Constraints).
- Review the whole branch with one fresh reviewer on the most capable model, against this plan and the spec.
- Collect the rulings and deferred minors into the final message.
- No beta cut until the user asks. Task F1's framework release is the user's.

## Self-review

- **Spec coverage:**
  - §3.1 page → Task 10. §3.2 steps, gate and finalization → Tasks 5 and 11.
  - §3.3 concurrency, interruptions, the endpoint bypass, failure and the deployment contract → Tasks 2, 5, 7 and 8.
  - §3.4 workspaces, including fresh reads → Task 4. §3.5 grants → Task 3. §3.6 turning off → Tasks 7 and 11.
  - §3.7 policy and audit → Task 6. §3.8 Browse removal → Task 9. §3.9 deferred (nothing to build).
  - §3.10 CLI, provision and required repair → Task 8. §4 tests → in each task. §5 docs → Task 12.
- **Placeholders:**
  - Task 5's last five tests are described in comments, not written out. Each states its arrange, act and assert precisely, and the executor writes them in the shape of the three shown.
  - Task 6's audit decides five packages, and the test pins the audit's result. That's a decision the code makes, not a placeholder.
- **Type consistency:**
  - `ActivationStore::acquire` returns `?ActivationLease`, used in Tasks 4, 5 and 7.
  - `InstallRoleGrants::apply(?callable)` is used in Task 5.
  - `CapabilityStateVersion::booted()` is used in Task 11.
  - `FeatureManagementPolicy::activationCapabilities()` is created in Task 4 and extended in Task 6.
- **Review Focus:** all five lines are pinned to tests in Tasks 4, 5, 6, 7 and 10.
