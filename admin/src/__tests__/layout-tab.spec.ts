// The Layout tab (container-layout spec §5): capability-driven sections, controls that follow the
// mode in force at the active breakpoint, and writes that land at that breakpoint.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { StyleSchemaResult } from '@/queries/styleSchema'
import LayoutTab from '@/editor/inspector/LayoutTab.vue'
import BlockInspector from '@/editor/inspector/BlockInspector.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'

vi.mock('@/queries/navigation', () => ({ useNavMenus: () => ({ data: ref([]) }) }))

const row = (
  path: string,
  group: string,
  responsive: boolean,
  domain: string | null,
  choices: string[] | null = null,
) => ({ path, group, kinds: ['token', 'reset'], responsive, token_domain: domain, choices })

const schema: StyleSchemaResult = {
  version: 2,
  breakpoints: { base: 0, md: 768, lg: 1024 },
  properties: [
    ...['top', 'right', 'bottom', 'left'].map((s) =>
      row(`spacing.padding.${s}`, 'spacing', true, 'spacing'),
    ),
    row('width', 'width', true, 'width'),
    row('alignment.text', 'alignment', true, null, ['start', 'center', 'end']),
    row('alignment.content', 'alignment', true, null, [
      'start',
      'center',
      'end',
      'between',
      'around',
      'evenly',
    ]),
    row('alignment.self', 'alignment', true, null, ['start', 'center', 'end']),
    row('visibility', 'visibility', true, null, ['visible', 'hidden']),
    row('layout.display', 'layout', true, null, ['flex', 'grid']),
    row('layout.direction', 'layout', true, null, [
      'row',
      'column',
      'row-reverse',
      'column-reverse',
    ]),
    row('layout.wrap', 'layout', true, null, ['nowrap', 'wrap']),
    row('layout.align_items', 'layout', true, null, ['start', 'center', 'end', 'stretch']),
    row('layout.columns', 'layout', true, null, ['1', '2', '3', '4', '1-2', '2-1']),
    row('layout.gap.column', 'layout', true, 'spacing'),
    row('layout.gap.row', 'layout', true, 'spacing'),
    row('layout.content_width', 'layout', true, 'width'),
    row('layout.gutter', 'layout', true, 'spacing'),
    row('layout.min_height', 'layout', true, null, ['auto', 'half', 'screen']),
    row('layout.overflow', 'layout', false, null, ['visible', 'hidden', 'auto']),
    row('layout.span', 'layout.item', true, null, ['1', '2', '3', '4', 'full']),
    row('layout.basis', 'layout.item', true, null, ['auto', '1/2', 'full']),
    row('layout.grow', 'layout.item', true, null, ['0', '1']),
    row('layout.shrink', 'layout.item', true, null, ['0', '1']),
    row('layout.align_self', 'layout.item', true, null, ['start', 'center', 'end', 'stretch']),
  ] as StyleSchemaResult['properties'],
  advanced: ['anchor', 'css_classes', 'attributes', 'accessibility.label'],
  vocabulary: {
    version: 1,
    domains: {
      spacing: ['none', 'sm', 'lg'],
      width: ['narrow', 'content', 'container', 'full'],
    },
    values: { 'spacing.lg': 'var(--space-4)' },
  },
}

const type = (slug: string, caps: string[] | null): BlockType =>
  ({
    uuid: `bt-${slug}`,
    slug,
    label: slug,
    icon: null,
    category: null,
    description: null,
    active: true,
    schema: [],
    style_capabilities: caps,
    style_targets: null,
    flags: {},
    starter_content: null,
  }) as BlockType

const container = type('container', [
  'spacing',
  'width',
  'alignment.self',
  'visibility',
  'layout.min_height',
  'layout.overflow',
  'layout.item',
  'layout.display',
  'layout.direction',
  'layout.wrap',
  'alignment.content',
  'layout.align_items',
  'layout.columns',
  'layout.gap.column',
  'layout.gap.row',
  'layout.content_width',
  'layout.gutter',
])
const heading = type('heading', [
  'spacing',
  'width',
  'alignment.self',
  'alignment.text',
  'visibility',
  'layout.item',
])
const button = type('button', ['spacing', 'alignment.content', 'visibility'])

