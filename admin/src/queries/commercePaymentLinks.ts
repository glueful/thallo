import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { ApiError, apiErrorDetails } from '@/api/errors'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

// ── Admin payment links (payment-links spec §2.2/§2.4, Task 13) ────────────────────────────────
//
// FOUR routes, TWO owners, ONE client surface:
//   - the mounted Commerce catalog owns mint/status/revoke —
//     `POST|GET|DELETE /commerce/orders/{uuid}/payment-link`
//   - the Thallo commerce pack owns delivery — `POST /commerce/orders/{uuid}/payment-link/send`
//     (a DISTINCT path one segment deeper, so neither shadows the other).
//
// Raw `authFetch` rather than the typed client: these routes are not in the generated OpenAPI
// schema yet (Task 14 regenerates it, and this module moves onto `client` then) — the same
// interim idiom `formSubmissions.ts`/`navigation.ts` use.
//
// ## Token custody (the reason this module is shaped the way it is)
//
// The mint response's `url` is the ONE and ONLY time the plaintext token exists on the client.
// The engine stores a hash, so `GET .../payment-link` can never re-issue it, and this module
// deliberately gives no way to cache one: `createOrderPaymentLink()` RETURNS the URL to its
// caller (a component holding it in local state and rendering it once) and nothing here writes it
// to the query cache, a store, or a log. `normalizeLink()` below projects the status read down to
// the engine's own closed four-field admin view, so a wire field that should never exist (a token,
// a url) cannot ride into the SPA through a status poll either.
//
// `sendOrderPaymentLink()` is the one call that submits a token BACK — see the shape gate in
// `paymentLinkTokenFromUrl()`, which is the ONLY sanctioned way to derive it (URL API + 64-hex
// gate, never string splitting).

/** The engine's CLOSED admin projection of one link (`PaymentLinkAdminView`) — four fields, and
 * deliberately no token, no hash, no order/tenant uuid. */
export interface PaymentLinkView {
  link_uuid: string
  /** `active | expired | consumed | revoked` — already carrying any lazy transition the read applied. */
  status: string
  /** UTC `Y-m-d H:i:s`. */
  expires_at: string
  /** Whether a provider checkout session was EVER exposed for this link (Ruling 3's trigger). */
  provider_session_issued: boolean
}

/** `PaymentSessionExposureDecision` — what an operator needs for honest cancellation copy. */
export interface PaymentLinkExposure {
  reason: 'none' | 'active_link' | 'session_exposed'
  blocks_automatic_cancellation: boolean
  requires_risk_acknowledgement: boolean
}

/** `GET /orders/{uuid}/payment-link` — NEVER carries a token or a URL, by construction. */
export interface PaymentLinkStatus {
  link: PaymentLinkView | null
  exposure: PaymentLinkExposure
}

/** `POST /orders/{uuid}/payment-link` — `url` is one-time: shown once, never re-fetchable. */
export interface PaymentLinkMint {
  url: string
  link: PaymentLinkView
}

/** The delivery ledger row projected to the wire — closed, and structurally token-free. */
export interface PaymentLinkReceipt {
  delivery_uuid: string
  order_uuid: string
  link_uuid: string | null
  mode: string
  /** `processing | sent | failed | indeterminate`. */
  status: string
  error_code: string | null
  provider_message_id: string | null
  replayed: boolean
  created_at: string
  updated_at: string
}

/**
 * `POST /orders/{uuid}/payment-link/send` — every answer that carries a RECEIPT, whatever its
 * HTTP status. A 502 (delivery failed) is not an error to this layer: it is the response that
 * carries the still-active link's URL for manual copy, so it resolves like a 200 and the caller
 * branches on `receipt.status`. `http_status` is preserved so a caller can tell the two apart.
 */
export interface PaymentLinkSendEnvelope {
  http_status: number
  message: string
  receipt: PaymentLinkReceipt
  link: PaymentLinkView | null
  /** Non-null in exactly ONE case: a `regenerate` whose DELIVERY failed. Never on a replay. */
  url: string | null
  recovery: string | null
}

export type PaymentLinkSendInput =
  | { mode: 'current'; token: string }
  | { mode: 'regenerate'; ttl_days?: number | null }

export const PAYMENT_LINK_TTL_MIN = 1
export const PAYMENT_LINK_TTL_MAX = 30
export const PAYMENT_LINK_TTL_DEFAULT = 7

/** The engine's own token shape (`PaymentLinkService::TOKEN_PATTERN`). */
const TOKEN_PATTERN = /^[a-f0-9]{64}$/

const base = (uuid: string) => `${runtimeConfig.apiBase}/commerce/orders/${uuid}/payment-link`

