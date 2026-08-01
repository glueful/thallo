# Admin SPA Commerce Area — Implementation Plan (Slice 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Full commerce admin area in the Thallo SPA — Commerce's 98-endpoint admin API re-mounted at `/v1/admin/commerce` via a Commerce-owned route catalog, `commerce.view`/`commerce.manage` authorization, and phased SPA domains (Products+Linking → Orders → the rest).

**Architecture:** Commerce 1.4.0 gains `AdminRouteCatalog` + `AdminMountProfile`; Commerce mounts it natively (route-parity-proven), thallo-commerce mounts an explicit fail-closed allowlist at `/v1/admin/commerce` behind session auth + workspace binding + a new any-of permission gate. The SPA consumes it through the existing typed client/Colada/registry patterns.

**Tech Stack:** PHP 8.3 (Glueful framework 1.71.2, glueful/commerce → 1.4.0), Vue 3 + Nuxt UI + @pinia/colada + openapi-fetch, vitest, PHPUnit.

**Spec:** `docs/superpowers/specs/commerce/2026-07-22-admin-commerce-area-design.md` — binding; on conflict the spec wins.

**Execution shape:** 31 bounded tasks/subtasks across the same eight phases. The lettered subtasks
split broad SPA domains for TDD/reviewability; they do not create additional spec or release cycles.

## Global Constraints

