# Outstanding Features — Thallo tracker

> A **living checklist** of what's left to build. Unlike [NEXT.md](NEXT.md) (a stable
> pointer page), this file is meant to be edited as work lands — tick items off, link the
> spec/plan/commit, and move them to **Recently shipped**.

**Last reconciled:** 2026-08-14 (against the working tree, not just `NEXT.md`).

## How to use this file

- Each item is a checkbox. When it ships:
  1. Change `- [ ]` → `- [x]`.
  2. Append `— shipped YYYY-MM-DD` and a link to the spec/plan (or commit).
  3. Cut it from its section and paste it under **Recently shipped** (newest first).
- **Size** is a rough estimate: `S` (a sliver, days), `M` (a feature, ~1–2 weeks), `L` (a track, multiple specs).
- **Home** links the doc where the work is already designed or tracked; if blank, no design exists yet — start with the brainstorm → spec → plan → implement loop.
- Keep this list reconciled with reality: if you discover something here already shipped, tick it and note the date.

Legend: **Size** S/M/L · **Home** = existing spec/doc, or _"(no design yet)"_.

---

## A. Large tracks (named in the vision)

- [ ] **Ecommerce content integration** — `L` — **Home:** [APPROACH.md](APPROACH.md). _No design yet._
- [ ] **Personalization / segmentation** — `L` — **Home:** [APPROACH.md](APPROACH.md). _No design yet._

## B. Per-feature follow-ups (deferred slivers on shipped features)

