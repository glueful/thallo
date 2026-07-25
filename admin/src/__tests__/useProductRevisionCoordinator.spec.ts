import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, inject, ref } from 'vue'
import { mount } from '@vue/test-utils'
import {
  ProductRevisionCoordinatorKey,
  useProductRevisionCoordinator,
  type SectionRegistration,
} from '@/composables/useProductRevisionCoordinator'
import type { SectionEnvelope } from '@/queries/commerceProductSections'

// Single-page product editor plan, Task C3: `useProductRevisionCoordinator()` orchestrates
// refreshing every registered section against fresh `{revision, items}` envelopes — it decides
// ONLY which callback (`adoptRemote` vs `reconcileRemote`) a section gets, never what the merged
// content should be, and never writes a section's own `baseRevision`. See
// `.superpowers/sdd/editor/task-C3-brief.md`.

/** A minimal item shape — the coordinator is generic over `T` and never inspects `items` itself,
 * so the exact shape used in these specs is arbitrary. */
interface Item {
  uuid: string
}

/** Builds a stub `SectionRegistration<Item>` with controllable `baseRevision`/`dirty` refs and
 * spy-wrapped `refetch`/`adoptRemote`/`reconcileRemote`, plus the envelope `refetch()` resolves
 * with (exposed directly for `toHaveBeenCalledWith` assertions). */
function makeSection(opts: {
  baseRevision?: number | null
  dirty?: boolean
  envelope: SectionEnvelope<Item>
}) {
  const baseRevision = ref<number | null>(opts.baseRevision ?? null)
  const dirty = ref(opts.dirty ?? false)
  const refetch = vi.fn(async () => opts.envelope)
  const adoptRemote = vi.fn()
  const reconcileRemote = vi.fn()
  const registration: SectionRegistration<Item> = {
    baseRevision,
    dirty,
    refetch,
    adoptRemote,
    reconcileRemote,
  }
  return {
    baseRevision,
    dirty,
    refetch,
    adoptRemote,
    reconcileRemote,
    registration,
    envelope: opts.envelope,
  }
}

async function flushMicrotasks(times = 10): Promise<void> {
  for (let i = 0; i < times; i++) await Promise.resolve()
}

describe('useProductRevisionCoordinator — register/refresh routing', () => {
  it('refresh(sectionId) refreshes only the named section, leaving others untouched', async () => {
    const categories = makeSection({ envelope: { revision: 1, items: [] } })
    const tags = makeSection({ envelope: { revision: 1, items: [] } })
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', categories.registration)
    coordinator.register('tags', tags.registration)

    await coordinator.refresh('categories')

    expect(categories.refetch).toHaveBeenCalledTimes(1)
    expect(tags.refetch).not.toHaveBeenCalled()
  })

  it('refresh() for a valid id with no live registration is a safe no-op', async () => {
    // Typos are compile errors now (the id param is the closed CommerceProductSection union);
    // the only runtime no-op left is a legitimately-unregistered (e.g. unmounted) section.
    const coordinator = useProductRevisionCoordinator()
    await expect(coordinator.refresh('stock')).resolves.toBeUndefined()
  })

  it('afterMutation() refreshes every registered section', async () => {
    const categories = makeSection({ envelope: { revision: 5, items: [{ uuid: 'cat-1' }] } })
    const tags = makeSection({ envelope: { revision: 5, items: [{ uuid: 'tag-1' }] } })
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', categories.registration)
    coordinator.register('tags', tags.registration)

    await coordinator.afterMutation()

    expect(categories.refetch).toHaveBeenCalledTimes(1)
    expect(tags.refetch).toHaveBeenCalledTimes(1)
    expect(categories.adoptRemote).toHaveBeenCalledWith(categories.envelope)
    expect(tags.adoptRemote).toHaveBeenCalledWith(tags.envelope)
  })

  it('routes clean sections to adoptRemote and dirty sections to reconcileRemote — the coordinator never chooses content itself', async () => {
    const clean = makeSection({ dirty: false, envelope: { revision: 3, items: [] } })
    const dirty = makeSection({ dirty: true, envelope: { revision: 3, items: [] } })
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', clean.registration)
    coordinator.register('tags', dirty.registration)

    await coordinator.afterMutation()

    expect(clean.adoptRemote).toHaveBeenCalledTimes(1)
    expect(clean.reconcileRemote).not.toHaveBeenCalled()
    expect(dirty.reconcileRemote).toHaveBeenCalledTimes(1)
    expect(dirty.adoptRemote).not.toHaveBeenCalled()
  })
})

