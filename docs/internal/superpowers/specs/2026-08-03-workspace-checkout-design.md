# Workspace Self-Serve Checkout — Design

Provider session origination so a workspace can self-serve subscribe to and cancel its own
platform plan, plus the pricing-blocks → billing deep-link bridge (OUTSTANDING §B). Builds on
shipped thallo-subscriptions (platform admin), glueful/subscriptions 2.1, glueful/payvia 2.4.

**Canonical flow (the essential sequence — every section serves it):**

```
reserve local pending subscription (engine, non-entitling)
  → claim Payvia origination (ledger row, idempotency key)
    → initialize provider checkout (Stripe subscription session / Paystack plan initialize)
      → buyer pays on the provider's hosted page
        → webhook correlates origination_uuid → ledger adopts ownership, enriches metadata
          → strict-lane projection activates the local subscription
            → post-dispatch finalizer marks the origination dispatched
```

Webhooks plus successful strict-lane projection are the ONLY activation authority. Returns,
the ledger, and the SPA are presentational.

## §1 Rulings (all pinned by the maintainer)

**Surface & buyer.** Checkout lives on a workspace-scoped Billing page in the admin SPA.
Subscription subject: the workspace. Acting buyer: a signed-in user with billing authority
for that workspace. Payment identity at the provider: the workspace, never the individual
user. Audit actor: the initiating user UUID (recorded locally, never sent to the provider).
Ownership stays correct if the administrator later leaves. The public signup-to-checkout
funnel is a later acquisition project that reuses the same checkout service — no Payvia
redesign may be required for it.

**Authority.** New workspace-grantable `billing.manage` capability in the CapabilityCatalog,
granted to `owner` by default in the role→capability matrix, delegable per workspace. Routes
use the workspace identity chain + `content_permission:billing.manage`. Boundaries:
- never permits editing the platform plan catalog;
- never permits acting on another workspace;
- checkout metadata derives the workspace UUID server-side, never from request input;
- subscription changes record the authenticated user as audit actor;
- removing the capability immediately blocks new billing actions without touching the
  subscription itself.
`tenancy.manage` (platform) and `billing.manage` (workspace) are disjoint authorities.

**Gateways.** Both Stripe and Paystack. Stripe: Checkout Session `mode=subscription` with the
plan's Stripe price ID. Paystack: transaction initialization with the plan's `plan_code`.
Shared request: workspace subject, immutable local plan_uuid, return/cancel URLs, customer
email, idempotency key. Shared result: provider checkout reference and redirect URL.
Settlement: webhooks authoritative; return routes display status only. A plan is purchasable
only when it has a valid identifier for the selected gateway. The capability fails explicitly
as unavailable when a gateway lacks valid recurring-plan configuration — NEVER falls back to
a one-time charge.

**Operations.** Subscribe + cancel only.
- Start the workspace's first provider-backed subscription.
- Cancel through the active gateway (default: stop renewal). Cancellation request mutates NO
  local state; webhooks project the outcome.
- Refuse another checkout while a provider subscription is active or an origination is live.
- Plan changes on an active provider subscription are disabled with "cancel first or contact
  your platform operator." Provider-native plan changes / Stripe Billing Portal are a
  separate capability and review cycle.

**Operator switch.** Platform-scoped setting `self_serve_checkout_enabled`, default false on
fresh AND upgraded installs. Gates checkout origination only: disabling immediately blocks
new checkout sessions; existing subscriptions, webhooks, cancellation, and operator
management continue. Server-side enforcement authoritative (rechecked at request time, not
just page load); SPA state presentational; exposed on `/meta`. Enabling requires a compatible
configured gateway; disabling is always possible.

**Pricing bridge.** `pricing_plan` gains an optional `plan_key` (navigation intent only,
platform SaaS plans only in this phase). The billing URL is generated server-side through a
soft-bound contract; `plan_key` is untrusted input validated against the plan-key format;
the selected plan survives login/workspace-signup/return-to; the workspace resolves from
authenticated context, never block data or query parameters; when capability, engine, or
resolver is unavailable the authored CTA URL renders unchanged; the billing page revalidates
plan existence, status, gateway compatibility, and eligibility before checkout; no claim is
made that displayed prices match provider amounts — authored copy stays authoritative.

