import { describe, it, expect, beforeEach } from 'vitest'
import {
  registerAdminModule,
  visibleNav,
  resetAdminModules,
  registeredModules,
} from '@/registry/adminModules'

describe('admin module registry', () => {
  beforeEach(() => resetAdminModules())

  it('always includes a module with no requires (core)', () => {
    registerAdminModule({ id: 'core', nav: { main: [{ label: 'Home', to: '/' }] } })
    const [main, utilities] = visibleNav(() => false)
    expect(main).toEqual([{ label: 'Home', to: '/' }])
    expect(utilities).toEqual([])
  })

  it('includes a gated module only when ALL its requires are enabled', () => {
    registerAdminModule({ id: 'core', nav: { main: [{ label: 'Home', to: '/' }] } })
    registerAdminModule({
      id: 'forms',
      requires: ['thallo.forms'],
      nav: { main: [{ label: 'Forms', to: '/forms' }] },
    })
    const enabled = new Set(['thallo.forms'])
    const [mainOn] = visibleNav((id) => enabled.has(id))
    expect(mainOn.map((i) => i.label)).toEqual(['Home', 'Forms'])
    const [mainOff] = visibleNav(() => false)
    expect(mainOff.map((i) => i.label)).toEqual(['Home'])
  })

  it('requires ALL ids (not any)', () => {
    registerAdminModule({
      id: 'multi',
      requires: ['a', 'b'],
      nav: { main: [{ label: 'Multi', to: '/multi' }] },
    })
    expect(visibleNav((id) => id === 'a')[0]).toEqual([]) // only one of two enabled
    expect(visibleNav(() => true)[0].map((i) => i.label)).toEqual(['Multi'])
  })

  it('routes utilities contributions into group 1', () => {
    registerAdminModule({
      id: 'core',
      nav: { utilities: [{ label: 'Health', to: '/utilities/health' }] },
    })
    const [main, utilities] = visibleNav(() => true)
    expect(main).toEqual([])
    expect(utilities.map((i) => i.label)).toEqual(['Health'])
  })

  it('re-registering the same id replaces (no duplicate from HMR)', () => {
    registerAdminModule({ id: 'core', nav: { main: [{ label: 'Old', to: '/' }] } })
    registerAdminModule({ id: 'core', nav: { main: [{ label: 'New', to: '/' }] } })
    expect(registeredModules()).toHaveLength(1)
    expect(visibleNav(() => true)[0].map((i) => i.label)).toEqual(['New'])
  })
})
