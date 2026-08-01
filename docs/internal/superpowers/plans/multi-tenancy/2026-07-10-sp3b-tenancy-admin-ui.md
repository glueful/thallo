# SP3b — Enablement + Tenant-Management Admin UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make multi-tenant Thallo fully operable from the admin SPA — enablement/resolution lifecycle, tenant/domain/membership management, and diagnostics — driving only shipped or SP3b-added HTTP surfaces, rendering the SP3a authorization model without inventing policy.

**Architecture:** Four new Thallo-local HTTP surfaces (diagnose GET, resolution activate POST, seed-repair POST, per-caller access GET) + one backend lifecycle fix (`migrating_extension` retry-resume) + one SP3a refactor (extract a non-auditing `decide()` from `OperatorBypass`); then an admin-SPA layer — an access store, an operator-mode flag, colada queries, a capability-gated `Tenants` module + a `Settings → Tenancy` page, with action-driven lifecycle progression and explicit fresh-boot rendering.

**Tech Stack:** PHP 8.3 (Glueful pack + app controllers), Vue 3 + Nuxt UI + Pinia (setup stores) + @pinia/colada + openapi-fetch, vitest/jsdom.

## Global Constraints

- **Thallo-only.** No framework / `glueful/extension-contracts` / `glueful/tenancy` extension change → **no release chain**. Pack = `packages/thallo-tenancy`; app = `app/`, `routes/`, `config/`, `admin/`.
- **Depends on SP3a landed.** SP3b consumes SP3a's produced interfaces (`RoleMatrix`, `TenantMembershipRoleReader`, `OperatorBypass`/`BypassDecision`, the evolved `RequirePermission`, the re-keyed routes, migration 013). Tasks T5–T6 and all matrix/role rendering require SP3a on disk. T1–T4 are SP3a-independent and may build against the current tree, but the **SP3b branch is not considered done until SP3a has landed** (spec §sequencing).
- **HOLD ALL COMMITS.** Every task's final "Commit" step is **SKIPPED** — do not run `git commit`. Work on `dev` directly. No AI/Anthropic attribution anywhere.
- **PHP:** `declare(strict_types=1)`, `final` classes, constructor DI, `use`-imports (no inline FQCNs), `composer phpcs` clean (120-char, warnings fail).
- **SPA:** setup-store Pinia only; @pinia/colada for queries/mutations; **`api/authFetch.ts` + `api/client.ts` middleware are the ONLY header-injection points**; `data-testid` hooks (assert those, never portal DOM); **no `UAuthForm`**; vitest/jsdom.
- **UI invents no policy** (SP3 index §3.6): every action is a 1:1 call to an HTTP surface; refusals render the server's message verbatim; the server stays the sole authority regardless of nav/affordance gating.
- **Status controls presentation only** — never authority. Fail closed everywhere (missing tenant context / access → deny / hide-affordance / all-false).

## SP3a interfaces consumed (from the SP3a plan; not re-derived here)

- `App\Content\Authorization\RoleMatrix`: `allows(string $role, string $capability): bool`; `capabilities(): array<string,list<string>>`; `isTenantCapability(string $capability): bool`. Reads `config('tenancy.role_matrix')`, `config('tenancy.bypass_permissions')`.
- `App\Content\Authorization\TenantMembershipRoleReader`: `__construct(ApplicationContext, ?CurrentTenantResolver = null)`; `resolvedTenantUuid(): ?string`; `roleFor(Request $request, string $userUuid): ?string` (one indexed `tenant_memberships` lookup, Request-attribute memoized).
- `App\Content\Authorization\OperatorBypass`: `evaluate(Request $request, string $userUuid, ?string $membershipRole, string $capability, string $resolvedTenantUuid, array $aegisContext): BypassDecision` (audits on grant). `App\Content\Authorization\BypassDecision` readonly `{bool $granted, ?string $mode, string $reason}`.
- `App\Content\Http\RequirePermission` evolved (ctor gains `?TenantMembershipRoleReader`, `?RoleMatrix`, `?OperatorBypass`; resolves `PermissionManager` via a container-id scan `permissionManager()` at `:133-150`).
- `config/tenancy.php` overlay carries `role_matrix` + `bypass_permissions => ['tenancy.access_any']`.

---

## Task 1: `migrating_extension` retry-resume fix (backend, SP3a-independent)

**Files:**
- Modify: `packages/thallo-tenancy/src/Enablement/TenancyEnablement.php` (`begin()`, `:60-157`)
- Test: `tests/Unit/Tenancy/Enablement/TenancyEnablementMigrationRetryTest.php` (new; root harness — the pack has no package-local test tree)

**Interfaces:**
- Consumes: `EnablementStep::MIGRATING_EXTENSION`; `$this->activation->migrate(): array{failed: string[]}`; `$this->store->recordFailure(EnablementStep, string): void`; `$this->store->setStep(EnablementStep): void`; `EnablementStep::AWAITING_CONFIRM`. All verified in `TenancyEnablement.php:137-153`.
- Produces: `begin()` now advances a `MIGRATING_EXTENSION`-restored step; no signature change.

As built: a failed extension migration records `failedFrom = MIGRATING_EXTENSION` (`:144`); `retry()` restores that step (`:245-251`); but `begin()` has no `MIGRATING_EXTENSION` branch, so it falls through the `:155` no-op default and the lifecycle is stuck. Add the idempotent branch that re-runs `migrate()` exactly as the `AWAITING_PROVIDER_BOOT` branch does.

- [ ] **Step 1: Write the failing test**

```php
public function test_failed_migration_can_be_retried_then_resumed_to_awaiting_confirm(): void
{
    // Arrange: drive enablement to AWAITING_PROVIDER_BOOT with a migration that fails once.
    $this->driveToAwaitingProviderBoot();               // helper: install + activate + provider present
    $this->activation->failNextMigration(['0102_x']);   // test double: migrate() returns ['failed'=>['0102_x']]
    $this->enablement->begin();                          // records failure at MIGRATING_EXTENSION
    $this->assertSame(EnablementStep::MIGRATING_EXTENSION, $this->store->failedFrom());
    $this->assertSame(EnablementStep::FAILED, $this->store->step());

    // Act: retry restores MIGRATING_EXTENSION, then begin() must re-run migration and advance.
    $this->enablement->retry();
    $this->assertSame(EnablementStep::MIGRATING_EXTENSION, $this->store->step());
    $this->activation->clearMigrationFailure();          // migrate() now returns ['failed'=>[]]
    $this->enablement->begin();

    // Assert: no longer stuck; advanced to AWAITING_CONFIRM.
    $this->assertSame(EnablementStep::AWAITING_CONFIRM, $this->store->step());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Tenancy/Enablement/TenancyEnablementMigrationRetryTest.php`
Expected: FAIL — after the second `begin()` the step is still `MIGRATING_EXTENSION` (fell through `:155`).

- [ ] **Step 3: Add the branch in `begin()`**

Insert a `MIGRATING_EXTENSION` branch mirroring the `AWAITING_PROVIDER_BOOT` migration handling (`:142-152`), placed after the `AWAITING_PROVIDER_BOOT` branch and before the fall-through:

```php
if ($step === EnablementStep::MIGRATING_EXTENSION) {
    $migration = $this->activation->migrate();
    if ($migration['failed'] !== []) {
        $this->store->recordFailure(
            EnablementStep::MIGRATING_EXTENSION,
            'Extension migration failed: ' . implode(', ', $migration['failed'])
        );

        return $this->status();
    }

    $this->store->setStep(EnablementStep::AWAITING_CONFIRM);

    return $this->status();
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Tenancy/Enablement/TenancyEnablementMigrationRetryTest.php` → PASS.
Then `composer phpcs` → clean.

- [ ] **Step 5: Commit** — **SKIPPED (HOLD).**

---

## Task 2: Diagnose HTTP endpoint (backend, pack)

