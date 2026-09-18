// The class Layout tab (container-layout spec §12.2–12.3): every layout property a class can
// hold, always present and editable, labelled by where it applies — and nothing shown, hidden or
// described on the strength of a mode, a parent or a default the class does not have.
import { readFileSync } from 'node:fs'
import { resolve as resolvePath } from 'node:path'
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ClassLayoutTab from '@/pages/settings/style-classes/components/ClassLayoutTab.vue'
import {
  CLASS_LAYOUT_SECTIONS,
  placedLayoutPaths,
  unplacedLayoutPaths,
} from '@/pages/settings/style-classes/components/classLayoutSections'
import { pathsForTab } from '@/editor/inspector/tabMap'
import { classEditorSchema } from './helpers/classEditorSchema'

type Bp = 'base' | 'md' | 'lg'
const choice = (value: string) => ({ type: 'choice', value })
const token = (value: string) => ({ type: 'token', value })
const RESET = { type: 'reset' }

const schema = classEditorSchema()
const layoutPaths = pathsForTab(
  schema.properties.map((row) => row.path),
  'layout',
)

function mountTab(style: Record<string, unknown>, bp: Bp = 'base', using = schema) {
  return mount(ClassLayoutTab, {
    props: { modelStyle: style, schema: using, activeBreakpoint: bp },
    attachTo: document.body,
  })
}
type Tab = ReturnType<typeof mountTab>
const row = (w: Tab, path: string) => w.find(`[data-test="style-field-${path}"]`)
const stateOf = (w: Tab, path: string) => row(w, path).find('[data-test="style-state"]').text()
/** Pressed VALUES: within each row's chooser, never the breakpoint chips or a box's link toggle. */
const pressedValues = (w: Tab) =>
  w
    .findAll('[data-test="style-chooser"]')
    .flatMap((chooser) => chooser.findAll('[aria-pressed="true"]'))

describe('coverage and placement', () => {
  it('every Layout path has exactly one control — width, placement and content alignment among them', () => {
    const w = mountTab({})
    expect(layoutPaths).toEqual(
      expect.arrayContaining(['width', 'alignment.self', 'alignment.content']),
    )
    for (const path of layoutPaths) {
      expect(w.findAll(`[data-test="style-field-${path}"]`), path).toHaveLength(1)
    }
    w.unmount()
  })

  it('every Layout path is placed DELIBERATELY: the fallback covers none of the current contract', () => {
    // Coverage alone cannot show this — the fallback renders whatever nobody placed. A Layout
    // property added to the contract fails HERE until someone decides where it belongs.
    const placed = placedLayoutPaths()
    expect([...placed].sort()).toEqual([...layoutPaths].sort()) // none missing, none extra
    expect(new Set(placed).size).toBe(placed.length) // none twice
    expect(unplacedLayoutPaths(layoutPaths)).toEqual([])
    const w = mountTab({})
    expect(w.findAll('[data-fallback="true"]')).toHaveLength(0)
    w.unmount()
  })

  it('a property nobody has placed is still editable, at the end of Box, and the placement check names it', async () => {
    const synthetic = classEditorSchema([
      {
        path: 'layout.synthetic',
        group: 'layout',
        kinds: ['choice', 'reset'],
        responsive: true,
        token_domain: null,
        choices: ['one', 'two'],
      },
    ])
    const paths = pathsForTab(
      synthetic.properties.map((r) => r.path),
      'layout',
    )
    expect(unplacedLayoutPaths(paths)).toEqual(['layout.synthetic'])

    const w = mountTab({}, 'md', synthetic)
    const fallback = w.find('[data-test="class-layout-group-box"] [data-fallback="true"]')
    expect(fallback.attributes('data-test')).toBe('style-field-layout.synthetic')
    expect(fallback.find('[data-test="style-state"]').text()).toBe('Not set in this class')
    expect(fallback.find('[data-test="style-use-theme-default"]').exists()).toBe(true)
    await fallback.find('[data-test="choice-two"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.synthetic', 'md', choice('two')]])
    w.unmount()
  })

  it('the sections are Container, Box, As an item — in that order', () => {
    expect(CLASS_LAYOUT_SECTIONS.map((s) => s.key)).toEqual(['container', 'box', 'item'])
    const w = mountTab({})
    expect(
      w.findAll('[data-test^="class-layout-group-"]').map((s) => s.attributes('data-test')),
    ).toEqual(['class-layout-group-container', 'class-layout-group-box', 'class-layout-group-item'])
    w.unmount()
  })
})

