# thallo-subscriptions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship glueful/subscriptions **2.1.0** (four additive seams + one spec-wording amendment) and then the **thallo-subscriptions** capability pack — workspace SaaS billing in Thallo per `docs/internal/superpowers/specs/2026-08-03-thallo-subscriptions-design.md`.

**Architecture:** Two repos in strict order. Phase A (subscriptions repo, Tasks 1–4): `currentForTenants()` trusted bulk read, `countSubjectRows()` purge seam, detailed override read, `SubscriptionSchemaReadiness` authority, spec amendment, release 2.1.0. Phase B (Thallo repo, Tasks 5–12): pack scaffold + wiring, host subject resolver, lazy `EngineGateway`, plans + workspaces admin APIs (platform authority, provider-managed guard), fail-closed purge integration, SPA module, and the capability/engine truth table.

**Tech Stack:** PHP 8.3; glueful/framework ≥1.57 (subscriptions repo) / ^1.74 (Thallo packs); phpunit 10.5 (subscriptions: `SubscriptionsTestCase`; Thallo: `App\Tests\Support\AppTestCase`); Vue 3 + vitest (admin SPA); phpstan; phpcs PSR-12.

## Global Constraints (from the spec — every task implicitly includes these)

- Every Thallo admin route uses `['auth', 'tenant_system', 'content_permission:tenancy.manage']`; a tenant-grantable subscriptions permission is rejected by design (spec §3).
- `currentForTenants()` is a trusted administrative projection: no per-UUID `validate()`; at most 100 inputs; contract requires a normalized, deduplicated, host-directory-derived list after platform authorization; never mounted as a batch-by-UUID HTTP input; repository work runs through `TenantIntegration::runAsSystemOr()` (spec §6.1). Thallo proves the precondition: UUID list only from a paginated slice of `TenantAdministration::listTenants()`, no caller-supplied UUID filter (spec §3).
- Provider-linked subscriptions (`provider_subscription_id` non-empty) refuse local cancel/set-plan with structured 409 `provider_managed_subscription`; manual subscriptions act freely (spec §3).
- Controllers never constructor-inject engine services; `EngineGateway` probes per-operation; meta is always 200 with `engine_disabled | schema_not_ready | ready`; readiness comes ONLY from the upstream `SubscriptionSchemaReadiness` authority — a lone legacy table or partial 006 is never `ready` (spec §5, §6.4).
- Purge: alias `thallo.subscriptions.purge_handler` ALWAYS registered (outside the capability gate); adapter soft-resolves a nullable purger via `container->has()`; schema-exists-but-purger-unavailable ⇒ prepare/purge/verify THROW; schema absent ⇒ zero-pass (spec §5).
- Resolver ruling (spec §2): `currentTenant()` delegates solely to `SingleStoreTenant::resolve()`; `TenantAdministration` is required, never nullable; `validate()` = tenant self-subject + `TenantAdministration::getTenant()` existence with no fallback; user subjects always false; `currentUser()` null.
- Cross-workspace override reads/writes enter `TenantIntegration::runAsTenantOr()` for the target workspace; `tenant_system` is only a route-classification marker. Admin reads use 2.1's detailed `listForSubject()` projection so expiry/reason metadata round-trips.
- Bundled-engine consistency: `SubscriptionsServiceProvider` added to committed `config/extensions.php`; Thallo root requires `glueful/subscriptions: ^2.1` (spec §1, §6).
- Capability-off hides surfaces; engine-off keeps the capability-gated shell visible in `engine_disabled` state (spec §1).
- The pack discovers no commands; upstream commands stay extension-owned (spec §5).
- Phase B is a hard publication gate: no Thallo dependency/lock commit against a sibling path repository. Task 5 starts only after subscriptions 2.1.0 resolves from the published repository.
- Gates green per task: subscriptions repo `vendor/bin/phpunit` / `phpstan analyse` / `phpcs --standard=PSR12 src`; Thallo `vendor/bin/phpunit` / `vendor/bin/phpstan` / `vendor/bin/phpcs` (composer-script forms) + `cd admin && npx vitest run` + `npm run -s typecheck` for SPA tasks.
- Conventional commits, no AI-attribution trailers.

## File Structure (final state)

