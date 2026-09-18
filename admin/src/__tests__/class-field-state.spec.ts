// What ONE class declares for a property at a breakpoint (container-layout spec §12.4): set,
// inherited, an explicit reset, an inherited reset, not set, or invalid — judged through the
// class's own breakpoint inheritance and nothing else. A class has no other layer to ask.
import { describe, expect, it } from 'vitest'
import { classFieldState } from '@/editor/inspector/classFieldState'
import { resolve } from '@/style/resolver'
import type { PropertyDefinition, Resolution } from '@/style/types'

const choice = (value: string) => ({ type: 'choice' as const, value })
const token = (value: string) => ({ type: 'token' as const, value })
const RESET = { type: 'reset' as const }

const direction: PropertyDefinition = {
  path: 'layout.direction',
  group: 'layout',
  responsive: true,
  tokenDomain: null,
  choices: ['row', 'column', 'row-reverse', 'column-reverse'],
}
const display: PropertyDefinition = {
  path: 'layout.display',
  group: 'layout',
  responsive: true,
  tokenDomain: null,
  choices: ['flex', 'grid'],
}
const overflow: PropertyDefinition = {
  path: 'layout.overflow',
  group: 'layout',
  responsive: false,
  tokenDomain: null,
  choices: ['visible', 'hidden', 'auto'],
}
const gapRow: PropertyDefinition = {
  path: 'layout.gap.row',
  group: 'layout',
  responsive: true,
  tokenDomain: 'spacing',
  choices: null,
}
const paddingTop: PropertyDefinition = {
  path: 'spacing.padding.top',
  group: 'spacing',
  responsive: true,
  tokenDomain: 'spacing',
  choices: null,
}
const SPACING = ['spacing.none', 'spacing.sm', 'spacing.lg', 'spacing.xl']

describe('a responsive property', () => {
  it('nothing declared anywhere is not set in this class, and presents no value', () => {
    for (const bp of ['base', 'md', 'lg'] as const) {
      expect(classFieldState(direction, {}, bp)).toEqual({
        kind: 'not-set',
        from: null,
        value: null,
        label: 'Not set in this class',
        declaredHere: false,
      })
    }
  })

  it('a value at the breakpoint being edited is set', () => {
    const style = { layout: { direction: { base: choice('row') } } }
    expect(classFieldState(direction, style, 'base')).toEqual({
      kind: 'set',
      from: 'base',
      value: choice('row'),
      label: 'Set',
      declaredHere: true,
    })
  })

  it('a value at an earlier breakpoint is inherited, and names the breakpoint that declares it', () => {
    const style = { layout: { direction: { base: choice('row') } } }
    for (const bp of ['md', 'lg'] as const) {
      expect(classFieldState(direction, style, bp)).toEqual({
        kind: 'inherited',
        from: 'base',
        value: choice('row'),
        label: 'Inherited from base',
        declaredHere: false,
      })
    }
  })

  it('the nearest earlier declaration wins: md over base, read at lg', () => {
    const style = { layout: { direction: { base: choice('row'), md: choice('column') } } }
    expect(classFieldState(direction, style, 'md')).toMatchObject({ kind: 'set', from: 'md' })
    expect(classFieldState(direction, style, 'lg')).toMatchObject({
      kind: 'inherited',
      from: 'md',
      value: choice('column'),
      label: 'Inherited from md',
    })
  })

  it('a reset at the breakpoint being edited is the theme default, set here', () => {
    const style = { layout: { direction: { base: RESET } } }
    expect(classFieldState(direction, style, 'base')).toEqual({
      kind: 'reset-here',
      from: 'base',
      value: null,
      label: 'Theme default, set here',
      declaredHere: true,
    })
  })

  it('a reset at an earlier breakpoint is INHERITED — the case the resolver cannot tell apart', () => {
    const style = { layout: { direction: { base: RESET } } }
    // Why this function reads `declarationOrigin`: asked about md, the resolver reports a reset
    // with no sign that it was authored at base. Shown as it stands it would read "reset, here",
    // and offer to remove a declaration that does not exist at md.
    const resolved = resolve('layout.direction', [], style, direction) as Record<string, Resolution>
    expect(resolved.md!.state).toBe('reset')
    expect(resolved.base!.state).toBe('reset')

    expect(classFieldState(direction, style, 'md')).toEqual({
      kind: 'inherited-reset',
      from: 'base',
      value: null,
      label: 'Theme default, from base',
      declaredHere: false,
    })
  })

  it('a reset over a value is inherited from where the RESET is, not from the value under it', () => {
    const style = { layout: { direction: { base: choice('row'), md: RESET } } }
    expect(classFieldState(direction, style, 'lg')).toMatchObject({
      kind: 'inherited-reset',
      from: 'md',
      label: 'Theme default, from md',
    })
    expect(classFieldState(direction, style, 'base')).toMatchObject({ kind: 'set', from: 'base' })
  })

  it('a token property and a box side go through the same states', () => {
    for (const [def, style] of [
      [gapRow, { layout: { gap: { row: { base: token('spacing.lg') } } } }],
      [paddingTop, { spacing: { padding: { top: { base: token('spacing.lg') } } } }],
    ] as const) {
      expect(classFieldState(def, style, 'base', SPACING)).toMatchObject({ kind: 'set' })
      expect(classFieldState(def, style, 'lg', SPACING)).toMatchObject({
        kind: 'inherited',
        from: 'base',
        value: token('spacing.lg'),
      })
      expect(classFieldState(def, {}, 'lg', SPACING)).toMatchObject({ kind: 'not-set' })
    }
  })
})

