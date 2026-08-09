import { describe, it, expect } from 'vitest'
import { visibleNav } from '@/registry/adminModules'
import { subscriptionsModule } from '@/registry/subscriptionsModule'

// Task 11 (thallo-subscriptions Phase B): the Subscriptions admin module, gated on
// `thallo.subscriptions`, contributing a single top-level Subscriptions group with Plans and
// Billing children -- mirrors `commerceGating.spec.ts`'s established registry-test idiom.
describe('subscriptions admin module gating (thallo.subscriptions capability)', () => {
  it('is registered with the correct id and capability requirement', () => {
    expect(subscriptionsModule.id).toBe('subscriptions')
    expect(subscriptionsModule.requires).toEqual(['thallo.subscriptions'])
  })

  it('contributes no navigation when thallo.subscriptions is not visible', () => {
    const [main, utilities] = visibleNav(() => false, [subscriptionsModule])
    expect(main).toEqual([])
    expect(utilities).toEqual([])
  })

  it('contributes Subscriptions → Plans, Billing, Workspace billing when thallo.subscriptions IS visible', () => {
    const [main, utilities] = visibleNav((id) => id === 'thallo.subscriptions', [subscriptionsModule])
    expect(utilities).toEqual([])
    expect(main).toEqual([
      {
        label: 'Subscriptions',
        icon: 'i-lucide-credit-card',
        defaultOpen: false,
        children: [
          { label: 'Plans', to: '/subscriptions/plans' },
          { label: 'Billing', to: '/subscriptions/billing' },
          { label: 'Workspace billing', to: '/billing' },
        ],
      },
    ])
  })
})
