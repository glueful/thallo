import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { core } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'
import { runtimeConfig } from '@/runtime/config'

export interface UploadedAsset {
  url: string
  thumb_url?: string | null
  mime_type?: string
  blob_uuid?: string
  visibility?: string
  [k: string]: unknown
}

// A blob's `url` from the upload response is a bare storage path, not servable. Public blobs serve
// directly from the framework's `/{version}/blobs/{uuid}` route (no auth) — build that URL so it can
// go straight into an <img>. The version prefix is derived from apiBase (`/v1/admin` → `/v1`).
export function blobDisplayUrl(uuid: string): string {
  const prefix = runtimeConfig.apiBase.replace(/\/admin\/?$/, '')
  return `${prefix}/blobs/${uuid}`
}

// Blob upload is a framework core route. It goes through the typed `core` openapi-fetch client so the
// path is taken straight from the generated spec (POST /v1/blobs) and the bearer is attached by the
// client middleware — no hand-written path that can drift, same pattern as the auth endpoints. The
// one wrinkle is multipart: openapi-fetch JSON-encodes bodies by default, so a `bodySerializer`
// turns the payload into FormData (which also makes openapi-fetch omit the JSON Content-Type so the
// browser sets the multipart boundary).
export async function uploadBlob(
  file: File,
  opts: { visibility?: 'public' | 'private'; pathPrefix?: string } = {},
): Promise<UploadedAsset> {
  const payload: Record<string, string | Blob> = { file }
  if (opts.visibility) payload.visibility = opts.visibility
  if (opts.pathPrefix) payload.path_prefix = opts.pathPrefix

  const { data, error, response } = await core.POST('/v1/blobs', {
    body: payload as never,
    bodySerializer: (body) => {
      const form = new FormData()
      for (const [key, value] of Object.entries(body as Record<string, string | Blob>)) {
        form.append(key, value)
      }
      return form
    },
  })
  if (error) throw toApiError(error, response)
  return ((data as { data?: UploadedAsset } | undefined)?.data ?? {}) as UploadedAsset
}

export function useUploadMedia() {
  return useMutation({
    mutation: (vars: { file: File; visibility?: 'public' | 'private' }) =>
      uploadBlob(vars.file, { visibility: vars.visibility }),
  })
}

// ── Media library (Thallo\Core\Http\Controllers\MediaAdminController, /v1/admin/media) ──────────────────

export interface MediaItem {
  uuid: string
  name: string
  mime_type: string
  size: number
  url: string
  /** Ready-to-render URL: public direct, private signed (works in <img>). */
  display_url: string
  /** Small on-the-fly resized variant (width 160) for list/grid display. */
  thumb_url: string
  visibility: 'public' | 'private'
  created_at?: string | null
}

export interface MediaDetail extends MediaItem {
  updated_at?: string | null
  created_by?: string | null
  alt_text?: string | null
  caption?: string | null
  tags: string[]
  usage_count: number
}

export interface MediaUsageEntry {
  entry_uuid: string
  type?: string | null
  status?: string | null
}

export interface MediaPage {
  media: MediaItem[]
  total: number
  current_page: number
  per_page: number
}

const mediaBase = () => `${runtimeConfig.apiBase}/media`

export async function fetchMedia(params: {
  page: number
  perPage: number
  type?: string
  q?: string
}): Promise<MediaPage> {
  const qs = new URLSearchParams({ page: String(params.page), per_page: String(params.perPage) })
  if (params.type) qs.set('type', params.type)
  if (params.q) qs.set('q', params.q)
  const json = await authFetch(`${mediaBase()}?${qs.toString()}`)
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    media: Array.isArray(d.media) ? (d.media as MediaItem[]) : [],
    total: Number(d.total ?? 0),
    current_page: Number(d.current_page ?? params.page),
    per_page: Number(d.per_page ?? params.perPage),
  }
}

export function useMediaList(
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  type: MaybeRefOrGetter<string | undefined>,
  q: MaybeRefOrGetter<string | undefined>,
) {
  return useQuery({
    key: () => ['media', toValue(page), toValue(type) ?? '', toValue(q) ?? ''],
    query: () =>
      fetchMedia({
        page: toValue(page),
        perPage: toValue(perPage),
        type: toValue(type) || undefined,
        q: toValue(q) || undefined,
      }),
  })
}

export async function fetchMediaItem(uuid: string): Promise<MediaDetail | null> {
  try {
    const json = await authFetch(`${mediaBase()}/${encodeURIComponent(uuid)}`)
    const d = (json.data ?? json) as Record<string, unknown>
    return (d.media ?? d) as MediaDetail
  } catch (e) {
    // A deleted/missing item (e.g. opening a stale ?item= URL, or a refetch after delete) should
    // show "nothing selected", not error the page or reject the delete mutation's invalidation.
    if (e instanceof ApiError && e.status === 404) return null
    throw e
  }
}

export function useMediaItem(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['media', 'detail', toValue(uuid) ?? ''],
    query: () => fetchMediaItem(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

export async function fetchMediaUsage(uuid: string): Promise<MediaUsageEntry[]> {
  const json = await authFetch(`${mediaBase()}/${encodeURIComponent(uuid)}/usage`)
  const d = (json.data ?? json) as Record<string, unknown>
  return Array.isArray(d.usage) ? (d.usage as MediaUsageEntry[]) : []
}

export function useMediaUsage(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['media', 'usage', toValue(uuid) ?? ''],
    query: () => fetchMediaUsage(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

export interface UpdateMediaInput {
  title?: string
  alt_text?: string
  caption?: string
  tags?: string[]
}

export function useMediaMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: ['media'] })

  const update = useMutation({
    mutation: (vars: { uuid: string; input: UpdateMediaInput }) =>
      authFetch(`${mediaBase()}/${encodeURIComponent(vars.uuid)}`, {
        method: 'PATCH',
        body: JSON.stringify(vars.input),
      }),
    onSettled: invalidate,
  })
  const remove = useMutation({
    mutation: (uuid: string) =>
      authFetch(`${mediaBase()}/${encodeURIComponent(uuid)}`, { method: 'DELETE' }),
    onSettled: invalidate,
  })
  const optimize = useMutation({
    mutation: (uuid: string) =>
      authFetch(`${mediaBase()}/${encodeURIComponent(uuid)}/optimize`, { method: 'POST' }),
    onSettled: invalidate,
  })

  return { update, remove, optimize }
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB']
  let value = bytes / 1024
  let i = 0
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024
    i++
  }
  return `${value.toFixed(value < 10 ? 2 : 1)} ${units[i]}`
}

export function isImage(mime: string): boolean {
  return mime.startsWith('image/')
}
