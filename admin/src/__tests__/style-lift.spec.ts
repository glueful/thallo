import { describe, expect, it } from 'vitest'
import { capabilityPaths } from '@/style/detach'
import { liftPreservesAppearance, liftedDeclarations } from '@/style/lift'

// Visual builder spec §4.5: a lift moves explicit declarations only and must preserve appearance.
const pad = (bp: string, token: string) => ({
  spacing: { padding: { top: { [bp]: { type: 'token', value: `spacing.${token}` } } } },
})
const merge = (...styles: Record<string, unknown>[]) => {
  const out: Record<string, unknown> = { spacing: { padding: { top: {} } } }
  for (const s of styles) {
    Object.assign(
      (out.spacing as { padding: { top: Record<string, unknown> } }).padding.top,
      (s.spacing as { padding: { top: Record<string, unknown> } }).padding.top,
    )
  }
  return out
}
const spacing = capabilityPaths(['spacing'])

describe('save as style class', () => {
  it('lifts explicit declarations only, in table order', () => {
    const style = { radius: { type: 'reset' }, ...merge(pad('md', 'sm'), pad('base', 'lg')) }
    expect(liftedDeclarations(style)).toEqual([
      {
        path: 'spacing.padding.top',
        breakpoint: 'base',
        value: { type: 'token', value: 'spacing.lg' },
      },
      {
        path: 'spacing.padding.top',
        breakpoint: 'md',
        value: { type: 'token', value: 'spacing.sm' },
      },
      { path: 'radius', breakpoint: null, value: { type: 'reset' } },
    ])
  })

  it('preserves appearance across the §1.6 table rows and a reset row', () => {
    const classA = { id: 'a', style: merge(pad('base', 'lg'), pad('md', 'xl')) }
    for (const instance of [
      pad('base', 'sm'),
      pad('md', 'sm'),
      merge(pad('base', 'sm'), pad('md', 'sm')),
      { spacing: { padding: { top: { md: { type: 'reset' } } } } },
    ]) {
      const lifted = { id: 'new', style: instance }
      expect(liftPreservesAppearance([classA], instance, lifted, spacing)).toBe(true)
    }
  })

  it('detects a class that does not carry what the instance declared', () => {
    const instance = pad('md', 'sm')
    expect(
      liftPreservesAppearance([], instance, { id: 'new', style: pad('md', 'lg') }, spacing),
    ).toBe(false)
    expect(liftPreservesAppearance([], instance, { id: 'new', style: {} }, spacing)).toBe(false)
  })
})
