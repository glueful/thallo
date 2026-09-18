// The Layout tab's two questions (container-layout spec §5): what mode is in force at the active
// breakpoint, and which settings that mode leaves dormant.
import { describe, expect, it } from 'vitest'
import { dormantPaths, effectiveDisplay } from '@/editor/inspector/layoutContext'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { StyleClassRef } from '@/style/types'

const block = (style: Record<string, unknown>): BlockInstance =>
  ({ id: 'b1', type: 'container', data: {}, settings: { style } }) as unknown as BlockInstance

const choice = (value: string) => ({ type: 'choice', value })

describe('effectiveDisplay', () => {
  it('is the theme default when nothing is declared', () => {
    // A flex column is the theme's own display (spec §11.1): the container stacks its children
    // until told otherwise, and there is no third mode for "stack".
    expect(effectiveDisplay(block({}), 'base', [])).toBe('flex')
    expect(effectiveDisplay(block({}), 'lg', [])).toBe('flex')
  })

  it('inherits a base value into the wider breakpoints', () => {
    const b = block({ layout: { display: { base: choice('flex') } } })
    expect(effectiveDisplay(b, 'base', [])).toBe('flex')
    expect(effectiveDisplay(b, 'md', [])).toBe('flex')
    expect(effectiveDisplay(b, 'lg', [])).toBe('flex')
  })

  it('takes the narrowest declaration at or below the breakpoint', () => {
    const b = block({ layout: { display: { base: choice('flex'), md: choice('grid') } } })
    expect(effectiveDisplay(b, 'base', [])).toBe('flex')
    expect(effectiveDisplay(b, 'md', [])).toBe('grid')
    expect(effectiveDisplay(b, 'lg', [])).toBe('grid')
  })

  it('reads a value supplied by a style class', () => {
    // A class is a layer below the block's own settings, and the controls follow what is in force
    // however it got there.
    const classes: StyleClassRef[] = [
      { id: 'c1', style: { layout: { display: { lg: choice('grid') } } } },
    ]
    const b = block({ layout: { display: { base: choice('flex') } } })
    expect(effectiveDisplay(b, 'base', classes)).toBe('flex')
    expect(effectiveDisplay(b, 'lg', classes)).toBe('grid')
  })

  it('returns flex for a reset, which is the theme default again', () => {
    const b = block({ layout: { display: { base: choice('grid'), md: { type: 'reset' } } } })
    expect(effectiveDisplay(b, 'base', [])).toBe('grid')
    expect(effectiveDisplay(b, 'md', [])).toBe('flex')
    expect(effectiveDisplay(b, 'lg', [])).toBe('flex')
  })

  it('does not take a stored value the contract no longer offers for a mode', () => {
    // A `block` from the one release that offered it is not a display (spec §11.1): the controls
    // follow the theme default, and Task 1.2's notice is what tells the author it is there.
    const b = block({ layout: { display: { base: choice('block') } } })
    expect(effectiveDisplay(b, 'base', [])).toBe('flex')
  })

  it('has no display at all when the block is not a container', () => {
    const leaf = { id: 'h1', type: 'heading', data: {}, settings: {} } as unknown as BlockInstance
    expect(effectiveDisplay(leaf, 'base', [])).toBe('flex')
  })
})

describe('dormantPaths', () => {
  it('names the parent settings the current mode ignores', () => {
    // Switching grid → flex must not delete the track count; the tab discloses that it is retained
    // and inert (spec §5).
    const b = block({
      layout: {
        display: { base: choice('flex') },
        columns: { base: choice('3') },
        direction: { base: choice('row') },
      },
    })
    expect(dormantPaths(b, 'base', [], 'parent')).toEqual(['layout.columns'])
  })

  it('names the flex settings a grid parent ignores', () => {
    const b = block({
      layout: {
        display: { base: choice('grid') },
        direction: { base: choice('column') },
        wrap: { base: choice('wrap') },
        columns: { base: choice('2') },
      },
    })
    expect(dormantPaths(b, 'base', [], 'parent')).toEqual(['layout.direction', 'layout.wrap'])
  })

  it('counts values supplied by a class, not only local declarations', () => {
    const classes: StyleClassRef[] = [
      { id: 'c1', style: { layout: { columns: { base: choice('4') } } } },
    ]
    const b = block({ layout: { display: { base: choice('flex') } } })
    expect(dormantPaths(b, 'base', classes, 'parent')).toEqual(['layout.columns'])
  })

  it('an untouched container is a flex column: only the grid settings are dormant', () => {
    const b = block({
      layout: { columns: { base: choice('2') }, direction: { base: choice('row') } },
    })
    // Direction is in force — the default mode is flex — so it is not named.
    expect(dormantPaths(b, 'base', [], 'parent')).toEqual(['layout.columns'])
  })

  it('names the item settings the parent mode ignores', () => {
    // The roles are separate: an item's dormancy is judged against its PARENT's mode.
    const item = {
      id: 'h1',
      type: 'heading',
      data: {},
      settings: {
        style: { layout: { span: { base: choice('2') }, basis: { base: choice('1/2') } } },
      },
    } as unknown as BlockInstance
    expect(dormantPaths(item, 'base', [], 'grid')).toEqual(['layout.basis'])
    expect(dormantPaths(item, 'base', [], 'flex')).toEqual(['layout.span'])
  })

  it('is empty when nothing is retained', () => {
    expect(
      dormantPaths(block({ layout: { display: { base: choice('flex') } } }), 'base', [], 'parent'),
    ).toEqual([])
  })
})
