// Presets as pure plans (container-layout spec §6.4, §6.5, §7.2): a preset writes the complete
// responsive result for every path it owns, so a value sitting at one breakpoint — the block's own
// or a style class's — cannot defeat the arrangement.
import { describe, expect, it } from 'vitest'
import { planPreset, presetDepth, PRESETS } from '@/editor/structure/presets'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { StyleClassRef } from '@/style/types'
import { resolve } from '@/style/resolver'
import { propertyDefinition } from '@/style/schema'

const container = (id: string, style: Record<string, unknown> = {}): BlockInstance =>
  ({
    id,
    type: 'container',
    data: { content: [] },
    settings: { style },
  }) as unknown as BlockInstance

const choice = (value: string) => ({ type: 'choice', value })
const token = (value: string) => ({ type: 'token', value })

/** The settings operations of a plan, by path and breakpoint. */
function settingsOf(plan: ReturnType<typeof planPreset>) {
  return plan!.operations.filter((op) => op.type === 'SetSetting')
}
function valuesAt(plan: ReturnType<typeof planPreset>, path: string) {
  return Object.fromEntries(
    settingsOf(plan)
      .filter((op) => op.path === path)
      .map((op) => [op.breakpoint ?? 'base', op.to]),
  )
}

describe('a two-column preset', () => {
  it('writes its owned paths at every breakpoint, stacking on mobile', () => {
    const plan = planPreset('cols-33-67', container('c1'), [])!
    expect(valuesAt(plan, 'layout.display')).toEqual({
      base: { present: true, value: choice('grid') },
      md: { present: true, value: choice('grid') },
      lg: { present: true, value: choice('grid') },
    })
    // Multi-column presets stack on mobile (spec §6.5): one track at base, the split above it.
    expect(valuesAt(plan, 'layout.columns')).toEqual({
      base: { present: true, value: choice('1') },
      md: { present: true, value: choice('1-2') },
      lg: { present: true, value: choice('1-2') },
    })
    for (const gap of ['layout.gap.column', 'layout.gap.row']) {
      expect(valuesAt(plan, gap)).toEqual({
        base: { present: true, value: token('spacing.lg') },
        md: { present: true, value: token('spacing.lg') },
        lg: { present: true, value: token('spacing.lg') },
      })
    }
  })

  it('asks for two column containers in the container content', () => {
    const plan = planPreset('cols-33-67', container('c1'), [])!
    expect(plan.children.map((child) => child.type)).toEqual(['container', 'container'])
    for (const [index, child] of plan.children.entries()) {
      expect(child.position).toEqual({ parent: 'c1', slot: 'content', index })
      // A column container reproduces today's Columns rhythm (spec §6.5).
      expect(child.settings.style).toMatchObject({
        layout: {
          display: { base: choice('flex'), md: choice('flex'), lg: choice('flex') },
          direction: { base: choice('column'), md: choice('column'), lg: choice('column') },
          gap: { row: { base: token('spacing.md') } },
        },
      })
    }
  })

  it('starts every write from the value that was there', () => {
    const before = container('c1', {
      layout: { columns: { lg: choice('4') }, display: { base: choice('flex') } },
    })
    const plan = planPreset('cols-33-67', before, [])!
    const columnsLg = settingsOf(plan).find(
      (op) => op.path === 'layout.columns' && op.breakpoint === 'lg',
    )!
    expect(columnsLg.from).toEqual({ present: true, value: choice('4') })
    const columnsBase = settingsOf(plan).find(
      (op) => op.path === 'layout.columns' && op.breakpoint === 'base',
    )!
    // Absent, not null: undo has to restore the absence exactly (spec §6.4).
    expect(columnsBase.from).toEqual({ present: false })
  })
})

describe('a style class cannot defeat a preset', () => {
  it('resolves to the preset result at every breakpoint despite a conflicting class', () => {
    // The class wins over nothing here: the preset writes an explicit value at each breakpoint, so
    // the instance layer sits above the class at all three (spec §6.4).
    const classes: StyleClassRef[] = [
      {
        id: 'wide',
        style: { layout: { columns: { lg: choice('3') }, display: { lg: choice('flex') } } },
      },
    ]
    const block = container('c1')
    const plan = planPreset('cols-33-67', block, classes)!

    // Apply the plan's instance writes and resolve the result through the same cascade the
    // renderer uses.
    const style: Record<string, unknown> = {}
    for (const op of settingsOf(plan)) {
      if (!op.to.present) continue
      const segments = ['style', ...op.path.split('.'), op.breakpoint ?? 'base'].slice(1)
      let node = style
      for (const segment of segments.slice(0, -1)) {
        node[segment] = (node[segment] as Record<string, unknown>) ?? {}
        node = node[segment] as Record<string, unknown>
      }
      node[segments[segments.length - 1]!] = op.to.value
    }
    const resolved = (path: string, bp: 'base' | 'md' | 'lg') => {
      const def = propertyDefinition(path)!
      const out = resolve(path, classes, style, def) as Record<string, { value: unknown }>
      return out[bp]?.value
    }
    expect(resolved('layout.columns', 'lg')).toEqual(choice('1-2'))
    expect(resolved('layout.display', 'lg')).toEqual(choice('grid'))
  })

  it('every from equals the instance value, never the class value', () => {
    const classes: StyleClassRef[] = [
      { id: 'wide', style: { layout: { columns: { lg: choice('3') } } } },
    ]
    const plan = planPreset('cols-33-67', container('c1'), classes)!
    for (const op of settingsOf(plan)) {
      expect(op.from, `${op.path} @${op.breakpoint}`).toEqual({ present: false })
    }
  })
})

