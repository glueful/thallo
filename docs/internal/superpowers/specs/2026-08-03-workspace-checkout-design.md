# Workspace Self-Serve Checkout — Design

Provider session origination so a workspace can self-serve subscribe to and cancel its own
platform plan, plus the pricing-blocks → billing deep-link bridge (OUTSTANDING §B). Builds on
shipped thallo-subscriptions (platform admin), glueful/subscriptions 2.1, glueful/payvia 2.4.

**Canonical flow (the essential sequence — every section serves it):**

```
atomically prepare checkout locally
  → claim Payvia live origination (ledger row, attempt idempotency key)
  → bind the engine's non-entitling reservation to that origination and plan
    → commit, then initialize provider checkout (Stripe subscription session / Paystack plan initialize)
      → buyer pays on the provider's hosted page
        → webhook correlates origination_uuid → ledger adopts ownership, enriches metadata
          → strict-lane projection records a durable accepted/rejected acknowledgement
            → accepted projection activates the local subscription
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
- Cancel through the active gateway (default: stop renewal). Cancellation request mutates no
  subscription state eagerly; it writes an actor audit record, while webhooks project the
  subscription outcome.
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
| A | glueful/payvia | 2.5.0 — subscription checkout capability, origination ledger, correlation + acknowledged finalizer | publication gate |
| B | glueful/subscriptions | 2.2.0 — origination-bound `reserveCheckoutFor`, projection acknowledgement, per-gateway purchasability | publication gate |
| C | thallo | billing.manage, operator switch, workspace billing API + SPA, bridge | — |

Release order is A → B → C. The subscriptions domain remains Payvia-optional, but its Payvia
bridge consumes 2.5's additive projection-acknowledgement contract when that integration is
present; Thallo requires that strict path. Each publication gate requires the next phase to
remove any temporary sibling-repository override for the dependency being released and prove
clean resolution from the published registry. Existing unrelated development repositories do
not satisfy or invalidate that proof by themselves.

## §3 Payvia 2.5.0

### §3.1 Gateway capability

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

interface SubscriptionCancellationModeProvider
{
    /** @return list<'stop_renewal'|'immediate'> */
    public function cancellationModes(): array;
}
```

`SubscriptionCheckoutRequest` includes the caller's `idempotencyKey`. Payvia derives the
provider idempotency reference from `originationUuid`, but the caller key remains part of the
public request and durable local attempt identity. Cancellation-mode discovery is an additive
interface; `SubscriptionCapableGateway` is unchanged so existing third-party subscription
drivers remain source-compatible with Payvia 2.5.

- **Stripe**: `POST /v1/checkout/sessions` with `mode=subscription`,
  `line_items[0][price] = <provider identifier>`, `client_reference_id = origination_uuid`,
  `metadata[origination_uuid]`, AND `subscription_data[metadata][origination_uuid]` — session
  metadata does NOT propagate to the subscription object; `subscription_data.metadata` is the
  documented mechanism that does. `success_url` required, `cancel_url` optional,
  `customer_email` set, `Idempotency-Key: payvia-subinit-<origination_uuid>`. Response
  validation mirrors `initialize()` (id `cs_`, absolute-HTTPS URL); `expires_at` from the
  session's `expires_at`.
- **Paystack**: `POST /transaction/initialize` with `plan = <plan_code>`, required `email`,
  `callback_url`, stringified `metadata[origination_uuid]`, reference derived from the
  origination UUID. Paystack documents that the plan overrides the submitted amount but its
  initialize example and API contract still carry `amount`; the sandbox gate below MUST prove
  whether omission is accepted. If amount is required, the driver fetches the Paystack plan
  and submits that provider-returned amount — no host amount or duplicate local price becomes
  authoritative. `expires_at` null (Paystack does not return one; local TTL is display and
  diagnostics only — see §3.3 on why it never frees the guard).
- A gateway that does not implement the interface, or a request whose provider identifier is
  empty, fails as unavailable BEFORE any ledger write or provider call. No code path may fall
  back to `InitiationCapableGateway` (one-time mode).

**Paystack request + correlation proof (blocking requirement).** Stripe documents
`subscription_data.metadata` propagation; Paystack's documentation does NOT establish that
transaction metadata reaches the `subscription.create` event. Phase A's Paystack task MUST
capture sandbox fixtures (real webhook payloads from a plan-carrying initialize → charge →
subscription sequence) and the real initialize response, proving both the accepted amount
shape and a deterministic join before its implementation is locked:
- If `subscription.create` carries the transaction metadata → correlate directly on
  `origination_uuid` (same as Stripe).
- If not, `charge.success` MUST provide the transaction reference/metadata AND the resulting
  `subscription_code`: the charge correlates by exact origination reference and records that
  subscription id; `subscription.create` then joins by `(gateway, subscription_code)`. A
  `(customer_code, plan_code)` join is explicitly forbidden — one buyer may administer two
  workspaces on the same plan, making that pair legitimately ambiguous.
- If neither exact path is proven, Paystack subscription initiation remains unavailable and
  Phase A cannot pass its release gate. An unresolved paid event is not accepted as a normal
  fail-closed outcome without an executable reconciliation path.
