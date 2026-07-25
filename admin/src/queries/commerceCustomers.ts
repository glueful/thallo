import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'
import { qk } from './keys'

// Task 17 (admin-commerce-area plan, slice 3): Customers, READ-ONLY —
// `AdminCustomerController` / `Glueful\Extensions\Commerce\Http\Admin\AdminCustomerController`.
// There is NO dedicated customer table: every row is `commerce_orders` aggregated per tenant by
// `user_uuid` when present, else by the normalized `lower(trim(email))` guest identity
// (`CustomerAggregationRepository`'s own class docblock has the full portability/SQL story). No
// mutation endpoint exists on this surface at all, so — unlike every other commerce admin page —
// there is no `can_manage` gating anywhere here; the whole surface is view-only regardless of
// capability grade.

export const CUSTOMER_SORT_FIELDS = ['last_order_at', 'total_spent'] as const
export type CommerceCustomerSort = (typeof CUSTOMER_SORT_FIELDS)[number]

/**
 * Design spec §7/Resolved Decision 2: a customer's `{key}` is NEVER "looks like a uuid" sniffed —
 * every lookup states explicitly whether it's a registered account (`user`) or a guest email
 * (`email`). `CustomerLookupQuery` 422s a missing/invalid `by`, so this frontend never sends an
 * ambiguous request either.
 */
export type CommerceCustomerKeyType = 'user' | 'email'

/**
 * One aggregated customer row — the SAME projection `CustomerAggregationRepository::paginate()`/
 * `findByUser()`/`findByEmail()` all share (its own docblock: "so the numbers can never drift
 * between the two surfaces"), enriched with an optional `username` by
 * `AdminCustomerController::enrich()`. `key` is the group's identity: the `user_uuid` verbatim for
 * a registered customer, or the ALREADY-NORMALIZED (`lower(trim())`) email for a guest — never the
 * raw as-typed email. `username` is a soft enrichment (an unresolvable/unbound
 * `UserProviderInterface` degrades to no key added at all, never an error) — normalized to `null`
 * here rather than left optional, mirroring every other soft-optional field in this codebase (e.g.
 * `CommerceOrder.discount_code`).
 */
export interface CommerceCustomer {
  key_type: CommerceCustomerKeyType
  key: string
  user_uuid: string | null
  email: string
  orders_count: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  total_spent_minor: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  refunded_minor: number
  first_order_at: string | null
  last_order_at: string | null
  username: string | null
}

/**
 * A `commerce_customer_addresses` row, whitelisted by `AdminCustomerController::addressProjection()`
 * — internal scoping columns (`tenant_uuid`, `user_uuid`) never leave that whitelist, since the
 * address already belongs to the customer being viewed. Attached to `show()` ONLY for a
 * `by=user` lookup (a guest identity has no address book, keyed by `user_uuid`, not email) — see
 * `CommerceCustomerDetail.addresses`'s own doc for how that absence is represented here.
 */
export interface CommerceCustomerAddress {
  uuid: string
  label: string | null
  address: Record<string, unknown>
  is_default_shipping: boolean
  is_default_billing: boolean
  created_at: string | null
  updated_at: string | null
}

/**
 * One entry of a customer's recent order history, as attached by `show()`
 * (`OrderRepository::paginatedFor()`, most-recent-first, capped at 25). That repository method
 * returns RAW `commerce_orders` rows (every internal column — `id`, `tenant_uuid`,
 * `guest_token_hash`, `fulfillment_revision`, `refund_revision`, `marketplace_partitioned`,
 * `metadata`, …) with no admin-projection whitelist of its own, unlike
 * `AdminOrderController`'s dedicated projections — this interface is this frontend's OWN
 * whitelist of the fields actually useful for order-history display, same "normalize only real,
 * useful fields" principle as everywhere else in this codebase. No `lines`/`events`: this listing
 * never joins either.
 */
export interface CommerceCustomerOrder {
  uuid: string
  order_number: string
  status: string
  fulfillment_status: string
  email: string
  currency: string
  /** Minor-unit integer amounts — format with `useMoney`, never `Number()`. */
  subtotal: number
  discount_total: number
  shipping_total: number
  tax_total: number
  grand_total: number
  refunded_total: number
  placed_at: string | null
  created_at: string | null
}

/**
 * `GET /commerce/customers/{key}` (`AdminCustomerController::show()`) — the same aggregate as
 * `CommerceCustomer`, plus recent orders and (user-keyed only) the address book. `addresses` is
 * `null` for an email-keyed (guest) customer — the key never appears in the response at all in
 * that case, distinct from a user-keyed customer with an empty (but present) address book, which
 * normalizes to `[]`.
 */
export interface CommerceCustomerDetail extends CommerceCustomer {
  orders: CommerceCustomerOrder[]
  addresses: CommerceCustomerAddress[] | null
}

export interface CustomerListFilters {
  email?: string
  sort?: CommerceCustomerSort
  direction?: 'asc' | 'desc'
  page?: number
  perPage?: number
}

export interface CustomerListPage {
  customers: CommerceCustomer[]
  total: number
  current_page: number
  per_page: number
}

