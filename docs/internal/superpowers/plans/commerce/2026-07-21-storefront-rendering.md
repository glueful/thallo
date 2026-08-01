# Storefront Rendering — Implementation Plan (Slice 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rendered shop pages + builder blocks over embedded commerce (catalog, cart, checkout) per `docs/superpowers/specs/commerce/2026-07-21-storefront-rendering-design.md` (authoritative; re-read the section named in each task).

**Architecture:** Four surfaces. `glueful/commerce` gains additive seams (SlugLifecycleAuthority + ProductSlugChanged, broad StorefrontCatalogChanged invalidation, putLine, CheckoutAttemptAuthority + typed replay, CheckoutPresentation) joining the unreleased 1.3.0 train — one commerce commit. `thallo-render` gains frozen contributor registries (reserved paths, template paths). `thallo-contracts`+app gain the block-type starter contributor and the shared CanonicalPublicOriginResolver. `packages/thallo-commerce` builds the storefront: URL authority, catalog pages + dimension-complete shop cache, slug ledger, CSRF + cookie custody, `/_shop` endpoints, checkout attempts, payment VMs, 4 blocks + templates + executable-tested `shop.js`.

**Tech Stack:** PHP 8.3, framework 1.71.0, Twig (render themes), PHPUnit, PostgreSQL (thallo PG-only; commerce SQLite + gated pgsql), vanilla JS (no build step).

## Global Constraints (verbatim from spec)

