# Workspace Self-Serve Checkout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Workspace admins self-serve subscribe/cancel their platform plan through provider-hosted checkout, with the pricing-blocks → billing deep-link bridge.

**Architecture:** Three phases per spec §2: Payvia 2.5.0 (subscription-checkout gateway capability, origination ledger + DB-backed subject guard, ownership correlation with persisted enrichment, durable projection acknowledgement + finalizer, operator reconciliation), then subscriptions 2.2.0 (origination-bound reservation seam, outcome-returning projection, `non_renewing`, per-gateway purchasability), then Thallo (billing.manage, operator switch, `/v1/admin/billing` API + SPA, bridge). Canonical flow: atomically prepare locally → claim origination → bind reservation → commit → provider checkout → webhook correlates + enriches → strict projection acknowledges → finalizer dispatches.

**Tech Stack:** PHP 8.3 / Glueful framework (payvia, subscriptions extensions; thallo packs), Vue 3 + pinia-colada + vitest (admin SPA), Twig (render blocks).

**Spec:** `docs/internal/superpowers/specs/2026-08-03-workspace-checkout-design.md` (in the thallo repo). Section references (§) below are to that file. Where a task says "verbatim from §X", the exact wording in the spec governs.

## Global Constraints

- Release order A → B → C with HARD publication gates: Phase B starts only after glueful/payvia 2.5.0 resolves from the published registry; Phase C only after glueful/subscriptions 2.2.0 also resolves. No sibling path repositories, ever.
- HARD sandbox gate inside Phase A: Paystack implementation may not be locked without captured sandbox fixtures proving the §3.1 join and amount shape; `(customer_code, plan_code)` ownership joins are forbidden.
- Never fall back from subscription initiation to one-time `InitiationCapableGateway`; unavailable gateways fail explicitly BEFORE any ledger write or provider call.
- Provider metadata carries ONLY `origination_uuid` (+ gateway transport fields: Paystack reference/email). Never actor_user_uuid, never subject/plan fields (§3.5).
- Webhooks + accepted strict-lane projection are the only activation authority. Returns, ledger, SPA are presentational.
- The subject guard table — not TTLs, booleans, partial indexes, or origination queries — is the live-attempt authority. Local TTL elapse never frees a guard (§3.2–3.3).
- Idempotency keys identify one attempt, replay requires a byte-identical request fingerprint (`idempotency_conflict` otherwise), unknown provider outcomes never free keys or guards.
- Enrichment must be persisted before `markProcessed()` and visible on FIRST delivery; missing payload-update capability fails closed (§3.4).
- Cancellation exposes only driver-declared `cancellationModes()`; Paystack disable projects `non_renewing` (entitling until `current_period_end`, then fail closed).
- Thallo workspace routes: `['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding']` + `content_permission:billing.manage`; workspace UUID always server-derived; `self_serve_checkout_enabled` default false, gates origination only, rechecked at request time.
- Every repo's full gates green per task (payvia/subscriptions: `composer test`, `composer analyze`, `composer phpcs`; thallo: phpunit, phpcs, `composer boundaries`, and for SPA tasks `npx vitest run`, `npm run -s type-check`, `npm run -s build` in admin/).
- Conventional commits, ONE commit per task unless stated; NO AI-attribution trailers (no Co-Authored-By).
- Thallo boot rule (learned this program): provider `register()` never runs on cached production boots — any Thallo boot-time wiring goes in `boot()` or `services()` DSL only.

# PHASE A — glueful/payvia 2.5.0