describe('no borrowed context', () => {
  it("is its own component: it reaches for none of the block tab's contextual machinery", () => {
    // The boundary of spec §12.2, held rather than hoped for. The block tab's rules about what
    // shows and when — the mode in force, dormancy, theme defaults, Fill — stay in the block
    // editor. Shared here: field controls, property names, the tab map.
    const source = readFileSync(
      resolvePath(__dirname, '../pages/settings/style-classes/components/ClassLayoutTab.vue'),
      'utf8',
    )
    const imports = [...source.matchAll(/from '([^']+)'/g)].map((m) => m[1]!)
    for (const forbidden of [
      'LayoutTab.vue',
      'layoutContext',
      'gridFill',
      'gridOccupancy',
      'structure/presets',
    ]) {
      expect(
        imports.filter((path) => path.includes(forbidden)),
        forbidden,
      ).toEqual([])
    }
    expect(imports).toEqual(
      expect.arrayContaining(['@/editor/inspector/tabMap', '@/editor/inspector/layoutLabels']),
    )
  })

  it('with nothing set, everything is there, enabled and labelled — and nothing is presented as a value', () => {
    const w = mountTab({}, 'md')
    const labels = {
      'class-layout-family-flex': 'Applies in Flex',
      'class-layout-family-grid': 'Applies in Grid',
      'class-layout-family-grid-parent': 'Applies in a Grid parent',
      'class-layout-family-flex-parent': 'Applies in a Flex parent',
    }
    for (const [test, label] of Object.entries(labels)) {
      expect(w.find(`[data-test="${test}"]`).text(), test).toContain(label)
    }
    expect(w.find('[data-test="class-layout-item-note-grid"]').text()).toBe(
      "These apply wherever the block's parent lays out its children as a grid.",
    )
    expect(w.find('[data-test="class-layout-item-note-flex"]').text()).toBe(
      "These apply wherever the block's parent lays out its children as flex.",
    )
    for (const path of layoutPaths.filter((p) => !p.startsWith('layout.gap.'))) {
      expect(stateOf(w, path), path).toBe('Not set in this class')
    }
    expect(w.findAll('button[disabled]')).toHaveLength(0)

    // Nothing the block tab owns.
    expect(w.findAll('[data-default="true"]')).toHaveLength(0)
    expect(w.find('[data-test="layout-fill-cells"]').exists()).toBe(false)
    expect(w.findAll('[data-test^="layout-dormant"]')).toHaveLength(0)
    expect(w.findAll('[data-test^="class-layout-retained-"]')).toHaveLength(0)

    // No pressed VALUE — and the two things that are pressed, and must stay so, prove the scoping.
    expect(pressedValues(w)).toHaveLength(0)
    expect(w.find('[data-test="class-layout-breakpoint-md"]').attributes('aria-pressed')).toBe(
      'true',
    )
    expect(w.find('[data-test="box-link"]').attributes('aria-pressed')).toBe('true')
    w.unmount()
  })

  it('with Grid set the Flex family is still there and still editable', async () => {
    const w = mountTab({ layout: { display: { base: choice('grid') } } })
    const flex = w.find('[data-test="class-layout-family-flex"]')
    await flex.find('[data-test="choice-column"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.direction', 'base', choice('column')]])
    w.unmount()
  })

  it('choosing a mode writes one declaration, whatever the other family holds', async () => {
    const w = mountTab({
      layout: {
        display: { base: choice('flex') },
        direction: { base: choice('row') },
        wrap: { md: choice('wrap') },
      },
    })
    await row(w, 'layout.display').find('[data-test="choice-grid"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.display', 'base', choice('grid')]])
    w.unmount()
  })

  it('the breakpoint is chosen once, at the top, and the rows carry no chips of their own', async () => {
    const w = mountTab({}, 'base')
    await w.find('[data-test="class-layout-breakpoint-lg"]').trigger('click')
    expect(w.emitted('update:activeBreakpoint')).toEqual([['lg']])
    expect(w.findAll('[data-test^="breakpoint-"]')).toHaveLength(0)
    w.unmount()
  })
})

