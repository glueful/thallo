# SP3 — Tenant Administration UI & Membership/RBAC (Index)

> Umbrella document for the SP3 sub-projects, in the SP2-README mold: objective, dependency
> graph, shared invariants and terminology, sub-project table, contract ledger, and the
> acceptance journey. Slices cite this as "SP3 index §n". SP1 + SP2a/b/c are shipped
> (framework 1.68.0, contracts 1.2.0, tenancy 1.2.0, Thallo committed).

## §1 Objective and boundaries

**Objective:** make multi-tenant Thallo **operable by humans**: a finalized authorization
model composing tenant membership roles with Aegis RBAC (SP3a), and the admin-SPA surfaces —
enablement lifecycle, tenant/domain/membership management, diagnostics — built on that
finalized model (SP3b).

**In scope (SP3 overall):** the tenant-role capability matrix (what owner/admin/member/viewer
may do WITHIN a tenant); tenant-aware `content_permission` evaluation; platform-operator
bypass semantics; the Settings → Tenancy enablement lifecycle page (action-driven
status/progress,
begin/confirm, retry/cancel, fresh-boot guidance, resolution activate/deactivate, disable
with gate refusals, diagnose report); tenant management UI (list/create/suspend/reactivate,
domains with TXT-verification UX, membership management with the ratified role picker).

**Out of scope (SP3):** billing/quotas/plan tiers; destructive rollback to `off`; tenant
deletion + host-retention policy; collections tenancy (fence stands); cross-tenant content
sharing; custom-domain TLS automation; background re-verification; public signup/self-serve
tenant creation (admin-created tenants only).

## §2 Dependency graph

```
SP3a  Membership × Aegis RBAC composition   (the authorization model)
  └─> SP3b  Enablement + tenant-management UI (built on the ratified model:
             role picker, capability-gated screens, my-tenants semantics)
```

- SP3a → SP3b: the UI's role picker, screen gating, and member-management affordances all
  render the SP3a capability matrix; building UI first would bake in provisional semantics.
- Both consume only SHIPPED SP1/SP2 surfaces (TenancyEnablement, FullResolutionActivation,
  TenantAdministration/TenantDomainAdministration, TenancyDiagnostics, my-tenants, switcher).

## §3 Shared invariants and terminology

