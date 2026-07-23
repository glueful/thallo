import { describe, it, expect, vi, afterEach } from 'vitest'
import { defineComponent, h, nextTick, ref, computed } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory, RouterView, isNavigationFailure } from 'vue-router'
import {
  createDirtyRegistry,
  useSectionState,
  useUnsavedGuard,
  DirtyRegistryKey,
  SECTION_SAVED_DECAY_MS,
  type DirtyRegistry,
  type BlockedSection,
} from '@/composables/useSectionState'

// Single-page product editor plan, Task C2: the section state machine (phase/dirty axes), the
// cross-section DirtyRegistry, and the navigation guard later section panels (C4-C8) are built
// against. Global Constraints §10: "Navigation blocks while `dirty || phase === 'saving'`. No
// automatic conflict retries — ever."

afterEach(() => {
  vi.restoreAllMocks()
})

// ---------------------------------------------------------------------------------------------
// useSectionState — state machine
// ---------------------------------------------------------------------------------------------

/** A registry stub for state-machine-only specs: satisfies the `inject()` requirement, ignores
 * registration (each test asserts on the returned `SectionState` directly, not the registry). */
function stubRegistry(): DirtyRegistry {
  return {
    register: () => () => {},
    blockedSections: () => [],
    isBlocked: computed(() => false),
  }
}

const SectionProbe = defineComponent({
  props: {
    id: { type: String, required: true },
    label: { type: String, required: true },
  },
  // Deliberately NOT `expose()` + a setup-returned render function: @vue/test-utils'
  // `findComponent(...).vm` reads the child instance's raw `proxy` (unlike the ROOT wrapper,
  // which resolves through a template ref and so DOES honor `expose()`) — returning the state
  // object from `setup()` instead puts it on `setupState`, which both access paths read from.
  setup(props) {
    return useSectionState(props.id, props.label)
  },
  render() {
    return null
  },
})

function mountSection(registry: DirtyRegistry = stubRegistry()) {
  return mount(SectionProbe, {
    props: { id: 'pricing', label: 'Pricing' },
    global: { provide: { [DirtyRegistryKey as symbol]: registry } },
  })
}