const choice = (value: string) => ({ type: 'choice', value })
const block = (id: string, slug: string, style: Record<string, unknown> = {}) => ({
  id,
  type: slug,
  data: {},
  settings: { style },
})

function mountTab(props: Record<string, unknown>) {
  return mount(LayoutTab, {
    props: { schema, classes: [], activeBreakpoint: 'base', ...props } as never,
  })
}

const sectionsOf = (w: ReturnType<typeof mountTab>) =>
  w
    .findAll('[data-test^="layout-group-"]')
    .map((el) => el.attributes('data-test'))
    .filter((name): name is string => !!name && !name.includes('toggle') && !name.includes('count'))

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe('sections follow what the block declares', () => {
  it('a container opens on Container, then Box — and has no Children section', () => {
    // Spec §5 as amended: Container is what an author came for, so it comes first; the section
    // that set the mode a second time and held the controls that belong under Layout is gone.
    const w = mountTab({ block: block('c1', 'container'), blockType: container })
    expect(sectionsOf(w)).toEqual(['layout-group-container', 'layout-group-box'])
    expect(w.find('[data-test="layout-group-children"]').exists()).toBe(false)
  })

  it('a container that is also an item ends with As an item', () => {
    const w = mountTab({
      block: block('c2', 'container'),
      blockType: container,
      parent: block('c1', 'container'),
      parentType: container,
    })
    expect(sectionsOf(w)).toEqual([
      'layout-group-container',
      'layout-group-box',
      'layout-group-item',
    ])
  })

  it('the mode is headed Layout, offers Flex and Grid, and is set by one control', () => {
    const w = mountTab({ block: block('c1', 'container'), blockType: container })
    const mode = w.find('[data-test="style-field-layout.display"]')
    expect(mode.text()).toContain('Layout')
    expect(mode.text()).not.toContain('Children')
    const choices = mode.findAll('[data-test^="choice-"]').map((el) => el.attributes('data-test'))
    expect(choices).toEqual(['choice-flex', 'choice-grid'])
    // No second control anywhere in the tab writes layout.display.
    expect(w.findAll('[data-test="choice-grid"]')).toHaveLength(1)
  })

  it("the mode's controls sit under Layout, before Content width — inside Container", () => {
    const order = (w: ReturnType<typeof mountTab>) =>
      w
        .find('[data-test="layout-group-container"]')
        .findAll(
          '[data-test="style-field-layout.display"], [data-test="layout-mode-controls"], [data-test="style-field-layout.content_width"], [data-test="style-field-layout.gutter"]',
        )
        .map((el) => el.attributes('data-test'))
    const flex = mountTab({ block: block('c1', 'container'), blockType: container })
    expect(order(flex)).toEqual([
      'style-field-layout.display',
      'layout-mode-controls',
      'style-field-layout.content_width',
      'style-field-layout.gutter',
    ])
    const controls = flex.find('[data-test="layout-mode-controls"]')
    expect(controls.find('[data-test="layout-field-layout.direction"]').exists()).toBe(true)
    expect(controls.find('[data-test="layout-field-layout.columns"]').exists()).toBe(false)

    const grid = mountTab({
      block: block('c1', 'container', { layout: { display: { base: choice('grid') } } }),
      blockType: container,
    })
    const gridControls = grid.find('[data-test="layout-mode-controls"]')
    expect(gridControls.find('[data-test="layout-field-layout.columns"]').exists()).toBe(true)
    expect(gridControls.find('[data-test="layout-field-layout.direction"]').exists()).toBe(false)
  })

  it('the kept-but-unused notice stays inside Container after a mode switch', () => {
    const w = mountTab({
      block: block('c1', 'container', {
        layout: { display: { base: choice('grid') }, direction: { base: choice('row') } },
      }),
      blockType: container,
    })
    const notice = w
      .find('[data-test="layout-group-container"]')
      .find('[data-test="layout-dormant-parent"]')
    expect(notice.text()).toContain('Direction')
  })

  it('a heading shows Box alone, with width and placement', () => {
    // A heading has a box and sits in a parent, but arranges nothing.
    const w = mountTab({ block: block('h1', 'heading'), blockType: heading })
    expect(sectionsOf(w)).toEqual(['layout-group-box'])
    const fields = w.findAll('[data-test^="style-field-"]').map((el) => el.attributes('data-test'))
    expect(fields).toEqual(['style-field-width', 'style-field-alignment.self'])
  })

  it('a button keeps content alignment without becoming a container', () => {
    // Spec §5: Button and Navigation distribute their own content; they gain no Container
    // section from that one capability — the control sits with the block's own Box.
    const w = mountTab({ block: block('b1', 'button'), blockType: button })
    expect(sectionsOf(w)).toEqual(['layout-group-box'])
    expect(w.find('[data-test="style-field-alignment.content"]').exists()).toBe(true)
    expect(w.find('[data-test="layout-group-children"]').exists()).toBe(false)
  })

  it('says so when the block declares no layout at all', () => {
    const plain = type('spacer', ['spacing'])
    const w = mountTab({ block: block('s1', 'spacer'), blockType: plain })
    expect(w.find('[data-test="layout-none"]').exists()).toBe(true)
  })
})

