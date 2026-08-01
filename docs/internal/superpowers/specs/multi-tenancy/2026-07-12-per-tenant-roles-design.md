# Per-Tenant Roles & Matrix Overrides — Design

**Status:** spec in review (HELD — not committed)
**Slice:** Bucket 2, item 2A (per-tenant custom roles / permission-matrix overrides).
**Scope:** Both halves designed together, implemented in two checkpoints: (1) per-tenant capability
**overrides** on the built-in roles (Thallo-only, shippable alone); (2) per-tenant **custom role
definitions** (needs an engine seam).
**Release chain:** checkpoint 1 is Thallo-only. Checkpoint 2's engine changes batch with the pending
unreleased provider-split work into **`glueful/tenancy` 2.0.0** (the `serviceproviders.php`
requirement already justifies the major) → Thallo. Vendor-first. No `extension-contracts` change.
**Date:** 2026-07-12

---

## §0 Context — as-built (source-verified)

- **`RoleMatrix`** (`app/Content/Authorization/RoleMatrix.php`) — a closed, config-driven map from
  `config('tenancy.role_matrix')`: four fixed roles (`owner|admin|member|viewer`) → capability
  lists. Fail-closed with a warning for unknown role/capability pairs. 2C added `collections.*`
  (owner/admin only; finer member collection permissions were explicitly deferred to this item).
- **`RequirePermission`** (`content_permission` middleware): tenancy-active path resolves
  `TenantMembershipRoleReader->roleFor()` (the member's `tenant_memberships.role` in the resolved
  workspace) → `RoleMatrix->allows(role, capability)`, OR `OperatorBypass`. Non-tenant path falls
  through to Aegis `PermissionManager::can()`.
- **Engine membership allowlist:** `tenancy.membership.roles = ['owner','admin','member','viewer']`,
  enforced by `ContractTenantAdministration::assertRole()` on `addMember`/`setMemberRole`.
  `assertNotFinalOwner()` (row-locked) is keyed on the literal `'owner'` role.
- **Aegis global roles are a separate axis** (slice-1 `workspace_manager`/`RoleAuthority`/
  continuity guards): cross-workspace platform roles, not workspace membership roles. This item
  changes only the **membership-role** axis.
- **Bypass permissions:** `tenancy.access_any` (selection), `tenancy.manage` (lifecycle authority)
  — platform capabilities, never part of the tenant matrix.
- **Pending engine state:** the provider split (control-plane + enforcement) is implemented and
  unreleased; `TenantAdministration` binds from the always-on `TenancyControlPlaneProvider`;
  `SystemFlags::enforcementActive()` is the canonical activation signal.

---

## §1 Model & storage

**Capability catalog** (new, declared): the explicit registry of tenant capabilities — slug, label,
group, `platform_only` flag — replacing the implicit "union of the matrix" as the validation
universe. Grantable-via-delta = catalog entries not `platform_only`. `tenancy.manage` /
`tenancy.access_any` are **not in the tenant catalog at all**. The catalog carries a stable
**`baseline_policy_hash`** used in cache keys and upgrade diagnostics. It is a canonical hash over
every input that can change effective authorization: the catalog, global `role_matrix`, reserved
built-in role slugs, owner-floor capabilities, and an explicit policy-algebra/normalization version.
Changing any of those inputs rotates the hash; a code-level floor or algebra change therefore cannot
reuse an effective-role cache entry produced under the previous policy.

**Checkpoint 1 — `tenant_role_overrides`** (delta store): `tenant_uuid`, `role_slug`, `capability`,
`effect` (`grant|revoke`), audit stamps. **Unique `(tenant_uuid, role_slug, capability)`** — one row
per triple; its `effect` is the verdict; contradictory rows cannot coexist by construction, and if a
corrupted duplicate ever appears, **revoke wins**. Removing a row restores inherited baseline
behavior; nothing ever materializes "the current baseline value" as an override.