describe('what the tab says about applicability', () => {
  const FLEX_NOTE =
    "Flex settings are retained. They apply wherever the block's effective layout is Flex."
  const GRID_NOTE =
    "Grid settings are retained. They apply wherever the block's effective layout is Grid."

  it('a class that sets Grid and holds Flex settings says they are retained — and predicts nothing', () => {
    const style = {
      layout: { display: { base: choice('grid') }, direction: { md: choice('row') } },
    }
    for (const bp of ['base', 'md', 'lg'] as const) {
      const w = mountTab(style, bp)
      expect(w.find('[data-test="class-layout-retained-flex"]').text()).toBe(FLEX_NOTE)
      expect(w.find('[data-test="class-layout-retained-grid"]').exists()).toBe(false)
      expect(w.text()).not.toMatch(/unused|ignored|dormant/i)
      w.unmount()
    }
  })

  it('without the stored Flex setting there is no note', () => {
    const w = mountTab({ layout: { display: { base: choice('grid') } } })
    expect(w.find('[data-test="class-layout-retained-flex"]').exists()).toBe(false)
    w.unmount()
  })

  it('a class that sets Flex and holds tracks says the Grid settings are retained', () => {
    const w = mountTab({
      layout: { display: { base: choice('flex') }, columns: { lg: choice('3') } },
    })
    expect(w.find('[data-test="class-layout-retained-grid"]').text()).toBe(GRID_NOTE)
    expect(w.find('[data-test="class-layout-retained-flex"]').exists()).toBe(false)
    w.unmount()
  })

  it('a class that declares no mode says nothing: there is no "other" family', () => {
    const w = mountTab({
      layout: { direction: { base: choice('row') }, columns: { base: choice('3') } },
    })
    expect(w.findAll('[data-test^="class-layout-retained-"]')).toHaveLength(0)
    w.unmount()
  })

  it('a mode declared only from md says nothing at base, where this class declares none', () => {
    const style = {
      layout: { display: { md: choice('grid') }, direction: { base: choice('row') } },
    }
    const base = mountTab(style, 'base')
    expect(base.findAll('[data-test^="class-layout-retained-"]')).toHaveLength(0)
    base.unmount()
    const lg = mountTab(style, 'lg')
    expect(lg.find('[data-test="class-layout-retained-flex"]').exists()).toBe(true)
    lg.unmount()
  })
})

