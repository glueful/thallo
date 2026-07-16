import { describe, it, expect } from 'vitest'
import { visibleNav } from '@/registry/adminModules'
import { analyticsModule } from '@/registry/analyticsModule'

describe('analytics admin module gating (thallo.analytics capability)', () => {
  it('omits the Analytics nav when thallo.analytics is not visible', () => {
    const [main] = visibleNav(() => false, [analyticsModule])
    expect(main).toEqual([])
  })

  it('includes the Analytics nav linking to /analytics when visible', () => {
    const [main] = visibleNav((id) => id === 'thallo.analytics', [analyticsModule])
    expect(main.map((i) => i.label)).toEqual(['Analytics'])
    expect(main[0].to).toBe('/analytics')
  })
})