1. **Two axes, one decision.** *Membership role* (tenant_memberships.role: owner | admin |
   member | viewer — the extension's configured allowlist) answers "what may this user do
   WITHIN this tenant"; *Aegis RBAC* answers "what capabilities does this principal hold."
   SP3a defines their composition; until it ships, today's baseline stands: membership gates
   tenant access, Aegis gates capabilities tenant-blindly, and requests without membership
   are denied by the resolution pipeline.
2. **Platform operators bypass tenant checks explicitly, never implicitly.**
   `tenancy.access_any` is the sole request-time tenant-selection bypass; `tenancy.manage`
   authorizes lifecycle/management operations but does not itself skip membership. The
   pipeline's `forAnyTenant` marking remains the named CLI/system bypass. SP3a keeps every
   bypass explicit and submits a best-effort audit entry through the shipped audit contract.
3. **Fail closed** (SP2 index §3.1 carries over): an authorization question that cannot be
   answered is a denial. A tenant-aware permission check that finds no tenant context on a
   tenant-data surface denies.
4. **Contract-only cross-package rule** (SP2 index §3.2 carries over verbatim), including
   for any new authorization seams SP3a adds.
5. **Final-owner protection is invariant** (SP2 index §3.14): no composition of roles and
   permissions may allow removing/demoting the last active owner.
6. **The UI drives shipped services and invents no policy.** Every SP3b action maps 1:1 to
   an existing or SP3b-added HTTP action (enablement begin/confirm/retry/cancel/finalize/disable,
   resolution activate/deactivate, tenant/domain/member CRUD, provisioning seed repair,
   diagnose); refusals render the
   server's gate messages. Fresh-boot boundaries surface as explicit "re-run required"
   states in the UI, never hidden retries.
7. **Admin SPA conventions carry over:** setup-store Pinia, @pinia/colada queries,
   `authFetch`/client-middleware as the only header injection points, `data-testid` hooks,
   capability-gated module registry, no `UAuthForm`.
8. **Terminology:** *tenant role* = membership role within one tenant; *capability* = an
   Aegis permission string; *platform operator* = principal holding a bypass permission;
   *tenant-aware check* = a capability check evaluated against (principal, capability,
   tenant) rather than (principal, capability).
9. **Deliverable pointer:** the role capability matrix (tenant role × capability × surface)
   is an SP3a deliverable ratified in its spec — this index deliberately does not duplicate
   it once written; SP3b treats it as law.

## §4 Sub-projects

| Slice | Spec | Plan | Status |
|-------|------|------|--------|
| SP3a — Membership × Aegis RBAC composition | `2026-07-10-sp3a-membership-rbac-design.md` | `../plans/multi-tenancy/2026-07-10-sp3a-membership-rbac.md` | implemented (held) |
| SP3b — Enablement + tenant-management UI | `2026-07-10-sp3b-tenancy-admin-ui-design.md` | `../plans/multi-tenancy/2026-07-10-sp3b-tenancy-admin-ui.md` | plan ready (held); build gated on SP3a landing |

## §5 Cross-project contract ledger

| Contract / surface | Owner | Consumed by | SP3 change |
|--------------------|-------|-------------|------------|
| `TenancyEnablement` HTTP actions (status/begin/confirm/retry/cancel/finalize/disable) | thallo-tenancy pack | SP3b settings page | UI consumer + local `migrating_extension` retry-resume correction |
| `FullResolutionActivation` HTTP (status/activate via CLI parity, deactivate) | pack | SP3b settings page | SP3b adds the activate HTTP action mirroring CLI parity |
| `TenantAdministration` / `TenantDomainAdministration` (contracts 1.2.0) | tenancy ext bridge | SP3b management UI (via pack HTTP) | unchanged |
| `TenantSeedRepair` | thallo-tenancy pack contract + app implementation | SP3b provisioning-repair HTTP/UI | SP3b exposes the existing repair seam through an operator-only HTTP action |
| `TenancyDiagnostics` | pack | SP3b diagnostics view | SP3b adds the operator-gated read-only HTTP action |
| `PermissionManager` / Gate context (`['tenant_id' => …]`) | framework + Aegis | SP3a tenant-aware checks | SP3a defines whether/how the context dimension is honored |
| `content_permission` (`RequirePermission` middleware) | Thallo app | every admin tenant-data route | SP3a: the §1 rule — membershipMatrix OR explicitOperatorBypass; tenant_system routes unchanged |
| `TenantAccess` bypass (`tenancy.access_any`) | tenancy extension + Thallo config | pipeline, SP3a model | SP3a narrows the configured bypass list; `tenancy.manage` remains operation authority |
| my-tenants / switcher / `X-Tenant-Id` contract | pack + SPA | SP3b screens | unchanged |

Any SP3a change to extension or contracts packages re-opens the release chain
(framework → contracts → extension → Thallo pins); Thallo-only composition is preferred
where the boundary allows.

## §6 Acceptance journey (the SP3 definition of done)

An operator with no CLI access, in the admin SPA alone: enables tenancy from Settings →
Tenancy (watching real progress through the fresh-boot boundaries, guided when a re-run is
needed) → activates full resolution → creates tenant two (lands active with starter content,
per SP2b) → adds + TXT-verifies a custom domain following on-screen instructions → invites a
member as `admin` and one as `viewer`, whose in-tenant capabilities match the SP3a matrix
exactly (the viewer cannot author; the admin cannot demote the final owner; neither sees
another tenant) → suspends and reactivates a tenant → runs diagnostics and reads a green
report → disables tenancy (single-tenant install) through the gate refusals and back. Every
step authorized by the ratified model; every refusal is the server's message rendered
faithfully.

## §7 Implementation and release order

1. **SP3a** spec → plan → build. If it stays Thallo-only (preferred), no release chain; any
   extension/contracts touch re-opens framework→contracts→extension→pins ordering.
2. **SP3b** spec may be drafted once SP3a's capability matrix is ratified in its spec;
   build starts after SP3a lands (the UI renders the matrix).
3. Commits held per slice until explicit go-ahead (standing rule), batched at logical
   groupings.
