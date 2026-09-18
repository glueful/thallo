// The shared field controls in a style class (container-layout spec §12.4): they say what the
// CLASS declares — set, inherited from a named breakpoint, an explicit reset, or not set — and
// offer Remove and Use theme default. In the block inspector, their default context, nothing
// changes: there a theme default really is what is in force.
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import ResponsiveField from '@/editor/inspector/controls/ResponsiveField.vue'
import BoxField from '@/editor/inspector/controls/BoxField.vue'
import type { StylePropertyRow } from '@/queries/styleSchema'

type Bp = 'base' | 'md' | 'lg'
const choice = (value: string) => ({ type: 'choice', value })
const token = (value: string) => ({ type: 'token', value })
const RESET = { type: 'reset' }

const direction: StylePropertyRow = {
  path: 'layout.direction',
  group: 'layout',
  kinds: ['choice', 'reset'],
  responsive: true,
  token_domain: null,
  choices: ['row', 'column', 'row-reverse', 'column-reverse'],
}
const display: StylePropertyRow = {
  path: 'layout.display',
  group: 'layout',
  kinds: ['choice', 'reset'],
  responsive: true,
  token_domain: null,
  choices: ['flex', 'grid'],
}
const overflow: StylePropertyRow = {
  path: 'layout.overflow',
  group: 'layout',
  kinds: ['choice', 'reset'],
  responsive: false,
  token_domain: null,
  choices: ['visible', 'hidden', 'auto'],
}
const radius: StylePropertyRow = {
  path: 'radius',
  group: 'radius',
  kinds: ['token', 'reset'],
  responsive: false,
  token_domain: 'radius',
  choices: null,
}
const gutter: StylePropertyRow = {
  path: 'layout.gutter',
  group: 'layout',
  kinds: ['token', 'reset'],
  responsive: true,
  token_domain: 'spacing',
  choices: null,
}
const gap = (side: 'column' | 'row'): StylePropertyRow => ({
  path: `layout.gap.${side}`,
  group: 'layout',
  kinds: ['token', 'reset'],
  responsive: true,
  token_domain: 'spacing',
  choices: null,
})
const vocabulary = {
  domains: { spacing: ['none', 'sm', 'lg'], radius: ['none', 'md', 'full'] },
  values: { 'spacing.lg': 'var(--space-4)', 'spacing.sm': '0.5rem', 'radius.md': '6px' },
}

function field(
  def: StylePropertyRow,
  style: Record<string, unknown>,
  bp: Bp = 'md',
  extra: Record<string, unknown> = {},
) {
  return mount(ResponsiveField, {
    props: {
      def,
      label: 'Field',
      style,
      classes: [],
      activeBreakpoint: bp,
      vocabulary,
      context: 'class',
      ...extra,
    } as never,
  })
}
function box(style: Record<string, unknown>, bp: Bp = 'md', context: 'class' | 'block' = 'class') {
  return mount(BoxField, {
    props: {
      label: 'Gap',
      sides: [
        { key: 'column', def: gap('column') },
        { key: 'row', def: gap('row') },
      ],
      style,
      classes: [],
      activeBreakpoint: bp,
      vocabulary,
      context,
    } as never,
    attachTo: document.body,
  })
}
/** Any mounted field: only `find` is used, so the helpers do not care which component it is. */
type Found = { find: ReturnType<typeof field>['find'] }
const state = (w: Found) => w.find('[data-test="style-state"]')
/** Pressed values WITHIN the value chooser: breakpoint chips and link toggles are not values. */
const pressedValues = (w: Found) =>
  w.find('[data-test="style-chooser"]').findAll('[aria-pressed="true"]')