- [ ] **Schema migrations: `retype` ops** — `S` — only `delete` + `rename` shipped. **Home:** [destructive-schema-backfill spec](superpowers/specs/2026-06-16-destructive-schema-backfill-design.md).
- [ ] **Field localization: copy-on-change sync** — `S` — sync non-localized fields across locales on change. **Home:** [field-localization spec](superpowers/specs/2026-06-16-field-localization-design.md).
- [ ] **Version pruning: scheduled + export-before-prune interlock** — `S` — manual pruning shipped. **Home:** [version-pruning spec](superpowers/specs/2026-06-16-version-pruning-design.md).
- [ ] **Per-locale RBAC: per-content-type scoping** — `M` — extend the same Aegis mechanism to content-type scope. **Home:** [per-locale-rbac spec](superpowers/specs/2026-06-16-per-locale-rbac-design.md).
- [ ] **Scheduled publish: auto-retry, recurring, failure notifications** — `M` — **Home:** [scheduled-publish spec](superpowers/specs/2026-06-16-scheduled-publish-design.md).
- [ ] **SEO: redirect import/export** — `S` — pack ships sitemaps/meta/robots; import/export not built. **Home:** [seo-routing-module spec](superpowers/specs/2026-06-16-seo-routing-module-design.md).
- [ ] **SEO: `thallo:seo:check` + `redirects:prune` commands** — `S` — not built. **Home:** same spec as above.
- [ ] **Multi-workspace setup: admin-settable resolution hosts** — `M` — base domain + default hosts are `config/tenancy.php`/`.env`-only today, so activating full resolution requires a hand-edited `.env` + server restart the admin UI never surfaces (the Resolution → Activate button 422s on "At least one default tenant host must be configured"). Make them persisted admin settings so the flow is UI-driven — enable → set base domain/hosts → activate — with at most one "reload to finish" prompt instead of editing `.env` and guessing when to bounce the server. Surfaced by dogfooding on thallodev.dev 2026-07-11. **Home:** _(no design yet)_ — follow-up on shipped multi-tenancy.
- [ ] **Subscriptions: Stripe Billing Portal / provider-native plan changes** — `M` — self-serve plan upgrades/downgrades and payment-method management via the provider's own hosted portal (Stripe Billing Portal and equivalents), instead of the shipped checkout-origination-only flow (new subscription + cancel). Deliberately deferred as a separate capability with its own review cycle — the shipped self-serve checkout work is subscribe/cancel only, per design spec §8. **Home:** [workspace-checkout spec §8](superpowers/specs/2026-08-03-workspace-checkout-design.md) — follow-up on shipped workspace self-serve checkout.
- [ ] **Subscriptions: public signup-to-checkout funnel** — `S` — a public (pre-authenticated-admin) signup flow that lands directly in checkout, reusing `SubscriptionCheckoutService` with a verified workspace subject + actor resolved through the public signup flow. No Payvia redesign needed — the shipped `WorkspaceCheckoutCoordinator`/engine stack already assumes an authenticated admin-panel actor; this wires a public entry point onto the same seam. Deliberately out of scope for the admin-panel self-serve checkout work, per design spec §8. **Home:** [workspace-checkout spec §8](superpowers/specs/2026-08-03-workspace-checkout-design.md) — follow-up on shipped workspace self-serve checkout.
- [ ] **Workspace merchant connections** — `M` — the later merchant-connection model the platform-payments-settings program deliberately did NOT build, so its structures (the app-owned platform payment store, the marker-gated cutover, the neutral Settings → Payments surface) are not undone by it: workspace-owned gateway connections; Payvia's explicit `platform | workspace:{workspace_uuid}` merchant scopes; per-connection webhook routing that identifies the connection BEFORE selecting and verifying its secret (a single tenantless secret per gateway cannot serve N workspace merchant accounts); and paid-membership revenue surfaces. Until this ships, every Thallo storefront order and workspace SaaS subscription settles through the ONE platform gateway account — stated prominently in the Settings → Payments UI, not workspace payment isolation. **Home:** [platform-payments-settings spec §4](superpowers/specs/2026-08-05-platform-payments-settings-design.md) — follow-up on shipped platform payments settings.
- [ ] **Orders/invoices: upstream immutable product-identity snapshot** — `M` — `product_uuid`/`variant_uuid`/thumbnail on the admin order-line projection + `InvoiceData`, plus variant `option_values` in `InvoiceData`; deliberately omitted this cycle (Ruling 13) so the admin line projection and invoice data never open a per-line product lookup loop. Belongs in upstream `glueful/commerce`, like the two extensions this program already shipped there. **Home:** [orders-invoices-receipts spec §2.6.4/§4](superpowers/specs/commerce/2026-08-09-orders-invoices-receipts-design.md) — follow-up on the orders invoices & receipts program (cycle 1).
- [ ] **Orders/invoices: branding snapshots for historical receipts** — `M` — historical receipts render with CURRENT branding settings (logo/footer/toggles) against immutable order data (Ruling 4); a true point-in-time snapshot of branding as it was when the order was placed is a later compliance feature. **Home:** [orders-invoices-receipts spec §4](superpowers/specs/commerce/2026-08-09-orders-invoices-receipts-design.md) — follow-up on the orders invoices & receipts program (cycle 1).
- [ ] **Orders/invoices: template editor** — `L` — a separate future project (sandboxing, versioning, preview, recovery) for admin-authored invoice/receipt templates, distinct from the shipped settings-driven customization (logo, footer text, SKU/address/tax-id toggles, paper preset). **Home:** [orders-invoices-receipts spec §4](superpowers/specs/commerce/2026-08-09-orders-invoices-receipts-design.md) — follow-up on the orders invoices & receipts program (cycle 1).
- [ ] **Orders/invoices: retire the app orders list endpoint at upstream filter parity** — `S` — `GET /v1/admin/commerce/orders/search` and `GET /v1/admin/commerce/orders/export` are TEMPORARY app ownership (both carry a temporary-ownership docblock) standing in for filtering the vendor `orders.index` endpoint doesn't yet offer; the vendor endpoint stays mounted untouched in the meantime. Retire both once `glueful/commerce` ships equivalent filtering. **Home:** [orders-invoices-receipts spec, Posture](superpowers/specs/commerce/2026-08-09-orders-invoices-receipts-design.md) — follow-up on the orders invoices & receipts program (cycle 1).
- [ ] **Admin order creation: guest self-service access custody** — `S` — guest self-service access custody for admin-born orders; unchanged by the payment-links cycle — the payment token deliberately grants none of it. **Home:** [admin-order-creation spec §4](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) — follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin order creation: account-attached digital orders** — `M` — download delivery for admin-born digital orders defined by account custody. **Home:** [admin-order-creation spec §4](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) — follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin order creation: marketplace-partitioned admin orders** — `M` — seller-order split + ledger at finalize. **Home:** [admin-order-creation spec §4](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) — follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin order creation: audited per-line price override** — `S` — comps/B2B price override capability, audited. **Home:** [admin-order-creation spec §4](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) — follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin order creation: in-store counter-sale tax** — `S` — in-store admin sales compute zero tax today; a real answer needs a store-address decision still pending. **Home:** _(no design yet)_ — follow-up on shipped admin order creation (cycle 2).
- [ ] **Payment links: Paystack renewal** — `S` — unavailable in v1: a new Paystack initialization cannot prove the old authorization URL dead, and a late second settlement is only detected after a double charge, so renewal requires a provider-confirmed permanent-death signal (or Paystack's payment-request API) that doesn't exist yet. Today's recovery is offline mark-paid or an explicit risk-acknowledged cancel/recreate. **Home:** [payment-links spec §4](superpowers/specs/commerce/2026-08-11-payment-links-design.md) — follow-up on shipped payment links.
- [ ] **Payment links: generic provider-session invalidation/status seam** — `M` — so a Stripe-confirmed dead session could release the conservative issued-session cancellation hold automatically instead of requiring the operator's `accept_late_payment_risk` acknowledgement; v1 deliberately uses one safe rule for every gateway regardless of which one can actually prove death. **Home:** [payment-links spec §4](superpowers/specs/commerce/2026-08-11-payment-links-design.md) — follow-up on shipped payment links.
- [ ] **Payment links: SMS/WhatsApp delivery channels** — `M` — only email is a first-class Send action today; copy-to-clipboard covers other channels manually. **Home:** [payment-links spec §4](superpowers/specs/commerce/2026-08-11-payment-links-design.md) — follow-up on shipped payment links.
- [ ] **Payment links: link analytics (opened/clicked)** — `S` — deliberately excluded — would require relaxing the landing page's no-analytics/zero-third-party-asset rule. **Home:** [payment-links spec §4](superpowers/specs/commerce/2026-08-11-payment-links-design.md) — follow-up on shipped payment links.
- [ ] **Payment links: `EmailChannel` exception-message width + `composeUrl` scrub** — `S` — two narrow custody/hygiene items from the payment-links final review, one of the original three closed by the cleanup train (the `pristineRequests` WeakMap cleanup shipped — see Recently shipped): (a) `EmailChannel` surfaces provider exception messages at full width into the delivery ledger, where a provider that echoes the message body would land link text in a stored row; (b) `composeUrl()` builds the public link before any scrub of its inputs, so a malformed base could compose a URL that is then only validated, never sanitized. Neither is a known live leak; both are defence-in-depth. **Home:** _(no design yet)_ — surfaced by the payment-links final review.
- [ ] **`glueful/payvia` engine: payable-less `confirm` still rewrites the `payments` row's attribution** — `S` — `PaymentService::confirmAndRecord()`'s payable-binding guard (2.7.0) only fires when the caller supplies BOTH `payable_type` and `payable_id`; a confirm call that supplies neither still reaches the `payments` upsert keyed on reference alone, so a legacy/manual caller can still rewrite an existing row's stored attribution with no comparison at all. Pre-existing, not introduced by 2.7.0 — the guard narrowed the gap without closing it. Adjudicated **park** at the T1 review. **Home:** _(no design yet)_ — payvia follow-up, cleanup-train T1 review.
- [ ] **`glueful/payvia` engine: `409 payable_mismatch` refusals are logged at `error` severity** — `S` — the payable-attribution refusal added in 2.7.0 is a legitimate, expected refusal (an attacker or a stale client retrying an old reference), not an application fault, but it logs at the same severity a genuine 500 would — a flood surface if a client retries a mismatched reference repeatedly, and noise that trains operators to ignore the error channel. Adjudicated **park** at the T1 review; the fix is lowering the log level on this one typed exception. **Home:** _(no design yet)_ — payvia follow-up, cleanup-train T1 review.
- [ ] **`glueful/framework`: `SensitiveParamRedactor` first-match-mutates template ordering** — `S` — `redactSegments()` applies every registered `logging.sensitive_paths` template to the SAME segment array in REGISTRATION ORDER, and a more specific template must be registered before a more general one whose placeholder would otherwise land on and redact the specific template's own literal segment — once that happens the specific template's literal comparison silently fails to match and its intended redaction never fires, with no error or warning of any kind. Discovered concretely in the cleanup train: `/checkout/pay/return/*/{signature}` and `/checkout/pay/cancel/*/{signature}` must be registered before `/checkout/pay/{token}`, whose placeholder otherwise consumes the literal `return`/`cancel` segment first. Today's only guards are a config comment and one ordering-assertion test; a future edit that inserts a new template ABOVE an existing specific one silently disables that template's redaction. Candidate fix upstream: compile `sensitive_paths` longest-match-first (by static segment count, or explicit specificity) instead of trusting registration order. **Home:** _(no design yet)_ — framework bug, surfaced by the cleanup train (T10).
- [ ] **Thallo PHP suite: `composer test` assertion-total nondeterminism** — `S` — the reported assertion total wobbles ±1–3 across otherwise-identical full-suite runs while the test count and skip count both stay exactly stable; the reliable gate today is tests+skips, not the assertion total. Surfaced during the payment-links program's repeated gate runs. **Cleanup-train hunt (T11a, time-boxed, mechanism not found):** ran the 8 largest/most-complex `tests/Integration/*` directories twice each in isolation (fresh `reset-test-db.php` + migration between every run, `vendor/bin/phpunit <dir>` directly, never the full suite) — `Commerce` (761 tests/9282 assertions/8 skipped, both runs byte-identical), `Render` (554/13814, identical), `Content` (297/977, identical), `Subscriptions` (157/1241, identical), `Tenancy` (115/1151/33 skipped, identical), `Collections` (137/466, identical), `Settings` (101/511, identical), `Http` (165/464, identical). Zero drift in any of the 16 runs — every directory is internally deterministic when run alone. **Excluded:** a single flaky test, a data-provider randomness source, and a per-class time-dependent branch confined to any one of these eight directories (the largest/highest-risk ones in the tree). **Working hypothesis, not yet verified:** the wobble is a full-suite-only, CROSS-directory effect that can't reproduce in an isolated single-directory phpunit invocation — `scripts/reset-test-db.php` resets only the Postgres schema between runs, never `storage/`/cache/export filesystem state, so a file a test in one directory leaves behind (an export, an upload, a warmed cache entry) could feed a filesystem-scanning assertion or data provider in an unrelated LATER directory only when both run in the SAME continuous phpunit process — which never happens in an isolated per-directory run. An in-process static/singleton registry accumulating across many test classes (rather than a handful) is the other live candidate. Neither was isolated to a specific class within the ~8-run time-box. **Next step for whoever picks this up:** run the FULL suite twice back to back with `--log-junit` (or a small custom assertion-count-per-testsuite reporter) and diff per-directory subtotals to find which directory's count actually MOVES inside a full run — this hunt's contribution is ruling out 8 directories as internally nondeterministic, narrowing the search to cross-directory interaction. **Home:** _(no design yet)_.
- [ ] **Payvia/engine: `payment_amount_mismatch` refusals have no operator-level audit surfacing** — `S` — a mismatched-amount payment (materially likelier now that Stripe repricing supersedes while the OLD session stays payable at the provider — `renewRepriced()` never expires it, and its docblock overstates the capability argument) is refused correctly by the engine handler but recorded only as a timeline-local order event; the cleanup train's late-payment visibility listener covers `payment_late_rejected` only. Candidate: extend the same listener pattern to mismatch events (visibility parity), and correct the `renewRepriced()` docblock. **Home:** _(no design yet)_ — surfaced by the cleanup-train final review.
- [ ] **Framework: v1.78.1's tag tree diverges from `dev` in changelog/roadmap prose** — `S` — the published tag (the dev→main merge) carries +50 CHANGELOG / +9 ROADMAP lines that exist only on `main`; zero `src/` difference (vendor byte-verified). Reconcile the two changelogs at the next framework sitting so dev's history matches what shipped. **Home:** _(no design yet)_ — surfaced by the cleanup-train final review.

- [ ] **Slider: caption overlay on bare image slides** — `S` — hero slides already carry
  full text-over-image (scrim, on-media ink, buttons); this is the LIGHTWEIGHT middle
  option — an image slide's `caption` styled as a small overlay (bottom-left over a
  subtle gradient) inside hero-style sliders, instead of the below-image caption.
  Deliberately kept out of the 2026-08 slider v1 (transitions/heights/duration arc) to
  watch whether authors reach for hero slides first. **Home:** _(no design yet)_ —
  follow-up on the modern-blocks carousel.

