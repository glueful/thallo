import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'
import { qk } from './keys'

// Closed vocabularies mirrored from the backend (Glueful\Extensions\Commerce\Orders\
// OrderStateMachine / FulfillmentStatus) — the single frontend declaration for filters and
// status badges. `refunded` is reachable from both `paid` and `fulfilled`; `partial` is a
// FULFILLMENT value only, never a `commerce_orders.status` lifecycle value.
export const ORDER_STATUSES = ['pending_payment', 'paid', 'fulfilled', 'canceled', 'refunded'] as const
export type CommerceOrderStatus = (typeof ORDER_STATUSES)[number]

export const FULFILLMENT_STATUSES = ['unfulfilled', 'partial', 'fulfilled'] as const
export type CommerceFulfillmentStatus = (typeof FULFILLMENT_STATUSES)[number]

/** One sanitized add-on echo (AddonSnapshot::sanitize() — Cart/AddonSnapshot.php): optional keys
 * are OMITTED (not null) by the backend whenever they don't apply to the entry's field type, so
 * every key here stays optional rather than `| null`. */
export interface CommerceOrderLineAddon {
  name: string
  field_type?: string
  choice_label?: string
  value?: unknown
  price_delta: number
}

/** A `commerce_order_lines` row as whitelisted by AdminOrderController::linesProjection() —
 * never the internal `order_uuid`/`variant_uuid` columns that method deliberately excludes. */
export interface CommerceOrderLine {
  uuid: string
  product_name: string
  sku: string
  quantity: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  unit_price: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  line_total: number
  option_values: Record<string, unknown>
  addons: CommerceOrderLineAddon[]
}

/** A `commerce_order_events` row — the append-only audit trail AdminOrderController::show()
 * attaches as `order.events`. Every lifecycle transition ({@see OrderRepository::transition()})
 * records one of these as `status:{to}`, so this list doubles as the order's status timeline;
 * `note.added` entries (Task 13d) live in the SAME list. */
export interface CommerceOrderEvent {
  uuid: string
  type: string
  payload: Record<string, unknown> | null
  actor_uuid: string | null
  visibility: string
  created_at: string | null
}

/** `addresses.shipping`/`addresses.billing` are a DELIBERATELY loose shape (see
 * CheckoutPlaceData/CreateAddressData's own docblocks: "same loose shape checkout already
 * accepts") — the backend enforces no fixed key schema, only a handful of alias groups a
 * display layer may probe (see SellerOrderService::shippingAddressProjection() for the
 * canonical alias list, ported for display in OrderDetail's `addressLine()`). Kept as a raw
 * record here rather than a fixed interface — normalizing it further would fabricate structure
 * the backend doesn't actually guarantee. */
export type CommerceOrderAddress = Record<string, unknown>

export interface CommerceOrderAddresses {
  shipping: CommerceOrderAddress | null
  billing: CommerceOrderAddress | null
}

/** A `commerce_orders` row (design spec Layer 1, order lifecycle) as returned by the admin order
 * endpoints. `lines`/`events` are attached whenever the endpoint includes them (order show does;
 * the paginated list does not — mirrors CommerceProduct's identical `variants` note). Internal
 * columns the backend's own row carries but that have no admin-UI value — `tenant_uuid`,
 * `guest_token_hash`, `fulfillment_revision`, `refund_revision`, `marketplace_partitioned` — are
 * deliberately excluded here, same principle as every other projection in this codebase
 * (AdminOrderController::sellerOrderProjection()'s own docblock states it explicitly). */
export interface CommerceOrder {
  uuid: string
  order_number: string
  status: string
  fulfillment_status: string
  email: string
  user_uuid: string | null
  currency: string
  /** Minor-unit integer amounts — format with `useMoney`, never `Number()`. */
  subtotal: number
  discount_total: number
  shipping_total: number
  tax_total: number
  grand_total: number
  refunded_total: number
  discount_code: string | null
  shipping_method: string | null
  addresses: CommerceOrderAddresses | null
  placed_at: string | null
  created_at: string | null
  updated_at: string | null
  lines: CommerceOrderLine[]
  events: CommerceOrderEvent[]
}

export interface OrderListFilters {
  status?: string
  page?: number
  perPage?: number
}

export interface OrderListPage {
  orders: CommerceOrder[]
  total: number
  current_page: number
  per_page: number
}

// The admin envelopes are doc-only in the OpenAPI schema (see commerceCatalog.ts's identical
// note), so normalize the raw JSON into the stricter hand-written shapes above at the boundary.

function normalizeAddress(raw: unknown): CommerceOrderAddress | null {
  return typeof raw === 'object' && raw !== null && !Array.isArray(raw)
    ? (raw as CommerceOrderAddress)
    : null
}

function normalizeAddresses(raw: unknown): CommerceOrderAddresses | null {
  if (typeof raw !== 'object' || raw === null) return null
  const obj = raw as Record<string, unknown>
  return {
    shipping: normalizeAddress(obj.shipping),
    billing: normalizeAddress(obj.billing),
  }
}

