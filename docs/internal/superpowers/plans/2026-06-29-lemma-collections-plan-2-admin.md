# Lemma Collections — Plan 2: Admin (backend + SPA)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the collections **admin** — a capability-gated Vue **schema builder + data browser** backed by a new admin API on the existing `/v1/admin` + Aegis surface — plus the two backend wirings surfaced during the framework-reuse work: admin **actor resolution** and **audit-log** integration.

**Architecture:** Admin controllers live in the `lemma-collections` package (boundary: framework + `lemma-contracts` only — **never `glueful/audit`, `App\*`, or `glueful/lemma`**), exposed under `/v1/admin/collections` from the package's **capability-gated** routes using the App-provided `auth` + `lemma_permission` middleware **aliases**. The admin actor is resolved by the package's `ActorResolver` from the post-auth session (`auth.user` → `user`). The pack only **emits** `CollectionRow*` events; **audit integration is App-side** (the App subscribes to those events and records them — the App may depend on `glueful/audit`; the pack may not). The SPA registers a `requires: ['lemma.collections']` admin module that mirrors the **content-types** (schema builder) and **entries** (data browser) features.

**Tech Stack:** PHP 8.3 (package + App). Vue 3.5 + `@nuxt/ui` 4 + `@pinia/colada` + `openapi-fetch` + `zod` 4 + `vitest` (admin SPA at `admin/`).

## Global Constraints

