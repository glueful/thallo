# SP3a — Membership × Aegis RBAC Composition — Implementation Plan (rev 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tenant-data authorization becomes `allow = authenticated AND api-key-scope AND (membershipMatrix(role, capability) OR explicitOperatorBypass(capability))` — membership roles become the in-tenant law, operator bypass becomes explicit and audited, and management re-keys off `system.access`.

**Architecture:** Thallo-only (Aegis/extension/contracts/framework untouched — no release chain). A `RoleMatrix` config + `TenantMembershipRoleReader` (neutral resolver + pinned raw indexed read, request-memoized) + `OperatorBypass` evaluator (foreign-tenant selection or `X-Tenant-Operator-Mode`, best-effort audit) compose inside the existing `RequirePermission` middleware (`content_permission` alias unchanged); member/domain self-service routes relocate from `tenant_system` into `tenant_profile:admin` + `tenant_bootstrap` with controller-level target binding; migration 013 seeds the operator grants. Spec: `docs/superpowers/specs/multi-tenancy/2026-07-10-sp3a-membership-rbac-design.md` (user-finalized); invariants `SP3-README.md` §3.

**Tech Stack:** PHP 8.3, PostgreSQL, PHPUnit; harness `RetrofittedTenantTestCase` + SP2 full-resolution helpers.

## Global Constraints

- **Repo:** `/Users/michaeltawiahsowah/Sites/glueful/thallo` only. **HOLD ALL COMMITS**; `dev`; NO attribution. `strict_types`, `final`, DI, `use`-imports; `composer phpcs` clean.
- Contract-only rule: app code reads the resolved tenant UUID ONLY via `Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver::tenantUuid(ApplicationContext): string` (`''` = none, never null — verified `CurrentTenantResolver.php:20`; precedent `TenantBlobPolicy.php:89-103`). `RequirePermission` may inspect only whether request-state `tenancy.tenant` is present as a fail-closed wiring guard; it never reads identity from that object. The membership-role read is the spec-§7-PINNED raw `db()->table('tenant_memberships')` integration (unique index `(tenant_uuid,user_uuid)` verified `002_…:40`) — never `TenantAdministration::listMembers()`, never extension models.
- **Verified enforcement surfaces:** `RequirePermission::handle()` flow (slug :36-39, api-key branch :47-55, `resolvePrincipal()` :107-131, `can($uuid,$perm,$resource,$context)` :80 with context `{roles,scopes,jwt_claims}` :75-79, `resourceFor()` :92-98, `forbidden()` :152-155); registered `'alias'=>['content_permission']` at `ThalloServiceProvider.php:1129-1134`. `PermissionManager::can(string,string,string,array): bool` passthrough (`PermissionManager.php:124-132`).
- **Verified route surfaces:** profile alias is **`tenant_profile`** (`tenant_profile:admin|public` — pack provider `:230`; consumed `routes/admin.php:40,204,279,384`); management routes to relocate live in `packages/thallo-tenancy/routes/enablement.php` Group B nested `content_permission:system.access` group (`:38-61`: tenants :39-42, domains :44-49, members :51-60); lifecycle/enablement actions in Group A (`:15-30`). `RouteCoverageTest` prefixes as-built: `[[], ['tenant_profile:public'], ['auth','tenant_profile:admin']]` (`:51-59`).
- **Verified audit seam:** `AuditRecorderInterface::record(AuditEntry): void` — best-effort, never throws (`extensions/audit/src/Contracts/AuditRecorderInterface.php:18-22`); `AuditEntry(occurredAt, action, category, actorUuid?, actorLabel?, targetType?, targetUuid?, targetLabel?, changes?, context?)` (`AuditEntry.php:28-40`); resolve OPTIONALLY (`has() ? get() : null` — no Thallo usage exists yet). Spec guarantees an audit ATTEMPT only.
- **Live slug inventory (route-inventory test freezes this):** tenant-data matrix set = `content.{view,create,edit,publish,delete,manage,routes}` + `navigation.manage` + `seo.manage` + `templates.manage` + `analytics.read` + `workflow.review` + new `tenant.{members,domains}.manage`; global = `users.{create,edit,delete}` + `system.access` (non-tenancy uses) + `tenancy.{manage,access_any}`; fenced = `collections.*` (excluded until collections tenancy ships).
- **Bypass rule (spec §4/§5/§6):** bypass NEVER implicit; foreign-tenant selection (no membership) or explicit `X-Tenant-Operator-Mode: 1` (membership exists); requires `tenancy.access_any` (Aegis). In bypass, the EXPLICIT map `tenant.members.manage|tenant.domains.manage → tenancy.manage` applies; an unknown `tenant.*` capability denies, and all non-tenant capabilities use their literal slug. App config narrows `tenancy.bypass_permissions` to `['tenancy.access_any']`.
- Sequencing: T1 · T2 · T3(T2) · T4(T1,T2,T3) · T5(T4) · T6 · T7(all).

