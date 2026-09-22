import { describe, expect, it } from 'vitest'
import { fieldLabel } from '@/utils/fieldLabel'

describe('fieldLabel', () => {
  it('uses the label when one is set', () => {
    expect(fieldLabel({ name: 'title', label: 'Headline' })).toBe('Headline')
  })

  it('makes the name readable otherwise', () => {
    expect(fieldLabel({ name: 'title' })).toBe('Title')
    expect(fieldLabel({ name: 'hero_image', label: '  ' })).toBe('Hero image')
  })
})
