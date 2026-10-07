// A Feature's Style tab (settings versions 16 and 17): the marker's colour, background and size sit
// in the Marker section beside its corners and shadow; the space between the marker and the text, and
// where the marker sits against the text, sit in Spacing.
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'
import { CHOICE_LABELS } from '@/editor/inspector/choiceLabels'
import { propertyDefinition } from '@/style/schema'

const schema = classEditorSchema()
const feature = {
  uuid: 'feature',
  slug: 'feature',
  label: 'Feature',
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: ['spacing', 'radius', 'colors', 'typography', 'marker', 'feature'],
  style_targets: null,
  flags: null,
  starter_content: null,
} as BlockType

function mountTab() {
  return mount(StyleTab, {
    props: {
      block: { id: 'f1', type: 'feature', data: {}, settings: { style: {} } },
      blockType: feature,
      schema,
      classes: [],
      activeBreakpoint: 'base',
    } as never,
  })
}
const field = (w: ReturnType<typeof mountTab>, group: string, path: string) =>
  w.find(`[data-test="style-group-${group}"] [data-test="style-field-${path}"]`)

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe("a Feature's Style tab", () => {
  it('offers the marker’s colour, background and size in the Marker section', () => {
    const w = mountTab()
    expect(field(w, 'marker', 'marker.color').text()).toContain('Colour')
    expect(field(w, 'marker', 'marker.background').text()).toContain('Background')
    expect(field(w, 'marker', 'marker.size').text()).toContain('Size')
  })
  it('offers the space between the marker and the text in Spacing', () => {
    const w = mountTab()
    expect(field(w, 'spacing', 'feature.gap').text()).toContain('Space after icon')
  })
  it('offers where the marker sits against the text in Spacing: start, centre or end', () => {
    const w = mountTab()
    expect(field(w, 'spacing', 'feature.align').text()).toContain('Align icon')
    expect(propertyDefinition('feature.align')?.choices).toEqual(['start', 'center', 'end'])
    expect(CHOICE_LABELS['feature.align']).toEqual({ start: 'Start', center: 'Center', end: 'End' })
  })
})
