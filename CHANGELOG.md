# Changelog

All notable changes to this Glueful API application will be documented in this file.

This project is generated from `glueful/api-skeleton`. Start recording application-specific changes here after scaffolding.

## [Unreleased]

### Added
- **Storefront customer accounts** (`packages/thallo-account`, gated by the `thallo.accounts`
  capability; requires glueful/framework ^1.74.0): a shopping visitor gets a global Glueful
  identity and **zero workspace authority** — no membership, role, or permission. The guarantee is
  structural, not a rule: the customer activation path has no branch that can reach `addMember()`,
  and a test asserts the authority tables stay empty after a shopper activates. Registration
  collects first name, last name, email and password, with the **email as the username** (framework
  1.74.0's widened username validation is what allows it — changing the username later is a named
  follow-up, not a shipped feature). The themed `/account/*` pages cover register → mailed one-time-
  code verification → activation, sign in over an HttpOnly session cookie (through the framework's
  `LoginOrchestrator`, so the two-factor gate is un-bypassable and login fails closed on a
  challenge), the signed-in account page, password recovery, and sign out. Registration and recovery
  are neutrality-preserving: the contracts (`RegistrationResult` / `RecoveryResult` /
  `RecoveryVerification`) cannot express "unknown email" or "delivery failed", and the app glue
  collapses every outcome, so a storefront can never become an account-existence oracle. Under the
  hood the shared signup pipeline is refactored around a `VerifiedAccountActivator` primitive that
  both member and customer signup reach through, and `SignupCoordinator` now dispatches on intent
  kind explicitly (member / customer / workspace) instead of a binary ternary that silently routed
  unknown kinds to workspace provisioning. Anonymous account POSTs are protected by same-origin
  provenance plus a rate limit, cookie-authenticated mutations by a session-bound CSRF token — a
  route-inventory test enforces that matrix as a gate. Enabling this needs the HttpOnly
  session-cookie transport, which Thallo now turns **on by default** (`SESSION_COOKIE_ENABLED`;
  disable to opt out) — the cookie stays opt-in per route and leaves bearer API auth unchanged.
- **Public account surface — conditional chrome, safe redirects, and admin settings**
  (`packages/thallo-account` + admin SPA): the storefront account chrome is now a reusable,
  cache-safe **`auth-state`** block instead of a bespoke sign-in link. It renders two allowlisted
  child slots (`signed_out` / `signed_in`) server-side into the shared page cache, the signed-in
  branch shipped `hidden inert`; a single coalesced `/_account/session` read hydrates every instance
  in one synchronous, fail-closed swap (any error leaves the signed-out state). Both branches live
  in cacheable HTML, so the block is presentation only, never an authorization boundary. Enforcing
  which children a slot may hold added a general primitive: `blocks` fields gain an opt-in
  **`enforce_block_types`** flag that turns their previously picker-only `block_types` allowlist into
  a hard server-side reject at the exact slot path, closing both the entry-write and region-write
  paths through the shared `FieldValidator` (the default stays picker-only, so no existing content is
  stranded). Operators can configure **post-login / post-logout redirects**, and a visitor may pass
  a `?next=` — both flow through one `AccountReturnPath` authority that accepts only a normalized
  application-relative path and rejects every open-redirect shape (protocol-relative, absolute,
  scheme, backslash, control char, percent-encoded bypass); a sign-out that cannot revoke the server
  session returns a cookie-cleared 500 rather than a misleading "signed out" redirect. A new
  **Settings → Accounts** admin page (gated by `thallo.accounts` + `content.manage`) lists the themed
  account pages and edits the two redirects — with curated, field-specific suggestions beside
  free-text entry: after sign-in offers `/account` plus the enabled account sections; after sign-out
  offers `/` and the sign-in page; both offer published site pages (so a custom landing page is one
  click away); transitional auth pages (register / verify / reset / logout) are never suggested. It
  is contributed into the shared Settings menu through a nav seam that never duplicates the Settings
  group. The deprecated `account-link` block is physically retired
  (`thallo:account:retire-account-link`).
- **Account form blocks + Store pages inventory** (`packages/thallo-account` /
  `packages/thallo-commerce` + admin SPA): devs can now compose **custom versions of the account
  pages** — three cache-safe form blocks (`login-form`, `register-form`, `forgot-password-form`)
  render the standard anonymous forms byte-identically into the shared page cache (same-origin
  provenance + rate limit, no session CSRF token) and join `auth-state`'s enforced slot allowlist.
  The login block carries a modern inline-error experience: its enhance script injects a path-only
  `return_to` (so a no-JS submit falls back to the themed page), a failed sign-in 303s back to the
  custom page carrying only an allowlisted code (`credentials` — never PII in the URL), a hidden
  `role="alert"` message is revealed and focused, the email is refilled from a consume-once,
  TTL-bounded, host+page-scoped sessionStorage stash, and `history.replaceState()` strips the code
  so refresh/back never replays it. 2FA keeps the themed fail-closed page (navigation, not an
  error code) and enumeration neutrality holds. `AccountReturnPath` gains the path-only
  `validatePagePath()`; the richer `validate()` contract for `next` is unchanged. Commerce adopts
  the page-inventory concept: **Commerce → Settings → Store** now lists the default store pages
  (Shop, Wishlist, Cart, Checkout) with paths computed from the live shop prefix.
- **Storefront v1 — Concept A** (`packages/thallo-commerce` + delivery seams; requires
  glueful/commerce 1.8.0's batched catalog reads): the shop and category pages gain a
  category chip rail, per-card category tags, and hover-revealed cart/wishlist actions —
  the cart button is a real PRG form for single-variant products with no required add-ons
  (AddToCartViewModel stays the sole purchasability authority) and a view-options link
  otherwise. The product page gains a quantity stepper with an exponent-aware
  price-in-button label (0/2/3-decimal currencies; server label untouched on any doubt), a
  wishlist heart, and a first-category breadcrumb. **Wishlist v1 is device-local**:
  tenant-scoped opaque localStorage (UUIDs only, bounded 100, newest first), a bounded
  ordered resolution endpoint (`private, no-store`; error responses can never wipe the
  saved set), a progressively hydrated `/shop/wishlist` page, and a `wishlist-link` block
  placeable in headers/footers/bodies — all fail-closed without JavaScript or storage, and
  fully inert while `thallo.commerce` is off. An account-backed wishlist is the named
  follow-up; card lists everywhere now build through one batched projection
  (constant query count regardless of product count).
- **Figtree is the default theme's typeface** (self-hosted, SIL OFL): variable roman +
  italic latin subsets with reproducible provenance (upstream tag, checksums, exact
  subsetting command committed), loaded via a new existence-aware `font_faces_style()`
  Twig helper — preload and `@font-face` share one byte-identical URL, custom themes
  without the files fall through to the system stack untouched, and a metric-matched
  Arial fallback eliminates swap reflow. Shop pages inherit the theme face (their own
  font-family overrides removed). Fonts carry their own 128KB payload budget test,
  separate from the runtime's.
- **Storefront performance & listing polish** (`packages/thallo-render` + delivery seam):
  responsive images behind a new optional `MediaVariantUrlResolver` render contract (the
  Thallo app always binds its MIME-aware implementation while candidate generation stays
  capability-gated; without real resizing, templates emit a plain `<img>` and non-image
  assets are omitted instead of ever rendering as broken images); at most one
  priority (LCP) image per page — the first eligible body image claims
  `fetchpriority="high"`, everything else lazy-loads; editorial listing rows for
  archive/listing/terms pages (cover thumbnail, clamped excerpt, date, whole-row hover
  with a semantic title link, server-side degradation when fields are absent);
  `content-visibility` relief on listing rows and the footer; a cross-document root
  crossfade via `@view-transition` (reduced-motion disabled); and a CI budget test
  pinning the single-runtime asset posture at 12KB gzipped.
- **Storefront styling for shop blocks and cart/checkout pages** (`packages/thallo-commerce`):
  `shop.css` now covers the four shop blocks (`mini-cart`, `product-grid`,
  `featured-product`, `add-to-cart`) and the cart, checkout, and confirmation pages —
  previously only the catalog pages (index/product/category) had styles, so blocks and
  the cart rendered as bare semantic HTML. Every shop block template and the three page
  templates now link the stylesheet (same fingerprinted asset pipeline as shop.js).
  Styles are theme-neutral: blocks inherit the surrounding theme's font and colors,
  accent via the existing `--shop-accent` custom property.
- **Shop grid product cards**: the shop index and category grids share a new
  `shop/_product_card.twig` partial with a modern borderless card treatment — soft
  square image panel, bold name linking to the product detail page, star rating (only
  when reviews exist), and a prominent price row with struck compare-at.
- **Mini-cart icon toggle**: the mini-cart trigger is now the standard storefront
  pattern — a shopping-cart icon button (commerce-owned inline SVG, `currentColor`) with
  the live count as a corner badge, keeping an `sr-only` "Cart" label for screen readers.
  The badge hides while the cart is empty (the shell ships it hidden; the cart paint
  reveals it only when items exist — no "0" badge before or after hydration).
- **Mini-cart drawer disclosure**: the mini-cart toggle's `aria-expanded` wiring is now
  real — shop.js binds the toggle (click opens/closes, Escape closes and refocuses,
  clicking outside closes), and shop.css keys the dropdown panel's visibility off the
  aria state. Previously the toggle was dead markup and the panel rendered permanently
  expanded. Idempotent via an inner bound-marker, covered by a dedicated node-harness
  test.
- The commerce **Mini cart** block can now be placed in the header and footer global
  regions (the classic cart-in-the-header storefront pattern) — added to both region
  palettes. The palette entry is inert without commerce: the picker only offers the
  block while `thallo.commerce` is on, and a stored one falls to the missing-template
  fallback while it is off.
- **Clean commerce capability boundary** (`packages/thallo-commerce`): disabling
  `thallo.commerce` now removes commerce from rendered pages entirely — the pack's
  template dir registers inside the capability gate, so stored shop blocks fall to the
  ordinary missing-template fallback (no shop HTML, no `/_shop/assets/shop.js` script
  tag and its 404 noise, no `/cart` links) instead of dead static shells. A boot-time
  flip reconciler purges the rendered-page cache (`thallo:render:page`) and the edge
  whenever the capability's enabled state changes between boots, so previously cached
  script tags disappear immediately in both directions. Stored block/link/catalog data
  is never touched, re-enabling restores templates and blocks with no migration or
  resync, and the shop-prefix path reservations plus the general theme runtime remain
  active regardless of capability state.
- **shop.js on the theme runtime** (`packages/thallo-commerce`, shopjs-on-runtime track):
  shop.js now registers its six commerce concerns (`shop-form`, `shop-gallery`,
  `shop-mini-cart`, `shop-product-grid`, `shop-featured-product`, `shop-add-to-cart`) as
  modules on the theme runtime — the core drives scanning, stamps `data-thallo-enhanced`
  component markers, contains per-component failures, and formalizes the canvas skip —
  with an exactly-once execution guard (every shop block template emits its own script
  tag) and a coalesced cart fetch (one `GET /_shop/cart` and one document-wide paint
  regardless of shell count). Pages without the runtime (copied pre-runtime layouts) keep
  the self-driving fallback unchanged, and `window.thalloShop.init()` delegates to
  `ThalloRuntime.enhance()` on runtime pages.
- The default theme's `blocks.js` compatibility loader is removed (theme-runtime spec
  §11.4, executed pre-launch — no released version ever shipped it): the theme now ships
  CSS only, and `ThemeCloner` is back to an unqualified full copy.
- **SEO head partial** (rendered delivery): entry pages — the entry-backed homepage
  included — now ship a complete head composed from thallo-seo data behind the new
  `SeoHeadResolver` contract: meta description, absolute canonical + hreflang alternates
  + x-default, Open Graph (`og:type` article/website, title/description/image/url/
  site_name), `twitter:card` only when explicitly overridden, `robots` only for real
  directives. The homepage canonicalizes to `/` (never its entry path); previews emit
  exactly `noindex, nofollow` and no canonical/OG; every URL attribute passes the
  safe-url discipline (unsafe values are omitted, never emitted). SEO override edits now
  dispatch `SeoMetaChanged` and purge the entry's cached rendered pages locally AND at
  the CDN edge. The seo meta resolver (and the headless `/v1/seo/meta` endpoint) derives
  unmapped-type titles from the conventional `title` field instead of the bare site name.
- **Package-owned theme runtime + theme accessibility refresh** (`packages/thallo-render`,
  theme-runtime track): the default theme's behavioral JS moves out of the theme into one
  package-owned `runtime.js` — a `ThalloRuntime` module registry (color-mode, forms,
  carousel, navigation, tabs) with idempotent enhancement and a canvas no-op policy —
  served fingerprinted at `/_thallo/runtime/` with the ShopAsset delivery pattern (the
  stable logical alias 302s to the current immutable `runtime-<fp>.js`; stale fingerprints
  404). Themes keep presentation (CSS) only; the default theme's `blocks.js` is now a
  temporary behavior-free compatibility loader for already-cached HTML, and `ThemeCloner`
  deliberately never seeds it into clones of the pack default. Ships with an accessibility
  refresh: shell skip link, focusable main target, labeled navs and `aria-current`; a
  unified `<details>` navigation with a no-JS mobile disclosure stack at the named 48rem
  breakpoint; honest-floor ARIA tabs (the radio floor claims no ARIA — the runtime layers
  real tablist semantics) with a 12-item authoring cap enforced in the editor and at save;
  carousel pause/visibility/status controls; and form error focus + `aria-busy`. The
  navigation starter block gains an `aria_label` field — existing workspaces pick up the
  block-type fingerprint update via `php glueful thallo:tenant:sync --all --kind=block_type`.
  Coexistence with `shop.js` is proven executable: both served byte-sources load into one
  Node DOM and each script enhances only its own forms.
- **Content search switch in Settings › General** (runtime, no restart or `.env` edit): a
  new feature toggle controls the `thallo.search` capability. The stored `search_enabled`
  value is a SYSTEM settings key (unscoped channel — readable at boot before tenant
  resolution, never tenant-fragmented) overlaid onto the deploy-time `thallo.capabilities`
  map when the capability registry is built, so a save applies on the next request;
  rowless installs keep the config default. Because `/v1/search` registration is
  boot-gated and the compiled route cache is keyed by route-file signatures, saving a
  changed value also clears the route cache. Fail-soft to the config map on pre-migration
  boots. The switch sits beside the scheduler/webhooks toggles, with a reminder to run
  `thallo:search:reindex` after enabling.
