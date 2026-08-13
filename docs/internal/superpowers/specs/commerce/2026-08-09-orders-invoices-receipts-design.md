# Orders Management: Invoices & Receipts, List Filtering, Detail Hierarchy — Design

**Date:** 2026-08-09
**Scope:** App-side cycle 1 of the orders program: printable invoices/receipts with settings-driven customization, an app-owned filtered orders list + CSV export, a payment-summary surface, and the order-detail hierarchy rework. **Admin order creation is cycle 2** (engine-level, separately specced).
**Posture:** ONE named publication dependency: the two §2.6 upstream `glueful/commerce` extensions ship as **v1.9.1** (repo: github.com/glueful/commerce, local checkout `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`); Thallo repins to `^1.9.1` behind an explicit publication gate — Thallo implementation continues only after installation resolves from the published artifact. Beyond that, deliberate schema coupling to Commerce (`commerce_orders`, `commerce_order_lines`) and Payvia (`payments`, `payment_intents`) tables. The vendor `orders.index` endpoint stays mounted untouched; the app list endpoint is **temporary ownership** — retire when equivalent upstream filtering exists.

---

## §1 Rulings

1. **Two print documents, one data source.** An A4 invoice and purpose-built 58/80mm thermal receipt layouts, both fed by the engine's `invoiceData` endpoint. Browser print dialog does paper/PDF; no server-side PDF, no new data access.
2. **Settings-driven customization only.** Merchants customize presentation: logo, business identity (reused from Store settings), footer text, optional sections, paper preset. Order number, dates, customer, currency, line items, totals, refunds, and statuses are authoritative — never hideable or editable. All customization text is plain text, never HTML. Template editing is a separate future project (sandboxing, versioning, preview, recovery).
3. **Untoggleable receipt core:** order identity/date/status, customer identity, line names, quantities, monetary values, currency, totals, refunds always print. Only SKU, addresses, tax ID, logo, and footer are optional.
4. **Historical receipts use current branding settings with immutable order data.** True branding snapshots are a later compliance feature (recorded in OUTSTANDING).
5. **One shared tenant-scoped query builder** powers list, count, and CSV so filters cannot drift — with filter application separated from query ownership (§2.1).
6. **`OrderProjection::forAdmin` is the only row shape** that leaves the list endpoint; raw order rows never cross the boundary.
7. **Payment lookup is order-first:** validate the tenant-owned order (non-revealing 404), then query Payvia by tenant + payable. The payment projection is closed (§2.3); `raw_payload`, authorization data, `message`, and unrestricted metadata never cross the response boundary.
8. **Payvia availability is a table-readability fact, not provider enablement.** Disabling the provider must not hide historical financial records. Tables readable ⇒ `available: true`; tables absent/not migrated ⇒ `available: false`; unexpected DB failure ⇒ 500, never disguised as unavailable.
9. **CSV is bounded, streamed, and neutralized:** same filter class as the list, streamed HTTP response over bounded keyset batches, count-before-headers 10,000-row cap (422 beyond), allowlisted columns, spreadsheet-formula neutralization.
10. **Search and date semantics are explicit and tested** (§2.1). Report-time (`COALESCE(placed_at, created_at)`) is used consistently for both sorting and (via indexable two-branch predicates) filtering.
11. **Order lifecycle state is never presented as gateway payment state.** Print views label `order.status` as "Order status"; gateway state appears only where the payment-summary endpoint is consumed.
12. **Per-print paper override is temporary UI state** on the print page (segmented control before `window.print()`), never a settings write.
13. **Product thumbnails/links are omitted this cycle.** The admin line projection and invoice data deliberately exclude `product_uuid`/`variant_uuid`; no lookup loops. Upstream immutable product-identity snapshot is the recorded follow-up.
14. **Nav/authority:** all new admin surfaces use the same commerce admin authority as the mounted vendor order routes (`commerce.view` read / `commerce.manage` write).

## §2 Component contracts

### 2.1 Orders search read model (pack: `packages/thallo-commerce`)

