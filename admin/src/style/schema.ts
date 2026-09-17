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
  // alignment.content distributes a container's children, so it carries the distribution
  // keywords too (container-layout spec §3.2); text and self place one box.
  ...['text', 'content', 'self'].map((k) =>
    choice(
      `alignment.${k}`,
      'alignment',
      true,
      k === 'content'
        ? ['start', 'center', 'end', 'between', 'around', 'evenly']
        : ['start', 'center', 'end'],
    ),
  ),
  token('typography.size', 'typography', true, 'typography.size'),
  choice('typography.weight', 'typography', true, ['regular', 'medium', 'semibold', 'bold']),
  choice('visibility', 'visibility', true, ['visible', 'hidden']),
  token('shadow', 'shadow', true, 'shadow'),
  token('radius', 'radius', false, 'radius'),
  ...['surface', 'text', 'border'].map((p) => token(`colors.${p}`, 'colors', false, 'color')),
  choice('border.width', 'border', false, ['none', 'thin', 'thick']),
  choice('border.style', 'border', false, ['solid', 'dashed']),
  // Layout (container-layout spec §3.2): parent properties on a container's `inner` target, the
  // band's own on its `root`, and the `layout.item` group on any block that sizes itself inside
  // a flex or grid parent.
  choice('layout.display', 'layout', true, ['block', 'flex', 'grid']),
  choice('layout.direction', 'layout', true, ['row', 'column', 'row-reverse', 'column-reverse']),
  choice('layout.wrap', 'layout', true, ['nowrap', 'wrap']),
  choice('layout.align_items', 'layout', true, ['start', 'center', 'end', 'stretch', 'baseline']),
  choice('layout.columns', 'layout', true, [
    '1',
    '2',
    '3',
    '4',
    '6',
    '12',
    '1-2',
    '2-1',
    '1-3',
    '3-1',
    '1-2-1',
    '1-1-2',
    '2-1-1',
  ]),
  token('layout.gap.column', 'layout', true, 'spacing'),
  token('layout.gap.row', 'layout', true, 'spacing'),
  token('layout.content_width', 'layout', true, 'width'),
  token('layout.gutter', 'layout', true, 'spacing'),
  choice('layout.min_height', 'layout', true, ['auto', 'half', 'screen']),
  // One value for every width: an overflow that changed with the viewport would hide content at
  // one size and not another.
  choice('layout.overflow', 'layout', false, ['visible', 'hidden', 'auto']),
  choice('layout.span', 'layout.item', true, [
    '1',
    '2',
    '3',
    '4',
    '5',
    '6',
    '7',
    '8',
    '9',
    '10',
    '11',
    '12',
    'full',
  ]),
  choice('layout.basis', 'layout.item', true, ['auto', '1/4', '1/3', '1/2', '2/3', '3/4', 'full']),
  choice('layout.grow', 'layout.item', true, ['0', '1']),
  choice('layout.shrink', 'layout.item', true, ['0', '1']),
  choice('layout.align_self', 'layout.item', true, ['start', 'center', 'end', 'stretch']),
]

const BY_PATH = new Map(PROPERTIES.map((p) => [p.path, p]))

export function propertyDefinition(path: string): PropertyDefinition | null {
  return BY_PATH.get(path) ?? null
}

export function styleProperties(): readonly PropertyDefinition[] {
  return PROPERTIES
}