Work from `/Users/michaeltawiahsowah/Sites/glueful/extensions/payvia` (branch `dev`). Namespace `Glueful\Extensions\Payvia\`. Migrations continue from 009 (next: 010). Existing anchors: `GatewayManager` (drivers map, `supports()`), `Services/PayviaPaymentCollector` (one-time initiation — do not modify), `Services/GatewaySubscriptionService::applyProviderEvent()`, `Services/WebhookService::processStored()/dispatch()`, `PayviaServiceProvider::makeWebhookService()` (composed dispatcher), `Events/ProviderEvent`, `Repositories/ProviderCorrelationRepository`, `Support/PayviaSettings`.

### Task 1: Gateway capability contracts + Stripe driver

**Files:**
- Create: `src/Contracts/SubscriptionInitiationCapableGateway.php`, `src/Contracts/SubscriptionCheckoutLifecycleCapableGateway.php`, `src/Checkout/SubscriptionCheckoutRequest.php`
- Modify: `src/Gateways/StripeGateway.php`, `src/Gateways/PaystackGateway.php` (cancellationModes only), `src/Contracts/SubscriptionCapableGateway.php` (add `cancellationModes(): array`), `src/GatewayManager.php` (capability key `subscription_checkout`)
- Test: `tests/Unit/Gateways/StripeSubscriptionCheckoutTest.php`, `tests/Unit/Gateways/CancellationModesTest.php`

**Interfaces:**
- Produces (verbatim §3.1):
```php
interface SubscriptionInitiationCapableGateway
{
    /** @return array{reference:string, checkout_url:string, expires_at:?string, raw:array} */
    public function initializeSubscription(SubscriptionCheckoutRequest $request): array;
}
interface SubscriptionCheckoutLifecycleCapableGateway
{
    /** @return 'pending'|'completed'|'expired'|'canceled'|'unknown' */
    public function subscriptionCheckoutStatus(string $reference): string;
    /** @return 'confirmed_dead'|'still_live'|'unsupported'|'unknown' */
    public function abandonSubscriptionCheckout(string $reference): string;
}
final class SubscriptionCheckoutRequest // readonly ctor-promoted
{
    public string $originationUuid; public string $tenantUuid; public string $subjectKey;
    public string $gateway; public string $providerPlanIdentifier;
    /** @var array<string,string> */ public array $consumerMetadata;
    public string $customerEmail; public string $returnUrl; public string $cancelUrl;
    public ?string $requiredProjectionConsumer;
}
```
- `SubscriptionCapableGateway::cancellationModes(): array` — Stripe returns `['stop_renewal','immediate']`; Paystack `['stop_renewal']` (its `cancelSubscription` behavior unchanged).
- Stripe `initializeSubscription()`: `POST /v1/checkout/sessions`, `mode=subscription`, `line_items[0][price]`, `client_reference_id = originationUuid`, `metadata[origination_uuid]`, `subscription_data[metadata][origination_uuid]` (named assertion — session metadata does not propagate), `success_url` required / `cancel_url` optional, `customer_email`, header `Idempotency-Key: payvia-subinit-<originationUuid>`; response validation mirrors `initialize()` (id `cs_`, absolute HTTPS URL); `expires_at` from session. Stripe lifecycle: `subscriptionCheckoutStatus` via `GET /v1/checkout/sessions/{ref}` (map `status`/`payment_status` → the 5-value enum); `abandonSubscriptionCheckout` via `POST /v1/checkout/sessions/{ref}/expire` then re-fetch (`confirmed_dead` only on expired/canceled). Paystack lifecycle methods return `'unknown'`/`'unsupported'` in this task (Task 3 revisits per fixtures).
- `GatewayManager::supports($gateway, 'subscription_checkout')` → `instanceof SubscriptionInitiationCapableGateway`.

- [ ] **Step 1: Failing tests.** Stripe: request-shape pin over a recording HTTP double (all fields above asserted, including the `subscription_data[metadata][origination_uuid]` named assertion and idempotency header); missing `success_url` throws; malformed session id/URL rejected; status mapping matrix; expire→confirmed_dead; NEVER-calls-`initialize()` assertion (spy). CancellationModes: both drivers' declared arrays; `GatewayManager::supports()` matrix.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat: subscription checkout gateway capability with Stripe driver`.

### Task 2: Paystack sandbox-proof harness — MAINTAINER GATE

**Files:**
- Create: `src/Console/CheckoutSandboxProofCommand.php` (`payvia:checkout:sandbox-proof`), `tests/Fixtures/paystack-checkout/README.md`
- Test: `tests/Unit/Console/CheckoutSandboxProofCommandTest.php` (command wiring + redaction only)

**Interfaces:**
- Produces: a console command that, given sandbox credentials in env, (1) creates a throwaway Paystack plan, (2) runs `POST /transaction/initialize` twice — once WITHOUT `amount`, once WITH — and records both raw responses, (3) prints the checkout URL for the maintainer to complete payment, (4) polls and captures the resulting raw webhook payloads (`charge.success`, `subscription.create`) from the `provider_events` table after ingestion, (5) writes REDACTED fixture JSON files (authorization codes, emails, signatures stripped; structure and metadata/reference/subscription_code fields preserved) to `tests/Fixtures/paystack-checkout/`. The README documents the §3.1 decision procedure: metadata propagated to `subscription.create` ⇒ direct-correlation mode; else `charge.success` must carry reference/metadata AND nested `subscription_code` ⇒ two-event mode; neither ⇒ Paystack initiation stays unavailable and the Phase A release gate CANNOT pass.
- [ ] Implement + gates; commit `feat: paystack checkout sandbox proof harness`.
- [ ] **HARD STOP — maintainer action:** run the command against a Paystack sandbox, complete one hosted checkout, commit the captured fixtures. Task 3 is blocked until fixtures exist in `tests/Fixtures/paystack-checkout/` and state which §3.1 mode (and amount shape) they prove.

### Task 3: Paystack subscription checkout driver (fixture-locked)

**Files:**
- Modify: `src/Gateways/PaystackGateway.php`
- Test: `tests/Unit/Gateways/PaystackSubscriptionCheckoutTest.php` (drives the committed fixtures)

**Interfaces:**
- Produces: `PaystackGateway implements SubscriptionInitiationCapableGateway` (+ lifecycle: `subscriptionCheckoutStatus` via `GET /transaction/verify/{reference}` mapping, `abandonSubscriptionCheckout` returns `'unsupported'` — pinned by §3.3). `initializeSubscription()`: `POST /transaction/initialize` with `plan`, required `email`, `callback_url`, stringified `metadata[origination_uuid]`, reference derived from origination UUID; amount handling EXACTLY as the fixtures prove (omit if omission accepted; else fetch `GET /plan/{code}` and submit the provider-returned amount — never a host amount). Normalizer additions per the proven mode: extract `origination_uuid` (direct mode) and/or nested `subscription_code` on `charge.success` (two-event mode) into normalized payloads.
- [ ] **Step 1: Failing tests from fixtures:** request-shape pin (per proven amount shape); fixture-driven normalizer assertions for the proven correlation fields; `'unsupported'` abandonment; one-time `charge.success` fixtures (existing tests) byte-identical — no behavior change for non-checkout events.
- [ ] RED → implement → GREEN + gates → commit `feat: paystack subscription checkout driver per sandbox proof`.

### Task 4: Origination ledger + subject guard (migration 010 + repositories)

**Files:**
- Create: `migrations/010_CreateCheckoutOriginations.php` (BOTH tables §3.3), `src/Repositories/CheckoutOriginationRepository.php`, `src/Repositories/CheckoutSubjectGuardRepository.php`
- Test: `tests/Integration/Repositories/CheckoutOriginationLedgerTest.php`

