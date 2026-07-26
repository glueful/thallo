# Ecommerce Content Integration — Slice 2: Storefront Rendering

**Track:** Ecommerce content integration (OUTSTANDING.md §A) — slice 2 of 4 (slice 1: adoption +
linkage, built). **Date:** 2026-07-21. **Status:** design.

Rendered shop pages and builder blocks over the embedded `glueful/commerce`, in Thallo's theme
system: catalog browsing, cart, and checkout — commerce authoritative, enrichment optional, no
checkout logic rebuilt in the pack.

## 1. Decisions (pinned)

- **Interaction: PRG foundation, JS-enhanced primary experience.** Every interaction is a real
  HTML form against `/_shop/...`; without JS the endpoint mutates and returns a 303 (PRG). JS
  intercepts the same forms and requests JSON from the same endpoints via content negotiation
  (`Accept: application/json`) — never parallel business endpoints. JS owns the experience
  (instant add-to-cart, animated drawer, live count, inline updates, recalculation, validation,
  focus management, `aria-live` announcements); the server owns workflow and truth. Checkout and
  payment confirmation are server-authoritative — nothing is ever optimistically shown as paid.
- **Routing: pack-owned catalog namespace + blocks anywhere.** `/{shop-prefix}` (default `/shop`,
  **one normalized path segment**, validated at boot), `/{shop-prefix}/products/{slug}`,
  `/{shop-prefix}/categories/{slug}`; `/cart`, `/checkout`, and the confirmation route are
  root-level reserved workflow paths, stable if the prefix changes. One **`ShopUrlGenerator`**
  owns every catalog/cart/checkout URL — blocks, templates, emails, and structured data never
  concatenate paths. Pack routes register before Render's `/{path}` catch-all. The linked entry
  provides enrichment, never routing authority; the starter Product Page type is route-less;
  canonicals and structured data point at `/{shop-prefix}/products/{slug}`. Cart/checkout/
  confirmation are noindex. Tombstoned/unavailable/tenant-mismatched products → non-revealing 404.
- **V1 inventory: 4 blocks + 6 page templates.** Blocks: `product-grid` (sources category | tag |
  manual list | newest; server-side pagination), `featured-product`, `add-to-cart` (simple
  products submit directly; variant/add-on products render required controls or link to detail —
  never an invalid cart line), `mini-cart` (stable cached shell + JS-loaded contents; a plain
  `/cart` link without JS). Templates: shop index, product detail (commerce data + optional
  enrichment blocks region), category archive, cart, checkout, order confirmation
  (ownership-protected). Marketplace seller presentation out of scope.
- **Payments: provider-ready, manual default.** The pack depends only on Commerce's payment
  abstraction; never imports Payvia classes, gateway names, or provider fields. No collector →
  `pending_payment` order + Commerce's manual instructions. Collector bound → the same flow
  renders/follows its redirect/reference result. Browser return NEVER marks paid — confirmation
  reads server-side order state (may stay pending until the verified webhook). Confirmation
  messaging distinguishes `pending_payment | paid | fulfilled | canceled | payment failure`.
  Installing/credentialing Payvia is a deployment step, not a slice-2 dependency.

## 2. Release surface

