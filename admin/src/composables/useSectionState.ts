import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  provide,
  ref,
  type ComputedRef,
  type InjectionKey,
  type Ref,
} from 'vue'
import { onBeforeRouteLeave } from 'vue-router'

// Single-page product editor plan, Task C2: the section state machine, cross-section dirty
// registry, and navigation guard that later tasks (C4-C8) build every section panel against
// (see `.superpowers/sdd/editor/task-C2-brief.md`). Global Constraints §10: "SPA section state =
// two axes: `phase: idle|saving|saved|error` + `dirty: boolean`. Navigation blocks while
// `dirty || phase === 'saving'`. No automatic conflict retries — ever." — this file is the ONLY
// place that encodes those two axes and their transition rules; every section composes
// `useSectionState()` rather than re-deriving phase/dirty locally.

/** The section-state machine's two independent axes (Global Constraints §10). */
export type SectionPhase = 'idle' | 'saving' | 'saved' | 'error'

/** How long a `saved` chip lingers before decaying back to `idle` on its own. */
export const SECTION_SAVED_DECAY_MS = 3000

/** One section's registration in the cross-section dirty registry. */
export interface RegisteredSection {
  id: string
  label: string
  /** `dirty || phase === 'saving'` — this section alone blocks navigation while true. */
  blocked: ComputedRef<boolean>
}

/** `{id, label}` for a single blocked section — the shape `useUnsavedGuard` lists in its confirm. */
export interface BlockedSection {
  id: string
  label: string
}

/**
 * Cross-section registry: every `useSectionState()` call registers itself here so the editor page
 * (and `useUnsavedGuard`) can answer "is ANYTHING on this page unsaved?" without each section
 * knowing about its siblings.
 */
export interface DirtyRegistry {
  /** Adds a section; returns the deregister function (call on unmount — see `useSectionState`). */
  register(section: RegisteredSection): () => void
  /** The subset of registered sections currently blocked, in registration order. */
  blockedSections(): BlockedSection[]
  /** True while ANY registered section is dirty or saving. */
  isBlocked: ComputedRef<boolean>
}

export const DirtyRegistryKey: InjectionKey<DirtyRegistry> = Symbol('thallo-dirty-registry')

/**
 * Creates the page-level dirty registry and `provide()`s it under `DirtyRegistryKey` for
 * descendant `useSectionState()` calls to `inject()`. Must be called from a component's `setup()`
 * (the single-page product editor's root) — `provide()` is a no-op outside that context, matching
 * every other Vue composable in this codebase.
 */
export function createDirtyRegistry(): DirtyRegistry {
  // A plain Map, not `reactive()`: entries hold a `ComputedRef` the section itself owns, so
  // reactivity already flows through `.blocked.value` reads inside `isBlocked` below — wrapping
  // the Map in `reactive()` would only add an unused proxy layer. `isBlocked` re-derives on every
  // register/deregister because those calls reassign `version` below, which the computed reads.
  const sections = new Map<string, RegisteredSection>()
  const version = ref(0)

  function register(section: RegisteredSection): () => void {
    sections.set(section.id, section)
    version.value++
    let deregistered = false
    return () => {
      // Idempotent: a spec (or a double-unmount edge case) calling the returned function twice
      // must not decrement/corrupt a DIFFERENT section that reused the same id after re-registering.
      if (deregistered) return
      deregistered = true
      if (sections.get(section.id) === section) sections.delete(section.id)
      version.value++
    }
  }

  function blockedSections(): BlockedSection[] {
    const out: BlockedSection[] = []
    for (const section of sections.values()) {
      if (section.blocked.value) out.push({ id: section.id, label: section.label })
    }
    return out
  }

  const isBlocked = computed(() => {
    void version.value // read only to track add/remove; the loop below reads the CURRENT sections
    for (const section of sections.values()) {
      if (section.blocked.value) return true
    }
    return false
  })

  const registry: DirtyRegistry = { register, blockedSections, isBlocked }
  provide(DirtyRegistryKey, registry)
  return registry
}

/** `useSectionState()`'s return shape (Task C2 brief's interface block, verbatim). */
export interface SectionState {
  phase: Ref<SectionPhase>
  dirty: Ref<boolean>
  markDirty(): void
  beginSave(): void
  saveSucceeded(): void
  saveFailed(): void
  markClean(): void
}

