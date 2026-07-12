# Per-Tenant Roles & Matrix Overrides Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a workspace deviate from the global role matrix — checkpoint 1: per-tenant capability overrides on the four built-in roles (Thallo-only); checkpoint 2: per-tenant custom role definitions (engine seams, batched into `glueful/tenancy` 2.0.0).

**Architecture:** A declared capability catalog + a canonical `baseline_policy_hash` define the validation universe. Workspace deviations are normalized delta rows (`tenant_role_overrides`, grant/revoke, unique per triple) over the live global baseline; a per-tenant policy version increments in the same transaction as every mutation, and effective-role cache keys embed `tenant + version + hash + role` so stale grants are unreachable by construction. A tenant-aware `EffectiveRoleMatrix` replaces the matrix lookup in `RequirePermission` with classification-before-short-circuit. Checkpoint 2 adds `tenant_roles` plus engine-local `MembershipRoleAuthority`/`MembershipRoleLock` seams so every membership mutation — regardless of caller — validates and locks identically.

**Tech Stack:** PHP 8.3+, PostgreSQL (advisory xact locks), Glueful framework (`CacheStore`, audit), `glueful/tenancy` (vendored, post-provider-split), Thallo packs + admin SPA (Vue 3, Pinia setup stores, vitest), PHPUnit against real PostgreSQL.

## Global Constraints

- **HOLD ALL COMMITS.** Stage only. Work on `dev`. No attribution, no tags, never stage `CLAUDE.md`.
- **Checkpoint 1 is Thallo-only and shippable alone.** Checkpoint 2's engine changes are vendor-first (`vendor/glueful/tenancy`) and **batch with the unreleased provider split into `glueful/tenancy` 2.0.0**. **No `extension-contracts` change.**
- **PHP style:** `declare(strict_types=1)`, `final`, constructor DI, `use`-imports, `composer phpcs` clean (120-char). SPA: setup stores, `data-testid`, no tail-piped tsc.
- **Effective computation (pinned):** built-in = `((baseline ∪ grants) − revokes) ⊕ owner floor`; custom = grants only (∩ catalog, non-platform, role active); unknown/missing/disabled role → ∅ fail-closed.
- **Owner floor:** `owner` always retains `tenant.roles.manage` + `tenant.members.manage`; revocation attempts are **rejected 422 at write time**, never silently ignored.
- **Cache keys:** `tenant_uuid + tenant_policy_version + baseline_policy_hash + role_slug`; the version increments **in the mutation's transaction**; `baseline_policy_hash` canonically covers catalog + global `role_matrix` + reserved built-in slugs + owner-floor capabilities + policy-algebra version.
- **Classification precedes the short-circuit:** baseline fast path only for the four reserved built-in slugs; a non-built-in slug never falls through to `RoleMatrix`.
- **Revoke wins** on corrupted duplicate rows; removing an override restores inheritance (never materialize the baseline).
- **Audit AFTER commit; policy writes + version increment IN the transaction.** Self-change: authorize pre-change; compute post-change in-transaction for invariants/response/audit; subsequent requests use the new version.
- **Policy mutation is aggregate and atomic:** a role's desired grant/revoke set is validated in full, diffed, written, and version-bumped exactly once in one transaction. No controller loops over independently committing one-capability mutations.
- **No shared-cache writes from an open policy transaction:** post-change invariant/response computation uses the uncached evaluator. Publishing an uncommitted result under a future version key would make it reachable if that version is later committed after rollback.
- **Operator rescue** requires BOTH `tenancy.manage` AND `tenancy.access_any` + explicit operator mode + audit naming the target workspace.
- **Custom role slugs are immutable** (rename = display name only); built-in slugs reserved; `assertNotFinalOwner` stays keyed on literal `'owner'`; custom roles never satisfy owner continuity.
- **Locking (checkpoint 2):** engine-provided per-(tenant, role) advisory xact locks; role **changes** lock source + destination in canonical sorted order and **re-read the membership after lock acquisition**; **new** memberships lock destination only; Thallo disable/delete/reassign acquire the identical engine lock.
- **Policy manifest** is deterministic, versioned, **data-only**; CLI validates + compares old/new hash; malformed/unsupported manifests fail closed; no executable or HTTP-supplied future policies.
- **Regression:** untouched workspaces behave byte-identically to today's four-role matrix; full Thallo off/on suites + engine suite (default bindings) green.

