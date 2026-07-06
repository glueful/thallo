import { describe, it, expect, beforeEach } from 'vitest'
import { visibleNav, resetAdminModules, registerAdminModule } from '@/registry/adminModules'
import { registerNavigationModule } from '@/registry/navigationModule'

describe('navigation admin module gating (thallo.navigation capability)', () => {
  beforeEach(() => resetAdminModules())

  it('omits the Site group entirely when thallo.navigation is disabled', () => {
    registerNavigationModule()
    const [main] = visibleNav(() => false)
    expect(main).toEqual([])
  })

  it('nests Navigation under the expandable Site group when enabled', () => {
    registerNavigationModule()
    const [main] = visibleNav((id) => id === 'thallo.navigation')

    expect(main.map((i) => i.label)).toEqual(['Site'])
    const site = main[0]!
    expect(site.icon).toBe('i-lucide-globe')
    expect((site.children ?? []).map((c) => c.label)).toEqual(['Navigation'])
    expect(site.children?.[0]?.to).toBe('/navigation')
  })

  it('multiple site-contributing modules share ONE Site group', () => {
    registerNavigationModule()
    registerAdminModule({
      id: 'render',
      requires: ['thallo.render'],
      nav: { site: [{ label: 'Themes', to: '/themes' }] },
    })
    const [main] = visibleNav(() => true)

    const siteGroups = main.filter((i) => i.label === 'Site')
    expect(siteGroups).toHaveLength(1)
    expect((siteGroups[0]!.children ?? []).map((c) => c.label)).toEqual(['Navigation', 'Themes'])
  })

  it('a disabled contributor is excluded from the shared Site group', () => {
    registerNavigationModule()
    registerAdminModule({
      id: 'render',
      requires: ['thallo.render'],
      nav: { site: [{ label: 'Themes', to: '/themes' }] },
    })
    const [main] = visibleNav((id) => id === 'thallo.navigation')

    expect((main[0]!.children ?? []).map((c) => c.label)).toEqual(['Navigation'])
  })
})
