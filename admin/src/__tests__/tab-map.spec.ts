// Tab membership is per property, from one central map (container-layout spec §5).
import { describe, expect, it } from 'vitest'
import { tabOf, pathsForTab, hasTab } from '@/editor/inspector/tabMap'
import { styleProperties } from '@/style/schema'

describe('tabOf', () => {
  it('splits the alignment group across two tabs', () => {
    // The group is not the unit of membership: text alignment is styling, while placement and
    // content distribution are layout decisions.
    expect(tabOf('alignment.text')).toBe('style')
    expect(tabOf('alignment.self')).toBe('layout')
    expect(tabOf('alignment.content')).toBe('layout')
  })

  it('puts every layout and item property on the Layout tab', () => {
    for (const path of ['layout.display', 'layout.columns', 'layout.gap.row', 'layout.gutter']) {
      expect(tabOf(path), path).toBe('layout')
    }
    for (const path of ['layout.span', 'layout.basis', 'layout.grow', 'layout.align_self']) {
      expect(tabOf(path), path).toBe('layout')
    }
  })

  it('puts width on Layout and the rest of styling on Style', () => {
    expect(tabOf('width')).toBe('layout')
    for (const path of [
      'spacing.padding.top',
      'colors.surface',
      'typography.size',
      'radius',
      'shadow',
      'border.width',
      'visibility',
    ]) {
      expect(tabOf(path), path).toBe('style')
    }
  })

  it('assigns every property in the contract to exactly one tab', () => {
    // A property with no entry would silently disappear from the inspector.
    for (const property of styleProperties()) {
      expect(['style', 'layout'], property.path).toContain(tabOf(property.path))
    }
  })

  it('treats an unknown path as styling rather than hiding it', () => {
    expect(tabOf('something.new')).toBe('style')
  })
})

describe('pathsForTab', () => {
  it('keeps only the declared paths that belong to the tab', () => {
    const declared = ['spacing.padding.top', 'width', 'layout.display', 'alignment.text']
    expect(pathsForTab(declared, 'layout')).toEqual(['width', 'layout.display'])
    expect(pathsForTab(declared, 'style')).toEqual(['spacing.padding.top', 'alignment.text'])
  })

  it('preserves the order it was given', () => {
    expect(pathsForTab(['layout.gutter', 'width', 'layout.display'], 'layout')).toEqual([
      'layout.gutter',
      'width',
      'layout.display',
    ])
  })
})

describe('hasTab', () => {
  it('is true when any declared path belongs to the tab', () => {
    expect(hasTab(['layout.display'], 'layout')).toBe(true)
    expect(hasTab(['alignment.content'], 'layout')).toBe(true)
    // A block with only styling has no Layout tab at all.
    expect(hasTab(['spacing.padding.top', 'colors.text'], 'layout')).toBe(false)
    expect(hasTab([], 'layout')).toBe(false)
  })
})
