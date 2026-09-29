import { describe, it, expect, vi } from 'vitest'
import { isModuleLoadFailure, recoverFromModuleLoadFailure } from '@/router/moduleRecovery'

// A route's page is loaded on demand. When that load fails — a dev server too busy to answer, or a
// deploy that replaced the chunk the open tab still names — the router starts nowhere: neither the
// page nor the sign-in form renders. The router reloads that route once instead, and never loops.

function memoryStorage(): Storage {
  const items = new Map<string, string>()
  return {
    get length() {
      return items.size
    },
    clear: () => items.clear(),
    getItem: (key) => items.get(key) ?? null,
    key: (index) => [...items.keys()][index] ?? null,
    removeItem: (key) => void items.delete(key),
    setItem: (key, value) => void items.set(key, value),
  }
}

describe('route module recovery', () => {
  it('recognises a failed page load in each browser engine', () => {
    for (const message of [
      'Failed to fetch dynamically imported module: http://x/admin/src/pages/regions/index.vue',
      'Importing a module script failed.',
      'error loading dynamically imported module: http://x/assets/index-abc.js',
    ]) {
      expect(isModuleLoadFailure(new TypeError(message))).toBe(true)
    }
    expect(isModuleLoadFailure(new Error('Request failed with status 500'))).toBe(false)
    expect(isModuleLoadFailure('not an error')).toBe(false)
  })

  it('reloads the route it was opening, once', () => {
    const storage = memoryStorage()
    const reload = vi.fn()
    const failure = new TypeError(
      'Failed to fetch dynamically imported module: /admin/src/pages/regions/index.vue',
    )

    expect(
      recoverFromModuleLoadFailure(failure, '/admin/regions', { storage, reload, now: () => 1000 }),
    ).toBe(true)
    expect(reload).toHaveBeenCalledWith('/admin/regions')

    expect(
      recoverFromModuleLoadFailure(failure, '/admin/regions', { storage, reload, now: () => 5000 }),
    ).toBe(false)
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('tries again after a while, or for another route', () => {
    const storage = memoryStorage()
    const reload = vi.fn()
    const failure = new TypeError('Importing a module script failed.')

    recoverFromModuleLoadFailure(failure, '/admin/regions', { storage, reload, now: () => 1000 })
    expect(
      recoverFromModuleLoadFailure(failure, '/admin/layouts', { storage, reload, now: () => 2000 }),
    ).toBe(true)
    expect(
      recoverFromModuleLoadFailure(failure, '/admin/regions', {
        storage,
        reload,
        now: () => 40_000,
      }),
    ).toBe(true)
    expect(reload).toHaveBeenCalledTimes(3)
  })

  it('leaves other errors alone, and never reloads when it cannot remember doing so', () => {
    const reload = vi.fn()
    expect(
      recoverFromModuleLoadFailure(new Error('boom'), '/admin/regions', {
        storage: memoryStorage(),
        reload,
      }),
    ).toBe(false)
    const denied = () => {
      throw new Error('denied')
    }
    const broken = { getItem: denied, setItem: denied } as unknown as Storage
    expect(
      recoverFromModuleLoadFailure(new TypeError('Importing a module script failed.'), '/admin/x', {
        storage: broken,
        reload,
      }),
    ).toBe(false)
    expect(reload).not.toHaveBeenCalled()
  })
})
