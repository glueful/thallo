# Public Account Surface — conditional chrome + redirects + settings

> **For agentic workers:** implement task-by-task with red-green TDD. Steps use `- [ ]` tracking.

**Goal:** Reposition `/account` as the site's general **public identity surface** (commerce/subscriptions/etc. layer sections on top). Replace the bespoke `account-link` block with a reusable **`auth-state`** conditional block, add operator-configurable **post-login / post-logout redirects** (with a validated `?next=`), and a **`settings/accounts`** admin page.

**Architecture:** A cache-safe conditional block (two child slots, `signed_out` visible / `signed_in` hidden) toggled by a minimal `/_account/session` read. Redirects flow through one `AccountReturnPath` validator. Config lives behind a pack-owned `AccountSettingsStore` interface the app bridges to `SettingsStore`.

**Tech stack:** PHP 8.3+, glueful/framework ^1.74.1, Twig, ThalloRuntime, PHPUnit 10, Vue 3 + Nuxt UI (admin SPA), node (runtime harness).

## Global Constraints (from review — binding on every task)

- **Presentation only.** `auth-state` is chrome, never an authorization boundary. Both branches ship in the shared/cached HTML, so `signed_in` must contain **no** sensitive data, no privileged actions, no per-visitor secrets. Real auth stays server-side on the `/account/*` routes.
- **Restricted child types.** Slots accept only passive, cache-safe blocks (`button`, `links`, `rich_text`, `logo`, `navigation`). Existing `block_types` metadata is picker-only by design, so Task 2 adds an opt-in `enforce_block_types: true` field-schema flag and enforces it in the shared `FieldValidator`; the default stays picker-only for every existing field. Both entry and region writes must reject a crafted disallowed child. No block that self-hydrates or fetches.
- **Fail closed.** `signed_out` is visible without JS; `signed_in` starts `hidden inert`. Hydration adds **both** attributes to the inactive branch before removing both from the active branch in the same synchronous turn. Any failure (non-200, malformed envelope, fetch reject) leaves the signed-out state.
- **One session request per document.** `account.js` coalesces a single `/_account/session` fetch and applies the result to **every** `auth-state` instance. Exactly-once runtime registration; a second evaluation is inert.
- **Minimal session response.** `/_account/session` returns `{ authenticated: bool }` only. `display_name`/`links` are removed (no confirmed consumer — the dashboard reads the `user` attribute server-side; `AccountNavigationRegistry` stays for the dashboard).
- **`auth-state` only — no generic engine.** Do not build a general conditional/rules engine. A second real condition (front-end groups) justifies that abstraction later.
- **One return-path authority.** `AccountReturnPath` validates BOTH stored settings and `?next=`: allow a normalized application-relative path with exactly one leading `/`; reject `//`, absolute URLs, any scheme, backslashes, control chars, and encoded bypasses (`%2f`, `%5c`, `%00`, …). Revalidate on POST. Precedence: valid `next` → configured default → fixed fallback. Emit **303** after login/logout.
- **Tenant posture.** Identity remains global, but redirect settings are workspace-owned. Every `/account/*` page/mutation that reads redirect settings carries `tenant_profile:public` + `tenant_bootstrap` instead of `tenant_system`, matching Render/Commerce public routes; tenancy-off remains the `''` sentinel. Asset delivery stays `tenant_system`; `/_account/session` may stay system-global because it returns only a credential-derived boolean and reads no tenant data.
- **Package boundary.** `AccountSettingsStore` lives in `packages/thallo-account/src/Settings/` (not `thallo-contracts`): the pack owns the interface and the app binds it to a `SettingsStore`-backed implementation, mirroring `CommerceSettingsStore`. It exposes reads plus one atomic save/clear operation used by the admin controller. The pack never imports `App\Settings`.
- **Admin posture.** `/settings/accounts` is gated by capability `thallo.accounts` + admin auth + `content.manage`. Values are tenant-scoped and pass through `AccountReturnPath` on write.
- **Logout stays on `/account`.** The cacheable block never embeds a session-bound CSRF token or a logout form; the signed-in slot links to `/account`, where the real logout lives.
- **Commit cadence:** commit on `dev`, never push, no AI attribution, hold this plan doc uncommitted. Gates per commit: `vendor/bin/phpunit` + `vendor/bin/phpcs`.

---

## Task 1: Retire `account-link`

