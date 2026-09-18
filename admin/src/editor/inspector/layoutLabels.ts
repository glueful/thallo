// What the layout properties are called, and how direction and wrap are drawn (container-layout
// spec §5). One source for the block's Layout tab and the style class's (§12.2): two editors must
// not name one property two ways. Names and icons only — which controls show, and when, is each
// tab's own business.

export const LAYOUT_LABELS: Record<string, string> = {
  width: 'Width',
  'alignment.self': 'Placement',
  'layout.min_height': 'Minimum height',
  'layout.overflow': 'Overflow',
  'layout.display': 'Layout',
  'layout.content_width': 'Content width',
  'layout.gutter': 'Gutter',
  'layout.direction': 'Direction',
  'layout.wrap': 'Wrap',
  'alignment.content': 'Distribute',
  'layout.align_items': 'Align',
  'layout.columns': 'Columns',
  'layout.span': 'Span',
  'layout.basis': 'Basis',
  'layout.grow': 'Grow',
  'layout.shrink': 'Shrink',
  'layout.align_self': 'Align self',
}

export const DIRECTION_ICONS: Record<string, string> = {
  row: 'i-lucide-arrow-right',
  column: 'i-lucide-arrow-down',
  'row-reverse': 'i-lucide-arrow-left',
  'column-reverse': 'i-lucide-arrow-up',
}
export const WRAP_ICONS: Record<string, string> = {
  nowrap: 'i-lucide-move-horizontal',
  wrap: 'i-lucide-corner-down-left',
}
export const WRAP_LABELS: Record<string, string> = {
  nowrap: 'One line',
  wrap: 'Wrap onto more lines',
}