// The admin envelopes are doc-only in the OpenAPI schema (see commerceReviews.ts's identical
// note), so normalize the raw JSON into the stricter hand-written shapes above at the boundary.

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

function normalizeCustomer(raw: Record<string, unknown>): CommerceCustomer {
  return {
    key_type: raw.key_type === 'user' ? 'user' : 'email',
    key: String(raw.key ?? ''),
    user_uuid: typeof raw.user_uuid === 'string' ? raw.user_uuid : null,
    email: String(raw.email ?? ''),
    orders_count: typeof raw.orders_count === 'number' ? raw.orders_count : 0,
    total_spent_minor: typeof raw.total_spent_minor === 'number' ? raw.total_spent_minor : 0,
    refunded_minor: typeof raw.refunded_minor === 'number' ? raw.refunded_minor : 0,
    first_order_at: typeof raw.first_order_at === 'string' ? raw.first_order_at : null,
    last_order_at: typeof raw.last_order_at === 'string' ? raw.last_order_at : null,
    username: typeof raw.username === 'string' ? raw.username : null,
  }
}

function normalizeCustomerAddress(raw: Record<string, unknown>): CommerceCustomerAddress {
  const address = raw.address
  return {
    uuid: String(raw.uuid ?? ''),
    label: typeof raw.label === 'string' ? raw.label : null,
    address: typeof address === 'object' && address !== null && !Array.isArray(address) ? (address as Record<string, unknown>) : {},
    is_default_shipping: raw.is_default_shipping === true,
    is_default_billing: raw.is_default_billing === true,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

function normalizeCustomerOrder(raw: Record<string, unknown>): CommerceCustomerOrder {
  return {
    uuid: String(raw.uuid ?? ''),
    order_number: String(raw.order_number ?? ''),
    status: String(raw.status ?? 'pending_payment'),
    fulfillment_status: String(raw.fulfillment_status ?? 'unfulfilled'),
    email: String(raw.email ?? ''),
    currency: String(raw.currency ?? ''),
    subtotal: typeof raw.subtotal === 'number' ? raw.subtotal : 0,
    discount_total: typeof raw.discount_total === 'number' ? raw.discount_total : 0,
    shipping_total: typeof raw.shipping_total === 'number' ? raw.shipping_total : 0,
    tax_total: typeof raw.tax_total === 'number' ? raw.tax_total : 0,
    grand_total: typeof raw.grand_total === 'number' ? raw.grand_total : 0,
    refunded_total: typeof raw.refunded_total === 'number' ? raw.refunded_total : 0,
    placed_at: typeof raw.placed_at === 'string' ? raw.placed_at : null,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
  }
}

function normalizeCustomerDetail(raw: Record<string, unknown>): CommerceCustomerDetail {
  const orders = Array.isArray(raw.orders) ? raw.orders : []
  const addresses = raw.addresses
  return {
    ...normalizeCustomer(raw),
    orders: orders.map((o) => normalizeCustomerOrder(asRecord(o))),
    addresses: Array.isArray(addresses) ? addresses.map((a) => normalizeCustomerAddress(asRecord(a))) : null,
  }
}

// ── Fetchers ─────────────────────────────────────────────────────────────────

/** `GET /commerce/customers` — `CustomerListQuery`'s exact param set is
 * `{email, sort, direction, page, per_page}`. */
export async function fetchCustomers(filters: CustomerListFilters = {}): Promise<CustomerListPage> {
  const { data, error, response } = await client.GET('/commerce/customers', {
    params: {
      query: {
        email: filters.email || undefined,
        sort: filters.sort,
        direction: filters.direction,
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
    customers: rows.map((c) => normalizeCustomer(asRecord(c))),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

/** `GET /commerce/customers/{key}` (`AdminCustomerController::show()`) — `by` is REQUIRED
 * (`CustomerLookupQuery`, 422 if missing/invalid); the caller must already know which kind of
 * identity `key` is (never sniffed here either). Non-revealing 404 (unknown key OR cross-tenant)
 * surfaces as an `ApiError` like every other detail fetcher in this codebase. */
export async function fetchCustomer(
  key: string,
  by: CommerceCustomerKeyType,
): Promise<CommerceCustomerDetail> {
  const { data, error, response } = await client.GET('/commerce/customers/{key}', {
    params: { path: { key }, query: { by } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Customer not found.', response?.status ?? 404, {}, data)
  return normalizeCustomerDetail(asRecord(raw))
}

// ── Query wrappers ───────────────────────────────────────────────────────────

export function useCommerceCustomers(filters: MaybeRefOrGetter<CustomerListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [
        ...qk.commerceCustomers(),
        f.email ?? '',
        f.sort ?? '',
        f.direction ?? '',
        f.page ?? 1,
        f.perPage ?? 24,
      ]
    },
    query: () => fetchCustomers(toValue(filters)),
  })
}

export function useCommerceCustomer(
  key: MaybeRefOrGetter<string>,
  by: MaybeRefOrGetter<CommerceCustomerKeyType>,
) {
  return useQuery({
    key: () => qk.commerceCustomer(toValue(key)),
    query: () => fetchCustomer(toValue(key), toValue(by)),
    enabled: () => !!toValue(key),
  })
}
