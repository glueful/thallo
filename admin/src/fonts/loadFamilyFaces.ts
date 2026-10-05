// Specimens for the Typeface control (block typeface plan Task 9): one mechanism, the FontFace API.
// Each uploaded face registers its own FontFace with the descriptors read from its file; the theme's
// files register under `thallo-theme-face`. No stylesheet is linked in the admin.
//
// A registry keyed `<id>:<blob_uuid>` (and `theme:<url>`) remembers each face's revision — its URL
// and descriptors. On every read, a key whose revision changed (a file read again) has its old face
// removed and a new one added; a key that is gone (a removed face or family, a theme's dropped
// files, a switched theme) is removed; an unchanged key is left alone. So the admin never needs a
// reload to show what the library holds now. A file that fails to load leaves its option in the
// stack's fallback.
import type { FontFamily, ThemeFace } from '@/queries/fontLibrary'
import { THEME_FACE_FAMILY } from './stacks'

interface Registered {
  revision: string
  face: FontFace
}

const registry = new Map<string, Registered>()

interface Wanted {
  family: string
  url: string
  descriptors: FontFaceDescriptors & { weight: string; style: string; display: FontDisplay }
}

function wanted(
  families: FontFamily[],
  themeFace: ThemeFace | null | undefined,
): Map<string, Wanted> {
  const out = new Map<string, Wanted>()
  for (const family of families) {
    if (family.kind !== 'uploaded' || family.removed) continue
    for (const face of family.faces) {
      if (face.url === '') continue
      const weight = face.unknown
        ? '100 900'
        : face.weight_min === face.weight_max
          ? `${face.weight_min}`
          : `${face.weight_min} ${face.weight_max}`
      out.set(`${family.id}:${face.blob_uuid ?? face.url}`, {
        family: `thallo-font-${family.id}`,
        url: face.url,
        descriptors: {
          weight,
          style: !face.unknown && face.italic ? 'italic' : 'normal',
          display: 'swap',
        },
      })
    }
  }
  for (const file of themeFace?.files ?? []) {
    out.set(`theme:${file.url}`, {
      family: THEME_FACE_FAMILY,
      url: file.url,
      descriptors: { weight: file.weight, style: file.style, display: 'swap' },
    })
  }
  return out
}

/** Brings `document.fonts` in line with the library and the theme's face, as just read. */
export async function loadFamilyFaces(
  families: FontFamily[],
  themeFace: ThemeFace | null | undefined,
): Promise<void> {
  if (typeof FontFace === 'undefined' || typeof document === 'undefined' || !document.fonts) return
  const next = wanted(families, themeFace)
  const loads: Promise<unknown>[] = []
  for (const [key, entry] of registry) {
    if (!next.has(key)) {
      document.fonts.delete(entry.face)
      registry.delete(key)
    }
  }
  for (const [key, want] of next) {
    const revision = JSON.stringify([want.url, want.descriptors.weight, want.descriptors.style])
    const existing = registry.get(key)
    if (existing?.revision === revision) continue
    if (existing) document.fonts.delete(existing.face)
    const face = new FontFace(
      want.family,
      `url("${want.url.replace(/"/g, '%22')}") format("woff2")`,
      want.descriptors,
    )
    document.fonts.add(face)
    registry.set(key, { revision, face })
    loads.push(face.load().catch(() => undefined))
  }
  await Promise.all(loads)
}

/** Forget every registered face: the next read registers the library afresh. */
export function resetFamilyFaces(): void {
  registry.clear()
}
