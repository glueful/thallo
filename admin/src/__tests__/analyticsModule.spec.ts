import { describe, it, expect, beforeEach } from 'vitest'
import { visibleNav, resetAdminModules } from '@/registry/adminModules'
import { registerAnalyticsModule } from '@/registry/analyticsModule'

describe('analytics admin module gating (thallo.analytics capability)', () => {
  beforeEach(() => resetAdminModules())

  it('omits the Analytics nav when thallo.analytics is disabled', () => {
    registerAnalyticsModule()
    const [main] = visibleNav(() => false)
    expect(main).toEqual([])
  })

  it('includes the Analytics nav linking to /analytics when enabled', () => {
    registerAnalyticsModule()
    const [main] = visibleNav((id) => id === 'thallo.analytics')
    expect(main.map((i) => i.label)).toEqual(['Analytics'])
    expect(main[0].to).toBe('/analytics')
  })
})