describe('a property that is not responsive', () => {
  it('has no breakpoints: the same answer whichever one is being edited, and never inherited', () => {
    const set = { layout: { overflow: choice('hidden') } }
    const reset = { layout: { overflow: RESET } }
    for (const bp of ['base', 'md', 'lg'] as const) {
      expect(classFieldState(overflow, set, bp)).toEqual({
        kind: 'set',
        from: null,
        value: choice('hidden'),
        label: 'Set',
        declaredHere: true,
      })
      expect(classFieldState(overflow, reset, bp)).toEqual({
        kind: 'reset-here',
        from: null,
        value: null,
        label: 'Theme default, set here',
        declaredHere: true,
      })
      expect(classFieldState(overflow, {}, bp)).toMatchObject({ kind: 'not-set', from: null })
    }
  })

  it('stored under a breakpoint it is invalid, not invisible: the server refuses that shape', () => {
    // The resolver reads a bare value only, so a wrapped one resolves to nothing — which would
    // read "Not set in this class" over a declaration that is there and blocks the save.
    const wrapped = { layout: { overflow: { base: choice('hidden') } } }
    expect(classFieldState(overflow, wrapped, 'md')).toEqual({
      kind: 'invalid',
      from: null,
      value: null,
      label: 'Invalid',
      declaredHere: true,
    })
  })
})

describe('a stored value the contract does not offer', () => {
  it('a choice outside the list is invalid where it is declared and where it is inherited', () => {
    const style = { layout: { display: { md: choice('block') } } }
    expect(classFieldState(display, style, 'md')).toEqual({
      kind: 'invalid',
      from: 'md',
      value: choice('block'),
      label: 'Invalid',
      declaredHere: true,
    })
    expect(classFieldState(display, style, 'lg')).toMatchObject({
      kind: 'invalid',
      from: 'md',
      declaredHere: false,
    })
    expect(classFieldState(display, style, 'base')).toMatchObject({ kind: 'not-set' })
  })

  it('a token is judged only against names it is given: the function does not guess', () => {
    const style = { layout: { gap: { row: { base: token('spacing.huge') } } } }
    expect(classFieldState(gapRow, style, 'base', SPACING)).toMatchObject({
      kind: 'invalid',
      value: token('spacing.huge'),
    })
    expect(classFieldState(gapRow, style, 'base')).toMatchObject({ kind: 'set' })
  })

  it('a value of the wrong kind for the property is invalid', () => {
    const style = { layout: { display: { base: token('spacing.lg') } } }
    expect(classFieldState(display, style, 'base')).toMatchObject({ kind: 'invalid' })
  })
})