The captured fixtures become permanent test fixtures through a closed allowlist projector,
not a denylist scrubber. Committed JSON may contain only the event type, transaction/reference,
`metadata.origination_uuid`, the proven `subscription_code`/`plan_code` locations, status, and
the minimum amount shape needed by the driver test. Customer objects, names, emails, phone
numbers, addresses, authorization/access/signature values, headers, and unrelated raw fields
are forbidden. A hostile-payload test proves those fields cannot survive projection. The proof
command also verifies the configured public Paystack webhook endpoint, signature secret, and
worker/ingestion prerequisites before polling only events created after its recorded start
time and matching its exact reference. No assumption or secret-bearing fixture ships.

### §3.2 SubscriptionCheckoutService

The service deliberately separates durable local preparation from provider I/O:

```php
prepare(
    ApplicationContext $context,
    SubscriptionCheckoutRequest $request,
    callable $bindLocalReservation,
): SubscriptionCheckoutClaim
initializeClaim(ApplicationContext $context, string $originationUuid): SubscriptionCheckoutResult
```

`prepare()` owns one database transaction. Its continuation receives the immutable claim and
MUST perform local database work only — no network calls. Payvia claims the live guard, invokes
the continuation, calls `markPrepared()`, and commits; any continuation failure rolls back all
three. This continuation is the generic seam the later public funnel also reuses.

Request (consumer-agnostic; the later public funnel supplies the same shape):
`tenant_uuid`, opaque `subject_key` (e.g. `tenant:<uuid>`), `gateway` (default from
`PayviaSettings::defaultGateway`), `provider_plan_identifier`, `consumer_metadata` (closed
map, stays local — see §3.5), `customer_email`, `return_url`, `cancel_url`,
`idempotency_key` (caller-supplied PER ATTEMPT), and optional
`required_projection_consumer`. Thallo sets the latter to `subscriptions`.

The claim persists every value `initializeClaim()` needs after a process restart: normalized
customer email, validated return/cancel URLs, gateway, provider identifier and local metadata,
plus a SHA-256 request fingerprint. Provider raw responses are not stored in this ledger.
Customer email is operational recovery state only: the atomic `initializing → pending|failed`
write clears it after a definitive provider outcome; an unknown-outcome `initializing` row
retains it until recovery. Return/cancel URLs remain for audit/replay and are always canonical,
host-configured-origin URLs rather than Host-derived input.

Order of operations (each step idempotent):
1. Validate: gateway enabled + implements `SubscriptionInitiationCapableGateway`; provider
   identifier non-empty. Fail `unavailable` before any write.
2. Inside `prepare()`, claim or re-read the ledger row as `preparing`, with the caller's
   idempotency key unique per tenant. This lookup happens BEFORE the subject guard is claimed:
   a matching same-key replay returns the committed row without being rejected by its own live
   guard. The insert runs in a savepoint; a unique-key race re-reads the winner and applies the
   same fingerprint decision. It performs NO provider I/O. Same-key replay is accepted only when the stored
   request fingerprint (subject, gateway, provider identifier, normalized consumer metadata,
   customer email, return/cancel destinations, required consumer) matches byte-for-byte;
   mismatch is `idempotency_conflict`. A different
   key while a live origination exists is refused by the database guard, not by a preceding
   read. A replay of an already committed `initializing`/`pending` claim returns that claim
   without invoking the reservation continuation again; a persisted `preparing` row outside
   the owning transaction is an invariant violation, not a resumable state. A terminal
   same-key replay returns the stored terminal result and never restarts that origination.
3. Only a genuinely new claim locks/claims the subject guard. The continuation binds its local reservation to the returned origination inside that SAME
   database transaction (§5.2). `markPrepared()` changes `preparing → initializing` only after
   the continuation succeeds; rollback removes the claim and reservation together.
4. `initializeClaim()` first acquires a durable per-origination execution lease using a random
   claim token plus `initialization_claimed_at`. Only the lease owner may call the provider or
   persist its result. A concurrent loser returns `{status: initializing, checkout_url: null}`
   (or the already-stored terminal/pending result) and performs zero provider I/O. A lease older
   than 120 seconds is stale and may be reclaimed; every attempt still uses the same provider idempotency reference derived from the
   origination UUID. On success persist `checkout_reference`, `checkout_url`,
   `provider_expires_at`, transition `initializing → pending`, and clear the lease atomically.
   Gateways throw `DefinitiveSubscriptionCheckoutRejection` only for a validated, definitive
   provider rejection; that path transitions to `failed`, clears the lease/email, and opens the
   matching guard. Every other exception is an unknown outcome: release only the execution
   lease, retain `initializing`, the email, idempotency key, and live subject guard, then rethrow
   so a later replay resumes safely.
5. Return `{origination_uuid, checkout_url: ?string, status}`. An `initializing` response is
   explicitly in progress, never presented as a checkout URL.