- Commerce 1.2.x is **published**: additive only, byte-parity unbound for EVERY seam; CHANGELOG under `[Unreleased]`; no version/pin bumps.
- **ALL thallo commits remain HELD** (user's standing decision) — thallo tasks finish code+tests+gates and stage NOTHING. The commerce repo commit (Task 4) is real.
- PRG + content negotiation on the same `/_shop` endpoints; JS never issues a second automatic native POST after an ambiguous started fetch; checkout/payment server-authoritative — never optimistically paid.
- `ShopUrlGenerator` is the ONLY URL source; shop prefix = one normalized path segment; routes register before Render's catch-all; product canonical = `/{prefix}/products/{slug}`; linked entries are route-less enrichment.
- CSRF v1 exact algorithm (spec §6): SameSite=Lax base; if `Sec-Fetch-Site` is present it must be `same-origin`, then ALWAYS require either `Origin` exact-match canonical origin or, when Origin is absent, `Referer` exact-match; both absent → 403 even when Fetch Metadata passed. Expected origin comes from `CanonicalPublicOriginResolver`, NEVER the request `Host`.
- Cart token + guest credentials ONLY in `Secure; HttpOnly; SameSite=Lax` cookies; guest cookie encrypted (AAD-bound), max 5 entries oldest-evicted, `thallo_commerce.guest_confirmation_days` (default 30, bounded 1–90) retention.
- Storefront cart writes use convergent `putLine()` desired-quantity semantics — never incrementing `addLine()`.
- Checkout attempts: Commerce-owned `CheckoutAttemptAuthority` coordinated INSIDE Commerce's placement transaction; order + attempt share ONE real commit; `OrderPlaced` dispatch + `PaymentCollector::initiate()` strictly post-commit; replay never re-dispatches `OrderPlaced`.
- Payment rendering only via the closed `CheckoutPresentation` VM (`manual | redirect | reference | unavailable`); redirect URLs validated absolute https; return/cancel routes never mutate payment state.
- Contributor registries: contributions during provider boot; one deterministic snapshot after all providers boot, frozen on first read; late registration = loud boot error; ordering `(priority, contributor_id)`; duplicates rejected (never first-wins); zero contributors byte-identical.
- Shop cache keyed `(tenant, resolved locale, active theme, appearance fingerprint, path, page)`, page bounded to integer `1..1000`; invalid pages 404 without caching, foreign params bypass, RenderPageCache untouched. `StorefrontCatalogChanged` purges only the affected tenant for every storefront-visible mutation; global theme/appearance events purge a shared global shop tag; cart/checkout/return/cancel/confirmation + `/_shop` = `private, no-store` + noindex.
- Asset serving: exact boot-built filename→file allowlist; never concatenate route input into a path; `public, max-age=31536000, immutable` (the custom.css precedent).
- Packs never reference `App\`; pack-consumable contracts live in `thallo-contracts`. No AI attribution anywhere. Spec/plan stay uncommitted.

**Verified insertion points (for implementers):** `CheckoutService` — cart lookup pre-txn at :144, txn opens :167, `OrderPlaced` dispatch :204 + `initiatePayment()` :205 both post-txn, returns `['order','guest_token','payment']`, `quote()` :100. `CommerceServiceProvider` constructs `CheckoutService`, so Task 3 must soft-resolve and inject the optional attempt authority there. `CartService` — `addLine` :102, `setLineQuantity` :161, claim protocol inside. `CatalogService` — slug pre-check in `applyProductPatch()` :672; create path builds the product row inside its txn. Render — `ReservedPaths`/`TwigFactory`/`ThemeLocator` built by provider factories (`RenderServiceProvider` :63/:87/:91); purge idiom = `invalidateTags(['thallo:render:page'])` with container-resolved `CacheStore`. Immutable-asset header precedent: RenderController custom.css :379. `StarterBlockTypes` definition shape is `slug/label/icon/category/description/schema[]` and contains no repeatable scalar field; the manual product list is therefore pinned as newline-delimited `text`. Existing trusted-origin logic is in `app/Content/Media/TenantBlobPublicUrlProvider.php`; single-store base URL is `config('app.urls.base')` (also used by `CreateAdminCommand`). Existing executable vanilla-JS precedent: `tests/Integration/Render/ColorModeRuntimeTest.php` launches Node with a hand-stubbed DOM.

---

## Repo A: glueful/commerce (BASE: dev tip; ONE commit after Task 4)

### Task 1: catalog lifecycle seams (`SlugLifecycleAuthority` + storefront invalidation)

**Files:** Create `src/Catalog/SlugLifecycleAuthority.php`, `src/Events/ProductSlugChanged.php`, `src/Events/StorefrontCatalogChanged.php`, `src/Catalog/StorefrontCatalogChangeDispatcher.php`. Modify `src/Catalog/CatalogService.php` (create + `applyProductPatch` rename paths and all storefront-visible product/variant mutations), `src/Catalog/ProductMediaService.php`, `CategoryService.php`, `TagService.php`, `AttributeService.php`, `AddonService.php`, the stock write repository/service paths used by inventory adjustments AND checkout/refund/cancel, and `src/CommerceServiceProvider.php` (soft authority consumption + shared dispatcher). Test `tests/Integration/Catalog/SlugLifecycleTest.php`, `StorefrontCatalogChangedTest.php`.

**Interfaces (produces):**
```php
namespace Glueful\Extensions\Commerce\Catalog;

interface SlugLifecycleAuthority
{
    /** Claim the proposed current slug; throw (422-shaped ValidationException) when reserved. */
    public function prepareCreate(ApplicationContext $c, string $tenant, string $productUuid, string $slug): void;

    /** Claim old+new, validate new, reserve old — same transaction as the rename. */
    public function prepareRename(ApplicationContext $c, string $tenant, string $productUuid, string $old, string $new): void;
}
```
`ProductSlugChanged extends BaseEvent` — readonly `tenantUuid, productUuid, oldSlug, newSlug`; dispatched after-commit (mirror `ProductDeleted`'s afterCommit + soft-resolve idiom exactly), slug-ledger/cache-invalidation ONLY.

`StorefrontCatalogChanged extends BaseEvent` — readonly `tenantUuid`, nullable `productUuid`, and reason constrained to exactly `product.created | product.updated | product.status_changed | product.deleted | variant.changed | stock.changed | media.changed | category.changed | tag.changed | attribute.changed | addon.changed`; dispatched after commit by `StorefrontCatalogChangeDispatcher`. Inventory every storefront-visible write path before editing and pin coverage for product create/update/status/delete, variants/prices, stock changes (including checkout/refund/cancel), media, categories, tags, attributes, and add-ons. Broad taxonomy events may carry null productUuid because arbitrary grids/archives can change. Reviews and download-entitlement definitions are excluded because slice 2 does not project them. Event dispatch is additive/fault-isolated and unobserved installs retain business behavior.

- [ ] **RED:** unbound → create/rename byte-identical (existing catalog tests green, no new queries). Bound stub: `prepareCreate` invoked INSIDE the create txn with the pre-generated product uuid BEFORE the product insert (product uuid must be generated before the txn/insert — restructure minimally if currently generated at insert time; report); `prepareRename` invoked inside the rename txn BEFORE the product update; an authority throw rolls back the whole create/rename; rename dispatches exactly one after-commit `ProductSlugChanged` (rollback → none; non-slug patch → none). A listener spy proves each named storefront-visible mutation family emits `StorefrontCatalogChanged` only after commit; rollback emits none; stock changes through checkout/refund/cancel are each covered so direct repository writes cannot bypass invalidation; reason vocabulary is closed and poison tenant data never crosses events.
- [ ] **Implement + GREEN + gates** (`composer test`, phpcs, analyze). **No commit yet.**

### Task 2: convergent `putLine()`

**Files:** Modify `src/Cart/CartService.php` (new method beside `addLine` :102, same claim protocol). Test `tests/Integration/Cart/PutLineTest.php`.

**Interfaces (produces):** `putLine(ApplicationContext $c, array $cart, string $variantUuid, int $quantity, array $addons = []): array` — atomically insert-or-set the matching line (same variant+addon identity `addLine` uses) to the DESIRED quantity under the existing cart claim; `quantity <= 0` removes the line; returns the same shape `addLine` returns. `addLine()` byte-untouched.

- [ ] **RED:** put twice with qty 2 → ONE line, qty 2 (vs addLine's 4); put new line inserts; put qty 0 removes; stock/validation rules identical to addLine (insufficient stock → same 422); claim serialization preserved (concurrent put converges — deterministic test; live race rides Task 12's matrix if needed); `addLine` behavior regression-pinned.
- [ ] **Implement + GREEN + gates.** **No commit yet.**

### Task 3: `CheckoutAttemptAuthority` coordination (CONCURRENCY CORE — opus-reviewed)

**Files:** Create `src/Orders/CheckoutAttemptAuthority.php`, `src/Orders/CheckoutAttemptContext.php`, `src/Orders/CheckoutAttemptReplay.php`. Modify `src/Orders/CheckoutService.php::placeOrder()` (+ optional collaborator) AND `src/CommerceServiceProvider.php` (soft-resolve `CheckoutAttemptAuthority` and inject it into the checkout-service factory). Test `tests/Integration/Orders/CheckoutAttemptTest.php`.

**Interfaces (produces):**
```php
namespace Glueful\Extensions\Commerce\Orders;

final class CheckoutAttemptContext
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $requestFingerprint,
    ) {}
}

