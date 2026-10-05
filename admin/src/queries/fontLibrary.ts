import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { getActivePinia } from 'pinia'
import { shallowRef } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { useAppearanceChanges } from '@/composables/useAppearanceChanges'
import { qk } from './keys'

// The font library (block typeface spec §2, §4): what the Typeface control offers — the built-ins,
// the workspace's uploaded families with the faces read from their files (removed ones flagged, so a
// stored value can be named), and the active theme's face. Any editor may read it; it carries no usage.

export interface FontFaceRow {
  blob_uuid?: string
  url: string
  weight_min: number
  weight_max: number
  italic: boolean
  variable: boolean
  /** A file the reader could not parse: it keeps the compatibility declaration (100–900, normal). */
  unknown: boolean
}

export interface FontFamily {
  id: string
  name: string
  kind: 'builtin' | 'uploaded'
  /** An uploaded family's fallback generic; null for a built-in. */
  fallback: string | null
  removed: boolean
  faces: FontFaceRow[]
}

export interface ThemeFace {
  declared: boolean
  family: string | null
  /** The theme's own files that exist, for the Theme specimen. */
  files: { url: string; weight: string; style: string }[]
}

export interface FontLibraryResult {
  families: FontFamily[]
  theme_face: ThemeFace
  /** Whether the reader may manage the library (only then is Restore offered). */
  can_manage: boolean
}

export async function fetchFontLibrary(): Promise<FontLibraryResult> {
  // TODO(block typeface plan, Task 12): typed once the API reference is regenerated.
  const get = client.GET as unknown as (path: string) => Promise<{
    data?: { data: FontLibraryResult }
    error?: unknown
    response: Response
  }>
  const { data, error, response } = await get('/fonts')
  if (error) throw toApiError(error, response)
  return (data as { data: FontLibraryResult }).data
}

/**
 * The font library, cached under `['fonts']`. Where no query cache exists (a component rendered on
 * its own, outside the app), there is no library: the control offers the built-ins alone.
 */
export function useFontLibrary() {
  if (!getActivePinia()) return { data: shallowRef<FontLibraryResult | undefined>(undefined) }
  return useQuery({ key: qk.fonts(), query: fetchFontLibrary, staleTime: 60_000 })
}

/** One face as the faces line names it: `400`, `400 italic`, `300–900 variable`. */
function faceLabel(face: FontFaceRow): string {
  const weight =
    face.weight_min === face.weight_max
      ? `${face.weight_min}`
      : `${face.weight_min}–${face.weight_max}`
  return `${weight}${face.variable ? ' variable' : ''}${face.italic ? ' italic' : ''}`
}

/**
 * What a family's faces are, in one line: "Faces: 400, 700, 400 italic", "Faces: 300–900 variable",
 * "Unknown faces", "Provided by the visitor's device", or — for Theme — whether the theme supplies it.
 */
export function facesLabel(family: FontFamily, themeFace?: ThemeFace | null): string {
  if (family.kind === 'builtin') {
    if (family.id !== 'theme') return 'Provided by the visitor’s device'
    return themeFace?.declared && themeFace.files.length > 0
      ? 'Supplied by the theme'
      : 'This theme declares no face; Theme uses the system stack'
  }
  if (family.faces.some((f) => f.unknown)) return 'Unknown faces'
  const faces = [...family.faces].sort(
    (a, b) => Number(a.italic) - Number(b.italic) || a.weight_min - b.weight_min,
  )
  return `Faces: ${faces.map(faceLabel).join(', ')}`
}

/**
 * The hundreds a family's files cover — a static face its weight, a variable one its range — or null
 * where nothing can be said: a built-in (the visitor's device decides) or a family with unknown faces.
 */
export function suppliedWeights(family: FontFamily): Set<number> | null {
  if (family.kind === 'builtin' || family.faces.some((f) => f.unknown)) return null
  const weights = new Set<number>()
  for (const face of family.faces) {
    for (let w = 100; w <= 900; w += 100) {
      if (w >= face.weight_min && w <= face.weight_max) weights.add(w)
    }
  }
  return weights
}

// ── Managing the library (content.manage; block typeface plan Task 11) ──────────────────────────

/** Where a family is used, as GET /fonts/{id}/usage answers. */
export interface FontUsage {
  entries: {
    uuid: string
    title: string | null
    locale: string | null
    draft: boolean
    published: boolean
    versions: boolean
  }[]
  regions: string[]
  layouts: { id: string; name: string }[]
  saved_sections: { id: string; name: string }[]
  style_classes: { id: string; name: string }[]
  appearance: { text: boolean; headings: boolean }
}

/** How many places a usage names: each document, region, layout, section, class and role. */
export function usageCount(usage: FontUsage): number {
  return (
    usage.entries.length +
    usage.regions.length +
    usage.layouts.length +
    usage.saved_sections.length +
    usage.style_classes.length +
    Number(usage.appearance.text) +
    Number(usage.appearance.headings)
  )
}

type Untyped = (
  path: string,
  init?: { body?: unknown },
) => Promise<{ data?: { data: unknown }; error?: unknown; response: Response }>

/** One call to a fonts endpoint; the body's `data`. */
async function call<T>(
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
  path: string,
  body?: unknown,
): Promise<T> {
  // TODO(block typeface plan, Task 12): typed once the API reference is regenerated.
  const send = client[method] as unknown as Untyped
  const { data, error, response } = await send(path, body === undefined ? undefined : { body })
  if (error) throw toApiError(error, response)
  return (data as { data: T }).data
}

export function fetchFontUsage(id: string): Promise<FontUsage> {
  return call<FontUsage>('GET', `/fonts/${encodeURIComponent(id)}/usage`)
}

/**
 * The library's changes. Each refreshes the library and, once it succeeds, tells the admin's other
 * tabs, whose open stages reload to show it (useAppearanceChanges).
 */
export function useFontLibraryMutations() {
  const cache = useQueryCache()
  const changes = useAppearanceChanges()
  const invalidate = () => cache.invalidateQueries({ key: qk.fonts() })
  const told = () => changes.notify('fonts')
  const path = (id: string, rest = '') => `/fonts/${encodeURIComponent(id)}${rest}`
  const familyId = (data: { family: { id: string } }) => data.family.id

  return {
    create: useMutation({
      mutation: async (body: { name: string; fallback: string; blob_uuids: string[] }) =>
        familyId(await call('POST', '/fonts', body)),
      onSuccess: told,
      onSettled: invalidate,
    }),
    update: useMutation({
      mutation: async ({ id, ...body }: { id: string; name?: string; fallback?: string }) =>
        familyId(await call('PATCH', path(id), body)),
      onSuccess: told,
      onSettled: invalidate,
    }),
    addFace: useMutation({
      mutation: async ({ id, blob_uuid }: { id: string; blob_uuid: string }) =>
        familyId(await call('POST', path(id, '/faces'), { blob_uuid })),
      onSuccess: told,
      onSettled: invalidate,
    }),
    remove: useMutation({
      mutation: (id: string) => call('DELETE', path(id)),
      onSuccess: told,
      onSettled: invalidate,
    }),
    restore: useMutation({
      mutation: (id: string) => call('POST', path(id, '/restore')),
      onSuccess: told,
      onSettled: invalidate,
    }),
    purge: useMutation({
      mutation: (id: string) => call('DELETE', path(id, '/permanent')),
      onSuccess: told,
      onSettled: invalidate,
    }),
    readAgain: useMutation({
      mutation: (id: string) => call('POST', path(id, '/read-again')),
      onSuccess: told,
      onSettled: invalidate,
    }),
  }
}