function str(v: unknown): string {
  return typeof v === 'string' ? v : ''
}

/** Project the wire's link object down to the engine's four documented fields — and ONLY those,
 * so a stray `token`/`url` key on the wire can never become SPA state. */
function normalizeLink(raw: unknown): PaymentLinkView | null {
  if (typeof raw !== 'object' || raw === null) return null
  const row = raw as Record<string, unknown>
  return {
    link_uuid: str(row.link_uuid),
    status: str(row.status),
    expires_at: str(row.expires_at),
    provider_session_issued: row.provider_session_issued === true,
  }
}

function normalizeExposure(raw: unknown): PaymentLinkExposure {
  const row = (raw ?? {}) as Record<string, unknown>
  const reason = row.reason
  return {
    reason:
      reason === 'active_link' || reason === 'session_exposed' || reason === 'none' ? reason : 'none',
    blocks_automatic_cancellation: row.blocks_automatic_cancellation === true,
    requires_risk_acknowledgement: row.requires_risk_acknowledgement === true,
  }
}

function normalizeReceipt(raw: unknown): PaymentLinkReceipt {
  const row = (raw ?? {}) as Record<string, unknown>
  return {
    delivery_uuid: str(row.delivery_uuid),
    order_uuid: str(row.order_uuid),
    link_uuid: typeof row.link_uuid === 'string' ? row.link_uuid : null,
    mode: str(row.mode),
    status: str(row.status),
    error_code: typeof row.error_code === 'string' ? row.error_code : null,
    provider_message_id: typeof row.provider_message_id === 'string' ? row.provider_message_id : null,
    replayed: row.replayed === true,
    created_at: str(row.created_at),
    updated_at: str(row.updated_at),
  }
}

/**
 * The machine-readable refusal code every payment-link route carries as
 * `error.details.reason` — `order_not_admin_origin`, `order_not_pending_payment`,
 * `payment_link_changed`, `public_url_unavailable`, `idempotency_key_conflict`, … Callers branch
 * on this, never on the message text.
 */
export function paymentLinkRefusalReason(e: unknown): string | null {
  const reason = apiErrorDetails(e)?.reason
  return typeof reason === 'string' ? reason : null
}

/** Clamp a TTL to the engine's own 1..30 window; anything non-numeric falls back to the default. */
export function clampPaymentLinkTtl(value: unknown): number {
  const n = typeof value === 'number' ? value : typeof value === 'string' && value.trim() !== '' ? Number(value) : Number.NaN
  if (!Number.isFinite(n)) return PAYMENT_LINK_TTL_DEFAULT
  return Math.min(PAYMENT_LINK_TTL_MAX, Math.max(PAYMENT_LINK_TTL_MIN, Math.trunc(n)))
}

/**
 * The ONE sanctioned way to recover the token from a URL the operator can still see: parse with
 * the platform `URL` API and shape-gate the final path segment. Never ad-hoc string splitting of
 * the raw value — a hand-rolled `split('/')` would happily "find" a token in a pasted fragment,
 * a query string, or a non-URL, and hand a live credential to the wrong host. Null means the
 * visible URL cannot be trusted to contain this link's token, and current-mode send must refuse.
 */
export function paymentLinkTokenFromUrl(url: string): string | null {
  let parsed: URL
  try {
    parsed = new URL(url)
  } catch {
    return null
  }
  const segments = parsed.pathname.split('/').filter((s) => s !== '')
  const last = segments.length === 0 ? '' : segments[segments.length - 1]
  return TOKEN_PATTERN.test(last) ? last : null
}

/**
 * An opaque per-INTENT idempotency key (the endpoint requires 16-128 printable ASCII). Generated
 * once when the operator initiates a send and REUSED verbatim on a retry of that same intent —
 * that reuse is what makes a double submit one delivery rather than two.
 */
