import { computed, provide, ref, type InjectionKey, type Ref } from 'vue'
import type { SectionEnvelope } from '@/queries/commerceProductSections'

// Single-page product editor plan, Task C3: the per-product revision coordinator that orchestrates
// refreshing every registered section card against the server's `{revision, items}` envelopes (see
// `commerceProductSections.ts`, Task C1) after a mutation or an explicit 409 recovery — see
// `.superpowers/sdd/editor/task-C3-brief.md`.
//
// The coordinator ORCHESTRATES ONLY: it decides WHICH callback a section gets — `adoptRemote()` if
// the section is currently clean, `reconcileRemote()` if it's dirty — but never decides WHAT the
// section's new content should be, and never writes a section's `baseRevision` itself. Each
// section owns its own typed baseline/draft (via `useSectionState()`, Task C2) and therefore owns
// adoption/reconciliation (typically built on `rebaseSet()`/`rebaseStructured()` from
// `utils/sectionRebase.ts`, also Task C3) — that division of responsibility is why `register()` is
// generic per section (`SectionRegistration<T>`) while the coordinator itself never touches `T`.

/** One section's registration with the coordinator (Task C3 brief's interface block, verbatim
 * field set). `baseRevision`/`dirty` are the SAME refs the section's own state owns — the
 * coordinator only ever READS `dirty` (to route `adoptRemote` vs `reconcileRemote`) and never
 * writes `baseRevision`; the section's own `adoptRemote`/`reconcileRemote` implementations are the
 * only code that advances it. */
export interface SectionRegistration<T> {
  baseRevision: Ref<number | null>
  dirty: Ref<boolean>
  /** Refetches this section's envelope from the server (typically the section's Colada query's
   * own `refetch()`, Task C1). */
  refetch: () => Promise<SectionEnvelope<T>>
  /** Called when the section is CLEAN at the moment its refetch resolves: adopt the fresh remote
   * state outright (there is no local draft to preserve). */
  adoptRemote: (remote: SectionEnvelope<T>) => void
  /** Called when the section is DIRTY at the moment its refetch resolves: the section decides how
   * to reconcile its local draft against the fresh remote snapshot (silent/merged/conflict — via
   * `rebaseSet()`/`rebaseStructured()`), never the coordinator. */
  reconcileRemote: (remote: SectionEnvelope<T>) => void
}

/** `useProductRevisionCoordinator()`'s return shape (Task C3 brief's interface block, verbatim). */
export interface ProductRevisionCoordinator {
  register<T>(sectionId: string, registration: SectionRegistration<T>): void
  /** Refreshes one section (`sectionId`) or, if omitted, every registered section — always
   * AWAITED, always resolves (a single section's `refetch()` rejecting never rejects this promise;
   * see `performRefresh()` below). Used directly for 409 recovery: `await refresh(sectionId)`
   * before presenting the conflict review UI, so the review is built from a fresh envelope. */
  refresh(sectionId?: string): Promise<void>
  /** Refreshes every registered section. C1's mutations already invalidate their own Colada
   * caches, so this does not "run invalidation first" — it simply drives every registration's
   * `refetch()` (which will naturally return fresh data because of that prior invalidation) and
   * routes each through `adoptRemote`/`reconcileRemote`. Every successful mutation covered by C1's
   * product-section invalidation matrix must `await afterMutation()` exactly once; pack-owned
   * linked-content mutations that don't advance `catalog_revision` must NOT call it. */
  afterMutation(): Promise<void>
  /** True while any requested `refresh()`/`afterMutation()` work — including anything still queued
   * behind an earlier call, see the reentrancy note on `runSerially` below — is in flight. This
   * flag IS the mechanism consumers use to disable replacement saves during a refresh; it is not
   * conveyed via promise rejection. */
  refreshing: Readonly<Ref<boolean>>
  /** Display-only: the most recent `revision` seen across EVERY envelope the coordinator has
   * observed, clean or dirty. Never a substitute for a section's own `baseRevision` — a dirty
   * section's baseline is only ever advanced by that section's own `reconcileRemote()`. */
  observedRevision: Ref<number | null>
}

export const ProductRevisionCoordinatorKey: InjectionKey<ProductRevisionCoordinator> = Symbol(
  'thallo-product-revision-coordinator',
)

