import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'
import { qk, COMMERCE_PRODUCT_SECTIONS } from './keys'

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

export const ADDON_FIELD_TYPES = ['select', 'checkbox', 'text'] as const
export type CommerceAddonFieldType = (typeof ADDON_FIELD_TYPES)[number]

export const ADDON_STATUSES = ['active', 'inactive'] as const
export type CommerceAddonStatus = (typeof ADDON_STATUSES)[number]

/** One `commerce_product_addons.choices` element (`AddonService::normalizeChoices()`) — SELECT-type
 * add-ons only; `checkbox`/`text` add-ons carry a single `price_delta` on the row itself and
 * `choices` is always `null` for them (see `CommerceAddon`'s docblock). */
export interface CommerceAddonChoice {
  key: string
  label: string
  /** Minor-unit SIGNED integer delta (`AddonService::normalizeChoices()`'s own docblock: "a
   * signed integer price_delta per choice") — format with `useMoney`, never `Number()`. */
  price_delta: number
}

/** A `commerce_product_addons` row (design spec Layer 6 §2, `007_CreateCommerceCatalogBreadthTables.php`)
 * — PER-PRODUCT (not a tenant-wide taxonomy like tags/categories/attributes): `GET
 * /commerce/products/{uuid}/addons` IS a real admin read path (unlike media/children/tag/category/
 * attribute assignment, which have no admin GET), so `AddonsPanel.vue` hydrates from it directly —
 * no "unknown assignment" placeholder state needed.
 *
 * `field_type === 'select'` carries a non-empty `choices` list and a forced `price_delta` of 0 on
 * the row itself (`AddonService::create()`/`planUpdate()`: `price_delta` is always overwritten to
 * 0 for select add-ons, regardless of what's submitted); `checkbox`/`text` carry `choices: null`
 * and their own signed `price_delta` instead. A definition edit never touches an existing cart/
 * order line — `AddonSnapshot` bakes display AND price fields into the line at selection time, so
 * an edit only ever affects FUTURE selections (`AddonService`'s own class docblock) — and
 * `status: 'inactive'` similarly only removes an add-on from new selections, never from
 * already-placed orders. */
export interface CommerceAddon {
  uuid: string
  product_uuid: string
  name: string
  field_type: string
  required: boolean
  choices: CommerceAddonChoice[] | null
  price_delta: number
  position: number
  status: string
}

/** `POST /commerce/products/{uuid}/addons` body (CreateAddonData). */
export interface CreateAddonInput {
  name: string
  field_type: string
  required?: boolean
  choices?: Array<{ key: string; label: string; price_delta: number }> | null
  price_delta?: number
  position?: number | null
  status?: string
}

/** `PATCH /commerce/addons/{uuid}` body (UpdateAddonData) — the controller reads the raw body (see
 * its own docblock), so only present keys are applied server-side; `AddonsPanel.vue` always sends
 * the FULL shape on every save regardless (mirrors `AttributesTab`'s value-edit form's "always
 * submits every field" discipline), never a sparse diff, so `field_type`/`choices`/`price_delta`
 * can never fall out of sync with each other mid-edit. */
export interface UpdateAddonInput {
  name?: string
  field_type?: string
  required?: boolean
  choices?: Array<{ key: string; label: string; price_delta: number }> | null
  price_delta?: number
  position?: number | null
  status?: string
}

export const DOWNLOAD_STATUSES = ['active', 'inactive'] as const
export type CommerceDownloadStatus = (typeof DOWNLOAD_STATUSES)[number]

/** A `commerce_downloads` row (design spec Layer 6 §2, `008_CreateCommerceCustomerDeliveryTables.php`)
 * — PER-VARIANT (unlike `CommerceAddon`'s per-product scope): `GET /commerce/variants/{uuid}/downloads`
 * IS a real admin read path (`DownloadService::list()`/`DownloadRepository::forVariant()`, ordered by
 * position), so `DownloadsPanel.vue` hydrates directly from it once a variant's section is expanded —
 * no "unknown assignment" placeholder dance needed (mirrors `CommerceAddon`'s docblock).
 * `download_limit`/`expiry_days` are both nullable and `null` is a REAL value (unlimited downloads /
 * never expires) — never "unset" (`DownloadService::normalizeNonNegativeInt()`'s own docblock).
 * Money-free: a download definition carries no price of its own — delivery is bundled into the
 * owning variant's own price, so no `useMoney` import is needed anywhere in this domain. */