describe('useSectionState — phase/dirty state machine', () => {
  it('starts idle and not dirty', () => {
    const wrapper = mountSection()
    expect(wrapper.vm.phase).toBe('idle')
    expect(wrapper.vm.dirty).toBe(false)
  })

  it('markDirty() sets dirty=true and leaves phase idle from idle', () => {
    const wrapper = mountSection()
    wrapper.vm.markDirty()
    expect(wrapper.vm.phase).toBe('idle')
    expect(wrapper.vm.dirty).toBe(true)
  })

  it('beginSave() sets phase=saving without touching dirty', () => {
    const wrapper = mountSection()
    wrapper.vm.markDirty()
    wrapper.vm.beginSave()
    expect(wrapper.vm.phase).toBe('saving')
    expect(wrapper.vm.dirty).toBe(true)
  })

  it('saveSucceeded() sets phase=saved and dirty=false', () => {
    const wrapper = mountSection()
    wrapper.vm.markDirty()
    wrapper.vm.beginSave()
    wrapper.vm.saveSucceeded()
    expect(wrapper.vm.phase).toBe('saved')
    expect(wrapper.vm.dirty).toBe(false)
  })

  it('saveFailed() sets phase=error and LEAVES dirty=true (no automatic conflict retries)', () => {
    const wrapper = mountSection()
    wrapper.vm.markDirty()
    wrapper.vm.beginSave()
    wrapper.vm.saveFailed()
    expect(wrapper.vm.phase).toBe('error')
    expect(wrapper.vm.dirty).toBe(true)
  })

  it('markDirty() after saveFailed() clears the error chip to idle; dirty stays true', () => {
    const wrapper = mountSection()
    wrapper.vm.markDirty()
    wrapper.vm.beginSave()
    wrapper.vm.saveFailed()
    expect(wrapper.vm.phase).toBe('error')

    wrapper.vm.markDirty()
    expect(wrapper.vm.phase).toBe('idle')
    expect(wrapper.vm.dirty).toBe(true)
  })

  it('markClean() clears dirty and resets phase to idle regardless of prior phase (external adoption)', () => {
    const wrapper = mountSection()
    wrapper.vm.markDirty()
    wrapper.vm.beginSave()
    wrapper.vm.saveFailed()
    expect(wrapper.vm.phase).toBe('error')

    wrapper.vm.markClean()
    expect(wrapper.vm.phase).toBe('idle')
    expect(wrapper.vm.dirty).toBe(false)
  })

  it('decays from saved to idle after SECTION_SAVED_DECAY_MS', () => {
    vi.useFakeTimers()
    try {
      const wrapper = mountSection()
      wrapper.vm.beginSave()
      wrapper.vm.saveSucceeded()
      expect(wrapper.vm.phase).toBe('saved')

      vi.advanceTimersByTime(SECTION_SAVED_DECAY_MS - 1)
      expect(wrapper.vm.phase).toBe('saved')

      vi.advanceTimersByTime(1)
      expect(wrapper.vm.phase).toBe('idle')
      expect(wrapper.vm.dirty).toBe(false)
    } finally {
      vi.useRealTimers()
    }
  })

  it('markDirty() during the saved-decay window returns to idle+dirty and cancels the decay', () => {
    vi.useFakeTimers()
    try {
      const wrapper = mountSection()
      wrapper.vm.beginSave()
      wrapper.vm.saveSucceeded()

      vi.advanceTimersByTime(SECTION_SAVED_DECAY_MS / 2)
      wrapper.vm.markDirty()
      expect(wrapper.vm.phase).toBe('idle')
      expect(wrapper.vm.dirty).toBe(true)

      // The cancelled decay timer must never fire and flip phase out from under the re-edit.
      vi.advanceTimersByTime(SECTION_SAVED_DECAY_MS)
      expect(wrapper.vm.phase).toBe('idle')
      expect(wrapper.vm.dirty).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })

  it('cancels the pending saved-decay timer when a new beginSave() starts before it fires', () => {
    vi.useFakeTimers()
    try {
      const wrapper = mountSection()
      wrapper.vm.beginSave()
      wrapper.vm.saveSucceeded()

      vi.advanceTimersByTime(SECTION_SAVED_DECAY_MS - 500)
      wrapper.vm.beginSave() // re-save starts before the old decay fires
      expect(wrapper.vm.phase).toBe('saving')

      // Past when the STALE timer would have fired — must not reset phase to idle mid-save.
      vi.advanceTimersByTime(1000)
      expect(wrapper.vm.phase).toBe('saving')
    } finally {
      vi.useRealTimers()
    }
  })

  it('markClean() cancels a pending saved-decay timer', () => {
    vi.useFakeTimers()
    try {
      const wrapper = mountSection()
      wrapper.vm.beginSave()
      wrapper.vm.saveSucceeded()
      wrapper.vm.markClean()
      expect(wrapper.vm.phase).toBe('idle')

      expect(() => vi.advanceTimersByTime(SECTION_SAVED_DECAY_MS + 100)).not.toThrow()
      expect(wrapper.vm.phase).toBe('idle')
    } finally {
      vi.useRealTimers()
    }
  })

  it('cancels a pending decay timer on unmount (no post-unmount mutation)', () => {
    vi.useFakeTimers()
    try {
      const wrapper = mountSection()
      wrapper.vm.beginSave()
      wrapper.vm.saveSucceeded()
      wrapper.unmount()

      expect(() => vi.advanceTimersByTime(SECTION_SAVED_DECAY_MS + 100)).not.toThrow()
    } finally {
      vi.useRealTimers()
    }
  })
})

// ---------------------------------------------------------------------------------------------
// createDirtyRegistry + useSectionState — provide/inject wiring, blocked math, unmount cleanup
// ---------------------------------------------------------------------------------------------

const Host = defineComponent({
  // A plain setup()-returned object (not a render function + `expose()`): both the root `mount()`
  // wrapper AND `findComponent()` need typed access to `registry`/`showA`/`showB` from outside,
  // and only a setup-returned object shows up on the inferred public-instance type.
  setup() {
    const registry = createDirtyRegistry()
    const showA = ref(true)
    const showB = ref(true)
    return { registry, showA, showB }
  },
  render() {
    return h('div', [
      this.showA ? h(SectionProbe, { id: 'a', label: 'Section A' }) : null,
      this.showB ? h(SectionProbe, { id: 'b', label: 'Section B' }) : null,
    ])
  },
})

describe('DirtyRegistry — blocked math + unmount deregistration', () => {
  it('reports isBlocked=false and blockedSections=[] when nothing is dirty/saving', () => {
    const wrapper = mount(Host)
    expect(wrapper.vm.registry.isBlocked.value).toBe(false)
    expect(wrapper.vm.registry.blockedSections()).toEqual([])
  })

  it('isBlocked=true and blockedSections lists a dirty section', () => {
    const wrapper = mount(Host)
    const [probeA] = wrapper.findAllComponents(SectionProbe)
    probeA!.vm.markDirty()

    expect(wrapper.vm.registry.isBlocked.value).toBe(true)
    expect(wrapper.vm.registry.blockedSections()).toEqual([
      { id: 'a', label: 'Section A' },
    ] satisfies BlockedSection[])
  })

  it('lists multiple blocked sections independently (dirty AND saving both block)', () => {
    const wrapper = mount(Host)
    const [probeA, probeB] = wrapper.findAllComponents(SectionProbe)
    probeA!.vm.markDirty()
    probeB!.vm.beginSave()

    expect(wrapper.vm.registry.isBlocked.value).toBe(true)
    expect(wrapper.vm.registry.blockedSections()).toEqual([
      { id: 'a', label: 'Section A' },
      { id: 'b', label: 'Section B' },
    ])
  })

  it('a clean section never appears in blockedSections even while a sibling is dirty', () => {
    const wrapper = mount(Host)
    const [probeA] = wrapper.findAllComponents(SectionProbe)
    probeA!.vm.markDirty()

    expect(wrapper.vm.registry.blockedSections()).toEqual([{ id: 'a', label: 'Section A' }])
  })

  it('a section unmounting (scroll-away card) deregisters and stops blocking navigation', async () => {
    const wrapper = mount(Host)
    const [probeA] = wrapper.findAllComponents(SectionProbe)
    probeA!.vm.markDirty()
    expect(wrapper.vm.registry.isBlocked.value).toBe(true)

    wrapper.vm.showA = false
    await nextTick()

    expect(wrapper.findAllComponents(SectionProbe)).toHaveLength(1)
    expect(wrapper.vm.registry.isBlocked.value).toBe(false)
    expect(wrapper.vm.registry.blockedSections()).toEqual([])
  })

  it('unmounting a NON-dirty section leaves the still-dirty sibling blocking', async () => {
    const wrapper = mount(Host)
    const [probeA] = wrapper.findAllComponents(SectionProbe)
    probeA!.vm.markDirty()

    // Section B (clean) scrolls away and unmounts — must not affect A's block.
    wrapper.vm.showB = false
    await nextTick()

    expect(wrapper.vm.registry.isBlocked.value).toBe(true)
    expect(wrapper.vm.registry.blockedSections()).toEqual([{ id: 'a', label: 'Section A' }])
  })

  it('throws when useSectionState is used with no ancestor DirtyRegistry', () => {
    expect(() => mount(SectionProbe, { props: { id: 'orphan', label: 'Orphan' } })).toThrow(
      /no DirtyRegistry/,
    )
  })
})

// ---------------------------------------------------------------------------------------------
// useUnsavedGuard — onBeforeRouteLeave confirm + beforeunload
// ---------------------------------------------------------------------------------------------

/** A registry double the guard specs drive directly, independent of useSectionState. */
function controllableRegistry() {
  const blockedRef = ref(false)
  let sections: BlockedSection[] = []
  const registry: DirtyRegistry = {
    register: () => () => {},
    blockedSections: () => sections,
    isBlocked: computed(() => blockedRef.value),
  }
  return {
    registry,
    setBlocked(value: boolean, withSections: BlockedSection[] = []) {
      blockedRef.value = value
      sections = withSections
    },
  }
}

function makeGuardedRouteComponent(registry: DirtyRegistry) {
  return defineComponent({
    setup() {
      useUnsavedGuard(registry)
      return () => h('div', 'guarded section page')
    },
  })
}

const RouteB = defineComponent({ render: () => h('div', 'destination page') })

async function mountRouterAt(routeAComponent: ReturnType<typeof makeGuardedRouteComponent>) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/a', component: routeAComponent },
      { path: '/b', component: RouteB },
    ],
  })
  await router.push('/a')
  await router.isReady()

  const wrapper = mount(defineComponent({ setup: () => () => h(RouterView) }), {
    global: { plugins: [router] },
  })
  await flushPromises()
  return { router, wrapper }
}

