# Per-Locale RBAC — Design

**Goal:** Allow editorial permissions to be scoped to a locale — e.g. an editor who may
publish `fr` but not `de` — using Aegis's **native resource-level filters**, so the V1
permission names (`lemma.entries.publish`, etc.) never have to be renamed or
type/locale-encoded.

**Status:** ✅ Shipped (2026-06-17) — implemented and reviewed.

**Backlog item:** [POST_V1.md](../../POST_V1.md) §6. Resolves the deferral documented in
[V1_DESIGN.md](../../V1_DESIGN.md) §3 ("does not add per-locale RBAC") + §7.

> V1_DESIGN §7: "Per-content-type restriction later uses Aegis's **native resource-level
> filters** — checks take a resource argument (`can($user, 'lemma.entries.write',
> 'content-type:{slug}')`) — not type-encoded permission names, so the v1 permission list
> never has to be renamed… V1 does not pretend to have fine-grained editorial permissions;
> it just checks the namespaced permission with no resource argument."

---

## Definition of behavior (the contract)

> A locale-specific admin action (publish `fr`, save the `de` draft, assign the `en`
> route…) is authorized against the **locale it targets**, not just the bare permission.
> A user whose grant for that permission is filtered to `locale:fr` may perform the action
> on `fr` and is denied (403) on any other locale. A user with an **unscoped** grant (the
> default seeded roles) may act on every locale, exactly as today. Locale-*agnostic* actions
> (managing content models, creating/deleting an entry) are authorized against the coarse
> `lemma` resource, unchanged.

## What exists today (and what Aegis already supports)

- `RequireLemmaPermission` (the `lemma_permission` route middleware) calls
  `PermissionManager::can($uuid, $permission, 'lemma', $context)` — the resource is
  **hardcoded to `'lemma'`** (`RequireLemmaPermission.php`).
- **Aegis (v1.8.0) already implements resource-scoped authorization** — verified against
  `extensions/aegis/src`:
  - `AegisPermissionProvider::can($uuid, $permission, $resource, $context)` matches
    `$resource` against a **`resource_filter` JSON column** on each grant row
    (`user_permissions` and `role_permissions`, migration `002_CreatePermissionsTables`).
  - A grant with **no** `resource_filter` matches **any** resource (acts as `*`); a grant
    with `resource_filter = {"resource":"locale:fr"}` matches only that resource; `*`
    wildcards are supported and regex-injection-safe (`preg_quote`, v1.7.0).
  - **No super-admin bypass** (role `level` is organizational only) and **`can()` decisions
    are not cached** (v1.7.1) — so a per-resource check is never silently wrong.
  - The provider advertises `resource_filtering` + `scoped_permissions` capabilities.
- **The seeded Lemma role grants are global** — `004_SeedLemmaRolesAndPermissions` inserts
  `role_permissions` rows with only `(role_uuid, permission_uuid)` and **no
  `resource_filter`**. So every seeded role matches any resource string ⇒ **changing the
  resource argument is backward-compatible**.
- The framework router sets `_route_params` on the request **before** the middleware
  pipeline runs (`Router.php:650`), so the middleware can read the resolved `{locale}`.

## The one hard constraint (not a choice — an Aegis property)

**Aegis is permissive: authorization is an OR over a user's matching grants, and there is
no deny rule.** A single unscoped (global) grant therefore *overrides* any locale-scoped
grant. Consequence for this feature:

> Per-locale restriction is achieved by granting a user **only** locale-scoped permissions
> and **not** the coarse global ones. A user who holds the seeded global `lemma_editor`
> role *and* a `locale:fr` grant is **not** restricted — the global grant wins.

So per-locale RBAC is an **opt-in grant pattern** layered on the same permission names, not
a deny list. The seeded coarse roles stay for users who should have full access; locale
restriction means assigning locale-filtered grants instead of the coarse role. This is
called out explicitly because it is the most surprising part of the model.

## Scope decisions

1. **Resource convention.** Locale-specific actions check the resource `locale:<code>`
   (matching the V1_DESIGN/POST_V1 literal); locale-agnostic actions keep the coarse
   `lemma` resource. A global grant matches both; a `locale:fr` grant matches only `fr`
   actions (and not the coarse `lemma` ones).
2. **The middleware derives the resource from the route, automatically.** When the matched
   route has a `{locale}` parameter, `RequireLemmaPermission` builds `locale:<value>` from
   `_route_params['locale']`; otherwise it passes `lemma`. No route definition or DTO
   changes — every existing `{locale}` route becomes locale-scoped for free.