---

## File Structure

**Checkpoint 1 (Thallo):**
- `app/Content/Authorization/CapabilityCatalog.php` — CREATE: declared registry + `baselinePolicyHash()`.
- `app/Content/Authorization/PolicyManifest.php` — CREATE: data-only manifest build/parse/validate.
- `packages/thallo-tenancy/src/Console/PolicyManifestCommand.php` (or app Console) — CREATE: validate/compare CLI.
- `app/Content/Authorization/TenantRoleOverrideRepository.php` — CREATE: delta store + policy version.
- `app/Content/Authorization/EffectiveRoleEvaluator.php` — CREATE: pure, uncached effective-set computation.
- `app/Content/Authorization/EffectiveRoleMatrix.php` — CREATE: tenant-aware resolution + versioned cache.
- `app/Content/Authorization/TenantRolePolicyMutator.php` — CREATE: aggregate transaction, post-state, after-commit audit.
- `app/Content/Http/RequirePermission.php` — MODIFY: wire `EffectiveRoleMatrix`.
- `app/Http/Controllers/TenantRolesController.php` — CREATE: list/overrides/preview/rescue routes.
- `packages/thallo-tenancy/migrations/00X_CreateTenantRolePolicyTables.php` — CREATE: overrides + policy-version.
- `packages/thallo-tenancy/src/Enablement/TenancyDiagnostics.php` — MODIFY: drift check.
- `config/tenancy.php` — MODIFY: owner-floor grants (`tenant.roles.manage` to owner).
- SPA: workspace settings Roles page + store + tests.

**Checkpoint 2 (engine, vendored + Thallo):**
- `vendor/glueful/tenancy/src/Membership/MembershipRoleAuthority.php`, `Membership/ConfigRoleAuthority.php`, `Membership/MembershipRoleLock.php`, `Membership/AdvisoryMembershipRoleLock.php` — CREATE.
- `vendor/glueful/tenancy/src/TenancyControlPlaneProvider.php` — MODIFY: default bindings.
- `vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php` — MODIFY: locked membership mutations.
- `packages/thallo-tenancy/migrations/00Y_CreateTenantRolesTable.php` — CREATE.
- `app/Content/Authorization/TenantRoleRepository.php` + Thallo authority binding — CREATE.
- `TenantRolesController` — MODIFY: custom-role CRUD; membership role picker endpoint.
- SPA: custom-role CRUD UI + picker.

---

## CHECKPOINT 1 — per-tenant overrides (Thallo-only)

### Task 1: Capability catalog + `baseline_policy_hash` + policy manifest + CLI

**Files:**
- Create: `app/Content/Authorization/CapabilityCatalog.php`
- Create: `app/Content/Authorization/PolicyManifest.php`
- Create: `app/Console/PolicyManifestCommand.php` (register per Thallo console conventions — verify how app commands register; slice-1 CLIs are the model)
- Modify: `config/tenancy.php` (owner baseline gains `tenant.roles.manage`)
- Test: `tests/Unit/Authorization/CapabilityCatalogTest.php`, `tests/Unit/Authorization/PolicyManifestTest.php`

**Interfaces:**
- Produces:
  - `CapabilityCatalog::all(): array<string, array{label:string,group:string,platform_only:bool}>`; `has(string $slug): bool`; `isGrantable(string $slug): bool` (exists ∧ not platform_only); `ownerFloor(): list<string>` (returns `['tenant.roles.manage','tenant.members.manage']`); `reservedRoles(): list<string>` (`['owner','admin','member','viewer']`); `ALGEBRA_VERSION = 1`.
  - `CapabilityCatalog::baselinePolicyHash(ApplicationContext $c): string` — sha256 over the canonical JSON of `{algebra_version, reserved_roles (sorted), owner_floor (sorted), catalog (keys sorted), role_matrix (roles+capabilities sorted)}`.
  - `PolicyManifest::export(ApplicationContext $c): array` (versioned data-only structure `{manifest_version:1, algebra_version, reserved_roles, owner_floor, catalog, role_matrix, hash}`); `PolicyManifest::validate(array $manifest): list<string>` (errors; empty = valid; recomputes and compares the embedded hash; unknown `manifest_version` → error, fail closed); `PolicyManifest::compare(array $old, array $new): array` (old/new hash + per-role capability diff).
  - CLI `thallo:policy:manifest` with `--export`, `--validate <file>`, `--compare <old> <new>`.