## File Structure

**Create:** `app/Content/Authorization/{RoleMatrix,TenantMembershipRoleReader,OperatorBypass,BypassDecision}.php`, `database/dependent-migrations/013_GrantTenancyOperatorToAdministrator.php`.
**Modify:** `config/tenancy.php` (add `role_matrix`, `bypass_permissions` — current shape verified: `public_origin` only, `:11-17`), `app/Content/Http/RequirePermission.php`, `app/Providers/ThalloServiceProvider.php` (bindings), `packages/thallo-tenancy/routes/enablement.php` (relocation + re-key), `packages/thallo-tenancy/src/Http/Controllers/{TenantDomainController,TenantMembershipController}.php` (target binding), `tests/Integration/Tenancy/RouteCoverageTest.php`.
**Tests:** `tests/Unit/Tenancy/Authorization/{RoleMatrixTest,TenantMembershipRoleReaderTest,OperatorBypassTest}.php`, `tests/Integration/Tenancy/{TenantAuthorizationTruthTableTest,SelfServiceBindingTest,ManagementRekeyTest,OperatorGrantMigrationTest}.php`, `tests/Unit/Tenancy/RouteInventoryTest.php`.

---

### Task 1: `RoleMatrix` + route-inventory pinning test

**Files:**
- Create: `app/Content/Authorization/RoleMatrix.php`
- Modify: `config/tenancy.php` (add `role_matrix` key)
- Test: `tests/Unit/Tenancy/Authorization/RoleMatrixTest.php`, `tests/Unit/Tenancy/RouteInventoryTest.php`

