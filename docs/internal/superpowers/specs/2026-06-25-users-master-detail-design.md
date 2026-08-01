# Users — Master–Detail Admin Page — Design

**Goal:** Replace the table-based Users admin page with a **two-pane master–detail layout**
(persistent list on the left, inline edit/detail panel on the right), so an admin can browse,
filter, create, edit, role-assign, and delete users without modals or page navigation.

**Status:** Design — for review before the implementation plan.

**Acceptance test:** from `/users`, an admin can (1) find a user via search, (2) select
them and see their details, (3) edit name/email/username/status/roles and save in place, (4) create a
new user in the same pane, and (5) delete a user — all without leaving the page, with the list and
detail staying in sync.

---

## Why this shape

The current page (`src/pages/users/index.vue`) is a 7-column table with an ellipsis-menu per row;
editing happens in a stack of modals (create/edit/delete) plus a roles modal and a permissions
slideover. That works but fragments a single task (manage one user) across several overlays.

Master–detail keeps the user list always visible and edits a selected user in a persistent right
pane — the same mental model as Lemma's content list → editor. It is faster for the common
"open a user, change one thing, save" loop and gives a natural home for future per-user surfaces
(permissions, activity) as **tabs** rather than more overlays.

This supersedes the table page and its modals; `UserRolesModal` is absorbed (roles become a form
field) and `UserPermissionsSlideover` becomes a tab.

## Scope

**In:**

1. Two-pane layout inside the page's inset panel: **list pane** (~340px fixed) + **detail pane** (fills rest).
2. **List pane:** search, server-side pagination, selectable user cards.
3. **Detail pane:** header (identity + delete), **Details** tab (editable form), **Permissions** tab (writable — direct per-user grants).
4. **Create via slideover:** `+ New` opens a right-side `USlideover` with the create form (not a modal, not in-pane).
5. **Edit + save in place**, **delete with self-delete guard**, **role sync** — all on existing endpoints.
6. **URL-synced selection** (`?user={uuid}`) for deep-linking and browser back.
7. **Responsive:** single-pane fallback below `lg`.

**No backend changes** — this is a pure frontend refactor on the endpoints already shipped.

**Out of scope (later):**

- **Role filter + sort on the list** — deferred. They need real backend support (`role`/`sort` params on
  `GET /v1/users`); client-filtering one loaded page lies at scale, so we add them properly when there's
  demand. Until then: search + pagination only.
- **Activity tab** — no per-user audit endpoint exists yet; the tab is omitted (not stubbed) until one does.
- **"Send user notification" on create** — deferred. Emailing the new user about their account needs a
  backend notify-on-create hook (an email channel capability); adding it now would reintroduce backend
  work this refactor deliberately avoids. The toggle returns when that hook exists.
- **Website / Biographical info fields** — not in Lemma's user model (those are WordPress fields); not added.
- **Editing an existing user's password** — deferred (needs a backend password field on update / a reset flow). Password is set at create only.
- **Inline password editing** — password is set via an explicit, deliberate action, not a quiet form field (see Decisions).
- Bulk actions (multi-select delete/role-assign), CSV import/export, avatar upload.
- Editing the role→permission mapping — that stays in Roles & Permissions. This page edits a user's
  *direct* grants and *role assignments*, not what a role contains.

## Architecture

Vue 3 SPA, Nuxt UI 4, Pinia Colada queries, openapi-fetch, file-based routing. Reuses the existing
`src/queries/users.ts` and `src/queries/rbac.ts`. No new state store — selection lives in the route
query; server state lives in Colada queries.

### Component tree

```
src/pages/users/index.vue              orchestrator — reads ?user=, lays out the two panes, owns mode (view|create)
src/pages/users/components/
  UsersListPane.vue                    search + card list + pagination + "+ New"
  UserListItem.vue                     one card: avatar, display name, email, role badges, status dot
  UserDetailPane.vue                   selected-user header + tabs; empty state when nothing selected
  UserDetailsForm.vue                  the editable Details tab (edit only)
  UserCreateSlideover.vue              right-side USlideover with the create form
  UserPermissionsTab.vue               direct-grant editor (dual-list shuttle from UserPermissionsSlideover, reflowed into the tab)
```

Removed/absorbed: the table, the create/edit modals, `UserRolesModal.vue` (roles → form field). The
`UserPermissionsSlideover.vue` shuttle UI + its save logic move into `UserPermissionsTab.vue` (same
behavior, no longer a slideover). The delete confirm modal is **kept** (small, triggered from the
detail header).

### Layout

