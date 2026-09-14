import { describe, it, expect } from 'vitest'
import { createApplyMetrics, quantile } from '@/editor/applyMetrics'

describe('apply metrics', () => {
  it('quantile is nearest-rank over the sorted sample', () => {
    expect(quantile([], 0.5)).toBe(0)
    expect(quantile([30, 10, 20], 0.5)).toBe(20)
    expect(quantile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 0.95)).toBe(10)
    expect(quantile([1, 2, 3, 4], 0.95)).toBe(4)
    expect(quantile([7], 0.95)).toBe(7)
  })

  it('records input-to-paint from the first input of a burst and request-to-paint per path', () => {
    let t = 0
    const metrics = createApplyMetrics(() => t)
    t = 100
    metrics.input()
    t = 150
    metrics.input() // a later keystroke joins the burst
    t = 400
    metrics.request()
    t = 500
    metrics.response()
    t = 600
    metrics.paint('fragments')
    t = 1000
    metrics.request() // an apply without a preceding input (the manual button)
    t = 1300
    metrics.paint('page')
    metrics.fallback('fragments')
    const [fragments, page] = metrics.summary()
    expect(fragments).toEqual({
      path: 'fragments',
      count: 1,
      fallbacks: 1,
      inputToPaint: { median: 500, p95: 500 },
      requestToPaint: { median: 200, p95: 200 },
    })
    expect(page).toEqual({
      path: 'page',
      count: 1,
      fallbacks: 0,
      inputToPaint: { median: 0, p95: 0 },
      requestToPaint: { median: 300, p95: 300 },
    })
  })
})