## C. Forms follow-ups (form block v1 deferrals)

**Home for all:** [form-block spec §14](superpowers/specs/2026-07-09-form-block-design.md).

- [ ] **Field-builder UI** — `M` — editor to add/remove/reorder arbitrary fields, editing the same normalized model the sealed descriptor already uses (additive, not a rewrite). _Biggest seam._
- [ ] **Forms registry (Approach B)** — `M` — first-class `forms` table + projector for per-form dashboards/analytics; submissions already carry `form_key`/`form_name` so no data is stranded.
- [ ] **CAPTCHA guard provider** — `S` — a new `FormSubmissionGuard` implementation behind the existing seam.
- [ ] **File-upload fields** — `M`.
- [ ] **Multi-step forms** — `M`.
- [ ] **Scheduled digests / CRM / webhook delivery** — `M`.

## D. Importer depth (adapters shipped; these extend them)

**Home for all:** [ADAPTER_NOTES.md](ADAPTER_NOTES.md).

- [ ] **WordPress: media / attachments** — `M`.
- [ ] **WordPress: authors** — `S`.
- [ ] **WordPress: categories / tags** — `S` — now unblocked (model as a terms content type + multi-valued reference).
- [ ] **WordPress: custom post types** — `M`.
- [ ] **WordPress: post-meta** — `S`.
- [ ] **WordPress: upsert-by-WP-id (re-import idempotency)** — `S` — v1 is create-only.
- [ ] **CSV / Markdown: upsert-by-key** — `S` — both create-only today.