- Thallo mount allowlist is **explicit and fail-closed** (`all` allowed ONLY for Commerce's native mount).
- Middleware order pinned: `auth → tenant_profile:admin → tenant_bootstrap → admin_tenant_binding → <permission gate>`.
- Authorization modes explicit per catalog entry (`view`|`manage`), never inferred from HTTP method. Permission implications are declarative catalog data (`commerce.manage → commerce.view`) consumed by one reusable `PermissionRequirementAuthority`; neither middleware nor controllers hardcode Commerce implications.
- API-key authorization is evaluated per required candidate after implication expansion: `exists required P where any expanded RBAC grant satisfies P AND any expanded key scope satisfies P`. JWT omits only the key-scope term. Unrelated alternatives can never cross-match; empty scopes deny; wildcard semantics stay unchanged.
- `ShopUrlGenerator` = sole storefront **path** authority (relative, `/`-prefixed); `CanonicalPublicOriginResolver` = sole **origin** authority (`scheme://host`, no trailing slash, never trusts Host). Absolute URLs assembled ONLY by `StorefrontPreviewUrlBuilder`; the SPA never concatenates origins/prefixes/slugs.
- Money: integer minor units; format via `currency` + `currency_exponent` with string decimal placement — no float arithmetic.
- Marketplace routes (34, `/commerce/admin/marketplace/*` + seller-order fulfill) NEVER enter the catalog.
- SPA nav children appear only when a domain's pages are complete; editor Commerce tab withheld until the meta query settles (loading ≠ enabled).
- Commits: thallo work commits on thallo dev; commerce work commits on commerce dev (`/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`). **Never commit** `config/extensions.php`, `docs/superpowers/**`. Commit only — never push. No AI attribution anywhere.
- Tests: thallo `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit <path>`; SPA `cd admin && pnpm test && pnpm type-check`; commerce `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/commerce && vendor/bin/phpunit`.
- During development thallo consumes commerce via its vendored copy — mirror commerce-repo edits into `thallo/vendor/glueful/commerce/` (vendor is gitignored); the P8 release repins properly.

---

## Phase P1 — Commerce: catalog + native re-mount

### Task 1 ✅ COMPLETE (commerce b4766a5): `AdminRouteCatalog` + `AdminMountProfile` + 98 entries

**Files:**
- Create: `src/Http/Routing/AdminRouteEntry.php`, `src/Http/Routing/AdminMountProfile.php`, `src/Http/Routing/AdminRouteCatalog.php` (commerce repo)
- Test: `tests/Unit/Http/Routing/AdminRouteCatalogTest.php`

**Interfaces (Produces):**
```php
final class AdminRouteEntry {
    public function __construct(
        public readonly string $key,          // 'products.index'
        public readonly string $method,       // 'GET'
        public readonly string $path,         // '/products' (prefix-relative)
        public readonly string $controller,   // AdminProductController::class
        public readonly string $action,       // 'index'
        public readonly string $mode,         // 'view'|'manage'
        public readonly string $kind,         // 'json'|'bulk'|'binary'|'unusual'
        public readonly string $domain,       // 'products'|'orders'|...
    ) {}
}
final class AdminMountProfile {
    private function __construct(/* prefix, routeNamePrefix, middleware, modeMiddleware, coverage */) {}
    /** The only API that selects the complete native catalog. */
    public static function native(string $prefix, array $middleware, array $modeMiddleware): self;
    /** @param non-empty-list<string> $allowlist; rejects an empty list and unknown keys at mount. */
    public static function restricted(string $prefix, string $routeNamePrefix, array $middleware, array $modeMiddleware, array $allowlist): self;
}
final class AdminRouteCatalog {
    /** @return list<AdminRouteEntry> */ public static function entries(): array;
    public static function mount(\Glueful\Routing\Router $router, AdminMountProfile $profile): void;
}
```

- [ ] **Step 1: Write failing unit tests** — `AdminRouteCatalogTest`:
  - `testEveryEntryHasUniqueNonEmptyKey` — collect keys, assert `count === count(array_unique)`, none empty.
  - `testEveryEntryHasExplicitValidMetadata` — each `mode ∈ {view,manage}`, `kind ∈ {json,bulk,binary,unusual}`, `domain` non-empty from the closed set `{products, taxonomy, inventory, downloads, customers, discounts, orders, reviews, shipping, tax, reports}`.
  - `testNoMarketplaceEntryEnters` — no key/path contains `marketplace` or `seller-orders`.
  - `testEntryCountIs98`.
  - `testRestrictedProfileRejectsAnEmptyAllowlist`; only `AdminMountProfile::native()` can represent complete-catalog coverage.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Unit/Http/Routing/AdminRouteCatalogTest.php` — FAIL (class not found).
- [ ] **Step 3: Implement.** `entries()` returns the 98 entries in the exact order of the current `routes.php` admin group (the authoritative table is that file, lines ~129–263). Key naming: `<domain-noun>.<action>` mirroring path semantics, e.g. `products.index/store/show/update/destroy`, `products.variants.store`, `variants.update`, `products.children.set`, `products.bulk_status`, `variants.bulk_price`, `variants.downloads.index/attach`, `downloads.update/detach`, `grants.revoke`, `grants.refund_override.set/clear`, `customers.index/show`, `products.media.attach`, `products.media.reorder`, `media.update/detach`, `categories.*` (+`products.categories.set`), `tags.*` (+`products.tags.set`), `attributes.*` (+`attributes.values.store`, `attribute_values.update/destroy`, `products.attributes.set`), `products.addons.index/store`, `addons.update/destroy`, `stock.adjust`, `discounts.*`, `orders.index/show/cancel/mark_paid/fulfill`, `orders.refunds.store/index`, `orders.notes.store/index`, `orders.invoice_data`, `refunds.list/show`, `reviews.index/show/store/approve/spam/destroy/bulk`, `shipping.zones.*` (+`shipping.zones.locations.set`, `shipping.zones.methods.index/store`, `shipping.methods.show/update/destroy`), `shipping.classes.*`, `tax.rates.*`, `reports.sales/products/customers/stock`. Modes: exactly the current `require_scope` mapping — `commerce:read` routes → `view`, `commerce:write` → `manage` (declared literally per entry, not computed). Kinds: `bulk` for `products.bulk_status`, `variants.bulk_price`, `reviews.bulk`; `unusual` for `products.children.set`, `products.media.reorder`, `grants.*`, `stock.adjust`, `orders.cancel/mark_paid/fulfill`, `orders.invoice_data`, the three `*.set` taxonomy routes, `shipping.zones.locations.set`; `json` otherwise (no `binary` endpoints exist — the constant stays for the contract). `mount()` consumes the profile's private coverage selection, throws on unknown allowlist keys, and registers each selected entry via `$r->{strtolower($method)}(...)`; names are applied only for the restricted Thallo profile. Callers cannot express an accidental `null = all` profile.
- [ ] **Step 4: Run tests** — PASS.
- [ ] **Step 5: Commit** (commerce repo): `feat(admin): AdminRouteCatalog + AdminMountProfile — declarative, mountable admin route inventory`

### Task 2 ✅ COMPLETE: Native re-mount + legacy route-parity fixture

**Files:**
- Create: `tests/fixtures/admin_route_inventory_1_3.json` (generated BEFORE refactor), `tests/Integration/Http/AdminRouteMountParityTest.php`
- Modify: `routes.php` (replace the 98 hand-written admin-group routes with a catalog mount; marketplace group untouched)

**Interfaces:** Consumes Task 1. Produces: `routes.php` mounting `AdminRouteCatalog::mount($router, AdminMountProfile::native('/commerce/admin', array_merge(['auth'], $tenantMiddleware), ['view' => 'require_scope:commerce:read', 'manage' => 'require_scope:commerce:write']))`.

- [ ] **Step 1: Capture the legacy fixture FIRST (pre-refactor).** Write a small throwaway script (or a temporarily-@group'd test) that boots the commerce test app, walks the router's route collection for paths starting `/commerce/admin` **excluding** `/commerce/admin/marketplace` and the seller-order fulfill route, and dumps ordered records `{method, path, controller, action, middleware: [flattened strings], name: null}` to `tests/fixtures/admin_route_inventory_1_3.json`. Assert every legacy route really is unnamed and verify exactly 98 records. Commit the fixture (not the script).
- [ ] **Step 2: Write the failing parity test.** `AdminRouteMountParityTest`:
  - `testNativeMountEqualsLegacyInventory` — boot, collect the same route set, compare against the fixture record-by-record on method, path, controller/action, flattened middleware, and route name. The expected name is explicitly `null`, so accidental native naming fails parity.
  - `testMarketplaceGroupStillRegistersOnlyWhenFlagEnabled` — with `commerce.marketplace.enabled=true` config override, the marketplace routes exist; default config: absent.
- [ ] **Step 3: Refactor `routes.php`** — delete the 98 route declarations, mount the catalog with the native profile (exact profile above; `$tenantMiddleware` variable reused as today). Marketplace group and storefront/account groups untouched.
- [ ] **Step 4: Run** the parity test + the FULL commerce suite — all green (any diff = a transcription bug in Task 1's entries; fix the catalog, never the fixture).
- [ ] **Step 5: Commit**: `refactor(routes): mount the admin surface from AdminRouteCatalog — legacy route parity proven by fixture`

---

## Phase P2 — Thallo: mount, permissions, preview URLs, meta, OpenAPI

### Task 3 ✅ COMPLETE (thallo 40ad379): Reusable permission requirements + `RequirePermission` any-of candidates

**Files:**
- Create: `app/Content/Authorization/PermissionRequirementAuthority.php`, `app/Content/Authorization/PermissionImplicationSource.php`
- Modify: `app/Content/Http/RequirePermission.php`, `app/Providers/ThalloServiceProvider.php`
- Test: `tests/Unit/Content/Http/RequirePermissionAnyOfTest.php` (new)

**Interfaces (Produces):** `PermissionRequirementAuthority::allows(Request $request, list<string> $requirements): bool`. `RequirePermission` becomes a thin parser/HTTP adapter over it. The router already comma-splits `content_permission:a,b` into multiple middleware params.

For each required permission `P`, the authority obtains its declarative satisfier closure from a
`PermissionImplicationSource`. The constructor accepts a nullable source and defaults to identity
(`satisfiers(P) = [P]`) so this task is independently green; tests inject a small fake implication
source. Task 4 makes `CapabilityCatalog` the production source and adds
`commerce.manage` as a satisfier of `commerce.view`. Contract:

```text
JWT:     allowed = exists required P where any live RBAC grant in satisfiers(P) is allowed
API key: allowed = exists required P where
           any live RBAC grant in satisfiers(P) is allowed
           AND any key scope in satisfiers(P) is allowed
```

Both factors must satisfy the same required `P`; their concrete satisfier may differ only when both grants legitimately imply `P` (for example manage-role + view-scoped key on a view requirement). Empty requirements or empty key scopes deny.

- [ ] **Step 1: Failing tests.** Cases (mock `PermissionAuthority`/resolver per the file's existing test seams — see how existing RequirePermission tests construct it; if none exist, construct directly with stub implementations):
  - JWT, requirement `[view]`, RBAC grants only `manage` through a test implication → allowed.
  - JWT, rbac grants neither → 403.
  - API key with scope `view` + RBAC grant `manage` → allowed for required `view`; the inverse (`manage` scope + `view` RBAC) also allows required `view` after implication expansion.
  - API key with view scope/RBAC only → denied for required `manage`.
  - **Cross-match rejection:** for unrelated alternatives `[A,B]`, API-key scope satisfying only A + RBAC satisfying only B → 403.
  - API key with empty scopes → 403 regardless of rbac.
  - Wildcard scope `commerce.*` satisfies both candidates (existing `scopeSatisfies` fnmatch semantics).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement the authority.** Move principal resolution, API-key scope enforcement, non-tenant `PermissionAuthority::can`, tenant `EffectiveRoleMatrix`, and operator-bypass evaluation out of `RequirePermission` into `PermissionRequirementAuthority`. It expands `PermissionImplicationSource::satisfiersFor($required)` before evaluating the formula above; null source means identity only. Preserve all existing fail-closed branches and resource derivation (`locale:<code>` vs `thallo`). Bind the authority once in `ThalloServiceProvider`; Task 4 replaces the identity fallback with the catalog source, and Task 8 injects this exact service into `/meta`.
- [ ] **Step 4: Make middleware parsing type-safe.** Iterate `$params`; ignore non-strings, trim only strings, discard empty values, and pass the resulting list to the authority. Do **not** call `trim` through `array_map` before filtering mixed values. Existing one-candidate routes remain byte-identical.
- [ ] **Step 5: Run new tests + `vendor/bin/phpunit tests/Unit/Content` + `tests/Integration/Tenancy` (matrix/bypass regressions)** — green.
- [ ] **Step 6: Commit**: `feat(authz): reusable permission requirement authority with implication-safe scope∩RBAC evaluation`

### Task 4 ✅ COMPLETE (thallo d609935): `commerce.view` across catalog / matrix / seed / policy-hash

**Files:**
- Modify: `app/Content/Authorization/CapabilityCatalog.php` (implements `PermissionImplicationSource`), `app/Providers/ThalloServiceProvider.php` (inject catalog source), `config/tenancy.php` (role_matrix), `packages/thallo-commerce/migrations/002_SeedCommercePermissions.php`
- Test: extend `tests/Unit/Tenancy/Authorization/CapabilityCatalogTest.php`; migration assertions in `tests/Integration/Commerce/PackSkeletonTest.php`

- [ ] **Step 1: Failing tests.** CapabilityCatalogTest: assert `has('commerce.view')`, label of `commerce.manage` === `'Manage commerce'`, `satisfiersFor('commerce.view') === ['commerce.view', 'commerce.manage']`, `satisfiersFor('commerce.manage') === ['commerce.manage']`, implication cycles/unknown targets are rejected, and the existing matrix↔catalog consistency test stays green. PackSkeletonTest: after migrations, `permissions` contains `commerce.view` (`View commerce`) and `commerce.manage` (`Manage commerce`), both `category='commerce'`, `is_system=true`.
- [ ] **Step 2: Implement.** Extend the catalog's declared entry vocabulary with an `implies` list (empty by default); `commerce.manage` declares `implies: ['commerce.view']`. Add deterministic, cycle-checked `satisfiersFor(string $required)` returning the required permission plus catalog grants whose transitive implication closure contains it. Add `commerce.view`, rename manage's label, and keep implication data inside the policy-hash payload so changes invalidate effective-policy caches. `config/tenancy.php` role matrix grants both permissions to owner/admin, neither to member/viewer. Fold the seed migration to insert `commerce.view` and idempotently rename `commerce.manage` for already-migrated dev DBs.
- [ ] **Step 3: Run** CapabilityCatalogTest + PolicyManifestTest (hash changes are EXPECTED — the tests assert determinism/validation, not a pinned hash; if any test pins a literal hash, update it consciously) + PackSkeletonTest. Green.
- [ ] **Step 4: Sync the LOCAL dev DB manually** (fold rule): insert `commerce.view` + rename `commerce.manage` name in the dev `lemma` DB `permissions` table — report the SQL executed.
- [ ] **Step 5: Commit** (thallo): `feat(authz): commerce.view permission + Manage commerce rename across catalog, matrix, seed, policy hash`

### Task 5 ✅ COMPLETE (thallo 5549000): `StorefrontPreviewUrlBuilder`

**Files:**
- Create: `packages/thallo-commerce/src/Shop/StorefrontPreviewUrlBuilder.php`
- Modify: `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (services() binding + use import — short names per convention)
- Test: `tests/Integration/Commerce/StorefrontPreviewUrlTest.php`

**Interfaces (Produces):**
```php
final class StorefrontPreviewUrlBuilder {
    public function __construct(private readonly CanonicalPublicOriginResolver $origins, private readonly ShopUrlGenerator $urls) {}
    public function shopIndexUrl(ApplicationContext $c): string;          // origin . urls->shopIndex()
    public function productUrl(ApplicationContext $c, string $slug): string; // origin . urls->product($slug)
}
```

- [ ] **Step 1: Failing tests.** (a) single-store mode (enforcement off): `productUrl` = `<app.urls.base origin>/shop/products/<slug>` — and is **byte-identical regardless of a hostile `Host: evil.test` request header** (drive two kernel requests differing only in Host; compare); (b) `shopIndexUrl` ends with `/shop` and starts `scheme://`; (c) slug is rawurlencoded (slug with space).
- [ ] **Step 2: Implement** (pure composition — resolver returns `scheme://host` no trailing slash; generator returns `/`-prefixed path; concatenate). Bind in `services()` as shared factory resolving `CanonicalPublicOriginResolver` + `ShopUrlGenerator` from the container.
- [ ] **Step 3: Run** — green. **Step 4: Commit**: `feat(commerce-pack): StorefrontPreviewUrlBuilder — canonical origin + shop path, sole absolute-URL composer`

### Task 6 ✅ COMPLETE (thallo 5705a47): Thallo mount with fail-closed allowlist + approved-inventory parity

**Files:**
- Create: `packages/thallo-commerce/src/Http/AdminMountAllowlist.php` (the explicit key list, all 98 initially), `tests/Integration/Commerce/AdminMountParityTest.php`, `tests/fixtures/commerce_admin_mount_inventory.json` (approved fixture: `[{key, mode}]` for all 98)
- Modify: `packages/thallo-commerce/routes/admin-routes.php`

- [ ] **Step 1: Failing parity test.** Three assertions: (a) fixture keys+modes == `AdminRouteCatalog::entries()` filtered to fixture keys (catalog drift detection: any catalog entry NOT in the fixture fails with its key printed — "new Commerce endpoint awaiting conscious approval"); (b) fixture == the live mounted `/v1/admin/commerce` route table (mount drift: every fixture key resolves to a registered route with method/path/controller matching the catalog and middleware ending in `content_permission:commerce.view,commerce.manage` (view mode) or `content_permission:commerce.manage` (manage mode)); (c) mounted route names are `thallo.commerce.admin.<key>` and globally unique.
- [ ] **Step 2: Implement.** `AdminMountAllowlist::keys(): array` returns the explicit 98-key list (readable, grouped by domain with comments). `admin-routes.php` appends after the existing link routes:
```php
AdminRouteCatalog::mount($router, AdminMountProfile::restricted(
    '/v1/admin/commerce',
    'thallo.commerce.admin.',
    ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ['view' => 'content_permission:commerce.view,commerce.manage', 'manage' => 'content_permission:commerce.manage'],
    AdminMountAllowlist::keys(),
));
```
(use imports for the commerce classes; place this mount outside the existing link-route group so
the `/v1/admin/commerce` prefix and base middleware are applied exactly once; the file itself already
loads only inside the capability gate).
- [ ] **Step 3: Run parity test + mount smoke** (extend the test: one representative endpoint per domain — e.g. `GET /v1/admin/commerce/products`, `GET .../orders`, `GET .../reports/sales` — through the real kernel as an admin-with-manage seeded user → 200 envelope) — green.
- [ ] **Step 4: Commit**: `feat(commerce-pack): mount the Commerce admin catalog at /v1/admin/commerce behind a fail-closed allowlist`

### Task 7 ✅ COMPLETE (thallo 619659f): Authorization matrix + slice-1 regrade + link projection + entry search

**Files:**
- Create: `packages/thallo-commerce/src/Links/EntryLinkSearch.php`
- Modify: `packages/thallo-commerce/routes/admin-routes.php` (regrade GETs; add entry-search route), `packages/thallo-commerce/src/Http/ProductLinkController.php` (projection + search action), `packages/thallo-commerce/src/Links/ProductLinkService.php` only if a read helper is missing, `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (search service binding)
- Test: `tests/Integration/Commerce/AdminAuthorizationMatrixTest.php` (new), extend `tests/Integration/Commerce/ProductLinkApiTest.php`

- [ ] **Step 1: Failing tests.**
  - Matrix (parameterized over one `view` route `GET /v1/admin/commerce/products` and one `manage` route `POST /v1/admin/commerce/products`): unauthenticated → 401; authed member of a DIFFERENT workspace → non-revealing (assert the admin_tenant_binding failure status quo — probe and pin actual code, 403/404); user with only `commerce.view` → GET 200, POST 403; only `commerce.manage` → GET 200 and a POST carrying a **valid create-product DTO** returns the normal success status (clean up the created product). API-key cases cover manage scope+role, view-scope+manage-role on a view requirement, manage-scope+view-role on a view requirement, unrelated cross-match denial, and empty-scope denial. Authorization tests must not confuse a correctly authorized 422 validation response with success.
  - Link projection: `GET /products/{uuid}/link` for an accessible UNLINKED product → 200 `{product_uuid, storefront_url: "<absolute>", link: null}`; linked → `link: {...existing row shape}`; unknown/cross-tenant/tombstoned → 404. `storefront_url` asserted absolute in single-store mode AND workspace mode (reuse Task 5's harness).
  - Entry search: `GET /v1/admin/commerce/entries?q=abo` (manage-gated) → 200 minimal rows `{uuid, title, content_type, status, locale}` only — assert NO other keys; view-only user → 403.
- [ ] **Step 2: Implement.** Regrade the two link GETs to `content_permission:commerce.view,commerce.manage`; keep PUT/DELETE at `commerce.manage`. `showByProduct`: resolve product accessibility exactly as today (404 mapping unchanged), then return the projection with `storefront_url = $previewUrls->productUrl($context, $productSlug)` and `link` nullable. `EntryLinkSearch` owns the tenant-scoped entries/content-type query; the controller only validates `q` (minimum 2), applies the hard limit 20, and maps its five-field projection. Pin one deterministic locale row per entry (requested locale when supplied and enabled, otherwise the workspace default), so localized entries never duplicate in results.
- [ ] **Step 3: Run** the new tests + full `tests/Integration/Commerce` — green.
- [ ] **Step 4: Commit**: `feat(commerce-pack): authorization matrix, view-graded link lookups, storefront_url link projection, minimal entry search`

### Task 8 ✅ COMPLETE (thallo 7b46245): `/meta` endpoint + mount-scoped OpenAPI + SPA schema regen

**Files:**
- Create: `packages/thallo-commerce/src/Http/CommerceMetaController.php`; route in `admin-routes.php` (`GET /meta`, `view` candidates)
- Test: `tests/Integration/Commerce/CommerceMetaTest.php`, `tests/Integration/Commerce/AdminOpenApiGateTest.php`

- [ ] **Step 1: Failing tests.** Meta: authed `view` user → 200 `{currency: 'USD', currency_exponent: 2, shop_index_url: <absolute>, low_stock_threshold: <int>, can_view: true, can_manage: false}`; manage user → both flags true. Inject Task 3's `PermissionRequirementAuthority` and call it for the exact view/manage requirements; the controller must not reproduce `effective(view) || effective(manage)`. Derive `currency_exponent` from Commerce's authoritative `Glueful\Extensions\Commerce\Support\Money::exponentFor($currency)`; test representative 0/2/3-decimal codes plus an unknown configured code that fails closed with a stable configuration error. No Thallo currency map or default-to-2 fallback is permitted.
  - OpenAPI input gate: every mounted route has a unique `thallo.commerce.admin.<key>` name; native Commerce routes remain explicitly unnamed and therefore receive distinct method/path ids.
- [ ] **Step 2: Implement** controller + route using the shared permission authority, `Money`, and `StorefrontPreviewUrlBuilder`. **Step 3: Run** — green.
- [ ] **Step 4: Regenerate and verify the real artifacts:** `composer docs:openapi` then `cd admin && pnpm gen:api`. Parse `docs/openapi.json` structurally (no grep-only gate): the `/v1/admin/commerce` method/path set must equal the approved mounted allowlist plus the pack-owned meta/link/search routes; every catalog operation id must start with the camel-cased `thallo.commerce.admin` route-name prefix and be globally unique. Then assert `admin/src/api/schema.d.ts` contains the stripped `/commerce/*` Thallo paths and no `/commerce/admin/*` native paths; native routes may appear only in the full/core schema. If routes are missing, investigate `RouteReflectionDocGenerator::shouldInclude` (`include_extensions` is true) and fix generation configuration — never hand-edit artifacts. Commit regenerated `docs/openapi.json`, `admin/src/api/schema.d.ts`, and `core-schema.d.ts`.
- [ ] **Step 5: Run FULL thallo suite** (`composer phpcs && vendor/bin/phpunit` with test env) — green. **Commit**: `feat(commerce-pack): /meta endpoint + mount-scoped OpenAPI operation ids; regenerate admin API schema`

---

## Phase P3 — SPA: scaffolding + Products + Linking (first visible activation)

### Task 9 ✅ COMPLETE (thallo 3892ec3): Commerce module scaffolding (meta query, money, gating)

**Files:**
- Create: `admin/src/registry/commerceModule.ts`, `admin/src/queries/commerceMeta.ts`, `admin/src/composables/useMoney.ts`
- Modify: `admin/src/registry/manifest.ts` (add module), `admin/src/queries/keys.ts` (add `commerceMeta`, `commerceProducts`, `commerceProduct`, `commerceLink`, `commerceLinkByEntry`, `commerceEntrySearch` keys)
- Test: `admin/src/__tests__/commerceGating.spec.ts`, `admin/src/__tests__/useMoney.spec.ts`

**Interfaces (Produces):** `useCommerceMeta()` → Colada query on `qk.commerceMeta()`, fetching `client.GET('/commerce/meta')`, returning `{currency, currency_exponent, shop_index_url, low_stock_threshold, can_view, can_manage}`. `useMoney()` → `{ format(minor: number | string | bigint): string }`. It parses the exact minor-unit value to `BigInt`, rejects non-integer/unsafe `number` inputs, splits major/fraction by `10n ** BigInt(exponent)`, and uses `Intl.NumberFormat(...).formatToParts()` only for locale/currency placement and grouping. It never passes the full decimal amount through JavaScript `Number`.

- [ ] **Step 1: Failing tests.** Gating (mirror `collectionsGating.spec.ts`): module hidden when `thallo.commerce` is unavailable; while P3 is incomplete the registered module contributes **no navigation item**. `useMoney`: exponent 0 (`JPY`), 2 (`USD`), and 3 (`KWD`); positive/negative values including `-1` at exponent 2; string and bigint inputs larger than `Number.MAX_SAFE_INTEGER`; and explicit rejection of unsafe/non-integer number inputs.
- [ ] **Step 2: Implement.** Register a scaffold-only `commerceModule` after `templatesModule`, capability-gated but with no nav contribution yet. Task 12 atomically adds Commerce → Products after Products **and** Linking are complete. Implement the exact BigInt/`formatToParts` formatter; do not coerce the assembled decimal string to Number.
- [ ] **Step 3: Run** `pnpm test` + `pnpm type-check` — green. **Step 4: Commit**: `feat(admin): commerce module scaffolding — meta query, exponent-safe money formatting, capability gating`

### Task 10a ✅ COMPLETE (thallo 42c10ba): Products core — list/detail/create/update/delete/bulk status

**Files:** Create `admin/src/queries/commerceCatalog.ts`, product index/detail pages,
`ProductsTable.vue`, `ProductCreateSlideover.vue`, and `ProductForm.vue`; tests
`commerceCatalog.spec.ts` and `commerceProducts.spec.ts`.

**Interfaces:** Consumes Task 9 and produces `useCommerceProduct(uuid)` for Task 12. Endpoints:
`GET/POST /commerce/products`, `GET/PATCH/DELETE /commerce/products/{uuid}`, and product bulk-status.

- [ ] Write failing query tests for the real paginated envelope and precise list/detail invalidation.
- [ ] Write failing component tests for rows, loading/empty/error states, exact-money rendering, read-only
  controls, create/update/delete confirmation, and bulk status.
- [ ] Implement the list and Details tab with route capability metadata. Keep the Commerce nav absent.
- [ ] Run all SPA tests + type-check; commit `feat(admin): commerce product catalog core`.

### Task 10b ✅ COMPLETE (thallo 758debf): Variants, composition, and inventory

**Files:** Extend `commerceCatalog.ts` and product detail; create `VariantsPanel.vue`; extend the same
query/component specs.

**Endpoints:** product variant create, variant update, bulk price, product children set, and stock adjust.

- [ ] Write failing tests for variant lifecycle, product-type/children constraints from API errors,
  bulk-price payloads, stock adjustment, exact money, and list/detail invalidation.
- [ ] Implement the Variants tab and guarded controls; server validation remains authoritative.
- [ ] Run all SPA tests + type-check; commit `feat(admin): commerce variants and inventory controls`.

### Task 10c ✅ COMPLETE (thallo 58048e5): Product media

**Files:** Extend `commerceCatalog.ts`; create `MediaPanel.vue`; extend query/component specs.

**Endpoints:** media attach, reorder, update, and detach.

- [ ] Write failing tests for attach/reorder/update/detach, stable ordering, read-only presentation,
  mutation invalidation, and failed-reorder rollback in the UI.
- [ ] Implement the Media tab using the existing media picker patterns.
- [ ] Run all SPA tests + type-check; commit `feat(admin): commerce product media management`.

### Task 10d ✅ COMPLETE (thallo e62cb28): Categories and product assignment

**Files:** Extend `commerceCatalog.ts`; create `CategoriesTab.vue`; extend query/component specs.

**Endpoints:** category CRUD and `PUT /commerce/products/{uuid}/categories`.

- [ ] Write failing tests for category pagination/tree shape, create/update/delete, set-list assignment,
  read-only state, and all affected query invalidations.
- [ ] Implement the Categories tab without placeholder tabs for the later P7 taxonomies.
- [ ] Run all SPA tests + type-check; commit `feat(admin): commerce categories and product assignment`.

### Task 11 ✅ COMPLETE (thallo dde6f71): `entryEditorPanels` manifest seam

**Files:**
- Create: `admin/src/registry/entryEditorPanels.ts`
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue` (tabs from manifest)
- Test: `admin/src/__tests__/entryEditorPanels.spec.ts`

**Interfaces (Produces):**
```ts
export interface EntryEditorPanel {
  id: string; label: string; order: number
  requiresCapability?: string
  /** Invoked once by the editor during setup; async/composable state stays inside Vue setup. */
  useGate?: (ctx: EntryEditorPanelContext) => Readonly<Ref<'ready' | 'hidden' | 'loading'>>
  component: Component   // async component ok
  props?: (ctx: { uuid: string; locale: string; type: string }) => Record<string, unknown>
}
export const entryEditorPanels: readonly EntryEditorPanel[]
export function useVisibleEditorPanels(ctx): ComputedRef<EntryEditorPanel[]> // capability + gate filtered, order-sorted
```

- [ ] **Step 1: Failing tests** — panel with `requiresCapability` absent → omitted; reactive gate `'loading'` → omitted (loading ≠ enabled, no flicker); changing the same ref to `'ready'` admits it in order and `'hidden'` removes it; each `useGate` is invoked exactly once during setup; the editor's built-in tabs keep their current behavior.
- [ ] **Step 2: Refactor the editor**: during setup, invoke every static declaration's optional `useGate` exactly once (never conditionally, satisfying Vue composable rules), defaulting to a ready ref. `sideTabItems` = built-ins + `useVisibleEditorPanels(ctx)` mapped to `{label, value: id}`; panel bodies render generically. Built-ins stay as-is; the manifest starts EMPTY. If a currently selected contributed panel becomes hidden, reset `sideTab` to `publishing` without rendering stale content.
- [ ] **Step 3: Run** existing editor specs + new spec + type-check — green. **Step 4: Commit**: `feat(admin): entryEditorPanels manifest — capability-gated, settle-before-admit editor tab seam`

### Task 12 ✅ COMPLETE (thallo e0035c3): `ProductEntryLinkPanel` (both mounts) — completes the P3 boundary

**Files:**
- Create: `admin/src/queries/commerceLinking.ts`, `admin/src/components/commerce/ProductEntryLinkPanel.vue`, `admin/src/pages/content/[type]/[uuid]/components/CommerceLinkPanel.vue` (thin wrapper wiring editor ctx → shared panel, entry-side mode)
- Modify: `admin/src/registry/entryEditorPanels.ts` (register commerce panel: `requiresCapability: 'thallo.commerce'`, `useGate` calls the shared meta composable during editor setup and returns `'loading'` until settled, `'hidden'` when `!can_view` or 403, `'ready'` otherwise), `admin/src/registry/commerceModule.ts` (add the first nav item only now), product detail page (product-side mount)
- Test: `admin/src/__tests__/commerceLinkPanel.spec.ts`, `admin/src/queries/commerceLinking.spec.ts`

**Interfaces:** Consumes: `GET/PUT/DELETE /commerce/products/{productUuid}/link`, `GET /commerce/entries/{entryUuid}/link`, `GET /commerce/entries?q=` (manage), `useCommerceMeta`. Panel props: `{ mode: 'product' | 'entry', productUuid?, entryUuid? }`.

- [ ] **Step 1: Failing specs.** Query spec: link mutation sends `expected_entry_uuid` on relink; 409 → typed `ApiError` surfaced; mutations invalidate BOTH `qk.commerceLink(productUuid)` and `qk.commerceLinkByEntry(entryUuid)`. Panel spec: product mode shows linked entry + `[data-test="link-preview"]` anchor whose href === `storefront_url` from the projection (never client-assembled); relink flow opens `[data-test="relink-confirm"]` showing the replaced entry title before submitting; `can_manage: false` → search/link/unlink controls absent, state + preview still visible; entry mode mirrors with product search; CAS 409 renders `[data-test="link-conflict"]` with refresh action.
- [ ] **Step 2: Implement** panel + wrapper + registration + product-detail mount. Only after those are complete, update `commerceModule` to contribute Commerce → Products at `/commerce/products`; this is the first commit in which the navigation entry exists.
- [ ] **Step 3: Run** all SPA tests + type-check — green.
- [ ] **Step 4: This closes the P3 activation boundary** — nav shows Commerce → Products; linking live on both sides. Run full thallo backend suite too. **Commit**: `feat(admin): bidirectional product↔entry link panel with CAS relink and server-built preview URLs`

---

## Phase P4 — Orders

### Task 13a ✅ COMPLETE (thallo 57f8e5a): Order list and detail (nav appends Orders when green)

**Files:** Create `commerceOrders.ts`, order index/detail pages, `OrdersTable.vue`, and their query/page
specs. Endpoints: order list/show with exact generated filters and pagination.

- [ ] Write failing tests for list filters, pagination, line/totals/status presentation, exact money,
  loading/empty/error states, and read-only behavior.
- [ ] Implement and run all SPA tests + type-check.
- [ ] Only after the pages are green, append Orders to `commerceModule`; commit
  `feat(admin): commerce order list and detail`.

### Task 13b ✅ COMPLETE (thallo 73f28e2): Order lifecycle actions

**Files:** Create `OrderActions.vue`; extend `commerceOrders.ts` and specs. Endpoints: cancel,
mark-paid, and fulfill.

- [ ] Write failing tests for server-valid status visibility, `can_manage`, request payloads, conflict
  responses, and list/detail invalidation.
- [ ] Implement, run all SPA tests + type-check, and commit `feat(admin): commerce order lifecycle actions`.

### Task 13c ✅ COMPLETE (thallo a85a628): Refunds

**Files:** Create `RefundSlideover.vue`; extend queries/specs. Endpoints: order refund create/list plus
cross-order refund list/show.

- [ ] Write failing tests for amount/line attribution payloads, refundable bounds as UX guidance
  (server remains authoritative), exact money, read-only history, and invalidation.
- [ ] Implement, run all SPA tests + type-check, and commit `feat(admin): commerce refund management`.

### Task 13d ✅ COMPLETE (thallo f218737): Notes and invoice data

**Files:** Create `OrderNotes.vue`; extend order detail, queries, and specs. Endpoints: notes create/list
and invoice-data read.

- [ ] Write failing tests for append-only notes, hidden mutation controls for view-only users,
  invoice-data rendering, and invalidation.
- [ ] Implement, run all SPA tests + type-check, and commit `feat(admin): commerce order notes and invoice data`.

---

## Phase P5 — Discounts + Settings

### Task 14 ✅ COMPLETE (thallo 5d71902): Discounts domain

Files: `admin/src/queries/commerceDiscounts.ts`, `admin/src/pages/commerce/discounts/index.vue` (+ `DiscountForm.vue` slideover), nav child `Discounts`; specs `commerceDiscounts(.spec).ts` ×2. Endpoints: discounts CRUD (5). Table columns: code, type/amount (money or % — inspect schema DTO), usage, active window; `can_manage` gates create/edit/delete (`[data-test="new-discount"]`). Same TDD steps + commit: `feat(admin): commerce Discounts area`.

### Task 15a ✅ COMPLETE (thallo af8be42): Settings shell + shipping zones, locations, and methods

Files: create `commerceSettings.ts`, Settings page, `ZonesPanel.vue`, and query/page specs. Endpoints:
zone CRUD, location set, and nested method CRUD.

- [ ] Test ordered first-match semantics, shadowing warnings, postcode/country validation responses,
  nested method editing, read-only state, and invalidation.
- [ ] Implement the Settings shell with only the completed Shipping zones tab. Add Settings nav only
  after this page is green; run all SPA tests + type-check and commit
  `feat(admin): commerce shipping zones and methods`.

### Task 15b ✅ COMPLETE (thallo 11799e1): Shipping classes

Files: create `ClassesPanel.vue`; extend settings queries/page/specs. Endpoints: shipping-class CRUD.

- [ ] Test create/update/delete, referenced-class refusal, read-only behavior, and invalidation.
- [ ] Add the completed tab, run all SPA tests + type-check, and commit
  `feat(admin): commerce shipping classes`.

### Task 15c ✅ COMPLETE (thallo 25734f1): Tax rates

Files: create `TaxRatesPanel.vue`; extend settings queries/page/specs. Endpoints: tax-rate CRUD.

- [ ] Test class/rate fields, percentage/bps representation from the generated schema, ordering,
  read-only behavior, validation errors, and invalidation.
- [ ] Add the completed tab, run all SPA tests + type-check, and commit
  `feat(admin): commerce tax-rate settings`.

---

## Phase P6 — Reviews, Customers, Overview

### Task 16 ✅ COMPLETE (thallo b359d86): Reviews moderation

Files: `admin/src/queries/commerceReviews.ts`, `admin/src/pages/commerce/reviews/index.vue` + `ReviewRow.vue`; nav child `Reviews`; specs ×2. Endpoints: reviews index/show/store/approve/spam/destroy/bulk. UI: status-filtered table, per-row approve/spam/delete + bulk bar (`[data-test="review-approve"]` etc.), all `can_manage`-gated. Commit: `feat(admin): commerce Reviews moderation`.

### Task 17 ✅ COMPLETE (thallo 8245633): Customers (read-only)

Files: `admin/src/queries/commerceCustomers.ts`, `admin/src/pages/commerce/customers/index.vue` + `[key]/index.vue`; nav child `Customers`; specs ×2. Endpoints: `GET /commerce/customers`, `GET /commerce/customers/{key}`. Read-only regardless of `can_manage` (no mutation endpoints exist). Detail shows order history from the orders query filtered by customer (schema-permitting) or the detail payload. Commit: `feat(admin): commerce Customers (read-only)`.

### Task 18 ✅ COMPLETE (thallo ce36676): Overview (reports)

Files: `admin/src/queries/commerceReports.ts`, `admin/src/pages/commerce/index.vue` (Overview dashboard); nav child `Overview` FIRST in the group; specs ×2. Endpoints: `GET /commerce/reports/sales|products|customers|stock` (period params per schema). Cards + tables: sales summary (money), top products, top customers, low-stock list flagged against `low_stock_threshold`. Charts optional — if used, follow the @unovis jsdom polyfill gotchas already in `setup.ts`. Commit: `feat(admin): commerce Overview reports dashboard`.

---

## Phase P7 — Taxonomy extras

### Task 19a ✅ COMPLETE (thallo 6e925d9): Tags

Extend `commerceCatalog.ts`; create `TagsTab.vue`; extend specs. Cover tag CRUD and product tag
set-list assignment, read-only state, pagination/search, and invalidation. Add the tab only when green;
run all SPA tests + type-check and commit `feat(admin): commerce tags and product assignment`.

### Task 19b ✅ COMPLETE (thallo 82cb7ad): Attributes and values

Create `AttributesTab.vue`; extend queries/specs. Cover attribute/value CRUD, per-product assignment,
composite-conflict errors, read-only state, and invalidation. Add the tab only when green; run all SPA
tests + type-check and commit `feat(admin): commerce attributes and values`.

### Task 19c ✅ COMPLETE (thallo 2bc1e6f): Product add-ons

Create `AddonsPanel.vue`; extend product queries/detail/specs. Cover add-on CRUD, option/delta-money
editing, immutable checkout-snapshot messaging through API states, read-only behavior, and invalidation.
Add the panel only when green; run all SPA tests + type-check and commit
`feat(admin): commerce product add-ons`.

### Task 19d ✅ COMPLETE (thallo 264998a): Downloads and grants

Create `DownloadsPanel.vue`; extend queries/detail/specs. Cover variant download attach/update/detach,
grant revoke, refund-access override set/clear, exact limits/expiry fields, read-only histories, and
invalidation. Add the panel only when green; run all SPA tests + type-check and commit
`feat(admin): commerce downloads and grants`.

---

## Phase P8 — Release train

### Task 20 ✅ COMPLETE: Commerce 1.4.0 release prep + thallo pins

- [ ] **Step 1:** Commerce repo: CHANGELOG `[1.4.0]` (theme: mountable admin route catalog; additive; native route parity proven; no schema/env changes), bump `extra.glueful.version` → `1.4.0`. Full commerce suite green. Commit (commerce dev). **User publishes/tags** — do not tag.
- [ ] **Step 2:** After publish: thallo `composer.json` + `packages/thallo-commerce/composer.json` → `glueful/commerce ^1.4.0`, `composer update glueful/commerce`; verify vendored copy contains `AdminRouteCatalog`.
- [ ] **Step 3:** Thallo CHANGELOG `[Unreleased]`: the slice-3 entry (admin commerce area, commerce.view, editor panel seam, meta/preview URLs).
- [ ] **Step 4:** Full gates: thallo `composer phpcs` + full phpunit; commerce suite; SPA `pnpm test` + `pnpm type-check` + `pnpm gen:api` idempotence (regen produces no diff). Commit thallo (deps + changelog). **No pushes.**

---

## Self-review notes (resolved inline)

- Spec §3.3 name-parity nuance: legacy routes carry NO `->name()` — the fixture records `name: null` and parity compares it; native profile remains nameless while the restricted Thallo profile names routes (`thallo.commerce.admin.<key>`), giving mount-scoped operation ids (Tasks 1/2/6/8).
- Spec §4.2 intersection lands in the reusable Task 3 authority; Task 4's catalog is the declarative implication source; route candidates remain generic alternatives; `/meta` injects the same authority (Task 8).
- Spec §7.4 settle-before-admit = Task 11 gate `'loading'` semantics + Task 12 registration.
- OpenAPI staleness: `docs/openapi.json` currently predates the pack — Task 8 regenerates and structurally compares the mounted path/operation-id set, with the `shouldInclude` investigation path named.
- All 98 endpoints have bounded consuming UI tasks: products core/variants/media/categories/stock (T10a–d), link+search (T12), order reads/lifecycle/refunds/notes/invoice (T13a–d), discounts (T14), shipping/tax (T15a–c), reviews (T16), customers (T17), reports (T18), tags/attributes/addons/downloads/grants (T19a–d).
- The P3 navigation boundary is single: Task 9 registers scaffolding without nav; Task 12 adds Commerce → Products only after Products and Linking are both complete.
- Currency exponent metadata comes exclusively from Commerce `Money::exponentFor`; SPA formatting keeps the full amount in BigInt/string space and rejects unsafe Number inputs.