describe('useUnsavedGuard — onBeforeRouteLeave', () => {
  it('allows navigation without prompting when nothing is blocked', async () => {
    const { registry } = controllableRegistry()
    const { router } = await mountRouterAt(makeGuardedRouteComponent(registry))
    const confirmSpy = vi.spyOn(window, 'confirm')

    await router.push('/b')

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/b')
  })

  it('prompts listing the blocked section labels, and navigates away when confirmed', async () => {
    const { registry, setBlocked } = controllableRegistry()
    setBlocked(true, [{ id: 'pricing', label: 'Pricing' }])
    const { router } = await mountRouterAt(makeGuardedRouteComponent(registry))
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)

    await router.push('/b')

    expect(confirmSpy).toHaveBeenCalledTimes(1)
    expect(confirmSpy.mock.calls[0]?.[0]).toContain('Pricing')
    expect(router.currentRoute.value.path).toBe('/b')
  })

  it('blocks navigation and stays on the page when the user cancels the confirm', async () => {
    const { registry, setBlocked } = controllableRegistry()
    setBlocked(true, [{ id: 'pricing', label: 'Pricing' }])
    const { router } = await mountRouterAt(makeGuardedRouteComponent(registry))
    vi.spyOn(window, 'confirm').mockReturnValue(false)

    const failure = await router.push('/b')

    expect(isNavigationFailure(failure)).toBe(true)
    expect(router.currentRoute.value.path).toBe('/a')
  })

  it('lists every blocked section label when multiple sections are dirty/saving', async () => {
    const { registry, setBlocked } = controllableRegistry()
    setBlocked(true, [
      { id: 'pricing', label: 'Pricing' },
      { id: 'inventory', label: 'Inventory' },
    ])
    const { router } = await mountRouterAt(makeGuardedRouteComponent(registry))
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)

    await router.push('/b')

    const message = confirmSpy.mock.calls[0]?.[0] ?? ''
    expect(message).toContain('Pricing')
    expect(message).toContain('Inventory')
  })
})