## E. Admin SPA

- [ ] **Polish pass** — `S` — no net-new surfaces outstanding; incremental UX refinement only. **Home:** `docs/superpowers/plans/*admin-spa*`.

---

## Recently shipped

- [x] **Framework: console boot eagerly opens a DB connection and runs DDL** — shipped 2026-08-16 in `glueful/framework` 1.78.3 (migrate commands resolve `MigrationManager` lazily in `execute()`; `php glueful list` works before the database does). Surfaced by the beta.1 artifact gate.
- [x] **Framework: `hasTable()` is privilege-blind on PostgreSQL** — shipped 2026-08-16 in `glueful/framework` 1.78.3 (`tableExistsQuery` reads `pg_catalog.pg_tables`, which reports existence regardless of privileges). Surfaced by the beta.1 artifact gate.

> Move ticked items here (newest first) with their ship date + spec link, so the sections
> above stay focused on what's left.

- [x] **Commerce cleanup train (payvia confirm/intent hardening, commerce draft/CAS fixes,
  framework OpenAPI cache bug, Thallo pack + SPA custody wave)** — shipped 2026-08-14 — an
  eleven-task train closing the straggler `S`-sized defects the payment-links and admin-order-
  creation cycles surfaced in review, across four repos. **Payvia 2.7.0** ("Attribution-Bound
  Confirmations"): `POST /payvia/payments/confirm` now refuses (409, non-revealing
  `payable_mismatch`, before any provider I/O or `payments` write) a caller-supplied payable that
  disagrees with the reference's own `payment_intents` binding, and same-reference redelivery of
  an already-settled confirmation returns success instead of a spurious `payment_late_rejected`
  (covers both a nested strict-lane settlement during recording and the more common out-of-band
  ordering — a webhook settles first, an operator replays the same reference later); a new
  `payvia:intents:sweep-stale` command reclaims abandoned `initializing`/`open` intent rows
  (`payvia.intents.stale_after_days`, default 30, clamped 1–365, `--tenant`), and confirmed-live
  session reuse now revalidates the payable's price before serving a cached checkout URL — a
  renewal-capable gateway (Stripe) supersedes and reopens on drift, a renewal-incapable one
  (Paystack) refuses with a typed `SessionRenewalUnavailableException` rather than ever leaving two
  live checkout URLs for the same payable (Ruling 6). Upgrading requires clearing any compiled
  container (a stale one now fails loud instead of silently running every confirm guard disabled).
  **Commerce 1.12.0** ("Draft Artifact Lifecycle & Settlement Idempotency"): the admin drafts list
  now hydrates a real `line_count`; `OrderRepository::transition()` throws a typed
  `OrderNotFoundException` instead of a bare `RuntimeException`; `AdminOrderController::markPaid()`
  runs the same draft-blind precheck every sibling endpoint already ran, so a draft/unknown/
  cross-tenant uuid gets the same non-revealing 404 instead of a 409 that mildly confirmed the row
  existed; the loser of a `pending_payment -> paid` CAS race now concedes idempotently
  (`markPaid(): bool`, 200 with the already-paid order) instead of a bare 500, routed through
  `rejectLatePayment()` exactly as a genuinely-late confirmation would be; and a new
  `DELETE /orders/{uuid}/artifact` endpoint (+ a third phase on the existing
  `commerce:orders:expire` sweep, `commerce.orders.draft_purge_days` default 30 clamped 1–365) lets
  an operator or the sweep permanently delete a numberless canceled draft artifact — structurally
  proven money-free (`order_number IS NULL AND status = 'canceled'`), deliberately unaudited (an
  order-event row referencing a deleted order would be permanently unreadable) but PSR-3 logged
  with actor/reason and no customer PII. **Framework 1.78.1**: `OpenApiGenerator::obtainRouter()`
  no longer resets `RouteManifest` and re-registers every route file when it detects a populated
  route cache — boot's own unconditional manifest load already leaves the container's router fresh
  with full metadata, so the old "cache detected, rebuild" branch was re-running every route file a
  second time against a router that already held it, dying on a duplicate named-route collision;
  the documented "delete the gitignored cache before regenerating" workaround is gone, proven live
  by a two-run byte-identical `docs/openapi.json` regeneration with the cache in place throughout.
  **Thallo**: repinned to all three (composer.lock + `packages/thallo-commerce`), with two
  commerce-1.12-contract adaptations (`AdminOrderPaymentsTest`'s draft mark-paid pin moved from
  "409" to "byte-identical non-revealing 404"; `CompleteSaleCoordinator` now branches on
  `markPaid() === false` as an explicit race-loss classification, sharing one `lostMarkPaidRace()`
  response builder with the pre-1.12 throw arm so the wire contract is unchanged across engine
  generations) and `orders.artifact.destroy` added to the admin mount allowlist. The admin SPA
  gained a draft-artifact delete action (trash icon on numberless canceled rows, gated on
  `can_manage`, resolving on 404, distinguishing the engine's `order_not_deletable` refusal from
  every other failure); `apiErrorDetails()`/`apiErrorCode()` moved from identity-based
  `instanceof ApiError` to a structural shape check, immune to the duplicated-module-graph failure
  mode that would otherwise silently degrade every machine-readable-code consumer; the payment-link
  send card now surfaces a replayed failure's `error_code`, and `AdminPaymentLinkSendController`'s
  `STATUS_BY_ERROR_CODE` map became exhaustive over both packages' full vocabularies (a
  self-enforcing bidirectional exhaustiveness test) instead of silently falling through an
  undocumented subset; the `pristineRequests` WeakMap now reads-and-deletes its entry before any
  branch instead of retaining a current-send's raw token for as long as the response object lives;
  a current-mode payment-link send now rechecks the link's live status immediately before
  submitting, taking the same path the server's own `payment_link_changed` 409 takes rather than
  delivering an email whose link is already dead; the `thallo_commerce_product_slugs`/
  `thallo_commerce_checkout_attempts` pack-table purge/adoption gap is closed (all four pack tables
  registered everywhere, with a coherence test pinning that the adoption contributor and the purge
  provider agree on the same table list); the payment-link receipt return/cancel `{signature}`
  paths are now registered sensitive-log templates, ordered BEFORE the token template (ordering is
  load-bearing here — ordering last wins, see the new framework `SensitiveParamRedactor` entry
  above); a `LatePaymentRejected` subscriber now writes a de-duped `glueful/audit` entry (category
  `security`, keyed on `(order_uuid, reference)` so a CAS-loser's idempotent concession never
  double-counts) so a late provider payment needing operator review is discoverable without already
  knowing which order to look at; the payment-links README's and this file's own "two egress
  points" claim is corrected to three (the landing page's Pay-form `action` was always the third,
  sanctioned one); and the `DraftFulfillmentCard` shipping-method `USelect` no longer crashes
  reka-ui the first time delivery mode is actually opened — the empty-string placeholder option is
  now a non-empty sentinel (`NO_SHIPPING_METHOD`) translated to `null` at the save boundary, the
  same idiom every other USelect in the SPA already uses (e.g. `commerce/products/index.vue`'s
  `ALL` sentinel), reproduced RED first by mounting the real (unstubbed) `<USelect>`/reka-ui stack
  and switching into delivery mode. **Time-boxed and NOT found this cycle:** the PHP suite's
  ±1–3 assertion-total wobble — see that entry above for the 8-directory isolation sweep and the
  cross-directory working hypothesis it narrows the search to. **Deliberately parked, not
  attempted:** two payvia custody nuances surfaced at the T1 review (payable-less confirm still
  rewrites `payments`-row attribution; 409 refusals logged at `error` severity) and the `EmailChannel`/
  `composeUrl` hygiene pair from the payment-links review — all recorded above as their own entries.
  [plan](superpowers/plans/2026-08-14-commerce-cleanup-train.md).