describe('every control carries the states and both actions — the icon and track choosers too', () => {
  it('direction: inherited names its breakpoint and shows the inherited icon pressed, with nothing to remove', () => {
    const w = mountTab({ layout: { direction: { base: choice('row') } } }, 'md')
    expect(stateOf(w, 'layout.direction')).toBe('Inherited from base')
    const pressed = row(w, 'layout.direction')
      .find('[data-test="style-chooser"]')
      .findAll('[aria-pressed="true"]')
    expect(pressed.map((b) => b.attributes('data-test'))).toEqual(['choice-row'])
    expect(row(w, 'layout.direction').find('[data-test="style-remove"]').exists()).toBe(false)
    w.unmount()
  })

  it('direction and columns: an inherited reset presses nothing and says where it comes from', () => {
    const w = mountTab({ layout: { direction: { base: RESET }, columns: { base: RESET } } }, 'md')
    for (const path of ['layout.direction', 'layout.columns']) {
      expect(stateOf(w, path)).toBe('Theme default, from base')
      expect(
        row(w, path).find('[data-test="style-chooser"]').findAll('[aria-pressed="true"]'),
      ).toHaveLength(0)
    }
    w.unmount()
  })

  it('columns: Use theme default and Remove write what they say', async () => {
    const w = mountTab({ layout: { columns: { md: choice('3') } } }, 'md')
    const columns = row(w, 'layout.columns')
    expect(columns.find('[data-test="track-3"]').attributes('aria-pressed')).toBe('true')
    await columns.find('[data-test="style-use-theme-default"]').trigger('click')
    await columns.find('[data-test="style-remove"]').trigger('click')
    await columns.find('[data-test="track-2"]').trigger('click')
    expect(w.emitted('set')).toEqual([
      ['layout.columns', 'md', RESET],
      ['layout.columns', 'md', null],
      ['layout.columns', 'md', choice('2')],
    ])
    w.unmount()
  })

  it('wrap is an icon choice with the same wrapper', async () => {
    const w = mountTab({}, 'lg')
    // The icon chooser, not the plain one: they share test ids, so it is told apart by what only
    // it has — the option described in words on hover, and drawn rather than named.
    const option = row(w, 'layout.wrap').find('[data-test="choice-wrap"]')
    expect(option.attributes('title')).toBe('Wrap onto more lines')
    expect(option.text()).not.toContain('wrap')
    expect(
      row(w, 'layout.direction').find('[data-test="choice-row-reverse"]').text(),
    ).not.toContain('row')
    await row(w, 'layout.wrap').find('[data-test="choice-wrap"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.wrap', 'lg', choice('wrap')]])
    expect(row(w, 'layout.wrap').find('[data-test="style-use-theme-default"]').exists()).toBe(true)
    w.unmount()
  })

  it('the gap is one box of two sides, with the class states', async () => {
    const w = mountTab(
      {
        layout: {
          gap: { row: { base: token('spacing.lg') }, column: { base: token('spacing.lg') } },
        },
      },
      'md',
    )
    const cell = w.find('[data-test="box-cell-layout.gap.row"] [data-test="style-state"]')
    expect(cell.text()).toBe('Inherited from base')
    w.unmount()
  })
})

describe('a property that is not responsive', () => {
  it('layout.overflow applies at all sizes and is written bare from every breakpoint', async () => {
    for (const bp of ['base', 'md', 'lg'] as const) {
      const w = mountTab({}, bp)
      const overflow = row(w, 'layout.overflow')
      expect(overflow.find('[data-test="style-all-sizes"]').text()).toBe('Applies at all sizes')
      await overflow.find('[data-test="choice-hidden"]').trigger('click')
      expect(w.emitted('set')).toEqual([['layout.overflow', null, choice('hidden')]])
      w.unmount()
    }
  })
})

describe('a stored mode the contract does not offer', () => {
  it('is shown as invalid with neither mode pressed, claims no family, and is not rewritten by being looked at', async () => {
    const w = mountTab(
      { layout: { display: { md: choice('block') }, direction: { base: choice('row') } } },
      'md',
    )
    expect(stateOf(w, 'layout.display')).toBe('Invalid')
    expect(row(w, 'layout.display').find('[data-test="style-invalid-value"]').text()).toBe(
      'Stored: block',
    )
    expect(
      row(w, 'layout.display').find('[data-test="style-chooser"]').findAll('[aria-pressed="true"]'),
    ).toHaveLength(0)
    expect(w.findAll('[data-test^="class-layout-retained-"]')).toHaveLength(0)
    for (const bp of ['lg', 'base', 'md'] as const) await w.setProps({ activeBreakpoint: bp })
    expect(w.emitted('set')).toBeUndefined()
    expect(w.emitted('set-all')).toBeUndefined()
    w.unmount()
  })
})
