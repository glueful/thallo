# Storefront v1 — Concept A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the approved Concept A storefront — category rail/tags, hover cart+wishlist
card actions with AddToCartViewModel-authority cart modes, stepper + exponent-aware
price-in-button, and device-local wishlist v1 (endpoint, page, store, `wishlist-link`
block) — on Commerce 1.8.0's four batched reads and a batched media-URL seam.

**Architecture:** Commerce 1.8.0 releases first (four additive tenant-scoped repository
reads; USER publishes). Thallo then repins and builds: a `MediaUrlBatchResolver` companion
seam; a `StorefrontWishlistResolver` seam mirroring `StorefrontLinkResolver` exactly
(opaque SHA-256 scope + generator-owned URL, two policy-allowlisted Twig helpers); a
closed `ProductCardViewModel` shared by server cards and client hydration; and a shop.js
wishlist store with fail-closed storage and generation/revision-gated reconciliation.

**Tech Stack:** PHP 8.3 (Glueful), Twig, vanilla shop.js (node-harness tested), PHPUnit.
Two repos: `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce` (Task 1) and
`/Users/michaeltawiahsowah/Sites/glueful/thallo` (Tasks 2–9).

## Global Constraints

- Two repos, separate commits. Commerce work commits in `extensions/commerce` on `dev`;
  thallo work on thallo `dev`. **Never push either. Never tag or publish — the USER
  publishes Commerce 1.8.0 at the Task 1→2 gate.** No AI attribution anywhere.
- Nothing under thallo `docs/` or `.superpowers/` staged (spec + this plan stay held).
  Stage exact files only — never a directory-wide `git add`.
- Test runs: `set -o pipefail && vendor/bin/phpunit <paths> 2>&1 | tail -5` — NEVER grep.
  phpcs PSR12 on every touched PHP file (both repos).
- Spec: `docs/superpowers/specs/2026-07-28-storefront-v1-concept-a-design.md` (thallo).
- Pinned values (verbatim): localStorage key `thallo:wishlist:v1:{opaqueScope}`; scope =
  unpadded base64url of SHA-256 of `"wishlist-v1\0" + normalizedTenant + "\0" + prefix`,
  `''` tenant → literal `shared`; UUIDs only, newest-first, bound **100** (unshift/
  tail-drop); endpoint `GET /_shop/wishlist/items` with `uuids[]`, >100 raw or malformed
  → 422 BEFORE querying, `private, no-store`, request-order preserved; event
  `CustomEvent('thallo:wishlist-changed', {detail: {scope, uuids}})`; hearts hidden until
  the store is `ready`, `aria-pressed`, product-specific labels; page starts
  `aria-busy="true"`, never flashes false empty state; stepper bounds 1–99;
  `data-price-minor`/`data-currency`/`data-currency-exponent` with exponent ONLY from
  `Money::exponentFor()`; TemplatePolicy `CACHE_VERSION` 14 → **15** (both new helpers in
  one bump); block slug `wishlist-link`; card projection allowlist exactly
  `{uuid, name, url, cover_url, rating, price_formatted, compare_at_formatted,
  category_name, cart_mode, direct_variant_uuid}`.

---

### Task 1: Commerce 1.8.0 — four batched tenant-scoped reads

**Repo:** `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce` (branch `dev`).

**Files:**
- Modify: `src/Catalog/ProductRepository.php`, `src/Catalog/CategoryRepository.php`,
  `src/Catalog/AddonRepository.php`, `src/Catalog/ProductMediaRepository.php` (verified:
  the class the thallo pack injects as `$this->media`).