The database-enforced live guard is a separate one-row-per-subject parent
`subscription_checkout_subject_guards`, unique on `(tenant_uuid, subject_key)`, carrying
`state = open|live|blocked`, `origination_uuid`, `blocked_reason`, and a revision. Claim locks
that row and may move `open → live` exactly once; terminal success/failure clears it only with
a matching origination CAS; projection rejection/late settlement moves it to `blocked`.
Concurrent first-use creation handles the unique race in a savepoint and re-reads the winner.
This parent — not an originations query, boolean, partial index, or nullable unique — is the
portable authority for both one-live-attempt and unresolved-conflict holds.

**Attempt idempotency.** An idempotency key identifies one user checkout attempt, not a
subject/plan forever. A terminal attempt keeps its key permanently for replay/audit. A later
attempt — even for the same plan — MUST use a new key. Thallo's SPA generates one token per
deliberate checkout action, retains it across network retries, and discards it only after a
terminal response or when `/meta` establishes a different live attempt. The server never
manufactures a fresh key while retrying an ambiguous request.

**Crash/replay contract for `initializing`:** a timeout or unknown network outcome does NOT
free the idempotency key and does NOT free the live-guard. Replay with the same caller key
resumes initialization with the SAME provider idempotency reference, so the provider deduplicates.
The `checkout_url` cannot be reconstructed — it is stored, and re-serving it is the recovery
path. The execution lease may become stale and be reclaimed; the origination and subject guard
never auto-expire. `initializing` rows past a recovery threshold are surfaced
(diagnostics/console), never auto-failed or freed.

### §3.3 Origination ledger

New table `subscription_checkout_originations`:

| column | notes |
|---|---|
| `uuid` (12, unique) | the opaque `origination_uuid` stamped into provider metadata |
| `tenant_uuid` (12) | owner; adopted by correlation |
| `subject_key` (191) | opaque consumer subject; live-guard scope |
| `gateway` (50), `provider_plan_identifier` (191) | |
| `idempotency_key` (191) | unique `(tenant_uuid, idempotency_key)` |
| `request_fingerprint` (64) | SHA-256 over the canonical request shape |
| `initialization_claim_token` (12, nullable), `initialization_claimed_at` (nullable) | exclusive, recoverable provider-I/O lease; not ownership |
| `customer_email` (254, nullable) | initialization recovery only; cleared on definitive outcome |
| `return_url` / `cancel_url` (2048) | validated canonical destinations used for replay |
| `checkout_reference` (191, nullable) | Stripe session id / Paystack reference |
| `checkout_url` (2048, nullable) | stored — not reconstructable |
| `provider_subscription_id` (191, nullable) | recorded at correlation |
| `provider_customer_code` / `provider_plan_code` (191, nullable) | diagnostics only; never an ownership join |
| `status` (24) | see state machine |
| `live` (bool) | derived/read-optimized flag, never the guard authority |
| `required_projection_consumer` (50, nullable) | consumer whose durable acceptance completes origination |
| `projection_event_key` / `projection_outcome` / `projection_reason` | durable consumer acknowledgement |
| `consumer_metadata` (json) | subject/plan/actor — LOCAL ONLY |
| `provider_expires_at`, timestamps | |

Indexes: `unique(uuid)`, `unique(tenant_uuid, idempotency_key)`,
`unique(gateway, checkout_reference)`,
`unique(gateway, provider_subscription_id)` (nullable until observed), and diagnostic indexes
on `(subject_key, live)` and status. The migration suite pins the unique-null behavior needed
for the provider-subscription index on every supported database; if it is not portable, use
the same re-key pattern rather than weakening uniqueness.

New parent table `subscription_checkout_subject_guards`: `uuid`, `tenant_uuid`,
`subject_key`, `state`, nullable `origination_uuid`/`blocked_reason`, `revision`, timestamps;
unique `(tenant_uuid, subject_key)`. Tenant lifecycle inventory/purge/adoption includes BOTH
tables, with child-originations deleted before guards. A guard row is durable coordination
state, not billing history; it contains no provider payload or PII.

**State machine** (normal transitions monotonic and idempotent; the late-money exception is
listed explicitly and replays otherwise never regress):

```
preparing → initializing → pending → provider_observed → dispatched
                  └→ failed                └→ projection_rejected
                              pending → expired | abandoned
```

- `preparing`: local claim exists inside the host transaction; never visible after a
  successful commit because `markPrepared()` advances it to `initializing`. A crash rolls the
  transaction back.
- `initializing`: key claimed, provider outcome unknown. Live. Never times out into free.
- `pending`: provider session minted, URL stored. Live.
- `provider_observed`: a signed webhook correlated this origination; provider ids recorded. Live.
- `dispatched`: the post-dispatch finalizer confirmed an ACCEPTED projection (§3.6).
  Terminal, not live; matching subject guard returns to `open`.
- `projection_rejected`: the required consumer durably rejected projection. The provider
  event may finish dispatching (the rejection is deterministic), but the guard STAYS live and
  the row is operator-visible until explicit remediation; a paid-but-unentitled subject must
  never be offered another checkout automatically.
- `late_settlement_conflict`: a historical terminal origination observed money movement after
  a newer reservation/subscription took ownership. Guarded and operator-visible; never
  overwrites the newer row and never retries the same impossible relink forever.