export interface CommerceDownload {
  uuid: string
  variant_uuid: string
  blob_uuid: string
  name: string
  download_limit: number | null
  expiry_days: number | null
  position: number
  status: string
}

/** `POST /commerce/variants/{uuid}/downloads` body (CreateDownloadData). The referenced blob must
 * already exist, be `active`, and be PRIVATE (`DownloadService::assertBlobAttachable()` — the
 * INVERSE of product media, which requires public) — the picker that sources `blob_uuid` must
 * upload/pick with `visibility: 'private'` (see `MediaPickerModal`'s `visibility` prop). */
export interface AttachDownloadInput {
  blob_uuid: string
  name: string
  download_limit?: number | null
  expiry_days?: number | null
  position?: number | null
}

/** `PATCH /commerce/downloads/{uuid}` body (UpdateDownloadData) — the controller reads the raw body
 * (see its own docblock: distinguishes an ABSENT key, "leave unchanged", from an explicit `null` on
 * `download_limit`/`expiry_days`, a real unlimited/never value), so only present keys are applied
 * server-side. `DownloadsPanel.vue` always sends the full shape on every save regardless (mirrors
 * `UpdateAddonInput`'s docblock), never a sparse diff. `blob_uuid` is absent on purpose — the
 * definition's blob can never be changed after attach, only detached and re-attached. */
export interface UpdateDownloadInput {
  name?: string
  download_limit?: number | null
  expiry_days?: number | null
  position?: number | null
  status?: string
}

/**
 * A digital-download grant projection (`AdminGrantController::projection()`) — the operator
 * kill-switch/audit surface (design spec §8) over an ALREADY-ISSUED grant: revoke, and the audited
 * full-refund access override set/clear.
 *
 * STAGED, NOT WIRED INTO ANY COMPONENT. Confirmed against `AdminRouteCatalog` (only
 * `grants.revoke`/`grants.refund_override.set`/`grants.refund_override.clear` exist — nothing
 * lists a grant) and `AdminOrderController::show()`'s own payload (`lines`/`events`/
 * `seller_orders` — none of which carries a grant uuid, even though `events` DOES log
 * `download.grant_revoked`/`download.override_set`/`download.override_cleared` entries emitted
 * BY these very mutations) that there is NO admin GET/listing endpoint anywhere in the shipped
 * backend surface a grant uuid could be read from. A grant is therefore unreachable from this
 * admin SPA today. `revokeGrant()`/`setGrantRefundOverride()`/`clearGrantRefundOverride()` and
 * `useCommerceGrantMutations()` below exist so the query layer is complete against the real,
 * shipped backend contract — this is an intentional, honest cut: wire them into a component only
 * once a grant-listing endpoint ships for a uuid to come from.
 */
