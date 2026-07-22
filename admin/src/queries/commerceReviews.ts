import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError, ApiError } from '@/api/errors'
import { qk } from './keys'

// Task 16 (admin-commerce-area plan, slice 3): review moderation (`AdminReviewController` /
// `Glueful\Extensions\Commerce\Http\Admin\AdminReviewController`). Status vocabulary is closed —
// `pending` (initial; admin/importer create never touches the product rollup) -> `approved` (adds
// the review's rating to `commerce_products.rating_sum`/`rating_count`) or `spam` (no rollup effect
// from `pending`; REVERSES the rollup when coming from `approved`) — see `ReviewService`'s own
// class docblock for the full transition matrix and the affected-row-checked claim discipline.
export const REVIEW_STATUSES = ['pending', 'approved', 'spam'] as const
export type CommerceReviewStatus = (typeof REVIEW_STATUSES)[number]

// The exact closed vocabulary `BulkReviewData::$action` accepts (`in:approve,spam,delete`).
export const REVIEW_BULK_ACTIONS = ['approve', 'spam', 'delete'] as const
export type CommerceReviewBulkAction = (typeof REVIEW_BULK_ACTIONS)[number]

/**
 * A `commerce_reviews` row (`007_CreateCommerceCatalogBreadthTables.php`), as returned by
 * `ReviewRepository::findByUuid()`/`paginatedFor()`. `tenant_uuid` is deliberately excluded here,
 * same principle as every other projection in this codebase (commerceDiscounts.ts's
 * `CommerceDiscount` docblock states it explicitly).
 */
export interface CommerceReview {
  uuid: string
  product_uuid: string
  user_uuid: string | null
  author_name: string
  author_email: string
  rating: number
  body: string
  status: string
  created_at: string | null
  updated_at: string | null
}

/**
 * `POST /commerce/reviews` body (`CreateReviewData`) — admin/importer create; always lands
 * `pending` and never touches the rollup (`ReviewService::create()`). No create UI ships in this
 * task (moderation-focused v1 — see task-16-report.md for the rationale); wired up here for parity
 * with the endpoint contract, same as `fetchDiscount()`'s own note in commerceDiscounts.ts.
 */
export interface CreateReviewInput {
  product_uuid: string
  rating: number
  body: string
  author_name: string
  author_email: string
  user_uuid?: string | null
}

export interface ReviewListFilters {
  status?: string
  product?: string
  page?: number
  perPage?: number
}

export interface ReviewListPage {
  reviews: CommerceReview[]
  total: number
  current_page: number
  per_page: number
}

/** `POST /commerce/reviews/bulk` response (`ReviewService::bulk()`'s return shape) — the SAME
 * `{applied, failed}` shape as `BulkStatusResult` in commerceCatalog.ts. */
export interface BulkReviewResult {
  applied: string[]
  failed: Array<{ uuid: string; reason: string }>
}

