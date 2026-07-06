import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref, toValue } from 'vue'

const enabled = ref(true)
// Capture the `enabled` arg the page passes to useAnalyticsSummary (vi.hoisted — the mock factory
// is hoisted above imports). This proves the page WIRES the capability into the query's gate; the
// gate itself (no fetch when false) is proven by analyticsEnabledGate.spec.
const h = vi.hoisted(() => ({ summaryEnabled: null as unknown }))

vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: (id: string) => (id === 'thallo.analytics' ? enabled.value : true) }),
}))
vi.mock('@/queries/home', () => ({
  useHomeOverview: () => ({ data: ref({ types: [], recent: [], total_entries: 0 }), status: ref('success') }),
}))
vi.mock('@/queries/entries', () => ({ useCreateEntry: () => ({ mutateAsync: vi.fn() }) }))
vi.mock('@/queries/analytics', () => ({
  rangeFor: () => ({ from: '2025-06-01', to: '2025-06-30' }),
  useAnalyticsSummary: (_range: unknown, enabledArg?: unknown) => {
    h.summaryEnabled = enabledArg
    return {
      data: ref({ from: 'a', to: 'b', totals: { 'auth.login': 1200 }, active_users: 128 }),
      status: ref('success'),
    }
  },
}))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => ({ user: { name: 'Test' } }) }))

import HomePage from '@/pages/index.vue'

const stubs = { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }

describe('home analytics KPI strip', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    enabled.value = true
    h.summaryEnabled = null
  })

  it('shows the analytics strip and enables the query when thallo.analytics is enabled', () => {
    const wrapper = mount(HomePage, { global: { stubs } })
    expect(wrapper.find('[data-test="home-analytics-strip"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('128')
    expect(toValue(h.summaryEnabled)).toBe(true)
  })

  it('hides the strip AND disables the analytics query when thallo.analytics is disabled', () => {
    enabled.value = false
    const wrapper = mount(HomePage, { global: { stubs } })
    expect(wrapper.find('[data-test="home-analytics-strip"]').exists()).toBe(false)
    // The page passes the capability flag as the summary's `enabled` gate, so a disabled pack
    // never triggers the analytics fetch (the (404'd) backend route is never called).
    expect(toValue(h.summaryEnabled)).toBe(false)
  })
})
