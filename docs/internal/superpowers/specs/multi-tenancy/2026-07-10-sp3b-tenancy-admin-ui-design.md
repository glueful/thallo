# SP3b — Enablement + Tenant-Management Admin UI (Design)

> Slice 2 of SP3 (`SP3-README.md`, cited "SP3 index §n"). Renders the SP3a authorization model
> (`2026-07-10-sp3a-membership-rbac-design.md`, cited "SP3a §n") — it invents no policy (index
> §3.6). Thallo-only: the local `packages/thallo-tenancy` pack + the `admin/` SPA + app
> controllers. No framework / contracts / extension change, so **no release chain**. Build
> starts after SP3a lands (index §7); the spec and plan proceed now.

## §1 Objective and boundaries

Make multi-tenant Thallo fully operable from the admin SPA — the index §6 acceptance journey:
an operator with **no CLI access** enables tenancy, activates full resolution, creates and
manages tenants, verifies custom domains, invites members whose in-tenant powers match the
SP3a matrix, runs diagnostics, and disables tenancy — every action a 1:1 call to a shipped (or
SP3b-added, §3) HTTP surface, every refusal the server's own message.

**In scope:** Settings → Tenancy lifecycle page; Tenants management area (list/create/suspend/
reactivate, domains with DNS-TXT UX, memberships with the SP3a role picker); the
`X-Tenant-Operator-Mode` toggle (SP3a §4); four new Thallo-local HTTP surfaces (§3); the
per-caller access probe (§4); nav gating (§5); action-driven progress + fresh-boot rendering
(§6).

**Out of scope:** everything in index §1 "out of scope" (billing/quotas, tenant deletion,
collections tenancy, TLS automation, public signup); any per-tenant role/matrix editing (SP3a
§12); changing SP3a's enforcement rule. SP3b is primarily a **consumer** of SP3a, with bounded
Thallo-local additions: the non-auditing evaluator seam (§4), the four read/action endpoints
(§3), and the enablement migration-retry correction (§6).

## §2 Information architecture

Two placements, mirroring the as-built patterns (`registry/adminModules.ts`,
`coreModule.ts` Settings-child node):

- **Settings → Tenancy** — a Settings child (`pages/settings/tenancy/index.vue`, file-routed):
  the **operator lifecycle** surface. Status pair (enablement + resolution), explicit
  state-advance actions with in-flight progress,
  begin → confirm-first-tenant, retry/cancel/finalize, resolution activate/deactivate, disable
  with gate refusals, diagnose report. Visible when the `thallo.tenancy` feature capability is
  installed **and** `access.manage_platform` (§5). Must render while tenancy is **off** (that
  is where you turn it on), so it never gates on `status.enabled`.

- **Tenants** — a new top-level module (`registry/tenancyModule.ts`, `requires:
  ['thallo.tenancy']`) registered in `layouts/default.vue` alongside the others. Its nav and
  screens are **role-shaped** (§5): an operator sees All-Tenants + lifecycle affordances; a
  tenant **owner** (no `manage_platform`) still reaches their resolved tenant's Domains/Members.

```
Sidebar
├─ … existing modules
├─ Tenants                         ← requires thallo.tenancy AND (manage_platform
│   │                                 OR manage_members OR manage_domains)
│   ├─ All tenants                 ← manage_platform only (list/create/suspend/reactivate)
│   └─ This tenant ▸ Domains       ← manage_domains  (add + TXT-verify)
│                  ▸ Members        ← manage_members  (SP3a role picker)
└─ Settings
    ├─ General …
    └─ Tenancy                     ← thallo.tenancy AND manage_platform (operator lifecycle)
```

## §3 New backend surfaces (Thallo-local pack/app — no release chain)

All mirror the existing `packages/thallo-tenancy` controllers/envelope
(`Glueful\Http\Response` → `{success,message,data}`).

1. **`GET /v1/admin/tenancy/diagnose`** — group `tenant_system` +
   `content_permission:tenancy.manage`. Wraps `TenancyDiagnostics::report(): array{sections:
   array<string,{status,detail}>, ok:bool}` (no args). Envelope `{data:{report}}`. New
   controller method on a diagnostics controller in the pack; DI already registers
   `TenancyDiagnostics` (`TenancyServiceProvider.php:160`).