- **`AdminOrderSearchQuery`** — owns construction of the tenant-predicated `commerce_orders` builder used by list, count, and export. Nothing else builds this query.
- **`AdminOrderSearchFilter`** — extends the framework QueryFilter but fully overrides `apply()`: the framework base understands `filter[...]`/`search`/`sort`, while this endpoint deliberately exposes the direct closed parameters below. It never calls the parent parser and applies predicates only; query ownership supplies ordering. Params:
  - `status`: closed enum (`pending_payment|paid|fulfilled|canceled|refunded`); invalid ⇒ 422.
  - `fulfillment_status`: closed enum (`unfulfilled|partial|fulfilled`); invalid ⇒ 422.
  - `placed_from`/`placed_to`: independently optional, strict round-trip-valid `YYYY-MM-DD`; when both exist, from must not exceed to. Half-open UTC boundaries `[from 00:00, to+1day 00:00)`. Indexable two-branch report-time predicate — never `WHERE COALESCE(...)`:
    - `placed_at IS NOT NULL AND placed_at >= from AND placed_at < toExclusive`, OR
    - `placed_at IS NULL AND created_at >= from AND created_at < toExclusive`.
  - `q`: normalized (trimmed, max 200 characters, lowercased for email leg) prefix match against `order_number` OR `email`. `!` is the explicit portable `LIKE ... ESCAPE '!'` character; `!`, `%`, and `_` are escaped before matching. Documented as normalized prefix match (which includes exact).
- **Sort:** `AdminOrderSearchQuery` owns `COALESCE(placed_at, created_at) DESC, id DESC`; list and keyset export call the same ordering method, while count remains unsorted (sort may use COALESCE; filtering may not).
- **`AdminOrderSearchController`** — `GET /v1/admin/commerce/orders/search`: paginated; every row through `OrderProjection::forAdmin`.
- **CSV export** — `GET /v1/admin/commerce/orders/export`:
  - Same `AdminOrderSearchQuery` + `AdminOrderSearchFilter`.
  - Unsorted filtered COUNT first; > 10,000 ⇒ 422 ("narrow your filters") **before any CSV headers are sent**.
  - Symfony `StreamedResponse` over bounded keyset batches (~500 rows keyed on the sort tuple), written with PHP 8.4's explicit empty CSV escape argument. The cursor predicate repeats `COALESCE(placed_at, created_at)` rather than referring to a select alias in `WHERE`.
  - Allowlisted columns only: `order_number, status, fulfillment_status, email, currency, subtotal, discount_total, shipping_total, tax_total, refunded_total, grand_total, discount_code, shipping_method, placed_at`. Money in minor units; no locale formatting.
  - Formula neutralization: values beginning `=`, `+`, `-`, `@`, tab, or carriage return are prefixed with `'` — applied after scalar serialization, before CSV escaping.
- **Temporary-ownership marker:** routes + both classes carry a docblock: retire in favor of upstream `orders.index` filtering at parity.

### 2.2 Payment summary read model (pack)

- **`OrderPaymentSummaryRepository`** — owns Payvia table access through an injected connection: schema readiness (`hasTable` for `payments`/`payment_intents`), tenant + payable predicates, deterministic ordering (`created_at DESC, id DESC`), normalization, closed projection. Controllers never touch tables; only missing tables become `available:false`, while other query failures escape.
- **`AdminOrderPaymentsController`** — `GET /v1/admin/commerce/orders/{uuid}/payments`:
  1. Resolve order by tenant + uuid via the engine repository; absent ⇒ non-revealing 404, **zero Payvia queries**.
  2. Query `payments` and `payment_intents` by `tenant_uuid` + `payable_type` + `payable_id = order uuid`. Payable type comes from the shared engine constant for `commerce_order` (§2.6) — the literal is never duplicated in Thallo.
  3. Intents have exactly `open|closed` status; all order intents are returned.
- **Closed payment projection:** `gateway, status, reference, gateway_transaction_id, amount, currency, created_at, updated_at`. Intent projection: `gateway, status, reference, amount, currency, created_at`. Excluded always: `raw_payload`, `metadata`, `message`, authorization data.
- **Invariant envelope** (every 200):

  ```json
  {"available": true, "payments": [], "intents": [], "refund": {"refunded_total": 0, "refund_revision": 0}}
  ```

  `refund` echoes the already-validated order's order-level aggregates.
- Availability per Ruling 8. Authority: `commerce.view` (read) as the order `show` route.

### 2.3 Print views (SPA)

