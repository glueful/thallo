import { afterEach, describe, expect, it, vi } from 'vitest'
import { useAppearanceChanges } from '@/composables/useAppearanceChanges'

// The appearance changed somewhere (block typeface plan Task 11): a tab that changes the font library
// or saves Appearance says so on a channel every other tab of the admin hears; where
// BroadcastChannel is missing, the `storage` event carries it.

describe('useAppearanceChanges', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('posts a change that another instance receives', async () => {
    const a = useAppearanceChanges()
    const b = useAppearanceChanges()
    const heard = vi.fn()
    b.onChange(heard)
    a.notify('fonts')
    await vi.waitFor(() => expect(heard).toHaveBeenCalledTimes(1))
    expect(heard.mock.calls[0]![0]).toMatchObject({ kind: 'fonts' })
    expect(typeof heard.mock.calls[0]![0].at).toBe('number')
    a.dispose()
    b.dispose()
  })

  it('a disposed listener hears nothing more', async () => {
    const a = useAppearanceChanges()
    const b = useAppearanceChanges()
    const heard = vi.fn()
    const off = b.onChange(heard)
    off()
    a.notify('appearance')
    await new Promise((r) => setTimeout(r, 20))
    expect(heard).not.toHaveBeenCalled()
    a.dispose()
    b.dispose()
  })

  it('falls back to the storage event without BroadcastChannel', () => {
    vi.stubGlobal('BroadcastChannel', undefined)
    const sender = useAppearanceChanges()
    const receiver = useAppearanceChanges()
    const heard = vi.fn()
    receiver.onChange(heard)
    sender.notify('appearance')
    const key = 'thallo.appearance.change'
    const written = localStorage.getItem(key)
    expect(JSON.parse(written!)).toMatchObject({ kind: 'appearance' })
    // The storage event fires in OTHER tabs only; here it is dispatched as another tab sees it.
    window.dispatchEvent(new StorageEvent('storage', { key, newValue: written }))
    expect(heard).toHaveBeenCalledWith(expect.objectContaining({ kind: 'appearance' }))
    // Noise is ignored.
    window.dispatchEvent(new StorageEvent('storage', { key, newValue: '{"kind":"nope"}' }))
    window.dispatchEvent(new StorageEvent('storage', { key: 'other', newValue: written }))
    expect(heard).toHaveBeenCalledTimes(1)
    sender.dispose()
    receiver.dispose()
  })
})