describe('the controls under Layout follow the mode in force', () => {
  it('an untouched container is a flex column: direction and wrap, no tracks, no dead end', () => {
    // Flex and Grid only (spec §11.1): nothing declared is the theme's flex column, so the flex
    // controls are there from the start and no line tells the author to switch mode first.
    const w = mountTab({ block: block('c1', 'container'), blockType: container })
    expect(w.find('[data-test="layout-children-stack"]').exists()).toBe(false)
    expect(w.find('[data-test="layout-field-layout.direction"]').exists()).toBe(true)
    expect(w.find('[data-test="track-2"]').exists()).toBe(false)
    // The mode is set in one place: the second switch that sat here is gone.
    expect(w.find('[data-test="layout-display-switch"]').exists()).toBe(false)
  })

  it('a flex container offers direction and wrap, not tracks', () => {
    const w = mountTab({
      block: block('c1', 'container', { layout: { display: { base: choice('flex') } } }),
      blockType: container,
    })
    expect(w.find('[data-test="choice-row-reverse"]').exists()).toBe(true)
    expect(w.find('[data-test="choice-wrap"]').exists()).toBe(true)
    expect(w.find('[data-test="track-2"]').exists()).toBe(false)
  })

  it('a grid container offers tracks, not direction', () => {
    const w = mountTab({
      block: block('c1', 'container', { layout: { display: { base: choice('grid') } } }),
      blockType: container,
    })
    expect(w.find('[data-test="track-1-2"]').exists()).toBe(true)
    expect(w.find('[data-test="choice-row-reverse"]').exists()).toBe(false)
  })

  it('re-renders the section when the breakpoint changes the mode', async () => {
    // Flex at base, grid from md: the controls are those of the width being edited.
    const w = mountTab({
      block: block('c1', 'container', {
        layout: { display: { base: choice('flex'), md: choice('grid') } },
      }),
      blockType: container,
    })
    expect(w.find('[data-test="choice-row-reverse"]').exists()).toBe(true)
    await w.setProps({ activeBreakpoint: 'md' })
    expect(w.find('[data-test="track-1-2"]').exists()).toBe(true)
    expect(w.find('[data-test="choice-row-reverse"]').exists()).toBe(false)
  })
})

