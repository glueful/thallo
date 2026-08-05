import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

// Task 19 (Phase C, workspace self-serve checkout plan, spec §5.3): the workspace-scoped billing
// admin SPA module -- wraps `SelfBillingController` (Tasks 15-17), mounted at `/v1/admin/billing`.
// Mirrors `queries/subscriptionsBilling.ts`'s un-typed-`authFetch` convention (this pack has no
// OpenAPI codegen yet). EVERY shape below is normalized against `SelfBillingController`'s own
// docblock -- the authoritative pinned contract -- not the design spec's paraphrase of it.

const base = () => `${runtimeConfig.apiBase}/billing`

// ── Meta ─────────────────────────────────────────────────────────────────────
// `GET /meta` (SelfBillingController::meta()) -- 200 always, once route authorization succeeds,
// in every engine state. Meta-first fetch (binding behavior rule, mirrors subscriptionsBilling.ts):
// this is the ONE probe the Billing page issues before deciding which state to render.

export type WorkspaceBillingEngineState = 'engine_disabled' | 'schema_not_ready' | 'ready'

function normalizeEngineState(value: unknown): WorkspaceBillingEngineState {
  return value === 'schema_not_ready' || value === 'ready' ? value : 'engine_disabled'
}

/** `SelfBillingController::projectSubscription()`'s exact shape -- never the raw engine row. No
 * plan display name: the controller returns `plan_key` only, so the SPA falls back to matching
 * `purchasable_plans` by key (best-effort) or renders the key itself. */
export interface WorkspaceBillingSubscription {
  status: string
  plan_key: string | null
  current_period_end: string | null
  provider_managed: boolean
}

/** A `live`-guard origination projection -- present ONLY while the guard is `live`
 * ({@see SelfBillingController::guardState()}). `checkout_url` is null while `status` is
 * `initializing` (no hosted session exists yet); non-null once `pending`. */
export interface WorkspaceLiveOrigination {
  status: string
  checkout_url: string | null
}

export interface WorkspacePurchasablePlan {
  plan_key: string
  name: string
}

export interface WorkspaceBillingMeta {
  engine: WorkspaceBillingEngineState
  self_serve_checkout_enabled: boolean
  workspace_uuid: string
  subscription: WorkspaceBillingSubscription | null
  origination: WorkspaceLiveOrigination | null
  operator_contact_required: boolean
  operator_contact_reason: string | null
  purchasable_plans: WorkspacePurchasablePlan[]
}

function normalizeSubscription(raw: unknown): WorkspaceBillingSubscription | null {
  if (typeof raw !== 'object' || raw === null) return null
  const r = raw as Record<string, unknown>
  return {
    status: String(r.status ?? ''),
    plan_key: typeof r.plan_key === 'string' ? r.plan_key : null,
    current_period_end: typeof r.current_period_end === 'string' ? r.current_period_end : null,
    provider_managed: r.provider_managed === true,
  }
}

function normalizeOrigination(raw: unknown): WorkspaceLiveOrigination | null {
  if (typeof raw !== 'object' || raw === null) return null
  const r = raw as Record<string, unknown>
  return {
    status: String(r.status ?? ''),
    checkout_url: typeof r.checkout_url === 'string' ? r.checkout_url : null,
  }
}

function normalizePurchasablePlans(raw: unknown): WorkspacePurchasablePlan[] {
  if (!Array.isArray(raw)) return []
  const out: WorkspacePurchasablePlan[] = []
  for (const entry of raw) {
    if (typeof entry !== 'object' || entry === null) continue
    const r = entry as Record<string, unknown>
    if (typeof r.plan_key === 'string' && typeof r.name === 'string') {
      out.push({ plan_key: r.plan_key, name: r.name })
    }
  }
  return out
}

export async function fetchWorkspaceBillingMeta(): Promise<WorkspaceBillingMeta> {
  const json = await authFetch(`${base()}/meta`)
  const raw = (json.data ?? json) as Record<string, unknown>
  return {
    engine: normalizeEngineState(raw.engine),
    self_serve_checkout_enabled: raw.self_serve_checkout_enabled === true,
    workspace_uuid: typeof raw.workspace_uuid === 'string' ? raw.workspace_uuid : '',
    subscription: normalizeSubscription(raw.subscription ?? null),
    origination: normalizeOrigination(raw.origination ?? null),
    operator_contact_required: raw.operator_contact_required === true,
    operator_contact_reason: typeof raw.operator_contact_reason === 'string' ? raw.operator_contact_reason : null,
    purchasable_plans: normalizePurchasablePlans(raw.purchasable_plans),
  }
}

export const qkWorkspaceBillingMeta = () => ['billing', 'meta'] as const