function normalizeOrderLineAddon(raw: Record<string, unknown>): CommerceOrderLineAddon {
  const addon: CommerceOrderLineAddon = {
    name: String(raw.name ?? ''),
    price_delta: typeof raw.price_delta === 'number' ? raw.price_delta : 0,
  }
  if (typeof raw.field_type === 'string') addon.field_type = raw.field_type
  if (typeof raw.choice_label === 'string') addon.choice_label = raw.choice_label
  if ('value' in raw && raw.value !== null && raw.value !== undefined) addon.value = raw.value
  return addon
}

function normalizeOrderLine(raw: Record<string, unknown>): CommerceOrderLine {
  const addons = Array.isArray(raw.addons) ? raw.addons : []
  const optionValues = raw.option_values
  return {
    uuid: String(raw.uuid ?? ''),
    product_name: String(raw.product_name ?? ''),
    sku: String(raw.sku ?? ''),
    quantity: typeof raw.quantity === 'number' ? raw.quantity : 0,
    unit_price: typeof raw.unit_price === 'number' ? raw.unit_price : 0,
    line_total: typeof raw.line_total === 'number' ? raw.line_total : 0,
    option_values:
      typeof optionValues === 'object' && optionValues !== null && !Array.isArray(optionValues)
        ? (optionValues as Record<string, unknown>)
        : {},
    addons: addons.map((a) => normalizeOrderLineAddon(a as Record<string, unknown>)),
  }
}

function normalizeOrderEvent(raw: Record<string, unknown>): CommerceOrderEvent {
  const payload = raw.payload
  return {
    uuid: String(raw.uuid ?? ''),
    type: String(raw.type ?? ''),
    payload: typeof payload === 'object' && payload !== null && !Array.isArray(payload) ? payload as Record<string, unknown> : null,
    actor_uuid: typeof raw.actor_uuid === 'string' ? raw.actor_uuid : null,
    visibility: String(raw.visibility ?? 'internal'),
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
  }
}

function normalizeOrder(raw: Record<string, unknown>): CommerceOrder {
  const lines = Array.isArray(raw.lines) ? raw.lines : []
  const events = Array.isArray(raw.events) ? raw.events : []
  return {
    uuid: String(raw.uuid ?? ''),
    order_number: String(raw.order_number ?? ''),
    status: String(raw.status ?? 'pending_payment'),
    fulfillment_status: String(raw.fulfillment_status ?? 'unfulfilled'),
    email: String(raw.email ?? ''),
    user_uuid: typeof raw.user_uuid === 'string' ? raw.user_uuid : null,
    currency: String(raw.currency ?? ''),
    // Amounts are JSON numbers from the API; anything else is malformed and becomes the neutral
    // fallback rather than a silently Number()-coerced guess (mirrors commerceCatalog.ts's
    // normalizeVariant price handling) — money display goes through useMoney, which rejects
    // unsafe values, so this boundary stays equally strict.
    subtotal: typeof raw.subtotal === 'number' ? raw.subtotal : 0,
    discount_total: typeof raw.discount_total === 'number' ? raw.discount_total : 0,
    shipping_total: typeof raw.shipping_total === 'number' ? raw.shipping_total : 0,
    tax_total: typeof raw.tax_total === 'number' ? raw.tax_total : 0,
    grand_total: typeof raw.grand_total === 'number' ? raw.grand_total : 0,
    refunded_total: typeof raw.refunded_total === 'number' ? raw.refunded_total : 0,
    discount_code: typeof raw.discount_code === 'string' ? raw.discount_code : null,
    shipping_method: typeof raw.shipping_method === 'string' ? raw.shipping_method : null,
    addresses: normalizeAddresses(raw.addresses),
    placed_at: typeof raw.placed_at === 'string' ? raw.placed_at : null,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
    lines: lines.map((l) => normalizeOrderLine(l as Record<string, unknown>)),
    events: events.map((e) => normalizeOrderEvent(e as Record<string, unknown>)),
  }
}

// ── Fetchers ─────────────────────────────────────────────────────────────────

/** `GET /commerce/orders` — OrderListQuery's exact param set is `{status, page, per_page}`; there
 * is no `type`/`q` filter on orders (unlike products). */
export async function fetchOrders(filters: OrderListFilters = {}): Promise<OrderListPage> {
  const { data, error, response } = await client.GET('/commerce/orders', {
    params: {
      query: {
        status: filters.status || undefined,
        page: filters.page,
        per_page: filters.perPage,
      },
    },
  })
  if (error) throw toApiError(error, response)
  const body = data as
    | { data?: unknown[]; current_page?: number; per_page?: number; total?: number }
    | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return {
    orders: rows.map((o) => normalizeOrder(o as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

export async function fetchOrder(uuid: string): Promise<CommerceOrder> {
  const { data, error, response } = await client.GET('/commerce/orders/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Order not found.', response?.status ?? 404, {}, data)
  return normalizeOrder(raw as Record<string, unknown>)
}

// ── Query wrappers ───────────────────────────────────────────────────────────

export function useCommerceOrders(filters: MaybeRefOrGetter<OrderListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [...qk.commerceOrders(), f.status ?? '', f.page ?? 1, f.perPage ?? 24]
    },
    query: () => fetchOrders(toValue(filters)),
  })
}

export function useCommerceOrder(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceOrder(toValue(uuid)),
    query: () => fetchOrder(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}
