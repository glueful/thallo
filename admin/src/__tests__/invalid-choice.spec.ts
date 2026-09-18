// A stored choice the contract no longer offers (container-layout spec §11.1). The one case today
// is the `block` display a single release offered, but nothing here names a value: it is "a
// choice path whose stored value is not among its choices", wherever the cascade puts it in force.
import { describe, expect, it } from 'vitest'
import { REPLACEMENT, invalidChoiceAt, invalidChoicesIn } from '@/editor/inspector/layoutContext'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { StyleClassRef } from '@/style/types'

const block = (style: Record<string, unknown>): BlockInstance =>
  ({ id: 'c1', type: 'container', data: {}, settings: { style } }) as unknown as BlockInstance
const choice = (value: string) => ({ type: 'choice', value })

describe('invalidChoiceAt', () => {
  it('reports the stored value and the breakpoint it is DECLARED at, not the one being edited', () => {
    const b = block({ layout: { display: { md: choice('block') } } })
    expect(invalidChoiceAt('layout.display', b, 'lg', [])).toEqual({
      path: 'layout.display',
      value: 'block',
      breakpoint: 'md',
      source: 'instance',
    })
  })

  it('attributes a class-supplied value to the class', () => {
    const classes: StyleClassRef[] = [
      { id: 'stacked', style: { layout: { display: { base: choice('block') } } } },
    ]
    expect(invalidChoiceAt('layout.display', block({}), 'md', classes)).toEqual({
      path: 'layout.display',
      value: 'block',
      breakpoint: 'base',
      source: { classId: 'stacked' },
    })
  })

  it('is null for a valid value, for nothing declared, and below the declaring breakpoint', () => {
    expect(
      invalidChoiceAt(
        'layout.display',
        block({ layout: { display: { base: choice('grid') } } }),
        'lg',
        [],
      ),
    ).toBeNull()
    expect(invalidChoiceAt('layout.display', block({}), 'lg', [])).toBeNull()
    const b = block({ layout: { display: { md: choice('block') } } })
    expect(invalidChoiceAt('layout.display', b, 'base', [])).toBeNull()
  })

  it('an instance value that is valid hides an invalid one a class holds beneath it', () => {
    const classes: StyleClassRef[] = [
      { id: 'stacked', style: { layout: { display: { base: choice('block') } } } },
    ]
    const b = block({ layout: { display: { base: choice('flex') } } })
    expect(invalidChoiceAt('layout.display', b, 'lg', classes)).toBeNull()
  })
})

describe('invalidChoicesIn', () => {
  it('lists every invalid declaration a style record holds itself, at every breakpoint', () => {
    const style = {
      layout: {
        display: { base: choice('flex'), md: choice('block') },
        direction: { lg: choice('diagonal') },
        overflow: choice('scroll'),
      },
    }
    expect(invalidChoicesIn(style)).toEqual([
      { path: 'layout.display', value: 'block', breakpoint: 'md' },
      { path: 'layout.direction', value: 'diagonal', breakpoint: 'lg' },
      { path: 'layout.overflow', value: 'scroll', breakpoint: 'base' },
    ])
  })

  it('is empty for a record whose choices are all offered, resets included', () => {
    expect(
      invalidChoicesIn({ layout: { display: { base: choice('grid'), md: { type: 'reset' } } } }),
    ).toEqual([])
    expect(invalidChoicesIn({})).toEqual([])
  })
})

describe('REPLACEMENT', () => {
  it('names what Replace writes for a path, and is itself a value the contract offers', () => {
    expect(REPLACEMENT['layout.display']).toBe('flex')
  })
})
