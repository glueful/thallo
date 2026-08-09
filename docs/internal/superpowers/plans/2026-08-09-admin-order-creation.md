# Admin Order Creation (Walk-In First) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Engine-native draft orders with a finalize authority (walk-in-first), the four bundled riders, a Thallo complete-sale orchestration, and the SPA draft workspace — shipped as commerce v1.10.0 behind a publication gate.

**Architecture:** Upstream first: riders in pinned sequence (projection leak → stock NOT NULL → OpenAPI canonicalization), then the enabling refactors (number-allocator savepoint fix, OrderFulfillmentService extraction, shared purchasable-line resolver), then schema, the draft state + isolation audit, the draft API, and DraftFinalizationService. Human-run v1.10.0 release gates all Thallo work: dual repin, pack isolation + allowlist + can_attach_user, complete-sale, SPA workspace + drafts view, one artifact regeneration for cycles 1+2.

**Tech Stack:** PHP 8.4 / Glueful (engine repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`; Thallo repo `/Users/michaeltawiahsowah/Sites/glueful/thallo`, pack `packages/thallo-commerce`), Vue 3 + pinia-colada (admin/), vitest, tools/runtime-browser untouched.

**Spec:** `docs/internal/superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md` — §1 rulings and §2 contracts govern verbatim; §3's matrix is distributed below. Engine file/line references were mapped against v1.9.1; every implementer re-verifies its own citations before coding.

## Global Constraints

- Repos/branches: engine work on a branch off `dev` in `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce` (currently v1.9.1); Thallo on `dev`. ONE publication gate after Task 11: if `composer update glueful/commerce` cannot resolve published v1.10.0, STOP — no path-repo or local workaround. The HUMAN runs the release (push dev, PR dev→main, merge, tag, Packagist) as with v1.9.1.
- Baselines: before Task 1, capture same-checkout baselines in the SDD ledger — engine `composer test` (v1.9.1 baseline was 2910/0F/0E/116S; re-capture), engine phpstan (`--memory-limit=1G`; 5 pre-existing errors) and phpcs; Thallo full gates (last known: PHP 3051/0F/0E/71S; vitest 130 files/2007; harness 62/62; phpcs/boundaries/type-check/build clean). Zero new failures anywhere, ever.
- Spec invariants that bind every task: drafts have no order number and claim no stock; finalize is the only `draft → pending_payment` path (dedicated CAS `OrderRepository::finalizeDraftTransition()`, generic `transition()` rejects the pair); draft events are draft-specific audit records only; `origin` closed `storefront|admin`; anti-goals (free status edits, hand-typed totals/shipping, stock bypass) are refused, never implemented; digital + marketplace-partitioned lines rejected at mutation AND finalize; PII ratchet per spec §2.6; exception messages never cross a response boundary.
- Gates per task, FOREGROUND, sequential, NEVER via run_in_background or monitors, with EXPLICIT Bash timeouts (backgrounded runs die when a subagent's turn ends; the 120s default auto-backgrounds long commands): engine tasks — `composer test` (timeout 300000) + phpstan `--memory-limit=1G` (timeout 300000) + that repo's phpcs script; Thallo PHP tasks — `COMPOSER_PROCESS_TIMEOUT=0 composer test` (timeout 600000), `composer phpcs`, `composer boundaries`; SPA tasks add from `admin/`: `npx vitest run`, `npm run -s type-check`, `npm run -s build`. Never run the two repos' suites (or a PHP suite + SPA gates) concurrently.
- Conventional commits, ONE per task in the task's repo; NO AI-attribution trailers; stage explicitly by path; never stage `.claude/`, `.superpowers/`, or composer files outside Task 11.
- TDD every task: the Step 1 matrix is written RED first (run, capture), then implement to GREEN.

---

### Task 1 (ENGINE, rider 1): storefront wire-projection leak fix

**Files:**
- Modify: the storefront order read path — `src/Http/Storefront/OrderController.php` and its `accessCheckedOrder()` helper (locate exactly; v1.9.1 returns the raw order row minus `guest_token_hash`)
- Create: a closed storefront order projection (e.g. `src/Http/Storefront/StorefrontOrderProjection.php` if none exists — check `tests/Integration/Http/StorefrontOrderProjectionTest.php` first; extend whatever it pins)
- Test: extend `tests/Integration/Http/StorefrontOrderProjectionTest.php`

**Interfaces:**
- Produces: every storefront order response passes through one closed field allowlist (a `FIELDS` const, `OrderProjection`-style). The closed-field regression test enumerates the EXACT allowed keys and asserts the response contains no others — this test is the ratchet Tasks 6/8 extend (new PII columns must NOT appear here).

- [ ] **Step 1: Failing tests:** seed an order with every column populated (incl. a sentinel value in `metadata` and `guest_token_hash`); storefront `show`/`mine` responses contain exactly the allowlisted keys; sentinel strings absent from raw JSON.
- [ ] RED → implement → GREEN + engine gates → commit `fix(storefront): closed wire projection for order reads`.

### Task 2 (ENGINE, rider 2): stock NOT NULL migration

**Files:**
- Create: new migration (backfill `commerce_stock.quantity NULL → 0`, `tracked NULL → false`, then NOT NULL)
- Test: migration test per that repo's migration-test conventions

- [ ] **Step 1: Failing tests:** rows seeded with NULL quantity/tracked are backfilled to 0/false (preserving current runtime semantics — verify `StockRepository::isTracked()`'s NULL handling first and assert equivalence before/after); NOT NULL enforced post-migration; runs on SQLite AND PostgreSQL; fresh-install and upgrade paths.
- [ ] RED → implement → GREEN + gates → commit `fix(inventory): NOT NULL stock columns with behavior-preserving backfill`.

### Task 3 (ENGINE, rider 3): OpenAPI path canonicalization

**Files:**
- Modify: the engine's OpenAPI generation path ordering (locate the generator/serializer that emits paths; canonicalize to a stable deterministic order, e.g. lexicographic)
- Test: a generation-determinism test (generate twice / from shuffled input ⇒ byte-identical path ordering)

- [ ] **Step 1: Failing test** proving current output order is input-dependent (or absent determinism guarantee), then canonical ordering pinned.
- [ ] RED → implement → GREEN + gates → commit `fix(openapi): canonical deterministic path ordering`.

### Task 4 (ENGINE): OrderNumberGenerator savepoint fix

**Files:**
- Modify: `src/Orders/OrderNumberGenerator.php` (~line 15: the competing-insert catch runs without a savepoint and can poison a PostgreSQL transaction)
- Test: extend `tests/Integration/Orders/OrderNumberGeneratorTest.php`

**Interfaces:**
- Produces: allocation retries inside a savepoint so a caught unique-violation never aborts the enclosing transaction. This is the precondition for Task 10's transactional-numbering claim.

- [ ] **Step 1: Failing tests:** on PostgreSQL, a competing allocation inside an open transaction no longer poisons it (transaction continues and commits successfully after the caught conflict); two concurrent allocations (parallel connections) both succeed with distinct sequential numbers; SQLite path unchanged.
- [ ] RED → implement → GREEN + gates → commit `fix(orders): savepoint-isolated order number allocation`.

### Task 5 (ENGINE): OrderFulfillmentService extraction

**Files:**
- Create: `src/Orders/OrderFulfillmentService.php`
- Modify: `src/Http/Admin/AdminOrderController.php` (`fulfill()` delegates; behavior identical), provider registration alongside `OrderPaymentService`
- Test: new `tests/Integration/Orders/OrderFulfillmentServiceTest.php` + existing admin fulfill tests stay green untouched

**Interfaces:**
- Produces: `OrderFulfillmentService::fulfill(ApplicationContext $context, string $tenant, string $orderUuid, array $tracking): array` (exact signature mirrors what the controller does today — extract, don't redesign): CAS `paid → fulfilled` via `OrderRepository::transition()`, tracking fields, events/webhooks exactly as the controller did. Consumed by Thallo's complete-sale (Task 13).

- [ ] **Step 1: Failing tests:** service-level parity matrix — fulfill from `paid` succeeds with tracking recorded + event emitted; from any other status ⇒ DomainException; concurrent transition ⇒ the CAS conflict; marketplace-partitioned orders keep their existing controller path working (whatever `fulfillSellerOrder` does stays untouched).
- [ ] RED → implement → GREEN + gates → commit `refactor(orders): extract OrderFulfillmentService from the admin controller`.

### Task 6 (ENGINE): schema — walk-in columns, nullable relaxations, attempts table

**Files:**
- Create: migrations per spec §2.6; `src/Orders/DraftAttemptRepository.php` (owns `commerce_order_draft_attempts` access; fields never projected out)
- Modify: tenant purge/adoption inventory registration (find how existing engine tables register)
- Test: migration gate tests + attempts-table shape/uniqueness tests

**Interfaces:**
- Produces (spec §2.6 verbatim): `commerce_orders` — `order_number` nullable (EXISTING `(tenant_uuid, order_number)` unique index retained), `email` nullable, `guest_token_hash` nullable, new `phone_normalized` (nullable), `phone_display` (nullable, ≤32), `customer_name` (nullable), `origin` (NOT NULL `storefront|admin`, backfill `storefront`), `fulfillment_mode` (NOT NULL `in_store|delivery`, backfill `delivery`), `draft_revision` (int NOT NULL default 0). `commerce_order_draft_attempts`: `id` bigint PK autoincrement, `tenant_uuid` varchar(12), `idempotency_key` varchar(191), `request_fingerprint` varchar(64), `order_uuid` varchar(12), `status` `pending|completed`, `completed_at` nullable, timestamps; UNIQUE `(tenant_uuid, idempotency_key)` — the sole key authority. `DraftAttemptRepository { claimOrReplay(tenant, key, fingerprint, orderUuid): {state: 'fresh'|'replay'|'fingerprint_mismatch', attempt} ; complete(id): void }` (all calls made inside the caller's transaction).

- [ ] **Step 1: Failing tests:** fresh install + REAL v1.9.1-shape upgrade on SQLite AND PostgreSQL; multiple NULL `order_number` rows coexist while duplicate non-null `(tenant, number)` rejected (both drivers); nullable email/token/phone columns accept null; backfills (`origin='storefront'`, `fulfillment_mode='delivery'`) on pre-existing rows; attempts-table uniqueness (second claim same tenant+key ⇒ replay/mismatch path, not a driver error); tenant purge removes attempts rows; storefront projection ratchet (Task 1's test) still passes with the new columns ABSENT from storefront output; null `guest_token_hash` grants no guest access (drive the storefront guest-access path with a null-hash order ⇒ denied); rollback safety.
- [ ] RED → implement → GREEN + gates → commit `feat(orders): walk-in order schema, nullable finalization fields, draft attempt ledger`.

### Task 7 (ENGINE): shared purchasable-line resolver

**Files:**
- Create: `src/Orders/PurchasableLineResolver.php` (extracted from `CartService::pricedLines()`'s variant/availability/addon/price authority — `CartService.php:414+`)
- Modify: `CartService::pricedLines()` consumes the resolver (behavior byte-identical)
- Test: new `tests/Unit/Orders/PurchasableLineResolverTest.php` (or Integration per repo convention) + existing cart/checkout tests untouched green

**Interfaces:**
- Produces: `PurchasableLineResolver::resolve(ApplicationContext $context, string $tenant, string $variantUuid, int $quantity, array $addons, array $options): ResolvedLine` — one authority for variant lookup, buyer availability, addon snapshot/delta pricing, option values, shipping/tax class attachment, digital/marketplace classification (`isDigital`, `isMarketplacePartitioned` exposed for Tasks 9/10's rejections). Consumed by checkout (via CartService) AND draft mutations/finalize.

- [ ] **Step 1: Failing tests:** resolver parity — for identical inputs, checkout's priced line and the resolver's output are identical (drive both paths on seeded product/variant/addons); unavailable variant ⇒ the same typed refusal checkout raises; classification flags correct for digital and marketplace fixtures.
- [ ] RED → implement → GREEN + gates → commit `refactor(orders): shared purchasable line resolver for checkout and drafts`.

### Task 8 (ENGINE): draft state, isolation, cleanup

**Files:**
- Modify: `src/Orders/OrderStateMachine.php` (ALLOWED + `draft` pair rules), `src/Orders/OrderRepository.php` (`transition()` rejects `draft → pending_payment`; new `finalizeDraftTransition()` CAS; centralized finalized-order predicate helper, e.g. `scopeFinalized()`/`excludeDrafts()` applied per audit), `src/Orders/ExpiryService.php` or sibling `DraftCleanupService` (TTL cleanup on the existing cron command), every engine `commerce_orders` reader the audit enumerates (storefront mine/show, admin orders.index, reports, refunds, customer aggregation, expiry, mail listeners, webhooks, currency-lock guard, fulfillment rollups, marketplace)
- Create: `src/Orders/Events/` draft audit event constants (draft_created/draft_canceled/draft_expired as order-event rows, not dispatched lifecycle events)
- Test: `tests/Unit/Orders/OrderStateMachineTest.php` (extend), new `tests/Integration/Orders/DraftIsolationTest.php`, new `tests/Integration/Orders/DraftCleanupTest.php`

**Interfaces:**
- Produces: `draft → [pending_payment, canceled]` in ALLOWED, but `transition()` throws on `draft → pending_payment` (dedicated-path-only); `finalizeDraftTransition(context, tenant, uuid): void` — CAS `WHERE status='draft'`, throws on 0 rows; the shared finalized-order predicate used by every excluded reader; `commerce.orders.draft_ttl_days` config (default 30) + `DraftCleanupService::cancelStale(now, batchSize)` (bounded batches, idempotent CAS, injectable clock); currency snapshot rule (drafts don't trigger the currency lock; the lock guard's query excludes drafts).

- [ ] **Step 1: Failing tests (the engine half of the seeded-draft matrix):** state-machine table (new pairs allowed, everything else unchanged, `transition()` rejects the finalize pair, `finalizeDraftTransition` CAS semantics incl. concurrent loser); seed drafts + finalized orders per tenant, then per reader: storefront mine/show cannot see or resolve drafts; admin `orders.index` excludes drafts; reports/aggregations exclude; customer aggregation excludes; expiry (`pending_payment` expiry) never touches drafts; mail listeners never fire for drafts; webhook capture never fires; currency-lock guard ignores drafts (store currency changeable with only drafts present); refund surfaces refuse drafts; drafts-with-no-order-number break nothing that formats numbers. Cleanup: TTL boundary (29 days stays, 31 days canceled), bounded batch (seed 2×batch+1, three runs drain), idempotent re-run, deterministic injected clock, `draft_canceled`/`draft_expired` audit rows only — assert zero mail/webhook/lifecycle side effects.
- [ ] RED → implement → GREEN + gates → commit `feat(orders): draft order state with structural isolation and bounded cleanup`.

### Task 9 (ENGINE): draft API + eligibility projection

**Files:**
- Create: `src/Http/Admin/AdminOrderDraftController.php`, `src/Orders/DraftOrderService.php` (create/update/lines/recalculate/cancel mechanics; controller stays thin), draft admin projection (name+phone allowed per PII ratchet)
- Modify: `src/Http/Routing/AdminRouteCatalog.php` (entries: `orders.drafts.store|index|show|update|cancel|recalculate`, `orders.drafts.lines.store|update|destroy`, `orders.drafts.finalize` — finalize wired in Task 10), `src/Http/Admin/AdminProductController.php` + its projection (`admin_draft_eligible` + `admin_draft_ineligible_reason` per spec §2.3)
- Test: new `tests/Integration/Http/AdminOrderDraftApiTest.php`

**Interfaces:**
- Produces (spec §2.3 verbatim — phone contract, user attachment incl. email-mismatch 409-class rejection, mode-switch clearing, shipping METHOD id re-quoted, discount validated-not-consumed, stable line UUIDs, advisory snapshots via Task 7's resolver, revision CAS on every mutation, digital/marketplace typed rejection at mutation, `recalculate` = drift acceptance refreshing snapshots + totals + revision increment). Eligibility: admin product search rows carry `admin_draft_eligible: bool` + nullable `admin_draft_ineligible_reason: 'digital'|'marketplace'|'unavailable'`; the line endpoints recheck and return the same closed reasons.

- [ ] **Step 1: Failing tests:** create defaults (`in_store`, anonymous valid — all identity fields null, NO placeholder email); phone matrix (valid international forms normalize + display preserved ≤32; missing `+`, bad lengths, letters ⇒ 422; null/empty clears both columns atomically); user attach (active user ok; unknown/inactive 422/409 per spec; supplied email mismatching resolved user ⇒ rejected, order unlinked); mode switch `delivery → in_store` clears shipping + addresses requirement and recalculates; shipping accepts only a live-quoted method id for delivery (amount fields refused); line add/update/remove with stable uuids + advisory snapshot fields; digital and marketplace items ⇒ typed rejection with the closed reason at mutation; eligibility projection parity — search projection reason equals line-endpoint rejection reason for the same fixtures; revision increments on every mutation class (customer/line/shipping/mode) and stale-revision update ⇒ typed conflict; `recalculate` refreshes a drifted price snapshot + increments revision; discount code validated (bad code 422) but not consumed; `once_per_buyer` discount on an anonymous draft ⇒ 422 at application; drafts listing includes drafts, `orders.index` still excludes (ratchet); PII ratchet — draft admin projection carries name+phone, storefront projection test still closed.
- [ ] RED → implement → GREEN + gates → commit `feat(orders): admin draft order API with closed eligibility and revision custody`.

### Task 10 (ENGINE): DraftFinalizationService

**Files:**
- Create: `src/Orders/DraftFinalizationService.php`
- Modify: `AdminOrderDraftController` (finalize action), `src/Mail/OrderMailListener.php` (origin + email-presence gating), provider wiring
- Test: new `tests/Integration/Orders/DraftFinalizationTest.php`

**Interfaces:**
- Produces (spec §2.5 verbatim): tenant-safe read-only preflight (unknown/cross-tenant ⇒ non-revealing 404, ZERO attempt writes); then one transaction — lock order row, `DraftAttemptRepository::claimOrReplay` (fingerprint = SHA-256 of canonical `{order_uuid, expected_revision}`) before any mutation; status/revision/currency checks (distinct typed conflicts); re-resolve lines via `PurchasableLineResolver` (drift/digital/marketplace conflicts); recalculate; stock claim via `StockRepository::decrement` (per-line typed conflict); number via fixed allocator; atomic snapshot replacement on stable line uuids; discount consumption (anonymous identity rules); `finalizeDraftTransition()`; attempt `complete`; `finalized` audit row — all one commit. `OrderPlaced` dispatched after commit with `origin` in the order payload; mail listener: no email ⇒ short-circuit; `commerce.order_confirmation` toggle governs admin sends.

- [ ] **Step 1: Failing tests (spec §3 finalize matrix, complete):** unknown + cross-tenant uuid ⇒ 404 and `commerce_order_draft_attempts` remains EMPTY; happy path in_store anonymous (totals server-computed, stock claimed, number allocated, status pending_payment, origin=admin, `finalized` audit row); idempotency triple — replay same key+fingerprint returns the finalized order without re-executing (stock decremented ONCE, one number), same key+different fingerprint ⇒ 409, two concurrent finalizes with different keys ⇒ one winner + loser gets truthful conflict, loser's pending attempt rolled back; stale revision ⇒ conflict, draft editable; currency changed since draft creation ⇒ typed conflict; price-drift line ⇒ per-line conflict + `recalculate` clears it + finalize then succeeds; unavailable line, insufficient stock (tracked), digital line, marketplace line ⇒ typed conflicts, rollback proves draft editable + zero stock movements/events/attempt rows; `once_per_buyer` anonymous ⇒ 422, with attached user ⇒ consumed; two-concurrent-finalizers distinct orders get distinct numbers and neither transaction poisons (the Task 4 precondition exercised through finalize on PostgreSQL); after-commit dispatch triple (induced post-CAS failure ⇒ rollback + zero dispatch; fresh ⇒ exactly one `OrderPlaced` carrying origin=admin; replay ⇒ none); mail gating (no email ⇒ zero mail attempts; email + toggle on ⇒ one; email + toggle off ⇒ none); `OrderPlaced` payload compatibility — every pre-existing field unchanged for a storefront checkout fixture (origin=storefront present).
- [ ] RED → implement → GREEN + full engine gates → commit `feat(orders): draft finalization authority with durable idempotency`.

### Task 11 (ENGINE + GATE): release v1.10.0, repin Thallo

**Files:**
- Engine: `CHANGELOG.md` + version bump per the 1.9.1 release commit's shape
- Thallo: `composer.json` + `packages/thallo-commerce/composer.json` (`"glueful/commerce": "^1.10.0"`), `composer.lock`

- [ ] **Step 1:** Engine: land Tasks 1-10 on `dev`, changelog, commit `Release 1.10.0 — Admin draft orders, walk-in finalization & storefront projection hardening`. STOP and hand to the HUMAN: push dev, PR dev→main, merge, tag v1.10.0, Packagist — resume only on their confirmation.
- [ ] **Step 2 (PUBLICATION GATE):** Thallo: repin both constraints, `composer update glueful/commerce` (retry briefly for Packagist lag; BLOCKED if unresolvable — no workarounds). Verify vendor carries `DraftFinalizationService`, `OrderFulfillmentService`, and the draft catalog entries.
- [ ] **Step 3:** Full Thallo gates (mount-parity/fixture tests will FAIL on the new catalog keys — that failure belongs to Task 12; if anything ELSE fails, stop and investigate). If the only failures are the expected allowlist-parity ones, proceed to Task 12 and commit the repin together with Task 12's allowlist fix ONLY if gates cannot otherwise be green; otherwise commit `chore(commerce): repin glueful/commerce to ^1.10.0 for draft orders` separately with the parity fixture updated in the same commit to keep the tree green.

### Task 12 (THALLO): allowlist, draft-blindness, can_attach_user

**Files:**
- Modify: `packages/thallo-commerce/src/Http/AdminMountAllowlist.php` (the `orders.drafts.*` keys), mount-parity fixture (`tests/fixtures/commerce_admin_mount_inventory.json` — regenerate via its documented flow), `packages/thallo-commerce/src/Http/CommerceMetaController.php` (`can_attach_user` per spec §2.3: `users.user_lookup.list.enabled` config AND effective `users.view`), `packages/thallo-commerce/src/Orders/AdminOrderSearchQuery.php` + export + payments endpoints IF the audit shows they can resolve drafts (apply the engine's finalized-order predicate)
- Test: extend `tests/Integration/Commerce/AdminOrderSearchTest.php`/`AdminOrderExportTest.php`/`AdminOrderPaymentsTest.php` with seeded-draft blindness; extend the meta test with the `can_attach_user` matrix

- [ ] **Step 1: Failing tests (the Thallo half of the seeded-draft matrix):** seed a draft; `/orders/search` never returns it under any filter; `/orders/export` CSV excludes it; `/orders/{uuid}/payments` with the draft's uuid ⇒ 404 (cannot RESOLVE, not merely absent); `can_attach_user` matrix — (list.enabled × users.view) all four cells, flag true only for (true, true); mount parity green with the new keys.
- [ ] RED → implement → GREEN + full Thallo gates → commit `feat(commerce): draft-blind order surfaces, drafts allowlist, user-attachment capability flag`.

### Task 13 (THALLO): complete-sale endpoint

**Files:**
- Create: `packages/thallo-commerce/src/Orders/CompleteSaleCoordinator.php`, `packages/thallo-commerce/src/Http/AdminCompleteSaleController.php`
- Modify: `packages/thallo-commerce/routes/admin-routes.php` (`POST /orders/{uuid}/complete-sale`, name `thallo.commerce.admin.orders.complete_sale`, `content_permission:commerce.manage`, before the catalog mount), `CommerceIntegrationServiceProvider`
- Test: new `tests/Integration/Commerce/CompleteSaleTest.php`

**Interfaces:**
- Consumes: engine `OrderPaymentService::markPaid()` + `OrderFulfillmentService::fulfill()` (v1.10.0). Produces (spec §2.8 verbatim): tenant-scoped resolve FIRST (404 unknown/cross-tenant); 409 wrong status/non-in_store/concurrent; 422 malformed; the five-outcome response contract with sanitized errors and `{steps:[{step,status,error?}], order}` shape; guarded Fulfill as recovery; never blind-retried.

- [ ] **Step 1: Failing tests:** truth table — unknown uuid 404; cross-tenant 404; draft/pending-but-delivery/paid/fulfilled/canceled ⇒ 409 with zero transitions; happy path in_store pending_payment ⇒ paid then fulfilled, steps both `done`, exactly one `OrderPaid` + one fulfill event; mark-paid domain conflict (seed a concurrent transition) ⇒ 409, steps mark_paid `failed` fulfill `skipped`; unexpected mark-paid exception pre-commit (failure double) ⇒ sanitized 500, order still pending, fulfill skipped; post-commit throw (after-commit callback double) ⇒ mark_paid truthfully `done` on reload, fulfill `skipped`, sanitized 500; fulfillment domain conflict after payment ⇒ 409 with refreshed PAID order; unexpected fulfillment failure ⇒ logged sanitized 500 + paid order; concurrency — two simultaneous calls, one winner, exactly one paid + one fulfilled event, loser gets truthful conflict; no exception message text in any response (regex sweep).
- [ ] RED → implement → GREEN + full Thallo gates → commit `feat(commerce): server-orchestrated complete-sale for in-store orders`.

### Task 14 (THALLO SPA): draft workspace

**Files:**
- Create: `admin/src/pages/commerce/orders/create.vue`, `admin/src/queries/commerceDrafts.ts` (raw-authfetch idiom per `commerceOrderSearch.ts` — draft endpoints are not in the generated schema until Task 16's regen; note it), `admin/src/pages/commerce/orders/components/DraftLineTable.vue`, `DraftCustomerCard.vue`, `DraftTotalsCard.vue`
- Modify: `admin/src/pages/commerce/orders/index.vue` ("Create order" action, `can_manage`-gated), `admin/src/queries/commerceMeta.ts` (`can_attach_user`)
- Test: new `admin/src/__tests__/commerceDraftWorkspace.spec.ts`

**Interfaces:**
- Consumes: engine draft API (mounted under `/v1/admin/commerce`), admin product search (`admin_draft_eligible` fields), `can_attach_user`. Produces (spec §2.9): line picker disabling ineligible results with the engine's closed reason; qty/addons; all-optional customer block (phone input surfaced as one field; server errors rendered field-level); user picker only when `can_attach_user` (zero `/v1/users` requests otherwise); mode toggle (in_store default; delivery reveals addresses + quoted method select); server `recalculate` with advisory badge; discount entry; finalize with conflict renderings (per-line drift → "Refresh prices" invoking recalculate; stock; revision → reload; currency → cancel/recreate guidance; idempotency 409); success → order detail route.

- [ ] **Step 1: Failing vitest specs:** create action gated on `can_manage`; workspace mounts with anonymous defaults (no placeholder email anywhere in the payload — assert absent, not empty-string); eligibility rendering (eligible selectable, ineligible disabled with reason text from the closed code); line add/update/remove round-trips + revision forwarded; phone field posts raw input and renders 422 field errors; user picker matrix (`can_attach_user` false ⇒ no picker AND zero users fetches — spy); mode toggle clears shipping + address UI on switch to in_store; recalculate updates advisory totals + revision; each finalize conflict type renders its distinct treatment; finalize success navigates to the detail route.
- [ ] RED → implement → GREEN (vitest/type-check/build) + PHP gates → commit `feat(admin): walk-in order draft workspace`.

### Task 15 (THALLO SPA): drafts view + complete-sale action

**Files:**
- Create: `admin/src/pages/commerce/orders/drafts.vue` (drafts listing: resume → workspace, cancel with confirm)
- Modify: `admin/src/pages/commerce/orders/[uuid]/index.vue` (Complete-sale primary action for finalized `in_store` + `pending_payment`; per-step result rendering; paid-but-unfulfilled resume via existing Fulfill), `admin/src/queries/commerceDrafts.ts` (complete-sale + drafts-list calls), orders list nav (Drafts entry/tab)
- Test: extend `admin/src/__tests__/commerceOrderDetail.spec.ts` + new drafts-view specs

- [ ] **Step 1: Failing vitest specs:** drafts view lists drafts with resume/cancel (cancel confirms, then removes); Complete-sale button appears ONLY for in_store + pending_payment (matrix: delivery, paid, draft, fulfilled all hide it); invoking renders per-step results for the five outcome shapes (mocked responses), incl. partial success showing paid state + the standard Fulfill as next action; cycle-1 orders list still draft-blind (mocked search response cannot even represent drafts — assert the drafts tab is the only surface).
- [ ] RED → implement → GREEN (SPA gates) + PHP gates → commit `feat(admin): drafts view and one-click complete sale`.

### Task 16 (THALLO): artifact regeneration + docs

**Files:**
- Regenerate: `docs/openapi.json` (`composer docs:openapi` — now canonicalized by Task 3), `AdminOpenApiGateTest::PACK_OWNED_ROUTES` + related pins, `admin/src/api/schema.d.ts` (the admin gen:api flow)
- Modify: `admin/src/queries/commerceOrderSearch.ts`/`commerceOrders.ts`/`commerceDrafts.ts` — migrate raw-fetch calls to the typed client ONLY where the regenerated schema now covers them (cycle-1 endpoints + drafts + complete-sale), keeping behavior identical; `docs/internal/OUTSTANDING.md` (shipped entry for cycle 2; carry §4 follow-ups verbatim: payment links + emailing, guest custody, account-attached digital, marketplace-partitioned admin orders, audited price override; retire-search note remains)
- Test: existing gates + the OpenAPI gate test updated

- [ ] **Step 1:** Regenerate all three artifacts in one pass (the ONE artifact regeneration the spec mandates); reconcile the OpenAPI gate test; migrate query call sites; failing-first where testable (gate-test pins updated RED then regenerated GREEN).
- [ ] **Step 2:** FULL gates both stacks (Thallo PHP + phpcs + boundaries + vitest + type-check + build; `cd tools/runtime-browser && npm test` timeout 420000 to prove the print gate untouched).
- [ ] **Step 3:** Commit `feat(commerce): cycle 1+2 API artifacts regenerated, admin order creation shipped docs`.

---

## Self-Review

- **Spec coverage:** §1.1-1.2 → Tasks 8/9/10; §1.3 (mark-paid only) → Task 13 consumes existing transitions, no payment surface added; §1.4 identity → Tasks 6/9; §1.5 modes → Tasks 9/10/13; §1.6 → Task 13; §1.7 audit → Tasks 8 (engine) + 12 (Thallo); §1.8 numbering → Tasks 4/10; §1.9 exclusions → Tasks 7/9/10; §1.10 riders/sequence → Tasks 1/2/3 + 11 + 16; §2.1 → Task 8; §2.2 → Tasks 8/12; §2.3 → Task 9; §2.4 → Task 7; §2.5 → Task 10; §2.6 → Task 6; §2.7 → Tasks 1/2/3/5; §2.8 → Task 13; §2.9 → Tasks 14/15; §3 rows all distributed (finalize matrix → 10; attempts shape → 6; eligibility parity → 9; phone → 9; attachment → 9/12/14; origin compat → 10; isolation → 8/12; cleanup → 8; migration gates → 6; rider regressions → 1/2/3; fulfillment parity → 5; guest-null access → 6; no-email mail → 10; complete-sale table → 13; can_attach_user → 12/14; SPA rows → 14/15; artifacts → 16). No gaps.
- **Placeholder scan:** two "locate exactly" instructions (Task 1 helper, Task 3 generator) are bounded discovery of v1.9.1 code the implementer must read anyway — each names the file/test to start from; no TBDs.
- **Type consistency:** `finalizeDraftTransition()` named identically in Tasks 8/10; `DraftAttemptRepository.claimOrReplay/complete` in 6/10; `PurchasableLineResolver.resolve` + classification flags in 7/9/10; `OrderFulfillmentService.fulfill` in 5/13; `admin_draft_eligible`/`admin_draft_ineligible_reason` in 9/14; `can_attach_user` in 12/14; the five-outcome complete-sale shape in 13/15.
- **Sequencing:** 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 (consumes 6) → 9 (consumes 6/7/8) → 10 (consumes 4/6/7/8/9) → 11 (GATE) → 12 → 13 (consumes 5 via vendor) → 14 (consumes 9/12) → 15 (consumes 13/14) → 16 (consumes all). Riders 1-3 land before the draft work per the spec's ordering; canonicalization (3) precedes the artifact pass (16).
