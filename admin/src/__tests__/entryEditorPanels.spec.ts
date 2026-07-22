import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { defineComponent, h, ref, type Ref } from 'vue'

const enabledIds = new Set<string>()
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: (id: string) => enabledIds.has(id) }),
}))

import {
  useVisibleEditorPanels,
  entryEditorPanels,
  type EntryEditorPanel,
  type EntryEditorPanelContext,
} from '@/registry/entryEditorPanels'

const ctx: EntryEditorPanelContext = { uuid: 'e-1', locale: 'en', type: 'page' }

function makePanel(overrides: Partial<EntryEditorPanel> & Pick<EntryEditorPanel, 'id' | 'order'>): EntryEditorPanel {
  return {
    label: overrides.id,
    component: { template: '<div />' },
    ...overrides,
  }
}

/** Mounts a throwaway component whose setup calls useVisibleEditorPanels once, matching how
 * the real entry editor calls it — so gate hooks run through real Vue setup semantics. */
function mountWith(panels: readonly EntryEditorPanel[]) {
  let panelsRef!: ReturnType<typeof useVisibleEditorPanels>
  const Comp = defineComponent({
    setup() {
      panelsRef = useVisibleEditorPanels(ctx, panels)
      return () => h('div')
    },
  })
  const wrapper = mount(Comp, { global: { plugins: [createPinia()] } })
  return { wrapper, ids: () => panelsRef.value.map((p) => p.id) }
}

describe('useVisibleEditorPanels', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    enabledIds.clear()
  })

  it('includes a panel with no requiresCapability and no gate (defaults to ready)', () => {
    const { ids } = mountWith([makePanel({ id: 'a', order: 1 })])
    expect(ids()).toEqual(['a'])
  })

  it('omits a panel whose required capability is not enabled', () => {
    const { ids } = mountWith([makePanel({ id: 'a', order: 1, requiresCapability: 'thallo.x' })])
    expect(ids()).toEqual([])
  })

  it('includes a panel whose required capability is enabled', () => {
    enabledIds.add('thallo.x')
    const { ids } = mountWith([makePanel({ id: 'a', order: 1, requiresCapability: 'thallo.x' })])
    expect(ids()).toEqual(['a'])
  })

  it("omits a panel whose gate is 'loading' (loading is never treated as enabled)", () => {
    const gate = ref<'ready' | 'hidden' | 'loading'>('loading')
    const { ids } = mountWith([makePanel({ id: 'a', order: 1, useGate: () => gate })])
    expect(ids()).toEqual([])
  })

  it("omits a panel whose gate is 'hidden'", () => {
    const gate = ref<'ready' | 'hidden' | 'loading'>('hidden')
    const { ids } = mountWith([makePanel({ id: 'a', order: 1, useGate: () => gate })])
    expect(ids()).toEqual([])
  })

  it("no flicker: a panel only appears once its gate settles to 'ready', never while 'loading'", () => {
    const gate = ref<'ready' | 'hidden' | 'loading'>('loading')
    const { ids } = mountWith([makePanel({ id: 'a', order: 1, useGate: () => gate })])
    expect(ids()).toEqual([]) // still loading — must not appear

    gate.value = 'ready'
    expect(ids()).toEqual(['a']) // settled — now admitted

    gate.value = 'hidden'
    expect(ids()).toEqual([]) // settled the other way — removed

    gate.value = 'ready'
    expect(ids()).toEqual(['a']) // re-admitted in place
  })

  it('invokes useGate exactly once per panel, even as its ref changes multiple times', () => {
    const gate = ref<'ready' | 'hidden' | 'loading'>('loading')
    const useGate = vi.fn((): Readonly<Ref<'ready' | 'hidden' | 'loading'>> => gate)
    const { ids } = mountWith([makePanel({ id: 'a', order: 1, useGate })])

    gate.value = 'ready'
    gate.value = 'hidden'
    gate.value = 'ready'
    ids()

    expect(useGate).toHaveBeenCalledTimes(1)
  })

  it('sorts admitted panels by order, independent of manifest declaration order', () => {
    const { ids } = mountWith([
      makePanel({ id: 'c', order: 30 }),
      makePanel({ id: 'a', order: 10 }),
      makePanel({ id: 'b', order: 20 }),
    ])
    expect(ids()).toEqual(['a', 'b', 'c'])
  })

  it('re-sorts as gates settle so a later-settling panel still lands in its declared order', () => {
    const gateA = ref<'ready' | 'hidden' | 'loading'>('loading')
    const { ids } = mountWith([
      makePanel({ id: 'b', order: 20 }),
      makePanel({ id: 'a', order: 10, useGate: () => gateA }),
      makePanel({ id: 'c', order: 30 }),
    ])
    expect(ids()).toEqual(['b', 'c'])

    gateA.value = 'ready'
    expect(ids()).toEqual(['a', 'b', 'c'])
  })

  it('an empty manifest yields no panels', () => {
    const { ids } = mountWith([])
    expect(ids()).toEqual([])
  })

  it('defaults to the real (currently empty) manifest when no panels argument is passed', () => {
    let panelsRef!: ReturnType<typeof useVisibleEditorPanels>
    const Comp = defineComponent({
      setup() {
        panelsRef = useVisibleEditorPanels(ctx)
        return () => h('div')
      },
    })
    mount(Comp, { global: { plugins: [createPinia()] } })
    expect(entryEditorPanels).toEqual([])
    expect(panelsRef.value).toEqual([])
  })
})
