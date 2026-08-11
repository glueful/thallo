# Payment Links for Admin Orders — Design

**Date:** 2026-08-11
**Cycle:** Orders program cycle 3 — the "Payvia payment links + customer emailing" follow-up from the admin-order-creation cycle.
**Posture:** THREE-release train, strict order: **Payvia 2.6.0** (ensure-live session semantics) → **Commerce 1.11.0** (payment-link machinery) → **Thallo** (landing page, admin controls, email, SPA). Each upstream release is human-run (push dev, PR dev→main, merge, tag, Packagist) behind an explicit publication gate; no path-repo/vendor-edit workarounds.
**Primary scenario:** phone/remote orders — admin finalizes a walk-in/delivery order, sends the customer a durable payment link; the customer pays through the hosted gateway page; webhooks settle. The in-store complete-sale path is untouched.

---

## §1 Rulings

1. **The link is an app landing page, never a raw gateway URL.** `/checkout/pay/{token}` identifies a revocable payment-link record; the Pay action ensures a live gateway session server-side. Gateway URLs/references stay server-controlled; webhooks remain the settlement authority; the landing page only displays current state.
2. **Token custody:** ≥256-bit random, presented as exactly **64 lowercase hex characters**; stored HASHED only (SHA-256); tenant/order-bound; purpose-bound to payment (never a guest credential — no order history, downloads, address edits, cancellation, refunds, notes, or account ownership); revocable; expiring; excluded from application-side plaintext persistence (databases, queues, logs, audit rows — the recipient's mailbox necessarily contains the link). Raw token egress: the mint/regenerate response's one-time surface, and the send-time email body. Nothing else — not order events, not projections, not status reads.
3. **Link TTL is an explicit stock-reservation lease.** Default `commerce.payment_links.ttl_days` = 7, operator-configurable clamped 1–30. While an admin-origin `pending_payment` order holds an ACTIVE unexpired link, the ordinary 60-minute expiry sweep skips it; expiry/revocation returns the order to the next ordinary sweep. Storefront orders unchanged. Paid/canceled orders make the link immediately unusable. Sweep and payment initiation recheck link/order state transactionally (the candidate-query filter is a prefilter only). Admin UI shows "Stock reserved until …" with revoke/cancel actions.
4. **Delivery = copy + email; the page is pay-only.** Copy always available; email only when the order has an address AND the `payment_request` template toggle (default **OFF**) is on. Send is an explicit admin action — mint never auto-sends.
5. **Ensure-live, never force-fresh.** Payvia's `initiate()` ensures ONE provably live hosted session per payable: no intent ⇒ create; confirmed-live ⇒ same URL; confirmed-dead ⇒ renew; unknown provider state ⇒ fail closed, never replace. Commerce never requests an unconditional fresh session. This is a Payvia-only change (the existing contract permits returning/refreshing the same logical intent) — no contracts-package release.
6. **Paystack renewal is unavailable in 2.6.0.** A new initialization does not prove the old authorization URL dead, and a late second settlement is only detected after a double charge. Renewal requires provider-confirmed permanent death; no timeout guessing. UI/docs must not imply link regeneration renews an indeterminate Paystack session — the merchant's recovery is offline completion (mark-paid) or cancel/recreate.
7. **One active link per order**, enforced transactionally (not by index): mint locks the tenant-owned order, revokes the current active link, inserts the replacement — one transaction. Mint, revoke, initiate, and expiry all use the same order-before-link lock order.
8. **Hash-only custody defines the admin flows:** Create/Regenerate return the raw URL once; while visible, "Send this link" is offered; after it's gone, "Regenerate and send" (with confirmation that the previous link becomes invalid). There is no encrypted-retrieval path.
9. **URL composition lives in Thallo.** Commerce returns the raw TOKEN once; Thallo composes `/checkout/pay/{token}` via a new `ShopUrlGenerator::paymentLink()` and absolute email URLs via `CanonicalPublicOriginResolver` — never the request Host.
10. **Engine-native ownership** (Commerce 1.11.0): link record, token custody, TTL/lease semantics, expiry integration, initiation rules, terminal behavior. Thallo owns only its application surfaces (route/page, admin controls, email template + toggle, allowlist/SPA, artifacts).

## §2 Component contracts

### 2.1 Payvia 2.6.0 — ensure-live sessions (repo: extensions/payvia)

- **`PayviaPaymentCollector::initiate()` becomes ensure-live** (Ruling 5). Backed by **reference-addressable session attempts**:
  - **New-row supersession:** renewal preserves the old intent row as `superseded` (its provider reference remains webhook-addressable), inserts a new open attempt; exactly one OPEN attempt per payable; concurrent renewal calls serialize (payable-scoped locking).
  - **Per-durable-attempt idempotency:** an attempt UUID is claimed BEFORE provider I/O and reused across timeouts/crashes — Stripe's idempotency key and Paystack's reference derive from it. A retry of one renewal returns the same provider session; a later confirmed renewal claims a new attempt UUID. (Fixes the fixed per-payable Stripe key at `StripeGateway.php:148`.)
  - **Stripe renewal:** status → expire → re-fetch; only `confirmed_dead` permits supersession. `completed`, still-open, transport failure, or unparseable state never frees the old intent.
  - **Paystack:** create + confirmed-live reuse only; expired-without-proof/unknown ⇒ typed failure; renewal unavailable (Ruling 6).
- **`ConfirmationDispatcher` becomes reference-aware:** resolves and closes the exact `(tenant, gateway, provider reference)` intent row — never "whichever is open for the payable" (`ConfirmationDispatcher.php:37`). A webhook for a superseded attempt attributes to that attempt; settlement of a superseded session against an already-paid payable is refused by the existing order CAS (fixture-proven on real supersession paths — Stripe).
- Provider matrix (fixtures): Stripe — create / confirmed-live / confirmed-dead renewal / unknown-fails-closed; Paystack — create / confirmed-live reuse / unknown-or-expired-without-proof fails closed, renewal unavailable. Double-settlement tests apply to actual supersession paths only.

### 2.2 Commerce 1.11.0 — the payment-link machinery (repo: extensions/commerce)

- **Table `commerce_payment_links`:** `id` PK autoincrement; `uuid` vc12; `tenant_uuid` vc12; `order_uuid` vc12; `token_hash` vc64; `status` closed `active|revoked|expired|consumed`; `expires_at` NOT NULL; `created_by` vc12; `consumed_at`/`revoked_at` nullable; timestamps. Unique `(tenant_uuid, token_hash)`; index `(tenant_uuid, order_uuid, status)`. Tenant purge/adoption registered. The transactional one-active authority is Ruling 7 — the index is not the enforcement mechanism.
- **`PaymentLinkService`:**
  - `mint(context, tenant, orderUuid, ttlDays, actorUuid): {rawToken, link}` — only for tenant-owned `origin='admin'` `status='pending_payment'` orders (else typed conflict); TTL clamped 1–30 (default from config); one transaction per Ruling 7; the raw token appears in this return value and nowhere else engine-side.
  - `revoke(context, tenant, orderUuid, actorUuid): void`.
  - `resolveByToken(context, rawToken): ?LinkView` — shape-gate (64 lowercase hex) before any lookup; hash and query under the host-resolved tenant; unknown/malformed/cross-tenant are indistinguishable (one generic null ⇒ 404). `LinkView` = the pay-only projection: store-identity fields the caller supplies, order reference (number), line names/quantities, totals, currency, payment/link state, `expires_at`. EXCLUDED: email, phone, addresses, user uuid, notes, internal ids, token, token_hash.
  - `initiateByToken(context, rawToken, ...): {checkoutUrl}` — transactionally (order-before-link lock) revalidates link `active` + unexpired + order still `pending_payment`, increments the **engine-owned atomic per-link initiation counter** (window: `commerce.payment_links.initiations_per_hour`, default 10, counted BEFORE provider I/O), then calls Payvia's ensure-live `initiate()`. Renewal-unavailable (Paystack dead-session) surfaces as a typed "temporarily unavailable — contact the merchant" state, not an exception leak.
  - Terminal transitions: order paid ⇒ link `consumed` (eagerly where OrderPaid is observed; lazily on resolve); canceled/refunded order ⇒ resolve returns honest state; TTL passed ⇒ `expired` lazily on resolve + swept.
- **Expiry integration:** `ExpiryService` candidate query adds the NOT-EXISTS(active unexpired link)-for-admin-origin prefilter; INSIDE each per-order transaction it locks/reloads the order and rechecks for an active unexpired link before releasing stock or transitioning (closes the mint-vs-sweep race, `ExpiryService.php:45` shape).
- Catalog entries (manage mode): `orders.payment_link.store|destroy|show` (mint returns the raw token once; `show` returns state/expiry only — never token or hash).

### 2.3 Thallo — public landing + initiation

- `GET /checkout/pay/{token}` (shop-routes, reserved-path guarded, never page-cached): shape-gate before lookup; render the LinkView states — active ⇒ summary + Pay form; paid ⇒ thank-you; revoked/expired/canceled ⇒ honest "no longer valid — contact the merchant". Headers on ALL responses: `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow, noarchive`. Zero third-party assets. Generic 404 for unknown/malformed/cross-tenant.
- `POST /checkout/pay/{token}/initiate`: **ShopCsrfGuard** (established anonymous-checkout-POST policy) + Thallo IP rate limit + the engine per-link counter; a **no-JS form POST** answering **303** to the trusted HTTPS gateway URL; same headers repeated on the POST response.
- Log-redaction guidance for the token path segment documented (app + reverse-proxy examples).
- `ShopUrlGenerator::paymentLink(rawToken)` composes the path; absolute URLs via `CanonicalPublicOriginResolver` (Ruling 9).

### 2.4 Thallo — admin surfaces

- Pack endpoints (manage): mint (raw URL once, composed Thallo-side), revoke, status; **send** (`POST /orders/{uuid}/payment-link/send`) — idempotency-keyed so retries/double-clicks cannot mint two links or email an already-revoked one. Send semantics per Ruling 8: "Send this link" while the raw URL is visible; "Regenerate and send" afterwards (confirmation shown; new link supersedes). On delivery failure the new link STAYS active and its raw URL returns on the original response for manual copy.
- **`PaymentRequestMailer` (dedicated, synchronous):** calls the registered rich email channel directly (`EmailChannel::sendNotification()` — renders editable templates WITHOUT notification/queue persistence, the template test-send pattern). Never `NotificationService::send()` (which persists the full payload — would store the raw token, `NotificationService.php:164`). Delivery failure returns a typed result; never auto-queued. Persisted delivery receipt: order uuid, link uuid, recipient hash, outcome, timestamps — never the token or rendered body.
- Email template `payment_request`: `EmailSettingsController::TEMPLATES` + `CommerceEmailTemplates` definition (editable; placeholder chips incl. expiry date; the link uses the EXISTING validated **`action_url`** placeholder — no new URL placeholder); toggle default **OFF**; substitution at send time only (stored template never contains a token).
- SPA order-detail "Payment link" card (`origin='admin'` + `pending_payment`): Create (TTL input within clamp), one-time copy surface, "Stock reserved until …", Regenerate, Revoke, Send (visible only when order email present + toggle on; disabled-with-reason otherwise). Paystack honesty: the card never claims regeneration revives a dead gateway session; the documented recovery is mark-paid (offline) or cancel/recreate. Complete-sale untouched; drafts have no link surface.

### 2.5 Artifacts

One pass after the Thallo repin: openapi regen (cache-deletion first per the known generator bug), PACK_OWNED_ROUTES/allowlist/parity fixtures, SPA schema + typed clients, OUTSTANDING shipped entry.

## §3 Test matrix

**Payvia:** the §2.1 provider matrix; reference-aware confirmation (webhook for superseded attempt closes THAT row; open attempt untouched); one-open-attempt serialization under concurrent renewal; per-attempt idempotency (retry same session, confirmed renewal new attempt); double-settlement refusal on real supersession (Stripe); Stripe status→expire→re-fetch state table (completed/open/transport-fail/unparseable never free the intent).
**Commerce:** mint conflicts (non-admin-origin, non-pending, cross-tenant); concurrent mint ⇒ one active link (locked-order serialization); regenerate revokes prior; TTL clamp; token shape-gate; resolve indistinguishability (unknown/malformed/cross-tenant); LinkView exclusion list (seed PII sentinels, assert absent); initiation counter atomically enforced before provider I/O; sweep race (mint inside the sweep's window ⇒ order survives; the transactional recheck proven, not just the prefilter); lazy + eager terminal transitions; raw-token no-egress ratchet (grep-style + response assertions); order-before-link lock order documented + concurrency-tested.
**Thallo:** landing state matrix + headers on GET and POST + generic 404 triple; CSRF guard on initiate; 303 to gateway URL; IP rate limit; admin card flows (one-time custody, Regenerate-and-send confirmation, delivery-failure keeps link + returns URL, send idempotency double-click); PaymentRequestMailer persistence audit (notification tables empty of tokens; receipt shape exact); template toggle default-off + `action_url` substitution; canonical-origin absolute URLs (never request Host); lease visible in UI; allowlist/parity/openapi/schema gates; full suites all repos.

## §4 Follow-ups (recorded, out of scope)

- Paystack renewal (pending a provider-confirmed death signal or payment-request API adoption).
- Guest self-service custody for admin orders (unchanged from cycle 2's list — the payment token deliberately grants none of it).
- SMS/WhatsApp delivery channels (copy covers them manually today).
- Link analytics (opened/clicked) — would require relaxing the no-analytics landing rule; deliberately excluded.