```
┌──────── users page (inset card) ───────────────────────────────────┐
│  LIST PANE (~340px)             │  DETAIL PANE (flex-1)             │
│ ┌─────────────────────────────┐ │ ┌──────────────────────────────┐ │
│ │ Users              [+ New]  │ │ │ [AV] jdoe            ⋮(delete)│ │   ← [+ New] opens a
│ │                              │ │ │                              │ │     right-side slideover
│ │ [ search… ]                 │ │ │      jdoe@x.com  ·  uuid · …  │ │
│ │ ───────────────────────────  │ │ ├──────────────────────────────┤ │
│ │ ▣ [AV] jdoe        Admin ·● │ │ │ Details │ Permissions        │ │
│ │   [AV] asmith      Editor·● │ │ ├──────────────────────────────┤ │
│ │   [AV] …                     │ │ │  …tab body…                  │ │
│ │                              │ │ │                              │ │
│ │ ───────────────────────────  │ │ │                 [Save changes]│ │
│ │ 1–25 of 312      ‹ 1 / 13 › │ │ └──────────────────────────────┘ │
│ └─────────────────────────────┘ │                                  │
└─────────────────────────────────────────────────────────────────────┘
```

## List pane (`UsersListPane`)

- **Search** → existing `useUsers(page, perPage, debouncedSearch)`; server-side, unchanged.
- **Card** (`UserListItem`): avatar (initial fallback), `userDisplayName()`, email, role badges, a
  status dot (active = success, otherwise neutral). Selected card = highlighted; click selects.
- *(Role filter + sort are deferred — see Out of scope. The list shows all users, search-narrowed,
  paginated.)*
- **Pagination** — reuse `TablePagination` (page + per-page + total) bound to the query meta.
- **Empty / disabled** — reuse the existing `UEmpty` (covers `USERS_USER_LIST_ENABLED=false`).
- **`+ New`** — opens the `UserCreateSlideover` (see below); does not affect the current selection.

## Create user (`UserCreateSlideover`)

A right-side `USlideover` titled "Create New User", footer `Cancel` / `Create User`. Fields:

| Field | Request field | Notes |
|---|---|---|
| Username | `username` | required; unique (422) |
| Email | `email` | required; unique (422) |
| Password | `password` | required, min 8; **reveal** toggle + **generate** button (client-side random) |
| First name | `first_name` | optional |
| Last name | `last_name` | optional |
| Roles | `role_slugs` | `USelectMenu multiple`, removable chips (from `useRoles()`) |

- **Submit** → `create.mutateAsync({...})` (server sets status `active` + verified, as today). On
  success: close the slideover, then **select** the new user (`?user={returned uuid}`) so the detail
  pane opens on them. Field errors map back onto the form (existing `setErrors` pattern); validation via zod.
- "Send user notification" is **not** included in v1 (deferred — see Out of scope).

## Detail pane (`UserDetailPane`)

**Header:** avatar · `userDisplayName` · email · `User ID` (uuid, copyable) · `Registered {created_at}`
· `⋮` menu with **Delete** (self-delete guard preserved → 422 surfaced as a notify; confirm modal kept).

**Empty state:** when nothing is selected — a centered prompt ("Select a user").

**Tabs:**

### Details (`UserDetailsForm`) — edit only

| Field | Source (`UserRow`) | Request field | Notes |
|---|---|---|---|
| First name | `profile.first_name` | `first_name` | optional |
| Last name | `profile.last_name` | `last_name` | optional |
| Username | `username` | `username` | required; unique (422) |
| Email | `email` | `email` | required; unique (422) |
| Status | `status` | `status` | `USelect` active/inactive |
| Roles | `roles[]` → `.slug` | `role_slugs` | `USelectMenu multiple`, removable chips |
| Email verified | `email_verified_at` | — | **read-only** badge (✓ + date) |
| 2FA | `two_factor_enabled` | — | **read-only** badge |
| Password | — | — | **not editable here** — deferred (needs a backend password field on update; set at create only) |

- **Save** → `update.mutateAsync({ uuid, input })`. Send `role_slugs` only when changed (the PATCH
  leaves roles untouched when omitted). Field errors map back onto the form (existing `setErrors` pattern).
- Validation via zod (mirrors the current edit schema).
- *(Roles and username/email/first/last fields are common to create + edit; implementation may extract
  a shared field sub-component, but the create form lives in the slideover and edit lives in this tab.)*

### Permissions (`UserPermissionsTab`) — writable, direct grants

Edits the user's **direct** permission grants — permissions given straight to the user, *in addition
to* whatever they inherit from roles. This is the existing `UserPermissionsSlideover` behavior moved
into a tab, unchanged in substance:

- Loads all permissions (`usePermissions`) + the user's current direct grants (`useUserPermissions(uuid)`).
- **Dual-list shuttle** — Available ↔ Assigned, with per-item select, select-all, and move-all, each
  list independently searchable.
- **Save** diffs the working set against the original and batch-applies the delta via
  `useUserPermissionMutations(uuid).save({ add, remove })` (slug-based add/remove).
- Caption makes the model explicit: "Direct grants are in addition to permissions from roles."

Layout note: the slideover used `max-w-5xl`; in the detail pane the shuttle uses the pane's width and
stacks the two lists vertically below a threshold so it stays usable in the narrower column.

## Data flow

- **Selection** = `route.query.user` (uuid). The orchestrator reads it; the list highlights it; the
  detail pane loads it. `+ New` toggles a `showCreate` ref (the slideover); on successful create it
  sets `?user` to the new uuid so the detail pane opens on the new user.
- **Detail fetch** — `useUser(uuid)` → `GET /v1/users/{uuid}` (already enriched with `profile` +
  `roles` by the `users.record_enricher` seam). Keeps the pane fresh independent of which list page is loaded.
- **Mutations** — `useUserAdminMutations()` already invalidates `['users']`; that refetches both the
  list and the detail (prefix match), and roles reappear via the enricher. No manual cache surgery.

## Backend changes

**None.** Everything this page needs already exists: list (`GET /v1/users`), single-read
(`GET /v1/users/{uuid}`, enriched with profile + roles), create/update/soft-delete
(`UserAdminController` + `glueful/users` `UserRepository`), role sync and direct-grant writes
(Aegis `AegisPermissionProvider` + the permission batch endpoints) — all shipped in the
admin-user-management commit. This is a pure frontend refactor.

*(Role filter + sort, when added later, will need `role`/`sort` params on `GET /v1/users` — that is
the trigger for the only backend work, and it's explicitly deferred. See Out of scope.)*

> **Boundary:** this page never touches the `users` / `profiles` / `user_roles` / permission schema
> directly — it composes existing app/extension endpoints. Role assignments and direct permission
> grants are both **per-user writes** that go through Aegis (slug-based add/remove). What it does *not*
> edit is the role→permission mapping itself (a role's contents) — that lives in Roles & Permissions.

## Responsive

- **≥ lg:** both panes side-by-side (list fixed ~340px, detail fills).
- **< lg:** single pane. List is full-width; selecting a user (`?user=`) shows the detail over/instead
  of the list with a **back** control to the list. (Reuses the established back-button pattern.)

## Decisions (resolved; flag to override)

1. **Password is set at create, not edited here.** On **create**, password is a required field (in the
   slideover, with reveal + generate). **Editing** an existing user's password is **deferred** — the
   update endpoint has no password field, and adding one is backend work this frontend-only refactor
   avoids; it belongs with a proper reset/“set password” flow later. *Rationale:* keeps the refactor
   frontend-only and avoids a quiet inline reset on every save.
2. **Permissions = writable tab** (the direct-grant shuttle, moved out of the slideover). *Rationale:*
   one fewer overlay; the detail pane is the natural home. Behavior is unchanged — it still edits the
   user's *direct* grants (additive to roles), not the role→permission mapping.
3. **No role filter / sort in v1.** *Rationale:* doing them honestly needs backend `role`/`sort`
   params; faking a filter over one loaded page misleads at scale. Ship search + pagination now; add
   filter/sort (with backend support) when there's real demand. Keeps this refactor frontend-only.
4. **Create = right-side slideover** (not a modal, not the in-pane detail form). *Rationale:* keeps the
   list + selected user visible behind it, matches the approved mock, and separates the create form
   (password + generate, no status) cleanly from the edit Details tab (status, no inline password).

## Acceptance criteria

- [ ] `/users` renders list + detail; selecting a card loads that user's details; `?user={uuid}` deep-links and survives refresh/back.
- [ ] Search drives the server query and updates the list + pagination meta.
- [ ] Editing username/email/status/first/last/roles and saving updates the row in place; uniqueness errors map onto the fields.
- [ ] Roles edit assigns/revokes via Aegis (slug-based) and the new roles show on the card + header after save.
- [ ] `+ New` opens a slideover; creating a user closes it and selects the new user (active + verified); password reveal + generate work.
- [ ] Delete honors the self-delete guard (422 surfaced) and removes the user from the list.
- [ ] Permissions tab edits the user's direct grants (shuttle add/remove → batch save) exactly as the current slideover does.
- [ ] Below `lg`, the layout collapses to a single pane with working back navigation.
- [ ] `vue-tsc` type-check, `oxlint`, and `vite build` all pass.
