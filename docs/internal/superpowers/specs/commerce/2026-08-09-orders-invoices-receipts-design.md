# Orders Management: Invoices & Receipts, List Filtering, Detail Hierarchy — Design

**Date:** 2026-08-09
**Scope:** App-side cycle 1 of the orders program: printable invoices/receipts with settings-driven customization, an app-owned filtered orders list + CSV export, a payment-summary surface, and the order-detail hierarchy rework. **Admin order creation is cycle 2** (engine-level, separately specced).
**Posture:** No publication dependency, but deliberate schema coupling to Commerce (`commerce_orders`, `commerce_order_lines`) and Payvia (`payments`, `payment_intents`) tables. Two small, deliberate upstream `glueful/commerce` extensions are in scope (§2.6). The vendor `orders.index` endpoint stays mounted untouched; the app list endpoint is **temporary ownership** — retire when equivalent upstream filtering exists.

---

## §1 Rulings

1. **Two print documents, one data source.** An A4/Letter invoice and purpose-built 58/80mm thermal receipt layouts, both fed by the engine's `invoiceData` endpoint. Browser print dialog does paper/PDF; no server-side PDF, no new data access.
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
- **`AdminOrderSearchFilter`** — framework QueryFilter: takes the `Request`, parses/validates params, applies itself to the builder supplied by `AdminOrderSearchQuery`. Params:
  - `status`: closed enum (`pending_payment|paid|fulfilled|canceled|refunded`); invalid ⇒ 422.
  - `fulfillment_status`: closed enum (`unfulfilled|partial|fulfilled`); invalid ⇒ 422.
  - `placed_from`/`placed_to`: `YYYY-MM-DD`; half-open UTC boundaries `[from 00:00, to+1day 00:00)`. Indexable two-branch report-time predicate — never `WHERE COALESCE(...)`:
    - `placed_at IS NOT NULL AND placed_at >= from AND placed_at < toExclusive`, OR
    - `placed_at IS NULL AND created_at >= from AND created_at < toExclusive`.
  - `q`: normalized (trimmed, lowercased for email leg) prefix match against `order_number` OR `email`. `%`, `_`, and the escape character are escaped before `LIKE`. Documented as normalized prefix match (which includes exact).
- **Sort:** `COALESCE(placed_at, created_at) DESC, id DESC` (sort may use COALESCE; filtering may not).
- **`AdminOrderSearchController`** — `GET /v1/admin/commerce/orders/search`: paginated; every row through `OrderProjection::forAdmin`.
- **CSV export** — `GET /v1/admin/commerce/orders/export`:
  - Same `AdminOrderSearchQuery` + `AdminOrderSearchFilter`.
  - Unsorted filtered COUNT first; > 10,000 ⇒ 422 ("narrow your filters") **before any CSV headers are sent**.
  - Streamed HTTP response over bounded keyset batches (~500 rows keyed on the sort tuple) — no framework row-cursor claim; a deliberately scoped raw PDO fetch loop is an acceptable alternative.
  - Allowlisted columns only: `order_number, status, fulfillment_status, email, currency, subtotal, discount_total, shipping_total, tax_total, refunded_total, grand_total, discount_code, shipping_method, placed_at`. Money in minor units; no locale formatting.
  - Formula neutralization: values beginning `=`, `+`, `-`, `@`, tab, or carriage return are prefixed with `'` — applied after scalar serialization, before CSV escaping.
- **Temporary-ownership marker:** routes + both classes carry a docblock: retire in favor of upstream `orders.index` filtering at parity.

### 2.2 Payment summary read model (pack)

- **`OrderPaymentSummaryRepository`** — owns Payvia table access: schema readiness (`hasTable` for `payments`/`payment_intents`), tenant + payable predicates, deterministic ordering (`created_at DESC, id DESC`), normalization, closed projection. Controllers never touch tables.
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
- **Layouts:** A4 (default) and thermal 80mm/58mm — single column, no backgrounds/color-borders, high-contrast monochrome, dashed rules, larger relative type, `@page` size per preset.
- **Per-print override:** on-page A4/80mm/58mm segmented control (initial value from settings), then `window.print()`. Never persists.
- **Content:** logo (if resolvable), seller identity, buyer block, order number + dates, "Order status", line items (name, optional SKU, sanitized addon name/value/choice data, unit price, qty, line total — no variant options, per §2.6 note; no thumbnails/links per Ruling 13), totals stack, refund lines, optional addresses/tax id, footer text (escaped at render regardless of validation).
- **Money:** `formatMoney({currency, currency_exponent})` using `currency_exponent` from InvoiceData (§2.6).
- **Print CSS:** global print rules + stable chrome hooks so the dashboard layout is reliably hidden from a scoped page; `thead` repeats per page; `break-inside: avoid` on rows.
- **Failure:** failed invoice fetch shows a retry state — never a blank printable.

### 2.4 Orders list (SPA)

- `orders/index.vue` switches to `/orders/search` via new `admin/src/queries/commerceOrderSearch.ts` (pinia-colada; key built from **normalized scalar values**, not object identity).
- Toolbar: `q` search (300ms debounce; placeholder "Order # or email"), status select, fulfillment select, date-range picker (Today/7d/30d/custom ⇒ plain `YYYY-MM-DD`).
- **URL state contract:** filters hydrate from the query string; only valid status/fulfillment/date/page/per-page values hydrate — malformed values are discarded. Non-search filter changes apply immediately; every filter change resets `page` to 1.
- **CSV:** fetch → Blob → object URL idiom (as `formSubmissions.ts:79`) with the bearer transport; parse a 422 **before** reading the blob and toast the narrowing message. Button visible under `commerce.view`.
- Table adds a fulfillment-status column. The order-number link remains the navigation semantic (no whole-row activation this cycle).

