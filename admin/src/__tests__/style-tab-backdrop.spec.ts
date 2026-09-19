// Three properties that modify what others declare: which sides a border is drawn on, how much
// of the background colour shows, and how much of what lies behind the element is blurred.
// Sides joins the `border` group, so a block that declares a border has it; the other two are the
// `backdrop` group, which a block or region opts into, and sit with the colours they belong to.
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import { styleProperties } from '@/style/schema'
import { tabOf } from '@/editor/inspector/tabMap'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

const schema = classEditorSchema()
const type = (slug: string, caps: string[]): BlockType =>
  ({
    uuid: slug,
    slug,
    label: slug,
    icon: null,
    category: null,
    description: null,
    active: true,
    schema: [],
    style_capabilities: caps,
    style_targets: null,
    flags: null,
    starter_content: null,
  }) as BlockType
const band = type('container', ['colors', 'border', 'backdrop'])
const card = type('feature', ['colors', 'border'])

function mountTab(blockType: BlockType, style: Record<string, unknown> = {}, bp = 'base') {
  return mount(StyleTab, {
    props: {
      block: { id: 'b1', type: blockType.slug, data: {}, settings: { style } },
      blockType,
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

describe('the contract the admin mirrors', () => {
  it('has the three paths, none of them responsive, on the Style tab', () => {
    const by = new Map(styleProperties().map((p) => [p.path, p]))
    expect(by.get('border.sides')).toMatchObject({
      group: 'border',
      responsive: false,
      choices: ['all', 'top', 'right', 'bottom', 'left'],
    })
    expect(by.get('colors.surface_opacity')).toMatchObject({
      group: 'backdrop',
      responsive: false,
      choices: ['100', '90', '80', '70', '60', '50'],
    })
    expect(by.get('backdrop.blur')).toMatchObject({
      group: 'backdrop',
      responsive: false,
      choices: ['none', 'sm', 'md', 'lg'],
    })
    for (const path of ['border.sides', 'colors.surface_opacity', 'backdrop.blur']) {
      expect(tabOf(path)).toBe('style')
    }
  })
})

describe('the Style tab', () => {
  it('offers Sides with the border, under Effects', () => {
    const effects = mountTab(card).find('[data-test="style-group-effects"]')
    const sides = effects.find('[data-test="style-field-border.sides"]')
    expect(sides.text()).toContain('Border sides')
    expect(sides.findAll('[data-test^="choice-"]').map((b) => b.text())).toEqual([
      'all',
      'top',
      'right',
      'bottom',
      'left',
    ])
  })

  it('puts the backdrop pair with the colours, and reads the opacity as a percentage', () => {
    const colours = mountTab(band).find('[data-test="style-group-colors"]')
    const opacity = colours.find('[data-test="style-field-colors.surface_opacity"]')
    expect(opacity.text()).toContain('Background opacity')
    expect(opacity.findAll('[data-test^="choice-"]').map((b) => b.text())).toEqual([
      '100%',
      '90%',
      '80%',
      '70%',
      '60%',
      '50%',
    ])
    expect(colours.find('[data-test="style-field-backdrop.blur"]').text()).toContain(
      'Backdrop blur',
    )
  })

  it('shows no backdrop pair for a block that declares colours alone', () => {
    const w = mountTab(card)
    expect(w.find('[data-test="style-field-colors.surface_opacity"]').exists()).toBe(false)
    expect(w.find('[data-test="style-field-backdrop.blur"]').exists()).toBe(false)
  })

  it('writes them bare, at any breakpoint, with the stored value and not its label', async () => {
    const w = mountTab(band, {}, 'md')
    await w
      .find('[data-test="style-field-colors.surface_opacity"] [data-test="choice-80"]')
      .trigger('click')
    await w.find('[data-test="style-field-backdrop.blur"] [data-test="choice-md"]').trigger('click')
    await w
      .find('[data-test="style-field-border.sides"] [data-test="choice-bottom"]')
      .trigger('click')
    expect(w.emitted('set')).toEqual([
      ['colors.surface_opacity', null, { type: 'choice', value: '80' }],
      ['backdrop.blur', null, { type: 'choice', value: 'md' }],
      ['border.sides', null, { type: 'choice', value: 'bottom' }],
    ])
  })
})