describe('Fill empty cells (container-layout spec §11.3)', () => {
  // The tab decides nothing: whether Fill applies, whether it can run and why not are handed to it
  // — the same answer the stage's button is drawn from.
  const gridBlock = () =>
    block('c1', 'container', {
      layout: { display: { base: choice('grid') }, columns: { base: choice('3') } },
    })
  const fillButton = (w: ReturnType<typeof mountTab>) => w.find('[data-test="layout-fill-cells"]')

  it('sits under the grid controls, enabled, and asks for the fill when pressed', async () => {
    const w = mountTab({
      block: gridBlock(),
      blockType: container,
      fill: { visible: true, enabled: true, cells: 2, preparing: false },
    })
    const controls = w.find('[data-test="layout-mode-controls"]')
    const button = controls.find('[data-test="layout-fill-cells"]')
    expect(button.exists()).toBe(true)
    expect(button.text()).toContain('Fill empty cells')
    expect(button.attributes('disabled')).toBeUndefined()
    expect(w.find('[data-test="layout-fill-note"]').text()).toContain('2 column containers')
    await button.trigger('click')
    expect(w.emitted('fill-cells')).toHaveLength(1)
  })

  it('one cell is said in the singular', () => {
    const w = mountTab({
      block: gridBlock(),
      blockType: container,
      fill: { visible: true, enabled: true, cells: 1, preparing: false },
    })
    expect(w.find('[data-test="layout-fill-note"]').text()).toContain('1 column container ')
  })

  it('disabled, it stays visible and says why in words, not only on hover', async () => {
    const w = mountTab({
      block: gridBlock(),
      blockType: container,
      fill: {
        visible: true,
        enabled: false,
        cells: 0,
        preparing: false,
        reason: 'No empty cells in the last row',
      },
    })
    expect(fillButton(w).attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="layout-fill-note"]').text()).toBe('No empty cells in the last row')
    await fillButton(w).trigger('click')
    expect(w.emitted('fill-cells')).toBeUndefined()
  })

  it('preparing, it is busy and cannot be pressed again', async () => {
    const w = mountTab({
      block: gridBlock(),
      blockType: container,
      fill: { visible: true, enabled: true, cells: 3, preparing: true },
    })
    expect(fillButton(w).attributes('disabled')).toBeDefined()
    expect(fillButton(w).attributes('aria-busy')).toBe('true')
    await fillButton(w).trigger('click')
    expect(w.emitted('fill-cells')).toBeUndefined()
  })

  it('is absent where Fill does not apply, or where nothing was handed down', () => {
    const notGrid = mountTab({
      block: block('c1', 'container', { layout: { display: { base: choice('flex') } } }),
      blockType: container,
      fill: { visible: false, enabled: false, cells: 0, preparing: false },
    })
    expect(fillButton(notGrid).exists()).toBe(false)
    const unanswered = mountTab({ block: gridBlock(), blockType: container })
    expect(fillButton(unanswered).exists()).toBe(false)
    const nulled = mountTab({ block: gridBlock(), blockType: container, fill: null })
    expect(fillButton(nulled).exists()).toBe(false)
  })
})

describe('writes land at the right breakpoint', () => {
  it('a track swatch writes the columns at the active breakpoint', async () => {
    const w = mountTab({
      block: block('c1', 'container', { layout: { display: { base: choice('grid') } } }),
      blockType: container,
      activeBreakpoint: 'md',
    })
    await w.find('[data-test="track-1-2"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.columns', 'md', { type: 'choice', value: '1-2' }]])
  })

  it('overflow writes with no breakpoint at all, and says it applies everywhere', async () => {
    const w = mountTab({
      block: block('c1', 'container'),
      blockType: container,
      activeBreakpoint: 'lg',
    })
    expect(w.find('[data-test="layout-overflow-note"]').text()).toContain('all sizes')
    const buttons = w.findAll('[data-test="style-field-layout.overflow"] [data-test^="choice-"]')
    await buttons.find((b) => b.attributes('data-test') === 'choice-hidden')!.trigger('click')
    expect(w.emitted('set')![0]).toEqual([
      'layout.overflow',
      null,
      { type: 'choice', value: 'hidden' },
    ])
  })

  it('the mode writes at the active breakpoint, from the one control that sets it', async () => {
    const w = mountTab({
      block: block('c1', 'container'),
      blockType: container,
      activeBreakpoint: 'lg',
    })
    await w
      .find('[data-test="style-field-layout.display"] [data-test="choice-grid"]')
      .trigger('click')
    expect(w.emitted('set')).toEqual([['layout.display', 'lg', { type: 'choice', value: 'grid' }]])
  })
})