**Interfaces:**
- Tables verbatim §3.3: `subscription_checkout_originations` (all listed columns incl. `request_fingerprint` 64, `customer_email` nullable 254, `return_url`/`cancel_url` 2048, `required_projection_consumer`, `projection_event_key`/`projection_outcome`/`projection_reason`, `live` derived flag; uniques `uuid`, `(tenant_uuid, idempotency_key)`, `(gateway, checkout_reference)`, `(gateway, provider_subscription_id)` — the migration test pins nullable-unique behavior on sqlite AND pgsql; if not portable use the documented re-key pattern) and `subscription_checkout_subject_guards` (`uuid, tenant_uuid, subject_key, state open|live|blocked, origination_uuid?, blocked_reason?, revision`, unique `(tenant_uuid, subject_key)`).
- Produces:
```php
CheckoutOriginationRepository::claimPreparing(ApplicationContext, array $row): array          // insert preparing; same-key returns existing row
CheckoutOriginationRepository::findByUuid/findByIdempotencyKey/findByCheckoutReference/findByProviderSubscriptionId(...): ?array
CheckoutOriginationRepository::transition(ApplicationContext, string $uuid, string $from, string $to, array $set = []): bool  // CAS on status
CheckoutSubjectGuardRepository::lockAndClaim(ApplicationContext, string $tenantUuid, string $subjectKey, string $originationUuid): bool  // open→live once; savepoint on unique race, re-read winner
CheckoutSubjectGuardRepository::release(ApplicationContext, string $tenantUuid, string $subjectKey, string $originationUuid): bool       // CAS on bound origination
CheckoutSubjectGuardRepository::block(ApplicationContext, ..., string $reason): bool
```
State machine (§3.3): `preparing → initializing → pending → provider_observed → dispatched`; `initializing → failed`; `pending → expired|abandoned`; `provider_observed → projection_rejected`; terminal → `provider_observed` re-bind sanctioned; `late_settlement_conflict` reachable from terminal statuses. Transitions monotonic/idempotent; repeating a done transition is a no-op `true`; illegal transitions return `false` without write.
- [ ] **Step 1: Failing tests:** full transition matrix (legal, repeat-idempotent, illegal-refused); guard open→live exactly once under a concurrent claim race (pgsql-gated two-connection test + sqlite sequential equivalent); release requires matching origination; email column cleared via `transition(..., ['customer_email' => null])`; unique-null pin for `(gateway, provider_subscription_id)`.
- [ ] RED → implement → GREEN + gates → commit `feat: checkout origination ledger and subject guard`.

### Task 5: SubscriptionCheckoutService (prepare / initializeClaim)

**Files:**
- Create: `src/Checkout/SubscriptionCheckoutService.php`, `src/Checkout/SubscriptionCheckoutClaim.php`, `src/Checkout/SubscriptionCheckoutResult.php`, `src/Checkout/CheckoutUnavailableException.php`, `src/Checkout/IdempotencyConflictException.php`, `src/Checkout/OriginationLiveException.php`
- Modify: `src/PayviaServiceProvider.php` (`services()`: shared bindings)
- Test: `tests/Integration/Checkout/SubscriptionCheckoutServiceTest.php`

**Interfaces (verbatim §3.2):**
```php
prepare(ApplicationContext $context, SubscriptionCheckoutRequest $request, callable $bindLocalReservation): SubscriptionCheckoutClaim
initializeClaim(ApplicationContext $context, string $originationUuid): SubscriptionCheckoutResult
```
`prepare()` owns ONE transaction: validate (gateway implements the capability + identifier non-empty → `CheckoutUnavailableException` BEFORE any write) → lock/claim guard (`OriginationLiveException` when another origination is live) → `claimPreparing` → invoke `$bindLocalReservation($claim)` (local DB work only; receives immutable claim with `originationUuid`) → `markPrepared()` (`preparing → initializing`) → commit; ANY continuation throw rolls back claim+guard+reservation together. Fingerprint: SHA-256 over the canonical JSON of (subjectKey, gateway, providerPlanIdentifier, sorted consumerMetadata, customerEmail, returnUrl, cancelUrl, requiredProjectionConsumer); same-key replay with matching fingerprint returns the stored claim WITHOUT re-invoking the continuation; mismatch → `IdempotencyConflictException`; terminal same-key replay returns the stored terminal result. `initializeClaim()`: CAS-guarded; calls `initializeSubscription()` with provider idempotency key `payvia-subinit-<uuid>`; success → persist reference/url/expires_at, `initializing → pending`, clear `customer_email`; definitive 4xx → `failed` + clear email; timeout/unknown → stays `initializing`, key and guard NOT freed, replay re-calls with the same provider key. `SubscriptionCheckoutClaim{originationUuid, status, checkoutUrl: ?string, replayed: bool}`; `SubscriptionCheckoutResult{originationUuid, checkoutUrl, status}`.
- [ ] **Step 1: Failing tests:** unavailable-before-write (no rows after refusal); happy path row/guard/claim states; continuation-throw full rollback; same-key replay skips continuation and returns url; fingerprint mismatch conflict; different-key + live guard refusal via DB; terminal replay; initializeClaim recovery matrix (unknown outcome retains email + resumes same provider key — recording gateway double; definitive failure frees guard + clears email); concurrent different-key race (pgsql-gated) yields exactly one live guard.
- [ ] RED → implement → GREEN + gates → commit `feat: subscription checkout service with atomic preparation`.

### Task 6: Immutable event replacement + persisted enrichment plumbing