| Codebase | Additions |
| --- | --- |
| `glueful/commerce` (joins the unreleased 1.3.0 seams) | `SlugLifecycleAuthority` seam invoked inside create/rename transactions (§4); after-commit `ProductSlugChanged` event (slug-ledger/cache invalidation ONLY, not correctness); broad after-commit `StorefrontCatalogChanged` signal for every storefront-visible product/variant/stock/media/taxonomy/add-on mutation (§9); optional `CheckoutAttemptAuthority` coordinated inside Commerce's placement transaction with a typed replay VO (§7); idempotent put-line cart primitive (§6); `CheckoutPresentation` mapper closing the payment-presentation contract (§8). All additive, byte-parity unbound. |
| `thallo-render` | Reserved-path contribution registry (§5.1); template-path contribution between active theme and default fallback (§5.2); nothing theme-breaking. |
| `thallo-contracts` + app | `StarterBlockTypeDefinition` VO + `StarterBlockTypeContributor` + registry interface; `BlockTypeKind::definitions()` validates + appends — the exact slice-1 content-type contributor pattern applied to block types. A pack-consumable `CanonicalPublicOriginResolver` contract and one app implementation become the shared trusted-origin authority for media URLs and storefront CSRF (§6). |
| `packages/thallo-commerce` | Routes/controllers/view models, `ShopUrlGenerator`, `/_shop` endpoints + CSRF guard, cart/order cookie custody, checkout-attempt authority (§7), slug ledger + 301s (§4), shop cache (§9), the 4 block types + templates + `shop.js` (§5.2/§10), starter block contributions. |

## 3. Routes & URL authority

Registered by the pack (inside the `thallo.commerce` capability gate — user-facing), before
Render's catch-all:

```
GET  /{prefix}                          shop index
GET  /{prefix}/products/{slug}          product detail (+ slug-history 301, §4)
GET  /{prefix}/categories/{slug}        category archive
GET  /cart                              cart page
GET  /checkout                          checkout page
GET  /checkout/return/{ref}             provider return (read state, then confirmation)
GET  /checkout/cancel/{ref}             provider cancel (read state, then confirmation)
GET  /checkout/confirmation/{ref}       order confirmation (ownership-protected, §6)
POST /_shop/cart/add|update|remove|discount
POST /_shop/checkout/quote|place
GET  /_shop/cart                        JSON cart view model (no-store; mini-cart hydration)
GET  /_shop/assets/{file}               fingerprinted pack assets (§5.2)
```

`ShopUrlGenerator` (pack service): `shopIndex()`, `product($slug)`, `category($slug)`, `cart()`,
`checkout()`, `paymentReturn($ref)`, `paymentCancel($ref)`, `confirmation($ref)`,
`assets($file)` — the only URL source. Return/cancel never mutate payment state: each performs
the same ownership check as confirmation, re-reads the order, then redirects to confirmation.
The prefix is normalized (`trim`, single segment, no `/`), rejected at boot otherwise.

## 4. Slug lifecycle: transactional reservation + 301s

Commerce allows slug renames via an unguarded uniqueness pre-check inside
`applyProductPatch()` (verified); an after-commit event alone cannot stop another product from
claiming an old slug. Commerce gains a **Commerce-local** authority seam, invoked INSIDE the
create and rename transactions:

```php
namespace Glueful\Extensions\Commerce\Catalog;

interface SlugLifecycleAuthority
{
    /** Claim the proposed current slug and throw (422-shaped) when it is reserved. */
    public function prepareCreate(ApplicationContext $c, string $tenant, string $productUuid, string $slug): void;

    /** Claim old+new, validate new, and reserve old before the product update. */
    public function prepareRename(ApplicationContext $c, string $tenant, string $productUuid, string $old, string $new): void;
}
```

`CatalogService` generates the product uuid before its transaction and calls `prepareCreate`
inside that transaction before checking/inserting the current product slug. Rename calls
`prepareRename` before updating the product row; any later validation/write failure rolls the
reservation work back with the rename. Unbound → byte-identical current behavior.

The **pack implementation** owns `thallo_commerce_product_slugs` (tenant, slug, product_uuid,
created_at; unique `(tenant, slug)`). Every current/history claim uses the same PostgreSQL
transaction advisory-lock namespace: create locks the proposed slug; rename locks `(old, new)`
in sorted order. Under those locks, `prepareRename` rejects a new slug reserved for a different
product, inserts the OLD reservation idempotently, and removes a NEW reservation held by the
same product (the A → B → A case). The product-table unique arbitrates current/current, the
history unique arbitrates history/history, and the shared advisory lock closes the
current/history cross-table race; neither unique constraint is claimed to do that alone.
The after-commit `ProductSlugChanged(tenant, productUuid, oldSlug, newSlug)` event drives shop-
cache purges only. The product route resolves current-slug first; on miss, the ledger → 301 to
the canonical URL (loop-safe: a ledger row whose slug is again a live product's current slug is
ignored in favor of the live product).

