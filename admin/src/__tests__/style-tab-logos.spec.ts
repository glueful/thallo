// A Logos block sizes its logos and spaces them. Logo size (`logos.height`, settings version 13)
// is the block's own Style group, per width; the gaps between logos are the Layout tab's Column
// and Row gaps on the row of logos — and, unset, they name the theme's own logo gaps, not a
// container's.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import LayoutTab from '@/editor/inspector/LayoutTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import { styleProperties } from '@/style/schema'
import { tabOf } from '@/editor/inspector/tabMap'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

vi.mock('@/queries/navigation', () => ({ useNavMenus: () => ({ data: ref([]) }) }))

const schema = classEditorSchema()
const logos = {
  uuid: 'logos',
  slug: 'logos',
  label: 'Logos',
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: [
    'spacing',
    'visibility',
    'layout.item',
    'layout.gap.column',
    'layout.gap.row',
    'logos',
  ],
  style_targets: {
    root: 'root',
    targets: {
      root: { kind: 'box' },
      track: {
        kind: 'stack',
        optional: true,
        defaults: { display: 'flex', gap: { row: 'lg', column: '2xl' } },
      },
      image: { kind: 'box', optional: true },
    },
    map: { 'layout.gap.column': 'track', 'layout.gap.row': 'track', logos: 'image' },
  },
  flags: null,
  starter_content: null,
} as unknown as BlockType

const props = (style: Record<string, unknown> = {}, bp = 'base') =>
  ({
    block: { id: 'b1', type: 'logos', data: {}, settings: { style } },
    blockType: logos,
    schema,
    classes: [],
    activeBreakpoint: bp,
  }) as never

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe('Logo size', () => {
  it('is mirrored as the contract declares it, on the Style tab', () => {
    const def = styleProperties().find((p) => p.path === 'logos.height')
    expect(def).toMatchObject({
      group: 'logos',
      responsive: true,
      choices: ['sm', 'md', 'lg', 'xl'],
    })
    expect(tabOf('logos.height')).toBe('style')
  })

  it('is a Logos group, offered in words', () => {
    const group = mount(StyleTab, { props: props() }).find('[data-test="style-group-logos"]')
    expect(group.text()).toContain('Logos')
    const field = group.find('[data-test="style-field-logos.height"]')
    expect(field.text()).toContain('Logo size')
    const words = field
      .findAll('[data-test^="choice-"]')
      .filter((c) => c.attributes('data-test') !== 'choice-mark')
      .map((c) => c.text())
    expect(words).toEqual(['Small', 'Medium', 'Large', 'Extra large'])
  })

  it('is written at the width being edited', async () => {
    const w = mount(StyleTab, { props: props({}, 'md') })
    await w.find('[data-test="style-field-logos.height"] [data-test="choice-lg"]').trigger('click')
    expect(w.emitted('set')).toEqual([['logos.height', 'md', { type: 'choice', value: 'lg' }]])
  })
})

describe('the gaps between logos', () => {
  it("are the Layout tab's, and unset they name the theme's logo gaps", () => {
    const w = mount(LayoutTab, { props: props() })
    expect(
      w.find('[data-test="box-gap"] [data-test="style-field-layout.gap.column"]').exists(),
    ).toBe(true)
    expect(w.find('[data-test="layout-gap-default"]').text()).toContain('2xl and lg')
  })
})