- The catalog **declares** every capability currently in the global matrix (all `platform_only:false`) — enumerate them from `config/tenancy.php`'s `role_matrix` verbatim — plus `tenant.roles.manage`. `tenancy.manage`/`tenancy.access_any` are absent entirely.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Unit/Authorization/CapabilityCatalogTest.php
public function testCatalogCoversTheGlobalMatrixAndExcludesPlatformCaps(): void
{
    $catalog = new CapabilityCatalog();
    foreach (config($this->context(), 'tenancy.role_matrix', []) as $capabilities) {
        foreach ($capabilities as $capability) {
            self::assertTrue($catalog->has($capability), "catalog must declare {$capability}");
        }
    }
    self::assertFalse($catalog->has('tenancy.manage'));
    self::assertFalse($catalog->has('tenancy.access_any'));
    self::assertSame(['tenant.roles.manage', 'tenant.members.manage'], $catalog->ownerFloor());
}

public function testBaselinePolicyHashRotatesOnAnyInput(): void
{
    // Same inputs → same hash (determinism); a changed matrix → different hash.
    // Drive by comparing hash before/after overriding config('tenancy.role_matrix') in a scoped context.
}
```

```php
// tests/Unit/Authorization/PolicyManifestTest.php
public function testExportValidateRoundTrip(): void { /* export → validate → [] */ }
public function testTamperedHashFailsClosed(): void { /* flip one capability, keep hash → non-empty errors */ }
public function testUnknownManifestVersionFailsClosed(): void { /* manifest_version: 999 → error */ }
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit --filter=CapabilityCatalogTest` → class not found.
- [ ] **Step 3: Implement** `CapabilityCatalog` (pure-data class: a const map; hash method reads `role_matrix` from config, builds the canonical array with recursive key/value sorting, `hash('sha256', json_encode(..., JSON_THROW_ON_ERROR))`), `PolicyManifest`, and the CLI (extend `Glueful\Console\BaseCommand`; `--compare` prints old/new hashes + per-role added/removed capabilities; nonzero exit on validation errors).
- [ ] **Step 4: Add `tenant.roles.manage`** to the catalog and to `config/tenancy.php` `role_matrix.owner` only.
- [ ] **Step 5: Run tests** → PASS. `composer phpcs` on new files.
- [ ] **Step 6: Stage (HOLD)** — the four files + tests.

---

### Task 2: Override store + policy version (migration + repository + write-time guardrails)

**Files:**
- Create: `packages/thallo-tenancy/migrations/004_CreateTenantRolePolicyTables.php` (next free number — verify)
- Create: `app/Content/Authorization/TenantRoleOverrideRepository.php`
- Register in `ThalloServiceProvider::services()`.
- Test: `tests/Integration/Authorization/TenantRoleOverrideRepositoryTest.php`

**Interfaces:**
- Produces:
  - Tables: `tenant_role_overrides` (`id`, `tenant_uuid` s12, `role_slug` s64, `capability` s96, `effect` s8 `grant|revoke`, `created_by` s12 null, timestamps; **unique `(tenant_uuid, role_slug, capability)`** named `uniq_tenant_role_override`; index `tenant_uuid`) and `tenant_role_policy` (`tenant_uuid` s12 unique, `version` int default 0, `updated_at`).
  - `TenantRoleOverrideRepository::reconcileRoleOverridesInTransaction(string $tenantUuid, string $roleSlug, list<string> $grants, list<string> $revokes, ?string $actorUuid): array{version:int,set:list<array{capability:string,effect:string}>,cleared:list<array{capability:string,effect:string}>}` — **requires an active transaction**, validates the complete desired set before writing, reconciles the role's rows, and increments policy version exactly once. Throws `RoleOverrideException` (field errors → 422) for unknown/`platform_only` capabilities; owner-floor revocation; intersecting grant/revoke inputs; and, during checkpoint 1, **every non-built-in role slug**. There is no public one-row mutator for controllers to loop over.
  - `clearTenantOverridesInTransaction(string $tenantUuid): array{version:int,cleared:list<array{role_slug:string,capability:string,effect:string}>}` — active transaction required; rescue clears all rows and bumps once.
  - Reads: `overridesFor(string $tenantUuid): array<string, array<string,string>>` (role → capability → effect); `hasAnyOverrides(string $tenantUuid, string $roleSlug): bool`; `policyVersion(string $tenantUuid): int` (**0 when no row**).
- Consumes: `CapabilityCatalog` (Task 1).

- [ ] **Step 1: Failing tests** — no policy row returns version 0; first reconciliation returns 1 (its cache key cannot equal the pristine key); set/clear reconciliations bump exactly once each; invalid capability anywhere rejects the whole desired set with no rows/version change; owner-floor, intersecting grant/revoke, `platform_only`, unknown capability, and every checkpoint-1 custom slug throw; unique triple remains one row when its desired effect changes.
- [ ] **Step 2: Verify failure.**
- [ ] **Step 3: Migration** (pack style, `hasTable` guard, no cross-package FK — plain indexed `tenant_uuid` scalars per pack convention).
- [ ] **Step 4: Repository** — every mutation shaped as:

```php
// Caller owns the transaction; assert transactionLevel() > 0.
$this->validateCompleteDesiredSet($roleSlug, $grants, $revokes);
// Diff and reconcile all rows for this role.
// Bump once: INSERT ... VALUES (tenant, 1) ON CONFLICT (tenant_uuid)
//   DO UPDATE SET version = tenant_role_policy.version + 1 ... RETURNING version.
```

- [ ] **Step 5: Run tests** → PASS. Stage (HOLD).

---

### Task 3: `EffectiveRoleMatrix` + `RequirePermission` wiring

**Files:**
- Create: `app/Content/Authorization/EffectiveRoleEvaluator.php`, `EffectiveRoleMatrix.php`
- Modify: `app/Content/Http/RequirePermission.php` (swap `RoleMatrix->allows` for the tenant-aware call)
- Register in `ThalloServiceProvider`.
- Test: `tests/Integration/Authorization/EffectiveRoleMatrixTest.php`

**Interfaces:**
- Produces:
  - `EffectiveRoleEvaluator::capabilitiesForUncached(string $tenantUuid, string $role): list<string>` — pure DB/baseline computation with **no CacheStore read or write**; the only evaluator allowed inside policy-mutation transactions.
  - `EffectiveRoleMatrix::allows(string $tenantUuid, string $role, string $capability): bool`
  - `EffectiveRoleMatrix::capabilitiesFor(string $tenantUuid, string $role): list<string>` — committed-read facade: versioned-cache lookup, then delegates misses to `EffectiveRoleEvaluator` and stores the result.
  - Resolution order: (1) **classify** — if `$role` ∉ `CapabilityCatalog::reservedRoles()`, resolve `tenant_roles` (checkpoint 2; until then: return ∅); (2) built-in fast path — `hasAnyOverrides()` false → `RoleMatrix` baseline + owner floor; (3) full compute — `((baseline ∪ grants) − revokes) ⊕ ownerFloor` for `owner`; grants/revokes filtered to `CapabilityCatalog::has()` (drift ignored fail-closed).
  - Cache: `CacheStore::get/set` with key `sprintf('thallo:erm:%s:%d:%s:%s', $tenantUuid, $policyVersion, $baselineHash, $role)`; the version + hash are read per resolution (version read is one indexed row; acceptable — memoize per request via the request attribute idiom `TenantMembershipRoleReader` uses if profiling demands).
- Consumes: evaluator → `RoleMatrix`, `TenantRoleOverrideRepository`, `CapabilityCatalog`; cached facade → evaluator, repository version read, catalog hash, `CacheStore`.

- [ ] **Step 1: Failing tests** — grant adds to member (`collections.data.manage` on member → allowed in that tenant only, second tenant unaffected); revoke removes from admin; owner floor holds even with a (forced-raw-SQL) floor-revoke row present (read-time ⊕ floor wins); baseline change flows to non-overridden workspaces; **first-mutation and later version-key unreachability**; classification: role `'reviewer'` (no custom-role support yet) → false without reaching `RoleMatrix`; drift excluded; `capabilitiesForUncached()` neither reads nor writes `CacheStore`.
- [ ] **Step 2: Verify failure.**
- [ ] **Step 3: Implement** the uncached `EffectiveRoleEvaluator` and cached `EffectiveRoleMatrix`; wire `RequirePermission` line ~104: `$this->matrix->allows($role, $permission)` → `$this->effective->allows($resolvedTenant, $role, $permission)` (inject `EffectiveRoleMatrix`; `RoleMatrix` remains the evaluator's baseline).
- [ ] **Step 4: Run** the authorization + SP3 permission suites → PASS (untouched workspaces byte-identical).
- [ ] **Step 5: Stage (HOLD).**

---

### Task 4: Admin routes — list/overrides/runtime-preview + operator rescue + audit + diagnostics

**Files:**
- Create: `app/Content/Authorization/TenantRolePolicyMutator.php`, `app/Http/Controllers/TenantRolesController.php`; routes in `routes/admin.php` under the tenant-admin chain gated `content_permission:tenant.roles.manage` (rescue route gated separately).
- Modify: `packages/thallo-tenancy/src/Enablement/TenancyDiagnostics.php` (drift section).
- Test: `tests/Integration/Authorization/TenantRolesApiTest.php`

**Interfaces:**
- Produces:
  - `GET /v1/admin/tenancy/roles` — built-ins (+custom later) with `{slug, name, builtin, status, baseline, grants, revokes, effective, drift}`.
  - `TenantRolePolicyMutator::reconcile(...)` owns one transaction: validate the complete desired grant/revoke set → repository aggregate reconcile + one version bump → compute post-state through `EffectiveRoleEvaluator::capabilitiesForUncached()` → register one after-commit audit callback containing the exact set/clear diff → return the post-state. A failure at any point leaves rows, version, cache, and audit unchanged.
  - `PUT /v1/admin/tenancy/roles/{slug}/overrides` — body `{grants: [...], revokes: [...]}` delegates once to the mutator (violations → 422 with per-capability errors). Self-change is authorized by middleware pre-change; response uses the in-transaction uncached post-state. It never calls the shared-cache facade before commit.
  - `POST /v1/admin/tenancy/roles/preview` — body = proposed `{role_slug, grants, revokes}`; returns the effective diff against the **currently deployed** baseline (pure computation, no writes, no future-policy input).
  - `POST /v1/admin/tenancy/roles/{tenant}/reset` (operator rescue, platform route group): requires **both** `tenancy.manage` and `tenancy.access_any` + explicit operator mode; one mutator transaction clears all overrides, bumps once, computes uncached post-state as needed, and schedules `tenant.roles_reset` after commit naming the target.
  - Diagnostics: a `role_policy` section listing drift rows `{tenant_uuid, role_slug, capability}`.
- [ ] **Step 1: Failing tests** — owner floor 422; one invalid item in a multi-capability request rolls back the entire desired set and does not bump; successful multi-item reconcile bumps once; preview writes nothing; rescue authorization + one bump; forced failure after row/version writes leaves no rows, no version advance, no audit, and no shared-cache write; a later successful mutation reaching the same numeric version cannot observe rolled-back post-state; drift appears in diagnostics.
- [ ] **Step 2–4:** implement, run, PASS.
- [ ] **Step 5: Stage (HOLD).**

---

### Task 5: SPA — workspace Roles page (built-in overrides)

**Files:** workspace settings → Roles page + Pinia setup store + `admin/src/__tests__/workspaceRoles.spec.ts` (verify current settings-page structure + query patterns first; follow `workspaceDeletion.spec.ts` harness).

- [ ] Role list with effective capabilities grouped by catalog group; per-capability grant/revoke toggles showing inherited-vs-overridden state; save calls `PUT …/overrides` and renders 422 field errors (owner floor, platform caps); preview-before-save via the preview endpoint; drift indicators. `data-testid` hooks: `roles-list`, `role-editor`, `capability-toggle-<slug>`, `overrides-save`, `overrides-preview`.
- [ ] Tests: toggle → save payload shape; 422 renders per-capability errors; preview renders diff. Run `pnpm test workspaceRoles` + `pnpm type-check` → green.
- [ ] Stage (HOLD). **Checkpoint 1 gate:** full off/on suites + admin suite green; this state is shippable alone.

---

## CHECKPOINT 2 — custom roles (engine 2.0.0 batch)

### Task 6: Engine seams — `MembershipRoleAuthority` + `MembershipRoleLock` + locked membership mutations

**Files:**
- Create: `vendor/glueful/tenancy/src/Membership/{MembershipRoleAuthority,ConfigRoleAuthority,MembershipRoleLock,AdvisoryMembershipRoleLock,MembershipRoleConflictException}.php`
- Modify: `vendor/glueful/tenancy/src/TenancyControlPlaneProvider.php` (default bindings)
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php` (`addMember`/`setMemberRole`)
- Modify: `packages/thallo-tenancy/src/Http/Controllers/TenantManagementController.php`, `packages/thallo-tenancy/src/Console/MemberManageCommand.php` (typed conflict mapping)
- Test: `tests/Integration/Tenancy/MembershipRoleLockTest.php` (Thallo, live) + engine suite at port time

