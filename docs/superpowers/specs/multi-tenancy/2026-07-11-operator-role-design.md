# Dedicated Workspace-Manager Role — Platform Authority Model

**Status:** spec in review (HELD — not committed)
**Slice:** Bucket 1, lifecycle gaps #1 (dedicated cross-workspace role). Thallo-only.
**Date:** 2026-07-11

---

## §0 Context — what's true today (source-verified)

Three facts from the as-built code drive this design:

1. **Cross-workspace power is a capability, not a role.** It = holding the Aegis permissions
   `tenancy.manage` + `tenancy.access_any`. Every authorization check keys on the *permission*,
   never a role slug: `OperatorBypass::decide()` calls
   `PermissionManager::can($uuid, 'tenancy.access_any', 'thallo', …)`
   (`app/Content/Authorization/OperatorBypass.php:74-92`); `TenancyAccessController::access()`
   checks `tenancy.manage`/`tenancy.access_any` the same way (`:37-38`);
   `RequirePermission` composes membership-matrix OR operator-bypass (`RequirePermission.php:104-112`).
   The **only** place a role slug is bound to that power is migration
   `013_GrantTenancyOperatorToAdministrator.php:24` (`where('slug','=','administrator')`).

2. **The install user is an `administrator`, not a superuser.** The sole install path,
   `SetupService` (`app/Setup/SetupService.php:104-106`), assigns
   `config('thallo.roles.admin', 'administrator')` = `administrator`. No code path assigns
   `superuser` to anyone. On real installs (incl. the local dev instance) there is no superuser.

3. **Superuser is enumerated, not a wildcard.** Aegis migration 003 grants `superuser` an explicit
   15-permission list (`vendor/glueful/aegis/migrations/003_SeedDefaultRoles.php:242-247`); `can()`
   has no superuser short-circuit. Superuser therefore does **not** implicitly hold `tenancy.*`, nor
   does it auto-inherit Thallo-added permissions (`content.publish`, `navigation.manage`,
   `seo.manage`, …) which migration 004 granted only to `administrator`. Adding a permission to
   superuser must be done explicitly (the established pattern — see
   `008_AddUsersRolesManagePermission.php:19`).

Consequence: making cross-workspace power a distinct role requires **no runtime authorization code
change** (all checks are permission-based), but it forces a **superuser lifecycle**, because the
model removes that power from `administrator` and only a superuser may hand out the new role.

---

## §1 Goal & authority hierarchy

Introduce a distinct, delegable cross-workspace **management** role, separating three concerns:

| Role (Aegis, global) | Level | Authority |
|---|---|---|
| `superuser` | 100 | Ultimate installation authority + hidden break-glass root (see below). Holds `tenancy.*`. Can delegate the Workspace Manager role. |
| `workspace_manager` ("Workspace Manager", **new**) | 90 | Delegated cross-workspace authority: `tenancy.manage` + `tenancy.access_any` only. Nothing else. |
| `administrator` | 80 | Global CMS administration. **No** automatic cross-workspace access. |

**Naming.** The role grants exactly two permissions, so its name states exactly that authority
without a disclaimer:

> **Workspace Manager** — Create, suspend, manage, and access every workspace. Does not include
> global user management or system administration.

It deliberately is **not** called "Thallo Owner": that name would imply broad installation control
(users, roles, settings, content) the two-permission role does not have, and expanding the role to
earn it would re-bundle exactly the concentration this slice exists to break apart (least privilege —
separate "can enter any workspace" from "runs the CMS"). Someone needing both hats gets
`administrator` + `workspace_manager`, each name meaning one coherent thing.

**Superuser is the hidden break-glass root.** `superuser` is never assignable through the product UI
(§5 forbids it; §6 omits it from the picker) and is granted only via setup or the recovery CLI (§3/§4).
So in the *product-visible* ladder, Workspace Manager is the top global role and `superuser` sits
off-stage. This must stay true — do not surface `superuser` as a product-assignable role later.
(`superuser`/`administrator` slugs are Aegis-vendor-seeded and cannot be renamed.)

**Naming family.** `workspace_manager` is a **global** role that manages *every* workspace; the
per-workspace **membership matrix** (`owner|admin|member|viewer` in `config/tenancy.php`) is a
separate ladder and is unchanged. The description's "**every** workspace" plus its global-only
placement (§6) is what distinguishes it from a per-workspace tier. `bypass_permissions` stays
`['tenancy.access_any']`.

