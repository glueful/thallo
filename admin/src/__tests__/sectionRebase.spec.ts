import { describe, expect, it } from 'vitest'
import { rebaseSet, rebaseStructured } from '@/utils/sectionRebase'

// Single-page product editor plan, Task C3: the pure three-way rebase engine. `rebaseSet()` covers
// unordered uuid-set sections (categories/tags/attribute-value assignments); `rebaseStructured()`
// covers everything else (attribute rows, media ordering, child positions) where no deterministic
// auto-merge exists and every genuine divergence surfaces as a conflict for explicit user review.
// See `.superpowers/sdd/editor/task-C3-brief.md`.

describe('rebaseSet', () => {
  describe('silent — remote set unchanged from baseline (order-insensitive)', () => {
    it('same elements, same order', () => {
      expect(rebaseSet(['a', 'b'], ['a'], ['a', 'b'])).toEqual({ kind: 'silent' })
    })

    it('same elements, different order', () => {
      expect(rebaseSet(['a', 'b'], ['a'], ['b', 'a'])).toEqual({ kind: 'silent' })
    })

    it('all three empty', () => {
      expect(rebaseSet([], [], [])).toEqual({ kind: 'silent' })
    })

    it('B and R both empty even though L has local additions — caller keeps L untouched', () => {
      // The remote genuinely never had anything (still matches the empty baseline); whatever L
      // added is the caller's business, not this function's — it must not silently report a
      // merge just because L differs from B.
      expect(rebaseSet([], ['a'], [])).toEqual({ kind: 'silent' })
    })

    it('B and R both equal a non-empty set even though L is empty (local removed everything, remote unrelated bump)', () => {
      expect(rebaseSet(['a'], [], ['a'])).toEqual({ kind: 'silent' })
    })

    it('B, L, and R all equal the same non-empty set', () => {
      expect(rebaseSet(['a'], ['a'], ['a'])).toEqual({ kind: 'silent' })
    })
  })

  describe('merged — three-way combination, deterministic order', () => {
    it('remote-only addition, local unchanged: B and L empty, R has an item', () => {
      expect(rebaseSet([], [], ['x'])).toEqual({ kind: 'merged', result: ['x'] })
    })

    it('remote-only removal, local unchanged: B has an item, L and R do not', () => {
      // R != B (R is empty, B is not), so this is NOT the "silent" case above (`rebaseSet(['a'],
      // [], ['a'])`) — here R actually dropped the item, and L never touched it, so the remote
      // removal simply carries through.
      expect(rebaseSet(['a'], ['a'], [])).toEqual({ kind: 'merged', result: [] })
    })

    it('local addition not yet present on the remote: R order first, then L-order additions', () => {
      expect(rebaseSet([], ['a'], ['x'])).toEqual({ kind: 'merged', result: ['x', 'a'] })
    })

    it('local removal with nothing else changing on B (B non-empty, L and R empty)', () => {
      expect(rebaseSet(['a'], [], [])).toEqual({ kind: 'merged', result: [] })
    })

    it('local addition survives even though remote lacks it (item both added-in-L and absent-from-R — local addition wins)', () => {
      // 'b' was never in B, so it can never be in B∖L (the excluded set) regardless of R. R also
      // gains an unrelated 'c' so setEquals(R, B) is false and the merge path actually runs —
      // rebaseSet(['a'], ['a', 'b'], ['a']) would be R==B (silent), which trivially "keeps L"
      // rather than exercising the merge formula this case is meant to pin.
      expect(rebaseSet(['a'], ['a', 'b'], ['a', 'c'])).toEqual({
        kind: 'merged',
        result: ['a', 'c', 'b'],
      })
    })

    it('an item removed-in-L stays removed even though R still carries it at a different position (B∖L always wins over R)', () => {
      // B=[a,b,c], L=[b,c] (a removed locally). R=[c,b,a,d]: same a/b/c membership as B (just
      // reordered) PLUS a genuine remote addition 'd', so setEquals(R, B) is false and the merge
      // path runs. A pure reorder of R with NO membership change would instead be 'silent' (R
      // equals B as a set) — see the "same elements, different order" case above — so this test
      // pairs a reorder with an unrelated remote addition specifically to exercise the merge
      // formula's B∖L exclusion rather than the trivial silent case.
      expect(rebaseSet(['a', 'b', 'c'], ['b', 'c'], ['c', 'b', 'a', 'd'])).toEqual({
        kind: 'merged',
        result: ['c', 'b', 'd'],
      })
    })

    it('duplicate-add idempotence: local re-adds an item the remote snapshot already carries — appears once', () => {
      expect(rebaseSet([], ['a'], ['a'])).toEqual({ kind: 'merged', result: ['a'] })
    })

    it('duplicate-add idempotence holds alongside an unrelated remote addition', () => {
      // L added 'a' (already present via R) AND 'c' (genuinely new); result must not duplicate 'a'.
      expect(rebaseSet([], ['a', 'c'], ['a', 'x'])).toEqual({
        kind: 'merged',
        result: ['a', 'x', 'c'],
      })
    })

    it('disjoint R: remote replaced the set with items sharing nothing with B or L', () => {
      expect(rebaseSet(['a'], ['a', 'b'], ['x', 'y'])).toEqual({
        kind: 'merged',
        result: ['x', 'y', 'b'],
      })
    })

    it('order is deterministic: R order preserved first, additions strictly in L order after', () => {
      expect(rebaseSet(['a'], ['a', 'c', 'b'], ['q', 'p'])).toEqual({
        kind: 'merged',
        result: ['q', 'p', 'c', 'b'],
      })
    })

    it('combined add-and-remove on both sides simultaneously', () => {
      // B=[a,b,c]. Local: removed b, added d -> L=[a,c,d]. Remote: removed c, added e -> R=[a,b,e].
      // Expect: R order [a,b,e] with b dropped (b in B∖L) -> [a,e]; then local addition d -> [a,e,d].
      expect(rebaseSet(['a', 'b', 'c'], ['a', 'c', 'd'], ['a', 'b', 'e'])).toEqual({
        kind: 'merged',
        result: ['a', 'e', 'd'],
      })
    })
  })

  describe('exhaustive empty B/L/R combinations', () => {
    it.each<[string[], string[], string[], ReturnType<typeof rebaseSet>]>([
      [[], [], [], { kind: 'silent' }],
      [[], ['a'], [], { kind: 'silent' }],
      [[], [], ['x'], { kind: 'merged', result: ['x'] }],
      [[], ['a'], ['x'], { kind: 'merged', result: ['x', 'a'] }],
      [['a'], [], [], { kind: 'merged', result: [] }],
      [['a'], [], ['a'], { kind: 'silent' }],
      [['a'], ['a'], [], { kind: 'merged', result: [] }],
      [['a'], ['a'], ['a'], { kind: 'silent' }],
    ])('rebaseSet(%j, %j, %j) -> %j', (B, L, R, expected) => {
      expect(rebaseSet(B, L, R)).toEqual(expected)
    })
  })
})

