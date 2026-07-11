# SP3a — Membership × Aegis RBAC Composition (Design)

> Slice 1 of SP3 (`SP3-README.md`, cited as "SP3 index §n"). Thallo-only: Aegis, the tenancy
> extension, contracts, and the framework are untouched — no release chain. SP3b builds its
> role picker and screen gating on this spec's ratified matrix (SP3 index §3.9).

## §1 The authorization rule (named, exact)

On tenant-data routes (`content_permission:<capability>` behind `tenant:admin` +
`tenant_bootstrap`):

```
allow =
  authenticated
  AND api-key scope permits            (unchanged, as today)
  AND (
        membershipMatrix(role, capability)      — the tenant axis; role = the caller's
                                                  membership role IN THE RESOLVED TENANT
    OR  explicitOperatorBypass(capability)      — §4: explicit mode + Aegis capability + audit
  )
```

This is deliberately **not strict conjunction**: within a tenant, the membership role is the
authority for `content.*` — a member with zero Aegis roles drafts by matrix right alone.
Aegis remains authoritative on the platform axis: `users.*`, `system.access`,
`tenancy.manage`, `tenancy.access_any`, and the capability check inside operator bypass.
No membership row and no explicit bypass → deny (fail closed, SP3 index §3.3).

`tenant_system` routes keep today's global Aegis behavior byte-for-byte.

## §2 The capability matrix (ratified; SP3 index §3.9's deliverable)

`config/tenancy.php` (app overlay) `tenancy.role_matrix` — rendered by SP3b exactly as
enforced; unknown role OR unknown capability → deny + warning log:

| capability | owner | admin | member | viewer |
|---|---|---|---|---|
| `content.view` | ✓ | ✓ | ✓ | ✓ |
| `content.create` | ✓ | ✓ | ✓ | — |
| `content.edit` | ✓ | ✓ | ✓ | — |
| `content.publish` | ✓ | ✓ | — | — |
| `content.delete` | ✓ | ✓ | — | — |
| `content.manage` | ✓ | ✓ | — | — |
| `content.routes` | ✓ | ✓ | — | — |
| `navigation.manage` | ✓ | ✓ | — | — |
| `seo.manage` | ✓ | ✓ | — | — |
| `templates.manage` | ✓ | ✓ | — | — |
| `analytics.read` | ✓ | ✓ | — | — |
| `workflow.review` | ✓ | ✓ | — | — |
| `tenant.members.manage` (new) | ✓ | — | — | — |
| `tenant.domains.manage` (new) | ✓ | — | — | — |

Roles come from the extension's configured allowlist (`owner|admin|member|viewer`); the
matrix is Thallo policy over that vocabulary. Final-owner protection (SP2 index §3.14) is
enforced in the bridge beneath ANY matrix outcome — no role combination reaches it.
These are all tenant-owned capability families currently reachable while tenancy is on.
The `collections.*` capabilities are deliberately absent because collections remain fenced
under tenancy; they join the matrix only when collections tenancy ships. `users.*` and
`system.access` remain global and never enter this matrix. A route-inventory test pins this
set: any new tenant-data `content_permission` slug must be added here deliberately or the
build fails, rather than becoming an accidental runtime denial.

## §3 Truth table (pinned wording)

- **Non-operator global `administrator` with `viewer` membership → viewer powers.** Global
  Aegis roles grant nothing on the tenant axis.
- **Platform administrator in ordinary tenant mode → membership powers.** Holding the bypass
  permissions does not elevate normal tenant work; if they are a member, the matrix governs.
- **Platform administrator with explicit bypass → Aegis-granted operator powers** (§4),
  with a best-effort audit attempt.
- **Member with no Aegis role → matrix-granted drafting powers** (view/create/edit).

## §4 Explicit operator bypass

Bypass never happens implicitly (pinned): the seeded permissions alone MUST NOT override a
membership role.