final readonly class CheckoutAttemptReplay
{
    public function __construct(
        public string $orderUuid,
        public string $orderRef,
        public string $guestCredential,
    ) {}
}

interface CheckoutAttemptAuthority
{
    /**
     * Called INSIDE the placement transaction, before cart validation.
     * Returns null (proceed as a new attempt) or a typed replay result.
     * Throws a 409-shaped exception on same-key/different-fingerprint.
     */
    public function claimOrReplay(ApplicationContext $c, string $tenant, CheckoutAttemptContext $ctx): ?CheckoutAttemptReplay;

    /** Called INSIDE the same transaction after the order exists: bind the attempt to it. */
    public function complete(ApplicationContext $c, string $tenant, CheckoutAttemptContext $ctx, string $orderUuid, string $orderRef, string $rawGuestToken): void;
}
```
`placeOrder(..., ?CheckoutAttemptContext $attempt = null)`: move the active-cart lookup (currently pre-txn :144) INSIDE the existing placement transaction (:167). `CommerceServiceProvider` injects a soft-resolved nullable authority; with an authority bound AND `$attempt` non-null: `claimOrReplay` FIRST inside the txn — typed replay → return the stored order (reload row) + stored credential, skip cart validation, skip `OrderPlaced`, then post-commit re-run `initiatePayment` for that order (collector contractually idempotent by payable); new attempt → place normally, `complete(...)` inside the same txn after the order insert. Null authority or null context → byte-identical today (zero attempt queries). `OrderPlaced` dispatch (:204) + `initiatePayment` (:205) stay strictly post-commit.

- [ ] **RED:** byte-parity (null/unbound — existing checkout suites green; cart-lookup move proven behavior-neutral: an inactive cart still 422s identically); new attempt → `complete` called inside the txn (a forced post-order-insert failure rolls back BOTH order and attempt binding — the one-commit proof); replay returns the same logical order + credential, dispatches NO second `OrderPlaced`, and re-initiates payment post-commit; fingerprint mismatch → 409-shaped throw propagates (whole txn rolls back, no order); a fake authority reading on a SECOND DB connection during placement cannot see the pending attempt before commit (transaction-visibility proof).
- [ ] **Implement + GREEN + gates.** **No commit yet.**

### Task 4: `CheckoutPresentation` + CHANGELOG → **the commerce commit**

**Files:** Create `src/Orders/CheckoutPresentation.php`. Modify `src/CommerceServiceProvider.php` (register shared), `CHANGELOG.md` (`[Unreleased]`, one block covering Tasks 1–4). Test `tests/Unit/Orders/CheckoutPresentationTest.php`.

**Interfaces (produces):** `CheckoutPresentation::present(array $paymentResult): array` — classifies `placeOrder()`'s `payment` array (wrapping `PaymentInitiation` fields: provider/status/payload) into the closed VM: `['action' => 'manual'|'redirect'|'reference'|'unavailable', ...allowlisted fields]`. `manual`: read `ManualPaymentCollector`'s ACTUAL payload keys (read the class; allowlist exactly those display fields). `redirect`: payload URL key(s) → absolute `https` (parse; scheme+host required) else `unavailable`. `reference`: opaque reference + display fields. Anything unknown → `unavailable` + `error_log`, NEVER raw passthrough.

- [ ] **RED:** manual collector's real output → `manual` VM with exactly the allowlisted keys (poison extra payload keys → absent); an `http://` or relative or javascript: URL → `unavailable`; valid https → `redirect`; unknown provider shape → `unavailable` + logged; no raw `payload` ever in the VM (poison-marker assert).
- [ ] **GREEN + gates**, CHANGELOG block (slug lifecycle authority + slug-changed event + broad storefront-catalog event, putLine, typed checkout attempt authority + provider injection, checkout presentation — additive host seams, byte-parity unbound, no schema/env changes). **COMMIT (commerce, T1–T4):** explicit add of all new/modified src+tests+CHANGELOG → `feat(storefront): catalog lifecycle, convergent put-line, checkout attempt authority, and payment presentation seams`.

