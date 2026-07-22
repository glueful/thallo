import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError, ApiError } from '@/api/errors'
import { qk } from './keys'

// Task 14 (admin-commerce-area plan, slice 3): discount CRUD (`AdminDiscountController` /
// `Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController`). The admin API only ever
// creates/edits `percentage`/`fixed` discounts (`validated()`'s own `type` check) — a
// `free_shipping` discount (a THIRD type the pricing/tax engines both support — see
// `PricingEngine::price()`/`DiscountAllocation::allocate()`) can exist in the table but is never
// reachable through this admin surface, so it's deliberately excluded from the closed vocabulary
// below.
export const DISCOUNT_TYPES = ['percentage', 'fixed'] as const
export type CommerceDiscountType = (typeof DISCOUNT_TYPES)[number]

// Mirrors `DiscountLifecycleTest`'s own fixtures/filters (`seedDiscount()` defaults to `'active'`,
// `testIndexFiltersByStatus()` filters on `'inactive'`) — the DTO itself accepts any string, but
// these are the only two values the backend ever produces or the admin UI ever needs to set.
export const DISCOUNT_STATUSES = ['active', 'inactive'] as const
export type CommerceDiscountStatus = (typeof DISCOUNT_STATUSES)[number]

/**
 * A `commerce_discounts` row (`005_CreateCommerceDiscountTables.php`), as returned by
 * `DiscountRepository::findByUuid()`/`paginatedFor()`. `tenant_uuid` and the folded `revision`
 * column (an internal optimistic-concurrency counter `DiscountService` claims on PATCH/DELETE —
 * see its class docblock) are deliberately excluded here, same principle as every other
 * projection in this codebase (`CommerceOrder`'s own docblock states it explicitly).
 *
 * `value`'s meaning is `type`-dependent (`PricingEngine::price()`'s own `match`):
 *   - `percentage`: basis points of a percent — `value / 100` is the percent
 *     (e.g. `1000` = 10.00%, `50` = 0.50%). `intdiv($base * value + 5000, 10000)` is the
 *     half-up-rounded discount amount off `$base`.
 *   - `fixed`: a genuine minor-unit currency amount (same unit as `unit_price`/order totals
 *     elsewhere in this codebase) — format with `useMoney`, never `Number()`.
 * Never coerced through `Number()` on the way in either way — an unexpected shape becomes the
 * neutral `0` fallback, exactly like every other amount normalizer in this codebase.
 */
export interface CommerceDiscount {
  uuid: string
  code: string
  type: string
  value: number
  /** A minor-unit currency amount (minimum cart subtotal) — format with `useMoney`, never `Number()`. */
  min_subtotal: number | null
  usage_limit: number | null
  once_per_buyer: boolean
  usage_count: number
  status: string
  starts_at: string | null
  ends_at: string | null
  /** Product-uuid scope restricting which lines the discount allocates against
   * (`DiscountAllocation::allocate()`); `null` means every line is eligible. Not part of the
   * `CreateDiscountData`/`UpdateDiscountData` DTOs (no admin endpoint sets it), so it's read-only
   * here — kept for completeness, no UI in this task. */
  product_scope: string[] | null
  created_at: string | null
  updated_at: string | null
}

/** The exact `CreateDiscountData` request body shape (`Http/DTOs/CreateDiscountData.php`). */
export interface CreateDiscountInput {
  code: string
  type: CommerceDiscountType
  value: number
  min_subtotal?: number | null
  usage_limit?: number | null
  once_per_buyer?: boolean
  status?: CommerceDiscountStatus | string
  starts_at?: string | null
  ends_at?: string | null
}

/** The exact `UpdateDiscountData` request body shape (`Http/DTOs/UpdateDiscountData.php`) — the
 * controller reads the raw JSON body directly (`ReadsAdminInput::input()`) and validates it
 * `partial: true`, so ONLY the keys present in the submitted object are changed; an omitted key
 * leaves its current value unchanged server-side (mirrors `UpdateCategoryInput`'s identical note). */
export interface UpdateDiscountInput {
  code?: string | null
  type?: CommerceDiscountType | null
  value?: number | null
  min_subtotal?: number | null
  usage_limit?: number | null
  once_per_buyer?: boolean | null
  status?: CommerceDiscountStatus | string | null
  starts_at?: string | null
  ends_at?: string | null
}

export interface DiscountListFilters {
  status?: string
  q?: string
  page?: number
  perPage?: number
}

export interface DiscountListPage {
  discounts: CommerceDiscount[]
  total: number
  current_page: number
  per_page: number
}