- **Route:** `admin/src/pages/commerce/orders/[uuid]/invoice.vue`, `<route lang="yaml">` metadata (matching the existing detail page convention), same auth/capability as detail. Opened from detail via real link `target="_blank" rel="noopener"`.
- **Data:** the existing `invoiceData` endpoint + the Invoices & receipts settings + (optionally, if present in cache or one fetch) nothing else. Payment-summary is NOT fetched here; the document prints "Order status: …" per Ruling 11.
- **Layouts:** A4 (default) and thermal 80mm/58mm — single column, no backgrounds/color-borders, high-contrast monochrome, dashed rules, larger relative type. Named pages switch the print contract: A4 selects `invoice-a4` with `@page invoice-a4 { size: A4 }`; thermal selects `invoice-thermal` with `@page invoice-thermal { size: auto }` and constrains the content box to 80mm/58mm. The standards grammar cannot express a fixed width with automatic roll height; the operator selects the matching roll in the browser/printer dialog.
- **Per-print override:** on-page A4/80mm/58mm segmented control (initial value from settings), then `window.print()`. Never persists.
- **Content:** logo (if resolvable), seller identity, buyer block, order number + dates, "Order status", line items (name, optional SKU, sanitized addon name/value/choice data, unit price, qty, line total — no variant options, per §2.6 note; no thumbnails/links per Ruling 13), totals stack, refund lines, optional addresses/tax id, footer text (escaped at render regardless of validation).
- **Money:** `formatMoney({currency, currency_exponent})` using `currency_exponent` from InvoiceData (§2.6).
- **Print CSS:** global print rules + stable hooks: dashboard sidebar and invoice toolbar are `data-print-chrome`; the content wrapper around RouterView is `data-print-shell` and stays visible while its dashboard margins/ring/radius/overflow are removed. A `data-print-chrome` ancestor must never contain the printable RouterView. `thead` repeats per page; rows avoid breaks and never clip or line-clamp content.
- **Failure:** failed invoice fetch shows a retry state — never a blank printable.

### 2.4 Orders list (SPA)

- `orders/index.vue` switches to `/orders/search` via new `admin/src/queries/commerceOrderSearch.ts` (pinia-colada; key built from **normalized scalar values**, not object identity).
- Toolbar: `q` search (300ms debounce; placeholder "Order # or email"), status select, fulfillment select, date-range picker (Today/7d/30d/custom ⇒ plain `YYYY-MM-DD`).
- **URL state contract:** one initial hydration occurs before watchers; only valid status/fulfillment, strict real dates, page >= 1, and per-page 1–100 hydrate. The hydrated page is preserved. Afterwards non-search filters apply immediately, `q` alone is debounced, user filter changes reset page, and page navigation does not. Canonical serialization omits defaults/nulls and an equality guard prevents replace loops.
- **CSV:** fetch → Blob → object URL idiom (as `formSubmissions.ts:79`) with the bearer transport; parse a 422 **before** reading the blob and toast the narrowing message. Button visible under `commerce.view`.
- Table adds a fulfillment-status column. The order-number link remains the navigation semantic (no whole-row activation this cycle).

### 2.5 Order detail hierarchy (SPA) + Invoices & receipts settings

**Detail page rework (`orders/[uuid]/index.vue`):**

- Header band: order number + copy, status + fulfillment badges, placed date, customer email + copy, grand total. Primary action: "Print invoice / receipt" (opens print view). Lifecycle actions grouped beside it; destructive cancel + the formatted "Invoice data" modal (existing, accurately labeled) in an overflow menu.
- Payment summary card (from §2.2) with four explicit classifications: `unavailable`, `no payments or attempts`, `payment records`, `payment attempts` (intent, no completed payment). When both arrays are populated the card renders both completed records and attempts; classification never hides data. Fields: gateway, payment status, reference + `gateway_transaction_id` with copy, amount/currency, timestamps; refunded total labeled as an **order-level aggregate**. 500 ⇒ error state + toast, never mistaken for empty.
- Line items: name, SKU, addons, unit price, qty, line total (no links/thumbnails this cycle).
- Copy controls: order number, email, payment reference, each address — copying the same normalized displayed address text, never raw JSON.
- Addresses side-by-side desktop / stacked mobile. Timeline + notes below the commercial blocks.
- Sticky rail (wide screens): compact identity summary — order number, status, total, print link, an "Actions" anchor to the one canonical header action group, and section links. It must not instantiate a second lifecycle-action controller/dialog or duplicate commercial sections/data cards. Cancellation has one dialog/mutation owner, opened from the header overflow.

**Settings — Commerce Settings → "Invoices & receipts" tab:**

- Seller identity (`commerce.seller.name/tax_id/address`) shown read-only with an "edit in Store settings" pointer.
- New tenant-scoped keys via the existing commerce settings mechanism:
  - `commerce.invoice.logo_blob_uuid` — saving proves the blob is active, public, image-MIME, view-authorized by the host `BlobAccessPolicy`, and resolvable through `MediaUrlResolver`. This honors tenant ownership when tenancy is enabled without falsely requiring a `media_assets` row in single-store mode. GET returns a derived `invoice_logo_url`; deleted/unresolvable logos produce a null URL and are omitted without erasing the stored uuid.
  - `commerce.invoice.footer_text` — plain text, length-capped; containing `<` is **refused (422)**, not stripped; rendering still escapes (validation is not the security boundary).
  - `commerce.invoice.show_sku` / `show_addresses` / `show_tax_id` — default true, one canonical storage representation; API returns real booleans.
  - `commerce.invoice.paper_preset` — closed enum `a4|thermal_80|thermal_58`.

