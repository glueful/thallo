// A feature's marker — its icon chip or number badge — is styled in the Style tab, under its own
// group: corners and shadow that are the marker's, beside the block's own under Effects. They are
// separate paths (`marker.radius`, `marker.shadow`) because one path holds one value.
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import { styleProperties } from '@/style/schema'
import { tabOf } from '@/editor/inspector/tabMap'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema, VOCABULARY } from './helpers/classEditorSchema'

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
const feature = type('feature', ['spacing', 'radius', 'shadow', 'marker'])
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
  it('has the marker paths, shaped like the properties they sit beside, on the Style tab', () => {
    const by = new Map(styleProperties().map((p) => [p.path, p]))
    const radius = by.get('marker.radius')!
    const shadow = by.get('marker.shadow')!
    expect(radius).toMatchObject({ group: 'marker', responsive: false, tokenDomain: 'radius' })
    expect(shadow).toMatchObject({ group: 'marker', responsive: true, tokenDomain: 'shadow' })
    expect(radius.responsive).toBe(by.get('radius')!.responsive)
    expect(shadow.responsive).toBe(by.get('shadow')!.responsive)
    expect(tabOf('marker.radius')).toBe('style')
    expect(tabOf('marker.shadow')).toBe('style')
  })
})

describe('the Style tab', () => {
  it('shows a Marker group — corners and shadow — for a block that declares a marker', () => {
    const w = mountTab(feature)
    const group = w.find('[data-test="style-group-marker"]')
    expect(group.exists()).toBe(true)
    expect(group.text()).toContain('Marker')
    expect(group.find('[data-test="style-field-marker.radius"]').text()).toContain('Corners')
    expect(group.find('[data-test="style-field-marker.shadow"]').text()).toContain('Shadow')
    // The block's own corners and shadow are still there, under Effects: two elements, two slots.
    expect(
      w.find('[data-test="style-group-effects"] [data-test="style-field-radius"]').exists(),
    ).toBe(true)
    expect(
      w.find('[data-test="style-group-effects"] [data-test="style-field-shadow"]').exists(),
    ).toBe(true)
  })

  it('shows none for a block without one', () => {
    expect(mountTab(heading).find('[data-test="style-group-marker"]').exists()).toBe(false)
  })

  it('writes the marker paths: corners bare, since they do not vary by screen; the shadow at the breakpoint', async () => {
    const w = mountTab(feature, {}, 'md')
    const full = VOCABULARY.domains.radius.includes('full') ? 'radius.full' : 'radius.lg'
    await w
      .find(`[data-test="style-field-marker.radius"] [data-test="token-${full}"]`)
      .trigger('click')
    await w
      .find('[data-test="style-field-marker.shadow"] [data-test="token-shadow.md"]')
      .trigger('click')
    expect(w.emitted('set')).toEqual([
      ['marker.radius', null, { type: 'token', value: full }],
      ['marker.shadow', 'md', { type: 'token', value: 'shadow.md' }],
    ])
  })

  it("the marker's corners and the card's are read independently", () => {
    const w = mountTab(feature, {
      radius: { type: 'token', value: 'radius.lg' },
      marker: { radius: { type: 'token', value: 'radius.full' } },
    })
    const pressed = (path: string) =>
      w
        .find(`[data-test="style-field-${path}"]`)
        .findAll('[aria-pressed="true"]')
        .map((b) => b.attributes('data-test'))
        .filter((t) => t?.startsWith('token-'))
    expect(pressed('radius')).toEqual(['token-radius.lg'])
    expect(pressed('marker.radius')).toEqual(['token-radius.full'])
  })
})