describe('a stored layout the contract no longer offers', () => {
  // The inspector must not show the theme default in its place: that reads as valid while the
  // save is refused (spec §11.1). It says what is stored, where, and how to get out.
  const stale = (bp: string) =>
    block('c1', 'container', { layout: { display: { [bp]: choice('block') } } })
  // `UButton :to` renders a RouterLink, whose `useLink()` needs an injected router even when it is
  // never navigated — the pattern `subscriptions-pages.spec.ts` established.
  const testRouter = () =>
    createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
    })
  const mountStale = (props: Record<string, unknown>) =>
    mount(LayoutTab, {
      props: {
        schema,
        classes: [],
        activeBreakpoint: 'base',
        blockType: container,
        ...props,
      } as never,
      global: { plugins: [testRouter()] },
    })

  it('names the value and the breakpoint it is declared at, and presses no choice', () => {
    const w = mountStale({ block: stale('md'), activeBreakpoint: 'lg' })
    const notice = w.find('[data-test="invalid-choice-layout.display"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('block')
    expect(notice.text()).toContain('md')
    expect(w.find('[data-test="style-field-layout.display"]').exists()).toBe(false)
  })

  it('Replace writes the replacement at the DECLARING breakpoint, one operation', async () => {
    const w = mountStale({ block: stale('md'), activeBreakpoint: 'lg' })
    await w.find('[data-test="invalid-choice-replace"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.display', 'md', { type: 'choice', value: 'flex' }]])
  })

  it('Remove deletes that one declaration, at the declaring breakpoint', async () => {
    const w = mountStale({ block: stale('md'), activeBreakpoint: 'lg' })
    await w.find('[data-test="invalid-choice-remove"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.display', 'md', null]])
  })

  it('clears once the stored value is one the contract offers', async () => {
    const w = mountStale({ block: stale('md'), activeBreakpoint: 'lg' })
    await w.setProps({
      block: block('c1', 'container', { layout: { display: { md: choice('flex') } } }),
    })
    expect(w.find('[data-test="invalid-choice-layout.display"]').exists()).toBe(false)
    expect(w.find('[data-test="style-field-layout.display"]').exists()).toBe(true)
  })

  it('from a style class: names the class, offers no write, and links to where it can be repaired', () => {
    const w = mountStale({
      block: block('c1', 'container'),
      classes: [{ id: 'stacked', style: { layout: { display: { base: choice('block') } } } }],
      classNames: { stacked: 'Stacked band' },
      activeBreakpoint: 'md',
    })
    const notice = w.find('[data-test="invalid-choice-layout.display"]')
    expect(notice.text()).toContain('Stacked band')
    expect(notice.text()).toContain('base')
    expect(w.find('[data-test="invalid-choice-replace"]').exists()).toBe(false)
    expect(w.find('[data-test="invalid-choice-remove"]').exists()).toBe(false)
    // The router's base supplies /admin/; the in-app path starts at /settings.
    expect(w.find('[data-test="invalid-choice-open-class"]').attributes('href')).toBe(
      '/settings/style-classes/stacked',
    )
  })

  it('an untouched container and a valid mode show no notice', () => {
    expect(
      mountStale({ block: block('c1', 'container') })
        .find('[data-test="invalid-choice-layout.display"]')
        .exists(),
    ).toBe(false)
    expect(
      mountStale({
        block: block('c1', 'container', { layout: { display: { base: choice('grid') } } }),
      })
        .find('[data-test="invalid-choice-layout.display"]')
        .exists(),
    ).toBe(false)
  })
})

