import { describe, it, expect, beforeEach } from 'vitest'

// The notice's decision is pure: an update is shown when the server says one is available,
// the install is not a development checkout, and this browser has not dismissed THAT version.
// Dismissal is version-keyed so a newer release re-shows.
describe('useUpdateNotice', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  const status = (over: Record<string, unknown> = {}) => ({
    current: '1.0.0-beta.21',
    latest: '1.0.0-beta.22',
    available: true,
    development: false,
    enabled: true,
    checkedAt: '2026-09-12T04:00:00+00:00',
    notesUrl: 'https://github.com/glueful/thallo/blob/main/CHANGELOG.md',
    ...over,
  })

  it('shows only an available, non-development, undismissed update', async () => {
    const { shouldShowNotice } = await import('@/composables/useUpdateNotice')

    expect(shouldShowNotice(status(), null)).toBe(true)
    expect(shouldShowNotice(status({ available: false }), null)).toBe(false)
    expect(shouldShowNotice(status({ development: true }), null)).toBe(false)
    expect(shouldShowNotice(status(), '1.0.0-beta.22')).toBe(false)
    expect(shouldShowNotice(undefined, null)).toBe(false)
    expect(shouldShowNotice(null, null)).toBe(false)
  })

  it('dismissal is keyed by version, so a newer release re-shows', async () => {
    const { shouldShowNotice, dismissVersion, readDismissed, DISMISSED_KEY } =
      await import('@/composables/useUpdateNotice')

    dismissVersion('1.0.0-beta.22')

    expect(readDismissed()).toBe('1.0.0-beta.22')
    expect(localStorage.getItem(DISMISSED_KEY)).toBe('1.0.0-beta.22')
    expect(shouldShowNotice(status(), readDismissed())).toBe(false)
    expect(shouldShowNotice(status({ latest: '1.0.0-beta.23' }), readDismissed())).toBe(true)
  })
})
