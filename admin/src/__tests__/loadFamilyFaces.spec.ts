import { beforeEach, describe, expect, it, vi } from 'vitest'
import { loadFamilyFaces, resetFamilyFaces } from '@/fonts/loadFamilyFaces'
import { fontLibrary } from './helpers/fontLibraryFixture'
import type { FontFamily } from '@/queries/fontLibrary'

// Specimens load through the FontFace API, one face per file with its own descriptors (block
// typeface plan Task 9): registered once per revision, replaced when a file is read again, and
// dropped when a face, a family or the theme's files go away — without reloading the admin.
class FakeFontFace {
  family: string
  source: string
  descriptors: Record<string, string>
  constructor(family: string, source: string, descriptors: Record<string, string>) {
    this.family = family
    this.source = source
    this.descriptors = descriptors
  }
  load = vi.fn(() => Promise.resolve(this))
}

let added: FakeFontFace[]
let deleted: FakeFontFace[]

beforeEach(() => {
  resetFamilyFaces()
  added = []
  deleted = []
  vi.stubGlobal('FontFace', FakeFontFace)
  Object.defineProperty(document, 'fonts', {
    configurable: true,
    value: {
      add: (f: FakeFontFace) => added.push(f),
      delete: (f: FakeFontFace) => deleted.push(f),
    },
  })
})

const uploaded = (lib = fontLibrary()) => lib.families.filter((f) => f.kind === 'uploaded')

describe('loadFamilyFaces', () => {
  it('registers one FontFace per face, with the descriptors read from its file', async () => {
    const lib = fontLibrary()
    await loadFamilyFaces(lib.families, lib.theme_face)
    const brand = added.filter((f) => f.family === 'thallo-font-Ab3dE5fG7hJ9')
    expect(brand.map((f) => [f.source, f.descriptors])).toEqual([
      [
        'url("/api/v1/blobs/blobreg00001") format("woff2")',
        { weight: '400', style: 'normal', display: 'swap' },
      ],
      [
        'url("/api/v1/blobs/blobbold0001") format("woff2")',
        { weight: '700', style: 'normal', display: 'swap' },
      ],
      [
        'url("/api/v1/blobs/blobital0001") format("woff2")',
        { weight: '400', style: 'italic', display: 'swap' },
      ],
    ])
    const vary = added.find((f) => f.family === 'thallo-font-Vr3dE5fG7hJ9')
    expect(vary?.descriptors.weight).toBe('300 900')
    // An unknown face keeps its compatibility declaration.
    const unknown = added.find((f) => f.family === 'thallo-font-Uk3dE5fG7hJ9')
    expect(unknown?.descriptors).toEqual({ weight: '100 900', style: 'normal', display: 'swap' })
  })

  it('registers the theme’s files under thallo-theme-face', async () => {
    const lib = fontLibrary()
    await loadFamilyFaces(lib.families, lib.theme_face)
    const theme = added.filter((f) => f.family === 'thallo-theme-face')
    expect(theme.map((f) => [f.source, f.descriptors.weight, f.descriptors.style])).toEqual([
      ['url("/theme-assets/fonts/figtree-roman-latin.woff2") format("woff2")', '300 900', 'normal'],
      [
        'url("/theme-assets/fonts/figtree-italic-latin.woff2") format("woff2")',
        '300 900',
        'italic',
      ],
    ])
  })

  it('leaves unchanged faces alone on the next read', async () => {
    const lib = fontLibrary()
    await loadFamilyFaces(lib.families, lib.theme_face)
    const first = added.length
    await loadFamilyFaces(lib.families, lib.theme_face)
    expect(added).toHaveLength(first)
    expect(deleted).toHaveLength(0)
  })

  it('replaces a face whose file was read again', async () => {
    const lib = fontLibrary()
    await loadFamilyFaces(lib.families, lib.theme_face)
    const old = added.find((f) => f.family === 'thallo-font-Uk3dE5fG7hJ9')!
    const reread = fontLibrary()
    const family = reread.families.find((f) => f.id === 'Uk3dE5fG7hJ9') as FontFamily
    family.faces[0] = {
      ...family.faces[0]!,
      weight_min: 500,
      weight_max: 500,
      unknown: false,
      italic: true,
    }
    added = []
    await loadFamilyFaces(reread.families, reread.theme_face)
    expect(deleted).toEqual([old])
    expect(added.map((f) => [f.family, f.descriptors.weight, f.descriptors.style])).toEqual([
      ['thallo-font-Uk3dE5fG7hJ9', '500', 'italic'],
    ])
  })

  it('swaps every theme face when the theme changes, and drops what is gone', async () => {
    const lib = fontLibrary()
    await loadFamilyFaces(lib.families, lib.theme_face)
    const oldTheme = added.filter((f) => f.family === 'thallo-theme-face')
    const vary = added.find((f) => f.family === 'thallo-font-Vr3dE5fG7hJ9')!
    added = []
    const switched = fontLibrary({
      families: fontLibrary().families.filter((f) => f.id !== 'Vr3dE5fG7hJ9'),
      theme_face: {
        declared: true,
        family: 'Other',
        files: [{ url: '/theme-assets/other.woff2', weight: '400', style: 'normal' }],
      },
    })
    await loadFamilyFaces(switched.families, switched.theme_face)
    expect(deleted).toEqual(expect.arrayContaining([...oldTheme, vary]))
    expect(deleted).toHaveLength(3)
    expect(added.map((f) => [f.family, f.source])).toEqual([
      ['thallo-theme-face', 'url("/theme-assets/other.woff2") format("woff2")'],
    ])
    expect(uploaded(switched)).toHaveLength(3)
  })

  it('keeps going when a file fails to load', async () => {
    vi.stubGlobal(
      'FontFace',
      class extends FakeFontFace {
        load = vi.fn(() => Promise.reject(new Error('404')))
      },
    )
    const lib = fontLibrary()
    await expect(loadFamilyFaces(lib.families, lib.theme_face)).resolves.toBeUndefined()
  })
})