- `failed`: provider initialization definitively rejected (4xx). Terminal; matching guard opens.
- `expired`: provider-confirmed expiry only (see below). Terminal; matching guard opens.
- `abandoned`: explicit protocol only. Terminal; matching guard opens.

**Live-guard vs origination identity (duplicate-subscription defense).** The origination row
is PERMANENT correlation identity: a webhook arriving after any terminal state still
correlates by `origination_uuid`. If no newer reservation/subscription conflicts, it adopts
ownership and transitions to `provider_observed` (terminal-to-observed is the one sanctioned
"regression", because money moved and the event must land); otherwise it enters
`late_settlement_conflict` as defined below. The live-guard is a separate, narrow question —
"may this subject originate another checkout?" — and local TTL elapse alone NEVER answers it,
because a hosted checkout may complete after the TTL. Reopening requires:
- **provider-confirmed expiry**: Stripe `checkout.session.expired` webhook, or an on-demand
  session `verify()` showing `expired`/`canceled`; or
- **explicit abandonment protocol**: an authorized caller requests abandonment through
  `SubscriptionCheckoutLifecycleCapableGateway`. Only `confirmed_dead` frees the guard.
  Stripe implements provider verification/session expiration. Paystack has no session-expiry
  API and therefore reports `unsupported`: workspace users cannot abandon or reopen a
  Paystack checkout in v1. A platform operator may resolve it only after externally confirming
  that no payment/subscription exists and recording that evidence through the operator
  reconciliation path. Local age, including 48h or any other TTL, is never sufficient.

Stripe's normalizer/applier explicitly recognizes `checkout.session.expired` for an
origination and closes its guard before the UNKNOWN-event early-dispatch path; this is a
ledger lifecycle event, not a subscription projection event.

A late webhook for a confirmed-dead historical origination still correlates. If a newer
subscription/reservation now owns the subject, the event does NOT enter an endless relink
retry and does NOT overwrite the newer row: Payvia marks the old origination
`late_settlement_conflict`, keeps the guard blocked, and surfaces it for operator cancellation/
refund reconciliation. The subscriptions projector deterministically rejects the mismatched
reservation as `origination_mismatch`; §3.6 records that rejected acknowledgement and lets the
signed provider event finish exactly once without treating the conflict as successful
projection. Automatic supersession or refund is out of scope; silently accepting the second
subscription is forbidden.

### §3.4 Ownership correlation (applier stage)

`GatewaySubscriptionService::applyProviderEvent()` gains one ownership source while preserving
the existing ownership order: an existing provider projection still wins ownership, then an
origination ledger row, then `billing_plan_uuid` derivation. "Existing wins" must no longer be
an early return when the event carries an origination token — it still validates the matching
ledger owner and performs enrichment. This closes the crash window where the provider row was
written but normalized-payload persistence failed before first dispatch.

1. Extract `origination_uuid` from normalized metadata, or for the proven Paystack two-event
   path resolve the ledger by the exact `(gateway, provider_subscription_id)` recorded from
   `charge.success` (both gateway normalizers learn the applicable fields).
2. Resolve the ledger row (gateway must match). Found with no existing projection → adopt ITS `tenant_uuid` (never the
   provider-supplied tenant hint alone; a conflicting hint is diagnosed and ignored, matching
   the existing hint policy), record `provider_subscription_id`, transition to
   `provider_observed`. Found with an existing projection → require the owners to match, then
   perform the same idempotent ledger transition/enrichment without moving ownership.
3. **Metadata enrichment**: merge the row's `consumer_metadata` correlation fields
   (`tenant_uuid`, `subject_type`, `subject_uuid`, `plan_uuid`, `glueful_consumer`) into the
   event's normalized metadata and persist, so the downstream engine projector receives them
   on this and every replay. The actor UUID is NOT merged — it never leaves the ledger/audit
   record.
4. No match → fall through to existing sources (billing_plan_uuid, else fail closed).

If the Paystack fixture selects the two-event path, its exact-reference `charge.success`
correlation is a narrow pre-pass BEFORE today's `isSubscriptionEvent()` early return:
`payment.succeeded` may record the proven nested `subscription_code`, enrich that event, and
move the origination to `provider_observed`, but it does not create/update a
`gateway_subscriptions` projection row and cannot finalize the origination. All unrelated
one-time charge events retain byte-identical behavior.

The current provider event is immutable and the applier callback returns `void`, so Phase A
adds this explicitly rather than relying on mutation that cannot occur:
- `ProviderEvent::withNormalized(array $normalized)` returns a replacement preserving the
  original gateway/type/ids/logical key/time/raw payload;
- the applier callback may return a replacement `PaymentProviderEventInterface` (a legacy
  void/null applier remains byte-compatible);
- when normalized data changed, `WebhookService` requires additive
  `ProviderEventPayloadUpdaterInterface::replaceNormalizedPayload(string $uuid, array $normalized)`,
  persists `normalized_payload` BEFORE `markProcessed()`, and
  dispatches the replacement object on the FIRST delivery; absence/failure of that capability
  fails closed rather than dispatching stale metadata;
- retries reconstruct the same enriched event from storage.