describe('a responsive field in a class', () => {
  it('says what the class declares, in the pinned words', () => {
    const at = (style: Record<string, unknown>, bp: Bp) => state(field(direction, style, bp)).text()
    const dir = (decl: Record<string, unknown>) => ({ layout: { direction: decl } })
    expect(at({}, 'md')).toBe('Not set in this class')
    expect(at(dir({ md: choice('row') }), 'md')).toBe('Set')
    expect(at(dir({ base: choice('row') }), 'md')).toBe('Inherited from base')
    expect(at(dir({ base: choice('row'), md: choice('column') }), 'lg')).toBe('Inherited from md')
    expect(at(dir({ md: RESET }), 'md')).toBe('Theme default, set here')
    expect(at(dir({ base: RESET }), 'md')).toBe('Theme default, from base')
    expect(
      state(field(gutter, { layout: { gutter: { base: token('spacing.lg') } } }, 'lg')).text(),
    ).toBe('Inherited from base')
  })

  it('carries the kind for styling and tests, and never a class-source badge', () => {
    const w = field(direction, { layout: { direction: { base: RESET } } }, 'md')
    expect(state(w).attributes('data-kind')).toBe('inherited-reset')
    expect(w.find('[data-test="style-source"]').exists()).toBe(false)
  })

  it('not set: no value is pressed, no value is named and there is nothing to remove', () => {
    const w = field(direction, {}, 'md')
    expect(pressedValues(w)).toHaveLength(0)
    expect(w.find('[data-test="style-remove"]').exists()).toBe(false)
    expect(w.find('[data-test="style-use-theme-default"]').exists()).toBe(true)
    // The scoping is real: the active breakpoint chip IS pressed, and is not a value.
    expect(w.find('[data-test="breakpoint-md"]').attributes('aria-pressed')).toBe('true')
  })

  it('an inherited value is shown pressed — it is what the class supplies here — but cannot be removed here', () => {
    const w = field(direction, { layout: { direction: { base: choice('row') } } }, 'md')
    expect(pressedValues(w).map((b) => b.attributes('data-test'))).toEqual(['choice-row'])
    expect(w.find('[data-test="style-remove"]').exists()).toBe(false)
  })

  it('a reset, here or inherited, presses nothing; only one declared HERE can be removed', () => {
    const here = field(direction, { layout: { direction: { md: RESET } } }, 'md')
    expect(pressedValues(here)).toHaveLength(0)
    expect(here.find('[data-test="style-remove"]').exists()).toBe(true)
    const inherited = field(direction, { layout: { direction: { base: RESET } } }, 'md')
    expect(pressedValues(inherited)).toHaveLength(0)
    expect(inherited.find('[data-test="style-remove"]').exists()).toBe(false)
  })

  it('Remove deletes the declaration at the breakpoint being edited, which may reveal an earlier one', async () => {
    const style = { layout: { direction: { base: choice('row'), md: choice('column') } } }
    const w = field(direction, style, 'md')
    expect(w.find('[data-test="style-remove"]').text()).toBe('Remove')
    await w.find('[data-test="style-remove"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.direction', 'md', null]])
    // What the author then sees: not "unset", but the base declaration showing through.
    const after = field(direction, { layout: { direction: { base: choice('row') } } }, 'md')
    expect(state(after).text()).toBe('Inherited from base')
  })

  it('Use theme default writes an explicit reset at the breakpoint being edited', async () => {
    const w = field(direction, { layout: { direction: { base: choice('row') } } }, 'md')
    expect(w.find('[data-test="style-use-theme-default"]').text()).toBe('Use theme default')
    await w.find('[data-test="style-use-theme-default"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.direction', 'md', RESET]])
    const after = field(
      direction,
      { layout: { direction: { base: choice('row'), md: RESET } } },
      'md',
    )
    expect(state(after).text()).toBe('Theme default, set here')
    expect(after.find('[data-test="style-remove"]').exists()).toBe(true)
  })

  it("the block inspector's actions are not offered here", () => {
    const w = field(direction, { layout: { direction: { md: choice('row') } } }, 'md')
    expect(w.find('[data-test="style-reset"]').exists()).toBe(false)
    expect(w.find('[data-test="style-clear"]').exists()).toBe(false)
  })
})

describe('a property that is not responsive, in a class', () => {
  for (const [name, def, style, picked, click] of [
    [
      'layout.overflow',
      overflow,
      { layout: { overflow: choice('hidden') } },
      choice('auto'),
      'choice-auto',
    ],
    ['radius', radius, { radius: token('radius.md') }, token('radius.full'), 'token-radius.full'],
  ] as const) {
    it(`${name}: applies at all sizes, stored bare, the same from every breakpoint`, async () => {
      for (const bp of ['base', 'md', 'lg'] as const) {
        const w = field(def, style, bp)
        expect(w.find('[data-test="style-all-sizes"]').text()).toBe('Applies at all sizes')
        expect(w.find('[role="group"][aria-label="Breakpoint"]').exists()).toBe(false)
        expect(w.find('[data-test="style-apply-all"]').exists()).toBe(false)
        expect(state(w).text()).toBe('Set')

        await w.find(`[data-test="${click}"]`).trigger('click')
        await w.find('[data-test="style-use-theme-default"]').trigger('click')
        await w.find('[data-test="style-remove"]').trigger('click')
        expect(w.emitted('set')).toEqual([
          [def.path, null, picked],
          [def.path, null, RESET],
          [def.path, null, null],
        ])
      }
    })
  }

  it('never shows an inherited state', () => {
    expect(state(field(overflow, {}, 'lg')).text()).toBe('Not set in this class')
    expect(state(field(overflow, { layout: { overflow: RESET } }, 'lg')).text()).toBe(
      'Theme default, set here',
    )
  })
})

