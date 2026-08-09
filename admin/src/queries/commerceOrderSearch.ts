import { useQuery } from '@pinia/colada'
import { toValue, type Ref } from 'vue'
import { authFetch } from '@/api/authFetch'
import { responseError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { runtimeConfig } from '@/runtime/config'
import {
  ORDER_STATUSES,
  FULFILLMENT_STATUSES,
  type CommerceOrder,
  type CommerceOrderStatus,
  type CommerceFulfillmentStatus,
} from './commerceOrders'

// `GET /v1/admin/commerce/orders/search` (Task 3) + `GET /v1/admin/commerce/orders/export`
// (Task 4) — both merged on the backend but NOT yet in the generated OpenAPI schema (no regen
// happened for them; recorded follow-up), so both ride on authFetch/raw fetch like
// formSubmissions.ts rather than the typed `client`, mirroring that file's exact idiom.

export interface OrderSearchFilters {
  q: string
  status: CommerceOrderStatus | null
  fulfillment: CommerceFulfillmentStatus | null
  /** Strict `YYYY-MM-DD`, inclusive lower bound — never a full datetime. */
  placedFrom: string | null
  /** Strict `YYYY-MM-DD`, inclusive upper bound — never a full datetime. */
  placedTo: string | null
  page: number
  perPage: number
}

export const ORDER_SEARCH_DEFAULTS: OrderSearchFilters = {
  q: '',
  status: null,
  fulfillment: null,
  placedFrom: null,
  placedTo: null,
  page: 1,
  perPage: 24,
}

export interface OrderSearchPage {
  orders: CommerceOrder[]
  total: number
  current_page: number
  per_page: number
}

const base = () => `${runtimeConfig.apiBase}/commerce/orders`

/** Request params sent to the backend — unlike `serializeOrderSearchQuery()` (the URL bar's
 * canonical form), `page`/`per_page` are always included here since the endpoint's own defaults
 * are a server-side concern, not something to rely on silently matching the client's. */
function searchParams(filters: OrderSearchFilters): URLSearchParams {
  const qs = new URLSearchParams()
  if (filters.status) qs.set('status', filters.status)
  if (filters.fulfillment) qs.set('fulfillment_status', filters.fulfillment)
  if (filters.placedFrom) qs.set('placed_from', filters.placedFrom)
  if (filters.placedTo) qs.set('placed_to', filters.placedTo)
  if (filters.q) qs.set('q', filters.q)
  qs.set('page', String(filters.page))
  qs.set('per_page', String(filters.perPage))
  return qs
}

// The admin envelope is doc-only (no schema for this route yet), so normalize the raw JSON into
// the CommerceOrder shape OrdersTable already renders — the search projection is a subset of the
// full order row (no lines/events/addresses), so those default to their empty/null shape here,
// same principle as every other `normalize*` boundary in commerceOrders.ts.
function normalizeSearchOrder(raw: Record<string, unknown>): CommerceOrder {
  return {
    uuid: String(raw.uuid ?? ''),
    order_number: String(raw.order_number ?? ''),
    status: String(raw.status ?? 'pending_payment'),
    fulfillment_status: String(raw.fulfillment_status ?? 'unfulfilled'),
    email: String(raw.email ?? ''),
    user_uuid: typeof raw.user_uuid === 'string' ? raw.user_uuid : null,
    currency: String(raw.currency ?? ''),
    subtotal: typeof raw.subtotal === 'number' ? raw.subtotal : 0,
    discount_total: typeof raw.discount_total === 'number' ? raw.discount_total : 0,
    shipping_total: typeof raw.shipping_total === 'number' ? raw.shipping_total : 0,
    tax_total: typeof raw.tax_total === 'number' ? raw.tax_total : 0,
    grand_total: typeof raw.grand_total === 'number' ? raw.grand_total : 0,
    refunded_total: typeof raw.refunded_total === 'number' ? raw.refunded_total : 0,
    discount_code: typeof raw.discount_code === 'string' ? raw.discount_code : null,
    shipping_method: typeof raw.shipping_method === 'string' ? raw.shipping_method : null,
    addresses: null,
    placed_at: typeof raw.placed_at === 'string' ? raw.placed_at : null,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
    lines: [],
    events: [],
  }
}

export async function fetchOrderSearch(filters: OrderSearchFilters): Promise<OrderSearchPage> {
  const json = await authFetch(`${base()}/search?${searchParams(filters).toString()}`)
  const body = json as { data?: unknown[]; current_page?: number; per_page?: number; total?: number }
  const rows = Array.isArray(body.data) ? body.data : []
  return {
    orders: rows.map((o) => normalizeSearchOrder(o as Record<string, unknown>)),
    total: body.total ?? 0,
    current_page: body.current_page ?? filters.page,
    per_page: body.per_page ?? filters.perPage,
  }
}

/** Query key: normalized scalars only, never the filters object's own identity — a brand-new
 * object carrying the SAME scalar values must not be treated as a different cache entry. */
export function useOrderSearch(filters: Ref<OrderSearchFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [
        'commerce',
        'orders',
        'search',
        f.status,
        f.fulfillment,
        f.placedFrom,
        f.placedTo,
        f.q,
        f.page,
        f.perPage,
      ] as const
    },
    query: () => fetchOrderSearch(toValue(filters)),
  })
}