- Modify: `CHANGELOG.md` (`[Unreleased]` — 1.8.0 material).
- Test: one new test class per repository method, in this repo's existing catalog-test
  location and idiom (`grep -rl 'ProductRepository' tests/ | head` and mirror the nearest
  file's fixtures/harness).

**Interfaces (produced — Tasks 5–7 consume these EXACT signatures):**

```php
/** @return array<string, array<string,mixed>> uuid => product row; input deduped; [] → no query */
ProductRepository::findActiveBuyerAvailableByUuids(ApplicationContext $context, string $tenant, array $uuids): array;

/** @return array<string, array{name: string, slug: string}> product_uuid => first category */
CategoryRepository::firstCategoryProjectionsForProducts(ApplicationContext $context, string $tenant, array $productUuids): array;

/** @return array<string, true> product_uuid => true where an ACTIVE required add-on exists */
AddonRepository::hasRequiredForProducts(ApplicationContext $context, string $tenant, array $productUuids): array;

/** @return array<string, array<string,mixed>> product_uuid => cover-role row, else first gallery row */
ProductMediaRepository::primaryForProducts(ApplicationContext $context, string $tenant, array $productUuids): array;
```

- [ ] **Step 1: Read the ground truth.** `activeFilteredQuery()` (ProductRepository ~:274)
  is the live+active+buyer-available predicate authority — `findActiveBuyerAvailableByUuids`
  MUST be `activeFilteredQuery($context, $tenant, null)` + `whereIn('uuid', $uuids)`,
  never a re-derived predicate. Read the three other repositories' existing list methods
  for their column names, `position` semantics, and the `required`/`active` add-on flags,
  and one existing repo test for the fixture idiom (products/variants/categories seeding,
  two-tenant setup).

- [ ] **Step 2: Write the failing tests** (one class per method; the assertions below are
  the contract — build fixtures in the house idiom):
  - each method: empty input → `[]` with ZERO queries and non-empty input → EXACTLY
    ONE query, measured with this suite's existing
    `tests/Support/CountingPdoStatement` (`PDO::ATTR_STATEMENT_CLASS` counter — read a
    test already using it and mirror; snapshot the count after fixture setup/schema
    warm-up, call the method, assert the delta);
  - `findActiveBuyerAvailableByUuids`: returns exactly the rows `listActive()` would
    consider buyable (parity fixture: one active+available, one inactive, one deleted,
    one other-tenant, one seller-unavailable — only the first comes back), uuid-keyed,
    duplicate input uuids deduped;
  - `firstCategoryProjectionsForProducts`: a product in categories (position 2, "B") and
    (position 1, "A") → `A`; ties on position break by `name ASC` then `uuid ASC`;
    direct assignments only; product with none → absent key;
  - `hasRequiredForProducts`: active required → key true; INACTIVE required → absent;
    optional-only → absent;
  - `primaryForProducts`: cover-role row wins over an earlier-positioned gallery row;
    no cover → first gallery by position; no media → absent key;
  - two-tenant isolation on every method.

- [ ] **Step 3: Run to verify failure** — undefined methods.

- [ ] **Step 4: Implement.** All four share ONE pinned normalizer (a small shared
  private/support helper in this repo): keep only values matching
  `/\A[A-Za-z0-9]{12}\z/` (schema pins `uuid` as `string(12)`; the framework's
  `RandomStringGenerator::CHARSET_NANOID` is alphanumeric ONLY — no `_` or `-`),
  dedupe preserving first occurrence, cap to the FIRST
  100 after dedupe, `[]` in → no query. Malformed values are DROPPED (defensive reads,
  never an exception — strictness lives at HTTP boundaries). Tests: a malformed value
  among valid ones is ignored; 101 valid distinct values → only the first 100 resolve.
  One query each, PHP-side reduction for first-category and primary-media
  (ordered reads: category join ordered `product_uuid, position ASC, name ASC, uuid ASC`;
  media ordered `product_uuid, position ASC, uuid ASC`, then prefer `role = 'cover'` per
  product in PHP). No new routes/tables/writes.

- [ ] **Step 5: Green + full commerce suite** —
  `set -o pipefail && vendor/bin/phpunit 2>&1 | tail -5` (expect the suite's current
  count, ~2823+, all green); phpcs PSR12 on the four repositories + tests.

- [ ] **Step 6: CHANGELOG** `[Unreleased]`: one entry — "Four additive batched catalog
  reads for storefront card surfaces (products-by-uuids with buyer availability,
  first-category projections, required-add-on presence, primary media)".

- [ ] **Step 7: Commit** (exact files) —
  `git commit -m "feat(catalog): batched buyer-available/category/addon/media reads for card surfaces"`

**⛔ GATE — Task 1 → Task 2:** the coordinator reports commerce green; the USER publishes
Commerce **1.8.0** (tag + packagist). Nothing in Tasks 2–9 starts until the user confirms
the release exists.

---

### Task 2: Thallo repin to Commerce ^1.8.0

**Repo:** thallo. **Files:** `composer.json` (root, `^1.7.0` → `^1.8.0`),
`packages/thallo-commerce/composer.json` (`^1.5.0` → `^1.8.0`), `composer.lock`.

- [ ] `composer update glueful/commerce` (root); verify `composer show glueful/commerce`
  reports 1.8.0.
- [ ] Smoke: `set -o pipefail && vendor/bin/phpunit tests/Integration/Commerce 2>&1 | tail -5`
  green.
- [ ] Commit the three files —
  `git commit -m "chore(commerce): require glueful/commerce ^1.8.0 (batched card reads)"`

---

### Task 3: MediaUrlBatchResolver seam

**Repo:** thallo.

**Files:**
- Create: `packages/thallo-contracts/src/Delivery/MediaUrlBatchResolver.php`
- Modify: `app/Content/Delivery/EngineMediaUrlResolver.php` (implements the batch; `url()`
  delegates), `app/Providers/ThalloServiceProvider.php` (bind the interface to the same
  factory/instance as `MediaUrlResolver`)
- Test: `tests/Integration/Content/MediaUrlBatchResolverTest.php` (new; sibling of
  `MediaVariantUrlResolverTest`, reuse its seedBlob shape)

**Interfaces (produced):**

```php
namespace Thallo\Contracts\Delivery;

interface MediaUrlBatchResolver
{
    /**
     * Anonymous public URLs for ≤100 blob uuids in ONE query — same fail-closed
     * predicate as MediaUrlResolver::url(). Unservable uuids are OMITTED.
     * @param list<string> $uuids  @return array<string,string> uuid => url
     */
    public function urls(array $uuids): array;
}
```

- [ ] **Step 1: Failing tests:** parity (a blob servable via `url()` appears in `urls()`
  with the SAME url string; a private/deleted/missing one is omitted by BOTH); one-query
  resolution of many uuids (this suite has no query counter — assert instead via a
  100-uuid call with correct omissions, and PROVE one query by porting Commerce's
  `CountingPdoStatement` pattern into thallo as `tests/Support/CountingPdoStatement.php`
  (`PDO::ATTR_STATEMENT_CLASS` on the suite connection's PDO works on pgsql exactly as
  on SQLite; snapshot after warm-up, assert delta === 1) — this helper is REUSED by
  Task 5's budget test; the delegation test below carries the drift guard); dedupe + cap-at-100 (101 distinct → the highest-indexed excess is
  ignored — pin: FIRST 100 after dedupe are resolved); empty → `[]`; `url()` delegates:
  temporarily impossible to observe externally, so pin it STRUCTURALLY — the test asserts
  `url()` and `urls([uuid])[uuid]` byte-equal across servable/unservable fixtures.
- [ ] **Step 2: red** (interface/class methods missing).
- [ ] **Step 3: Implement.** `EngineMediaUrlResolver implements MediaUrlResolver,
  MediaUrlBatchResolver`: `urls()` = the existing predicate as ONE
  `whereIn('uuid', ...)` query (uploads-enabled/access gate first, exactly as `url()`);
  **rewrite `url()` as `return $this->urls([$uuid])[$uuid] ?? null;`** so the predicate
  cannot drift. Provider: bind `MediaUrlBatchResolver::class` via a factory returning the
  container's `MediaUrlResolver` instance (one object, two interfaces).
- [ ] **Step 4: green** (new test + existing `MediaUrlResolverTest` + `MediaVariantUrlResolverTest`
  untouched-green), phpcs.
- [ ] **Step 5: Commit** —
  `git commit -m "feat(delivery): batched anonymous media URL seam (one query per card list)"`

---

### Task 4: StorefrontWishlistResolver seam + Twig helpers + policy v15

**Repo:** thallo.

**Files:**
- Create: `packages/thallo-contracts/src/Delivery/StorefrontWishlistResolver.php`
- Create: `packages/thallo-commerce/src/Shop/ShopWishlistSurface.php`
- Modify: `packages/thallo-commerce/src/Shop/ShopUrlGenerator.php` (add `wishlist()`),
  `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (bind the
  resolver — UNCONDITIONALLY like the reserved-path contribution; the class itself
  checks the capability at call time),
  `packages/thallo-render/src/RenderContextExtension.php` (ctor param + two helpers),
  `packages/thallo-render/src/RenderServiceProvider.php` (soft-bind pass-through),
  `packages/thallo-render/src/Templates/TemplatePolicy.php` (both helpers, v15)
- Test: `tests/Integration/Commerce/ShopWishlistSurfaceTest.php` (new);
  extend `tests/Integration/Render/BlocksRenderingTest.php` (policy)

**Interfaces (produced):**

```php
namespace Thallo\Contracts\Delivery;

interface StorefrontWishlistResolver
{
    /** Opaque device-storage scope for the current store, or null while the shop is inactive. */
    public function storageScope(): ?string;

    /** Canonical wishlist page URL, or null while the shop is inactive. */
    public function wishlistUrl(): ?string;
}
```

Twig (registered `needs_context: false`, null-safe): `shop_wishlist_scope(): ?string`,
`shop_wishlist_url(): ?string`.

- [ ] **Step 1: Read the model.** `StorefrontLinkResolver` end-to-end: the contract, the
  pack implementation binding, `RenderContextExtension`'s nullable ctor param +
  `shopProductUrl()`, `RenderServiceProvider`'s `$container->has(...)` soft-bind. Mirror
  ALL of it. Read `CapabilityFlipPurge`'s provider wiring for the capability-read idiom.
- [ ] **Step 2: Failing tests** (`ShopWishlistSurfaceTest`, direct construction +
  container):
  - scope determinism: same tenant+prefix → same scope; different tenant → different;
    different prefix → different;
  - LIVE tenant resolution: ONE surface instance with a fake CommerceTenantResolution
    returning tenant A, then B, then A across successive storageScope() calls yields
    scope(A), scope(B), scope(A) — proving no construction-time or first-call capture;
  - scope shape: unpadded base64url (`^[A-Za-z0-9_-]+$`), 43 chars (SHA-256), and does
    NOT contain the raw tenant uuid substring;
  - `''` tenant normalizes to the `shared` sentinel input (two empty-tenant surfaces with
    the same prefix agree);
  - `wishlistUrl()` === `ShopUrlGenerator::wishlist()` === `'/{prefix}/wishlist'` with a
    normalized custom prefix honored (ShopUrlGenerator composes the trusted normalized
    prefix without encoding it — mirror its siblings exactly);
  - capability off (construct with a false-returning capability callable, or assert via
    the disabled-boot idiom in `StorefrontInertnessTest` style): both methods null;
  - Twig: `shop_wishlist_scope()`/`shop_wishlist_url()` return the surface values when
    bound; null → null (no exception); `BlocksRenderingTest` policy: both names in
    `FUNCTIONS`, `CACHE_VERSION` 15, linter compiles a template calling both.
- [ ] **Step 3: red.**
- [ ] **Step 4: Implement.**

```php
// ShopWishlistSurface (pack): ctor(ApplicationContext $context, ShopUrlGenerator $urls,
//   CommerceTenantResolution $tenants, \Closure $capabilityEnabled). The tenant is
//   resolved LIVE inside every storageScope() call — ThalloCommerceTenantResolution's
//   contract explicitly forbids caching a resolved tenant value, and this service is
//   shared across requests. NEVER capture the tenant string at construction.
public function storageScope(): ?string
{
    if (!($this->capabilityEnabled)()) {
        return null;
    }
    $raw = $this->tenants->tenantUuid($this->context);
    $tenant = $raw === '' ? 'shared' : $raw;
    $digest = hash('sha256', "wishlist-v1\0" . $tenant . "\0" . $this->urls->prefix, true);
    return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
}
```

  `ShopUrlGenerator::wishlist(): string` — pure composition beside its siblings
  (`'/' . $this->prefix . '/wishlist'` in whatever exact idiom `shopIndex()` uses; read
  it). TemplatePolicy bump comment:
  `// bumped: shop_wishlist_scope + shop_wishlist_url joined the allowlist (storefront-v1 spec §5)`.
- [ ] **Step 5: green** (new + BlocksRenderingTest + Render suite), phpcs.
- [ ] **Step 6: Commit** —
  `git commit -m "feat(shop): storefront wishlist seam — opaque scope + generator-owned URL, policy v15"`

---

### Task 5: Cards + grid server side (ProductCardViewModel, cart modes, rail, hover actions)

**Repo:** thallo.

**Files:**
- Create: `packages/thallo-commerce/src/Shop/ViewModels/ProductCardViewModel.php`
- Modify: `packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php`
  (`buildGrid()` uses the 1.8.0 batched reads + batch media + `AddToCartViewModel`;
  ctor gains the nullable `MediaUrlBatchResolver`), `packages/thallo-commerce/templates/shop/_product_card.twig`,
  `templates/shop/index.twig`, `templates/shop/category.twig`,
  `packages/thallo-commerce/assets/shop.css`
- Test: `tests/Integration/Commerce/ProductCardViewModelTest.php` (new) + extend
  `tests/Integration/Commerce/ShopCatalogTest.php` (rail, tags, cart-mode matrix in
  rendered HTML, query budget)

**Interfaces:**
- Consumes: Task 1's four reads; Task 3's `urls()`; Task 4's Twig helpers; existing
  `AddToCartViewModel::build(array $product, array $activeVariants, bool $hasRequiredAddons, ShopUrlGenerator $urls, string $defaultCurrency)`.
- Produces: `ProductCardViewModel::toArray(): array` — EXACTLY the Global-Constraints
  allowlist keys, nothing else (`cart_mode` ∈ `'direct'|'options'`;
  `direct_variant_uuid` null unless direct). Task 7's endpoint and Task 8's
  `buildProductCard()` consume this shape verbatim.

- [ ] **Step 1: Failing tests.**
  - `ProductCardViewModelTest`: `toArray()` keys are EXACTLY the allowlist (assert
    `array_keys` equality — the closed-projection guard); `AddToCartViewModel` mode
    `direct` → `cart_mode: 'direct'` + the variant uuid; `select`/`link` → `'options'`
    + null.
  - `ShopCatalogTest` extensions (rendered HTML through the real controller):
    single-active-variant no-required-addon product → the card contains
    `<form method="post" action="/_shop/cart/add"` with its variant uuid; the SAME
    fixture + an active required add-on → NO form, an options link to the product URL;
    multi-variant → options link; category rail renders every category chip + active
    state on the category page; tile tag shows the deterministic first category; a
    product without categories → no tag; heart buttons render `hidden` with
    `data-shop-wishlist-toggle` + the product uuid; the page root carries
    `data-shop-scope`; **query budget**: render the index twice — once with 2
    products, once with 6 — using Task 3's `tests/Support/CountingPdoStatement` helper
    (snapshot after a warm-up render) and assert the second render's query count equals
    the first's (constant in product count — the test FAILS if anyone reintroduces a
    per-card loop).
