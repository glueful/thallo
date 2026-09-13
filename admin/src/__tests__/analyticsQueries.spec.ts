import { describe, it, expect, vi, beforeEach } from 'vitest'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { rangeFor, fetchSeries, fetchSummary, fetchBreakdown } from '@/queries/analytics'

describe('analytics query layer', () => {
  beforeEach(() => authFetch.mockReset())

  it('rangeFor computes an inclusive N-day window ending today', () => {
    const r = rangeFor(7, new Date('2025-06-30T12:00:00Z'))
    expect(r).toEqual({ from: '2025-06-24', to: '2025-06-30' }) // 7 days inclusive
  })

  it('fetchSeries hits /analytics/series with metric+from+to and unwraps data.series', async () => {
    authFetch.mockResolvedValue({ data: { series: [{ day: '2025-06-30', count: 4 }] } })
    const series = await fetchSeries('auth.login', '2025-06-24', '2025-06-30')
    expect(series).toEqual([{ day: '2025-06-30', count: 4 }])
    const url = authFetch.mock.calls[0][0] as string
    expect(url).toContain('/v1/admin/analytics/series?')
    expect(url).toContain('metric=auth.login')
    expect(url).toContain('from=2025-06-24')
    expect(url).toContain('to=2025-06-30')
  })

  it('fetchSummary hits /analytics/summary and returns the summary payload', async () => {
    authFetch.mockResolvedValue({
      data: { from: 'a', to: 'b', totals: { 'auth.login': 9 }, active_users: 3 },
    })
    const s = await fetchSummary('a', 'b')
    expect(s.active_users).toBe(3)
    expect(s.totals['auth.login']).toBe(9)
    expect(authFetch.mock.calls[0][0]).toContain('/v1/admin/analytics/summary?')
  })

  it('fetchBreakdown hits /analytics/breakdown with event+limit and unwraps data.breakdown', async () => {
    authFetch.mockResolvedValue({ data: { breakdown: [{ subject: 'posts', count: 5 }] } })
    const b = await fetchBreakdown('collections.row.created', 'a', 'b', 10)
    expect(b).toEqual([{ subject: 'posts', count: 5 }])
    const url = authFetch.mock.calls[0][0] as string
    expect(url).toContain('/v1/admin/analytics/breakdown?')
    expect(url).toContain('event=collections.row.created')
    expect(url).toContain('limit=10')
  })
})