/**
 * One section panel's local state machine, auto-registered in the ancestor `DirtyRegistry`
 * (`createDirtyRegistry()` must run in an ancestor's `setup()` — the editor page).
 *
 * Transition rules (Task C2 brief, test-pinned):
 * - `markDirty()`: sets `dirty = true`. If `phase` was `'error'` or `'saved'`, it returns to
 *   `'idle'` first — re-editing after a failed save clears the error chip, and re-editing during
 *   the `saved` decay window cancels the decay early. From `'idle'`/`'saving'`, `phase` is left
 *   alone (edits mid-save don't fabricate a new phase).
 * - `beginSave()`: `phase = 'saving'`. Cancels any pending `saved`-decay timer.
 * - `saveSucceeded()`: `phase = 'saved'`, `dirty = false`, then decays to `'idle'` after
 *   `SECTION_SAVED_DECAY_MS` — cancelled if any other transition (including a new `beginSave()`)
 *   fires before it does.
 * - `saveFailed()`: `phase = 'error'`. `dirty` is left untouched — an unsaved edit that failed to
 *   save is STILL unsaved (Global Constraints §10's "no automatic conflict retries" pairs with
 *   this: the user must re-attempt the save explicitly, and the registry must keep blocking nav).
 * - `markClean()`: external adoption (conflict "Use latest", coordinator sync) — `dirty = false`,
 *   `phase = 'idle'`, unconditionally.
 */
export function useSectionState(sectionId: string, label: string): SectionState {
  const registry = inject(DirtyRegistryKey, null)
  if (!registry) {
    throw new Error(
      `useSectionState("${sectionId}"): no DirtyRegistry was provided. Call createDirtyRegistry() ` +
        "in an ancestor component (the single-page product editor's root) before mounting sections.",
    )
  }

  const phase = ref<SectionPhase>('idle')
  const dirty = ref(false)
  const blocked = computed(() => dirty.value || phase.value === 'saving')

  let decayTimer: ReturnType<typeof setTimeout> | null = null
  function cancelDecay(): void {
    if (decayTimer === null) return
    clearTimeout(decayTimer)
    decayTimer = null
  }

  function markDirty(): void {
    cancelDecay()
    if (phase.value === 'error' || phase.value === 'saved') phase.value = 'idle'
    dirty.value = true
  }

  function beginSave(): void {
    cancelDecay()
    phase.value = 'saving'
  }

  function saveSucceeded(): void {
    cancelDecay()
    phase.value = 'saved'
    dirty.value = false
    decayTimer = setTimeout(() => {
      decayTimer = null
      // Defensive: every other transition already cancels this timer, so `phase` should still be
      // `'saved'` here — but guard anyway rather than trust that invariant blindly.
      if (phase.value === 'saved') phase.value = 'idle'
    }, SECTION_SAVED_DECAY_MS)
  }

  function saveFailed(): void {
    cancelDecay()
    phase.value = 'error'
    // `dirty` is deliberately left untouched — see the docblock above.
  }

  function markClean(): void {
    cancelDecay()
    dirty.value = false
    phase.value = 'idle'
  }

  const deregister = registry.register({ id: sectionId, label, blocked })
  onUnmounted(() => {
    cancelDecay()
    deregister()
  })

  return { phase, dirty, markDirty, beginSave, saveSucceeded, saveFailed, markClean }
}

/**
 * Wires the page-level navigation guard: blocks in-app route navigation (`onBeforeRouteLeave`)
 * and a hard browser close/refresh (`beforeunload`) while `registry.isBlocked` is true, i.e. while
 * ANY registered section is dirty or mid-save (Global Constraints §10).
 *
 * No modal composable exists in this codebase for confirming destructive/discarding actions (the
 * few existing confirmations — `DropConfirmModal.vue`, `TenantPurgeModal.vue` — are bespoke
 * per-page components, not a reusable programmatic composable), so this uses the platform's own
 * synchronous confirm, the simplest mechanism available and the only one `onBeforeRouteLeave` can
 * consume synchronously without introducing new app-wide modal infrastructure.
 */
export function useUnsavedGuard(registry: DirtyRegistry): void {
  function confirmMessage(): string {
    const labels = registry.blockedSections().map((section) => section.label)
    return `You have unsaved changes in: ${labels.join(', ')}. Leave this page and discard them?`
  }

  onBeforeRouteLeave(() => {
    if (!registry.isBlocked.value) return true
    return window.confirm(confirmMessage())
  })

  function handleBeforeUnload(event: BeforeUnloadEvent): void {
    if (!registry.isBlocked.value) return
    event.preventDefault()
    // Legacy `returnValue` assignment: Chrome still requires it to show its native confirmation,
    // even though the string itself is never displayed by any modern browser.
    event.returnValue = ''
  }

  onMounted(() => {
    window.addEventListener('beforeunload', handleBeforeUnload)
  })
  onUnmounted(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload)
  })
}
