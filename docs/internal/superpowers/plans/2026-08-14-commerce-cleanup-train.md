# Commerce Cleanup Train Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One pass that ships the draft-artifact delete capability (user feature) and clears every parked `S`-sized OUTSTANDING.md follow-up plus the payvia `M` pair — three small releases (payvia 2.7.0, commerce 1.12.0, framework 1.79.0) and a Thallo wave.

**Architecture:** Same three-repo train shape as the payment-links program: payvia tasks → commerce tasks → framework task → ONE human publication sitting (all three releases) → Thallo repin gate → Thallo wave → final review. Engine work programs against contracts; only the Thallo repin needs published artifacts.

**Tech stack:** Glueful framework (NOT Laravel); Vue 3 + TS admin SPA (pnpm); PHPUnit; per-repo gates as recorded in the ledger baselines.

## Global Constraints

- Every item's authoritative description is its `docs/internal/OUTSTANDING.md` entry (copied into the task briefs below); when a brief and OUTSTANDING disagree, OUTSTANDING governs and the discrepancy is raised.
- Strict TDD per task; FOREGROUND gates with explicit timeouts; never concurrent across repos or PHP/SPA stacks; never backgrounded.
- NO AI-attribution trailers on any commit.
- Deletion capability is guarded structurally: hard-delete is legal ONLY for rows with `order_number IS NULL` AND status `canceled` (never-finalized artifacts — no payments/invoices/stock/links can exist). Everything else ⇒ typed 409. Children purge in the same transaction.
- Canceled draft artifacts REMAIN VISIBLE in the orders list (user decision 2026-08-14 — the earlier hide-the-leak plan is dropped); the delete action is their remedy.
- Thallo PHP suite gate is tests+skips (assertion totals wobble ±2 — known, itself a task here).
- Publication is the human's; release-preps stop before push/tag. Framework release follows the framework repo's `.claude/skills/release` skill (docs site + api-skeleton companions).

---

### Task 1 (PAYVIA): confirm-route hardening — payable binding + settle-before-dispatch

**Files:** Modify `src/Services/PaymentService.php` (+ tests)
**OUTSTANDING items (verbatim source of truth):** the `/payvia/payments/confirm` payable-binding entry and the `confirmAndRecord()` spurious-late-rejection entry.

- [ ] TDD: (a) confirmAndRecord() must refuse (typed conflict) a caller-supplied payable that disagrees with the `(tenant, gateway, reference)` intent row's own `payable_type`/`payable_id` — an equal-amount cross-order attribution is the reproduced attack; unknown-reference behavior unchanged. (b) Reorder/detect so the route's own dispatch does not record `payment_late_rejected` when its own `recordVerifyEvent()` chain already settled this reference (webhook strict lane) — the manual-recovery timeline stays clean; a GENUINE late second settlement still records it. Full payvia gates. Commit `fix(payments): intent-bound payable attribution and settle-aware confirm ordering`.

### Task 2 (PAYVIA): intent lifecycle — orphan expiry sweeper + reuse amount revalidation

**Files:** Create sweeper (console command per repo convention + service method); Modify `src/Services/PayviaPaymentCollector.php`, `src/Repositories/PaymentIntentRepository.php` (+ config keys, tests)
**OUTSTANDING item:** the orphan-intent expiry/sweeper + ensure-live amount-revalidation entry.