## 5. Render seams (both additive, inert unbound)

### 5.1 Reserved-path contribution

`ReservedPaths` is constructor-frozen today. `thallo-render` gains a
`ReservedPathContributor` registry (register `prefixes`/`exacts`; consumed where
`ReservedPaths` is built). The pack contributes `{prefix}`, `cart`, `checkout`, `_shop` —
no ordering-dependent config mutation. Registries accept contributions throughout provider
boot, then take one deterministic snapshot immediately after all providers have booted and
before route dispatch/Twig construction. First read freezes the snapshot; late registration is
a loud boot error. Contributor ids are unique, ordering is `(priority, contributor_id)`, and
duplicate reserved paths or template namespaces are rejected rather than first-wins. Tests pin
that neither `ReservedPaths` nor Twig resolves before the pack has contributed. Zero
contributors → today's list byte-identical.

### 5.2 Template + asset ownership (pack-owned)

Custom themes replace the default theme's template dir per-template and the asset dir
ENTIRELY (verified) — so neither templates nor JS may live in the render default theme.
`thallo-render` gains a **template-path contributor**: contributed template dirs resolve
**between** the active app theme and the render default fallback (active theme overrides pack;
pack provides the default). The pack ships all block/page templates under
`packages/thallo-commerce/templates/`. `shop.js` is served from the pack via
`GET /_shop/assets/shop-{fingerprint}.js` (content-hash fingerprint computed at boot, immutable
cache headers); the controller resolves only an exact boot-built filename → file allowlist and
never concatenates the route value into a filesystem path. Templates reference it through
`ShopUrlGenerator::assets()`. It is
dependency-free, no build step, loaded only by shop templates/blocks.

## 6. Token custody + CSRF (v1)

- **Cart token:** minted on first cart mutation (in-process `CartService`), stored ONLY in a
  `Secure; HttpOnly; SameSite=Lax` cookie, TTL aligned to `commerce.cart.ttl_days`. Never in
  markup, JS storage, or query strings.
- **Guest order credentials:** on placement, the raw order token is stored in an **encrypted**
  (`EncryptionService`, AAD-bound) `Secure; HttpOnly; SameSite=Lax` cookie holding at most
  **5** `(order_ref, token)` entries — oldest evicted, never an unbounded list. Commerce orders
  have no general expiry, so v1 defines `thallo_commerce.guest_confirmation_days` (default 30,
  bounded 1–90) as the cookie/attempt retention window instead of referring to an undefined
  order expiry. The confirmation route decrypts, matches `{ref}`, and reads the order in-process;
  no match → non-revealing 404.
- **CSRF v1 (no token):** personalized CSRF fields conflict with public full-page caching and
  the cart cookie doesn't exist before the first mutation. Every `/_shop` mutation uses this
  exact algorithm: (1) `SameSite=Lax` is the cookie base; (2) when `Sec-Fetch-Site` is present,
  reject unless it is exactly `same-origin`; this Fetch Metadata check is an additional gate and
  never replaces origin validation; (3) when `Origin` is present, require its normalized
  scheme/host/port to equal the canonical public origin; otherwise require the same exact match
  from `Referer`; (4) reject when both `Origin` and `Referer` are absent. The expected origin comes
  from a shared `Thallo\Contracts\Delivery\CanonicalPublicOriginResolver`, never the request's
  untrusted `Host` header. The app implementation is the single authority: in tenancy-enforced
  mode it resolves the current tenant and uses the existing public-origin precedence (default
  host for the default tenant, then verified active custom domain, then active tenant slug plus
  base domain); in single-store mode it uses `config('app.urls.base')`. The contract exposes both
  `currentOrigin(context)` for request-bound consumers and `originForTenant(context, tenantUuid)`
  for owner-bound resources. The existing `TenantBlobPublicUrlProvider` keeps deriving the blob's
  owner tenant, then delegates that tenant to this same contract, so media and CSRF cannot drift. A
  future general cache-safe CSRF-token primitive (response substitution) is explicitly out of
  scope.