export function newPaymentLinkIdempotencyKey(): string {
  const random = globalThis.crypto?.randomUUID?.()
  return `plink-${random ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`}`
}

export async function fetchOrderPaymentLink(uuid: string): Promise<PaymentLinkStatus> {
  const json = await authFetch(base(uuid))
  const data = (json.data ?? json) as { link?: unknown; exposure?: unknown }
  return { link: normalizeLink(data.link), exposure: normalizeExposure(data.exposure) }
}

/**
 * Mint. The returned `url` is the one-time credential — hand it straight to the component holding
 * it in local state; do NOT cache it, log it, or put it in a store.
 */
export async function createOrderPaymentLink(
  uuid: string,
  ttlDays: number | null,
): Promise<PaymentLinkMint> {
  const json = await authFetch(base(uuid), {
    method: 'POST',
    // Absent (not null) means "use the configured default" — the engine treats a present null the
    // same, but omitting it keeps the request honest about what the operator actually chose.
    body: JSON.stringify(ttlDays === null ? {} : { ttl_days: clampPaymentLinkTtl(ttlDays) }),
  })
  const data = (json.data ?? json) as { url?: unknown; link?: unknown }
  return {
    url: str(data.url),
    link: normalizeLink(data.link) ?? {
      link_uuid: '',
      status: 'active',
      expires_at: '',
      provider_session_issued: false,
    },
  }
}

export async function revokeOrderPaymentLink(uuid: string): Promise<void> {
  await authFetch(base(uuid), { method: 'DELETE' })
}

/**
 * Deliver. Resolves for EVERY answer that carries a receipt — including the 502 whose body holds
 * the still-active link's URL for manual copy, and a replayed failure whose recorded status is
 * re-answered with a `recovery` instruction. A refusal that carries NO receipt (422/404/409/503
 * from the pre-claim gates) throws the ordinary `ApiError`, whose `error.details.reason` the
 * caller reads through `paymentLinkRefusalReason()`.
 */
export async function sendOrderPaymentLink(
  uuid: string,
  input: PaymentLinkSendInput,
  idempotencyKey: string,
): Promise<PaymentLinkSendEnvelope> {
  const body = JSON.stringify(
    input.mode === 'current'
      ? { mode: 'current', token: input.token }
      : input.ttl_days === undefined || input.ttl_days === null
        ? { mode: 'regenerate' }
        : { mode: 'regenerate', ttl_days: clampPaymentLinkTtl(input.ttl_days) },
  )

  try {
    const json = await authFetch(`${base(uuid)}/send`, {
      method: 'POST',
      headers: { 'Idempotency-Key': idempotencyKey },
      body,
    })
    return envelopeFrom(json, 200)
  } catch (e) {
    if (e instanceof ApiError && hasReceipt(e.body)) {
      return envelopeFrom(e.body as Record<string, unknown>, e.status)
    }
    throw e
  }
}

function hasReceipt(body: unknown): boolean {
  if (typeof body !== 'object' || body === null) return false
  const data = (body as { data?: unknown }).data
  return typeof data === 'object' && data !== null && 'receipt' in (data as Record<string, unknown>)
}

function envelopeFrom(json: Record<string, unknown>, httpStatus: number): PaymentLinkSendEnvelope {
  const data = (json.data ?? {}) as Record<string, unknown>
  return {
    http_status: httpStatus,
    message: str(json.message),
    receipt: normalizeReceipt(data.receipt),
    link: normalizeLink(data.link),
    url: typeof data.url === 'string' && data.url !== '' ? data.url : null,
    recovery: typeof data.recovery === 'string' ? data.recovery : null,
  }
}

/** The status read. `enabled` lets the card skip the request entirely for an order that can never
 * carry a link (non-admin origin, or no longer awaiting payment). */
export function useOrderPaymentLink(
  uuid: MaybeRefOrGetter<string>,
  enabled: MaybeRefOrGetter<boolean> = true,
) {
  return useQuery({
    key: () => qk.commerceOrderPaymentLink(toValue(uuid)),
    query: () => fetchOrderPaymentLink(toValue(uuid)),
    enabled: () => toValue(enabled) && !!toValue(uuid),
  })
}

/**
 * Mint / revoke / send. Every one of them can change what the status read reports (a mint revokes
 * the predecessor; a regenerate-send mints inside the request), so all three invalidate it —
 * and the ORDER itself too, since a link's existence feeds the detail page's exposure-aware
 * cancellation copy.
 */
export function useOrderPaymentLinkMutations() {
  const cache = useQueryCache()

  async function invalidate(uuid: string) {
    await cache.invalidateQueries({ key: qk.commerceOrderPaymentLink(uuid) })
    await cache.invalidateQueries({ key: qk.commerceOrder(uuid) })
  }

  const create = useMutation({
    mutation: (vars: { uuid: string; ttlDays: number | null }) =>
      createOrderPaymentLink(vars.uuid, vars.ttlDays),
    onSettled: async (_data, _error, vars) => {
      await invalidate(vars.uuid)
    },
  })

  const revoke = useMutation({
    mutation: (uuid: string) => revokeOrderPaymentLink(uuid),
    onSettled: async (_data, _error, uuid) => {
      await invalidate(uuid)
    },
  })

  const send = useMutation({
    mutation: (vars: { uuid: string; input: PaymentLinkSendInput; idempotencyKey: string }) =>
      sendOrderPaymentLink(vars.uuid, vars.input, vars.idempotencyKey),
    onSettled: async (_data, _error, vars) => {
      await invalidate(vars.uuid)
    },
  })

  return { create, revoke, send }
}