**Interfaces:**
- Produces: `RoleMatrix::__construct(ApplicationContext $context)` reading `config('tenancy.role_matrix')` once; `allows(string $role, string $capability): bool` — unknown role OR capability absent from the matrix → `false` + a `warning` on each denied evaluation (no shared suppression state that could leak across requests); `capabilities(): array<string, list<string>>` (SP3b renders this); `isTenantCapability(string $capability): bool` (present in any role's list). Config shape (spec §2 verbatim):

```php
'role_matrix' => [
    'owner'  => ['content.view', 'content.create', 'content.edit', 'content.publish',
                 'content.delete', 'content.manage', 'content.routes', 'navigation.manage',
                 'seo.manage', 'templates.manage', 'analytics.read', 'workflow.review',
                 'tenant.members.manage', 'tenant.domains.manage'],
    'admin'  => ['content.view', 'content.create', 'content.edit', 'content.publish',
                 'content.delete', 'content.manage', 'content.routes', 'navigation.manage',
                 'seo.manage', 'templates.manage', 'analytics.read', 'workflow.review'],
    'member' => ['content.view', 'content.create', 'content.edit'],
    'viewer' => ['content.view'],
],
'bypass_permissions' => ['tenancy.access_any'],   // §4 narrowing: manage ≠ selection authority
```

- [ ] **Step 1: Failing tests** — matrix truth per role×capability (the §2 table verbatim as a data provider); unknown role `'ghost'` → false; unknown capability → false + warning asserted; `capabilities()` round-trips config. **Route-inventory test (RED until matrix config lands):** boot the router (RouteCoverageTest's discovery pattern `:21-27`), collect every `content_permission:<slug>` on routes whose middleware includes `tenant_bootstrap`; assert each slug ∈ matrix capability union ∪ {fenced `collections.*` on `collections_disabled_when_tenant` routes only}; assert `users.*`/`system.access`/`tenancy.*` appear ONLY on `tenant_system` routes; failure message: "new tenant-data permission slug — add it to tenancy.role_matrix deliberately".
- [ ] **Step 2: Implement + config.** Run → PASS; phpcs. Commit SKIPPED (HELD); ledger.

---

### Task 2: `TenantMembershipRoleReader`

**Files:**
- Create: `app/Content/Authorization/TenantMembershipRoleReader.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (binding, factory-threaded nullable resolver like `makeTenantBlobPolicy`)
- Test: `tests/Unit/Tenancy/Authorization/TenantMembershipRoleReaderTest.php` (integration-backed, PG harness)

**Interfaces:**
- Consumes (verified): `CurrentTenantResolver::tenantUuid(ApplicationContext): string`; `db($context)->table('tenant_memberships')` builder over the unique `(tenant_uuid,user_uuid)` index; `status='active'` literal (verified pipeline convention).
- Produces: `TenantMembershipRoleReader::__construct(ApplicationContext $context, ?CurrentTenantResolver $resolver = null)`; `resolvedTenantUuid(): ?string` (`''`→null); `roleFor(Request $request, string $userUuid): ?string` — one indexed lookup `where tenant_uuid = resolved AND user_uuid = ? AND status='active'`, returns `role` or null (no membership / no resolved tenant). Memoization lives ONLY on the Symfony request attributes under a private key containing `tenant:user`; the shared reader holds no role cache, so revocation is visible on the next request and long-lived workers cannot leak roles across requests. Never enumerates members.

- [ ] **Step 1: Failing tests** — member → role; non-member → null; inactive membership → null; no resolved tenant → null; **single-query proof**: enable the connection query log (or a counting wrapper), call `roleFor($sameRequest, ...)` three times → exactly one `tenant_memberships` SELECT; a second Request performs a fresh SELECT and observes a role revocation made between requests.
- [ ] **Step 2: Implement + binding.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 3: `OperatorBypass` + best-effort audit

**Files:**
- Create: `app/Content/Authorization/OperatorBypass.php`, `app/Content/Authorization/BypassDecision.php`
- Test: `tests/Unit/Tenancy/Authorization/OperatorBypassTest.php`

**Interfaces:**
- Consumes (verified): `PermissionManager::can(string,string,string,array): bool` (resolved via the same container-id scan `RequirePermission::permissionManager()` uses, `:133-150` — extract that scan into this class or share a small locator); `AuditRecorderInterface::record(AuditEntry): void` optional (`has() ? get() : null`); `AuditEntry` ctor (verified shape — `action: 'tenancy.operator_bypass'`, `category: 'security'`, `actorUuid`, `targetType: 'tenant'`, `targetUuid`, `context: {capability, route, mode}`); `Request::headers->get('X-Tenant-Operator-Mode')`.
- Produces: `BypassDecision` readonly VO `{granted: bool, mode: ?string /* 'foreign'|'escalated' */, reason: string}`; `OperatorBypass::evaluate(Request $request, string $userUuid, ?string $membershipRole, string $capability, string $resolvedTenantUuid, array $aegisContext): BypassDecision` implementing spec §4 exactly:
  1. membership exists AND header absent → `granted: false` (membership wins — never implicit).
  2. Candidate mode: no membership → `'foreign'`; membership + header `X-Tenant-Operator-Mode: 1` → `'escalated'`.
  3. Requires Aegis `can($user, 'tenancy.access_any', 'thallo', $ctx)`; then the capability check uses an explicit constant map: `tenant.members.manage` and `tenant.domains.manage` → `tenancy.manage`; any OTHER `tenant.*` → deny; all non-tenant capabilities → their literal slug.
  4. On grant: best-effort audit attempt (recorder null-safe; a throwing recorder is impossible per contract but wrap defensively anyway — the request proceeds).

- [ ] **Step 1: Failing tests** — the §4 matrix: member+no-header → denied regardless of grants; member+header+access_any+capability → escalated grant + audit entry captured (fake recorder); no-membership+access_any+capability → foreign grant + audit; header without access_any → denied; access_any without the capability (or without `tenancy.manage` for either explicitly mapped tenant-management capability) → denied; unknown `tenant.billing.manage` denies even when `tenancy.manage` is granted; recorder absent → grant still works, no error; recorder invoked exactly once per granted bypass.
- [ ] **Step 2: Implement.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 4: `RequirePermission` §1 evolution + truth-table suite

**Files:**
- Modify: `app/Content/Http/RequirePermission.php` (ctor gains `?TenantMembershipRoleReader $roleReader = null, ?RoleMatrix $matrix = null, ?OperatorBypass $bypass = null` — nullable for existing direct-construction tests, factory-threaded in production; incomplete wiring fails closed when tenant request-state is present), `app/Providers/ThalloServiceProvider.php:1129-1134` (factory threads the trio)
- Test: `tests/Integration/Tenancy/TenantAuthorizationTruthTableTest.php`

**Interfaces:**
- Consumes: T1/T2/T3 exact signatures; verified handle() flow (the api-key branch `:47-55` and `resolvePrincipal()` stay untouched and FIRST).
- Produces — the new decision core, inserted after principal resolution (replacing the bare `can()` call at `:80` ONLY when a tenant is resolved):

```php
$tenantContextPresent = $this->context->getRequestState('tenancy.tenant') !== null;
if (!$tenantContextPresent) {
    // tenant_system/off/inert routes: today's global Aegis path, byte-for-byte.
    $allowed = $manager->can($principal['uuid'], $permission, $this->resourceFor($request), $context);
} else {
    if ($this->roleReader === null || $this->matrix === null || $this->bypass === null) {
        return $this->forbidden(); // resolved tenant + incomplete SP3a wiring MUST NOT fall back globally
    }
    $resolvedTenant = $this->roleReader->resolvedTenantUuid();
    if ($resolvedTenant === null) {
        return $this->forbidden(); // torn context/resolver state
    }
    $role = $this->roleReader->roleFor($request, $principal['uuid']);
    $allowed = ($role !== null && $this->matrix->allows($role, $permission))
        || $this->bypass->evaluate(
            $request,
            $principal['uuid'],
            $role,
            $permission,
            $resolvedTenant,
            $context
        )->granted;
}
if (!$allowed) {
    return $this->forbidden();
}
```

- [ ] **Step 1: Failing truth-table suite** (PG harness, full-resolution mode, two tenants, real routes): the four §3 cases as named tests — `testGlobalAdministratorWithViewerMembershipGetsViewerPowers` (the current-hole regression: 403 on `content.delete`, 200 on `content.view`); `testPlatformAdministratorInOrdinaryTenantModeGetsMembershipPowers`; `testPlatformAdministratorWithExplicitBypassGetsAegisGrantedPowers` (+ audit asserted); `testMemberWithNoAegisRoleDraftsByMatrixAlone` (view/create/edit 200, publish 403). Plus per-family checks on package routes (viewer 403 `navigation.manage`; admin 200 `seo.manage`, `workflow.review`); api-key branch regression (unchanged behavior); `tenant_system` route byte-identical (users.* route with/without global role); resolved tenant + any missing collaborator → 403; request-state tenant present but resolver returns `''` → 403.
- [ ] **Step 2: Implement + factory threading.** Run → PASS (+ full on-suite); phpcs. Commit SKIPPED.

---

### Task 5: Management re-key + self-service relocation + target binding

**Files:**
- Modify: `packages/thallo-tenancy/routes/enablement.php` (Group A actions re-key `content_permission:system.access` → `content_permission:tenancy.manage` (`:15-30`); Group B: tenants lifecycle + list stay `tenant_system` re-keyed to `tenancy.manage` (`:39-42`); member/domain routes (`:44-60`) MOVE into a new group `['auth','tenant_profile:admin','tenant_bootstrap']` with `content_permission:tenant.members.manage` / `content_permission:tenant.domains.manage`; `/my-tenants` untouched), `packages/thallo-tenancy/src/Http/Controllers/TenantDomainController.php` + `TenantMembershipController.php` (target binding), `tests/Integration/Tenancy/RouteCoverageTest.php` (marker expectations for relocated routes — they now satisfy the EXISTING `['auth','tenant_profile:admin']` adjacency prefix, verified `:51-59`)
- Test: `tests/Integration/Tenancy/SelfServiceBindingTest.php`, `tests/Integration/Tenancy/ManagementRekeyTest.php`

**Interfaces:**
- Consumes: relocated routes' controllers already receive route params (`{uuid}` tenant routes; `{uuid}` DOMAIN uuid on verify/enable/disable/delete — spec §6 pinned); `TenantMembershipRoleReader::resolvedTenantUuid()` (binding source); `TenantDomainAdministration::getDomain()` (owner check loads one domain through the neutral contract and reveals nothing foreign).
- Produces — controller-level binding rule (spec §6 verbatim): member routes + domain index/create: `routeTenantUuid === resolvedTenantUuid` else `forbidden()` (non-revealing); domain-item routes: load domain via `getDomain()`, require `domain.tenant_uuid === resolvedTenantUuid`, else the SAME non-revealing deny as an unknown uuid. There is NO controller bypass exception and NO authorization decision in request-state: an operator reaches a foreign tenant by explicitly selecting it, after which that tenant is the resolved tenant and the same equality invariant still applies.

- [ ] **Step 1: Evolve RouteCoverageTest FIRST (RED)** — relocated routes must now carry `tenant_bootstrap` (exactly-one-marker) with the admin-profile prefix; lifecycle/my-tenants stay `tenant_system`; add the assertion that NO route carries `content_permission:system.access` under `/v1/admin/tenancy` anymore.
- [ ] **Step 2: Relocate + re-key routes; implement binding in the two controllers.**
- [ ] **Step 3: Failing binding/re-key tests** — owner manages own tenant's members/domains → 200; owner-of-both-tenants with tenant B's uuid while resolved on A → 403; foreign DOMAIN uuid → non-revealing deny (same body as nonexistent); operator selects tenant B explicitly, then targets B → 200 + audit; operator resolves A but targets B → 403 despite bypass grants; `system.access`-holding non-operator: tenant create → 403, enablement status → 403; `tenancy.manage` holder → 200 on existing lifecycle/resolution/disable HTTP surfaces; suspend/create/reactivate remain operator-only (owner → 403). Diagnose is CLI-only today and has no request principal; its future SP3b HTTP surface will be gated by `tenancy.manage` when added.
- [ ] **Step 4: Run → PASS (incl. SP2a management API tests updated for the new keys); phpcs. Commit SKIPPED.**

---

### Task 6: Migration 013 — operator grants

**Files:**
- Create: `database/dependent-migrations/013_GrantTenancyOperatorToAdministrator.php` (013 verified free; copy `009_GrantWorkflowPermissionsToAdministrator.php` shape: `const PERMISSIONS = ['tenancy.manage' => 'Manage tenants', 'tenancy.access_any' => 'Access any tenant']`, `const ROLE = 'administrator'`, `ensurePermissions()` insert-if-missing by slug with `Utils::generateNanoID()` + `is_system=true` (`009:74-98`), role lookup by slug — return early if absent (`:36`), idempotent `role_permissions` insert (`:105-127`), `down()` removes grants only (`:44-62`))
- Test: `tests/Integration/Tenancy/OperatorGrantMigrationTest.php`

- [ ] **Step 1: Failing test** — run up() on the test DB: both permission rows exist, both granted to administrator; run up() twice → no duplicates; `TenantAccess`-style check: `PermissionManager::can(adminUuid, 'tenancy.access_any', '', [])` → true post-migration (canBypass alive); down() removes grants, leaves permission rows.
- [ ] **Step 2: Implement + register in `scripts/run-test-migrations.php` if the hardcoded list requires it (verify — memory: the list is hardcoded).** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 7: Acceptance + regression gates

**Files:** Extend `tests/Integration/Tenancy/TenantAuthorizationTruthTableTest.php` (or a dedicated `AuthorizationAcceptanceTest`) with the journey slice; Test = this task.

- [ ] **Step 1: Acceptance (SP3 index §6, authorization slice):** full-resolution install, two tenants; invite `admin` + `viewer` to tenant A (owner self-service path); assert the §3 truth table end-to-end over real HTTP: viewer cannot author anywhere (content/navigation/seo/templates); admin has the full tenant set but cannot demote the final owner (bridge protection unreachable by matrix); neither sees tenant B (profile + matrix); the platform administrator works membership-scoped in A, escalates with the header (audited), operates foreign on B (audited); member-with-no-Aegis-role drafts.
- [ ] **Step 2: Gate runs:** full off/on/inert suites; SP2a/b/c acceptance untouched; route-inventory + RouteCoverage green; `composer phpcs`; `composer boundaries`. Record counts in the ledger.
- [ ] **Step 3: Ledger. Commits remain HELD** (batch shape at go-ahead: authorization core (T1-T4) · routes+binding+rekey (T5) · migration (T6) · tests).

---

## Self-Review (rev 2)

**Spec coverage:** §1 rule → T4 (exact decision core; api-key + principal resolution untouched-first; resolved+incomplete wiring denies); §2 matrix + inventory pinning → T1 (config verbatim; the live-slug freeze test with the deliberate-addition failure message); §3 truth table → T4 named tests verbatim incl. the current-hole regression; §4 explicit bypass + narrowing + best-effort audit → T3 (+ config in T1; explicit tenant-management mapping, unknown tenant capability denies); §5 re-key → T5; §6 relocation + tenant/domain-uuid binding + non-revealing denies → T5 (same equality rule for operators; no controller bypass); §7 reader pins (neutral resolver, raw indexed read, Request-local memoization, never listMembers) → T2 (+ cross-request revocation proof); §8 seeding + narrowing note → T6 + T1 config; §9 untouched-list respected (zero Aegis/extension/contracts/framework files in the File Structure); §10 failure modes distributed as named tests; §11 every listed matrix present; §12 out-of-scope respected.

**Verified-contract basis:** every Consumes cites the sweep's file:line — `RequirePermission` flow (`:36-155`), alias registration (`ThalloServiceProvider.php:1129-1134`), `tenant_profile` alias (`:230`) and consumption sites, enablement route groups (`:14-61`), RouteCoverageTest prefixes (`:51-59`), `CurrentTenantResolver` semantics (`:20`), membership unique index (`002:40`), `AuditRecorderInterface`/`AuditEntry` (`:18-22`/`:28-40`), `PermissionManager::can` (`:124-132`), grant pattern (`009:36-127`), 013 slot free, and the 19-slug live inventory. One deliberate verify-at-execution step: T6's `run-test-migrations.php` hardcoded-list check (known gotcha, named).

**Type consistency:** `RoleMatrix::allows/capabilities/isTenantCapability` (T1) consumed in T4; `TenantMembershipRoleReader::resolvedTenantUuid/roleFor(Request, userUuid)` (T2) in T4/T5; `OperatorBypass::evaluate(...): BypassDecision{granted,mode,reason}` (T3) is consumed only inside T4 and never trusted by controllers; capability strings identical across T1 config, T3's explicit bypass map, T5 route annotations, and test names.

## Execution Record

Implemented on `dev`; all commits remain HELD. One implementation correction preserved the
package boundary: pack-owned member/domain controllers use the neutral
`CurrentTenantResolver` directly for target binding instead of importing the app-owned
`TenantMembershipRoleReader`; role lookup remains app-owned inside `RequirePermission`.

- Focused authorization, route-inventory, target-binding, migration, and route-coverage tests: green.
- `composer phpcs`: 1,069 files clean.
- `composer boundaries`: 10 packages clean.
- Default/off-inert suite: 1,688 tests, 17,722 assertions, 53 skipped.
- `THALLO_TENANCY_DEV_LINK=1 composer test`: 1,803 tests, 18,257 assertions, 1 skipped.
