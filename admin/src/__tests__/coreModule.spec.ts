import { describe, it, expect } from 'vitest'
import { visibleNav } from '@/registry/adminModules'
import { coreModule } from '@/registry/coreModule'

describe('core module declaration', () => {
  it('declares the core nav as an always-on module (visible with no capabilities)', () => {
    const [main, utilities] = visibleNav(() => false, [coreModule]) // nothing visible
    // Core is always-on: its top-level sections are present even with zero visible capabilities.
    const labels = main.map((i) => i.label)
    expect(labels).toContain('Home')
    expect(labels).toContain('Content')
    expect(labels).toContain('Media')
    // Utilities is a node INSIDE the single (main) group today — assert it stays there.
    expect(labels).toContain('Utilities')
    // The second group is empty (no items[1] exists today) — preserves the empty bottom menu.
    expect(utilities).toEqual([])
  })
})
