import { describe, it, expect } from 'vitest'
import { homepageButtonState } from '@/utils/homepageButton'

// The server refuses a published-but-routeless locale as the homepage; the button must not
// offer what the server will refuse, and must say which prerequisite is missing.

describe('homepageButtonState', () => {
  it('waits for publish first', () => {
    expect(homepageButtonState({ published: false, hasRoute: false })).toEqual({
      enabled: false,
      tooltip: 'Publish first to set as homepage',
    })
    expect(homepageButtonState({ published: false, hasRoute: true }).enabled).toBe(false)
  })

  it('then waits for a saved slug', () => {
    expect(homepageButtonState({ published: true, hasRoute: false })).toEqual({
      enabled: false,
      tooltip: 'Save a slug first to set as homepage',
    })
  })

  it('enables once both hold', () => {
    expect(homepageButtonState({ published: true, hasRoute: true })).toEqual({
      enabled: true,
      tooltip: 'Set as homepage',
    })
  })
})
