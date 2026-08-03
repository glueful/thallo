# thallo-subscriptions — Workspace SaaS Billing Design (Phase 2)

**Status:** approved design, pre-implementation.
**Scope:** the Thallo capability module for workspace (tenant) billing against
glueful/subscriptions 2.0's preserved tenant facade. Phase 3 (memberships,
paywalls, workspace-owned member catalogs, any checkout) is explicitly out.
**Companion:** a small additive glueful/subscriptions **2.1.0** release (§6) —
2.0.0 is published, so the three new upstream seams and one spec-wording
amendment ride a minor, not an amendment.

## 1. Package & layering

New `packages/thallo-subscriptions` (`type: library`, PSR-4
`Thallo\Subscriptions\`, provider
`Thallo\Subscriptions\SubscriptionsIntegrationServiceProvider`), wired like
commerce:

- Path repository + `"glueful/thallo-subscriptions": "*"` in root
  composer.json; root also gains `"glueful/subscriptions": "^2.1"`.
- Provider listed in `config/serviceproviders.php`; post-extension tier
  (`loadPriority()`), `loadAfter([Glueful\Extensions\Subscriptions\
  SubscriptionsServiceProvider::class])`.
- Capability `thallo.subscriptions` (label "Subscriptions") registered in
  `boot()`.
- **Bundled engine is enabled by default (consistency rule):** capabilities
  default ON in `DefaultCapabilityRegistry` (absent key ⇒ enabled), so
  `Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider` is added to
  the committed `config/extensions.php` `enabled` list. Without this, a fresh
  install would show an enabled Subscriptions module backed by a disabled
  engine. The three-layer separation stands: composer = availability;
  extensions.php = engine on/off; `thallo.subscriptions` = Thallo surfaces
  on/off. Disabling the capability hides the Thallo surfaces without deleting
  data. Disabling only the engine deliberately leaves the capability-gated
  admin shell visible in its `engine_disabled` state so an operator can reach
  the Extensions-page recovery action; every engine-backed operation is
  unavailable.

## 2. The host subject resolver (ruling — tenant-only in Phase 2)

`Thallo\Subscriptions\ThalloSubjectResolver` binds over
`SubjectResolverInterface`, replacing the compatibility default with the
strict host authority the 2.0 spec assigns to hosts:

- `currentTenant()` — the real workspace via tenancy context when enforcement
  is on; otherwise `SingleStoreTenant::resolve()` (the persisted
  default-workspace UUID). Never a sentinel.
- `validate()` — tenant subjects require `subject_uuid === tenant_uuid` AND
  existence proven via `TenantAdministration::getTenant()` (the existence
  authority); host sentinels rejected. **User subjects always return false.**
- `currentUser()` — returns null.

Binding this resolver does NOT enable memberships. Memberships are enabled
only when a host resolver positively resolves and validates user subjects —
Phase 3's change, not a side effect of Phase 2. (§6 amends the upstream
spec's "binding the resolver is enabling memberships" wording to match.)

Single-store consequence: the "This site's plan" panel is a **real
default-workspace projection, not a synthetic row** — the identity already
exists (`tenancy.default_tenant_uuid`) and survives tenancy enablement
unchanged; only the presentation widens from one panel to a directory.

## 3. Admin API — platform authority throughout

All routes live under `/v1/admin/subscriptions/*`, and **every endpoint uses
`['auth', 'tenant_system', 'content_permission:tenancy.manage']`** — the same
posture as Thallo's tenant-management surface. Platform plan CRUD and
cross-workspace billing are platform-operator authority; a tenant-grantable
`subscriptions.manage` permission is explicitly rejected (it would let one
workspace admin edit global plans or another workspace's subscription).
`tenancy.manage` already belongs to workspace_manager and superuser.

Endpoints (thin controllers over the engine's facades; no new business
logic):

- **Plans** — list / create / update / archive / import-config via
  `PlanManagementService`'s platform scope (plan_key immutability and scope
  invariants enforced upstream).
- **Workspaces index** — the tenant directory joined with subscription state
  via the NEW bulk read `currentForTenants()` (§6), returning status /
  plan (key + display name) / trial / grace per workspace, keyed by tenant
  UUID. No N+1: one directory page ⇒ one subscription query. The UUID list is
  derived exclusively from `TenantAdministration::listTenants()` after the
  route's platform-authority gate; the endpoint accepts no caller-supplied
  UUID filter. This is the trusted-host-inventory precondition of the upstream
  administrative batch seam, not a bypass available to ordinary
  subject-scoped callers.
- **Workspace detail / actions** — `current()` detail; set-plan
  (`start`/`changePlan`); cancel; overrides via the 2.0 writer
  (`upsertForSubject` / `deleteForSubject`) — never raw table writes.
- **Provider-managed guard (cancel/change honesty):**
  `SubscriptionService::cancelFor()` changes LOCAL state only — it does not
  call Stripe/Paystack. Therefore: manually managed subscriptions
  (no `provider_subscription_id`) allow cancel/set-plan freely;
  **provider-linked subscriptions refuse local cancel/change with a
  structured 409 `provider_managed_subscription`**, telling the operator to
  cancel/change at the provider (webhooks then project the result through
  the strict lane). The SPA renders the guidance, not a dead button.
- **Meta** — always 200; reports `engine: engine_disabled | schema_not_ready
  | ready`, plus tenancy on/off and the default-workspace UUID when off. The
  SPA drives its empty/degraded states from this.

## 4. Admin SPA

`admin/src/registry/subscriptionsModule.ts` (`requires:
['thallo.subscriptions']`, nav "Subscriptions"), pages under
`admin/src/pages/subscriptions/` with `requiresCapability` guards, following
the commerce module's query/page conventions:

- **Plans** — catalog table (key, name, status, audience-locked to platform),
  editor with entitlements as key/value rows, archive, import-config action.
- **Billing** — tenancy ON: workspace directory (status chips, plan, trial/
  grace) with a per-workspace drawer (detail, set-plan, cancel where
  permitted, overrides list + editor). Tenancy OFF: the "This site's plan"
  panel — the same drawer bound to the default workspace.
- Degraded states: `engine_disabled` renders a call-to-action linking to the
  Extensions page; `schema_not_ready` says "run migrations"; engine-dependent
  actions surface the structured 409s verbatim.

## 5. Lifecycle, degradation, purge

**Lazy engine gateway (degraded mode all the way through).** Controllers do
NOT constructor-inject engine services — that would fatal before the intended
409 when the provider is disabled. A pack-owned
`Thallo\Subscriptions\Engine\EngineGateway` probes the container **inside
each operation**: `engineState()` returns `engine_disabled` (provider absent
⇒ `SubscriptionService` unbound), `schema_not_ready` (services bound but the
upstream `SubscriptionSchemaReadiness` authority reports that the complete
minimum 2.x runtime shape is absent), or `ready`; accessors return the engine
services or throw a typed exception the controllers map to the structured
409. A lone legacy `subscriptions` table, or a partially-applied subject-model
migration, is never reported ready. Meta stays 200 in every state.

**Purge integration (concrete, fail-closed — commerce's actual mechanism):**

- The pack exposes container alias `thallo.subscriptions.purge_handler`
  OUTSIDE the capability gate. The alias and adapter are ALWAYS registered;
  the adapter factory soft-resolves a nullable
  `SubscriptionSubjectDataPurger` with `container->has()`. The composer class
  being autoloadable is not evidence that the upstream provider bound its
  service.
- `TenancyServiceProvider::makePurgeResourceRegistry()` adds that alias to
  its handler list (one-line change in `packages/thallo-tenancy`).
- **Fail-closed rule:** if the subscriptions SCHEMA exists but the purger is
  unavailable (engine disabled after data was written), prepare, purge, and
  verify must THROW — a tenant purge must never silently skip billing data.
  If the schema does not exist, the handler reports zero rows and passes.
- Resumable prepare/verify uses the NEW non-mutating
  `countSubjectRows()` seam (§6) so Thallo never duplicates the extension's
  table inventory.

Outside the capability gate: the always-present purge adapter and capability
registration. The module discovers no commands; upstream subscription commands
remain owned by `SubscriptionsServiceProvider`. Inside the gate: routes and
the admin module contribution. No blocks, no public routes, no storefront
surfaces in Phase 2 (payments ruling: manual assignment only; provider-driven
billing continues via payvia metadata + the strict lane; Phase 2 never
originates purchases).

## 6. Upstream companion — glueful/subscriptions 2.1.0 (additive minor)

1. **Bulk subscription read:** `SubscriptionService::currentForTenants(array
   $tenantUuids): array` — bounded list in, results keyed by tenant UUID
   (absent key = no subscription), tenant subjects only, one query
   (`WHERE subject_type='tenant' AND tenant_uuid IN (...)`). Test pins a
   CONSTANT query count across page sizes (a recording connection/query
   counter), plus empty-list and mixed-hit/miss cases. This is explicitly a
   trusted administrative projection seam: unlike `currentFor()`, it does not
   call `SubjectResolverInterface::validate()` once per UUID, because that
   would recreate the N+1 through host existence checks. Its contract requires
   a normalized, deduplicated list obtained from the host's authoritative
   tenant directory after platform authorization; it is not mounted directly
   as an HTTP batch-by-UUID endpoint. Thallo proves the precondition by deriving
   the list from `TenantAdministration::listTenants()` and accepting no UUID
   input on the workspace-index route.
2. **Purge count seam:** `SubscriptionSubjectDataPurger::countSubjectRows(
   Subject $subject): array` — non-mutating, returns per-table counts using
   the SAME matching predicates as `purgeSubject()` (resolved-or-candidate
   for receipts), enabling host prepare/verify without duplicating table
   inventory. Test: counts match what purge then deletes; zero after purge;
   idempotent.
3. **Schema-readiness authority:** a bound
   `SubscriptionSchemaReadiness::isReady(): bool` owned by the extension. It
   checks the complete minimum 2.x runtime shape used by the services — base
   tables, subject/plan identity columns, scoped-plan columns, and the provider
   receipt table — rather than treating `subscriptions` table existence as
   readiness. Tests cover a fresh 2.x schema, no schema, a legacy 1.x schema,
   and representative partially-applied migration shapes. Hosts consume this
   authority instead of duplicating the extension's schema inventory.
4. **Spec wording amendment** (design spec §4, the "binding the resolver is
   enabling memberships" sentence): memberships are enabled only when the
   host resolver positively resolves AND validates user subjects; a
   tenant-only host resolver (Thallo Phase 2) binds without enabling them.

Released and published as 2.1.0 before the Thallo work that consumes the
seams lands; Thallo's root constraint moves to `^2.1`.

## 7. Testing

- **Upstream (2.1.0):** as pinned in §6.
- **Thallo PHP:** resolver (tenancy on/off, default-workspace resolution,
  sentinel rejection, nonexistent-tenant rejection, user subjects false,
  currentUser null); every admin endpoint against a real engine harness
  (seeded plans/subscriptions; the workspace index against a multi-tenant
  fixture asserting bulk-read wiring, no caller-supplied UUID input, and a
  constant total query count); the provider-managed 409 on a linked
  subscription and success on a manual one; permission posture (a
  non-tenancy.manage actor gets 403 on every route); EngineGateway state
  matrix (disabled / legacy schema / partial schema / ready) with the provider
  absent from the disabled harness container; purge adapter — alias remains
  registered with the engine disabled, fail-closed throw when schema exists
  without purger, zero-pass when schema is absent, counts-then-purge parity via
  the new seam. A capability/engine truth table pins capability-off as hidden,
  engine-off as the visible degraded shell, and both enabled as operational.
- **Admin SPA (vitest):** module/nav gating on the capability; Plans and
  Billing page states (tenancy on/off, all three engine states); drawer
  actions incl. the provider-managed refusal rendering. Existing
  capability/nav parity gates pick the module up automatically.

## 8. Out of scope (deliberately)

- Memberships, paywalls, workspace-owned member catalogs, any user-subject
  surface (Phase 3).
- Workspace self-serve checkout / provider session origination (dedicated
  follow-up product decision).
- Per-tenant plan catalogs; provider-side cancellation orchestration.
- A tenant-grantable subscriptions permission (rejected by design — §3).