describe('the defaults in force are shown, not hidden', () => {
  // With Flex and Grid only, an untouched container is a flex column whose gaps are xl (spec
  // §3.8). A control that shows nothing pressed, or a gap that reads "–", says "none" about a
  // value that is very much in force — so the theme's default is marked, distinctly from a choice
  // the author made.
  const marked = (w: ReturnType<typeof mountTab>, field: string) =>
    w
      .find(`[data-test="layout-field-${field}"]`)
      .findAll('[data-default="true"]')
      .map((el) => el.attributes('data-test'))

  it('an untouched container marks column and one line as the defaults, pressed as neither', () => {
    const w = mountTab({ block: block('c1', 'container'), blockType: container })
    expect(marked(w, 'layout.direction')).toEqual(['choice-column'])
    expect(marked(w, 'layout.wrap')).toEqual(['choice-nowrap'])
    const column = w.find('[data-test="layout-field-layout.direction"] [data-test="choice-column"]')
    expect(column.attributes('aria-pressed')).toBe('false')
    expect(column.attributes('title')).toContain('default')
  })

  it('an authored direction is pressed and no default is marked beside it', () => {
    const w = mountTab({
      block: block('c1', 'container', { layout: { direction: { base: choice('row') } } }),
      blockType: container,
    })
    expect(marked(w, 'layout.direction')).toEqual([])
    expect(
      w
        .find('[data-test="layout-field-layout.direction"] [data-test="choice-row"]')
        .attributes('aria-pressed'),
    ).toBe('true')
  })

  it('a reset lands on the default again, and marks it', () => {
    const w = mountTab({
      block: block('c1', 'container', {
        layout: { direction: { base: choice('row'), md: { type: 'reset' } } },
      }),
      blockType: container,
      activeBreakpoint: 'md',
    })
    expect(marked(w, 'layout.direction')).toEqual(['choice-column'])
  })

  it('a mixed selection marks nothing: there is no one value to call the default in force', () => {
    const w = mountTab({
      block: block('c1', 'container'),
      blocks: [
        block('c1', 'container'),
        block('c2', 'container', { layout: { direction: { base: choice('row') } } }),
      ],
      blockType: container,
      blockTypes: [container, container],
    })
    expect(marked(w, 'layout.direction')).toEqual([])
  })

  it('a grid with no track count set marks one track: that is what is in force', () => {
    const w = mountTab({
      block: block('c1', 'container', { layout: { display: { base: choice('grid') } } }),
      blockType: container,
    })
    const one = w.find('[data-test="track-1"]')
    expect(one.attributes('data-default')).toBe('true')
    expect(one.attributes('aria-pressed')).toBe('false')
    expect(w.find('[data-test="track-3"]').attributes('data-default')).toBeUndefined()
    // Once a count is chosen it is pressed, and nothing is marked as the default beside it.
    const chosen = mountTab({
      block: block('c1', 'container', {
        layout: { display: { base: choice('grid') }, columns: { base: choice('3') } },
      }),
      blockType: container,
    })
    expect(chosen.find('[data-test="track-1"]').attributes('data-default')).toBeUndefined()
    expect(chosen.find('[data-test="track-3"]').attributes('aria-pressed')).toBe('true')
  })

  it('an unset gap says what spaces the children instead of reading as none', () => {
    const w = mountTab({ block: block('c1', 'container'), blockType: container })
    expect(w.find('[data-test="layout-gap-default"]').text()).toContain('xl')
    const set = mountTab({
      block: block('c1', 'container', {
        layout: {
          gap: {
            column: { base: { type: 'token', value: 'spacing.md' } },
            row: { base: { type: 'token', value: 'spacing.md' } },
          },
        },
      }),
      blockType: container,
    })
    expect(set.find('[data-test="layout-gap-default"]').exists()).toBe(false)
  })

  it('names the side that is still unset when only one gap is authored', () => {
    const w = mountTab({
      block: block('c1', 'container', {
        layout: { gap: { column: { base: { type: 'token', value: 'spacing.md' } } } },
      }),
      blockType: container,
    })
    const note = w.find('[data-test="layout-gap-default"]').text()
    expect(note).toContain('row')
    expect(note).not.toContain('column')
  })
})