**Files:**
- Create: `src/Contracts/ProviderEventPayloadUpdaterInterface.php`
- Modify: `src/Events/ProviderEvent.php` (`withNormalized(array): static` preserving gateway/type/ids/logical key/time/raw), `src/Services/WebhookService.php` (applier callback may return `?PaymentProviderEventInterface`; when non-null: `replaceNormalizedPayload($uuid, $normalized)` persisted BEFORE `markProcessed()`, replacement object dispatched on FIRST delivery; missing updater binding or persistence failure ⇒ fail closed: markFailed + rethrow), `src/Repositories/ProviderEventRepository.php` (implement the updater), `src/PayviaServiceProvider.php` (binding)
- Test: `tests/Integration/Webhooks/EventReplacementTest.php`

**Interfaces:**
```php
interface ProviderEventPayloadUpdaterInterface { public function replaceNormalizedPayload(string $uuid, array $normalized): void; }
```
Legacy void applier stays byte-compatible (null return = no replacement).
- [ ] **Step 1: Failing tests:** replacement persisted before markProcessed (order asserted via recording doubles); FIRST delivery dispatches the enriched object (strict-listener spy sees enrichment — a retry-only success fails the test, §3.4); retry reconstructs identical enriched event from storage; missing updater ⇒ fail closed, event retryable; void applier unchanged.
- [ ] RED → implement → GREEN + gates → commit `feat: immutable provider event replacement with persisted enrichment`.

### Task 7: Origination ownership correlation + late-settlement conflict

**Files:**
- Modify: `src/Services/GatewaySubscriptionService.php`, `src/Gateways/StripeGateway.php` (normalizer extracts `origination_uuid`; recognize `checkout.session.expired` → ledger lifecycle: `pending → expired` + guard release, BEFORE the unknown-type early-dispatch path), `src/Gateways/PaystackGateway.php` (only if fixtures chose two-event mode: `payment.succeeded` pre-pass fields)
- Test: `tests/Integration/Checkout/OriginationCorrelationTest.php`

**Interfaces (§3.4):** ownership order preserved — existing projection row, then origination ledger, then billing_plan_uuid. When the event carries an origination token, "existing wins" is NOT an early return: validate matching ledger owner, then perform the same idempotent transition + enrichment. New source: resolve by `origination_uuid` (or, two-event mode, `(gateway, provider_subscription_id)` recorded from the charge pre-pass); adopt ledger `tenant_uuid` (provider hints diagnosed+ignored per existing policy); record `provider_subscription_id`; transition → `provider_observed`; ENRICH normalized metadata with ledger `consumer_metadata` correlation fields (`tenant_uuid, subject_type, subject_uuid, plan_uuid, glueful_consumer` — NEVER actor) and return the replacement event (Task 6 plumbing). Late settlement: correlated terminal origination whose subject now has a NEWER live origination/owner → transition to `late_settlement_conflict`, `block` the guard with reason, no overwrite, no retry loop (event completes applier normally). Paystack two-event pre-pass (only if proven): narrow branch BEFORE `isSubscriptionEvent()` early return — `payment.succeeded` with an exact origination reference records nested `subscription_code` + enriches + `provider_observed`, creates NO `gateway_subscriptions` row, never finalizes; all other one-time charges byte-identical (regression-pinned).
- [ ] **Step 1: Failing tests:** adopt+enrich happy path (first delivery, replacement returned); ledger-vs-hint conflict diagnosed, ledger wins; existing-projection event with token still enriches (crash-window test: provider row written, payload update failed, retry re-enriches); owner mismatch with existing projection refused; late-settlement conflict (terminal origination + newer owner → conflict state + blocked guard, newer row untouched); terminal→provider_observed re-bind when NO newer owner; Stripe expiry closes the right guard pre-dispatch; two-event pre-pass matrix (fixture-gated) incl. unrelated charge regression.
- [ ] RED → implement → GREEN + gates → commit `feat: origination ownership correlation with late-settlement conflicts`.

### Task 8: Projection acknowledgement + post-dispatch finalizer

**Files:**
- Create: `src/Contracts/SubscriptionProjectionAcknowledger.php` (verbatim §3.6 signature), `src/Checkout/RequiredProjectionAcknowledgementMissing.php`
- Modify: `src/Services/WebhookService.php` (finalizer after ordinary bus → strict → chargeback all succeed), `src/Repositories/CheckoutOriginationRepository.php` (acknowledgement CAS writer), `src/PayviaServiceProvider.php`
- Test: `tests/Integration/Checkout/ProjectionAcknowledgementTest.php`

**Interfaces:** `acknowledge(originationUuid, consumer, logicalEventKey, outcome 'accepted'|'rejected', ?reason)` — CAS over `provider_observed` + exact `required_projection_consumer` + logical key; repeat same outcome no-op; conflicting outcome throws; wrong consumer/state refused. Finalizer (per event, after dispatcher success): resolve origination by enriched `origination_uuid` or `(gateway, provider_subscription_id)`; for `subscription.created` with a required consumer — accepted ack → `provider_observed → dispatched` + guard release; rejected ack → `projection_rejected` + guard `block` (bounded reason), event dispatch completes; NO ack → throw `RequiredProjectionAcknowledgementMissing` (lease released, event retryable). No required consumer → `dispatched` = generic completion. Correlation-only events (`payment.succeeded` pre-pass) never finalize.
- [ ] **Step 1: Failing tests:** ack CAS matrix (accept, repeat, conflict-throw, wrong consumer/state); finalizer accepted/rejected/missing matrix incl. lease release + retry succeeds after late ack; strict-listener throw leaves `provider_observed` + event retryable + no second ownership row on retry; crash-after-projection-before-ack recovery (duplicate delivery re-reads receipt outcome — simulated consumer double).
- [ ] RED → implement → GREEN + gates → commit `feat: durable projection acknowledgement and origination finalizer`.