export function useWorkspaceBillingMeta() {
  return useQuery({ key: qkWorkspaceBillingMeta(), query: fetchWorkspaceBillingMeta })
}

// ── Idempotency-Key discipline (spec §5.2/§5.3) ─────────────────────────────
// An opaque token, 32 characters, drawn from the SAME charset the controller's
// `IDEMPOTENCY_KEY_PATTERN` accepts (`[A-Za-z0-9._~-]`) -- well within its 16-128 length bound.
// One token per DELIBERATE checkout click, reused verbatim across retries of that same attempt,
// and rotated ONLY in the two cases the brief pins: (1) a terminal outcome from the checkout
// response itself (a settled `provider_observed`/`dispatched` replay, or a 409
// `checkout_failed`/`checkout_expired`/`checkout_abandoned`) -- see {@link isTerminalCheckoutOutcome} --
// or (2) `/meta` reporting a DIFFERENT live attempt than the one this token is tracking (detected
// by a changed non-null `checkout_url`, the only stable per-attempt identity `/meta` exposes).

const IDEMPOTENCY_CHARSET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._~-'
const IDEMPOTENCY_TOKEN_LENGTH = 32

export function generateIdempotencyToken(): string {
  const bytes = new Uint32Array(IDEMPOTENCY_TOKEN_LENGTH)
  crypto.getRandomValues(bytes)
  let out = ''
  for (let i = 0; i < IDEMPOTENCY_TOKEN_LENGTH; i++) {
    out += IDEMPOTENCY_CHARSET[bytes[i]! % IDEMPOTENCY_CHARSET.length]
  }
  return out
}

/** `SelfBillingController`'s pinned `POST /checkout` status/code -> HTTP table: only these six
 * outcomes are terminal (attempt is definitively over, a NEW attempt needs a NEW key). Every
 * other status (`pending`, `initializing`) keeps the current token alive.
 *
 * `idempotency_conflict` (code review fix) is ALSO terminal even though it isn't in that status
 * table: it means the held token was already used for a DIFFERENT plan_key than the one just
 * submitted -- resubmitting the SAME (token, plan) pair never produces this code (the controller
 * lets a matching-key/matching-plan request replay through to `prepare()` untouched), so without
 * rotating here the token is durably poisoned for the rest of this page mount -- every further
 * click reuses the same doomed token and 409s forever until a hard reload. */
const TERMINAL_CHECKOUT_STATUSES = new Set(['provider_observed', 'dispatched'])
const TERMINAL_CHECKOUT_ERROR_CODES = new Set([
  'checkout_failed',
  'checkout_expired',
  'checkout_abandoned',
  'idempotency_conflict',
])

export function isTerminalCheckoutStatus(status: string): boolean {
  return TERMINAL_CHECKOUT_STATUSES.has(status)
}

export function isTerminalCheckoutErrorCode(code: string | null): boolean {
  return code !== null && TERMINAL_CHECKOUT_ERROR_CODES.has(code)
}

/**
 * Owns the SPA-side idempotency token for one workspace's checkout attempt. Plain class (no Vue
 * reactivity needed -- callers hold it in setup() so it survives re-renders, and only its
 * `token` getter feeds a request) so the rotation rules above are unit-testable in isolation from
 * any component/mount.
 */
export class CheckoutAttemptTracker {
  private currentToken: string | null = null
  /** `undefined` = no url observed yet for the current token; `null`/string = the last-seen
   * `checkout_url` from `/meta`, used to detect a DIFFERENT live attempt taking over the guard. */
  private lastCheckoutUrl: string | null | undefined = undefined
  /** Whether a live (non-null) origination has been observed at all since the current token was
   * minted -- see {@link observeMeta}'s disappearance-rotation branch (code review fix). */
  private sawLiveOrigination = false

  get token(): string | null {
    return this.currentToken
  }

  /** Mint a token if none is held; otherwise return the retained one (a retry of the SAME
   * deliberate click). */
  ensureToken(): string {
    if (this.currentToken === null) {
      this.currentToken = generateIdempotencyToken()
      this.lastCheckoutUrl = undefined
      this.sawLiveOrigination = false
    }
    return this.currentToken
  }

