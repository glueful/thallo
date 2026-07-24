import { describe, it, expect } from 'vitest'
import { visibleNav } from '@/registry/adminModules'
import { commerceModule } from '@/registry/commerceModule'

// Task 9 (admin-commerce-area plan, slice 3) registered the Commerce module capability-gated on
// `thallo.commerce` with NO navigation contributed yet. Task 12 completed the P3 activation
// boundary (design spec §6/§9): Commerce → Products appeared once Products AND bidirectional
// linking had both landed — the first user-visible nav entry for the whole area. Task 13a appends
// Orders to the SAME Commerce group rather than registering a second top-level entry. Task 14
// appends Discounts the same way. Task 15a appends Settings the same way once its first tab
// (Shipping zones) is green. Task 16 appends Reviews the same way once moderation is green. Task 17
// appends Customers the same way (a read-only surface, no can_manage gating behind it). Task 18
// (completing phase P6) PREPENDS Overview (reports) as the FIRST child instead of appending — the
// landing page for the whole area, ahead of Products.
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

  it('contributes Commerce → Overview, Products, Orders, Discounts, Settings, Reviews, Customers when thallo.commerce IS visible, with Overview FIRST', () => {
    const [main, utilities] = visibleNav((id) => id === 'thallo.commerce', [commerceModule])
    expect(utilities).toEqual([])
    expect(main).toEqual([
      {
        label: 'Commerce',
        icon: 'i-lucide-shopping-cart',
        defaultOpen: false,
        children: [
          // `exact`: Overview's path prefixes every sibling — without it, default link
          // matching shows TWO active items on /commerce/products etc.
          { label: 'Overview', to: '/commerce', exact: true },
          { label: 'Products', to: '/commerce/products' },
          { label: 'Orders', to: '/commerce/orders' },
          { label: 'Discounts', to: '/commerce/discounts' },
          { label: 'Settings', to: '/commerce/settings' },
          { label: 'Reviews', to: '/commerce/reviews' },
          { label: 'Customers', to: '/commerce/customers' },
        ],
      },
    ])
  })

  it('places Overview strictly first among the Commerce children, exact-matched (its path prefixes every sibling)', () => {
    const [main] = visibleNav((id) => id === 'thallo.commerce', [commerceModule])
    expect(main[0]!.children![0]).toEqual({ label: 'Overview', to: '/commerce', exact: true })
  })
})
