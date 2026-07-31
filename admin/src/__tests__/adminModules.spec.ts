import { describe, it, expect } from 'vitest'
import { visibleNav, type AdminModule, type SettingsAnchor } from '@/registry/adminModules'
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

describe('the Settings contribution seam', () => {
  const settingsParent: SettingsAnchor = {
    label: 'Settings',
    contributionSlot: 'settings',
    children: [{ label: 'General', to: '/settings/general' }],
  }
  const coreWithSettings: AdminModule = { id: 'core', nav: { main: [settingsParent] } }
  const contributor: AdminModule = {
    id: 'x',
    requires: ['thallo.x'],
    nav: { settings: [{ label: 'Accounts', to: '/settings/accounts' }] },
  }
  const settingsNode = (main: ReturnType<typeof visibleNav>[0]): SettingsAnchor | undefined =>
    main.find((n) => n.label === 'Settings') as SettingsAnchor | undefined

  it('appends a contribution AFTER the core Settings children', () => {
    const [main] = visibleNav(() => true, [coreWithSettings, contributor])
    expect(settingsNode(main)?.children?.map((c) => c.label)).toEqual(['General', 'Accounts'])
  })

  it('strips the private contributionSlot marker from the rendered node', () => {
    const [main] = visibleNav(() => true, [coreWithSettings])
    expect(settingsNode(main)?.contributionSlot).toBeUndefined()
  })

  it('omits a gated contribution when its capability is not visible', () => {
    const [main] = visibleNav(() => false, [coreWithSettings, contributor])
    expect(settingsNode(main)?.children?.map((c) => c.label)).toEqual(['General'])
  })

  it('is deterministic across calls and never mutates the static parent', () => {
    visibleNav(() => true, [coreWithSettings, contributor])
    const [main] = visibleNav(() => true, [coreWithSettings, contributor])
    // A second call does not accumulate a duplicate contribution...
    expect(settingsNode(main)?.children?.filter((c) => c.label === 'Accounts')).toHaveLength(1)
    // ...and the declared parent is untouched (its marker and children stand).
    expect(settingsParent.contributionSlot).toBe('settings')
    expect(settingsParent.children?.map((c) => c.label)).toEqual(['General'])
  })

  it('adds Accounts to the real Settings group only when thallo.accounts is visible', () => {
    const [mainOn] = visibleNav((id) => id === 'thallo.accounts')
    const on = settingsNode(mainOn)
    expect(on?.children?.some((c) => c.to === '/settings/accounts')).toBe(true)
    expect(on?.contributionSlot).toBeUndefined() // marker never leaks into rendered nav

    const [mainOff] = visibleNav(() => false)
    expect(settingsNode(mainOff)?.children?.some((c) => c.to === '/settings/accounts')).toBe(false)
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
      'commerce',
      'submissions',
      'tenancy',
      'account',
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