- **Commerce adoption + content linkage foundation** (`packages/thallo-commerce`, slice 1
  of the ecommerce content-integration track): `glueful/commerce` runs embedded with
  per-workspace data behind the standard `thallo.commerce` capability — a three-mode
  tenant resolution (`''` sentinel / widened default tenant / enforcement request tenant,
  live-evaluated, bound through Commerce's host seam without touching the shared
  resolver), a canonical product→entry enrichment link (`thallo_commerce_product_links`,
  one entry per product and one product per entry, admin API with expectation-guarded
  relink, sorted 64-bit advisory-lock serialization, after-commit audit events, proven
  under real two-connection PostgreSQL races), lifecycle cleanup (entry-delete and
  product-tombstone listeners, a batch-limited `thallo:commerce:links:reconcile` sweep,
  `thallo:commerce:diagnose`), workspace integration (a tenant-adoption contributor that
  rekeys links and Commerce rows during enforcement activation under the write barrier;
  a fail-closed workspace purge that delegates Commerce-table deletion to Commerce's own
  purge service and refuses to report success while Commerce data could remain), and an
  idempotent starter **Product page** content type (localized headline/summary + blocks
  region; SEO stays with thallo-seo) provisioned for fresh/future tenants automatically
  and adopted into existing workspaces via the explicit
  `thallo:tenant:sync --all --kind=content_type` step. Host seams added for it:
  tenant-adoption contributors (`thallo-tenancy`) and typed starter content-type
  contributors (`thallo-contracts`), both byte-inert with zero contributors. Marketplace
  stays disabled; diagnostics flag it as unsupported in v1. Commerce is consumed from
  the published `glueful/commerce` releases (`^1.4.0`).
- **Storefront rendering** (`packages/thallo-commerce`, slice 2 of the ecommerce
  content-integration track): rendered shop pages, cart, and checkout over the embedded
  `glueful/commerce`, in Thallo's own theme system — commerce authoritative, enrichment
  optional, no checkout logic rebuilt in the pack. A pack-owned catalog namespace
  (`/{shop-prefix}` default `/shop`, one normalized path segment validated at boot;
  `/{prefix}/products/{slug}`, `/{prefix}/categories/{slug}`) plus stable root-level
  workflow paths (`/cart`, `/checkout`, `/checkout/return|cancel|confirmation/{ref}`) that
  register ahead of Render's catch-all; one `ShopUrlGenerator` is the sole source of every
  catalog/cart/checkout URL. Slug renames gain a transactional reservation ledger
  (`thallo_commerce_product_slugs`, PostgreSQL advisory locks, old-slug 301s, loop-safe
  against a live product reclaiming its own history) via a new Commerce-local
  `SlugLifecycleAuthority` seam invoked inside Commerce's own create/rename transactions.
  Four starter blocks (`product-grid`, `featured-product`, `add-to-cart`, `mini-cart`) and
  six page templates (index, product detail, category archive, cart, checkout, order
  confirmation) ship batteries-included via the same starter-contributor pattern slice 1
  established for content types, now generalized to block types
  (`StarterBlockTypeContributor`/`StarterBlockTypeRegistry`); a dependency-free `shop.js`
  intercepts the plain PRG forms for instant cart/count/quote updates without ever losing
  the no-JS fallback, proven by an executable Node DOM test. Cart mutations use a new
  idempotent Commerce `putLine(...)` primitive (add/update/remove converge instead of
  double-adding on replay); checkout placement gains a durable
  `thallo_commerce_checkout_attempts` ledger and an optional Commerce
  `CheckoutAttemptAuthority` seam so a retried placement replays the same order/credential
  rather than creating a second one or losing the payment-collector call, and a new
  `CheckoutPresentation` mapper closes Commerce's provider-neutral payment payload into a
  typed `manual | redirect | reference | unavailable` view model the storefront can render
  safely. Guest order credentials live only in a capped, encrypted, `HttpOnly` cookie;
  every `/_shop` mutation is guarded by an Origin/Fetch-Metadata CSRF check against a new
  shared `CanonicalPublicOriginResolver` contract — the same trusted-origin authority the
  existing tenant-owned-blob media URLs now resolve through too, so media and storefront
  CSRF can never disagree. A tenant/locale/theme/appearance-fingerprint/path/page-keyed
  shop cache purges on every storefront-visible catalog mutation (price, stock — including
  checkout/refund/cancel — media, category/tag/attribute, add-on) via a new broad
  `StorefrontCatalogChanged` Commerce event, plus the existing global theme/appearance
  events; `/cart`, `/checkout`, and every `/_shop` response stay `private, no-store`.
  Render gains two additive seams for this (a reserved-path contributor registry and a
  template-path contributor between the active theme and the default fallback), both
  byte-identical with zero contributors. Marketplace/seller presentation, customer
  accounts, and Payvia credentialing are out of scope for v1.
- **Admin commerce area** (slice 3 of the ecommerce content-integration track): the full
  shop-management surface inside the admin SPA, backed by Commerce's admin JSON API
  re-mounted at `/v1/admin/commerce` through Commerce 1.4.0's mountable
  `AdminRouteCatalog` — an explicit fail-closed 98-key allowlist (a newly added Commerce
  endpoint stays unmounted until consciously approved; approved-inventory parity tests
  keep catalog, allowlist, and mounted routes locked three ways), behind the standard
  admin session/workspace-binding stack so workspace selection drives the Commerce tenant
  context. Authorization gains `commerce.view` alongside the renamed `commerce.manage`
  ("Manage commerce"), evaluated by one reusable `PermissionRequirementAuthority` with
  declarative catalog implications (manage ⟹ view) and candidate-wise API-key
  scope∩RBAC intersection; the pack's `/meta` endpoint (currency + authoritative
  ISO-4217 exponent, canonical shop-index URL, effective `can_view`/`can_manage` flags)
  consumes the same authority through a neutral `thallo-contracts` seam, so route gate
  and UI flags can never disagree. SPA surfaces: Overview (sales/products/stock reports +
  acquisition tiles), Products (variants, composition, inventory adjust, media,
  categories/tags/attributes with values, add-ons, per-variant digital downloads),
  bidirectional product↔entry linking (one shared panel on the product detail and — via
  the new capability-gated, settle-before-admit `entryEditorPanels` manifest — the entry
  editor; explicit CAS relink with conflict recovery; server-built absolute preview URLs,
  the client never assembles storefront URLs), Orders (state-machine-mirrored lifecycle
  actions, refunds with exact BigInt minor-unit entry against the refundable ceiling,
  notes, invoice data), Discounts (percentage in true basis-points parity with the
  pricing engine, fixed amounts in minor units), Settings (shipping zones/locations/
  methods incl. per-class tables, shipping classes, tax rates), Reviews moderation
  (transition-faithful, XSS-safe), and read-only Customers. All amounts flow through one
  BigInt-safe money formatter (0/2/3-decimal exponents, no float arithmetic); mutation
  controls hide without `commerce.manage` while every surface stays readable with
  `commerce.view`.
- **Single-page product editor** (admin SPA, on glueful/commerce 1.5.0): the product
  detail page becomes one scrollable editor of independently-saving section cards
  (Details, Images, Pricing & stock, Organization, Add-ons, Downloads, Linked content,
  Grouped products) with a sticky scroll-spied section nav — the whole authoring surface
  is visible at once. Every assignment section hydrates its existing state from Commerce
  1.5.0's six per-product reads (`{revision, items}` envelopes), so replacement saves are
  built from server truth plus the user's edits — the blind-replacement warnings and the
  off-page wipe risk are gone. Replacement mutations carry `expected_revision` (Commerce's
  new CAS guard): concurrent edits surface as content-aware recovery — unrelated bumps
  rebase silently, category/tag sets three-way-merge deterministically, and structured
  data (attributes, media order, child composition) gets an explicit "Use latest" /
  "Replace with mine" review; nothing ever auto-retries. Per-section Saving/Saved/
  error/unsaved chips, a page dirty-registry blocking navigation while anything is
  unsaved or mid-save, progressive pricing disclosure (simple products get a compact
  SKU/price/compare-at/stock card; variant-heavy products the full table), compare-at
  prices now editable (and clearable) everywhere, per-variant stock quantities shown from
  the real inventory read (integrity failures render honestly instead of fabricated
  zeros), and grouped-product composition moves to a hydrated Children card that shows
  attached tombstoned children truthfully.
- **The Omnibox Launcher — product creation as one smart screen** (admin SPA):
  `/commerce/products/new` replaces both the old create slideover and its interim form
  with a single surface: a smart input that conservatively parses a trailing money token
  ("Aurora Desk Lamp $89", "89.99", or currency-neutrally "89 GHS"/"GHS 89" with the
  tenant's own code — BigInt major-unit math, bare unmarked integers stay in the name) plus
  a four-card type row (Physical/Digital/External/Grouped, keyboard 1–4) that morphs the
  surface — External swaps the price affordance for its required Link field, Grouped
  collects name only. Honest chips (name, formatted price, derived slug/SKU, type state)
  show exactly what the one atomic "Create draft" action will do before any row exists;
  the launcher stands alone, with the editor's sections appearing on the page the create
  lands in. Single-flight submission,
  values retained on validation errors, `router.replace()` into the editor on success,
  and unsaved-changes guard participation throughout.
- **The composed product editor — identity bar + Command Center** (admin SPA, phase 1 of
  the approved edit-page design): every product page now opens with an identity bar
  (thumbnail from the media read, name, slug · type · price line, status pill, and — for
  drafts — the Activate shortcut, replacing the old draft banner). Active products
  additionally open with a Health card computed from the shipped per-product reads:
  factual counts only (images, categories, lowest tracked stock against the store's
  low-stock threshold), warning rows deep-linking to their owning sections, and a failed
  stock read shown honestly as unavailable rather than as zeros. Drafts lead with the
  editor; everything stays fully editable in every state.
- **Command Center completed — trade + recent orders** (admin SPA, on glueful/commerce
  1.6.0): active products' opening strip gains the "Last N days" tile (product-attributed
  revenue and order count, mirroring the products report's discipline) and a Recent orders
  panel linking into order detail — both powered by commerce 1.6.0's per-product order
  activity read. Admins running an older commerce degrade gracefully: the panels are simply
  absent, never an error banner.
- **Product type is now editable while it safely can be**: the editor previously
  rendered type as permanently read-only ("Type is set at creation"), but Commerce's
  actual rule only rejects a type change while the product carries variants, grouped
  children, or cart/order references. The Details card now mirrors that honestly — a
  variant-carrying product shows type locked with the real reason; a variant-free
  product (e.g. an external listing) gets a working type select, `type` rides the
  payload only when actually changed, and switching to `external` reveals the required
  link field in the same save. The rarer server-side locks surface as field-mapped
  validation errors.
- **Editor chrome polish**: the storefront pane's toggle is labeled "Preview" (was
  "Mirror"), and the product page's dashboard navbar no longer repeats the product
  name above the identity bar — it shows the quiet back-context "Products" instead.
  The compact Pricing & stock card's layout is fixed: three fields on an even
  three-column row, a normal "Save pricing" button beside the formatted preview
  (previously a full-width stretched bar below an orphan grid hole), the redundant
  "Optional" help dropped, and the stock quantity + Adjust control on one row. The
  "Compare-at price" field is relabeled **"Original price"** everywhere (with help
  text "Shown crossed out beside the price, marking a sale") — Shopify jargon that
  explained nothing to anyone else; the API field stays `compare_at_price`, and the
  Pricing digest now reads "was $129.00".
- **Condensed section cards — the editor's resting state** (admin SPA): the product
  editor now matches the approved composed mock. Section cards (Details, Images,
  Pricing & stock, Organization, Grouped products) rest collapsed as one-line digests —
  field rosters, real thumbnails, `SKU · $19.99 · compare-at $29.99 · 24 in stock` —
  with their state chips, and expand from the header, the section nav, or any identity
  bar / Health strip jump (every jump now expands first, then scrolls). A card holding
  unsaved edits, a saving state, or a failed save refuses to collapse. Collapse is
  CSS-only (panels stay mounted), so no dirty draft can be lost to a remount and
  expanding is instant. The rarely-touched tail (Add-ons / Downloads / Linked content)
  condenses into one quiet row until asked for. Digests stay honest: counts, prices,
  and stock appear only once their reads resolve — never fabricated values. The section
  nav becomes the mock's rail: a hairline down the left with a primary accent segment +
  bolder text for the active item (no filled background), attention as a tinted dot
  pill on the right, and empty hints compacted to "· n".
- **Prices are typed in major units everywhere**: every price input in the product
  editor (compact pricing card, variant add/edit, bulk price, compare-at) now takes the
  amount the storefront shows — `19.99`, not `1999` minor units — hydrated and parsed
  through BigInt round-trip helpers in `useMoney.ts` (no float arithmetic ever touches
  an amount) and gated on the tenant currency's exponent from `/commerce/meta`, so a
  wrong-scale write is impossible. Field help shows the tenant currency code.
- **The Live Mirror** (admin SPA + storefront pack): a toggle in the product identity bar
  trades the section nav for the REAL storefront product page in an embedded frame — the
  server-built absolute `storefront_url` the product-link projection already carries, so
  perfect fidelity with zero preview components to maintain. The pane reloads as sections
  save (and on a manual refresh); active products also gain a "View in store" link. The
  storefront can't render drafts, so drafts get an honest placeholder, never a fake
  preview. Enabling this also HARDENS the shop product pages: they previously sent no
  frame headers at all (frameable by anyone) and now carry
  `Content-Security-Policy: frame-ancestors 'self' <admin-origin>` (from
  `RENDER_ADMIN_URL`; unconfigured leaves responses untouched and the Mirror disabled —
  never a wildcard), applied before the shop cache so cached responses carry the policy
  too. (Still pending, commerce-gated: the Command Center's recent-orders and trade
  panels behind an orders-by-product read.)