describe('the gutter discloses the default it would use', () => {
  it('names the page gutter for a boxed container and none for a full-width one', async () => {
    const boxed = mountTab({
      block: block('c1', 'container', {
        layout: { content_width: { base: { type: 'token', value: 'width.container' } } },
      }),
      blockType: container,
    })
    expect(boxed.find('[data-test="layout-gutter-default"]').text()).toContain('page gutter')

    const full = mountTab({
      block: block('c2', 'container', {
        layout: { content_width: { base: { type: 'token', value: 'width.full' } } },
      }),
      blockType: container,
    })
    expect(full.find('[data-test="layout-gutter-default"]').text()).toContain('none')
  })
})

describe('a multi-selection', () => {
  it('offers the capability intersection only', () => {
    // A heading and a container share the box properties; nothing that arranges children.
    const w = mountTab({
      block: block('h1', 'heading'),
      blockType: heading,
      blocks: [block('h1', 'heading'), block('c1', 'container')],
      blockTypes: [heading, container],
    })
    expect(sectionsOf(w)).toEqual(['layout-group-box'])
    const fields = w.findAll('[data-test^="style-field-"]').map((el) => el.attributes('data-test'))
    expect(fields).toEqual(['style-field-width', 'style-field-alignment.self'])
  })
})

describe('As an item', () => {
  const grid = (style: Record<string, unknown> = {}) =>
    block('c1', 'container', { layout: { display: { base: choice('grid') } }, ...style })
  const flex = () => block('c1', 'container', { layout: { display: { base: choice('flex') } } })
  const stack = () => block('c1', 'container')

  it('offers span against a grid parent and basis, grow and shrink against a flex one', () => {
    const inGrid = mountTab({
      block: block('h1', 'heading'),
      blockType: heading,
      parent: grid(),
      parentType: container,
    })
    let fields = inGrid
      .findAll('[data-test="layout-group-item"] [data-test^="style-field-"]')
      .map((el) => el.attributes('data-test'))
    expect(fields).toEqual(['style-field-layout.span', 'style-field-layout.align_self'])

    const inFlex = mountTab({
      block: block('h1', 'heading'),
      blockType: heading,
      parent: flex(),
      parentType: container,
    })
    fields = inFlex
      .findAll('[data-test="layout-group-item"] [data-test^="style-field-"]')
      .map((el) => el.attributes('data-test'))
    expect(fields).toEqual([
      'style-field-layout.basis',
      'style-field-layout.grow',
      'style-field-layout.shrink',
      'style-field-layout.align_self',
    ])
  })

  it('an untouched parent is a flex column, so its items size themselves as flex items', () => {
    const w = mountTab({
      block: block('h1', 'heading'),
      blockType: heading,
      parent: stack(),
      parentType: container,
    })
    expect(w.find('[data-test="layout-item-stacks"]').exists()).toBe(false)
    expect(w.find('[data-test="style-field-layout.basis"]').exists()).toBe(true)
    expect(w.find('[data-test="style-field-layout.span"]').exists()).toBe(false)
  })

  it('follows the parent mode at the breakpoint being edited', async () => {
    // The parent is a grid from md up; below that it is the default flex column, so the item
    // sizes itself as a flex item there and as a grid item from md.
    const w = mountTab({
      block: block('h1', 'heading'),
      blockType: heading,
      parent: block('c1', 'container', { layout: { display: { md: choice('grid') } } }),
      parentType: container,
    })
    expect(w.find('[data-test="style-field-layout.basis"]').exists()).toBe(true)
    expect(w.find('[data-test="style-field-layout.span"]').exists()).toBe(false)
    await w.setProps({ activeBreakpoint: 'md' })
    expect(w.find('[data-test="style-field-layout.span"]').exists()).toBe(true)
    expect(w.find('[data-test="style-field-layout.basis"]').exists()).toBe(false)
  })

  it('has no item section without a parent, or when the parent arranges nothing', () => {
    const orphan = mountTab({ block: block('h1', 'heading'), blockType: heading })
    expect(orphan.find('[data-test="layout-group-item"]').exists()).toBe(false)

    const inButton = mountTab({
      block: block('h1', 'heading'),
      blockType: heading,
      parent: block('b1', 'button'),
      parentType: button,
    })
    expect(inButton.find('[data-test="layout-group-item"]').exists()).toBe(false)
  })
})

