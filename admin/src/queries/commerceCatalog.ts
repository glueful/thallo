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

export const MEDIA_ROLES = ['cover', 'gallery'] as const
export type CommerceMediaRole = (typeof MEDIA_ROLES)[number]

/** A `commerce_product_media` row (AttachMediaData/UpdateMediaData, design spec Layer 6 §2). There
 * is no admin GET for a product's media list — attach returns only the single created row, and
 * reorder is the one endpoint that ever returns the full set — so the SPA tracks known rows itself
 * from mutation responses, exactly like `setProductChildren`'s children list. */
export interface CommerceProductMedia {
  uuid: string
  product_uuid: string
  variant_uuid: string | null
  blob_uuid: string
  role: string
  position: number
  alt: string | null
}

/** `POST /commerce/products/{uuid}/media` body (AttachMediaData). */
export interface AttachMediaInput {
  blob_uuid: string
  role?: string
  alt?: string | null
  variant_uuid?: string | null
}

/** `PATCH /commerce/media/{uuid}` body (UpdateMediaData) — only present keys are applied. */
export interface UpdateProductMediaInput {
  role?: string | null
  alt?: string | null
  position?: number | null
}

/** A `commerce_categories` row (design spec Layer 6 §2) — CRUD'd independently of products, then
 * attached/detached via `PUT /commerce/products/{uuid}/categories`'s set-list replace. Flat, not a
 * nested tree: `parent_uuid` is the only structural signal the API gives back (`CategoryService::list()`
 * returns every category for the tenant, unpaginated), so the SPA renders a flat list annotated with
 * each row's parent (see CategoriesTab.vue's `parentName()`) rather than building an actual tree
 * client-side. */
export interface CommerceCategory {
  uuid: string
  parent_uuid: string | null
  slug: string
  name: string
  description: string | null
  position: number
}

/** `POST /commerce/categories` body (CreateCategoryData). `blob_uuid` (an optional category image)
 * exists on the DTO but has no UI here — out of scope for this task's category CRUD. */
export interface CreateCategoryInput {
  slug: string
  name: string
  description?: string | null
  parent_uuid?: string | null
  position?: number | null
}

/** `PATCH /commerce/categories/{uuid}` body (UpdateCategoryData) — the controller reads the raw
 * body (see its own docblock), so only present keys are applied; an explicit `parent_uuid: null`
 * moves the category to the root, while an omitted key leaves its current parent unchanged. */
export interface UpdateCategoryInput {
  slug?: string | null
  name?: string | null
  description?: string | null
  parent_uuid?: string | null
  position?: number | null
}

/** A `commerce_tags` row (design spec Layer 6 §2, `007_CreateCommerceCatalogBreadthTables.php`) —
 * FLAT, unlike categories: no `parent_uuid`/`description`/`position`, just `slug`/`name`. CRUD'd
 * independently of products, then attached/detached via `PUT /commerce/products/{uuid}/tags`'s
 * set-list replace, exactly like categories. `GET /commerce/tags` IS paginated though
 * (`TagRepository::paginatedFor()`), unlike `fetchCategories()`'s flat unpaginated list — mirrors
 * `fetchProducts()`'s `Response::paginated` envelope instead. */
export interface CommerceTag {
  uuid: string
  slug: string
  name: string
}

export interface TagListFilters {
  q?: string
  page?: number
  perPage?: number
}

export interface TagListPage {
  tags: CommerceTag[]
  total: number
  current_page: number
  per_page: number
}

/** `POST /commerce/tags` body (CreateTagData). */
export interface CreateTagInput {
  slug: string
  name: string
}

/** `PATCH /commerce/tags/{uuid}` body — rename only. `TagService::rename()`'s own docblock: slug
 * is immutable (referenced by storefront filters) and the key's mere PRESENCE in the request
 * throws a 422 (`array_key_exists('slug', $changes)`), even when its value matches the current
 * slug — so `updateTag()` below must never include it. */
export interface UpdateTagInput {
  name: string
}

/** A `commerce_attribute_values` row (design spec Layer 6 §2) — embedded inside its owning
 * `CommerceAttribute.values`, position-ordered. There is no standalone value GET: the owning
 * attribute's list/show response (embedded) and the direct value create/update mutations
 * (returning this same shape) are the only read paths. */
export interface CommerceAttributeValue {
  uuid: string
  slug: string
  value: string
  position: number
}