- [ ] **Step 2: red.**
- [ ] **Step 3: Implement server side.** `buildGrid()`: collect page uuids → one call
  each: variants (existing batched read), `hasRequiredForProducts`,
  `firstCategoryProjectionsForProducts`, `primaryForProducts`, then
  `MediaUrlBatchResolver::urls()` for the blob uuids (fallback: when the batch seam is
  unbound, the existing per-row `mediaUrl()` path — pack never hard-requires the app
  binding); per row call `AddToCartViewModel::build()` and construct
  `ProductCardViewModel`. Replace the old `coversForProducts()` + per-missing
  `forProduct()` loop with `primaryForProducts`.
- [ ] **Step 4: Templates + CSS.** `_product_card.twig` (read the current file + the
  approved mock's anatomy): tile (media panel + category tag chip + hover-actions
  container with the PRG form-or-link cart button and the hidden heart
  `<button type="button" hidden data-shop-wishlist-toggle data-product-uuid="{{ product.uuid }}" aria-pressed="false" aria-label="Save {{ product.name }} to wishlist">`),
  body (name link, rating, price row). Index/category: the chip rail
  (`CategoryRepository::all()` already reaches the controller — thread `categories` into
  the template context), `data-shop-scope="{{ shop_wishlist_scope() }}"` on the section
  root (omit the attribute when null). CSS: port the approved mock's hover-action rules
  (stacked circular buttons bottom-right, `opacity 0→1` + translateY on
  `:hover/:focus-within`, transition under `prefers-reduced-motion: no-preference` only,
  `@media (hover: none)` always-visible) into shop.css's grid section, adapted to the
  real class names.
