import type { PropertyDefinition } from './types'

// Mirror of `Thallo\Contracts\Style\StyleSchema::properties()` (spec §1.3). The fixture contract
// (`resolver-fixtures/v1`) pins that both runtimes agree; the style-schema endpoint is the
// runtime source for controls.
const token = (
  path: string,
  group: string,
  responsive: boolean,
  domain: string,
): PropertyDefinition => ({
  path,
  group,
  responsive,
  tokenDomain: domain,
  choices: null,
})
const choice = (
  path: string,
  group: string,
  responsive: boolean,
  choices: string[],
): PropertyDefinition => ({ path, group, responsive, tokenDomain: null, choices })

const PROPERTIES: PropertyDefinition[] = [
  ...['top', 'right', 'bottom', 'left'].map((s) =>
    token(`spacing.padding.${s}`, 'spacing', true, 'spacing'),
  ),
  ...['top', 'bottom'].map((s) => token(`spacing.margin.${s}`, 'spacing', true, 'spacing')),
  token('width', 'width', true, 'width'),
  ...['text', 'content', 'self'].map((k) =>
    choice(`alignment.${k}`, 'alignment', true, ['start', 'center', 'end']),
  ),
  token('typography.size', 'typography', true, 'typography.size'),
  choice('typography.weight', 'typography', true, ['regular', 'medium', 'semibold', 'bold']),
  choice('visibility', 'visibility', true, ['visible', 'hidden']),
  token('shadow', 'shadow', true, 'shadow'),
  token('radius', 'radius', false, 'radius'),
  ...['surface', 'text', 'border'].map((p) => token(`colors.${p}`, 'colors', false, 'color')),
  choice('border.width', 'border', false, ['none', 'thin', 'thick']),
  choice('border.style', 'border', false, ['solid', 'dashed']),
]

const BY_PATH = new Map(PROPERTIES.map((p) => [p.path, p]))

export function propertyDefinition(path: string): PropertyDefinition | null {
  return BY_PATH.get(path) ?? null
}

export function styleProperties(): readonly PropertyDefinition[] {
  return PROPERTIES
}
