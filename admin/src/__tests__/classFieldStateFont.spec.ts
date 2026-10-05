import { describe, expect, it } from 'vitest'
import { classFieldState } from '@/editor/inspector/classFieldState'
import { propertyDefinition } from '@/style/schema'

// A style class's typeface (block typeface plan Task 9): a shaped ID is set; anything else is
// invalid; a family the library holds only as removed is still set, and says so.
const def = propertyDefinition('typography.family')!
const style = (value: unknown) => ({ typography: { family: value } })
const removed = new Map([['Rm3dE5fG7hJ9', { name: 'Gone', removed: true }]])

describe('classFieldState for a typeface', () => {
  it('is set for a built-in or a library ID', () => {
    for (const id of ['serif', 'theme', 'Ab3dE5fG7hJ9']) {
      const state = classFieldState(def, style({ type: 'font', value: id }), 'base')
      expect([state.kind, state.label]).toEqual(['set', 'Set'])
    }
  })

  it('is invalid for a value that is not a typeface ID', () => {
    for (const value of [
      { type: 'font', value: 'My Font' },
      { type: 'font', value: 'inherit' },
      { type: 'choice', value: 'serif' },
    ]) {
      expect(classFieldState(def, style(value), 'base').kind).toBe('invalid')
    }
  })

  it('is set, and labelled removed, for a removed family', () => {
    const state = classFieldState(
      def,
      style({ type: 'font', value: 'Rm3dE5fG7hJ9' }),
      'base',
      undefined,
      removed,
    )
    expect([state.kind, state.label]).toEqual(['set', 'Set — removed typeface: Gone'])
  })

  it('reads a reset as the theme default set here', () => {
    expect(classFieldState(def, style({ type: 'reset' }), 'base').kind).toBe('reset-here')
  })
})