**Terminology.** The permission-holder set — the users who can act across workspaces — is defined by
**possession of `tenancy.access_any`** (superusers + workspace managers), never by role name. This
keeps the runtime authorization path untouched. Referred to below as "cross-workspace-access holders."

**Superuser identity is role-bound, not level-derived.** A user is a superuser only when they hold the
active, canonical Aegis `superuser` role. A custom role at level 100 or above is not a superuser. Role
level remains an ordering/ceiling input; it is never sufficient to acquire hidden-root authority.

---

## §2 Role & permission model — consolidated migration 013

Because Thallo is pre-launch and this database is local-only, we **consolidate migration 013**
rather than add a 014: a new migration would preserve history that has no external value. The file
is rewritten (renamed) and re-run via the local reset procedure in §8.

**`database/dependent-migrations/013_CreateTenancyAuthorityRoles.php`** (replaces
`013_GrantTenancyOperatorToAdministrator.php`).

`up()` — fully idempotent:
1. Ensure permissions `tenancy.manage` ("Manage tenants") and `tenancy.access_any`
   ("Access any tenant") exist (category `tenancy`, `is_system`).
2. Ensure role `workspace_manager` exists — name "Workspace Manager", **level 90**, `is_system`,
   `status = active` (create-if-missing by slug, per the 004 `ensureRows` pattern).
3. Grant both permissions to `workspace_manager`.
4. Grant both permissions to `superuser` (explicit, idempotent — the 008 pattern).
5. **Remove** both permissions from `administrator`.

It does **not** promote existing `administrator` users. User promotion is operational state, not
canonical role definition — that is the recovery CLI's job (§4) and setup's job for new installs (§3).

`down()` — reverse the canonical definition: regrant both permissions to `administrator`; remove
them from `superuser` and `workspace_manager`; delete the `workspace_manager` role and its grants.
(down() restores the pre-slice authorization shape so the local reset in §8 is clean.)

No changes to `OperatorBypass`, `RequirePermission`, `TenancyAccessController`, or `config/tenancy.php`.

---

## §3 Superuser lifecycle — SetupService change (new installs)

New installs must have a superuser (the ultimate authority + first cross-workspace-access holder).
`SetupService` (`app/Setup/SetupService.php:104-106`) is changed to assign the install user **both**:

- `superuser` — ultimate authority; via §2 it now carries the two tenancy permissions, so the first
  user can manage workspaces AND delegate the Workspace Manager role from day one.
- `administrator` — the Thallo-added CMS content permissions that superuser's enumerated grant list
  does not include.

Rationale for stacking rather than superuser-only: avoids retrofitting superuser's entire grant list
with every Thallo permission on every future permission addition. Superuser stays "authority +
cross-workspace"; administrator stays "CMS content." The install user does **not** need
`workspace_manager` — it holds the two permissions via superuser.

`config('thallo.roles.admin')` semantics: setup assigns the fixed pair above. (If a single config
knob is preferred, that is a plan-level detail; the canonical requirement is superuser + administrator.)

Setup is a **controlled system path** — it seeds roles directly and is not subject to the §5
assignment policy (bootstrap, not runtime delegation).

---

## §4 Recovery and transfer CLI

A foundational authority model needs a break-glass recovery/transfer path (e.g. the current
pre-launch admin who is not yet a superuser; a future ownership transfer).

**`app/Setup/Console/SuperuserGrantCommand.php`** — `#[AsCommand(name: 'thallo:superuser:grant')]`,
extends `Glueful\Console\BaseCommand` (mirrors `CreateAdminCommand`).

- **Argument:** `<user-uuid>` (required).
- **Behavior:** grants **both** `superuser` and `administrator` to the target user via
  `AegisPermissionProvider::assignRole()` — so the recovered user has ultimate authority plus Thallo
  CMS permissions. Both assignments run in one database transaction; an injected failure on either
  assignment rolls back both, and the audit record is emitted only after commit.
- **Local-console-only:** it is a console command (no HTTP surface). It refuses to proceed
  non-interactively unless `--force` is passed (guards against accidental scripted escalation).
- **Confirmation-gated:** interactive `confirm()` naming the resolved user (email/uuid) before granting.
- **Idempotent:** re-running on a user who already holds the roles is a no-op success.
- **Validated:** unknown/inactive user uuid → clear failure, non-zero exit.
- **Audited:** best-effort `AuditRecorderInterface::record()` — action `security.superuser_granted`,
  actor = the console operator (or `system:console`), target = the user, context `{roles, source: 'cli'}`.