  /**
   * Feed the latest `/meta` origination read. Two rotation triggers:
   *
   * 1. A DIFFERENT non-null `checkout_url` than the one previously observed for this token --
   *    only a non-null url is a stable per-attempt identity (an `initializing` origination has
   *    none yet, so it never triggers THIS branch by itself).
   * 2. (Code review fix) A previously-observed LIVE origination -- `initializing` or `pending`,
   *    url or no url -- disappearing entirely (non-null -> null). This is the guard being
   *    released out-of-band (operator resolution, expiry, a race with another actor): the token
   *    this instance is holding no longer corresponds to anything live, and silently keeping it
   *    would resubmit it for whatever plan the next click picks -- which, if the origination's
   *    plan differed even once, always 409s `idempotency_conflict` for the rest of this page
   *    mount. Reusing the SAME token for a genuine same-attempt resume never sees this
   *    transition (the origination stays visible, live, right up to a terminal outcome this
   *    tracker's `markTerminal()` already handles), so this cannot misfire on a normal retry.
   */
  observeMeta(origination: WorkspaceLiveOrigination | null): void {
    if (this.currentToken === null) return

    if (origination === null) {
      if (this.sawLiveOrigination) this.reset()
      return
    }
    this.sawLiveOrigination = true

    const url = origination.checkout_url
    if (url === null) return
    if (this.lastCheckoutUrl === undefined) {
      this.lastCheckoutUrl = url
      return
    }
    if (this.lastCheckoutUrl !== url) {
      this.reset()
    }
  }

  /** Rotate after a terminal checkout outcome (see the status/code sets above). */
  markTerminal(): void {
    this.reset()
  }

  reset(): void {
    this.currentToken = null
    this.lastCheckoutUrl = undefined
    this.sawLiveOrigination = false
  }
}

// ── Checkout / cancel / abandon mutations ───────────────────────────────────

export interface CheckoutResult {
  status: string
  checkout_url: string | null
}

/** `POST /checkout` -- the `Idempotency-Key` header carries the caller's per-attempt token
 * verbatim (never trimmed; the controller validates the raw value). A 202 `initializing` body
 * resolves this promise normally (authFetch only throws on a non-2xx status). */
export async function startWorkspaceCheckout(planKey: string, idempotencyKey: string): Promise<CheckoutResult> {
  const json = await authFetch(`${base()}/checkout`, {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify({ plan_key: planKey }),
  })
  const raw = (json.data ?? json) as Record<string, unknown>
  return {
    status: String(raw.status ?? ''),
    checkout_url: typeof raw.checkout_url === 'string' ? raw.checkout_url : null,
  }
}

export function useWorkspaceCheckoutMutation() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (vars: { planKey: string; idempotencyKey: string }) =>
      startWorkspaceCheckout(vars.planKey, vars.idempotencyKey),
    onSettled: () => cache.invalidateQueries({ key: qkWorkspaceBillingMeta() }),
  })
}

/**
 * `POST /cancel {mode}` -- `mode` is NOT pre-known: the controller validates it against the
 * active gateway driver's own declared `cancellationModes()`, which `/meta` does not expose (see
 * `CancelDialog.vue`'s own docblock for the resulting UI choice: offer `stop_renewal` by default,
 * surface a 422 `invalid_cancellation_mode` verbatim rather than pre-enumerating modes).
 */
export async function cancelWorkspaceSubscription(mode: string): Promise<{ mode: string }> {
  const json = await authFetch(`${base()}/cancel`, {
    method: 'POST',
    body: JSON.stringify({ mode }),
  })
  const raw = (json.data ?? json) as Record<string, unknown>
  return { mode: typeof raw.mode === 'string' ? raw.mode : mode }
}

export function useWorkspaceCancelMutation() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (mode: string) => cancelWorkspaceSubscription(mode),
    onSettled: () => cache.invalidateQueries({ key: qkWorkspaceBillingMeta() }),
  })
}

/** `POST /checkout/abandon` -- succeeds only on the provider's `confirmed_dead` outcome; every
 * other outcome (incl. Paystack's `checkout_abandonment_unsupported`) is a 409 the caller renders
 * verbatim (see `CheckoutPendingPanel.vue`). */
export async function abandonWorkspaceCheckout(): Promise<{ status: string }> {
  const json = await authFetch(`${base()}/checkout/abandon`, { method: 'POST' })
  const raw = (json.data ?? json) as Record<string, unknown>
  return { status: typeof raw.status === 'string' ? raw.status : '' }
}

export function useWorkspaceAbandonMutation() {
  const cache = useQueryCache()
  return useMutation({
    mutation: () => abandonWorkspaceCheckout(),
    onSettled: () => cache.invalidateQueries({ key: qkWorkspaceBillingMeta() }),
  })
}

/** A real browser navigation, wrapped so tests can stub it (mirrors the rest of this file's
 * "narrow seam, easy to mock" style). Called on a `POST /checkout` 200 `{status:'pending'}`
 * result -- redirect to the provider's hosted checkout page. */
export function navigateToCheckout(url: string): void {
  window.location.assign(url)
}
