# Orders: Invoices & Receipts, Filtered List, Payment Summary, Detail Hierarchy — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Printable A4 invoices + 58/80mm thermal receipts with settings-driven branding, an app-owned filtered orders list + bounded CSV export, an order payment-summary surface, and the operator-speed detail-page hierarchy — after a small gated `glueful/commerce` v1.9.1 release.

**Architecture:** Phase 0 lands two contract extensions upstream (shared `commerce_order` payable constant; `currency_exponent` in `InvoiceData`) and stops at a publication gate until Thallo resolves v1.9.1. Thallo then builds: a pack-owned search read model (one tenant-scoped builder shared by list/count/CSV, rows only via `OrderProjection::forAdmin`), a pack payment-summary read model over Payvia tables (order-first, closed projection, table-readability availability), pack settings keys for invoice branding, and the SPA work (print views, list filters + export, detail rework, settings tab), closed by a real-browser print-media gate.

**Tech Stack:** PHP 8.4 / Glueful framework (packages/thallo-commerce pack; upstream repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`), Vue 3 + pinia-colada + @nuxt/ui (admin/), vitest, Playwright (new, print gate only).

**Spec:** `docs/internal/superpowers/specs/commerce/2026-08-09-orders-invoices-receipts-design.md` — §1 rulings and §2 contracts govern verbatim; §3's test matrix is distributed into the tasks below.

## Global Constraints

- Thallo repo `/Users/michaeltawiahsowah/Sites/glueful/thallo`, branch `dev`. Upstream repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce` (github.com/glueful/commerce), currently v1.9.0 at d59d607. ONE publication gate after Task 2: if `composer update glueful/commerce` cannot resolve published v1.9.1, STOP and report — no Thallo task may proceed against a path-hack or local artifact.
- Untoggleable receipt core (spec §1.3): order identity/date/status, customer identity, line names, quantities, monetary values, currency, totals, refunds ALWAYS print. Optional only: SKU, addresses, tax ID, logo, footer.
- Order lifecycle state is never presented as gateway payment state: print views label `order.status` as "Order status"; gateway state appears only where the §2.2 endpoint is consumed.
- Raw order rows never cross the list boundary (`OrderProjection::forAdmin` only). Payment projection closed: `gateway, status, reference, gateway_transaction_id, amount, currency, created_at, updated_at` (+ intent projection minus `gateway_transaction_id`/`updated_at`); `raw_payload`/`metadata`/`message`/authorization data never in any response. `available` = table readability, never provider enablement; real DB failures propagate as 500.
- Route/authority pattern (all new pack routes): group `['prefix' => '/v1/admin/commerce', 'middleware' => ['auth','tenant_profile:admin','tenant_bootstrap','admin_tenant_binding']]`; read = `content_permission:commerce.view,commerce.manage`, write = `content_permission:commerce.manage`; names `thallo.commerce.admin.*`. DI: `['class' => X::class, 'shared' => true, 'autowire' => true]` in `CommerceIntegrationServiceProvider::services()`.
- Gates per Thallo task, FOREGROUND, sequential, NEVER via run_in_background or monitors (backgrounded runs die when a subagent's turn ends): `COMPOSER_PROCESS_TIMEOUT=0 composer test` (blocking Bash, timeout 600000, ~9 min; baseline 0 failures/0 errors, 71 skipped), `composer phpcs`, `composer boundaries`. SPA tasks add, from `admin/`: `npx vitest run`, `npm run -s type-check`, `npm run -s build`. Upstream tasks run that repo's own suite (`composer test` there) instead.
- Conventional commits, ONE per task (upstream tasks commit in the upstream repo); NO AI-attribution trailers; stage files explicitly by path; never stage `.claude/`, `.superpowers/`, or unrelated worktree files.
- SPA test mounting follows `admin/src/__tests__/commerceOrders.spec.ts` idioms (plain `mount()` + `setActivePinia(createPinia())`, `vi.mock('vue-router', ...)`); PHP integration tests extend `App\Tests\Support\AppTestCase` and seed actors like `AdminAuthorizationMatrixTest::seedApiKeyUser()`.

---

### Task 1 (UPSTREAM): `OrderPayable::TYPE` constant + `InvoiceData::currency_exponent`

**Repo:** `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce` (work on a branch off the v1.9.0 head; that repo's own conventions govern).

**Files:**
- Create: `src/Payments/OrderPayable.php`
- Modify: `src/Orders/CheckoutService.php` (~line 901), `src/Orders/OrderPaymentConfirmationHandler.php` (~line 26), `src/Mail/OrderNotifiable.php` (~line 39), `src/Orders/Refunds/RefundService.php` (~line 276), `src/Marketplace/ChargebackService.php` (~line 80, redefine `SUPPORTED_PAYABLE_TYPE = OrderPayable::TYPE`), `src/Invoices/InvoiceData.php`
- Test: that repo's invoice-data test + a new constant-consistency test

**Interfaces:**
- Produces: `final class OrderPayable { public const TYPE = 'commerce_order'; }` (namespace `Glueful\Extensions\Commerce\Payments`); every literal `'commerce_order'` site consumes the constant. `InvoiceData::build()` output gains `order.currency_exponent` (int), derived from the order's own `currency` via a static ISO-4217 minor-unit map inside `InvoiceData` (default 2; 0 for JPY/KRW/VND/XAF/XOF etc.; 3 for BHD/KWD/OMR/TND) — never from tenant settings. `schema_version` bumped per that file's existing convention.

- [ ] **Step 1: Failing tests (upstream repo):** invoice data for a seeded order carries `order.currency_exponent === 2` for GHS/USD and `0` for JPY; existing top-level keys unchanged (compatibility assertion on the full key set); a reflection/grep-style test asserting `OrderPayable::TYPE === 'commerce_order'` and that `CheckoutService` produces a `PayableReference` whose `type === OrderPayable::TYPE`.
- [ ] **Step 2:** RED. **Step 3:** Implement (constant + five call sites + exponent map). **Step 4:** GREEN + that repo's full suite. **Step 5:** Commit `feat(payments,invoices): shared order payable-type constant + invoice currency exponent`.

### Task 2 (UPSTREAM + GATE): Release v1.9.1, repin Thallo

**Files:**
- Upstream: `CHANGELOG.md` per that repo's release convention (see the 1.9.0 release commit `d59d607` for shape)
- Thallo: `composer.json` (`"glueful/commerce": "^1.9.1"`), `composer.lock`

**Interfaces:**
- Produces: published tag `v1.9.1` on github.com/glueful/commerce; Thallo `vendor/glueful/commerce` at 1.9.1 so Tasks 3–11 can consume `OrderPayable::TYPE` and `order.currency_exponent`.

- [ ] **Step 1:** Upstream: merge/land Task 1 on the release branch per that repo's convention, update changelog, commit `Release 1.9.1 — Order payable constant & invoice currency exponent`, tag `v1.9.1`, push branch + tag. **Confirm with the human before pushing** if the repo shows an unfamiliar release process.
- [ ] **Step 2 (PUBLICATION GATE):** In Thallo: set `"glueful/commerce": "^1.9.1"`, run `composer update glueful/commerce`. If it cannot resolve published v1.9.1 (packagist/github delay), STOP — report BLOCKED at the gate; do not proceed with any path-repo or local workaround.
- [ ] **Step 3:** Full Thallo gates (composer test / phpcs / boundaries — proves 1.9.1 breaks nothing). **Step 4:** Commit `chore(commerce): repin glueful/commerce to ^1.9.1 for payable constant and invoice currency exponent` (staging composer.json + composer.lock explicitly).

### Task 3: Orders search read model + list endpoint

**Files:**
- Create: `packages/thallo-commerce/src/Orders/AdminOrderSearchQuery.php`, `packages/thallo-commerce/src/Orders/AdminOrderSearchFilter.php`, `packages/thallo-commerce/src/Http/AdminOrderSearchController.php`
- Modify: `packages/thallo-commerce/routes/admin-routes.php` (route inside the existing group), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (DI entries)
- Test: `tests/Integration/Commerce/AdminOrderSearchTest.php` (new)

**Interfaces:**
- Produces: `AdminOrderSearchQuery { __construct(ApplicationContext $context) ; builder(string $tenantUuid): QueryBuilder }` — the ONLY constructor of the tenant-predicated `commerce_orders` builder (`->table('commerce_orders')->where('tenant_uuid', $tenant)`); used by list, count (Task 4), and export (Task 4). `AdminOrderSearchFilter extends Glueful\Api\Filtering\QueryFilter` — `__construct(Request $request)`; validates then applies `status` (enum `pending_payment|paid|fulfilled|canceled|refunded`), `fulfillment_status` (`unfulfilled|partial|fulfilled`), `placed_from`/`placed_to` (`YYYY-MM-DD`, half-open UTC `[from 00:00, to+1day 00:00)`), `q` (normalized prefix on `order_number` OR lowercased `email`); invalid enum/date ⇒ `ValidationException` (422). Date predicate is the two-branch indexable form — grouped OR, never `WHERE COALESCE`:

  ```php
  $qb->where(function ($w) use ($from, $toExclusive) {
      $w->where(function ($a) use ($from, $toExclusive) {
          $a->whereNotNull('placed_at')->where('placed_at', '>=', $from)->where('placed_at', '<', $toExclusive);
      })->orWhere(function ($b) use ($from, $toExclusive) {
          $b->whereNull('placed_at')->where('created_at', '>=', $from)->where('created_at', '<', $toExclusive);
      });
  });
  ```

  `q` escaping before LIKE: `$escaped = addcslashes($q, '\\%_');` then `whereLike(..., $escaped.'%')` (escape char documented). Sort: `ORDER BY COALESCE(placed_at, created_at) DESC, id DESC` via the builder's raw-order capability. Route: `GET /v1/admin/commerce/orders/search`, name `thallo.commerce.admin.orders.search`, view authority. Controller paginates (page ≥ 1, per_page 1–100 default 24) and maps every row through `\Glueful\Extensions\Commerce\Http\Admin\OrderProjection::forAdmin` into `Response::paginated`. Both classes + the route carry the TEMPORARY-OWNERSHIP docblock (retire at upstream filter parity).

- [ ] **Step 1: Failing tests:** seed orders across two tenants and both date shapes (placed_at set / null): tenant isolation; status + fulfillment enum filters (and 422 on invalid values, malformed dates); date half-open boundary — order stamped exactly `placed_to` 00:00 UTC excluded, `placed_from` 00:00 included; placed_at-null row honored via created_at branch; `q` prefix-matches order_number and email (case-normalized), literal `%`/`_` in input match only literally; sort = report-time DESC with id tie-break (two rows same placed_at); response rows have exactly the `OrderProjection::FIELDS` keys (no raw extras); authority matrix (manage 200, view-only 200, no-permission 403, anonymous 401) via `seedApiKeyUser()`-style actors.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(commerce): app-owned filtered orders search endpoint`.

### Task 4: CSV export

**Files:**
- Create: `packages/thallo-commerce/src/Http/AdminOrderExportController.php`, `packages/thallo-commerce/src/Orders/OrderCsvWriter.php`
- Modify: `packages/thallo-commerce/routes/admin-routes.php`, `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php`
- Test: `tests/Integration/Commerce/AdminOrderExportTest.php` (new)

**Interfaces:**
- Consumes: Task 3's `AdminOrderSearchQuery::builder()` + `AdminOrderSearchFilter` (the SAME classes — the controller composes them identically; no second query path).
- Produces: `GET /v1/admin/commerce/orders/export`, name `thallo.commerce.admin.orders.export`, view authority. Flow: apply filter to a fresh builder → unsorted `COUNT(*)` → if > 10000 ⇒ 422 `"Export exceeds 10,000 rows — narrow your filters."` BEFORE any output/headers → else streamed response (`Content-Type: text/csv; charset=UTF-8`, `Content-Disposition: attachment; filename="orders-export.csv"`) iterating keyset batches of 500 ordered by `(COALESCE(placed_at, created_at), id)` descending, keyed on the last row's `(report_time, id)` tuple (`WHERE (report_time, id) < (:t, :id)` composed as the two-comparison form for driver portability). `OrderCsvWriter::row(array $projected): array` returns the allowlisted columns exactly: `order_number, status, fulfillment_status, email, currency, subtotal, discount_total, shipping_total, tax_total, refunded_total, grand_total, discount_code, shipping_method, placed_at` — minor units, no locale formatting. Neutralization applied after scalar serialization, before CSV escaping:

  ```php
  private static function neutralize(string $value): string
  {
      return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
  }
  ```

- [ ] **Step 1: Failing tests:** filters bind identically to list and export (apply a status filter; a mutation-style assertion that export honors the same filter object semantics — seed rows the filter excludes and prove absence in CSV); 10,001 seeded matching rows ⇒ 422 with no CSV bytes; ≤ cap ⇒ header row + exactly the allowlisted columns in order; each neutralization trigger (`=SUM(A1)`, `+x`, `-x`, `@x`, tab-lead, CR-lead as discount_code/email values) arrives prefixed with `'`; batch coverage — seed 1,201 matching rows and assert 1,201 data lines (crosses three batches, no dup/no gap: assert order_number uniqueness and full set equality); minor-unit money values verbatim; authority matrix.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(commerce): bounded streamed CSV export for orders`.

### Task 5: Payment summary endpoint

**Files:**
- Create: `packages/thallo-commerce/src/Payments/OrderPaymentSummaryRepository.php`, `packages/thallo-commerce/src/Http/AdminOrderPaymentsController.php`
- Modify: `packages/thallo-commerce/routes/admin-routes.php`, `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php`
- Test: `tests/Integration/Commerce/AdminOrderPaymentsTest.php` (new)

**Interfaces:**
- Consumes: `\Glueful\Extensions\Commerce\Payments\OrderPayable::TYPE` (Task 1/2); the engine's order repository lookup used by the mounted `show` route (resolve it from the container the way `AdminOrderController` does — find its exact repository call at implementation time and reuse, not reimplement).
- Produces: `OrderPaymentSummaryRepository { __construct(ApplicationContext $context) ; available(): bool ; paymentsFor(string $tenant, string $orderUuid): array ; intentsFor(string $tenant, string $orderUuid): array }` — owns `hasTable('payments')`/`hasTable('payment_intents')` readiness, predicates (`tenant_uuid` + `payable_type = OrderPayable::TYPE` + `payable_id = $orderUuid`), ordering `created_at DESC, id DESC`, and the closed projections: payments `{gateway, status, reference, gateway_transaction_id, amount, currency, created_at, updated_at}`, intents `{gateway, status, reference, amount, currency, created_at}` (intents statuses are exactly `open|closed`; return all). Controller: `GET /v1/admin/commerce/orders/{uuid}/payments`, name `thallo.commerce.admin.orders.payments`, view authority. Order-first: engine lookup by tenant+uuid; absent ⇒ non-revealing 404 with zero Payvia queries. Invariant 200 envelope always: `{available: bool, payments: [], intents: [], refund: {refunded_total: int, refund_revision: int}}` (refund echoed from the validated order row). Tables absent ⇒ `available:false` with empty arrays; tables present ⇒ `available:true` regardless of provider enablement; any other query failure propagates (500).

- [ ] **Step 1: Failing tests:** cross-tenant order uuid ⇒ 404 AND zero Payvia queries (temporarily drop/rename-proof: run with payments tables absent and assert 404 arrives without an availability error — plus a repository spy where feasible); seeded payment rows (incl. hostile `raw_payload`/`metadata`/`message` carrying fake secrets) ⇒ response contains closed fields only, hostile strings asserted absent from the raw JSON; intents open+closed both returned, ordering deterministic (`created_at DESC, id DESC` with equal timestamps); provider-disabled-but-tables-present ⇒ `available:true` with historical rows (toggle the payvia capability/config off in-test); tables absent (point repository at empty temp schema names or drop in an isolated transaction) ⇒ `available:false` envelope intact; forced query failure (invalid table name injected via a test-only subclass) ⇒ 500 propagates; refund block echoes order aggregates; envelope keys present on every 200; authority matrix.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(commerce): order payment summary endpoint with closed projection`.

### Task 6: Invoice settings keys (pack)

**Files:**
- Modify: `packages/thallo-commerce/src/Settings/SettingsStoreCommerceOverride.php` (EDITABLE_KEYS), `packages/thallo-commerce/src/Http/CommerceSettingsController.php` (validation), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (only if new DI needed)
- Test: extend `tests/Integration/Commerce/` settings coverage with `tests/Integration/Commerce/InvoiceSettingsTest.php` (new)

**Interfaces:**
- Produces: four new editable keys — `commerce.invoice.logo_blob_uuid`, `commerce.invoice.footer_text`, `commerce.invoice.show_sku`, `commerce.invoice.show_addresses`, `commerce.invoice.show_tax_id`, `commerce.invoice.paper_preset` — accepted by `GET/PUT /v1/admin/commerce/settings` (existing endpoint; SPA key list updated in Task 10). Validation in `CommerceSettingsController::validate()`: `paper_preset` closed enum `a4|thermal_80|thermal_58`; booleans canonicalized to stored `'1'|'0'` and returned as real booleans in `show()` (follow the controller's existing boolean handling — extend it if none exists); `footer_text` plain text, max 500 chars, REFUSED (`ValidationException::forField`) when containing `<` — never stripped; `logo_blob_uuid` must reference a blob that is active, image-MIME (`image/*` on `blobs.mime_type`), `status` active, public visibility, AND tenant-owned per `media_assets.blob_uuid → tenant_uuid` exactly as `app/Content/Media/TenantBlobPolicy::authorizeAccess()` checks (reuse/duplicate its predicate through a small pack-local helper that queries `blobs` + `media_assets`; do not import app classes into the pack if boundaries forbid it — mirror the check and note the mirror in a comment).

- [ ] **Step 1: Failing tests:** round-trip each key through PUT+GET (booleans come back as booleans; preset enum enforced, invalid ⇒ 422); footer with `<b>` ⇒ 422 field error, not stored, not stripped; footer at 501 chars ⇒ 422; logo uuid of another tenant's blob ⇒ 422; non-image blob ⇒ 422; deleted/inactive blob ⇒ 422; valid tenant-owned public image ⇒ accepted; unknown `commerce.invoice.*` key still refused by the allowlist.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(commerce): invoice & receipt branding settings keys`.

### Task 7: SPA orders list — filters, URL contract, CSV

**Files:**
- Create: `admin/src/queries/commerceOrderSearch.ts`
- Modify: `admin/src/pages/commerce/orders/index.vue`, `admin/src/pages/commerce/orders/components/OrdersTable.vue` (fulfillment column)
- Test: `admin/src/__tests__/commerceOrderSearch.spec.ts` (new; update existing orders list specs where they pin the old query)

**Interfaces:**
- Consumes: Task 3 `/orders/search` (paginated `OrderProjection` rows), Task 4 `/orders/export`.
- Produces: `commerceOrderSearch.ts` exporting `ORDER_SEARCH_DEFAULTS`, `useOrderSearch(filters: Ref<OrderSearchFilters>)` (pinia-colada `useQuery`, key `['commerce','orders','search', status, fulfillment, placedFrom, placedTo, q, page, perPage]` — normalized scalars, never object identity), and `downloadOrdersCsv(filters): Promise<void>` using the `formSubmissions.ts:79`-style auth-gated fetch → check `response.status === 422` FIRST (parse JSON error, throw a typed `ExportTooLargeError` with the server message) → else Blob → object URL → anchor download `orders-export.csv`. Types: `OrderSearchFilters { q: string; status: CommerceOrderStatus | null; fulfillment: CommerceFulfillmentStatus | null; placedFrom: string | null; placedTo: string | null; page: number; perPage: number }` (statuses from `commerceOrders.ts` constants).
- Page behavior (URL contract, spec §2.4): hydrate from `route.query`, accepting ONLY valid enum members, `YYYY-MM-DD`-shaped dates, positive-int page/per-page — malformed values discarded to defaults; `q` debounced 300ms, all other filter changes apply immediately; EVERY filter change resets page to 1; filters written back to the URL via `router.replace`. Date presets Today/7d/30d/custom emit plain `YYYY-MM-DD` (compose two `UInputDate`-based inputs; no new dependency). Export button gated on commerce view access (page already requires `thallo.commerce` capability; use `useCommerceMeta()` presence), 422 path surfaces via `useNotify().warning(...)` with the server's narrowing message. Order-number link remains the only row navigation.

- [ ] **Step 1: Failing vitest specs:** hydration matrix (garbage status/fulfillment/date/page params discarded; valid ones adopted); debounce applies to `q` only (fake timers) and every filter mutation resets page to 1; query key reflects normalized scalars (two filter objects with same values produce one key shape); `downloadOrdersCsv` happy path creates an object-URL anchor download, 422 path parses the JSON error BEFORE blob reading and throws `ExportTooLargeError` (fetch mocked both ways); toast fired on the 422 path from the page handler; fulfillment column renders; order-number link still the sole navigator.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN (`npx vitest run`, `type-check`, `build`) + the PHP gates untouched-but-run per Global Constraints. **Step 5:** Commit `feat(admin): orders list search, filters, URL state, CSV export`.

### Task 8: SPA print views (A4 + thermal) + settings consumption

**Files:**
- Create: `admin/src/pages/commerce/orders/[uuid]/invoice.vue`, `admin/src/queries/commerceInvoice.ts`, `admin/src/assets/print.css` (global print rules + chrome hooks)
- Modify: `admin/src/queries/commerceSettings.ts` (add the six `commerce.invoice.*` keys to `STORE_SETTING_KEYS` + typed accessors `useInvoiceSettings()` selector), the root layout file that renders the dashboard chrome (add the stable `data-print-chrome` hook attribute; locate it at implementation time — the shell that wraps page content), `admin/src/main.ts` or the CSS entry to import `print.css`
- Test: `admin/src/__tests__/commerceInvoicePrint.spec.ts` (new)

**Interfaces:**
- Consumes: engine endpoint `GET /v1/admin/commerce/orders/{uuid}/invoice-data` (name `thallo.commerce.admin.orders.invoice_data`) — payload shape per spec/explore: `{schema_version, seller{name,address,tax_id}, buyer{email,addresses}, order{number, dates{placed_at,created_at,updated_at}, currency, currency_exponent, status}, lines[], totals{subtotal_minor,discount_minor,shipping_minor,tax_minor,grand_minor,refunded_minor}, refunds[]}` (`currency_exponent` from Task 1/2). Task 6 settings via `useStoreSettings()`.
- Produces: route `/commerce/orders/:uuid/invoice` with the `<route lang="yaml">` block (`requiresAuth: true`, `requiresCapability: thallo.commerce` — same as the detail page). Page renders ONE document component `InvoiceDocument` with a `preset` prop (`a4|thermal_80|thermal_58`): on-screen toolbar (hidden in print) = segmented preset control (initialized from `commerce.invoice.paper_preset`, NEVER written back) + "Print / Save as PDF" button calling `window.print()`. Document content: logo (only when `logo_blob_uuid` resolves to a URL — omitted silently otherwise), seller identity, buyer block, order number + dates, literal label **"Order status"**, lines table (name; SKU column only when `show_sku`; sanitized addon name/value/choice rows; unit price; qty; line total), totals stack incl. refunds, addresses when `show_addresses`, tax id when `show_tax_id`, footer text (rendered with Vue's default text interpolation — never `v-html`). Money via `formatMoney(minor, {currency: order.currency, currency_exponent: order.currency_exponent})`. Print CSS (global file): `@media print { [data-print-chrome] { display: none !important } }`, `@page` size per preset (A4; `80mm auto`; `58mm auto`), thermal presets single-column/monochrome/dashed rules/larger relative type, `thead { display: table-header-group }`, `tr { break-inside: avoid }`. Failed invoice fetch ⇒ retry state component, never an empty printable.

- [ ] **Step 1: Failing vitest specs:** renders all three presets (preset prop drives a `data-preset` attribute + layout class); untoggleable core present under EVERY toggle combination (order number, date, "Order status" label, customer email, line name/qty/money, totals, refunds when present); SKU/addresses/tax-id/logo/footer respond to their toggles; footer containing `<script>` renders as text (escaped, no element); segmented control switches preset without any settings mutation (spy on the save mutation — never called); `window.print` spy fired by the button; fetch-failure state shows retry and no document; money uses `currency_exponent` from the payload (JPY fixture renders no decimals).
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN (vitest, type-check, build) + PHP gates. **Step 5:** Commit `feat(admin): printable invoice and thermal receipt views`.

### Task 9: SPA detail hierarchy rework

**Files:**
- Modify: `admin/src/pages/commerce/orders/[uuid]/index.vue`
- Create: `admin/src/pages/commerce/orders/components/OrderPaymentCard.vue`, `admin/src/pages/commerce/orders/components/OrderStickyRail.vue`, `admin/src/components/CopyButton.vue` (if no copy component exists — check first)
- Modify: `admin/src/queries/commerceOrders.ts` (add `useOrderPayments(uuid)` against Task 5's endpoint)
- Test: `admin/src/__tests__/commerceOrderDetail.spec.ts` (extend existing detail specs; add payment-card specs)

**Interfaces:**
- Consumes: Task 5 `GET /orders/{uuid}/payments` (invariant envelope), Task 8's `/invoice` route.
- Produces (spec §2.5 verbatim): header band — order number + copy, status + fulfillment badges, placed date, customer email + copy, grand total; primary action "Print invoice / receipt" as a real link (`target="_blank" rel="noopener"`) to the invoice route; lifecycle actions (mark paid / fulfill / refund) grouped beside it; overflow menu holds destructive cancel + the existing formatted "Invoice data" modal (label unchanged). `OrderPaymentCard` states: `unavailable` (available:false), `no payments or attempts` (both arrays empty), `payment records` (payments non-empty), `payment attempts` (payments empty, intents non-empty); shows gateway, payment status, reference + `gateway_transaction_id` with copy, amount/currency, timestamps, and refunded total labeled "Refunded (order total)"; query error ⇒ error state + `useNotify().error`. Copy controls (one shared `CopyButton` using `navigator.clipboard.writeText`) on order number, email, payment reference, each address — address copy text is the SAME normalized string the template displays (build one `formatAddress(addr): string` helper used by both render and copy; never `JSON.stringify`). Addresses side-by-side ≥ `lg`, stacked below. Timeline + notes rendered below the commercial blocks. `OrderStickyRail` (visible ≥ `xl`, `position: sticky`): order number, status badge, grand total, primary print link + lifecycle action echoes, anchor links to sections — intentionally a compact identity/action summary; it must NOT duplicate the payment card, line table, or address blocks.

- [ ] **Step 1: Failing vitest specs:** header contents + copy buttons write expected strings to a mocked clipboard; the four payment-card states from four mocked envelopes; refunded label reads "Refunded (order total)"; action grouping — cancel and "Invoice data" inside the overflow, print link has `target="_blank"` + `rel="noopener"` and points at the invoice route; address copy equals the displayed `formatAddress` output for a fixture address; timeline/notes appear after the commercial sections in DOM order; sticky rail renders identity summary and contains no line-item/payment/address markup (assert absence of their test-ids).
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN (vitest, type-check, build) + PHP gates. **Step 5:** Commit `feat(admin): order detail hierarchy — header, payment summary, copy controls, sticky rail`.

### Task 10: SPA Invoices & receipts settings tab

**Files:**
- Create: `admin/src/pages/commerce/settings/components/InvoicesPanel.vue`
- Modify: `admin/src/pages/commerce/settings/index.vue` (add the "Invoices & receipts" tab), `admin/src/queries/commerceSettings.ts` (keys added in Task 8's step; extend save payload typing for the invoice keys if not already)
- Test: extend `admin/src/__tests__/commerceSettings.spec.ts`

**Interfaces:**
- Consumes: Task 6 keys via the existing `useStoreSettings()`/`useSaveStoreSettings()` pair; `admin/src/queries/media.ts` `uploadBlob()` + `admin/src/fields/components/AssetField.vue` for the logo.
- Produces: panel sections — read-only seller identity (name/tax id/address values with a link "Edit in Store settings" switching to the Store tab); logo upload (AssetField-based, stores the blob uuid into `commerce.invoice.logo_blob_uuid`); footer textarea (500 max, counter); three optional-section switches; paper preset select (`A4`, `Thermal 80mm`, `Thermal 58mm`); save via the existing settings mutation; 422s render field-level (mirror StorePanel's error rendering).

- [ ] **Step 1: Failing vitest specs:** tab appears labeled "Invoices & receipts"; seller identity fields render read-only with the Store-tab pointer (no inputs for them); toggles/preset/footer round-trip through the save mutation payload; server 422 on footer renders on the field; logo section stores uuid into the payload; saving disabled without manage rights (mirror StorePanel's `can_manage` gating).
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN (vitest, type-check, build) + PHP gates. **Step 5:** Commit `feat(admin): invoices & receipts settings panel`.

### Task 11: Real-browser print gate + docs

**Files:**
- Create: `admin/e2e/print.spec.ts`, `admin/playwright.config.ts`
- Modify: `admin/package.json` (devDependency `@playwright/test`, script `"test:print": "playwright test e2e/print.spec.ts"`), `docs/internal/OUTSTANDING.md` (follow-ups: upstream product-identity snapshot; branding snapshots for historical receipts; template editor as separate project; retire app list endpoint at upstream parity; cycle 2 = admin order creation)
- Test: the print gate itself

- [ ] **Step 1:** Playwright setup: config with one chromium project, `baseURL` from env (`PRINT_GATE_URL` defaulting to the vite preview URL), a doc comment stating the gate needs a running app + seeded order + auth storage-state (document the exact `npx playwright ...` invocation and storage-state bootstrap in the spec file header; if a full app boot is impractical, the spec mounts the built invoice route against a mocked API via `page.route()` interception — choose at implementation time, record the choice in the report).
- [ ] **Step 2: The gate (all three presets via `page.emulateMedia({media: 'print'})`):** `[data-print-chrome]` elements hidden (`toBeHidden`); untoggleable core visible (order number, "Order status", a line name, grand total); `thead` repeats configured (`display: table-header-group` computed style); a seeded long multi-line item row does not clip (`scrollHeight <= clientHeight` on the row or no `overflow: hidden` truncation). Run for `a4`, `thermal_80`, `thermal_58` via the preset control.
- [ ] **Step 3:** Docs edits per Files. **Step 4:** FULL gates (PHP + phpcs + boundaries + vitest + type-check + build + `npm run -s test:print` with the documented bootstrap). **Step 5:** Commit `feat(admin): real-browser print-media gate and orders cycle docs`.

---

## Self-Review

- **Spec coverage:** §1.1/§2.3 → Task 8; §1.2/§1.3 settings + core → Tasks 6/8/10; §1.4 → Task 11 docs; §1.5/§2.1 → Tasks 3/4; §1.7/§2.2/§1.8 → Task 5; §1.9 → Task 4; §1.10 → Task 3; §1.11 → Tasks 8/9; §1.12 → Task 8; §1.13 → Tasks 8/9 (omission) + Task 11 docs (follow-up); §1.14 → every route task; §2.4 → Task 7; §2.5 → Tasks 9/10; §2.6 → Tasks 1/2 (+gate); §3 PHP rows → Tasks 3/4/5/6; §3 vitest rows → Tasks 7/8/9/10; §3 print gate → Task 11; §4 follow-ups → Task 11 docs. No gaps.
- **Placeholder scan:** two deliberate implementation-time decisions are flagged as such with bounded options (engine order-lookup reuse in Task 5; print-gate bootstrap mode in Task 11) — both are recorded-choice points, not TBDs. Everything else names exact keys, routes, enums, columns, thresholds, and assertions.
- **Type consistency:** `AdminOrderSearchQuery::builder(string): QueryBuilder` consumed identically in Tasks 3/4; payment envelope `{available, payments, intents, refund{refunded_total, refund_revision}}` identical in Tasks 5/9; settings keys `commerce.invoice.{logo_blob_uuid,footer_text,show_sku,show_addresses,show_tax_id,paper_preset}` identical in Tasks 6/8/10; preset enum `a4|thermal_80|thermal_58` identical in Tasks 6/8/11; `OrderPayable::TYPE` identical in Tasks 1/2/5; `currency_exponent` produced Task 1, consumed Task 8.
- **Sequencing:** 1 → 2(GATE) → 3 → 4 (consumes 3) → 5 (consumes 2) → 6 → 7 (consumes 3+4) → 8 (consumes 2+6) → 9 (consumes 5+8) → 10 (consumes 6) → 11 (consumes 8). Tasks 3–6 are backend-parallel in principle but execute sequentially per SDD.