## §2 Program shape

| Phase | Repo | Release | Gate |
|---|---|---|---|
| A | glueful/payvia | 2.5.0 — subscription checkout capability, origination ledger, correlation + finalizer | publication gate |
| B | glueful/subscriptions | 2.2.0 — `reserveCheckoutFor` seam, per-gateway purchasability projection | publication gate |
| C | thallo | billing.manage, operator switch, workspace billing API + SPA, bridge | — |

A and B are independent of each other; C consumes both published releases. No path
repositories — each gate requires clean resolution from the published registry.

## §3 Payvia 2.5.0

### §3.1 Gateway capability

```php
interface SubscriptionInitiationCapableGateway
{
    /** @return array{reference:string, checkout_url:string, expires_at:?string, raw:array} */
    public function initializeSubscription(SubscriptionCheckoutRequest $request): array;
}
```

- **Stripe**: `POST /v1/checkout/sessions` with `mode=subscription`,
  `line_items[0][price] = <provider identifier>`, `client_reference_id = origination_uuid`,
  `metadata[origination_uuid]`, AND `subscription_data[metadata][origination_uuid]` — session
  metadata does NOT propagate to the subscription object; `subscription_data.metadata` is the
  documented mechanism that does. `success_url` required, `cancel_url` optional,
  `customer_email` set, `Idempotency-Key: payvia-subinit-<origination_uuid>`. Response
  validation mirrors `initialize()` (id `cs_`, absolute-HTTPS URL); `expires_at` from the
  session's `expires_at`.
- **Paystack**: `POST /transaction/initialize` with `plan = <plan_code>`, required `email`,
  `callback_url`, `metadata[origination_uuid]`, reference derived from the origination UUID.
  Amount is plan-derived by Paystack. `expires_at` null (Paystack does not return one; the
  ledger applies the configured origination TTL for display only — see §3.3 on why TTL never
  frees the guard).
- A gateway that does not implement the interface, or a request whose provider identifier is
  empty, fails as unavailable BEFORE any ledger write or provider call. No code path may fall
  back to `InitiationCapableGateway` (one-time mode).

**Paystack correlation proof (blocking requirement).** Stripe documents
`subscription_data.metadata` propagation; Paystack's documentation does NOT establish that
transaction metadata reaches the `subscription.create` event. Phase A's Paystack task MUST
capture sandbox fixtures (real webhook payloads from a plan-carrying initialize → charge →
subscription sequence) proving a deterministic join before its implementation is locked:
- If `subscription.create` carries the transaction metadata → correlate directly on
  `origination_uuid` (same as Stripe).
- If not → implement the documented two-event chain: `charge.success` (which carries
  transaction metadata and reference) correlates the origination and records
  `customer_code` + `plan_code` on the ledger row; `subscription.create` then joins on
  `(gateway, customer_code, plan_code)` against originations awaiting that join. The join
  window is the origination's lifetime; ambiguity (two awaiting originations with the same
  pair) fails closed to the unresolved-ownership path.
The captured fixtures become permanent test fixtures. No assumption ships.

### §3.2 SubscriptionCheckoutService

`originate(ApplicationContext $context, SubscriptionCheckoutRequest $request): SubscriptionCheckoutResult`

Request (consumer-agnostic; the later public funnel supplies the same shape):
`tenant_uuid`, opaque `subject_key` (e.g. `tenant:<uuid>`), `gateway` (default from
`PayviaSettings::defaultGateway`), `provider_plan_identifier`, `consumer_metadata` (closed
map, stays local — see §3.5), `customer_email`, `return_url`, `cancel_url`,
`idempotency_key` (caller-supplied).

Order of operations (each step idempotent):
1. Validate: gateway enabled + implements `SubscriptionInitiationCapableGateway`; provider
   identifier non-empty. Fail `unavailable` before any write.
2. Claim: insert ledger row status `initializing` with the caller's idempotency key
   (unique per tenant). Same-key replay returns the existing row — including its
   `checkout_url` when already minted. A DIFFERENT key while a live origination exists for
   the same `subject_key` is refused (`origination_live`).