- **External products actually work now**: creation previously omitted the API-required
  `metadata.external_url` (every external create 422'd), and the editor had no surface
  to change the link afterward. The launcher collects the link at create, and Details
  gains an External link + button-label fieldset for external products that merges into
  existing metadata rather than replacing it.
- **Full tenant resolution and operations**: verified custom domains plus
  subdomain fallback for public delivery, header/JWT resolution for the admin,
  a resumable fresh-boot activation flow, tenant/domain/membership HTTP and CLI
  management, a persisted admin tenant switcher, and centralized
  `X-Tenant-Id` injection with membership-revocation recovery. Framework blob
  routes receive generic app-contributed middleware and canonical-origin seams;
  Thallo supplies tenant-aware implementations without moving tenant policy into
  framework core. Domain mutations purge only the affected tenant's render and
  sitemap namespaces. All resolver profiles remain inert until full activation,
  preserving tenancy-off and SP1 bootstrap behavior.
- **Form block**: a generic `form` block (contact-form preset in v1) whose
  submissions are stored, best-effort emailed, spam-guarded, and triaged/exported
  in the admin. Built on a **sealed-descriptor** model: `blocks/form.twig` calls a
  single gated `form_render(block)` Twig function that derives the field list and
  seals an AES-256-GCM descriptor (`EncryptionService`, aad `form.descriptor`) in
  ONE pass via an app-bound `Thallo\Contracts\Content\FormSealer`, so the exact form
  the visitor saw is the exact schema the server validates — the recipient and
  config never appear in the markup. Descriptor lifetime is
  `max(FORMS_DESCRIPTOR_MAX_AGE, render cache_ttl + FORMS_DESCRIPTOR_BUFFER)` so a
  page-cached form never outlives its token; an un-routable form (no valid recipient
  and no `FORMS_DEFAULT_RECIPIENT`) refuses to seal and renders a disabled notice, so
  the endpoint is unreachable. `POST /_forms/submit` (reserved `/_forms` prefix,
  IP-rate-limited, unauthenticated — the sealed token IS the authorization) opens the
  descriptor, runs a guard chain (honeypot → time-trap → per-form-key+IP rate-limit,
  reasons recorded server-side only), validates against the sealed fields, stores a
  normalized snapshot, and best-effort emails through a soft `FormMailSender` seam
  (unbound → no-op; storage is the source of truth). AJAX and no-JS POSTs share one
  response path: field errors as JSON or a PRG `?form_ok=`/`?form_err=` redirect;
  spam rejects always return generic success. `redirect_url` is root-relative-only,
  validated at seal and at submit. An admin **Submissions** area (`GET/PATCH/DELETE
  /v1/admin/form-submissions/*`, gated `content.manage`) lists/filters/reads/deletes
  submissions, exports CSV (fixed metadata columns unioned with every field key
  seen), and drives a sidebar unread-count badge. New config `config/forms.php`
  (`FORMS_DESCRIPTOR_MAX_AGE`, `FORMS_DESCRIPTOR_BUFFER`, `FORMS_MIN_SECONDS`,
  `FORMS_RATE_MAX`, `FORMS_RATE_WINDOW`, `FORMS_DEFAULT_RECIPIENT`) and migration
  `022_CreateFormSubmissionsTable`. Block options: a `delivery` mode
  (`store_and_email` default, or `email_only` which skips storage — the choice is
  sealed server-side), a selectable submit-button style (`submit_variant`/
  `submit_color`, reusing the button block's classes), and compact
  Tailwind-style fields that self-constrain to the reading measure like every
  other content block.
- **Blog posts block**: a dynamic `blog_posts` leaf block that lists published
  `post` entries as cards at render time. Introduces a `Thallo\Contracts\Delivery\
  EntryListReader` seam (engine impl `EngineEntryListReader`, modeled on
  `FacetCountsReader`/`facets()`): server-side limit clamp `1..12`, newest/oldest
  order, and an optional category filter that auto-detects the first filterable
  reference field in schema order and matches against the published-reference
  projection. The reader carries its own cache tags — the broad
  `thallo:type:{slug}` listing dependency (from the resolved type identity) plus
  per-item entry tags and category term tags — collected into the render's
  `Cache-Tag` header. Exposed to Twig as `entries(type, opts)` and `is_preview()`;
  the block shows an empty-state placeholder only in the editor/canvas
  (`is_preview()`), rendering nothing on the public site. The card is an inline
  same-file macro (no separate `blogPost` block in v1); `columns 1..4`,
  `outline/soft/subtle/ghost/naked` variants, and vertical/horizontal orientation.
  Row→item+href shaping is shared with the listing route resolver via an extracted
  app-internal `ListingItemShaper`. A `cover` (asset) field is added to the seeded
  `post` content type. Author byline is deferred (needs an author-identity seam).
- **Pricing blocks**: five Nuxt-UI-Pro-modeled block types for the default theme —
  `pricing_plan` (a card: price, discount, billing, one-per-line features with a
  uniform icon, flat CTA, `outline`/`solid`/`soft`/`subtle` variants + `highlight`),
  `pricing_plans` (a grid/stack wrapper with auto column `--count`, a group-level
  featured-plan `scale`, and horizontal/vertical orientation cascaded to cards via
  CSS), and `pricing_table` (a feature-comparison table with `pricing_tier` columns
  and a flat `pricing_feature` list — section-heading rows plus positional per-tier
  cells `value_1..4` capped at 4 tiers, `✓`/`yes`/`-`/`no`/literal tokens; renders a
  desktop `<table>` and a mobile stacked list, both server-side). CTAs are flat
  fields (no nested button block) and the feature list is flat (no section block) so
  the blocks stay within `BlockDepth::MAX` and nest one wrapper deep. Self-contained
  rounded cards (deliberate radius exception); accent rings use borders, not raw
  box-shadow, to honor the shadow-token invariant.
- **Shadow system**: the default theme's single flat `--shadow` is replaced by
  a Tailwind v4-derived elevation scale in `site.css` — `--shadow-none` and
  `--shadow-2xs…2xl` (verbatim Tailwind geometry/opacity), each composed from an
  overridable `--shadow-color` + `--shadow-strength` via `color-mix()` so an
  element can retint (colored shadow) or restrength (opacity modifier) its
  shadow. `--shadow` re-aliases to `--shadow-md` (every existing surface stays
  md, no per-line edits); the nav overlay moves to `--shadow-lg`; dark mode just
  overrides the two knobs (black / strength 2.5). Matching `.thallo-shadow-{level}`
  utilities ship in `blocks.css`. Page-builder controls: the **Style block** gains
  `shadow` (depth), `shadow_color`, `shadow_opacity` (0–200), `padding` and
  `margin` — color/opacity emitted as inline vars only when they pass a
  render-time shape/range guard; the **Container** gains `shadow` depth. New
  `thallo:blocks:sync` command additively propagates evolved starter fields onto
  existing block-type rows (never removes; preserves order; `--dry-run` preview).
- **Email admin**: Settings → Email is now the full email admin, a pure
  client of glueful/email-notification 1.11's API. Transport settings are
  DB-backed (saved via `PUT /email/settings`, applied on the next send — the
  old `.env`-writing `EmailSettingsController` and its routes are retired);
  a new **Mail templates** section manages every registered template
  (accordion per template with subject, HTML body editing, placeholder chips
  from definition metadata, save with inline engine-violation 422s, and
  reset-to-default); and a send-test modal covers both a plain transport
  test and per-template test-sends rendered with placeholder samples —
  real sends, domain policy applying. `email.templates.manage` is ensured
  and granted to administrator in the folded roles seed (the Aegis catalog
  sync is CLI-only, so the migration creates the permission row if missing);
  a 403 hides the templates section gracefully. The test-migration harness
  gained the extension's migrations path; the mailer select derives from the
  configured mailer set (hardcoded sendmail dies). Twig 3.28's `ConfigNode`
  joined the template-policy node allowlist (CACHE_VERSION 9 → 10) — the
  deny-by-default policy flagged the upgrade exactly as designed.
  The Mail templates section also manages the LAYOUT PARTIALS (layout,
  header, footer, and the new styles partial — the clean CSS-injection
  point: the layout includes it inside its <style> block, so overriding it
  restyles every email without touching document structure); body-only
  editing with per-type highlighting (CSS for styles), save/reset, and the
  same inline lint 422s. Requires glueful/email-notification with partial
  support (> 1.11.0).
- **Live theme setting**: the site's theme is now an admin setting — a Theme
  card in Settings → General (options from a new
  `GET /admin/render/themes`), DB override → `RENDER_THEME` env → `default`,
  applied on the next page view with no restart. Write-time validated (you
  cannot store a nonexistent/broken theme); a row that goes stale later logs
  and falls back — never a 500 (the env ladder is unchanged: missing env dir
  still silently defaults, a present-but-broken env theme.json stays loud).
  `/theme-assets/*` moved from a boot-frozen static mount to per-request
  serving with an explicit MIME map, and `asset()` URLs now carry a
  `?t={theme}` cache-buster so browser caches never survive a theme switch;
  a `ThemeChanged` contract event purges pages AND themed error bodies via
  the standard `lemma:render:page` tag. New lemma-contracts seam:
  `Settings/ThemeSettingProvider` (raw stored override, never the resolved
  fallback).
- **Site custom CSS**: a DB-backed, per-theme `custom.css` — the styling
  escape hatch. Rides the existing template store (versioning, history,
  restore, and the purge pipeline for free) as the ONE allowed non-twig
  path; save/restore validate it as CSS (UTF-8 + configurable size cap,
  `LEMMA_CUSTOM_CSS_MAX_BYTES`) instead of Twig-linting, and it never falls
  back to the filesystem. Served at `GET /custom.css` with immutable
  year-long caching — safe because the layout links it via a new
  policy-gated `custom_css()` function as `/custom.css?v={version_uuid}`
  (TemplatePolicy CACHE_VERSION 8 → 9), loaded AFTER the theme stylesheets
  so operator rules win the cascade. Admin: a pinned "Site" entry in the
  templates tree (always visible; 404 opens an empty editor, not an error)
  with CSS syntax highlighting and trust-model framing (site styling for
  operators under templates.manage — not a content-editing surface). The
  templates page also gained a dashboard panel layout, independently
  scrolling panes with a vertical divider, collapsible folder groups
  (collapsed by default, folder/file icons), a search filter that
  force-opens matching folders, and a theme indicator/switcher backed by a
  new `themes` list in the listing API. Theme asset files (`assets/*.css`,
  `assets/*.js`) and `theme.json` also join the tree as READ-ONLY viewers
  (lock icon, no save/history, editor read-only with per-type syntax
  highlighting) so operators can browse class names to override in
  `custom.css` — writes to those paths remain impossible at the API. The
  editor gained autocomplete: real CSS property/value completion
  (@codemirror/lang-css) and a Twig completion source seeded from the
  TemplatePolicy allowlists (functions/filters/tests + tag snippets), so the
  editor never suggests what the linter would reject. Themes can now be
  CLONED: `php glueful lemma:theme:clone <name> [--from=]` or the Duplicate
  button next to the templates-page theme switcher — a full server-side copy
  into `themes/{name}/` (strict lowercase name grammar, refuse-overwrite,
  loud error on unwritable app dirs, theme.json name rewritten), immediately
  valid and selectable.
- **Site identity**: favicon + dark-mode logo settings. Two new
  GeneralSettings keys (`site_favicon`, `site_logo_dark`) round-trip through
  `PUT /admin/settings/general`. Twig: new `site_favicon()` joins the
  sandbox allowlist (TemplatePolicy `CACHE_VERSION` 7 → 8) and resolves the
  uuid through the SAME `media()` predicate — a private/deleted/gated blob
  yields null and the layout emits NO `<link rel="icon">` (favicon fetches
  are anonymous; a 401ing link is worse than a missing one). `site_logo()`
  gains a strict variant argument (`null|'light'|'dark'` only; anything else
  returns null — a DB template can never widen it into a settings lookup);
  dark unset falls back to the light logo. The default theme's header and
  logo block render a light/dark img pair gated by a template-emitted
  `--has-dark` modifier (CSS swaps under `prefers-color-scheme: dark`);
  without a dark upload the light-only markup is byte-identical. Admin:
  Site identity card gains "Site logo (dark)" and "Favicon" uploads with a
  browser-tab `FaviconPreview` mock (app tile + fake tab with favicon, site
  name, ×); AssetField's single-asset preview drops the uuid text for a
  larger image — identity lives in the media picker's filenames, with the
  uuid kept as a `title`/`alt` tooltip + assistive-tech affordance.
- **Icon picker**: `GET /admin/icons?set=lucide|brands` exposes the render
  pack's VENDORED icon inventory (glob parity with what `icon()` renders,
  per-process memo). String fields gain editor-hint formats — `STRING_FORMATS`
  (`icon` | `brand-icon`), type-scoped like text's `plain|rich`, validation
  unchanged (pattern/enum; seeded brand-icon schemas PAIR the `brand:`
  pattern, so API-written bare names 422). A searchable page-numbered
  `IconPickerModal` (80/page, one page of tiles in the DOM, pinned selection,
  Clear, empty state) behind a compact `IconField` — wired through
  StringField's format branch (custom types get it by declaring the format)
  and used directly by navigation menu items. Brand fields display bare names
  but store `brand:`-prefixed values.
- **Navigation block v2 + menu-item icons**: the navigation block becomes a
  real site-nav component — the `menu` field is picked from existing menus in
  the editor (cosmetic select; the slug + pattern rule stay the contract),
  plus align (start/center/end), size (sm/md/lg), active_style
  (underline/pill/none), hover_style (color/underline/pill), submenu_icon
  (curated: chevron-down/chevron-right/plus/none) and submenu_trigger
  (hover/click) enums. Nested menu items now RENDER (v1 dropped them):
  CSS-only dropdowns — hover mode via :hover/:focus-within, click mode via
  native `<details name>` (name-exclusivity is progressive enhancement;
  parents with their own url repeat it as the first child). One nesting
  level; grandchildren flatten. Active state matches against a new
  `current_path` render-context value normalized by the SAME normalizer as
  the page-cache key (`RenderPageCache::normalizePath()`, extracted public
  static, HTTP-path hygiene only — canonical decisions stay with the
  pre-render 301s), passed through to block contexts; item urls are absolute
  resolver output, path-compared in the template. Menu ITEMS gain an
  optional Lucide `icon` (column folded into the create-table migration +
  manual dev/test DB sync; lucide-only grammar validated at tree save with
  dot-path 422s; carried by resolver + admin tree; rendered via `icon()`
  with label-only fallback; live preview chip in the tree editor).
- **Global regions (editable header & footer)**: header and footer become
  structured block regions (`lemma_regions`: slug PK, blocks + settings JSON) —
  the same `{id,type,data}` model as entry blocks, validated by the real
  `FieldValidator` plus SERVER-enforced per-region palettes (a deliberate
  divergence from the picker-only `block_types` convention) and a fixed
  settings vocabulary (header: sticky/width; footer: width). New structured-
  source starter blocks: `navigation` (selects a MENU slug, renders via
  `menu()`) and `social_links`/`social_link` (brand-namespace icons through
  `icon()`, safe_url links) — 34 starters total. Rendering goes through the
  new `region_blocks()`/`region_settings()` sandbox functions
  (CACHE_VERSION → 7): `region_blocks()` returns `Twig\Markup`, suppresses
  canvas annotation internally (chrome ids are not entry blocks), and returns
  null for EVERY unavailable state — unbound reader, missing table, absent
  row, saved-empty list — so the layout falls back to the hardcoded chrome;
  hiding is per-page via the new `_presentation.header/footer`
  (`default`|`hidden`) keys. Saves broad-purge the render page cache
  (`RegionUpdated` → `lemma:render:page`, the menu/template posture). Admin:
  `GET/PUT /admin/regions` (palettes ship in the response) + a Site → Header
  & footer page reusing the blocks editor with palette-filtered pickers and
  clobber-safe dirty tracking. Setup seeds default regions (logo + main nav /
  site-name footer) so fresh installs are region-editable from minute one.
  `POST /admin/regions/preview` renders the UNSAVED chrome through the real
  theme pipeline (save-identical validation, never writes) into a sandboxed
  blob-URL iframe on the page — debounced auto-refresh + manual refresh,
  last-good-preview with an explicit "Preview not updated" stale badge on
  validation errors.
- **Columns sizing**: the columns block gains `widths` ratio presets
  (50-50 … 25-25-50) and `align` vertical alignment (stretch/top/center/
  bottom) — exact-token modifier classes emitted from a single template
  allowlist map (mismatched layout/preset, unknown or absent values render
  equal columns; stretch/absent is byte-identical to the old markup).
  Additive schema only — no content rewrite; defaults live in template/CSS/
  editor.
- **Icon library**: `icon(name)` Twig function serving vendored inline SVGs —
  the full Lucide set (1.23.0) by default and a curated 27-brand Simple Icons
  (16.24.1) set under `brand:` (normalized to `fill="currentColor"` at import;
  exact brand color is theme CSS). Strict name grammar `^(brand:)?[a-z0-9-]+$`;
  unknown/invalid names return null so templates fall back to text. Returns
  `Twig\Markup` (no `is_safe`) so `{{ icon(x) ?? x }}` renders the SVG raw
  while string fallbacks stay auto-escaped. `icon` joined the DB-template
  sandbox allowlist (CACHE_VERSION → 6); `IconAssetsTest` regression-gates the
  vendored tree (no active content, brand normalization, manifest↔shipped
  parity with `VENDORED.md`). Feature block `icon` field now renders Lucide
  SVGs with the legacy free-text/emoji fallback preserved, and gains a
  Lucide-only `pattern` on newly seeded installs.
- **Icon block**: a 31st starter block — one Lucide glyph with size
  (small/medium/large), alignment (start/center/end), optional safe-url link
  with an accessible label (falls back to the icon name). Lucide-only
  `pattern` on the name; unknown names degrade to escaped text.
- **Rich HTML sanitization**: `RichHtmlSanitizer` contract + TipTap-scoped
  allowlist implementation over symfony/html-sanitizer (additive-only config,
  explicit 1MB input limit, task-list `data-*` preserved, checkbox inputs
  stripped, protocol-relative hrefs dropped by a custom attribute sanitizer).
  Enforced at SAVE in `FieldValidator` for `format: rich` fields — including
  rich fields inside blocks via the existing recursion — and at RENDER via the
  new `safe_html` Twig filter (fail-closed: unbound or throwing sanitizer
  escapes instead). `safe_html` joined the DB-template sandbox allowlist
  (CACHE_VERSION → 3). Unblocks the `rich_text` starter block.
- **Page/block builder**: a `blocks` content field type — ordered `{id, type, data}`
  lists inside entry JSON, so versions/publish/localization/delivery work unchanged —
  backed by a global admin-defined block-type registry (`lemma_block_types`:
  slug-immutable, deactivate-over-delete, free-form `category` grouping the block
  picker — presentation-only, nothing branches on it — and schemas reusing the
  content field vocabulary minus nesting/localization/filterable). Per-block validation with
  dot-path errors and publish-time dangling-reference checks; `block_types`
  field allowlists are picker-only by design. Structured block-list editor in the
  entry editor (picker, reorder, duplicate, collapse; nested fields reuse the
  existing widgets) + a Block Types settings screen. Rendering via a new `blocks()`
  Twig function through `blocks/{type}.twig` (theme or DB-edited templates), added
  to the DB-template sandbox allowlist with a policy cache-version bump. References
  inside blocks stay raw uuids (no auto-expansion in v1).
  Follow-up (same day): **container blocks** — block schemas may nest `blocks`
  fields (sections, columns) up to a centralized depth of 3 (`BlockDepth::MAX`,
  mirrored and test-asserted in the render pack and SPA); depth-aware validation
  via an explicit internal depth parameter; recursive block editor with a
  max-depth notice and a cycle-free async registry entry; render-scoped depth
  counter in the reset family. No sandbox-policy change.
  Follow-up (same day): **starter block library** — `lemma:blocks:seed` (idempotent,
  opt-in, never overwrites) seeds 10 starter types with default-theme templates and
  style-convention modifier classes (styling ships standalone as `blocks.css` so
  custom themes adopt it by copying one file); new `media(uuid)` helper (MediaUrlResolver
  contract — public + anonymously-retrievable blobs only, full blob-route-stack
  parity) and `safe_url` filter (scheme-allowlisted hrefs); both joined the
  DB-template sandbox allowlists (CACHE_VERSION → 4).
  Follow-up: **block reference auto-expansion** — references inside block data now
  expand in place (same batch loading, depth-2 reference-hop budget, and scope gates
  as top-level references; block structure never consumes expansion depth; asset
  fields never expand). Expansion targets now feed cache correctness everywhere:
  `Cache-Tag` carries `lemma:entry:{target}` for every expanded target (delivery API
  and rendered pages purge when an embedded target republishes) and the delivery
  ETag folds in sorted target `entry:version` identities (no more false 304 after a
  target republish). Unresolved targets contribute neither (surrogate-header
  privacy). Also fixes the dormant top-level bug where `asset` fields were passed to
  entry expansion (splicing them to null): asset values now always pass through raw.
  Follow-up: **block-schema migrations + hard-delete** — block-type schema edits
  are now additive-only; renames/deletes are declared migrations (rename/delete
  ops, one active per type) with an eager queued backfill that rewrites every
  current draft and republishes every pinned publication (non-deleted entries,
  archived included, nested to the depth cap). While a migration is active
  (running OR failed), saving/publishing entries containing that block type 409s
  — closing the unstamped-instance data-loss window. Version rollback projects
  block data once through the completed-migration timestamp suffix (microsecond
  precision) and materializes a new version when anything changed (the rollback
  response now reports the ACTUALLY pinned version); restoring a version that
  references a hard-deleted block type is blocked with a clear error. New usage
  endpoint (current drafts + publications, archived included, nested; historical
  versions excluded; picker allowlists reported, not gating) and zero-usage-gated
  hard delete (server-side re-scan, no force flag). `entry_versions.created_at`
  now persists microseconds. CLI: `lemma:blocks:migration:backfill` re-drives a
  failed backfill.
  Follow-up: **Notion-like block editor UX** — inline insert dividers with a
  searchable block picker, `/` quick-insert, drag handles with cross-container
  drag (subtree-aware depth guard: a drop that would exceed the nesting cap is
  rejected in place, never a post-hoc validation error), keyboard movement
  (⌘/Alt+↑↓ move, ⌘D duplicate, Enter expand, Delete confirm), an outline rail,
  deep-copy duplicate (fixes nested-list aliasing), and the prose seam: block
  types shaped as a single rich-text field render chromeless as flowing prose,
  an empty tail offers "Type here…", and `/` inside prose can insert a widget
  block mid-text by splitting the prose block (original id kept for the before
  half; one structured-tree operation). TipTap/UEditor remains bounded to text
  editing — the Vue block tree stays canonical. SPA-only; stored model,
  validation, and render contracts unchanged.
  Follow-up: **visual canvas (v1)** — a full-screen Design view per entry:
  theme-rendered preview iframe (real Twig through the preview session; every
  preview render now annotates blocks() output with layout-inert
  `.lemma-preview-block` wrappers and injects a token-free, nonce-correlated
  postMessage bridge), click-a-rendered-block-to-edit selection into a
  full-form inspector (the same FieldEditor/BlocksField the editor uses),
  entry-wide outline rail, responsive viewport presets, and an explicit
  Save & refresh loop (saveDraft + re-minted preview per apply; 409 handling
  mirrors the form editor). Select-only stage in v1 — structure edits stay in
  the inspector's Notion UX; no HTML/CSS editing surface. New stored-contract
  invariant: block ids are unique across the whole entry (validated).
- Canvas stage toolbar (v2): selecting a block in the Design view's stage now
  shows an in-preview toolbar — move up/down, duplicate, delete (confirmed in
  the admin), and add-after (per-list block picker). Structural edits route
  through the inspector's block tree and mirror optimistically in the stage
  until the next Save & refresh; save failures reload the stage to the
  last-applied render. The inspector's insert menus (and the prose `/` menu)
  now respect a nested container region's own `block_types` allowlist.
- Ephemeral preview render (loop C): the Design view's primary action is now
  Apply — the working block tree is validated with the exact draft-save guard
  set (block-migration gate included) and stashed in cache, and the stage
  reloads its same preview URL to render unsaved work instantly. Save draft
  persists as before and clears the stash; version-pinned preview tokens can
  neither write nor read a working copy (409 `PREVIEW_VERSION_PINNED`).
- Fixed: the render page cache built per-path keys containing raw `/`, which
  the framework's Redis cache driver rejects — every live render 500'd on
  Redis. Keys now rawurlencode the path segment.
- Edit-in-place text (canvas v3): double-click a prose block in the Design
  view's stage to type directly into the rendered page — bare contenteditable
  with native shortcuts, debounced back into the block tree, server-touched
  only at Apply/Save (existing sanitizer chain). Renderer marks prose
  rich-field output via a new soft-bound `BlockEditableFieldResolver`
  contract; non-prose blocks are never marked.
- Editable string fields (canvas v4): themes opt plain string/text fields
  into edit-in-place with `{{ data.heading|editable_text('heading') }}` —
  single-line strings commit on Enter, multiline text keeps newlines, and
  the admin's schema-derived kind matrix is the grant/patch authority. The
  starter theme adopts it across hero/section/quote/image/cta (never
  unwrapping conditional emissions; attribute values stay unfiltered).
- Auto-apply (canvas v5): the Design view re-applies the working tree
  automatically on an 800ms debounce — suppressed during in-place edit
  sessions, coalesced to one in-flight request, suspended (with one banner)
  on failure until a manual Apply succeeds, and toggleable per browser. The
  stage's scroll position now survives every reload, including manual
  Apply's.
- Free drag (canvas v6): drag the stage toolbar's grip to reorder a block
  within its list, sortable-style — the page reorders live under the
  pointer, one move lands in the block tree on drop (validated same-list by
  the inspector's ops), Escape cancels, and a rejected drop snaps the stage
  back to truth. Cross-container moves stay in the inspector.
- Stage keyboard shortcuts (canvas v7): with a block selected in the stage,
  Alt/Option+Arrows move it, Backspace/Delete opens the delete confirm,
  Cmd/Ctrl+D duplicates, Enter enters in-place editing (only when the block
  OWNS exactly one editable region — keyboard Enter stays equivalent to the
  wrapper double-click fallback, and both now share one owned-region rule,
  fixing the pointer fallback adopting a container's CHILD region), and
  Escape deselects (new `block-deselect` notification keeps outline/inspector
  selection honest). Guarded against edit sessions, drags, the bridge
  toolbar, and theme form controls.
- In-stage formatting bubble (canvas v8): rich edit sessions show a
  selection-following formatting bubble (TipTap-style, positioned off the
  selection rect) — bold, italic, underline, strikethrough, link, unlink —
  applied in place and normalized into the sanitizer's allowlist shape
  (`b/i/strike` → `strong/em/s`, styled spans unwrapped) both after each
  action and at commit. The
  commit-time pass also fixes a latent v3 bug: native Cmd+B output
  (`<b>`) was dropped WITH its text by the save/render sanitizer, so
  bolded text vanished at the next apply. Links are added through an
  inline input panel inside the bubble (no browser prompt): the edit
  session survives focus moving into the panel, the text selection is
  saved and restored around the command, URLs validate against the
  safe_url posture before execCommand runs, and invalid input keeps the
  panel open marked invalid.
  The bubble cancels pointerdown/mousedown (the edit session never blurs)
  and every action schedules the debounced commit explicitly.
  The bridge's CSP pin is reworded: appearance stays in preview.css and no
  style attributes are ever emitted, but bridge-owned UI may be positioned
  via CSSOM transform (which strict style-src does not restrict).
- Fixed: an in-stage edit session now commits-and-ends when focus leaves
  the stage window (clicking into the inspector, switching tabs) —
  cross-frame focus moves don't reliably fire the region's own blur, so
  the session could outlive stage focus and pin the parent's edit-session
  suppression, silently blocking every inspector-driven auto-apply until
  a stage click or manual Apply (whose flush ends the session) healed it.
  Stage-refresh acks carry a diagnostic detail (swap counts / reload
  reason) for debugging.
- Fixed: the auto-apply debounce gained a max-wait (~2.5s) — anything
  touching the tree more often than the 800ms window (a browser extension
  like Grammarly re-emitting editor updates, a theme timer) restarted the
  trailing timer forever, silently starving auto-apply for inspector
  edits. A change stream may now DELAY an apply, never prevent it.
- Fixed: opening the canvas now reconciles the working-copy stash — the
  stash outlives sessions (keyed by entry+locale, cleared only by save),
  so an abandoned session's stash silently overlaid the draft on the next
  open, starting the stage and the tree OUT OF SYNC in a way stageStale
  cannot detect. One initial apply of the hydrated tree (after the stage
  loads, regardless of the Auto toggle) overwrites the stash with truth.
- **Starter block library expansion (10 → 30)**: 15 new page-level blocks —
  `container` (free-form styled wrapper: validated hex background/overlay
  colors + asset background image emitted as CSS custom properties in ONE
  style attribute, width/padding/min-height enums, holds child blocks),
  `grid` (wrapping grid or CSS-columns masonry of any blocks), `features`,
  `testimonials`, `faq` (native `<details>` accordion, per-instance
  exclusive-open groups), `tabs` (CSS-only radio tabs, per-instance ids),
  `steps` (CSS-counter stepper), `button` (solid/outline/soft/ghost ×
  sm/md/lg), `carousel` (scroll-snap base with zero JS; arrows/dots/autoplay
  via a new deferred `blocks.js` theme asset that no-ops in the canvas stage
  and honors prefers-reduced-motion), `logo` (renders the new `site_logo`
  site setting, falls back to the site name), `logo_cloud` (pure-CSS
  marquee option), `video` (uploads via native `<video>`; YouTube/Vimeo by
  URL with iframes built server-side from a parsed id — raw user iframes
  never render), `audio`, `html` (verbatim raw output; seeds DEACTIVATED —
  an admin explicitly activates it), `shortcode` (renders
  `shortcodes/{name}.twig` through the theme/DB template hierarchy;
  missing → nothing live, dashed placeholder in preview) — plus 5 `Items`
  child blocks (`feature`, `testimonial`, `faq_item`, `tab`, `step`)
  reusing nested blocks as repeaters. **`hero` and `cta` redefined** to the
  Nuxt UI PageHero/PageCTA shapes (headline/title/description/links +
  orientation/reverse; cta gains solid/outline/soft/subtle/naked variants);
  button links-rows collapse into flex rows with context-forced sizes.
  Every user URL field renders through `safe_url`. `blocks()` now passes
  the caller's `site` context to block templates. Reference doc:
  `docs/NUXT_UI_PAGE_COMPONENTS.md`.
- **Schema-declared value constraints**: field definitions accept `pattern`
  (anchored regex for string/text, compilability checked at schema save)
  and `min`/`max` (number bounds) — enforced by FieldValidator with
  dot-path errors and threaded through EVERY schema surface (domain parse +
  serialize, request/response DTOs, OpenAPI, admin types; the admin
  normalizer also stopped dropping `reference_type`/`block_types` on read).
- First-run setup now seeds a renderable site: "Pages" (publicly delivered,
  mounted at root — /about), "Posts" (publicly delivered, prefixed —
  /post/hello; title/excerpt/body/categories) and "Categories" — the
  taxonomy worked-example (taxonomies are ordinary content types +
  filterable reference fields; posts carry a multi `categories` reference,
  so /post/categories/{slug} archives, /post/terms/categories indexes and
  facets work out of the box). Deliberately ONE seeded taxonomy — a `tag`
  type is a two-minute copy of the same recipe. Previously the single
  seeded type was neither public nor root-mounted, so a fresh install
  rendered nothing until flags were flipped by hand.
- The render listing/archive allowlist is now a **General setting**
  (`listing_types`, Settings → General multi-select): the DB row wins
  ('' = explicitly none) with the deploy config (`RENDER_LISTING_TYPES`)
  as the pre-first-save fallback; unknown type slugs 422 at write time;
  setup seeds it with `post`. Closes the half-working-default trap where a
  seeded filterable field 404'd its archives until an env edit + restart.
- `site_logo` general setting (Settings → General asset picker) + sandbox
  functions `site_logo()` and `video_embed()` (TemplatePolicy
  CACHE_VERSION 4 → 5).
- **Root-mounted content types**: a per-type `mount_at_root` flag serves
  entries at `/{locale?}/{slug}` (`/about`, `/fr/a-propos`) instead of the
  type-prefixed `/{type}/{slug}` — the marketing-site URL shape. The root
  grammar is FIXED (never derived from `LEMMA_SEO_ROUTE_TEMPLATE`); type
  paths take precedence at resolve time; the prefixed path 301s to the root
  canonical (content only — redirect rows follow their descriptor in one
  hop); rename redirects keep working at root. A `RootMountGuard` owns the
  global root namespace at WRITE time (409/422, never silent shadowing):
  root slugs and redirect sources cannot collide with type slugs, reserved
  prefixes/exact paths (`v1`, `sitemap.xml`…), active locale codes,
  `page`/`terms`, or each other — with a self-reclaim exception for an
  entry renaming back to its own previous slug; flipping the flag ON
  validates every existing route + redirect source first, and either flip
  purges the render page cache. A single `CanonicalPathBuilder` now makes
  the prefixed-vs-root + default-locale-collapse decision for EVERY href
  surface: resolver/listing hrefs, nav targets, SEO canonical + hreflang
  (per-pin type flags), search index hrefs, sitemap, delivery hrefs,
  redirect targets, and the LemmaContext seam — fixing latent off-canonical
  `/en/...` emissions in redirect targets, sitemap, search, and hreflang
  alternates along the way. Admin: "Mount at root" toggles on the type
  create screen and type editor (409 conflict lists surface in the toast;
  the switch reverts — the flag never flips partially).
- Content-type metadata is now editable post-creation: `PATCH
  /content-types/{slug}` updates name/description/cache_ttl/**public_delivery**
  (slug stays immutable; the schema keeps its own endpoint). Previously
  `public_delivery` was creation-only — a type created with the switch off
  (the default) could never be made publicly deliverable. The type editor's
  read-only "Public delivery" row is now a live toggle; flipping the flag
  purges the render page cache so stale public/404 responses don't linger.
  Type dropdowns (navigation Add page, homepage picker) show non-public
  types disabled with a "not publicly delivered" hint instead of hiding
  them.
- Navigation entry items are now first-class in the admin: "Add item"
  split into **Add link** (url) and **Add page** (published-entry picker —
  the UI previously could only create url items, leaving the entry-kind
  machinery unreachable). Menu labels INHERIT the target page's localized
  title when empty (resolver fallback: label locale → default → any →
  published title) — typed labels override, clearing re-inherits, renames
  flow to the nav on publish. The admin tree payload carries
  `target_title` so the label input's placeholder shows the exact
  inherited string, plus the resolved target path next to the status
  badge. Newly added page items show the inherited-title placeholder
  immediately (the picker hands back the display title), not only after
  save/reload. Fixed: entry-item target paths now collapse the default
  locale (`/pages/home`, not `/en/pages/home`) — matching the site's
  canonical rule, so menus no longer link off-canonical.
- DB-backed homepage setting: the homepage is now an admin-editable site
  setting (Settings › General → Homepage picker, or "Set as homepage" in
  the entry editor's publish panel; a Home badge marks the entry in the
  content list and publish panel). Stores the entry uuid in
  lemma_settings; env `RENDER_HOMEPAGE_ENTRY` remains the deploy default.
  Write-time validation requires a published entry of a publicly
  delivered type (422 otherwise); clearing DELETES the override row so
  the env fallback shows through; a valid-at-write override that later
  breaks (unpublished/deleted) is re-validated per request by a
  source-aware `HomepageEntryProvider` contract — it logs and falls back
  instead of 500ing, while an invalid ENV value keeps the loud config
  error. One homepage entry, entry-level locale.
- Modern default theme: the reference theme is now a polished modern-SaaS
  site — fluid type scale, spacing/radius/color tokens with automatic dark
  mode, sticky translucent header, namespaced shell classes
  (.site-header/.site-nav/.site-footer — block templates' own
  header/nav/footer elements no longer inherit shell styling), full-width
  flow with per-block containers, and a restyled starter block library
  (full-bleed gradient hero, panel CTA, section bands, card columns, pull
  quote, cover-fit gallery). Presentation is a new LAYERED contract: one
  composed `presentation` template context (`show_title`, `layout`)
  resolves per-page override → theme.json per-type → theme.json default →
  built-ins. Themes declare defaults in a strictly-validated `settings`
  block; editors override per page from the canvas inspector's new
  **Page** tab. Overrides live under the reserved `_presentation` fields
  key — validated against a fixed vocabulary regardless of schema,
  versioned/previewed/published with the page, STRIPPED from all delivery
  payloads, and schema field names may never start with `_` (reserved
  for system keys). The homepage
  template now renders a blocks body through blocks() exactly like
  entry.twig (it previously printed the array through the scalar
  fallback). DB template overrides are untouched; disk fallbacks update
  everywhere no override exists.
- Partial DOM patching (canvas v10): successful Apply/auto-apply no longer
  reloads the stage iframe — the bridge fetches a real render of the
  working copy from the stage's own URL, proves the page shell and the
  top-level block skeleton identical (live mirrors count: mirrored
  move/duplicate/delete orders patch; unmirrored drift reloads), and swaps
  only the wrappers whose HTML changed. Typing never flickers; scroll,
  selection, and the session survive untouched. Anything unprovable —
  fetch failures, shell drift, added/removed blocks — answers with an
  honest full reload, and failure paths keep today's reload semantics.
  New nonce-enveloped, refresh_id-correlated stage-refresh/stage-refreshed
  message pair.
- Session-wide working-copy overlay: the canvas's applied-but-unsaved
  working copy now wins over the draft EVERYWHERE the preview session
  overlays the draft — canonical URLs and (new) the homepage, which
  previously never overlaid even the draft. One resolver-side overlay
  helper keyed off the verified read's own entry+locale; version-pinned
  sessions keep ignoring working copies; listings/archives/terms keep
  their published-only posture; session renders keep bypassing the page
  cache (regression-asserted with a cache sentinel). `PublicRouteResolver::
  resolveEntry` gains an optional trailing `?PreviewSession` parameter
  (additive).
- Canvas polish batch (v9): bubble buttons show active-state (via
  `queryCommandState`, treated as untrusted — missing/throwing = inactive;
  link state via region-contained-`<a>`; classes cleared whenever the
  bubble hides so stale marks can never flash); drags get a
  cursor-following ghost (stripped compact clone, built on first move,
  torn down on drop/cancel/Escape) and edge auto-scroll (48px zones, one
  interval, direction follows the zone); the outline answers the same
  keyboard scheme as the stage (Alt+Arrows move, Backspace/Delete opens
  the centered confirm, Cmd/Ctrl+D duplicates, Escape deselects both
  parent state and the stage ring) through the page's existing intent
  handlers — no new mutation paths.
- **DB-edited templates**: theme templates editable from the admin (new Templates
  screen with CodeMirror editor, per-template version history, restore, delete-with-
  fallback). Storage is per-theme + append-only (`lemma_render_templates` /
  `_versions`); a pack-owned DB-first loader (deliberately not Twig's `ChainLoader`,
  whose persistent exists-cache breaks DB-only templates) with per-render reset and
  version+policy-keyed compile-cache keys (no compiled-cache purging). Enforcement is
  a static AST policy scan (`TemplateLinter`) at save (422 with line numbers), at
  compile, and on restore — no runtime sandbox; `raw`, macros, arrow-function filters,
  dynamic include/extends targets, and method calls are denied. Active-theme mutations
  purge the render page + error caches; themed preview sessions render that theme's
  overrides. Kill-switch: `RENDER_DB_TEMPLATES`. New permission: `templates.manage`.
- **Preview sessions (preview v2)**: `/_preview/{token}` now starts a signed-cookie
  session (Secure on HTTPS; dies with the token) — full-site navigation in preview
  chrome with an Exit link, the tokened draft overlaid at its canonical URL
  (single-draft scope: everything else stays published), listing/archive/term pages
  navigable uncached, and in-session 404s rendered fresh. New contracts:
  `PreviewSession` VO + `PreviewSessionVerifier` (one verification per request via
  `PreviewSessionMiddleware` — sessions survive `cache_enabled=false`) and
  `PreviewThemeValidator` (render-owned mint validation). Optional per-preview
  `theme` is signed into the token; themed sessions render through request-local
  Twig environments with token-scoped `/_preview-assets/{token}/…` assets.
- **Taxonomy term index pages**: `/{type}/terms/{field}` renders every term of a
  filterable reference field with counts and archive links (`terms` joins `page` as a
  reserved segment, sealed at the paged 5-segment form too; allowlist-gated; 500-term
  cap). The resolver kind is THIN — the render controller fetches via
  `FacetCountsReader` and dispatches on its now-pinned invariant (empty `cache_tags` ⇔
  gate failure → themed 404; valid-but-empty → 200); index pages carry both type tags
  so publishes purge them structurally.
- **Preview-through-theme**: `GET /_preview/{token}` renders drafts/pinned versions
  through the active Twig theme (structurally uncached dedicated route; `no-store` +
  `noindex`; fail-closed themed 404s; `preview` template flag + default-theme banner).
  `PublicRouteResolver` gained `resolvePreview()` (kind `content` + `preview: true`
  flag); the mint response gained a server-decided `theme_url` (`null` when rendered
  delivery is off) and the admin editor a "Preview in theme" action.
- **`facets()` in Twig** over the new `FacetCountsReader` contract (`{items,
  cache_tags}` — a valid empty facet still tags the page); a render-scoped tag
  collector merges facet tags into `Cache-Tag`, so facet sidebars purge event-driven.
- **OpenAPI**: the render pack's HTML routes (`GET /`, `GET /{path}`, `/_preview/…`,
  `/theme-assets/*`) are excluded from the generated spec (`Default` and
  `Theme Assets` joined the tag deny-list).
- **Rendered listing & archive pages** (V2 render follow-up): `/{type}` and
  `/{type}/{field}/{term}` (+ `/page/n`, + locale prefixes) through the render
  catch-all — `PublicRouteResolver` gained `listing`/`archive` kinds with
  LIST-shaped items carrying batch-rendered `href`s; archive membership rides the
  `published_entry_references` projection; path-based pagination with `/page/1`
  canonical 301s and `total_pages = max(1, ceil(total/per_page))`; cached pages
  carry the broad `lemma:type:{type}` tag so any publish purges them. Opt-in via
  `RENDER_LISTING_TYPES` (default off). New default-theme templates
  `listing.twig`/`archive.twig`/`_pagination.twig`; `page` is reserved as an
  archive field segment.
- **Term archives + facet counts** (the taxonomy delivery surface the references spec
  deferred): a new `published_entry_references` projection (listener-maintained on
  publish/unpublish/delete, re-driven by `lemma:resync`, schema-projected so rollback
  re-pins stay correct) is the single source of "published source references published
  term". `GET /v1/content/{type}/facets?fields=…` returns global per-term counts
  (`{uuid, slug, count}`, `count DESC, slug ASC`, limit 100/max 500);
  `GET /v1/content/{type}/archive/{field}/{term}` returns the shaped term + its members
  with the list endpoint's exact pagination modes. Target-type visibility is fail-closed
  (a non-public term type 404s — no term enumeration); term liveness is a read-time
  publication join, so unpublished terms drop out immediately. Cache purging rides the
  existing surrogate tags with zero new invalidation code. `facets` becomes a reserved
  word under `/v1/content/{type}/`.
- **Render page caching** (`glueful/lemma-render` — V2 sub-project 3): `RenderPageCache`
  middleware keyed `render:{theme}:{normalizedPath}`; only `200 text/html` content
  renders cached per path; single fixed 404/410 body per theme served via
  `RenderErrorCache` BEFORE the template renders (bogus URLs cost resolver queries
  only); ETag/304 with `Cache-Control: public, max-age=0, must-revalidate`; entry/type
  purges ride the existing surrogate-tag listener; `MenuUpdated` purges broadly;
  TTL-only fallback on non-tag cache drivers. Config: `RENDER_CACHE_ENABLED` /
  `RENDER_CACHE_TTL`.
- **`php glueful render:cache:clear`** — operator purge for the rendered-page cache
  (theme file edits are not event-visible).
- Rendered entry pages (and cached 404/410 bodies) now emit `Cache-Tag` surrogate
  headers, so CDN purging composes with rendered pages via the existing
  `PurgeCdnListener`.
- **Rendered delivery core** (`glueful/lemma-render`, new capability pack — V2
  sub-project 2): Lemma serves real HTML pages from published content through filesystem
  Twig themes. One lowest-priority catch-all feeds raw paths into the new
  `PublicRouteResolver` contract (core wraps the routing/addressability layer:
  normalization-first canonical 301s, route-template parsing, anonymous visibility,
  redirect/410 passthrough, and the read-only public delivery shape + content-type slug
  for template selection — delivery item shaping extracted into a shared
  `DeliveryItemShaper`, responses byte-identical). Pack-embedded default reference theme
  (escape-by-default; `|raw` is a theme-author opt-in) with app `themes/` override and
  per-template fallback; `menu()`/`path()`/`asset()` context functions (navigation
  optional; no dead links; path-safe assets); reserved paths return standard JSON 404s;
  homepage via `index.twig` with loud-but-not-leaky config errors; Twig compile cache
  with auto-reload. Shipped uncached-first; the render page cache followed (above).
- **Navigation / menu builder** (`glueful/lemma-navigation`, new capability pack — V2
  rendered-delivery sub-project 1): menu trees as data with per-locale label maps and
  published-only resolution. New `lemma-contracts` seams: `MenuReader` (menus for
  render/frontends; null ≡ pack absent) and `EntryTargetResolver`
  (`published|unpublished|deleted|missing|routeless` + path, where `published` means
  addressable — publication AND route — and path is null otherwise), implemented by core.
  Public `GET /v1/menus/{slug}` (rate-limited); admin CRUD + atomic whole-tree
  `PUT /menus/{slug}/items` guarded by `lock_version` (stale → 409), recursive validation
  (depth 6, 500 items, URL schemes; `missing`/`deleted` targets 422, `unpublished`/
  `routeless` allowed), locale-aware editor payload (`target_status` per `?locale=`).
  `navigation.manage` permission (granted to `administrator`); `MenuUpdated` event as the
  future render-cache purge seam. Admin SPA: Navigation page with tree editor (per-locale
  labels, entry picker with target badges, up/down/indent/outdent, 409 reload handling).
- **Approval / review workflow** (`glueful/lemma-workflow`, new capability pack): a
  single-stage editorial state machine over draft/publish — submit → in_review →
  approved / changes_requested, per entry+locale. Publishing requires an approved review
  or the new `workflow.bypass` permission (409 with `details.workflow_state` otherwise);
  bypass publishes are recorded as `published_with_bypass` in the append-only
  `workflow_transitions` history. Edits invalidate active review/approval
  (`changes_requested` survives; resubmit clears it); self-review is blocked
  (`lemma_workflow.allow_self_review` escape hatch); scheduled publishes follow the same
  gate at run time with the schedule's stored creator. Core gained one seam:
  `PublishGate`/`PublishBlocked`/`DraftSummaryReader` in `lemma-contracts`, consulted by
  `PublishService` via the `lemma.publish_gate` container tag — no gates registered means
  byte-for-byte pre-seam behaviour. New permissions `workflow.review`/`workflow.bypass`
  (granted to `administrator`); admin API under `/v1/admin/workflow` (submit / approve /
  request-changes / withdraw / state+history / review queue). Admin SPA: workflow panel in
  the entry editor + a capability-gated Review queue page.

### Changed
- Commerce taxonomy forms (Categories, Tags, Attributes and attribute values) auto-derive
  the slug from the name while creating — same behavior as the content-type create screen:
  the slug stays editable, a direct slug edit stops the auto-derive, and editing an existing
  record never rewrites its slug.
- **Starter content type renamed: "Product page" → "Product story"** (slug `product_page` →
  `product-story`, sourceId `thallo-commerce:product-page` → `thallo-commerce:product-story`):
  the old name read as the storefront page a product displays on, when the type is actually
  the editorial enrichment content linked to a commerce product. Existing installs rename the
  provisioned row in place — update the provenance row's `source_id` to the new spelling,
  then `thallo:tenant:sync --all --kind=content_type` renames the type (same uuid; entries
  and product links untouched). Done pre-launch so no published install ever carries the old
  identifiers. The add-to-cart starter block's description now says "Product story" as well.
- **Modules, not extensions** (2026-07-25 design, executed on framework 1.72.0): the ten
  `packages/thallo-*` provider packages are now library-typed composer modules registered in
  `config/serviceproviders.php` — they no longer appear in the extensions catalog,
  `config/extensions.php`, or the in-admin extensions browser, which now shows only real
  installable extensions. Load order is declarative (`DeclaresLoadOrder`): modules share the
  post-extension priority tier in list order, with thallo-commerce explicitly ordered after
  glueful/commerce's provider. The resolved boot order is identical to the previous
  activation list (verified against a captured baseline) except thallo-search, which now
  always loads with its behaviour gated by the new `thallo.search` capability
  (`config/thallo.php`, off by default — the `/v1/search` route and the real reindexer stay
  inert, preserving the previous opt-in posture). The tenancy enforcement provider is listed
  in the new `extensions.protected` map, so every generic enable/disable surface (framework
  CLI and controller, Thallo's extensions admin) refuses it with a pointer to
  Settings › Workspaces.
- **lemma-contracts (BREAKING):** `MenuUpdated` moved from
  `Glueful\Lemma\Navigation\Events\MenuUpdated` to
  `Glueful\Lemma\Contracts\Navigation\MenuUpdated` (cross-pack seams live in
  contracts; lemma-render subscribes without depending on lemma-navigation). No
  deprecated alias — subscribers must re-import the contracts FQCN (none existed
  in-repo before this change).

### Fixed
- The `/cart` page now re-renders after a successful cart mutation. Its rows, per-line
  forms, totals and empty-state are server-rendered, but the mutation response only fed
  the mini-cart regions — so a removed line stayed on screen until the visitor refreshed
  by hand. shop.js now reloads when the cart page is the one displaying that state
  (`data-shop-cart-page`), landing on exactly what the no-JS PRG redirect reaches, for the
  same two round trips; add-to-cart elsewhere still never discards the page being read.
- Test harness: dedicated in-process boots now reset the provider route-file latch
  (`ServiceProvider::resetLoadedRoutes()`) alongside the existing `RouteManifest` reset,
  so a second boot no longer silently loses every pack route another boot loaded first.
  Five capability-off route tests were updated to production-parity expectations exposed
  by the fix: with render's `GET /{path}` catch-all present, non-GET probes of absent
  pack routes return 405 (path claimed for GET), not 404.
- Extension activation writes (the tenancy enablement flow and the admin extensions toggle)
  now recompile the provider cache from the just-written `config/extensions.php` — the
  context config cache is cleared before the recompile, which previously resolved through
  the enabled list cached at boot and persisted the pre-write activation state. The tenancy
  flow also switched from an explicit extensions-only provider list to the full resolved
  list, so the recompiled cache always carries the always-on Thallo modules.

### Security
- Admin routes (`lemma_permission` gate) now require API-key principals to carry a key scope
  satisfying the required permission slug (wildcards via fnmatch; empty scope list = deny), on top
  of the owner's RBAC. Previously any leaked key — however narrowly scoped — inherited its owner's
  full admin rights, including schema DDL.
- Collections: an API key minted with an empty scope list no longer gets full read/write/delete on
  every scoped collection (the framework's `scopeSatisfies([]) === true` legacy semantics are
  overridden to default-deny in `CollectionAccessResolver`).
- Collections capabilities are now namespaced `collections.{name}.{op}` (was the bare
  `{name}.{op}`, as `products.write`): a collection named after another scope/permission family —
  e.g. `users` — no longer fails open to that family's unrelated `users.read` grants. **Breaking
  for pre-release keys/permissions minted with the unprefixed form** — re-mint with the
  `collections.` prefix.
- Collections public data routes are now rate-limited (reads 120/min, writes/deletes 60/min,
  bulk-create 20/min; keyed by the authenticated principal, per-IP for anonymous public reads),
  matching every other public Lemma surface.
- Access-policy replacement (`PATCH /v1/admin/collections/{name}/access`) — the mutation that can
  make a collection world-readable/writable — is now fully audited: it stamps the acting admin on
  a `collection_schema_changes` row (`update_access`, policy payload) and dispatches
  `CollectionUpdated('access_updated')`.
- Importers (from the `glueful/lemma-importers` package review): WordPress (WXR) HTML bodies are
  now sanitized with `symfony/html-sanitizer` before storage (scripts, iframes, event handlers,
  and `javascript:` URLs dropped; normal markup kept) — previously the WXR `content:encoded` HTML
  was stored verbatim and served to delivery consumers, a stored-XSS vector the pack's own
  Markdown importer already defended against.
- SEO (from the `glueful/lemma-seo` package review): the public routes (`/v1/seo/meta/...`,
  `/sitemap.xml`, `/sitemap/{n}.xml`, `/robots.txt`) are now rate-limited like every other
  anonymous Lemma surface. Sitemap page numbers are bounded by the actual page count (404 beyond
  it) — previously every distinct `{n}` minted a permanent (no-TTL) cache entry plus a deep-OFFSET
  enumeration query, an anonymous cache-fill vector.

### Added (product editor)
- **Rich-text product descriptions**: the Details card's Description field is now the
  CMS's own RichText editor (Tiptap) — bold, italic, lists, links, images — storing an
  HTML string. A blank/empty document still saves as null. The storefront renders it
  through the render pack's fail-closed `safe_html` sanitizer (markup kept, scripts and
  event handlers dropped — including their inner text, for the JSON-LD plain-text
  description) with prose styling in `shop.css`; legacy plain-text descriptions render
  unchanged.

### Added
- **Store settings, editable from the admin** (needs glueful/commerce 1.6.0): Commerce ›
  Settings gains a **Store** tab (now the default) editing currency, tax rate (entered as a
  percent, stored as basis points), order number format (live preview), order payment window,
  cart lifetime, and the low-stock threshold. Values live as ordinary `settings`-table rows —
  per-workspace under tenancy — flowing into commerce through its new runtime-settings seam;
  a cleared field DELETES its row so the `.env`/config default shows through, and every field
  shows that default as help when not overridden. **Currency locks once ORDERS exist** —
  recorded money history, not catalog contents: a setup store full of draft products changes
  currency freely, with existing prices keeping their numbers ($700.00 becomes GH₵700.00 —
  warned inline, and every variant's currency code follows the store so checkout keeps
  working). Once an order exists, changes are rejected server-side with a field-level error
  and the input renders disabled with the reason. Changes apply on the next request — no
  deploy, no restart.
- **Marketplace tab: activation seller select no longer corrupts the page**: the "No default
  seller" option used an empty-string value, which reka-ui's SelectItem rejects at setup —
  corrupting the component tree and surfacing as unhandled Vue patch/unmount errors (11 of
  them failing CI's vitest job despite 1677 passing tests). The option now uses a sentinel
  mapped to null on submit. Alongside: the vitest setup gains `enableAutoUnmount(afterEach)`
  so stale wrappers can't re-render against torn-down DOM (the charts spec opts out — @unovis
  containers can't survive destroy in jsdom), which is what converted these deferred unhandled
  rejections into attributable in-test failures.
- **Disable wizard: `disabled_widened` no longer dead-ends on "Continue"**: the panel mapped
  the disable direction's resting state to the RE-ENABLE entry (`begin`) behind a generic
  "Continue" label — while the run still awaited fresh-boot verification, begin refuses, so
  clicking Continue produced "Disabled-widened mode is awaiting fresh-boot verification" with
  no way forward. The awaiting-verification sub-state now offers "Verify and finish disable"
  (the settle path — `disable()` again), and the settled state offers an explicit
  "Re-enable workspaces" instead of a bare Continue.
- **Workspace disable no longer blocked by customized starters** (policy revision, sp2c §6):
  `customized` is a fully known, user-owned state whose provenance row survives disable, so the
  gate now blocks only genuinely incoherent provenance — missing starters, starter-shaped
  content without provenance, dangling/wrong-key rows, and orphaned sources. Previously any
  site that had edited its header could never disable workspaces, and the prescribed remedy
  (`thallo:tenant:sync`) deliberately preserves customized state — a dead end.
- **Honest "purging" state on the Workspaces page**: a purging workspace whose purge run
  needs operator attention — dispatch never landed, the job failed, a worker died mid-run,
  or the run has sat queued untouched past a two-minute grace window (no queue worker
  consuming, the dev-install classic) — now says so under its tag: "Purge is waiting for a
  queue worker", with the exact commands to run. One server-side predicate
  (`PurgeRunRepository::isStalled`) decides; the tenant list enriches purging rows with
  `purge_stalled`, and healthy runs (fresh queue, live lease, retry backoff) stay untagged.
- **Marketplace tab in Commerce Settings**: per-workspace marketplace activation (with a
  default-seller select for adopting existing products) and the workspace fallback commission
  policy (percentage-as-bps or fixed minor units), fronting commerce's own marketplace services
  through new pack endpoints (`GET /v1/admin/commerce/marketplace` + activate/deactivate/
  commission/master) graded like every other settings surface. The master switch is a RUNTIME
  toggle: `commerce.marketplace.enabled` joined the settings seam (commerce ≥ 1.7.0 —
  `MarketplaceMode::installEnabled()`, the single choke point checkout and the webhook
  publisher re-check per call, reads through it), so the off-state card offers an "Enable
  marketplace" button instead of `.env` instructions; the env var remains the config default
  and ops kill-switch, and commerce's direct marketplace REST API (external integrations)
  still requires it at boot. Switching off entirely is offered only while the workspace is
  inactive (deactivate first). Sellers, payouts, and financials remain a future Marketplace
  admin area.
- **Download link lifetime on the Store tab + webhook URLs on the Payments tab**: the signed
  download-URL TTL (`commerce.downloads.url_ttl`, 60s–7d, default 300) is runtime-editable
  through the settings seam (needs commerce ≥ 1.7.0 for the stored value to reach the signer;
  the field itself works on 1.6.x), and each Payments gateway card now shows the copy-able
  `/webhooks/{gateway}` URL for the gateway dashboard — origin from the canonical public-origin
  resolver, never the request Host header.
- **Emails is now its own Commerce Settings tab — switches + relocated templates**: the four
  buyer order emails (confirmation, payment, fulfillment, cancellation) gained per-template
  on/off switches (`GET/PUT /v1/admin/commerce/emails`; settings rows over pack-config defaults,
  all ON; the sender checks per send, so a switch applies to the next order event), and their
  subject/body editors MOVED from Settings › Email into the new tab — the same registry and
  email-notification API, reused filtered to commerce-owned templates, so overrides, test
  sends, and resets keep working unchanged. Settings › Email keeps transport (SMTP/sender)
  and every non-commerce template. When `commerce.email.enabled` activates commerce's own
  dormant mailer, the tab banners that Thallo's sender stands down and the switches govern
  nothing — one mailer, never double emails.
- **Payments is now its own Settings tab, with full gateway configuration**: default gateway,
  per-gateway enable toggles, and the API keys themselves (secret key + webhook secret) are
  editable in the admin — no more `.env` round-trips to configure payments. Keys are stored
  ENCRYPTED (framework AES-256-GCM, AAD-bound to the setting key) and are write-only on the
  wire: the server only ever reports `{set, source}` booleans back, an empty input means "keep
  the stored value", and the explicit Clear action deletes the row so any `.env` value shows
  through again. Values flow to payvia through its new settings seam (payvia ≥ 2.2.0), which
  decrypts on read — `GatewayManager`, both drivers, and webhook verification all see the
  stored keys immediately, no restart. With no gateway extension installed the tab honestly
  reports manual collection. Payment credentials are installation-level (webhook signature
  verification precedes tenant resolution), recorded as enforcement-time work in the spec.
- **Store identity on the Store tab**: store name, business address, and tax ID (the invoice
  header — commerce's `commerce.seller.*` keys, now runtime-editable through the same settings
  seam). The read-only payments status card that briefly lived here moved into the dedicated
  Payments tab above, upgraded to full configuration.
- Store tab polish: currency is a curated dropdown (an env-configured code outside the list
  still renders), and Commerce › Settings moved to the END of the Commerce nav. Safety: if an
  operator enables commerce's own dormant order mailer (`COMMERCE_EMAIL_ENABLED=true`),
  thallo's order-email sender stands down automatically — one sender at a time, never double
  emails (the templates stay editable either way).
- **Order emails** — commerce now sends transactional email: order confirmation, payment
  received, order fulfilled, and order canceled, each rendered through the email extension's
  template registry so all four appear — editable, with placeholder chips and test-send — in
  the EXISTING Settings › Email page (the Store tab links there). Sends are at-most-once per
  template×order via idempotency keys, a mail failure never fails checkout/fulfillment (logged;
  the notification retry queue is the recovery channel), and orders without a usable buyer
  address skip silently. Honesty notes: no order links in the emails yet (the storefront
  confirmation page authenticates by cookie, so a link wouldn't open — tokenized status links
  are the follow-up), and card checkouts send both "confirmation" and "payment received"
  (per-template switches are the other noted follow-up).
- **Price and stock on the products list** (needs glueful/commerce 1.6.0): the catalog table
  gains Price and Stock columns — one amount for a single-variant product, a `$19.99 – $29.99`
  range plus a variant count when they differ, and the on-hand quantity for tracked inventory.
  Both degrade honestly: untracked inventory, an unknown total (a variant missing its stock
  row), and an older commerce that doesn't send the summary all render "—", never a fabricated
  0 that would read as "out of stock". A tracked zero IS shown, in warning color.

### Fixed
- The draft product's primary action is labeled **"Publish"** (was "Activate") —
  matching the CMS's own content vocabulary and what Woo migrants expect; the toast
  says "Product published" and the Preview pane's draft placeholder now reads
  "Publish the product to see it exactly as customers will."
- The Details card's Type field now uses the launcher's own type cards (one shared
  ProductTypeCards component — Physical/Digital/External/Grouped with icons and
  taglines) instead of a bare select. When the type is locked (the product carries
  variants), every card renders inert with the current type still marked and the
  lock reason below — the choice stays visible instead of collapsing to plain text.
- Product list polish (the Nuxt UI table layout): the "Select all on page" text
  button is replaced by a real header checkbox (checked / indeterminate / empty
  tracking the page's selection), and the search + status/type filters move out of
  the dashboard navbar into a toolbar row with the table — search left, filters
  right. Every commerce list's default page size is now 25 (the page-size dropdown
  offers 10/25/50/100; the old default of 24 wasn't in the list, so the select
  rendered a value it didn't contain).
- Leaving the product editor (or launcher) with unsaved changes now asks through a
  real in-app modal — "Discard unsaved changes?" naming the dirty sections, with
  Keep editing / Discard changes — instead of the browser's native "thallodev.dev
  says…" confirm. The route guard parks the navigation on a promise until the modal
  answers; dismissing (Esc/backdrop) safely stays. Tab close / hard refresh keeps
  the browser's own dialog — browsers allow no custom UI there.
- The storefront product page now looks like a store, and actually shows the
  product's images: it only ever rendered a `role='cover'` media row — but the admin
  attaches every image as `role='gallery'`, so admin-managed products shipped an
  imageless store page — and its image URLs were hand-built `/blobs/…` paths that
  missed the API prefix and ignored blob visibility. Product/grid/featured-block
  images now resolve through the same anonymous-media URL authority rendered pages
  use (cover-role first, first gallery image as the honest fallback, unservable
  blobs skipped), the product page renders a real gallery with a thumbnail strip,
  prices display in customer form ("$89.00", intl-formatted with a "decimal CODE"
  fallback) with a struck compare-at for single-variant sales, JSON-LD keeps the
  plain decimal, and the pack ships a theme-neutral `shop.css` baseline (breadcrumb,
  gallery, price row, styled add-to-cart) through the fingerprinted immutable asset
  pipeline. The shop index and category archives get the same pass: a real product
  card grid (constrained square images, name, price with struck compare-at) instead
  of a raw bulleted list with an unconstrained full-size image, and the duplicate
  currency-code suffix is gone there too. This is the surface the editor's Preview
  pane embeds. Gallery images render whole (object-fit contain — product photography
  is never cropped), and the detail page's thumbnails are now real controls: clicking
  one swaps the main image (a shop.js enhancement over server-rendered buttons; every
  image stays visible without JS), with shop.js now loading on every product page —
  previously it only shipped inside the purchasable branch.
- The product editor's Activate button now actually activates: it was a scroll
  shortcut to the Details card's status control, which on the condensed page — with
  Details already in view — visibly did nothing, leaving drafts stuck and the
  storefront Preview pane on its draft placeholder. It now performs the real
  status → active mutation (loading state, success/error toasts, coordinator revision
  refresh), after which the Preview pane can render the live product page. Reversible
  via Details' Status select.
- Blob uploads no longer 403 for authenticated admins: `POST/GET-info/DELETE /v1/blobs`
  carried `tenant_profile:admin` (an authenticated-membership gate) but NO `auth`
  middleware — with `UPLOADS_ACCESS=public` the framework's blob routes add none of
  their own, so `auth.user.uuid` was never populated and the tenancy pipeline denied
  every upload ("Access to this tenant is denied") regardless of the bearer sent.
  `TenantBlobRouteMiddlewareProvider` now contributes `auth` ahead of the admin
  profile for upload/info/delete/sign; public blob viewing is unchanged. This had
  blocked adding images to products from the admin media picker.
- Admin SPA: capability-gated nav/panels now converge WITHOUT manual reloads when a pack is
  toggled. Enable/disable on the extension detail page polls the capabilities endpoint until
  the answer actually changes (the backend serves the pre-toggle list for a few seconds — the
  dev extension-cache TTL — so a single refetch loses the race), and the capabilities store
  re-fetches on window focus for toggles made outside the UI (CLI). Background refetches keep
  the previous set on transient failure instead of blanking the gated nav.
- Importers (from the `glueful/lemma-importers` package review): import/export batch uuids are now
  random — the deterministic `hash(adapter:sequence:offset)` uuids collided with the globally
  UNIQUE `import_export_batches.uuid` column, so the SECOND import ever run with the same adapter
  (including the core snapshot import/export in `app/Content/ImportExport/`) failed on its first
  batch. Mappings and `body_field` are now validated against the target schema (and WXR keys
  against the known set) at plan time — a typo'd field previously produced a "successful" import
  that silently dropped that data. CSV user imports now reject intra-file duplicate
  emails/usernames in dry-run (both rows, case-insensitive email — dry-run and commit report
  identically) and unknown `status` values (`active`/`inactive`); the deliberate
  email-verified-on-import behavior is now documented. The triplicated source-file/coercion
  helpers were extracted to a shared `ReadsImportSource` trait.
- SEO (from the `glueful/lemma-seo` package review): admin SEO-meta upserts are now validated
  (`SeoMetaUpsertDTO`) — non-string values, over-length fields, unknown `robots`/`twitter_card`
  values, and oversize locales are 422s instead of database-driver 500s. The upsert itself is now
  an atomic `ON CONFLICT` write (find-then-insert raced concurrent PUTs into a unique-violation
  500) with UTC timestamps. An empty-string `og_title`/`og_description` override now falls back
  like `title`/`description` instead of emitting `''`.
- Analytics (from the `glueful/lemma-analytics` package review): the admin read API now normalizes
  `from`/`to` to canonical `Y-m-d` before they reach SQL or the response echo — previously any
  PHP-parseable non-ISO date (`06/10/2025`, `next tuesday`) passed validation but was string/cast-
  compared raw against the `day` date column (wrong results on SQLite, DateStyle-dependent or a
  500 on Postgres). Removed the dead `analytics.enabled` / `ANALYTICS_ENABLED` config key (and the
  identical `SEO_ENABLED` key in `lemma-seo`) — nothing ever read them; the only gate is the
  `lemma.capabilities` switchboard, and the keys' comments falsely claimed they gated
  routes/listeners. `series?dimension=subject` without a `subject` is now a 422 instead of
  silently returning `__total__` counts mislabeled as a breakdown. Metadata `json_encode` failures
  now throw into the recorder's best-effort catch (logged) instead of inserting a raw `false`; an
  empty actor-hash key (ANALYTICS_HASH_KEY and APP_KEY both unset) now logs a boot warning that
  hashes are unsalted. README/permission label now mention the third endpoint (`breakdown`).
- Collections (pre-release hardening of `glueful/lemma-collections`, from the package review):
  every schema mutation (create, add/drop field, add/remove index, drop collection) now commits
  the definition write and its DDL in ONE transaction with an optimistic `schema_version` guard —
  a mid-operation failure can no longer leave the definition and the physical table permanently
  diverged (previously un-retryable: duplicate-column/missing-table DDL errors forever), a failed
  create no longer orphans the table (making the name uncreatable), and concurrent alters now get
  a 409 instead of silently losing one admin's field. Index ops carry their kind (`unique`/`plain`)
  fixed at plan time — dropping a unique constraint used to be impossible (and one path silently
  dropped the unique while metadata still claimed it), and `settings.index` on new fields was
  silently never materialized (inline indexes are discarded/constraint-ified by the create-table
  SQL path; all indexes are now planned as explicit alter ops). Admin truncate now requires the
  same `confirm` token as the other destructive ops, resolves the actor, dispatches a
  `CollectionTruncated` audit event, and no longer uses `CASCADE`. Validation hardening turns a
  raft of raw-driver 500s into per-field 422s: field type/duplicate-name/taken-collection-name
  checks, identifier length budgets (63-char Postgres limit incl. derived index names), enum
  `values` required, string/email/url length caps, 32-bit integer range, decimal precision fit,
  JSON fields actually validated (arrays encoded instead of stored as the literal `"Array"`),
  relation/asset lists capped at 100 and batch-verified (was one query per element), soft-deleted
  blobs rejected as asset targets, LIKE metacharacters escaped in the reference check, typo'd
  field names on index/drop ops now 404 instead of phantom-succeeding (bumping `schema_version`
  and dispatching events for no-op changes), the unique-index preflight no longer false-positives
  on NULLs, and array-valued query params / `filter[f][null]=false` truthiness no longer crash or
  invert list queries. Scoped JWT writes on the public data API are now attributed: the on-demand
  session auth memoizes the principal onto the request and `ActorResolver` reads the provider-level
  `user_data`/`user_id` attributes, so rows no longer stamp `created_by_id = NULL` for session
  users. Row create/update/delete now bracket their check-then-act pairs (relation-target
  existence, restrict-delete reference check) in a transaction with events dispatched after
  commit; malformed JSON bodies are a 400 instead of silently proceeding as `{}`; corrupt
  persisted `fields` JSON fails loudly instead of degrading to "zero fields"; `?expand` of
  multi-relations tolerates legacy non-string JSON members; session admins holding
  `collections.data.manage` can expand scoped targets in the admin data browser (previously
  403'd on targets they could already read directly); admin rows stamp
  `created_by_type='admin'` via the provider-level `is_admin` flag when roles are absent; and
  the permissions seed migration skips gracefully on hosts without an RBAC `permissions` table.
- Search (pre-release hardening of the unreleased `/v1/search` feature, from the branch review):
  Meilisearch-safe document ids (`{uuid}_{locale}` — colons are invalid Meilisearch ids, so
  nothing could ever be indexed against a real server); `entry_uuid` added to the filterable
  attributes so whole-entry purges work; the event-driven reindexer now ensures the index (with
  settings) before its first upsert, so a fresh install's first publish no longer creates a
  settings-less index that rejects every filtered search; visibility is resolved from the live
  content-type store per request instead of `public_delivery` denormalized into documents —
  flipping a type private now drops it from search immediately, and wildcard API-key scopes
  (`read:content:*`) now match types exactly as delivery does; an empty-string `title` field
  falls back to the entry label like a missing one; the search backfill orders by a total order
  (`+ locale`) so multi-locale entries can't be skipped/duplicated across pages, and memoizes
  per-type schema lookups.
- API-key requests through `optional_api_key` now set the post-auth `user` request attribute, so
  `rateLimit(..., by: 'user')` actually keys per user instead of silently degrading to per-IP
  (which made keyed clients behind one NAT share a single bucket).
- Audit log now shows the acting user's email/username (not a bare uuid) for content
  create/update/delete/publish actions. Content events dispatch after-commit, so the audit layer
  has no request to resolve a display label from; `PublishEventEmitter` now resolves the actor
  uuid → email/username (via `UserProviderInterface`) and attaches it to the event before dispatch.
- Media (asset) deletions are now audited. `MediaAdminController::destroy` soft-deletes via a raw
  `blobs` status update that bypassed `BlobRepository`'s entity events, so the deletion went
  unrecorded; it now dispatches a `MediaDeleted` audit event (category `media`) attributed to the
  acting user.

### Added

#### Content Search (`/v1/search`) — `glueful/lemma-search`
- Public, delivery-parity **content search** over published content, backed by Meilisearch via the
  `glueful/meilisearch` extension. `GET /v1/search?q=&locale=&type=&limit=&offset=` returns ranked
  hits with `<mark>`-highlighted, HTML-escaped snippets (payload under the standard `data`
  envelope).
- **Delivery-parity visibility**, enforced inside the Meilisearch filter (so `total`/pagination
  stay correct): `read:content` ⇒ all types, `read:content:{slug}` ⇒ those types, anonymous ⇒
  `public_delivery` types only. `type` omitted → inaccessible types silently excluded; `type`
  provided but inaccessible → 403; unknown `type` → 404.
- Live index maintenance through Lemma's existing `ContentReindexer` seam (identity-only,
  after-commit, wrapped so a search-backend failure logs and never breaks a publish); a whole-entry
  delete (`locale = null`) purges every locale document.
- Engine-neutral `SearchBackend` port with a single Meilisearch-confined adapter
  (`LiveMeilisearchIndex`), a `DocumentBuilder` (index string/text fields by convention, with a
  per-type `title_field`/`body_fields`/`exclude_fields`/`weights` override), and operator commands
  `search:reindex` / `search:status`.
- Fail-closed: Meilisearch missing/unhealthy ⇒ `/v1/search` returns 503. **Opt-in** capability
  (`lemma.search`) — not enabled by default (it requires external Meilisearch); enable via
  `extensions:enable lemma-search`. No migrations (Meilisearch owns storage).
- Contract additions in `glueful/lemma-contracts`: `IndexableContentReader`
  (`IndexableContent`/`IndexablePage`), `ContentTypeReader::isPublicDelivery()`, and
  `ContentReindexer::reindexEntry()` locale widened to `?string` for whole-entry deletes.

#### Data Collections (`/v1/collections`) — `glueful/lemma-collections`
- Developer-defined **data collections**: a JSON collection definition drives runtime DDL to
  materialize a real per-collection table (`collection_<hash>`), PocketBase-style — not a shared
  key/value store. Field types, validation, and filter/sort capabilities come from a shared
  `FieldTypeRegistry` (the `collections.*` type set).
- Public CRUD + query API at `/v1/collections/{name}`: list (filter/sort/offset pagination,
  field selection, one-level relation `expand`), get, create, patch, delete, and strict
  all-or-nothing bulk create — behind API-key scopes `collections.{name}.{read|write|delete}`,
  **default-deny** (no key, or a wrong/cross-collection scope → 403).
- Soft collection↔collection relations (validate-on-write target existence, bounded one-level
  expand, restrict-delete while a row is still referenced) and
  `CollectionRow{Created,Updated,Deleted}` change events.
- Auditable, recoverable schema lifecycle: every DDL op is bracketed by a
  `collection_schema_changes` row (pending → applied/failed), a unique pre-flight runs before any
  write, and destructive drops require an empty table or an explicit confirmation token.
- Removable capability (`lemma.collections`): disabling it removes the public routes but preserves
  every table; the pack depends only on the framework + `glueful/lemma-contracts`.
- **v1 limits:** `storage_mode` is `table` only; in-place field-definition changes are blocked
  (remodel via drop + add); `json`/multi-value columns are stored as `TEXT` for cross-driver
  portability; no rename/retype/bulk-patch, realtime, or row-level rules yet.

#### Delivery API (`/v1/content`)
- Public, read-only delivery of **published content only**. `DeliveryRepository` reads
  exclusively through `entry_publications ⋈ entry_versions ⋈ entries[status=active]` — there is
  no draft/status column on the read path, so drafts physically cannot leak.
- Admin deletion and routing endpoints for content types and entries: discard working drafts,
  soft-delete content types/entries, list published versions, and assign/list/remove entry routes.
- Delivery access gate with both global (`read:content`) and per-content-type
  (`read:content:{type}`) API-key scopes, plus per-type public delivery opt-in via
  `content_types.public_delivery`. Invalid supplied API keys still fail 401 and never fall
  through to public access.
- `FilterCompiler`: safe, typed, filterable-only JSONB filter predicates
  (`?filter[field][op]=value`) with always-bound values, sharing a `FieldSqlExpression` helper
  with the expression-index planner so predicates always hit their index.
- Filterable-field expression-index lifecycle: a queued `EnsureFilterIndexesJob` builds Postgres
  expression indexes (`CREATE INDEX CONCURRENTLY`) out-of-band; a registry table tracks them.
- `SortCompiler` + keyset (cursor) pagination, stable under publish churn (`v.id` tiebreaker).
  Sorting on an optional filterable field pins missing-value rows last (`NULLS LAST`, both
  directions) and the keyset predicate mirrors that, so rows missing the sorted field are never
  skipped across page boundaries. Framework offset pagination backs the `?page`/`?perPage` path.
- `ReferenceResolver`: batch-loaded, published-only resolution of entry-UUID references at read
  time (unpublished/archived targets resolve to `null`; depth-bounded).
- Field selection / `ETag` / `Cache-Tag` (`lemma:entry:{uuid}`, `lemma:type:{slug}`) /
  `Cache-Control` on delivery responses.
- SEO/routing module: route slug changes auto-capture 301 redirects, admins can manage manual
  internal/external redirects, single-entry delivery emits canonical/hreflang metadata, and moved
  paths return a headless redirect descriptor (`data.redirect`) instead of serving duplicate
  content at the old path.

#### Import/export
- `lemma.content` export adapter for `glueful/import-export`, registered through
  `import_export.exporter`. It writes deterministic NDJSON batch files containing content types,
  entries, drafts, versions, publications, routes, and reference/asset projections as
  `{kind, data}` records.
- `lemma.content` import adapter for `glueful/import-export`, registered through
  `import_export.importer`. It supports dry-run validation and commit-mode idempotent upserts of
  Lemma content NDJSON bundles by each record kind's natural key.
- Content-import adapters that create entries of a chosen content type from foreign formats, each
  driven by a field-mapping wizard on the Import/Export settings page (dry-run validates without
  writing; commit creates drafts and optionally publishes):
  - `csv.content` — one entry per CSV row, fields mapped to columns.
  - `markdown.content` — a Markdown/MDX document with front matter; the body is converted to HTML
    into a chosen field.
  - `wordpress.content` — a WordPress export (WXR); each `post`/`page` `<item>` becomes an entry,
    with scalar WXR keys (`title`, `excerpt`, `slug`, `date`, `status`, `author`) mapped to fields
    and `content:encoded` HTML routed into a chosen body field. Items with WXR status `publish` are
    published on commit. Attachments and custom post types are skipped.
- `csv.users` import adapter (and a bulk-import modal on the Users page) for creating users and
  their profiles from a CSV. Built on a reusable `AbstractCsvImporter` base that the content and
  user CSV importers share.
- Multi-valued + filterable references: `reference`/`asset` fields can be declared `multiple`
  (ordered uuid array, optional `max_items`), and `reference`/`asset` fields can be `filterable`.
  Delivery filters published entries by a reference target via JSONB array containment —
  `?filter[category][eq|in]=<uuid|slug>` — with slug→uuid resolution against the target type
  (`reference_slug_field`, default `slug`), GIN-indexed, and correct across single/multi/flipped
  fields. Admin gains builder controls and ordered multi-pickers. (Unblocks taxonomies + a future
  WordPress categories/tags importer.)

#### Localization
- i18n-backed content locale validation through `ContentLocaleService`: when `glueful/i18n` is
  installed, authoring, publishing, routing, and preview-mint locale params must be enabled i18n
  locales; without i18n, Lemma falls back to `lemma.default_locale`.
- Entry locale variant workflow endpoints: `GET /v1/admin/entries/{uuid}/locales` summarizes each
  locale's draft/publication/route state, and `POST /v1/admin/entries/{uuid}/locales/{locale}`
  creates a target-locale draft, optionally copied from a source locale draft.
- Field-level localization automation: source-locale copy now preserves non-localized/shared fields
  by key presence while leaving `localized: true` fields empty for translation.
- Per-locale RBAC support through Aegis resource-filtered grants: locale-targeted admin routes
  now authorize against `locale:<code>` while locale-agnostic routes keep the coarse `lemma`
  resource. Seeded unscoped roles remain backward compatible.
- Localization editor UX: per-locale publish/draft/scheduled status in the entry-editor locale
  switcher, locale-aware versions page, copy-into-existing-locale (overwrite), translation-coverage
  progress in the entry list, cross-locale route management, and bulk create/publish across locales.
  Disabling a language now warns when it still has published or draft content, backed by a new
  `GET /v1/admin/locales/{locale}/usage` endpoint.

#### Publishing pipeline
- A frozen PSR-14 content-event taxonomy (`entry.created/updated/published/unpublished/deleted`,
  `model.created/updated/deleted`, `asset.attached/detached`) with identity-only payloads
  (never full field content).
- Events dispatched from `db()->afterCommit(...)` — fire once, on the outermost commit only,
  never on rollback. Asset `attached`/`detached` deltas diffed on draft save.
- Listeners (registered in `LemmaServiceProvider::boot()`): `InvalidateCacheTagsListener`
  (invalidates the delivery cache tags), `DispatchWebhookListener` (core `WebhookDispatcher`,
  identity-only, gated by `pipeline.webhooks_enabled`), and capability-gated `PurgeCdnListener`
  / `ReindexSearchListener` (clean no-ops without `glueful/cdn` / a bound content reindexer).
- `lemma:resync` command: re-drives the idempotent effects (cache invalidation + search reindex;
  webhooks opt-in via `--webhooks`) for an entry, a type, or everything — published content only,
  bounded/keyset-paged.
- Scheduled publish/unpublish: `POST /v1/admin/entries/{uuid}/schedules/{locale}` creates or
  reschedules a pending publish/unpublish action, `GET /schedules` lists pending/history rows,
  `DELETE /schedules/{scheduleUuid}` cancels pending rows, and the every-minute
  `RunDueSchedulesJob` fires due rows through the normal `PublishService` path.

#### Version retention
- `lemma:versions:prune` operator command for manual, opt-in pruning of non-pinned
  `entry_versions` history, with `--dry-run`, `--keep`, and `--max-age-days` controls. Pinned
  publications are protected by a delete-time guard, and unset retention config preserves
  unlimited history.

#### Schema migrations
- Explicit destructive schema migrations for content types: `POST /v1/admin/content-types/{slug}/migrations`
  accepts tracked `rename` and `delete` field operations, records progress in
  `entry_schema_migrations`, flips the canonical schema immediately, and queues
  `lemma:schema:backfill` materialization.
- Read-time schema projection now replays pending migration operations for lagging drafts,
  versions, preview tokens, and delivery rows, so partially materialized catalogs still serve
  the current schema shape. Published backfills append and re-pin new migrated versions while
  preserving historical version rows.

#### Preview tokens
- HMAC-signed (`APP_KEY`) `PreviewToken` bound to `{entry, locale, version?}` with a minutes-scale
  TTL — signature verified constant-time before any payload is trusted; `exp` is inside the signed
  payload (no lifetime extension).
- Admin mint endpoint `POST /v1/admin/entries/{uuid}/preview/{locale}` (auth + `lemma_permission`);
  public `GET /v1/preview/{token}` — unauthenticated by design (the token is the capability),
  rate-limited by IP, fail-closed (invalid/malformed → 403, expired → 410, target gone → 404).
  Serves the entry's current draft, or a specific pinned version (bound to the token's entry+locale).
  This is the **only** door to a draft; the public delivery API can never see drafts.

### Changed
- `FieldValidator` normalizes `datetime` field values to canonical ISO-8601 UTC
  (`YYYY-MM-DDTHH:MM:SSZ`) on write, keeping stored values lexicographically comparable
  as text for the datetime expression index and filter range comparisons.
- `PublishService` now rebuilds the `entry_references` projection from the published version
  snapshot on publish/rollback, so draft edits never affect delete protection or delivery-time
  reference resolution until they are actually published.
- `EntryRepository::saveDraft` debounces `entry.updated`: successful saves that write the same
  field payload no longer emit redundant update events.
- `PublishService` now reserves the next immutable version number under a transaction-scoped
  advisory lock per entry+locale before appending the version row.
- `FieldValidator` validates `asset` fields against active core `blobs` on the configured
  `lemma.media_disk`, instead of accepting any non-empty UUID-shaped string.
- `RequireLemmaPermission` resolves the authenticated principal from the post-auth `user` request
  attribute set by `AuthMiddleware` (falling back from the optional `auth.user` enricher), so every
  `lemma_permission`-gated admin route authorizes correctly in a lean install — still fail-closed
  (no principal or missing grant → 403).
- Content types now carry an optional `cache_ttl` override, and delivery responses use it for
  `Cache-Control` max-age before falling back to `lemma.delivery.cache_ttl`.
- Single-entry delivery now uses `glueful/i18n` locale fallback chains when available: `show`
  resolves route slugs and entry UUIDs through the requested locale's fallback chain while
  preserving the actual served locale in the response payload.
- `EntryRepository::softDelete` now emits `AssetDetached` for the deleted entry's current asset
  references before emitting `EntryDeleted`, keeping asset usage webhooks consistent with draft-save
  asset deltas.
- `ReindexSearchListener` now calls Lemma's provider-neutral `ContentReindexerInterface` seam, so
  any search extension can bind the reindexer and own its own queueing/document shape without Lemma
  referencing a vendor-specific job class.
- PHPUnit now pins `DB_DRIVER=pgsql`, and the repository ships a GitHub Actions CI workflow that
  runs the Composer CI gate against Postgres.
