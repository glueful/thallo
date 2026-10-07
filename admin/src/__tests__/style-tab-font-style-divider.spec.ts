// Font style (`typography.style`, settings version 14) is a Typography row on every block that has
// Typography, set once for every width; the Footer block's divider is its own Style group — the line
// under its top section, by colour, width and style.
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import { styleProperties } from '@/style/schema'
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

function mountTab(blockType: BlockType) {
  return mount(StyleTab, {
    props: {
      block: { id: 'b1', type: blockType.slug, data: {}, settings: { style: {} } },
      blockType,
      schema,
      classes: [],
      activeBreakpoint: 'md',
    } as never,
  })
}

const words = (field: ReturnType<ReturnType<typeof mountTab>['find']>) =>
  field
    .findAll('[data-test^="choice-"]')
    .filter((c) => c.attributes('data-test') !== 'choice-mark')
    .map((c) => c.text())

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe('Font style', () => {
  it('is mirrored as the contract declares it', () => {
    expect(styleProperties().find((p) => p.path === 'typography.style')).toMatchObject({
      group: 'typography',
      responsive: false,
      choices: ['normal', 'italic'],
    })
  })

  it('is a Typography row offered in words, written once for every width', async () => {
    const w = mountTab(type('heading', ['typography']))
    const field = w.find(
      '[data-test="style-group-typography"] [data-test="style-field-typography.style"]',
    )
    expect(field.text()).toContain('Font style')
    expect(words(field)).toEqual(['Normal', 'Italic'])
    await field.find('[data-test="choice-italic"]').trigger('click')
    expect(w.emitted('set')).toEqual([
      ['typography.style', null, { type: 'choice', value: 'italic' }],
    ])
  })
})

describe('The Footer divider', () => {
  it('is mirrored as the contract declares it', () => {
    const by = new Map(styleProperties().map((p) => [p.path, p]))
    expect(by.get('footer.divider_color')).toMatchObject({
      group: 'footer',
      responsive: false,
      tokenDomain: 'color',
    })
    expect(by.get('footer.divider_width')?.choices).toEqual(['none', 'thin', 'medium', 'thick'])
    expect(by.get('footer.divider_style')?.choices).toEqual(['solid', 'dashed', 'dotted'])
  })

  it('is a Divider group for a block that declares it', () => {
    const group = mountTab(type('footer', ['spacing', 'footer'])).find(
      '[data-test="style-group-footer"]',
    )
    expect(group.text()).toContain('Divider')
    expect(group.find('[data-test="style-field-footer.divider_color"]').text()).toContain('Colour')
    expect(words(group.find('[data-test="style-field-footer.divider_width"]'))).toEqual([
      'None',
      'Thin',
      'Medium',
      'Thick',
    ])
    expect(words(group.find('[data-test="style-field-footer.divider_style"]'))).toEqual([
      'Solid',
      'Dashed',
      'Dotted',
    ])
  })
})
