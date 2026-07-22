import { computed, defineAsyncComponent, ref, type Component, type ComputedRef, type Ref } from 'vue'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { useCommerceLinkGate } from '@/queries/commerceLinking'

/**
 * Plain, reactive context handed to a panel's `useGate`/`props`. The entry editor builds
 * ONE of these per instance (via `reactive({ uuid, locale, type })` over its own refs) and
 * reuses it for every manifest entry — reading `ctx.uuid`/`ctx.locale`/`ctx.type` inside a
 * gate's own `computed`/`watchEffect` tracks the editor's live values (e.g. a locale
 * switch) without the editor ever calling `useGate` again.
 */
export interface EntryEditorPanelContext {
  uuid: string
  locale: string
  type: string
}

export interface EntryEditorPanel {
  id: string
  label: string
  /** Render order among manifest-contributed tabs (built-in tabs are never reordered). */
  order: number
  /** Capability id that must be ENABLED (verified `isEnabled`, never the presentation-hint `isVisible`) for this panel to be admitted. Absent = no capability requirement. */
  requiresCapability?: string
  /**
   * Composable gate, invoked ONCE per panel by `useVisibleEditorPanels` during the
   * editor's setup (Vue's rule-of-hooks: the manifest is a fixed, module-level array, so
   * every panel's hook runs, unconditionally, in the same order, exactly once per editor
   * instance — the editor never re-invokes it, including across locale/uuid changes).
   * Keep async/composable state (refs, watchers, queries) inside the hook itself; read
   * `ctx`'s properties reactively from there to react to the editor's live values instead
   * of expecting a fresh call.
   *
   * Settle-before-admit: `'loading'` is NEVER admitted — only `'ready'` is. A gate that
   * starts at `'loading'` and later resolves to `'hidden'` never flickers its tab in and
   * back out; a gate that resolves to `'ready'` admits it at that point. Panels without a
   * `useGate` default to an always-`'ready'` gate.
   */
  useGate?: (ctx: EntryEditorPanelContext) => Readonly<Ref<'ready' | 'hidden' | 'loading'>>
  /** The panel's root component. Async components (`defineAsyncComponent`) are fine. */
  component: Component
  /** Extra props to bind onto `component`, recomputed on every render from the live context. */
  props?: (ctx: EntryEditorPanelContext) => Record<string, unknown>
}

/**
 * Task 12 (admin-commerce-area plan, slice 3): the Commerce entry-editor panel — the bidirectional
 * product<->entry linkage panel (ProductEntryLinkPanel.vue, entry-side mode), wired through the
 * thin CommerceLinkPanel.vue wrapper. `requiresCapability` gates on the extension being enabled
 * at all; `useGate` (useCommerceLinkGate, queries/commerceLinking.ts) separately gates on the
 * CALLER's own `commerce.view` permission via the shared meta query — settle-before-admit, so a
 * still-loading or denied gate never flickers the tab in and back out.
 */
const commerceEntryPanel: EntryEditorPanel = {
  id: 'commerce-link',
  label: 'Commerce',
  order: 10,
  requiresCapability: 'thallo.commerce',
  useGate: () => useCommerceLinkGate(),
  component: defineAsyncComponent(
    () => import('@/pages/content/[type]/[uuid]/components/CommerceLinkPanel.vue'),
  ),
  props: (ctx) => ({ entryUuid: ctx.uuid }),
}

/**
 * The STATIC entry-editor side-tab manifest: every panel a content type's editor can ever
 * show beyond the built-ins (Publishing, SEO, Versions), declared in render order.
 */
export const entryEditorPanels: readonly EntryEditorPanel[] = [commerceEntryPanel]

/** Shared default gate for panels without a `useGate` — always `'ready'`, never mutated. */
const alwaysReady: Readonly<Ref<'ready' | 'hidden' | 'loading'>> = ref('ready')

/**
 * Capability- and gate-filtered, order-sorted view of `entryEditorPanels` for one entry
 * editor instance. Call this ONCE during the editor's setup (it is itself a composable —
 * it reads the capabilities store and invokes hooks).
 *
 * For every manifest entry, its `useGate` (or the shared always-ready default) is invoked
 * exactly once, right here, unconditionally — never behind an `if (capability enabled)`
 * check — so the same hooks run in the same order every time this composable's owner is
 * set up, satisfying Vue's rule-of-hooks even though the manifest itself is a plain array.
 * The returned `ComputedRef` re-filters reactively whenever a gate ref settles or the
 * capability store's verified state changes; it never re-invokes `useGate`.
 *
 * A panel is admitted when BOTH hold: no `requiresCapability`, or it is verified enabled
 * (`caps.isEnabled`, never the presentation-hint `isVisible`); AND its gate reads `'ready'`
 * — `'loading'` and `'hidden'` are both omitted (loading is never treated as enabled, so a
 * still-settling gate cannot flicker a tab in and back out).
 *
 * @param panels test seam — defaults to the static manifest
 */
export function useVisibleEditorPanels(
  ctx: EntryEditorPanelContext,
  panels: readonly EntryEditorPanel[] = entryEditorPanels,
): ComputedRef<EntryEditorPanel[]> {
  const caps = useCapabilitiesStore()

  const gates = panels.map((panel) => panel.useGate?.(ctx) ?? alwaysReady)

  return computed(() =>
    panels
      .filter((panel, i) => {
        if (panel.requiresCapability && !caps.isEnabled(panel.requiresCapability)) return false
        return gates[i]!.value === 'ready'
      })
      .sort((a, b) => a.order - b.order),
  )
}