// The admin envelopes are doc-only in the OpenAPI schema (see commerceDiscounts.ts's identical
// note), so normalize the raw JSON into the stricter hand-written shape above at the boundary.
function normalizeReview(raw: Record<string, unknown>): CommerceReview {
  return {
    uuid: String(raw.uuid ?? ''),
    product_uuid: String(raw.product_uuid ?? ''),
    user_uuid: typeof raw.user_uuid === 'string' ? raw.user_uuid : null,
    author_name: String(raw.author_name ?? ''),
    author_email: String(raw.author_email ?? ''),
    // Ratings are JSON numbers from the API; anything else is malformed and becomes the neutral
    // fallback rather than a silently Number()-coerced guess (mirrors commerceDiscounts.ts's
    // identical amount-handling note).
    rating: typeof raw.rating === 'number' ? raw.rating : 0,
    body: String(raw.body ?? ''),
    status: String(raw.status ?? 'pending'),
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

// ── Fetchers ─────────────────────────────────────────────────────────────────

/** `GET /commerce/reviews` — `ReviewListQuery`'s exact param set is `{status, product, page, per_page}`. */
export async function fetchReviews(filters: ReviewListFilters = {}): Promise<ReviewListPage> {
  const { data, error, response } = await client.GET('/commerce/reviews', {
    params: {
      query: {
        status: filters.status || undefined,
        product: filters.product || undefined,
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
    reviews: rows.map((r) => normalizeReview(r as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

/** `GET /commerce/reviews/{uuid}` (`AdminReviewController::show()`). No page in this task consumes
 * this directly (moderation acts on the row already held by the list — single-page domain, no
 * detail route), but it's wired up for parity with the endpoint contract, same as
 * `fetchDiscount()`'s identical note. */
export async function fetchReview(uuid: string): Promise<CommerceReview> {
  const { data, error, response } = await client.GET('/commerce/reviews/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Review not found.', response?.status ?? 404, {}, data)
  return normalizeReview(raw as Record<string, unknown>)
}

export async function createReview(input: CreateReviewInput): Promise<CommerceReview> {
  const { data, error, response } = await client.POST('/commerce/reviews', {
    body: {
      product_uuid: input.product_uuid,
      rating: input.rating,
      body: input.body,
      author_name: input.author_name,
      author_email: input.author_email,
      user_uuid: input.user_uuid ?? null,
    } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeReview((raw ?? {}) as Record<string, unknown>)
}

/** `POST /commerce/reviews/{uuid}/approve` — `pending -> approved` (409 `ReviewStateException` if
 * the review isn't currently pending). */
export async function approveReview(uuid: string): Promise<CommerceReview> {
  const { data, error, response } = await client.POST('/commerce/reviews/{uuid}/approve', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeReview((raw ?? {}) as Record<string, unknown>)
}

/** `POST /commerce/reviews/{uuid}/spam` — `pending -> spam` (no rollup effect) or `approved -> spam`
 * (reverses the rollup); 409 if the review is already spam. */
export async function spamReview(uuid: string): Promise<CommerceReview> {
  const { data, error, response } = await client.POST('/commerce/reviews/{uuid}/spam', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeReview((raw ?? {}) as Record<string, unknown>)
}

/** `DELETE /commerce/reviews/{uuid}` — 204 on success; 404 (non-revealing) for unknown/cross-tenant
 * OR a currently-`approved` review — the guarded delete only ever allows `pending`/`spam`
 * (`ReviewService::delete()`'s own docblock: an approved review must be spammed first so its
 * rollup contribution is reversed before the row can disappear). */
export async function deleteReview(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/reviews/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

/** `POST /commerce/reviews/bulk` — the exact `BulkReviewData` body shape (`action` + `uuids`). */
export async function bulkModerateReviews(
  action: CommerceReviewBulkAction,
  uuids: string[],
): Promise<BulkReviewResult> {
  const { data, error, response } = await client.POST('/commerce/reviews/bulk', {
    body: { action, uuids } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (
    data as
      | { data?: { applied?: string[]; failed?: Array<{ uuid: string; reason: string }> } }
      | undefined
  )?.data
  return { applied: raw?.applied ?? [], failed: raw?.failed ?? [] }
}

// ── Query/mutation wrappers ──────────────────────────────────────────────────

export function useCommerceReviews(filters: MaybeRefOrGetter<ReviewListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [...qk.commerceReviews(), f.status ?? '', f.product ?? '', f.page ?? 1, f.perPage ?? 24]
    },
    query: () => fetchReviews(toValue(filters)),
  })
}

export function useCommerceReview(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceReview(toValue(uuid)),
    query: () => fetchReview(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

/**
 * `create` invalidates ONLY the list — a brand-new review has no existing
 * `qk.commerceReview(uuid)` consumer yet (mirrors `useCommerceDiscountMutations()`'s `create`).
 * `approve`/`spam`/`remove` invalidate BOTH the single review key and the list — each changes
 * `status`, a field the list itself renders (mirrors `update`/`remove` in commerceDiscounts.ts).
 * `bulk` invalidates ONLY the list, never N individual detail keys — mirrors
 * `useCommerceProductMutations()`'s `bulkStatus` (a many-uuid mutation invalidates the aggregate
 * view, not one key per acted-on uuid).
 */
export function useCommerceReviewMutations() {
  const cache = useQueryCache()
  const invalidateList = () => cache.invalidateQueries({ key: qk.commerceReviews() })
  const invalidateReview = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceReview(uuid) })
    invalidateList()
  }

  return {
    create: useMutation({
      mutation: (input: CreateReviewInput) => createReview(input),
      onSettled: invalidateList,
    }),
    approve: useMutation({
      mutation: (uuid: string) => approveReview(uuid),
      onSettled: (_d, _e, uuid) => invalidateReview(uuid),
    }),
    spam: useMutation({
      mutation: (uuid: string) => spamReview(uuid),
      onSettled: (_d, _e, uuid) => invalidateReview(uuid),
    }),
    remove: useMutation({
      mutation: (uuid: string) => deleteReview(uuid),
      onSettled: (_d, _e, uuid) => invalidateReview(uuid),
    }),
    bulk: useMutation({
      mutation: (vars: { action: CommerceReviewBulkAction; uuids: string[] }) =>
        bulkModerateReviews(vars.action, vars.uuids),
      onSettled: invalidateList,
    }),
  }
}