---

## Repo B: thallo (ALL tasks below: code+tests+gates, NOTHING committed/staged)

### Task 5: render contributor registries (reserved paths + template paths)

**Files:** Create `packages/thallo-render/src/Contribution/ReservedPathContributor.php`, `TemplatePathContributor.php`, `RenderContributionRegistry.php`. Modify `packages/thallo-render/src/RenderServiceProvider.php` (`makeReservedPaths`, `makeThemeLocator`/`makeTwigFactory` consume the frozen snapshot), `packages/thallo-render/src/ThemeLocator.php` (contributed template dirs between app theme and pack default). Test `tests/Integration/Render/RenderContributionTest.php`.

**Interfaces (produces):**
```php
namespace Thallo\Render\Contribution;

interface ReservedPathContributor
{
    public function contributorId(): string;
    public function priority(): int;                 // ordering: (priority, contributor_id)
    /** @return list<string> */ public function reservedPrefixes(): array;
    /** @return list<string> */ public function reservedExacts(): array;
}

interface TemplatePathContributor
{
    public function contributorId(): string;
    public function priority(): int;
    /** @return list<string> absolute template dirs */ public function templatePaths(): array;
}

final class RenderContributionRegistry   // one instance, both kinds
{
    public function registerReservedPaths(ReservedPathContributor $c): void;   // throws after freeze
    public function registerTemplatePaths(TemplatePathContributor $c): void;   // throws after freeze
    /** @return array{prefixes: list<string>, exacts: list<string>} */ public function frozenReserved(): array;
    /** @return list<string> */ public function frozenTemplatePaths(): array;  // first read freezes BOTH
}
```
Freeze semantics (spec §5.1 verbatim): contributions accepted throughout provider boot; first `frozen*()` read takes ONE deterministic snapshot (sorted `(priority, contributorId)`); later registration → loud `\RuntimeException` at boot; duplicate contributor ids OR duplicate reserved paths/template dirs → throw (never first-wins). ThemeLocator template chain becomes: app theme → frozen contributed dirs (in order) → render default.

- [ ] **RED:** zero contributors → ReservedPaths + template chain byte-identical (existing render tests green); a contributed prefix 404s through the catch-all; contributed template dir resolves BETWEEN app theme and default (override matrix: app theme wins over pack; pack wins over render default); late registration after first read → RuntimeException; duplicate id/path → throw; ordering deterministic across permuted registration order. A real-provider-order integration boot proves `thallo-render` does not freeze either registry before `thallo-commerce` contributes, using the actual `config/extensions.php` order rather than only direct registry tests.
- [ ] **Implement + GREEN + gates** (thallo suite + phpcs + pack-boundaries). **NO commit.**

### Task 6: block-type starter contributor + canonical public-origin contract (contracts + app)

**Files:** Create `packages/thallo-contracts/src/Starter/StarterBlockTypeDefinition.php`, `StarterBlockTypeContributor.php`, `StarterBlockTypeRegistry.php` (interface), `packages/thallo-contracts/src/Delivery/CanonicalPublicOriginResolver.php`; `app/Content/Starter/DefaultStarterBlockTypeRegistry.php`, `app/Content/Delivery/ThalloCanonicalPublicOriginResolver.php`. Modify `app/Content/Starter/Kinds/BlockTypeKind.php` (validate + append), `app/Content/Media/TenantBlobPublicUrlProvider.php` (delegate origin selection to the shared contract), `app/Providers/ThalloServiceProvider.php` (bind shared). Test `tests/Integration/Content/Starter/BlockTypeContributorTest.php`, `tests/Integration/Content/Delivery/CanonicalPublicOriginResolverTest.php`, and the existing tenant-blob URL tests.

**Interfaces (produces):**
```php
namespace Thallo\Contracts\Starter;

final readonly class StarterBlockTypeDefinition
{
    /** @param list<array<string,mixed>> $schema  StarterBlockTypes field-entry shape */
    public function __construct(
        public string $sourceId,
        public string $slug,
        public string $label,
        public string $icon,
        public string $category,
        public ?string $description,
        public array $schema,
    ) {}
}

interface StarterBlockTypeContributor
{
    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array;
}

interface StarterBlockTypeRegistry
{
    public function register(StarterBlockTypeContributor $contributor): void;
    /** @return list<StarterBlockTypeContributor> */
    public function all(): array;
}

namespace Thallo\Contracts\Delivery;

interface CanonicalPublicOriginResolver
{
    /** Return normalized scheme://host[:port] for the current request-bound tenant. */
    public function currentOrigin(ApplicationContext $c): string;

    /** Return normalized scheme://host[:port] for an explicitly owned tenant resource. */
    public function originForTenant(ApplicationContext $c, string $tenantUuid): string;
}
```
`BlockTypeKind::definitions()` = fixed `StarterBlockTypes::definitions()` + converted contributions — validation BEFORE return (scalar fields; schema entries validated through the same mechanism the fixed block schemas satisfy; duplicate `sourceId`/`slug` across the full set → throw), mirroring slice-1's `ContentTypeKind` exactly.

