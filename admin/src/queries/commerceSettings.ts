import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'
import { qk } from './keys'

// Task 15a (admin-commerce-area plan, slice 3): shipping zone/location/method settings
// (`AdminShippingZoneController` / `Glueful\Extensions\Commerce\Http\Admin\AdminShippingZoneController`,
// backed by `ShippingZoneService`). Only the Settings shell's "Shipping zones" tab lands here — Task
// 15b (shipping classes) and Task 15c (tax rates) extend this same file later.
//
// Task 15b adds shipping-class CRUD (`AdminShippingClassController` /
// `Glueful\Extensions\Commerce\Http\Admin\AdminShippingClassController`, backed by
// `ShippingClassService`) — see the "Shipping classes" section further down.

/** `ShippingZoneService::LOCATION_KINDS` — a zone location's `kind`. */
export const SHIPPING_LOCATION_KINDS = ['country', 'state', 'postcode_pattern'] as const
export type ShippingLocationKind = (typeof SHIPPING_LOCATION_KINDS)[number]

/** `ShippingZoneService::METHOD_KINDS` — a shipping method's `kind`, immutable once created. */
export const SHIPPING_METHOD_KINDS = ['flat', 'free_over', 'per_class_table'] as const
export type ShippingMethodKind = (typeof SHIPPING_METHOD_KINDS)[number]

/** A `commerce_shipping_zone_locations` row (`009_CreateCommerceShippingTaxTables.php`) — `value` is
 * always normalized (trimmed + uppercased) by the server: an ISO-3166 alpha-2 country, a
 * `COUNTRY:REGION` state, or an exact-or-single-trailing-wildcard postcode pattern, depending on
 * `kind` ({@see ShippingZoneService::normalizeLocations()}). */
export interface CommerceShippingLocation {
  kind: string
  value: string
}

/** A `commerce_shipping_methods` row, decoded (`ShippingZoneService::decodeMethod()`): `config`'s
 * shape depends on `kind` — `flat`: `{amount}`; `free_over`: `{amount, free_over}`;
 * `per_class_table`: `{default_amount, classes: {slug: amount}}`. Every amount in `config` is a
 * genuine non-negative INTEGER minor-unit currency value (`ShippingZoneService::nonNegativeInt()`)
 * — format with `useMoney`, never `Number()`; this is real money, unlike a discount's bps `value`.
 * `warnings` is only ever non-empty immediately after a create/update whose `per_class_table`
 * config named an unknown shipping-class slug (WARN-but-allow, never rejected) — a plain read
 * (list/show) always normalizes it to `[]`, since the server only computes it inline during the
 * mutation that just ran. */
export interface CommerceShippingMethod {
  uuid: string
  zone_uuid: string
  kind: string
  label: string
  config: Record<string, unknown>
  position: number
  enabled: boolean
  warnings: string[]
  created_at: string | null
  updated_at: string | null
}

/** A `commerce_shipping_zones` row, embedding its full `locations`/`methods` projection
 * (`ShippingZoneService::show()`/`fullProjection()`) — there is no separate detail route in this
 * task, so the list query alone carries everything ZonesPanel needs, mirroring Discounts'
 * single-page-domain precedent. `shadows_later_zones` is a LIST-CONTEXT-ONLY derived field (true
 * when this zone has zero locations — an "everywhere" zone — AND at least one other zone follows
 * it in the same position/uuid evaluation order, `ShippingZoneService::fullProjection()`'s own
 * docblock): `show()`'s single-zone projection omits it entirely, so it normalizes to `false` there
 * rather than fabricating a claim this endpoint never made. `tenant_uuid` is deliberately excluded,
 * same principle as every other projection in this codebase. */
export interface CommerceShippingZone {
  uuid: string
  name: string
  position: number
  revision: number
  locations: CommerceShippingLocation[]
  methods: CommerceShippingMethod[]
  shadows_later_zones: boolean
  created_at: string | null
  updated_at: string | null
}

export interface ShippingZoneListFilters {
  page?: number
  perPage?: number
}

export interface ShippingZoneListPage {
  zones: CommerceShippingZone[]
  total: number
  current_page: number
  per_page: number
}

/** The exact `CreateZoneData` request body shape (`Http/DTOs/CreateZoneData.php`). */
export interface CreateZoneInput {
  name: string
  position?: number | null
}

/** The exact `UpdateZoneData` request body shape (`Http/DTOs/UpdateZoneData.php`) — the controller
 * reads the raw JSON body directly and applies ONLY the keys present, so an omitted key leaves its
 * current value unchanged server-side (mirrors `UpdateDiscountInput`'s identical note). */
export interface UpdateZoneInput {
  name?: string | null
  position?: number | null
}

/** The exact `CreateMethodData` request body shape (`Http/DTOs/CreateMethodData.php`). */
export interface CreateMethodInput {
  kind: ShippingMethodKind
  label: string
  config: Record<string, unknown>
  position?: number | null
  enabled?: boolean | null
}

