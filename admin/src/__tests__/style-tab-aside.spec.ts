// A hero's aside — the blocks in its media column — is styled in the Style tab, under its own
// group: padding and a fill that are the aside's, beside the band's own under Spacing and Colours.
// They are separate paths (`aside.padding`, `aside.surface`) because one path holds one value.
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
const hero = type('hero', ['spacing', 'colors.surface', 'radius', 'shadow', 'aside'])
const heading = type('heading', ['spacing', 'typography'])

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
  it('has the aside paths, shaped like the properties they sit beside, on the Style tab', () => {
    const by = new Map(styleProperties().map((p) => [p.path, p]))
    const padding = by.get('aside.padding')!
    const surface = by.get('aside.surface')!
    expect(padding).toMatchObject({ group: 'aside', responsive: true, tokenDomain: 'spacing' })
    expect(surface).toMatchObject({ group: 'aside', responsive: false, tokenDomain: 'color' })
    expect(padding.responsive).toBe(by.get('spacing.padding.top')!.responsive)
    expect(surface.responsive).toBe(by.get('colors.surface')!.responsive)
    expect(tabOf('aside.padding')).toBe('style')
    expect(tabOf('aside.surface')).toBe('style')
  })
})

describe('the Style tab', () => {
  it('shows an Aside group — padding and background — for a block that declares one', () => {
    const w = mountTab(hero)
    const group = w.find('[data-test="style-group-aside"]')
    expect(group.exists()).toBe(true)
    expect(group.text()).toContain('Aside')
    expect(group.find('[data-test="style-field-aside.padding"]').text()).toContain('Padding')
    expect(group.find('[data-test="style-field-aside.surface"]').text()).toContain('Background')
    // The band's own background is still there, under Colours: two elements, two slots.
    expect(
      w.find('[data-test="style-group-colors"] [data-test="style-field-colors.surface"]').exists(),
    ).toBe(true)
  })

  it('shows none for a block without one', () => {
    expect(mountTab(heading).find('[data-test="style-group-aside"]').exists()).toBe(false)
  })

  it('writes the aside paths: padding at the breakpoint, the background bare', async () => {
    const w = mountTab(hero, {}, 'md')
    await w
      .find('[data-test="style-field-aside.padding"] [data-test="token-spacing.lg"]')
      .trigger('click')
    await w
      .find('[data-test="style-field-aside.surface"] [data-test="token-color.surface"]')
      .trigger('click')
    expect(w.emitted('set')).toEqual([
      ['aside.padding', 'md', { type: 'token', value: 'spacing.lg' }],
      ['aside.surface', null, { type: 'token', value: 'color.surface' }],
    ])
  })
})