Replay safety: correlation is a pure adopt-and-enrich; re-processing an already-correlated
event re-reads the same row, makes no new rows, and produces byte-identical enrichment. A
first-delivery integration test asserts the strict listener sees the enriched subject/plan;
a retry-only success is a failure of this contract.

### §3.5 Provider metadata policy

Closed, bounded set sent to providers: `origination_uuid` only (plus gateway-required
transport fields such as Paystack's reference/email). NO `actor_user_uuid`, NO subject/plan
fields, NO arbitrary consumer metadata — those live in the ledger and reach the engine via
§3.4 enrichment. This keeps PII and internal identifiers out of provider dashboards and
keeps the correlation surface a single opaque token.

### §3.6 Post-dispatch finalizer

Today `WebhookService` marks the provider event processed after the applier and then runs the
composed dispatcher (ordinary bus → strict lane → chargeback), with no success hook and no
proof that a strict consumer actually accepted projection. Phase A adds a generic durable
acknowledgement contract owned by Payvia:

```php
interface SubscriptionProjectionAcknowledger
{
    public function acknowledge(
        string $originationUuid,
        string $consumer,
        string $logicalEventKey,
        string $outcome, // accepted|rejected
        ?string $reason = null,
    ): void;
}
```

Subscriptions 2.2's strict bridge calls its outcome-returning projector entry point, then
acknowledges `accepted` or deterministic `rejected`. Duplicate delivery re-reads the existing
receipt and returns its original outcome, so a crash after projection but before acknowledgement
recovers. Unmapped/transient projection throws and writes no acknowledgement. The writer is a
CAS over `provider_observed` + exact required consumer + logical key: a wrong consumer/state is
refused, repeating the same outcome is a no-op, and a conflicting second outcome throws. The
sole state exception is `late_settlement_conflict`: it accepts only a matching `rejected`
acknowledgement, records the outcome/reason without changing status or opening the guard, and
rejects an `accepted` acknowledgement loudly.

After ordinary bus → strict lane → chargeback completes, the finalizer resolves the
origination. For the activation-bearing `subscription.created` event:
- required consumer + accepted acknowledgement for THIS logical key → transition
  `provider_observed → dispatched` and free the live guard;
- required consumer + rejected acknowledgement → transition to `projection_rejected`, retain
  the guard, record the bounded reason, and allow the provider event's deterministic dispatch
  to complete;
- required consumer + no acknowledgement → throw
  `RequiredProjectionAcknowledgementMissing`, release the logical dispatch lease, and retry;
- no required consumer → `dispatched` means only generic local dispatch completion.
- `late_settlement_conflict` + matching rejected acknowledgement for this event → finalizer
  completes as a no-op, preserving the conflict status and blocked guard; missing, accepted,
  or conflicting acknowledgement throws and keeps the event retryable.

Correlation-only events such as Paystack's preliminary `charge.success` may move the row to
`provider_observed`, but NEVER finalize it; the origination awaits `subscription.created`.
Any strict-listener, chargeback, acknowledgement-write, or finalizer failure propagates as
today and leaves the origination retryable without creating a second ownership row.

### §3.7 Cancellation semantics (gateway-truthful)

`SubscriptionCapableGateway::cancelSubscription($id, $atPeriodEnd)` stays unchanged, but Payvia 2.5
documents and exposes per-gateway truth instead of promising uniform semantics:
- Stripe: `at_period_end=true` → `cancel_at_period_end`; `false` → immediate DELETE.
- Paystack: the disable operation stops future charges; the already-paid period runs to its
  `next_payment_date`. The `atPeriodEnd` argument is ignored today — Payvia 2.5 makes this
  explicit: the driver reports capability `cancel_modes: ['stop_renewal']` (Stripe:
  `['stop_renewal','immediate']`) via the additive
  `SubscriptionCancellationModeProvider::cancellationModes()` capability, and callers may only
  offer modes the driver declares. A driver that does not implement the capability exposes no
  self-serve cancellation modes; Payvia does not modify the existing public gateway interface.
Paystack's normalized disable event additionally carries `cancellation_mode=stop_renewal` and
its provider period end. Subscriptions 2.2 projects that as `non_renewing`, not immediately
`canceled`; its entitlement resolvers grant the current plan only until
`current_period_end`, then fall back. Stripe's existing `cancel_at_period_end` active posture
continues unchanged; immediate Stripe deletion projects `canceled` immediately. Tests assert
effective entitlement before AND after the boundary, not merely that a date column was stored.

### §3.8 Operator reconciliation

`projection_rejected`, `late_settlement_conflict`, and Paystack originations that cannot be
provider-confirmed dead are not dead-end statuses. Payvia exposes an operator-resolution
service with a local-only continuation, using the same transaction discipline as `prepare()`;
the host owns the platform-authority console/UI entry point. It may inspect only sanitized
origination/event identities and records one of two explicit resolutions:
- `provider_confirmed_dead`: the operator supplies a non-empty audit note/reference after
  confirming externally that no payment or subscription exists; allowed only when the ledger
  has never observed provider money/subscription state;
- `provider_canceled_or_refunded`: the operator supplies a non-empty audit note after resolving
  the provider side.

For either resolution Payvia opens the matching blocked/live subject guard and the host
releases only the exactly-bound incomplete reservation in the same transaction.

The service never performs an automatic refund, never rewrites a committed rejected receipt,
never activates a subscription, and never accepts a bare `ignore` resolution. An operator who
wants to grant a compensating manual plan may use the existing platform assignment flow only
after the provider subscription has been canceled/refunded; it is not represented as successful
projection of the rejected checkout. Thallo supplies the guarded operator command; its
workspace UI only says to contact the platform operator and does not expose this authority.

## §4 Subscriptions 2.2.0

### §4.1 `reserveCheckoutFor` (the reservation seam)

The projector only relinks provider subscriptions to an EXISTING local row
(`SubscriptionEventProjector` throws retryable-unmapped otherwise). Self-serve checkout must
therefore create the row first:

```php
SubscriptionService::reserveCheckoutFor(
    Subject $subject,
    string $planUuid,
    string $originationUuid,
    array $opts = [],
): array
```

Migration: `subscriptions.checkout_origination_uuid` nullable VARCHAR(12), indexed for
diagnostics. Existing rows remain null; provider projection never infers an origination for
them. Schema readiness includes the column only for checkout-capable 2.2 hosts, without
changing the 2.0 tenant facade.

- Creates (or idempotently returns) a `status = 'incomplete'` subscription row for the
  subject: NON-ENTITLING (the entitlement resolver must treat `incomplete` as no
  entitlements — tested), no provider fields, `plan_uuid` and the opaque checkout
  `origination_uuid` set, audit-stamped via `$opts['actor']` into the subscription-events log
  (`source = 'checkout_reservation'`). The origination UUID is local correlation state, never
  a provider authority.
- Idempotency: the same origination + same plan returns the reservation unchanged. A different
  origination or plan may replace an incomplete reservation ONLY inside the successful
  `SubscriptionCheckoutService::prepare()` continuation after that new origination has won
  the database live guard. Direct/ad-hoc replacement is refused. This prevents two concurrent
  plan requests from leaving the reservation on the losing plan.
- Refuses when the subject already has an active/trialing/past_due subscription, or a
  `non_renewing` subscription whose period end is still in the future
  (`already_subscribed`). An expired `non_renewing` row is non-entitling and may be replaced
  by a new origination-bound incomplete reservation.
- Called inside Payvia's local preparation transaction and BEFORE provider I/O. Activation
  authority remains webhooks: the
  existing `subscription.created` projection finds the incomplete row, relinks provider ids,
  verifies that metadata `origination_uuid` equals the bound reservation, and computes
  `active`/`trialing` exactly as today. A mismatched historical/late origination is surfaced as
  the conflict posture from §3.3; it never overwrites the reservation. `reserveCheckoutFor`
  never entitles, never activates.
- Reservation cleanup: a reservation whose origination reached a terminal non-dispatched
  state may be released by the host (explicit call
  `releaseCheckoutReservation(Subject, originationUuid)`, compare-and-delete/update guarded by
  the exact origination and refusing when provider fields are present).

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

### §4.3 Projection outcome and non-renewing access

- The projector gains an additive outcome-returning entry point used by the strict bridge.
  Existing callers may keep the void facade. Outcomes are `accepted` or deterministic
  `rejected(code)`; unmapped/transient failures still throw. A duplicate logical key returns
  the already-stored receipt outcome rather than an ambiguous no-op. The receipt repository
  therefore gains an exact logical-key read returning only settled outcome/reason/key data;
  both the early duplicate path and a unique-insert race re-read through that method.
- `origination_uuid` joins `ProviderEventData`'s safe opaque allowlist so accepted/rejected
  receipts and operator diagnostics can tie the outcome to the checkout without retaining
  customer/provider payload fields.
- After projection, the strict bridge writes the Payvia acknowledgement from §3.6. It never
  acknowledges before the receipt transaction commits.
- `non_renewing` joins the known status vocabulary. Tenant and member entitlement resolvers
  grant its plan only while `current_period_end > now`; absent/invalid/past period end fails
  closed to the default plan. Paystack `stop_renewal` uses this state. Immediate cancellation
  remains `canceled` and non-entitling.
- The operator-resolution continuation exposes exact-origin release after provider-side
  cancellation/refund; it cannot activate a row or weaken normal subject validation.

## §5 Thallo phase

### §5.1 Authority & switch

- Both Thallo's root manifest and `packages/thallo-subscriptions/composer.json` directly
  constrain `glueful/payvia:^2.5` and `glueful/subscriptions:^2.2`; the pack also declares its
  direct `glueful/users` dependency for authoritative verified-email lookup. Root-only
  availability is not accepted as a package dependency.
- `billing.manage` in `CapabilityCatalog` (grantable), role matrix grants it to `owner`;
  boundaries of §1 stated as code comments + tests (workspace UUID always from
  `AdminTenantBindingMiddleware`-bound context). The access endpoint/store defaults it false;
  owner or a delegated workspace role may receive it. A platform-only operator with
  `tenancy.manage`/`tenancy.access_any` but no workspace grant remains false.
- `self_serve_checkout_enabled`: pack-owned system-flags key
  `subscriptions.self_serve_checkout_enabled`, default false, read/written through a small
  `SelfServeCheckoutSetting` wrapper over the existing `SystemFlags` store. A concrete
  `PUT /v1/admin/subscriptions/self-serve` route lives in the existing platform subscriptions
  middleware group (`auth`, `tenant_system`, `content_permission:tenancy.manage`) and the
  platform Billing page owns its control. Enable-time validation requires the active gateway
  to implement subscription initiation; disable is always allowed. `POST /checkout` rechecks
  it at request time.

### §5.2 Workspace billing API

Group `/v1/admin/billing`, middleware `['auth', 'tenant_profile:admin', 'tenant_bootstrap',
'admin_tenant_binding']`, per-route `content_permission:billing.manage`, names
`thallo.subscriptions.billing.*`. All engine access via the existing `EngineGateway`
(one probe per action); engine-unavailable → the established structured 409.

- `GET /meta` → once route authorization succeeds, 200 even when the engine is unavailable:
  engine state, `self_serve_checkout_enabled`, workspace uuid,
  current subscription projection (status, plan, period end, `provider_managed`), live
  origination (status + stored checkout_url for resume), projection-rejected/late-settlement
  operator-contact state, purchasable plans for the active gateway (plan_key + name only — no
  prices). It does not return `can_manage_billing`: reaching the route already proves that
  permission, while a caller without it receives the route's uniform 403.
- `POST /checkout {plan_key}`, requiring an `Idempotency-Key` header matching a bounded opaque
  token contract → recheck switch + capability; refuse `subscription_already_active`
  (active/trialing/past_due, or non_renewing before its period end), `checkout_pending` only
  for a DIFFERENT attempt key (live origination — response includes the stored URL when one
  exists), `plan_not_purchasable` (not in
  `PlanPurchasability::forGateway`
  for the active gateway). A matching same-key request always reaches Payvia `prepare()` so it
  can replay/resume; changing the plan under that key becomes `idempotency_conflict`. A
  `WorkspaceCheckoutCoordinator` then calls Payvia `prepare()`;
  its local-only continuation invokes `reserveCheckoutFor(subject, plan, originationUuid)` in
  the SAME transaction. After commit it calls `initializeClaim()`. The Payvia request uses
  server-derived tenant uuid, `subject_key = tenant:<uuid>`, consumer metadata
  `{tenant_uuid, subject_type, subject_uuid, plan_uuid, glueful_consumer, actor_user_uuid}`,
  `required_projection_consumer = subscriptions`, the caller's per-attempt idempotency token,
  and return/cancel URLs from the canonical admin origin. A pending result returns 200
  `{status, checkout_url}`; a concurrent initialization owner returns 202
  `{status: initializing, checkout_url: null}` and the SPA retains the same attempt key while
  polling/retrying. It never manufactures a second attempt.
  `customer_email` is loaded server-side from the authenticated user's authoritative account
  row and must be verified; request/JWT email claims are never accepted. A missing account or
  unverified email fails before preparation. It is the receipt recipient only; payment
  identity remains the workspace, and the email is initialization-recovery state under §3.2's
  redaction rule, never an ownership signal.
- `POST /cancel {mode}` → only when the projected local row carries
  `provider_subscription_id` (`not_provider_managed` otherwise); `mode` must be one of the
  driver's declared `cancellationModes()`; provider-side cancel; ZERO eager mutation of the
  subscription row (webhook remains authoritative); the request and actor are written to the
  existing audit sink. Available regardless of the operator switch (§1).
- `POST /checkout/abandon` → invokes the capability from §3.1 and succeeds only on
  `confirmed_dead`. Paystack returns structured 409 `checkout_abandonment_unsupported`; its
  UI offers resume/contact-operator, never a workspace reopen action.
- Platform operator command `subscriptions:checkout:resolve <origination> --resolution=...`
  implements §3.8 through Payvia's transactional continuation and the engine's exact-origin
  release/assertion seam. It requires a non-empty audit note, prints no provider payload/PII,
  and is not exposed through workspace routes.
- Return: `success_url`/`cancel_url` point at the admin SPA route
  `/billing/return?origination=<uuid>`; the page polls `GET /meta` and renders projected
  state. Informational only — no server endpoint marks anything successful.

### §5.3 SPA

Workspace Billing page (nav under the Subscriptions group, gated by an effective-permission
flag for `billing.manage`: the tenancy access endpoint gains `manage_billing`, evaluated
against the resolved workspace exactly like `manage_members`; distinct from the platform
Billing directory). The registry declares all three children, while the existing tenancy-nav
shaper filters platform Plans/Billing by `manage_platform` and Workspace billing by
`manage_billing`, dropping an empty group. A static module capability cannot substitute for
the loaded per-workspace access store.
Meta-first states:
engine unavailable, switch off ("self-serve billing is not enabled on this platform"),
no subscription + plan picker, initializing origination (waiting/retry with same key, no link),
pending origination (resume/abandon), active subscription
(plan, period end, cancel with per-mode confirm), non-renewing (access-until date),
projection-rejected/late-settlement (blocked + contact operator), provider-managed-elsewhere,
canceled. The SPA generates one idempotency token per checkout click, reuses it for retries,
and never generates a replacement while `/meta` reports a live attempt.
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
  or malformed/absent key all degrade identically).