- **Commits are gated.** Do **not** run any `git commit` until the human authorizes. During Subagent-Driven execution, per-task commits on `dev` are authorized by launching the run; absent that, treat every `Commit (when authorized)` step as blocked. Never commit the plan/spec docs without explicit go-ahead.
- **Package boundary:** namespace `Glueful\Lemma\Collections\`; depends on **framework + `lemma-contracts` only** — **no `glueful/audit`, no `App\*`, no `glueful/lemma`**; `composer boundaries` stays green. Routes reference App middleware by **alias string only** (`'auth'`, `'lemma_permission:collections.schema.manage'`). Audit and api-key-scope wiring live in the **App**, not the pack.
- **Capability gating:** the admin surface is gated on `lemma.collections`. Admin routes register only when enabled (the package boot gate already used for public routes); the SPA module declares `requires: ['lemma.collections']` and each page sets `meta.requiresCapability`. Disabling hides the admin **and** public API and preserves all tables.
- **Admin routes are triple-gated:** every admin route is protected by **all three** independent gates — the `auth` middleware (admin session), a per-route `lemma_permission:collections.{schema|data}.manage` (RBAC), **and** the `lemma.collections` capability boot gate. Capability-enabled is not authorization; auth + permission still apply on every route.
- **Permissions split (spec §2):** `collections.schema.manage` (create/alter/drop structure + indexes) and `collections.data.manage` (write rows via the admin) are distinct and never blurred. Public API-key scopes (`collections.{name}.{read|write|delete}`) are unchanged.
- **Canonical package:** `packages/lemma-collections` is the single source of truth (the composable-core path package, matching Plan 1). Anything under `vendor/` or `extensions/` is installed output / an old map artifact — **never edit it.**
- **Unreleased framework dependency:** relies on the vendor-patched framework fixes from this session (notably `AuthMiddleware` populating `auth.user`). Keep the vendor patch until the framework is released and the pin bumped.
- **SPA conventions:** forms = `UForm` + a `zod` schema + `UFormField`/`UInput` (never `UAuthForm`); Pinia **setup** stores; assert component tests via `data-test`/`data-testid` hooks (never Nuxt UI portal internals); if a page trips the `definePage` vue-tsc quirk, use an SFC `<route lang="json">` block instead.

---

## File Structure

**Backend — package (`packages/lemma-collections/`):**
- `src/Http/ActorResolver.php` *(modify — admin/user session branch)*
- `src/Data/RowRepository.php` *(modify — `delete()` takes `Actor`)*
- `src/Events/CollectionRowDeleted.php` *(modify — carries the deleting `Actor`)*
- `src/Http/Controllers/CollectionAdminSchemaController.php`, `CollectionAdminDataController.php` *(new)*
- `src/Http/admin-routes.php` *(new — loaded by the provider boot, gated)*
- `src/Http/DTOs/...` *(new — request DTOs)*
- `src/LemmaCollectionsServiceProvider.php` *(modify — register controllers + load admin routes when enabled)*
- `migrations/003_SeedCollectionsPermissions.php` *(new — seed `collections.*` permissions; additive-safe `down()`)*

**Backend — App (`app/`):**
- `app/Collections/Audit/CollectionAuditSubscriber.php` *(new — subscribes to `CollectionRow*`, records audit; depends on `glueful/audit`)* + its registration in `config/events.php` / `LemmaServiceProvider`.
- (Task 7) possibly a minimal **api-keys** scope-update endpoint, only if one is missing.

**SPA (`admin/src/`):**
- `registry/collectionsModule.ts`, `queries/collections.ts` (+ `queries/keys.ts`) *(new)*
- `pages/collections/index.vue`, `new.vue`, `[name]/index.vue` (schema), `[name]/data/index.vue` (data browser), `pages/collections/components/*` *(new)*
- `api/schema.d.ts` *(regenerate via `pnpm gen:api` after Phase A lands)*
- `__tests__/collectionsGating.spec.ts`, `queries/collections.spec.ts` *(new)*

---

# PHASE A — Backend

### Task 1: `ActorResolver` resolves the admin/user session actor

**Files:** Modify `packages/lemma-collections/src/Http/ActorResolver.php`; Test `tests/Integration/Collections/ActorResolverTest.php` (new).

**Interfaces:** Consumes request attributes `auth_method`/`api_key_uuid` (api-key path) and `auth.user` (`Glueful\Auth\UserIdentity`) / the `user` array (session path, set by the framework `AuthMiddleware`). Produces: `resolve(Request): Actor` (signature unchanged; the session branch now works).

**Context:** the session branch reads `user_id`/`user_data`, which only the api-key path sets — a real admin session sets `user`/`auth.user`. With the framework fix `auth.user` is always populated after auth; resolve from it (fall back to the `user` array), deriving admin vs user from roles. Read `Glueful\Auth\UserIdentity` + the request attributes directly (framework, not `App\`); do **not** import `App\Support\ActorHelper`.

- [ ] **Step 1 — failing test:** (a) `auth_method='api_key'`+`api_key_uuid='k-1'` → `Actor('api_key','k-1')`; (b) `auth.user`=`new UserIdentity('u-1',['administrator'])` → `Actor('admin','u-1')`; (c) no `auth.user`, `user`=`['uuid'=>'u-2','roles'=>['editor']]` → `Actor('user','u-2')`.
- [ ] **Step 2 — run, fail** (b/c return `Actor('user', null)`).
- [ ] **Step 3 — implement** the session branch (prefer `auth.user` `UserIdentity`, else the `user` array; admin iff roles contain `administrator`). Add `use Glueful\Auth\UserIdentity;`.
- [ ] **Step 4 — run unit + integration collections suites; green.**
- [ ] **Commit (when authorized):** `ActorResolver: resolve admin/user actor from auth.user/user session`

---

### Task 2: Carry the deleting actor on `CollectionRowDeleted`

**Files:** Modify `src/Data/RowRepository.php`, `src/Events/CollectionRowDeleted.php`; Test `tests/Integration/Collections/RowDeleteActorTest.php` (new) + update existing `RelationsTest` delete-event usage.

**Interfaces:** Produces `RowRepository::delete(CollectionDefinition $def, string $uuid, Actor $actor): void` (gains `$actor`); `CollectionRowDeleted` gains `public readonly Actor $actor`. **No `glueful/audit` import** — this is pure event payload so the App audit subscriber (Task 3) can attribute the delete. (`Created`/`Updated` already carry `$row`, which holds `created_by_*`/`updated_by_*`.)

- [ ] **Step 1 — failing test:** delete a row through `RowRepository::delete($def,$uuid,$actor)`; assert the dispatched `CollectionRowDeleted->actor` equals the passed actor.
- [ ] **Step 2 — run, fail** (signature has no actor).
- [ ] **Step 3 — implement:** thread `Actor` through `delete()` → `new CollectionRowDeleted($def->name, $uuid, $actor)`; update callers (the public `CollectionDataController` + the admin data controller in Task 6) to pass the resolved actor.
- [ ] **Step 4 — run unit + integration; green** (incl. `RelationsTest`).
- [ ] **Commit (when authorized):** `RowRepository.delete carries the deleting Actor on CollectionRowDeleted`

---

### Task 3: App-side audit subscriber for collection row events

**Files (App):** Create `app/Collections/Audit/CollectionAuditSubscriber.php`; register it (`config/events.php` listeners for `CollectionRow{Created,Updated,Deleted}`, or in `LemmaServiceProvider`); Test `tests/Integration/Collections/CollectionAuditTest.php` (new).

**Interfaces:** Consumes the pack's `Glueful\Lemma\Collections\Events\CollectionRow{Created,Updated,Deleted}` + `glueful/audit`'s recording surface (the App may depend on both). Produces: collection row CRUD recorded in the audit log, attributed to the actor.

**Context:** keep the pack decoupled — it just emits events. The App owns audit policy (it already uses `glueful/audit` for content). The subscriber maps each event → an audit record with category `collections`, action `created|updated|deleted`, target `{type:'collection_row', uuid:$rowUuid, label:$collectionName}`, and actor uuid (from the row's `updated_by_id`/`created_by_id`, or `CollectionRowDeleted->actor->id`). Record via the audit extension's API — either dispatch an App-side event implementing `Glueful\Extensions\Audit\Contracts\AuditableEvent` (mirroring `BaseContentEvent`), or call the audit recorder directly. The implementer picks whichever the audit extension exposes cleanly; **only the App references `glueful/audit`.**

- [ ] **Step 1 — failing test:** with the subscriber registered, create/update/delete a collection row; assert an audit record exists for each (category `collections`, the right action/target/actor).
- [ ] **Step 2 — run, fail.**
- [ ] **Step 3 — implement** the subscriber + registration.
- [ ] **Step 4 — run; green;** `composer boundaries` green (the pack still imports no audit).
- [ ] **Commit (when authorized):** `App: audit collection row CRUD via a CollectionRow* subscriber`

---

### Task 4: Seed the `collections.*` admin permissions

**Files:** Create `packages/lemma-collections/migrations/003_SeedCollectionsPermissions.php`; Test `tests/Integration/Collections/CollectionsPermissionsSeededTest.php` (new).

**Context:** `collections.*` permissions exist only because the pack exists, so the pack declares them — a flat package migration (DEPENDENT priority) mirroring `database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php` `up()`. Slugs `collections.manage`, `collections.schema.manage`, `collections.data.manage`; `category='collections'`, `is_system=true`; granted to `administrator`.

**Rollback is additive-safe (decision):** disable/remove must preserve data and avoid RBAC churn. `down()` is a **no-op by default** — never strips grants. (It may delete the permission rows *only if* completely safe — unassigned from every role — but never the grants.)

- [ ] **Step 1 — failing test:** after migrate, the three slugs exist (category `collections`) and are granted to `administrator`.
- [ ] **Step 2 — run, fail.**
- [ ] **Step 3 — implement:** `up()` `ensureRows('permissions', …)` + `role_permissions` grants; `down()` additive-safe per above. Register in `scripts/run-test-migrations.php` at DEPENDENT priority.
- [ ] **Step 4 — run; green.**
- [ ] **Commit (when authorized):** `Seed collections.{manage,schema.manage,data.manage} permissions (additive-safe down)`

---

### Task 5: `CollectionAdminSchemaController` — model management API

**Files:** Create `src/Http/Controllers/CollectionAdminSchemaController.php` + request DTOs under `src/Http/DTOs/`; Modify provider `services()`; Test `tests/Integration/Collections/AdminSchemaApiTest.php` (new).

**Interfaces:** Consumes `CollectionManager` (`create/addField/addIndex/removeIndex/dropField/dropCollection`), `CollectionDefinitionRepository` (`all/findByName`), `ActorResolver`, `Glueful\Http\Response`. Maps `CollectionValidationException`→422 (`->errors()`), `BlockedSchemaChangeException`→422, `DestructiveConfirmationRequiredException`→409, not-found→404. Produces handlers `index/show/store/addField/dropField/addIndex/dropIndex/destroy`.

**Context:** thin HTTP layer over `CollectionManager`; actor from `ActorResolver::resolve($request)`. Mirror `ContentTypeController` (constructor-injected deps, request DTOs first-param, `Response::*` factories, try/catch mapping, `#[ApiOperation]`/`#[ApiResponse]`). `dropField`/`destroy` accept a `confirm` body field forwarded to the manager (guarded-drop flow).

- [ ] **Step 1 — failing test** (drive via the container + built `Request`, asserting the `Response`, until routes exist in Task 8): `store` creates (201); `index` lists; `addField`/`addIndex` succeed; `dropField` without `confirm` on a populated table → 409; `destroy` with the right `confirm` → 200; unsupported field type → 422.
- [ ] **Step 2 — run, fail.**
- [ ] **Step 3 — implement** the controller + DTOs (`CreateCollectionData` w/ `#[Rule(...)]` on `name`/`label`/`#[ArrayOf(FieldData::class)] fields`; `AddFieldData`, `AddIndexData`).
- [ ] **Step 4 — run; green;** phpcs + boundaries.
- [ ] **Commit (when authorized):** `Add CollectionAdminSchemaController (model management API)`

---

### Task 6: `CollectionAdminDataController` — admin data browser API

**Files:** Create `src/Http/Controllers/CollectionAdminDataController.php`; Modify provider `services()`; Test `tests/Integration/Collections/AdminDataApiTest.php` (new).

**Interfaces:** Consumes `CollectionDefinitionRepository::findByName`, `QueryCompiler::list`, `RowRepository` (`find/create/update/delete` — actor-aware delete from Task 2), `RelationResolver::expand`, `ActorResolver`. Maps `RowValidationException`→422, `RowNotFoundException`→404, `RowReferencedException`→409. Produces `index/show/store/update/destroy`.

**Context:** the admin data browser reuses the Plan-1 engine, but the actor is the **admin session** and access is gated by `collections.data.manage`. `index` returns `Response::paginated($result->data,$result->total,$result->page,$result->perPage)`. `destroy` passes the actor to `RowRepository::delete` and surfaces restrict errors (409).

- [ ] **Step 1 — failing test:** (admin actor) create a collection in setUp, then list rows (paginated); `store` stamps `created_by_type='admin'`+admin uuid; `update`; `destroy`; deleting a referenced row → 409.
- [ ] **Step 2 — run, fail.**
- [ ] **Step 3 — implement.**
- [ ] **Step 4 — run; green;** phpcs + boundaries.
- [ ] **Commit (when authorized):** `Add CollectionAdminDataController (admin data browser API)`

---

### Task 7: API-key scope-management support (verify; add endpoint only if missing)

**Files:** Test/verify against the existing api-keys admin (`app/Http/Controllers/ApiKeyAdminController.php` + `admin/src/queries/apiKeys.ts`). If a gap exists: a minimal **api-keys** scope-update endpoint + its test. **Runs before Task 9 (OpenAPI regen) and before all SPA tasks.**

**Context (decision):** per-collection scopes are just strings on API keys. The collections admin will compose `collections.{name}.{read|write|delete}` and drive the **existing** api-key create/rotate/**update** surface. This task verifies that surface can patch a key's scopes; only on a **hard gap** do we add a minimal endpoint — to the **api-keys** admin (not a collections-specific key API).

- [ ] **Step 1 — verify:** can the current api-key admin API set/replace a key's scopes (via update/rotate)? Inspect `ApiKeyAdminController` + `ApiKeyService`. Record the finding in the task report.
- [ ] **Step 2 — if it can:** no backend change; note the reusable endpoint for Task 15. Done.
- [ ] **Step 2′ — only if it cannot:** add a minimal `PATCH /v1/admin/api-keys/{uuid}/scopes` (or extend the existing update) on the api-keys admin, with a failing test → implement → green. Keep it generic (not collections-specific).
- [ ] **Commit (when authorized):** `Verify/enable API-key scope updates for collections admin` *(skip the commit if no change was needed; record the finding instead)*

---

### Task 8: Gated admin routes + provider wiring

**Files:** Create `src/Http/admin-routes.php`; Modify `src/LemmaCollectionsServiceProvider.php`; Test `tests/Integration/Collections/AdminRoutesGatedTest.php` (new).

**Context:** register admin routes from `boot()` **inside** the `isEnabled('lemma.collections')` gate (next to the public `loadRoutesFrom`). Routes: `/v1/admin/collections`, group middleware `['auth']`, per-route `lemma_permission:collections.schema.manage` (schema) / `collections.data.manage` (data) — by **alias string** (no `App\` import). Triple-gated per the constraints.

- [ ] **Step 1 — failing test:** capability **enabled** → `GET /v1/admin/collections` without auth/permission is **401/403, not 404** (route exists); **disabled** → **404** (unregistered). Mirror `RemovabilityTest`.
- [ ] **Step 2 — run, fail.**
- [ ] **Step 3 — implement** `admin-routes.php` + the gated `loadRoutesFrom`.
- [ ] **Step 4 — run full collections + content/delivery suites; green.**
- [ ] **Commit (when authorized):** `Register gated /v1/admin/collections routes`

---

### Task 9: Backend removability + boundary + OpenAPI

**Files:** Extend `tests/Integration/Collections/RemovabilityTest.php`; `composer boundaries`; ensure `#[ApiOperation]`/`#[ApiResponse]` on all admin handlers (incl. any Task-7 endpoint) so `pnpm gen:api` produces typed SPA paths.

- [ ] **Step 1** — disabling `lemma.collections` 404s the admin routes too; `composer boundaries` green (pack imports no `App\`/`glueful/audit`).
- [ ] **Step 2** — doc-only `ResponseData` holders exist for the admin envelopes so `schema.d.ts` is typed.
- [ ] **Step 3 — run full suite + boundaries + phpcs; green.**
- [ ] **Commit (when authorized):** `Prove admin removability + boundary; annotate admin API for OpenAPI`

---

# PHASE B — Admin SPA (`admin/`)

> First: `pnpm gen:api` to regenerate `admin/src/api/schema.d.ts` against the Phase-A endpoints. Every SPA task ends with `pnpm type-check` (vue-tsc) + `pnpm test` (vitest) green.

### Task 10: Capability-gated collections admin module

**Files:** Create `admin/src/registry/collectionsModule.ts`; Modify `admin/src/layouts/default.vue` (register alongside `registerCoreModule()`); Test `admin/src/__tests__/collectionsGating.spec.ts`.

**Context:** mirror `registry/coreModule.ts` + the `lemma.importers` gating. `requires: ['lemma.collections']`; nav group "Collections" (Schema + Data).

- [ ] **Step 1 — failing test** (the `adminModules.spec.ts` pattern): `visibleNav(() => false)` omits Collections; `visibleNav(id => id==='lemma.collections')` includes it.
- [ ] **Step 2 — run, fail.** **Step 3 — implement.** **Step 4 — `pnpm test` + `type-check`; green.**
- [ ] **Commit (when authorized):** `admin: capability-gated collections module + nav`

---

### Task 11: Collections query/mutation layer

**Files:** Create `admin/src/queries/collections.ts` (+ keys in `keys.ts`); Test `admin/src/queries/collections.spec.ts`.

**Context:** mirror `queries/contentTypes.ts` — typed `openapi-fetch` `client` fetchers that throw `toApiError`, wrapped in `@pinia/colada` `useQuery`/`useMutation` with central keys + `cache.invalidateQueries` on settle. Definitions: list/create/addField/dropField/addIndex/dropIndex/dropCollection. Rows: list(paginated)/create/update/delete. Normalize `unknown[]` rows to hand-written interfaces.

- [ ] **Step 1 — failing test:** stub `globalThis.fetch`; `fetchCollections()` parses the list; failure throws `ApiError`.
- [ ] **Step 2 — run, fail.** **Step 3 — implement.** **Step 4 — `pnpm test` + `type-check`; green.**
- [ ] **Commit (when authorized):** `admin: collections query/mutation layer`

---

### Task 12: Schema builder — list + create

**Files:** Create `admin/src/pages/collections/index.vue`, `new.vue`; Test `admin/src/__tests__/collectionsSchema.spec.ts`.

**Context:** clone `pages/settings/content-types/index.vue` (UTable list + delete modal) and `new.vue` (`UForm`+zod create + field editor); reuse `src/fields/` for per-type settings. Each page `definePage({ meta: { requiresAuth: true, requiresCapability: 'lemma.collections' } })` (or `<route>` block on the vue-tsc quirk). API field errors → `form.setErrors(ApiError.fieldErrors)` + `notifyError`.

- [ ] **Step 1 — failing test:** mount the list with a mocked `useCollections()` (two collections) + caps store enabled; assert two `[data-test="collection-row"]`; the create button links to `new`.
- [ ] **Step 2 — run, fail.** **Step 3 — implement.** **Step 4 — `pnpm test` + `type-check`; green.**
- [ ] **Commit (when authorized):** `admin: collections schema builder — list + create`

---

### Task 13: Schema builder — edit (fields/indexes) + guarded drops

**Files:** Create `admin/src/pages/collections/[name]/index.vue`, `pages/collections/components/FieldEditor.vue`, `DropConfirmModal.vue`; Test `admin/src/__tests__/collectionsFieldEditor.spec.ts`.

**Context:** edit page lists fields/indexes; add-field/drop-field/add-index/drop-index call the Task-11 mutations. Drops use `DropConfirmModal` (type-name confirm + data-loss ack; empty-table light path skips confirm). Mirror `content-types/[slug].vue` + `FieldEditor.vue`.

- [ ] **Step 1 — failing test:** adding a field emits the create-mutation payload; the drop modal requires the typed name before enabling confirm (assert via `data-test`).
- [ ] **Step 2 — run, fail.** **Step 3 — implement.** **Step 4 — `pnpm test` + `type-check`; green.**
- [ ] **Commit (when authorized):** `admin: collections schema builder — edit + guarded drops`

---

### Task 14: Data browser — list + create/edit drawer + delete

**Files:** Create `admin/src/pages/collections/[name]/data/index.vue`, `pages/collections/components/RowDrawer.vue`; Test `admin/src/__tests__/collectionsData.spec.ts`.

**Context:** clone `pages/content/[type]/index.vue` (UTable + debounced search + `TablePagination` + delete modal) and a create/edit **drawer** (`USlideover`/`UModal` + `UForm`+zod; fields rendered from the collection's types via `src/fields/components/`). Restrict-delete (409) → `notifyError`. Columns from the definition.

- [ ] **Step 1 — failing test:** mount with a mocked `useCollectionRows()` (one page) + the definition; rows render via `[data-test="row"]`; pagination wires to `TablePagination`; the new-row button opens `[data-test="row-drawer"]`.
- [ ] **Step 2 — run, fail.** **Step 3 — implement.** **Step 4 — `pnpm test` + `type-check`; green.**
- [ ] **Commit (when authorized):** `admin: collections data browser — list + drawer + delete`

---

### Task 15: Permissions panel — per-collection API-key scopes

**Files:** Create `admin/src/pages/collections/[name]/components/ScopesPanel.vue` (or a schema-page section); Test `admin/src/__tests__/collectionsScopes.spec.ts`.

**Context:** per spec §9, basic. Composes `collections.{name}.{read|write|delete}` and drives the **existing** api-key scope-update endpoint verified/added in Task 7. **No collections-specific key API.**

- [ ] **Step 1 — failing test:** toggling a scope calls the api-key scope-update mutation with `collections.{name}.read` (assert via the mocked mutation).
- [ ] **Step 2 — run, fail.** **Step 3 — implement** against the Task-7 endpoint. **Step 4 — `pnpm test` + `type-check`; green.**
- [ ] **Commit (when authorized):** `admin: per-collection API-key scopes panel`

---

### Task 16: SPA polish + whole-feature check

- [ ] **Step 1** — loading/empty/error states on every page (`#empty` slots, pending spinners); `oxlint`/`oxfmt` clean.
- [ ] **Step 2** — `pnpm build` (vue-tsc --build + vite build) green; `pnpm test` green.
- [ ] **Step 3** — manual gating sanity: disabled → nav hides + routes guard-redirect; enabled → full flow (create collection → add field → add row → delete) works against the vendor-patched backend.
- [ ] **Commit (when authorized):** `admin: collections module polish + states`

---

## Plan Self-Review

- **Boundary integrity:** the pack imports **no** `glueful/audit` / `App\*` — Task 2 only adds event payload; audit lives in the App (Task 3). `composer boundaries` asserted (Task 9).
- **Spec coverage:** §9 schema builder → 5,12,13; data browser → 6,14; per-collection scopes → 7(+verify),15; capability-gated module → 8,10. §2 permission split → 4 + the triple-gated routes (8). Findings: ActorResolver → 1; audit → 2+3; `CollectionScopeMiddleware` correctly untouched.
- **Sequencing:** api-key scope verify/endpoint (7) precedes OpenAPI regen (9) and all SPA tasks — so a missing endpoint can't strand Phase B.
- **Commit discipline:** every commit step is `Commit (when authorized)`; nothing commits without the human's go-ahead.
- **Type consistency:** `Actor`/`CollectionDefinition`/`ListResult` and the manager/repo/compiler signatures are reused from Plan 1; the only signature change is `RowRepository::delete(+Actor)` (Task 2), threaded through callers.

**Definition of done:** an admin with `collections.schema.manage`/`collections.data.manage` can, from the SPA, create a collection, evolve its fields/indexes with guarded drops, browse/create/edit/delete rows, and manage per-collection key scopes — all gated on `lemma.collections`, with row CRUD recorded in the audit log (App-side) and attributed to the admin actor, and the pack still depending only on framework + contracts.