3. Initialize: call the gateway with the provider idempotency key derived from the
   origination UUID. On success persist `checkout_reference`, `checkout_url`,
   `provider_expires_at`, transition `initializing → pending`.
4. Return `{origination_uuid, checkout_url, status}`.

**Crash/replay contract for `initializing`:** a timeout or unknown network outcome does NOT
free the idempotency key and does NOT free the live-guard. Replay with the same caller key
resumes step 3 with the SAME provider idempotency reference, so the provider deduplicates.
The `checkout_url` cannot be reconstructed — it is stored, and re-serving it is the recovery
path. `initializing` rows past a recovery threshold are surfaced (diagnostics/console), never
auto-expired.

### §3.3 Origination ledger

New table `subscription_checkout_originations`:

| column | notes |
|---|---|
| `uuid` (12, unique) | the opaque `origination_uuid` stamped into provider metadata |
| `tenant_uuid` (12) | owner; adopted by correlation |
| `subject_key` (191) | opaque consumer subject; live-guard scope |
| `gateway` (50), `provider_plan_identifier` (191) | |
| `idempotency_key` (191) | unique `(tenant_uuid, idempotency_key)` |
| `checkout_reference` (191, nullable) | Stripe session id / Paystack reference |
| `checkout_url` (2048, nullable) | stored — not reconstructable |
| `provider_subscription_id` (191, nullable) | recorded at correlation |
| `provider_customer_code` / `provider_plan_code` (191, nullable) | Paystack two-event join |
| `status` (24) | see state machine |
| `live` (bool) | guard flag, maintained with status |
| `consumer_metadata` (json) | subject/plan/actor — LOCAL ONLY |
| `provider_expires_at`, timestamps | |

Indexes: `unique(uuid)`, `unique(tenant_uuid, idempotency_key)`, `index(gateway,
checkout_reference)`, `index(subject_key, live)`.

**State machine** (transitions monotonic and idempotent; replays never regress):

```
initializing → pending → provider_observed → dispatched
     └→ failed                pending → expired | abandoned
```

- `initializing`: key claimed, provider outcome unknown. Live. Never times out into free.
- `pending`: provider session minted, URL stored. Live.
- `provider_observed`: a signed webhook correlated this origination; provider ids recorded. Live.
- `dispatched`: the post-dispatch finalizer confirmed projection (§3.6). Terminal, not live.
- `failed`: provider initialization definitively rejected (4xx). Terminal.
- `expired`: provider-confirmed expiry only (see below). Terminal.
- `abandoned`: explicit protocol only. Terminal.

**Live-guard vs origination identity (duplicate-subscription defense).** The origination row
is PERMANENT correlation identity: a webhook arriving after any terminal state still
correlates by `origination_uuid`, still adopts ownership, and transitions the row to
`provider_observed` (terminal-to-observed is the one sanctioned "regression", because money
moved and the event must land). The live-guard is a separate, narrow question — "may this
subject originate another checkout?" — and local TTL elapse alone NEVER answers it, because a
hosted checkout may complete after the TTL. Reopening requires:
- **provider-confirmed expiry**: Stripe `checkout.session.expired` webhook, or an on-demand
  session `verify()` showing `expired`/`canceled`; or
- **explicit abandonment protocol**: an authorized caller (workspace `billing.manage` or
  operator) requests abandonment; Payvia first attempts provider-side verification and, for
  Stripe, session expiration; only a confirmed-dead session transitions to `abandoned`. For
  Paystack (no session-expiry API), abandonment requires the configured
  `abandon_after_seconds` (default 48h, ≥ 2× any plausible checkout duration) AND is recorded
  as caller-accepted risk in the row; a late `charge.success`/`subscription.create` still
  correlates and re-binds (see above), and the engine's single-active-subscription posture
  plus operator visibility handle the rare double-settlement by refund/cancel through the
  operator surface.

### §3.4 Ownership correlation (applier stage)

`GatewaySubscriptionService::applyProviderEvent()` gains one ownership source, tried after
"existing projection row wins" and before the `billing_plan_uuid` derivation:

1. Extract `origination_uuid` from normalized metadata (both gateway normalizers learn the
   key; Paystack additionally per the proven join of §3.1).
