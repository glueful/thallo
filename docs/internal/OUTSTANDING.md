# Outstanding Features — Thallo tracker

> A **living checklist** of what's left to build. Unlike [NEXT.md](NEXT.md) (a stable
> pointer page), this file is meant to be edited as work lands — tick items off, link the
> spec/plan/commit, and move them to **Recently shipped**.

**Last reconciled:** 2026-08-10 (against the working tree, not just `NEXT.md`).

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
- [ ] **Admin order creation: Payvia payment links + customer emailing** — `M` — one flow covering link custody, expiry, guest access, and delivery for admin-born orders. **Home:** [admin-order-creation spec §4](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) — follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin order creation: guest self-service access custody** — `S` — guest self-service access custody for admin-born orders. **Home:** [admin-order-creation spec §4](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) — follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin order creation: account-attached digital orders** — `M` — download delivery for admin-born digital orders defined by account custody. **Home:** [admin-order-creation spec §4](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) — follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin order creation: marketplace-partitioned admin orders** — `M` — seller-order split + ledger at finalize. **Home:** [admin-order-creation spec §4](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) — follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin order creation: audited per-line price override** — `S` — comps/B2B price override capability, audited. **Home:** [admin-order-creation spec §4](superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md) — follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin order creation: in-store counter-sale tax** — `S` — in-store admin sales compute zero tax today; a real answer needs a store-address decision still pending. **Home:** _(no design yet)_ — follow-up on shipped admin order creation (cycle 2).
- [ ] **`glueful/commerce` engine: `AdminOrderDraftController::index()` line-count hydration** — `S` — the drafts list index never hydrates `$lines`, so the admin drafts list renders an honest-but-empty item count per row instead of the real line count; fix upstream and the SPA's non-committal render resolves itself. **Home:** _(no design yet)_ — follow-up on shipped admin order creation (cycle 2).
- [ ] **`glueful/commerce` engine: typed not-found exception from `OrderRepository::transition()`** — `S` — a vanished order during `transition()` currently surfaces as a bare `RuntimeException` (logged 500, message-only distinguishable); a typed not-found exception would let callers classify it correctly. **Home:** _(no design yet)_ — follow-up on shipped admin order creation (cycle 2).
- [ ] **`glueful/framework` engine: `OpenApiGenerator::obtainRouter()` stale-cache re-registration bug** — `S` — when `storage/cache/routes_dev.php` exists, generation re-registers routes onto a cache-populated router, producing named-route collisions; workaround today is deleting the gitignored cache before regenerating. **Home:** _(no design yet)_ — framework bug, follow-up on shipped admin order creation (cycle 2).
- [ ] **Admin SPA: `DraftFulfillmentCard` `USelect` empty-string placeholder** — `S` — a reka-ui crash when the empty-string placeholder option is actually mounted (pre-existing, latent, disclosed at discovery). **Home:** _(no design yet)_ — follow-up on shipped admin order creation (cycle 2).

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

> Move ticked items here (newest first) with their ship date + spec link, so the sections
> above stay focused on what's left.

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
  (app-owned, same posture as the cycle-1 orders search/export/payments routes) — a single
  one-click action that finalizes a draft AND marks it paid/fulfilled in one call, returning one
  of five typed outcomes (spec §2.8) the SPA renders as a RESULT rather than a blank failure. The
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
