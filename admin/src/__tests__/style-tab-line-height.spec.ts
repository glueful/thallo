// Line height is the third typography property: every block that declares typography has it,
// beside Size and Weight, and like them it varies by screen.
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import { styleProperties } from '@/style/schema'
import { tabOf } from '@/editor/inspector/tabMap'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

const schema = classEditorSchema()
const heading = {
  uuid: 'heading',
  slug: 'heading',
  label: 'Heading',
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: ['typography'],
  style_targets: null,
  flags: null,
  starter_content: null,
} as BlockType

function mountTab(style: Record<string, unknown> = {}, bp = 'base') {
  return mount(StyleTab, {
    props: {
      block: { id: 'b1', type: 'heading', data: {}, settings: { style } },
      blockType: heading,
      schema,
      classes: [],
      activeBreakpoint: bp,
    } as never,
  })
}

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe('line height', () => {
  it('is mirrored as the contract declares it, on the Style tab', () => {
    const def = styleProperties().find((p) => p.path === 'typography.line_height')
    expect(def).toMatchObject({
      group: 'typography',
      responsive: true,
      choices: ['tight', 'snug', 'normal', 'relaxed', 'loose'],
    })
    expect(tabOf('typography.line_height')).toBe('style')
  })

  it('sits in Typography after Size and Weight', () => {
    const group = mountTab().find('[data-test="style-group-typography"]')
    const fields = group.findAll('[data-test^="style-field-typography."]')
    expect(fields.map((f) => f.attributes('data-test'))).toEqual([
      'style-field-typography.size',
      'style-field-typography.weight',
      'style-field-typography.line_height',
    ])
    expect(fields[2]!.text()).toContain('Line height')
  })

  it('is written at the breakpoint being edited', async () => {
    const w = mountTab({}, 'lg')
    await w
      .find('[data-test="style-field-typography.line_height"] [data-test="choice-tight"]')
      .trigger('click')
    expect(w.emitted('set')).toEqual([
      ['typography.line_height', 'lg', { type: 'choice', value: 'tight' }],
    ])
  })
})
