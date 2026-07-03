import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'
import { qk } from './keys'

// The field types a content-type schema field may take (mirrors the backend enum).
export const FIELD_TYPES = [
  'string',
  'text',
  'number',
  'boolean',
  'datetime',
  'enum',
  'reference',
  'asset',
  'json',
  'blocks',
] as const
export type FieldType = (typeof FIELD_TYPES)[number]

/** One field definition within a content type's schema. */
export interface ContentTypeField {
  name: string
  type: FieldType
  required: boolean
  localized: boolean
  filterable: boolean
  filter_type?: string | null
  /** Allowed values when `type === 'enum'`. */
  enum?: string[]
  /** Editing widget for `text` fields: 'plain' (textarea) or 'rich' (editor). Undefined otherwise. */
  format?: 'plain' | 'rich'
  /** Target content-type slug for a `reference` field; undefined for every other type. */
  reference_type?: string | null
  /** Whether a reference/asset field holds an ordered array of uuids. */
  multiple?: boolean
  /** Max array length for a multiple field; null = unbounded. */
  max_items?: number | null
  /** Target field used to resolve slug filter values for a reference field (default `slug`). */
  reference_slug_field?: string | null
  /** Picker-only block-type allowlist for a `blocks` field ([] / absent = all active). */
  block_types?: string[]
}

/** A content type with its full field schema. */
export interface ContentType {
  id?: number
  uuid?: string
  slug: string
  name: string
  description: string | null
  cache_ttl: number | null
  public_delivery: boolean
  status?: string
  schema: ContentTypeField[]
  schema_version?: number
  created_at?: string
  updated_at: string | null
}

/** The payload accepted when creating a content type. */
export interface ContentTypeInput {
  slug: string
  name: string
  description?: string | null
  cache_ttl?: number | null
  public_delivery?: boolean
  schema?: ContentTypeField[]
}

// The OpenAPI spec types every content-type field as optional, so normalize into our stricter
// shapes (with defaults) at the boundary — pages then work with fully-populated objects.
function normalizeField(
  f: Partial<ContentTypeField> & { type?: string; format?: string },
): ContentTypeField {
  const type = (f.type ?? 'string') as FieldType
  return {
    name: f.name ?? '',
    type,
    required: f.required ?? false,
    localized: f.localized ?? false,
    filterable: f.filterable ?? false,
    filter_type: f.filter_type ?? null,
    enum: f.enum ?? [],
    // `format` is only meaningful for text; default existing/absent text fields to 'plain'.
    format: type === 'text' ? ((f.format as 'plain' | 'rich' | undefined) ?? 'plain') : undefined,
    multiple: f.multiple ?? false,
    max_items: f.max_items ?? null,
    reference_slug_field: f.reference_slug_field ?? undefined,
  }
}

function normalizeContentType(ct: Record<string, unknown>): ContentType {
  const raw = ct as Partial<ContentType> & { schema?: Array<Partial<ContentTypeField>> }
  return {
    id: raw.id,
    uuid: raw.uuid,
    slug: raw.slug ?? '',
    name: raw.name ?? '',
    description: raw.description ?? null,
    cache_ttl: raw.cache_ttl ?? null,
    public_delivery: raw.public_delivery ?? false,
    status: raw.status,
    schema: (raw.schema ?? []).map(normalizeField),
    schema_version: raw.schema_version,
    created_at: raw.created_at,
    updated_at: raw.updated_at ?? null,
  }
}

/**
 * Fetches the admin content-type list. Extracted from the query wrapper so it can be unit-tested
 * without a Pinia Colada runtime.
 */
export async function fetchContentTypes() {
  const { data, error, response } = await client.GET('/content-types')
  if (error) throw toApiError(error, response)
  return data?.data?.content_types ?? []
}

export function useContentTypes() {
  return useQuery({
    key: qk.contentTypes(),
    query: fetchContentTypes,
  })
}

export async function fetchContentType(slug: string): Promise<ContentType> {
  const { data, error, response } = await client.GET('/content-types/{slug}', {
    params: { path: { slug } },
  })
  if (error) throw toApiError(error, response)
  const ct = data?.data?.content_type
  if (!ct) throw new ApiError('Content type not found.', response?.status ?? 404, {}, data)
  return normalizeContentType(ct as Record<string, unknown>)
}

export function useContentType(slug: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.contentType(toValue(slug)),
    query: () => fetchContentType(toValue(slug)),
  })
}

export async function createContentType(input: ContentTypeInput) {
  const { data, error, response } = await client.POST('/content-types', { body: input })
  if (error) throw toApiError(error, response)
  return data?.data?.content_type
}

export async function updateContentTypeSchema(slug: string, schema: ContentTypeField[]) {
  const { data, error, response } = await client.PATCH('/content-types/{slug}/schema', {
    params: { path: { slug } },
    body: { schema },
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function deleteContentType(slug: string) {
  const { error, response } = await client.DELETE('/content-types/{slug}', {
    params: { path: { slug } },
  })
  if (error) throw toApiError(error, response)
}

/** Validate field rows before save; returns a human-readable message when invalid, else null. */
export function validateContentTypeFields(fields: ContentTypeField[]): string | null {
  const seen = new Set<string>()
  for (const field of fields) {
    const name = field.name.trim()
    if (name === '') return 'Every field needs a name.'
    if (seen.has(name)) return `Duplicate field name “${name}”.`
    seen.add(name)
    if (field.type === 'enum' && (field.enum ?? []).length === 0) {
      return `Enum field “${name}” needs at least one allowed value.`
    }
  }
  return null
}

/** Create/update/delete mutations, each invalidating the list (and the affected type). */
export function useContentTypeMutations() {
  const cache = useQueryCache()

  const create = useMutation({
    mutation: (input: ContentTypeInput) => createContentType(input),
    onSettled() {
      cache.invalidateQueries({ key: qk.contentTypes() })
    },
  })

  const updateSchema = useMutation({
    mutation: (vars: { slug: string; schema: ContentTypeField[] }) =>
      updateContentTypeSchema(vars.slug, vars.schema),
    onSettled(_data, _error, vars) {
      cache.invalidateQueries({ key: qk.contentType(vars.slug) })
      cache.invalidateQueries({ key: qk.contentTypes() })
    },
  })

  const remove = useMutation({
    mutation: (slug: string) => deleteContentType(slug),
    onSettled() {
      cache.invalidateQueries({ key: qk.contentTypes() })
    },
  })

  return { create, updateSchema, remove }
}