**Files:**
- Modify: `packages/thallo-tenancy/src/Http/Controllers/TenancyEnablementController.php` (add `diagnose()`)
- Modify: `packages/thallo-tenancy/routes/enablement.php` (add route in Group A)
- Test: `tests/Integration/Tenancy/TenancyDiagnoseEndpointTest.php`

**Interfaces:**
- Consumes: `Thallo\Tenancy\Enablement\TenancyDiagnostics::report(): array{sections: array<string,array{status:string,detail:mixed}>, ok:bool}` (no args; container id `TenancyDiagnostics::class`, verified `TenancyServiceProvider.php:160-164`); `Glueful\Http\Response::success(array): Response`.
- Produces: `GET /v1/admin/tenancy/diagnose` → `{data:{report:{sections,ok}}}`.

- [ ] **Step 1: Write the failing test**

```php
public function test_diagnose_returns_report_and_is_manage_gated(): void
{
    $this->actingAsOperator();                       // holds tenancy.manage
    $res = $this->get('/v1/admin/tenancy/diagnose');
    $res->assertStatus(200);
    $this->assertArrayHasKey('sections', $res->json('data.report'));
    $this->assertArrayHasKey('ok', $res->json('data.report'));

    $this->actingAsNonOperator();                    // lacks tenancy.manage
    $this->get('/v1/admin/tenancy/diagnose')->assertStatus(403);
}
```

- [ ] **Step 2: Run test to verify it fails** — Route 404 / method missing. Expected FAIL.

- [ ] **Step 3: Add the controller method**

`TenancyEnablementController` (namespace `Thallo\Tenancy\Http\Controllers`) — inject `TenancyDiagnostics` into the ctor (autowired) and add:

```php
public function diagnose(): Response
{
    return Response::success(['report' => $this->diagnostics->report()]);
}
```

Add the constructor property `private readonly TenancyDiagnostics $diagnostics` (import it). No `guarded()` wrapper — `report()` never throws lifecycle exceptions.

- [ ] **Step 4: Register the route**

In `packages/thallo-tenancy/routes/enablement.php` Group A (`['prefix'=>'/v1/admin','middleware'=>['auth']]`), beside the resolution routes (`:25-30`):

```php
$router->get('/tenancy/diagnose', [TenancyEnablementController::class, 'diagnose'])
    ->middleware('tenant_system')
    ->middleware('content_permission:tenancy.manage');
```

- [ ] **Step 5: Run tests to verify they pass** — PASS; `composer phpcs` clean.

- [ ] **Step 6: Commit** — **SKIPPED (HOLD).**

---

## Task 3: Resolution activate HTTP endpoint (backend, pack)

**Files:**
- Modify: `packages/thallo-tenancy/src/Http/Controllers/TenancyResolutionController.php` (add `activate()`)
- Modify: `packages/thallo-tenancy/routes/enablement.php` (add route beside deactivate, `:28`)
- Test: `tests/Integration/Tenancy/TenancyResolutionActivateEndpointTest.php`

**Interfaces:**
- Consumes: `FullResolutionActivation::advance(): array{step,mode,failure,fresh_boot_required}`; `::retry(): array` (throws `EnablementException` when not retryable); `Glueful\Http\Response::{success,error}`; `Response::HTTP_CONFLICT` / `HTTP_UNPROCESSABLE_ENTITY`; `EnablementLockedException`, `EnablementException`. Template: `deactivate()` at `TenancyResolutionController.php:23-32`.
- Produces: `POST /v1/admin/tenancy/resolution/activate` → `{data:{resolution}}`; body `{"retry": true}` routes to `retry()`; `step==='failed'` → 422.

- [ ] **Step 1: Write the failing tests**

```php
public function test_activate_advances_and_maps_failed_step_to_422(): void
{
    $this->actingAsOperator();
    $this->enableTenancyOn();                         // SP1 step 'on' so assertCanActivate passes
    $res = $this->postJson('/v1/admin/tenancy/resolution/activate', []);
    $res->assertStatus(200);
    $this->assertArrayHasKey('step', $res->json('data.resolution'));

    $this->forceResolutionFailedStep();               // advance() will surface step:'failed'
    $this->postJson('/v1/admin/tenancy/resolution/activate', [])->assertStatus(422);
}

public function test_activate_retry_flag_calls_retry_and_422s_when_not_retryable(): void
{
    $this->actingAsOperator();
    $this->enableTenancyOn();
    $this->postJson('/v1/admin/tenancy/resolution/activate', ['retry' => true])->assertStatus(422);
}
```

- [ ] **Step 2: Run to verify they fail** — Route missing. Expected FAIL.

- [ ] **Step 3: Add the controller method** (mirror `deactivate()`, add the retry branch + explicit `failed`→422)

```php
public function activate(Request $request): Response
{
    try {
        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $retry = ($body['retry'] ?? false) === true;
        $resolution = $retry ? $this->activation->retry() : $this->activation->advance();

        if (($resolution['step'] ?? null) === 'failed') {
            return Response::error(
                (string) ($resolution['failure'] ?? 'Resolution activation failed.'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['resolution' => $resolution]
            );
        }

        return Response::success(['resolution' => $resolution]);
    } catch (EnablementLockedException $e) {
        return Response::error($e->getMessage(), Response::HTTP_CONFLICT);
    } catch (EnablementException $e) {
        return Response::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
```

Import `Symfony\Component\HttpFoundation\Request` (and keep the existing exception imports). `advance()` swallows a failed step into `step:'failed'` rather than throwing (`FullResolutionActivation.php:66-70`), so the explicit `failed`→422 restores CLI parity (`ResolutionActivateCommand.php:23`). `assertCanActivate()` already throws `EnablementException` when SP1 enablement isn't `on` (→ 422 via the catch).
Use the existing controller JSON pattern above rather than `Request::toArray()`: malformed or
non-object JSON degrades to an empty body and cannot escape as an uncaught JSON exception.

- [ ] **Step 4: Register the route** (beside deactivate, `routes/enablement.php:28`)

```php
$router->post('/tenancy/resolution/activate', [TenancyResolutionController::class, 'activate'])
    ->middleware('tenant_system')
    ->middleware('content_permission:tenancy.manage');
```

- [ ] **Step 5: Run tests → PASS; `composer phpcs` clean.**

- [ ] **Step 6: Commit** — **SKIPPED (HOLD).**

---

## Task 4: Seed-repair HTTP endpoint (backend, pack + app DI)

**Files:**
- Modify: `packages/thallo-tenancy/src/Http/Controllers/TenantManagementController.php` (add `seed()` + nullable `?TenantSeedRepair` ctor dep)
- Modify: `packages/thallo-tenancy/routes/enablement.php` (add route in the nested `content_permission:tenancy.manage` group, beside `:41-42`)
- Test: `tests/Integration/Tenancy/TenantSeedRepairEndpointTest.php`

**Interfaces:**
- Consumes: `Thallo\Tenancy\Contracts\TenantSeedRepair::repair(string $tenantUuid): void` (returns void; throws `\DomainException` on ineligible tenant / missing owner, `Thallo\Tenancy\StarterSeedException` — extends `\RuntimeException`, carries `public readonly string $definitionLabel` — on a definition failure). Container id `TenantSeedRepair::class`, bound `ThalloServiceProvider.php:597-599`. Eligibility (verified `TenantSeeder.php:36-53`): accepts `provisioning|active`, rejects suspended; requires an active owner.
- Produces: `POST /v1/admin/tenancy/tenants/{uuid}/seed` → `{data:{tenant:{uuid,status:'active'}}}` on success; 422 on `StarterSeedException`/`DomainException`; 503 when the repair binding is unavailable.

- [ ] **Step 1: Write the failing tests**

