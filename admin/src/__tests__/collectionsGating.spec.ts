import { describe, it, expect } from 'vitest'
import { visibleNav } from '@/registry/adminModules'
import { collectionsModule } from '@/registry/collectionsModule'

describe('collections admin module gating (thallo.collections capability)', () => {
  it('omits the Collections nav when thallo.collections is not visible', () => {
    const [main] = visibleNav(() => false, [collectionsModule])
    expect(main).toEqual([])
  })

  it('includes the Collections nav linking to the split view when visible', () => {
    const [main] = visibleNav((id) => id === 'thallo.collections', [collectionsModule])
    expect(main.map((i) => i.label)).toEqual(['Collections'])
    expect(main[0].to).toBe('/collections')
  })
})