**Checkpoint 2 — `tenant_roles`** (custom role definitions): `tenant_uuid`, `slug`, `name`,
`status` (`active|disabled`), stamps; unique `(tenant_uuid, slug)`. The four built-in slugs are
**reserved** — they cannot be created, deleted, or renamed (built-ins remain config-baseline roles,
not rows). **Custom role slugs are immutable**: "rename" changes only the display `name` — a slug
migration would require atomic rewrites of memberships, overrides, cache keys, and audit references,
and is deferred unless a concrete need appears. Custom-role capabilities live in the same override
table with `effect=grant` only (no baseline to revoke from).

**Per-tenant policy version** (`tenant_role_policy_version`, or a column on an existing per-tenant
policy row): incremented **in the same transaction** as every role/override mutation.

**Effective computation:**

```
built-in role:  ((global baseline ∪ tenant grants) − tenant revokes) ⊕ owner floor
custom role:    tenant grants only (∩ catalog, non-platform), role row must be active
unknown role / missing custom-role row / disabled role → ∅   (fail closed)
```

---

## §2 Resolution, caching & versioning

- `RoleMatrix` remains the **baseline** reader. A new tenant-aware **`EffectiveRoleMatrix`**
  (same `allows()`/`capabilities()` shape plus `tenant_uuid`) replaces the matrix lookup in
  `RequirePermission`'s tenancy path: `roleFor()` →
  `EffectiveRoleMatrix->allows($tenantUuid, $role, $capability)`.
- **Classification precedes the short-circuit.** The "no overrides → baseline" fast path applies
  **only to the four reserved built-in slugs**. Any non-built-in slug is resolved against
  `tenant_roles`; a missing/disabled row yields ∅ — it must never fall through to `RoleMatrix`.
- **Transactional policy versioning, not mere invalidation.** Effective-set cache keys are
  `tenant_uuid + tenant_policy_version + baseline_policy_hash + role_slug`. Because the version
  increments in the mutation's transaction, old cached grants become **unreachable** even if cache
  deletion fails; any baseline-policy input change rotates `baseline_policy_hash` with the same
  effect.
- **Drift fails closed:** override rows naming capabilities absent from the catalog are ignored at
  read time and surface as drift in `thallo:tenancy:diagnose` (naming tenant, role, capability —
  never anything secret).

---

## §3 Guardrails (invariant set)

1. **Owner floor, beneath the delta algebra:** `owner` always retains `tenant.roles.manage` and
   `tenant.members.manage`. An override attempting to revoke either is **rejected at write time**
   (422), not silently ignored. Custom roles may *receive* `tenant.roles.manage` (delegation) but
   can never alter or bypass the owner floor.
2. **Platform-cap denial:** delta and custom-role grants validate against the catalog;
   `platform_only` or unknown capabilities are rejected at write time and fail closed at read time.
3. **Editing gate:** new `tenant.roles.manage` capability; the global baseline grants it to
   **owner only**; workspaces may delegate it via grants.
4. **Built-in `owner` cannot be deleted or renamed**; every workspace retains at least one active
   owner — the engine's `assertNotFinalOwner` invariant is unchanged, independent, and keyed on the
   literal built-in `'owner'`; **no custom role satisfies owner continuity**.
5. **Custom-role lifecycle:** **disable** → the role immediately yields ∅ effective capabilities
   but **retains its membership assignments** (and cannot be assigned to new members); **delete**
   requires zero memberships or an explicit **atomic reassignment** (one transaction: lock →
   reassign memberships → delete overrides + role row → increment policy version → commit).
6. **Concurrency:** custom-role assignment, disabling, deletion, and reassignment share engine-
   provided **per-(tenant, role) locks** so every caller participates (§4). A membership role change
   locks both its current/source role and requested/destination role in canonical sorted order;
   locking only the destination would race deletion of the source role. A new membership locks only
   its destination. The membership is re-read after lock acquisition before validation/write.
7. **Self-change semantics:** the current mutation is **authorized using the pre-change policy**.
   The post-change policy is computed inside the same transaction for invariant checks and the
   response/audit record; it does not retroactively invalidate the request. Every subsequent
   authorization uses the new version and may deny the actor.