- **Foreign tenant (no membership):** selecting a tenant the caller has no membership in —
  via the operator listing in the switcher or `X-Tenant-Id` directly — IS the explicit act.
  Requires `tenancy.access_any`; the request runs in bypass mode; every such request submits
  a best-effort audit entry (`tenancy.operator_bypass`: user, tenant, capability, route).
- **Same tenant escalation (membership exists):** membership wins by default. Escalation
  requires the explicit request flag `X-Tenant-Operator-Mode: 1` (SPA exposes it as a
  deliberate "operate as platform admin" toggle in SP3b) AND `tenancy.access_any`; audited
  identically. Without the flag, an operator-who-is-a-viewer gets viewer powers.
- **CLI/system paths:** the existing `Bypass\Tenancy::forAnyTenant()` wrapper remains the
  explicit context (unchanged, already permission-checked).
- In bypass mode the capability check is Aegis (`PermissionManager::can`) — operator powers
  are exactly what the platform granted, not automatic-everything.
- **Mechanics note (verified as-built):** the resolution pipeline marks `tenancy.bypass`
  before checking membership; SP3a's middleware does its OWN membership lookup and applies
  membership-first regardless of that marking, so the extension pipeline is untouched.
- **Extension bypass narrowing:** Thallo config sets
  `tenancy.bypass_permissions = ['tenancy.access_any']`. The extension default also includes
  `tenancy.manage`, but management authority alone MUST NOT skip membership resolution.
  `tenancy.manage` authorizes the operation; `tenancy.access_any` authorizes selection of a
  tenant where the principal has no membership.

## §5 Management permissions (pinned: not `system.access`)

Cross-tenant/lifecycle management re-keys from `content_permission:system.access` to the
tenancy-specific permissions:

- **`tenancy.manage`** — tenant lifecycle + management operations: create, suspend,
  reactivate, list-all, enablement/resolution/disable actions, diagnose.
- **`tenancy.access_any`** — accessing a tenant without membership (bypass mode, §4).
- **Both** where an operation needs both powers (e.g. managing a foreign tenant's members:
  `tenancy.manage` for the operation + `tenancy.access_any` to target it without
  membership).
- `system.access` keeps its non-tenancy uses (settings/system surfaces) — tenancy routes
  stop using it.

## §6 Owner self-service (target-bound)

Member/domain management endpoints move out of the current `tenant_system` group and gain
`tenant_profile:admin` + `tenant_bootstrap`, so owner authorization has a resolved tenant to
bind to. Lifecycle/list-all/my-tenants routes remain system routes. The caller's selected
tenant context is the only self-service authorization target; another target is denied
regardless of role. Rule per endpoint:

- member routes and domain list/create routes carry a tenant `{uuid}` and require
  `route tenant uuid == resolved tenant uuid`;
- domain verify/enable/disable/remove routes carry a **domain uuid**, not a tenant uuid. The
  controller first loads the domain, reveals no foreign-domain details, and requires
  `domain.tenant_uuid == resolved tenant uuid` before mutation;
- members list/add/remove/set-role and domains add/verify/enable/disable/remove then require
  `owner-of-resolved-tenant AND target-bound-as-above` (capabilities
  `tenant.members.manage` / `tenant.domains.manage` via the matrix)
  OR explicit bypass (§4) with `tenancy.manage` (+ `tenancy.access_any` when targeting a
  tenant the operator has no membership in).
- create/suspend/reactivate/list-all: operator-only (`tenancy.manage`; never self-service).
- Required-host and final-owner protections sit beneath both paths, unchanged.

## §7 Enforcement point

`App\Content\Http\RequirePermission` (`content_permission` alias — route annotations do not
move) evolves: when request-state carries a resolved tenant, apply §1 through an app-owned
`TenantMembershipRoleReader`. The reader performs one indexed lookup by
`{tenant_uuid,user_uuid,status=active}` against `tenant_memberships` and memoizes that result
for the request. It does not call `TenantAdministration::listMembers()` and does not import
extension models; the direct read is a deliberately pinned Thallo integration with the
shipped extension table schema, avoiding a contracts/extension release solely for a read
helper. On `tenant_system` routes, current
global behavior. API-key scope short-circuit unchanged. Management endpoints (§5/§6) adjust
their route permission strings and add the target-binding checks in the controllers.