describe('a stored value the contract does not offer', () => {
  it('is named as invalid with nothing pressed, and the chooser stays usable', async () => {
    const w = field(display, { layout: { display: { md: choice('block') } } }, 'md')
    expect(state(w).text()).toBe('Invalid')
    expect(w.find('[data-test="style-invalid-value"]').text()).toBe('Stored: block')
    expect(pressedValues(w)).toHaveLength(0)
    await w.find('[data-test="choice-flex"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.display', 'md', choice('flex')]])
  })

  it('is not rewritten by being looked at: mounting and changing breakpoint emit nothing', async () => {
    const w = field(display, { layout: { display: { md: choice('block') } } }, 'base')
    for (const bp of ['md', 'lg', 'base'] as const) await w.setProps({ activeBreakpoint: bp })
    expect(w.emitted('set')).toBeUndefined()
    expect(w.emitted('set-all')).toBeUndefined()
  })

  it('a token the vocabulary does not have is invalid too', () => {
    const w = field(gutter, { layout: { gutter: { md: token('spacing.huge') } } }, 'md')
    expect(state(w).text()).toBe('Invalid')
    expect(w.find('[data-test="style-invalid-value"]').text()).toBe('Stored: spacing.huge')
    expect(pressedValues(w)).toHaveLength(0)
  })
})

describe('the control slot', () => {
  it('hands a custom chooser the value and a pick that writes as the built-in one would', async () => {
    const w = mount(ResponsiveField, {
      props: {
        def: direction,
        label: 'Direction',
        style: { layout: { direction: { base: choice('row') } } },
        classes: [],
        activeBreakpoint: 'md',
        vocabulary,
        context: 'class',
      } as never,
      slots: {
        control: (slot: { value: string | null; pick: (raw: string) => void }) =>
          h('button', {
            'data-test': 'custom',
            'data-value': slot.value ?? '',
            onClick: () => slot.pick('column'),
          }),
      },
    })
    expect(w.find('[data-test="custom"]').attributes('data-value')).toBe('row')
    expect(w.find('[data-test="choice-row"]').exists()).toBe(false) // the slot replaces the built-in
    await w.find('[data-test="custom"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.direction', 'md', choice('column')]])
    // The state and the actions are the wrapper's, whatever the chooser is.
    expect(state(w).text()).toBe('Inherited from base')
    expect(w.find('[data-test="style-use-theme-default"]').exists()).toBe(true)
  })

  it('gives a custom chooser no value for a reset, an unset or an invalid property', () => {
    for (const style of [
      {},
      { layout: { direction: { base: RESET } } },
      { layout: { direction: { md: choice('sideways') } } },
    ]) {
      const w = mount(ResponsiveField, {
        props: {
          def: direction,
          label: 'D',
          style,
          classes: [],
          activeBreakpoint: 'md',
          vocabulary,
          context: 'class',
        } as never,
        slots: {
          control: (slot: { value: string | null }) =>
            h('i', { 'data-test': 'custom', 'data-value': String(slot.value) }),
        },
      })
      expect(w.find('[data-test="custom"]').attributes('data-value')).toBe('null')
    }
  })
})