8. **Operator rescue** requires **both** `tenancy.manage` **and** `tenancy.access_any`, an explicit
   operator (bypass) mode, and an audit record naming the target workspace — `tenancy.manage` alone
   never implies cross-workspace selection. Rescue (inspect + reset a workspace's overrides) is
   emergency tooling, not the normal recovery path; the owner floor makes lockout unreachable by
   construction.
9. **Audit after commit; policy in the transaction.** Authorization-state writes and the policy-
   version increment are transactional; audit records are emitted **after commit** (never for a
   rolled-back mutation).

---

## §4 Engine seam (checkpoint 2) — role authority + role lock

`ContractTenantAdministration` stops validating roles against the static config allowlist and
consults two **engine-local** seams (no `extension-contracts` change):

```php
interface MembershipRoleAuthority
{
    public function isAssignable(ApplicationContext $c, string $tenantUuid, string $role): bool;
}

interface MembershipRoleLock
{
    /** Acquire the per-(tenant, role) transaction-scoped lock. Caller must be in a transaction. */
    public function lock(ApplicationContext $c, string $tenantUuid, string $role): void;
}
```

- **Engine assignment methods participate in the lock themselves** — Thallo locking only its own
  controllers cannot protect direct `TenantAdministration::addMember()` callers. `addMember` /
  `setMemberRole` become: `BEGIN → read current membership role → collect the unique current +
  requested role keys → sort keys → lock each key → re-read membership → validate the destination
  authority → write → COMMIT`. A membership that does not yet exist has no source key and locks only
  the requested destination. Canonical sorting prevents two opposite role changes from deadlocking.
- **Default bindings** (engine control-plane provider): authority = the config-allowlist behavior
  (hosts without per-tenant roles are unaffected); lock = the engine's advisory-lock idiom
  (`pg_advisory_xact_lock(hashtextextended('tenancy:role:'||tenant||':'||role, 0))`).
- **Thallo bindings:** authority — built-ins always assignable; custom roles assignable iff an
  **active** `tenant_roles` row exists. Thallo's disable/delete/reassign operations acquire the
  **identical lock through the same service**, closing the race regardless of caller.
- Memberships may carry a role string whose row was later deleted/disabled: assignment-time
  validation is the authority's job; resolution-time fail-closed (∅) is `EffectiveRoleMatrix`'s.
- **Release:** these engine changes batch with the unreleased provider split as
  **`glueful/tenancy` 2.0.0** (upgrade notes: `serviceproviders.php` requirement from the split +
  the new `assertRole` internals; both seams have back-compatible defaults).

---

## §5 Admin surface

- **Routes** (tenant-admin chain `tenant_profile:admin` → `tenant_bootstrap`, gated
  `content_permission:tenant.roles.manage`):
  - `GET /roles` — built-ins + custom roles with effective capabilities, override deltas, drift
    markers.
  - `PUT /roles/{slug}/overrides` — set/clear delta rows; write-time 422 for owner-floor,
    `platform_only`, or unknown capabilities.
  - `POST /roles`, `PATCH /roles/{slug}` (display-name, disable/enable), `DELETE /roles/{slug}`
    (optional `reassign_to`) — checkpoint 2.
  - `POST /roles/preview` — **runtime preview**: evaluates a **proposed override edit** against the
    **currently deployed** baseline/catalog and returns the per-role effective diff. It does NOT
    accept a hypothetical future baseline/matrix.
- **Deployment preview is separate and executable without historical storage:** each release ships a
  deterministic, versioned **policy manifest** containing the canonical inputs to
  `baseline_policy_hash`. Before replacing the deployed release, a CLI accepts the next release's
  local manifest file, validates its schema/version and recomputed hash, and compares current
  effective permissions against the proposed manifest for customized workspaces. The resulting
  report is keyed by old/new policy hashes and may be retained as a deployment artifact; the
  application does not retain historical policy snapshots. Arbitrary matrices are never accepted
  through the admin HTTP API, and the manifest is data-only — it cannot contain executable code.
- **Member role picker** becomes server-derived per workspace (built-ins + active custom roles) —
  the tenant-scope analog of slice-1's `AssignableRolesController`.
- **Operator rescue endpoint** per §3.8 (inspect + reset; dual-permission + operator mode + target-
  naming audit).
- **Audit actions** (after commit): `tenant.role_override_set`, `tenant.role_override_cleared`,
  `tenant.role_created`, `tenant.role_renamed` (display name), `tenant.role_disabled`,
  `tenant.role_enabled`, `tenant.role_deleted`, `tenant.role_memberships_reassigned`,
  `tenant.roles_reset` (operator rescue).
- **SPA:** workspace settings → Roles page — role list with drift indicators, capability editor
  grouped by catalog groups, custom-role CRUD, delete-with-reassignment modal, preview-before-save
  (runtime preview endpoint). Setup stores, `data-testid` hooks.

---

## §6 Checkpoints & testing

**Checkpoint 1 (Thallo-only, shippable alone):** capability catalog + `baseline_policy_hash` +
versioned policy manifest;
`tenant_role_overrides` + policy version; `EffectiveRoleMatrix` + `RequirePermission` wiring +
classification-before-short-circuit; owner floor + platform-cap write rejection; runtime preview;
built-in-role overrides UI; drift diagnostics; operator rescue; audit.

**Checkpoint 2 (engine 2.0.0 batch):** `tenant_roles` + immutable slugs + lifecycle;
`MembershipRoleAuthority` + `MembershipRoleLock` engine seams with default bindings; Thallo
bindings + locked disable/delete/reassign; custom-role UI + server-derived member role picker.

**Testing:**
- Delta algebra: grant/revoke composition; revoke-wins on corrupted duplicates; removal restores
  inheritance (a later baseline change flows through); custom-role grants ∩ catalog.
- Write rejection: owner-floor revocation 422; `platform_only` and unknown capabilities 422.
- Versioned cache: old `(tenant, version, hash, role)` keys become unreachable after a mutation
  **even when cache deletion is suppressed**; rotating the catalog, matrix, reserved roles, owner
  floor, or policy-algebra version rotates the policy hash with the same effect.
- Classification: a non-built-in slug with no row → ∅ and never reaches `RoleMatrix`; disabled role
  → ∅ with memberships retained; built-in fast path byte-identical for untouched workspaces.
- Lifecycle races: concurrent assign-vs-delete and assign-vs-disable serialize on the engine lock
  regardless of entry point (direct `TenantAdministration` call vs Thallo controller); changing an
  existing membership locks source + destination in sorted order and re-reads after locking; opposite
  A→B / B→A changes do not deadlock; delete with reassignment is atomic (lock → reassign → delete →
  version bump → commit).
- Deployment preview: a valid next-release data manifest produces an old/new policy-hash report;
  malformed, hash-mismatched, or unsupported-version manifests fail closed without changing policy.
- Self-change: actor's mutation authorized pre-change; post-change state checked in-transaction and
  reflected in response/audit; subsequent request denied under the new version.
- Owner continuity: last-active-owner unchanged; custom roles never satisfy it.
- Rescue: requires `tenancy.manage` + `tenancy.access_any` + operator mode; audited with target;
  single-permission attempts refused.
- Audit-after-commit: no audit record for a rolled-back mutation.
- Engine defaults: hosts on the default authority/lock bindings behave exactly as the config
  allowlist did (engine suite).
- Regression: full Thallo off/on suites; untouched workspaces behave identically to today.

---

## §7 Out of scope

Custom-role slug migration/renaming; per-tenant deviation of **global Aegis roles** (the platform
axis is untouched); role templates/sharing across workspaces; capability wildcards; retaining
historical baseline snapshots for arbitrary-version previews; accepting executable or HTTP-supplied
future policies; public signup (2B).