```php
public function test_seed_repair_activates_a_provisioning_tenant(): void
{
    $this->actingAsOperator();
    $uuid = $this->makeProvisioningTenantWithOwner();
    $res = $this->postJson("/v1/admin/tenancy/tenants/{$uuid}/seed", []);
    $res->assertStatus(200);
    $this->assertSame('active', $res->json('data.tenant.status'));
}

public function test_seed_repair_422s_on_starter_failure_and_reports_definition(): void
{
    $this->actingAsOperator();
    $uuid = $this->makeProvisioningTenantWithFailingStarter('menu:main');
    $res = $this->postJson("/v1/admin/tenancy/tenants/{$uuid}/seed", []);
    $res->assertStatus(422);
    $this->assertSame('menu:main', $res->json('errors.repair.0') ?? $res->json('data.failed_definition'));
}

public function test_seed_repair_is_manage_gated(): void
{
    $this->actingAsNonOperator();
    $this->postJson('/v1/admin/tenancy/tenants/'.$this->anyUuid().'/seed', [])->assertStatus(403);
}
```

- [ ] **Step 2: Run to verify they fail** — Route missing. Expected FAIL.

- [ ] **Step 3: Add the ctor dep + method**

Add `?TenantSeedRepair $seedRepair = null` to the constructor (import it), and:

```php
public function seed(string $uuid): Response
{
    if ($this->seedRepair === null) {
        return $this->unavailable();                    // existing HTTP_SERVICE_UNAVAILABLE helper (:128-134)
    }

    try {
        $this->seedRepair->repair($uuid);

        return Response::success(['tenant' => ['uuid' => $uuid, 'status' => 'active']]);
    } catch (StarterSeedException $e) {
        return Response::error(
            'Starter seeding failed.',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['tenant_uuid' => $uuid, 'failed_definition' => $e->definitionLabel]
        );
    } catch (\DomainException | \RuntimeException $e) {
        return Response::validation(['repair' => [$e->getMessage()]]);
    }
}
```

(Catch `StarterSeedException` before `\RuntimeException` since it extends it.) Import `Thallo\Tenancy\Contracts\TenantSeedRepair` and `Thallo\Tenancy\StarterSeedException`.

- [ ] **Step 4: Register the route** (nested manage group, `routes/enablement.php` beside `:41-42`)

```php
$router->post('/tenants/{uuid}/seed', [TenantManagementController::class, 'seed']);
```

(The nested group already carries `content_permission:tenancy.manage` + `auth` + `tenant_system`.)

- [ ] **Step 5: Run tests → PASS; `composer phpcs` clean.**

- [ ] **Step 6: Commit** — **SKIPPED (HOLD).**

---

## Task 5: Extract non-auditing `decide()` from SP3a `OperatorBypass` (SP3a refactor)