### Task 9: Operator reconciliation + tenant lifecycle + Release 2.5.0

**Files:**
- Create: `src/Checkout/CheckoutReconciliationService.php`
- Modify: `src/Console/TenancyAdoptCommand.php` + tenant inventory/purge surfaces (both checkout tables; child originations deleted before guards), `CHANGELOG.md`, `README.md`, `composer.json` (`extra.glueful.version` → `2.5.0`)
- Test: `tests/Integration/Checkout/CheckoutReconciliationTest.php`, `tests/Integration/Checkout/TenantLifecycleInclusionTest.php`

**Interfaces (§3.8):**
```php
CheckoutReconciliationService::resolve(ApplicationContext $context, string $originationUuid, string $resolution /* provider_confirmed_dead|provider_canceled_or_refunded */, string $auditNote, callable $releaseLocalReservation): void
```
Same transaction discipline as `prepare()` (local-only continuation). `provider_confirmed_dead` allowed ONLY when the ledger never observed provider money/subscription state (no checkout completion, no provider_subscription_id); non-empty audit note required for both; opens the matching blocked/live guard with origination CAS and invokes the continuation in the same transaction. Never auto-refunds, never rewrites a committed rejected receipt, never activates, no bare-ignore.
- [ ] **Step 1: Failing tests:** both resolutions (guard opened + continuation same-transaction, rollback-together); `provider_confirmed_dead` refused once money observed; empty note refused; applies to `projection_rejected`, `late_settlement_conflict`, and stuck Paystack `pending`; purge/adopt inventory includes both tables in FK-safe order.
- [ ] RED → implement → GREEN + gates. **Release steps:** CHANGELOG 2.5.0 (capability, ledger/guard, correlation+enrichment, acknowledgement/finalizer, reconciliation, cancellationModes; additive, existing flows unchanged), README sections, version bump. Commit `Release 2.5.0 — subscription checkout origination` + local annotated tag `v2.5.0`. NOTHING pushed.
- [ ] **HARD PUBLICATION GATE:** maintainer publishes payvia 2.5.0. Verify clean `^2.5` resolution from the published registry before Task 10.

# PHASE B — glueful/subscriptions 2.2.0

Work from `/Users/michaeltawiahsowah/Sites/glueful/extensions/subscriptions` (branch `dev`). Migrations continue from 006 (next: 007). Add `glueful/payvia: ^2.5` to `require-dev` (Task 12 needs the acknowledger contract; runtime stays optional via the existing `strictLaneMode()` probing).

### Task 10: Reservation seam (`reserveCheckoutFor`) + migration 007

**Files:**
- Create: `migrations/007_CheckoutReservations.php` (`subscriptions.checkout_origination_uuid` VARCHAR(12) nullable + index)
- Modify: `src/SubscriptionService.php`, `src/Schema/SubscriptionSchemaReadiness.php` (2.2 shape includes the column), `src/Repositories/SubscriptionRepository.php`
- Test: `tests/Integration/CheckoutReservationTest.php`

**Interfaces (verbatim §4.1):**
```php
SubscriptionService::reserveCheckoutFor(Subject $subject, string $planUuid, string $originationUuid, array $opts = []): array
SubscriptionService::releaseCheckoutReservation(Subject $subject, string $originationUuid): void
```
Reservation row: `status = 'incomplete'` (non-entitling — entitlement resolver test), no provider fields, `plan_uuid` + `checkout_origination_uuid` set, event `source = 'checkout_reservation'` with `$opts['actor']`. Idempotent same-origination+plan; different origination/plan replaces an incomplete reservation ONLY when the caller passes `$opts['replace'] = true` (the documented prepare-continuation path — direct ad-hoc replacement refused); refuses `already_subscribed` for active/trialing/past_due OR unexpired `non_renewing`; expired `non_renewing` replaceable. Release: compare-and-delete guarded by exact origination, refused when provider fields present.
- [ ] **Step 1: Failing tests:** idempotency; replace-guard matrix (ad-hoc refused, flagged path replaces, two-plan race — second reservation without flag loses); refusal matrix incl. unexpired `non_renewing` (status seeded directly; vocabulary lands fully in Task 11 — this task adds the string to the status allowlist only); `incomplete` grants zero entitlements; release CAS matrix; readiness includes the new column.
- [ ] RED → implement → GREEN + gates → commit `feat: origination-bound checkout reservation seam`.

### Task 11: `non_renewing` status + cancellation-mode projection

**Files:**
- Modify: `src/Projection/SubscriptionEventProjector.php` (`computeChanges`: `subscription.canceled` with `normalized['cancellation_mode'] === 'stop_renewal'` + a provider period end ⇒ `status='non_renewing'` keeping `current_period_end`; otherwise `canceled` as today), entitlement resolvers (tenant + member paths: `non_renewing` entitles only while `current_period_end > now`, absent/invalid/past ⇒ default plan), `src/Projection/ProviderEventData.php` (allow `cancellation_mode` top-level)
- Test: `tests/Integration/NonRenewingProjectionTest.php`