- [x] **Payment links for admin orders (Payvia payment links + customer emailing)** — shipped
  2026-08-12 — durable, revocable payment links for admin-origin `pending_payment` orders, closing
  the admin-order-creation cycle-2 follow-up: an admin finalizes a phone/remote order and sends the
  customer a link to a hosted-gateway-backed landing page instead of taking payment in person.
  Three-release train, human-published in order: **Payvia 2.6.0** makes `initiate()` ensure-live
  (no intent ⇒ create; confirmed-live ⇒ same URL; confirmed-dead ⇒ renew; unknown provider state ⇒
  fail closed, never force-fresh), backed by reference-addressable session attempts (`payment_intents`
  migrated to a service-enforced `initializing|open|superseded|closed|failed` status set, per-durable
  -attempt idempotency keyed off the attempt UUID, a reference-aware `ConfirmationDispatcher` that
  resolves the exact provider reference instead of "whichever intent is open") and a provider-host
  URL trust boundary that also fixes Paystack's previously-unchecked `authorization_url`. **Commerce
  1.11.0** adds the engine-native `commerce_payment_links` table/`PaymentLinkService` (hash-only
  token custody — a raw token exists at exactly THREE egress points, the one-time mint/regenerate
  response, the send-time email body, and the landing page's Pay-form `action` attribute, and
  never touches a database, queue, log, or audit row),
  one-active-link-per-order enforced transactionally, a lease-governed TTL
  (`commerce.payment_links.ttl_days`, default 7, clamped 1–30) with a fail-closed
  provider-session-exposure boundary (once a hosted gateway URL has ever been returned, automatic
  stock release/cancellation is blocked until the order is paid or an operator explicitly accepts
  late-payment risk — Paystack cannot prove a prior session dead, so renewal is unavailable in v1 by
  design, not oversight), and a two-phase `initiateByToken()` that runs no provider/network I/O
  inside a database transaction or while row locks are held. **Thallo** binds the
  `PaymentLinkPublicUrlProvider`/`PaymentLinkReturnUrlProvider` host seams, ships the pay-only
  `GET /checkout/pay/{token}` landing page (`no-store`/`no-referrer`/`noindex`, zero third-party
  assets) with signed, non-authorizing return/cancel receipts (`PaymentLinkReturnSigner`, distinct
  purposes, `hash_equals()` verification, no fallback key), and the pack-owned send surface
  (`POST /v1/admin/commerce/orders/{uuid}/payment-link/send`, required `Idempotency-Key`) with its
  own idempotent delivery ledger (`thallo_commerce_payment_link_deliveries`: a replayed
  same-key-and-fingerprint request re-answers the recorded outcome without a raw URL or another
  send attempt; a crashed `processing` row times out to `indeterminate` after
  `thallo-commerce.payment_links.delivery_processing_stale_seconds`, default 300s) and a dedicated
  synchronous `PaymentRequestMailer` (never `NotificationService::send()`, which would persist the
  token). The admin SPA's order-detail "Payment link" card gates Create/Regenerate/Revoke/Send on
  order origin, status, recipient email, the `payment_request` template toggle, and rich-email-channel
  availability; renders the one-time URL exactly once with custody enforced even against Pinia
  Colada's own mutation cache (plain awaited functions instead of `useMutation()` — an entry there
  would have parked the URL/token past "Hide" and unmount in the global `_pc_mutation` store); and
  carries the post-exposure warning copy plus Paystack honesty (no revive/invalidate claims —
  recovery is mark-paid or a risk-acknowledged cancel/recreate). **Task 14 artifact regeneration:**
  `docs/openapi.json` regenerated (cache-deletion first per the known
  `OpenApiGenerator::obtainRouter()` stale-cache bug, two consecutive cache-deleted runs verified
  byte-identical) to document the four new shop-side `/checkout/pay/*` routes and the pack-owned
  send route, which moved out of `AdminOpenApiGateTest`'s `AWAITING_SPEC_REGENERATION` carve-out
  (now empty again) into `PACK_OWNED_ROUTES`; `admin/src/api/schema.d.ts`/`core-schema.d.ts`
  regenerated from the new spec; and `commercePaymentLinks.ts`'s four admin calls (status read,
  mint, revoke, send) migrated from raw `authFetch` to the typed `client`, preserving the
  token-free `normalizeLink()` closed projection and every custody guarantee — the unmocked
  `orderPaymentLinkCustody.spec.ts` (converted to dynamic-importing the card per test, since the
  typed client captures `globalThis.fetch` once at construction rather than per call) and the
  module/card specs all stayed green throughout. The shop-side public `/checkout/pay/*` routes
  deliberately stay on raw `fetch` — no admin auth client applies to an unauthenticated landing
  page. **Follow-ups** (recorded above, out of scope for this cycle): Paystack renewal pending a
  provider-confirmed death signal, a generic provider-session invalidation/status seam, SMS/WhatsApp
  delivery channels, and link analytics (deliberately excluded); guest self-service custody for
  admin orders is unchanged from cycle 2's existing follow-up entry. Also surfaced and recorded
  during this cycle's review (pre-existing, out of scope for it): a tenant purge/adoption gap for
  `thallo_commerce_product_slugs`/`thallo_commerce_checkout_attempts`, and `composer test`'s
  assertion-total nondeterminism.
  [spec](superpowers/specs/commerce/2026-08-11-payment-links-design.md) ·
  [plan](superpowers/plans/2026-08-11-payment-links.md).

