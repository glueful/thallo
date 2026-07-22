import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
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

// ── Lifecycle actions (Task 13b) ─────────────────────────────────────────────
//
// Endpoints: `POST /commerce/orders/{uuid}/cancel`, `/mark-paid`, `/fulfill`
// (AdminOrderController::cancel()/markPaid()/fulfill()). Cancel and mark-paid take no request
// body at all (both handlers accept only `Request $request, string $uuid` — no DTO parameter);
// fulfill's body is exactly `FulfillOrderData` (Http/DTOs/FulfillOrderData.php): one optional,
// nullable string. Every success response is the SAME `Response::success($order, ...)` envelope
// `fetchOrder()` already parses, so all three fetchers reuse `normalizeOrder()` below.

/** The exact (and only) request body `POST /commerce/orders/{uuid}/fulfill` accepts — mirrors
 * `FulfillOrderData` field-for-field. */
export interface FulfillOrderInput {
  tracking_ref?: string | null
}

/**
 * Which lifecycle actions are legal from a given `commerce_orders.status`, mirroring
 * `OrderStateMachine::ALLOWED` (Orders/OrderStateMachine.php) exactly:
 *   pending_payment -> [paid, canceled]
 *   paid            -> [fulfilled, canceled, refunded]
 *   fulfilled       -> [refunded]
 * `canCancelOrder()` covers BOTH `pending_payment` and `paid` — the only two source states the
 * `canceled` target is reachable from (`transition()`/`assertTransition()` are called with a
 * FIXED target per endpoint, never a caller-chosen one). `canMarkOrderPaid()`/`canFulfillOrder()`
 * each cover their own single legal source state. Refund (`paid`/`fulfilled` -> `refunded`) is
 * Task 13c and intentionally not modeled here.
 *
 * These three functions are presentation guidance ONLY — they decide which buttons `OrderActions`
 * renders, never whether a request is allowed. The server re-asserts the same transition inside
 * its own CAS (`OrderRepository::transition()`) and rejects an illegal or since-changed status
 * with a 409 regardless of what the client believed was legal a moment ago; `OrderActions` always
 * surfaces that 409 inline rather than assuming its own guard was sufficient.
 */
export function canCancelOrder(status: string): boolean {
  return status === 'pending_payment' || status === 'paid'
}
export function canMarkOrderPaid(status: string): boolean {
  return status === 'pending_payment'
}
export function canFulfillOrder(status: string): boolean {
  return status === 'paid'
}