// The admin envelopes are doc-only in the OpenAPI schema (see commerceOrders.ts's identical
// note), so normalize the raw JSON into the stricter hand-written shape above at the boundary.
function normalizeDiscount(raw: Record<string, unknown>): CommerceDiscount {
  const scope = raw.product_scope
  return {
    uuid: String(raw.uuid ?? ''),
    code: String(raw.code ?? ''),
    type: String(raw.type ?? 'percentage'),
    // Amounts are JSON numbers from the API; anything else is malformed and becomes the neutral
    // fallback rather than a silently Number()-coerced guess (mirrors commerceOrders.ts's
    // identical amount-handling note) — money display goes through useMoney, which rejects unsafe
    // values, so this boundary stays equally strict.
    value: typeof raw.value === 'number' ? raw.value : 0,
    min_subtotal: typeof raw.min_subtotal === 'number' ? raw.min_subtotal : null,
    usage_limit: typeof raw.usage_limit === 'number' ? raw.usage_limit : null,
    once_per_buyer: raw.once_per_buyer === true,
    usage_count: typeof raw.usage_count === 'number' ? raw.usage_count : 0,
    status: String(raw.status ?? 'active'),
    starts_at: typeof raw.starts_at === 'string' ? raw.starts_at : null,
    ends_at: typeof raw.ends_at === 'string' ? raw.ends_at : null,
    product_scope: Array.isArray(scope) ? scope.map((v) => String(v)) : null,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

// ── Fetchers ─────────────────────────────────────────────────────────────────

/** `GET /commerce/discounts` — `DiscountListQuery`'s exact param set is `{status, q, page, per_page}`. */
export async function fetchDiscounts(filters: DiscountListFilters = {}): Promise<DiscountListPage> {
  const { data, error, response } = await client.GET('/commerce/discounts', {
    params: {
      query: {
        status: filters.status || undefined,
        q: filters.q || undefined,
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
    discounts: rows.map((d) => normalizeDiscount(d as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

/** `GET /commerce/discounts/{uuid}` (`AdminDiscountController::show()`). No page in this task
 * consumes this directly (editing goes through the row already held by the list — single-page
 * domain, no detail route), but it's wired up for parity with the endpoint contract, same as
 * `fetchRefund()`/`fetchRefunds()` in commerceOrders.ts. */
export async function fetchDiscount(uuid: string): Promise<CommerceDiscount> {
  const { data, error, response } = await client.GET('/commerce/discounts/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Discount not found.', response?.status ?? 404, {}, data)
  return normalizeDiscount(raw as Record<string, unknown>)
}

export async function createDiscount(input: CreateDiscountInput): Promise<CommerceDiscount> {
  const { data, error, response } = await client.POST('/commerce/discounts', {
    body: {
      code: input.code,
      type: input.type,
      value: input.value,
      min_subtotal: input.min_subtotal ?? null,
      usage_limit: input.usage_limit ?? null,
      once_per_buyer: input.once_per_buyer ?? false,
      status: input.status ?? 'active',
      starts_at: input.starts_at ?? null,
      ends_at: input.ends_at ?? null,
    } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDiscount((raw ?? {}) as Record<string, unknown>)
}

export async function updateDiscount(
  uuid: string,
  input: UpdateDiscountInput,
): Promise<CommerceDiscount> {
  const { data, error, response } = await client.PATCH('/commerce/discounts/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDiscount((raw ?? {}) as Record<string, unknown>)
}

/** `DELETE /commerce/discounts/{uuid}` (`AdminDiscountController::destroy()`) — 204 on success,
 * 409 (`DiscountRedeemedException`, "…Disable it via status instead.") when the discount has at
 * least one redemption; that message surfaces verbatim via `toApiError`, never replaced. */
export async function deleteDiscount(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/discounts/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

// ── Query/mutation wrappers ──────────────────────────────────────────────────

export function useCommerceDiscounts(filters: MaybeRefOrGetter<DiscountListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [...qk.commerceDiscounts(), f.status ?? '', f.q ?? '', f.page ?? 1, f.perPage ?? 24]
    },
    query: () => fetchDiscounts(toValue(filters)),
  })
}

export function useCommerceDiscount(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceDiscount(toValue(uuid)),
    query: () => fetchDiscount(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

/**
 * `create` invalidates ONLY the list — a brand-new discount has no existing
 * `qk.commerceDiscount(uuid)` consumer yet (there's nothing stale to refresh). `update`/`remove`
 * invalidate BOTH the single discount key and the list — its row may now be stale (code, value,
 * status, window, usage). Mirrors `useCommerceProductMutations()`'s create/update/remove
 * reasoning exactly.
 */
export function useCommerceDiscountMutations() {
  const cache = useQueryCache()
  const invalidateList = () => cache.invalidateQueries({ key: qk.commerceDiscounts() })
  const invalidateDiscount = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceDiscount(uuid) })
    invalidateList()
  }

  return {
    create: useMutation({
      mutation: (input: CreateDiscountInput) => createDiscount(input),
      onSettled: invalidateList,
    }),
    update: useMutation({
      mutation: (vars: { uuid: string; input: UpdateDiscountInput }) =>
        updateDiscount(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateDiscount(vars.uuid),
    }),
    remove: useMutation({
      mutation: (uuid: string) => deleteDiscount(uuid),
      onSettled: (_d, _e, uuid) => invalidateDiscount(uuid),
    }),
  }
}