describe('Stack', () => {
  it('records nothing for a container that already stacks', () => {
    // Block is the theme default, so a fresh container resolves to it everywhere: dismissing the
    // picker with Stack changes nothing and records nothing (spec §6.4).
    const plan = planPreset('stack', container('c1'), [])
    expect(plan!.operations).toEqual([])
    expect(plan!.children).toEqual([])
  })

  it('writes flex only where the RESOLVED display is not already flex', () => {
    // A stack is a flex column (spec §6.5, §11.1). A class makes this container a grid at lg.
    const classes: StyleClassRef[] = [
      { id: 'tiles', style: { layout: { display: { lg: choice('grid') } } } },
    ]
    const plan = planPreset('stack', container('c1'), classes)!
    expect(settingsOf(plan).map((op) => [op.path, op.breakpoint])).toEqual([
      ['layout.display', 'lg'],
    ])
    expect(settingsOf(plan)[0]!.to).toEqual({ present: true, value: choice('flex') })
  })

  it('writes the column direction wherever a row is in force, inheritance included', () => {
    // Row at md is in force at md AND lg, so both need an explicit column.
    const plan = planPreset(
      'stack',
      container('c1', { layout: { direction: { md: choice('row') } } }),
      [],
    )!
    expect(settingsOf(plan).map((op) => [op.path, op.breakpoint])).toEqual([
      ['layout.direction', 'md'],
      ['layout.direction', 'lg'],
    ])
    for (const op of settingsOf(plan)) {
      expect(op.to).toEqual({ present: true, value: choice('column') })
    }
  })

  it('is a no-op over an explicit flex column, which is already a stack', () => {
    const plan = planPreset(
      'stack',
      container('c1', {
        layout: { display: { base: choice('flex') }, direction: { base: choice('column') } },
      }),
      [],
    )!
    expect(plan.operations).toEqual([])
  })
})

describe('the Section presets', () => {
  it('writes the element, the band padding, the measure and a track reset', () => {
    const plan = planPreset('section', container('c1'), [])!
    const element = plan.operations.find((op) => op.type === 'SetField')!
    expect(element).toMatchObject({ field: 'element', to: { present: true, value: 'section' } })

    for (const side of ['top', 'bottom']) {
      expect(valuesAt(plan, `spacing.padding.${side}`)).toEqual({
        base: { present: true, value: token('spacing.3xl') },
        md: { present: true, value: token('spacing.3xl') },
        lg: { present: true, value: token('spacing.3xl') },
      })
    }
    expect(valuesAt(plan, 'layout.content_width')).toEqual({
      base: { present: true, value: token('width.container') },
      md: { present: true, value: token('width.container') },
      lg: { present: true, value: token('width.container') },
    })
    // A path the vertical Section does not use is reset at every breakpoint, never left alone
    // (spec §7.2), so a class cannot supply tracks the composition never asked for.
    expect(valuesAt(plan, 'layout.columns')).toEqual({
      base: { present: true, value: { type: 'reset' } },
      md: { present: true, value: { type: 'reset' } },
      lg: { present: true, value: { type: 'reset' } },
    })
  })

  it('asks for the header group, the content area and the links row', () => {
    const plan = planPreset('section', container('c1'), [])!
    expect(plan.children.map((c) => c.type)).toEqual(['container', 'container', 'container'])
    expect(plan.children.map((c) => c.position.index)).toEqual([0, 1, 2])
  })

  it('section-split is a flex column that becomes two tracks at lg', () => {
    const plan = planPreset('section-split', container('c1'), [])!
    expect(valuesAt(plan, 'layout.display')).toEqual({
      base: { present: true, value: choice('flex') },
      md: { present: true, value: choice('flex') },
      lg: { present: true, value: choice('grid') },
    })
    expect(valuesAt(plan, 'layout.columns')).toEqual({
      base: { present: true, value: { type: 'reset' } },
      md: { present: true, value: { type: 'reset' } },
      lg: { present: true, value: choice('2') },
    })
  })
})

describe('presetDepth', () => {
  // A block that holds blocks needs a level below it for them: the server refuses such a block's
  // list — even empty — when its items would sit below the cap. A preset's columns are exactly
  // that: empty containers. So their level counts, or the picker offers what Apply then refuses.
  const regionsOf = (slug: string): string[] => (slug === 'container' ? ['content'] : [])

  it('is the height of the subtree the preset creates, counting the level an empty column needs', () => {
    expect(presetDepth('stack', regionsOf)).toBe(1)
    // container → column → the level the column's own content needs
    expect(presetDepth('cols-33-67', regionsOf)).toBe(3)
    expect(presetDepth('cols-quarters', regionsOf)).toBe(3)
    // section → header group → its blocks; the empty body container needs the same three
    expect(presetDepth('section', regionsOf)).toBe(3)
  })

  it('a type with no regions needs nothing below it', () => {
    expect(presetDepth('cols-33-67', () => [])).toBe(2)
  })

  it('is defined for every preset the picker can offer', () => {
    for (const key of Object.keys(PRESETS)) {
      expect(presetDepth(key, regionsOf), key).toBeGreaterThanOrEqual(1)
    }
  })
})