describe('dormant settings are disclosed both ways', () => {
  it('a flex container names the grid tracks it is keeping', () => {
    const w = mountTab({
      block: block('c1', 'container', {
        layout: { display: { base: choice('flex') }, columns: { base: choice('3') } },
      }),
      blockType: container,
    })
    const notice = w.find('[data-test="layout-dormant-parent"]')
    expect(notice.text()).toContain('Columns')
    expect(notice.text()).toContain('Kept but unused')
  })

  it('an item names the settings its parent mode ignores', () => {
    const w = mountTab({
      block: block('h1', 'heading', { layout: { basis: { base: choice('1/2') } } }),
      blockType: heading,
      parent: block('c1', 'container', { layout: { display: { base: choice('grid') } } }),
      parentType: container,
    })
    expect(w.find('[data-test="layout-dormant-item"]').text()).toContain('Basis')
  })

  it('counts a value a style class supplies', () => {
    const w = mountTab({
      block: block('c1', 'container', { layout: { display: { base: choice('flex') } } }),
      blockType: container,
      classes: [{ id: 'cls1', style: { layout: { columns: { base: choice('4') } } } }],
    })
    expect(w.find('[data-test="layout-dormant-parent"]').text()).toContain('Columns')
  })

  it('says nothing when nothing is retained', () => {
    const w = mountTab({
      block: block('c1', 'container', { layout: { display: { base: choice('grid') } } }),
      blockType: container,
    })
    expect(w.find('[data-test="layout-dormant-parent"]').exists()).toBe(false)
  })
})

describe('the inspector tabs', () => {
  it('orders Content, Layout, Style and Advanced for a container', () => {
    const w = mount(BlockInspector, {
      props: {
        block: block('c1', 'container'),
        blockType: container,
        schema,
        classes: [],
        activeBreakpoint: 'base',
      } as never,
    })
    expect(w.findAll('[role="tab"]').map((t) => t.text())).toEqual([
      'Content',
      'Layout',
      'Style',
      'Advanced',
    ])
  })

  it('omits Layout for a block that declares none of it', () => {
    const plain = type('spacer', ['spacing'])
    const w = mount(BlockInspector, {
      props: {
        block: block('s1', 'spacer'),
        blockType: plain,
        schema,
        classes: [],
        activeBreakpoint: 'base',
      } as never,
    })
    expect(w.findAll('[role="tab"]').map((t) => t.text())).toEqual(['Content', 'Style', 'Advanced'])
  })

  it('offers Layout and Style to a multi-selection', () => {
    const w = mount(BlockInspector, {
      props: {
        block: block('c1', 'container'),
        blockType: container,
        blocks: [block('c1', 'container'), block('c2', 'container')],
        blockTypes: [container, container],
        schema,
        classes: [],
        activeBreakpoint: 'base',
      } as never,
    })
    expect(w.findAll('[role="tab"]').map((t) => t.text())).toEqual(['Layout', 'Style'])
  })
})