2. **`POST /v1/admin/tenancy/resolution/activate`** — sits beside the existing deactivate route
   (`routes/enablement.php:28`), identical `tenant_system` + `content_permission:tenancy.manage`
   middleware. Mirrors `TenancyResolutionController::deactivate` (`:23`): calls
   `FullResolutionActivation::advance()` by default; when the request body carries
   `{"retry": true}` (the SPA sends it only from a `failed` state) it calls `retry()` instead —
   which throws `EnablementException` when the step isn't retryable (→ 422). Returns
   `{data:{resolution}}`. Error mapping: `EnablementLockedException`→409,
   `EnablementException`→422, **and `resolution.step === 'failed'` → 422 explicitly**, because
   `advance()` swallows a failed step into `step:'failed'` rather than throwing
   (`FullResolutionActivation.php:66-70`) — without this the UI would 200 on a failed
   activation, unlike the CLI (`ResolutionActivateCommand.php:23`). `assertCanActivate()` already
   throws `EnablementException` when SP1 enablement isn't `on` (→ 422).

3. **`GET /v1/admin/tenancy/access`** — the per-caller permission probe (§4).

4. **`POST /v1/admin/tenancy/tenants/{uuid}/seed`** — operator-only repair for a tenant left in
   `provisioning` after starter seeding failed. It sits in the existing tenant-management group
   (`tenant_system` + `content_permission:tenancy.manage`) and calls the already-shipped narrow
   `TenantSeedRepair::repair(string $tenantUuid)` seam — it does not widen
   `TenantSeedActivator`. Success returns `{data:{tenant:{uuid,status:'active'}}}`. A
   `StarterSeedException` returns 422 with the failing definition; an unavailable repair binding
   returns 503. The repair is idempotent and preserves the shipped `TenantSeedRepair` contract:
   provisioning and active tenants are eligible; suspended tenants fail closed. The SPA offers
   it for the provisioning-failure recovery path. The CLI command remains operational guidance,
   not the admin UI's only recovery path.

These four keep the UI honest to index §3.6: it drives services, never re-derives policy.

## §4 The access probe (per-caller permission surface)

**Three inputs stay separate** (per the design decision): the capability registry answers *is
tenancy installed?*; this probe answers *what may this caller do?*; the enablement/resolution
status answers *what lifecycle state?*. The SPA never re-computes policy in Vue — it reads
explicit booleans and the server remains the sole authority (every action is still
server-authorized regardless of what the probe returned).

**`GET /v1/admin/tenancy/access`** — `auth` + soft admin resolution + optional bootstrap
(`tenant_profile:admin,soft` → `tenant_bootstrap:optional`) but **no `content_permission` gate**
(a non-operator must be able to learn it holds nothing). The composition is mode-correct:
tenancy off reaches the global checks; bootstrap-default supplies the default tenant; full mode
uses `X-Tenant-Id` when valid but still reaches the controller without a selector, returning
scoped booleans false rather than a resolver 404/503. Normal tenant-data routes remain required.
Response:

```json
{ "data": { "access": {
  "manage_platform": true,   "access_any": true,
  "manage_members": false,   "manage_domains": false
} } }
```

- `manage_platform` = `PermissionManager::can(user, 'tenancy.manage', 'thallo', ctx)` — global.
- `access_any` = `PermissionManager::can(user, 'tenancy.access_any', 'thallo', ctx)` — global.
- `manage_members` / `manage_domains` = SP3a's **effective** decision (membershipMatrix OR
  explicit operator bypass) for `tenant.members.manage` / `tenant.domains.manage` against the
  **resolved** tenant, honoring the request's operator headers (`X-Tenant-Id`,
  `X-Tenant-Operator-Mode`). With no resolved tenant, both are `false`.

**The probe must not emit audit events (pinned).** SP3a's `OperatorBypass::evaluate` records a
best-effort `tenancy.operator_bypass` audit entry **on grant** (SP3a plan T3 step 4). This
endpoint is refreshed frequently (on load, tenant switch, operator-mode toggle) — auditing it
would log authorization *probes* as operator *actions*. Therefore SP3b extracts a **pure,
non-auditing evaluation path** from `OperatorBypass`: a `decide(...)` that returns the
`BypassDecision` with no recorder call, which `evaluate(...)` wraps with the audit attempt. The
access probe calls `decide(...)`; **only** `RequirePermission`'s enforcement path calls the
auditing `evaluate(...)`. This is the sole SP3b touch to SP3a-owned code (still Thallo-only).