3. **Locale-agnostic routes stay coarse.** `content-types*` (`lemma.models.manage` /
   `read`), `POST /entries` (create), `GET/DELETE /entries/{uuid}` (show/destroy),
   `GET …/locales`, `GET …/routes` have no single target locale → resource `lemma`. A
   locale-restricted user is therefore denied entry create/destroy and model management
   unless also granted the coarse permission — correct, since those actions aren't scoped
   to one locale.
   **Stated plainly — this creates a discovery/visibility boundary:** the inventory routes
   `GET /entries/{uuid}/locales` and `GET /entries/{uuid}/routes` (and `GET /entries/{uuid}`
   show, content-type listing) are coarse. A user holding *only* `locale:fr`-scoped grants
   can edit a known `…/draft/fr` URL but **cannot list which locales/routes exist** or open
   the entry-show view without a **coarse** `lemma.entries.read` grant. Granting that coarse
   read to fix discovery also lets them **read** every locale's draft/version content (read
   isolation is not enforced on the coarse routes), while **write/publish stay fr-scoped**.
   So "strict per-locale read isolation" and "full admin discovery UX" are mutually
   exclusive for a locale-restricted user; pick per editor (see the operator recipe).
4. **Backward-compatible by construction.** No data migration. Existing global grants keep
   working; the feature only changes the *resource string* passed to `can()`. With no
   locale-scoped grants assigned anywhere, **HTTP authorization behavior is unchanged for
   globally granted users** (same allow/deny outcomes). One observable difference: Aegis
   *logs* the resource string it was given (`AegisPermissionProvider::can` →
   `logPermissionCheck`), so locale routes will now log `locale:fr` instead of `lemma` — an
   audit-log content change, not an authorization change.
5. **Per-content-type is the same mechanism, deferred.** Per-content-type restriction
   (`content-type:<slug>`) uses the identical resource-filter path, but the slug isn't a
   route param on most entry routes (it requires an entry→type lookup in the middleware),
   so wiring it is a separate step. This spec defines the mechanism so it's additive later;
   it does not wire per-content-type now (resolved: later).
6. **No new permissions, roles, routes, events, or tables.** The §5 event taxonomy and the
   permission list are unchanged — that's the whole point of using resource filters instead
   of renamed permissions.

## Architecture

Single focused change plus documentation:

- **`RequireLemmaPermission` gains resource derivation.** A small private method
  `resourceFor(Request $request): string`:
  ```
  $params = (array) $request->attributes->get('_route_params');
  $locale = $params['locale'] ?? null;
  return is_string($locale) && $locale !== '' ? "locale:{$locale}" : 'lemma';
  ```
  `handle()` passes that into `can($uuid, $permission, $resourceFor($request), $context)`
  instead of the literal `'lemma'`. Everything else (principal resolution, fail-closed
  behavior, the `PermissionManager` lookup) is unchanged.
- **No change to grant *assignment* code in Lemma.** Locale-scoped grants are created
  through Aegis's existing API — but the **supported shape is one role per locale**, not
  stacked per-user grants. Aegis's assignment paths dedupe by **(principal, permission)**
  ignoring `resource_filter`: `PermissionAssignmentService::assignPermissionToUser` checks
  `findUserPermission($user, $permission)` and `RolePermissionRepository::assignPermissionToRole`
  checks `findWhere(role_uuid, permission_uuid)` — so a given role/user can hold a permission
  with **exactly one** resource filter, and a second resource-scoped grant of the same
  permission **silently no-ops**. Therefore multi-locale access is modeled as a
  locale-filtered role per locale (`lemma_editor_fr` etc.), assigning a user to each locale
  role they need. This feature **documents the recipe**; it does not add a Lemma-specific
  assignment endpoint (see out-of-scope).

Data flow: request → router sets `_route_params` → `auth` resolves principal →
`RequireLemmaPermission` derives `locale:<code>` (or `lemma`) → `can()` matches it against
the user's grant `resource_filter`s → allow/deny (403).

## Data model

**No schema change in Lemma.** The `resource_filter` / `constraints` columns already exist
in Aegis's `user_permissions` / `role_permissions` tables. This feature only starts
*populating the check side* (the resource argument); the storage side already supports it.

## Setting up a locale-restricted editor (operator recipe — documented, not built)

To make a user a "French-only editor":
1. Do **not** assign the global `lemma_editor` role (its grants are unscoped → full access,
   which would override any locale restriction via Aegis's permissive OR model).
2. Create a **locale role** `lemma_editor_fr` whose `role_permissions` rows are each
   `resource_filter = {"resource":"locale:fr"}` for the permissions used by the
   **locale-scoped** routes:
   - `lemma.entries.read` @ `locale:fr` — the read on `GET …/draft/fr`, `GET …/versions/fr`,
     `POST …/preview/fr` (these routes require `read` **and** carry `{locale}`, so a global
     `*` read is **not** required — scoped read is enough and keeps fr isolation);
   - `lemma.entries.write` @ `locale:fr` — draft save/discard, locale-draft create, route
     assign/remove for fr;
   - `lemma.entries.publish` @ `locale:fr` — publish/unpublish/rollback for fr.
   Assign the user to `lemma_editor_fr`. (One role per locale — see the assignment caveat
   above; do **not** try to stack `locale:fr` + `locale:de` grants of the same permission on
   one role.) A French+German editor gets **both** `lemma_editor_fr` and `lemma_editor_de`.
3. **Decide the discovery/visibility tradeoff** (scope-decision 3): the coarse routes
   (`GET /entries/{uuid}`, `GET …/locales`, `GET …/routes`, content-type listing) need a
   **coarse** `lemma.entries.read` (resource `lemma`/unscoped). Granting it restores full
   admin discovery UX but also lets the user **read** all locales' content (write/publish
   stay fr-scoped). Omitting it keeps strict fr read isolation but the user can't list
   locale/route inventory or open the entry-show view. Choose per editor.

