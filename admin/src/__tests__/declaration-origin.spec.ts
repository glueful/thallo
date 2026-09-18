import { describe, it, expect } from 'vitest'
import { declarationOrigin } from '@/style/resolver'
import { propertyDefinition } from '@/style/schema'
import type { StyleClassRef } from '@/style/types'

// `Resolution.breakpoint` is the breakpoint that was ASKED about: an `md` declaration inherited at
// `lg` reports `lg`. A repair has to write where the value is declared, so the origin is looked up
// on its own — the same cascade walk, answering the layer and breakpoint that hold the value.

const display = propertyDefinition('layout.display')!
const overflow = propertyDefinition('layout.overflow')!
const choice = (value: string) => ({ type: 'choice', value })
const layout = (display: Record<string, unknown>) => ({ layout: { display } })

describe('declarationOrigin', () => {
  it('answers the declaring breakpoint, not the one asked about', () => {
    const style = layout({ md: choice('grid') })
    expect(declarationOrigin('layout.display', [], style, display, 'lg')).toEqual({
      breakpoint: 'md',
      source: 'instance',
    })
    expect(declarationOrigin('layout.display', [], style, display, 'md')).toEqual({
      breakpoint: 'md',
      source: 'instance',
    })
  })

  it('names the class that supplies the value', () => {
    const classes: StyleClassRef[] = [{ id: 'tiles', style: layout({ md: choice('grid') }) }]
    expect(declarationOrigin('layout.display', classes, {}, display, 'lg')).toEqual({
      breakpoint: 'md',
      source: 'class:tiles',
    })
  })

  it('prefers the instance over a class at the same breakpoint, and a later class over an earlier', () => {
    const classes: StyleClassRef[] = [
      { id: 'first', style: layout({ md: choice('grid') }) },
      { id: 'second', style: layout({ md: choice('flex') }) },
    ]
    expect(declarationOrigin('layout.display', classes, {}, display, 'md')?.source).toBe(
      'class:second',
    )
    const own = layout({ md: choice('grid') })
    expect(declarationOrigin('layout.display', classes, own, display, 'md')?.source).toBe(
      'instance',
    )
  })

  it('a nearer breakpoint wins over a higher-precedence layer further down', () => {
    // Breakpoint-first: the class declares at lg, the instance only at base.
    const classes: StyleClassRef[] = [{ id: 'wide', style: layout({ lg: choice('grid') }) }]
    const own = layout({ base: choice('flex') })
    expect(declarationOrigin('layout.display', classes, own, display, 'lg')).toEqual({
      breakpoint: 'lg',
      source: 'class:wide',
    })
  })

  it('a reset is its own origin: it is what holds the property there', () => {
    const style = layout({ md: choice('grid'), lg: { type: 'reset' } })
    expect(declarationOrigin('layout.display', [], style, display, 'lg')).toEqual({
      breakpoint: 'lg',
      source: 'instance',
    })
  })

  it('is null where only the theme default is in force', () => {
    expect(declarationOrigin('layout.display', [], {}, display, 'lg')).toBeNull()
    // Below the declaring breakpoint nothing reaches up.
    expect(
      declarationOrigin('layout.display', [], layout({ md: choice('grid') }), display, 'base'),
    ).toBeNull()
  })

  it('a property that is not responsive is declared at base', () => {
    const style = { layout: { overflow: choice('hidden') } }
    expect(declarationOrigin('layout.overflow', [], style, overflow, 'lg')).toEqual({
      breakpoint: 'base',
      source: 'instance',
    })
  })
})