- All `/_shop` responses are closed **view models** — never raw commerce rows. Cart mutations
  use convergent semantics: Commerce gains an additive `putLine(...)` primitive that atomically
  inserts the variant/add-on line or sets its quantity to the submitted desired quantity under
  the existing cart claim. The pack never calls incrementing `addLine()` for the storefront, so
  an identical add/update/remove/discount replay converges rather than adding twice. Checkout
  placement uses the stronger durable authority in §7.

## 7. Durable checkout idempotency (attempt authority)

Commerce's cart claim prevents a second ORDER but not a replay of the first RESULT (a retry
sees "Cart not found or no longer active", verified). The pack adds
`thallo_commerce_checkout_attempts` (tenant, idempotency_key, request_fingerprint,
status `pending|completed`, order_uuid, order_ref, guest_credential_ciphertext, created_at,
updated_at; unique `(tenant, idempotency_key)`):

- The pack never wraps `CheckoutService::placeOrder()` in an outer transaction: the current
  method dispatches `OrderPlaced` and invokes the payment collector after its own transaction,
  which would move both side effects before the outer commit and hold database locks across
  provider I/O.
- Commerce instead gains an optional **`CheckoutAttemptAuthority`** collaborator, soft-resolved
  by `CommerceServiceProvider` and injected into `CheckoutService`. The pack passes
  an optional `CheckoutAttemptContext(key, fingerprint)` to `placeOrder()`. Commerce moves its
  initial active-cart lookup into the existing placement transaction and calls
  `claimOrReplay(...)` first. The pack authority takes a transaction advisory lock on
  `(tenant, key)`, then re-reads: same key/different fingerprint → 409; completed → replay;
  absent → insert pending. This lock-and-re-read, not a naked preflight lookup, serializes two
  simultaneous first uses of the same key. A completed claim returns a typed
  `CheckoutAttemptReplay(orderUuid, orderRef, guestCredential)`, not an associative array. A
  replay returns the existing order/credential before
  cart validation and does not dispatch `OrderPlaced` again. For a new attempt,
  `complete(...)` writes order uuid/ref + encrypted guest credential INSIDE the same Commerce
  transaction. The pending row can never commit separately from its completed order. Order and
  attempt therefore share one real commit, with no crash window, while event dispatch and
  `PaymentCollector::initiate()` remain strictly after commit. Unbound authority and a null
  attempt context preserve today's behavior and perform zero attempt-table queries.
- **Replay:** same key + same `request_fingerprint` (hash of the canonicalized checkout payload)
  → return the stored result (same logical order, credential re-delivered to the same cookie),
  then safely re-run provider initiation for that order: `PaymentCollector` is contractually
  idempotent by payable `(type, id)`. Same key, different fingerprint → 409. Missing key on the
  enhanced-JS path → the client generates one per checkout intent; the no-JS checkout form
  carries one minted on the private/no-store checkout page.
- Attempt rows expire with the `guest_confirmation_days` retention sweep; the guest credential
  is encrypted at rest (AAD `checkout.attempt:{tenant}:{key}`). A crash after commit but before
  provider initiation is repaired by replay; a provider is never called for an uncommitted order.

## 8. Payment presentation contract (closed)

`PaymentInitiation::payload` is arbitrary (`array<string,mixed>`, verified) — a provider-neutral
storefront must not render it directly. Commerce gains a **`CheckoutPresentation`** mapper
(Commerce-local, additive): classifies an initiation into a closed view model with a typed
action vocabulary —

- `manual` — allowlisted instruction fields (title, reference, lines of instruction text,
  amount/currency) from Commerce's own manual collector;
- `redirect` — a validated absolute `https` URL (scheme+host validated; anything else →
  `unavailable`) the storefront may follow/link;