- thallo-subscriptions binds the resolver: returns the admin billing deep link
  `{admin_url}/billing?plan=<key>` (canonical admin origin), null when capability/engine
  unavailable. The deep link survives the SPA's login return-to; the billing page revalidates
  everything before checkout (the resolver makes no existence or purchasability promise).
  Therefore a well-formed but unknown key deliberately reaches the Billing page and renders
  `plan_not_purchasable`; it does NOT fall back to the authored URL. This preserves the pinned
  plan-key-plus-deep-link-only bridge without adding render-time catalog queries.

## §6 Failure & degraded matrix

| Condition | Origination | Cancel | Meta | Bridge CTA |
|---|---|---|---|---|
| Capability `thallo.subscriptions` off | routes 404 | 404 | 404 | authored URL |
| Engine disabled / schema not ready | 409 engine code | 409 | 200 + state | authored URL |
| Switch off | 409 `self_serve_disabled` | works | 200 (`false`) | deep link (page shows switch-off state) |
| No `billing.manage` | 403 | 403 | 403 | deep link (page 403s) |
| Gateway lacks capability/identifier | 409 `plan_not_purchasable` / `unavailable` | n/a | plans omitted | deep link |
| Active subscription | 409 `subscription_already_active` | works | shown | deep link |
| Live origination | same-key initializing: 202/no URL; pending or different key: 409 `checkout_pending` (+ URL when stored) | works | shown | deep link |
| Projection rejected / late settlement | blocked pending operator resolution | operator only | blocked state | deep link |
| Paystack abandonment requested | 409 `checkout_abandonment_unsupported` | — | resume/contact operator | deep link |
| Provider webhook fails mid-lane | ledger stays `provider_observed`; event retryable | — | — | — |