2. Resolve the ledger row (gateway must match). Found → adopt ITS `tenant_uuid` (never the
   provider-supplied tenant hint alone; a conflicting hint is diagnosed and ignored, matching
   the existing hint policy), record `provider_subscription_id`, transition to
   `provider_observed`.
3. **Metadata enrichment**: merge the row's `consumer_metadata` correlation fields
   (`tenant_uuid`, `subject_type`, `subject_uuid`, `plan_uuid`, `glueful_consumer`) into the
   event's normalized metadata and persist, so the downstream engine projector receives them
   on this and every replay. The actor UUID is NOT merged — it never leaves the ledger/audit
   record.
4. No match → fall through to existing sources (billing_plan_uuid, else fail closed).

Replay safety: correlation is a pure adopt-and-enrich; re-processing an already-correlated
event re-reads the same row, makes no new rows, and produces byte-identical enrichment.

### §3.5 Provider metadata policy

Closed, bounded set sent to providers: `origination_uuid` only (plus gateway-required
transport fields such as Paystack's reference/email). NO `actor_user_uuid`, NO subject/plan
fields, NO arbitrary consumer metadata — those live in the ledger and reach the engine via
§3.4 enrichment. This keeps PII and internal identifiers out of provider dashboards and
keeps the correlation surface a single opaque token.

### §3.6 Post-dispatch finalizer

Today `WebhookService` marks the provider event processed after the applier and then runs the
composed dispatcher (ordinary bus → strict lane → chargeback), with no success hook. Phase A
adds an explicit finalizer invoked ONLY after the entire dispatcher completes without throw:
resolve the origination by `(gateway, provider_subscription_id)` (or enriched
`origination_uuid`) and transition `provider_observed → dispatched`. Any strict-listener or
chargeback failure propagates as today (lease released, event retryable) and the origination
stays `provider_observed` — undispatched, retryable, no second checkout and no new ownership
rows on the retry.

### §3.7 Cancellation semantics (gateway-truthful)

`SubscriptionCapableGateway::cancelSubscription($id, $atPeriodEnd)` stays, but Payvia 2.5
documents and exposes per-gateway truth instead of promising uniform semantics:
- Stripe: `at_period_end=true` → `cancel_at_period_end`; `false` → immediate DELETE.
- Paystack: the disable operation stops future charges; the already-paid period runs to its
  `next_payment_date`. The `atPeriodEnd` argument is ignored today — Payvia 2.5 makes this
  explicit: the driver reports capability `cancel_modes: ['stop_renewal']` (Stripe:
  `['stop_renewal','immediate']`) via a small `cancellationModes(): array` addition to the
  interface, and callers may only offer modes the driver declares.
Tests pin the normalized local outcome: after projection, the local subscription's
entitlement end (`current_period_end`) reflects the provider's stated period end for each
gateway.

## §4 Subscriptions 2.2.0

### §4.1 `reserveCheckoutFor` (the reservation seam)

The projector only relinks provider subscriptions to an EXISTING local row
(`SubscriptionEventProjector` throws retryable-unmapped otherwise). Self-serve checkout must
therefore create the row first:

```php
SubscriptionService::reserveCheckoutFor(Subject $subject, string $planUuid, array $opts = []): array
```

- Creates (or idempotently returns) a `status = 'incomplete'` subscription row for the
  subject: NON-ENTITLING (the entitlement resolver must treat `incomplete` as no
  entitlements — tested), no provider fields, `plan_uuid` set, audit-stamped via
  `$opts['actor']` into the subscription-events log (`source = 'checkout_reservation'`).
- Idempotency: one reservation per subject. Re-reserving with a different plan updates the
  reservation's `plan_uuid` (still non-entitling; the projector's existing
  `requireCoherentPlan` cross-check then guards settlement against a stale-plan webhook).
- Refuses when the subject already has an active/trialing/past_due subscription
  (`already_subscribed`), mirroring the checkout refusal rules.
- Called BEFORE Payvia performs provider I/O. Activation authority remains webhooks: the
  existing `subscription.created` projection finds the incomplete row, relinks provider ids,
  and computes `active`/`trialing` exactly as today. `reserveCheckoutFor` never entitles,
  never activates.
- Reservation cleanup: a reservation whose origination reached a terminal non-dispatched
  state may be released by the host (explicit call `releaseCheckoutReservation(Subject)`,
  refusing when provider fields are present).

