import { describe, it, expect, beforeEach } from 'vitest'
import { visibleNav, resetAdminModules } from '@/registry/adminModules'
import { registerCollectionsModule } from '@/registry/collectionsModule'

describe('collections admin module gating (thallo.collections capability)', () => {
  beforeEach(() => resetAdminModules())

  it('omits the Collections nav when thallo.collections is disabled', () => {
    registerCollectionsModule()
    const [main] = visibleNav(() => false)
    expect(main).toEqual([])
  })

  it('includes the Collections nav linking to the split view when enabled', () => {
    registerCollectionsModule()
    const [main] = visibleNav((id) => id === 'thallo.collections')
    expect(main.map((i) => i.label)).toEqual(['Collections'])
    expect(main[0].to).toBe('/collections')
  })
})
