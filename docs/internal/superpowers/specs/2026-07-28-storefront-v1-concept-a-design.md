# Storefront v1 — Concept A ("Gallery") — Design

**Date:** 2026-07-28
**Status:** Draft for review (approved concept: artifact `ce6f1b4f`, Concept A with
hover actions + wishlist + quantity stepper)
**Packages:** `glueful/commerce` 1.8.0 (four additive batched catalog reads, released
first), `packages/thallo-contracts` + `packages/thallo-render` (optional storefront
wishlist + media-batch seams/Twig helpers), `packages/thallo-commerce` (templates,
shop.css, shop.js, view models, one endpoint, one block), app (media-batch binding +
`RegionDefinitions` palette), `tests/`

## §0 Context

The operator approved Concept A as storefront v1: the quiet, product-forward evolution of
the current theme. Its four deltas over what ships today: (1) category chips (index rail +
tile tags), (2) hover-revealed tile actions — cart + wishlist — replacing the card's text
CTA, (3) an inline quantity stepper beside the product page's add-to-cart button with the
price multiplying in the button label, and (4) **wishlist v1**: device-local, with a
`wishlist-link` block, a canonical wishlist page, and a bounded resolution endpoint.

Grounding facts (verified): `ProductViewModel::fromRow()` receives the product's variants
but that is NOT enough to decide direct purchase — the existing
`AddToCartViewModel::build()` also checks required add-ons. `shop.js`'s `FORM_SELECTOR`
already intercepts `form[action="/_shop/cart/add"]`, so a correctly-authorized PRG card
form hydrates for free. `CategoryRepository::all()` already supplies the category rail;
no new category-list read is required. Commerce does NOT yet expose the four batched
reads this design needs (buyer-available products by uuid, first category per product,
required-add-on presence, cover-or-first-gallery media), so they are explicit Commerce
1.8.0 seams below rather than hidden plan-time queries. The app's current
`MediaUrlResolver` also performs one blob query per UUID; §2.2 adds a general batch
companion so a 100-item wishlist does not institutionalize that N+1. The mini-cart
block/module pair is the template for `wishlist-link`.

**Release order:** Commerce 1.8.0 releases first with the additive repository methods in
§2.1. Thallo then raises its root `glueful/commerce` pin and the pack's own currently-stale
`^1.5.0` constraint to `^1.8.0` before consuming them. No schema change is involved.

## §1 Goals and non-goals

**Goals**

- The approved Concept A visuals for `shop/index.twig`, `shop/category.twig`,
  `shop/_product_card.twig`, and `shop/product.twig` — pure template/CSS where possible.
- Honest per-card cart actions: direct add for a single active variant with no required
  add-ons (PRG form, no-JS works), a view-options link for everything else.
- Wishlist v1 exactly as pinned in §5 — explicitly device-local.

**Non-goals (named follow-ups, not omissions)**

- **Account-backed wishlist** is a dedicated follow-up: a Commerce-owned, tenant-scoped
  wishlist model with authenticated sync; on login it merges the local UUID set (dedupe by
  product uuid, existing account chronology preserved first, newly imported local-only
  items appended in their device-list order, availability re-validated), then clears only
  the successfully imported local set. Device v1 deliberately stores UUIDs only, not local
  timestamps, so the follow-up must never claim timestamp ordering it cannot reconstruct.
  Named here so localStorage never becomes the accidental permanent authority.
- B's hero band (a later optional shop block once the index has an operator-content seam).
- Reviews/ratings AUTHORING (display exists; writing does not).
- No guest identifier cookie, no DB table, no customer-profile coupling, no checkout
  behavior changes in v1.

## §2 Shop page (index + category)

- **Category rail** on the index: chip links — "All" (`/shop`, active state) + one chip per
  category, sourced from the existing `CategoryRepository::all()` read. Chips link to the
  existing category pages via `ShopUrlGenerator`. Zero categories → the rail is not
  rendered. The category page renders the same rail with its own chip active.