/** A `commerce_attributes` row (design spec Layer 6 §2) with its `values` embedded
 * (`AttributeService::list()`/`show()` batch-load them — never a separate values fetch, mirrors
 * `CommerceProduct.variants`). Unlike tags, BOTH `slug` and `name` stay editable after creation
 * (`AttributeService::update()` — no tag-style immutability lock), so `UpdateAttributeInput`
 * below carries no special-case. */
export interface CommerceAttribute {
  uuid: string
  slug: string
  name: string
  position: number
  values: CommerceAttributeValue[]
}

export interface AttributeListFilters {
  q?: string
  page?: number
  perPage?: number
}

export interface AttributeListPage {
  attributes: CommerceAttribute[]
  total: number
  current_page: number
  per_page: number
}

/** `POST /commerce/attributes` body (CreateAttributeData). */
export interface CreateAttributeInput {
  slug: string
  name: string
  position?: number | null
}

/** `PATCH /commerce/attributes/{uuid}` body (UpdateAttributeData) — the controller reads the raw
 * body (see its own docblock), so only present keys are applied; unlike `UpdateTagInput`, `slug`
 * IS safe to send (attribute slugs stay editable — see `CommerceAttribute`'s docblock). */
export interface UpdateAttributeInput {
  slug?: string | null
  name?: string | null
  position?: number | null
}

/** `POST /commerce/attributes/{uuid}/values` body (CreateAttributeValueData). */
export interface CreateAttributeValueInput {
  slug: string
  value: string
  position?: number | null
}

/** `PATCH /commerce/attribute-values/{uuid}` body (UpdateAttributeValueData) — only present keys
 * are applied server-side; this form always submits every field, which is idempotent (unlike
 * tags' slug-presence trap). */
export interface UpdateAttributeValueInput {
  slug?: string | null
  value?: string | null
  position?: number | null
}

/** One element of `PUT /commerce/products/{uuid}/attributes`'s `attributes` array
 * (`SetProductAttributesData` — shape/business validation both happen in
 * `AttributeService::setProductAttributes()`, nested-DTO support for arbitrary request arrays
 * being pending, same substitute documented on `ReorderMediaData`). Exactly ONE of
 * `attribute_uuid` (references a tenant attribute; `values` must be that attribute's existing
 * value SLUGS) or a non-empty `name` (a one-off custom row; `values` is free text) is given —
 * never both, never neither. */
export interface ProductAttributeAssignmentInput {
  attribute_uuid?: string | null
  name?: string | null
  values?: string[]
  used_for_variants?: boolean
  visible?: boolean
  position?: number | null
}

/** A `commerce_product_attributes` row as returned by `setProductAttributes` — the ONLY read path
 * for a product's attribute assignment (there is no admin GET), exactly like
 * `CommerceProductMedia`'s docblock. `attribute_slug`/`attribute_name` are joined in for
 * attribute-linked rows (`AttributeService::productAttributesPayload()`); both are `null` on a
 * custom row. */