The probe reuses SP3a's `RoleMatrix`, `TenantMembershipRoleReader`, and the extracted
`decide(...)` — it re-implements none of the rule. It lives in an app controller (SP3a's
authorization components are app-owned under `app/Content/Authorization/`). Principal extraction
(`auth.user` and lean-install `user` array), Aegis context construction, and PermissionManager
location are behavior-preservingly extracted from `RequirePermission` into shared helpers; the
probe never substitutes empty roles/scopes/JWT context.

## §5 Nav gating (feature × permission × status)

`visibleNav(isEnabled)` filters modules by `requires` against the capabilities store; SP3b adds
a second, orthogonal input — the access booleans — evaluated in the module's `nav` computation,
not baked into `requires` (which only knows feature capabilities). Owners must not lose
navigation, so gating is role-shaped:

- **Tenants module presence:** `thallo.tenancy` feature **AND** (`manage_platform` OR
  `manage_members` OR `manage_domains`). An owner with neither platform power still sees it for
  their tenant's Domains/Members.
- **All Tenants** child (list/create/suspend/reactivate): `manage_platform` only.
- **This-tenant Domains** child + its mutating affordances: `manage_domains`.
- **This-tenant Members** child + its mutating affordances: `manage_members`.
- **Settings → Tenancy** lifecycle child: `thallo.tenancy` feature **AND** `manage_platform`.
- **Status controls presentation only** — it never grants or hides authority; e.g. the Tenants
  module may additionally suppress management screens until `status.enabled`, but that is a
  presentation choice layered *on top of* the permission gate, never a substitute for it.

Nav gating is cosmetic; §3/§4 server checks are the boundary. A caller who reaches a screen it
cannot act on sees the server's 403 rendered faithfully (§7).

## §6 Lifecycle progression and fresh-boot rendering

The state machines are **action-driven, not background jobs**. `TenancyEnablement::begin()` and
`FullResolutionActivation::advance()` each move at most one stage per request; their `status()`
methods only read and can never advance a step. Therefore the UI must never rely on polling to
cross a state. Every non-terminal state renders the server-prescribed explicit action (`Begin`,
`Continue`, `Confirm`, `Finalize`, `Retry`, `Disable`, or `Continue activation`). While an action
request is in flight, its returned status/progress is displayed; an optional slow-request status
poll may observe progress, but it stops when that request settles and is never the advancement
mechanism.

- **Enablement actions:** `off|installing|awaiting_install|enabling_extension` → `begin`;
  `awaiting_provider_boot` → a fresh request followed by `begin`; `migrating_extension` →
  `begin`; `awaiting_confirm` → `confirm`; `retrofitting` → `confirm` using persisted
  `pending_slug`/`pending_name` (fall back to the form if either is absent);
  `reloading|finalizing` → a fresh request followed by `finalize`; `failed` → `retry`, then
  render the action for the restored step; `on` → `disable`; `disabling` → `disable` (resume);
  settled `disabled_widened` → `begin` (re-enable). Terminal/stable states render no automatic
  action loop.
- **Resolution actions:** `inactive|mapping_hosts|verifying_wiring|rebuilding_routes` →
  `activate` (one explicit stage per click/request); `awaiting_fresh_boot` → a fresh request
  followed by `activate`; `failed` → `activate` with `{retry:true}`, then render the restored
  step; `full` → `deactivate` where its one-tenant gate permits it.

**Migration-retry prerequisite (SP3b correction).** As built, a failed extension migration is
recorded with `failedFrom=migrating_extension`; `retry()` restores that step, but
`TenancyEnablement::begin()` has no `migrating_extension` branch. SP3b adds the idempotent branch
that re-runs `activation->migrate()`, records another failure exactly as the existing
`awaiting_provider_boot` branch does, and advances to `awaiting_confirm` on success. A regression
test drives failed migration → retry → begin → awaiting_confirm. This is a local resumability fix,
not new authorization policy.