/**
 * Creates one product editor's revision coordinator and `provide()`s it under
 * `ProductRevisionCoordinatorKey` (same pattern as Task C2's `createDirtyRegistry()` /
 * `DirtyRegistryKey`) so every section card mounted underneath can `inject()` the SAME instance.
 * Must be called once from the single-page product editor shell's `setup()` (Task C4) —
 * `provide()` is a no-op outside that context, matching `createDirtyRegistry()`.
 *
 * Reentrancy: `refresh()` and `afterMutation()` share one internal serial queue
 * (`runSerially()`). A second call arriving while one is already in flight does NOT run its
 * refetches in parallel with the first (a "parallel storm of refetches" against the same sections
 * could interleave two `adoptRemote`/`reconcileRemote` calls for the same section in an
 * unpredictable order) — it is queued and only starts once every call ahead of it has settled, at
 * which point it computes its own fresh section list/dirty snapshot. `refreshing` stays true for
 * as long as ANY call — running or still queued — hasn't settled yet.
 */
export function useProductRevisionCoordinator(): ProductRevisionCoordinator {
  const sections = new Map<string, SectionRegistration<unknown>>()
  const refreshingInternal = ref(false)
  const observedRevision = ref<number | null>(null)

  // Outstanding-call counter backing `refreshingInternal`: incremented when a `refresh()`/
  // `afterMutation()` call is accepted (whether it starts running immediately or is queued behind
  // an earlier one), decremented once THAT call's own work has settled.
  let outstandingCalls = 0
  function adjustOutstanding(delta: number): void {
    outstandingCalls += delta
    refreshingInternal.value = outstandingCalls > 0
  }

  // The serial queue: `queueTail` always resolves once every call enqueued so far has settled
  // (success or failure), so chaining a new call's `work` off it guarantees it never runs
  // concurrently with an earlier one. `performRefresh()` below never itself rejects (it uses
  // `Promise.allSettled` internally), so in practice `work` never throws — the `.then(x, x)` guard
  // is defensive: even if a caller's `adoptRemote`/`reconcileRemote` threw synchronously, the queue
  // must keep moving rather than wedge every future call behind a rejected `queueTail`.
  let queueTail: Promise<void> = Promise.resolve()

  function runSerially(work: () => Promise<void>): Promise<void> {
    adjustOutstanding(1)
    const run = queueTail.then(work, work)
    queueTail = run.then(
      () => undefined,
      () => undefined,
    )
    return run.finally(() => adjustOutstanding(-1))
  }

  /** Refreshes ONE section: awaits its own `refetch()`, then reads `dirty.value` — AT THIS POINT,
   * after the refetch resolved, not from a snapshot taken before the refetch started — so a
   * section that flips dirty WHILE its own refetch was in flight is correctly routed to
   * `reconcileRemote` rather than `adoptRemote`. If `refetch()` rejects, this function's rejection
   * propagates to the caller's `Promise.allSettled` (see `performRefresh`), which is exactly what
   * keeps that section's `adoptRemote`/`reconcileRemote` — and therefore its `baseRevision` —
   * untouched: neither callback runs when `refetch()` itself failed. */
  async function refreshSection(entry: SectionRegistration<unknown>): Promise<void> {
    const remote = await entry.refetch()
    observedRevision.value = remote.revision
    if (entry.dirty.value) {
      entry.reconcileRemote(remote)
    } else {
      entry.adoptRemote(remote)
    }
  }

  /** Refreshes every section named in `ids` (silently skipping any id with no live registration)
   * via `Promise.allSettled` — one section's `refetch()` rejecting must never abort, delay, or
   * otherwise affect any other section's refresh; a rejected section's baseline is simply left
   * exactly as it was. This function itself never rejects. */
  async function performRefresh(ids: readonly string[]): Promise<void> {
    const targets = ids
      .map((id) => sections.get(id))
      .filter((entry): entry is SectionRegistration<unknown> => entry !== undefined)
    await Promise.allSettled(targets.map((entry) => refreshSection(entry)))
  }

  function register<T>(sectionId: string, registration: SectionRegistration<T>): void {
    // Type-erased in storage: each entry's `T` is only ever used internally, consistently, between
    // that SAME entry's own `refetch`/`adoptRemote`/`reconcileRemote` — the cast is sound because
    // nothing here ever mixes envelopes across two different registrations.
    sections.set(sectionId, registration as unknown as SectionRegistration<unknown>)
  }

  function refresh(sectionId?: string): Promise<void> {
    return runSerially(() => {
      const ids = sectionId !== undefined ? [sectionId] : [...sections.keys()]
      return performRefresh(ids)
    })
  }

  function afterMutation(): Promise<void> {
    return runSerially(() => performRefresh([...sections.keys()]))
  }

  const coordinator: ProductRevisionCoordinator = {
    register,
    refresh,
    afterMutation,
    refreshing: computed(() => refreshingInternal.value),
    observedRevision,
  }

  provide(ProductRevisionCoordinatorKey, coordinator)
  return coordinator
}