- [x] **Admin order creation (cycle 2: walk-in draft orders + one-click complete sale)** — shipped
  2026-08-10 — a manual order-creation surface for admin-side (walk-in/counter) sales, built on
  a dedicated engine-level draft state machine so an in-progress sale is never a half-written
  live order: `glueful/commerce` v1.10.0 ships the `draft` order status with a single-authority
  `transition()`/`DraftCleanupService` boundary (drafts refuse every ordinary lifecycle
  transition; only `finalize`/`cancel` touch them), a tenant-safe
  `DraftFinalizationService::finalize()` preflight-then-one-transaction path (CAS on
  `draft_revision`, per-line variant-currency drift guard, shared purchasable-line resolver reused
  from checkout so admin and storefront can never diverge on what counts as sellable), and the
  `AdminOrderDraftController` REST surface (create/read/list, customer/mode/address/shipping/
  discount updates, line add/update/delete, recalculate, cancel, finalize) mounted through
  `AdminRouteCatalog`. Thallo adds `POST /v1/admin/commerce/orders/{uuid}/complete-sale`
  (app-owned, same posture as the cycle-1 orders search/export/payments routes) — draft-blind
  (a draft uuid 404s here just like the cycle-1 routes; finalize is a separate, earlier operation)
  and only ever runs against an ALREADY-FINALIZED in-store order still `pending_payment`, chaining
  the engine's `mark-paid` then `fulfill` in one call, returning one of five typed outcomes (spec
  §2.8) the SPA renders as a RESULT rather than a blank failure. The
  admin SPA's walk-in order workspace (`admin/src/pages/commerce/orders/`) drives the whole draft
  lifecycle against real wire-envelope error normalization (idempotency-key rotation on retry,
  resilient finalize custody, a drafts list view). **Task 16 artifact regeneration:** the
  complete-sale route pair moved out of `AdminOpenApiGateTest`'s
  `AWAITING_SPEC_REGENERATION` carve-out into `PACK_OWNED_ROUTES` and `docs/openapi.json` was
  regenerated (two-run byte-identity verified) to document it, the walk-in draft endpoints, and
  the three cycle-1 orders endpoints (`search`/`export`/`{uuid}/payments`) that had been
  awaiting regeneration since that program; `admin/src/api/schema.d.ts` +
  `admin/src/api/core-schema.d.ts` were regenerated from the new spec, and
  `commerceOrderSearch.ts`'s `search` call, `commerceOrders.ts`'s payments call, and
  `commerceDrafts.ts`'s draft CRUD/lifecycle + `complete-sale` calls all migrated from raw
  `authFetch` to the typed `client`, behavior-preserving (the Task 14 error-envelope handling via
  `responseError()`/`toApiError()` verified equivalent on the typed path). `downloadOrdersCsv()`
  deliberately stays on raw `fetch()` — it inspects a 422 JSON body before ever calling
  `res.blob()`, a shape the typed client's single `parseAs` per call can't express without
  duplicating that dance. **Follow-ups** (recorded below, out of scope for this cycle): Payvia
  payment links + customer emailing for admin orders, guest self-service access custody,
  account-attached digital admin orders, marketplace-partitioned admin orders, audited per-line
  price override, the in-store counter-sale zero-tax question, plus four smaller engine/SPA
  defects surfaced along the way (drafts-list line-count hydration, a typed not-found exception
  for `OrderRepository::transition()`, a framework `OpenApiGenerator::obtainRouter()` stale-cache
  bug, and a latent `DraftFulfillmentCard` `USelect` crash).
  [spec](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) ·
  [plan](superpowers/plans/2026-08-09-admin-order-creation.md).
