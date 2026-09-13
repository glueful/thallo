import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'
import { qk } from './keys'

// Supported collection field types (mirrors ColumnMapper::SUPPORTED on the backend).
export const COLLECTION_FIELD_TYPES = [
  'collections.string',
  'collections.text',
  'collections.integer',
  'collections.decimal',
  'collections.boolean',
  'collections.date',
  'collections.datetime',
  'collections.json',
  'collections.email',
  'collections.url',
  'collections.enum',
  'collections.relation',
  'collections.asset',
] as const
export type CollectionFieldType = (typeof COLLECTION_FIELD_TYPES)[number]

/** Display label + icon per field type, for the add-field picker and field badges. */
export const COLLECTION_FIELD_TYPE_META: Record<
  CollectionFieldType,
  { label: string; icon: string }
> = {
  'collections.string': { label: 'String', icon: 'i-lucide-type' },
  'collections.text': { label: 'Text', icon: 'i-lucide-align-left' },
  'collections.integer': { label: 'Integer', icon: 'i-lucide-hash' },
  'collections.decimal': { label: 'Decimal', icon: 'i-lucide-calculator' },
  'collections.boolean': { label: 'Boolean', icon: 'i-lucide-toggle-left' },
  'collections.date': { label: 'Date', icon: 'i-lucide-calendar' },
  'collections.datetime': { label: 'Date & time', icon: 'i-lucide-calendar-clock' },
  'collections.json': { label: 'JSON', icon: 'i-lucide-braces' },
  'collections.email': { label: 'Email', icon: 'i-lucide-mail' },
  'collections.url': { label: 'URL', icon: 'i-lucide-link' },
  'collections.enum': { label: 'Enum', icon: 'i-lucide-list' },
  'collections.relation': { label: 'Relation', icon: 'i-lucide-link-2' },
  'collections.asset': { label: 'Asset', icon: 'i-lucide-paperclip' },
}

export interface CollectionField {
  name: string
  type: string
  settings: Record<string, unknown>
}

export type AccessLevel = 'public' | 'scoped'
export interface AccessPolicy {
  read: AccessLevel
  write: AccessLevel
  delete: AccessLevel
}

/** A collection definition with its field schema and access policy. */
export interface Collection {
  uuid?: string
  name: string
  label: string
  tableName?: string
  fields: CollectionField[]
  schemaVersion?: number
  status?: string
  accessPolicy: AccessPolicy
  /** Display order of all column names (system + custom); empty = system-first default. */
  fieldOrder: string[]
}

/** Payload to create a collection. */
export interface CollectionInput {
  name: string
  label?: string
  fields: Array<{ name: string; type: string; settings?: Record<string, unknown> }>
  access?: Partial<AccessPolicy>
  /** Display order of all column names (system + custom). */
  field_order?: string[]
}

export type CollectionRow = Record<string, unknown>
export interface PaginatedRows {
  rows: CollectionRow[]
  total: number
  page: number
  perPage: number
}

// The admin envelopes are doc-only (loosely typed in the OpenAPI schema), so normalize the raw
// JSON into the stricter hand-written shapes above at the boundary.
function normalizeCollection(raw: Record<string, unknown>): Collection {
  const r = raw as Partial<Collection> & {
    fields?: Array<Partial<CollectionField>>
    accessPolicy?: Partial<AccessPolicy>
  }
  const lvl = (v: unknown): AccessLevel => (v === 'public' ? 'public' : 'scoped')
  return {
    uuid: r.uuid,
    name: r.name ?? '',
    label: r.label ?? '',
    tableName: r.tableName,
    fields: (r.fields ?? []).map((f) => ({
      name: f.name ?? '',
      type: f.type ?? 'collections.string',
      settings: (f.settings as Record<string, unknown>) ?? {},
    })),
    schemaVersion: r.schemaVersion,
    status: r.status,
    accessPolicy: {
      read: lvl(r.accessPolicy?.read),
      write: lvl(r.accessPolicy?.write),
      delete: lvl(r.accessPolicy?.delete),
    },
    fieldOrder: Array.isArray(r.fieldOrder) ? r.fieldOrder : [],
  }
}