- [ ] TDD: (a) `payment_intents` sweeper — `initializing`/`open` rows older than config `payvia.intents.stale_after_days` (default 30, clamp 1–365) transition to `failed` via the retire CAS (frees the idempotency port); batched; per-row CAS; console command wired like existing payvia commands; NEVER sweeps rows whose payable still legitimately holds them (definition: age is the only criterion — document that a swept-then-returning payer converges via ensure-live's create path). (b) ensure-live confirmed-live REUSE revalidates the payable's CURRENT amount/currency against the intent row's stored amount/currency; mismatch ⇒ supersede + fresh attempt (new session at the new amount), never serve the stale URL. Full payvia gates. Commit `feat(intents): stale-intent sweeper and amount-revalidated session reuse`.

### Task 3 (PAYVIA): 2.7.0 release-prep (parked for the human)

- [ ] Land Tasks 1–2 on payvia `dev`; changelog + version bumps per v2.6.0's convention; full suite on the release commit. STOP — no push/tag.

### Task 4 (ENGINE): cycle-2 leftovers + paid-CAS-loser idempotency

**Files:** Modify `src/Http/Admin/AdminOrderDraftController.php`, `src/Orders/OrderRepository.php`, `src/Http/Admin/AdminOrderController.php`, `src/Orders/OrderPaymentService.php` (+ tests)
**OUTSTANDING items:** drafts-list line-count hydration; typed not-found from `transition()`; draft-blind `markPaid()` precheck; paid-CAS-loser 500.

- [ ] TDD: (a) drafts index hydrates real line counts; (b) `transition()` throws a typed not-found (new exception class) instead of bare RuntimeException when the row vanished — callers mapped; (c) `markPaid()` gains the same draft-blind `order()` precheck its siblings use (404 not 409 for draft uuids); (d) the loser of the `pending_payment → paid` CAS answers idempotently (recognize the desired end state was reached: return the paid outcome / take rejectLatePayment path) instead of a bare 500 — race-tested per repo convention. Full engine gates. Commit `fix(orders): draft leftovers, typed not-found, idempotent paid-CAS loser`.

### Task 5 (ENGINE): draft-artifact deletion — endpoint + sweep purge phase

**Files:** Create `src/Http/Admin/AdminOrderArtifactController.php` (or fold into the draft controller per repo taste); Modify `src/Orders/DraftCleanupService.php`, `src/Http/Routing/AdminRouteCatalog.php` (+ tests, route fixtures)
**Feature (user-approved design, 2026-08-14):** see Global Constraints deletion guard.

- [ ] TDD: (a) `DELETE /orders/{uuid}/artifact` catalog entry (manage mode): legal ONLY for `order_number IS NULL` AND `status='canceled'`; deletes children (`commerce_order_lines`, `commerce_order_events`, `commerce_order_draft_attempts`) + the row in ONE transaction; typed 409 (`order_not_deletable`) for numbered/live rows incl. active drafts; non-revealing 404 for unknown/cross-tenant; route-inventory ratchets updated per convention. (b) `DraftCleanupService` gains a purge phase: canceled numberless artifacts older than `commerce.orders.draft_purge_days` (default 30, clamp 1–365, measured from `updated_at`) are hard-deleted by the same transactional purge, batched, wired into the existing sweep command. Full engine gates. Commit `feat(orders): draft-artifact deletion endpoint and purge sweep`.

### Task 6 (ENGINE): 1.12.0 release-prep (parked)

- [ ] Land Tasks 4–5 on engine `dev`; changelog per 1.11.0 convention; full suite. STOP — no push/tag.

### Task 7 (FRAMEWORK): OpenAPI stale-cache fix + 1.79.0 release-prep (parked)

**Files:** Modify `src/.../OpenApiGenerator.php` (+ tests)
**OUTSTANDING item:** `OpenApiGenerator::obtainRouter()` stale-cache re-registration bug.

- [ ] TDD: reproduce (routes cache file present ⇒ named-route collisions on generation), fix (fresh router for generation, or cache-aware skip of re-registration), regression test. Full framework gates. Release-prep 1.79.0 per the framework's own release skill (framework files; docs site + api-skeleton at the sitting). STOP — no push/tag. THEN: **THE HUMAN PUBLICATION SITTING** — payvia 2.7.0, commerce 1.12.0, framework 1.79.0 (docs/skeleton companions per the release skill). Resume only on confirmation.

### Task 8 (THALLO + GATE): triple repin

- [ ] Repin payvia ^2.7, commerce ^1.12, framework ^1.79; `COMPOSER_PROCESS_TIMEOUT=0 composer update` those three; verify vendor surfaces; full Thallo gates; only route-catalog/allowlist/openapi-regen fallout for the new delete entry may be fixed in-commit. Commit `chore(commerce): repin cleanup-train releases with artifact-delete allowlist`.

### Task 9 (THALLO SPA): delete action + SPA parked fixes

**Files:** Modify `admin/src/pages/commerce/orders/components/OrdersTable.vue`, `admin/src/queries/commercePaymentLinks.ts`, `admin/src/queries/commerceOrders.ts`, `admin/src/api/errors.ts`, card/send components (+ specs)
**OUTSTANDING items:** replayed-failure error_code surfacing; `apiErrorDetails()/apiErrorCode()` structural migration; pristineRequests WeakMap cleanup; current-send stale-status recheck (SPA half: refetch status immediately before current-send). Plus the delete feature UI.

- [ ] TDD: (a) trash action (actions column) ONLY on numberless canceled rows; confirm dialog with the approved copy; calls the new engine endpoint; list refreshes; numbered rows never render it. (b) replayed-failure envelopes surface `error_code` in the card. (c) `apiErrorDetails`/`apiErrorCode` migrate to structural checks (instanceof kept for typing only) — existing consumers' specs stay green. (d) pristineRequests entry deleted once consumed. (e) current-send refetches link status immediately before submitting; stale ⇒ the 409 flow. SPA gates + Thallo PHP gates. Commit `feat(admin): draft-artifact delete and custody-hardened send flow`.

### Task 10 (THALLO PHP): pack parked fixes + docs

**Files:** Modify `packages/thallo-commerce/src/Purge/CommercePurgeHandler.php`, `.../Adoption/CommerceAdoptionContributor.php`, registry + fixtures; `AdminPaymentLinkSendController.php`; `config/logging.php`; listener/subscriber for LatePaymentRejected; README (+ tests)
**OUTSTANDING items:** purge/adoption gap (product_slugs, checkout_attempts); send-controller status-map honesty (server half of the error_code item); receipt `{signature}` sensitive-path templates; LatePaymentRejected operator-notification subscriber; README "two egress points"→three.

- [ ] TDD: (a) both missing tables registered in purge/adoption/registry + fixture; (b) send-controller status map covers the full delivery vocabulary or documents its subset honestly (pick per OUTSTANDING wording); (c) add the two receipt sensitive-path templates; (d) a LatePaymentRejected subscriber records an operator-visible notification per the pack's notification idiom (email NOT required — in-admin surface acceptable; scope to the cheapest honest surfacing); (e) README custody prose says three egress points. Full Thallo gates. Commit `fix(commerce): pack purge coverage, honest send map, late-payment visibility`.

### Task 11 (THALLO): stragglers + assertion hunt + final review

- [ ] (a) DraftFulfillmentCard USelect empty-string placeholder fix (SPA, cycle-2 item). (b) Time-boxed assertion-nondeterminism hunt: bisect the conditional assertion source (run targeted suites repeatedly); fix if found, otherwise update the OUTSTANDING entry with findings. (c) Check OUTSTANDING.md: tick every entry this train closed. (d) Final cross-repo review (fable) over the train's four ranges; one fix wave max; then superpowers:finishing-a-development-branch.