- [x] **Platform payments settings (Payvia credential ownership moved to the platform)** —
  shipped 2026-08-08 — Payvia gateway credentials (default gateway, per-gateway enable +
  secret/webhook keys) are now APP-OWNED and platform-scoped over the unscoped system channel
  (`App\Settings\PlatformPayviaSettingsOverride` / `PlatformPaymentSettingsStore`), replacing the
  retired commerce-pack `SettingsStorePayviaOverride`: resolution is independent of ambient
  tenant context (a workspace's own `settings` rows can never shadow the platform merchant) and
  of the `thallo.commerce`/`thallo.subscriptions` capabilities, proven end to end for BOTH the
  commerce storefront checkout and the subscriptions self-serve checkout, plus webhook signature
  verification under no tenant context at all
  (`tests/Integration/Settings/PlatformPaymentsRegressionTest.php`). A marker-gated
  (`payments.platform_credentials_migrated`), non-destructive migration command
  (`thallo:payments:migrate-platform-credentials`) provides a deployment-safe cutover: platform
  values are always preserved, a pre-retrofit unscoped or persisted-default-workspace legacy row
  is adopted only where absent, cross-workspace conflicts are refused and reported rather than
  guessed, and ciphertext moves verbatim (no re-encryption). A new neutral
  `GET/PUT /v1/admin/settings/payments` API + `Settings → Payments` admin page (platform-authority
  gated, like Workspaces) replace the retired `/v1/admin/commerce/payments` endpoints and Commerce
  Payments tab, carrying a prominent notice that every storefront order and workspace subscription
  settles through the ONE platform gateway account until Payvia ships explicit merchant
  connections (tracked above, "Workspace merchant connections"). Fulfills the enforcement-time
  obligation the commerce store-settings spec §3.6 recorded when Payvia credentials first shipped
  as a commerce-owned surface. **Doc-regeneration note:** `docs/openapi.json` was hand-spliced for
  this program's `/v1/admin/commerce/payments` → `/v1/admin/settings/payments` path move (a full
  `composer docs:openapi` regeneration would also refresh several already-stale, unrelated paths
  from earlier features); the next full regeneration will therefore show an
  unrelated-looking reordering diff for that one path — expected, not a regression.
  [spec](superpowers/specs/2026-08-05-platform-payments-settings-design.md) ·
  [plan](superpowers/plans/2026-08-05-platform-payments-settings.md).
