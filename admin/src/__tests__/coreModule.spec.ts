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

describe('core module: Developers › API Reference', () => {
  it("links to the running site's API-docs path from runtime config, never a hardcoded host", async () => {
    const { runtimeConfig } = await import('@/runtime/config')
    const { coreModule: core } = await import('@/registry/coreModule')
    const developers = (core.nav?.main ?? []).find((i) => i.label === 'Developers')
    const link = (developers?.children ?? []).find((c) => c.label === 'API Reference')

    expect(link).toBeDefined()
    expect(link!.to).toBe(runtimeConfig.apiDocsPath)

    // Read at render time: a value loaded after the module was imported still wins.
    runtimeConfig.apiDocsPath = '/reference'
    expect(link!.to).toBe('/reference')
    expect(String(link!.to)).not.toContain('thallodev')
    // A same-origin PATH must still leave the SPA: the reference is served by PHP.
    expect(link!.external).toBe(true)
    expect(link!.target).toBe('_blank')
  })
})
