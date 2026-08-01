import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk, COMMERCE_PRODUCT_SECTIONS, type CommerceProductSection } from './keys'

// Single-page product editor plan, Task C1: the six per-product section reads
// (categories/tags/attributes/media/children/stock) — see `CatalogService::childrenForProduct()`/
// `stockForProduct()` and `CategoryService`/`TagService`/`AttributeService`/`ProductMediaService`'s
// own `forProduct()` docblocks (glueful/commerce, single-page product editor plan). Every one
// returns the SAME closed envelope shape (Global Constraints: "exact envelope `{revision, items}`
// — no extra keys, no raw rows") and reads `revision` from `ProductRepository::catalogRevision()`
// BEFORE querying `items`, so a concurrent write between the two only ever costs a later CAS save
// (the five replacement mutations below) a harmless 409 — never a false pass.
//
// UNLIKE `commerceCatalog.ts`'s existing normalizers (which default a malformed field to a neutral
// fallback — `String(raw.uuid ?? '')`, `typeof raw.position === 'number' ? raw.position : 0`),
// every normalizer here THROWS on a field that doesn't match the wire contract instead of
// defaulting or skipping the item. These envelopes feed the five replacement mutations' payloads
// (`setCategories`/`setTags`/`setAttributes`/`reorderMedia`/`setChildren` below) — a silently
// skipped or defaulted item would round-trip back to the server as a DROPPED assignment on the
// next save, reintroducing the exact "wipe" class of bug the whole `{revision, items}` contract
// exists to prevent (see `docs/internal/superpowers/sdd/editor/global-constraints.md`'s "1.4.1 lesson").

export type SectionKey = CommerceProductSection

/** The closed `{revision, items}` envelope every section read returns (Global Constraints). */
export interface SectionEnvelope<T> {
  revision: number
  items: T[]
}

/** `{uuid, name, slug}` — the assigned-category projection (`CategoryService::forProduct()`'s own
 * docblock: `commerce_categories.{uuid,name,slug}` only, name-then-uuid ordered). Deliberately NOT
 * `CommerceCategory` (the tenant-wide CRUD shape in `commerceCatalog.ts`, which also carries
 * `parent_uuid`/`description`/`position`) — this is the narrower read-only assignment projection. */
export interface AssignedCategory {
  uuid: string
  name: string
  slug: string
}

/** `{uuid, name, slug}` — the assigned-tag projection (`TagService::forProduct()`'s own docblock),
 * mirrors `AssignedCategory` above; deliberately NOT `CommerceTag`. */
export interface AssignedTag {
  uuid: string
  name: string
  slug: string
}

/** `{attribute_uuid, name, values, used_for_variants, visible, position}` — the assigned-attribute
 * projection (`AttributeService::forProduct()`'s own docblock). Exactly one of `attribute_uuid`
 * (tenant-linked) or `name` (a one-off custom row) is non-null, mirroring
 * `ProductAttributeAssignmentInput`'s docblock in `commerceCatalog.ts` — unlike
 * `CommerceProductAttribute` (the mutation-response shape), this read carries no `uuid`/
 * `product_uuid`/`attribute_slug`/`attribute_name`. */
export interface ProductAttributeAssignment {
  attribute_uuid: string | null
  name: string | null
  values: string[]
  used_for_variants: boolean
  visible: boolean
  position: number
}

/** `{uuid, blob_uuid, role, position, alt, variant_uuid}` — the product-media projection
 * (`ProductMediaService::forProduct()`'s own docblock); `variant_uuid` surfaces variant-scoped
 * attribution (nullable — a product-wide row carries `null`). */
export interface ProductMediaItem {
  uuid: string
  blob_uuid: string
  role: string
  position: number
  alt: string | null
  variant_uuid: string | null
}

/** `{uuid, name, slug, status, deleted, position}` — the assigned-child projection
 * (`CatalogService::childrenForProduct()`'s own docblock). `deleted` is DELIBERATELY included for
 * an attached tombstoned child rather than hiding it (Global Constraints: "Admin children reads
 * never hide existing attachments") — a replacement may retain or drop it, but may never newly
 * attach a tombstoned product. */
export interface ProductChildItem {
  uuid: string
  name: string
  slug: string
  status: string
  deleted: boolean
  position: number
}

/** `{variant_uuid, tracked, quantity}` — the per-variant stock projection
 * (`CatalogService::stockForProduct()`'s own docblock). A missing `commerce_stock` row is a
 * `StockIntegrityException` on the backend (Global Constraints: "the read fails loudly") — it
 * never reaches this envelope as a synthetic `{tracked: false, quantity: 0}` row, so every item
 * here always carries real `tracked`/`quantity` values. */