- `reference` — an opaque provider reference + display fields to show while pending;
- `unavailable` — the safe fallback for unknown/malformed shapes (message + operator log),
  never raw payload passthrough.

The pack renders only this view model. The exact return/cancel routes in §3 re-read server-side
order state through the same ownership protection as confirmation (§6); they never accept a
browser-supplied paid/failed verdict.

## 9. Caching posture

- Catalog pages (`/{prefix}`, product detail, category): a **shop-specific cache** in the pack —
  keyed on `(tenant, resolved locale, active theme, appearance fingerprint, path, canonical
  allowlisted query set)`; v1 allowlist is exactly `page`, bounded to integer `1..1000` (invalid
  or out-of-range → non-revealing 404 and never cached; any OTHER query parameter present →
  bypass). RenderPageCache is
  path-only and strips queries (verified) — it is not used for shop routes; shop routes carry
  the same private/preview bypasses. Locale is the render pipeline's resolved locale (default
  locale in v1 when no locale route is present), not an untrusted query value.
- Commerce dispatches `StorefrontCatalogChanged(tenantUuid, reason, productUuid?)` after commit
  from every storefront-visible mutation path: product create/update/status/delete, variant and
  price changes, stock changes (including checkout/refund/cancel adjustments), media, category,
  tag, attribute, and add-on changes. The event is broad by design: category/tag definition and
  assignment changes can affect archives and arbitrary grids. Its closed reason vocabulary is
  `product.created`, `product.updated`, `product.status_changed`, `product.deleted`,
  `variant.changed`, `stock.changed`, `media.changed`, `category.changed`, `tag.changed`,
  `attribute.changed`, and `addon.changed`. Product review and download-
  entitlement mutations are excluded because v1 does not project them publicly. The pack purges
  only `thallo:shop:catalog:{tenant}` on this event; `ProductSlugChanged` still owns slug-ledger
  invalidation/redirect behavior, and link changes purge the same tenant namespace. Because the
  existing `ThemeChanged`/`ThemeAppearanceChanged` events are global (no tenant identity), shop
  entries also carry a global `thallo:shop:catalog` tag; either event purges that tag. The cache
  key's appearance fingerprint is derived from the resolved accent/neutral values, not a
  nonexistent revision counter. TTL is defense-in-depth, never the
  primary freshness mechanism.