describe('a box of sides in a class', () => {
  it('each cell carries its own state, and the open panel says it in words', async () => {
    const w = box(
      { layout: { gap: { row: { base: token('spacing.lg') }, column: { md: RESET } } } },
      'md',
    )
    const cell = (side: string) =>
      w.find(`[data-test="box-cell-layout.gap.${side}"] [data-test="style-state"]`)
    expect(cell('row').text()).toBe('Inherited from base')
    expect(cell('row').attributes('data-kind')).toBe('inherited')
    expect(cell('column').text()).toBe('Theme default, set here')
    await w.find('[data-test="box-link"]').trigger('click') // sides differ: make sure they are separate
    if (w.find('[data-test="box-link"]').attributes('aria-pressed') === 'true')
      await w.find('[data-test="box-link"]').trigger('click')
    await w.find('[data-test="box-cell-layout.gap.row"]').trigger('click')
    expect(w.find('[data-test="box-panel"] [data-test="style-state-label"]').text()).toBe(
      'Inherited from base',
    )
    w.unmount()
  })

  it('unlinked: Remove shows only for a side declared here, and removes that side alone', async () => {
    const w = box(
      {
        layout: {
          gap: { row: { base: token('spacing.lg') }, column: { md: token('spacing.sm') } },
        },
      },
      'md',
    )
    expect(w.find('[data-test="box-link"]').attributes('aria-pressed')).toBe('false') // sides differ
    await w.find('[data-test="box-cell-layout.gap.row"]').trigger('click')
    expect(w.find('[data-test="style-remove"]').exists()).toBe(false) // row is inherited at md
    await w.find('[data-test="box-cell-layout.gap.column"]').trigger('click')
    await w.find('[data-test="style-remove"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.gap.column', 'md', null]])
    w.unmount()
  })

  it('linked: Remove shows when ANY side is declared here, and touches only the sides that are', async () => {
    const w = box(
      {
        layout: {
          gap: {
            row: { base: token('spacing.lg') },
            column: { base: token('spacing.lg'), md: token('spacing.lg') },
          },
        },
      },
      'md',
    )
    expect(w.find('[data-test="box-link"]').attributes('aria-pressed')).toBe('true') // both read lg
    await w.find('[data-test="box-cell-layout.gap.row"]').trigger('click')
    await w.find('[data-test="style-remove"]').trigger('click')
    expect(w.emitted('set')).toEqual([['layout.gap.column', 'md', null]])
    w.unmount()
  })

  it('linked: Use theme default resets every side; a pick writes every side', async () => {
    const w = box({}, 'md')
    await w.find('[data-test="box-cell-layout.gap.row"]').trigger('click')
    expect(
      w
        .find('[data-test="box-panel"] [data-test="style-chooser"]')
        .findAll('[aria-pressed="true"]'),
    ).toHaveLength(0)
    await w.find('[data-test="style-use-theme-default"]').trigger('click')
    await w.find('[data-test="token-spacing.sm"]').trigger('click')
    expect(w.emitted('set')).toEqual([
      ['layout.gap.column', 'md', RESET],
      ['layout.gap.row', 'md', RESET],
      ['layout.gap.column', 'md', token('spacing.sm')],
      ['layout.gap.row', 'md', token('spacing.sm')],
    ])
    expect(w.find('[data-test="style-reset"]').exists()).toBe(false)
    expect(w.find('[data-test="style-clear"]').exists()).toBe(false)
    w.unmount()
  })
})

describe('the block inspector is unchanged (spec §12.4)', () => {
  it('with no context given, the labels and the actions are the ones it always had', async () => {
    const w = mount(ResponsiveField, {
      props: {
        def: direction,
        label: 'D',
        style: {},
        classes: [],
        activeBreakpoint: 'md',
        vocabulary,
      },
    })
    expect(state(w).text()).toBe('theme')
    expect(w.find('[data-test="style-reset"]').text()).toBe('Reset to theme')
    expect(w.find('[data-test="style-use-theme-default"]').exists()).toBe(false)
    expect(w.find('[data-test="style-remove"]').exists()).toBe(false)
    expect(w.find('[data-test="style-all-sizes"]').exists()).toBe(false)

    const set = mount(ResponsiveField, {
      props: {
        def: direction,
        label: 'D',
        style: { layout: { direction: { base: RESET, md: choice('row') } } },
        classes: [],
        activeBreakpoint: 'md',
        vocabulary,
      },
    })
    expect(state(set).text()).toBe('set')
    expect(set.find('[data-test="style-clear"]').text()).toBe('Clear')
    await set.setProps({ activeBreakpoint: 'base' })
    expect(state(set).text()).toBe('reset')
  })

  it('a block box keeps Reset to theme and Clear', async () => {
    const w = box(
      {
        layout: { gap: { row: { md: token('spacing.lg') }, column: { md: token('spacing.lg') } } },
      },
      'md',
      'block',
    )
    await w.find('[data-test="box-cell-layout.gap.row"]').trigger('click')
    expect(w.find('[data-test="style-reset"]').text()).toBe('Reset to theme')
    expect(w.find('[data-test="style-clear"]').text()).toBe('Clear')
    expect(w.find('[data-test="style-remove"]').exists()).toBe(false)
    expect(w.find('[data-test="box-cell-layout.gap.row"] [data-test="style-state"]').text()).toBe(
      'set',
    )
    w.unmount()
  })
})