/** The exact `UpdateMethodData` request body shape (`Http/DTOs/UpdateMethodData.php`) — `kind` is
 * immutable after creation and therefore absent here; only present keys are applied server-side. */
export interface UpdateMethodInput {
  label?: string | null
  config?: Record<string, unknown> | null
  position?: number | null
  enabled?: boolean | null
}

// The admin envelopes are doc-only in the OpenAPI schema (see commerceDiscounts.ts's identical
// note), so normalize the raw JSON into the stricter hand-written shapes above at the boundary.

function normalizeLocation(raw: Record<string, unknown>): CommerceShippingLocation {
  return {
    kind: String(raw.kind ?? ''),
    value: String(raw.value ?? ''),
  }
}

function asConfigRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

function normalizeMethod(raw: Record<string, unknown>): CommerceShippingMethod {
  const warnings = raw.warnings
  return {
    uuid: String(raw.uuid ?? ''),
    zone_uuid: String(raw.zone_uuid ?? ''),
    kind: String(raw.kind ?? 'flat'),
    label: String(raw.label ?? ''),
    config: asConfigRecord(raw.config),
    position: typeof raw.position === 'number' ? raw.position : 0,
    enabled: raw.enabled === true,
    warnings: Array.isArray(warnings) ? warnings.map((w) => String(w)) : [],
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

function normalizeZone(raw: Record<string, unknown>): CommerceShippingZone {
  const locations = Array.isArray(raw.locations) ? raw.locations : []
  const methods = Array.isArray(raw.methods) ? raw.methods : []
  return {
    uuid: String(raw.uuid ?? ''),
    name: String(raw.name ?? ''),
    position: typeof raw.position === 'number' ? raw.position : 0,
    revision: typeof raw.revision === 'number' ? raw.revision : 0,
    locations: locations.map((l) => normalizeLocation(l as Record<string, unknown>)),
    methods: methods.map((m) => normalizeMethod(m as Record<string, unknown>)),
    shadows_later_zones: raw.shadows_later_zones === true,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

// ── Zone fetchers ────────────────────────────────────────────────────────────

/** `GET /commerce/shipping/zones` — `ShippingZoneListQuery`'s exact param set is `{page, per_page}`;
 * zones carry no `q` filter (mirrors `OrderListFilters`'s identical note). Rows are returned in the
 * server's own (position ASC, uuid ASC) evaluation order — the SAME order shipping-quote matching
 * walks the zone list in — and this fetcher never re-sorts them. */
export async function fetchShippingZones(
  filters: ShippingZoneListFilters = {},
): Promise<ShippingZoneListPage> {
  const { data, error, response } = await client.GET('/commerce/shipping/zones', {
    params: { query: { page: filters.page, per_page: filters.perPage } },
  })
  if (error) throw toApiError(error, response)
  const body = data as
    | { data?: unknown[]; current_page?: number; per_page?: number; total?: number }
    | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return {
    zones: rows.map((z) => normalizeZone(z as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

/** `GET /commerce/shipping/zones/{uuid}` — wired up for parity with the endpoint contract, same as
 * `fetchDiscount()`/`fetchRefund()` elsewhere in this codebase; ZonesPanel reads everything it
 * needs off the list projection instead (no detail route in this task). */
export async function fetchShippingZone(uuid: string): Promise<CommerceShippingZone> {
  const { data, error, response } = await client.GET('/commerce/shipping/zones/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Shipping zone not found.', response?.status ?? 404, {}, data)
  return normalizeZone(raw as Record<string, unknown>)
}

export async function createShippingZone(input: CreateZoneInput): Promise<CommerceShippingZone> {
  const { data, error, response } = await client.POST('/commerce/shipping/zones', {
    body: { name: input.name, position: input.position ?? null } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeZone((raw ?? {}) as Record<string, unknown>)
}

export async function updateShippingZone(
  uuid: string,
  input: UpdateZoneInput,
): Promise<CommerceShippingZone> {
  const { data, error, response } = await client.PATCH('/commerce/shipping/zones/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeZone((raw ?? {}) as Record<string, unknown>)
}

/** `DELETE /commerce/shipping/zones/{uuid}` — 204 on success; cascades server-side (deletes every
 * method and location belonging to the zone before deleting the zone row itself). */
export async function deleteShippingZone(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/shipping/zones/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

/** `PUT /commerce/shipping/zones/{uuid}/locations` — whole-set replace; an empty list is valid and
 * meaningful (the zone becomes "everywhere", spec §3). Returns the fresh, server-normalized
 * location set (uppercased values), not an echo of what was submitted. */
export async function setShippingZoneLocations(
  zoneUuid: string,
  locations: CommerceShippingLocation[],
): Promise<CommerceShippingLocation[]> {
  const { data, error, response } = await client.PUT('/commerce/shipping/zones/{uuid}/locations', {
    params: { path: { uuid: zoneUuid } },
    body: { locations } as never,
  })
  if (error) throw toApiError(error, response)
  const body = data as { data?: unknown[] } | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return rows.map((l) => normalizeLocation(l as Record<string, unknown>))
}

// ── Method fetchers (nested under a zone) ────────────────────────────────────

/** `GET /commerce/shipping/zones/{uuid}/methods` — wired up for parity with the endpoint contract;
 * ZonesPanel reads methods off the zone's own embedded `methods` array instead (same "no extra
 * round trip needed" reasoning as `fetchShippingZone()` above). */
export async function fetchShippingZoneMethods(zoneUuid: string): Promise<CommerceShippingMethod[]> {
  const { data, error, response } = await client.GET('/commerce/shipping/zones/{uuid}/methods', {
    params: { path: { uuid: zoneUuid } },
  })
  if (error) throw toApiError(error, response)
  const body = data as { data?: unknown[] } | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return rows.map((m) => normalizeMethod(m as Record<string, unknown>))
}

export async function createShippingMethod(
  zoneUuid: string,
  input: CreateMethodInput,
): Promise<CommerceShippingMethod> {
  const { data, error, response } = await client.POST('/commerce/shipping/zones/{uuid}/methods', {
    params: { path: { uuid: zoneUuid } },
    body: {
      kind: input.kind,
      label: input.label,
      config: input.config,
      position: input.position ?? null,
      enabled: input.enabled ?? null,
    } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeMethod((raw ?? {}) as Record<string, unknown>)
}

/** `GET /commerce/shipping/methods/{uuid}` — wired up for parity, same as `fetchShippingZone()`. */
export async function fetchShippingMethod(uuid: string): Promise<CommerceShippingMethod> {
  const { data, error, response } = await client.GET('/commerce/shipping/methods/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Shipping method not found.', response?.status ?? 404, {}, data)
  return normalizeMethod(raw as Record<string, unknown>)
}

export async function updateShippingMethod(
  uuid: string,
  input: UpdateMethodInput,
): Promise<CommerceShippingMethod> {
  const { data, error, response } = await client.PATCH('/commerce/shipping/methods/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeMethod((raw ?? {}) as Record<string, unknown>)
}

export async function deleteShippingMethod(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/shipping/methods/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

// ── Query wrappers ───────────────────────────────────────────────────────────

export function useCommerceShippingZones(filters: MaybeRefOrGetter<ShippingZoneListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [...qk.commerceShippingZones(), f.page ?? 1, f.perPage ?? 24]
    },
    query: () => fetchShippingZones(toValue(filters)),
  })
}

export function useCommerceShippingZone(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceShippingZone(toValue(uuid)),
    query: () => fetchShippingZone(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

/**
 * `createZone` invalidates ONLY the list (a brand-new zone has no existing detail-key consumer
 * yet — mirrors `useCommerceDiscountMutations()`'s create precedent exactly). `updateZone`/
 * `deleteZone` invalidate BOTH the zone detail key and the list. `setLocations` is zone-scoped (the
 * locations live on the zone's own list/detail projection), so it invalidates the same pair.
 * `createMethod`/`updateMethod`/`deleteMethod` are doubly zone-scoped: the zone-scoped methods key
 * AND the owning zone's own detail+list keys (its embedded `methods` array is now stale too).
 */
export function useCommerceShippingZoneMutations() {
  const cache = useQueryCache()
  const invalidateList = () => cache.invalidateQueries({ key: qk.commerceShippingZones() })
  const invalidateZone = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceShippingZone(uuid) })
    invalidateList()
  }
  const invalidateMethods = (zoneUuid: string) => {
    cache.invalidateQueries({ key: qk.commerceShippingZoneMethods(zoneUuid) })
    invalidateZone(zoneUuid)
  }

  return {
    createZone: useMutation({
      mutation: (input: CreateZoneInput) => createShippingZone(input),
      onSettled: invalidateList,
    }),
    updateZone: useMutation({
      mutation: (vars: { uuid: string; input: UpdateZoneInput }) =>
        updateShippingZone(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateZone(vars.uuid),
    }),
    deleteZone: useMutation({
      mutation: (uuid: string) => deleteShippingZone(uuid),
      onSettled: (_d, _e, uuid) => invalidateZone(uuid),
    }),
    setLocations: useMutation({
      mutation: (vars: { zoneUuid: string; locations: CommerceShippingLocation[] }) =>
        setShippingZoneLocations(vars.zoneUuid, vars.locations),
      onSettled: (_d, _e, vars) => invalidateZone(vars.zoneUuid),
    }),
    createMethod: useMutation({
      mutation: (vars: { zoneUuid: string; input: CreateMethodInput }) =>
        createShippingMethod(vars.zoneUuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateMethods(vars.zoneUuid),
    }),
    updateMethod: useMutation({
      mutation: (vars: { uuid: string; zoneUuid: string; input: UpdateMethodInput }) =>
        updateShippingMethod(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateMethods(vars.zoneUuid),
    }),
    deleteMethod: useMutation({
      mutation: (vars: { uuid: string; zoneUuid: string }) => deleteShippingMethod(vars.uuid),
      onSettled: (_d, _e, vars) => invalidateMethods(vars.zoneUuid),
    }),
  }
}

// ── Task 15b: shipping classes ────────────────────────────────────────────────

/**
 * A `commerce_shipping_classes` row (`009_CreateCommerceShippingTaxTables.php`). `slug` is an
 * immutable pricing identity once created (`ShippingClassService`'s own class docblock):
 * `per_class_table` shipping-method config references classes BY SLUG while variants retain the
 * class UUID, so a slug rename would silently change live shipping charges. Only `name` is ever
 * PATCHable — a `slug` key present ANYWHERE in the PATCH payload is rejected 422 by the server,
 * loudly, not silently dropped. `tenant_uuid` is deliberately excluded, same principle as every
 * other projection in this file.
 *
 * There is no `description` column on this table, nor a `description` field on
 * `CreateShippingClassData`/`UpdateShippingClassData` — verified directly against the DTOs and
 * migration 009. This projection (and the panel built on it) omits it rather than inventing a
 * field the API doesn't accept.
 */
export interface CommerceShippingClass {
  uuid: string
  slug: string
  name: string
  revision: number
  created_at: string | null
  updated_at: string | null
}

export interface ShippingClassListFilters {
  /** Case-insensitive literal substring match on class name or slug (`ShippingClassListQuery::$q`). */
  q?: string
  page?: number
  perPage?: number
}

export interface ShippingClassListPage {
  classes: CommerceShippingClass[]
  total: number
  current_page: number
  per_page: number
}

/** The exact `CreateShippingClassData` request body shape (`Http/DTOs/CreateShippingClassData.php`). */
export interface CreateShippingClassInput {
  slug: string
  name: string
}

/** The exact `UpdateShippingClassData` request body shape (`Http/DTOs/UpdateShippingClassData.php`)
 * — `slug` is deliberately absent: it's immutable after creation, and `ShippingClassService::update()`
 * rejects a `slug` key present ANYWHERE in the raw PATCH payload with 422 rather than silently
 * ignoring it (unlike `UpdateMethodInput`'s silently-dropped-`kind` precedent above — the spec here
 * calls for a loud rejection instead, mirroring the zone-method docblock's own distinction). */
export interface UpdateShippingClassInput {
  name?: string | null
}

function normalizeClass(raw: Record<string, unknown>): CommerceShippingClass {
  return {
    uuid: String(raw.uuid ?? ''),
    slug: String(raw.slug ?? ''),
    name: String(raw.name ?? ''),
    revision: typeof raw.revision === 'number' ? raw.revision : 0,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

/** `GET /commerce/shipping/classes` — `ShippingClassListQuery`'s exact param set is
 * `{q, page, per_page}`. */
export async function fetchShippingClasses(
  filters: ShippingClassListFilters = {},
): Promise<ShippingClassListPage> {
  const { data, error, response } = await client.GET('/commerce/shipping/classes', {
    params: { query: { q: filters.q || undefined, page: filters.page, per_page: filters.perPage } },
  })
  if (error) throw toApiError(error, response)
  const body = data as
    | { data?: unknown[]; current_page?: number; per_page?: number; total?: number }
    | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return {
    classes: rows.map((c) => normalizeClass(c as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

/** `GET /commerce/shipping/classes/{uuid}` — wired up for parity with the endpoint contract, same
 * as `fetchShippingZone()` above; ClassesPanel edits directly off the rows already held by the
 * list (no detail-route consumer in this task). */
export async function fetchShippingClass(uuid: string): Promise<CommerceShippingClass> {
  const { data, error, response } = await client.GET('/commerce/shipping/classes/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Shipping class not found.', response?.status ?? 404, {}, data)
  return normalizeClass(raw as Record<string, unknown>)
}

export async function createShippingClass(
  input: CreateShippingClassInput,
): Promise<CommerceShippingClass> {
  const { data, error, response } = await client.POST('/commerce/shipping/classes', {
    body: { slug: input.slug, name: input.name } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeClass((raw ?? {}) as Record<string, unknown>)
}

export async function updateShippingClass(
  uuid: string,
  input: UpdateShippingClassInput,
): Promise<CommerceShippingClass> {
  const { data, error, response } = await client.PATCH('/commerce/shipping/classes/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeClass((raw ?? {}) as Record<string, unknown>)
}

/** `DELETE /commerce/shipping/classes/{uuid}` — 204 on success; 409
 * (`ShippingClassInUseException`, "…still assigned to one or more variants. Detach it first.")
 * while any variant currently references the class — that message surfaces verbatim via
 * `toApiError`, never replaced (mirrors `deleteDiscount()`'s identical redeemed-discount note in
 * commerceDiscounts.ts). */
export async function deleteShippingClass(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/shipping/classes/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

// ── Query wrappers ───────────────────────────────────────────────────────────

export function useCommerceShippingClasses(filters: MaybeRefOrGetter<ShippingClassListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [...qk.commerceShippingClasses(), f.q ?? '', f.page ?? 1, f.perPage ?? 24]
    },
    query: () => fetchShippingClasses(toValue(filters)),
  })
}

export function useCommerceShippingClass(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceShippingClass(toValue(uuid)),
    query: () => fetchShippingClass(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

/**
 * `createClass` invalidates ONLY the classes list — a brand-new class has no existing detail-key
 * consumer yet (mirrors `useCommerceShippingZoneMutations()`'s `createZone` precedent exactly).
 * `updateClass`/`deleteClass` invalidate BOTH the class detail key and the list.
 */
export function useCommerceShippingClassMutations() {
  const cache = useQueryCache()
  const invalidateList = () => cache.invalidateQueries({ key: qk.commerceShippingClasses() })
  const invalidateClass = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceShippingClass(uuid) })
    invalidateList()
  }

  return {
    createClass: useMutation({
      mutation: (input: CreateShippingClassInput) => createShippingClass(input),
      onSettled: invalidateList,
    }),
    updateClass: useMutation({
      mutation: (vars: { uuid: string; input: UpdateShippingClassInput }) =>
        updateShippingClass(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateClass(vars.uuid),
    }),
    deleteClass: useMutation({
      mutation: (uuid: string) => deleteShippingClass(uuid),
      onSettled: (_d, _e, uuid) => invalidateClass(uuid),
    }),
  }
}

// ── Task 15c: tax rates ────────────────────────────────────────────────────────

/**
 * A `commerce_tax_rates` row (`009_CreateCommerceShippingTaxTables.php`, `TaxRateService`'s own
 * class docblock). `rate_bps` is a genuine basis-points integer, 0..10000 inclusive (10000 =
 * 100%) — verified against `TaxRateService::normalizeBps()` and `DbTaxCalculator::applyRate()`'s
 * `intdiv($amount * $bps + 5000, 10000)`. This is EXACTLY the same convention as a `percentage`
 * discount's `value` (commerceDiscounts.ts's own docblock: `value / 100` is the percent) — the
 * query layer stores/echoes the raw integer untouched; percent<->bps conversion belongs to
 * TaxRatesPanel.vue, mirroring DiscountForm.vue's identical division of labor.
 *
 * `state`, when present, is `COUNTRY:REGION` whose country prefix equals this row's OWN
 * `country` (`TaxRateService::normalizeState()`) — unlike a shipping-zone location's `state`,
 * which only needs to match the incoming ADDRESS's country. `postcode_pattern` follows the same
 * exact-or-single-trailing-wildcard grammar as a zone location's postcode. `class` is the open
 * vocabulary rule, defaulting server-side to `'standard'` when omitted. Unlike shipping zones,
 * a tax rate has no cross-table reference to guard at delete time — DELETE is unconditional once
 * claimed. `tenant_uuid` is deliberately excluded, same principle as every other projection here.
 */
export interface CommerceTaxRate {
  uuid: string
  country: string
  state: string | null
  postcode_pattern: string | null
  rate_bps: number
  label: string
  priority: number
  shipping_taxable: boolean
  class: string
  revision: number
  created_at: string | null
  updated_at: string | null
}

export interface TaxRateListFilters {
  /** ISO-3166 alpha-2 country code (`TaxRateListQuery::$country`). */
  country?: string
  /** Tax class slug (`TaxRateListQuery::$class`). */
  class?: string
  page?: number
  perPage?: number
}

export interface TaxRateListPage {
  rates: CommerceTaxRate[]
  total: number
  current_page: number
  per_page: number
}

/** The exact `CreateTaxRateData` request body shape (`Http/DTOs/CreateTaxRateData.php`). */
export interface CreateTaxRateInput {
  country: string
  state?: string | null
  postcode_pattern?: string | null
  rate_bps: number
  label: string
  priority?: number | null
  shipping_taxable?: boolean | null
  class?: string | null
}

/** The exact `UpdateTaxRateData` request body shape (`Http/DTOs/UpdateTaxRateData.php`) — the
 * controller reads the raw JSON body directly, so only present keys are applied server-side
 * (mirrors `UpdateZoneInput`'s identical note); an explicit `null` for `state`/`postcode_pattern`
 * clears the column rather than being ignored. */
export interface UpdateTaxRateInput {
  country?: string | null
  state?: string | null
  postcode_pattern?: string | null
  rate_bps?: number | null
  label?: string | null
  priority?: number | null
  shipping_taxable?: boolean | null
  class?: string | null
}

function normalizeTaxRate(raw: Record<string, unknown>): CommerceTaxRate {
  return {
    uuid: String(raw.uuid ?? ''),
    country: String(raw.country ?? ''),
    state: typeof raw.state === 'string' ? raw.state : null,
    postcode_pattern: typeof raw.postcode_pattern === 'string' ? raw.postcode_pattern : null,
    rate_bps: typeof raw.rate_bps === 'number' ? raw.rate_bps : 0,
    label: String(raw.label ?? ''),
    priority: typeof raw.priority === 'number' ? raw.priority : 0,
    shipping_taxable: raw.shipping_taxable === true,
    class: String(raw.class ?? 'standard'),
    revision: typeof raw.revision === 'number' ? raw.revision : 0,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

/** `GET /commerce/tax/rates` — `TaxRateListQuery`'s exact param set is
 * `{country, class, page, per_page}`. Rows are returned in the server's own
 * (country ASC, priority ASC, uuid ASC) order — the SAME order `DbTaxCalculator`'s rate
 * selection walks the candidate list in — and this fetcher never re-sorts them. */
export async function fetchTaxRates(filters: TaxRateListFilters = {}): Promise<TaxRateListPage> {
  const { data, error, response } = await client.GET('/commerce/tax/rates', {
    params: {
      query: {
        country: filters.country || undefined,
        class: filters.class || undefined,
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
    rates: rows.map((r) => normalizeTaxRate(r as Record<string, unknown>)),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

/** `GET /commerce/tax/rates/{uuid}` — wired up for parity with the endpoint contract, same as
 * `fetchShippingClass()` above; TaxRatesPanel edits directly off the rows already held by the
 * list (no detail-route consumer in this task). */
export async function fetchTaxRate(uuid: string): Promise<CommerceTaxRate> {
  const { data, error, response } = await client.GET('/commerce/tax/rates/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Tax rate not found.', response?.status ?? 404, {}, data)
  return normalizeTaxRate(raw as Record<string, unknown>)
}

export async function createTaxRate(input: CreateTaxRateInput): Promise<CommerceTaxRate> {
  const { data, error, response } = await client.POST('/commerce/tax/rates', {
    body: {
      country: input.country,
      state: input.state ?? null,
      postcode_pattern: input.postcode_pattern ?? null,
      rate_bps: input.rate_bps,
      label: input.label,
      priority: input.priority ?? null,
      shipping_taxable: input.shipping_taxable ?? null,
      class: input.class ?? null,
    } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeTaxRate((raw ?? {}) as Record<string, unknown>)
}

export async function updateTaxRate(
  uuid: string,
  input: UpdateTaxRateInput,
): Promise<CommerceTaxRate> {
  const { data, error, response } = await client.PATCH('/commerce/tax/rates/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeTaxRate((raw ?? {}) as Record<string, unknown>)
}

/** `DELETE /commerce/tax/rates/{uuid}` — 204 on success; unconditional once claimed (a tax rate
 * has no cross-table reference to guard, unlike a shipping class's referenced-by-variant 409). */
export async function deleteTaxRate(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/tax/rates/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

// ── Query wrappers ───────────────────────────────────────────────────────────

export function useCommerceTaxRates(filters: MaybeRefOrGetter<TaxRateListFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [...qk.commerceTaxRates(), f.country ?? '', f.class ?? '', f.page ?? 1, f.perPage ?? 24]
    },
    query: () => fetchTaxRates(toValue(filters)),
  })
}

export function useCommerceTaxRate(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceTaxRate(toValue(uuid)),
    query: () => fetchTaxRate(toValue(uuid)),
    enabled: () => !!toValue(uuid),
  })
}

/**
 * `createRate` invalidates ONLY the rates list — a brand-new rate has no existing detail-key
 * consumer yet (mirrors `useCommerceShippingClassMutations()`'s `createClass` precedent exactly).
 * `updateRate`/`deleteRate` invalidate BOTH the rate detail key and the list.
 */
export function useCommerceTaxRateMutations() {
  const cache = useQueryCache()
  const invalidateList = () => cache.invalidateQueries({ key: qk.commerceTaxRates() })
  const invalidateRate = (uuid: string) => {
    cache.invalidateQueries({ key: qk.commerceTaxRate(uuid) })
    invalidateList()
  }

  return {
    createRate: useMutation({
      mutation: (input: CreateTaxRateInput) => createTaxRate(input),
      onSettled: invalidateList,
    }),
    updateRate: useMutation({
      mutation: (vars: { uuid: string; input: UpdateTaxRateInput }) => updateTaxRate(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidateRate(vars.uuid),
    }),
    deleteRate: useMutation({
      mutation: (uuid: string) => deleteTaxRate(uuid),
      onSettled: (_d, _e, uuid) => invalidateRate(uuid),
    }),
  }
}

// ── Store settings (store-settings spec §3.4) ───────────────────────────────────────────────────
// `GET/PUT /commerce/settings` — the runtime-editable store settings, backed by thallo's
// `settings` table through Commerce's settings seam. Keys EQUAL config keys; a `null` field in
// the PUT body clears the override (the row is DELETED server-side, so the config/env default
// shows through).

export interface StoreSettingEntry {
  value: string | number
  default: string | number
  overridden: boolean
}

export interface StoreSettings {
  settings: Record<string, StoreSettingEntry>
  /** True once ORDERS exist — recorded money history; the currency lock's predicate. */
  currency_locked: boolean
  /** Priced products exist (no lock, but a currency change reinterprets their numbers). */
  has_priced_products: boolean
}

export const STORE_SETTING_KEYS = [
  'commerce.currency',
  'commerce.tax.flat_rate_bps',
  'commerce.orders.number_format',
  'commerce.orders.expiry_minutes',
  'commerce.cart.ttl_days',
  'commerce.reports.low_stock_threshold',
  'commerce.downloads.url_ttl',
  'commerce.seller.name',
  'commerce.seller.address',
  'commerce.seller.tax_id',
] as const

export type StoreSettingKey = (typeof STORE_SETTING_KEYS)[number]

/** PUT body: value = set (validated server-side), null = clear back to default, absent = untouched. */
export type StoreSettingsSave = Partial<Record<StoreSettingKey, string | number | null>>

function normalizeStoreEntry(raw: unknown): StoreSettingEntry {
  const entry = (raw ?? {}) as Partial<StoreSettingEntry>
  return {
    value: entry.value ?? '',
    default: entry.default ?? '',
    overridden: entry.overridden === true,
  }
}

function normalizeStoreSettings(raw: unknown): StoreSettings {
  const data = (raw ?? {}) as {
    settings?: Record<string, unknown>
    currency_locked?: boolean
    has_priced_products?: boolean
  }
  const settings: Record<string, StoreSettingEntry> = {}
  for (const key of STORE_SETTING_KEYS) {
    settings[key] = normalizeStoreEntry(data.settings?.[key])
  }
  return {
    settings,
    currency_locked: data.currency_locked === true,
    has_priced_products: data.has_priced_products === true,
  }
}

export async function fetchStoreSettings(): Promise<StoreSettings> {
  const { data, error, response } = await client.GET('/commerce/settings')
  if (error) throw toApiError(error, response)
  return normalizeStoreSettings((data as { data?: unknown } | undefined)?.data)
}

export async function saveStoreSettings(body: StoreSettingsSave): Promise<StoreSettings> {
  const { data, error, response } = await client.PUT('/commerce/settings', { body: body as never })
  if (error) throw toApiError(error, response)
  return normalizeStoreSettings((data as { data?: unknown } | undefined)?.data)
}

export function useStoreSettings() {
  return useQuery({ key: qk.commerceStoreSettings(), query: fetchStoreSettings })
}

export function useSaveStoreSettings() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (body: StoreSettingsSave) => saveStoreSettings(body),
    onSettled: async () => {
      // Currency / low-stock-threshold feed /commerce/meta too — refresh both.
      await cache.invalidateQueries({ key: qk.commerceStoreSettings() })
      await cache.invalidateQueries({ key: qk.commerceMeta() })
    },
  })
}

// ── Payments settings (store-settings spec §3.6) ────────────────────────────────────────────────
// `GET/PUT /commerce/payments` — gateway configuration through payvia's settings seam. Secrets
// are WRITE-ONLY: the server stores them encrypted and only ever reports `{set, source}`
// booleans back; the PUT body carries a new value, `null` to clear (row deleted — env fallback
// shows through), or omits the field to leave the stored value untouched.

/** A secret field's reportable state — the server never sends key material. */
export interface SecretFieldState {
  set: boolean
  source: 'settings' | 'env' | null
}

export interface PaymentsSettingEntry {
  value: boolean
  default: boolean
  overridden: boolean
}

export interface PaymentsGatewayRow {
  id: string
  enabled: PaymentsSettingEntry
  secret_key: SecretFieldState
  webhook_secret: SecretFieldState
  default: boolean
  /** Absolute URL for the gateway dashboard's webhook field; null when no origin is resolvable. */
  webhook_url: string | null
}

export interface PaymentsSettings {
  /** `manual` = no gateway extension configures gateways (operator mark-paid is the flow). */
  mode: 'gateway' | 'manual'
  default_gateway: { value: string | null; default: string; overridden: boolean }
  gateways: PaymentsGatewayRow[]
}

/** PUT body — every field optional; secrets: value = replace, null = clear, absent = keep. */
export interface PaymentsSettingsSave {
  default_gateway?: string | null
  gateways?: Record<
    string,
    { enabled?: boolean; secret_key?: string | null; webhook_secret?: string | null }
  >
}

function normalizeSecretState(raw: unknown): SecretFieldState {
  const data = (raw ?? {}) as { set?: unknown; source?: unknown }
  return {
    set: data.set === true,
    source: data.source === 'settings' || data.source === 'env' ? data.source : null,
  }
}

function normalizePaymentsSettings(raw: unknown): PaymentsSettings {
  const data = (raw ?? {}) as {
    mode?: unknown
    default_gateway?: unknown
    gateways?: unknown
  }
  const dg = (data.default_gateway ?? {}) as { value?: unknown; default?: unknown; overridden?: unknown }
  const gateways = Array.isArray(data.gateways) ? data.gateways : []
  return {
    mode: data.mode === 'gateway' ? 'gateway' : 'manual',
    default_gateway: {
      value: typeof dg.value === 'string' ? dg.value : null,
      default: typeof dg.default === 'string' ? dg.default : '',
      overridden: dg.overridden === true,
    },
    gateways: gateways.map((g) => {
      const row = (g ?? {}) as Record<string, unknown>
      const enabled = (row.enabled ?? {}) as { value?: unknown; default?: unknown; overridden?: unknown }
      return {
        id: String(row.id ?? ''),
        enabled: {
          value: enabled.value === true,
          default: enabled.default === true,
          overridden: enabled.overridden === true,
        },
        secret_key: normalizeSecretState(row.secret_key),
        webhook_secret: normalizeSecretState(row.webhook_secret),
        default: row.default === true,
        webhook_url: typeof row.webhook_url === 'string' ? row.webhook_url : null,
      }
    }),
  }
}

export async function fetchPaymentsSettings(): Promise<PaymentsSettings> {
  const { data, error, response } = await client.GET('/commerce/payments')
  if (error) throw toApiError(error, response)
  return normalizePaymentsSettings((data as { data?: unknown } | undefined)?.data)
}

export async function savePaymentsSettings(body: PaymentsSettingsSave): Promise<PaymentsSettings> {
  const { data, error, response } = await client.PUT('/commerce/payments', { body: body as never })
  if (error) throw toApiError(error, response)
  return normalizePaymentsSettings((data as { data?: unknown } | undefined)?.data)
}

export function usePaymentsSettings() {
  return useQuery({ key: qk.commercePaymentsSettings(), query: fetchPaymentsSettings })
}

export function useSavePaymentsSettings() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (body: PaymentsSettingsSave) => savePaymentsSettings(body),
    onSettled: async () => {
      await cache.invalidateQueries({ key: qk.commercePaymentsSettings() })
    },
  })
}

// ── Order-email switches (store-settings spec §4.2 follow-up) ───────────────────────────────────
// `GET/PUT /commerce/emails` — whether each of the four buyer order emails sends at all.
// Template CONTENT (subject/body/test/reset) stays with the email-notification extension's
// /email/templates API; the Emails tab reuses it filtered to this pack's owner.

export interface EmailTemplateSwitch {
  /** Short name — the PUT vocabulary: order_confirmation | order_paid | order_fulfilled | order_canceled. */
  template: string
  /** The registry key (`commerce.{template}`) — joins to /email/templates rows. */
  key: string
  enabled: PaymentsSettingEntry
}

export interface CommerceEmailSettings {
  templates: EmailTemplateSwitch[]
  /** True when commerce's own dormant mailer (`commerce.email.enabled`) is switched on — then
   * thallo's sender stands down entirely and these switches govern nothing. */
  commerce_mailer_active: boolean
}

/** PUT body: bool = set the switch, null = clear back to the default, absent = untouched. */
export interface CommerceEmailSettingsSave {
  templates: Record<string, boolean | null>
}

function normalizeEmailSettings(raw: unknown): CommerceEmailSettings {
  const data = (raw ?? {}) as { templates?: unknown; commerce_mailer_active?: unknown }
  const templates = Array.isArray(data.templates) ? data.templates : []
  return {
    templates: templates.map((t) => {
      const row = (t ?? {}) as Record<string, unknown>
      const enabled = (row.enabled ?? {}) as { value?: unknown; default?: unknown; overridden?: unknown }
      return {
        template: String(row.template ?? ''),
        key: String(row.key ?? ''),
        enabled: {
          value: enabled.value === true,
          default: enabled.default === true,
          overridden: enabled.overridden === true,
        },
      }
    }),
    commerce_mailer_active: data.commerce_mailer_active === true,
  }
}

export async function fetchCommerceEmailSettings(): Promise<CommerceEmailSettings> {
  const { data, error, response } = await client.GET('/commerce/emails')
  if (error) throw toApiError(error, response)
  return normalizeEmailSettings((data as { data?: unknown } | undefined)?.data)
}

export async function saveCommerceEmailSettings(
  body: CommerceEmailSettingsSave,
): Promise<CommerceEmailSettings> {
  const { data, error, response } = await client.PUT('/commerce/emails', { body: body as never })
  if (error) throw toApiError(error, response)
  return normalizeEmailSettings((data as { data?: unknown } | undefined)?.data)
}

export function useCommerceEmailSettings() {
  return useQuery({ key: qk.commerceEmailSettings(), query: fetchCommerceEmailSettings })
}

export function useSaveCommerceEmailSettings() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (body: CommerceEmailSettingsSave) => saveCommerceEmailSettings(body),
    onSettled: async () => {
      await cache.invalidateQueries({ key: qk.commerceEmailSettings() })
    },
  })
}