- `product-grid` blocks on BUILDER pages render page 1 only; their pagination links go to the
  canonical shop/category routes (Render's cache stays untouched by query variation).
- `/cart`, `/checkout`, return/cancel, confirmation, and every `/_shop` response:
  `private, no-store`, noindex.
- `mini-cart` renders a stable, cacheable shell; contents hydrate via `GET /_shop/cart` (JSON,
  no-store).

## 10. JS enhancement layer

One dependency-free `shop.js` (pack asset, §5.2): intercepts the real forms, re-submits with
`Accept: application/json`, updates drawer/count/line totals/quote results inline, manages
focus + `aria-live` status announcements, and disables double-submits. PRG is the no-JS path,
not an AJAX retry strategy: once `fetch()` has started, a network failure is ambiguous and JS
must never issue a second native POST automatically. It refreshes/reconciles the cart, preserves
the checkout idempotency key, and offers an explicit retry using that same key. Native form
submission is allowed only when interception fails before any request is sent. No framework,
no build step. Checkout keeps distinct loading/error/payment states; payment redirect actions
navigate top-level.

The JavaScript contract is executable, not string-inspected: a Node test with a hand-stubbed DOM
(the existing `ColorModeRuntimeTest` pattern) loads the served asset and proves interception,
JSON-driven cart/count/quote updates, focus movement, `aria-live` announcements, double-submit
suppression, and that a rejected/ambiguous fetch never triggers a second POST or a fallback native
submission. An explicit user retry is the only second attempt and preserves the checkout key.

## 11. Testing (Postgres; per the pinned contracts)

- **Checkout:** manual e2e (place → pending_payment → instructions VM → confirmation);
  a **fake redirecting PaymentCollector** proving provider-readiness (redirect VM, validated
  URL); return-before-webhook stays pending; webhook-driven transition visible on refresh;
  duplicate placement (same key+fingerprint) → same logical order; key reuse with different
  fingerprint → 409; `OrderPlaced` dispatches once; a fake collector reading on a second DB
  connection sees the committed order (proves provider I/O is post-commit); crash-after-commit
  replay initiates the same logical payment; concurrent first use of one key in two connections
  produces one completed attempt/order and one replay.
- **CSRF matrix:** cross-origin `Origin` → 403; `Sec-Fetch-Site: cross-site` → 403; no origin
  signal at all → 403; `Sec-Fetch-Site: same-origin` without `Origin`/`Referer` still → 403;
  absent `Origin` + exact same-origin `Referer` succeeds; spoofed `Host` cannot alter the expected
  origin; the shared origin contract produces identical origins for media and storefront CSRF in
  single-store/default-host/custom-domain/subdomain modes; same-origin PRG (no JS) works; JSON
  negotiation works.
- **Custody:** no cart/order token in any markup/JSON/URL; guest cookie encrypted, capped at 5,
  oldest evicted, expires at the configured 1–90-day confirmation window; confirmation and
  return/cancel ownership (wrong/absent credential → 404).
- **Slug lifecycle:** rename reserves the old slug transactionally; a second product's
  create/rename onto a reserved slug → 422 (raced under two connections both orderings);
  current-product-create vs history-reservation and rename-vs-create are raced under the shared
  advisory lock in both orderings; old-slug route → 301 → canonical; ledger-vs-live-slug loop
  safety; `ProductSlugChanged` purges the shop cache.
- **Caching:** tenant/locale/theme/appearance-fingerprint/path/page keying (page 2 cached separately; page 0,
  page 1001, and malformed pages are rejected without a cache write; foreign query parameter
  bypasses); every storefront-visible mutation family named in §9 purges only that tenant;
  global theme/appearance events purge the global shop tag and do not serve stale markup;
  cart/checkout/return/cancel/confirmation
  no-store; grid-block pagination links to canonical routes.
- **Blocks/templates:** the product-grid manual source is a `text` field containing one product
  slug per line, normalized by trim + blank removal + stable ordered deduplication and capped at
  50 slugs (comma-delimited input is rejected, not guessed); all 4 blocks render themed with/without enrichment + with/without JS
  (markup contract); add-to-cart never creates an invalid line for variant products; template
  contributor: active theme overrides pack template; registry snapshots after all providers,
  rejects late/duplicate contributions, and orders deterministically; asset fingerprint URL
  stable + immutable headers; unknown/traversal asset names → 404.
- **Mutation retry:** identical `putLine` requests leave one desired quantity; an ambiguous JS
  failure never triggers a second POST or native fallback; executable Node DOM tests prove form
  interception, JSON updates, focus/`aria-live`, and double-submit behavior; explicit checkout
  retry preserves the original key.
- **Seams byte-parity:** commerce with no `SlugLifecycleAuthority`/`CheckoutAttemptAuthority`
  consumer unchanged, unobserved `StorefrontCatalogChanged` dispatch has no query or behavior
  effect, and existing `addLine()` behavior remains untouched; `CheckoutPresentation`
  remains closed on unknown payloads; render with zero reserved-path/template contributors
  byte-identical; `BlockTypeKind` with zero contributors byte-identical.
- **Capability/tenancy:** capability off → shop routes 404, blocks absent, catch-all untouched;
  the three tenancy modes on product detail + cart + checkout hot paths.

## 12. Out of scope

Admin SPA commerce screens (slice 3); Woo importer (slice 4); marketplace/seller presentation;
customer accounts/login on the storefront (guest checkout only in v1); a general CSRF-token
primitive; Render query-variation caching; wishlist/search/reviews UI.