```
subscriptions/ (Phase A)
  src/SubscriptionService.php                (+currentForTenants)
  src/Repositories/SubscriptionRepository.php (+findTenantSubjectsAmong)
  src/Lifecycle/SubscriptionSubjectDataPurger.php (+countSubjectRows)
  src/Repositories/OverrideRepository.php        (+listForSubject)
  src/Schema/SubscriptionSchemaReadiness.php  (new)
  src/SubscriptionsServiceProvider.php        (bind readiness)
  docs/…/2026-08-02-subscriptions-v2-subject-model-design.md (§4 wording)
  CHANGELOG.md / composer.json (2.1.0)
thallo/ (Phase B)
  packages/thallo-subscriptions/composer.json
  packages/thallo-subscriptions/src/SubscriptionsIntegrationServiceProvider.php
  packages/thallo-subscriptions/src/Resolver/ThalloSubjectResolver.php
  packages/thallo-subscriptions/src/Engine/EngineGateway.php
  packages/thallo-subscriptions/src/Engine/EngineUnavailableException.php
  packages/thallo-subscriptions/src/Http/PlansController.php
  packages/thallo-subscriptions/src/Http/WorkspaceBillingController.php
  packages/thallo-subscriptions/src/Http/MetaController.php
  packages/thallo-subscriptions/src/Purge/SubscriptionsPurgeHandler.php
  packages/thallo-subscriptions/routes/admin-routes.php
  packages/thallo-tenancy/src/TenancyServiceProvider.php (+alias consult)
  config/serviceproviders.php / config/extensions.php / composer.json
  admin/src/registry/subscriptionsModule.ts (+manifest.ts entry)
  admin/src/queries/subscriptionsBilling.ts
  admin/src/pages/subscriptions/{plans,billing}/index.vue (+components)
  tests/Integration/Subscriptions/*.php (Thallo-side)
```

---

# PHASE A — glueful/subscriptions 2.1.0
Work from `/Users/michaeltawiahsowah/Sites/glueful/extensions/subscriptions` (branch `dev`).

### Task 1: `currentForTenants()` bulk read

**Files:**
- Modify: `src/SubscriptionService.php` (after `currentFor()`), `src/Repositories/SubscriptionRepository.php` (after `findBySubject()`)
- Test: `tests/Integration/SubscriptionServiceBulkReadTest.php` (new)

**Interfaces:**
- Produces: `SubscriptionRepository::findTenantSubjectsAmong(ApplicationContext $c, array $tenantUuids): array` — ONE `whereIn` query over `subject_type='tenant' AND tenant_uuid IN (...)`, rows decoded like `findBySubject()`. `SubscriptionService::currentForTenants(array $tenantUuids): array` — rejects more than `MAX_TENANT_BATCH = 100` inputs before querying, normalizes (string-cast, trim, drop empties, dedupe), enters `TenantIntegration::runAsSystemOr()`, and returns `[tenantUuid => row]` (absent key = no subscription). Docblock carries the §6.1 trusted-projection contract verbatim (no per-UUID validate; host-directory-derived list after platform authorization; never a batch-by-UUID HTTP input).

- [ ] **Step 1: Failing tests:**

```php
public function testReturnsRowsKeyedByTenantUuidWithAbsentKeysForMisses(): void
{
    $this->seedPlatformPlanAndSubscription('t-1', 'pro');   // helper: plan row + subject row
    $this->seedPlatformPlanAndSubscription('t-3', 'free');
    $out = $this->service->currentForTenants(['t-1', 't-2', 't-3']);
    self::assertSame(['t-1', 't-3'], array_keys($out));
    self::assertSame('pro', $out['t-1']['plan_key']);
}

public function testEmptyListReturnsEmptyArrayWithoutQuerying(): void
{
    self::assertSame([], $this->serviceWithSpyRepo()->currentForTenants([]));
    self::assertSame(0, $this->spyRepo->calls);
}

public function testNeverReturnsUserSubjectRows(): void
{
    // Seed a USER-subject row for t-1 only; tenant read must miss it.
    $this->seedMembership('t-1', 'u-1', 'member-basic');
    self::assertSame([], $this->service->currentForTenants(['t-1']));
}

public function testConstantRepositoryCallCountAcrossPageSizes(): void
{
    // The spec's constant-query-count pin, measured at the repository seam: a
    // spy repository (extends SubscriptionRepository, increments a counter in
    // findTenantSubjectsAmong then parent::) proves ONE call for 1, 25, and
    // 100 tenants; single-query-ness of the repo method itself is pinned by
    // testBulkRepositoryMethodIssuesOneWhereInQuery below.
    foreach ([1, 25, 100] as $n) {
        $this->spyRepo->calls = 0;
        $this->serviceWithSpyRepo()->currentForTenants($this->tenantIds($n));
        self::assertSame(1, $this->spyRepo->calls, "page size {$n}");
    }
}

public function testRejectsMoreThanOneHundredInputsBeforeQuerying(): void
{
    $service = $this->serviceWithSpyRepo();
    $this->expectException(\InvalidArgumentException::class);
    try {
        $service->currentForTenants($this->tenantIds(101));
    } finally {
        self::assertSame(0, $this->spyRepo->calls);
    }
}

public function testRunsTheCrossWorkspaceReadAsSystem(): void
{
    $runner = new RecordingTenantContextRunner();
    $this->bind(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class, $runner);
    $this->seedPlatformPlanAndSubscription('t-1', 'pro');
    $this->seedPlatformPlanAndSubscription('t-2', 'pro');

    $rows = $this->service()->currentForTenants(['t-1', 't-2']);

    self::assertSame(['t-1', 't-2'], array_keys($rows));
    self::assertSame([['mode' => 'system', 'tenantUuid' => null]], $runner->calls());
}

public function testInputIsNormalizedAndDeduplicated(): void
{
    $this->seedPlatformPlanAndSubscription('t-1', 'pro');
    $out = $this->service->currentForTenants(['t-1', 't-1', '', ' t-1 ']); // trim per normalize rule
    self::assertSame(['t-1'], array_keys($out));
}
```