### 2.5 Order detail hierarchy (SPA) + Invoices & receipts settings

**Detail page rework (`orders/[uuid]/index.vue`):**

- Header band: order number + copy, status + fulfillment badges, placed date, customer email + copy, grand total. Primary action: "Print invoice / receipt" (opens print view). Lifecycle actions grouped beside it; destructive cancel + the formatted "Invoice data" modal (existing, accurately labeled) in an overflow menu.
- Payment summary card (from §2.2) with four explicit states: `unavailable`, `no payments or attempts`, `payment records`, `payment attempts` (open intent, no completed payment). Fields: gateway, payment status, reference + `gateway_transaction_id` with copy, amount/currency, timestamps; refunded total labeled as an **order-level aggregate**. 500 ⇒ error state + toast, never mistaken for empty.
- Line items: name, SKU, addons, unit price, qty, line total (no links/thumbnails this cycle).
- Copy controls: order number, email, payment reference, each address — copying the same normalized displayed address text, never raw JSON.
- Addresses side-by-side desktop / stacked mobile. Timeline + notes below the commercial blocks.
- Sticky rail (wide screens): compact identity/action summary — order number, status, total, primary actions, section links. It intentionally repeats that summary; it duplicates no commercial sections or data cards.

**Settings — Commerce Settings → "Invoices & receipts" tab:**

- Seller identity (`commerce.seller.name/tax_id/address`) shown read-only with an "edit in Store settings" pointer.
- New tenant-scoped keys via the existing commerce settings mechanism:
  - `commerce.invoice.logo_blob_uuid` — saving proves the blob is active, public, image-MIME, and owned by the current tenant; deleted/unresolvable logos are omitted at render time.
  - `commerce.invoice.footer_text` — plain text, length-capped; containing `<` is **refused (422)**, not stripped; rendering still escapes (validation is not the security boundary).
  - `commerce.invoice.show_sku` / `show_addresses` / `show_tax_id` — one canonical storage representation; API returns real booleans.
  - `commerce.invoice.paper_preset` — closed enum `a4|thermal_80|thermal_58`.

### 2.6 Deliberate upstream `glueful/commerce` extensions (small, this cycle)

1. **`currency_exponent` added to `InvoiceData`**, derived from the order's own currency — historically correct receipts never borrow today's store exponent.
2. **Shared payable-type constant** for `commerce_order` extracted where checkout writes it (if no constant exists today); Thallo consumes the constant.
3. *(Recorded as follow-up, NOT this cycle:)* immutable product-identity snapshot (`product_uuid`/`variant_uuid`/thumbnail) on admin line projection + InvoiceData; variant `option_values` in InvoiceData.

## §3 Test matrix

**PHP (pack integration):**

- Search/list/export provably share one builder (mutation: a filter applied to list must bind export).
- Date branches: placed_at-set and placed_at-null rows both honored; half-open boundary (order at `to` 00:00 excluded; at `from` 00:00 included).
- `q`: prefix match on order_number and email; `%`/`_`/escape-char in input match literally; case-normalized email.
- Sort: `COALESCE` report-time ordering with id tie-break.
- Enum 422s for bad status/fulfillment; malformed dates 422.
- CSV: cap-before-headers (10,001 rows ⇒ 422, no partial body); allowlisted columns exactly; formula neutralization for each trigger character; keyset batches cover full result (seed > batch size); minor-units values.
- Payments endpoint: cross-tenant order ⇒ 404 with **zero Payvia queries** (recording double); provider disabled but tables present ⇒ `available: true` with historical rows; tables absent ⇒ `available: false` envelope; real DB failure ⇒ 500 propagates; deterministic ordering; hostile secret-bearing `raw_payload`/`metadata`/`message` seeded and asserted absent from the response; invariant envelope on every 200; intents `open|closed` returned sorted.
- Settings: logo blob cross-tenant refused, non-image refused, inactive/private refused; footer `<` refused (422); paper preset closed-enum; boolean canonicalization round-trip.
- Payable type sourced from the engine constant (grep/identity assertion).

**Vitest (state + markup):**

- List URL-state contract: hydration filtering of malformed params, debounce on `q` only, page reset on every filter change, normalized-scalar query keys.
- CSV export idiom incl. 422-before-blob toast path.
- Print views: all three presets render; untoggleable core present regardless of settings toggles; optional sections respond to toggles; footer text escaped; "Order status" label; segmented override switches layout without settings write.
- Detail: header contents + copy controls; payment card four states + order-level refund label; action grouping incl. overflow; address copy text = displayed projection.
- Settings panel: round-trip, field-level 422 rendering, read-only seller identity pointer.

**Real-browser print gate (`emulateMedia('print')`, all three presets):** admin chrome hidden; required fields visible; table headers repeat; long rows do not clip.

## §4 Follow-ups (recorded, out of scope)

- **Cycle 2: admin order creation** — engine-level draft/manual order surface (state-machine entry, totals, payment linkage).
- Upstream immutable product-identity snapshot (Ruling 13 / §2.6.3).
- Branding snapshots for historical receipts (compliance).
- Template editor (separate project: sandboxing, versioning, preview, recovery).
- Retire the app list endpoint when upstream filtering reaches parity (temporary-ownership marker).