**Fresh-boot boundaries are explicit UI states, never hidden retries** (index §3.6). `reloading`
(enablement) and `awaiting_fresh_boot` (resolution `fresh_boot_required:true`) render a
**Reload and continue** panel. On Thallo's supported shared-nothing PHP runtime, a new browser
request is the required fresh boot, so the button directly issues `finalize`/`activate` — it does
not call `window.location.reload()` and cannot form a reload loop. The operator does not need CLI
access. Deployments using a
long-lived worker must restart/reload that worker through their hosting control plane, and the UI
labels that as an operational prerequisite rather than pretending a status poll can do it.
`awaiting_confirm` surfaces the first-tenant confirm form;
`awaiting_install`/`awaiting_provider_boot` surface their guided next step; `failed` surfaces the
server failure text + retry.

## §7 Frontend structure

- **Stores (setup-style Pinia):**
  - `stores/tenancyAccess.ts` — the four booleans; `ensureLoaded`/`refresh`; **reset then refresh
    on tenant switch and operator-mode change**, and reset on auth `reset()`; fails closed (all
    `false`) on error. Refreshes carry an `AbortController` or monotonically increasing request
    generation, so a response started for tenant A can never overwrite tenant B's access state.
  - `stores/tenant.ts` (extend) — add an in-memory `operatorMode: boolean` flag (default
    `false`). **Never persisted** with `selectedUuid`. Reset it whenever the tenant changes,
    selection clears, `reset()` (auth logout) runs, or a `tenant-switch-required` event fires.
  - `stores/capabilities.ts` — unchanged; supplies the `thallo.tenancy` feature flag.
- **Access lifecycle:** one layout-owned composable awaits the initial tenant load/selection before
  the first access probe, then watches `selectedUuid` and `operatorMode` and reset+refreshes access
  on change. Login/logout resets access alongside tenant/capabilities. The
  `tenant-switch-required` handler refreshes after re-selection and controls the actual
  `USelectMenu` open state — opening the sidebar alone is not considered a prompt.
