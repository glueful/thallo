# Orders: Invoices & Receipts, Filtered List, Payment Summary, Detail Hierarchy — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Printable A4 invoices + 58/80mm thermal receipts with settings-driven branding, an app-owned filtered orders list + bounded CSV export, an order payment-summary surface, and the operator-speed detail-page hierarchy — after a small gated `glueful/commerce` v1.9.1 release.

**Architecture:** Phase 0 lands two contract extensions upstream (shared `commerce_order` payable constant; `currency_exponent` in `InvoiceData`) and stops at a publication gate until Thallo resolves v1.9.1. Thallo then builds: a pack-owned search read model (one tenant-scoped builder shared by list/count/CSV, rows only via `OrderProjection::forAdmin`), a pack payment-summary read model over Payvia tables (order-first, closed projection, table-readability availability), pack settings keys for invoice branding, and the SPA work (print views, list filters + export, detail rework, settings tab), closed by a real-browser print-media gate.

**Tech Stack:** PHP 8.4 / Glueful framework (packages/thallo-commerce pack; upstream repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`), Vue 3 + pinia-colada + @nuxt/ui (admin/), vitest, and the existing `tools/runtime-browser` Playwright/Chromium harness.

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
- Modify: `src/Orders/CheckoutService.php` (~line 901), `src/Payments/OrderPaymentConfirmationHandler.php` (~line 26), `src/Mail/OrderNotifiable.php` (~line 39), `src/Orders/Refunds/RefundService.php` (~line 276), `src/Marketplace/ChargebackService.php` (~line 80, redefine `SUPPORTED_PAYABLE_TYPE = OrderPayable::TYPE`), `src/Invoices/InvoiceData.php`
- Test: that repo's invoice-data test + a new constant-consistency test

**Interfaces:**
- Produces: `final class OrderPayable { public const TYPE = 'commerce_order'; }` (namespace `Glueful\Extensions\Commerce\Payments`); every production literal `'commerce_order'` site consumes the constant. `InvoiceData::build()` output gains `order.currency_exponent` (int), derived from the order's own `currency` through the existing `Glueful\Extensions\Commerce\Support\Money::exponentFor()` authority — never a second ISO map and never tenant settings. An unknown currency is an invariant violation and throws; it must not silently fabricate exponent 2. `schema_version` bumps from 1 to 2.

- [ ] **Step 1: Failing tests (upstream repo):** invoice data for a seeded order carries `order.currency_exponent === 2` for GHS/USD and `0` for JPY; an invalid stored currency fails loudly rather than defaulting; existing top-level keys unchanged and `schema_version === 2`; a behavioral test asserts `OrderPayable::TYPE === 'commerce_order'` and that `CheckoutService` produces a `PayableReference` whose `type === OrderPayable::TYPE`. A source inventory pins the five production consumers (`CheckoutService`, `OrderPaymentConfirmationHandler`, `OrderNotifiable`, `RefundService`, `ChargebackService`) without requiring fixture literals to disappear.
- [ ] **Step 2:** RED. **Step 3:** Implement (constant + five production call sites + `Money::exponentFor()`). **Step 4:** GREEN + that repo's full suite. **Step 5:** Commit `feat(payments,invoices): shared order payable-type constant + invoice currency exponent`.

### Task 2 (UPSTREAM + GATE): Release v1.9.1, repin Thallo

**Files:**
- Upstream: `CHANGELOG.md` per that repo's release convention (see the 1.9.0 release commit `d59d607` for shape)
- Thallo: `composer.json` and `packages/thallo-commerce/composer.json` (`"glueful/commerce": "^1.9.1"` in both), `composer.lock`

**Interfaces:**
- Produces: published tag `v1.9.1` on github.com/glueful/commerce; Thallo `vendor/glueful/commerce` at 1.9.1 so Tasks 3–11 can consume `OrderPayable::TYPE` and `order.currency_exponent`.

- [ ] **Step 1:** Upstream: merge/land Task 1 on the release branch per that repo's convention, update changelog, commit `Release 1.9.1 — Order payable constant & invoice currency exponent`, tag `v1.9.1`, push branch + tag. **Confirm with the human before pushing** if the repo shows an unfamiliar release process.
- [ ] **Step 2 (PUBLICATION GATE):** In Thallo: set `"glueful/commerce": "^1.9.1"` in both the root and `packages/thallo-commerce/composer.json` (the pack directly references the new class and must be installable standalone), then run `composer update glueful/commerce`. If it cannot resolve published v1.9.1 (packagist/github delay), STOP — report BLOCKED at the gate; do not proceed with any path-repo or local workaround.
- [ ] **Step 3:** Full Thallo gates (composer test / phpcs / boundaries — proves 1.9.1 breaks nothing). **Step 4:** Commit `chore(commerce): repin glueful/commerce to ^1.9.1 for payable constant and invoice currency exponent` (staging root composer.json, pack composer.json, and composer.lock explicitly).

### Task 3: Orders search read model + list endpoint

**Files:**
- Create: `packages/thallo-commerce/src/Orders/AdminOrderSearchQuery.php`, `packages/thallo-commerce/src/Orders/AdminOrderSearchFilter.php`, `packages/thallo-commerce/src/Http/AdminOrderSearchController.php`
- Modify: `packages/thallo-commerce/routes/admin-routes.php` (route inside the existing group), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (DI entries)
- Test: `tests/Integration/Commerce/AdminOrderSearchTest.php` (new)

**Interfaces:**
- Produces: `AdminOrderSearchQuery { __construct(ApplicationContext $context) ; builder(string $tenantUuid): QueryBuilder ; applyOrder(QueryBuilder $query): QueryBuilder }` — the ONLY constructor of the tenant-predicated `commerce_orders` builder (`->table('commerce_orders')->where('tenant_uuid', $tenant)`) and the ONLY owner of report-time ordering (`COALESCE(placed_at, created_at) DESC, id DESC`); used by list, count, and export. `AdminOrderSearchFilter extends Glueful\Api\Filtering\QueryFilter`, but overrides `apply(QueryBuilder $query)` completely: the framework base parses only `filter[...]`, `search`, and `sort`, whereas this route's closed public contract is the direct parameters `status`, `fulfillment_status`, `placed_from`, `placed_to`, and `q`. The override consumes only those names, applies predicates only, and never calls `parent::apply()` (so arbitrary framework filter/sort vocabulary cannot leak into the endpoint). It validates `status` (enum `pending_payment|paid|fulfilled|canceled|refunded`), `fulfillment_status` (`unfulfilled|partial|fulfilled`), dates by strict UTC `!Y-m-d` parse **and round-trip**, and trimmed `q` capped at 200 characters; invalid values ⇒ `ValidationException` (422). Date bounds are independently optional; when both exist, `placed_from > placed_to` is 422. Present bounds form the half-open UTC interval (`from >= 00:00`, `to < next-day 00:00`). Date predicate is the two-branch indexable form — grouped OR, never `WHERE COALESCE`:

  ```php
  $qb->where(function ($w) use ($from, $toExclusive) {
      $w->where(function ($a) use ($from, $toExclusive) {
          $a->whereNotNull('placed_at')->where('placed_at', '>=', $from)->where('placed_at', '<', $toExclusive);
      })->orWhere(function ($b) use ($from, $toExclusive) {
          $b->whereNull('placed_at')->where('created_at', '>=', $from)->where('created_at', '<', $toExclusive);
      });
  });
  ```

  `q` uses one explicit portable escape contract because framework `whereLike()` emits no `ESCAPE` clause: choose `!`, replace `! → !!`, `% → !%`, `_ → !_`, and apply grouped raw predicates `order_number LIKE ? ESCAPE '!'` / `LOWER(email) LIKE ? ESCAPE '!'` with the escaped prefix plus `%`. The controller calls `applyOrder()` only after filtering, paginates (`page >= 1`, `per_page` 1–100, default 24), and maps every row through `\Glueful\Extensions\Commerce\Http\Admin\OrderProjection::forAdmin` into `Response::paginated`. Route: `GET /v1/admin/commerce/orders/search`, name `thallo.commerce.admin.orders.search`, view authority. Both classes + the route carry the TEMPORARY-OWNERSHIP docblock (retire at upstream filter parity).

- [ ] **Step 1: Failing tests:** seed orders across two tenants and both date shapes (placed_at set / null): tenant isolation; status + fulfillment enum filters (and 422 on invalid values); shape-valid impossible date (`2026-02-31`) and malformed date both 422; date half-open boundary — order stamped exactly `placed_to` 00:00 UTC excluded, `placed_from` 00:00 included; placed_at-null row honored via created_at branch; `q` prefix-matches order number and email (case-normalized), literal `!`/`%`/`_` match only literally on SQLite and PostgreSQL; >200-character `q` 422s; direct `sort`, `search`, and `filter[...]` inputs do not alter the closed query; sort = report-time DESC with id tie-break (two rows same report time); response rows have exactly the `OrderProjection::FIELDS` keys (no raw extras); authority matrix (manage 200, view-only 200, no-permission 403, anonymous 401) via `seedApiKeyUser()`-style actors.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(commerce): app-owned filtered orders search endpoint`.

### Task 4: CSV export

**Files:**
- Create: `packages/thallo-commerce/src/Http/AdminOrderExportController.php`, `packages/thallo-commerce/src/Orders/OrderCsvWriter.php`
- Modify: `packages/thallo-commerce/routes/admin-routes.php`, `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php`
- Test: `tests/Integration/Commerce/AdminOrderExportTest.php` (new)

**Interfaces:**
- Consumes: Task 3's `AdminOrderSearchQuery::builder()` + `AdminOrderSearchFilter` (the SAME classes — the controller composes them identically; no second query path).
- Produces: `GET /v1/admin/commerce/orders/export`, name `thallo.commerce.admin.orders.export`, view authority. Flow: apply predicates to a fresh builder → unsorted `COUNT(*)` → if > 10000 ⇒ 422 `"Export exceeds 10,000 rows — narrow your filters."` **before constructing a response or headers** → else return Symfony `StreamedResponse`, set disposition with `$response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'orders-export.csv')`, and write with `fputcsv($stream, $row, ',', '"', '')` (explicit empty escape argument for PHP 8.4). Iterate keyset batches of 500 using `AdminOrderSearchQuery::applyOrder()`. The cursor predicate repeats the real expression because the select alias is unavailable in `WHERE`: `(COALESCE(placed_at, created_at) < ?) OR (COALESCE(placed_at, created_at) = ? AND id < ?)`, using the last row's report-time + id. Every batch row passes through `OrderProjection::forAdmin()` before `OrderCsvWriter::row(array $projected)`, which returns the allowlisted columns exactly: `order_number, status, fulfillment_status, email, currency, subtotal, discount_total, shipping_total, tax_total, refunded_total, grand_total, discount_code, shipping_method, placed_at` — minor units, no locale formatting. Neutralization is applied after scalar serialization, before CSV escaping:

  ```php
  private static function neutralize(string $value): string
  {
      return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
  }
  ```

- [ ] **Step 1: Failing tests:** filters bind identically to list and export (apply a status filter; seed rows it excludes and prove absence in CSV); list and export produce the same report-time/id order; 10,001 seeded matching rows ⇒ 422 with no `Content-Type`/`Content-Disposition` CSV headers and no body bytes; <= cap ⇒ header row + exactly the allowlisted columns in order; each neutralization trigger (`=SUM(A1)`, `+x`, `-x`, `@x`, tab-lead, CR-lead as discount_code/email values) arrives prefixed with `'`; batch coverage — seed 1,201 matching rows, including equal report timestamps at a batch boundary, and assert 1,201 data lines with no duplicate/gap; minor-unit money values verbatim; filename disposition is attachment-safe; authority matrix.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(commerce): bounded streamed CSV export for orders`.

### Task 5: Payment summary endpoint

**Files:**
- Create: `packages/thallo-commerce/src/Payments/OrderPaymentSummaryRepository.php`, `packages/thallo-commerce/src/Http/AdminOrderPaymentsController.php`
- Modify: `packages/thallo-commerce/routes/admin-routes.php`, `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php`
- Test: `tests/Integration/Commerce/AdminOrderPaymentsTest.php` (new)

**Interfaces:**
- Consumes: `\Glueful\Extensions\Commerce\Payments\OrderPayable::TYPE` (Task 1/2); `\Glueful\Extensions\Commerce\Orders\OrderRepository::findByUuid(ApplicationContext $context, string $tenantUuid, string $uuid)` — the exact tenant-scoped lookup used by the mounted admin show route.
- Produces: `OrderPaymentSummaryRepository { __construct(Glueful\Database\Connection $connection) ; available(): bool ; paymentsFor(string $tenant, string $orderUuid): array ; intentsFor(string $tenant, string $orderUuid): array }` — owns `hasTable('payments')`/`hasTable('payment_intents')` readiness, predicates (`tenant_uuid` + `payable_type = OrderPayable::TYPE` + `payable_id = $orderUuid`), ordering `created_at DESC, id DESC`, and the closed projections: payments `{gateway, status, reference, gateway_transaction_id, amount, currency, created_at, updated_at}`, intents `{gateway, status, reference, amount, currency, created_at}` (intents statuses are exactly `open|closed`; return all). Controller: `GET /v1/admin/commerce/orders/{uuid}/payments`, name `thallo.commerce.admin.orders.payments`, view authority. Order-first: engine lookup by tenant+uuid; absent ⇒ non-revealing 404 with zero Payvia queries. Invariant 200 envelope always: `{available: bool, payments: [], intents: [], refund: {refunded_total: int, refund_revision: int}}` (refund echoed from the validated order row). Tables absent ⇒ `available:false` with empty arrays; tables present ⇒ `available:true` regardless of provider enablement; any other query failure propagates (500).

- [ ] **Step 1: Failing tests:** cross-tenant order uuid ⇒ 404 AND zero Payvia queries (run against a fresh application without the Payvia provider and assert the order 404 wins); seeded payment rows (incl. hostile `raw_payload`/`metadata`/`message` carrying fake secrets) ⇒ response contains closed fields only and hostile strings are absent from raw JSON; intents open+closed both returned, ordering deterministic (`created_at DESC, id DESC` with equal timestamps); a genuinely fresh boot with the Payvia provider disabled but physical tables retained ⇒ `available:true` with historical rows (provider enablement is not a capability/config toggle); tables absent in an isolated schema/application ⇒ `available:false` envelope intact; inject the repository's `Connection` dependency with a query-failure double and assert the unexpected failure propagates as 500 — no invalid-table-name subclass or catch-all; refund block echoes order aggregates; envelope keys present on every 200; authority matrix. When both payments and intents exist, both arrays remain populated for the UI to render; neither hides the other.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(commerce): order payment summary endpoint with closed projection`.

### Task 6: Invoice settings keys (pack)

**Files:**
- Create: `packages/thallo-commerce/src/Settings/InvoiceLogoResolver.php`
- Modify: `packages/thallo-commerce/src/Settings/SettingsStoreCommerceOverride.php` (EDITABLE_KEYS/defaults), `packages/thallo-commerce/src/Http/CommerceSettingsController.php` (validation + derived response URL), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (DI)
- Test: extend `tests/Integration/Commerce/` settings coverage with `tests/Integration/Commerce/InvoiceSettingsTest.php` (new)

**Interfaces:**
- Produces: **six** editable keys — `commerce.invoice.logo_blob_uuid`, `commerce.invoice.footer_text`, `commerce.invoice.show_sku`, `commerce.invoice.show_addresses`, `commerce.invoice.show_tax_id`, `commerce.invoice.paper_preset` — accepted by `GET/PUT /v1/admin/commerce/settings` (existing endpoint; SPA key list updated in Task 10). Defaults are explicit: logo/footer empty, all three optional sections `true`, paper `a4`. Extend all three existing settings authorities together: editable-key list, `effective()` normalization, and `configDefault()`/return types. Validation: `paper_preset` closed enum; branch boolean keys **before** the current generic string/int guard, accept the controller's established boolean inputs, store canonical `'1'|'0'`, return real booleans; `footer_text` plain text max 500 and REFUSED on `<`, never stripped.

  `InvoiceLogoResolver` is the one ownership + servability authority for logo saves and reads. It queries `blobs` for the uuid and enforces public, active, not deleted, and `image/*`; then calls the injected framework `BlobAccessPolicy` with `new BlobAccessContext(BlobAction::VIEW, null, false)` so the app's `TenantBlobPolicy` enforces tenant ownership when tenancy is enabled while single-store mode remains valid; finally it requires the injected `Thallo\Contracts\Delivery\MediaUrlResolver` to return a URL. It never imports app classes and never directly requires a `media_assets` row, because those rows are not created when tenancy is off. `show()` adds derived, non-editable `invoice_logo_url`; an invalid/deleted/unresolvable stored uuid yields `null` without mutating the stored setting. The SPA and print view consume only this URL and never synthesize one from the uuid.

- [ ] **Step 1: Failing tests:** defaults on an unset install; round-trip each key through PUT+GET (booleans come back as booleans and persist as canonical strings; preset enum enforced); footer with `<b>` and footer at 501 chars ⇒ 422 field errors, not stored; tenancy-on cross-tenant logo ⇒ 422; non-image/private/deleted/inactive/unresolvable blob ⇒ 422; valid tenant-owned public image ⇒ accepted and returns `invoice_logo_url`; tenancy-off valid public image with no `media_assets` row ⇒ accepted; deleting the accepted blob makes the next GET return `invoice_logo_url:null` while preserving `logo_blob_uuid`; unknown `commerce.invoice.*` key still refused.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(commerce): invoice & receipt branding settings keys`.

### Task 7: SPA orders list — filters, URL contract, CSV

**Files:**
- Create: `admin/src/queries/commerceOrderSearch.ts`
- Modify: `admin/src/pages/commerce/orders/index.vue`, `admin/src/pages/commerce/orders/components/OrdersTable.vue` (fulfillment column)
- Test: `admin/src/__tests__/commerceOrderSearch.spec.ts` (new; update existing orders list specs where they pin the old query)

**Interfaces:**
- Consumes: Task 3 `/orders/search` (paginated `OrderProjection` rows), Task 4 `/orders/export`.
- Produces: `commerceOrderSearch.ts` exporting `ORDER_SEARCH_DEFAULTS`, `useOrderSearch(filters: Ref<OrderSearchFilters>)` (pinia-colada `useQuery`, key `['commerce','orders','search', status, fulfillment, placedFrom, placedTo, q, page, perPage]` — normalized scalars, never object identity), and `downloadOrdersCsv(filters): Promise<void>` using the `formSubmissions.ts:79`-style auth-gated fetch → check `response.status === 422` FIRST (parse JSON error, throw a typed `ExportTooLargeError` with the server message) → else Blob → object URL → anchor download `orders-export.csv`. Types: `OrderSearchFilters { q: string; status: CommerceOrderStatus | null; fulfillment: CommerceFulfillmentStatus | null; placedFrom: string | null; placedTo: string | null; page: number; perPage: number }` (statuses from `commerceOrders.ts` constants).
- Page behavior (URL contract, spec §2.4): perform one guarded initial hydration **before** installing URL-sync/filter watchers. Accept only enum members, strict round-trip-valid `YYYY-MM-DD`, `page >= 1`, and `per_page` 1–100; discard everything else. Initial hydration preserves its page. After hydration, debounce `q` 300ms; other filters apply immediately; a user change to a filter other than page resets page to 1, while page navigation does not reset itself. A canonical serializer omits null/default values, and an equality guard prevents `router.replace` loops. Date presets Today/7d/30d/custom emit plain dates (two `UInputDate` inputs; no dependency). Export is gated by `useCommerceMeta().can_view`, not composable presence; 422 surfaces through `useNotify().warning(...)`. Order-number link remains the only row navigation.

- [ ] **Step 1: Failing vitest specs:** hydration matrix (invalid enums, impossible dates, page 0, per_page 101 discarded; valid values adopted); a hydrated non-default page survives watcher installation; debounce applies to `q` only; user filter mutation resets page, page navigation does not; canonical URL output omits defaults and does not loop on equivalent queries; query key reflects normalized scalars; export button follows `can_view` true/false; `downloadOrdersCsv` creates/revokes an object URL on success, and on 422 parses JSON before blob reading and throws `ExportTooLargeError`; page handler toasts; fulfillment column renders; order-number link remains the sole navigator.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN (`npx vitest run`, `type-check`, `build`) + the PHP gates untouched-but-run per Global Constraints. **Step 5:** Commit `feat(admin): orders list search, filters, URL state, CSV export`.

### Task 8: SPA print views (A4 + thermal) + settings consumption

**Files:**
- Create: `admin/src/pages/commerce/orders/[uuid]/invoice.vue`, `admin/src/pages/commerce/orders/components/InvoiceDocument.vue`, `admin/src/queries/commerceInvoice.ts`, `admin/src/assets/print.css`
- Modify: `admin/src/queries/commerceOrders.ts` (move its existing invoice-data types/fetch/query into `commerceInvoice.ts` and re-export only if a compatibility import is needed), the existing formatted invoice modal consumer (update its import), `admin/src/queries/commerceSettings.ts` (six editable keys + derived `invoice_logo_url` + typed `useInvoiceSettings()` selector), `admin/src/layouts/default.vue` (stable print hooks), `admin/src/main.ts` (import `print.css`)
- Test: `admin/src/__tests__/commerceInvoicePrint.spec.ts` (new)

**Interfaces:**
- Consumes: engine endpoint `GET /v1/admin/commerce/orders/{uuid}/invoice-data` (name `thallo.commerce.admin.orders.invoice_data`) — payload shape per spec/explore: `{schema_version, seller{name,address,tax_id}, buyer{email,addresses}, order{number, dates{placed_at,created_at,updated_at}, currency, currency_exponent, status}, lines[], totals{subtotal_minor,discount_minor,shipping_minor,tax_minor,grand_minor,refunded_minor}, refunds[]}` (`currency_exponent` from Task 1/2). Task 6 settings via `useStoreSettings()`.
- Produces: route `/commerce/orders/:uuid/invoice` with the `<route lang="yaml">` block (`requiresAuth: true`, `requiresCapability: thallo.commerce`). Page renders one `InvoiceDocument` with a `preset` prop (`a4|thermal_80|thermal_58`): on-screen toolbar marked `data-print-chrome` = segmented preset control (initialized from setting, never persisted) + "Print / Save as PDF" calling `window.print()`. `admin/src/layouts/default.vue` marks the sidebar `data-print-chrome` and marks only the RouterView content wrapper `data-print-shell`; it must **not** put `data-print-chrome` on an ancestor containing RouterView. Print CSS removes the content wrapper's margins/ring/radius/overflow under print. Document content: logo from server-derived `invoice_logo_url` only; seller/buyer, order number + dates, **"Order status"**, lines (optional SKU, sanitized addons, unit price, qty, total), totals/refunds, optional addresses/tax id, escaped footer. Money uses the payload exponent. The document selects a named page: A4 maps to `page: invoice-a4` / `@page invoice-a4 { size: A4; ... }`; both thermal presets map to `page: invoice-thermal` / `@page invoice-thermal { size: auto; margin: 0 }` and constrain content to 80mm/58mm (never invalid `80mm auto`/`58mm auto`). Thermal is single-column/monochrome with dashed rules; `thead { display: table-header-group }`, rows avoid breaks and have no clipping/line clamp. Failed fetch ⇒ retry state, never an empty printable.

- [ ] **Step 1: Failing vitest specs:** invoice query/types have one implementation (existing formatted modal and new page both consume it); all three presets render; untoggleable core remains under every toggle combination; SKU/addresses/tax-id/logo/footer respond; logo uses `invoice_logo_url` and a uuid alone never creates an image URL; hostile footer renders as text; segmented control changes preset without a save; `window.print` fires; fetch failure shows retry/no document; JPY exponent renders no decimals; layout-hook test proves sidebar/toolbar are chrome while RouterView's ancestor is only the printable shell.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN (vitest, type-check, build) + PHP gates. **Step 5:** Commit `feat(admin): printable invoice and thermal receipt views`.

### Task 9: SPA detail hierarchy rework

**Files:**
- Modify: `admin/src/pages/commerce/orders/[uuid]/index.vue`, `admin/src/pages/commerce/orders/components/OrderActions.vue`
- Create: `admin/src/pages/commerce/orders/components/OrderCancelDialog.vue`, `admin/src/pages/commerce/orders/components/OrderPaymentCard.vue`, `admin/src/pages/commerce/orders/components/OrderStickyRail.vue`, `admin/src/components/CopyButton.vue`
- Modify: `admin/src/queries/commerceOrders.ts` (add `useOrderPayments(uuid)` against Task 5's endpoint)
- Test: `admin/src/__tests__/commerceOrderDetail.spec.ts` (extend existing detail specs; add payment-card specs)

**Interfaces:**
- Consumes: Task 5 `GET /orders/{uuid}/payments` (invariant envelope), Task 8's `/invoice` route.
- Produces (spec §2.5 verbatim): header band — order number + copy, status + fulfillment badges, placed date, customer email + copy, grand total; primary print link; lifecycle actions grouped beside it; overflow holds destructive cancel + existing "Invoice data" modal. Refactor `OrderActions` so mark-paid/fulfill/refund remain in the canonical header group and cancellation is owned by one `OrderCancelDialog` controlled by the parent overflow; do not instantiate duplicate stateful action components. `OrderPaymentCard` classifications: unavailable; empty; records; attempts-only. If payments and intents both exist it renders **both sections**. It shows closed fields and order-level refund; query error ⇒ card error + toast. Shared `CopyButton` covers order/email/reference/addresses; one `formatAddress()` powers displayed and copied text. Addresses responsive; timeline/notes below commercial blocks. `OrderStickyRail` (>= `xl`, sticky): identity, print link, section links, and an "Actions" anchor to the canonical header controls — no second `OrderActions`, modal, or mutation state.

- [ ] **Step 1: Failing vitest specs:** header/copy behavior; four payment classifications plus a fifth envelope with both arrays proving both sections render; refund label; cancel + invoice data in overflow and exactly one cancel dialog/mutation owner; print target/rel; displayed address equals copied text; timeline/notes DOM order; sticky rail identity + actions anchor and no line/payment/address markup, `OrderActions`, or dialog duplicate.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN (vitest, type-check, build) + PHP gates. **Step 5:** Commit `feat(admin): order detail hierarchy — header, payment summary, copy controls, sticky rail`.

### Task 10: SPA Invoices & receipts settings tab

**Files:**
- Create: `admin/src/pages/commerce/settings/components/InvoicesPanel.vue`
- Modify: `admin/src/pages/commerce/settings/index.vue` (add tab + handle `edit-store`), `admin/src/queries/commerceSettings.ts`, `admin/src/fields/components/AssetField.vue`, `admin/src/fields/components/MediaPickerModal.vue` (make their existing `mediaType: image` contract reject non-image uploads as well as filter the library)
- Test: extend `admin/src/__tests__/commerceSettings.spec.ts`

**Interfaces:**
- Consumes: Task 6 keys via the existing settings pair and `AssetField` configured for `mediaType: 'image'`. Tighten `AssetField`/`MediaPickerModal` so direct drops and the modal Upload tab validate `File.type` with the same media-type contract before upload; the Library tab already filters images. Backend validation remains authoritative.
- Produces: panel sections — read-only seller identity with an `edit-store` event; the settings page owns tab state and handles that event by selecting Store. Image-only logo picker stores the uuid and previews the server-derived `invoice_logo_url`; footer textarea; three switches; paper preset; existing save mutation; field-level 422s.

- [ ] **Step 1: Failing vitest specs:** tab label; seller fields read-only; `edit-store` switches the parent tab; toggles/preset/footer payload; footer 422 field; logo picker accepts images, rejects non-images client-side, stores uuid, and previews only `invoice_logo_url`; saving disabled without `can_manage`.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN (vitest, type-check, build) + PHP gates. **Step 5:** Commit `feat(admin): invoices & receipts settings panel`.

### Task 11: Real-browser print gate + docs

**Files:**
- Create: `tools/runtime-browser/fixtures/invoice-print.html`, `tools/runtime-browser/tests/print-media.spec.js`
- Modify: `tools/runtime-browser/README.md`, `.github/workflows/runtime-browser.yml` (trigger on the invoice component/print CSS/layout paths as well as harness paths), `docs/internal/OUTSTANDING.md` (follow-ups: upstream product-identity snapshot; branding snapshots; template editor; retire app list endpoint; cycle 2 admin order creation)
- Test: the print gate itself

- [ ] **Step 1:** Extend the existing Chromium harness, with no new npm dependency/config. The static fixture uses the exact production `data-print-*` contract and loads the real `admin/src/assets/print.css` from the repository server. Vitest owns Vue/query correctness; this fixture owns browser interpretation of print CSS. Add the relevant admin paths to the existing workflow trigger so print changes cannot skip the gate.
- [ ] **Step 2: Gate all three presets via `page.emulateMedia({media: 'print'})`:** chrome hidden; printable shell/document visible; untoggleable core visible; computed `thead` is `table-header-group`; rows have visible overflow/no line clamp/no max-height and each row's bounding box contains its long descendant (rather than the invalid `scrollHeight <= clientHeight` proxy). Assert A4 document width and 80mm/58mm content widths within a small pixel tolerance; thermal computed page rule is not asserted because browsers expose paper selection to the print dialog.
- [ ] **Step 3:** Docs edits. **Step 4:** FULL gates (PHP + phpcs + boundaries + admin vitest/type-check/build + `cd tools/runtime-browser && npm test`). **Step 5:** Commit `feat(admin): real-browser print-media gate and orders cycle docs`.

---

## Self-Review

- **Spec coverage:** §1.1/§2.3 → Task 8; §1.2/§1.3 settings + core → Tasks 6/8/10; §1.4 → Task 11 docs; §1.5/§2.1 → Tasks 3/4; §1.7/§2.2/§1.8 → Task 5; §1.9 → Task 4; §1.10 → Task 3; §1.11 → Tasks 8/9; §1.12 → Task 8; §1.13 → Tasks 8/9 (omission) + Task 11 docs (follow-up); §1.14 → every route task; §2.4 → Task 7; §2.5 → Tasks 9/10; §2.6 → Tasks 1/2 (+gate); §3 PHP rows → Tasks 3/4/5/6; §3 vitest rows → Tasks 7/8/9/10; §3 print gate → Task 11; §4 follow-ups → Task 11 docs. No gaps.
- **Placeholder scan:** no implementation-time choices remain. Task 5 names `OrderRepository::findByUuid`; Task 11 extends the existing static Chromium harness. Everything names exact keys, routes, enums, columns, thresholds, and assertions.
- **Type consistency:** `AdminOrderSearchQuery::builder(string): QueryBuilder` consumed identically in Tasks 3/4; payment envelope `{available, payments, intents, refund{refunded_total, refund_revision}}` identical in Tasks 5/9; settings keys `commerce.invoice.{logo_blob_uuid,footer_text,show_sku,show_addresses,show_tax_id,paper_preset}` identical in Tasks 6/8/10; preset enum `a4|thermal_80|thermal_58` identical in Tasks 6/8/11; `OrderPayable::TYPE` identical in Tasks 1/2/5; `currency_exponent` produced Task 1, consumed Task 8.
- **Sequencing:** 1 → 2(GATE) → 3 → 4 (consumes 3) → 5 (consumes 2) → 6 → 7 (consumes 3+4) → 8 (consumes 2+6) → 9 (consumes 5+8) → 10 (consumes 6) → 11 (consumes 8). Tasks 3–6 are backend-parallel in principle but execute sequentially per SDD.