- **Tile category tag**: the small chip on the card's artwork names the product's FIRST
  category. Requires a `categoryName` (nullable) on the grid projection — batch-fetched
  for the page's products in one query (no per-card N+1); null → no chip. "First" is
  deterministic: directly assigned categories ordered by category `position ASC`,
  `name ASC`, `uuid ASC`; no ancestor expansion.
- **Hover actions** replace the text CTA: two stacked circular buttons at the tile's
  bottom-right — cart (dark) and wishlist heart (light) — `opacity 0 → 1` +
  small translateY on card hover/focus-within; transition only under
  `prefers-reduced-motion: no-preference`; `@media (hover: none)` renders them
  always-visible. The card body stays: name (the detail link), rating (when present),
  price row.

### §2.1 Commerce 1.8.0 batched read seams

Commerce is published, so the pack does not query its tables directly and does not loop
single-product repository calls. Four additive, tenant-scoped methods release first:

- `ProductRepository::findActiveBuyerAvailableByUuids(context, tenant, uuids)` — one
  query reusing `activeFilteredQuery()`'s exact live + active + seller-available
  predicate and returning a uuid-keyed map. Empty input performs no query. The wishlist
  controller restores request order after projection.
- `CategoryRepository::firstCategoryProjectionsForProducts(context, tenant, productUuids)` —
  one portable joined read ordered by product uuid then category
  `position/name/uuid`; PHP keeps the first row per product and returns a
  `product_uuid => {name, slug}` map. Direct assignments only. Cards consume `name`;
  product-detail breadcrumb builds the category URL through `ShopUrlGenerator`.
- `AddonRepository::hasRequiredForProducts(context, tenant, productUuids)` — one query
  for active rows with `required = true`, returning a `product_uuid => true` set; absent
  keys mean false.
- `ProductMediaRepository::primaryForProducts(context, tenant, productUuids)` — one
  query for all candidate rows ordered by product/position/uuid; PHP chooses the
  role=`cover` row when present, otherwise the first gallery row, returning one row per
  product. It replaces the current `coversForProducts()` + per-missing-product
  `forProduct()` fallback loop without changing visual selection semantics.

All four validate/deduplicate UUID inputs at their service boundary, cap them to the
caller's bounded page/request size, and have query-count plus two-tenant tests. They add
no routes, tables, or write behavior.

### §2.2 Batched anonymous media URL seam

`thallo-contracts` adds optional
`MediaUrlBatchResolver::urls(list<string> $uuids): array<string,string>`. The app's
`EngineMediaUrlResolver` implements it with one `blobs WHERE uuid IN (...)` query carrying
the exact same uploads-enabled/access/visibility/status/deleted predicates as `url()`;
`url()` delegates to a one-item `urls()` call so the security predicate cannot drift.
Input dedupes/caps at 100; output omits every unservable UUID.

Thallo-commerce soft-consumes the batch interface for card lists. The existing
single-item resolver remains the product-detail path and compatibility fallback, but the
Thallo app MUST bind the batch seam; the endpoint/query-budget gate runs with that real
binding. This makes the complete 100-item card projection bounded in both Commerce and
host-media reads.

## §3 Card cart action: PRG-honest, variant-aware

`ProductViewModel` does NOT invent another purchasability rule. `buildGrid()` batch-loads
variants and required-add-on presence, then calls the existing
`AddToCartViewModel::build()` for each row. The card projection reduces that closed
decision to:

- Existing decision `mode === 'direct'` → `cartMode: 'direct'` +
  `directVariantUuid`.
- Existing decision `select|link|unavailable` → `cartMode: 'options'`.

The card renders accordingly:

- **direct**: a real `<form method="post" action="/_shop/cart/add">` with hidden
  `variant_uuid` + `quantity=1`, the circular cart button as its submit. No JS → PRG works
  (the storefront's hard rule); with JS, the EXISTING `shop-form` module intercepts it —
  zero new cart JS.
- **options**: the button is an `<a>` to the product page (view-options arrow icon, as in
  the approved mock) — a grid add can never be honest about unchosen variants, required
  add-ons, or zero currently-buyable variants.

This preserves the existing authority exactly: one active variant WITH a required add-on
is options/link, never a direct form. The page query budget gains one batched required-
add-on read, not one query per card.

## §4 Product page: stepper + price-in-button

- The buy area becomes: variant select on its own row (unchanged form semantics), then ONE
  action row — quantity stepper + the add-to-cart button (`flex: 1`).
- The stepper RESTYLES the existing quantity input (it stays the real form input,
  `min=1`, bounded `max=99`): − / + buttons adjust it; no-JS shows the plain number input
  exactly as today.
- **Price-in-button**: the button label reads "Add to cart — {unit × qty}". Server emits
  `data-price-minor` (integer minor units) per variant option, and `data-currency` +
  `data-currency-exponent` ONCE on the form (Commerce enforces a single store currency,
  so per-option currency attributes would be redundant; the form-level pair plus the
  selected option's minor price fully determine the label). The exponent comes only
  from Commerce's `Money::exponentFor()` authority — never a JavaScript currency map or
  a guessed `2`. The behavior performs `totalMinor = unitMinor × qty` as checked integer
  arithmetic, divides by `10 ** exponent`, then passes the MAJOR-unit value to
  `Intl.NumberFormat(document.documentElement.lang, {style: 'currency', currency})`.
  Invalid attributes, an unknown exponent, or an unsafe JS integer leave the current
  server label untouched. Tests pin 0-decimal JPY, 2-decimal USD/GHS, and 3-decimal KWD.
  Accepted nuance, recorded: Intl's glyph/grouping formatting may differ from server
  `Money::format` in some locales — the label is presentation only; the CART's integer
  numbers remain server-rendered truth. No-JS: the button says "Add to cart" with the
  server-rendered unit price (today's content).
- **Detail-page heart**: the SAME wishlist heart component as the cards (one state
  authority, §5) rendered beside the action row — an outlined circular button that fills
  when the product is saved. Every heart is a real `type="button"` carrying the product
  uuid, starts `hidden`, and becomes visible only after the wishlist store initializes
  successfully. Enhancement sets `aria-pressed`, a product-specific accessible name
  ("Save {name} to wishlist" / "Remove {name} from wishlist"), and keeps the existing
  visible focus treatment. No-JS or unavailable localStorage: hearts remain absent,
  honest with §5's storage reality. The same rule applies to CARD hearts — no inert
  button is ever exposed without JavaScript.
- Trust strip + breadcrumb + thumbs as in the approved mock — template/CSS only.
  `gallery` and variant availability already exist; the optional first-category
  breadcrumb comes from §2.1's projection seam (the current product controller does NOT
  already load a category, so this query is explicit rather than waved through as
  "existing data").

## §5 Wishlist v1 (pinned)

**Storage (device-local, explicitly):**

- localStorage key is versioned and namespaced by tenant + shop identity:
  `thallo:wishlist:v1:{opaqueScope}`. A new optional
  `StorefrontWishlistResolver` contract in `thallo-contracts` is soft-bound into Render
  exactly like `StorefrontLinkResolver`. It exposes `storageScope()` and
  `wishlistUrl()`; Render exposes those through the null-safe, allowlisted
  `shop_wishlist_scope()` and `shop_wishlist_url()` Twig helpers. The pack implementation
  `ShopWishlistSurface` derives the scope as unpadded base64url of the full binary
  SHA-256 digest of `"wishlist-v1\0" + normalizedTenant + "\0" + prefix`
  (`''` becomes the explicit `shared` sentinel), and delegates the URL to a new
  `ShopUrlGenerator::wishlist()` method. It checks
  `thallo.commerce` at call time and returns null while disabled. Raw tenant UUIDs never
  enter markup/localStorage keys, and block templates never concatenate shop paths.
- Every shop page root and every shop block root emits the helper result as
  `data-shop-scope`; null means Commerce is absent/inactive and wishlist enhancement
  does not start. This gives catalog pages, route-less builder pages, product-grid
  hydration, and `wishlist-link` one authority without a metadata fetch. General
  rendered-page caches are already tenant-segmented; the scope is deterministic within
  that segment.
- Value: a unique ordered list of PRODUCT UUIDS only, **newest first**, bounded at
  **100** (add = unshift, overflow = drop from the tail/oldest). No timestamps, PII,
  prices, or names. Read sanitization rejects malformed UUIDs, removes duplicates
  preserving first occurrence, and clamps legacy/hostile values to 100.
- Storage is an adapter, not naked `window.localStorage` calls: `getItem`, JSON parse,
  `setItem`, and `removeItem` are individually caught. A corrupt payload is reset to
  `[]`; an unavailable or unwritable storage backend fails closed (hearts/count remain
  hidden and no false persistence is shown) without preventing any other `shop.js`
  module from registering or enhancing. Initialization becomes `ready` only after the
  sanitized current value successfully round-trips through `setItem`; a toggle
  publishes only AFTER the new value writes successfully.
- The adapter listens for the browser `storage` event, re-sanitizes the changed value,
  and publishes the same state event so hearts, pages, and badges converge across tabs.

**Resolution endpoint** — `GET /_shop/wishlist/items` (shop routes, inside the
`thallo.commerce` capability gate):

- Accepts repeated `uuids[]` query values. More than 100 raw values, a non-list shape,
  or any malformed UUID → 422 before querying. Valid duplicates dedupe by first
  occurrence; empty input returns `items: []` without querying. Read-only,
  `private, no-store`.
- Uses §2.1's product/category/add-on/primary-media reads, the existing batched variant
  read, and §2.2's batch URL resolver — a bounded constant query count, never 100 direct
  product/media/blob reads.
- Returns ONLY `ProductCardViewModel::toArray()`, a new closed card-specific projection
  shared by native shop grids and hydrated product-grid/wishlist surfaces:
  `{uuid, name, url, cover_url, rating, price_formatted, compare_at_formatted,
  category_name, cart_mode, direct_variant_uuid}`. It never exposes description,
  gallery, decimal/machine price, raw product rows, or add-on definitions.
- Includes only products that are live, active, and seller-available for the CURRENT
  tenant.
- **Preserves the request's order** in its response — never database order.
- Missing/tombstoned/unavailable uuids are simply omitted; the client removes omitted
  uuids from localStorage during reconciliation (the endpoint response IS the
  reconciliation authority).

**Wishlist page** — canonical `/shop/wishlist` (registered in shop routes; URL built ONLY
via `ShopUrlGenerator`; under the already-reserved shop prefix):

- Server-rendered, shop-cached shell: title, loading/status region, initially-hidden
  empty state ("Nothing saved yet" + continue-shopping link), and grid container.
  It starts `aria-busy=true`; it must never flash a false empty state before local
  storage and reconciliation settle. Progressively hydrated by shop.js from localStorage
  via the endpoint; cards render with the standard card component + a remove
  (filled-heart) action. **Honestly JS-dependent** — the server cannot read browser
  storage; a `<noscript>` message explains that saved items need JavaScript.
- Client-hydrated product-grid and wishlist cards use one
  `buildProductCard(product, context)` function refactored from today's
  `buildGridItem()`. `_product_card.twig` remains the server renderer but shares the
  same class/data/ARIA contract; a structural parity test pins the two renderers so a
  redesign cannot silently drift.

**`wishlist-link` block** (named for what it is — a LINK, not the collection):

- Mirrors mini-cart exactly: registered by `ShopBlockTypesContributor` (5th shop block,
  capability-gated like its siblings), template ships the heart icon + count badge
  (`hidden` until count > 0, like the cart badge) + optional "Wishlist" label + the
  `shop_wishlist_url()` link; placeable in header, footer, and bodies (both region
  palettes gain `wishlist-link`). A null URL/scope fails to plain, non-interactive text
  rather than emitting a broken link.
- Hydrated by a new `shop-wishlist` shop.js module; no-JS → an ordinary wishlist link
  without a count.

**One state authority:** a single shop.js wishlist store (read/write/toggle/reconcile,
bound once) is consumed by card hearts, the product-page heart, the wishlist page, and
the link badge. Changes broadcast as
`CustomEvent('thallo:wishlist-changed', {detail: {scope, uuids}})` so every
badge/heart on the page updates together. Registered as runtime modules alongside the
existing six (exactly-once guard and catch-up pass already handle late registration).

**Race-safe reconciliation:** each request captures `{scope, uuids, storeRevision,
requestGeneration}`. A response applies only when it is the latest generation AND the
store revision is unchanged. Otherwise it is ignored and one fresh reconciliation is
scheduled from current state. On an applicable response, remove only UUIDs present in
that request snapshot but absent from its response; never replace the whole current
store. This preserves a remove/re-add or new heart toggle that occurs while fetch is in
flight. Persistence succeeds before the changed event/grid paint.

## §6 Testing

- Commerce 1.8.0 seams: empty-input zero-query, one-query non-empty reads, exact
  buyer-availability parity, deterministic first-category ordering, active-required-
  add-on semantics, cover-over-gallery media choice, dedupe/cap, and two-tenant
  isolation.
- Media batch seam: exact predicate parity with `EngineMediaUrlResolver::url()`,
  one-query 100-UUID resolution, order-independent uuid map, unservable omission,
  empty zero-query, and single-item delegation parity.
- Card cart-mode: single active variant + NO required add-on → PRG form; the same variant
  + required add-on → options link; multi/zero active variants → options link. The form
  intercepts under the EXISTING shop-form module (extend the ShopJsRuntimeTest
  byte-contract only if selectors change — they don't).
- Grid category tags: batched query count, deterministic position/name/uuid choice,
  null-safe.
- Stepper: no-JS parity (plain input remains), exponent-aware shop.js label math for
  JPY/USD-or-GHS/KWD, bounds 1–99, malformed/unknown/unsafe-number leaves the server
  label unchanged.
- Wishlist resolver seam: tenant + prefix stability, shared-sentinel normalization,
  generator-owned canonical URL, no raw tenant UUID in output, null-soft behavior when
  unbound/disabled, same scope across native shop pages and builder-page block renders,
  both TemplatePolicy memberships plus cache-version bump.
- Wishlist endpoint: raw >100/non-list/malformed 422-before-query, duplicate first-
  occurrence behavior, empty zero-query, bounded query count, order preservation,
  exact card allowlist, tenant scoping, unavailable omission, `private, no-store`.
- Wishlist store (node harness): versioned namespaced key, newest-first bound at 100 with
  oldest-drop, corrupt JSON recovery, each localStorage operation throwing independently,
  write-before-publish, cross-tab `storage` convergence, exact event shape, badge hidden
  at zero.
- Reconciliation races: toggle-during-fetch and remove-then-re-add both preserve the
  newer state; stale generation ignored; unchanged revision removes only snapshot
  omissions; failed persistence paints/broadcasts nothing; returning-user page never
  flashes the empty state before reconciliation settles.
- Hearts: hidden before successful enhancement, no-JS/storage-denied absence,
  `aria-pressed` and product-specific label transitions, keyboard/focus behavior.
- Card renderer: `buildProductCard()` reused by hydrated grid + wishlist and structural
  parity against `_product_card.twig`.
- wishlist-link block: registered/capability-gated like mini-cart (extend
  StorefrontInertnessTest's absent-when-disabled list), palette membership, no-JS link.
- Full suite + phpcs; screenshots of the four surfaces for the operator after
  implementation (same §-gate style as the font track).

## §7 Out of scope → later

- Account-backed wishlist (the named follow-up in §1).
- Hero/operator-content seam on the index (Concept B's layer).
- Reviews authoring; structured spec accordions (Concept C's).
- Wishlist analytics/sharing.