- **Bypasses the §5 policy** (it is a controlled system path, like setup) — this is the sanctioned
  way to mint a superuser, which the normal delegation path (§5) forbids.

**`app/Setup/Console/SuperuserTransferCommand.php`** —
`#[AsCommand(name: 'thallo:superuser:transfer')]` is the only supported way to complete an ownership
transfer, because `superuser` is deliberately absent from the product role picker.

- **Arguments:** `<from-user-uuid> <to-user-uuid>` (required and distinct).
- **Behavior:** under one transaction and the §5 authority-continuity lock, validate that the source
  is an active superuser and the target is active; grant `superuser` + `administrator` to the target;
  then revoke `superuser` from the source. The source retains any other roles, including
  `administrator`.
- **Atomic:** a failure before commit leaves both users' original role sets unchanged. The target is
  an active superuser before the source grant is removed, so the final-superuser invariant is never
  transiently broken.
- **Confirmation and automation:** same interactive confirmation / explicit `--force` rule as the
  grant command. The prompt names both resolved users.
- **Audited:** best-effort action `security.superuser_transferred`, actor `system:console`, target =
  the destination user, context `{from_user_uuid, to_user_uuid, source: 'cli'}` after commit.
- **Idempotent recovery:** if the transfer already completed (target is a superuser and source is
  not), rerunning reports success without changing roles. Any other mismatched state fails clearly.

Both commands are registered in `ThalloServiceProvider` services() (~:1397) + `commands()` list
(~:1522), like `CreateAdminCommand`. There is no general-purpose `superuser:revoke`: removal must be
part of a transfer so the hidden root cannot be left without an active holder.

---

## §5 Assignment-policy hardening — `UserRoleAssignmentPolicy`

The policy (`app/Support/UserRoleAssignmentPolicy.php`, invoked from `UserAdminController` create
`:80` / update `:159`) governs runtime role delegation through the admin API. It already enforces:
`users.roles.manage` required; a level ceiling; can't-change-own-roles; unknown slug → 422. This
slice tightens it to the pinned invariants.

**Assignability rules** replace the current level-only exception. Superuser identity is determined by
the canonical active `superuser` role slug, never by `actorMaxLevel >= 100`.

Protected-role checks run first: `superuser` always denies add/remove through this policy;
`workspace_manager` requires a canonical superuser actor. The general rules then apply:

```
canAdd(actor, role) =
    actorMaxLevel > role.level
    AND ( actorIsSuperuser  OR  actorHoldsEveryPermissionGrantedByRole(role) )

canRemove(actor, role) =
    actorMaxLevel > role.level
```

- `actorMaxLevel > role.level` is **strict** and applies to everyone, superuser included. So the
  normal delegation path can never assign `superuser` (100 > 100 is false) or an equal-level role —
  minting a superuser is CLI/setup only (§3/§4). It *can* assign `workspace_manager` (100 > 90) and
  `administrator` (100 > 80) for a superuser actor.
- **Superuser is special-cased in the permission-subset clause** (not the level clause): because
  superuser's grant list is enumerated rather than a wildcard, requiring it to literally possess
  every permission in a role could wrongly block legitimate lower-level assignments. Superuser is
  trusted to hold the subset.
- **`superuser` is API-immutable.** Neither adding nor removing it is permitted through
  `UserRoleAssignmentPolicy`, regardless of actor level. Setup/`superuser:grant` may add it and
  `superuser:transfer` may move it; there is no product-API path. This closes the custom-level-above-
  100 case for revocation as well as assignment.
- **`workspace_manager` is a protected system role.** Assigning or revoking it through the product
  API requires `actorIsSuperuser` explicitly, in addition to the strict level rule. This invariant
  does not rely on level ordering: Aegis permits custom roles with arbitrary levels, so a custom
  level-95/100 role must not acquire the ability to delegate Workspace Manager merely by holding the
  same permissions. `superuser` itself remains unassignable through this policy.