export interface VariantStock {
  variant_uuid: string
  tracked: boolean
  quantity: number
}

// ── Strict field guards ──────────────────────────────────────────────────────
//
// Every guard throws — rather than defaulting or skipping — the instant a field doesn't match the
// wire contract (see the module docblock above for why). Message style mirrors the codebase's
// existing "Malformed X response." normalizer convention (`signupSettings.ts`/
// `tenancyEnablement.ts`/`tenancyResolution.ts`/`tenancyDiagnose.ts`).

function expectItemObject(raw: unknown, section: string): Record<string, unknown> {
  if (typeof raw !== 'object' || raw === null) {
    throw new Error(`Malformed product ${section} response: item is not an object.`)
  }
  return raw as Record<string, unknown>
}

function expectString(value: unknown, field: string, section: string): string {
  if (typeof value !== 'string') {
    throw new Error(`Malformed product ${section} response: '${field}' is not a string.`)
  }
  return value
}

function expectNullableString(value: unknown, field: string, section: string): string | null {
  if (value === null) return null
  if (typeof value !== 'string') {
    throw new Error(`Malformed product ${section} response: '${field}' is not a string or null.`)
  }
  return value
}

function expectBoolean(value: unknown, field: string, section: string): boolean {
  if (typeof value !== 'boolean') {
    throw new Error(`Malformed product ${section} response: '${field}' is not a boolean.`)
  }
  return value
}

function expectNumber(value: unknown, field: string, section: string): number {
  if (typeof value !== 'number') {
    throw new Error(`Malformed product ${section} response: '${field}' is not a number.`)
  }
  return value
}

function expectStringArray(value: unknown, field: string, section: string): string[] {
  if (!Array.isArray(value) || value.some((v) => typeof v !== 'string')) {
    throw new Error(`Malformed product ${section} response: '${field}' is not an array of strings.`)
  }
  return value
}

/** Envelope-level guard shared by all six sections: `revision` must be a non-negative integer
 * (Global Constraints) and `items` must be an array — both throw, never default, on violation. */
function normalizeSectionEnvelope<T>(
  data: unknown,
  section: string,
  normalizeItem: (raw: unknown) => T,
): SectionEnvelope<T> {
  const body = (data as { data?: unknown } | undefined)?.data
  if (typeof body !== 'object' || body === null) {
    throw new Error(`Malformed product ${section} response: envelope is not an object.`)
  }
  const envelope = body as Record<string, unknown>
  const revision = envelope.revision
  if (typeof revision !== 'number' || !Number.isInteger(revision) || revision < 0) {
    throw new Error(`Malformed product ${section} response: 'revision' must be a non-negative integer.`)
  }
  if (!Array.isArray(envelope.items)) {
    throw new Error(`Malformed product ${section} response: 'items' must be an array.`)
  }
  return {
    revision,
    items: envelope.items.map((item) => normalizeItem(item)),
  }
}

function normalizeAssignedCategory(raw: unknown): AssignedCategory {
  const r = expectItemObject(raw, 'categories')
  return {
    uuid: expectString(r.uuid, 'uuid', 'categories'),
    name: expectString(r.name, 'name', 'categories'),
    slug: expectString(r.slug, 'slug', 'categories'),
  }
}

function normalizeAssignedTag(raw: unknown): AssignedTag {
  const r = expectItemObject(raw, 'tags')
  return {
    uuid: expectString(r.uuid, 'uuid', 'tags'),
    name: expectString(r.name, 'name', 'tags'),
    slug: expectString(r.slug, 'slug', 'tags'),
  }
}

function normalizeProductAttributeAssignment(raw: unknown): ProductAttributeAssignment {
  const r = expectItemObject(raw, 'attributes')
  return {
    attribute_uuid: expectNullableString(r.attribute_uuid, 'attribute_uuid', 'attributes'),
    name: expectNullableString(r.name, 'name', 'attributes'),
    values: expectStringArray(r.values, 'values', 'attributes'),
    used_for_variants: expectBoolean(r.used_for_variants, 'used_for_variants', 'attributes'),
    visible: expectBoolean(r.visible, 'visible', 'attributes'),
    position: expectNumber(r.position, 'position', 'attributes'),
  }
}

function normalizeProductMediaItem(raw: unknown): ProductMediaItem {
  const r = expectItemObject(raw, 'media')
  return {
    uuid: expectString(r.uuid, 'uuid', 'media'),
    blob_uuid: expectString(r.blob_uuid, 'blob_uuid', 'media'),
    role: expectString(r.role, 'role', 'media'),
    position: expectNumber(r.position, 'position', 'media'),
    alt: expectNullableString(r.alt, 'alt', 'media'),
    variant_uuid: expectNullableString(r.variant_uuid, 'variant_uuid', 'media'),
  }
}

