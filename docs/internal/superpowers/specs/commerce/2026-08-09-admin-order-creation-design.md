# Admin Order Creation (Walk-In First) — Design

**Date:** 2026-08-09
**Cycle:** Orders program cycle 2. Engine-native draft orders in `glueful/commerce` (ships as **v1.10.0** with four riders), consumed by Thallo (complete-sale orchestration + SPA draft workspace).
**Posture:** ONE publication dependency — commerce v1.10.0 via the human-run PR/tag flow (repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`), Thallo repins root + pack behind an explicit publication gate. Engine-native ownership is deliberate: cart coupling and app-owned duplicate draft models are rejected.
**Primary scenario:** in-store/walk-in purchases (POS-style). Phone/delivery orders supported; storefront checkout untouched.

---

## §1 Rulings

1. **The parked 1.6.0-candidates sketch governs**, its anti-goals affirmed: no free status dropdown, no hand-editable totals, no stock bypass. Any deliberate override (per-line price for comps/B2B) is a separate audited capability, deferred.
2. **Draft → finalize model.** Finalize is the single authority for: current prices/discounts/shipping/tax/totals; stock validation and claiming; the transition to `pending_payment`; lifecycle events and payment eligibility; idempotency and concurrent-finalization protection.
3. **Payment this cycle: mark-paid only** (cash/bank/POS via the existing guarded transition). Payvia payment links + customer emailing are ONE recorded follow-up flow, not partially added now.
4. **Walk-in identity: everything optional** — `email`, `phone`, `customer_name`, `user_uuid` all optional; a fully anonymous "Walk-in customer" is valid. Placeholder emails are never invented. Email alone never establishes ownership; phone is contact info only (never access, ownership, or an implicit account identifier); an attached `user_uuid` is the only account-history authority. No guest token is minted; guest self-access custody joins the payment-link follow-up.
5. **Explicit `fulfillment_mode`:** `in_store` (default; no addresses, `shipping_total = 0`, no method) | `delivery` (required address fields + a server-quoted shipping METHOD — never a hand-entered amount). Switching `delivery → in_store` clears the shipping selection and recalculates. Server-enforced.
6. **Complete-sale is server-orchestrated** (Thallo pack endpoint) chaining the EXISTING guarded transitions mark-paid → fulfill for finalized `in_store` orders in `pending_payment` only, returning structured per-step results; the UI never blind-retries.
7. **Isolation audit is a first-class deliverable**: drafts live in `commerce_orders`, so every existing reader is enumerated and ruled on (§2.2). Invariants: drafts excluded from storefront history, reports, payment/refund surfaces, notifications, currency locks, and ordinary order listings unless explicitly requested; drafts have no order number and claim no stock; nullable email audited across every consumer — no email means no notification attempt; abandoned drafts get explicit cancel plus bounded cleanup; origin recorded without weakening normal lifecycle events.
8. **Numbering honesty:** abandoned drafts never consume order numbers (allocation happens at finalize). Gap-free failed finalization is claimed ONLY because the allocator is being fixed and proven transactional (§2.5.9); absent that proof the spec would state the gap possibility.
9. **Digital and marketplace-partitioned lines are out of scope**: rejected at line mutation (early feedback) AND at finalize (authority). Account-attached digital admin orders and partitioned admin orders are recorded follow-ups.
10. **Riders bundled** (each its own task/commit, sequenced): storefront projection leak fix → stock NOT NULL hygiene → OpenAPI path canonicalization → draft feature → v1.10.0 publication → dual Thallo repin → ONE artifact regeneration (openapi.json + PACK_OWNED_ROUTES + SPA schema) covering cycle 1's three endpoints and cycle 2's surface.
11. All engine class/API names referenced here were mapped against v1.9.1 sources; the plan re-verifies each at implementation time.

## §2 Component contracts

### 2.1 State machine + dedicated finalize transition (engine)

- `OrderStateMachine::ALLOWED` gains `draft → [pending_payment, canceled]`; every existing pair untouched; `draft` enters no other transition.
- **`OrderRepository::transition()` REJECTS `draft → pending_payment`.** A dedicated CAS method `OrderRepository::finalizeDraftTransition()` performs that pair and is callable only by `DraftFinalizationService`, after recalculation and stock claim. No other caller can bypass the chokepoint.
- Draft cancellation (explicit or cleanup) uses `draft → canceled` via a draft-specific path that emits **draft-specific audit records only** (`draft_created`, `draft_canceled`, `draft_expired`): no customer mail, no payment/fulfillment/marketplace listeners, no ordinary order-canceled side effects.
- Cleanup: `commerce.orders.draft_ttl_days` (default **30**), bounded batches, idempotent CAS cancellation, deterministic clock injection in tests. Runs on the existing expiry cron command; explicit cancel endpoint exists regardless.

### 2.2 Isolation audit (engine + Thallo, its own task before any endpoint ships)

- Enumerate every `commerce_orders` reader in the engine AND Thallo: storefront `mine`/`show`, admin `orders.index`, Thallo search/export/payments endpoints, reports/aggregations, refunds, customer aggregation, expiry, mail listeners, webhooks, currency-lock guard, fulfillment rollups, marketplace partitioning. Each gets an explicit ruling: **exclude drafts** (default) or include-on-request (the drafts listing only).
- **Structural isolation:** centralize finalized-order predicates where possible (a shared scope/helper), then use the seeded-draft matrix as the regression ratchet.
- The matrix explicitly proves cycle-1's search/export/payments endpoints cannot **resolve** drafts by uuid — not merely that drafts are absent from default lists.
- Currency: drafts do NOT trigger the store currency lock; each draft snapshots its creation currency (the `currency` column); finalize on a changed store currency returns a typed conflict requiring cancel/recreate.
- `origin` is closed and NOT NULL (`storefront | admin`), existing rows backfilled `storefront`; recorded without altering normal lifecycle event payloads.

### 2.3 Draft API (engine `AdminRouteCatalog`, manage mode, orders domain)

- `orders.drafts.store` — `POST /orders/drafts` (optional initial customer block + `fulfillment_mode`, default `in_store`).
- `orders.drafts.index` / `orders.drafts.show` — the ONLY draft-inclusive listing.
- `orders.drafts.update` — customer block (`email?`; `phone?` stored as `phone_normalized` strict E.164 + `phone_display` trimmed input; `customer_name?`; `user_uuid?`), `fulfillment_mode` (switch semantics per Ruling 5), addresses, shipping METHOD id (`delivery` only; re-quoted, never an amount), discount code (validated, not consumed until finalize).
- **User attachment:** `user_uuid` must resolve to an active user via the user provider; if `email` is ALSO supplied and mismatches the resolved user's email, the update is **rejected** (409-class typed error) — never silently linked.
- `orders.drafts.lines.store|update|destroy` — stable line UUIDs in `commerce_order_lines`; each mutation re-resolves through the **shared purchasable-line resolver** (§2.4) and stores an advisory snapshot; digital or marketplace-partitioned items ⇒ typed rejection at mutation time.
- `orders.drafts.recalculate` — **the explicit drift-acceptance operation**: refreshes advisory line snapshots to current catalog values, recalculates totals (pricing, discount preview, tax, shipping re-quote), and CAS-increments `draft_revision`. This is how a price-drift finalize conflict is cleared.
- `orders.drafts.finalize` — §2.5. `orders.drafts.cancel` — draft-specific event only.
- **Revision custody:** every customer/line/shipping mutation CAS-increments `draft_revision`; finalize accepts the expected revision and refuses stale state (typed conflict → reload).
- **Eligibility surface:** the admin product/draft projections expose an authoritative `admin_draft_eligible: bool` + closed `reason` code (`digital | marketplace | unavailable`), OR the SPA calls the line endpoint and renders its typed rejection — the client never reconstructs eligibility.

### 2.4 Shared purchasable-line resolver (engine refactor)

One extracted resolver (from `CartService::pricedLines()`'s variant/availability/addon/price authority) consumed by BOTH storefront checkout and draft mutations/finalize. Two similar implementations calling the same repositories is expressly insufficient — drift is the failure mode this guards.

### 2.5 `DraftFinalizationService::finalize()` — one transaction

1. Idempotency claim FIRST (before any stock change) in `commerce_order_draft_attempts`: same key + same fingerprint ⇒ return the finalized order (no re-execution); same key + different fingerprint ⇒ 409; concurrent different keys ⇒ one CAS winner.
2. Load draft, verify `draft` status + expected `draft_revision` + currency snapshot vs store currency (each mismatch a distinct typed conflict).
3. Re-resolve every line via the shared resolver (availability, price, addons); recheck digital/marketplace exclusions; drift ⇒ typed per-line conflict list, abort, draft untouched.
4. Recalculate all totals server-side; stored advisory totals discarded.
5. Claim stock per tracked line via `StockRepository::decrement()` (atomic); failure ⇒ typed per-line stock conflict, rollback.
6. Allocate `order_number` via the FIXED `OrderNumberGenerator` (savepoint-isolated retry — the current catch-without-savepoint can poison a PostgreSQL transaction; a two-concurrent-finalizers first-order test is the precondition for claiming transactional numbering).
7. Atomically replace advisory line snapshots with authoritative snapshots (same stable line UUIDs — no duplicate insertion), write totals + `placed_at`, record stock movements, consume discount. Anonymous discount identity: ordinary discounts use the draft/order UUID; `once_per_buyer` without user/email ⇒ 422 at application AND finalize.
8. Dedicated CAS `draft → pending_payment`; `finalized` audit row INSIDE the transaction.
9. **`OrderPlaced` dispatches only after commit** (matching `CheckoutService.php:231` semantics): rollback ⇒ zero dispatch; fresh finalize ⇒ exactly one; idempotent replay ⇒ no redispatch. Mail listener consults `origin` + email presence: no email ⇒ short-circuit before the mailer; the `commerce.order_confirmation` toggle governs admin-origin sends.
10. Failure at any step: rollback leaves an editable `draft` with zero stock/event/transition side effects.

### 2.6 Schema (engine v1.10.0 migrations)

- `commerce_orders`: `order_number` nullable — the EXISTING `(tenant_uuid, order_number)` unique index is retained (SQLite and PostgreSQL both permit multiple NULLs while rejecting duplicate non-null values; tested on both); `email` nullable; `guest_token_hash` nullable (anonymous admin orders mint no credential; access tests prove null grants no guest access); new: `phone_normalized` (nullable, strict E.164), `phone_display` (nullable, trimmed operator input), `customer_name` (nullable), `origin` (NOT NULL, `storefront|admin`, backfill `storefront`), `fulfillment_mode` (NOT NULL, `in_store|delivery`, backfill `delivery` — documented as conservative compatibility meaning "not eligible for automatic in-store completion," not a claim that historical orders shipped), `draft_revision` (int NOT NULL default 0).
- New table `commerce_order_draft_attempts` (engine-owned; `CheckoutAttemptAuthority` is NOT reused — it is a host seam whose completion shape requires an order reference and raw guest credential): tenant-scoped `key`, `fingerprint`, draft/order uuid, state, timestamps. Registered in tenant purge/adoption inventory.
- Rider: `commerce_stock.quantity NULL → 0`, `tracked NULL → false` backfill, then NOT NULL — preserving current runtime behavior; both drivers.
- **Projection/PII ratchet:** `customer_name`/`phone_*`/`draft_revision`/attempt internals absent from storefront/public projections by default; admin draft/detail projections explicitly allow name + phone; attempt fields never leave their repository.
- **Migration gates:** fresh install AND real v1.9.1 upgrade, SQLite + PostgreSQL: multiple null order numbers, nullable email/token, backfills, tenant purge/adoption, rollback safety.

### 2.7 Riders (engine, sequenced before the draft work where noted)

1. `accessCheckedOrder()` storefront wire-projection leak fix + closed-field regression test (first).
2. Stock NOT NULL migration (second).
3. OpenAPI path canonicalization (before any artifact generation).
4. **`OrderFulfillmentService` extraction**: non-partitioned fulfillment logic moves out of `AdminOrderController::fulfill()` into a service peer of `OrderPaymentService::markPaid()`; the controller AND Thallo's complete-sale coordinator both delegate. The pack never reproduces controller transaction/event logic.

### 2.8 Thallo: complete-sale + allowlist

- Allowlist gains the `orders.drafts.*` keys; mount-parity fixture regenerated.
- `POST /v1/admin/commerce/orders/{uuid}/complete-sale` (`commerce.manage`): resolve tenant-scoped order FIRST — unknown/cross-tenant ⇒ **404**; wrong status, non-`in_store`, or concurrent transition ⇒ **409**; malformed input ⇒ **422**. Chains `OrderPaymentService::markPaid()` then `OrderFulfillmentService` with per-step CAS/audit/events intact. Response contract:
  - mark-paid conflict/failure ⇒ 409, fulfill `skipped`;
  - mark-paid committed, fulfillment domain conflict ⇒ 409 with the refreshed PAID order + structured steps;
  - unexpected fulfillment failure ⇒ logged 500 with sanitized step error + refreshed paid order;
  - exception messages never exposed directly.
  - After partial completion, the normal guarded Fulfill action is the recovery path; Complete sale is never blindly retried.
- Concurrency: two simultaneous complete-sale calls ⇒ exactly one paid event and one fulfilled event; the loser receives a truthful conflict + current order, never duplicate side effects.

### 2.9 SPA (admin/)

- "Create order" (gated `can_manage`) → `/commerce/orders/create` draft workspace: product line picker driven by the engine's `admin_draft_eligible` + reason codes (never client-inferred); qty/addons; all-optional customer block + user search attach; fulfillment-mode toggle (in_store default; delivery reveals addresses + quoted shipping methods); server `recalculate` with advisory totals badge; discount code entry.
- Finalize success → standard order detail. Conflict renderings: per-line drift (with "Refresh prices" invoking `recalculate`), stock, revision (reload), currency (cancel/recreate), idempotency 409.
- Order detail: finalized `in_store` + `pending_payment` ⇒ primary **Complete sale** action rendering per-step results and the true partial-failure state (paid-but-unfulfilled resumes via the standard Fulfill).
- Drafts view (filtered listing) with resume/cancel; the cycle-1 orders list/search stays draft-blind.

## §3 Test matrix (distributed to plan tasks)

**Engine:** state-machine table incl. `transition()` rejecting `draft → pending_payment`; dedicated finalize CAS; finalize matrix — idempotency triple (same key+fingerprint / same key+different fingerprint / concurrent keys), revision staleness, currency conflict, per-line drift/stock/digital/marketplace conflicts, discount identity (ordinary via uuid; `once_per_buyer` 422), rollback leaves editable draft with zero side effects, after-commit dispatch triple (rollback ⇒ none; fresh ⇒ once; replay ⇒ none); two-concurrent-finalizers order-number test (savepoint fix precondition); shared-resolver parity (checkout and drafts produce identical line resolution for the same input); isolation-audit seeded-draft matrix (every §2.2 reader + cycle-1 endpoints cannot resolve drafts by uuid); draft cleanup (TTL 30, bounded batches, idempotent CAS, deterministic clock); migration gates (§2.6); rider regressions (projection closed-field test; stock backfill; canonical path ordering); `OrderFulfillmentService` parity with the previous controller behavior; null `guest_token_hash` grants no guest access; no-email finalize attempts no notification.
**Pack:** complete-sale truth table (404/409/422 discipline, per-step result shapes incl. all four §2.8 outcomes, concurrency single-winner); allowlist/mount parity.
**SPA:** draft workspace flows; eligibility rendering from engine codes; conflict renderings per type; complete-sale per-step + partial-failure resume; drafts view; draft-blind cycle-1 list.
**Artifacts:** post-repin single regeneration of openapi.json + PACK_OWNED_ROUTES + SPA schema, covering cycles 1+2.

## §4 Follow-ups (recorded, out of scope)

- Payvia payment links + customer emailing for admin orders (one flow: link custody, expiry, guest access, delivery).
- Guest self-service access custody for admin-born orders.
- Account-attached digital admin orders (download delivery defined by account custody).
- Marketplace-partitioned admin orders (seller-order split + ledger at finalize).
- Audited per-line price override capability (comps/B2B).
- Retire Thallo's temporary orders search/export at upstream filter parity (carried from cycle 1).
