import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'
import { qk } from './keys'

// Closed vocabularies mirrored from the backend (Glueful\Extensions\Commerce\Catalog\ProductStatus /
// ProductType) — kept here as the single frontend declaration for filters, selects and badges.
export const PRODUCT_STATUSES = ['draft', 'active', 'archived'] as const
export type CommerceProductStatus = (typeof PRODUCT_STATUSES)[number]

export const PRODUCT_TYPES = ['physical', 'digital', 'external', 'grouped'] as const
export type CommerceProductType = (typeof PRODUCT_TYPES)[number]

/** A `commerce_variants` row (design spec, Layer 6 §2) as returned by the admin product endpoints. */
export interface CommerceVariant {
  uuid: string
  sku: string
  /** Minor-unit integer amount (e.g. cents) — format with `useMoney`, never `Number()`. */
  price: number
  compare_at_price: number | null
  currency: string
  status: string
  position: number
}

/** A `commerce_products` row, with `variants` attached whenever the endpoint includes them
 * (product show/create/update do; the paginated list does not). */
export interface CommerceProduct {
  uuid: string
  slug: string
  name: string
  description: string | null
  type: string
  status: string
  tax_class: string | null
  created_at: string | null
  updated_at: string | null
  variants: CommerceVariant[]
}

export interface ProductListFilters {
  status?: string
  type?: string
  q?: string
  page?: number
  perPage?: number
}

export interface ProductListPage {
  products: CommerceProduct[]
  total: number
  current_page: number
  per_page: number
}

export interface ProductVariantInput {
  sku: string
  price: number
  currency: string
  option_values?: unknown[]
  compare_at_price?: number | null
  status?: string | null
  shipping_class_uuid?: string | null
}

/** `POST /commerce/products` body (CreateProductData). `seller_uuid` is never sent here —
 * it's rejected by the backend on the ordinary admin create endpoint. */
export interface CreateProductInput {
  slug: string
  name: string
  description?: string | null
  type?: string
  status?: string
  tax_class?: string | null
  variants: ProductVariantInput[]
}

/** `PATCH /commerce/products/{uuid}` body (UpdateProductData). */
export interface UpdateProductInput {
  slug?: string | null
  name?: string | null
  description?: string | null
  type?: string | null
  status?: string | null
  tax_class?: string | null
}

export interface BulkStatusResult {
  applied: string[]
  failed: Array<{ uuid: string; reason: string }>
}

// The admin envelopes are doc-only in the OpenAPI schema (see collections.ts's identical note), so
// normalize the raw JSON into the stricter hand-written shapes above at the boundary.
function normalizeVariant(raw: Record<string, unknown>): CommerceVariant {
  return {
    uuid: String(raw.uuid ?? ''),
    sku: String(raw.sku ?? ''),
    price: typeof raw.price === 'number' ? raw.price : Number(raw.price ?? 0),
    compare_at_price:
      raw.compare_at_price === null || raw.compare_at_price === undefined
        ? null
        : Number(raw.compare_at_price),
    currency: String(raw.currency ?? ''),
    status: String(raw.status ?? 'active'),
    position: typeof raw.position === 'number' ? raw.position : Number(raw.position ?? 0),
  }
}

function normalizeProduct(raw: Record<string, unknown>): CommerceProduct {
  const variants = Array.isArray(raw.variants) ? raw.variants : []
  return {
    uuid: String(raw.uuid ?? ''),
    slug: String(raw.slug ?? ''),
    name: String(raw.name ?? ''),
    description: (raw.description as string | null | undefined) ?? null,
    type: String(raw.type ?? 'physical'),
    status: String(raw.status ?? 'draft'),
    tax_class: (raw.tax_class as string | null | undefined) ?? null,
    created_at: (raw.created_at as string | null | undefined) ?? null,
    updated_at: (raw.updated_at as string | null | undefined) ?? null,
    variants: variants.map((v) => normalizeVariant(v as Record<string, unknown>)),
  }
}

// ── Fetchers ─────────────────────────────────────────────────────────────────

export async function fetchProducts(filters: ProductListFilters = {}): Promise<ProductListPage> {
  const { data, error, response } = await client.GET('/commerce/products', {
    params: {
      query: {
        status: filters.status || undefined,
        type: filters.type || undefined,
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
    products: rows.map((p) => normalizeProduct(p as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

export async function fetchProduct(uuid: string): Promise<CommerceProduct> {
  const { data, error, response } = await client.GET('/commerce/products/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Product not found.', response?.status ?? 404, {}, data)
  return normalizeProduct(raw as Record<string, unknown>)
}

export async function createProduct(input: CreateProductInput): Promise<CommerceProduct> {
  const { data, error, response } = await client.POST('/commerce/products', {
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeProduct((raw ?? {}) as Record<string, unknown>)
}

export async function updateProduct(
  uuid: string,
  input: UpdateProductInput,
): Promise<CommerceProduct> {
  const { data, error, response } = await client.PATCH('/commerce/products/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeProduct((raw ?? {}) as Record<string, unknown>)
}

export async function deleteProduct(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/products/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

export async function bulkStatusUpdate(uuids: string[], status: string): Promise<BulkStatusResult> {
  const { data, error, response } = await client.POST('/commerce/products/bulk-status', {
    body: { uuids, status } as never,
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

export function useCommerceProducts(filters: MaybeRefOrGetter<ProductListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [
        ...qk.commerceProducts(),
        f.status ?? '',
        f.type ?? '',
        f.q ?? '',
        f.page ?? 1,
        f.perPage ?? 24,
      ]
    },
    query: () => fetchProducts(toValue(filters)),
  })
}

export function useCommerceProduct(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceProduct(toValue(uuid)),
    query: () => fetchProduct(toValue(uuid)),
  })
}

/** Product mutations. `create`/`bulkStatus` invalidate the list; `update`/`remove` invalidate both
 * the single product and the list (its row may now be stale — status, name, etc.). */
export function useCommerceProductMutations() {
  const cache = useQueryCache()
  const invalidateList = () => cache.invalidateQueries({ key: qk.commerceProducts() })
  const invalidateProduct = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceProduct(uuid) })
    invalidateList()
  }

  return {
    create: useMutation({
      mutation: (input: CreateProductInput) => createProduct(input),
      onSettled: invalidateList,
    }),
    update: useMutation({
      mutation: (vars: { uuid: string; input: UpdateProductInput }) =>
        updateProduct(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProduct(vars.uuid),
    }),
    remove: useMutation({
      mutation: (uuid: string) => deleteProduct(uuid),
      onSettled: (_d, _e, uuid) => invalidateProduct(uuid),
    }),
    bulkStatus: useMutation({
      mutation: (vars: { uuids: string[]; status: string }) =>
        bulkStatusUpdate(vars.uuids, vars.status),
      onSettled: invalidateList,
    }),
  }
}