describe('rebaseStructured', () => {
  // The signature takes ITEM ARRAYS (`SectionEnvelope.items`), never whole envelopes — passing
  // envelopes would turn a bare revision bump into a false conflict, so the type refuses them.

  it('returns silent when R deep-equals B item-wise, regardless of key order', () => {
    const B = [{ name: 'Widget', values: ['a', 'b'], visible: true }]
    const R = [{ visible: true, values: ['a', 'b'], name: 'Widget' }]
    expect(rebaseStructured(B, [{ name: 'Widget (local edit)', values: [], visible: true }], R)).toBe(
      'silent',
    )
  })

  it('returns silent for nested item structures with reordered keys at every level', () => {
    const B = [{ position: 1, meta: { alt: 'x', variant_uuid: null } }]
    const R = [{ meta: { variant_uuid: null, alt: 'x' }, position: 1 }]
    expect(rebaseStructured(B, [], R)).toBe('silent')
  })

  it('returns silent for equal arrays of objects', () => {
    const B = [
      { uuid: 'a', position: 0 },
      { uuid: 'b', position: 1 },
    ]
    const R = [
      { position: 0, uuid: 'a' },
      { position: 1, uuid: 'b' },
    ]
    expect(rebaseStructured(B, [], R)).toBe('silent')
  })

  it('returns conflict when a field value differs between B and R', () => {
    const B = [{ name: 'Widget', position: 0 }]
    const R = [{ name: 'Widget', position: 1 }]
    expect(rebaseStructured(B, B, R)).toBe('conflict')
  })

  it('returns conflict when R has a different array order than B (structured order matters)', () => {
    const B = [{ uuid: 'a' }, { uuid: 'b' }]
    const R = [{ uuid: 'b' }, { uuid: 'a' }]
    expect(rebaseStructured(B, B, R)).toBe('conflict')
  })

  it('returns conflict when R adds or drops a field on an item', () => {
    expect(rebaseStructured([{ a: 1 }], [{ a: 1 }], [{ a: 1, b: 2 }])).toBe('conflict')
  })

  it('returns conflict when R adds or removes an item', () => {
    expect(rebaseStructured([{ a: 1 }], [{ a: 1 }], [{ a: 1 }, { a: 2 }])).toBe('conflict')
    expect(rebaseStructured([{ a: 1 }], [{ a: 1 }], [])).toBe('conflict')
  })

  it('never auto-merges: returns conflict even when L already equals R exactly', () => {
    const B = [{ name: 'Widget' }]
    const L = [{ name: 'Widget (edited)' }]
    const R = [{ name: 'Widget (edited)' }] // R happens to already match L — still not silent.
    expect(rebaseStructured(B, L, R)).toBe('conflict')
  })
})

describe('rebaseSet — within-side duplicates (defensive dedup)', () => {
  it('dedupes a literal repeated uuid inside R without corrupting the merge', () => {
    const result = rebaseSet(['a'], ['a', 'x'], ['a', 'b', 'b'])
    expect(result).toEqual({ kind: 'merged', result: ['a', 'b', 'x'] })
  })

  it('dedupes a literal repeated uuid inside L without corrupting the merge', () => {
    const result = rebaseSet(['a'], ['a', 'x', 'x'], ['a', 'b'])
    expect(result).toEqual({ kind: 'merged', result: ['a', 'b', 'x'] })
  })
})
