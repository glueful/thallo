import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { useAnalyticsSummary } from '@/queries/analytics'

function mountWith(enabled: boolean) {
  const Comp = defineComponent({
    setup() {
      useAnalyticsSummary(
        () => ({ from: '2025-06-01', to: '2025-06-30' }),
        () => enabled,
      )
      return () => h('div')
    },
  })
  // Pinia must be installed before PiniaColada.
  return mount(Comp, { global: { plugins: [createPinia(), PiniaColada] } })
}

describe('useAnalyticsSummary enabled gate', () => {
  beforeEach(() => {
    authFetch
      .mockReset()
      .mockResolvedValue({ data: { from: 'a', to: 'b', totals: {}, active_users: 0 } })
  })

  it('does NOT hit the backend when disabled', async () => {
    mountWith(false)
    await flushPromises()
    expect(authFetch).not.toHaveBeenCalled()
  })

  it('hits /analytics/summary when enabled', async () => {
    mountWith(true)
    await flushPromises()
    expect(authFetch).toHaveBeenCalledTimes(1)
    expect(authFetch.mock.calls[0][0]).toContain('/analytics/summary')
  })
})