export interface CommerceProductAttribute {
  uuid: string
  product_uuid: string
  attribute_uuid: string | null
  attribute_slug: string | null
  attribute_name: string | null
  name: string | null
  values: string[]
  used_for_variants: boolean
  visible: boolean
  position: number
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

function normalizeMedia(raw: Record<string, unknown>): CommerceProductMedia {
  return {
    uuid: String(raw.uuid ?? ''),
    product_uuid: String(raw.product_uuid ?? ''),
    variant_uuid: typeof raw.variant_uuid === 'string' ? raw.variant_uuid : null,
    blob_uuid: String(raw.blob_uuid ?? ''),
    role: String(raw.role ?? 'gallery'),
    position: typeof raw.position === 'number' ? raw.position : 0,
    alt: typeof raw.alt === 'string' ? raw.alt : null,
  }
}

function normalizeCategory(raw: Record<string, unknown>): CommerceCategory {
  return {
    uuid: String(raw.uuid ?? ''),
    parent_uuid: typeof raw.parent_uuid === 'string' ? raw.parent_uuid : null,
    slug: String(raw.slug ?? ''),
    name: String(raw.name ?? ''),
    description: typeof raw.description === 'string' ? raw.description : null,
    position: typeof raw.position === 'number' ? raw.position : 0,
  }
}

function normalizeTag(raw: Record<string, unknown>): CommerceTag {
  return {
    uuid: String(raw.uuid ?? ''),
    slug: String(raw.slug ?? ''),
    name: String(raw.name ?? ''),
  }
}

function normalizeAttributeValue(raw: Record<string, unknown>): CommerceAttributeValue {
  return {
    uuid: String(raw.uuid ?? ''),
    slug: String(raw.slug ?? ''),
    value: String(raw.value ?? ''),
    position: typeof raw.position === 'number' ? raw.position : 0,
  }
}

function normalizeAttribute(raw: Record<string, unknown>): CommerceAttribute {
  const values = Array.isArray(raw.values) ? raw.values : []
  return {
    uuid: String(raw.uuid ?? ''),
    slug: String(raw.slug ?? ''),
    name: String(raw.name ?? ''),
    position: typeof raw.position === 'number' ? raw.position : 0,
    values: values.map((v) => normalizeAttributeValue(v as Record<string, unknown>)),
  }
}

function normalizeProductAttribute(raw: Record<string, unknown>): CommerceProductAttribute {
  const values = Array.isArray(raw.values) ? raw.values : []
  return {
    uuid: String(raw.uuid ?? ''),
    product_uuid: String(raw.product_uuid ?? ''),
    attribute_uuid: typeof raw.attribute_uuid === 'string' ? raw.attribute_uuid : null,
    attribute_slug: typeof raw.attribute_slug === 'string' ? raw.attribute_slug : null,
    attribute_name: typeof raw.attribute_name === 'string' ? raw.attribute_name : null,
    name: typeof raw.name === 'string' ? raw.name : null,
    values: values.map((v) => String(v)),
    used_for_variants: Boolean(raw.used_for_variants),
    visible: raw.visible === undefined || raw.visible === null ? true : Boolean(raw.visible),
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

export async function attachProductMedia(
  productUuid: string,
  input: AttachMediaInput,
): Promise<CommerceProductMedia> {
  const { data, error, response } = await client.POST('/commerce/products/{uuid}/media', {
    params: { path: { uuid: productUuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeMedia((raw ?? {}) as Record<string, unknown>)
}

export async function updateProductMedia(
  mediaUuid: string,
  input: UpdateProductMediaInput,
): Promise<CommerceProductMedia> {
  const { data, error, response } = await client.PATCH('/commerce/media/{uuid}', {
    params: { path: { uuid: mediaUuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeMedia((raw ?? {}) as Record<string, unknown>)
}

export async function detachProductMedia(mediaUuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/media/{uuid}', {
    params: { path: { uuid: mediaUuid } },
  })
  if (error) throw toApiError(error, response)
}

/** `PUT /commerce/products/{uuid}/media/order` (ReorderMediaData) — the backend wants
 * `positions: [{uuid, position}]`, not a bare uuid array, so this derives that shape from a plain
 * ordered uuid list (position = its array index). Callers must pass EVERY visible media uuid: the
 * service only repositions entries present in the list, so a partial list would silently leave the
 * omitted rows' positions unchanged. Returns the fresh ordered list — the only source of truth for
 * a product's media the SPA ever gets back (mirrors `setProductChildren`'s note). */
export async function reorderProductMedia(
  productUuid: string,
  orderedUuids: string[],
): Promise<CommerceProductMedia[]> {
  const positions = orderedUuids.map((uuid, index) => ({ uuid, position: index }))
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/media/order', {
    params: { path: { uuid: productUuid } },
    body: { positions } as never,
  })
  if (error) throw toApiError(error, response)
  const rows = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(rows) ? rows.map((m) => normalizeMedia(m as Record<string, unknown>)) : []
}

/** `GET /commerce/categories` — a flat, unpaginated list of every category for the tenant
 * (`CategoryService::list()` returns `CategoryRepository::all()` directly, not a paginated
 * result), ordered by position then name. */
export async function fetchCategories(): Promise<CommerceCategory[]> {
  const { data, error, response } = await client.GET('/commerce/categories')
  if (error) throw toApiError(error, response)
  const rows = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(rows) ? rows.map((c) => normalizeCategory(c as Record<string, unknown>)) : []
}

export async function createCategory(input: CreateCategoryInput): Promise<CommerceCategory> {
  const { data, error, response } = await client.POST('/commerce/categories', {
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeCategory((raw ?? {}) as Record<string, unknown>)
}

export async function updateCategory(
  uuid: string,
  input: UpdateCategoryInput,
): Promise<CommerceCategory> {
  const { data, error, response } = await client.PATCH('/commerce/categories/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeCategory((raw ?? {}) as Record<string, unknown>)
}

export async function deleteCategory(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/categories/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

/** `PUT /commerce/products/{uuid}/categories` (SetProductCategoriesData) — a wholesale set-list
 * replace, exactly like `setProductChildren`: the SUBMITTED list becomes the product's entire
 * category assignment, and the response is the fresh list of attached category rows. There is no
 * admin GET for a product's current categories (`/commerce/products/{uuid}/categories` declares no
 * `get` in schema.d.ts), so this response is the only source of truth the SPA ever has. */
export async function setProductCategories(
  productUuid: string,
  categoryUuids: string[],
): Promise<CommerceCategory[]> {
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/categories', {
    params: { path: { uuid: productUuid } },
    body: { category_uuids: categoryUuids } as never,
  })
  if (error) throw toApiError(error, response)
  const rows = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(rows) ? rows.map((c) => normalizeCategory(c as Record<string, unknown>)) : []
}

/** `GET /commerce/tags` — paginated (unlike `fetchCategories()`'s flat list): `TagListQuery`'s
 * exact param set is `{q, page, per_page}`. */
export async function fetchTags(filters: TagListFilters = {}): Promise<TagListPage> {
  const { data, error, response } = await client.GET('/commerce/tags', {
    params: {
      query: {
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
    tags: rows.map((t) => normalizeTag(t as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

export async function createTag(input: CreateTagInput): Promise<CommerceTag> {
  const { data, error, response } = await client.POST('/commerce/tags', {
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeTag((raw ?? {}) as Record<string, unknown>)
}

/** Only ever sends `{ name }` — never `slug` (see `UpdateTagInput`'s docblock: the backend
 * rejects the update wholesale if the `slug` key is present at all). */
export async function updateTag(uuid: string, input: UpdateTagInput): Promise<CommerceTag> {
  const { data, error, response } = await client.PATCH('/commerce/tags/{uuid}', {
    params: { path: { uuid } },
    body: { name: input.name } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeTag((raw ?? {}) as Record<string, unknown>)
}

export async function deleteTag(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/tags/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

/** `PUT /commerce/products/{uuid}/tags` (SetProductTagsData) — a wholesale set-list replace,
 * exactly like `setProductCategories()`: the SUBMITTED list becomes the product's entire tag
 * assignment, and the response is the fresh list of attached tag rows. There is no admin GET for
 * a product's current tags, so this response is the only source of truth the SPA ever has. */
export async function setProductTags(productUuid: string, tagUuids: string[]): Promise<CommerceTag[]> {
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/tags', {
    params: { path: { uuid: productUuid } },
    body: { tag_uuids: tagUuids } as never,
  })
  if (error) throw toApiError(error, response)
  const rows = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(rows) ? rows.map((t) => normalizeTag(t as Record<string, unknown>)) : []
}

/** `GET /commerce/attributes` — paginated (`AttributeListQuery`'s exact param set is
 * `{q, page, per_page}`, mirrors `fetchTags()`), each row with its `values` embedded
 * (`AttributeService::list()` batch-loads them — never a separate per-attribute fetch). */
export async function fetchAttributes(filters: AttributeListFilters = {}): Promise<AttributeListPage> {
  const { data, error, response } = await client.GET('/commerce/attributes', {
    params: {
      query: {
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
    attributes: rows.map((a) => normalizeAttribute(a as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

export async function createAttribute(input: CreateAttributeInput): Promise<CommerceAttribute> {
  const { data, error, response } = await client.POST('/commerce/attributes', {
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeAttribute((raw ?? {}) as Record<string, unknown>)
}

export async function updateAttribute(
  uuid: string,
  input: UpdateAttributeInput,
): Promise<CommerceAttribute> {
  const { data, error, response } = await client.PATCH('/commerce/attributes/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeAttribute((raw ?? {}) as Record<string, unknown>)
}

export async function deleteAttribute(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/attributes/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

/** `POST /commerce/attributes/{uuid}/values` — the response is the created value row directly
 * (not wrapped in its owning attribute); the attribute uuid in the path names the OWNER, never a
 * field on the returned/normalized value itself (mirrors `CommerceAttributeValue`'s shape). */
export async function createAttributeValue(
  attributeUuid: string,
  input: CreateAttributeValueInput,
): Promise<CommerceAttributeValue> {
  const { data, error, response } = await client.POST('/commerce/attributes/{uuid}/values', {
    params: { path: { uuid: attributeUuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeAttributeValue((raw ?? {}) as Record<string, unknown>)
}

export async function updateAttributeValue(
  uuid: string,
  input: UpdateAttributeValueInput,
): Promise<CommerceAttributeValue> {
  const { data, error, response } = await client.PATCH('/commerce/attribute-values/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeAttributeValue((raw ?? {}) as Record<string, unknown>)
}

export async function deleteAttributeValue(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/attribute-values/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

/** `PUT /commerce/products/{uuid}/attributes` (`SetProductAttributesData`) — a wholesale set-list
 * replace, exactly like `setProductTags()`/`setProductCategories()`, except each row carries real
 * payload (values/flags/position) rather than being a bare uuid — see
 * `ProductAttributeAssignmentInput`'s docblock. There is no admin GET for a product's current
 * attribute assignment, so the response is the only source of truth the SPA ever has. */
export async function setProductAttributes(
  productUuid: string,
  rows: ProductAttributeAssignmentInput[],
): Promise<CommerceProductAttribute[]> {
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/attributes', {
    params: { path: { uuid: productUuid } },
    body: { attributes: rows } as never,
  })
  if (error) throw toApiError(error, response)
  const result = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(result)
    ? result.map((r) => normalizeProductAttribute(r as Record<string, unknown>))
    : []
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

/** `enabled` defaults to always-on (every existing caller passes a route-derived, always-present
 * uuid); Task 12's linking panel passes a reactive `enabled` so a not-yet-resolved linked-product
 * uuid (entry-mode's by-entry lookup, before it settles) never fires a bogus empty-uuid fetch —
 * this ALSO guards against an empty uuid on its own, regardless of `enabled`. */
export function useCommerceProduct(
  uuid: MaybeRefOrGetter<string>,
  enabled: MaybeRefOrGetter<boolean> = true,
) {
  return useQuery({
    key: () => qk.commerceProduct(toValue(uuid)),
    query: () => fetchProduct(toValue(uuid)),
    enabled: () => toValue(enabled) && !!toValue(uuid),
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
    // Task 10c: media mutations, same invalidation reasoning as variants/children/stock above —
    // every one of these vars carries the owning `productUuid` explicitly.
    attachMedia: useMutation({
      mutation: (vars: { productUuid: string; input: AttachMediaInput }) =>
        attachProductMedia(vars.productUuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    updateMedia: useMutation({
      mutation: (vars: { uuid: string; productUuid: string; input: UpdateProductMediaInput }) =>
        updateProductMedia(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    detachMedia: useMutation({
      mutation: (vars: { uuid: string; productUuid: string }) => detachProductMedia(vars.uuid),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    reorderMedia: useMutation({
      mutation: (vars: { productUuid: string; orderedUuids: string[] }) =>
        reorderProductMedia(vars.productUuid, vars.orderedUuids),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    // Task 10d: product category assignment — invalidates ONLY the owning product, same
    // reasoning as setChildren/media above. Never invalidates commerceCategories(): the shared
    // category list shows no per-category product count, so a product's own assignment
    // changing never makes anything useCommerceCategories() renders stale.
    setCategories: useMutation({
      mutation: (vars: { productUuid: string; categoryUuids: string[] }) =>
        setProductCategories(vars.productUuid, vars.categoryUuids),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    // Task 19a: product tag assignment — invalidates ONLY the owning product, same reasoning as
    // setCategories above. Never invalidates commerceTags(): the shared tag list shows no
    // per-tag product count, so a product's own assignment changing never makes anything
    // useCommerceTags() renders stale.
    setTags: useMutation({
      mutation: (vars: { productUuid: string; tagUuids: string[] }) =>
        setProductTags(vars.productUuid, vars.tagUuids),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
    // Task 19b: product attribute assignment — invalidates ONLY the owning product, same
    // reasoning as setTags/setCategories above. Never invalidates commerceAttributes(): the
    // shared attribute list shows no per-attribute product count, so a product's own assignment
    // changing never makes anything useCommerceAttributes() renders stale.
    setAttributes: useMutation({
      mutation: (vars: { productUuid: string; rows: ProductAttributeAssignmentInput[] }) =>
        setProductAttributes(vars.productUuid, vars.rows),
      onSettled: (_d, _e, vars) => invalidateProductOnly(vars.productUuid),
    }),
  }
}

export function useCommerceCategories() {
  return useQuery({ key: qk.commerceCategories(), query: fetchCategories })
}

/** Category CRUD mutations — a separate hook from `useCommerceProductMutations()` since
 * categories are their own top-level resource (not scoped to a single product); every one
 * invalidates the shared category list, the only thing any `useCommerceCategories()` consumer
 * renders from. */
export function useCommerceCategoryMutations() {
  const cache = useQueryCache()
  const invalidateCategories = () => cache.invalidateQueries({ key: qk.commerceCategories() })

  return {
    create: useMutation({
      mutation: (input: CreateCategoryInput) => createCategory(input),
      onSettled: invalidateCategories,
    }),
    update: useMutation({
      mutation: (vars: { uuid: string; input: UpdateCategoryInput }) =>
        updateCategory(vars.uuid, vars.input),
      onSettled: invalidateCategories,
    }),
    remove: useMutation({
      mutation: (uuid: string) => deleteCategory(uuid),
      onSettled: invalidateCategories,
    }),
  }
}

/** Paginated, filtered (`q`) tag list — mirrors `useCommerceProducts()`/`useCommerceReviews()`'s
 * filter-suffixed key pattern rather than `useCommerceCategories()`'s bare one, since `fetchTags()`
 * (unlike `fetchCategories()`) takes real filters. */
export function useCommerceTags(filters: MaybeRefOrGetter<TagListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [...qk.commerceTags(), f.q ?? '', f.page ?? 1, f.perPage ?? 24]
    },
    query: () => fetchTags(toValue(filters)),
  })
}

/** Tag CRUD mutations — a separate hook from `useCommerceProductMutations()`, mirroring
 * `useCommerceCategoryMutations()`: tags are their own top-level resource (not scoped to a single
 * product); every one invalidates the shared tag list (every filter/page variant), the only thing
 * any `useCommerceTags()` consumer renders from. */
export function useCommerceTagMutations() {
  const cache = useQueryCache()
  const invalidateTags = () => cache.invalidateQueries({ key: qk.commerceTags() })

  return {
    create: useMutation({
      mutation: (input: CreateTagInput) => createTag(input),
      onSettled: invalidateTags,
    }),
    update: useMutation({
      mutation: (vars: { uuid: string; input: UpdateTagInput }) => updateTag(vars.uuid, vars.input),
      onSettled: invalidateTags,
    }),
    remove: useMutation({
      mutation: (uuid: string) => deleteTag(uuid),
      onSettled: invalidateTags,
    }),
  }
}

/** Paginated, filtered (`q`) attribute list — mirrors `useCommerceTags()`'s filter-suffixed key
 * pattern; each attribute's `values` come embedded (see `fetchAttributes()`'s docblock), so there
 * is no separate values query. */
export function useCommerceAttributes(filters: MaybeRefOrGetter<AttributeListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [...qk.commerceAttributes(), f.q ?? '', f.page ?? 1, f.perPage ?? 24]
    },
    query: () => fetchAttributes(toValue(filters)),
  })
}

/** Attribute CRUD + value CRUD mutations — a separate hook from `useCommerceProductMutations()`,
 * mirroring `useCommerceTagMutations()`: attributes (and their values) are their own top-level
 * resource, not scoped to a single product. Every one — attribute create/update/remove AND value
 * create/update/remove — invalidates the shared attribute list (every filter/page variant): a
 * value has no independent read path (it's embedded in its owning attribute's row), so a value
 * mutation has nothing narrower to invalidate than the whole list. */
export function useCommerceAttributeMutations() {
  const cache = useQueryCache()
  const invalidateAttributes = () => cache.invalidateQueries({ key: qk.commerceAttributes() })

  return {
    create: useMutation({
      mutation: (input: CreateAttributeInput) => createAttribute(input),
      onSettled: invalidateAttributes,
    }),
    update: useMutation({
      mutation: (vars: { uuid: string; input: UpdateAttributeInput }) =>
        updateAttribute(vars.uuid, vars.input),
      onSettled: invalidateAttributes,
    }),
    remove: useMutation({
      mutation: (uuid: string) => deleteAttribute(uuid),
      onSettled: invalidateAttributes,
    }),
    createValue: useMutation({
      mutation: (vars: { attributeUuid: string; input: CreateAttributeValueInput }) =>
        createAttributeValue(vars.attributeUuid, vars.input),
      onSettled: invalidateAttributes,
    }),
    updateValue: useMutation({
      mutation: (vars: { uuid: string; input: UpdateAttributeValueInput }) =>
        updateAttributeValue(vars.uuid, vars.input),
      onSettled: invalidateAttributes,
    }),
    removeValue: useMutation({
      mutation: (uuid: string) => deleteAttributeValue(uuid),
      onSettled: invalidateAttributes,
    }),
  }
}