export interface CommerceGrant {
  grant_uuid: string
  order_uuid: string
  name: string
  remaining: number | null
  expires_at: string | null
  mint_count: number
  last_minted_at: string | null
  revoked_at: string | null
  refund_access_override_at: string | null
  refund_access_override_by: string | null
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

function normalizeAddonChoice(raw: Record<string, unknown>): CommerceAddonChoice {
  return {
    key: String(raw.key ?? ''),
    label: String(raw.label ?? ''),
    price_delta: typeof raw.price_delta === 'number' ? raw.price_delta : 0,
  }
}

function normalizeAddon(raw: Record<string, unknown>): CommerceAddon {
  const choices = Array.isArray(raw.choices) ? raw.choices : null
  return {
    uuid: String(raw.uuid ?? ''),
    product_uuid: String(raw.product_uuid ?? ''),
    name: String(raw.name ?? ''),
    field_type: String(raw.field_type ?? 'text'),
    required: Boolean(raw.required),
    choices: choices ? choices.map((c) => normalizeAddonChoice(c as Record<string, unknown>)) : null,
    price_delta: typeof raw.price_delta === 'number' ? raw.price_delta : 0,
    position: typeof raw.position === 'number' ? raw.position : 0,
    status: String(raw.status ?? 'active'),
  }
}

function normalizeDownload(raw: Record<string, unknown>): CommerceDownload {
  return {
    uuid: String(raw.uuid ?? ''),
    variant_uuid: String(raw.variant_uuid ?? ''),
    blob_uuid: String(raw.blob_uuid ?? ''),
    name: String(raw.name ?? ''),
    download_limit: typeof raw.download_limit === 'number' ? raw.download_limit : null,
    expiry_days: typeof raw.expiry_days === 'number' ? raw.expiry_days : null,
    position: typeof raw.position === 'number' ? raw.position : 0,
    status: String(raw.status ?? 'active'),
  }
}

function normalizeGrant(raw: Record<string, unknown>): CommerceGrant {
  return {
    grant_uuid: String(raw.grant_uuid ?? ''),
    order_uuid: String(raw.order_uuid ?? ''),
    name: String(raw.name ?? ''),
    remaining: typeof raw.remaining === 'number' ? raw.remaining : null,
    expires_at: typeof raw.expires_at === 'string' ? raw.expires_at : null,
    mint_count: typeof raw.mint_count === 'number' ? raw.mint_count : 0,
    last_minted_at: typeof raw.last_minted_at === 'string' ? raw.last_minted_at : null,
    revoked_at: typeof raw.revoked_at === 'string' ? raw.revoked_at : null,
    refund_access_override_at:
      typeof raw.refund_access_override_at === 'string' ? raw.refund_access_override_at : null,
    refund_access_override_by:
      typeof raw.refund_access_override_by === 'string' ? raw.refund_access_override_by : null,
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
 * child products (ordered). There is no admin GET for the current children (Task C1 adds one —
 * see `useProductChildren()` in `commerceProductSections.ts`), so this response — and any prior
 * successful call's response — remains the freshest post-write source of truth.
 *
 * `expectedRevision` (single-page product editor plan, Task C1): optional CAS guard mirroring
 * `SetProductChildrenData::$expected_revision` server-side — omitted preserves today's unguarded
 * replace byte-for-byte (the key is never sent on the wire); present, a stale value 409s via
 * `StaleCatalogRevisionException` with no state change. */
export async function setProductChildren(
  productUuid: string,
  childUuids: string[],
  expectedRevision?: number,
): Promise<CommerceProduct[]> {
  const body: Record<string, unknown> = { child_uuids: childUuids }
  if (expectedRevision !== undefined) body.expected_revision = expectedRevision
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/children', {
    params: { path: { uuid: productUuid } },
    body: body as never,
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
 * a product's media the SPA ever gets back (mirrors `setProductChildren`'s note).
 *
 * `expectedRevision` (single-page product editor plan, Task C1): optional CAS guard mirroring
 * `ReorderMediaData::$expected_revision` server-side — see `setProductChildren`'s docblock for the
 * full byte-for-byte-when-absent rationale (not repeated here to avoid drift). */
export async function reorderProductMedia(
  productUuid: string,
  orderedUuids: string[],
  expectedRevision?: number,
): Promise<CommerceProductMedia[]> {
  const positions = orderedUuids.map((uuid, index) => ({ uuid, position: index }))
  const body: Record<string, unknown> = { positions }
  if (expectedRevision !== undefined) body.expected_revision = expectedRevision
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/media/order', {
    params: { path: { uuid: productUuid } },
    body: body as never,
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
 * category assignment, and the response is the fresh list of attached category rows. Task C1 adds
 * a real admin GET for a product's current categories — see `useProductCategories()` in
 * `commerceProductSections.ts` — but this mutation's own response remains the freshest
 * post-write source of truth.
 *
 * `expectedRevision` (single-page product editor plan, Task C1): optional CAS guard mirroring
 * `SetProductCategoriesData::$expected_revision` server-side — see `setProductChildren`'s
 * docblock for the full byte-for-byte-when-absent rationale (not repeated here to avoid drift). */
export async function setProductCategories(
  productUuid: string,
  categoryUuids: string[],
  expectedRevision?: number,
): Promise<CommerceCategory[]> {
  const body: Record<string, unknown> = { category_uuids: categoryUuids }
  if (expectedRevision !== undefined) body.expected_revision = expectedRevision
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/categories', {
    params: { path: { uuid: productUuid } },
    body: body as never,
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
 * assignment, and the response is the fresh list of attached tag rows. Task C1 adds a real admin
 * GET for a product's current tags — see `useProductTags()` in `commerceProductSections.ts` — but
 * this mutation's own response remains the freshest post-write source of truth.
 *
 * `expectedRevision` (single-page product editor plan, Task C1): optional CAS guard mirroring
 * `SetProductTagsData::$expected_revision` server-side — see `setProductChildren`'s docblock for
 * the full byte-for-byte-when-absent rationale (not repeated here to avoid drift). */
export async function setProductTags(
  productUuid: string,
  tagUuids: string[],
  expectedRevision?: number,
): Promise<CommerceTag[]> {
  const body: Record<string, unknown> = { tag_uuids: tagUuids }
  if (expectedRevision !== undefined) body.expected_revision = expectedRevision
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/tags', {
    params: { path: { uuid: productUuid } },
    body: body as never,
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
 * `ProductAttributeAssignmentInput`'s docblock. Task C1 adds a real admin GET for a product's
 * current attribute assignment — see `useProductAttributes()` in `commerceProductSections.ts` —
 * but this mutation's own response remains the freshest post-write source of truth.
 *
 * `expectedRevision` (single-page product editor plan, Task C1): optional CAS guard mirroring
 * `SetProductAttributesData::$expected_revision` server-side — see `setProductChildren`'s
 * docblock for the full byte-for-byte-when-absent rationale (not repeated here to avoid drift). */
export async function setProductAttributes(
  productUuid: string,
  rows: ProductAttributeAssignmentInput[],
  expectedRevision?: number,
): Promise<CommerceProductAttribute[]> {
  const body: Record<string, unknown> = { attributes: rows }
  if (expectedRevision !== undefined) body.expected_revision = expectedRevision
  const { data, error, response } = await client.PUT('/commerce/products/{uuid}/attributes', {
    params: { path: { uuid: productUuid } },
    body: body as never,
  })
  if (error) throw toApiError(error, response)
  const result = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(result)
    ? result.map((r) => normalizeProductAttribute(r as Record<string, unknown>))
    : []
}

/** `GET /commerce/products/{uuid}/addons` — a real per-product admin read path (unlike media/
 * children/tags/categories/attributes' assignment endpoints, which have none — see `CommerceAddon`'s
 * docblock), ordered by position (`AddonRepository::forProduct()`). */
export async function fetchProductAddons(productUuid: string): Promise<CommerceAddon[]> {
  const { data, error, response } = await client.GET('/commerce/products/{uuid}/addons', {
    params: { path: { uuid: productUuid } },
  })
  if (error) throw toApiError(error, response)
  const rows = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(rows) ? rows.map((a) => normalizeAddon(a as Record<string, unknown>)) : []
}

export async function createProductAddon(
  productUuid: string,
  input: CreateAddonInput,
): Promise<CommerceAddon> {
  const { data, error, response } = await client.POST('/commerce/products/{uuid}/addons', {
    params: { path: { uuid: productUuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeAddon((raw ?? {}) as Record<string, unknown>)
}

export async function updateProductAddon(uuid: string, input: UpdateAddonInput): Promise<CommerceAddon> {
  const { data, error, response } = await client.PATCH('/commerce/addons/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeAddon((raw ?? {}) as Record<string, unknown>)
}

export async function deleteProductAddon(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/addons/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

/** `GET /commerce/variants/{uuid}/downloads` — a real per-variant admin read path (unlike media/
 * children/tags/categories/attributes' assignment endpoints), ordered by position
 * (`DownloadRepository::forVariant()`). */
export async function fetchVariantDownloads(variantUuid: string): Promise<CommerceDownload[]> {
  const { data, error, response } = await client.GET('/commerce/variants/{uuid}/downloads', {
    params: { path: { uuid: variantUuid } },
  })
  if (error) throw toApiError(error, response)
  const rows = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(rows) ? rows.map((d) => normalizeDownload(d as Record<string, unknown>)) : []
}

export async function attachVariantDownload(
  variantUuid: string,
  input: AttachDownloadInput,
): Promise<CommerceDownload> {
  const { data, error, response } = await client.POST('/commerce/variants/{uuid}/downloads', {
    params: { path: { uuid: variantUuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDownload((raw ?? {}) as Record<string, unknown>)
}

export async function updateDownload(uuid: string, input: UpdateDownloadInput): Promise<CommerceDownload> {
  const { data, error, response } = await client.PATCH('/commerce/downloads/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDownload((raw ?? {}) as Record<string, unknown>)
}

export async function deleteDownload(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/downloads/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

/** `POST /commerce/grants/{uuid}/revoke` (`AdminGrantController::revoke()`) — see `CommerceGrant`'s
 * docblock: staged against the real endpoint, not wired into any component. Takes no request body. */
export async function revokeGrant(uuid: string): Promise<CommerceGrant> {
  const { data, error, response } = await client.POST('/commerce/grants/{uuid}/revoke', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeGrant((raw ?? {}) as Record<string, unknown>)
}

/** `PUT /commerce/grants/{uuid}/refund-access-override` — see `CommerceGrant`'s docblock. */
export async function setGrantRefundOverride(uuid: string): Promise<CommerceGrant> {
  const { data, error, response } = await client.PUT('/commerce/grants/{uuid}/refund-access-override', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeGrant((raw ?? {}) as Record<string, unknown>)
}

/** `DELETE /commerce/grants/{uuid}/refund-access-override` — see `CommerceGrant`'s docblock. */
export async function clearGrantRefundOverride(uuid: string): Promise<CommerceGrant> {
  const { data, error, response } = await client.DELETE('/commerce/grants/{uuid}/refund-access-override', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeGrant((raw ?? {}) as Record<string, unknown>)
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

/** A product's add-on definitions — a real admin GET (see `CommerceAddon`'s docblock), so
 * `AddonsPanel.vue` hydrates directly from this, no "unknown assignment" placeholder needed
 * (unlike `useCommerceProduct`'s siblings for media/children/tags/categories/attributes). */
export function useCommerceProductAddons(productUuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceProductAddons(toValue(productUuid)),
    query: () => fetchProductAddons(toValue(productUuid)),
    enabled: () => !!toValue(productUuid),
  })
}

/** A variant's download definitions — a real admin GET (see `CommerceDownload`'s docblock), so
 * `DownloadsPanel.vue` hydrates directly from this once a variant's section is expanded, no
 * "unknown assignment" placeholder needed (mirrors `useCommerceProductAddons()` above). */
export function useCommerceVariantDownloads(variantUuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceVariantDownloads(toValue(variantUuid)),
    query: () => fetchVariantDownloads(toValue(variantUuid)),
    enabled: () => !!toValue(variantUuid),
  })
}

/** Product mutations. `create`/`bulkStatus` invalidate the list; `remove` invalidates both the
 * single product and the list (its row is gone).
 *
 * Single-page product editor plan, Task C1 — invalidation contract (load-bearing for the whole
 * editor, pinned by `commerceProductSectionsInvalidation.spec.ts`): every mutation that can
 * advance the product's `catalog_revision` — `update` (details), the variant/media/organization/
 * children/stock mutations (Task 10b–10d/19a–19b), and the add-on/download mutations
 * (Task 19c–19d) — invalidates BOTH `qk.commerceProduct(uuid)` AND all six
 * `qk.commerceProductSection(uuid, section)` keys (Task C1's new per-product reads), via
 * `invalidateProductAndSections()`. `create`/`bulkStatus`/`remove` are UNCHANGED (a not-yet-existing
 * or now-gone product has no section caches worth refreshing); pack-owned product-LINK mutations
 * (`useCommerceLinkMutations()` in `commerceLinking.ts`) are also unchanged — they don't advance
 * `catalog_revision` (spec-pinned negative case).
 *
 * Every mutation still requires the caller to pass the owning `productUuid` explicitly (mirroring
 * `update`/`remove`'s `uuid`) rather than reading it off the mutation's own response, so the
 * invalidation still runs correctly even when the call fails. The three download mutations are the
 * one exception: they're PER-VARIANT and callers historically had no reason to know the owning
 * product, so `productUuid` is OPTIONAL there — omitted preserves the pre-C1 downloads-list-only
 * invalidation byte-for-byte; supplied, the product + six sections are invalidated too.
 */
export function useCommerceProductMutations() {
  const cache = useQueryCache()
  const invalidateList = () => cache.invalidateQueries({ key: qk.commerceProducts() })
  const invalidateProduct = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceProduct(uuid) })
    invalidateList()
  }
  const invalidateProductOnly = (uuid: string) => cache.invalidateQueries({ key: qk.commerceProduct(uuid) })
  const invalidateProductSections = (uuid: string) => {
    for (const section of COMMERCE_PRODUCT_SECTIONS) {
      cache.invalidateQueries({ key: qk.commerceProductSection(uuid, section) })
    }
  }
  const invalidateProductAndSections = (uuid: string) => {
    invalidateProductOnly(uuid)
    invalidateProductSections(uuid)
  }
  const invalidateAddons = (productUuid: string) =>
    cache.invalidateQueries({ key: qk.commerceProductAddons(productUuid) })
  const invalidateDownloads = (variantUuid: string) =>
    cache.invalidateQueries({ key: qk.commerceVariantDownloads(variantUuid) })

  return {
    create: useMutation({
      mutation: (input: CreateProductInput) => createProduct(input),
      onSettled: invalidateList,
    }),
    update: useMutation({
      mutation: (vars: { uuid: string; input: UpdateProductInput }) =>
        updateProduct(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => {
        invalidateProductAndSections(vars.uuid)
        invalidateList()
      },
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
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    updateVariant: useMutation({
      mutation: (vars: { uuid: string; productUuid: string; input: UpdateVariantInput }) =>
        updateProductVariant(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    bulkPrice: useMutation({
      mutation: (vars: { productUuid: string; items: BulkPriceItem[] }) =>
        bulkUpdateVariantPrices(vars.items),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    setChildren: useMutation({
      mutation: (vars: { productUuid: string; childUuids: string[]; expectedRevision?: number }) =>
        setProductChildren(vars.productUuid, vars.childUuids, vars.expectedRevision),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    stockAdjust: useMutation({
      mutation: (vars: { variantUuid: string; productUuid: string; input: StockAdjustInput }) =>
        adjustVariantStock(vars.variantUuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    // Task 10c: media mutations, same invalidation reasoning as variants/children/stock above —
    // every one of these vars carries the owning `productUuid` explicitly.
    attachMedia: useMutation({
      mutation: (vars: { productUuid: string; input: AttachMediaInput }) =>
        attachProductMedia(vars.productUuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    updateMedia: useMutation({
      mutation: (vars: { uuid: string; productUuid: string; input: UpdateProductMediaInput }) =>
        updateProductMedia(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    detachMedia: useMutation({
      mutation: (vars: { uuid: string; productUuid: string }) => detachProductMedia(vars.uuid),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    reorderMedia: useMutation({
      mutation: (vars: { productUuid: string; orderedUuids: string[]; expectedRevision?: number }) =>
        reorderProductMedia(vars.productUuid, vars.orderedUuids, vars.expectedRevision),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    // Task 10d: product category assignment — invalidates the owning product AND its six section
    // reads (Task C1). Never invalidates commerceCategories(): the shared category list shows no
    // per-category product count, so a product's own assignment changing never makes anything
    // useCommerceCategories() renders stale.
    setCategories: useMutation({
      mutation: (vars: { productUuid: string; categoryUuids: string[]; expectedRevision?: number }) =>
        setProductCategories(vars.productUuid, vars.categoryUuids, vars.expectedRevision),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    // Task 19a: product tag assignment — invalidates the owning product AND its six section reads
    // (Task C1), same reasoning as setCategories above. Never invalidates commerceTags(): the
    // shared tag list shows no per-tag product count, so a product's own assignment changing never
    // makes anything useCommerceTags() renders stale.
    setTags: useMutation({
      mutation: (vars: { productUuid: string; tagUuids: string[]; expectedRevision?: number }) =>
        setProductTags(vars.productUuid, vars.tagUuids, vars.expectedRevision),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    // Task 19b: product attribute assignment — invalidates the owning product AND its six section
    // reads (Task C1), same reasoning as setTags/setCategories above. Never invalidates
    // commerceAttributes(): the shared attribute list shows no per-attribute product count, so a
    // product's own assignment changing never makes anything useCommerceAttributes() renders stale.
    setAttributes: useMutation({
      mutation: (vars: {
        productUuid: string
        rows: ProductAttributeAssignmentInput[]
        expectedRevision?: number
      }) => setProductAttributes(vars.productUuid, vars.rows, vars.expectedRevision),
      onSettled: (_d, _e, vars) => invalidateProductAndSections(vars.productUuid),
    }),
    // Task 19c: product add-ons — PER-PRODUCT (unlike tags/categories/attributes' tenant-wide
    // CRUD, add-ons have no top-level management surface of their own), so these fold in here
    // alongside variants/media/children/stock rather than getting a standalone
    // useCommerceAddonMutations() hook. Every one invalidates qk.commerceProductAddons(productUuid)
    // (unchanged — no admin product endpoint embeds `addons` in its payload) PLUS the owning
    // product and its six section reads (Task C1): the product-editor Add-ons card lives on the
    // same page as the other sections, so a save there must also settle the shared revision
    // coordinator (Task C3) the same way every other section mutation does.
    createAddon: useMutation({
      mutation: (vars: { productUuid: string; input: CreateAddonInput }) =>
        createProductAddon(vars.productUuid, vars.input),
      onSettled: (_d, _e, vars) => {
        invalidateAddons(vars.productUuid)
        invalidateProductAndSections(vars.productUuid)
      },
    }),
    updateAddon: useMutation({
      mutation: (vars: { uuid: string; productUuid: string; input: UpdateAddonInput }) =>
        updateProductAddon(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => {
        invalidateAddons(vars.productUuid)
        invalidateProductAndSections(vars.productUuid)
      },
    }),
    removeAddon: useMutation({
      mutation: (vars: { uuid: string; productUuid: string }) => deleteProductAddon(vars.uuid),
      onSettled: (_d, _e, vars) => {
        invalidateAddons(vars.productUuid)
        invalidateProductAndSections(vars.productUuid)
      },
    }),
    // Task 19d: variant downloads — PER-VARIANT (deeper than add-ons' per-product scope, but the
    // same "no standalone top-level management surface" reasoning applies), so these fold in here
    // too. Every one invalidates qk.commerceVariantDownloads(variantUuid) (unchanged). `productUuid`
    // is OPTIONAL (see this hook's own docblock): when the caller supplies it, the owning product
    // and its six section reads (Task C1) are invalidated too, same reasoning as
    // createAddon/updateAddon/removeAddon above; omitted, behavior stays byte-for-byte pre-C1.
    attachDownload: useMutation({
      mutation: (vars: { variantUuid: string; productUuid?: string; input: AttachDownloadInput }) =>
        attachVariantDownload(vars.variantUuid, vars.input),
      onSettled: (_d, _e, vars) => {
        invalidateDownloads(vars.variantUuid)
        if (vars.productUuid) invalidateProductAndSections(vars.productUuid)
      },
    }),
    updateDownload: useMutation({
      mutation: (vars: {
        uuid: string
        variantUuid: string
        productUuid?: string
        input: UpdateDownloadInput
      }) => updateDownload(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => {
        invalidateDownloads(vars.variantUuid)
        if (vars.productUuid) invalidateProductAndSections(vars.productUuid)
      },
    }),
    removeDownload: useMutation({
      mutation: (vars: { uuid: string; variantUuid: string; productUuid?: string }) =>
        deleteDownload(vars.uuid),
      onSettled: (_d, _e, vars) => {
        invalidateDownloads(vars.variantUuid)
        if (vars.productUuid) invalidateProductAndSections(vars.productUuid)
      },
    }),
  }
}

/** Grant operator mutations (revoke / refund-access override set-clear) — see `CommerceGrant`'s
 * docblock: STAGED against the real endpoints, intentionally unwired from any component (there is
 * no admin GET/listing endpoint anywhere a grant uuid could be read from). No `onSettled`
 * invalidation: there is no cached grant list/query anywhere in this app for these to invalidate. */
export function useCommerceGrantMutations() {
  return {
    revoke: useMutation({ mutation: (uuid: string) => revokeGrant(uuid) }),
    setRefundOverride: useMutation({ mutation: (uuid: string) => setGrantRefundOverride(uuid) }),
    clearRefundOverride: useMutation({ mutation: (uuid: string) => clearGrantRefundOverride(uuid) }),
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