- [x] **Subscriptions: workspace self-serve checkout + pricing blocks ↔ platform plans bridge** — shipped 2026-08-05 — workspaces can self-serve subscribe to and cancel their own platform plan, without operator involvement: `GET/POST /v1/admin/billing/{meta,checkout,cancel,checkout/abandon}` (`Thallo\Subscriptions\Http\SelfBillingController`), a `billing.manage` workspace-delegable authority disjoint from platform `tenancy.manage`, an operator kill-switch, hosted-checkout origination through `glueful/payvia` (idempotent reservation + provider session, webhook-driven activation, abandon/cancel, operator reconciliation console command), and the pricing-blocks → billing deep-link bridge (`pricing_plan.plan_key` resolves to a live checkout URL; engine/capability off degrades to the authored URL). End-to-end truth table (`tests/Integration/Subscriptions/SelfServeCheckoutTruthTableTest.php`) pins every spec §6 row: capability off (routes 404), engine off (structured 409 / meta 200+state), switch off (checkout refuses, cancel still works), missing `billing.manage` (denied), plan not purchasable, an active subscription, a live origination (same-key resume vs. different-key `checkout_pending`), a `projection_rejected` guard, a DISTINCT `late_settlement_conflict` fixture (blocked guard + rejected receipt columns survive untouched), Paystack abandonment (unsupported against the real driver), and a provider webhook failing mid-lane (ledger stays `provider_observed`, claim released, retry completes without duplicate ownership). Companion `glueful/payvia` **2.5.0** + `glueful/subscriptions` **2.2.0** (checkout origination ledger, strict-lane acknowledgement contract, projection outcome reporting). [spec](superpowers/specs/2026-08-03-workspace-checkout-design.md) · [plan](superpowers/plans/2026-08-03-workspace-checkout.md).
- [x] **Subscriptions (workspace SaaS billing, Phase 2)** — shipped 2026-08-03 — `packages/thallo-subscriptions`: platform plans admin API, per-workspace billing (plan set/cancel, entitlement overrides, provider-managed refusal), a lazy three-state `EngineGateway` (capability off → this pack's `/v1/admin/subscriptions/*` routes 404 + hidden from the capabilities list; engine off → visible degraded shell reporting `engine_disabled`; both on → operational — pinned end-to-end by a capability/engine truth table), fail-closed tenant-purge integration, admin SPA module. Platform authority is total: the engine's OWN native `/subscriptions/plans*` mounts (which `glueful/subscriptions` loads unconditionally behind a raw `subscriptions.plans.manage` permission, outside every one of those gates) are pre-empted by the pack provider and answer 404 in every capability/engine state — spec §3's rejection of tenant-grantable plan administration holds for the whole app, not just for this pack's own routes. Companion `glueful/subscriptions` **2.1.0** (four additive upstream seams). [spec](superpowers/specs/2026-08-03-thallo-subscriptions-design.md) · [plan](superpowers/plans/2026-08-03-thallo-subscriptions.md).
- [x] **Multi-tenancy** — shipped 2026-07-11–12 — the full arc, far beyond the original additive-retrofit line: SP1 foundation (`tenant_uuid` retrofit, widened uniques, `BelongsToTenant`, raw-query scoping) → SP2 resolution + tenant management → SP3 membership×RBAC → **Bucket 1** lifecycle gaps (workspace-manager role, two-phase deletion + host-cooldown, background domain re-verification) → **Bucket 2** (collections tenancy, per-tenant roles / matrix overrides, public self-serve signup) → the control-plane/enforcement **provider split** shipped as `glueful/tenancy` **2.0.0**. Index: [LIFECYCLE-GAPS-README](superpowers/specs/multi-tenancy/LIFECYCLE-GAPS-README.md); specs/plans under `superpowers/{specs,plans}/multi-tenancy/`. **Loose ends (housekeeping, not feature gaps):** public-signup (2B) is implemented + verified but **HELD/uncommitted** along with the admin-settings polish; one tenancy-on `BlobPublicUrlProviderTest` failure remains unreproduced (passes in every isolated config) pending the exact suite-invocation command.
- [x] **Form block** — shipped 2026-07-09 — generic `form` block (contact preset): sealed-descriptor model, stored + best-effort-emailed submissions, spam guard chain, admin Submissions area + CSV, delivery mode (store+email / email-only), selectable submit-button style. [spec](superpowers/specs/2026-07-09-form-block-design.md) · [plan](superpowers/plans/2026-07-09-form-block.md).
- [x] **Rendered delivery (V2)** — shipped 2026-07-02/03 — navigation pack, render core, render caching, listing/archive pages, preview-through-theme + preview sessions, term index pages, DB-edited templates, page/block builder. [V2_DESIGN.md](V2_DESIGN.md).
- [x] **Capability packs** — seo, collections, search, analytics, workflow, navigation, importers — built as removable packs on the composable-core seams.
- [x] **POST-V1 batch** — shipped 2026-06-17 — destructive-schema backfill, field localization, version pruning, per-locale RBAC, SEO/routing, scheduled publish. [POST_V1.md](POST_V1.md).
