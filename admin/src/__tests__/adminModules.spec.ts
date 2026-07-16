import { describe, it, expect } from 'vitest'
import { visibleNav, type AdminModule } from '@/registry/adminModules'
import { adminManifest } from '@/registry/manifest'

const core: AdminModule = { id: 'core', nav: { main: [{ label: 'Home', to: '/' }] } }
const forms: AdminModule = {
  id: 'forms',
  requires: ['thallo.forms'],
  nav: { main: [{ label: 'Forms', to: '/forms' }] },
}

describe('admin module visibility (pure over the declared list)', () => {
  it('always includes a module with no requires (core)', () => {
    const [main, utilities] = visibleNav(() => false, [core])
    expect(main).toEqual([{ label: 'Home', to: '/' }])
    expect(utilities).toEqual([])
  })

  it('includes a gated module only when ALL its requires are visible', () => {
    const visible = new Set(['thallo.forms'])
    const [mainOn] = visibleNav((id) => visible.has(id), [core, forms])
    expect(mainOn.map((i) => i.label)).toEqual(['Home', 'Forms'])
    const [mainOff] = visibleNav(() => false, [core, forms])
    expect(mainOff.map((i) => i.label)).toEqual(['Home'])
  })

  it('requires ALL ids (not any)', () => {
    const multi: AdminModule = {
      id: 'multi',
      requires: ['a', 'b'],
      nav: { main: [{ label: 'Multi', to: '/multi' }] },
    }
    expect(visibleNav((id) => id === 'a', [multi])[0]).toEqual([]) // only one of two visible
    expect(visibleNav(() => true, [multi])[0].map((i) => i.label)).toEqual(['Multi'])
  })

  it('routes utilities contributions into group 1', () => {
    const util: AdminModule = {
      id: 'core',
      nav: { utilities: [{ label: 'Health', to: '/utilities/health' }] },
    }
    const [main, utilities] = visibleNav(() => true, [util])
    expect(main).toEqual([])
    expect(utilities.map((i) => i.label)).toEqual(['Health'])
  })
})

describe('the static manifest', () => {
  it('declares every module once, core first, in render order', () => {
    const ids = adminManifest.map((m) => m.id)
    expect(ids[0]).toBe('core')
    expect(new Set(ids).size).toBe(ids.length) // no duplicate declarations
    expect(ids).toEqual([
      'core',
      'collections',
      'analytics',
      'workflow',
      'navigation',
      'regions',
      'templates',
      'submissions',
      'tenancy',
    ])
  })

  it('every gated module names plausible thallo capability ids', () => {
    for (const m of adminManifest) {
      for (const id of m.requires ?? []) {
        expect(id).toMatch(/^thallo\.[a-z][a-z0-9._-]*$/)
      }
    }
  })

  it('core and regions/submissions are the only always-on modules', () => {
    const alwaysOn = adminManifest.filter((m) => (m.requires ?? []).length === 0).map((m) => m.id)
    expect(alwaysOn).toEqual(['core', 'regions', 'submissions'])
  })
})