## §8 Operator seeding

`database/dependent-migrations/013_GrantTenancyOperatorToAdministrator.php` (established
Grant pattern): ensure `tenancy.manage` + `tenancy.access_any` permission rows exist;
idempotently grant both to `administrator`. The install admin becomes the platform operator
with zero manual steps; `TenantAccess::canBypass` comes alive. (A dedicated operator role
remains a later split — SP3 index §1 out-of-scope.)
The app tenancy config simultaneously narrows the extension's bypass permission list to
`['tenancy.access_any']` as required by §4; the grant of `tenancy.manage` remains operation
authority, not tenant-selection authority.

## §9 What deliberately doesn't change

Aegis (dormant scope dimension stays dormant); the tenancy extension (pipeline order,
membership gate, `TenantAccess`); contracts; framework; `users.*` globality; `my-tenants`
semantics; the SP2a admin-profile enforcement; final-owner and required-host protections.

## §10 Failure modes

Tenant-data route with no resolved tenant → deny. Membership revoked mid-session → next
lookup denies (403; SPA recovery per SP2a). Unknown role/capability vs matrix → deny +
warning. Bypass flag without `tenancy.access_any` → deny. Operator bypass on a route whose
capability Aegis hasn't granted → deny. Route-uuid ≠ resolved-tenant on self-service → deny
(both for owners and for operators NOT in bypass mode); domain-item routes apply the same
rule to the loaded domain's tenant owner. Audit recording uses the installed
`AuditRecorderInterface`, whose contract is deliberately best-effort and returns `void`:
the request proceeds if persistence fails, and the audit extension logs the failure at
`error` (falling back to `error_log`). The spec therefore guarantees an audit **attempt**,
not durable recording during an audit-store outage. A strict/fail-closed audit writer would
be a separate cross-cutting design change.

## §11 Testing

- **Truth-table suite** (§3 verbatim, per capability × surface): the four pinned cases as
  named tests, including the current-hole regression (global administrator with viewer
  membership can NO LONGER delete).
- Matrix enforcement per role on real routes (viewer 403 on create; member 403 on publish;
  admin full tenant capability set; owner + `tenant.*` capabilities). A route-inventory
  assertion covers `navigation.manage`, `seo.manage`, `templates.manage`, `analytics.read`,
  and `workflow.review`, and excludes fenced `collections.*` plus global permissions.
- Explicit-bypass matrix: foreign-tenant access without `tenancy.access_any` → 403; with →
  200 + audit record asserted; same-tenant escalation only with the flag; flag without
  permission → 403; bypass capability still Aegis-checked.
- Self-service binding: route groups establish admin tenant resolution; owner manages own
  tenant → 200; tenant route uuid of another tenant → 403 even for an owner-of-both; a
  domain uuid owned by another tenant → non-revealing deny; operator targets foreign tenant
  only in bypass mode.
- Management re-keying: `system.access`-holding non-operator can no longer manage tenants;
  `tenancy.manage` can; enablement/resolution/disable/diagnose surfaces re-keyed.
- Member with zero Aegis roles: drafts (view/create/edit), cannot publish.
- Membership reader executes one indexed query per request and never enumerates all members.
- Bypass audit success writes the expected entry; a failing recorder is invoked but does not
  block authorization (the underlying best-effort contract owns failure logging).
- Seeding migration idempotence; routes that remain `tenant_system` retain byte-identical
  global authorization (route-table + behavior assertions); full off/on/inert regression
  suites.

## §12 Out of scope

Dedicated operator role; per-tenant custom roles or matrix overrides; UI (SP3b renders this
spec); Aegis scope-dimension activation; permission editing surfaces; API-key per-tenant
scoping (keys keep today's semantics).
