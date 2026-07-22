import { describe, it, expect, vi, beforeEach } from 'vitest'

// Pins the Task 15a invalidation contract: `createZone` invalidates only the zones list (a
// brand-new zone has no existing detail-key consumer yet — mirrors
// `useCommerceDiscountMutations()`'s create precedent). `updateZone`/`deleteZone`/`setLocations`
// invalidate BOTH the zone detail key and the list. `createMethod`/`updateMethod`/`deleteMethod`
// are doubly zone-scoped: the zone-scoped methods key AND the owning zone's own detail+list keys.
// The colada layer is mocked so each mutation's onSettled can be driven directly.

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

const cacheInvalidate = vi.hoisted(() => vi.fn())
vi.mock('@pinia/colada', () => ({
  useQueryCache: () => ({ invalidateQueries: cacheInvalidate }),
  useQuery: () => ({ data: { value: undefined }, status: { value: 'idle' } }),
  useMutation: (options: { onSettled?: () => void }) => ({ mutate: vi.fn(), ...options }),
}))

describe('useCommerceShippingZoneMutations invalidation', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
  })

  async function bundle() {
    const { useCommerceShippingZoneMutations } = await import('@/queries/commerceSettings')
    const { qk } = await import('@/queries/keys')
    const mutations = useCommerceShippingZoneMutations() as unknown as Record<
      'createZone' | 'updateZone' | 'deleteZone' | 'setLocations' | 'createMethod' | 'updateMethod' | 'deleteMethod',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  // ── Zone CRUD ────────────────────────────────────────────────────────────────────────────────

  it('createZone invalidates ONLY the zones list', async () => {
    const { mutations, qk } = await bundle()
    mutations.createZone.onSettled?.(undefined, undefined, { name: 'Domestic' })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceShippingZones() }]])
  })

  it('updateZone invalidates the zone detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.updateZone.onSettled?.(undefined, undefined, { uuid: 'z1', input: { name: 'Domestic Shipping' } })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZone('z1') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  it('updateZone still invalidates both keys when the mutation itself failed (a name conflict, say)', async () => {
    const { mutations, qk } = await bundle()
    mutations.updateZone.onSettled?.(undefined, new Error('Name already in use.'), {
      uuid: 'z2',
      input: { name: 'Domestic' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZone('z2') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  it('deleteZone invalidates the zone detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.deleteZone.onSettled?.(undefined, undefined, 'z3')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZone('z3') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  it('deleteZone still invalidates both keys when the mutation itself failed (an unknown zone, say)', async () => {
    const { mutations, qk } = await bundle()
    mutations.deleteZone.onSettled?.(undefined, new Error('Resource not found.'), 'z4')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZone('z4') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  // ── Locations set-list ───────────────────────────────────────────────────────────────────────

  it('setLocations invalidates the owning zone detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.setLocations.onSettled?.(undefined, undefined, {
      zoneUuid: 'z1',
      locations: [{ kind: 'country', value: 'US' }],
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZone('z1') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  it('setLocations still invalidates both keys when the mutation itself failed (a validation 422, say)', async () => {
    const { mutations, qk } = await bundle()
    mutations.setLocations.onSettled?.(undefined, new Error('locations.0.value: invalid.'), {
      zoneUuid: 'z2',
      locations: [],
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZone('z2') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  // ── Method CRUD: zone-scoped keys ────────────────────────────────────────────────────────────

  it('createMethod invalidates the zone-scoped methods key, the zone detail, AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.createMethod.onSettled?.(undefined, undefined, {
      zoneUuid: 'z1',
      input: { kind: 'flat', label: 'Standard', config: { amount: 500 } },
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZoneMethods('z1') }],
      [{ key: qk.commerceShippingZone('z1') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  it('updateMethod invalidates the zone-scoped methods key, the zone detail, AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.updateMethod.onSettled?.(undefined, undefined, {
      uuid: 'm1',
      zoneUuid: 'z1',
      input: { enabled: false },
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZoneMethods('z1') }],
      [{ key: qk.commerceShippingZone('z1') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  it('updateMethod still invalidates all keys when the mutation itself failed (a config 422, say)', async () => {
    const { mutations, qk } = await bundle()
    mutations.updateMethod.onSettled?.(undefined, new Error('config.amount: invalid.'), {
      uuid: 'm2',
      zoneUuid: 'z2',
      input: { config: { amount: -5 } },
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZoneMethods('z2') }],
      [{ key: qk.commerceShippingZone('z2') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  it('deleteMethod invalidates the zone-scoped methods key, the zone detail, AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.deleteMethod.onSettled?.(undefined, undefined, { uuid: 'm3', zoneUuid: 'z1' })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZoneMethods('z1') }],
      [{ key: qk.commerceShippingZone('z1') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })

  it('deleteMethod still invalidates all keys when the mutation itself failed', async () => {
    const { mutations, qk } = await bundle()
    mutations.deleteMethod.onSettled?.(undefined, new Error('Resource not found.'), {
      uuid: 'm4',
      zoneUuid: 'z3',
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingZoneMethods('z3') }],
      [{ key: qk.commerceShippingZone('z3') }],
      [{ key: qk.commerceShippingZones() }],
    ])
  })
})

// ── Task 15b: shipping class CRUD invalidation ──────────────────────────────────────────────
//
// Pins the same shape as the zone-mutation contract above: `createClass` invalidates ONLY the
// classes list (a brand-new class has no existing detail-key consumer yet). `updateClass`/
// `deleteClass` invalidate BOTH the class detail key and the list.

describe('useCommerceShippingClassMutations invalidation', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
  })

  async function classBundle() {
    const { useCommerceShippingClassMutations } = await import('@/queries/commerceSettings')
    const { qk } = await import('@/queries/keys')
    const mutations = useCommerceShippingClassMutations() as unknown as Record<
      'createClass' | 'updateClass' | 'deleteClass',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  it('createClass invalidates ONLY the classes list', async () => {
    const { mutations, qk } = await classBundle()
    mutations.createClass.onSettled?.(undefined, undefined, { slug: 'fragile', name: 'Fragile' })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceShippingClasses() }]])
  })

  it('updateClass invalidates the class detail AND the list', async () => {
    const { mutations, qk } = await classBundle()
    mutations.updateClass.onSettled?.(undefined, undefined, { uuid: 'c1', input: { name: 'Extra Fragile' } })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingClass('c1') }],
      [{ key: qk.commerceShippingClasses() }],
    ])
  })

  it('updateClass still invalidates both keys when the mutation itself failed (an immutable-slug 422, say)', async () => {
    const { mutations, qk } = await classBundle()
    mutations.updateClass.onSettled?.(undefined, new Error('slug is immutable.'), {
      uuid: 'c2',
      input: { name: 'x' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingClass('c2') }],
      [{ key: qk.commerceShippingClasses() }],
    ])
  })

  it('deleteClass invalidates the class detail AND the list', async () => {
    const { mutations, qk } = await classBundle()
    mutations.deleteClass.onSettled?.(undefined, undefined, 'c3')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingClass('c3') }],
      [{ key: qk.commerceShippingClasses() }],
    ])
  })

  it('deleteClass still invalidates both keys when the mutation itself failed (a referenced-class 409, say)', async () => {
    const { mutations, qk } = await classBundle()
    mutations.deleteClass.onSettled?.(
      undefined,
      new Error('This shipping class is still assigned to one or more variants. Detach it first.'),
      'c4',
    )

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceShippingClass('c4') }],
      [{ key: qk.commerceShippingClasses() }],
    ])
  })
})