Structured error vocabulary extends the existing `error.details.code` convention.

## §7 Testing

- **Payvia**: request-shape pins per gateway (Stripe `subscription_data[metadata]` presence is
  a named assertion; Paystack sandbox proves amount shape plus exact reference/subscription-id
  join, while an unrelated one-time `charge.success` remains untouched); fail-unavailable
  (no one-time fallback path exists — asserted); concurrent different-key claim race proves
  the database yields exactly one live guard; same key + mismatched
  fingerprint rejects; a fresh attempt key after terminal creates a new origination; restart
  recovery reconstructs initialize input from the ledger and clears stored customer email on
  definitive outcome; `initializing` crash-replay resumes with the same provider idempotency reference and never
  frees the key; state-machine monotonicity plus guarded late-settlement conflict; correlation
  adopt-and-enrich (ledger tenant wins; actor never enriched); first delivery sees the
  replacement event and persisted enrichment; a payload-update failure after the provider
  projection row was written still re-enriches on retry; accepted/rejected/missing acknowledgement
  finalizer matrix, including late-settlement rejected acknowledgement completing without
  changing its blocked state; strict-lane throw leaves `provider_observed` retryable; Stripe expiry
  closes the correct guard; Paystack abandonment is unsupported; additive cancellation-mode discovery and
  checkout-lifecycle capability per driver; both origination tables participate in tenant
  purge/adoption; operator resolutions require their stated evidence and atomically release
  the matching guard/continuation.
