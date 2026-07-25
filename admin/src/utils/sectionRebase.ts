// Single-page product editor plan, Task C3: the pure three-way rebase engine that decides,
// whenever a section's remote data is refreshed while a local draft is dirty, whether the remote
// change is unrelated to the local edit (silent — keep the draft as-is) or a genuine conflict that
// needs either an automatic structural merge (order-insensitive uuid sets) or explicit user review
// (everything else). See `.superpowers/sdd/editor/task-C3-brief.md`. Every function here is pure —
// no Vue reactivity, no I/O — so the merge/conflict rules can be reasoned about and tested in
// isolation from `useProductRevisionCoordinator.ts`'s orchestration (which calls these against
// each section's own baseline/draft/remote before deciding `adoptRemote` vs `reconcileRemote`).

/** Result of `rebaseSet()`: either the remote bump was unrelated to the local draft (`'silent'` —
 * the caller keeps its local `L` untouched), or the sets diverged and a deterministic merge is
 * available (`'merged'`, carrying the merged result). */
export type SetRebaseResult = { kind: 'silent' } | { kind: 'merged'; result: string[] }

/** Set-equality over two uuid arrays, order-insensitive. Treated as a genuine mathematical set
 * (duplicates within one side collapse) since every caller here passes deduplicated assignment
 * lists (categories/tags/attribute-value uuids, etc). */
function setEquals(a: readonly string[], b: readonly string[]): boolean {
  const setA = new Set(a)
  const setB = new Set(b)
  if (setA.size !== setB.size) return false
  for (const item of setA) {
    if (!setB.has(item)) return false
  }
  return true
}

/**
 * Three-way rebase for an unordered uuid SET (categories/tags/attribute-value assignments):
 * `B` = the revision the local draft `L` started editing from, `R` = the freshly-refetched remote
 * set (same revision family as `B`, i.e. a later revision of the SAME section).
 *
 * - `setEquals(R, B)`: the remote hasn't actually changed the set since `B` (an unrelated revision
 *   bump elsewhere on the product) — `{kind: 'silent'}`. The caller keeps `L` exactly as-is; this
 *   function never returns the local draft itself.
 * - Otherwise, the remote set genuinely diverged from `B` while `L` was being edited. Both sides'
 *   changes are combined deterministically:
 *   `result = (R ∪ (L∖B)) ∖ (B∖L)` — remote's current membership, plus whatever the local draft
 *   added since `B` (`L∖B`), minus whatever the local draft removed since `B` (`B∖L`) — a local
 *   removal always wins over a remote change to that same item (see the "changed position" case
 *   below), and a local addition always survives even if the remote snapshot doesn't have it yet
 *   (an item can't be "in `B∖L`" if it was never in `B` to begin with).
 *   Order is deterministic: `R`'s own order first (deduplicated), then local additions (`L∖B`) in
 *   `L`'s order, skipping any addition already present from `R` (duplicate-add idempotence).
 */
export function rebaseSet(
  B: readonly string[],
  L: readonly string[],
  R: readonly string[],
): SetRebaseResult {
  if (setEquals(R, B)) return { kind: 'silent' }

  const baselineSet = new Set(B)
  const localSet = new Set(L)
  // B∖L: items the local draft removed since the baseline. These stay excluded from the merged
  // result even if the remote snapshot still carries them (a local removal always wins).
  const removedLocally = new Set([...baselineSet].filter((item) => !localSet.has(item)))

  const seen = new Set<string>()
  const result: string[] = []

  for (const item of R) {
    if (seen.has(item)) continue // R itself may carry duplicates; dedupe defensively.
    seen.add(item)
    if (removedLocally.has(item)) continue
    result.push(item)
  }

  // L∖B, in L's order: items the local draft added since the baseline that the remote snapshot
  // doesn't already carry (an item added locally can never be in `removedLocally` — that set is a
  // subset of B, and a locally-added item is by definition NOT in B).
  for (const item of L) {
    if (baselineSet.has(item)) continue
    if (seen.has(item)) continue
    seen.add(item)
    result.push(item)
  }

  return { kind: 'merged', result }
}

/** Recursively sorts object keys (arrays keep their order) so two structurally-equal values with
 * differently-ordered keys stringify identically — the "JSON-stable comparison" `rebaseStructured`
 * relies on. */
function canonicalize(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(canonicalize)
  if (value !== null && typeof value === 'object') {
    const sortedEntries = Object.entries(value as Record<string, unknown>)
      .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
      .map(([key, entryValue]) => [key, canonicalize(entryValue)] as const)
    return Object.fromEntries(sortedEntries)
  }
  return value
}

/**
 * Three-way rebase for a STRUCTURED (non-set) section value — attribute assignment rows, media
 * ordering, child positions: anything where item identity plus its own fields plus overall order
 * all matter, not just set membership. Unlike `rebaseSet`, there is no deterministic auto-merge for
 * these shapes (Task C3 brief: "Never auto-merges") — the coordinator always surfaces a genuine
 * divergence for explicit user review (the C5-C8 "Use latest" / "Replace with mine" conflict UI).
 *
 * Deep-equality is JSON-stable (key order doesn't matter) and compares `R` against `B` ONLY — `L`
 * is accepted for interface symmetry with `rebaseSet()` and to make call sites uniform, but plays
 * no role in the verdict: if the remote genuinely changed since the baseline the draft started
 * from, that's always a conflict regardless of what the local draft happens to contain, INCLUDING
 * the case where `L` already deep-equals `R` — this function does not special-case that away.
 * `revision` fields are never part of `B`/`L`/`R` here by construction: the parameters are typed
 * as ITEM ARRAYS (`SectionEnvelope.items`), never the envelope itself — passing whole envelopes
 * would make an unrelated revision bump with identical items read as a false conflict, so the
 * signature refuses them.
 */
export function rebaseStructured<T>(
  B: readonly T[],
  L: readonly T[],
  R: readonly T[],
): 'silent' | 'conflict' {
  void L
  return JSON.stringify(canonicalize(R)) === JSON.stringify(canonicalize(B)) ? 'silent' : 'conflict'
}
