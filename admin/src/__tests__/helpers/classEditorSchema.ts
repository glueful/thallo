// The style schema as the class editor receives it, built from the admin's own mirror of the
// contract (`styleProperties()`), so a property added to the contract reaches these tests without
// an edit here — which is what lets them prove "every Layout path has a control".
import { styleProperties } from '@/style/schema'
import type { StylePropertyRow, StyleSchemaResult } from '@/queries/styleSchema'

export const VOCABULARY = {
  version: 1,
  domains: {
    spacing: ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'],
    width: ['narrow', 'content', 'container', 'full'],
    radius: ['none', 'sm', 'md', 'lg', 'full'],
    color: ['background', 'surface', 'text', 'accent'],
    shadow: ['none', 'sm', 'md', 'lg'],
  },
  values: { 'spacing.lg': 'var(--space-4)', 'radius.md': '6px' },
}

export function classEditorSchema(extra: StyleSchemaResult['properties'] = []): StyleSchemaResult {
  return {
    version: 3,
    breakpoints: { base: 0, md: 768, lg: 1024 },
    properties: [
      ...styleProperties().map(
        (def): StylePropertyRow => ({
          path: def.path,
          group: def.group,
          kinds: (def.kinds as StylePropertyRow['kinds'] | undefined) ?? [
            def.tokenDomain !== null ? 'token' : 'choice',
            'reset',
          ],
          responsive: def.responsive,
          token_domain: def.tokenDomain,
          choices: def.choices,
        }),
      ),
      ...extra,
    ],
    advanced: [],
    vocabulary: VOCABULARY,
  }
}

type PaletteSlot = NonNullable<StyleSchemaResult['palette']>['slots'][string]

/**
 * The style schema's palette as the server builds it (custom palette spec §5.2): three configured
 * brand colours, a light swatch per colour name and every colour name's label. Each override merges
 * into one slot.
 */
export function paletteFixture(
  overrides: Partial<Record<'brand-1' | 'brand-2' | 'brand-3', Partial<PaletteSlot>>> = {},
): NonNullable<StyleSchemaResult['palette']> {
  const base: Record<string, PaletteSlot> = {
    'brand-1': {
      name: 'Gold dark',
      hex: '#8a6a2a',
      state: 'configured',
      reserved: false,
      replacing: null,
    },
    'brand-2': {
      name: 'Rose',
      hex: '#c98a8a',
      state: 'configured',
      reserved: false,
      replacing: null,
    },
    'brand-3': {
      name: 'Ink',
      hex: '#111111',
      state: 'configured',
      reserved: false,
      replacing: null,
    },
  }
  const slots: Record<string, PaletteSlot> = {}
  const labels: Record<string, string> = {
    'color.background': 'Background',
    'color.surface': 'Surface',
    'color.surface-2': 'Surface 2',
    'color.text': 'Text',
    'color.muted': 'Muted',
    'color.line': 'Line',
    'color.accent': 'Accent',
    'color.accent-contrast': 'Accent — text',
    'color.transparent': 'Transparent',
    'color.white': 'White',
    'color.black': 'Black',
  }
  for (const key of ['brand-1', 'brand-2', 'brand-3'] as const) {
    slots[key] = { ...base[key]!, ...overrides[key] }
    const name =
      slots[key]!.state === 'unset'
        ? `Brand ${key.slice(-1)}`
        : (slots[key]!.name ?? `Brand ${key.slice(-1)}`)
    labels[`color.${key}`] = name
    labels[`color.${key}-contrast`] = `${name} — text`
  }
  return {
    slots,
    swatches: {
      'color.background': '#ffffff',
      'color.surface': '#f6f7f9',
      'color.surface-2': '#eef0f4',
      'color.text': '#0f172a',
      'color.muted': '#64748b',
      'color.line': '#e2e8f0',
      'color.accent': '#2563eb',
      'color.accent-contrast': '#ffffff',
      'color.white': '#ffffff',
      'color.black': '#000000',
    },
    labels,
    color_mode: true,
    generation: 1,
    replacements: { after: 1, through: 1, records: [] },
  }
}
