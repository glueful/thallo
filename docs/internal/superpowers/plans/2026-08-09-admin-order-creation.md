# Admin Order Creation (Walk-In First) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Engine-native draft orders with a finalize authority (walk-in-first), the four bundled program riders, a Thallo complete-sale orchestration, and the SPA draft workspace — shipped behind framework v1.77.0 and commerce v1.10.0 publication gates.

**Architecture:** Upstream first: Commerce projection leak + stock NOT NULL, then framework-owned OpenAPI canonicalization and its v1.77.0 release gate, then Commerce's enabling refactors (number-allocator savepoint fix, OrderFulfillmentService extraction, shared purchasable-line resolver), schema, draft state + isolation audit, draft API, and DraftFinalizationService. Human-run commerce v1.10.0 release gates the remaining Thallo work: commerce repin, pack isolation + allowlist + can_attach_user, complete-sale, SPA workspace + drafts view, one artifact regeneration for cycles 1+2.

**Tech Stack:** PHP 8.4 / Glueful (framework repo `/Users/michaeltawiahsowah/Sites/glueful/framework`; engine repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`; Thallo repo `/Users/michaeltawiahsowah/Sites/glueful/thallo`, pack `packages/thallo-commerce`), Vue 3 + pinia-colada (admin/), vitest, tools/runtime-browser untouched.

**Spec:** `docs/internal/superpowers/specs/commerce/2026-08-09-admin-order-creation-design.md` — §1 rulings and §2 contracts govern verbatim; §3's matrix is distributed below. Engine file/line references were mapped against v1.9.1; every implementer re-verifies its own citations before coding.

## Global Constraints

- Repos/branches: framework and engine work on branches off their `dev` branches; Thallo on `dev`. TWO publication gates: Task 3 publishes framework v1.77.0 and repins Thallo before Task 4; Task 11 publishes commerce v1.10.0 and repins Thallo before Tasks 12-16. If Composer cannot resolve either published version, STOP — no path-repo or local workaround. The HUMAN runs each release (push dev, PR dev→main, merge, tag, Packagist).
- Baselines: before Task 1, capture same-checkout baselines in the SDD ledger — engine `composer test` (v1.9.1 baseline was 2910/0F/0E/116S; re-capture), engine phpstan (`--memory-limit=1G`; 5 pre-existing errors) and phpcs; before Task 3 capture framework `composer ci`; before the first Thallo edit capture its full gates (last known: PHP 3051/0F/0E/71S; vitest 130 files/2007; harness 62/62; phpcs/boundaries/type-check/build clean). Zero new failures anywhere, ever.
- Spec invariants that bind every task: drafts have no order number and claim no stock; finalize is the only `draft → pending_payment` path (dedicated CAS `OrderRepository::finalizeDraftTransition()`, generic `transition()` rejects the pair); draft events are draft-specific audit records only; `origin` closed `storefront|admin`; anti-goals (free status edits, hand-typed totals/shipping, stock bypass) are refused, never implemented; digital + marketplace-partitioned lines rejected at mutation AND finalize; PII ratchet per spec §2.6; exception messages never cross a response boundary.
- Gates per task, FOREGROUND, sequential, NEVER via run_in_background or monitors, with EXPLICIT Bash timeouts (backgrounded runs die when a subagent's turn ends; the 120s default auto-backgrounds long commands): framework Task 3 — `composer ci` (timeout 600000); engine tasks — `composer test` (timeout 300000) + phpstan `--memory-limit=1G` (timeout 300000) + that repo's phpcs script; Thallo PHP tasks — `COMPOSER_PROCESS_TIMEOUT=0 composer test` (timeout 600000), `composer phpcs`, `composer boundaries`; SPA tasks add from `admin/`: `npx vitest run`, `npm run -s type-check`, `npm run -s build`. Never run different repos' suites (or a PHP suite + SPA gates) concurrently.
- Conventional commits, one per repository phase named by the task (Tasks 3 and 11 each have an upstream release commit plus a separate Thallo repin commit); NO AI-attribution trailers; stage explicitly by path; never stage `.claude/` or `.superpowers/`; composer files change only in Task 3's framework repin and Task 11's Commerce repin.
- TDD every task: the Step 1 matrix is written RED first (run, capture), then implement to GREEN.

---

### Task 1 (ENGINE, rider 1): storefront wire-projection leak fix

**Files:**
- Modify: `src/Http/Storefront/OrderController.php` — `show()` and `mine()` response boundaries; leave `accessCheckedOrder()` internal/raw apart from its existing `guest_token_hash` removal
- Create: a closed storefront order projection (e.g. `src/Http/Storefront/StorefrontOrderProjection.php` if none exists — check `tests/Integration/Http/StorefrontOrderProjectionTest.php` first; extend whatever it pins)
- Test: extend `tests/Integration/Http/StorefrontOrderProjectionTest.php`

**Interfaces:**
- Produces: `accessCheckedOrder()` remains the INTERNAL raw access-checked row (minus `guest_token_hash`) because downloads and retry-payment require internal fields. One closed field allowlist is applied at order HTTP response boundaries only: `show()` projects the fully enriched `authorizedOrder()` result, and `mine()` maps every item through the same projection. Downloads keep their already-closed grant projections; retry-payment receives the internal row and projects only its own payment result. The closed-field regression test enumerates the EXACT allowed order keys and asserts responses contain no others — this test is the ratchet Tasks 6/8 extend.

- [ ] **Step 1: Failing tests:** seed an order with every column populated (incl. sentinel values in `metadata`, `tenant_uuid`, and `guest_token_hash`); storefront `show`/`mine` responses contain exactly the allowlisted keys; sentinel strings absent from raw JSON. Existing `downloads`, `downloadUrl`, and `retryPayment` behavior tests remain green unedited, proving projection was not moved into the internal access helper.
- [ ] RED → implement → GREEN + engine gates → commit `fix(storefront): closed wire projection for order reads`.

### Task 2 (ENGINE, rider 2): stock NOT NULL migration

**Files:**
- Create: new migration (backfill `commerce_stock.quantity NULL → 0`, `tracked NULL → false`, then NOT NULL)
- Test: migration test per that repo's migration-test conventions

- [ ] **Step 1: Failing tests:** rows seeded with NULL quantity/tracked are backfilled to 0/false (preserving current runtime semantics — verify `StockRepository::isTracked()`'s NULL handling first and assert equivalence before/after); NOT NULL enforced post-migration; runs on SQLite AND PostgreSQL; fresh-install and upgrade paths.
- [ ] RED → implement → GREEN + gates → commit `fix(inventory): NOT NULL stock columns with behavior-preserving backfill`.

### Task 3 (FRAMEWORK + GATE, rider 3): OpenAPI path canonicalization and v1.77.0

**Files:**
- Framework modify: `src/Support/Documentation/DocGenerator.php` — canonicalize inside `getSwaggerJson()` after tag filtering and before schema pruning/`transformPaths()`: lexicographic path keys plus path-item keys ordered `get, put, post, delete, options, head, patch, trace`, followed by non-operation keys lexicographically. `OpenApiGenerator` remains unchanged because it only orchestrates the real serializer.
- Framework test: create `tests/Unit/Support/Documentation/DocGeneratorPathOrderingTest.php` with shuffled `mergePaths()` input producing byte-identical decoded `paths` and JSON output under OpenAPI 3.0 and 3.1.
- Framework release: `CHANGELOG.md` + v1.77.0 release chores.
- Thallo repin: root `composer.json` + `composer.lock` from `glueful/framework:^1.76.0` to `^1.77.0` (pack constraints already admit it).

- [ ] **Step 1: Failing test** feeds the same operations in different insertion orders and proves the serialized paths differ; implement canonical ordering and make both results byte-identical without changing operation bodies.
- [ ] **Step 2:** Framework full gates, changelog, commit `fix(openapi): canonical deterministic path ordering`. STOP for the HUMAN to publish v1.77.0.
- [ ] **Step 3 (PUBLICATION GATE):** only after Packagist resolves v1.77.0, repin Thallo root to `^1.77.0`, run the full Thallo PHP gates, and commit `chore(framework): repin to ^1.77.0 for canonical OpenAPI generation`. Task 4 and all later Commerce work wait for this gate.

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
- Produces: `OrderFulfillmentService::fulfill(ApplicationContext $context, string $tenant, string $orderUuid, ?string $trackingRef): array`: the non-partitioned extraction mirrors today's controller input (`tracking_ref`), owns the transaction, tenant-safe precheck, CAS `paid → fulfilled`, raw-row reload, and exactly-once `OrderFulfilled` dispatch, then returns the raw fulfilled row for callers to project. `AdminOrderController` retains the marketplace-partitioned fan-out branch and HTTP projection and never dispatches the non-partitioned event itself. Consumed by Thallo's complete-sale (Task 13).

- [ ] **Step 1: Failing tests:** service-level parity matrix — fulfill from `paid` succeeds with nullable tracking recorded + exactly one event carrying the raw row; from any other status ⇒ DomainException; concurrent transition ⇒ the CAS conflict; controller response remains projected; marketplace-partitioned orders keep their existing controller path and event count unchanged. Existing admin fulfill tests stay green unedited.
- [ ] RED → implement → GREEN + gates → commit `refactor(orders): extract OrderFulfillmentService from the admin controller`.

### Task 6 (ENGINE): schema — walk-in columns, nullable relaxations, attempts table

**Files:**
- Create: migrations per spec §2.6; `src/Orders/DraftAttemptRepository.php` (owns `commerce_order_draft_attempts` access; fields never projected out)
- Modify: tenant purge/adoption inventory registration; `src/Http/Admin/OrderProjection.php` (closed finalized-order fields: `customer_name`, `phone_normalized`, `phone_display`, `fulfillment_mode`, `origin`; attempt fields and `draft_revision` remain absent here)
- Test: migration gate tests + attempts-table shape/uniqueness tests

**Interfaces:**
- Produces (spec §2.6 verbatim): `commerce_orders` — `order_number` nullable (EXISTING `(tenant_uuid, order_number)` unique index retained), `email` nullable, `guest_token_hash` nullable, new `phone_normalized` (nullable), `phone_display` (nullable, ≤32), `customer_name` (nullable), `origin` (NOT NULL `storefront|admin`, backfill `storefront`), `fulfillment_mode` (NOT NULL `in_store|delivery`, backfill `delivery`), `draft_revision` (int NOT NULL default 0). `commerce_order_draft_attempts`: `id` bigint PK autoincrement, `tenant_uuid` varchar(12), `idempotency_key` varchar(191), `request_fingerprint` varchar(64), `order_uuid` varchar(12), `status` `pending|completed`, `completed_at` nullable, timestamps; UNIQUE `(tenant_uuid, idempotency_key)` — the sole key authority. `DraftAttemptRepository { claimOrReplay(tenant, key, fingerprint, orderUuid): {state: 'fresh'|'replay'|'fingerprint_mismatch', attempt} ; complete(id): void }` (inside the caller's transaction). A fresh insert runs in its own savepoint; duplicate is caught outside it, the winner is re-read and verified, and unrelated PDO failures rethrow, so PostgreSQL's outer transaction remains usable.

- [ ] **Step 1: Failing tests:** fresh install + REAL v1.9.1-shape upgrade on SQLite AND PostgreSQL; multiple NULL `order_number` rows coexist while duplicate non-null `(tenant, number)` rejected (both drivers); nullable email/token/phone columns accept null; backfills (`origin='storefront'`, `fulfillment_mode='delivery'`) on pre-existing rows; admin projection contains the five closed finalized walk-in fields and no `draft_revision`; attempts-table uniqueness (sequential replay/mismatch plus PostgreSQL concurrent first claims using the same tenant/key against DIFFERENT drafts ⇒ deterministic fingerprint conflict, no raw PDO error, and the outer transaction accepts a later write); unrelated insert failure surfaces; tenant purge removes attempts rows; storefront projection ratchet still passes with new columns absent; null `guest_token_hash` grants no guest access; rollback safety.
- [ ] RED → implement → GREEN + gates → commit `feat(orders): walk-in order schema, nullable finalization fields, draft attempt ledger`.

### Task 7 (ENGINE): shared purchasable-line resolver

**Files:**
- Create: `src/Orders/PurchasableLineResolver.php` (shared product/variant base extracted from `CartService::pricedLines()` plus explicit addon policies)
- Modify: `CartService::pricedLines()` consumes the persisted-snapshot method (behavior byte-identical); draft service consumes the selection method
- Test: new `tests/Unit/Orders/PurchasableLineResolverTest.php` (or Integration per repo convention) + existing cart/checkout tests untouched green

**Interfaces:**
- Produces two typed methods over one private base: `resolvePersistedSnapshot(context, tenant, variantUuid, quantity, canonicalAddonSnapshot): ResolvedLine` for carts/checkout, preserving persisted addon deltas; and `resolveSelections(context, tenant, variantUuid, quantity, rawSelections): ResolvedLine` for draft mutation/recalculate/finalize, resolving active addon definitions and returning a fresh canonical snapshot. Both own variant lookup, buyer availability, live variant price, variant-derived option values, shipping/tax attachment, and digital/marketplace classification. There is no caller-supplied options argument and no ambiguous addon array.

- [ ] **Step 1: Failing tests:** persisted-snapshot path is byte-identical to current checkout output and remains unchanged after an addon definition/price edit; selections path resolves the edited definition into a new snapshot and exposes drift; both methods share product/variant availability behavior; option values always come from the variant; classification flags correct for digital and marketplace fixtures.
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
- Produces (spec §2.3 verbatim — phone contract; user attachment with unknown/inactive UUIDs returning one neutral 422 validation error and supplied-email mismatch returning typed 409; mode-switch clearing; shipping METHOD id re-quoted; discount validated-not-consumed; stable line UUIDs; advisory snapshots via Task 7's `resolveSelections()`; revision CAS on every mutation; digital/marketplace typed rejection at mutation; `recalculate` = drift acceptance refreshing snapshots + totals + revision increment). Draft projection carries name, phone, fulfillment mode, origin, and `draft_revision`. Eligibility: admin product search rows carry `admin_draft_eligible: bool` + nullable closed reason.

- [ ] **Step 1: Failing tests:** create defaults (`in_store`, anonymous valid — all identity fields null, NO placeholder email); phone matrix; user attach (active user ok; unknown and inactive both neutral 422; supplied-email mismatch 409, order unlinked); mode switch clearing; live-quoted shipping method only; line mutations with stable UUIDs + advisory snapshots through `resolveSelections`; digital/marketplace typed rejection; eligibility parity; revision increments and stale conflict; recalculate refreshes drifted variant/addon snapshots; discount validation/identity; drafts listing isolation; PII ratchet — draft projection carries name+phone+revision, ordinary admin projection carries finalized walk-in fields, storefront remains closed.
- [ ] RED → implement → GREEN + gates → commit `feat(orders): admin draft order API with closed eligibility and revision custody`.

### Task 10 (ENGINE): DraftFinalizationService

**Files:**
- Create: `src/Orders/DraftFinalizationService.php`
- Modify: `AdminOrderDraftController` (finalize action), `src/Mail/OrderMailListener.php` (one shared email-presence guard in `safeSend()` before every template; admin-origin order-confirmation toggle at placed handling), provider wiring
- Test: new `tests/Integration/Orders/DraftFinalizationTest.php`; extend `tests/Integration/Mail/OrderMailListenerTest.php`

**Interfaces:**
- Produces (spec §2.5 verbatim): controller requires `X-Idempotency-Key` matching `\A[A-Za-z0-9._:-]{16,191}\z` plus non-negative integer `expected_revision`, returning 422 before lookup/ledger access when invalid; tenant-safe read-only preflight; then one transaction — lock order row, claim/replay attempt before mutation; status/revision/currency checks; re-resolve draft selections through `resolveSelections()` while checkout remains on persisted snapshots; recalculate; stock claim; number; atomic snapshot replacement; discount consumption; dedicated transition; attempt completion; audit row. `OrderPlaced` dispatches after commit with origin. The listener's shared `safeSend()` guard refuses null/blank email for every lifecycle template (placed, paid, fulfilled, refund, notifying note); the admin-origin confirmation toggle additionally governs `OrderPlaced`.

- [ ] **Step 1: Failing tests (spec §3 finalize matrix, complete):** retain the full existing matrix; missing/malformed key and revision each return 422 with zero order/attempt query; add current-addon-definition drift alongside variant-price drift; add concurrent same-key/different-draft attempt behavior from Task 6 through finalize; after-commit dispatch triple; mail gating proves a null-email admin order emits zero mailer calls for `OrderPlaced`, then direct sequential invocation of the engine `OrderPaymentService` + `OrderFulfillmentService` used by Complete Sale emits no paid/fulfilled mail, and refund/notifying-note handlers also emit none, while email + toggle on/off preserves existing semantics; `OrderPlaced` payload compatibility remains pinned.
- [ ] RED → implement → GREEN + full engine gates → commit `feat(orders): draft finalization authority with durable idempotency`.

### Task 11 (ENGINE + SECOND GATE): release v1.10.0, repin Thallo

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
- Consumes: engine `OrderPaymentService::markPaid()` + `OrderFulfillmentService::fulfill()` (v1.10.0). Produces (spec §2.8 verbatim): tenant-scoped resolve FIRST (404 unknown/cross-tenant); 409 wrong status/non-in_store/concurrent; 422 malformed; the five-outcome response contract with sanitized errors and `{steps:[{step,status,error?}], order}` shape; every returned/reloaded raw order crosses engine `OrderProjection::forAdmin()` before serialization; guarded Fulfill as recovery; never blind-retried.

- [ ] **Step 1: Failing tests:** truth table — unknown uuid 404; cross-tenant 404; draft/pending-but-delivery/paid/fulfilled/canceled ⇒ 409 with zero transitions; happy path in_store pending_payment ⇒ paid then fulfilled, steps both `done`, exactly one `OrderPaid` + one fulfill event; mark-paid domain conflict; unexpected mark-paid pre/post-commit cases; fulfillment domain/unexpected failures; concurrency single winner; no exception message text in any response; exact-key assertions prove `order` is the closed admin projection and excludes tenant/token/revision/attempt internals in success and every refreshed-error shape.
- [ ] RED → implement → GREEN + full Thallo gates → commit `feat(commerce): server-orchestrated complete-sale for in-store orders`.

### Task 14 (THALLO SPA): draft workspace

**Files:**
- Create: `admin/src/pages/commerce/orders/create.vue`, `admin/src/queries/commerceDrafts.ts` (raw-authfetch idiom until Task 16), draft components
- Modify: orders index Create action, commerce meta, `admin/src/queries/commerceOrders.ts` + order list/detail/invoice components for nullable email and finalized walk-in fields
- Test: new `admin/src/__tests__/commerceDraftWorkspace.spec.ts`; extend `commerceOrders.spec.ts`, `commerceOrderDetail.spec.ts`, and `commerceInvoicePrint.spec.ts` for nullable identity/projection behavior

**Interfaces:**
- Consumes the draft API, product eligibility, and `can_attach_user`. Route custody is `/commerce/orders/create?draft={uuid}`: without a UUID create one draft once and immediately `router.replace()` the UUID; with one, load it; refresh/resume never create. Finalize sends `crypto.randomUUID()` in `X-Idempotency-Key`; store one opaque key per draft UUID + expected revision in `sessionStorage`, reuse after ambiguous failures/reload, rotate after revision changes, and clear after confirmed finalize/cancel. Produces the full spec §2.9 workspace and conflict handling. Existing admin order normalization models `email: string|null` plus finalized walk-in fields; list/detail/invoice render “Walk-in customer” and omit email copy controls when null.

- [ ] **Step 1: Failing vitest specs:** retain the full workspace matrix; additionally assert initial creation replaces the URL, refresh/present UUID performs show without store, Resume opens the exact UUID, finalize/cancel clears custody; ambiguous network failure + reload reuses the same header key, revision increment rotates it, confirmed success clears it; list/detail/invoice handle null email without empty placeholders or copy buttons and expose `fulfillment_mode` for Complete Sale.
- [ ] RED → implement → GREEN (vitest/type-check/build) + PHP gates → commit `feat(admin): walk-in order draft workspace`.

### Task 15 (THALLO SPA): drafts view + complete-sale action

**Files:**
- Create: `admin/src/pages/commerce/orders/drafts.vue` (drafts listing: resume → `/commerce/orders/create?draft={uuid}`, cancel with confirm)
- Modify: `admin/src/pages/commerce/orders/[uuid]/index.vue` (Complete-sale primary action for finalized `in_store` + `pending_payment`; per-step result rendering; paid-but-unfulfilled resume via existing Fulfill), `admin/src/queries/commerceDrafts.ts` (complete-sale + drafts-list calls), orders list nav (Drafts entry/tab)
- Test: extend `admin/src/__tests__/commerceOrderDetail.spec.ts` + new drafts-view specs

- [ ] **Step 1: Failing vitest specs:** drafts view lists drafts with UUID-bearing resume/cancel; resumed workspace loads without creating; Complete-sale button appears ONLY from the server-projected `fulfillment_mode='in_store'` + `pending_payment`; retain the five outcome and draft-blindness matrices.
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

- **Spec coverage:** §1.1-1.2 → Tasks 8/9/10; §1.3 → 13; §1.4 → 6/9/14; §1.5 → 6/9/10/13/14; §1.6 → 13; §1.7 → 8/10/12/14; §1.8 → 4/10; §1.9 → 7/9/10; §1.10 → 1/2/3/11/16; §2.1 → 8; §2.2 → 8/12; §2.3 → 9; §2.4 → 7; §2.5 → 10/14; §2.6 → 6/14; §2.7 → 1/2/3/5; §2.8 → 13; §2.9 → 14/15. Concurrency, nullable-email, resume, key-custody, and all-event mail rows are explicitly assigned.
- **Placeholder scan:** no "locate exactly", TBD, or unresolved `422/409` branches remain; Task 3 names the real framework generator boundary and release.
- **Type consistency:** `finalizeDraftTransition()` in 8/10; attempt methods in 6/10; `resolvePersistedSnapshot` vs `resolveSelections` in 7/9/10; fulfillment nullable tracking + raw return in 5/13; finalized/draft projection split in 6/9/14; eligibility and `can_attach_user` names unchanged; finalize key header/custody in 10/14.
- **Sequencing:** 1 → 2 → 3 (FRAMEWORK GATE) → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 (COMMERCE GATE) → 12 → 13 → 14 → 15 → 16. Canonicalization is published before the artifact pass; no Commerce or Thallo path workaround bypasses either gate.
