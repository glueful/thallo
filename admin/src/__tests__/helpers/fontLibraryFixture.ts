// The font library as GET /fonts answers it: the built-ins, uploaded families of every kind the
// Typeface control describes (static, variable, unknown faces, removed), and the theme's face.
import type { FontLibraryResult } from '@/queries/fontLibrary'

const builtin = (id: string, name: string) => ({
  id,
  name,
  kind: 'builtin' as const,
  fallback: null,
  removed: false,
  faces: [],
})
const face = (
  blob: string,
  min: number,
  max: number,
  extra: Partial<{ italic: boolean; variable: boolean; unknown: boolean }> = {},
) => ({
  blob_uuid: blob,
  url: `/api/v1/blobs/${blob}`,
  weight_min: min,
  weight_max: max,
  italic: false,
  variable: false,
  unknown: false,
  ...extra,
})

export function fontLibrary(overrides: Partial<FontLibraryResult> = {}): FontLibraryResult {
  return {
    families: [
      builtin('theme', 'Theme'),
      builtin('serif', 'Serif'),
      builtin('humanist', 'Humanist'),
      builtin('geometric', 'Geometric'),
      builtin('slab', 'Slab'),
      builtin('mono', 'Mono'),
      builtin('system', 'System'),
      {
        id: 'Ab3dE5fG7hJ9',
        name: 'Brand',
        kind: 'uploaded',
        fallback: 'serif',
        removed: false,
        faces: [
          face('blobreg00001', 400, 400),
          face('blobbold0001', 700, 700),
          face('blobital0001', 400, 400, { italic: true }),
        ],
      },
      {
        id: 'Vr3dE5fG7hJ9',
        name: 'Vary',
        kind: 'uploaded',
        fallback: 'sans-serif',
        removed: false,
        faces: [face('blobvary0001', 300, 900, { variable: true })],
      },
      {
        id: 'Uk3dE5fG7hJ9',
        name: 'Old upload',
        kind: 'uploaded',
        fallback: 'system-ui',
        removed: false,
        faces: [face('blobunkn0001', 100, 900, { unknown: true })],
      },
      {
        id: 'Rm3dE5fG7hJ9',
        name: 'Gone',
        kind: 'uploaded',
        fallback: 'serif',
        removed: true,
        faces: [face('blobgone0001', 400, 400)],
      },
    ],
    theme_face: {
      declared: true,
      family: 'Figtree',
      files: [
        {
          url: '/theme-assets/fonts/figtree-roman-latin.woff2',
          weight: '300 900',
          style: 'normal',
        },
        {
          url: '/theme-assets/fonts/figtree-italic-latin.woff2',
          weight: '300 900',
          style: 'italic',
        },
      ],
    },
    can_manage: true,
    ...overrides,
  }
}
