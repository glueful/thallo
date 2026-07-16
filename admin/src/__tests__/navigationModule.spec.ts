import { describe, it, expect } from 'vitest'
import { visibleNav, type AdminModule } from '@/registry/adminModules'
import { navigationModule } from '@/registry/navigationModule'

const renderModule: AdminModule = {
  id: 'render',
  requires: ['thallo.render'],
  nav: { site: [{ label: 'Themes', to: '/themes' }] },
}

describe('navigation admin module gating (thallo.navigation capability)', () => {
  it('omits the Site group entirely when thallo.navigation is not visible', () => {
    const [main] = visibleNav(() => false, [navigationModule])
    expect(main).toEqual([])
  })

  it('nests Navigation under the expandable Site group when visible', () => {
    const [main] = visibleNav((id) => id === 'thallo.navigation', [navigationModule])

    expect(main.map((i) => i.label)).toEqual(['Site'])
    const site = main[0]!
    expect(site.icon).toBe('i-lucide-globe')
    expect((site.children ?? []).map((c) => c.label)).toEqual(['Navigation'])
    expect(site.children?.[0]?.to).toBe('/navigation')
  })

  it('multiple site-contributing modules share ONE Site group', () => {
    const [main] = visibleNav(() => true, [navigationModule, renderModule])

    const siteGroups = main.filter((i) => i.label === 'Site')
    expect(siteGroups).toHaveLength(1)
    expect((siteGroups[0]!.children ?? []).map((c) => c.label)).toEqual(['Navigation', 'Themes'])
  })

  it('a hidden contributor is excluded from the shared Site group', () => {
    const [main] = visibleNav((id) => id === 'thallo.navigation', [navigationModule, renderModule])

    expect((main[0]!.children ?? []).map((c) => c.label)).toEqual(['Navigation'])
  })
})