describe('useUnsavedGuard — beforeunload', () => {
  it('registers a beforeunload listener on mount and removes it on unmount', async () => {
    const { registry } = controllableRegistry()
    const addSpy = vi.spyOn(window, 'addEventListener')
    const removeSpy = vi.spyOn(window, 'removeEventListener')

    const { wrapper } = await mountRouterAt(makeGuardedRouteComponent(registry))

    const addCall = addSpy.mock.calls.find(([type]) => type === 'beforeunload')
    expect(addCall).toBeDefined()
    const handler = addCall?.[1] as EventListener

    wrapper.unmount()
    expect(removeSpy).toHaveBeenCalledWith('beforeunload', handler)
  })

  it('preventDefaults and sets returnValue only while blocked', async () => {
    const { registry, setBlocked } = controllableRegistry()
    const addSpy = vi.spyOn(window, 'addEventListener')

    await mountRouterAt(makeGuardedRouteComponent(registry))
    const handler = addSpy.mock.calls.find(([type]) => type === 'beforeunload')?.[1] as (
      e: BeforeUnloadEvent,
    ) => void

    const cleanEvent = { preventDefault: vi.fn(), returnValue: '' } as unknown as BeforeUnloadEvent
    handler(cleanEvent)
    expect(cleanEvent.preventDefault).not.toHaveBeenCalled()

    setBlocked(true, [{ id: 'pricing', label: 'Pricing' }])
    const dirtyEvent = { preventDefault: vi.fn(), returnValue: '' } as unknown as BeforeUnloadEvent
    handler(dirtyEvent)
    expect(dirtyEvent.preventDefault).toHaveBeenCalledTimes(1)
    expect(dirtyEvent.returnValue).toBe('')
  })
})
