# SP2 — Multi-Tenant Operation (Index)

> Umbrella document for the SP2 sub-projects. Detailed requirements live in the per-slice
> specs; this index holds the objective, the dependency graph, the shared invariants, and
> the cross-project contract ledger so slices can cite stable references
> (e.g. "SP2 index §3 invariant 4") without one unreviewable mega-spec.

## §1 Objective and boundaries

**Objective:** take Thallo from SP1's single-tenant bootstrap runtime (tenancy ON, exactly one
default tenant, every request implicitly scoped to it) to **true multi-tenant operation**:
request-time tenant resolution on every tenant-data surface, admission of tenant two and
beyond, per-tenant starter content, and the operational envelope (disable path, diagnostics).

**In scope (SP2 overall):** full request resolution (admin switcher + public delivery),
tenant lifecycle (create/suspend), the domain surface in the schema, blobs/media resolution +
URL-cache segmentation, seed-on-create + additive sync-on-upgrade with `starter_provenance`,
the `disabled_widened` disable path, and `thallo:tenancy:diagnose`.

**Out of scope (SP2):** schema-per-tenant or database-per-tenant isolation; collections
tenancy (remains fenced 503 while enabled); tenant-tier billing/quotas; MySQL/SQLite
(Thallo is PostgreSQL-only); custom-domain TLS automation (certificate issuance is a
deployment concern, not application logic).

## §2 Dependency graph

```
SP2a  Resolution + tenant management     (prerequisite for everything below)
  └─> SP2b  Seed/sync + starter_provenance   (tenant two is empty without it;
        └─> SP2c  Disable path + diagnostics   disable's gates read provenance)
```

- SP2a → SP2b: seed-on-create hooks the tenant-creation flow SP2a builds.
- SP2b → SP2c: the disable path's "no customized/orphaned rows" gate reads
  `starter_provenance`; diagnose asserts provenance integrity.
- SP2c is spec-complete only after SP2b's provenance shape is final.

## §3 Shared decisions and invariants

1. **Fail closed, always.** An enabled runtime that cannot resolve a tenant DENIES
   (503/404/throw) — never falls back to the default tenant outside
   `bootstrap_default` mode. (SP1 precedent: BootstrapDefaultTenantMiddleware 503,
   TenantBlobPolicy null-resolution, MissingTenantForCacheException.)
2. **Contract-only cross-package rule.** Thallo app/pack code never imports concrete
   `Glueful\Extensions\Tenancy\*` classes; everything crosses via
   `Glueful\Extensions\Contracts\Tenancy\*` or pack-local `Thallo\Contracts\*`.
   Implementers bind shared contract IDs; consumers soft-resolve. Querying an
   implementation-owned table such as `tenant_domains` is also a contract violation; neutral
   lookup methods carry those reads.
3. **Mode has no independently stored enum.** `TenantRuntimeReadiness::mode()` computes
   `none | bootstrap_default | full_resolution` from enablement state plus the live
   `FullTenantResolutionReadiness` binding. That readiness implementation reads SP2a's persisted
   activation flag AND required-host health; the stored flag alone is never authoritative.
4. **The bootstrap middleware defers to real resolution.** When a resolver has already
   populated the tenant, `tenant_bootstrap` passes through untouched; in
   `full_resolution` mode with no resolved tenant it 503s. SP2a builds on this
   existing seam — it does not modify SP1's state machine.
5. **One owner per blob; ownership-first authorization.** `media_assets` keeps the
   global `blob_uuid` unique; an ownerless blob is denied before any public/signed
   shortcut. SP2a's blob-resolution work changes WHERE the tenant comes from, never
   these invariants.
6. **Cache keys segment as `tenant:{uuid}:…`** via `TenantCacheSegment` — fail-closed
   when enabled-but-unresolved; purges must cover legacy AND segmented shapes.
7. **Every Thallo route carries exactly one tenancy marker**
   (`tenant_bootstrap` | `tenant_system` | `collections_disabled_when_tenant`),
   enforced by RouteCoverageTest. SP2a may ADD a marker kind (full-resolution
   tenant middleware) but the exactly-one rule stands.
8. **Tenant creation flows through the guard.** Every creation seam calls
   `BootstrapTenantCreationGuard::assertCanCreateTenant()` — allowed ONLY in
   `full_resolution`; both `bootstrap_default` and `none` fail closed.
9. **PostgreSQL-only.** Advisory locks, `ON CONFLICT … RETURNING`, PG DDL are assumed.
10. **No attribution** in commits/PRs/releases; release order is framework → contracts →
    extension → app (pin only published versions).
11. **Tenant lifecycle: `provisioning → active ⇄ suspended`.** A tenant created after SP1's
    default lands in `provisioning` and NEVER resolves publicly nor appears in `my-tenants`
    until seed success calls `TenantAdministration::markActive()` (SP2b). Suspension flips
    tenant status only — domain rows/statuses are preserved and reactivation restores them;
    hosts are never freed by suspension (takeover protection). Hard deletion is out of SP2.
12. **Domain state is two independent columns** — `verification_status` (DNS fact) and
    `status` (operator choice). Public resolution requires verified + active + tenant-active
    + tenant operationally active.
13. **One full-resolution predicate.** Profile middleware and `TenantRuntimeReadiness` consume
    the same shared `FullTenantResolutionReadiness`; required default-host mappings cannot be
    disabled/removed while it is active. No component gates independently on the raw flag.