This recipe lives in `docs/` and the spec; the admin UX for it is a frontend follow-up.

## Testing (Postgres, `LemmaTestCase`; Aegis migrations run in the suite)

- **Backward compatibility:** a user with the seeded global `lemma_editor` role can publish
  *any* locale (global grant matches `locale:*`) — proves the resource change breaks
  nothing.
- **Locale restriction (allow):** a user granted only `lemma.entries.publish` filtered to
  `locale:fr` → `POST …/publish/fr` succeeds.
- **Locale restriction (deny):** the same user → `POST …/publish/de` returns **403**.
- **Scoped read on locale routes:** a user with only `lemma.entries.read` @ `locale:fr` →
  `GET …/draft/fr` succeeds and `GET …/draft/de` returns **403** (a `*` read is not required
  for fr's own locale-scoped read routes — confirms the recipe).
- **Discovery boundary:** that same fr-read-scoped user (no coarse read) → `GET …/locales`
  and `GET /entries/{uuid}` (resource `lemma`) return **403**; granting coarse
  `lemma.entries.read` makes them succeed (documents the visibility tradeoff).
- **Resource derivation:** assert (unit-level on the middleware) that a route with
  `_route_params['locale']='fr'` yields resource `locale:fr`, and a route without a
  `locale` param yields `lemma`.
- **Locale-agnostic deny:** a locale-only-granted user → `DELETE /entries/{uuid}` (resource
  `lemma`) returns **403** (documents scope-decision 3); a coarse-granted user succeeds.
- **OR-semantics caveat:** a user with *both* the global role and a `locale:fr` grant is
  **not** restricted on `de` — locks in the documented "global grant wins" behavior so it
  can't silently regress.
- **Fail-closed unchanged:** missing principal / unresolvable `PermissionManager` / empty
  permission param still 403 (existing behavior preserved).

## Out of scope / follow-ups

- **Lemma admin UI / API for assigning per-locale grants** — POST_V1 names this as the hard
  UX part; it's a frontend deliverable on top of this backend mechanism.
- **Per-content-type scoping wiring** — mechanism defined (resource `content-type:<slug>`);
  wiring needs an entry→type lookup in the middleware and a decision on combining axes
  (resolved decision 3). Additive later.
- **Combining locale + content-type in one check** — Aegis matches a single resource string
  per `can()` call, so a composite convention (e.g. `content-type:blog/locale:fr`) or
  two sequential checks would be needed; deferred with per-content-type.
- **Deny rules / negative permissions** — not supported by Aegis's OR model and not added.

## Resolved decisions

1. **Resource derivation → auto-derive** from the presence of a `{locale}` route param (zero
   route churn, uniform across every locale route). Chosen over an explicit per-route hint
   (e.g. `lemma_permission:…:by-locale`); the `{locale}` param is an unambiguous, intentional
   scope signal.
2. **Entry create → coarse `lemma`** (not scoped to an initial-draft locale). Creating an
   entry is a non-locale-specific capability; a locale-restricted editor needs a coarse grant
   to create entries. (A locale-scoped create can be added later if needed.)
3. **Per-content-type → later.** This spec ships per-locale; per-content-type uses the same
   resource-filter mechanism but needs the entry→type lookup + an axis-combination decision,
   so it's an additive follow-up, not part of this iteration.

## Success criteria

- With no locale-scoped grants assigned, **HTTP authorization is unchanged** for globally
  granted users (the seeded coarse roles authorize every locale; only the audit-log resource
  string changes).
- A user granted a permission filtered to `locale:fr` can perform that action on `fr` and is
  denied (403) on other locales, via Aegis's native resource filter — no permission renames.
- A French-only editor is configured via a `locale:fr` role (scoped read + write + publish),
  with the discovery/visibility tradeoff chosen explicitly.
- The permission list, routes, events, and tables are unchanged; the only code change is the
  resource string the middleware passes.
- Full suite green on Postgres CI; the backward-compat, allow, deny, scoped-read,
  discovery-boundary, derivation, and OR-semantics tests pass.