describe('useProductRevisionCoordinator — observedRevision / baseRevision ownership', () => {
  it('observedRevision updates from a CLEAN section envelope', async () => {
    const clean = makeSection({ dirty: false, envelope: { revision: 12, items: [] } })
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', clean.registration)
    expect(coordinator.observedRevision.value).toBeNull()

    await coordinator.afterMutation()

    expect(coordinator.observedRevision.value).toBe(12)
  })

  it('observedRevision updates from a DIRTY section envelope too, but that section baseRevision is never written by the coordinator', async () => {
    const dirty = makeSection({
      dirty: true,
      baseRevision: 7,
      envelope: { revision: 12, items: [] },
    })
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', dirty.registration)

    await coordinator.afterMutation()

    expect(coordinator.observedRevision.value).toBe(12) // display-only, updates regardless of dirty state
    expect(dirty.baseRevision.value).toBe(7) // untouched — only the section's own reconcileRemote may advance it
    expect(dirty.reconcileRemote).toHaveBeenCalledWith(dirty.envelope)
  })

  it('a section that flips dirty WHILE its own refetch is in flight is routed to reconcileRemote, not adoptRemote (sampled at completion, not up front)', async () => {
    let resolveFetch!: (v: SectionEnvelope<Item>) => void
    const refetch = vi.fn(
      () => new Promise<SectionEnvelope<Item>>((resolve) => (resolveFetch = resolve)),
    )
    const dirty = ref(false) // clean at the moment refresh() is called
    const baseRevision = ref<number | null>(1)
    const adoptRemote = vi.fn()
    const reconcileRemote = vi.fn()
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', {
      baseRevision,
      dirty,
      refetch,
      adoptRemote,
      reconcileRemote,
    })

    const pending = coordinator.refresh('categories')
    // `refresh()` queues its work as a microtask (the serial queue, see the composable's own
    // docblock) rather than calling refetch() synchronously — flush first so `resolveFetch` is
    // actually captured before the section flips dirty and this resolves it.
    await flushMicrotasks()
    dirty.value = true // an edit lands while the refetch this very call kicked off is still in flight

    resolveFetch({ revision: 2, items: [] })
    await pending

    expect(reconcileRemote).toHaveBeenCalledTimes(1)
    expect(adoptRemote).not.toHaveBeenCalled()
  })

  it('the reverse: a section that turns CLEAN while its refetch is in flight is routed to adoptRemote (sampled at completion, not up front)', async () => {
    let resolveFetch!: (v: SectionEnvelope<Item>) => void
    const refetch = vi.fn(
      () => new Promise<SectionEnvelope<Item>>((resolve) => (resolveFetch = resolve)),
    )
    const dirty = ref(true) // dirty at the moment refresh() is called
    const baseRevision = ref<number | null>(1)
    const adoptRemote = vi.fn()
    const reconcileRemote = vi.fn()
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', {
      baseRevision,
      dirty,
      refetch,
      adoptRemote,
      reconcileRemote,
    })

    const pending = coordinator.refresh('categories')
    await flushMicrotasks()
    dirty.value = false // e.g. the section's own save/markClean landed while this refetch was in flight

    resolveFetch({ revision: 2, items: [] })
    await pending

    expect(adoptRemote).toHaveBeenCalledTimes(1)
    expect(reconcileRemote).not.toHaveBeenCalled()
  })
})

describe('useProductRevisionCoordinator — refreshing flag + allSettled failure isolation', () => {
  it('refreshing is true while a refresh is in flight and false once it settles', async () => {
    let resolveFetch!: (v: SectionEnvelope<Item>) => void
    const refetch = vi.fn(
      () => new Promise<SectionEnvelope<Item>>((resolve) => (resolveFetch = resolve)),
    )
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', {
      baseRevision: ref(null),
      dirty: ref(false),
      refetch,
      adoptRemote: vi.fn(),
      reconcileRemote: vi.fn(),
    })

    expect(coordinator.refreshing.value).toBe(false)
    const pending = coordinator.refresh('categories')
    expect(coordinator.refreshing.value).toBe(true)

    await flushMicrotasks()
    resolveFetch({ revision: 1, items: [] })
    await pending

    expect(coordinator.refreshing.value).toBe(false)
  })

  it('a rejected refetch clears refreshing and leaves that section baseRevision/dirty untouched — no adopt/reconcile call', async () => {
    const refetch = vi.fn().mockRejectedValue(new Error('network down'))
    const dirty = ref(false)
    const baseRevision = ref<number | null>(4)
    const adoptRemote = vi.fn()
    const reconcileRemote = vi.fn()
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', {
      baseRevision,
      dirty,
      refetch,
      adoptRemote,
      reconcileRemote,
    })

    await expect(coordinator.refresh('categories')).resolves.toBeUndefined()

    expect(coordinator.refreshing.value).toBe(false)
    expect(adoptRemote).not.toHaveBeenCalled()
    expect(reconcileRemote).not.toHaveBeenCalled()
    expect(baseRevision.value).toBe(4)
    expect(dirty.value).toBe(false)
    expect(coordinator.observedRevision.value).toBeNull() // never saw a successful envelope
  })

  it('afterMutation(): one section rejecting does not abort or delay the others (Promise.allSettled semantics)', async () => {
    const failing = makeSection({ envelope: { revision: 1, items: [] } })
    failing.refetch.mockReset()
    failing.refetch.mockRejectedValue(new Error('boom'))
    const succeeding = makeSection({ envelope: { revision: 9, items: [{ uuid: 'tag-1' }] } })

    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', failing.registration)
    coordinator.register('tags', succeeding.registration)

    await expect(coordinator.afterMutation()).resolves.toBeUndefined()

    expect(failing.adoptRemote).not.toHaveBeenCalled()
    expect(failing.reconcileRemote).not.toHaveBeenCalled()
    expect(succeeding.adoptRemote).toHaveBeenCalledWith(succeeding.envelope)
    expect(coordinator.refreshing.value).toBe(false)
  })
})