// ── Definition fetchers ─────────────────────────────────────────────────────

export async function fetchCollections(): Promise<Collection[]> {
  const { data, error, response } = await client.GET('/collections')
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: { collections?: unknown[] } } | undefined)?.data?.collections ?? []
  return raw.map((c) => normalizeCollection(c as Record<string, unknown>))
}

export async function fetchCollection(name: string): Promise<Collection> {
  const { data, error, response } = await client.GET('/collections/{name}', {
    params: { path: { name } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: { collection?: unknown } } | undefined)?.data?.collection
  if (!raw) throw new ApiError('Collection not found.', response?.status ?? 404, {}, data)
  return normalizeCollection(raw as Record<string, unknown>)
}

export async function createCollection(input: CollectionInput) {
  const { data, error, response } = await client.POST('/collections', { body: input as never })
  if (error) throw toApiError(error, response)
  return data
}

export async function addField(
  name: string,
  field: { name: string; type: string; settings?: Record<string, unknown> },
) {
  const { data, error, response } = await client.POST('/collections/{name}/fields', {
    params: { path: { name } },
    body: field as never,
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function dropField(name: string, field: string, confirm?: string) {
  const { data, error, response } = await client.DELETE('/collections/{name}/fields/{field}', {
    params: { path: { name, field } },
    body: (confirm !== undefined ? { confirm } : undefined) as never,
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function addIndex(name: string, body: { field: string; unique?: boolean }) {
  const { data, error, response } = await client.POST('/collections/{name}/indexes', {
    params: { path: { name } },
    body: body as never,
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function dropIndex(name: string, field: string) {
  const { data, error, response } = await client.DELETE('/collections/{name}/indexes/{field}', {
    params: { path: { name, field } },
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function updateAccess(name: string, access: Partial<AccessPolicy>) {
  const { data, error, response } = await client.PATCH('/collections/{name}/access', {
    params: { path: { name } },
    body: access as never,
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function updateFieldOrder(name: string, fieldOrder: string[]) {
  const { data, error, response } = await client.PATCH('/collections/{name}/field-order', {
    params: { path: { name } },
    body: { field_order: fieldOrder } as never,
  })
  if (error) throw toApiError(error, response)
  return data
}

/**
 * Delete every row in a collection (keeps the schema). Guarded like dropField/dropCollection:
 * when the table has rows the backend requires `confirm` to equal the collection name (409
 * otherwise); an empty table waives it.
 */
export async function truncateRows(name: string, confirm?: string) {
  const { data, error, response } = await client.DELETE('/collections/{name}/rows', {
    params: { path: { name } },
    body: (confirm !== undefined ? { confirm } : undefined) as never,
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function dropCollection(name: string, confirm?: string) {
  const { data, error, response } = await client.DELETE('/collections/{name}', {
    params: { path: { name } },
    body: (confirm !== undefined ? { confirm } : undefined) as never,
  })
  if (error) throw toApiError(error, response)
  return data
}

// ── Row fetchers ────────────────────────────────────────────────────────────

export async function fetchRows(
  name: string,
  query: { page?: number; perPage?: number } = {},
): Promise<PaginatedRows> {
  const q: Record<string, string> = {}
  if (query.page !== undefined) q.page = String(query.page)
  if (query.perPage !== undefined) q.perPage = String(query.perPage)
  const { data, error, response } = await client.GET('/collections/{name}/rows', {
    params: { path: { name }, query: q as never },
  })
  if (error) throw toApiError(error, response)
  const body = data as
    | { data?: unknown[]; meta?: { total?: number; page?: number; per_page?: number } }
    | undefined
  return {
    rows: (body?.data ?? []).map((r) => r as CollectionRow),
    total: body?.meta?.total ?? 0,
    page: body?.meta?.page ?? 1,
    perPage: body?.meta?.per_page ?? 0,
  }
}

export async function createRow(name: string, row: CollectionRow) {
  const { data, error, response } = await client.POST('/collections/{name}/rows', {
    params: { path: { name } },
    body: row as never,
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function updateRow(name: string, uuid: string, row: CollectionRow) {
  const { data, error, response } = await client.PATCH('/collections/{name}/rows/{uuid}', {
    params: { path: { name, uuid } },
    body: row as never,
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function deleteRow(name: string, uuid: string) {
  const { error, response } = await client.DELETE('/collections/{name}/rows/{uuid}', {
    params: { path: { name, uuid } },
  })
  if (error) throw toApiError(error, response)
}

// ── Query/mutation wrappers ──────────────────────────────────────────────────

export function useCollections() {
  return useQuery({ key: qk.collections(), query: fetchCollections })
}

export function useCollection(name: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.collection(toValue(name)),
    query: () => fetchCollection(toValue(name)),
  })
}

export function useCollectionRows(
  name: MaybeRefOrGetter<string>,
  page?: MaybeRefOrGetter<number>,
  perPage?: MaybeRefOrGetter<number>,
) {
  return useQuery({
    key: () => [...qk.collectionRows(toValue(name)), toValue(page) ?? 1, toValue(perPage) ?? 20],
    query: () => fetchRows(toValue(name), { page: toValue(page), perPage: toValue(perPage) }),
  })
}

/** Schema mutations; each invalidates the affected collection and the list. */
export function useCollectionMutations() {
  const cache = useQueryCache()
  const invalidate = (name?: string) => {
    if (name !== undefined) cache.invalidateQueries({ key: qk.collection(name) })
    cache.invalidateQueries({ key: qk.collections() })
  }

  return {
    create: useMutation({
      mutation: (input: CollectionInput) => createCollection(input),
      onSettled: () => invalidate(),
    }),
    addField: useMutation({
      mutation: (vars: {
        name: string
        field: { name: string; type: string; settings?: Record<string, unknown> }
      }) => addField(vars.name, vars.field),
      onSettled: (_d, _e, vars) => invalidate(vars.name),
    }),
    dropField: useMutation({
      mutation: (vars: { name: string; field: string; confirm?: string }) =>
        dropField(vars.name, vars.field, vars.confirm),
      onSettled: (_d, _e, vars) => invalidate(vars.name),
    }),
    addIndex: useMutation({
      mutation: (vars: { name: string; field: string; unique?: boolean }) =>
        addIndex(vars.name, { field: vars.field, unique: vars.unique }),
      onSettled: (_d, _e, vars) => invalidate(vars.name),
    }),
    dropIndex: useMutation({
      mutation: (vars: { name: string; field: string }) => dropIndex(vars.name, vars.field),
      onSettled: (_d, _e, vars) => invalidate(vars.name),
    }),
    updateAccess: useMutation({
      mutation: (vars: { name: string; access: Partial<AccessPolicy> }) =>
        updateAccess(vars.name, vars.access),
      onSettled: (_d, _e, vars) => invalidate(vars.name),
    }),
    updateFieldOrder: useMutation({
      mutation: (vars: { name: string; order: string[] }) =>
        updateFieldOrder(vars.name, vars.order),
      onSettled: (_d, _e, vars) => invalidate(vars.name),
    }),
    truncate: useMutation({
      mutation: (vars: { name: string; confirm?: string }) => truncateRows(vars.name, vars.confirm),
      onSettled: (_d, _e, vars) => {
        invalidate(vars.name)
        cache.invalidateQueries({ key: qk.collectionRows(vars.name) })
      },
    }),
    remove: useMutation({
      mutation: (vars: { name: string; confirm?: string }) =>
        dropCollection(vars.name, vars.confirm),
      onSettled: () => invalidate(),
    }),
  }
}

/** Row mutations for one collection; each invalidates that collection's rows. */
export function useCollectionRowMutations(name: MaybeRefOrGetter<string>) {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qk.collectionRows(toValue(name)) })

  return {
    create: useMutation({
      mutation: (row: CollectionRow) => createRow(toValue(name), row),
      onSettled: invalidate,
    }),
    update: useMutation({
      mutation: (vars: { uuid: string; row: CollectionRow }) =>
        updateRow(toValue(name), vars.uuid, vars.row),
      onSettled: invalidate,
    }),
    remove: useMutation({
      mutation: (uuid: string) => deleteRow(toValue(name), uuid),
      onSettled: invalidate,
    }),
  }
}