`ThalloCanonicalPublicOriginResolver` is the ONE trusted-origin algorithm: `currentOrigin()` resolves the current tenant and delegates to `originForTenant()` when enforcement is active; single-store uses normalized `config('app.urls.base')`. `originForTenant()` applies the existing `TenantBlobPublicUrlProvider` precedence exactly (default tenant → first configured default host; otherwise first verified active custom domain; otherwise active tenant slug + base domain; throw if no trustworthy origin exists). Preserve configured scheme and explicit non-default port. Refactor `TenantBlobPublicUrlProvider` to keep its owner lookup, then call `originForTenant()` and compose the blob path; no duplicate host-selection code remains. Packs consume only the contract and never import `App\`.

- [ ] **RED (starter):** zero contributors byte-identical (existing starter/seed tests green); a stub contribution appears, provisions into a fresh tenant, syncs idempotently via `thallo:tenant:sync --kind=block_type` (verify the kind name `BlockTypeKind::kind()` returns and use it exactly); duplicate slug vs a FIXED block → throw before any write; malformed schema entry → throw naming the sourceId.
- [ ] **RED (origin):** single-store app base URL (including explicit port); enforced current default tenant/default host; verified custom domain; tenant subdomain fallback; readiness/missing-domain failure all match the pre-refactor media behavior. `TenantBlobPublicUrlProvider` owner lookup + `originForTenant()` and direct contract calls return identical origins; `currentOrigin()` selects the request-bound tenant, hostile request `Host` is ignored, and no pack references `App\`.
- [ ] **Implement + GREEN + gates.** **NO commit.**

### Task 7: `ShopUrlGenerator` + routes + catalog pages

**Files:** Create `packages/thallo-commerce/src/Shop/ShopUrlGenerator.php`, `src/Http/Shop/ShopCatalogController.php`, `src/Shop/ViewModels/ProductViewModel.php` + `CategoryViewModel.php` + `GridViewModel.php` (closed projections), `routes/shop-routes.php`, templates `packages/thallo-commerce/templates/shop/{index,product,category}.twig`. Modify `CommerceIntegrationServiceProvider.php` (reserved-path + template-path contributions OUTSIDE the gate registration but routes INSIDE `isEnabled('thallo.commerce')`; config `thallo-commerce.shop_prefix` default `'shop'` normalized/validated one segment; inject the existing locale manager used by Render and expose the resolved/default locale to the shop rendering context). Test `tests/Integration/Commerce/ShopCatalogTest.php`.

**Interfaces (produces):** `ShopUrlGenerator`: `shopIndex()`, `product(string $slug)`, `category(string $slug)`, `cart()`, `checkout()`, `paymentReturn(string $ref)`, `paymentCancel(string $ref)`, `confirmation(string $ref)`, `assets(string $file)` — all `/`-prefixed paths; the ONLY URL source. Catalog reads: in-process commerce services/`CatalogReader` under the slice-1 tenant resolution; product detail merges the closed commerce VM + the linked enrichment entry's rendered blocks region (slice-1 `resolveByProduct` + the render pipeline used for entry blocks — reuse how Render renders an entry's blocks region; report the mechanism). Canonical + JSON-LD product structured data point at `product($slug)`; noindex meta on nothing here (catalog is indexable).

- [ ] **RED:** routes 404 with the capability off and resolve with it on (registered before the catch-all — a builder page at `shop` cannot shadow the prefix; reserved); prefix misconfig (`a/b`, empty) → boot error; product page renders commerce data alone (unlinked) and enrichment blocks (linked); tombstoned/cross-tenant → non-revealing 404; category archive lists live products only; all URLs in markup come from ShopUrlGenerator (grep-style template assert on a rendered page); closed VMs (poison internal columns absent).
- [ ] **Implement + GREEN + gates.** **NO commit.**

### Task 8: shop cache + pack slug authority + 301s

**Files:** Create `packages/thallo-commerce/src/Shop/ShopPageCache.php` (middleware), `src/Shop/PackSlugLifecycleAuthority.php`, `migrations/003_CreateProductSlugLedger.php` (`thallo_commerce_product_slugs`: tenant_uuid, slug, product_uuid, created_at; unique `(tenant_uuid, slug)`), purge listeners (`PurgeShopCacheOnCatalogChange`, `...OnSlugChange`, `...OnLinkChange`, `...OnThemeChange`, `...OnAppearanceChange`). Modify provider (bind the commerce `SlugLifecycleAuthority` — outside the gate, interface_exists-guarded like slice 1's resolution binding; wire middleware onto catalog routes; listeners outside the gate). Test `tests/Integration/Commerce/ShopCacheTest.php`, `tests/Integration/Commerce/SlugLifecycleRaceTest.php` (+ proc_open fixture child).

**Interfaces:** `ShopPageCache` keyed `(tenant, resolvedLocale, activeTheme, appearanceFingerprint, path, page)` — locale/theme/appearance come from trusted render services, never query input; appearance fingerprint = stable hash of resolved accent+neutral values (there is no revision counter). `page` is exactly one integer in `1..1000` (invalid/out-of-range → 404 and no cache write); any other query param present → bypass; preview/private bypass mirrors RenderPageCache's attribute check. Tags are both `thallo:shop:catalog:{tenant}` and global `thallo:shop:catalog`; purge = `invalidateTags` (container-resolved CacheStore, the render listener idiom). `StorefrontCatalogChanged` is the primary content-freshness signal and purges only its tenant; link and slug events cover pack-owned state/redirect changes; the existing tenantless `ThemeChanged`/`ThemeAppearanceChanged` events purge the global tag (the key dimensions also make stale entries unreachable). `PackSlugLifecycleAuthority` (spec §4 verbatim): every claim takes `pg_advisory_xact_lock(hashtextextended(?, 0))` on `thallo_commerce_slug:{tenant}:{slug}` keys — create locks the proposed slug; rename locks old+new sorted; under the locks `prepareRename` rejects a new slug reserved for a DIFFERENT product (`ValidationException::forField('slug', ...)`), inserts the OLD reservation idempotently, removes a NEW reservation held by the same product (A→B→A). Product route: current-slug miss → ledger lookup → 301 to canonical (live-product-wins loop safety).

- [ ] **RED (cache):** page-2 cached separately from page-1; page 0/1001/non-integer → 404 with zero cache write; foreign query param bypasses; locale, theme, and appearance changes produce distinct keys; global theme/appearance events purge the global tag. Product create/update/status/delete, variant/price, stock via normal adjustment + checkout/refund/cancel, media, category, tag, attribute, and add-on event cases each purge the tenant tag; a poison second tenant's cache remains intact. Slug/link changes purge pack-owned state; cart/checkout/`/_shop` never cached (no-store asserted in T9/T10 but the middleware never wraps them — assert wiring).
- [ ] **RED (slug):** rename reserves old slug; another product's create onto the reserved slug → 422; rename onto it → 422; A→B→A round-trip clean; old-slug URL → 301 → canonical → 200; ledger row whose slug equals a live product's current slug → live wins (no redirect loop); TWO-CONNECTION races both orderings: create-vs-rename claiming the same slug → one winner one 422; rename-vs-rename crossing → serialized, consistent ledger.
- [ ] **Implement + GREEN + gates.** **NO commit.**

### Task 9: CSRF + custody + `/_shop` cart endpoints + cart page + mini-cart JSON

**Files:** Create `packages/thallo-commerce/src/Http/Shop/ShopCsrfGuard.php` (middleware), `src/Http/Shop/CartCookie.php`, `src/Http/Shop/ShopCartController.php`, `src/Shop/ViewModels/CartViewModel.php`, template `templates/shop/cart.twig`, routes additions (`/_shop/cart/*`, `GET /_shop/cart`, `GET /cart`). Modify provider to inject the Task-6 `CanonicalPublicOriginResolver` contract directly. Test `tests/Integration/Commerce/ShopCsrfTest.php`, `ShopCartTest.php`.

**Interfaces:** `ShopCsrfGuard` receives `Thallo\Contracts\Delivery\CanonicalPublicOriginResolver` and compares only normalized `scheme://host[:port]`; NEVER `Host`. It implements spec §6 exactly: first reject a present non-`same-origin` `Sec-Fetch-Site`; then independently require exact `Origin`, or exact `Referer` only when Origin is absent; no Origin/Referer always rejects even when Fetch Metadata passed (403 JSON/PRG-safe). `CartCookie`: name `thallo_cart`, `Secure; HttpOnly; SameSite=Lax`, TTL `commerce.cart.ttl_days` days; raw token value only. Endpoints call `CartService` in-process: add/update → **`putLine`** (desired quantity; NEVER `addLine`), remove → `putLine(0)` or the line-remove primitive, discount apply/remove; every response a closed VM; content negotiation: `Accept: application/json` → JSON, else 303 to `cart()` (or `Referer`-returned shop page when same-origin). `GET /_shop/cart` and `/cart` page: `private, no-store` + noindex.

- [ ] **RED (CSRF matrix, spec §11 verbatim):** cross-origin `Origin` → 403; `Sec-Fetch-Site: cross-site` → 403 even with matching Origin; `Sec-Fetch-Site: same-origin` with no Origin/Referer → 403; no signal at all → 403; absent Origin + exact same-origin Referer → 200; spoofed `Host` does NOT alter the expected origin (set a hostile Host, still validated against the shared origin contract); single-store/default-host/custom-domain/subdomain origins match media URL generation; same-origin PRG works; JSON negotiation works.
- [ ] **RED (cart):** first mutation mints the cookie (attributes exact); token never in body/URL/markup; identical add replayed → ONE line at desired qty (putLine proof through the HTTP surface); update/remove/discount round-trips; cart page + mini-cart JSON no-store; closed VMs poison-checked.
- [ ] **Implement + GREEN + gates.** **NO commit.**

### Task 10: checkout + attempts + payment pages (CONCURRENCY CORE — opus-reviewed)

**Files:** Create `packages/thallo-commerce/src/Shop/PackCheckoutAttemptAuthority.php`, `migrations/004_CreateCheckoutAttempts.php` (spec §7 columns; unique `(tenant_uuid, idempotency_key)`), `src/Http/Shop/ShopCheckoutController.php`, `src/Http/Shop/GuestOrderCookie.php`, `src/Shop/ViewModels/CheckoutViewModel.php` + `ConfirmationViewModel.php`, templates `templates/shop/{checkout,confirmation}.twig`, `src/Console/PurgeCheckoutAttemptsCommand.php` (`thallo:commerce:checkout:purge-attempts`), routes (`/checkout`, `/checkout/return/{ref}`, `/checkout/cancel/{ref}`, `/checkout/confirmation/{ref}`, `POST /_shop/checkout/quote|place`). Modify provider (bind the commerce `CheckoutAttemptAuthority` outside the gate, guarded; routes inside; config `thallo_commerce.guest_confirmation_days` default 30 clamped 1–90). Test `tests/Integration/Commerce/ShopCheckoutTest.php`, `ShopCheckoutRaceTest.php` (+ fixture child).

**Interfaces:** `PackCheckoutAttemptAuthority` (spec §7 verbatim): `claimOrReplay` takes `pg_advisory_xact_lock(hashtextextended(?, 0))` on `thallo_commerce_attempt:{tenant}:{key}` then re-reads — completed+same fingerprint → `CheckoutAttemptReplay`; different fingerprint → 409-shaped throw; absent → insert `pending` row; `complete` updates the row to `completed` + order uuid/ref + `guest_credential_ciphertext` (EncryptionService, AAD `checkout.attempt:{tenant}:{key}`) — all inside Commerce's placement txn. `GuestOrderCookie`: encrypted blob (AAD `shop.orders:{tenant}`), max 5 `(ref, token)` entries oldest-evicted, `Secure; HttpOnly; SameSite=Lax`, `guest_confirmation_days` expiry. Controller: `quote` → `CheckoutService::quote` VM; `place` → key from JS header/form field (no-JS form mints one at checkout-page render), fingerprint = sha256 of the canonicalized payload, calls `placeOrder(..., new CheckoutAttemptContext(...))`, stores the credential cookie, renders/returns the **`CheckoutPresentation`** VM (redirect action → 303 to the validated URL on PRG, JSON `{action:redirect,url}` for JS); return/cancel/confirmation: decrypt cookie, match `{ref}`, re-read order state in-process — NEVER mutate payment state; distinguish `pending_payment|paid|fulfilled|canceled|payment failure`. Purge command deletes attempts older than the window; tenant-safe.

- [ ] **RED:** manual e2e (place → pending_payment + manual VM → confirmation shows pending); fake REDIRECTING collector (test-bound `PaymentCollector`) → redirect VM with validated URL, PRG 303s to it, JS JSON carries it; return-before-webhook stays pending (return route mutates nothing — DB state asserted unchanged); simulated webhook transition (drive commerce's payment-confirm path) visible on confirmation refresh; duplicate place same key+fingerprint → same order_ref, ONE `OrderPlaced` (listener spy), credential re-delivered; different fingerprint → 409; TWO-CONNECTION race: simultaneous first use of one key → one completed attempt/order + one replay (both orderings); a fake collector reading on a second connection sees the COMMITTED order (post-commit initiation proof); crash-after-commit-before-initiation repaired by replay (re-initiate same payable); cookie capped at 5 oldest-evicted, encrypted (raw token absent from cookie bytes), expiry honored, wrong/absent credential → 404 on confirmation/return/cancel; purge command respects the window.
- [ ] **Implement + GREEN + gates.** **NO commit.**

### Task 11: blocks + templates + `shop.js` + fingerprinted assets

**Files:** Create `packages/thallo-commerce/src/Starter/ShopBlockTypesContributor.php` (implements `StarterBlockTypeContributor`; the 4 definitions), block templates `templates/blocks/{product-grid,featured-product,add-to-cart,mini-cart}.twig`, `assets/shop.js`, `src/Http/Shop/ShopAssetController.php` + `src/Shop/ShopAssetMap.php` (boot-built filename→path+hash allowlist), route `GET /_shop/assets/{file}`. Modify provider (block contribution and asset route/map INSIDE the capability gate), `ShopUrlGenerator::assets()`. Test `tests/Integration/Commerce/ShopBlocksTest.php` and `tests/Integration/Commerce/ShopJsRuntimeTest.php` (Node + hand-stubbed DOM, following `ColorModeRuntimeTest`).

**Interfaces:** the 4 `StarterBlockTypeDefinition`s (sourceIds `thallo-commerce:{slug}`): `product-grid` (`source` enum category|tag|manual|newest, `category_slug` string, `tag_slug` string, `products` **text** containing exactly one product slug per line; normalize by trim, remove blanks, stable ordered dedupe, cap at 50, reject overflow and reject comma-delimited input rather than guessing), `page_size` (enum small|medium|large mapped to 12/24/48); `featured-product` (`product_slug` string); `add-to-cart` (`product_slug` string optional — falls back to the enriched product context); `mini-cart` (no fields). Grid blocks render page 1 with "view all" links to canonical routes (never query-paginated on builder pages). `shop.js`: form interception + JSON re-submit + drawer/count/inline updates + focus/`aria-live` + double-submit disable + NO automatic re-POST or native fallback after an ambiguous started fetch (explicit-retry UI preserving the checkout key) + native submit only when interception fails pre-request. `ShopAssetMap`: built at boot from the pack `assets/` dir (content-hash), controller resolves EXACT allowlisted names only (`shop-{hash}.js`), immutable headers (`public, max-age=31536000, immutable`); unknown/traversal names → 404.

- [ ] **RED (blocks/assets):** the 4 block types provision/sync via the T6 contributor (capability-gated); newline manual list normalizes/deduplicates in stable order, caps at 50, and rejects comma-delimited input; each block renders themed markup (with/without enrichment context; add-to-cart on a variant product renders controls or a detail link — never an invalid-line form); grid pagination links go to canonical routes; asset URL from `ShopUrlGenerator::assets()` is fingerprint-stable, serves immutable headers; `../`/unknown names → 404.
- [ ] **RED (executable JS):** launch Node with a hand-stubbed DOM and mocked fetch, load the exact served `shop.js`, then prove: submit interception issues one JSON POST; success updates mini-cart count/line totals/quote regions; focus and `aria-live` announcement move to the result/error target; a second submit while pending is suppressed; an ambiguous rejected fetch issues exactly one POST and never invokes native form submission; only an explicit user retry makes a second POST and preserves the checkout idempotency key. String-marker assertions and manual runtime verification do not satisfy this gate.
- [ ] **Implement + GREEN + gates.** **NO commit.**

### Task 12: gates — e2e matrix + CHANGELOG/README

**Files:** Create `tests/Integration/Commerce/StorefrontWalkTest.php`, `StorefrontInertnessTest.php`. Modify `CHANGELOG.md` (thallo `[Unreleased]` slice-2 entry), `packages/thallo-commerce/README.md` (storefront section). Fix-forward what the tests reveal (report production changes separately). Test: as above + BOTH full suites.

- [ ] **Walk:** seed catalog + enrichment → browse index/category/product (cache hit second read) → mutate price, stock, media, taxonomy, and add-on data and prove the next request is fresh → putLine add → cart → checkout place (manual) → confirmation pending — across the three tenancy modes on product detail + cart + checkout; capability off → all shop routes 404, blocks absent, catch-all untouched, `/_shop` absent.
- [ ] **Byte-parity re-asserts:** commerce with no authority/presentation consumers unchanged and no storefront-event listener incurs no new query/business result (its own suite green — run it); render zero-contributor identical; BlockTypeKind zero-contributor identical; media URL output remains identical after the shared-origin refactor.
- [ ] Full thallo suite + phpcs + pack-boundaries; executable Node storefront runtime gate (skip only when Node is genuinely unavailable, while structural asset assertions still run); full commerce suite at /Users/michaeltawiahsowah/Sites/glueful/extensions/commerce. **NO commit** — report the complete uncommitted file inventory for the user's go-ahead.

---

## Self-Review

- **Spec coverage:** §1 → T7/T9/T10/T11 (interaction/routing/inventory/payments); §2 table → T1–T4 / T5 / T6 / T7–T12; §3 → T7 (+T10 routes); §4 → T1 (seam) + T8 (impl/301/races); §5.1/§5.2 → T5 (+T11 assets); §6 → T6 origin contract + T9 guard/custody (+T2 putLine, T10 guest cookie); §7 → T3 (seam/provider injection/typed replay) + T10 (impl); §8 → T4 (+T10 rendering); §9 → T1 broad event + T8 dimension-complete cache (+T9/T10 no-store); §10 → T11 executable Node gate; §11 distributed + T12; §12 honored.
- **Type consistency:** `SlugLifecycleAuthority.prepareCreate/prepareRename` (T1=T8); `StorefrontCatalogChanged` (T1=T8); `putLine` (T2=T9); `CheckoutAttemptContext`/`CheckoutAttemptReplay`/`claimOrReplay`/`complete` (T3=T10); `CheckoutPresentation::present` (T4=T10); registry/freeze API (T5=T7/T11 contributions); `StarterBlockTypeDefinition` (T6=T11); `CanonicalPublicOriginResolver` (T6=T9 and media); `ShopUrlGenerator` methods (T7=T8/T9/T10/T11).
- **Placeholders:** none. The manual-list schema is pinned to newline-delimited `text` (50 stable-deduped slugs); trusted-origin resolution is pinned to `CanonicalPublicOriginResolver` with one app implementation; JavaScript behavior is pinned to an executable Node DOM test rather than manual verification.