- [ ] **Step 1: Failing tests:** stop_renewal event ⇒ `non_renewing` + period end retained; immediate cancel ⇒ `canceled`; effective entitlements asserted immediately BEFORE and AFTER the boundary (clock-controlled), not just column values; missing/invalid period end fails closed to default plan; `reserveCheckoutFor` refusal now driven by the real projected status.
- [ ] RED → implement → GREEN + gates → commit `feat: non-renewing subscription state with boundary entitlements`.

### Task 12: Outcome-returning projection + strict-bridge acknowledgement

**Files:**
- Create: `src/Projection/ProjectionOutcome.php` (`readonly {outcome: 'accepted'|'rejected', reason: ?string, logicalEventKey: string}`)
- Modify: `src/Projection/SubscriptionEventProjector.php` (additive `projectWithOutcome(ProviderSubscriptionEvent): ProjectionOutcome`; void `project()` delegates; deterministic rejections — `subject_mismatch`, `plan_scope_mismatch`, origination-mismatch — return `rejected(code)`; unmapped/transient still throw; duplicate logical key returns the stored receipt outcome), `src/Bridge/StrictPayviaSubscriptionEventBridge.php` (call `projectWithOutcome`, then `SubscriptionProjectionAcknowledger::acknowledge(...)` AFTER the receipt transaction commits — resolve the acknowledger lazily from the container, only when the payvia contract exists), `src/Projection/ProviderEventData.php` (`origination_uuid` into `METADATA_ALLOW`), `src/Projection/SubscriptionEventProjector.php` (activation path verifies metadata `origination_uuid` equals the reserved row's `checkout_origination_uuid`; mismatch ⇒ deterministic `rejected('origination_mismatch')`, reservation NOT overwritten), `composer.json` (require-dev payvia ^2.5)
- Test: `tests/Integration/ProjectionOutcomeTest.php`, `tests/Integration/StrictBridgeAcknowledgementTest.php`

- [ ] **Step 1: Failing tests:** outcome matrix (accepted; each deterministic rejection code; throw for unmapped/transient with NO acknowledgement — recording acknowledger double); duplicate replay returns stored outcome; ack ordering (never before receipt commit — transaction spy); origination match activates the reserved row through the real `subscription.created` path; mismatched historical origination rejects without overwrite; receipts tie outcome to `origination_uuid` via the allowlist.
- [ ] RED → implement → GREEN + gates → commit `feat: outcome-returning projection with payvia acknowledgement`.

### Task 13: Per-gateway purchasability + Release 2.2.0

**Files:**
- Create: `migrations/008_PlanProviderIdentifiers.php` (`subscription_plans.provider_identifiers` JSON nullable), `src/Plans/PlanPurchasability.php`
- Modify: `src/Plans/PlanManagementService.php` + payload validator (accept/validate the map on create/update/import-config: keys `/^[a-z0-9_-]{1,50}$/`, non-empty string values ≤191), `src/Schema/SubscriptionSchemaReadiness.php`, `CHANGELOG.md`, `README.md`, `composer.json` (version 2.2.0)
- Test: `tests/Integration/Plans/PlanPurchasabilityTest.php`

**Interfaces (verbatim §4.2):** `PlanPurchasability::forGateway(ApplicationContext $context, string $gateway): array` — `list<array{plan_uuid, plan_key, name, provider_identifier}>`, audience tenant + active + identifier present. Scalar `provider_price_id` NEVER read for purchasability (mutation test: set scalar only ⇒ not purchasable).
- [ ] **Step 1: Failing tests:** projection matrix (audience/status/identifier); validation bounds each write path; scalar-never-read mutation test; readiness updated.
- [ ] RED → implement → GREEN + gates. Release steps: CHANGELOG 2.2.0 (four features, additive), README, version bump, wording per spec §4. Commit `Release 2.2.0 — checkout reservation and purchasability` + local tag `v2.2.0`. NOTHING pushed.
- [ ] **HARD PUBLICATION GATE:** maintainer publishes subscriptions 2.2.0. Verify clean `^2.2` (and payvia `^2.5`) resolution before Task 14.

# PHASE C — Thallo

Work from `/Users/michaeltawiahsowah/Sites/glueful/thallo` (branch `dev`). Anchors: `packages/thallo-subscriptions` (EngineGateway, RespondsEngineUnavailable, EnginePreemptionServiceProvider — boot()-only wiring rule), `app/Content/Authorization/CapabilityCatalog.php`, `config/tenancy.php` role matrix, `app/Http/Controllers/TenancyAccessController.php`, `app/Settings/SystemKeys.php`, `packages/thallo-contracts`, `packages/thallo-render` (pricing_plan block), commerce workspace route groups as middleware template.

### Task 14: Dependencies + billing.manage + manage_billing access flag

**Files:**
- Modify: root `composer.json` (+ lock: `glueful/payvia: ^2.5`, `glueful/subscriptions: ^2.2`), `app/Content/Authorization/CapabilityCatalog.php` (`billing.manage`, grantable), `config/tenancy.php` (owner grant), `app/Http/Controllers/TenancyAccessController.php` (+`manage_billing`, evaluated against the resolved workspace exactly like `manage_members`), `admin/src/queries/tenancyAccess.ts` (type + field)
- Test: `tests/Integration/Subscriptions/BillingManageCapabilityTest.php`

- [ ] Steps: composer update both packages + `php glueful migrate:run`; failing tests (catalog grantable + owner-matrix grant + platform-only capabilities untouched; access endpoint returns `manage_billing` true for owner / false for plain member / true via operator bypass with `tenancy.access_any` only under operator mode); implement; full PHP gates + vitest/type-check (type change); commit `feat(subscriptions): billing.manage capability and access flag`.

### Task 15: Operator switch `self_serve_checkout_enabled`

**Files:**
- Modify: `app/Settings/SystemKeys.php` (+`self_serve_checkout_enabled`), platform subscriptions meta (`packages/thallo-subscriptions/src/Http/MetaController.php` exposes it), platform settings write surface (mirror the existing system-flag edit endpoint idiom; enable-time validation: active gateway implements subscription initiation — probed via payvia `GatewayManager::supports($gateway,'subscription_checkout')` resolved lazily; disable always allowed)
- Test: `tests/Integration/Subscriptions/SelfServeSwitchTest.php`

- [ ] Steps: failing tests (default false on fresh install; platform authority required to write; enable refused without capable gateway, allowed with; disable always; meta exposes boolean); implement; gates; commit `feat(subscriptions): self-serve checkout operator switch`.

### Task 16: Workspace billing API — meta + checkout origination

**Files:**
- Create: `packages/thallo-subscriptions/src/Http/SelfBillingController.php`, `packages/thallo-subscriptions/src/Checkout/WorkspaceCheckoutCoordinator.php`, `packages/thallo-subscriptions/routes/billing-routes.php`
- Modify: provider `boot()` (load new route file inside the capability gate)
- Test: `tests/Integration/Subscriptions/WorkspaceBillingSelfServeTest.php`

**Interfaces:** Group `/v1/admin/billing`, middleware `['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding']`, per-route `content_permission:billing.manage`, names `thallo.subscriptions.billing.*`. `GET /meta` per §5.2 (200 after auth even when engine unavailable; no `can_manage_billing` field). `POST /checkout {plan_key}` + required `Idempotency-Key` header (opaque token 16–128 chars): recheck switch → refusal matrix (`subscription_already_active` incl. unexpired non_renewing, `checkout_pending` + stored URL, `plan_not_purchasable` via `PlanPurchasability::forGateway`) → `WorkspaceCheckoutCoordinator::checkout(context, tenantUuid, planRow, actorUuid, idempotencyKey)`: Payvia `prepare()` whose continuation calls `reserveCheckoutFor(subject, plan_uuid, originationUuid, ['actor'=>$actorUuid, 'replace'=>true])` in the SAME transaction; after commit `initializeClaim()` → `{checkout_url}`. Consumer metadata `{tenant_uuid, subject_type, subject_uuid, plan_uuid, glueful_consumer: 'subscriptions', actor_user_uuid}`; `required_projection_consumer='subscriptions'`; `customer_email` = acting user's verified email; URLs from canonical admin origin (`/billing/return?origination=<uuid>`). All engine access via `EngineGateway` single-probe-per-action.
- [ ] **Step 1: Failing tests:** authority matrix (owner 200 / member-without-grant 403 / platform-only-tenancy.manage-without-membership 403 / anonymous 401); switch-off 409 `self_serve_disabled` rechecked at request time; full refusal matrix; happy path end-to-end against a recording payvia double registered in-container (reservation row + origination created atomically; provider failure after commit leaves reservation + `initializing` for same-key recovery; definitive 4xx leaves reservation + `failed`); missing/malformed Idempotency-Key 422; meta shape incl. blocked/live origination states; pgsql-gated concurrent plan-A/plan-B: exactly one live guard, loser 409.
- [ ] RED → implement → GREEN + gates → commit `feat(subscriptions): workspace self-serve checkout API`.

### Task 17: Cancel, abandon, operator reconciliation command

**Files:**
- Modify: `packages/thallo-subscriptions/src/Http/SelfBillingController.php`, `routes/billing-routes.php`
- Create: `packages/thallo-subscriptions/src/Console/CheckoutResolveCommand.php` (`subscriptions:checkout:resolve <origination> --resolution= --note=`)
- Test: `tests/Integration/Subscriptions/WorkspaceBillingCancelAbandonTest.php`

**Interfaces:** `POST /cancel {mode}`: requires local row with `provider_subscription_id` (`not_provider_managed` 409 otherwise); `mode` ∈ driver `cancellationModes()` (422 otherwise); provider cancel via payvia; ZERO subscription-row writes (byte-compare test); actor to audit sink; works with switch off. `POST /checkout/abandon`: §3.1 lifecycle capability; success only on `confirmed_dead` (origination → `abandoned`, guard opens, reservation released via `releaseCheckoutReservation`); Paystack ⇒ 409 `checkout_abandonment_unsupported`. Console command: platform authority, wraps payvia `CheckoutReconciliationService::resolve()` with continuation releasing the exactly-bound reservation; non-empty `--note` required; prints no provider payload/PII; not exposed via workspace routes.
- [ ] **Step 1: Failing tests:** cancel matrix (mode validation per driver double, zero-write byte-compare, switch-off still works, audit written); abandon matrix (Stripe confirmed_dead full release chain; still_live refuses; Paystack 409); resolve command both resolutions + evidence rules + reservation release same-transaction + refusals.
- [ ] RED → implement → GREEN + gates → commit `feat(subscriptions): cancel, abandonment, and operator reconciliation`.

### Task 18: Pricing bridge (contract + block + resolver binding)

**Files:**
- Create: `packages/thallo-contracts/src/Billing/PlanCheckoutUrlResolver.php` (verbatim §5.4 interface), `packages/thallo-subscriptions/src/Bridge/AdminBillingPlanCheckoutUrlResolver.php`
- Modify: `packages/thallo-render/themes/default/templates/blocks/pricing_plan.twig` + the block's data schema/editor field registration (optional `plan_key`), render-side resolver consumption (soft-bound: `has()` probe, null-tolerant), thallo-subscriptions provider `services()` (binding), thallo-contracts + render + subscriptions pack composer wiring
- Test: `tests/Integration/Render/PricingPlanCheckoutBridgeTest.php`, `tests/Integration/Subscriptions/PlanCheckoutUrlResolverTest.php`

**Interfaces:** resolver binding returns `{admin_url}/billing?plan=<key>` from canonical admin origin, null when capability/engine unavailable. Render: `plan_key` validated `/^[a-z0-9._-]{1,100}$/` (invalid ⇒ treated absent); CTA uses resolver URL when non-null, else authored `button_url` unchanged; well-formed unknown keys STILL deep-link (resolver makes no existence promise — §5.4).
- [ ] **Step 1: Failing tests:** degradation matrix (capability off / engine off / resolver unbound / malformed key ⇒ authored URL byte-identical; well-formed key ⇒ deep link regardless of catalog existence); URL from canonical origin not Host header; binding null-safety; block renders authored copy untouched in every case.
- [ ] RED → implement → GREEN + gates → commit `feat(render): pricing plan checkout deep-link bridge`.

### Task 19: SPA — workspace Billing page + platform identifier editing

**Files:**
- Create: `admin/src/pages/billing/index.vue`, `admin/src/pages/billing/return.vue`, `admin/src/queries/workspaceBilling.ts`, components under `admin/src/pages/billing/components/` (PlanPicker.vue, CheckoutPendingPanel.vue, CancelDialog.vue)
- Modify: `admin/src/registry/subscriptionsModule.ts` (nav gated on `manage_billing`), platform Plans editor (`admin/src/pages/subscriptions/plans/` — provider_identifiers map editing), `admin/src/queries/subscriptionsBilling.ts` (plan payload type)
- Test: `admin/src/__tests__/workspace-billing.spec.ts`, extend plans specs

- [ ] **Step 1: Failing vitest specs:** meta-first states per §5.3 (engine unavailable, switch off, plan picker, live origination resume/abandon incl. Paystack no-abandon, active + cancel per-mode confirm, non-renewing access-until, projection-rejected/late-settlement contact-operator, provider-managed, canceled, meta-error branch); idempotency-token discipline (one token per click, reused across retries, rotated only after terminal or different live attempt via /meta); return page polls meta and never mutates; deep-link `?plan=` preselection survives login return-to; identifier map editor round-trip + 422 rendering.
- [ ] RED → implement → GREEN (`npx vitest run`, `npm run -s type-check`, `npm run -s build`; PHP suite untouched) → commit `feat(subscriptions): workspace billing SPA and plan identifier editing`.

### Task 20: End-to-end truth table + docs

**Files:**
- Create: `tests/Integration/Subscriptions/SelfServeCheckoutTruthTableTest.php`
- Modify: `docs/internal/OUTSTANDING.md` (mark §B checkout + bridge shipped; add follow-ups: Stripe Billing Portal/plan changes, public funnel), `docs/internal/DISTRIBUTION.md` (billing.manage note), `docs/internal/composable-core` docs touched by capability list conventions
- Test: the truth table itself

- [ ] **Step 1: Failing truth table (spec §6 matrix):** rows for capability off (routes 404), engine off (409/200+state), switch off (origination 409 / cancel works), no billing.manage (403), plan-not-purchasable, active subscription, live origination, blocked (projection_rejected) — each a genuinely distinct boot/fixture, non-vacuity verified by flipping one gate per row (note evidence in report).
- [ ] RED where meaningful → implement gaps → GREEN + FULL gates (PHP + admin) → docs edits → commit `feat(subscriptions): self-serve checkout truth table and docs`.

---

## Self-Review

- **Spec coverage:** §1 rulings → Tasks 14–19 (authority/switch/operations/bridge) + Task 1/3 (gateway pins); §2 order/gates → phase headers + Tasks 9/13 gates; §3.1 → Tasks 1–3; §3.2 → Task 5; §3.3 → Task 4 (+7 conflict, +8 finalizer states); §3.4 → Tasks 6–7; §3.5 → Tasks 1/3/16 (metadata contents pinned in each); §3.6 → Task 8 (+12 consumer side); §3.7 → Tasks 1 (modes) + 11 (projection); §3.8 → Tasks 9 + 17; §4.1 → Task 10 (+12 activation verify); §4.2 → Task 13; §4.3 → Tasks 11–12 (+17 release seam); §5.1 → Tasks 14–15; §5.2 → Tasks 16–17; §5.3 → Task 19; §5.4 → Task 18; §6 → Task 20 + per-task refusal tests; §7 test lists distributed into the matching tasks' Step 1s; §8 honored (no portal, no funnel, no hydration tasks). No gaps found.
- **Placeholder scan:** clean — every task carries exact signatures, exact refusal codes, exact table/column values, or names the spec section whose verbatim text governs; no TBDs.
- **Type consistency:** `SubscriptionCheckoutRequest` fields (Task 1) = `prepare()` request fields (Task 5) = coordinator call (Task 16); `reserveCheckoutFor(subject, planUuid, originationUuid, opts)` identical in Tasks 10/12/16; `ProjectionOutcome` (Task 12) feeds the Task 8 acknowledger's `outcome` strings; guard/ledger state names identical across Tasks 4/5/7/8/9/17; `manage_billing` flag name identical in Tasks 14/19; `PlanPurchasability::forGateway` identical in Tasks 13/16.
- **Cross-repo sequencing:** Paystack fixture gate blocks Task 3; payvia publication gate blocks Phase B; subscriptions publication gate blocks Phase C; Task 12's require-dev payvia ^2.5 is satisfied because Phase B starts only after the payvia gate.