14. **Tenant administration preserves control.** Ordinary users list only their ACTIVE
    memberships; bypass operators can select every ACTIVE tenant; the final ACTIVE owner cannot
    be removed or demoted, including under concurrent mutations.
15. **Activation completion is atomic.** The persisted full-resolution flag and activation step
    FULL commit in one DB transaction; neither may become visible without the other.

## §4 Sub-projects

| Slice | Spec | Plan | Status |
|-------|------|------|--------|
| SP2a — Resolution + tenant management | `2026-07-10-sp2a-resolution-tenant-management-design.md` | `../plans/multi-tenancy/2026-07-10-sp2a-resolution-tenant-management.md` | implemented (held; release gate T17 deferred) |
| SP2b — Seed/sync + starter_provenance | `2026-07-10-sp2b-tenant-seed-sync-design.md` | `../plans/multi-tenancy/2026-07-10-sp2b-tenant-seed-sync.md` | implemented (held) |
| SP2c — Disable path + diagnostics | `2026-07-10-sp2c-disable-diagnostics-design.md` | `../plans/multi-tenancy/2026-07-10-sp2c-disable-diagnostics.md` | implemented (held) |

Cross-slice coupling points: SP2a's pack-owned HTTP/CLI creation surfaces call SP2b through the
Thallo-local `TenantSeedActivator` seam; SP2b's app-owned seeder then calls
`TenantAdministration::markActive()` (the provisioning→active boundary SP2a defines).
Tenant-creation UI ships only after SP2b (an unseeded tenant must not be creatable from the UI
as an operational flow).

## §5 Cross-project contract ledger

Contracts each slice binds/consumes (details in slice specs; changes here require
re-checking every consumer):

| Contract | Bound by | Consumed by | SP2 change |
|----------|----------|-------------|------------|
| `CurrentTenantResolver` | tenancy ext bridge | packs, TenantBlobPolicy, caches | unchanged |
| `FullTenantResolutionReadiness` | **SP2a (new binding, Thallo)** | TenancyRuntimeReadiness composite + resolver-profile middleware | shared predicate: activation flag + required-host health |
| `TenantRuntimeReadiness` | thallo-tenancy pack | middleware, blob policy, guards | unchanged (mode flips by §3.3) |
| `TenantProvisioner` | tenancy ext bridge | SP1 retrofit ONLY | unchanged — stays retrofit-specific (verified it cannot express management) |
| `TenantAdministration` (**new, contracts 1.2.0**) | tenancy ext bridge (SP2a) | Thallo controllers/CLI/blob-origin provider; SP2b seeder (`markActive`) | create→provisioning, get/list reads, suspend/reactivate, markActive, owner-safe membership operations |
| `TenantDomainAdministration` (**new, contracts 1.2.0**) | tenancy ext bridge (SP2a) | Thallo controllers/CLI/readiness/blob-origin/purge, activation flow | domain get/list/CRUD + DNS TXT verify + idempotent preverified apex mapping |
| `TenantResolutionProbe` (**new, contracts 1.2.0**) | tenancy ext bridge (SP2a) | FullResolutionActivation | fresh-process direct public-profile probe that bypasses only deployment activation |
| `BlobRouteMiddlewareProvider` (**new, framework**) | Thallo (SP2a) | framework blob route registration | generic action→middleware seam; VIEW runs after `auth:optional`, with no strict-auth gate before signature validation |
| `BlobPublicUrlProvider` (**new, framework**) | Thallo (SP2a) | framework signed/public URL composition | canonical custom/default/subdomain origin; no request-host fallback while full |
| `TenantContextRunner` | tenancy ext bridge | seed/sync (SP2b), workers | SP2b's forEachTenant workhorse |
| `TenantProvisioningRunner` (**new, contracts**) | tenancy ext bridge (SP2b) | TenantSeeder only | scopes provisioning/active seed work without weakening active-only jobs |
| `TenantEnforcementProbe` | tenancy ext bridge | FinalizationProbe, diagnose (SP2c) | unchanged |
| `BlobCreatedHook`/`BlobAccessPolicy` (framework) | Thallo TenantBlobPolicy | framework UploadController | SP2a: resolution source widens |
| `WriteBarrier` (pack contract) | RetrofitMaintenanceGuard | raw writers | unchanged |
| `SystemKeyReconciler` (pack contract) | Thallo app | thallo-tenancy pack | unchanged |

## §6 Deferred / out of scope (beyond SP2)

- Tenant-tier quotas, billing, plan enforcement.
- Custom-domain TLS issuance/automation (deployment).
- Collections tenancy (fence stays).
- Cross-tenant content sharing/syndication.
- Tenant export/import + destructive `off` rollback (noted in SP1 spec §9 as a
  separate command; revisit after SP2c).

## §7 Implementation and release order

1. **SP2a** spec → plan → build. Framework/extension/contract changes (if any) release
   FIRST (framework → contracts → extension), then Thallo pins.
2. **SP2b** spec may be drafted during SP2a implementation; build starts after SP2a's
   tenant-create seam exists.
3. **SP2c** spec after SP2b's provenance shape freezes; build last.
4. Each slice ends with its own release gate (published pins, no path repos) mirroring
   SP1 Task 21.