**Interfaces:**
- Produces (engine-local, **not** contracts):

```php
interface MembershipRoleAuthority
{
    public function isAssignable(ApplicationContext $c, string $tenantUuid, string $role): bool;
}

interface MembershipRoleLock
{
    /** Per-(tenant, role) xact advisory lock. Caller must hold a transaction. */
    public function lock(ApplicationContext $c, string $tenantUuid, string $role): void;

    /** Normalize + dedupe + sort role keys canonically, then lock each in order. @param list<string> $roles */
    public function lockMany(ApplicationContext $c, string $tenantUuid, array $roles): void;
}
```

  - `ConfigRoleAuthority` = today's allowlist check verbatim; `AdvisoryMembershipRoleLock` = `pg_advisory_xact_lock(hashtextextended('tenancy:role:'||$tenantUuid||':'||$role, 0))` (skip on sqlite, matching the engine's driver guards); both bound in the control-plane provider as defaults; the bridge takes both via constructor with `new ConfigRoleAuthority()` / `new AdvisoryMembershipRoleLock()` defaults.
- **`addMember` reorder** (assertRole moves inside; existing membership = role change):

```php
public function addMember(ApplicationContext $c, string $tenantUuid, string $userUuid, string $role): void
{
    db($c)->transaction(function () use ($c, $tenantUuid, $userUuid, $role): void {
        // Snapshot (unlocked) to decide the lock set: an existing membership makes this a role CHANGE.
        $existing = TenantMembership::query($c)
            ->where('tenant_uuid', $tenantUuid)->where('user_uuid', $userUuid)->first();
        $lockRoles = $existing === null ? [$role] : [$role, (string) $existing->role];
        $this->roleLock->lockMany($c, $tenantUuid, $lockRoles);          // sorted, deduped

        if (!$this->roleAuthority->isAssignable($c, $tenantUuid, $role)) {
            throw new \InvalidArgumentException('Unknown tenant membership role.');
        }

        // Re-read AFTER the locks; if the membership's role moved outside the locked set, the
        // lock set is wrong — fail with a retryable conflict rather than proceed unprotected.
        $current = TenantMembership::query($c)
            ->where('tenant_uuid', $tenantUuid)->where('user_uuid', $userUuid)->first();
        if ($current !== null && !in_array((string) $current->role, $lockRoles, true)) {
            throw new MembershipRoleConflictException('Membership role changed concurrently; retry.');
        }
        // ... existing create-or-update body unchanged ...
    });
}
```

  `setMemberRole` gets the same shape (snapshot source role → `lockMany([source, dest])` → `isAssignable(dest)` → re-read + conflict check → existing final-owner guard + guarded UPDATE). Delete `assertRole()` (replaced by the authority) — keep the same exception message for BC.
