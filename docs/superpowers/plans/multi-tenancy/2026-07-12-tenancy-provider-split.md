# Tenancy Provider Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split `glueful/tenancy` into an always-on control-plane provider and an enablement-gated enforcement provider, make `SystemFlags::enforcementActive()` the canonical "tenant-aware work permitted" signal, and retire the temporary 2C Thallo workarounds.

**Architecture:** A new `TenancyControlPlaneProvider` (registered in Thallo's `config/serviceproviders.php`, always loaded) owns identity migrations, lifecycle/administration bindings, console commands, and config defaults. The existing `TenancyServiceProvider` FQCN is stripped to request-scoping enforcement and stays gated by the enablement machine via `config/extensions.php`. The machine gains a persisted `ENABLING_ENFORCEMENT` step: activation happens **after** retrofit success (barrier raised), and only after activation succeeds is `tenancy.enabled=1` + `RELOADING` set atomically. Disable gains `deactivate()` (provider removal) with the barrier staying raised in-process.

**Tech Stack:** PHP 8.3+, Glueful framework (ProviderClassResolver/ExtensionManager, `ExtensionStateWriter`, `MigrationPriority`), `glueful/tenancy` (vendored), Thallo packs, PHPUnit against real PostgreSQL.

## Global Constraints

- **Release chain:** `glueful/tenancy` **1.4.0** → Thallo. **No contracts change.** Vendor-first: edit `vendor/glueful/tenancy` in place, test live in Thallo, port to source + release later; pin after publish.
- **HOLD ALL COMMITS.** Stage only; never commit until explicit go-ahead. Work on `dev`. No attribution, no tags, never stage `CLAUDE.md`.
- **PHP style:** `declare(strict_types=1)`, `final` classes, constructor DI, `use`-imports, `composer phpcs` clean (120-char).
- **Exact persisted keys:** `tenancy.enabled`, `tenancy.enable_step`, `tenancy.retrofit_active` (the last is `RetrofitMaintenanceGuard::KEY`).
- **Signal model (pinned):** `isActivated()` = allow-listed for next boot; container probe = loaded this boot; `enforcementActive()` = tenant-aware work permitted; barrier = mutations blocked during `RELOADING`/`FINALIZING` (and the `ENABLING_ENFORCEMENT` crash window); `TenantRuntimeReadiness` = request-resolution readiness incl. finalization probing (never the activation signal).
- **Ordering invariants:** `tenancy.enabled=1` is never set before enforcement activation succeeds; activation failure → `FAILED` with `failed_from=ENABLING_ENFORCEMENT`; retry resumes activation **without rerunning retrofit**.
- **Cross-process freshness:** `enforcementActive()` clears the process-local `SystemFlags` cache once before reading its three-key snapshot; long-lived workers must observe remote enable and disable transitions.
- **Provider convergence:** `activate()`/`deactivate()` always regenerate the desired extension cache, even when the provider-list file already has the desired membership. File mutation alone is idempotently conditional; cache repair is never skipped.
- **`TenantManagementServices`** keeps lazy PSR-resolution **only as a deployment-failure backstop** — it is NOT `enforcementActive()`-gated.
- **Legacy steps** (`INSTALLING`, `AWAITING_INSTALL`, `ENABLING_EXTENSION`, `AWAITING_PROVIDER_BOOT`) become recovery-only aliases that advance to `MIGRATING_EXTENSION`.
- **Regression set:** the 11 tests that failed under the 2C binding experiment; plus full Thallo off/on suites and the engine suite.

---

## File Structure

**Engine (`vendor/glueful/tenancy/src/`):**
- `TenancyControlPlaneProvider.php` — CREATE: always-on identity/lifecycle provider.
- `TenancyServiceProvider.php` — MODIFY: strip to enforcement; hooks unconditional.
- `tests/Support/TenancyTestCase.php` (engine repo copy at port time; the vendored copy if present) — MODIFY: register both providers.

**Thallo pack (`packages/thallo-tenancy/src/`):**
- `System/SystemFlags.php` — MODIFY: add `enforcementActive()`.
- `Enablement/EnablementStep.php` — MODIFY: add `ENABLING_ENFORCEMENT`; legacy-alias handling.
- `Enablement/ExtensionActivationContract.php` + `Enablement/ExtensionActivation.php` — MODIFY: add `deactivate()`.
- `Enablement/TenancyEnablement.php` — MODIFY: enable/disable reordering.
- `TenancyServiceProvider.php` (pack) — MODIFY: retire 2C workarounds.
- `Tenant/SingleStoreTenant.php` — MODIFY: inject `TenantProvisioner` contract.

**Thallo app:**
- `config/serviceproviders.php` — MODIFY: add control-plane FQCN first.
- `app/Content/Scheduling/ScheduleRunner.php`, `app/Content/Indexing/EnsureFilterIndexesJob.php` — MODIFY: `enforcementActive()` (the workflow scheduled-publish acceptance exercises `ScheduleRunner`; it is not a third implementation).

**Admin SPA:**
- `admin/src/queries/tenancyEnablement.ts` — MODIFY: add `enabling_enforcement` to the step union.
- `admin/src/components/tenancy/EnablementPanel.vue` — MODIFY: resume action for the new state.
- `admin/src/__tests__/tenancyLifecyclePage.spec.ts` — MODIFY: action/presentation coverage.

**Tests:** `tests/Integration/Tenancy/CleanInstallIdentityPlaneTest.php` (polarity flip), `TenancyEnablementApiTest.php`, `TenantManagementApiTest.php`, new `EnablementOrderingTest.php`; existing enablement/disable suites updated.

---

### Task 1: Split the engine provider (control-plane + stripped enforcement)

**Files:**
- Create: `vendor/glueful/tenancy/src/TenancyControlPlaneProvider.php`
- Modify: `vendor/glueful/tenancy/src/TenancyServiceProvider.php`
- Modify: `config/serviceproviders.php`
- Test: `tests/Integration/Tenancy/CleanInstallIdentityPlaneTest.php` (polarity flip)

**Interfaces:**
- Produces: `Glueful\Extensions\Tenancy\TenancyControlPlaneProvider` binding `TenantProvisioner`, `TenantProvisioningRunner`, `TenantAdministration`, `TenantDomainAdministration`, `TenantContextRunner`, `ReleasedHostRepository`; owning `mergeConfig('tenancy', …)`, the identity migrations at `MigrationPriority::DEFAULT - 50`, and `discoverCommands`. The enforcement `TenancyServiceProvider` keeps only: `TenantMiddleware` (alias `tenant`), `TenantRequestMiddlewareContract`, `CurrentTenantResolver`, `TenantTableRegistryContract`, `TenantEnforcementProbe`, `TenantResolutionProbe`, `RowLevelStrategy` (alias `TenancyStrategyInterface`), `TenantAccess`, `ResolverChain`, `TenantResolutionPipeline`; its `boot()` registers the hooks **unconditionally** (no `config('tenancy.enabled')` gate) and no longer loads migrations/commands/config.

- [ ] **Step 1: Flip the failing boundary test**

Rewrite `tests/Integration/Tenancy/CleanInstallIdentityPlaneTest.php` to the new polarity:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioningRunner;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Thallo\Tenancy\System\SystemFlags;

final class CleanInstallIdentityPlaneTest extends AppTestCase
{
    public function testControlPlaneIsBoundWhileEnforcementIsAbsent(): void
    {
        // Control-plane: always available (service availability, NOT an activation signal).
        self::assertTrue($this->container()->has(\Thallo\Tenancy\Tenant\SingleStoreTenant::class));
        self::assertTrue($this->container()->has(TenantProvisioner::class));
        self::assertTrue($this->container()->has(TenantProvisioningRunner::class));
        self::assertTrue($this->container()->has(TenantAdministration::class));
        self::assertTrue($this->container()->has(TenantDomainAdministration::class));
        self::assertTrue($this->container()->has(TenantContextRunner::class));
        self::assertTrue($this->connection()->getSchemaBuilder()->hasTable('tenants'));

        // Enforcement: absent until the machine activates it.
        self::assertFalse($this->container()->has(CurrentTenantResolver::class));
        self::assertFalse($this->container()->has(TenantEnforcementProbe::class));
        self::assertFalse($this->container()->get(SystemFlags::class)->tenancyEnabled());
        self::assertSame([], TenantTableRegistry::all());

        $row = Connection::applyInsertHooks('collection_definitions', ['name' => 'probe']);
        self::assertArrayNotHasKey('tenant_uuid', $row);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=CleanInstallIdentityPlaneTest`
Expected: FAIL — `TenantProvisioner`/`TenantAdministration`/etc. are not bound off-mode (the engine provider isn't registered; the pack binds only the concrete `ContractTenantProvisioner`).

- [ ] **Step 3: Create `TenancyControlPlaneProvider`**

Create `vendor/glueful/tenancy/src/TenancyControlPlaneProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioningRunner;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantAdministration;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantDomainAdministration;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantProvisioner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantProvisioningRunner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantRunner;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;

/**
 * Tenancy CONTROL-PLANE: identity, lifecycle, and administration — everything that works without
 * request scoping. Always loaded (host apps register it in config/serviceproviders.php), so the
 * tenants schema and the provisioning/administration contracts exist on every install. Request
 * enforcement (CurrentTenantResolver, resolver pipeline, hooks, guards) lives in the separate,
 * enablement-gated TenancyServiceProvider. Binding presence here answers "is this service
 * available?" — never "is tenant enforcement active?" (that is the host's persisted flag).
 */
final class TenancyControlPlaneProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            TenantProvisioner::class => [
                'class' => ContractTenantProvisioner::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantProvisioningRunner::class => [
                'class' => ContractTenantProvisioningRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantAdministration::class => [
                'class' => ContractTenantAdministration::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantDomainAdministration::class => [
                'class' => ContractTenantDomainAdministration::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantContextRunner::class => [
                'class' => ContractTenantRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReleasedHostRepository::class => [
                'class' => ReleasedHostRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('tenancy', require __DIR__ . '/../config/tenancy.php');
    }

    public function boot(ApplicationContext $context): void
    {
        // Identity migrations: AFTER the identity store (IDENTITY = -100) but BEFORE app/feature
        // migrations (DEFAULT = 0), so `tenants` exists before any app tenant-owned table that
        // FKs or refers to tenants.uuid. Always loaded — clean-off installs included.
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEFAULT - 50,
            'glueful/tenancy'
        );

        $this->discoverCommands(
            'Glueful\\Extensions\\Tenancy\\Console',
            __DIR__ . '/Console'
        );
    }
}
```

- [ ] **Step 4: Strip the enforcement provider**

In `vendor/glueful/tenancy/src/TenancyServiceProvider.php`:
1. **Delete** from `services()`: the `TenantProvisioner`, `TenantProvisioningRunner`, `TenantAdministration`, `TenantDomainAdministration`, `TenantContextRunner`, `ReleasedHostRepository` entries (moved to control-plane). Keep: `TenantMiddleware`, `TenantRequestMiddlewareContract`, `CurrentTenantResolver`, `TenantTableRegistryContract`, `TenantEnforcementProbe`, `TenantResolutionProbe`, `RowLevelStrategy`, `TenantAccess`, `ResolverChain`, `TenantResolutionPipeline`.
2. **Delete** the now-unused `use` imports for the moved contracts/bridges (phpcs).
3. `register()`: **delete** the `mergeConfig` call (control-plane owns it); leave the method empty or remove it.
4. `boot()`: **delete** the `loadMigrationsFrom` block and `discoverCommands` (moved); **remove** the `if (\config($context, 'tenancy.enabled', true) === true)` gate so the four hook registrations (`TenantTableRegistry::loadFromConfig`, `registerTableHook()`, `QueryExecutor::addQueryInterceptor(new TenantQueryGuard())`, `Connection::addInsertHook(TenantInsertStamper::hook())`) run unconditionally — keep the try/catch envelope. Update the class docblock: this provider is enforcement-only and enablement-gated; presence means "enforcement machinery loaded."

- [ ] **Step 5: Register the control-plane provider in Thallo**

In `config/serviceproviders.php`:

```php
return [
    'enabled' => [
        'Glueful\\Extensions\\Tenancy\\TenancyControlPlaneProvider',
        'App\\Providers\\ThalloServiceProvider',
    ],
];
```

(App providers load before all extension providers — verified in `ProviderClassResolver::resolve()` — so control-plane bindings and migrations precede every pack.)

- [ ] **Step 6: Run the boundary test + tenancy subset**

Run: `vendor/bin/phpunit --filter=CleanInstallIdentityPlaneTest` then `vendor/bin/phpunit tests/Integration/Tenancy`
Expected: the boundary test PASSES. Some enablement-machine tests may fail until Tasks 3–5 land — record failures; only boundary/identity tests must be green here.

- [ ] **Step 7: Stage (HOLD)**

```bash
git add config/serviceproviders.php tests/Integration/Tenancy/CleanInstallIdentityPlaneTest.php
# vendor/ is git-ignored in Thallo; the engine edits port to the source repo at release time.
# HOLD.
```

---

### Task 2: `SystemFlags::enforcementActive()` + `ENABLING_ENFORCEMENT` step + `deactivate()`

**Files:**
- Modify: `packages/thallo-tenancy/src/System/SystemFlags.php`
- Modify: `packages/thallo-tenancy/src/Enablement/EnablementStep.php`
- Modify: `packages/thallo-tenancy/src/Enablement/ExtensionActivationContract.php`
- Modify: `packages/thallo-tenancy/src/Enablement/ExtensionActivation.php`
- Test: `tests/Integration/Tenancy/EnablementOrderingTest.php` (new — predicate cases here; machine cases in Tasks 3–4)
- Test: `tests/Unit/Tenancy/Enablement/ExtensionActivationTest.php` (partial file/cache convergence)
- Test: `tests/Integration/Tenancy/EnableFullMachineAcceptanceTest.php` (contract fake gains `deactivate()`)

**Interfaces:**
- Produces:
  - `SystemFlags::enforcementActive(): bool` — exactly:
    ```php
    public function enforcementActive(): bool
    {
        $this->clearCache();

        return $this->tenancyEnabled()
            && $this->get('tenancy.enable_step') === 'on'
            && $this->get('tenancy.retrofit_active') !== '1';
    }
    ```
  - `EnablementStep::ENABLING_ENFORCEMENT = 'enabling_enforcement'` (persisted, between `RETROFITTING` and `RELOADING`; `needsFreshBoot()` remains unchanged; `progress()` returns `85`).
  - `ExtensionActivationContract::deactivate(): void`; both activation directions converge the provider-list file **and** regenerated cache after partial failure.

- [ ] **Step 1: Write the failing predicate tests**

Create `tests/Integration/Tenancy/EnablementOrderingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\System\SystemFlags;

final class EnablementOrderingTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->getPDO()
            ->exec("DELETE FROM thallo_system_flags WHERE key IN "
                . "('tenancy.enabled','tenancy.enable_step','tenancy.retrofit_active')");
        parent::tearDown();
    }

    private function flags(): SystemFlags
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->clearCache();
        return $flags;
    }

    public function testEnforcementActiveOnlyWhenOnAndBarrierDown(): void
    {
        // Off.
        self::assertFalse($this->flags()->enforcementActive());

        // RELOADING window: enabled=1 but step not 'on' → NOT active.
        $f = $this->flags();
        $f->put('tenancy.enabled', '1');
        $f->put('tenancy.enable_step', 'reloading');
        self::assertFalse($this->flags()->enforcementActive());

        // ON but barrier still up → NOT active.
        $f = $this->flags();
        $f->put('tenancy.enable_step', 'on');
        $f->put('tenancy.retrofit_active', '1');
        self::assertFalse($this->flags()->enforcementActive());

        // ON, barrier down → active.
        $this->flags()->put('tenancy.retrofit_active', '0');
        self::assertTrue($this->flags()->enforcementActive());
    }

    public function testEnforcementActiveRefreshesRemoteLifecycleChanges(): void
    {
        $worker = new SystemFlags($this->appContext());
        $writer = new SystemFlags($this->appContext());

        self::assertFalse($worker->enforcementActive()); // prime the worker's process cache
        $writer->put('tenancy.enabled', '1');
        $writer->put('tenancy.enable_step', 'on');
        $writer->put('tenancy.retrofit_active', '0');
        self::assertTrue($worker->enforcementActive());

        $writer->put('tenancy.enable_step', 'disabling');
        self::assertFalse($worker->enforcementActive());
    }
}
```

The teardown SQL is already pinned to the verified `thallo_system_flags(key,value,updated_at)` migration shape.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=EnablementOrderingTest`
Expected: FAIL — `enforcementActive()` undefined.

- [ ] **Step 3: Implement the three additions**

`SystemFlags`: add `enforcementActive()` exactly as pinned above. It clears once, then `tenancyEnabled()` loads one fresh `all()` snapshot and the next two `get()` calls reuse it. Add a one-line doc: "Normal tenant-aware work is permitted — fresh ON-and-barrier-down snapshot. The ONLY activation predicate consumers may use."

`EnablementStep`: add `case ENABLING_ENFORCEMENT = 'enabling_enforcement';`, add `self::ENABLING_ENFORCEMENT => 85` to `progress()`, and keep it out of `needsFreshBoot()`. Add a docblock line: "retrofit succeeded, barrier raised, enforcement provider being allow-listed; `tenancy.enabled` is still 0. A crash here may leave enforcement loaded on the next boot — the raised barrier protects that window."

`ExtensionActivationContract` + every test fake: add `deactivate()`. Rewrite **both** directions so retries repair a stale cache even when the provider-list file already has the desired state:

```php
    public function activate(): void
    {
        $candidates = $this->candidates();
        if (!isset($candidates[self::PACKAGE])) {
            throw new \RuntimeException('glueful/tenancy is not installed.');
        }

        $current = EnabledProviders::from($this->context);
        $enabled = in_array(self::PROVIDER, $current, true)
            ? $current
            : [...$current, self::PROVIDER];
        $resolution = (new ExtensionResolver())->resolve($candidates, $enabled, Version::VERSION);
        if ($resolution->hasErrors()) {
            throw new \RuntimeException(implode('; ', array_map(
                static fn ($error): string => $error->message,
                $resolution->errors,
            )));
        }

        if (!in_array(self::PROVIDER, $current, true)) {
            (new ExtensionStateWriter())->enable(config_path($this->context, 'extensions.php'), self::PROVIDER);
        }
        // ALWAYS run: this is the repair path when the file write succeeded but cache write failed.
        app($this->context, ExtensionManager::class)->writeCacheNow($resolution->providers);
    }

    public function deactivate(): void
    {
        $current = EnabledProviders::from($this->context);
        $enabled = array_values(array_diff($current, [self::PROVIDER]));
        $resolution = (new ExtensionResolver())->resolve($this->candidates(), $enabled, Version::VERSION);
        if ($resolution->hasErrors()) {
            throw new \RuntimeException(implode('; ', array_map(
                static fn ($error): string => $error->message,
                $resolution->errors,
            )));
        }

        if (in_array(self::PROVIDER, $current, true)) {
            (new ExtensionStateWriter())->disable(config_path($this->context, 'extensions.php'), self::PROVIDER);
        }
        // ALWAYS run: absence from the file does not prove the generated cache is current.
        app($this->context, ExtensionManager::class)->writeCacheNow($resolution->providers);
    }
```

Extend `ExtensionActivationTest` with a temporary app-root fixture (literal `config/extensions.php`, writable `bootstrap/cache`, and a read-only symlink to the current test `vendor/` so `PackageManifest` sees the real candidate) and two recovery tests: (1) provider already listed with a deliberately stale cache, `activate()` rewrites the cache containing the provider; (2) provider already absent with a stale cache containing it, `deactivate()` rewrites the cache without it. Never mutate the repository's real config or cache. Update `EnableFullMachineAcceptanceTest`'s anonymous `ExtensionActivationContract` fake with `deactivate()`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter='EnablementOrderingTest|ExtensionActivationTest'`
Expected: predicate and both provider-cache convergence tests PASS.

- [ ] **Step 5: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/System/SystemFlags.php \
        packages/thallo-tenancy/src/Enablement/EnablementStep.php \
        packages/thallo-tenancy/src/Enablement/ExtensionActivationContract.php \
        packages/thallo-tenancy/src/Enablement/ExtensionActivation.php \
        tests/Integration/Tenancy/EnablementOrderingTest.php \
        tests/Unit/Tenancy/Enablement/ExtensionActivationTest.php \
        tests/Integration/Tenancy/EnableFullMachineAcceptanceTest.php
# HOLD.
```

---

### Task 3: Enable-transition reordering (`confirm()` + `begin()` + legacy aliases)

**Files:**
- Modify: `packages/thallo-tenancy/src/Enablement/TenancyEnablement.php`
- Modify: `admin/src/queries/tenancyEnablement.ts`
- Modify: `admin/src/components/tenancy/EnablementPanel.vue`
- Test: `tests/Integration/Tenancy/EnableFullMachineAcceptanceTest.php`
- Test: `tests/Unit/Tenancy/Enablement/TenancyEnablementRecoveryTest.php`
- Test: `admin/src/__tests__/tenancyLifecyclePage.spec.ts`

**Interfaces:**
- Consumes: `ENABLING_ENFORCEMENT`, `ExtensionActivation::activate()/deactivate()`, `EnablementStore::compareAndSet/recordFailure/failedFrom`.
- Produces: the reordered `confirm()` flow; `begin()` simplified; legacy steps as recovery-only aliases.

- [ ] **Step 1: Write the failing ordering tests**

Extend `EnableFullMachineAcceptanceTest`'s existing `RetrofitHarnessTestCase` flow. Replace its anonymous activation fake with a file-local `RecordingExtensionActivation` implementing every contract method and exposing `activateCalls`, `deactivateCalls`, and `failNextActivation`. Add these executable cases:

```php
    public function testActivationFailureLeavesResumablePreReloadingState(): void
    {
        $activation = new RecordingExtensionActivation(failNextActivation: true);
        $service = $this->advanceToAwaitingConfirm($activation);
        $failed = $service->confirm('acme', 'Acme', 'user00000001');

        self::assertSame(EnablementStep::FAILED, $failed->step);
        self::assertSame(EnablementStep::ENABLING_ENFORCEMENT, $this->store()->failedFrom());
        self::assertFalse($this->flags()->tenancyEnabled());
        self::assertSame(1, $activation->activateCalls);

        self::assertSame(EnablementStep::ENABLING_ENFORCEMENT, $service->retry()->step);
        self::assertSame(EnablementStep::RELOADING, $service->begin()->step);
        self::assertSame(2, $activation->activateCalls);
        self::assertSame(0, $this->retrofitAccessProbe()->accesses);
    }

    public function testLegacyStepsAdvanceToMigratingExtension(): void
    {
        foreach ([
            EnablementStep::INSTALLING,
            EnablementStep::AWAITING_INSTALL,
            EnablementStep::ENABLING_EXTENSION,
            EnablementStep::AWAITING_PROVIDER_BOOT,
        ] as $legacy) {
            $this->resetEnablementFlags();
            $this->store()->setStep($legacy);
            self::assertSame(EnablementStep::MIGRATING_EXTENSION, $this->service()->begin()->step);
        }
    }
```

Add the named helpers shown above to the existing harness using its verified `service()`, `SystemFlags`, and `EnablementStore` access. For the no-retrofit proof, construct the resumed service with a small `ContainerInterface` decorator that increments `accesses` and throws if `has(SchemaRetrofit::class)` or `get(SchemaRetrofit::class)` is called; delegate every other service. A successful `ENABLING_ENFORCEMENT → RELOADING` transition with `accesses === 0` is the executable proof that resume never enters `resolveRetrofit()`. The test must compile before implementation begins; no comment-only body remains.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter=EnablementOrderingTest`
Expected: FAIL — machine still activates at `ENABLING_EXTENSION` and sets `enabled=1` directly after retrofit.

- [ ] **Step 3: Reorder `confirm()`**

In `TenancyEnablement::confirm()` (currently: retrofit → `$this->flags->put('tenancy.enabled', '1'); $this->store->setStep(EnablementStep::RELOADING);`), replace the post-retrofit tail with:

```php
            // Retrofit succeeded; barrier stays raised. Allow-list the enforcement provider FIRST —
            // tenancy.enabled is NEVER set before activation succeeds, so an activation/config-write
            // failure leaves a resumable pre-RELOADING state (retry re-runs activation only).
            if (!$this->store->compareAndSet(
                EnablementStep::RETROFITTING,
                EnablementStep::ENABLING_ENFORCEMENT,
            )) {
                throw new EnablementException('Retrofit completion transition lost a CAS race.');
            }
            try {
                $this->activation->activate();
            } catch (\Throwable $exception) {
                $this->store->recordFailure(EnablementStep::ENABLING_ENFORCEMENT, $exception->getMessage());
                return $this->status();
            }

            $this->connection->transaction(function (): void {
                $this->flags->put('tenancy.enabled', '1');
                if (!$this->store->compareAndSet(
                    EnablementStep::ENABLING_ENFORCEMENT,
                    EnablementStep::RELOADING,
                )) {
                    throw new EnablementException('Enforcement activation transition lost a CAS race.');
                }
            });
```

Extract the activation + checked transaction into one private `activateEnforcement(): EnablementStatus` helper. `confirm()` sets `ENABLING_ENFORCEMENT` after retrofit and calls it; `begin()` calls it directly when the persisted step is `ENABLING_ENFORCEMENT`. Catch activation **or transaction** failure outside the transaction and `recordFailure(ENABLING_ENFORCEMENT, ...)`. Thus `retry()` restores that exact state and the next `begin()` never enters `resolveRetrofit()`.

- [ ] **Step 4: Rework `begin()`**

1. **Fresh enable path:** `OFF` (and the legacy aliases) advance to `MIGRATING_EXTENSION` directly — the package is a hard composer dep and the control-plane provider is always loaded, so `INSTALLING`/`AWAITING_INSTALL`/`ENABLING_EXTENSION`/`AWAITING_PROVIDER_BOOT` are **recovery-only aliases**: a persisted legacy value is accepted and routed into the `MIGRATING_EXTENSION` handler (do not delete the enum cases — persisted state may hold them).
2. **Re-enable path (`DISABLED_WIDENED`):** after the settled-pair check and barrier raise/cache purge, use a checked CAS `DISABLED_WIDENED → ENABLING_ENFORCEMENT`, then call the same `activateEnforcement()` helper. The helper's checked CAS is therefore identical for initial enable and re-enable.
3. **Admin exhaustiveness:** add `'enabling_enforcement'` to the TypeScript union and `EnablementPanel` begin/resume action list; add `self::ENABLING_ENFORCEMENT => 85` to backend progress; extend `tenancyLifecyclePage.spec.ts` with `['enabling_enforcement', 'enablement-action-begin']`. Include the admin typecheck + Vitest file in Step 5's verification commands.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter='EnableFullMachineAcceptanceTest|TenancyEnablementRecoveryTest|EnablementOrderingTest'`, then the pack's enablement suites (`vendor/bin/phpunit tests/Integration/Tenancy tests/Unit/Tenancy`), then `pnpm --dir admin type-check` and `pnpm --dir admin test src/__tests__/tenancyLifecyclePage.spec.ts` (verified against `admin/package.json`).
Expected: ordering tests PASS; update existing enablement tests that asserted the old `ENABLING_EXTENSION`-time activation or the old step sequence (assert the new sequence instead — do not weaken assertions).

- [ ] **Step 6: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Enablement/TenancyEnablement.php \
        packages/thallo-tenancy/src/Enablement/EnablementStep.php \
        tests/Integration/Tenancy/EnableFullMachineAcceptanceTest.php \
        tests/Unit/Tenancy/Enablement/TenancyEnablementRecoveryTest.php \
        admin/src/queries/tenancyEnablement.ts \
        admin/src/components/tenancy/EnablementPanel.vue \
        admin/src/__tests__/tenancyLifecyclePage.spec.ts
# HOLD.
```

---

### Task 4: Disable-transition sequencing (6-step, `deactivate()`)

**Files:**
- Modify: `packages/thallo-tenancy/src/Enablement/TenancyEnablement.php` (disable path)
- Test: `tests/Integration/Tenancy/DisableRoundTripAcceptanceTest.php` + existing disable suites

**Interfaces:**
- Consumes: `ExtensionActivation::deactivate()` (Task 2), `DisableGates`, `DisableProbe`, the disable sentinel.
- Produces: the pinned sequence — (1) CAS `ON → DISABLING`; (2) raise barrier + pass gates; (3) purge cache + write sentinel; (4) `deactivate()`; (5) atomically `tenancy.enabled=0` + `DISABLED_WIDENED`; (6) return requiring fresh boot. Barrier **stays raised in-process** after provider removal (static hooks remain registered); the fresh boot has no enforcement provider; `DisableProbe` verifies compat then lowers the barrier.

- [ ] **Step 1: Write the failing test**

Extend `DisableRoundTripAcceptanceTest` with a `RecordingExtensionActivation` passed through a local `service()` helper (the same shape used by `EnableFullMachineAcceptanceTest`):

```php
    public function testDisableDeactivatesProviderBeforeFlagsAndKeepsBarrierRaised(): void
    {
        $activation = new RecordingExtensionActivation(activated: true);
        $disabled = $this->service($activation)->disable();

        self::assertSame(1, $activation->deactivateCalls);
        self::assertSame(EnablementStep::DISABLED_WIDENED, $disabled->step);
        self::assertFalse($this->flags()->tenancyEnabled());
        self::assertSame('1', $this->flags()->get('tenancy.retrofit_active'));
    }
```

Add a second case where `deactivate()` fails once: assert `FAILED`/`failed_from=DISABLING`, retry restores `DISABLING`, and the next `disable()` converges. These are executable adaptations of the existing acceptance harness, not comment-only placeholders.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter=testDisableDeactivatesProviderBeforeFlagsAndKeepsBarrierRaised`
Expected: FAIL — disable does not deactivate the provider today.

- [ ] **Step 3: Insert `deactivate()` into the disable sequence**

In the disable path (read it fully first — it already does CAS, gates, cache purge + sentinel, and the atomic flag+step write): insert `$this->activation->deactivate();` between the sentinel write and the atomic `enabled=0` + `DISABLED_WIDENED` transaction. A `deactivate()` failure records `FAILED` with `failed_from=DISABLING` and is retryable. Do **not** lower the barrier anywhere in this process — confirm the existing code already leaves it to the fresh-boot `DisableProbe` path, and add a comment: "The current process retains the static enforcement hooks (table hook/guard/stamper are process-global), so the barrier must stay raised after provider removal; the fresh boot has no enforcement provider and DisableProbe lowers it."

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit --filter=DisableRoundTripAcceptanceTest` + the pack's disable suites.
Expected: PASS (update disable tests asserting the old sequence).

- [ ] **Step 5: Stage (HOLD)** — the enablement file + tests.

---

### Task 5: Thallo retirement of the 2C workarounds + consumer migration

**Files:**
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php` (pack)
- Modify: `packages/thallo-tenancy/src/Tenant/SingleStoreTenant.php`
- Modify: `app/Content/Scheduling/ScheduleRunner.php`, `app/Content/Indexing/EnsureFilterIndexesJob.php`
- Test: `tests/Integration/Tenancy/TenancyEnablementApiTest.php`, `TenantManagementApiTest.php` re-anchored; consumer tests

**Interfaces:**
- Consumes: control-plane bindings (Task 1), `SystemFlags::enforcementActive()` (Task 2).
- Produces: pack `boot()` without the engine-migration reflection-load; pack `services()` without the `ContractTenantProvisioner` concrete rebinding; `SingleStoreTenant` constructor takes `TenantProvisioner` (contract); activation-proxy consumers gate on `enforcementActive()`.

- [ ] **Step 1: Retire the pack workarounds**

In the pack `TenancyServiceProvider`:
1. `boot()`: delete the reflection-resolved engine-migrations `loadMigrationsFrom` block (control-plane owns it) + its comment.
2. `services()`: delete the `ContractTenantProvisioner` self-binding entry + its `use` import.

In `SingleStoreTenant`: change the constructor dependency from `ContractTenantProvisioner` (concrete) to the `Glueful\Extensions\Contracts\Tenancy\TenantProvisioner` contract (always bound by control-plane); update the `use` import and any pack DI registration arguments.

- [ ] **Step 2: Migrate the activation-proxy consumers**

For each, replace the binding-presence activation check with `SystemFlags::enforcementActive()` (inject `SystemFlags`); keep nullable service params where the service itself is optionally used:
- `ScheduleRunner`: the decision "run per-tenant vs single-store" gates on `enforcementActive()`; the `?TenantContextRunner` stays (control-plane always binds it now — presence is no longer a signal).
- `EnsureFilterIndexesJob` (line ~80): an explicit-null payload remains the sole clean-off direct path. For a valid tenant UUID, require both `enforcementActive()===true` and `TenantContextRunner`; otherwise throw so the queue retries rather than reconciling unscoped.
- There is no third workflow implementation: `WorkflowScheduledPublishTest` exercises `ScheduleRunner`, whose missing-`tenant_uuid` demand is covered by the first bullet.
- `TenantManagementServices`: **no change to its gate semantics** — lazy PSR-resolution stays purely as a deployment-failure backstop. Only its comment updates: the services are expected to be always-bound by the control-plane provider; degrade fires only on genuine deployment failure (missing `serviceproviders.php` entry).

- [ ] **Step 3: Re-anchor the boundary tests**

`TenancyEnablementApiTest::testResolutionServiceResolvesAndReportsInactiveWhileTenancyIsOff`: its precondition `assertFalse(has(TenantDomainAdministration))` flips — the contract IS bound off-mode now; the test's real assertion (resolution status reports `inactive`) re-anchors on `enforcementActive() === false` / the absence of `CurrentTenantResolver`. `TenantManagementApiTest`: listing still 200-empty off-mode (control-plane bound but zero tenants — verify the directory’s off-mode behavior remains, now via real services returning empty rather than degrade). Update `FullResolutionActivation`'s inactive-reporting if it keyed on the missing binding (read `makeFullResolutionActivation` + `status()`; key its `inactive` on the resolution-activation store / readiness, not `has()`).

- [ ] **Step 4: Run the consumer + tenancy suites**

Run: `vendor/bin/phpunit tests/Integration/Tenancy tests/Integration/Workflow tests/Unit/Tenancy` and the setup/collections suites (`--filter=SetupServiceTest`, `tests/Integration/Collections`).
Expected: PASS — including the previously-failing 11-test regression set, now green **with** control-plane bound.

- [ ] **Step 5: Stage (HOLD)** — pack provider, `SingleStoreTenant`, the two consumers, updated tests.

---

### Task 6: Full regression, engine suite, docs & porting note

**Files:**
- Modify: `docs/superpowers/specs/multi-tenancy/LIFECYCLE-GAPS-README.md` (or tracking index) + `docs/operations/tenancy.md`
- Test: everything.

- [ ] **Step 1: Full Thallo suites**

Run: `composer test` (off-mode) and the tenancy-on suite.
Expected: PASS. The 11-test 2C regression set is the explicit checklist — enumerate and confirm each. Investigate any failure via systematic-debugging.

- [ ] **Step 2: Engine suite (vendored)**

Run the engine's own tests against the split (its `TenancyTestCase`/harness must register **both** providers — update it in the vendored copy so the port carries the fix).
Expected: PASS (177+ tests).

- [ ] **Step 3: phpcs**

Run: `composer phpcs` — clean; `phpcbf` for mechanical fixes. Match engine style in the vendored files.

- [ ] **Step 4: Live smoke on `lemma`**

`php glueful migrate:run` (no pending surprises), then browser: `/v1/admin/api-keys`, `/v1/admin/tenancy/tenants` (200-empty off-mode), one analytics/navigation page (no premature scoping), setup/seed path intact. This class of bug historically surfaced only live — do not skip.

- [ ] **Step 5: Docs + porting note**

Update `docs/operations/tenancy.md` with the two-provider model + the `serviceproviders.php` upgrade note (existing installs must merge the control-plane FQCN; `extensions:enable` cannot manage it). Update the tracking index: provider split implemented (HELD). Porting note for release: port `TenancyControlPlaneProvider` + stripped `TenancyServiceProvider` + engine test-harness updates to the `glueful/tenancy` source repo → release **1.4.0** (Upgrade Notes: the `serviceproviders.php` requirement; the removed `config('tenancy.enabled')` boot gate; activation-timing change is host-machine-owned) → pin `^1.4.0` in Thallo. Update memory `project_tenancy_provider_split` to "implemented (HELD)" at commit time.

- [ ] **Step 6: Stage (HOLD)** — docs + index. Do not commit until the user gives the go-ahead.

---

## Self-Review

**1. Spec coverage:** §1 partition → Task 1 (binding lists match the verified `services()` inventory exactly). §2 registration/terminology → Tasks 1, 2 (serviceproviders.php; `deactivate()`; terminology in docblocks). §3 predicate + consumer migration → Tasks 2, 5. §4 enable reordering (`ENABLING_ENFORCEMENT`, never-enabled-before-activation, resumable FAILED, legacy aliases, re-enable path) → Task 3. §5 disable 6-step + in-process barrier → Task 4. §6 Thallo retirement → Tasks 1, 5. §7 release/upgrade notes → Task 6. §8 failure modes (crash windows) → Tasks 3–4 tests + the `ENABLING_ENFORCEMENT` docblock. §9 testing → per-task + Task 6. §10 out of scope — nothing here touches contracts or the manifest rule. ✅

**2. Placeholder scan:** Tasks 3–4 now name the existing acceptance harnesses, concrete recording fake, setup, actions, and assertions. No comment-only test body or "fill in later" instruction remains. Activation cache-repair tests use a temporary app root and never mutate the repository config.

**3. Type/state consistency:** `enforcementActive()` refreshes once and has the same signature in Tasks 2/5; `ENABLING_ENFORCEMENT` / `'enabling_enforcement'` is present in the PHP enum, progress match, TypeScript union, UI action map, and tests; `deactivate(): void` is on the contract, implementation, and every fake; both provider directions always regenerate cache; control-plane binding lists match Task 1; `SingleStoreTenant` injects the `TenantProvisioner` contract consistently.

**4. Consumer inventory:** exactly two production activation-proxy sites change: `ScheduleRunner` and `EnsureFilterIndexesJob`. `WorkflowScheduledPublishTest` is acceptance coverage for `ScheduleRunner`, not a third code path. The job's tenant-bearing inactive path is explicitly fail-closed.

**One deliberate scope note:** engine-side `TenancyTestCase` update rides in Task 6 Step 2 rather than its own task — it is harness plumbing whose only observable is the engine suite passing.

---

## Execution Record (2026-07-12)

Implemented across Thallo and the `glueful/tenancy` source repository; all changes remain HELD and uncommitted.

- Added the always-on control-plane provider and reduced the existing provider to enforcement-only bindings/hooks.
- Reordered first enable, retry, re-enable, and disable around the explicit `ENABLING_ENFORCEMENT` boundary; activation/deactivation cache regeneration is convergent.
- Added the fresh `SystemFlags::enforcementActive()` predicate and migrated the two activation-proxy consumers without changing nullable-resolver availability semantics.
- Removed Thallo's temporary engine-migration load and concrete provisioner rebinding; `SingleStoreTenant` now consumes the neutral contract.
- Added ordering, recovery, clean-install, cache-convergence, disable-failure, and admin-state regressions.
- Ported the provider split and engine harness changes to `/Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy`; the ignored vendored copy is not the only implementation.

Verification:

- Thallo default suite: 1,755 tests, 18,132 assertions, 58 skipped.
- Thallo tenancy-enabled suite: 1,886 tests, 18,741 assertions, 1 skipped.
- Focused tenancy/workflow/collections suite: 351 tests, 1,952 assertions, 32 skipped.
- Engine suite: 177 tests, 426 assertions.
- Admin suite: 451 tests across 74 files; `vue-tsc` clean.
- `composer phpcs`, package boundaries, engine PHPCS/PHPStan, `git diff --check`, and migrations all clean.
- Live unauthenticated smoke reached API-key, tenancy, and navigation routes without container 500s (expected auth responses).

Deferred by the standing hold: commits, `glueful/tenancy` 1.4.0 release, and Thallo dependency pinning.
