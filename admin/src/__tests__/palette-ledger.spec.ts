import { describe, expect, it } from 'vitest'
import { createReplacementLedger, type ReplacementRecord } from '@/editor/paletteReplacements'

const A: ReplacementRecord = {
  id: 'jobA',
  slot: 1,
  map: { 'color.brand-1': 'color.brand-2' },
  completed_generation: 5,
}
const B: ReplacementRecord = {
  id: 'jobB',
  slot: 2,
  map: { 'color.brand-2': 'color.accent' },
  completed_generation: 7,
}
const C: ReplacementRecord = {
  id: 'jobC',
  slot: 1,
  map: { 'color.brand-1': 'color.surface' },
  completed_generation: 9,
}
const batch = (after: number, through: number, records: ReplacementRecord[]) => ({
  after,
  through,
  records,
})

describe('replacement ledger', () => {
  it('applies a covering batch in order and advances its boundary', () => {
    const l = createReplacementLedger(4)
    expect(l.accept(batch(4, 8, [B, A]))).toBe('apply')
    expect(l.take(batch(4, 8, [B, A])).map((r) => r.id)).toEqual(['jobA', 'jobB'])
    l.advance(8)
    expect(l.through).toBe(8)
  })
  it('B in one response, A later: a batch that starts beyond the boundary is a gap and applies nothing', () => {
    const l = createReplacementLedger(4)
    expect(l.accept(batch(6, 8, [B]))).toBe('gap') // A (5) may be missing in (4, 6]
    expect(l.through).toBe(4)
    expect(l.accept(batch(4, 6, [A]))).toBe('apply') // the fetched range
    l.advance(6)
    expect(l.accept(batch(6, 8, [B]))).toBe('apply')
    l.advance(8)
    expect(l.through).toBe(8)
  })
  it('ignores a stale or duplicate batch', () => {
    const l = createReplacementLedger(8)
    expect(l.accept(batch(4, 8, [A, B]))).toBe('stale')
    expect(l.accept(batch(0, 7, [A, B]))).toBe('stale')
  })
  it('takes only records above the boundary from an overlapping batch', () => {
    const l = createReplacementLedger(6) // A already reflected
    expect(l.take(batch(4, 10, [A, B, C])).map((r) => r.id)).toEqual(['jobB', 'jobC'])
  })
  it('expire locks it until reset', () => {
    const l = createReplacementLedger(4)
    l.expire()
    expect(l.expired).toBe(true)
    expect(l.accept(batch(4, 8, [A, B]))).toBe('stale') // refuses everything while expired
    l.reset(9)
    expect(l.expired).toBe(false)
    expect(l.through).toBe(9)
  })
})