// ── URL contract (spec §2.4) ─────────────────────────────────────────────────────────────────
//
// Parsing and serializing live here (not in the page) so the hydration matrix and the canonical
// serializer are directly unit-testable without mounting a component.

function isValidEnum<T extends string>(value: unknown, allowed: readonly T[]): value is T {
  return typeof value === 'string' && (allowed as readonly string[]).includes(value)
}

/** Strict `YYYY-MM-DD` — round-trips through a UTC date construction so a syntactically-shaped
 * but impossible date (2026-02-30, 2026-13-01) is rejected, not silently normalized. */
function isValidIsoDate(value: unknown): value is string {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const [y, m, d] = value.split('-').map(Number) as [number, number, number]
  const date = new Date(Date.UTC(y, m - 1, d))
  return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d
}

function parsePositiveInt(value: unknown): number | null {
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null
  const n = Number(value)
  return Number.isSafeInteger(n) && n > 0 ? n : null
}

/** Validate raw `route.query` against the closed filter contract: only enum members, strict
 * round-trip-valid dates, `page >= 1`, and `per_page` 1–100 survive — everything else is
 * discarded back to `ORDER_SEARCH_DEFAULTS` (the backend would 422 on an invalid enum/date/
 * oversize `q` anyway, so a silently-corrected client never sends a request doomed to fail). */
export function parseOrderSearchQuery(query: Record<string, unknown>): OrderSearchFilters {
  const status = isValidEnum(query.status, ORDER_STATUSES) ? query.status : ORDER_SEARCH_DEFAULTS.status
  const fulfillment = isValidEnum(query.fulfillment, FULFILLMENT_STATUSES)
    ? query.fulfillment
    : ORDER_SEARCH_DEFAULTS.fulfillment
  const placedFrom = isValidIsoDate(query.placed_from) ? query.placed_from : ORDER_SEARCH_DEFAULTS.placedFrom
  const placedTo = isValidIsoDate(query.placed_to) ? query.placed_to : ORDER_SEARCH_DEFAULTS.placedTo
  const q = typeof query.q === 'string' ? query.q : ORDER_SEARCH_DEFAULTS.q
  const page = parsePositiveInt(query.page) ?? ORDER_SEARCH_DEFAULTS.page
  const perPageCandidate = parsePositiveInt(query.per_page)
  const perPage =
    perPageCandidate !== null && perPageCandidate <= 100 ? perPageCandidate : ORDER_SEARCH_DEFAULTS.perPage

  return { q, status, fulfillment, placedFrom, placedTo, page, perPage }
}

/** Canonical URL serializer: omits every null/default value so the address bar stays minimal —
 * the page's equality guard compares against exactly this shape to decide whether a
 * `router.replace` is actually needed (and so avoid looping on an equivalent query). */
export function serializeOrderSearchQuery(filters: OrderSearchFilters): Record<string, string> {
  const out: Record<string, string> = {}
  if (filters.status) out.status = filters.status
  if (filters.fulfillment) out.fulfillment = filters.fulfillment
  if (filters.placedFrom) out.placed_from = filters.placedFrom
  if (filters.placedTo) out.placed_to = filters.placedTo
  if (filters.q) out.q = filters.q
  if (filters.page !== ORDER_SEARCH_DEFAULTS.page) out.page = String(filters.page)
  if (filters.perPage !== ORDER_SEARCH_DEFAULTS.perPage) out.per_page = String(filters.perPage)
  return out
}

// ── CSV export ───────────────────────────────────────────────────────────────────────────────

/** Thrown by `downloadOrdersCsv` when the export would exceed the server's 10,000-row ceiling
 * (`AdminOrderController::export()`, Task 4) — carries the server's exact message so the caller
 * surfaces it verbatim via `useNotify().warning()` rather than a generic failure toast. */
export class ExportTooLargeError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'ExportTooLargeError'
  }
}

const EXPORT_TOO_LARGE_FALLBACK = 'Export exceeds 10,000 rows — narrow your filters.'

/**
 * Fetch the CSV with the session bearer and trigger a browser download — mirrors
 * `downloadSubmissionsCsv` (formSubmissions.ts:88) exactly, with one addition: the export route's
 * row-ceiling rejection arrives as a 422 JSON body, not a blob, so status is checked FIRST — before
 * `res.blob()` is ever called — or the 422's JSON body would be consumed as (invalid) CSV bytes.
 */
export async function downloadOrdersCsv(filters: OrderSearchFilters): Promise<void> {
  const token = useSessionStore().accessToken
  const res = await fetch(`${base()}/export?${searchParams(filters).toString()}`, {
    headers: token ? { authorization: `Bearer ${token}` } : {},
  })
  if (res.status === 422) {
    const body = (await res.json().catch(() => ({}))) as { message?: string }
    throw new ExportTooLargeError(
      typeof body.message === 'string' && body.message.trim() !== '' ? body.message : EXPORT_TOO_LARGE_FALLBACK,
    )
  }
  if (!res.ok) throw await responseError(res, 'Could not export orders.')
  const blob = await res.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'orders-export.csv'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
