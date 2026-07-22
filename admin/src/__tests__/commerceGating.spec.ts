import { describe, it, expect } from 'vitest'
import { visibleNav } from '@/registry/adminModules'
import { commerceModule } from '@/registry/commerceModule'

// Task 9 (admin-commerce-area plan, slice 3): the Commerce module is registered and
// capability-gated on `thallo.commerce`, but contributes NO navigation item yet. Task 12
// atomically adds Commerce → Products once Products AND Linking complete the first
// user-visible activation boundary (design spec §6/§9). Mirrors collectionsGating.spec.ts.
describe('commerce admin module gating (thallo.commerce capability, Task-9 scaffold)', () => {
  it('is registered with the correct id and capability requirement, and has no nav yet', () => {
    expect(commerceModule.id).toBe('commerce')
    expect(commerceModule.requires).toEqual(['thallo.commerce'])
    expect(commerceModule.nav).toBeUndefined()
  })

  it('contributes no navigation when thallo.commerce is not visible', () => {
    const [main, utilities] = visibleNav(() => false, [commerceModule])
    expect(main).toEqual([])
    expect(utilities).toEqual([])
  })

  it('still contributes NO navigation even when thallo.commerce IS visible (nav lands in Task 12)', () => {
    const [main, utilities] = visibleNav((id) => id === 'thallo.commerce', [commerceModule])
    expect(main).toEqual([])
    expect(utilities).toEqual([])
  })
})