function normalizeProductChildItem(raw: unknown): ProductChildItem {
  const r = expectItemObject(raw, 'children')
  return {
    uuid: expectString(r.uuid, 'uuid', 'children'),
    name: expectString(r.name, 'name', 'children'),
    slug: expectString(r.slug, 'slug', 'children'),
    status: expectString(r.status, 'status', 'children'),
    deleted: expectBoolean(r.deleted, 'deleted', 'children'),
    position: expectNumber(r.position, 'position', 'children'),
  }
}

function normalizeVariantStock(raw: unknown): VariantStock {
  const r = expectItemObject(raw, 'stock')
  return {
    variant_uuid: expectString(r.variant_uuid, 'variant_uuid', 'stock'),
    tracked: expectBoolean(r.tracked, 'tracked', 'stock'),
    quantity: expectNumber(r.quantity, 'quantity', 'stock'),
  }
}

// ── Fetchers ─────────────────────────────────────────────────────────────────

export async function fetchProductCategoriesSection(
  productUuid: string,
): Promise<SectionEnvelope<AssignedCategory>> {
  const { data, error, response } = await client.GET('/commerce/products/{uuid}/categories', {
    params: { path: { uuid: productUuid } },
  })
  if (error) throw toApiError(error, response)
  return normalizeSectionEnvelope(data, 'categories', normalizeAssignedCategory)
}

export async function fetchProductTagsSection(productUuid: string): Promise<SectionEnvelope<AssignedTag>> {
  const { data, error, response } = await client.GET('/commerce/products/{uuid}/tags', {
    params: { path: { uuid: productUuid } },
  })
  if (error) throw toApiError(error, response)
  return normalizeSectionEnvelope(data, 'tags', normalizeAssignedTag)
}

export async function fetchProductAttributesSection(
  productUuid: string,
): Promise<SectionEnvelope<ProductAttributeAssignment>> {
  const { data, error, response } = await client.GET('/commerce/products/{uuid}/attributes', {
    params: { path: { uuid: productUuid } },
  })
  if (error) throw toApiError(error, response)
  return normalizeSectionEnvelope(data, 'attributes', normalizeProductAttributeAssignment)
}

export async function fetchProductMediaSection(
  productUuid: string,
): Promise<SectionEnvelope<ProductMediaItem>> {
  const { data, error, response } = await client.GET('/commerce/products/{uuid}/media', {
    params: { path: { uuid: productUuid } },
  })
  if (error) throw toApiError(error, response)
  return normalizeSectionEnvelope(data, 'media', normalizeProductMediaItem)
}

export async function fetchProductChildrenSection(
  productUuid: string,
): Promise<SectionEnvelope<ProductChildItem>> {
  const { data, error, response } = await client.GET('/commerce/products/{uuid}/children', {
    params: { path: { uuid: productUuid } },
  })
  if (error) throw toApiError(error, response)
  return normalizeSectionEnvelope(data, 'children', normalizeProductChildItem)
}

export async function fetchProductStockSection(productUuid: string): Promise<SectionEnvelope<VariantStock>> {
  const { data, error, response } = await client.GET('/commerce/products/{uuid}/stock', {
    params: { path: { uuid: productUuid } },
  })
  if (error) throw toApiError(error, response)
  return normalizeSectionEnvelope(data, 'stock', normalizeVariantStock)
}

// ── Query wrappers ───────────────────────────────────────────────────────────
//
// Every hook is keyed via `qk.commerceProductSection(uuid, section)` (Task C1) and disabled for an
// empty uuid, mirroring `useCommerceProduct()`'s own guard in `commerceCatalog.ts`.

export function useProductCategories(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceProductSection(toValue(uuid), 'categories'),
    query: () => fetchProductCategoriesSection(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

export function useProductTags(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceProductSection(toValue(uuid), 'tags'),
    query: () => fetchProductTagsSection(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

export function useProductAttributes(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceProductSection(toValue(uuid), 'attributes'),
    query: () => fetchProductAttributesSection(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

export function useProductMedia(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceProductSection(toValue(uuid), 'media'),
    query: () => fetchProductMediaSection(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

export function useProductChildren(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceProductSection(toValue(uuid), 'children'),
    query: () => fetchProductChildrenSection(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

export function useProductStock(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceProductSection(toValue(uuid), 'stock'),
    query: () => fetchProductStockSection(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

// Re-exported so callers driving every section generically (e.g. the revision coordinator, Task
// C3) don't need their own copy of the closed vocabulary.
export { COMMERCE_PRODUCT_SECTIONS }
