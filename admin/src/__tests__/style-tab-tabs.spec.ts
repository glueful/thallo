// A tabs block's strip is styled in the Style tab, under its own group: the bar's corners and the
// tab's — the pill behind the active label. They are separate paths (`tabs.bar_radius`,
// `tabs.tab_radius`) because the block's own `radius` is the panels area's, and one path holds
// one value.
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
const tabs = type('tabs', ['spacing', 'radius', 'tabs'])
const feature = type('feature', ['spacing', 'radius', 'marker'])

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
  it('has the strip paths, shaped like `radius`, on the Style tab', () => {
    const by = new Map(styleProperties().map((p) => [p.path, p]))
    for (const path of ['tabs.bar_radius', 'tabs.tab_radius']) {
      expect(by.get(path)).toMatchObject({
        group: 'tabs',
        responsive: false,
        tokenDomain: 'radius',
      })
      expect(by.get(path)!.responsive).toBe(by.get('radius')!.responsive)
      expect(tabOf(path)).toBe('style')
    }
  })
})

describe('the Style tab', () => {
  it('shows a Tabs group — the bar and the active tab — for a block that declares one', () => {
    const w = mountTab(tabs)
    const group = w.find('[data-test="style-group-tabs"]')
    expect(group.exists()).toBe(true)
    expect(group.text()).toContain('Tabs')
    expect(group.find('[data-test="style-field-tabs.bar_radius"]').text()).toContain('Bar corners')
    expect(group.find('[data-test="style-field-tabs.tab_radius"]').text()).toContain(
      'Active tab corners',
    )
    // The block's own corners are still there, under Effects: the panels area's.
    expect(
      w.find('[data-test="style-group-effects"] [data-test="style-field-radius"]').exists(),
    ).toBe(true)
  })

  it('shows none for a block without one', () => {
    expect(mountTab(feature).find('[data-test="style-group-tabs"]').exists()).toBe(false)
  })

  it('writes the strip paths bare, at any breakpoint: corners do not vary by screen', async () => {
    const w = mountTab(tabs, {}, 'md')
    const full = VOCABULARY.domains.radius.includes('full') ? 'radius.full' : 'radius.lg'
    await w
      .find(`[data-test="style-field-tabs.bar_radius"] [data-test="token-${full}"]`)
      .trigger('click')
    await w
      .find('[data-test="style-field-tabs.tab_radius"] [data-test="token-radius.sm"]')
      .trigger('click')
    expect(w.emitted('set')).toEqual([
      ['tabs.bar_radius', null, { type: 'token', value: full }],
      ['tabs.tab_radius', null, { type: 'token', value: 'radius.sm' }],
    ])
  })

  it("the bar's corners, the tab's and the panels area's are read independently", () => {
    const w = mountTab(tabs, {
      radius: { type: 'token', value: 'radius.lg' },
      tabs: {
        bar_radius: { type: 'token', value: 'radius.md' },
        tab_radius: { type: 'token', value: 'radius.sm' },
      },
    })
    const pressed = (path: string) =>
      w
        .find(`[data-test="style-field-${path}"]`)
        .findAll('[aria-pressed="true"]')
        .map((b) => b.attributes('data-test'))
        .filter((t) => t?.startsWith('token-'))
    expect(pressed('radius')).toEqual(['token-radius.lg'])
    expect(pressed('tabs.bar_radius')).toEqual(['token-radius.md'])
    expect(pressed('tabs.tab_radius')).toEqual(['token-radius.sm'])
  })
})
