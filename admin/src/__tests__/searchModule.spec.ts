import { describe, it, expect } from 'vitest'
import { visibleNav } from '@/registry/adminModules'
import { adminManifest } from '@/registry/manifest'

// Settings › Search belongs to the Search capability: it leaves the sidebar while Search is off,
// like every section a capability owns.
const settingsChildren = (isVisible: (id: string) => boolean): string[] => {
  const [main, utilities] = visibleNav(isVisible)
  const settings = [...main, ...utilities].find((i) => i.label === 'Settings')
  return ((settings?.children ?? []) as { label?: string }[]).map((c) => String(c.label))
}

describe('the Settings › Search entry (thallo.search capability)', () => {
  it('is absent while Search is off', () => {
    expect(settingsChildren((id) => id !== 'thallo.search')).not.toContain('Search')
  })

  it('links to /settings/search while Search is on', () => {
    expect(settingsChildren(() => true)).toContain('Search')
    const [main, utilities] = visibleNav(() => true, adminManifest)
    const settings = [...main, ...utilities].find((i) => i.label === 'Settings')
    const search = (settings?.children ?? []).find((c) => c.label === 'Search')
    expect(search?.to).toBe('/settings/search')
  })
})