**Files:**
- Modify: `app/Content/Authorization/OperatorBypass.php` (SP3a-owned; requires SP3a landed)
- Test: `tests/Unit/Tenancy/Authorization/OperatorBypassTest.php` (extend SP3a's suite)

**Interfaces:**
- Consumes: existing `evaluate(Request, string $userUuid, ?string $membershipRole, string $capability, string $resolvedTenantUuid, array $aegisContext): BypassDecision` (audits on grant, SP3a T3 step 4); `AuditRecorderInterface::record(AuditEntry): void`.
- Produces: **new** `decide(Request $request, string $userUuid, ?string $membershipRole, string $capability, string $resolvedTenantUuid, array $aegisContext): BypassDecision` — the pure §1-through-§4 decision with **no recorder call**. `evaluate(...)` becomes: `$decision = $this->decide(...); if ($decision->granted) { /* best-effort audit attempt */ } return $decision;`. `RequirePermission` continues to call `evaluate()` (enforcement audits); the access probe (Task 6) calls `decide()` (probe never audits).

- [ ] **Step 1: Write the failing tests** (add to the SP3a suite)

```php
public function test_decide_grants_without_emitting_audit(): void
{
    $recorder = new FakeAuditRecorder();
    $bypass = $this->makeBypass($recorder, grantsAccessAny: true, grantsCapability: true);
    $decision = $bypass->decide($this->foreignRequest(), 'user-1', null, 'tenant.members.manage', 'tenant-b', []);
    $this->assertTrue($decision->granted);
    $this->assertSame('foreign', $decision->mode);
    $this->assertCount(0, $recorder->records);            // decide() NEVER audits
}

public function test_evaluate_still_audits_on_grant(): void
{
    $recorder = new FakeAuditRecorder();
    $bypass = $this->makeBypass($recorder, grantsAccessAny: true, grantsCapability: true);
    $bypass->evaluate($this->foreignRequest(), 'user-1', null, 'tenant.members.manage', 'tenant-b', []);
    $this->assertCount(1, $recorder->records);            // enforcement path audits exactly once
}
```

- [ ] **Step 2: Run to verify they fail** — `decide()` undefined. Expected FAIL.

- [ ] **Step 3: Refactor** — move the entire decision body of `evaluate()` into `decide()` (return `BypassDecision`, no recorder use); rewrite `evaluate()` to call `decide()`, then, only when `granted`, perform the existing best-effort audit attempt (null-safe, defensively wrapped), and return the same decision. No behavior change for `evaluate()` callers.

- [ ] **Step 4: Run the full `OperatorBypass` suite → PASS** (SP3a's matrix tests + the two new ones); `composer phpcs` clean.

- [ ] **Step 5: Commit** — **SKIPPED (HOLD).**

---

## Task 6: Per-caller access probe endpoint (backend, app)

**Files:**
- Create: `app/Http/Controllers/TenancyAccessController.php`
- Create: `app/Content/Authorization/AuthenticatedPrincipalResolver.php`
- Create: `app/Content/Authorization/PermissionAuthority.php`
- Modify: `app/Content/Http/RequirePermission.php` (consume both shared helpers; behavior-preserving extraction)
- Modify: `packages/thallo-tenancy/src/Runtime/BootstrapDefaultTenantMiddleware.php` (honor an `optional` parameter outside bootstrap-default mode)
- Modify: `routes/admin.php` (register the soft/optional access route **without** a `content_permission` gate)
- Modify: `app/Providers/ThalloServiceProvider.php` (register helpers/controller and update the `RequirePermission` factory)
- Modify: `tests/Integration/Tenancy/RouteCoverageTest.php` (recognize parameterized tenancy markers)
- Test: `tests/Integration/Tenancy/TenancyAccessEndpointTest.php`
- Test: `tests/Integration/Tenancy/BootstrapResolutionTest.php` (extend optional-mode coverage)

**Interfaces:**
- Consumes: `RoleMatrix::allows()`; `TenantMembershipRoleReader::{resolvedTenantUuid,roleFor}`; `OperatorBypass::decide()` (Task 5); the exact principal shapes already supported by `RequirePermission` (`auth.user: UserIdentity` and fallback `user` array); `PermissionManager::can(string,string,string,array): bool`; `Symfony\Component\HttpFoundation\Request`; `Glueful\Http\Response`.
- Produces: `GET /v1/admin/tenancy/access` → `{data:{access:{manage_platform,access_any,manage_members,manage_domains}}}`. All-false when no authenticated user or no resolved tenant (members/domains only).

There is no shared app principal accessor today. Do not invent a controller-only abstraction or
duplicate private authorization logic. Extract the current `RequirePermission::resolvePrincipal()` body unchanged
into `AuthenticatedPrincipalResolver::resolve(Request): ?array{uuid,roles,scopes}` and its context
construction into `aegisContext(Request,array): array{roles,scopes,jwt_claims}`. Extract the
container-id scan into `PermissionAuthority::manager(): ?PermissionManager` / `can(...)`. Both
`RequirePermission` and this controller consume the same helpers, so the probe and enforcement
cannot disagree between `auth.user`, lean-install `user`, roles, scopes, or JWT claims.

The four booleans (spec §4):
- `manage_platform` = `can(uuid, 'tenancy.manage', 'thallo', $ctx)` — global.
- `access_any` = `can(uuid, 'tenancy.access_any', 'thallo', $ctx)` — global.
- `manage_members` = effective decision for `tenant.members.manage` against the resolved tenant.
- `manage_domains` = effective decision for `tenant.domains.manage` against the resolved tenant.

Effective decision (identical to `RequirePermission`'s composed rule, but via the non-auditing `decide()`):
`$role = $reader->roleFor($request, $uuid); $effective = ($role !== null && $matrix->allows($role, $cap)) || $bypass->decide($request, $uuid, $role, $cap, $resolvedTenant, $ctx)->granted;`

- [ ] **Step 1: Write the failing tests**

```php
public function test_operator_sees_manage_platform_and_access_any(): void
{
    $this->actingAsOperator();                         // administrator: tenancy.manage + tenancy.access_any
    $a = $this->get('/v1/admin/tenancy/access')->json('data.access');
    $this->assertTrue($a['manage_platform']);
    $this->assertTrue($a['access_any']);
}

public function test_owner_of_resolved_tenant_sees_member_and_domain_management_without_platform(): void
{
    $uuid = $this->makeTenantWithOwner($this->currentUserUuid());
    $this->actingAsOwnerNoPlatform();
    $a = $this->withHeader('X-Tenant-Id', $uuid)->get('/v1/admin/tenancy/access')->json('data.access');
    $this->assertFalse($a['manage_platform']);
    $this->assertTrue($a['manage_members']);
    $this->assertTrue($a['manage_domains']);
}

public function test_member_and_no_tenant_are_all_false_for_management(): void
{
    $this->actingAsPlainMember();
    $a = $this->get('/v1/admin/tenancy/access')->json('data.access');  // no X-Tenant-Id
    $this->assertFalse($a['manage_members']);
    $this->assertFalse($a['manage_domains']);
}

public function test_access_route_is_reachable_without_a_selector_in_off_bootstrap_and_full_modes(): void
{
    // OFF: global booleans answer, scoped booleans false.
    // BOOTSTRAP_DEFAULT: the middleware supplies the default tenant.
    // FULL: soft admin resolution + optional bootstrap reaches the controller with no tenant.
    $this->assertAccessModeMatrix();
}

public function test_both_supported_principal_shapes_produce_the_same_access_context(): void
{
    $this->assertPrincipalShapeParity('auth.user');
    $this->assertPrincipalShapeParity('user');
}

public function test_access_probe_emits_no_audit(): void
{
    $recorder = $this->fakeAuditRecorder();
    $this->actingAsOperator();
    $this->withHeader('X-Tenant-Id', $this->foreignTenantUuid())
         ->withHeader('X-Tenant-Operator-Mode', '1')
         ->get('/v1/admin/tenancy/access');
    $this->assertCount(0, $recorder->records);          // probe uses decide(), never audits
}

public function test_access_probe_needs_no_manage_permission_to_answer(): void
{
    $this->actingAsPlainMember();                       // holds nothing platform-level
    $this->get('/v1/admin/tenancy/access')->assertStatus(200);   // NOT 403
}
```

- [ ] **Step 2: Run to verify they fail** — controller/route missing. Expected FAIL.

- [ ] **Step 3: Implement the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\Authorization\OperatorBypass;
use App\Content\Authorization\RoleMatrix;
use App\Content\Authorization\TenantMembershipRoleReader;
use App\Content\Authorization\AuthenticatedPrincipalResolver;
use App\Content\Authorization\PermissionAuthority;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class TenancyAccessController
{
    public function __construct(
        private readonly AuthenticatedPrincipalResolver $principals,
        private readonly PermissionAuthority $permissions,
        private readonly ?RoleMatrix $matrix = null,
        private readonly ?TenantMembershipRoleReader $roleReader = null,
        private readonly ?OperatorBypass $bypass = null,
    ) {
    }

    public function access(Request $request): Response
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null || $this->matrix === null || $this->roleReader === null || $this->bypass === null) {
            return Response::success(['access' => $this->denyAll()]);
        }

        $uuid = $principal['uuid'];
        $ctx = $this->principals->aegisContext($request, $principal);

        $access = [
            'manage_platform' => $this->permissions->can($uuid, 'tenancy.manage', 'thallo', $ctx),
            'access_any'      => $this->permissions->can($uuid, 'tenancy.access_any', 'thallo', $ctx),
            'manage_members'  => $this->effective($request, $uuid, 'tenant.members.manage', $ctx),
            'manage_domains'  => $this->effective($request, $uuid, 'tenant.domains.manage', $ctx),
        ];

        return Response::success(['access' => $access]);
    }

    private function effective(Request $request, string $uuid, string $capability, array $ctx): bool
    {
        $tenant = $this->roleReader->resolvedTenantUuid();
        if ($tenant === null) {
            return false;
        }
        $role = $this->roleReader->roleFor($request, $uuid);

        return ($role !== null && $this->matrix->allows($role, $capability))
            || $this->bypass->decide($request, $uuid, $role, $capability, $tenant, $ctx)->granted;
    }

    private function denyAll(): array
    {
        return ['manage_platform' => false, 'access_any' => false, 'manage_members' => false, 'manage_domains' => false];
    }
}
```

`PermissionAuthority::can()` returns false when no manager resolves. Update
`RequirePermission` to call these helpers with no behavior change, and add focused parity tests
for both principal shapes plus missing-manager fail-closed behavior. The controller **must not**
carry a `content_permission` middleware.

- [ ] **Step 4: Register the route (app) + the controller (DI)**

The access probe must work while tenancy is off, in bootstrap-default mode, and in full mode with
no selected tenant. Required `tenant_profile:admin` cannot do that: in full mode it returns 404
before global booleans can be reported. Register the route under the existing `/v1/admin` prefix
with **exactly** this middleware order:

```php
$router->get('/tenancy/access', [TenancyAccessController::class, 'access'])
    ->middleware('auth')
    ->middleware('tenant_profile:admin,soft')
    ->middleware('tenant_bootstrap:optional');
```

Extend `BootstrapDefaultTenantMiddleware` to parse `optional`: bootstrap-default mode still wraps
the default tenant; full mode with no resolved tenant calls `$next` when optional instead of 503;
required routes retain byte-identical behavior. Update `RouteCoverageTest` to compare marker base
names (`explode(':', $middleware, 2)[0]`) so `tenant_bootstrap:optional` counts as exactly one
marker, and pin the allowed prefix `['auth','tenant_profile:admin,soft']` for this route.

`ThalloServiceProvider::services()` — register both helpers as shared services, thread them into
`makeRequirePermission()`, and register `TenancyAccessController` through an explicit
`makeTenancyAccessController()` factory that supplies the helpers and nullable SP3a services.

- [ ] **Step 5: Run tests → PASS; `composer phpcs` clean.**

- [ ] **Step 6: Commit** — **SKIPPED (HOLD).**

---

## Task 7: `operatorMode` on the tenant store (SPA)

**Files:**
- Modify: `admin/src/stores/tenant.ts`
- Test: `admin/src/__tests__/tenantStore.spec.ts` (extend)

**Interfaces:**
- Produces: `tenant.operatorMode: Ref<boolean>` (default `false`, **never persisted**); `setOperatorMode(on: boolean)`; `operatorMode` is force-reset to `false` inside `select()`, `clearSelection()`, and `reset()`.

- [ ] **Step 1: Write the failing tests**

```php
// (TS) — vitest
it('defaults operatorMode false and never persists it', () => {
  const s = useTenantStore()
  expect(s.operatorMode).toBe(false)
  s.setOperatorMode(true)
  expect(localStorage.getItem('thallo_tenant') ?? '').not.toContain('operatorMode')
})

it('resets operatorMode on select, clearSelection, and reset', () => {
  const s = useTenantStore()
  s.setOperatorMode(true); s.select('tenant000002'); expect(s.operatorMode).toBe(false)
  s.setOperatorMode(true); s.clearSelection();       expect(s.operatorMode).toBe(false)
  s.setOperatorMode(true); s.reset();                expect(s.operatorMode).toBe(false)
})
```

- [ ] **Step 2: Run to verify they fail** — `operatorMode` undefined. FAIL.

- [ ] **Step 3: Implement** — add to the setup store:

```ts
const operatorMode = ref(false)
function setOperatorMode(on: boolean) { operatorMode.value = on }
```

Set `operatorMode.value = false` as the first line of `select()`, `clearSelection()`, and `reset()`. Extend the return object with `operatorMode, setOperatorMode`. Leave the persist `paths: ['selectedUuid']` **unchanged** (operatorMode stays in-memory).

- [ ] **Step 4: Run tests → PASS.** (`pnpm test` / `pnpm vitest run` per repo convention — NOT `--noEmit`.)

- [ ] **Step 5: Commit** — **SKIPPED (HOLD).**

---

## Task 8: `stores/tenancyAccess.ts` — race-safe access store (SPA)

**Files:**
- Create: `admin/src/stores/tenancyAccess.ts`
- Create: `admin/src/queries/tenancyAccess.ts` (the fetcher)
- Test: `admin/src/__tests__/tenancyAccessStore.spec.ts`

**Interfaces:**
- Consumes: `fetchTenancyAccess(): Promise<TenancyAccess>` → `authFetch(`${apiBase}/tenancy/access`)` unwrap `data.access`.
- Produces: `TenancyAccess = { manage_platform, access_any, manage_members, manage_domains }` (all `boolean`); store `{ access, loaded, ensureLoaded(force?), refresh(), reset() }` where `access` defaults all-false; `refresh()` is **generation-guarded** so a stale tenant-A response cannot overwrite tenant-B state; fails closed (all-false) on error.

- [ ] **Step 1: Write the failing tests**

```ts
it('loads the four booleans and fails closed on error', async () => {
  fetchAccess.mockResolvedValueOnce({ manage_platform: true, access_any: true, manage_members: false, manage_domains: false })
  const s = useTenancyAccessStore(); await s.ensureLoaded()
  expect(s.access.manage_platform).toBe(true)
  fetchAccess.mockRejectedValueOnce(new Error('x')); await s.refresh()
  expect(s.access.manage_platform).toBe(false)        // reset-then-refresh, fail closed
})

it('a delayed tenant-A response cannot overwrite tenant-B state', async () => {
  const s = useTenancyAccessStore()
  let resolveA: (v: any) => void
  fetchAccess.mockImplementationOnce(() => new Promise(r => { resolveA = r }))       // A: slow
  const pA = s.refresh()
  fetchAccess.mockResolvedValueOnce({ manage_platform: true, access_any: true, manage_members: true, manage_domains: true }) // B: fast
  await s.refresh()                                    // B settles first, newer generation
  resolveA!({ manage_platform: false, access_any: false, manage_members: false, manage_domains: false }); await pA
  expect(s.access.manage_platform).toBe(true)          // B wins; A discarded
})
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement** (mirror `stores/capabilities.ts` structure; add a generation counter):

```ts
export const useTenancyAccessStore = defineStore('tenancyAccess', () => {
  const empty = { manage_platform: false, access_any: false, manage_members: false, manage_domains: false }
  const access = ref<TenancyAccess>({ ...empty })
  const loaded = ref(false)
  let generation = 0
  let inflight: Promise<void> | null = null

  async function run(): Promise<void> {
    const gen = ++generation
    try {
      const next = await fetchTenancyAccess()
      if (gen === generation) { access.value = next; loaded.value = true }
    } catch {
      if (gen === generation) { access.value = { ...empty }; loaded.value = true }
    }
  }
  async function ensureLoaded(force = false): Promise<void> {
    if (loaded.value && !force) return
    if (!inflight) inflight = run().finally(() => { inflight = null })
    return inflight
  }
  async function refresh(): Promise<void> { access.value = { ...empty }; await run() }
  function reset(): void { access.value = { ...empty }; loaded.value = false; generation++ }
  return { access, loaded, ensureLoaded, refresh, reset }
})
```

- [ ] **Step 4: Run tests → PASS.**

- [ ] **Step 5: Commit** — **SKIPPED (HOLD).**

---

## Task 9: Operator-mode header injection + `tenant-switch-required` listener (SPA)

**Files:**
- Modify: `admin/src/api/authFetch.ts` (inject `X-Tenant-Operator-Mode` beside `X-Tenant-Id` at `:23`)
- Modify: `admin/src/api/client.ts` (inject in `authMiddleware.onRequest` beside `:26-28`)
- Create: `admin/src/composables/useTenancyAccessLifecycle.ts` (tenant-first initialization + reactive refresh)
- Modify: `admin/src/components/TenantSwitcher.vue` (add the `tenant-switch-required` listener; control and reopen the actual select menu)
- Modify: `admin/src/stores/session.ts` (reset access on identity clear/login)
- Test: `admin/src/__tests__/tenantHeader.spec.ts` (extend — mirror the existing X-Tenant-Id assertions)
- Test: `admin/src/__tests__/tenancyAccessLifecycle.spec.ts`
- Test: `admin/src/__tests__/tenantSwitcher.spec.ts` (extend event/open-state coverage)

**Interfaces:**
- Consumes: `useTenantStore().operatorMode` (Task 7); `stores/tenancyAccess` reset/refresh (Task 8).
- Produces: `X-Tenant-Operator-Mode: '1'` present at **both** choke points iff `tenant.operatorMode` is `true` **and** an `X-Tenant-Id` is set; a tenant-first access lifecycle; a `tenant-switch-required` handler that resets operator mode/access and opens the actual `USelectMenu`.

- [ ] **Step 1: Write the failing tests**

```ts
it('injects X-Tenant-Operator-Mode only when operatorMode is set and a tenant is selected', async () => {
  stores.tenant.selectedUuid = 'tenant000001'; stores.tenant.operatorMode = false
  await callAuthFetch(); expect(lastHeaders['X-Tenant-Operator-Mode']).toBeUndefined()
  stores.tenant.operatorMode = true
  await callAuthFetch(); expect(lastHeaders['X-Tenant-Operator-Mode']).toBe('1')
  stores.tenant.selectedUuid = null
  await callAuthFetch(); expect(lastHeaders['X-Tenant-Operator-Mode']).toBeUndefined()  // no tenant → no header
})
```

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement injection** — in `authFetch.ts` immediately after the `X-Tenant-Id` line (`:23`):

```ts
if (tenant?.selectedUuid) {
  headers['X-Tenant-Id'] = tenant.selectedUuid
  if (tenant.operatorMode) headers['X-Tenant-Operator-Mode'] = '1'
}
```

Same in `client.ts` `authMiddleware.onRequest` (guard on `getActivePinia()` as the existing X-Tenant-Id code does).

- [ ] **Step 4: Wire the listener** — in `TenantSwitcher.vue`:

```ts
const switcherOpen = ref(false)
function onSwitchRequired() {
  useTenantStore().setOperatorMode(false)
  useTenancyAccessStore().reset()
  void useTenancyAccessStore().refresh()
  switcherOpen.value = true                            // opens USelectMenu, not merely the sidebar
}
onMounted(() => window.addEventListener('tenant-switch-required', onSwitchRequired))
onBeforeUnmount(() => window.removeEventListener('tenant-switch-required', onSwitchRequired))
```

Bind `v-model:open="switcherOpen"` on `USelectMenu`. The `authFetch`/`client` 403 recovery already
clears and reloads selection before dispatch; the explicit refresh after the event prevents the
new selection from being left with all-false access.

- [ ] **Step 5: Wire the access lifecycle** — `useTenancyAccessLifecycle()` must:
  1. await `tenant.ensureLoaded()` before the first access request;
  2. then call `access.refresh()` once;
  3. watch `[tenant.selectedUuid, tenant.operatorMode]` and reset+refresh on every later change;
  4. rely on Task 8's generation guard so an older response cannot win.

Call this composable once from `default.vue` in Task 11 instead of independently firing
`tenant.ensureLoaded()` and `access.ensureLoaded()`. In `session.clear()` and after a successful
login identity change, lazy-import and reset `tenancyAccess` alongside capabilities/tenant.
Tests assert initial ordering, tenant/operator refresh, stale-response rejection, and auth reset.

- [ ] **Step 6: Run tests → PASS.**

- [ ] **Step 7: Commit** — **SKIPPED (HOLD).**

---

## Task 10: Colada queries for the tenancy surfaces (SPA)

**Files:**
- Create: `admin/src/queries/tenancyEnablement.ts`, `tenancyResolution.ts`, `tenancyDiagnose.ts`
- Modify: `admin/src/queries/tenants.ts` (add list-all/create/suspend/reactivate/seed-repair)
- Create: `admin/src/queries/tenantDomains.ts`, `tenantMembers.ts`
- Test: `admin/src/__tests__/tenancyQueries.spec.ts`

**Interfaces (all via `authFetch`, unwrap `json.data ?? json`):**
- `tenancyEnablement.ts`: `fetchEnablementStatus(): Promise<EnablementStatus>` (`GET /tenancy/status` → `data.tenancy`); mutations `beginEnablement/confirmEnablement({slug,name})/retryEnablement/cancelEnablement/finalizeEnablement/disableEnablement` (`POST /tenancy/{action}` → `data.tenancy`). `EnablementStatus = { step, enabled, schema_state, progress, reloading, mode, pending_slug, pending_name, failure, cli_fallback }`.
- `tenancyResolution.ts`: `fetchResolutionStatus()` (`GET /tenancy/resolution` → `data.resolution`); `activateResolution(retry=false)` (`POST /tenancy/resolution/activate` body `{retry}` → `data.resolution`); `deactivateResolution()`. `ResolutionStatus = { step, mode, failure, fresh_boot_required }`.
- `tenancyDiagnose.ts`: `fetchDiagnose()` (`GET /tenancy/diagnose` → `data.report`), `DiagnoseReport = { sections: Record<string,{status,detail}>, ok }`.
- `tenants.ts` (extend): `fetchAllTenants(status?)` (`GET /tenancy/tenants` → `data.tenants`); `createTenant({slug,name})` (`POST /tenancy/tenants` — the backend deliberately makes the authenticated actor the owner; there is no `owner_uuid` input); `suspendTenant(uuid)` / `reactivateTenant(uuid)`; `repairTenantSeed(uuid)` (`POST /tenancy/tenants/{uuid}/seed`). Keep `fetchMyTenants` + `TenantSummary`.
- `tenantDomains.ts`: `fetchDomains(uuid)` (`GET /tenancy/tenants/{uuid}/domains` → `data.domains`); `addDomain(uuid,{host})` (→ `data` incl. `txt_record` + `token`); `verifyDomain(domainUuid)/enableDomain/disableDomain/removeDomain`.
- `tenantMembers.ts`: `fetchMembers(uuid)` (→ `data.members`, item `{user_uuid,role,status}`); `addMember(uuid,{user_uuid,role})`; `setMemberRole(uuid,userUuid,{role})`; `removeMember(uuid,userUuid)`.

- [ ] **Step 1: Write failing tests** — one per fetcher asserting the exact path + unwrap (mock `authFetch`, assert the URL and returned shape). Example:

```ts
it('fetchAllTenants hits GET /tenancy/tenants and unwraps data.tenants', async () => {
  authFetch.mockResolvedValueOnce({ data: { tenants: [{ uuid: 'tenant000001', slug: 'a', name: 'A', status: 'active' }] } })
  const rows = await fetchAllTenants()
  expect(authFetch).toHaveBeenCalledWith('/v1/admin/tenancy/tenants')
  expect(rows[0].uuid).toBe('tenant000001')
})
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement** each fetcher/mutation mirroring `queries/tenants.ts` (`authFetch`, `json.data ?? json`, `?? []` for lists). Query keys namespaced `['tenancy', ...]`. Provide `useX` colada wrappers (`useQuery`/`useMutation`) where a page consumes them, with `onSettled → invalidateQueries` on the sibling status/list key.

- [ ] **Step 4: Run tests → PASS.**

- [ ] **Step 5: Commit** — **SKIPPED (HOLD).**

---

## Task 11: `Tenants` module + `Settings → Tenancy` nav + access-boolean gating (SPA)

**Files:**
- Create: `admin/src/registry/tenancyModule.ts`
- Create: `admin/src/navigation/shapeTenancyNav.ts` (pure reactive-nav transformer)
- Modify: `admin/src/layouts/default.vue` (register the module; access-gate the Tenants node + children)
- Modify: `admin/src/registry/coreModule.ts` (add the `Tenancy` Settings child)
- Test: `admin/src/__tests__/tenancyNav.spec.ts`

**Interfaces:**
- Consumes: `registerAdminModule({id,requires,nav})`; `useCapabilitiesStore().isEnabled('thallo.tenancy')`; `useTenancyAccessStore().access`; `useTenancyEnablement` status (`status.enabled`).
- Produces: `registerTenancyModule()` registering `{ id: 'tenancy', requires: ['thallo.tenancy'], nav: { main: [tenantsNode] } }`; a reactive access-gate in `default.vue` that (a) drops the whole Tenants node unless `manage_platform || manage_members || manage_domains`, (b) drops the **All Tenants** child unless `manage_platform`, (c) drops **Domains**/**Members** children unless `manage_domains`/`manage_members`. The Settings→Tenancy child is dropped unless `thallo.tenancy` feature **and** `manage_platform`.

Feature gating (`thallo.tenancy`) rides the registry `requires`; access gating rides the existing
reactive `mainItems` seam, but the transformation lives in a pure `shapeTenancyNav()` helper
rather than accumulating label checks directly in the layout. The helper receives nav, access,
`selectedUuid`, and feature-enabled state; it returns cloned/pruned nodes and concrete links.

- [ ] **Step 1: Write the failing tests**

```ts
it('hides the Tenants node when no access boolean is set', () => {
  access.value = allFalse()
  expect(visibleTenancyNav().find(n => n.label === 'Tenants')).toBeUndefined()
})
it('shows Tenants with only Domains/Members for an owner without manage_platform', () => {
  access.value = { manage_platform: false, access_any: false, manage_members: true, manage_domains: true }
  const node = visibleTenancyNav().find(n => n.label === 'Tenants')!
  expect(node.children.map(c => c.label)).toEqual(['Domains', 'Members'])   // no All Tenants
})
it('hides Settings→Tenancy without manage_platform', () => {
  access.value = { ...allFalse(), manage_members: true }
  expect(settingsChildren().find(c => c.to === '/settings/tenancy')).toBeUndefined()
})
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement the module** (`registry/tenancyModule.ts`) — a `Tenants` main node (`icon: 'i-lucide-building-2'`, `to: '/tenants'`) with semantic child markers for All tenants, Domains, and Members. `shapeTenancyNav()` replaces the latter two with concrete `/tenants/{selectedUuid}/domains|members` links when a selection exists and omits them when it does not. A literal `/tenants/:selected/...` URL must never reach `UNavigationMenu`. Register in `default.vue` setup beside the other `registerXModule()` calls.

- [ ] **Step 4: Implement gating + lifecycle wiring** — add the `Tenancy` child to `coreModule.ts`'s Settings children (`{ label: 'Tenancy', icon: 'i-lucide-layers', to: '/settings/tenancy' }`). Call `useTenancyAccessLifecycle()` once in `default.vue`; do **not** start tenant and access loads concurrently. Feed the registry nav through `shapeTenancyNav()` inside `mainItems`, applying the drop/link rules above. Tests cover no selection (no literal/invalid detail links), selection changes (links update reactively), owner/operator child sets, and Settings feature+permission gating.

- [ ] **Step 5: Run tests → PASS.**

- [ ] **Step 6: Commit** — **SKIPPED (HOLD).**

---

## Task 12: `Settings → Tenancy` lifecycle page (SPA)

**Files:**
- Create: `admin/src/pages/settings/tenancy/index.vue`
- Create: `admin/src/components/tenancy/EnablementPanel.vue`, `ResolutionPanel.vue`, `DiagnoseReport.vue`, `FirstTenantConfirmForm.vue`
- Test: `admin/src/__tests__/tenancyLifecyclePage.spec.ts`

**Interfaces:**
- Consumes: Task 10 enablement/resolution/diagnose queries; `EnablementStatus.step` / `ResolutionStatus.step`.
- Produces: an **action-driven** lifecycle page (spec §6). `status()` never advances a machine — each non-terminal state renders exactly one server-prescribed action; there is **no advancement poll**.

Action map (render the single action per state; the button calls the matching mutation then re-reads status):

| Enablement step | action |
|---|---|
| `off` / `installing` / `awaiting_install` / `enabling_extension` / `migrating_extension` | **Begin** (`begin`) |
| `awaiting_provider_boot` | **Continue** — a fresh request, then `begin` |
| `awaiting_confirm` | **Confirm** — `FirstTenantConfirmForm` (`confirm{slug,name}`) |
| `retrofitting` | **Continue** (`confirm` resume with `pending_slug` + `pending_name`; show form if either is absent) |
| `reloading` / `finalizing` | **Reload and continue** → the click issues the fresh `finalize` request |
| `failed` | **Retry** (`retry`), then render the restored step's action |
| `on` | **Disable** (`disable`) |
| `disabling` | **Disable** (resume) |
| `disabled_widened` | **Begin** (re-enable) |

| Resolution step | action |
|---|---|
| `inactive` / `mapping_hosts` / `verifying_wiring` / `rebuilding_routes` | **Continue activation** (`activate`) |
| `awaiting_fresh_boot` | **Reload and continue** → the click issues the fresh `activate` request |
| `failed` | **Retry activation** (`activate` with `{retry:true}`) |
| `full` | **Deactivate** (`deactivate`) where its gate permits |

Fresh-boot rendering (spec §6): `reloading` / `awaiting_fresh_boot` render a **Reload and
continue** panel. On the shared-nothing runtime, the button's POST is itself the next fresh
backend request — do not call `window.location.reload()` or create a reload loop. On a
long-lived-worker deployment, the panel tells the operator to reload the worker through its
control plane first, then click Continue. Never a silent re-poll.

- [ ] **Step 1: Write the failing tests**

```ts
it('renders Begin at off and never starts an advancement poll', async () => {
  status.value = { step: 'off', enabled: false, progress: 0, reloading: false }
  const w = mountPage()
  expect(w.find('[data-testid="enablement-action-begin"]').exists()).toBe(true)
  expect(pollSpy).not.toHaveBeenCalled()
})
it('renders Reload-and-continue (not a spinner) at reloading', () => {
  status.value = { step: 'reloading', enabled: false, progress: 90, reloading: true }
  expect(mountPage().find('[data-testid="enablement-reload-continue"]').exists()).toBe(true)
})
it('shows the first-tenant confirm form at awaiting_confirm', () => {
  status.value = { step: 'awaiting_confirm', enabled: false, progress: 50, reloading: false }
  expect(mountPage().find('[data-testid="first-tenant-confirm"]').exists()).toBe(true)
})
it('resumes retrofitting with the persisted pending tenant payload', async () => {
  status.value = { step: 'retrofitting', pending_slug: 'default', pending_name: 'Default' }
  const w = mountPage(); await w.find('[data-testid="enablement-action-confirm"]').trigger('click')
  expect(confirm).toHaveBeenCalledWith({ slug: 'default', name: 'Default' })
})
it('shows the confirm form when a retrofitting payload is incomplete', () => {
  status.value = { step: 'retrofitting', pending_slug: null, pending_name: null }
  expect(mountPage().find('[data-testid="first-tenant-confirm"]').exists()).toBe(true)
})
it('renders the diagnose report sections and ok summary', async () => {
  diagnose.value = { sections: { schema: { status: 'ok', detail: 'x' } }, ok: true }
  expect(mountPage().find('[data-testid="diagnose-section-schema"]').text()).toContain('ok')
})
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement the page + panels** following `pages/settings/general/index.vue` structure: `definePage({ meta: { requiresAuth: true } })`; `UDashboardPanel id="settings-tenancy"` → `#header` `UDashboardNavbar title="Tenancy"` → `#body`. Status pending → `USkeleton`. `EnablementPanel` renders the status pair + the single action (button `data-testid="enablement-action-<action>"`); `ResolutionPanel` likewise; `DiagnoseReport` lists `sections` with a per-section `status` badge (`data-testid="diagnose-section-<key>"`) and the `ok` summary + a **Run diagnostics** button; `FirstTenantConfirmForm` is `UFormField`+`UInput` (**no `UAuthForm`**) for `slug`/`name` with the slug hint `[a-z0-9][a-z0-9-]*`, mapping 422 `fieldErrors`. A `retrofitting` resume sends the status's persisted pending slug/name; missing pending data falls back to the form. Reload-and-continue directly invokes `finalize`/`activate` as the next backend request. All actions then invalidate status. No `useIntervalFn` advancement loop.

- [ ] **Step 4: Run tests → PASS.**

- [ ] **Step 5: Commit** — **SKIPPED (HOLD).**

---

## Task 13: All-Tenants management page + route-target sync + seed repair (SPA)

**Files:**
- Create: `admin/src/pages/tenants/index.vue`
- Create: `admin/src/components/tenancy/TenantCreateModal.vue`, `OperatorModeToggle.vue`
- Test: `admin/src/__tests__/tenantsPage.spec.ts`

**Interfaces:**
- Consumes: Task 10 `fetchAllTenants/createTenant/suspendTenant/reactivateTenant/repairTenantSeed`; `useTenantStore().select` + `useTenancyAccessStore()`; the create response's provisioning-failure body `{tenant_uuid,status:'provisioning',failed_definition,repair_command}`.
- Produces: an operator-only list (create/suspend/reactivate); a **Retry seeding** action on provisioning rows (calls `repairTenantSeed`); shared target helpers: `selectThenNavigate(uuid, sub)` performs `tenant.select(uuid)` → `await tenancyAccess.refresh()` → navigate, while `ensureTargetSelected(uuid)` performs the same select+refresh and returns true only when the UUID exists in the loaded tenant directory. T14/T15 consume the latter before enabling detail queries.

- [ ] **Step 1: Write the failing tests**

```ts
it('selecting a tenant precedes navigation for that tenant', async () => {
  await selectThenNavigate('tenant000002', 'domains')
  expect(tenantStore.select).toHaveBeenCalledWith('tenant000002')
  expect(accessStore.refresh).toHaveBeenCalled()
  expect(router.push).toHaveBeenCalledWith('/tenants/tenant000002/domains')
  expect(select.mock.invocationCallOrder[0]).toBeLessThan(push.mock.invocationCallOrder[0])
})
it('offers Retry seeding on a provisioning-failure create response', async () => {
  create.mockRejectedValueOnce(apiError(500, { tenant_uuid: 't1', status: 'provisioning', repair_command: '…' }))
  const w = mountPage(); await w.find('[data-testid="tenant-create-submit"]').trigger('click'); await flush()
  expect(w.find('[data-testid="tenant-retry-seed"]').exists()).toBe(true)
})
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement** — `pages/tenants/index.vue` (`definePage requiresAuth`, `UDashboardPanel`/`UDashboardNavbar title="Tenants"`, a `UTable`/list of `fetchAllTenants`, `data-testid="tenant-row-<uuid>"`). Row actions: Suspend/Reactivate (mutations + invalidate), and **Manage domains / members** → `selectThenNavigate(uuid, 'domains'|'members')`. Put both target helpers in a small shared composable used by all three pages. `TenantCreateModal` posts `{slug,name}` only; on the 500 provisioning body render the provisioning state + **Retry seeding** (`repairTenantSeed`) + show `repair_command` as secondary guidance. `OperatorModeToggle` (`USwitch`, `data-testid="operator-mode-toggle"`) drives `tenant.setOperatorMode`, visible only when `access.access_any`.

- [ ] **Step 4: Run tests → PASS.**

- [ ] **Step 5: Commit** — **SKIPPED (HOLD).**

---

## Task 14: Tenant Domains page with DNS-TXT UX (SPA)

**Files:**
- Create: `admin/src/pages/tenants/[uuid]/domains.vue`
- Create: `admin/src/components/tenancy/DomainAddForm.vue`, `DomainVerifyInstructions.vue`
- Test: `admin/src/__tests__/tenantDomainsPage.spec.ts`

**Interfaces:**
- Consumes: Task 10 `fetchDomains/addDomain/verifyDomain/enableDomain/disableDomain/removeDomain`; the add response `{ ..., txt_record, token }`; route `uuid`; `useTenantStore().selectedUuid`; `useTenancyAccessStore().access.manage_domains`.
- Produces: a domains list + add/verify/enable/disable/remove; **fail-closed target guard** — no detail fetch until `ensureTargetSelected(uuid)` has selected the route tenant and awaited the access refresh; DNS instructions render **both** the TXT **Name** (`txt_record`) and **Value** (`token`).

- [ ] **Step 1: Write the failing tests**

```ts
it('does not fetch domains when route uuid != selectedUuid and tenant not in directory', () => {
  tenantStore.selectedUuid = 'tenant000001'; tenantStore.tenants = [{ uuid: 'tenant000001' }]
  mountPage({ uuid: 'tenant000009' })
  expect(fetchDomains).not.toHaveBeenCalled()
})
it('renders the TXT record name and token value after add', async () => {
  addDomain.mockResolvedValueOnce({ uuid: 'd1', host: 'x.com', txt_record: '_thallo-verify.x.com', token: 'abc123' })
  const w = mountPage({ uuid: 'tenant000001' }); await addFlow(w, 'x.com')
  const box = w.find('[data-testid="domain-verify-instructions"]').text()
  expect(box).toContain('_thallo-verify.x.com'); expect(box).toContain('abc123')
})
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement** — query `enabled` stays false until route UUID equals `selectedUuid` **and** the access refresh for that selection has completed. On mismatch, await `ensureTargetSelected(uuid)`; if unavailable, render a non-revealing prompt and never fetch. Add a test proving call order `select < access.refresh < fetchDomains`. List rows (`data-testid="domain-row-<uuid>"`) with verify/enable/disable/remove buttons gated on `manage_domains`. `DomainAddForm` posts `addDomain`; `DomainVerifyInstructions` renders Name = `txt_record`, Value = `token`, and Verify. Refusals render `ApiError.message` verbatim.

- [ ] **Step 4: Run tests → PASS.**

- [ ] **Step 5: Commit** — **SKIPPED (HOLD).**

---

## Task 15: Tenant Members page with SP3a role picker (SPA)

**Files:**
- Create: `admin/src/pages/tenants/[uuid]/members.vue`
- Create: `admin/src/components/tenancy/MemberAddForm.vue`, `RolePicker.vue`
- Test: `admin/src/__tests__/tenantMembersPage.spec.ts`

**Interfaces:**
- Consumes: Task 10 `fetchMembers/addMember/setMemberRole/removeMember`; the SP3a role vocabulary `owner|admin|member|viewer`; `useTenancyAccessStore().access.manage_members`; the same route-target guard as Task 14.
- Produces: a members list + add/set-role/remove; `RolePicker` offering exactly `owner|admin|member|viewer`; the same awaited `ensureTargetSelected()` guard as T14; affordances gated on `manage_members`; refusals (incl. final-owner protection 422) rendered verbatim.

- [ ] **Step 1: Write the failing tests**

```ts
it('offers exactly the four SP3a roles', () => {
  expect(rolePickerOptions()).toEqual(['owner', 'admin', 'member', 'viewer'])
})
it('renders the server refusal when demoting the final owner', async () => {
  setRole.mockRejectedValueOnce(apiError(422, {}, 'Cannot remove the last active owner.'))
  const w = mountPage({ uuid: 'tenant000001' }); await demoteFlow(w, 'user1')
  expect(w.find('[data-testid="member-error"]').text()).toContain('Cannot remove the last active owner.')
})
it('hides mutating affordances without manage_members', () => {
  access.value.manage_members = false
  expect(mountPage({ uuid: 'tenant000001' }).find('[data-testid="member-add"]').exists()).toBe(false)
})
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement** — page with the Task-14 awaited route-target guard; add the equivalent `select < access.refresh < fetchMembers` test. Render members (`data-testid="member-row-<userUuid>"`) with `RolePicker` (`USelect`/`USelectMenu`, values `owner|admin|member|viewer`, `data-testid="role-picker"`) driving `setMemberRole`, remove buttons, and `MemberAddForm` (`user_uuid` + role) driving `addMember`. All mutating affordances render only when `access.manage_members`. Errors → `data-testid="member-error"` with `ApiError.message`.

- [ ] **Step 4: Run tests → PASS.**

- [ ] **Step 5: Commit** — **SKIPPED (HOLD).**

---

## Task 16: Acceptance pass — journey + full gate matrix (SPA + backend)

**Files:**
- Create: `admin/src/__tests__/tenancyAcceptance.spec.ts`
- Modify: any test-migration registration if 013/access wiring needs it (`scripts/run-test-migrations.php`)

**Interfaces:** consumes everything above; produces no new interface.

- [ ] **Step 1: Write the acceptance tests** (index §6 journey + spec §9 matrix), covering:
  - Nav gating: Tenants + Settings→Tenancy hidden with all-false access; owner-only booleans show Tenants (Domains/Members only, no All Tenants); All-Tenants + Settings→Tenancy hidden without `manage_platform`.
  - operatorMode: not persisted; reset on tenant change / clear / `reset()` / `tenant-switch-required`; header at both choke points only when set.
  - Access lifecycle: tenant selection completes before the first access probe; tenant/operator
    changes and identity reset refresh or clear access; a stale response never wins.
  - Lifecycle: status reads never advance a machine; each intermediate state renders the correct action; `reloading`→(fresh request)→`finalizing` shows Reload-and-continue; failed migration → retry → begin reaches `awaiting_confirm` (drives the Task 1 fix through the UI action).
  - Target sync: select(B) precedes navigation/fetch for B; route-UUID/header mismatch performs no detail request; owner cannot reconcile to an unavailable foreign tenant.
  - DNS: create response TXT name + token value both rendered.
  - Refusals: 403/409/422 messages verbatim; disable gate-refusals shown; 500 seed-failure offers in-SPA repair.
  - Endpoint gates (backend): diagnose/activate/seed `tenancy.manage`-gated; access probe answers
    in off/bootstrap/full modes, supports both principal shapes, and **emits no audit** while a
    real member/domain mutation **does** audit.

- [ ] **Step 2: Run the complete backend gates from the repository root:**

```bash
composer test
THALLO_TENANCY_DEV_LINK=1 composer test
composer phpcs
composer boundaries
```

All green (the linked run exercises the real tenancy enforcement/oracle path).

- [ ] **Step 3: Run the complete admin gates from `admin/`:**

```bash
pnpm type-check
pnpm test
pnpm lint
pnpm fmt:check
```

All green. Do not substitute a targeted vitest run for these final gates.

- [ ] **Step 4: Commit** — **SKIPPED (HOLD).**

---

## Self-Review notes

- **Spec coverage:** §2 IA → T11/T12/T13; §3 four endpoints → T2/T3/T4/T6; §4 access probe + no-audit → T5/T6/T16; §5 nav gating → T11/T16; §6 action-driven + migration fix + fresh-boot → T1/T12/T16; §7 stores/queries/header/route-target → T7/T8/T9/T10/T13/T14; §8 refusals/403-recovery/seed-repair → T9/T12/T13/T14/T15; §9 tests → each task + T16.
- **Type consistency:** `EnablementStatus`/`ResolutionStatus`/`DiagnoseReport`/`TenancyAccess`/`TenantSummary` used identically across T8/T10/T12/T13; `decide()`/`evaluate()` split consistent across T5/T6; `roleFor(Request, userUuid)` (not tenantUuid) honored in T6.
- **SP3a dependency:** T5/T6 and all matrix/role rendering require SP3a on disk; T1–T4 are SP3a-independent. The branch is not done until SP3a lands.
- **Standing rules:** every Commit step is SKIPPED (HOLD); Thallo-only, no release chain; no attribution; new controllers registered in `ThalloServiceProvider::services()` (T6).