describe('useProductRevisionCoordinator — reentrancy', () => {
  it('a second afterMutation() issued while one is running is queued serially, not run in parallel', async () => {
    const order: string[] = []
    let resolveFirst!: (v: SectionEnvelope<Item>) => void
    const refetch = vi.fn()
    refetch.mockImplementationOnce(() => {
      order.push('refetch-1-start')
      return new Promise<SectionEnvelope<Item>>((resolve) => {
        resolveFirst = (v) => {
          order.push('refetch-1-resolve')
          resolve(v)
        }
      })
    })
    refetch.mockImplementationOnce(async () => {
      order.push('refetch-2')
      return { revision: 2, items: [] }
    })
    const adoptRemote = vi.fn((remote: SectionEnvelope<Item>) =>
      order.push(`adopt-${remote.revision}`),
    )
    const coordinator = useProductRevisionCoordinator()
    coordinator.register('categories', {
      baseRevision: ref(null),
      dirty: ref(false),
      refetch,
      adoptRemote,
      reconcileRemote: vi.fn(),
    })

    const callA = coordinator.afterMutation()
    const callB = coordinator.afterMutation()
    expect(coordinator.refreshing.value).toBe(true)

    // However many microtasks we flush, callB's work cannot progress until callA's own refetch
    // (still pending on `resolveFirst`) settles — this is a hard dependency of the serial queue,
    // not a timing race, so this assertion is not flaky regardless of flush count.
    await flushMicrotasks()
    expect(order).toEqual(['refetch-1-start'])
    expect(refetch).toHaveBeenCalledTimes(1)

    resolveFirst({ revision: 1, items: [] })
    await callA
    await callB

    expect(order).toEqual([
      'refetch-1-start',
      'refetch-1-resolve',
      'adopt-1',
      'refetch-2',
      'adopt-2',
    ])
    expect(coordinator.refreshing.value).toBe(false)
  })
})

describe('useProductRevisionCoordinator — provide/inject', () => {
  const Child = defineComponent({
    setup() {
      return { injected: inject(ProductRevisionCoordinatorKey, null) }
    },
    render() {
      return null
    },
  })

  const Host = defineComponent({
    setup() {
      const coordinator = useProductRevisionCoordinator()
      return { coordinator }
    },
    render() {
      return h(Child)
    },
  })

  it('provides one coordinator instance under ProductRevisionCoordinatorKey for descendants to inject (same pattern as DirtyRegistryKey)', () => {
    const wrapper = mount(Host)
    const child = wrapper.findComponent(Child)
    expect(child.vm.injected).toBe(wrapper.vm.coordinator)
  })
})

describe('useProductRevisionCoordinator — deregistration', () => {
  it('a deregistered section is skipped by afterMutation() and refresh()', async () => {
    const categories = makeSection({ envelope: { revision: 2, items: [] } })
    const tags = makeSection({ envelope: { revision: 2, items: [] } })
    const coordinator = useProductRevisionCoordinator()
    const deregister = coordinator.register('categories', categories.registration)
    coordinator.register('tags', tags.registration)

    deregister()
    await coordinator.afterMutation()

    expect(categories.refetch).not.toHaveBeenCalled()
    expect(tags.refetch).toHaveBeenCalledTimes(1)

    await coordinator.refresh('categories')
    expect(categories.refetch).not.toHaveBeenCalled()
  })

  it('deregister is idempotent and identity-checked: a stale deregister never removes a newer registration', async () => {
    const first = makeSection({ envelope: { revision: 1, items: [] } })
    const second = makeSection({ envelope: { revision: 2, items: [] } })
    const coordinator = useProductRevisionCoordinator()
    const deregisterFirst = coordinator.register('categories', first.registration)
    // The id is legitimately reused by a newer registration (e.g. remount)…
    coordinator.register('categories', second.registration)

    // …so the FIRST registration's late unmount cleanup must not evict it.
    deregisterFirst()
    deregisterFirst()

    await coordinator.refresh('categories')
    expect(second.refetch).toHaveBeenCalledTimes(1)
    expect(first.refetch).not.toHaveBeenCalled()
  })
})