### §4.2 Per-gateway purchasability projection

- Migration: `subscription_plans` gains `provider_identifiers` JSON — a closed map
  `{gateway_key: identifier}`, keys `/^[a-z0-9_-]{1,50}$/`, identifiers non-empty strings
  ≤191, validated on every write path (create/update/import-config).
- **One declared authority**: the map is THE checkout-purchasability authority. The existing
  scalar `provider_price_id` remains compatibility-only (webhook correlation for
  pre-existing provider-managed rows) and is NEVER read for purchasability. No automatic
  migration: existing plans become purchasable only when an operator explicitly configures
  the map (documented in the release notes and the platform Plans UI copy).
- Typed read:
  ```php
  PlanPurchasability::forGateway(ApplicationContext $context, string $gateway): array
  // list<array{plan_uuid, plan_key, name, provider_identifier}>
  ```
  Audience `tenant`, status active, identifier present for the gateway. Thallo consumes this
  projection only — raw metadata/columns are not part of the host contract.
- Platform Plans admin (Thallo, Phase C) gains identifier editing on the existing plan
  editor; the platform API PATCH already surfaces upstream validation errors as 422.

## §5 Thallo phase

### §5.1 Authority & switch

- `billing.manage` in `CapabilityCatalog` (grantable), role matrix grants it to `owner`;
  boundaries of §1 stated as code comments + tests (workspace UUID always from
  `AdminTenantBindingMiddleware`-bound context).
- `self_serve_checkout_enabled`: platform-scoped system-flags row (SystemKeys addition),
  default false; editable only by platform authority (existing platform Billing surface +
  settings endpoint); enable-time validation requires an active gateway implementing
  subscription initiation; disable always allowed. `POST /checkout` rechecks it at request
  time.

### §5.2 Workspace billing API

Group `/v1/admin/billing`, middleware `['auth', 'tenant_profile:admin', 'tenant_bootstrap',
'admin_tenant_binding']`, per-route `content_permission:billing.manage`, names
`thallo.subscriptions.billing.*`. All engine access via the existing `EngineGateway`
(one probe per action); engine-unavailable → the established structured 409.

- `GET /meta` → 200 always: engine state, `self_serve_checkout_enabled`, workspace uuid,
  current subscription projection (status, plan, period end, `provider_managed`), live
  origination (status + stored checkout_url for resume), purchasable plans for the active
  gateway (plan_key + name only — no prices), `can_manage_billing`.
- `POST /checkout {plan_key}` → recheck switch + capability; refuse `subscription_already_active`
  (active/trialing/past_due), `checkout_pending` (live origination — response includes the
  stored URL for resume), `plan_not_purchasable` (not in `PlanPurchasability::forGateway`
  for the active gateway); then `reserveCheckoutFor` (engine) → `originate` (Payvia) with
  server-derived tenant uuid, `subject_key = tenant:<uuid>`, consumer metadata
  `{tenant_uuid, subject_type, subject_uuid, plan_uuid, glueful_consumer, actor_user_uuid}`,
  idempotency key `tenant:<uuid>:plan:<plan_uuid>`, return/cancel URLs from the canonical
  admin origin → 200 `{checkout_url}`.
- `POST /cancel {mode}` → only when the projected local row carries
  `provider_subscription_id` (`not_provider_managed` otherwise); `mode` must be one of the
  driver's declared `cancellationModes()`; provider-side cancel; ZERO local mutation; actor
  logged. Available regardless of the operator switch (§1).
- `POST /checkout/abandon` → the explicit abandonment protocol of §3.3, workspace-initiated.
- Return: `success_url`/`cancel_url` point at the admin SPA route
  `/billing/return?origination=<uuid>`; the page polls `GET /meta` and renders projected
  state. Informational only — no server endpoint marks anything successful.

### §5.3 SPA

Workspace Billing page (nav under the Subscriptions group, shown when `/meta` reports
`can_manage_billing`; distinct from the platform Billing directory). Meta-first states:
engine unavailable, switch off ("self-serve billing is not enabled on this platform"),
no subscription + plan picker, live origination (resume/abandon), active subscription
(plan, period end, cancel with per-mode confirm), provider-managed-elsewhere, canceled.
Plan changes on an active provider subscription: disabled control + the pinned message.
Vitest specs per state; meta-error branch from day one (learned in Task 11).

