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

/** `PATCH /commerce/variants/{uuid}` body (UpdateVariantData — see its own docblock: the
 * controller reads the raw body, so every field is optional and an explicit `null` on
 * `shipping_class_uuid` clears the assignment while an omitted key preserves it). */
export interface UpdateVariantInput {
  sku?: string
  price?: number
  currency?: string
  option_values?: unknown[]
  compare_at_price?: number | null
  status?: string | null
  shipping_class_uuid?: string | null
}

/** One `{uuid, price}` element of `POST /commerce/variants/bulk-price`'s `items` array
 * (BulkPriceItemData) — `price` is minor units, never a decimal amount. */
export interface BulkPriceItem {
  uuid: string
  price: number
}

/** `POST /commerce/stock/{variantUuid}/adjust` body (StockAdjustmentData). `reason` defaults to
 * `'adjustment'` server-side when omitted, but callers here always send it explicitly so the
 * request body stays self-describing. */
export interface StockAdjustInput {
  delta: number
  reason?: string
  reference_uuid?: string | null
}

/** `{ variant_uuid, quantity }` — the resulting on-hand quantity AFTER the adjustment. There is no
 * admin GET for a variant's current stock (only this adjust endpoint), so this response is the
 * only source of truth the SPA has for a variant's quantity. */
export interface StockAdjustResult {
  variant_uuid: string
  quantity: number
}

// The admin envelopes are doc-only in the OpenAPI schema (see collections.ts's identical note), so
// normalize the raw JSON into the stricter hand-written shapes above at the boundary.
function normalizeVariant(raw: Record<string, unknown>): CommerceVariant {
  return {
    uuid: String(raw.uuid ?? ''),
    sku: String(raw.sku ?? ''),
    // Amounts are JSON numbers from the API; anything else is malformed and becomes the
    // neutral fallback rather than a silently Number()-coerced guess (money display goes
    // through useMoney, which rejects unsafe values — keep this boundary equally strict).
    price: typeof raw.price === 'number' ? raw.price : 0,
    compare_at_price: typeof raw.compare_at_price === 'number' ? raw.compare_at_price : null,
    currency: String(raw.currency ?? ''),
    status: String(raw.status ?? 'active'),
    position: typeof raw.position === 'number' ? raw.position : 0,
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

export async function createProductVariant(
  productUuid: string,
  input: ProductVariantInput,
): Promise<CommerceVariant> {
  const { data, error, response } = await client.POST('/commerce/products/{uuid}/variants', {
    params: { path: { uuid: productUuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeVariant((raw ?? {}) as Record<string, unknown>)
}

export async function updateProductVariant(
  variantUuid: string,
  input: UpdateVariantInput,
): Promise<CommerceVariant> {
  const { data, error, response } = await client.PATCH('/commerce/variants/{uuid}', {
    params: { path: { uuid: variantUuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeVariant((raw ?? {}) as Record<string, unknown>)
}

export async function bulkUpdateVariantPrices(items: BulkPriceItem[]): Promise<BulkStatusResult> {
  const { data, error, response } = await client.POST('/commerce/variants/bulk-price', {
    body: { items } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (
    data as
      | { data?: { applied?: string[]; failed?: Array<{ uuid: string; reason: string }> } }
      | undefined
  )?.data
  return { applied: raw?.applied ?? [], failed: raw?.failed ?? [] }
}

/** `PUT /commerce/products/{uuid}/children` — a wholesale replace (SetProductChildrenData): the
 * SUBMITTED list becomes the product's entire child set, and the response is the fresh list of
 * child products (ordered). There is no admin GET for the current children, so this response —
 * and any prior successful call's response — is the only source of truth the SPA has. */
export async function setProductChildren(
  productUuid: string,
  childUuids: string[],
): Promise<CommerceProduct[]> {
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/children', {
    params: { path: { uuid: productUuid } },
    body: { child_uuids: childUuids } as never,
  })
  if (error) throw toApiError(error, response)
  const rows = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(rows) ? rows.map((p) => normalizeProduct(p as Record<string, unknown>)) : []
}

export async function adjustVariantStock(
  variantUuid: string,
  input: StockAdjustInput,
): Promise<StockAdjustResult> {
  const { data, error, response } = await client.POST('/commerce/stock/{variantUuid}/adjust', {
    params: { path: { variantUuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: { variant_uuid?: string; quantity?: number } } | undefined)?.data
  return {
    variant_uuid: raw?.variant_uuid ?? variantUuid,
    quantity: typeof raw?.quantity === 'number' ? raw.quantity : 0,
  }
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
 * the single product and the list (its row may now be stale — status, name, etc.).
 *
 * The variant/children/stock mutations below (Task 10b) invalidate ONLY `qk.commerceProduct(uuid)`,
 * never the list: none of the fields ProductsTable renders (name, slug, type, status, updated_at —
 * see ProductsTable.vue's `columns`) come from a variant, the children set, or stock, so a list
 * invalidation would just be a wasted refetch. Every one of them requires the caller to pass the
 * owning `productUuid` explicitly (mirroring `update`/`remove`'s `uuid`) rather than reading it off
 * the mutation's response, so the invalidation still runs correctly even when the call fails.
 */
export function useCommerceProductMutations() {
  const cache = useQueryCache()
  const invalidateList = () => cache.invalidateQueries({ key: qk.commerceProducts() })
  const invalidateProduct = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceProduct(uuid) })
    invalidateList()
  }
  const invalidateProductOnly = (uuid: string) => cache.invalidateQueries({ key: qk.commerceProduct(uuid) })

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
    createVariant: useMutation({
      mutation: (vars: { productUuid: string; input: ProductVariantInput }) =>
        createProductVariant(vars.productUuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    updateVariant: useMutation({
      mutation: (vars: { uuid: string; productUuid: string; input: UpdateVariantInput }) =>
        updateProductVariant(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    bulkPrice: useMutation({
      mutation: (vars: { productUuid: string; items: BulkPriceItem[] }) =>
        bulkUpdateVariantPrices(vars.items),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    setChildren: useMutation({
      mutation: (vars: { productUuid: string; childUuids: string[] }) =>
        setProductChildren(vars.productUuid, vars.childUuids),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    stockAdjust: useMutation({
      mutation: (vars: { variantUuid: string; productUuid: string; input: StockAdjustInput }) =>
        adjustVariantStock(vars.variantUuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
  }
}