- [ ] **Step 2:** `vendor/bin/phpunit --filter BulkRead` → RED (method not found).
- [ ] **Step 3: Implement.** Repository (mirror `findBySubject`'s decode path):

```php
/** @param list<string> $tenantUuids @return list<array<string,mixed>> */
public function findTenantSubjectsAmong(ApplicationContext $context, array $tenantUuids): array
{
    if ($tenantUuids === []) {
        return [];
    }
    $rows = db($context)->table('subscriptions')
        ->where('subject_type', '=', SubjectType::TENANT)
        ->whereIn('tenant_uuid', $tenantUuids)
        ->get();
    return array_map(fn (array $row): array => $this->decode($row), $rows);
}
```

(If the existing single-row path decodes inline rather than via a `decode()` helper, extract that helper first so both paths share it — a move, not a copy.) Service:

```php
public const MAX_TENANT_BATCH = 100;

/** @param list<string> $tenantUuids @return array<string,array<string,mixed>> */
public function currentForTenants(array $tenantUuids): array
{
    if (count($tenantUuids) > self::MAX_TENANT_BATCH) {
        throw new \InvalidArgumentException('At most 100 tenant UUIDs may be read at once.');
    }
    $normalized = [];
    foreach ($tenantUuids as $uuid) {
        $uuid = trim((string) $uuid);
        if ($uuid !== '') {
            $normalized[$uuid] = true;
        }
    }
    if ($normalized === []) {
        return [];
    }
    $rows = TenantIntegration::runAsSystemOr(
        $this->context,
        fn (): array => $this->subscriptions->findTenantSubjectsAmong($this->context, array_keys($normalized)),
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['tenant_uuid']] = $row;
    }
    return $out;
}
```

- [ ] **Step 4:** GREEN; full suite + phpstan + phpcs.
- [ ] **Step 5:** Commit `feat: currentForTenants trusted administrative bulk read`.

### Task 2: Detailed override read + `countSubjectRows()` purge seam

**Files:**
- Modify: `src/Repositories/OverrideRepository.php`
- Modify: `src/Lifecycle/SubscriptionSubjectDataPurger.php`
- Test: extend `tests/Integration/Repositories/OverrideRepositorySubjectTest.php`
- Test: extend `tests/Integration/Lifecycle/PurgerTest.php`

**Interfaces:**
- Produces: `OverrideRepository::listForSubject(ApplicationContext $context, Subject $subject): array` — every row for the exact subject triple, including expired rows, ordered by `entitlement ASC`, projected only as `{entitlement, value, expires_at, reason, created_at, updated_at}` with `value` decoded through the same helper as `activeForSubject()`. Extract `subjectQuery()` for the shared triple predicate; `scopedQuery()` adds entitlement to it. This repository method does not invent host authorization or tenant switching: callers performing cross-workspace administration MUST wrap it in `TenantIntegration::runAsTenantOr()`.
- Produces: `public function countSubjectRows(Subject $subject): array` — non-mutating, keys `subscriptions`, `subscription_overrides`, `subscription_events`, `subscription_provider_event_receipts` (int counts), plus for tenant subjects `subscription_plans` (workspace member plans only). MUST reuse the same private predicate builders `purgeSubject()` uses (extract them if currently inline — resolved-or-candidate matching for receipts; `audience='user' AND owner_tenant_uuid=<t>` for plans) so counts and deletes cannot drift.

- [ ] **Step 1: Failing override tests:** seed active and expired rows for the target subject plus sibling-user and foreign-workspace rows; assert `listForSubject()` returns both target rows in entitlement order, decodes scalar/object JSON values, preserves nullable `expires_at`/`reason` and timestamps, and exposes no storage identity fields. Assert `activeForSubject()` remains active-only and byte-compatible.
- [ ] **Step 2: Failing purge tests:** counts match what a subsequent `purgeSubject()` deletes (capture counts, purge, assert per-table deleted == counted); all-zero after purge; idempotent (second count identical); user-subject form never counts plans; sibling users/foreign workspaces uncounted; `assertPurgeableSubject` guard applies (empty ids throw).
- [ ] **Step 3:** RED. **Step 4:** Extract `OverrideRepository::subjectQuery()` and reuse it from both read paths; extract the purge WHERE-builders from `purgeSubject()`; implement counts as `SELECT COUNT(*)` per table via the shared builders, wrapped in `TenantIntegration::runAsSystemOr()` like `purgeSubject()`.
- [ ] **Step 5:** GREEN; full suite + gates. **Step 6:** Commit `feat: administrative override and purge read seams`.

### Task 3: `SubscriptionSchemaReadiness` authority

**Files:**
- Create: `src/Schema/SubscriptionSchemaReadiness.php`
- Modify: `src/SubscriptionsServiceProvider.php` (`services()`: shared binding)
- Test: `tests/Integration/Schema/SubscriptionSchemaReadinessTest.php` (new; extends `SubscriptionsTestCase` for fresh/empty/partial 2.x shapes), `tests/Integration/Schema/SubscriptionSchemaReadinessLegacyTest.php` (new; extends `LegacySchemaTestCase` for the pre-006 fixture)

**Interfaces:**
- Produces: `final class SubscriptionSchemaReadiness { public function __construct(private readonly ApplicationContext $context) {} public function isReady(): bool; }` — true ONLY when the complete minimum 2.x runtime shape exists. Required tables: `subscriptions`, `subscription_overrides`, `subscription_events`, `subscription_plans`, `subscription_provider_event_receipts`. Required altered columns: `subscriptions.subject_type/subject_uuid/plan_uuid`; `subscription_overrides.subject_type/subject_uuid`; `subscription_events.subject_type/subject_uuid`; `subscription_plans.audience/owner_tenant_uuid`. Required receipt columns: `uuid`, `provider_gateway`, `provider_logical_event_key`, `event_type`, all four `candidate_*` identity fields, resolved `tenant_uuid/subject_type/subject_uuid/plan_uuid`, `outcome`, `rejection_code`, `data`, and `created_at`. Uses the framework schema builder's `hasTable`/`hasColumn`; every probe wrapped so a thrown DB error returns false (readiness is a probe, never a fatal). Registered `shared => true` in `services()` + a `ServiceProviderWiringTest` row.

- [ ] **Step 1: Failing tests:** fresh 2.x harness ⇒ true; empty database (drop all) ⇒ false; legacy 1.x schema (`LegacySchemaTestCase`, migrations 001–004 only) ⇒ false; representative partial shapes ⇒ false: receipts table missing; `subscriptions.plan_uuid` missing; `subscription_overrides.subject_uuid` missing; `subscription_events.subject_type` missing; `subscription_plans.owner_tenant_uuid` missing; one consumed receipt column (`candidate_subject_uuid`) missing. Add the DB-throw path (probe against a closed/broken connection double) ⇒ false.
- [ ] **Step 2:** RED. **Step 3:** Implement + bind. **Step 4:** GREEN; gates. **Step 5:** Commit `feat: extension-owned schema readiness authority`.

### Task 4: Spec amendment + release 2.1.0

**Files:** `docs/superpowers/specs/2026-08-02-subscriptions-v2-subject-model-design.md` (§4: the "binding the resolver is enabling memberships" sentence → "memberships are enabled only when the host resolver positively resolves AND validates user subjects; a tenant-only host resolver binds without enabling them"), `CHANGELOG.md` (2.1.0: four seams, additive, no behavior changes for existing callers; the trusted-projection and host-context contracts summarized), `composer.json` (`extra.glueful.version` → `2.1.0`), README (short "Bulk administrative reads", "Administrative override reads", and "Schema readiness" notes), version-pin test update.

- [ ] Edits; full gates; commit per release convention (`Release 2.1.0 — host integration seams`); local tag `v2.1.0`; NOTHING pushed.
- [ ] **HARD STOP:** maintainer publishes `glueful/subscriptions` 2.1.0. Verify a clean Composer resolution of `^2.1` from the configured published repository before starting Task 5. A sibling path repository is explicitly forbidden for Phase B because it would bypass this release gate and could leave Thallo's lock file pointing at unpublished code.

---

# PHASE B — Thallo
Work from `/Users/michaeltawiahsowah/Sites/glueful/thallo` (branch `dev`).

### Task 5: Pack scaffold + wiring

**Files:**
- Create: `packages/thallo-subscriptions/composer.json` (mirror `packages/thallo-account/composer.json`: type library, PSR-4 `Thallo\Subscriptions\` → `src/`, `extra.glueful.provider = Thallo\Subscriptions\SubscriptionsIntegrationServiceProvider`, require `php ^8.3`, `glueful/thallo-contracts *`, `glueful/thallo-tenancy *`, `glueful/extension-contracts *`, `glueful/subscriptions ^2.1`, `glueful/framework ^1.74.0`, version 0.1.0; `extension-contracts` is direct because this pack imports `TenantAdministration`, not merely transitively available)
- Create: `packages/thallo-subscriptions/src/SubscriptionsIntegrationServiceProvider.php` (skeleton: `loadAfter([\Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class])`, `loadPriority()` post-extension tier copied from `CommerceIntegrationServiceProvider`'s value, `register()` no-op for now, `boot()` registers `new Capability('thallo.subscriptions', label: 'Subscriptions', description: 'Workspace SaaS billing: platform plans and per-workspace subscriptions.')` via the registry exactly as `CommerceIntegrationServiceProvider::boot()` does for `thallo.commerce`)
- Modify: root `composer.json` (path repo entry for `packages/thallo-subscriptions`; require `glueful/thallo-subscriptions: *` AND `glueful/subscriptions: ^2.1`), `config/serviceproviders.php` (provider entry beside the other packs), `config/extensions.php` (append `Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class` to `enabled` — the spec §1 consistency rule)
- Test: `tests/Integration/Subscriptions/PackWiringTest.php` (new)

**Interfaces:**
- Produces: the provider FQCN `Thallo\Subscriptions\SubscriptionsIntegrationServiceProvider` and capability id `thallo.subscriptions` that every later task builds on.

- [ ] **Step 1:** From a clean Composer state, verify `glueful/subscriptions:^2.1` resolves from the configured published repository, then run `composer update glueful/subscriptions glueful/thallo-subscriptions`. Do not add a sibling path repository and do not continue if 2.1.0 is unavailable. Run `php glueful migrate:run` so the engine's tables exist in the dev install.
- [ ] **Step 2: Failing test:** capability `thallo.subscriptions` registered and enabled by default; the engine provider line present in `config/extensions.php`; boot does not throw with everything enabled.
- [ ] **Step 3:** Implement scaffold; `composer dump-autoload`. **Step 4:** GREEN; full Thallo suite + gates (boundaries check too: `composer boundaries`). **Step 5:** Commit `feat(subscriptions): thallo-subscriptions pack scaffold and engine wiring`.

### Task 6: `ThalloSubjectResolver`

**Files:**
- Create: `packages/thallo-subscriptions/src/Resolver/ThalloSubjectResolver.php`
- Modify: provider `services()` (bind `Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface` → resolver, shared — this OVERRIDES the engine's default binding because the pack loads after; add a boot-order comment citing `loadAfter`)
- Test: `tests/Integration/Subscriptions/ThalloSubjectResolverTest.php`

**Interfaces:**
- Produces: `final class ThalloSubjectResolver implements SubjectResolverInterface` — ctor `(ApplicationContext $context, SingleStoreTenant $singleStore, TenantAdministration $tenants)`, all non-nullable. `currentTenant()` delegates solely to `SingleStoreTenant::resolve()`, which already owns tenancy-enabled current-context resolution and tenancy-off default-workspace resolution. `validate()` returns true only when `$s->type === SubjectType::TENANT`, `$s->uuid === $s->tenantUuid`, the UUID is non-empty, and `TenantAdministration::getTenant($context, $s->tenantUuid) !== null`; there is no coherent-shape fallback when the authority is unavailable. User subjects always `return false`; `currentUser()` returns `null`.

- [ ] **Step 1: Failing tests:** tenancy-off resolution returns the default workspace uuid; tenancy-on resolution follows the current workspace through the same `SingleStoreTenant` authority; coherent tenant self-subject with an existing tenant ⇒ true; nonexistent tenant uuid ⇒ false; `subject_uuid !== tenant_uuid` ⇒ false; empty ids ⇒ false; `Subject::user(...)` ⇒ false always; `currentUser()` null; constructing/resolving without `TenantAdministration` fails rather than accepting shape-only subjects; the container binding resolves THIS class (not the engine default) — assert `get(SubjectResolverInterface::class) instanceof ThalloSubjectResolver`.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + gates. **Step 5:** Commit `feat(subscriptions): tenant-only host subject resolver`.

### Task 7: `EngineGateway`

**Files:**
- Create: `packages/thallo-subscriptions/src/Engine/EngineGateway.php`, `src/Engine/EngineUnavailableException.php`
- Test: `tests/Integration/Subscriptions/EngineGatewayTest.php`

**Interfaces:**
- Produces:

```php
final class EngineUnavailableException extends \RuntimeException
{
    public function __construct(public readonly string $state) // 'engine_disabled' | 'schema_not_ready'
    { parent::__construct("subscriptions engine unavailable: {$state}"); }
}

final class EngineGateway
{
    public const DISABLED = 'engine_disabled';
    public const SCHEMA_NOT_READY = 'schema_not_ready';
    public const READY = 'ready';
    public function __construct(private readonly ApplicationContext $context) {}
    public function engineState(): string;      // probes container per call, never cached
    public function subscriptions(): \Glueful\Extensions\Subscriptions\SubscriptionService;      // or throw
    public function plans(): \Glueful\Extensions\Subscriptions\Plans\PlanManagementService;      // or throw
    public function overrides(): \Glueful\Extensions\Subscriptions\Repositories\OverrideRepository; // or throw
    public function purger(): ?\Glueful\Extensions\Subscriptions\Lifecycle\SubscriptionSubjectDataPurger; // soft, for Task 10
}
```

`engineState()`: container lacks `SubscriptionService::class` ⇒ DISABLED; else `SubscriptionSchemaReadiness::isReady()` false ⇒ SCHEMA_NOT_READY; else READY. Accessors call `engineState()` and throw `EngineUnavailableException($state)` unless READY (except `purger()`, which returns null when unavailable). Registered non-shared is unnecessary — it holds no state; shared with per-call probing.

- [ ] **Step 1: Failing tests:** three-state matrix — (a) harness container without the engine services ⇒ DISABLED + accessor throws with state; (b) services bound + readiness false (bind a stub readiness returning false) ⇒ SCHEMA_NOT_READY; (c) full harness ⇒ READY + accessors return real services; `purger()` null in (a).
- [ ] **Step 2:** RED. **Step 3:** Implement + provider binding. **Step 4:** GREEN + gates. **Step 5:** Commit `feat(subscriptions): lazy engine gateway with three-state readiness`.

### Task 8: Plans admin API

**Files:**
- Create: `packages/thallo-subscriptions/src/Http/PlansController.php`, `packages/thallo-subscriptions/routes/admin-routes.php` (started here; extended in Task 9)
- Modify: provider `boot()` — `loadRoutesFrom(routes/admin-routes.php)` INSIDE the capability gate
- Test: `tests/Integration/Subscriptions/PlansAdminApiTest.php`

**Interfaces:**
- Produces routes (group prefix `/subscriptions`, mounted under the admin `/v1/admin` mount; middleware `['auth', 'tenant_system', 'content_permission:tenancy.manage']`; route-name prefix `thallo.subscriptions.admin.`):
  - `GET /plans` → list (platform scope)
  - `POST /plans` → create
  - `PATCH /plans/{key}` → update (plan_key immutability surfaces upstream's error as 422)
  - `POST /plans/{key}/archive` → archive
  - `POST /plans/import-config` → import
  Controller methods take `EngineGateway`; every action begins `try { $plans = $this->gateway->plans(); } catch (EngineUnavailableException $e) { return Response::error('subscriptions engine unavailable', 409, ['code' => $e->state]); }` — the structured-409 shape reused verbatim in Task 9 (extract a small `RespondsEngineUnavailable` trait in this task).

- [ ] **Step 1: Failing tests:** happy-path CRUD against the real engine (seeded via import-config); 403 for a non-`tenancy.manage` actor on EVERY route (loop the route table); 409 with `code: engine_disabled` when the gateway reports disabled (bind a stub gateway); plan_key-change attempt → 422 carrying upstream's `plan_key is immutable` message.
- [ ] **Step 2:** RED. **Step 3:** Implement (mirror `packages/thallo-commerce/routes/admin-routes.php` group/middleware/naming conventions; thin delegation only). **Step 4:** GREEN + gates. **Step 5:** Commit `feat(subscriptions): platform plans admin API`.

### Task 9: Workspace billing API + meta

**Files:**
- Create: `packages/thallo-subscriptions/src/Http/WorkspaceBillingController.php`, `src/Http/MetaController.php`
- Modify: `packages/thallo-subscriptions/routes/admin-routes.php`
- Test: `tests/Integration/Subscriptions/WorkspaceBillingApiTest.php`

**Interfaces:**
- Produces routes:
  - `GET /meta` → 200 always: `{engine: <state>, tenancy_enabled: bool, default_tenant_uuid: ?string}`. When tenancy is off, read `SingleStoreTenant::defaultUuidOrNull()`; never call `resolve()` in meta, because a missing initial pointer is representable and must not turn this status endpoint into a 500.
  - `GET /workspaces?page=<n>&per_page=<n>` → tenancy ON: fetch the authoritative `TenantAdministration::listTenants()` result, validate/clamp `per_page` to 1–100, slice it in memory, and pass only that page's UUIDs to `currentForTenants()`; tenancy OFF: resolve the one real default-workspace row or return 409 `default_workspace_missing`. Response rows: `{tenant: {...directory fields}, subscription: null | {status, plan_key, plan_display_name, trial_ends_at, grace_ends_at, provider_managed: bool}}` plus ordinary pagination metadata. Build the plan display-name map from exactly ONE `$gateway->plans()->list()` call for the page, keyed by `plan_key`; never instantiate/query `PlanCatalog` once per distinct plan. NO caller-supplied UUID filter parameter exists (spec §3).
  - `GET /workspaces/{uuid}` → detail incl. every override from `OverrideRepository::listForSubject()` (active and expired, with expiry/reason metadata). Execute the override read through `TenantIntegration::runAsTenantOr($context, $uuid, ...)`.
  - `PUT /workspaces/{uuid}/plan` body `{plan_key}` → `start()` when no subscription, `changePlan()` otherwise — REFUSED 409 `provider_managed_subscription` when the existing row has a non-empty `provider_subscription_id`
  - `POST /workspaces/{uuid}/cancel` body `{at_period_end?: bool}` → `cancel()` — same provider-managed 409 guard
  - `PUT /workspaces/{uuid}/overrides/{entitlement}` body `{value, expires_at?, reason?}` → `upsertForSubject`, inside `TenantIntegration::runAsTenantOr($context, $uuid, ...)`
  - `DELETE /workspaces/{uuid}/overrides/{entitlement}` → `deleteForSubject`, inside the same target-workspace runner
  All workspace routes validate `{uuid}` against `TenantAdministration::getTenant()` (404 when unknown; tenancy-off: only the non-null default workspace uuid is valid). Any single-store billing request that needs a default while none exists returns structured 409 `default_workspace_missing`. Same middleware + trait as Task 8.

- [ ] **Step 1: Failing tests:** meta in all three engine states (200 each, correct payload) and both tenancy modes; with no default pointer, meta remains 200 with `default_tenant_uuid: null`, while the single-store workspace index/action returns 409 `default_workspace_missing`. Against a 101+ tenant directory, assert page 1 and page 2 each preserve directory order, never pass more than 100 UUIDs, perform exactly ONE bulk subscription read and ONE platform-plan list per page, and ignore a caller's `?uuids=` parameter. Seed a foreign active tenant context and prove the page still joins all requested workspaces. Assert absent-subscription rows and `provider_managed`. Detail + overrides round-trip active and expired values including `expires_at`/`reason`; with another tenant active, a recording runner proves list/upsert/delete enter the target workspace, sibling data remains untouched, and one active override is honored by the tenant entitlement resolver. Cover set-plan on fresh workspace (start) and existing (changePlan); provider-linked PUT plan/POST cancel both 409 `provider_managed_subscription`, manual fixture both succeed; unknown workspace 404; invalid pagination 422; 403 posture loop as Task 8.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + gates. **Step 5:** Commit `feat(subscriptions): workspace billing admin API with provider-managed guard`.

### Task 10: Purge integration (fail-closed)

**Files:**
- Create: `packages/thallo-subscriptions/src/Purge/SubscriptionsPurgeHandler.php`
- Modify: provider `services()` (alias `thallo.subscriptions.purge_handler` — ALWAYS registered, factory soft-resolves), `packages/thallo-tenancy/src/TenancyServiceProvider.php:~465` (third alias-consult block, mirroring the commerce one verbatim with the new alias string)
- Test: `tests/Integration/Subscriptions/SubscriptionsPurgeHandlerTest.php` + extend the tenancy purge-registry test that pins registered handler ids

**Interfaces:**
- Produces: `final class SubscriptionsPurgeHandler implements \Thallo\Tenancy\Purge\PurgeHandler` — `id(): 'subscriptions'`; `dependsOn(): []`; ctor `(ApplicationContext $context, Connection $connection, EngineGateway $gateway)`. Each `prepare`/`purge`/`verify` call first checks `$connection->getSchemaBuilder()->hasTable('subscriptions')` directly. If false, the handler returns the zero-artifact/no-op/true result without asking readiness or the gateway. If true, `$gateway->purger()` MUST resolve or all three operations throw `\RuntimeException('subscriptions data exists but the subscriptions engine is disabled or not ready — enable and migrate the extension before purging this tenant')`. This deliberately treats legacy and partially-applied schemas as data-bearing, fail-closed states; `SubscriptionSchemaReadiness::false` never means “schema absent.” With a purger, `prepare` = `countSubjectRows(Subject::tenant($uuid))`, `purge` = `purgeSubject(...)`, and `verify` = all counts zero.

- [ ] **Step 1: Failing tests:** schema-absent zero-pass; a lone legacy `subscriptions` table + no engine throws from all three methods; a representative partial-006 schema + readiness false throws from all three; complete schema + provider disabled throws; ready path counts→purges→verifies with seeded billing data including member plans owned by the workspace. Assert the alias is registered even when the capability is disabled and when the engine provider is absent; tenancy registry test lists `subscriptions` among handler ids.
- [ ] **Step 2:** RED. **Step 3:** Implement (+ one-line tenancy consult block). **Step 4:** GREEN + gates. **Step 5:** Commit `feat(subscriptions): fail-closed tenant purge integration`.

### Task 11: Admin SPA module

**Files:**
- Create: `admin/src/registry/subscriptionsModule.ts`, `admin/src/queries/subscriptionsBilling.ts`, `admin/src/pages/subscriptions/plans/index.vue`, `admin/src/pages/subscriptions/billing/index.vue`, shared components under `admin/src/pages/subscriptions/components/` (PlanEditor.vue, WorkspaceDrawer.vue, EngineStateNotice.vue)
- Modify: `admin/src/registry/manifest.ts` (add the module)
- Test: `admin/src/__tests__/subscriptions-module.spec.ts`, `subscriptions-pages.spec.ts`

**Interfaces:**
- Produces: `subscriptionsModule: AdminModule = { id: 'subscriptions', requires: ['thallo.subscriptions'], nav: { primary: [{ label: 'Subscriptions', icon: 'i-lucide-credit-card', to: '/subscriptions/plans' }, ...] } }` — follow `commerceModule.ts`'s nav shape (top-level group with Plans + Billing children as that file's conventions dictate; if commerce uses a single entry + in-page tabs, mirror that instead — match the existing pattern, don't invent). Pages declare `definePage({ meta: { requiresCapability: 'thallo.subscriptions' } })`. Queries wrap the Task 8/9 endpoints with the repo's pinia-colada conventions (`useInstalledExtensions`-style).
- Behavior: both pages fetch `/meta` first; `engine_disabled` renders `EngineStateNotice` with a link to `/extensions`; `schema_not_ready` renders the run-migrations notice; Billing renders the directory (tenancy on) or the "This site's plan" panel (off — same `WorkspaceDrawer` bound to a non-null `default_tenant_uuid`). A null default pointer renders the `default_workspace_missing` repair state rather than issuing a workspace request. The drawer displays active and expired overrides with expiry/reason intact, disables set-plan/cancel with the provider-managed explanation when `provider_managed` is true, and renders structured 409 payload messages verbatim.

- [ ] **Step 1: Failing vitest specs:** module gating (hidden without the capability — reuse the registry test idiom from `commerceModule` coverage); Plans page states (list render, editor submit calls the create endpoint, engine_disabled notice); Billing states (tenancy on paginated directory + drawer actions, tenancy off single panel, null-default repair state with no workspace fetch, expired override metadata visible/editable, provider-managed refusal rendering, all three engine states).
- [ ] **Step 2:** RED. **Step 3:** Implement pages/components/queries. **Step 4:** GREEN (`npx vitest run`), `npm run -s typecheck`, `npm run -s build`; full Thallo PHP suite unaffected. **Step 5:** Commit `feat(subscriptions): admin SPA module — plans and workspace billing`.

### Task 12: Truth table + docs + final gates

**Files:**
- Create: `tests/Integration/Subscriptions/CapabilityEngineTruthTableTest.php`
- Modify: `docs/internal/OUTSTANDING.md` (Recently-shipped entry + a §B follow-up line for the deferred workspace-checkout product decision), `docs/internal/composable-core/` capability listing if one enumerates capabilities (grep `thallo.search` to find the canonical list docs and add `thallo.subscriptions`)
- Test: the truth table itself

**Interfaces:** none new.

- [ ] **Step 1: Failing truth-table test** (spec §7): capability OFF ⇒ admin routes 404 (route file never loads) and the capabilities endpoint omits/disables visibility; capability ON + engine provider disabled ⇒ routes respond (shell visible) with meta `engine_disabled` and engine actions 409; both ON ⇒ operational. Drive via the harness config overrides used by existing capability tests (grep `capabilities` overrides in tests for the idiom).
- [ ] **Step 2:** RED where meaningful; implement any gap it exposes (it should pass from Tasks 5–9 work — if it does immediately, verify by temporarily flipping a branch, note in report). **Step 3:** Docs edits. **Step 4:** FULL gates: Thallo phpunit + phpstan + phpcs + admin vitest + typecheck + build + `composer boundaries`. **Step 5:** Commit `feat(subscriptions): capability/engine truth table and shipped docs`.

---

## Self-Review

- **Spec coverage:** §1 → Task 5 (scaffold, direct contracts dependency, extensions.php, published ^2.1, three layers); §2 → Task 6 (+§6.5 wording in Task 4); §3 → Tasks 8–9 (platform authority, bounded/system bulk join, one-call plan map, tenant-scoped detailed overrides, no-UUID-input, provider-managed 409, null-safe meta); §4 → Task 11; §5 → Tasks 7 (gateway/lazy/states), 10 (direct marker-table distinction, purge always-alias/fail-closed), route/command posture in Tasks 5+8; §6.1 → Task 1; §6.2–3 → Task 2; §6.4 → Task 3 (+consumed in Task 7); §6.5 → Task 4; §7 upstream → Tasks 1–3 tests, Thallo → Tasks 6–12 tests including the truth table (Task 12), pagination/constant-call-count, target-tenant override context, and UUID-input exclusion (Tasks 1, 9); §8 respected (no checkout, no memberships, no per-tenant catalogs, no new permission). No gaps.
- **Placeholder scan:** clean — every code step carries real code; pattern-adoption instructions name exact sibling files and are not TBDs.
- **Type consistency:** `currentForTenants(array): array` (Task 1) consumed in Task 9; `OverrideRepository::listForSubject(...): array` and `countSubjectRows(Subject): array` (Task 2) consumed in Tasks 9 and 10; `SubscriptionSchemaReadiness::isReady(): bool` (Task 3) consumed in Task 7; `EngineGateway` states/accessors (Task 7) consumed in Tasks 8–10; `EngineUnavailableException::$state` naming consistent; alias string `thallo.subscriptions.purge_handler` identical in Task 10's two edit sites.
- **Cross-repo sequencing:** Task 4 ends at a maintainer publication stop. Task 5 requires a clean published-repository resolution of subscriptions 2.1.0; no path-repository escape hatch exists.
