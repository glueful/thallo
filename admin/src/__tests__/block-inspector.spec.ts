import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { StyleSchemaResult } from '@/queries/styleSchema'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import AdvancedTab from '@/editor/inspector/AdvancedTab.vue'
import BlockInspector from '@/editor/inspector/BlockInspector.vue'

vi.mock('@/queries/navigation', () => ({ useNavMenus: () => ({ data: ref([]) }) }))

const row = (
  path: string,
  group: string,
  responsive: boolean,
  domain: string | null,
  choices: string[] | null = null,
) => ({ path, group, kinds: ['token', 'reset'], responsive, token_domain: domain, choices })

const schema: StyleSchemaResult = {
  version: 1,
  breakpoints: { base: 0, md: 768, lg: 1024 },
  properties: [
    ...['top', 'right', 'bottom', 'left'].map((s) =>
      row(`spacing.padding.${s}`, 'spacing', true, 'spacing'),
    ),
    ...['top', 'bottom'].map((s) => row(`spacing.margin.${s}`, 'spacing', true, 'spacing')),
    row('width', 'width', true, 'width'),
    ...['text', 'content', 'self'].map((k) =>
      row(`alignment.${k}`, 'alignment', true, null, ['start', 'center', 'end']),
    ),
    row('typography.size', 'typography', true, 'typography.size'),
    row('typography.weight', 'typography', true, null, ['regular', 'bold']),
    row('visibility', 'visibility', true, null, ['visible', 'hidden']),
    row('shadow', 'shadow', true, 'shadow'),
    row('radius', 'radius', false, 'radius'),
    ...['surface', 'text', 'border'].map((p) => row(`colors.${p}`, 'colors', false, 'color')),
    row('border.width', 'border', false, null, ['none', 'thin']),
    row('border.style', 'border', false, null, ['solid']),
  ] as StyleSchemaResult['properties'],
  advanced: ['anchor', 'css_classes', 'attributes', 'accessibility.label'],
  vocabulary: {
    version: 1,
    domains: {
      spacing: ['none', 'sm', 'lg'],
      width: ['narrow', 'full'],
      radius: ['none', 'full'],
      color: ['text', 'accent'],
      shadow: ['none', 'md'],
      'typography.size': ['sm', 'lg'],
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
    schema: [
      { name: 'text', type: 'string', required: false, localized: false, filterable: false },
    ],
    style_capabilities: caps,
    style_targets: null,
    flags: {},
    starter_content: null,
  }) as BlockType

const heading = type('heading', [
  'spacing',
  'alignment.text',
  'typography',
  'colors.text',
  'visibility',
])

const button = type('button', ['spacing', 'alignment.content', 'visibility'])
const token = (name: string) => ({ type: 'token', value: `spacing.${name}` })
const padded = (id: string, type: string, top?: ReturnType<typeof token>) => ({
  id,
  type,
  data: {},
  settings: top ? { style: { spacing: { padding: { top: { base: top } } } } } : {},
})

describe('a multi-selection in the inspector (visual builder spec §5.5)', () => {
  it('the Style tab renders only the capability intersection; a property any block lacks has no control', () => {
    const w = mount(StyleTab, {
      props: {
        block: padded('h1', 'heading'),
        blockType: heading,
        blocks: [padded('h1', 'heading'), padded('h2', 'heading'), padded('b1', 'button')],
        blockTypes: [heading, heading, button],
        schema,
        classes: [],
        activeBreakpoint: 'base',
      },
    })
    const fields = w.findAll('[data-test^="style-field-"]').map((el) => el.attributes('data-test'))
    expect(fields).toEqual([
      'style-field-spacing.padding.top',
      'style-field-spacing.padding.right',
      'style-field-spacing.padding.bottom',
      'style-field-spacing.padding.left',
      'style-field-spacing.margin.top',
      'style-field-spacing.margin.bottom',
      'style-field-visibility',
    ])
    expect(w.find('[data-test="style-field-alignment.text"]').exists()).toBe(false)
    expect(w.find('[data-test="save-as-style-class"]').exists()).toBe(false)
  })

  it('a property whose blocks resolve differently shows mixed; a pick still emits one set', async () => {
    const w = mount(StyleTab, {
      props: {
        block: padded('h1', 'heading', token('sm')),
        blockType: heading,
        blocks: [padded('h1', 'heading', token('sm')), padded('h2', 'heading', token('lg'))],
        blockTypes: [heading, heading],
        schema,
        classes: [],
        activeBreakpoint: 'base',
      },
    })
    const top = w.find('[data-test="style-field-spacing.padding.top"]')
    expect(top.find('[data-test="style-state"]').text()).toBe('mixed')
    await top.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
    expect(top.find('[data-test="token-spacing.sm"]').attributes('aria-pressed')).not.toBe('true')
    // A property they agree on is not mixed.
    expect(
      w.find('[data-test="style-field-spacing.padding.left"] [data-test="style-state"]').text(),
    ).toBe('theme')
    await top.find('[data-test="token-spacing.lg"]').trigger('click')
    expect(w.emitted('set')?.[0]).toEqual(['spacing.padding.top', 'base', token('lg')])
  })

  it('the inspector shows only Style for several blocks and counts them in the title', () => {
    const w = mount(BlockInspector, {
      props: {
        block: padded('h1', 'heading'),
        blockType: heading,
        blocks: [padded('h1', 'heading'), padded('h2', 'heading')],
        blockTypes: [heading, heading],
        schema,
        classes: [],
        activeBreakpoint: 'base',
      },
    })
    expect(w.find('[data-test="block-inspector-title"]').text()).toBe('2 blocks')
    expect(w.find('[data-test="style-tab"]').exists()).toBe(true)
    expect(w.findComponent({ name: 'AdvancedTab' }).exists()).toBe(false)
    expect(w.findComponent({ name: 'BlockFields' }).exists()).toBe(false)
  })
})

describe('StyleTab', () => {
  it('a heading shows spacing, text alignment, typography, text colour and visibility only', () => {
    const w = mount(StyleTab, {
      props: {
        block: { id: 'h', type: 'heading', data: {}, settings: {} },
        blockType: heading,
        schema,
        classes: [],
        activeBreakpoint: 'md',
      },
    })
    const fields = w.findAll('[data-test^="style-field-"]').map((el) => el.attributes('data-test'))
    expect(fields).toEqual([
      'style-field-spacing.padding.top',
      'style-field-spacing.padding.right',
      'style-field-spacing.padding.bottom',
      'style-field-spacing.padding.left',
      'style-field-spacing.margin.top',
      'style-field-spacing.margin.bottom',
      'style-field-alignment.text',
      'style-field-typography.size',
      'style-field-typography.weight',
      'style-field-colors.text',
      'style-field-visibility',
    ])
    expect(w.find('[data-test="style-group-effects"]').exists()).toBe(false)
    // The four-sided properties present as one box row each (padding, margin), the breakpoint
    // chips sit once on the group header, and a single-value row carries none of its own.
    expect(
      w.findAll('[data-test="style-group-spacing"] [data-test^="box-"]').length,
    ).toBeGreaterThan(0)
    expect(
      w.find('[data-test="style-group-spacing"] [data-test="group-breakpoint-md"]').exists(),
    ).toBe(true)
    expect(
      w.find('[data-test="style-field-visibility"] [data-test="breakpoint-md"]').exists(),
    ).toBe(false)
  })

  it('editing at active breakpoint md writes md; lg shows the value as inherited', async () => {
    const w = mount(StyleTab, {
      props: {
        block: {
          id: 'h',
          type: 'heading',
          data: {},
          settings: {
            style: {
              spacing: { padding: { top: { md: { type: 'token', value: 'spacing.lg' } } } },
            },
          },
        },
        blockType: heading,
        schema,
        classes: [],
        activeBreakpoint: 'md',
      },
    })
    const top = w.find('[data-test="style-field-spacing.padding.top"]')
    expect(top.find('[data-test="style-state"]').text()).toBe('set')
    await top.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
    await top.find('[data-test="token-spacing.sm"]').trigger('click')
    expect(w.emitted('set')?.[0]).toEqual([
      'spacing.padding.top',
      'md',
      { type: 'token', value: 'spacing.sm' },
    ])

    await w.setProps({ activeBreakpoint: 'lg' })
    expect(
      w.find('[data-test="style-field-spacing.padding.top"] [data-test="style-state"]').text(),
    ).toBe('inherited')
    await w
      .find('[data-test="style-field-spacing.padding.top"] [data-test="style-reset"]')
      .trigger('click')
    expect(w.emitted('set')?.[1]).toEqual(['spacing.padding.top', 'lg', { type: 'reset' }])
  })
})

describe('StyleTab groups collapse', () => {
  // Folds are module state shared by every StyleTab: clean before AND after, for the specs below.
  beforeEach(() => {
    localStorage.clear()
    resetFolds()
  })
  afterEach(() => {
    localStorage.clear()
    resetFolds()
  })
  const mountTab = (style: Record<string, unknown> = {}) =>
    mount(StyleTab, {
      props: {
        block: { id: 'h', type: 'heading', data: {}, settings: { style } },
        blockType: heading,
        schema,
        classes: [],
        activeBreakpoint: 'md',
      },
    })

  it('a group header collapses its controls, and the choice holds for the next block', async () => {
    const w = mountTab()
    const toggle = w.find('[data-test="style-group-toggle-spacing"]')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(w.find('[data-test="style-field-spacing.padding.top"]').exists()).toBe(false)
    expect(w.find('[data-test="style-field-typography.size"]').exists()).toBe(true)
    // Another block, later: spacing is still folded, the rest still open.
    const next = mountTab()
    expect(next.find('[data-test="style-group-toggle-spacing"]').attributes('aria-expanded')).toBe(
      'false',
    )
    expect(next.find('[data-test="style-field-spacing.padding.top"]').exists()).toBe(false)
    expect(next.find('[data-test="style-field-typography.size"]').exists()).toBe(true)
    await next.find('[data-test="style-group-toggle-spacing"]').trigger('click')
    expect(next.find('[data-test="style-field-spacing.padding.top"]').exists()).toBe(true)
  })

  it('a folded group says how many of its properties this block sets', async () => {
    const w = mountTab({
      spacing: {
        padding: { top: { md: { type: 'token', value: 'spacing.lg' } } },
        margin: { top: { base: { type: 'token', value: 'spacing.sm' } } },
      },
    })
    expect(w.find('[data-test="style-group-count-spacing"]').exists()).toBe(false)
    await w.find('[data-test="style-group-toggle-spacing"]').trigger('click')
    expect(w.find('[data-test="style-group-count-spacing"]').text()).toBe('2 set')
    await w.find('[data-test="style-group-toggle-typography"]').trigger('click')
    expect(w.find('[data-test="style-group-count-typography"]').exists()).toBe(false)
  })
})

/** Nuxt UI puts fall-through attrs on the <input> itself; older shapes wrap it. */
function inputOf(w: ReturnType<typeof mount>, test: string) {
  const el = w.find(`[data-test="${test}"]`)
  return el.element.tagName === 'INPUT' ? el : el.find('input')
}

describe('AdvancedTab', () => {
  it('a value inherited from a class is labelled by the class name; a missing id is flagged', () => {
    const band = {
      id: 'band',
      style: { spacing: { padding: { top: { md: { type: 'token', value: 'spacing.lg' } } } } },
    }
    const w = mount(StyleTab, {
      props: {
        block: { id: 'h', type: 'heading', data: {}, settings: { classes: ['band'] } },
        blockType: heading,
        schema,
        classes: [band],
        classNames: { band: 'Hero band' },
        activeBreakpoint: 'lg',
      },
    })
    const field = w.find('[data-test="style-field-spacing.padding.top"]')
    expect(field.find('[data-test="style-state"]').text()).toBe('inherited')
    expect(field.find('[data-test="style-state"]').attributes('data-source')).toBe('class:band')
    expect(field.find('[data-test="style-source"]').text()).toBe('from Hero band')

    const a = mount(AdvancedTab, {
      props: {
        block: { id: 'h', type: 'heading', data: {}, settings: { classes: ['band', 'gone'] } },
        classNames: { band: 'Hero band' },
      },
    })
    expect(a.find('[data-test="style-class-band"]').text()).toContain('Hero band')
    expect(
      a.find('[data-test="style-class-gone"] [data-test="style-class-missing"]').exists(),
    ).toBe(true)
    expect(
      a.find('[data-test="style-class-band"] [data-test="style-class-missing"]').exists(),
    ).toBe(false)
  })

  it('the Style classes list is labelled apart from CSS classes, offers only applicable classes, and emits each action', async () => {
    const a = mount(AdvancedTab, {
      props: {
        block: { id: 'h', type: 'heading', data: {}, settings: { classes: ['band', 'quiet'] } },
        classNames: { band: 'Hero band', quiet: 'Quiet', old: 'Old', busy: 'Busy', fresh: 'Fresh' },
        classOptions: [
          { id: 'band', name: 'Hero band', archived: false, locked: false },
          { id: 'quiet', name: 'Quiet', archived: false, locked: false },
          { id: 'old', name: 'Old', archived: true, locked: false },
          { id: 'busy', name: 'Busy', archived: false, locked: true },
          { id: 'fresh', name: 'Fresh', archived: false, locked: false },
        ],
      },
    })
    expect(a.text()).toContain('Style classes')
    expect(a.text()).toContain('CSS classes')
    const pickable = (a.vm as unknown as { pickable: { value: string }[] }).pickable
    expect(pickable.map((o) => o.value)).toEqual(['fresh'])
    const busy = mount(AdvancedTab, {
      props: {
        block: { id: 'h', type: 'heading', data: {}, settings: { classes: ['busy'] } },
        classNames: { busy: 'Busy' },
        classOptions: [{ id: 'busy', name: 'Busy', archived: false, locked: true }],
      },
    })
    expect(busy.find('[data-test="style-class-locked"]').text()).toContain('job running')
    expect(busy.find('[data-test="style-class-detach-busy"]').attributes('disabled')).toBeDefined()

    await a.find('[data-test="style-class-detach-band"]').trigger('click')
    expect(a.emitted('detach-class')?.[0]).toEqual(['band'])
    await a.find('[data-test="style-class-remove-quiet"]').trigger('click')
    expect(a.emitted('remove-class')?.[0]).toEqual(['quiet'])
    await a.find('[data-test="style-classes-detach-all"]').trigger('click')
    expect(a.emitted('detach-all')).toHaveLength(1)
  })

  it('anchor, CSS classes and label emit set; data-thallo-* and non data-* attributes are rejected', async () => {
    const w = mount(AdvancedTab, {
      props: { block: { id: 'h', type: 'heading', data: {}, settings: { classes: ['c1'] } } },
    })
    expect(w.find('[data-test="style-classes"]').text()).toContain('c1')
    await inputOf(w, 'identifier-anchor').setValue('intro')
    expect(w.emitted('set')?.[0]).toEqual(['anchor', 'intro'])
    await inputOf(w, 'identifier-anchor').setValue('Intro!')
    expect(w.find('[data-test="identifier-error"]').exists()).toBe(true)
    expect(w.emitted('set')).toHaveLength(1)

    await inputOf(w, 'css-classes').setValue('hero  is-featured')
    expect(w.emitted('set')?.[1]).toEqual(['css_classes', ['hero', 'is-featured']])
    await inputOf(w, 'css-classes').setValue('1bad')
    expect(w.find('[data-test="css-classes-error"]').exists()).toBe(true)

    await inputOf(w, 'attribute-name').setValue('data-thallo-x')
    await w.find('[data-test="attribute-add"]').trigger('click')
    expect(w.find('[data-test="attribute-error"]').text()).toContain('reserved')
    await inputOf(w, 'attribute-name').setValue('onclick')
    await w.find('[data-test="attribute-add"]').trigger('click')
    expect(w.find('[data-test="attribute-error"]').text()).toContain('data-*')
    await inputOf(w, 'attribute-name').setValue('data-track')
    await inputOf(w, 'attribute-value').setValue('cta')
    await w.find('[data-test="attribute-add"]').trigger('click')
    expect(w.emitted('set')?.[2]).toEqual(['attributes', { 'data-track': 'cta' }])

    await inputOf(w, 'accessibility-label').setValue('Read more')
    expect(w.emitted('set')?.[3]).toEqual(['accessibility.label', 'Read more'])
  })
})

describe('BlockInspector', () => {
  it('shows Content, Style and Advanced for the selected block', () => {
    const w = mount(BlockInspector, {
      props: {
        block: { id: 'h', type: 'heading', data: { text: 'Hi' }, settings: {} },
        blockType: heading,
        schema,
        classes: [],
        activeBreakpoint: 'lg',
      },
    })
    expect(w.find('[data-test="block-inspector-title"]').text()).toBe('heading')
    const tabs = w.findAll('[role="tab"]').map((t) => t.text())
    expect(tabs).toEqual(['Content', 'Style', 'Advanced'])
  })
})

describe('a block’s parts', () => {
  const links = {
    ...type('links', ['spacing', 'typography']),
    style_targets: {
      targets: { root: { kind: 'box' }, title: { kind: 'text' } },
      map: { spacing: 'root', typography: 'title' },
      parts: { link: { label: 'Link', capabilities: ['typography', 'colors.text'] } },
    },
  } as BlockType

  it('the Style tab has a section per part, writing to the part and not to the block', async () => {
    const w = mount(BlockInspector, {
      props: {
        block: {
          id: 'l1',
          type: 'links',
          data: {},
          settings: {
            parts: {
              link: {
                typography: { size: { base: { type: 'token', value: 'typography.size.sm' } } },
              },
            },
          },
        },
        blockType: links,
        schema,
        classes: [],
        activeBreakpoint: 'base',
        noContent: true,
      },
    })
    const part = w.find('[data-test="style-part-link"]')
    expect(part.exists()).toBe(true)
    expect(part.find('[data-test="style-part-title"]').text()).toBe('Link')
    const fields = part
      .findAll('[data-test^="style-field-"]')
      .map((el) => el.attributes('data-test'))
    expect(fields).toEqual([
      'style-field-typography.size',
      'style-field-typography.weight',
      'style-field-colors.text',
    ])
    // The part's own value, not the block's.
    expect(
      part.find('[data-test="style-field-typography.size"] [data-test="style-state"]').text(),
    ).toBe('set')

    await part
      .find('[data-test="style-field-typography.size"] [data-test="token-typography.size.lg"]')
      .trigger('click')
    expect(w.emitted('set-part-setting')?.[0]).toEqual([
      'link',
      'typography.size',
      'base',
      { type: 'token', value: 'typography.size.lg' },
    ])
    expect(w.emitted('set-setting')).toBeUndefined()
  })

  it('a block without parts, and a selection of several, show no part sections', () => {
    const one = mount(BlockInspector, {
      props: {
        block: { id: 'h', type: 'heading', data: {}, settings: {} },
        blockType: heading,
        schema,
        classes: [],
        activeBreakpoint: 'base',
        noContent: true,
      },
    })
    expect(one.find('[data-test^="style-part-"]').exists()).toBe(false)
    const block = { id: 'l1', type: 'links', data: {}, settings: {} }
    const several = mount(BlockInspector, {
      props: {
        block,
        blockType: links,
        blocks: [block, { ...block, id: 'l2' }],
        blockTypes: [links, links],
        schema,
        classes: [],
        activeBreakpoint: 'base',
      },
    })
    expect(several.find('[data-test^="style-part-"]').exists()).toBe(false)
  })
})