- [ ] **Step 5: green** (new + ShopCatalogTest + StorefrontWalkTest + full Commerce dir),
  phpcs; `node --check packages/thallo-commerce/assets/shop.js` (untouched, sanity).
- [ ] **Step 6: Commit** —
  `git commit -m "feat(shop): Concept A cards — batched card projection, honest cart modes, category rail, hover actions"`

---

### Task 6: Product page — stepper, price-in-button, detail heart, breadcrumb

**Repo:** thallo.

**Files:**
- Modify: `packages/thallo-commerce/templates/shop/product.twig`,
  `packages/thallo-commerce/templates/shop/cart.twig`,
  `packages/thallo-commerce/templates/shop/checkout.twig`,
  `packages/thallo-commerce/templates/shop/confirmation.twig` (root `data-shop-scope`),
  `packages/thallo-commerce/src/Shop/ViewModels/AddToCartViewModel.php` (the current
  option projection is ONLY {uuid, label, price_formatted} and CANNOT emit the pinned
  attributes: each option additionally gains `price_minor` (int), and the view model
  exposes top-level `currency` (resolved via the existing default-currency rule),
  `currencyExponent` (?int, from `Money::exponentFor()`), and `directPriceMinor` (?int —
  the single variant's minor price in `direct` mode, where `options` is empty)),
  `packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php` (product(): the
  first-category read for the breadcrumb),
  `packages/thallo-commerce/assets/shop.css`, `packages/thallo-commerce/assets/shop.js`
  (stepper + price module), `tests/Integration/Commerce/ShopCatalogTest.php` +
  `tests/Integration/Commerce/ShopJsRuntimeTest.php`

**Interfaces:**
- Consumes: Task 1's `firstCategoryProjectionsForProducts` (single-product call is fine —
  it is the same bounded read); commerce `Money::exponentFor()`; Task 4's heart contract
  (`data-shop-wishlist-toggle` + uuid, hidden, aria — same attributes as Task 5's cards).
- Produces: the form emits `data-price-minor`, `data-currency`,
  `data-currency-exponent`; each variant `<option>` carries `data-price-minor`.

- [ ] **Step 1: Failing tests.** `ShopCatalogTest`: the product page emits the three data
  attributes with correct values (fixture with a known minor price), the stepper markup
  (`data-shop-qty-minus/plus` buttons wrapping the EXISTING quantity input), the hidden
  detail heart with the product uuid, `data-shop-scope` on the product page's section
  root, and the category breadcrumb linking through `shop_category_url`. Spec §5 says
  EVERY shop page root emits the scope — that includes `cart.twig`, `checkout.twig`,
  and `confirmation.twig`: add the same root attribute to all three IN THIS TASK (the
  file map gains those templates) with assertions in the cart/checkout page tests. `ShopJsRuntimeTest` node harness (new scenario in the existing
  idiom): stepper minus at 1 stays 1; plus to 99 caps; label math —
  `{minor: 70000, currency: 'USD', exponent: 2} × 3` → contains `2,100`;
  `{minor: 500, currency: 'JPY', exponent: 0} × 2` → contains `1,000` and no decimals;
  `{minor: 1250, currency: 'KWD', exponent: 3} × 2` → contains `2.500`; malformed/absent
  exponent or `minor * qty > Number.MAX_SAFE_INTEGER` → label UNCHANGED from the
  server-rendered text; variant change re-reads the selected option's
  `data-price-minor`, and the harness asserts a switch between two differently-priced
  variants recomputes the label from the NEW option's minor price. Unit-side,
  extend/create the AddToCartViewModel tests to pin the new projection fields
  (`price_minor` per option, `currency`, `currencyExponent`, `directPriceMinor`) in
  select AND direct modes.
- [ ] **Step 2: red** (markup absent; harness scenario fails on missing behavior).
- [ ] **Step 3: Implement.** Template: buy-area restructure per the approved mock (select
  row; action row = stepper + button); server keeps rendering the unit price in the
  button text (no-JS truth). Controller: exponent via commerce `Money::exponentFor()`
  (import; null exponent → omit the attribute, JS then leaves the label alone).
  shop.js: a `shop-buy` module (registered like its siblings; runtime + fallback sweep)
  binding the stepper buttons (inner bound-marker idiom) and recomputing the label via
  checked integer math + `Intl.NumberFormat(document.documentElement.lang || undefined,
  {style: 'currency', currency})`; every parse guarded (`Number.isSafeInteger`). CSS:
  stepper pill styles from the approved mock adapted to shop.css.
- [ ] **Step 4: green** (ShopCatalogTest + ShopJsRuntimeTest + RuntimeShopCoexistenceTest
  — the new module joins the registry count there; update its expected module list),
  `node --check`, phpcs.
- [ ] **Step 5: Commit** —
  `git commit -m "feat(shop): quantity stepper with exponent-aware price-in-button; detail heart + category breadcrumb"`

---

### Task 7: Wishlist server side — endpoint + page

**Repo:** thallo.

**Files:**
- Create: `packages/thallo-commerce/src/Http/Shop/ShopPageRenderer.php` — EXTRACTED from
  `ShopCatalogController::render()` (which is PRIVATE and cannot be reused): a shared
  final class owning that method's exact reset discipline
  (`resetTags; resetPerRenderState; setAssetContext(null, null); setBlockAnnotations(false);
  setThemeAppearanceOverride(null, null); setLocale(...)`) and context assembly.
  `ShopCatalogController` delegates to it (behavior byte-identical; its existing tests
  are the regression net); `ShopWishlistController` consumes the same instance.
- Create: `packages/thallo-commerce/src/Http/Shop/ShopWishlistController.php`
- Create: `packages/thallo-commerce/templates/shop/wishlist.twig`
- Modify: `packages/thallo-commerce/routes/shop-routes.php` (two routes inside the
  existing capability-gated file: `GET /{prefix}/wishlist` → page,
  `GET /_shop/wishlist/items` → items), provider `services()` registration for the
  controller (use-imports, like its siblings)
- Test: `tests/Integration/Commerce/ShopWishlistEndpointTest.php` (new) + extend
  `tests/Integration/Commerce/StorefrontInertnessTest.php` (both routes 404 when
  disabled)

**Interfaces:**
- Consumes: Tasks 1/3/5 (`findActiveBuyerAvailableByUuids`, batched
  category/addon/media reads, `MediaUrlBatchResolver`, `ProductCardViewModel`), Task 4's
  `shop_wishlist_scope()` (page template root) and `ShopUrlGenerator::wishlist()`.
- Produces: JSON `{items: [ProductCardViewModel::toArray(), …]}` in REQUEST order;
  page shell classes/hooks Task 8 hydrates:
  `data-shop-wishlist-page`, `[data-shop-wishlist-status]`,
  `[data-shop-wishlist-empty] hidden`, `[data-shop-wishlist-grid] hidden`,
  root `aria-busy="true"`, `<noscript>` explanation.

- [ ] **Step 1: Failing tests** (`ShopWishlistEndpointTest`, HTTP through the kernel like
  `CommerceMarketplaceEndpointTest`'s idiom):
  - 101 raw `uuids[]` → 422 with NO catalog query; a malformed uuid → 422; non-list
    (`uuids=abc`) → 422; empty/absent → `{"items":[]}` 200;
  - order: request `[C, A, B]` (all servable) → response items exactly `C, A, B`;
  - omission: inactive/deleted/other-tenant/seller-unavailable uuids omitted;
  - duplicates: `[A, A, B]` → `A, B` (first occurrence);
  - shape: each item's keys EXACTLY the allowlist; headers `Cache-Control` contains
    `private` and `no-store`;
  - the wishlist PAGE renders the shell hooks above, `aria-busy="true"`, hidden empty
    state, noscript copy, and the `data-shop-scope` attribute;
  - inertness: both routes 404 on the capability-disabled boot (extend the existing
    route matrix).
- [ ] **Step 2: red** (routes absent).
- [ ] **Step 3: Implement.** Controller: validate FIRST — there is NO existing pack
  validator; pin the SAME regex as Commerce's repositories as a controller constant:
  `private const PRODUCT_UUID_PATTERN = '/\A[A-Za-z0-9]{12}\z/';` — non-list shape,
  >100 raw values, or ANY value failing the pattern → 422 BEFORE any query (strict at
  the boundary; the repositories' drop semantics are the defensive second layer).
  Dedupe first-occurrence, then the batched pipeline (products → variants → addons →
  categories → media → urls) and `ProductCardViewModel` per uuid IN INPUT ORDER
  (skip absents). Page action renders the shell through the extracted
  `ShopPageRenderer`. **Cache posture pinned:** the wishlist PAGE participates in
  `ShopPageCache` exactly as the catalog pages do (read how index/category wire it and
  mirror — the shell is static and cacheable by design); the items ENDPOINT is never
  page-cached and sends `private, no-store`. Routes beside the cart/catalog registrations with the same
  middleware posture (read the file; the items endpoint is GET + public like
  `/_shop/cart`).
- [ ] **Step 4: green** + phpcs.
- [ ] **Step 5: Commit** —
  `git commit -m "feat(shop): wishlist page shell + bounded ordered resolution endpoint"`

---

### Task 8: Wishlist client + `wishlist-link` block

**Repo:** thallo.

**Files:**
- Modify: `packages/thallo-commerce/assets/shop.js` (wishlist store + hearts + page
  hydration + link badge + `buildProductCard()` refactor), `assets/shop.css` (hearts,
  filled state, wishlist grid, link badge)
- Create: `packages/thallo-commerce/templates/blocks/wishlist-link.twig`
- Modify: `packages/thallo-commerce/src/Starter/ShopBlockTypesContributor.php` (5th
  block, `SLUG_WISHLIST_LINK = 'wishlist-link'`), `app/Content/Regions/RegionDefinitions.php`
  (both palettes gain `wishlist-link`)
- Test: `tests/Integration/Commerce/ShopJsRuntimeTest.php` (wishlist-store scenarios) +
  `tests/Integration/Commerce/ShopBlocksTest.php` (block render/registration) +
  `tests/Integration/Render/RuntimeShopCoexistenceTest.php` (module count) +
  `tests/Integration/Commerce/StorefrontInertnessTest.php` (block absent when disabled) +
  `tests/Integration/Http/RegionAdminApiTest.php` (palette)

**Interfaces:**
- Consumes: Task 7's endpoint + page hooks; Task 5's heart attributes and
  `ProductCardViewModel` JSON shape; Task 4's `shop_wishlist_url()`/scope; the pinned
  storage/event contracts from Global Constraints.
- Produces: shop.js modules `shop-wishlist` (hearts + badges; selector
  `[data-shop-wishlist-toggle], [data-shop-wishlist-count]`) and `shop-wishlist-page`
  (selector `[data-shop-wishlist-page]`); function `buildProductCard(product)` returning
  a card element matching `_product_card.twig`'s structure.

- [ ] **Step 1: Failing node-harness tests** (extend ShopJsRuntimeTest in its established
  idiom — fake localStorage object per scenario):
  - store init: valid stored list round-trips and `ready` fires; corrupt JSON → resets
    to `[]`; `getItem` THROWS → hearts stay hidden, other modules still enhance
    (containment); `setItem` throws on the init round-trip → not ready, no reveal;
  - primitives: the store exposes `add(uuid)`, `remove(uuid)`, and `toggle(uuid)`
    (toggle DELEGATES: present → remove, absent → add). `add` unshifts newest-first;
    adding at 100 drops the tail; `add` of an already-present uuid is a NO-OP (the UI
    only ever toggles — this arm is defensive API surface); remove-then-re-add lands at
    the front (that IS newest-first). Each publish happens only AFTER a successful
    write; event shape exactly `thallo:wishlist-changed` with `{scope, uuids}`;
  - badge: hidden at 0, count text + visible at n>0; heart `aria-pressed` + label swap
    on toggle;
  - reconciliation: response omitting uuid X removes ONLY X when generation is latest
    and revision unchanged; a toggle during flight bumps revision → response ignored +
    one refetch scheduled; stale generation ignored; page hydration: cards painted in
    stored order via `buildProductCard`, `aria-busy` cleared AFTER settle, empty state
    shown only when the settled list is empty (never before);
  - cross-tab: a synthetic `storage` event with a new value re-sanitizes and publishes;
  - `buildProductCard` parity: PHP structural test in ShopBlocksTest — render
    `_product_card.twig` for a fixture product, and assert the node-built card (run the
    builder in the harness against the same ProductCardViewModel JSON) contains the same
    class hooks (`shop-grid__name`, price row, heart attributes). Exact-markup equality
    is NOT required; the pinned contract is the class/data/ARIA hook set.
- [ ] **Step 2: red.**
- [ ] **Step 3: Implement shop.js.** A storage ADAPTER (each op individually try/caught),
  the store (scope from the nearest `[data-shop-scope]` — root emission from Tasks 5/7 —
  no scope → never initialize), toggle/reconcile with `{storeRevision,
  requestGeneration}` gating exactly as the spec's §5 race rules, DOM wiring (hearts
  delegate on click; badges subscribe; page module fetches
  `/_shop/wishlist/items?uuids[]=…` and paints). Refactor `buildGridItem()` →
  `buildProductCard(product)` consumed by both the grid hydration and the wishlist page
  (keep the old name as a one-line alias if the grid module references it — check).
  Register both modules with the six existing ones (order: append after
  `shop-add-to-cart`; the exactly-once guard + catch-up pass handle the rest).
- [ ] **Step 4: Block + palettes.** `wishlist-link.twig` mirrors `mini-cart.twig`'s
  anatomy (icon + `hidden` count badge + optional label + link) but as a plain `<a>`
  via `{{ shop_wishlist_url() }}`; null URL → render the label as plain text (no dead
  link). Contributor entry mirrors the mini-cart definition (schema: optional
  `label` string). Palettes: add `'wishlist-link'` beside `'mini-cart'` in both.
  Spec §5 root emission: `wishlist-link.twig` AND the four existing shop block templates
  (`mini-cart`, `product-grid`, `featured-product`, `add-to-cart`) each gain
  `{% set shopScope = shop_wishlist_scope() %}{% if shopScope %} data-shop-scope="{{ shopScope }}"{% endif %}`
  on their root element — hydrated hearts inside builder-page blocks find the scope from
  the nearest root without a metadata fetch. ShopBlocksTest asserts the attribute on
  each block's rendered root.
- [ ] **Step 5: green across the six test files**, `node --check`, phpcs.
- [ ] **Step 6: Commit** —
  `git commit -m "feat(shop): device-local wishlist — store, hearts, page hydration, wishlist-link block"`

---

### Task 9: Gates, CHANGELOG, operator validation

**Repo:** thallo. Coordinator + one small implementer step.

- [ ] **CHANGELOG** `[Unreleased]` → `### Added`, top: one entry covering the storefront
  v1 redesign (Concept A cards + rail, honest cart modes, stepper with exponent-aware
  price label, device-local wishlist v1 with the named account-backed follow-up,
  Commerce 1.8.0 requirement). Commit with any final fixups.
- [ ] **Full gates:** thallo full suite green; phpcs clean; `node --check` shop.js;
  admin SPA untouched (no vitest run needed).
- [ ] **Coordinator validation gate** (browser, like the font track): purge render caches
  (`php glueful render:cache:clear`) and the shop cache tag; walk `/shop` (rail, tags,
  hover actions, direct-add PRG round trip), a product page (stepper math visually,
  heart), `/shop/wishlist` (save two, reload, order, remove), the `wishlist-link` block
  in the header (badge count, zero-hide); screenshots of the four surfaces for the
  operator. The operator's approval closes the track.

## Self-Review Notes

- Spec coverage: §2.1→Task 1; §2.2→Task 3; §2 rail/tags/hover→Task 5; §3→Tasks 5;
  §4→Task 6; §5 storage/endpoint/page/block/authority/races→Tasks 4, 7, 8; §6 tests
  distributed per task; release order→the Task 1→2 gate.
- The earlier draft's "re-save moves to front" pin contradicted the toggle-only UI and
  is replaced by explicit add/remove primitives (toggle delegates; duplicate add is a
  no-op); remove-then-re-add landing at the front is the only reachable re-save path.
- Type consistency: `ProductCardViewModel::toArray()` allowlist identical in Tasks 5/7/8;
  heart attributes (`data-shop-wishlist-toggle`, uuid, hidden, aria-pressed) identical in
  Tasks 5/6/8; module names `shop-buy`, `shop-wishlist`, `shop-wishlist-page`; policy v15
  once (Task 4).