- **Subscriptions**: origination-bound `reserveCheckoutFor` idempotency, non-entitlement of
  `incomplete`, refusal matrix, exact-origination release guard; deterministic two-plan race
  proving the losing plan cannot replace the winner's reservation; projector activates the
  matching reserved row through `subscription.created` and rejects a mismatched historical
  origination without overwrite; outcome-returning projection preserves accepted/rejected on
  duplicate replay and acknowledges only after commit; `non_renewing` effective entitlements
  immediately before/after period end; purchasability projection matrix, identifier bounds,
  and scalar-never-read mutation test.
- **Thallo**: authority matrix (billing.manage vs tenancy.manage vs none, per route);
  switch kill-switch semantics (disable blocks origination mid-session, cancel still works);
  refusal matrix incl. request-time switch recheck; cancel performs zero eager subscription-
  row writes but records actor audit; checkout preparation transaction rollback and a pgsql-
  gated concurrent plan-A/plan-B proof; provider failure leaves the bound incomplete
  reservation plus either an `initializing` origination for unknown-outcome same-key recovery
  or a terminal `failed` origination after a definitive rejection; SPA attempt-token reuse and
  post-terminal rotation; Paystack no-abandon UI; operator reconciliation command boundaries;
  bridge degradation plus well-formed unknown-key deep-link behavior; deep-link survives
  login; return page never mutates; end-to-end
  truth table extension.

## §8 Out of scope

Public signup-to-checkout funnel (reuses `SubscriptionCheckoutService` with a verified
workspace subject + actor — by design, no Payvia changes needed); provider-native plan
changes and Stripe Billing Portal (separate capability + review); price/display hydration
into pricing blocks; per-tenant gateway credentials; memberships/paid content (Phase 3);
dunning/retry policy beyond the engine's existing grace handling; automatic refunds or
automatic resolution of paid projection conflicts.
