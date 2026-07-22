import { describe, it, expect } from 'vitest'
import { visibleNav } from '@/registry/adminModules'
import { commerceModule } from '@/registry/commerceModule'

// Task 9 (admin-commerce-area plan, slice 3) registered the Commerce module capability-gated on
// `thallo.commerce` with NO navigation contributed yet. Task 12 completes the P3 activation
// boundary (design spec §6/§9): Commerce → Products now appears once Products AND bidirectional
// linking have both landed — this is the first user-visible nav entry for the whole area. Later
// phases (Orders, etc.) append their OWN children to the SAME Commerce group rather than
// registering a second top-level entry.
describe('commerce admin module gating (thallo.commerce capability)', () => {
  it('is registered with the correct id and capability requirement', () => {
    expect(commerceModule.id).toBe('commerce')
    expect(commerceModule.requires).toEqual(['thallo.commerce'])
  })

  it('contributes no navigation when thallo.commerce is not visible', () => {
    const [main, utilities] = visibleNav(() => false, [commerceModule])
    expect(main).toEqual([])
    expect(utilities).toEqual([])
  })

  it('contributes Commerce → Products when thallo.commerce IS visible', () => {
    const [main, utilities] = visibleNav((id) => id === 'thallo.commerce', [commerceModule])
    expect(utilities).toEqual([])
    expect(main).toEqual([
      {
        label: 'Commerce',
        icon: 'i-lucide-shopping-cart',
        defaultOpen: false,
        children: [{ label: 'Products', to: '/commerce/products' }],
      },
    ])
  })
})