### 2.6 Deliberate upstream `glueful/commerce` extensions (v1.9.1, behind the publication gate)

Both belong upstream — they benefit every Commerce host; app-side equivalents would create temporary authorities needing later removal.

1. **`currency_exponent` added to `InvoiceData`** (part of the invoice-data contract), derived from the order's own currency — historically correct receipts never borrow today's store exponent. With compatibility tests.
2. **Shared payable-type constant** for `commerce_order` (Commerce's payable identity, owned by Commerce): today the literal is written at `CheckoutService.php:901` and repeated in `OrderPaymentConfirmationHandler`, `Mail/OrderNotifiable`, `Refunds/RefundService`, and `ChargebackService::SUPPORTED_PAYABLE_TYPE` — extract one constant, migrate those sites, Thallo consumes it. With compatibility tests.
3. **Release + gate:** tag v1.9.1, repin both Thallo root and `packages/thallo-commerce` constraints to `^1.9.1`, update the lock. If the package is not yet available, STOP at the publication gate; Thallo work resumes only after installation resolves from the published artifact.
4. *(Recorded as follow-up, NOT this cycle:)* immutable product-identity snapshot (`product_uuid`/`variant_uuid`/thumbnail) on admin line projection + InvoiceData; variant `option_values` in InvoiceData.

## §3 Test matrix

**PHP (pack integration):**

- Search/list/export provably share one builder (mutation: a filter applied to list must bind export).
- Date branches: placed_at-set and placed_at-null rows both honored; half-open boundary (order at `to` 00:00 excluded; at `from` 00:00 included).
- `q`: prefix match on order_number and email; `%`/`_`/escape-char in input match literally; case-normalized email.
- Sort: `COALESCE` report-time ordering with id tie-break.
- Enum 422s for bad status/fulfillment; malformed dates 422.
- CSV: cap-before-headers (10,001 rows ⇒ 422, no partial body); allowlisted columns exactly; formula neutralization for each trigger character; keyset batches cover full result (seed > batch size); minor-units values.
- Payments endpoint: cross-tenant order ⇒ 404 with **zero Payvia queries** (recording double); provider disabled but tables present ⇒ `available: true` with historical rows; tables absent ⇒ `available: false` envelope; real DB failure ⇒ 500 propagates; deterministic ordering; hostile secret-bearing `raw_payload`/`metadata`/`message` seeded and asserted absent from the response; invariant envelope on every 200; intents `open|closed` returned sorted.
- Settings: logo blob cross-tenant refused when tenancy is on; valid single-store image accepted without a media-assets row; non-image/inactive/private/unresolvable refused; deleted accepted logo yields null derived URL without erasing its uuid; footer `<` refused (422); defaults, paper enum, and boolean canonicalization round-trip.
- Payable type sourced from the engine constant (grep/identity assertion).

**Vitest (state + markup):**

- List URL-state contract: one-time hydration, strict validation/bounds, hydrated page preservation, debounce on `q` only, reset on user filter changes but not page navigation, canonical-loop-free URL writes, normalized-scalar query keys.
- CSV export idiom incl. 422-before-blob toast path.
- Print views: all three presets render; untoggleable core present regardless of settings toggles; optional sections respond to toggles; footer text escaped; "Order status" label; segmented override switches layout without settings write.
- Detail: header contents + copy controls; payment card four states + order-level refund label; action grouping incl. overflow; address copy text = displayed projection.
- Settings panel: round-trip, field-level 422 rendering, read-only seller identity pointer.

**Real-browser print gate:** extend the existing `tools/runtime-browser` Chromium harness (no second Playwright toolchain). Under `emulateMedia('print')`, all three presets hide chrome but preserve the document, repeat headers, avoid row clipping, and apply the expected document/content widths.

## §4 Follow-ups (recorded, out of scope)

- **Cycle 2: admin order creation** — engine-level draft/manual order surface (state-machine entry, totals, payment linkage).
- Upstream immutable product-identity snapshot (Ruling 13 / §2.6.4).
- Branding snapshots for historical receipts (compliance).
- Template editor (separate project: sandboxing, versioning, preview, recovery).
- Retire the app list endpoint when upstream filtering reaches parity (temporary-ownership marker).
