import { describe, expect, it } from 'vitest'
import { propertyDefinition, styleProperties } from '@/style/schema'

// The admin mirror of StyleSchema::properties() (block typeface plan Task 3): the typeface is a font
// ID or a reset, one value for every width; every other path keeps its token or choice kinds.
describe('style schema mirror', () => {
  it('declares typography.family as a font or a reset, not responsive', () => {
    expect(propertyDefinition('typography.family')).toEqual({
      path: 'typography.family',
      group: 'typography',
      responsive: false,
      tokenDomain: null,
      choices: null,
      kinds: ['font', 'reset'],
    })
  })

  it('lists the typeface, then letter spacing, casing and decoration, after line height', () => {
    const typography = styleProperties()
      .filter((p) => p.group === 'typography')
      .map((p) => p.path)
    expect(typography).toEqual([
      'typography.size',
      'typography.weight',
      'typography.line_height',
      'typography.family',
      'typography.letter_spacing',
      'typography.transform',
      'typography.decoration',
      'typography.style',
    ])
  })

  it('gives tokens and choices their own kinds', () => {
    expect(propertyDefinition('typography.size')?.kinds).toEqual(['token', 'reset'])
    expect(propertyDefinition('typography.weight')?.kinds).toEqual(['choice', 'reset'])
  })
})