- **Concurrent first-add conflict:** two callers adding the same `(tenant_uuid,user_uuid)` with
  different destinations do not share a role lock. `addMember` catches PostgreSQL `23505` from the
  membership create, rolls back that attempt, and retries the whole snapshot/lock/re-read sequence
  **once**; the retry observes the now-existing source role and takes the sorted dual-lock path. A
  second `23505`, or a post-lock source outside the lock set, throws the typed retryable
  `MembershipRoleConflictException` (not a generic `RuntimeException`). Thallo HTTP maps it to 409;
  CLI names the retry condition and exits nonzero. Other SQL failures propagate unchanged.
- **Default-binding equivalence:** with `ConfigRoleAuthority`, behavior is byte-identical to today (engine suite proves it).

- [ ] **Step 1: Failing tests** — custom role assignment refused under default authority; opposing concurrent role changes serialize without deadlock; source moving outside the lock set throws `MembershipRoleConflictException`; two first-add callers for the same tenant/user with different destinations exercise the `23505` retry (one creates, the other re-snapshots and performs a locked role change) without a 500; persistent `23505` maps to the typed conflict; unrelated SQL errors are not swallowed; `assertNotFinalOwner` unchanged.
- [ ] **Step 2–4:** implement, run Thallo tenancy + engine suites → PASS.
- [ ] **Step 5: Stage (HOLD)** — Thallo tests only (vendor ports at release).