- **Non-superusers must possess every permission a role being added grants** ("cannot grant
  permissions you do not possess") — computed from the actor's effective permissions vs the role's
  `role_permissions` set. The permission-subset clause applies only to additions. Removing an
  otherwise-removable lower role does not require possessing the permissions being taken away.
- A `workspace_manager` cannot mint another Workspace Manager both because 90 > 90 is false and
  because the protected-role rule requires a canonical superuser.

**Retained guards:** `users.roles.manage` required; **can't-change-own-roles** for non-superusers
(kept explicitly — the strict level clause alone would let an admin drop a lower-level role from
themselves); unknown slug → 422; diff-only (unchanged role sets are a no-op).

**Authority-continuity guard** (new): reject any operation that would remove
- the **final active superuser**, or
- the **final active cross-workspace-access holder** — the last user whose active roles grant
  `tenancy.access_any` (superusers counted).

"Active" means the user is active and not soft-deleted, the role is active, and the user-role
assignment is active. The guard covers every authority-removal verb, not only a submitted role diff:

1. removing `superuser`, `workspace_manager`, or any role carrying `tenancy.access_any`;
2. changing a holder's user status away from active; and
3. soft-deleting a holder.

`UserAdminController::update()` must authorize the complete requested account + role transition
before writing account/profile fields. A denied role or continuity change leaves the whole request
unchanged. `destroy()` invokes the same guard before soft deletion.

All three mutation paths serialize on one PostgreSQL advisory **transaction** lock and perform the
fresh holder count plus mutation in the same database transaction. This prevents two concurrent
revocations/deactivations from both observing another holder and committing a zero-holder state.
The implementation must prove that Aegis role writes participate in that same underlying connection;
if they do not, planning must introduce a transaction-participating repository seam rather than
weakening the invariant. Violations are structured 403s. Setup and `superuser:grant` are controlled
system paths; `superuser:transfer` uses the lock while preserving the invariant atomically.

**Audit** (new): every assignment, revocation, **and denied attempt** emits a best-effort
`AuditRecorderInterface::record()` — actions `security.role_assigned` / `security.role_revoked` /
`security.role_assignment_denied`, category `security`, actor + target + context
`{role, outcome, reason}`. Best-effort contract (try/catch, never blocks), mirroring
`OperatorBypass::recordAudit()`. A continuity denial caused by status/deletion rather than a role
diff uses `security.authority_change_denied` with `{operation, reason}` so the audit event remains
truthful.

---

## §6 Server-derived role visibility

The frontend must not infer whether the caller is a superuser; assignability is server-authored.
The global roles **list** is served by Aegis's `RoleController::index` (vendor) — we do **not** edit
Aegis. Instead Thallo adds a small owned surface:

**`GET /v1/admin/users/assignable-roles[?target_uuid={uuid}]`** is an authenticated, global/system
route requiring `users.roles.manage`. It derives add/remove authority from §5 and has two modes:

- **Create mode** (no target): return only roles the caller may add. `superuser` is never returned;
  `workspace_manager` is returned only to a canonical superuser.
- **Edit mode** (`target_uuid`): return the assignable catalog plus every role currently assigned to
  the target. Assigned but unassignable/unremovable roles are included as locked entries, e.g.
  `{slug, name, assigned: true, assignable: false, removable: false}`. This reveals no authority the
  caller could not already see on the user record and prevents a hidden high-trust role from being
  silently dropped by a full-set update.

The SPA user-role picker consumes this instead of the raw Aegis roles list. Locked roles render as
read-only and remain in any submitted full `role_slugs` set. Editing profile/account fields without
changing roles omits `role_slugs` entirely, preserving the API's existing "omitted means unchanged"
contract. API enforcement remains authoritative if either behavior is forged.

- **Surface placement.** `workspace_manager` is surfaced only under a global area (e.g. **Settings →
  Users & Roles → System roles**) — never in workspace membership forms.
- **Workspace membership pickers are unchanged** — they render only `owner|admin|member|viewer` from
  the matrix and never expose Aegis roles.
- **API enforcement remains authoritative** (§5); hiding a role in the picker is presentation only —
  a forged request to assign an unassignable role is still refused by the policy.

---

## §7 What deliberately doesn't change

Runtime authorization (`OperatorBypass`, `RequirePermission`, `TenancyAccessController`); the tenant
membership matrix and `bypass_permissions` in `config/tenancy.php`; the tenancy extension/contracts;
the role's scope stays **global** (not per-tenant); Aegis's scope dimension stays dormant; API-key
semantics. Cross-workspace access is permission-derived, so nothing downstream of the permission
check is aware of the new role.

---

## §8 Local reset procedure (operational, local-only)

Migration 013 is currently the last applied migration. To swap in the consolidated version cleanly:

```bash
php glueful migrate:rollback --steps=1        # runs old 013 down(): removes the tenancy grants from administrator and deletes its ledger row
# replace 013_GrantTenancyOperatorToAdministrator.php with 013_CreateTenancyAuthorityRoles.php
# apply SetupService change, recovery CLI, and policy changes
php glueful migrate:run                         # applies the consolidated 013 from scratch
php glueful thallo:superuser:grant <current-user-uuid>   # promote the existing local admin once
php glueful migrate:status                      # verify
composer test
```

Rollback is cleaner than hand-deleting the ledger row because it runs the old `down()` first
(removing the old administrator grants before the replacement `up()` runs). Tests rebuild their DB
from scratch, so they exercise the consolidated 013 naturally. This procedure is **local-only** and
documented, not code.

---

## §9 Failure modes

- Adding or removing `superuser` through the API → 403 (API-immutable), audited as a denied attempt.
- Removing the final cross-workspace-access role, or deactivating/deleting the final superuser or
  cross-workspace-access holder → 403 (continuity), audited as a denied attempt. Concurrent attempts
  serialize so at most one can commit.
- Non-superuser attempts to assign or revoke `workspace_manager` → 403 (protected-role rule).
- Any API attempt to add or remove `superuser`, including from a custom role above level 100 → 403;
  setup/recovery/transfer CLI are the only mutation surfaces.
- A custom role at level 95/100 is not treated as a superuser and cannot delegate
  `workspace_manager`.
- Non-superuser assigns a role granting a permission they lack → 403 (permission-subset clause).
- Recovery CLI on unknown/inactive uuid → non-zero exit, no partial grant.
- Recovery CLI non-interactive without `--force` → refuses.
- Transfer with an invalid source/target or a partial failure → non-zero exit, no role changes.
- Audit store outage → grant/denial still proceeds (best-effort contract); failure logged by the
  audit extension.

---

## §10 Testing

- **Migration:** idempotent `up()` (double-run); `workspace_manager` at level 90 with exactly the two
  grants; `superuser` gains both; `administrator` loses both; `down()` restores the prior shape;
  does not touch administrator user assignments.
- **Runtime parity:** an `administrator`-only user can no longer manage tenants / bypass; a
  `workspace_manager` and a `superuser` can; byte-identical behavior on routes that were never
  tenancy-gated.
- **Assignability truth table:** superuser assigns workspace_manager/administrator (✓) but not
  superuser (✗, CLI-only) and cannot remove superuser through the API (✗); a workspace_manager cannot
  mint another workspace_manager (✗);
  administrator cannot assign workspace_manager (✗); a custom level-95/100 role cannot assign it
  (✗); administrator assigns editor when it holds editor's perms (✓); non-superuser lacking an added
  role's permission (✗); removing a lower role does not require its permissions (✓); self-role change
  blocked for non-superuser (✗).
- **Last-of-kind:** superuser role removal is API-immutable regardless of holder count; deactivating
  or deleting the sole superuser (✗); removing the final cross-workspace-access role or
  deactivating/deleting its sole holder (✗); allowed where applicable when another active holder
  exists (✓); two concurrent authority-removal operations cannot both commit.
- **Audit:** assignment, revocation, and denied attempt each record the expected entry; a failing
  recorder does not block the operation.
- **Recovery CLI:** grants both roles atomically; injected second-assignment failure rolls both back;
  idempotent re-run; unknown uuid fails; confirmation gate; `--force` non-interactive path; audit
  entry written only after commit.
- **Transfer CLI:** atomically grants the target before revoking the source; rollback on injected
  failure; idempotent completed-state retry; invalid/inactive users fail; audit after commit.
- **Setup:** a fresh install provisions the first user as superuser + administrator; that user holds
  cross-workspace access.
- **Assignable-roles endpoint:** create mode returns only addable roles; edit mode preserves an
  existing unassignable role as locked/read-only; unrelated profile edits omit `role_slugs`;
  superuser sees workspace_manager as assignable while administrator/custom high-level roles do not;
  membership pickers remain unaffected.
- **Regression:** existing tenancy/authorization suites (SP3a truth tables, bypass matrices) stay
  green.

---

## §11 Out of scope

Per-tenant workspace managers (the role stays global); wiring the declarative `config/tenancy.php`
`bypass_permissions` into `OperatorBypass` (still hardcoded `tenancy.access_any`); Aegis scope-dimension
activation; retrofitting superuser's grant list to hold all Thallo permissions; API-key per-tenant
scoping; the other Bucket 1 slices (tenant deletion & host-retention; background domain
re-verification) — each its own spec.