**Files:** modify `packages/thallo-account/src/Blocks/AccountBlockTypesContributor.php`, `app/Content/Regions/RegionDefinitions.php`, `packages/thallo-account/src/AccountServiceProvider.php` (docblocks) and `packages/thallo-account/routes.php` (docblock), `app/Providers/ThalloServiceProvider.php` (command registration), `app/Content/Starter/StarterProvenanceRepository.php` (add `deleteBySource`); delete `packages/thallo-account/templates/blocks/account-link.twig`; add `app/Content/Console/RetireAccountLinkCommand.php` (**app, not the pack** — `scripts/check-pack-boundaries.php`, enforced by `Commerce/InertnessTest`, forbids any `App\` reference in pack sources, and the command orchestrates `App\Content\*` repos); test in `tests/Integration/Account/AccountCacheIsolationTest.php` plus `tests/Integration/Account/RetireAccountLinkCommandTest.php`.

Removing the contributor definition does **not** delete the already-synced `block_types` row or placed instances — and I just seeded `account-link` and one was placed in the header. So this task must physically retire it (pre-launch → delete, don't migrate):

- [ ] Drop the `account-link` definition from `AccountBlockTypesContributor` and remove `'account-link'` from the header/footer palettes in `RegionDefinitions`.
- [ ] Delete `templates/blocks/account-link.twig`.
- [ ] Add one concrete, idempotent pre-launch command, `thallo:account:retire-account-link`, registered as an **app** command in `ThalloServiceProvider` (alongside `SyncBlockTypesCommand`) — unconditionally available even on a boot where `thallo.accounts` is disabled (app commands are never capability-gated). For every tenant, run one transaction under `TenantContextRunner`: use `BlockUsageScanner::usage('account-link')` to fail closed if any current draft or pinned publication contains `account-link` (**scope note:** `BlockUsageScanner` scans entry drafts/publications only — it never scans regions); **independently** strip top-level `account-link` instances from every region's `regions.blocks` (via `RegionRepository`) while preserving order and every other block, then re-read those regions to confirm the slug is gone — region coverage is this direct sweep, not the entry scan; delete the now-unused `block_types` row; then delete the matching `starter_provenance` row (`definition_kind=block_type`, `source_id=thallo-account:account-link`) — `StarterProvenanceRepository` has no delete method today, so add `deleteBySource(string $kind, string $sourceId): void` and call it rather than issuing raw SQL. A missing block type/provenance/instance is an idempotent no-op. Do not rewrite immutable `entry_versions`, and do not ship a catch-all content-deletion migration.
- [ ] Run the command against the current development data before deleting the template/definition. Its expected preflight is exactly the placed header instance and zero entry usage; any additional entry usage stops for operator review.
- [ ] Test: seed a header with surrounding blocks plus the block-type and provenance rows, run twice, and assert surrounding order is unchanged, the slug/provenance/instance are absent, and a fresh `block_type` sync neither recreates the slug nor reports `orphaned_source`. A separate test seeds an entry use and proves the command rolls back everything.

---

## Task 2: The `auth-state` conditional block + minimal session endpoint

**Files:** `AccountBlockTypesContributor.php` (add `auth-state`), `templates/blocks/auth-state.twig` (new), `packages/thallo-account/assets/account.js` (rewrite as the auth-state hydrator), `packages/thallo-account/src/Http/AccountSessionController.php` (slim), `RegionDefinitions.php` (palette), `AccountServiceProvider.php` (registration); add the opt-in field flag through `app/Content/Schema/FieldDefinition.php`, `app/Content/Schema/ContentTypeSchema.php`, `app/Content/Http/DTOs/FieldDefinitionData.php`, `app/Content/Http/DTOs/Responses/ContentTypes/FieldSchemaData.php`, and `app/Content/Validation/FieldValidator.php`; tests in `AccountCacheIsolationTest.php`, `tests/Unit/Content/BlocksFieldSchemaTest.php`, and entry/region validation integration tests.

**Interfaces / shapes:**

- **Block schema** — two slots, allowlisted, no template-path field:
  ```php
  new StarterBlockTypeDefinition(
      sourceId: 'thallo-account:auth-state', slug: 'auth-state',
      label: 'Account state', icon: 'i-lucide-user-round', category: 'Account',
      description: 'Shows one set of blocks to signed-out visitors and another to signed-in ones.',
      schema: [
          [
              'name' => 'signed_out', 'type' => 'blocks',
              'block_types' => ['button','links','rich_text','logo','navigation'],
              'enforce_block_types' => true,
          ],
          [
              'name' => 'signed_in', 'type' => 'blocks',
              'block_types' => ['button','links','rich_text','logo','navigation'],
              'enforce_block_types' => true,
          ],
      ],
  )
  ```
- **`templates/blocks/auth-state.twig`** — both slots render server-side; NO `display` CSS on the wrappers so the UA `[hidden]` rule governs visibility (removing the attribute reveals it); the child blocks carry their own styling, so the block needs no stylesheet:
  ```twig
  <div class="thallo-block thallo-block-auth-state" data-auth-state>
    <div data-auth-when="anonymous">{{ blocks(data.signed_out) }}</div>
    <div data-auth-when="authenticated" hidden inert>{{ blocks(data.signed_in) }}</div>
  </div>
  <script src="/_account/assets/account.js" defer></script>
  ```
- **Field-schema enforcement** — add `FieldDefinition::$enforceBlockTypes` / raw key `enforce_block_types`, valid only on `blocks` fields and defaulting to `false`. Preserve it through request DTOs, normalized schema output and OpenAPI response shape. Pass the current field into `validateBlocks()`; when the flag is true, reject every child whose `type` is absent from that field's `blockTypes` before recursing. Preserve and test the existing picker-only behavior when false. Because both entry validation and `RegionValidator` use this same `FieldValidator`, one rule closes both write paths without teaching app core about the optional `auth-state` slug.
- **`account.js`** — ThalloRuntime module `auth-state` (selector `[data-auth-state]`); coalesce ONE `/_account/session` fetch per document (a module-level promise), apply to every instance: on `data.authenticated === true`, add `hidden` + `inert` to `[data-auth-when="anonymous"]`, then remove both from `[data-auth-when="authenticated"]` in the same synchronous turn; otherwise leave the server-rendered attributes untouched. Keep the exactly-once `window.thalloAccount` guard + the catch-up enhance pass. Fail closed on any error.
- **`AccountSessionController::show`** — return `Response::success(['authenticated' => $authenticated])` + `private, no-store`. Drop `display_name`/`links`.

- [ ] Update `AccountCacheIsolationTest`: session returns `{authenticated}` (drop the `display_name` assertions); the `auth-state` block renders both slots with `[data-auth-when]` hooks, correct `hidden`/`inert` defaults, and links `account.js`; capability off → no chrome + `/_account/session` 404; node harness proves one-fetch coalescing, both-attribute swap across multiple instances, and fail-closed (signed-out stays).
- [ ] Drive crafted payloads through the real entry-draft and region-save APIs: an allowed child saves, a disallowed/self-hydrating child returns 422 at the precise slot path, and an unrelated existing blocks field with the same picker metadata but no enforcement flag still accepts an out-of-list existing type.
- [ ] Add `'auth-state'` to the header/footer palettes; register the block type in `boot()` (idempotent, as before).

---

## Task 3: `AccountReturnPath` + configurable redirects

**Files:** `packages/thallo-account/src/AccountReturnPath.php` (new), `packages/thallo-account/src/Settings/AccountSettingsStore.php` (new interface), app-side `app/Settings/AccountSettingsBridge.php` bound in `ThalloServiceProvider` (bridge to `SettingsStore`), `AccountServiceProvider.php` (service wiring), `packages/thallo-account/routes.php` (tenant posture), `AccountPageController.php` (login GET), `AccountAuthController.php` (login/error/logout), `templates/account/login.twig` (`next` hidden field); tests in `tests/Unit/Account/AccountReturnPathTest.php` + `AccountFlowTest.php` + a tenancy-enabled redirect test.

- **`AccountReturnPath`** — `validate(string $candidate): ?string` (null when unsafe) enforcing the Global-Constraints ruleset; `resolve(?string $next, ?string $configured, string $fallback): string` independently revalidates both candidates before implementing precedence. Pure, no deps → unit-testable.
- **`AccountSettingsStore`** (pack interface): `afterLogin(): ?string`, `afterLogout(): ?string`, and `saveRedirects(?string $afterLogin, ?string $afterLogout): void`. The app bridge maps these to tenant-owned `SettingsStore` keys `account.redirect.after_login` / `account.redirect.after_logout`; `null` clears the row via `forget()`. Inject `ApplicationContext` into the bridge and wrap both `SettingsStore::putMany()`/`forget()` operations in one `db($context)->transaction(...)`, so the interface's one save call cannot land a partial pair.
- **Route posture** — replace `tenant_system` with `tenant_profile:public` then `tenant_bootstrap` at the **front** of each account page/form/dashboard/logout middleware list, before same-origin/CSRF code that may resolve the workspace's public origin. Keep the asset route and minimal session endpoint system-global. Extend the tenancy route-inventory and acceptance tests to pin this split, and prove tenant A/B resolve different configured redirects while tenancy-off still uses the sentinel.
- **`AccountPageController::loginPage()`**: validate the GET `?next=` and pass only the accepted value to Twig. Do not resolve the configured fallback on GET; POST remains authoritative.
- **`login()`**: validate the posted `next` again, preserve that validated value in both invalid-credentials and two-factor re-renders, then `resolve(next, settings->afterLogin(), '/account')`; issue the cookie onto a **303** redirect only after authentication succeeds.
- **`logout()`**: validate posted `next`, resolve it against `afterLogout()` and `/account/login`, and pass that 303 response into `SessionLogout::logout()`. If `result->revoked` is true, return its cookie-cleared redirect. If false, log the security-relevant failure and return a cookie-cleared 500 response; never report a successful redirect while the server session may remain live.
- **`login.twig`**: `<input type="hidden" name="next" value="{{ next|default('') }}">`; because the page controller and both error branches provide only a validated value, hostile input is never reflected into the form.

- [ ] Unit-test `AccountReturnPath` against the bypass corpus (`//evil`, `https://evil`, `javascript:`, `/\evil`, `%2f%2fevil`, `%5c`, `%00`, leading whitespace/control chars) → all rejected; plain `/account/orders` → accepted; precedence order verified.
- [ ] `AccountFlowTest`: GET carries a safe `next` into the form, omits a hostile one, failed login/2FA preserve only a safe value, POST revalidation catches a tampered hidden field, precedence is correct, and successful login/logout are 303.
- [ ] Add a logout-revocation-failure test: `revoked=false` returns 500, logs, and still expires both cookies; no 303 success is exposed.
- [ ] Under enforcement, bind two workspace contexts with different redirect settings and prove the same account controller resolves each correctly; no tenant context must fail closed rather than reading another workspace's row.

---

## Task 4: `settings/accounts` admin page

**Files:** `packages/thallo-account/src/Http/AccountSettingsController.php` (new), `packages/thallo-account/routes/admin-routes.php` (new), `AccountServiceProvider.php` (service + capability-gated route load), app bridge binding, `admin/src/pages/settings/accounts/` (new SPA page), `admin/src/queries/accountSettings.ts`, `admin/src/registry/accountModule.ts`, `admin/src/registry/adminModules.ts`, `admin/src/registry/coreModule.ts`, `admin/src/registry/manifest.ts`, and focused backend/SPA tests.

- **Server registration** — keep the controller in `thallo-account`, consuming only `AccountSettingsStore` + `AccountReturnPath`. Load `routes/admin-routes.php` beside the public route file only inside the existing `thallo.accounts` gate. The route group is `/v1/admin/settings/accounts` with `['auth','tenant_profile:admin','tenant_bootstrap','admin_tenant_binding']`, then `content_permission:content.manage` on GET and PUT. Capability off therefore removes the route at boot (404), rather than relying on an app route that always exists.
- **`AccountSettingsController`** (mirror `GeneralSettingsController`): `GET` returns a fixed, allowlisted account-page inventory plus current redirect values; `PUT` normalizes blank to null, validates each non-null redirect through `AccountReturnPath`, and calls `saveRedirects()` once. Add typed request/response DTOs and OpenAPI assertions; never expose arbitrary router inventory.
- **Admin navigation seam** — add an optional `settings?: NavigationMenuItem[]` contribution to `AdminModuleNav` and a private `contributionSlot: 'settings'` marker on the core Settings parent. Keep that parent in its current `main` position with all nine existing children. `visibleNav()` gathers `settings` items only from visible modules, clones the marked parent, appends those items after the core children, strips the private marker, and never mutates the static manifest (repeat-call determinism test). Add `accountModule` with `requires: ['thallo.accounts']` and one settings contribution `{label:'Accounts', to:'/settings/accounts'}`; register it in `adminManifest`. This makes the entry disappear with the capability without moving/duplicating Settings. The page route also declares `requiresCapability: thallo.accounts`, so direct SPA navigation is guarded by verified capability state rather than sidebar visibility.
- **SPA page** (mirror `settings/general` / `settings/signup`): a read-only list of the account pages with links, and two inputs for after-login / after-logout redirects with client validation mirroring `AccountReturnPath` (server remains the authority). Setup-store Pinia, `UForm` + zod, no `UAuthForm`.

- [ ] Backend route-inventory/auth-matrix test: capability off → route absent/404; enabled unauthenticated → 401; authenticated without `content.manage` → 403; selected tenant is bound before reads/writes; hostile redirects 422 without mutation; valid and cleared values are tenant-isolated.
- [ ] Navigation tests: the original core Settings parent and children retain their positions, Accounts appears once at the end of that same group only when `thallo.accounts` is visible, two consecutive `visibleNav()` calls do not accumulate it, no private slot marker reaches rendered navigation, and direct page navigation requires the verified capability.
- [ ] SPA test (vitest): renders the allowlisted page inventory, saves valid redirects, clears an override, and surfaces a validation error — asserting `data-test` hooks, not portal DOM.

---

## Not in scope (deferred, agreed)

- Redirect/behavior **by front-end group/role** — waits for a real groups concept and a second consumer; only then generalize `auth-state` toward a conditional engine.
- Softening the internal `CustomerSignupService` / `customer` kind naming — the external surface is already generic; cosmetic, revisit if it bothers us.
- Showing the visitor's **name** in the header — would reintroduce `display_name` to the session response; add only when a confirmed consumer needs it.