### §5.4 Pricing bridge

- Contract in `packages/thallo-contracts`:
  ```php
  interface PlanCheckoutUrlResolver
  {
      public function resolve(ApplicationContext $context, string $planKey): ?string;
  }
  ```
- `pricing_plan` block: optional `plan_key` field (editor input validated
  `/^[a-z0-9._-]{1,100}$/`; invalid → treated as absent). Template/render layer: when
  `plan_key` present AND the soft-bound resolver returns a URL, the CTA uses it; otherwise
  the authored `button_url` renders unchanged (capability off, engine off, resolver unbound,
  or unknown key all degrade identically).
- thallo-subscriptions binds the resolver: returns the admin billing deep link
  `{admin_url}/billing?plan=<key>` (canonical admin origin), null when capability/engine
  unavailable. The deep link survives the SPA's login return-to; the billing page revalidates
  everything before checkout (the resolver makes no purchasability promise).

## §6 Failure & degraded matrix

| Condition | Origination | Cancel | Meta | Bridge CTA |
|---|---|---|---|---|
| Capability `thallo.subscriptions` off | routes 404 | 404 | 404 | authored URL |
| Engine disabled / schema not ready | 409 engine code | 409 | 200 + state | authored URL |
| Switch off | 409 `self_serve_disabled` | works | 200 (`false`) | deep link (page shows switch-off state) |
| No `billing.manage` | 403 | 403 | 403 | deep link (page 403s) |
| Gateway lacks capability/identifier | 409 `plan_not_purchasable` / `unavailable` | n/a | plans omitted | deep link |
| Active subscription | 409 `subscription_already_active` | works | shown | deep link |
| Live origination | 409 `checkout_pending` + stored URL | works | shown | deep link |
| Provider webhook fails mid-lane | ledger stays `provider_observed`; event retryable | — | — | — |

Structured error vocabulary extends the existing `error.details.code` convention.

## §7 Testing

- **Payvia**: request-shape pins per gateway (Stripe `subscription_data[metadata]` presence is
  a named assertion; Paystack plan/email/reference); fail-unavailable (no one-time fallback
  path exists — asserted); ledger idempotency (same key resumes, different key + live guard
  refuses); `initializing` crash-replay resumes with the same provider idempotency reference
  and never frees the key; state-machine monotonicity incl. terminal→`provider_observed`
  re-bind on late webhooks; correlation adopt-and-enrich (ledger tenant wins over hints;
  enrichment persisted and replay-identical; actor never enriched); finalizer (strict-lane
  throw ⇒ origination stays `provider_observed`, event retryable, retry creates no rows);
  Paystack fixture-driven correlation tests from §3.1's captured payloads;
  `cancellationModes()` per driver.
- **Subscriptions**: `reserveCheckoutFor` idempotency, non-entitlement of `incomplete`
  (entitlement resolver test), refusal matrix, plan-update-on-re-reserve,
  `releaseCheckoutReservation` guards; projector activates a reserved row through the
  existing `subscription.created` path (integration fixture); purchasability projection
  matrix (audience/status/identifier); identifier validation bounds; scalar never read for
  purchasability (mutation test).
- **Thallo**: authority matrix (billing.manage vs tenancy.manage vs none, per route);
  switch kill-switch semantics (disable blocks origination mid-session, cancel still works);
  refusal matrix incl. request-time switch recheck; cancel-performs-zero-local-writes
  (row byte-compare); checkout wiring order (reserve before originate; originate failure
  leaves only the reservation); bridge degradation matrix; deep-link survives login
  (SPA spec); return page never mutates; end-to-end truth table extension.

## §8 Out of scope

Public signup-to-checkout funnel (reuses `SubscriptionCheckoutService` with a verified
workspace subject + actor — by design, no Payvia changes needed); provider-native plan
changes and Stripe Billing Portal (separate capability + review); price/display hydration
into pricing blocks; per-tenant gateway credentials; memberships/paid content (Phase 3);
dunning/retry policy beyond the engine's existing grace handling.