---

### Task 7: `tenant_roles` + Thallo authority binding + lifecycle service

**Files:**
- Create: `packages/thallo-tenancy/migrations/005_CreateTenantRolesTable.php`
- Create: `app/Content/Authorization/TenantRoleRepository.php` + `TenantRoleLifecycle.php`; Thallo `MembershipRoleAuthority` binding (built-ins always; custom iff active row) registered to override the engine default.
- Modify: `EffectiveRoleMatrix` — the non-built-in classification branch now resolves `tenant_roles` (active → grants-only compute; missing/disabled → ∅).
- Test: `tests/Integration/Authorization/TenantRoleLifecycleTest.php`

**Interfaces:**
- Produces:
  - Table `tenant_roles`: `tenant_uuid` s12, `slug` s64, `name` s160, `status` s16 `active|disabled`, stamps; unique `(tenant_uuid, slug)`; reserved built-in slugs rejected at create.
  - `TenantRoleLifecycle::create($tenantUuid, $slug, $name, $actor)` (slug format `[a-z][a-z0-9_]*`, reserved-slug 422); `rename(…, $name)` (display only); `disable`/`enable`; `delete($tenantUuid, $slug, ?string $reassignTo, $actor)` — one transaction: `MembershipRoleLock::lockMany([$slug, $reassignTo?])` → re-read role → if memberships exist: require `$reassignTo` (else 422) → validate destination → reassign → delete overrides + role → bump once → commit. Every mutation bumps once; audit after commit.
  - Checkpoint-2 evolution of `TenantRolePolicyMutator`: built-in reconciliation is unchanged; a custom slug first acquires the identical role lock inside the policy transaction, then re-reads `tenant_roles` and requires an existing row (active **or disabled**, so a disabled role may be configured before re-enable). Custom desired sets must have `revokes=[]` because custom roles have no baseline. Missing/deleted custom roles or custom revokes are 422. The lock remains held through override reconciliation + version bump, so delete cannot race a grant and leave orphan overrides.
  - Thallo authority: `isAssignable` = built-in ∨ active `tenant_roles` row (a **disabled** role is NOT assignable; existing memberships keep the slug and resolve to ∅).