- **Queries (@pinia/colada, via `authFetch` — tenancy isn't in the OpenAPI schema):**
  `tenancyEnablement.ts` (status/begin/confirm/retry/cancel/finalize/disable),
  `tenancyResolution.ts` (status/activate/deactivate), `tenancyDiagnose.ts` (report),
  `tenancyAccess.ts` (access), extend `tenants.ts` (list-all/create/suspend/reactivate), new
  `tenantDomains.ts`, `tenantMembers.ts`, and the provisioning repair mutation. Mutations
  `invalidate` their sibling status/list queries in `onSettled`.
- **Module + pages:** `registry/tenancyModule.ts` (registered in `layouts/default.vue`);
  `pages/settings/tenancy/index.vue` (lifecycle); `pages/tenants/index.vue` (All Tenants);
  `pages/tenants/[uuid]/domains.vue`, `pages/tenants/[uuid]/members.vue` (or a tabbed
  `[uuid]/index.vue`). Settings child added to the `coreModule.ts` Settings node.
- **Components:** enablement status/progress panel; resolution status/activate panel;
  first-tenant confirm form (`UFormField`+`UInput`, **no `UAuthForm`**, slug-regex hint mirroring
  the server's `[a-z0-9][a-z0-9-]*`); disable panel (renders gate refusals); diagnose report view
  (`sections` list with per-section `status` badge, `ok` summary); tenant create modal; domain
  add + TXT-record instruction + verify button (shows **Name** from the server's `txt_record`
  and **Value** from its returned `token`); member add with
  the **SP3a role picker** (`owner|admin|member|viewer` — the ratified vocabulary) + role change +
  remove; the **operator-mode toggle** ("operate as platform admin", SP3a §4).
- **Header injection — the two existing choke points only:** `X-Tenant-Operator-Mode: 1` rides
  `api/authFetch.ts` and `api/client.ts` middleware, emitted when `tenant.operatorMode` is set.
  `X-Tenant-Id` path unchanged. No third injection site.

**Route target = selected tenant (pinned).** Domain/member controllers require route `{uuid}` to
equal the tenant resolved from `X-Tenant-Id`. An All-Tenants row action targeting tenant B must
therefore call `tenant.select(B)` (which resets operator mode and access), await the race-safe
access refresh, and only then navigate to `/tenants/B/...` and issue detail queries. Detail pages
fail closed before fetching when the route UUID and `selectedUuid` differ; they may reconcile by
selecting the route tenant only when it appears in the caller's loaded tenant directory, and must
await the same access refresh before enabling their query. Owners
cannot use a URL parameter to independently select a foreign authorization target.

## §8 Error handling and refusals

- `ApiError` (`{status,message,fieldErrors,body}`) → render `message` verbatim; use
  `apiErrorCode`/`apiErrorDetails` for coded branches (409 lock/stale during enablement, disable
  gate-refusal codes). SP3b **invents no refusal copy** — it shows the server's.
- **403 recovery:** SP2a's `authFetch`/`client` already `clearSelection` → `ensureLoaded(true)` →
  dispatch `tenant-switch-required` on a 403 with an `X-Tenant-Id` present, but **nothing listens**
  today. SP3b wires the listener (reopen the switcher / prompt re-selection) — closing the SP2a
  seam — and resets `operatorMode` on that event (§7).
- First-tenant confirm validation errors (422) map to `fieldErrors` on the form.
- The 500 tenant-create seed-failure path (`{tenant_uuid, status:'provisioning',
  repair_command}`) renders the provisioning state plus a **Retry seeding** action backed by §3's
  HTTP repair endpoint. The CLI command remains secondary operational guidance.

## §9 Testing

vitest/jsdom; assert `data-testid`, never portal DOM (Nuxt UI stub convention).

- **Nav gating (§5):** Tenants module hidden without any of the three booleans; visible to an
  owner with only `manage_members`/`manage_domains`; All-Tenants + Settings→Tenancy hidden
  without `manage_platform`; Domains/Members mutating affordances hidden without their boolean.
- **Access store:** loads the four booleans; reset+refreshes on tenant/operator-mode changes;
  initial tenant selection precedes the first probe; auth reset clears it; fails closed on error;
  a delayed tenant-A response cannot overwrite tenant-B state.
- **operatorMode:** not persisted; reset on tenant change, selection clear, `reset()`, and
  `tenant-switch-required`; header injected at both choke points only when set.
- **Lifecycle progression (§6):** status reads never advance either machine; every request-driven
  intermediate state renders the correct explicit continue action; no post-response polling loop
  waits for `enabling_extension`, `mapping_hosts`, or equivalent states to change themselves. A
  simulated `reloading`→(fresh request)→`finalizing` transition renders Reload and continue, not
  a silent retry. Failed extension migration → retry → begin reaches `awaiting_confirm` rather
  than remaining stuck at `migrating_extension`.
- **Target synchronization:** selecting tenant B precedes navigation/fetch for tenant B; a route
  UUID/header mismatch performs no detail request; an owner cannot reconcile to an unavailable
  foreign tenant.
- **DNS instructions:** the create response's TXT name and token value are both rendered.
- **Refusal rendering:** server 403/409/422 messages surfaced verbatim; disable gate-refusals
  shown; 500 seed-failure offers browser-based repair and the repair endpoint returns active on
  success / the failing definition on 422.
- **New endpoints (server):** diagnose envelope/`content_permission:tenancy.manage` gate;
  resolution activate mirrors deactivate incl. `step==='failed'`→422 and lock→409; seed repair
  is `tenancy.manage`-gated and reuses `TenantSeedRepair`; access probe
  returns correct booleans for operator / owner / member / no-tenant across off/bootstrap/full
  modes and both supported principal shapes, and **emits no audit
  entry** (fake recorder asserted un-invoked) while an actual protected member/domain mutation
  **does** audit.

## §10 What deliberately doesn't change

SP3a's enforcement rule and matrix (SP3b renders, never edits them); the capabilities endpoint
(stays feature-only — the access probe is the separate per-caller surface); the tenancy
extension, contracts, framework (no release chain); `X-Tenant-Id` semantics and the switcher;
my-tenants. Existing SP1/SP2 domain behavior stays unchanged apart from the bounded
`migrating_extension` retry-resume correction (§6); the provisioning endpoint exposes the
already-shipped `TenantSeedRepair` behavior without changing it. SP3a changes only by extracting
the pure decision from `OperatorBypass` (§4).

## §11 Out of scope

Per-tenant role/matrix editing; permission-editing surfaces; tenant deletion; background
domain re-verification; TLS automation; any new authorization *policy* (SP3b is pure UI +
read/action plumbing over SP3a). Dedicated operator role (SP3a §12) unchanged.
