import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref, toValue } from 'vue'

// vi.mock factories are hoisted above imports, so a captured handle must come through vi.hoisted.
// We stash the event ref the page passes to useAnalyticsBreakdown, then read it AFTER the click
// (the page passes a computed<string>; toValue reads its current value post-reactivity-flush).
const h = vi.hoisted(() => ({ breakdownEventRef: null as unknown, scoped: false as boolean }))

vi.mock('@/queries/analytics', () => ({
  rangeFor: () => ({ from: '2025-06-01', to: '2025-06-30' }),
  useAnalyticsSummary: () => ({
    data: ref({
      from: '2025-06-01',
      to: '2025-06-30',
      totals: { 'auth.login': 1200, 'content.entry.created': 340, 'collections.row.created': 890 },
      active_users: 128,
      scoped: h.scoped,
    }),
    status: ref('success'),
    error: ref(null),
  }),
  useAnalyticsSeries: () => ({
    data: ref([{ day: '2025-06-01', count: 5 }]),
    status: ref('success'),
    error: ref(null),
  }),
  useAnalyticsBreakdown: (event: unknown) => {
    h.breakdownEventRef = event
    return { data: ref([{ subject: 'posts', count: 12 }]), status: ref('success'), error: ref(null) }
  },
}))

import AnalyticsPage from '@/pages/analytics/index.vue'

const stubs = {
  AnalyticsLineChart: { props: ['series'], template: '<div data-test="line-chart" />' },
  AnalyticsBarChart: { props: ['items'], template: '<div data-test="bar-chart" />' },
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}

describe('analytics page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    h.scoped = false
  })

  it('renders the four KPI values from the summary', () => {
    const wrapper = mount(AnalyticsPage, { global: { stubs } })
    const text = wrapper.text()
    expect(text).toContain('128') // active users
    expect(text).toContain('1,200') // logins — Intl.NumberFormat renders 1200 as "1,200"
    expect(text).toContain('340') // entries created
    expect(text).toContain('890') // rows created
  })

  it('renders a line chart per trend block and a breakdown bar chart', () => {
    const wrapper = mount(AnalyticsPage, { global: { stubs } })
    // Activity trend + active users/day + auth health = 3 line charts.
    expect(wrapper.findAll('[data-test="line-chart"]').length).toBe(3)
    expect(wrapper.find('[data-test="bar-chart"]').exists()).toBe(true)
  })

  it('drops platform auth panels in a workspace-scoped view', () => {
    h.scoped = true
    const wrapper = mount(AnalyticsPage, { global: { stubs } })

    // Auth KPIs (active users, logins) are hidden; content KPIs remain.
    expect(wrapper.find('[data-test="kpi-active"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="kpi-logins"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="kpi-entries"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="kpi-rows"]').exists()).toBe(true)

    // Only the Activity trend chart remains; the active-users/day + auth-health row is gone.
    expect(wrapper.findAll('[data-test="line-chart"]').length).toBe(1)
    expect(wrapper.find('[data-test="bar-chart"]').exists()).toBe(true)
  })

  it('defaults the breakdown to Content types and switches to Collections', async () => {
    const wrapper = mount(AnalyticsPage, { global: { stubs } })
    expect(toValue(h.breakdownEventRef)).toBe('content.entry.created')
    await wrapper.find('[data-test="seg-collections"]').trigger('click')
    expect(toValue(h.breakdownEventRef)).toBe('collections.row.created')
  })
})