export async function cancelOrder(uuid: string): Promise<CommerceOrder> {
  const { data, error, response } = await client.POST('/commerce/orders/{uuid}/cancel', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeOrder((raw ?? {}) as Record<string, unknown>)
}

export async function markOrderPaid(uuid: string): Promise<CommerceOrder> {
  const { data, error, response } = await client.POST('/commerce/orders/{uuid}/mark-paid', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeOrder((raw ?? {}) as Record<string, unknown>)
}

export async function fulfillOrder(uuid: string, input: FulfillOrderInput): Promise<CommerceOrder> {
  const { data, error, response } = await client.POST('/commerce/orders/{uuid}/fulfill', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeOrder((raw ?? {}) as Record<string, unknown>)
}

// ── Refunds (Task 13c) ───────────────────────────────────────────────────────
//
// Endpoints: `POST`/`GET /commerce/orders/{uuid}/refunds` (create / per-order list) plus the
// cross-order `GET /commerce/refunds` (list) and `GET /commerce/refunds/{uuid}` (show) —
// AdminRefundController::store()/index()/list()/show(). A `commerce_refunds` row
// (`006_CreateCommerceRefundTables.php`) is exactly the field set RefundService::buildRow()
// builds. `lines` (the sibling `commerce_refund_lines` rows) is attached by `store()` and by the
// per-order `index()` (RefundRepository::listForOrder()), but NOT by the cross-order
// `list()`/`show()` (paginatedForTenant()/findByUuid() never join lines) — normalized as an
// optional-defaulting-to-empty array here, same principle as CommerceOrder's
// list-omits-lines/events note. `created_at`/`updated_at`/`provider_ref`/`failure_reason` are
// similarly ABSENT from the immediate store() response (it returns the in-memory row buildRow()
// constructed, before the DB fills in its own defaults) but present once re-fetched via
// index()/list()/show() — every field stays defensively nullable/defaulted for that reason.

export interface CommerceRefundLine {
  order_line_uuid: string
  quantity: number
  amount: number
}

export interface CommerceRefund {
  uuid: string
  order_uuid: string
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  amount: number
  currency: string
  /** `'manual' | 'gateway'` (RefundService's two issuance paths). */
  method: string
  /** `'pending' | 'completed' | 'failed'`. */
  status: string
  reason: string | null
  restocked: boolean
  failure_reason: string | null
  initiated_by: string | null
  created_at: string | null
  updated_at: string | null
  completed_at: string | null
  lines: CommerceRefundLine[]
}

/** The exact `CreateRefundData` request body shape (`Http/DTOs/CreateRefundData.php`) — `amount`
 * omitted/null means "refund the remaining balance" server-side (RefundService::validate()), but
 * RefundSlideover always sends an explicit amount (task-13c brief: the client always computes and
 * submits exact minor units, never relies on server-side defaulting). `lines` (per-order-line
 * attribution) is accepted for parity with the DTO but has no UI in this task — restock without
 * lines is a legitimate request the server itself rejects with a 422, surfaced verbatim. */
export interface CreateRefundInput {
  amount?: number | null
  reason?: string | null
  lines?: CommerceRefundLine[] | null
  restock?: boolean
}

export interface RefundListFilters {
  status?: string
  order?: string
  from?: string
  to?: string
  page?: number
  perPage?: number
}

export interface RefundListPage {
  refunds: CommerceRefund[]
  total: number
  current_page: number
  per_page: number
}

function normalizeRefundLine(raw: Record<string, unknown>): CommerceRefundLine {
  return {
    order_line_uuid: String(raw.order_line_uuid ?? ''),
    quantity: typeof raw.quantity === 'number' ? raw.quantity : 0,
    amount: typeof raw.amount === 'number' ? raw.amount : 0,
  }
}

function normalizeRefund(raw: Record<string, unknown>): CommerceRefund {
  const lines = Array.isArray(raw.lines) ? raw.lines : []
  return {
    uuid: String(raw.uuid ?? ''),
    order_uuid: String(raw.order_uuid ?? ''),
    amount: typeof raw.amount === 'number' ? raw.amount : 0,
    currency: String(raw.currency ?? ''),
    method: String(raw.method ?? ''),
    status: String(raw.status ?? 'pending'),
    reason: typeof raw.reason === 'string' ? raw.reason : null,
    restocked: raw.restocked === true,
    failure_reason: typeof raw.failure_reason === 'string' ? raw.failure_reason : null,
    initiated_by: typeof raw.initiated_by === 'string' ? raw.initiated_by : null,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
    completed_at: typeof raw.completed_at === 'string' ? raw.completed_at : null,
    lines: lines.map((l) => normalizeRefundLine(l as Record<string, unknown>)),
  }
}

/** Legal refund source statuses, mirroring `OrderStateMachine::ALLOWED` exactly: both `paid` and
 * `fulfilled` transition to `refunded` — `pending_payment`/`canceled`/`refunded` never do. Same
 * presentation-guidance-only caveat as `canCancelOrder()` et al. above: the server's own
 * validate() (`status: order must be paid or fulfilled to accept a refund.`) stays authoritative. */
export function canRefundOrder(status: string): boolean {
  return status === 'paid' || status === 'fulfilled'
}

/** `POST /commerce/orders/{uuid}/refunds` (AdminRefundController::store()) — REQUIRES a non-empty
 * `Idempotency-Key` header (max 128 chars) or the server itself returns 422; RefundSlideover
 * generates a fresh one per open so retries within the same attempt replay idempotently while a
 * freshly reopened slideover always starts a genuinely new request. */
export async function createRefund(
  orderUuid: string,
  input: CreateRefundInput,
  idempotencyKey: string,
): Promise<CommerceRefund> {
  const { data, error, response } = await client.POST('/commerce/orders/{uuid}/refunds', {
    params: { path: { uuid: orderUuid } },
    headers: { 'Idempotency-Key': idempotencyKey },
    body: {
      amount: input.amount ?? null,
      reason: input.reason ?? null,
      lines: input.lines ?? null,
      restock: input.restock ?? false,
    } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeRefund((raw ?? {}) as Record<string, unknown>)
}

/** `GET /commerce/orders/{uuid}/refunds` (AdminRefundController::index(), per-order list) — every
 * row carries its `lines` (RefundRepository::listForOrder() attaches them in one batched query). */
export async function fetchOrderRefunds(orderUuid: string): Promise<CommerceRefund[]> {
  const { data, error, response } = await client.GET('/commerce/orders/{uuid}/refunds', {
    params: { path: { uuid: orderUuid } },
  })
  if (error) throw toApiError(error, response)
  const body = data as { data?: unknown[] } | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return rows.map((r) => normalizeRefund(r as Record<string, unknown>))
}

/** `GET /commerce/refunds` (AdminRefundController::list(), cross-order admin list) —
 * `RefundListQuery`'s exact param set; `paginatedForTenant()` never attaches `lines`. No admin page
 * consumes this yet (task-13c scope is the per-order slideover + list), kept here — with the
 * cross-order `show()` fetcher below — because the brief's endpoint contract explicitly covers
 * both, and a future global Refunds surface can build on an already-verified shape. */
export async function fetchRefunds(filters: RefundListFilters = {}): Promise<RefundListPage> {
  const { data, error, response } = await client.GET('/commerce/refunds', {
    params: {
      query: {
        status: filters.status || undefined,
        order: filters.order || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
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
    refunds: rows.map((r) => normalizeRefund(r as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

/** `GET /commerce/refunds/{uuid}` (AdminRefundController::show()) — `findByUuid()` never attaches
 * `lines`, so this always normalizes to an empty `lines` array. */
export async function fetchRefund(uuid: string): Promise<CommerceRefund> {
  const { data, error, response } = await client.GET('/commerce/refunds/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Refund not found.', response?.status ?? 404, {}, data)
  return normalizeRefund(raw as Record<string, unknown>)
}

export function useOrderRefunds(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceOrderRefunds(toValue(uuid)),
    query: () => fetchOrderRefunds(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

/**
 * Every lifecycle action invalidates BOTH the order's own detail query AND the list — unlike
 * commerceCatalog.ts's per-product-only mutations (variant/media/stock), a lifecycle transition
 * changes `status` (and for fulfill, `fulfillment_status`), fields `OrdersTable` itself renders, so
 * the list can never skip invalidation the way those product-detail-only mutations do. Mirrors
 * `update`/`remove`'s ordering in `useCommerceProductMutations()`: detail first, then list.
 *
 * `refund` additionally invalidates the two refund-specific keys (per-order list + the cross-order
 * list) — a completed refund changes what BOTH of those would return, even though no page consumes
 * the cross-order one yet.
 *
 * `addNote` (Task 13d) invalidates its own `qk.commerceOrderNotes()` key AND the order
 * detail: the backend records a `note.added` row in commerce_order_events, which the detail's
 * Status timeline renders via `order.events` — notes-only invalidation left that same-page
 * card stale. Unlike every
 * lifecycle action above, a note changes no field `OrdersTable` or the order detail's own primary
 * fields render (the dedicated notes list reads through the separate `/notes` endpoint, not
 * `order.events`), so this mirrors commerceCatalog.ts's variant/media/stock mutations' single
 * narrow invalidation rather than cascading to the order detail or list.
 */
export function useCommerceOrderMutations() {
  const cache = useQueryCache()
  const invalidate = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceOrder(uuid) })
    cache.invalidateQueries({ key: qk.commerceOrders() })
  }
  const invalidateRefund = (uuid: string) => {
    invalidate(uuid)
    cache.invalidateQueries({ key: qk.commerceOrderRefunds(uuid) })
    cache.invalidateQueries({ key: qk.commerceRefunds() })
  }
  const invalidateNotes = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceOrderNotes(uuid) })
    // note.added also lands in order.events (the detail's Status timeline) — refresh it too.
    cache.invalidateQueries({ key: qk.commerceOrder(uuid) })
  }

  return {
    cancel: useMutation({
      mutation: (uuid: string) => cancelOrder(uuid),
      onSettled: (_d, _e, uuid) => invalidate(uuid),
    }),
    markPaid: useMutation({
      mutation: (uuid: string) => markOrderPaid(uuid),
      onSettled: (_d, _e, uuid) => invalidate(uuid),
    }),
    fulfill: useMutation({
      mutation: (vars: { uuid: string; input: FulfillOrderInput }) => fulfillOrder(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
    refund: useMutation({
      mutation: (vars: { uuid: string; input: CreateRefundInput; idempotencyKey: string }) =>
        createRefund(vars.uuid, vars.input, vars.idempotencyKey),
      onSettled: (_d, _e, vars) => invalidateRefund(vars.uuid),
    }),
    addNote: useMutation({
      mutation: (vars: { uuid: string; input: CreateOrderNoteInput }) => addOrderNote(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateNotes(vars.uuid),
    }),
  }
}

// ── Notes (Task 13d) ─────────────────────────────────────────────────────────
//
// Endpoints: `GET`/`POST /commerce/orders/{uuid}/notes` (AdminOrderController::notes()/addNote()).
// A note is a `commerce_order_events` row of `type: 'note.added'` — the SAME table/shape as
// `CommerceOrderEvent` above (note.added entries also live in `order.events`, per that interface's
// own docblock) — but `notes()` pre-filters to just those rows and returns them in the SAME
// ascending-id (chronological) order `eventsForOrder()` already produces, so this fetcher never
// re-sorts. `addNote()`'s 200 response is `{ order_uuid, note: {...} }` — the in-memory note this
// request just built, not a full event row (no `uuid`/`created_at`) — mirroring `createRefund()`'s
// "immediate response before the DB fills in its own defaults" precedent, so this resolves to
// `void`; callers rely on the notes-key invalidation to refetch the authoritative list.

/** A `commerce_order_events` row of `type: 'note.added'`, flattened from its `payload` for direct
 * display — `visibility`/`actor_uuid` are read from the event's own top-level columns (the
 * authoritative source `recordEvent()` writes), falling back to the payload's duplicate copy only
 * if the top-level column is somehow absent. */
export interface CommerceOrderNote {
  uuid: string
  body: string
  visibility: string
  notify: boolean
  actor_uuid: string | null
  created_at: string | null
}

/** The exact `CreateOrderNoteData` request body shape (`Http/DTOs/CreateOrderNoteData.php`).
 * `visibility` defaults to `'internal'` and `notify` to `false` — OrderNotes.vue ships no
 * visibility/notify UI (task-13d brief scope), so every note it submits is an internal,
 * non-notifying one; both remain overridable here for callers/tests that need the full DTO
 * surface (`notify: true` REQUIRES `visibility: 'customer'` server-side or the request 422s). */
export interface CreateOrderNoteInput {
  body: string
  visibility?: 'internal' | 'customer'
  notify?: boolean
}

function normalizeOrderNote(raw: Record<string, unknown>): CommerceOrderNote {
  const payload = (typeof raw.payload === 'object' && raw.payload !== null ? raw.payload : {}) as Record<
    string,
    unknown
  >
  return {
    uuid: String(raw.uuid ?? ''),
    body: typeof payload.body === 'string' ? payload.body : '',
    visibility:
      typeof raw.visibility === 'string'
        ? raw.visibility
        : typeof payload.visibility === 'string'
          ? payload.visibility
          : 'internal',
    notify: payload.notify === true,
    actor_uuid:
      typeof raw.actor_uuid === 'string'
        ? raw.actor_uuid
        : typeof payload.actor_uuid === 'string'
          ? payload.actor_uuid
          : null,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
  }
}

/** `GET /commerce/orders/{uuid}/notes` (AdminOrderController::notes(), view-graded). */
export async function fetchOrderNotes(orderUuid: string): Promise<CommerceOrderNote[]> {
  const { data, error, response } = await client.GET('/commerce/orders/{uuid}/notes', {
    params: { path: { uuid: orderUuid } },
  })
  if (error) throw toApiError(error, response)
  const body = data as { data?: unknown[] } | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return rows.map((r) => normalizeOrderNote(r as Record<string, unknown>))
}

/** `POST /commerce/orders/{uuid}/notes` (AdminOrderController::addNote(), manage-graded). */
export async function addOrderNote(orderUuid: string, input: CreateOrderNoteInput): Promise<void> {
  const { error, response } = await client.POST('/commerce/orders/{uuid}/notes', {
    params: { path: { uuid: orderUuid } },
    body: {
      body: input.body,
      visibility: input.visibility ?? 'internal',
      notify: input.notify ?? false,
    } as never,
  })
  if (error) throw toApiError(error, response)
}

export function useOrderNotes(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceOrderNotes(toValue(uuid)),
    query: () => fetchOrderNotes(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

// ── Invoice data (Task 13d) ──────────────────────────────────────────────────
//
// Endpoint: `GET /commerce/orders/{uuid}/invoice-data` (AdminOrderController::invoiceData(),
// view-graded) — mirrors `InvoiceData::build()` (Invoices/InvoiceData.php) field-for-field. Every
// `*_minor` amount is a genuine integer minor-unit value (format with `useMoney`, never `Number()`)
// and `refunds` is already completed-only, exactly whitelisted (`date`, `amount_minor`, `method` —
// never `reason`) by the backend itself.

export interface CommerceInvoiceLine {
  name: string
  sku: string
  quantity: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  unit_minor: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  subtotal_minor: number
  addons: CommerceOrderLineAddon[]
}

export interface CommerceInvoiceRefund {
  date: string | null
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  amount_minor: number
  method: string
}

export interface CommerceInvoiceData {
  schema_version: number
  seller: { name: string | null; address: string | null; tax_id: string | null }
  buyer: { email: string | null; addresses: CommerceOrderAddresses | null }
  order: {
    number: string | null
    dates: { placed_at: string | null; created_at: string | null; updated_at: string | null }
    currency: string | null
    status: string | null
  }
  lines: CommerceInvoiceLine[]
  totals: {
    subtotal_minor: number
    discount_minor: number
    shipping_minor: number
    tax_minor: number
    grand_minor: number
    refunded_minor: number
  }
  refunds: CommerceInvoiceRefund[]
}

function normalizeInvoiceLine(raw: Record<string, unknown>): CommerceInvoiceLine {
  const addons = Array.isArray(raw.addons) ? raw.addons : []
  return {
    name: String(raw.name ?? ''),
    sku: String(raw.sku ?? ''),
    quantity: typeof raw.quantity === 'number' ? raw.quantity : 0,
    unit_minor: typeof raw.unit_minor === 'number' ? raw.unit_minor : 0,
    subtotal_minor: typeof raw.subtotal_minor === 'number' ? raw.subtotal_minor : 0,
    addons: addons.map((a) => normalizeOrderLineAddon(a as Record<string, unknown>)),
  }
}

function normalizeInvoiceRefund(raw: Record<string, unknown>): CommerceInvoiceRefund {
  return {
    date: typeof raw.date === 'string' ? raw.date : null,
    amount_minor: typeof raw.amount_minor === 'number' ? raw.amount_minor : 0,
    method: String(raw.method ?? ''),
  }
}

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : {}
}

function normalizeInvoiceData(raw: Record<string, unknown>): CommerceInvoiceData {
  const seller = asRecord(raw.seller)
  const buyer = asRecord(raw.buyer)
  const orderInfo = asRecord(raw.order)
  const dates = asRecord(orderInfo.dates)
  const totals = asRecord(raw.totals)
  const lines = Array.isArray(raw.lines) ? raw.lines : []
  const refunds = Array.isArray(raw.refunds) ? raw.refunds : []

  return {
    schema_version: typeof raw.schema_version === 'number' ? raw.schema_version : 1,
    seller: {
      name: typeof seller.name === 'string' ? seller.name : null,
      address: typeof seller.address === 'string' ? seller.address : null,
      tax_id: typeof seller.tax_id === 'string' ? seller.tax_id : null,
    },
    buyer: {
      email: typeof buyer.email === 'string' ? buyer.email : null,
      addresses: normalizeAddresses(buyer.addresses),
    },
    order: {
      number: typeof orderInfo.number === 'string' ? orderInfo.number : null,
      dates: {
        placed_at: typeof dates.placed_at === 'string' ? dates.placed_at : null,
        created_at: typeof dates.created_at === 'string' ? dates.created_at : null,
        updated_at: typeof dates.updated_at === 'string' ? dates.updated_at : null,
      },
      currency: typeof orderInfo.currency === 'string' ? orderInfo.currency : null,
      status: typeof orderInfo.status === 'string' ? orderInfo.status : null,
    },
    lines: lines.map((l) => normalizeInvoiceLine(l as Record<string, unknown>)),
    totals: {
      subtotal_minor: typeof totals.subtotal_minor === 'number' ? totals.subtotal_minor : 0,
      discount_minor: typeof totals.discount_minor === 'number' ? totals.discount_minor : 0,
      shipping_minor: typeof totals.shipping_minor === 'number' ? totals.shipping_minor : 0,
      tax_minor: typeof totals.tax_minor === 'number' ? totals.tax_minor : 0,
      grand_minor: typeof totals.grand_minor === 'number' ? totals.grand_minor : 0,
      refunded_minor: typeof totals.refunded_minor === 'number' ? totals.refunded_minor : 0,
    },
    refunds: refunds.map((r) => normalizeInvoiceRefund(r as Record<string, unknown>)),
  }
}

/** `GET /commerce/orders/{uuid}/invoice-data` (AdminOrderController::invoiceData()). */
export async function fetchOrderInvoiceData(orderUuid: string): Promise<CommerceInvoiceData> {
  const { data, error, response } = await client.GET('/commerce/orders/{uuid}/invoice-data', {
    params: { path: { uuid: orderUuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeInvoiceData(asRecord(raw))
}

/** `enabled` defaults to always-on but OrderDetail passes a modal-open ref so the request only
 * fires once the invoice section is actually opened (mirrors `useCommerceProduct()`'s identical
 * `enabled` parameter). */
export function useOrderInvoiceData(
  uuid: MaybeRefOrGetter<string>,
  enabled: MaybeRefOrGetter<boolean> = true,
) {
  return useQuery({
    key: () => qk.commerceOrderInvoiceData(toValue(uuid)),
    query: () => fetchOrderInvoiceData(toValue(uuid)),
    enabled: () => toValue(enabled) && !!toValue(uuid),
  })
}