- [ ] **Step 1: Failing tests** — create/assign/resolve custom role end-to-end; disabled role may be configured but resolves ∅ and refuses assignment; missing/deleted custom-role override write → 422; concurrent grant-vs-delete serializes and cannot leave orphan overrides; delete/reassign and assign/delete races serialize; custom role never satisfies final-owner.
- [ ] **Step 2–4:** implement, run → PASS.
- [ ] **Step 5: Stage (HOLD).**

---

### Task 8: Custom-role routes + server-derived member role picker + SPA

**Files:**
- Modify: `TenantRolesController` (+routes): `POST /roles`, `PATCH /roles/{slug}` (name/status), `DELETE /roles/{slug}?reassign_to=`, `GET /roles/assignable` (built-ins + active custom — the tenant-scope analog of slice-1's `AssignableRolesController`).
- Modify: the membership UI's role picker to consume `GET /roles/assignable`; SPA custom-role CRUD (create/rename/disable/delete-with-reassignment modal) on the Roles page.
- Test: `tests/Integration/Authorization/TenantRolesApiTest.php` (append) + `admin/src/__tests__/workspaceRoles.spec.ts` (append).
- [ ] Failing tests → implement → PASS (`pnpm test` + `pnpm type-check`, no tail-piping). Stage (HOLD).

---

### Task 9: Regression sweep, docs, release batching

- [ ] **Full suites:** `composer test` (off), tenancy-on suite, engine suite (default bindings byte-identical), admin suite. The untouched-workspace equivalence and the 2C/provider-split regression sets green.
- [ ] **phpcs** clean.
- [ ] **Live smoke on `lemma`:** migrate, open the Roles page, set + clear an override, verify a member's access flips, run `thallo:policy:manifest --export`/`--validate`.
- [ ] **Docs:** ops guide section (roles model, owner floor, rescue procedure, manifest CLI); tracking index → 2A implemented (HELD).
- [ ] **Porting/release note:** checkpoint-2 engine files (Membership/ seams, control-plane bindings, bridge changes) port to the `glueful/tenancy` source repo and **batch with the provider split as 2.0.0** (upgrade notes: `serviceproviders.php` requirement from the split; `assertRole` replaced by `MembershipRoleAuthority` with a byte-identical default). Thallo pins after publish. Stage docs (HOLD); no commits.

---

## Self-Review

**1. Spec coverage:** §1 catalog/hash/manifest/overrides/policy-version/custom-roles → Tasks 1, 2, 7. §2 resolution/classification/version-keys/drift → Task 3 (+7 for the custom branch). §3 invariants: floor/platform denial/gate → Tasks 1–4; lifecycle/locks → Tasks 6–7; self-change/rescue/audit → Task 4. Aggregate mutation + uncached in-transaction post-state are explicit in Tasks 2–4. §4 seams/locked mutations/sorted dual-lock/re-read/2.0.0 → Task 6, including the same-user first-add retry gap. §5 routes/preview/picker/audit/SPA → Tasks 4, 5, 8. §6 checkpoints/tests → structure + Task 9. §7 out-of-scope respected. ✅

**2. Placeholder scan:** Tasks 5 and 8 (SPA) carry named verify-first instructions pointing at the concrete harness files rather than full component code — consistent with how this repo's SPA work is specified; all backend interfaces/invariants carry real code. No TBDs.

**3. Type consistency:** `CapabilityCatalog::{has,isGrantable,ownerFloor,reservedRoles,baselinePolicyHash}` is consistent in Tasks 1–4; `TenantRoleOverrideRepository::{reconcileRoleOverridesInTransaction,clearTenantOverridesInTransaction,overridesFor,hasAnyOverrides,policyVersion}` is consistent in Tasks 2/4/7; `EffectiveRoleEvaluator::capabilitiesForUncached` is the only in-transaction evaluator while `EffectiveRoleMatrix::{allows,capabilitiesFor}` is the committed cached facade; `MembershipRoleAuthority::isAssignable` / `MembershipRoleLock::{lock,lockMany}` is consistent in Tasks 6/7; `MembershipRoleConflictException` covers both post-lock source drift and exhausted first-add retries.

---

## Execution Record (2026-07-12)

Implemented both checkpoints across Thallo and the durable `glueful/tenancy` source repository. All
changes remain HELD and uncommitted.

- Shipped the declared capability catalog, canonical policy manifest/CLI, aggregate delta store,
  transactional policy versions, uncached evaluator + versioned cache facade, owner floor, drift
  diagnostics, dual-permission rescue, and after-commit audit.
- Added custom-role storage/lifecycle, immutable slugs, disable-to-zero behavior, atomic
  delete-with-reassignment, server-derived assignable roles, and the workspace Roles SPA.
- Added engine-local role authority/lock seams, moved assignment validation inside the transaction,
  added sorted source/destination locks, one retry for first-add `23505`, and typed 409 conflicts.
- Plan-time correction: Glueful retains the first service definition, so the host role authority is
  selected by the engine control-plane factory via `tenancy.membership.role_authority`; other hosts
  retain `ConfigRoleAuthority` by default.
- Registered the new tenant-owned policy tables and classified their raw PDO path under the existing
  mutation barrier/static audit.

Verification:

- Thallo off mode: 1,765 tests, 18,256 assertions, 59 skipped.
- Thallo tenancy-enabled: 1,896 tests, 18,865 assertions, 1 skipped.
- Admin: 75 files / 453 tests; type-check and production build clean.
- `glueful/tenancy`: 177 tests / 426 assertions; PHPCS and PHPStan clean.
- Thallo PHPCS (1,162 files), 10 package boundaries, raw-PDO audit, migrations, and diff checks clean.
- Live boot: new role/access endpoints resolve with expected anonymous 401 responses; admin bundle is
  available at `/admin` after rebuild.

Deferred under the standing hold: commits, publishing `glueful/tenancy` 2.0.0, and the Thallo pin.
