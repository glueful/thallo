<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useContentTypes } from '@/queries/contentTypes'
import { MAX_BLOCK_DEPTH, useBlockTypes } from '@/queries/blockTypes'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { proseRichFieldName } from '@/fields/components/blocks/proseDetection'
import { useDraft, useSaveDraft } from '@/queries/drafts'
import {
  applyPreview,
  mintPreviewData,
  type ApplyPreviewResult,
  type RevisionPair,
} from '@/queries/preview'
import { useCanvasBridge } from '@/composables/useCanvasBridge'
import { createApplyMetrics, type ApplyPath } from '@/editor/applyMetrics'
import { createEditorHistory, type EditorHistory } from '@/editor/ops/history'
import { diffDocuments } from '@/editor/ops/diff'
import { newEditorSession } from '@/editor/ops/session'
import { absent, present } from '@/editor/ops/types'
import { readPath, setPath, settingSegments } from '@/editor/ops/apply'
import { useStyleSchema, type StylePropertyRow } from '@/queries/styleSchema'
import { useStyleClasses, useStyleClassMutations } from '@/queries/styleClasses'
import { capabilityPaths, detachStyleClass } from '@/style/detach'
import { liftPreservesAppearance, liftedDeclarations } from '@/style/lift'
import {
  createDragCoordinator,
  type DragSource,
  type DropZone,
} from '@/editor/structure/coordinator'
import { createStructurePicker } from '@/editor/structure/structurePicker'
import {
  createGridFill,
  type FillAvailability,
  type StageFillState,
} from '@/editor/structure/gridFill'
import MoveToDialog from './components/MoveToDialog.vue'
import BlocksPalette from '@/editor/palette/BlocksPalette.vue'
import BoxField from '@/editor/inspector/controls/BoxField.vue'
import ResponsiveField from '@/editor/inspector/controls/ResponsiveField.vue'
import { BREAKPOINT_LABELS } from '@/editor/breakpoint'
import { resolveTarget, tilePreflight, type InsertTarget } from '@/editor/palette/target'
import { useBlockFactory } from '@/queries/blockFactory'
import { createPaletteDrag } from '@/editor/palette/usePaletteDrag'
import type { Legality, LegalityContext } from '@/editor/structure/legality'
import {
  EMPTY_SELECTION,
  extend,
  reconcile,
  single,
  toggle,
  type Selection,
} from '@/editor/selection'
import SaveAsStyleClassDialog from '@/editor/inspector/SaveAsStyleClassDialog.vue'
import type { StyleClassRef } from '@/style/types'
import {
  activeBreakpoint,
  BREAKPOINT_OF_VIEWPORT,
  setActiveBreakpoint,
  VIEWPORT_OF_BREAKPOINT,
  type ViewportPreset,
} from '@/editor/breakpoint'
import type { Breakpoint, StyleValue } from '@/style/types'
import BlockInspector from '@/editor/inspector/BlockInspector.vue'
import { invertOperation } from '@/editor/ops/invert'
import type {
  ChangeValue,
  EditorDocument,
  Operation,
  OperationBody,
  Position,
} from '@/editor/ops/types'
import type {
  BridgeAnchor,
  EditKind,
  SelectModifiers,
  StageRefreshMode,
} from '@/composables/useCanvasBridge'
import { useNotify } from '@/composables/useNotify'
import { ApiError, apiErrorCode, apiErrorDetails } from '@/api/errors'
import { blockAtValidationPath } from '@/editor/validationPath'
import { toFieldDef } from '@/fields/normalize'
import type { ContentTypeField } from '@/queries/contentTypes'
import type { FieldDef } from '@/fields/types'
import FieldEditor from '@/components/FieldEditor.vue'
import SeoPanel from '../components/SeoPanel.vue'
import VersionsPanel from '../components/VersionsPanel.vue'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { usePublish } from '@/queries/publish'
import { useEntryLocales } from '@/queries/entries'
import { localeStatus } from '../components/localeStatus'
import CanvasOutline from './components/CanvasOutline.vue'

// The visual canvas (visual-canvas spec §1): a FULL-SCREEN sibling of the entry
// editor — iframe stage (real theme render via a preview session), left outline,
// right inspector (the editor's exact FieldEditor), explicit Save & refresh.
// This page loads the draft INDEPENDENTLY and saves through the same endpoint
// with lock_version; the stale-lock 409 is the race boundary with the editor.
definePage({ meta: { requiresAuth: true, collapseSidebar: true } })

const route = useRoute()
const { success, warning, error: notifyError } = useNotify()

const type = computed(() => String(route.params.type))
const uuid = computed(() => String(route.params.uuid))
const locale = computed(() => String(route.params.locale))

// ── Schema + draft (independent load; spec §1) ─────────────────────────────────
const { data: contentTypes } = useContentTypes()
const contentType = computed(() => contentTypes.value?.find((c) => c.slug === type.value))
const schema = computed<FieldDef[]>(() =>
  // The list endpoint's schema rows are structurally ContentTypeField (the shared
  // wire shape); the cast bridges the generated optional-name variance.
  (contentType.value?.schema ?? []).map((f) => toFieldDef(f as ContentTypeField)),
)

const { data: draft } = useDraft(uuid, () => locale.value)
const fields = ref<Record<string, unknown>>({})
const lockVersion = ref(0)
// What the stage currently shows (loop C §4): set at first hydration (the
// initial render is the draft) and after every successful Apply.
const lastApplied = ref('')
let hydratedLock = -1
// Stash-reconciliation state (declared BEFORE the immediate hydration watcher
// below — it calls maybeReconcileStash at setup time).
let stageLoaded = false
let stageSynced = false
// ── History and the three revisions (visual builder spec §3.2, §3.5) ──────────
// L (local) is the history's sequence; A (accepted) is the pair the server last accepted;
// D (displayed) is the pair the stage last patched to. Every mutation of `fields` — the
// inspector's own components, stage intents, page settings — is derived into operations and
// recorded; undo and redo replay the history's document back into `fields`.
const session = newEditorSession()
let history: EditorHistory | null = null
let replaying = false
let pendingRebase = false
let submittedSequence = 0
let composing = false
let commitTimer: ReturnType<typeof setTimeout> | null = null
const opsSinceApply: Operation[] = []
const accepted = ref<RevisionPair | null>(null)
const displayed = ref<RevisionPair | null>(null)
const styleGeneration = ref<number | null>(null)
const historyState = ref({ canUndo: false, canRedo: false, dirty: false, pending: false })
function refreshHistoryState(): void {
  if (!history) return
  historySequence.value = history.currentSequence
  historyState.value = {
    canUndo: history.canUndo(),
    canRedo: history.canRedo(),
    dirty: history.isDirty,
    pending: history.activeTransaction !== null,
  }
}
watch(
  draft,
  (d) => {
    // Hydrate on load and after saves (lock bump); never clobber in-flight edits
    // from a background refetch of the SAME lock — nor edits made while a save was in
    // flight (spec §3.5: a save of revision 10 completing after an edit to 11 leaves 11).
    if (d && d.lock_version !== hydratedLock) {
      const editedSinceSubmit = history !== null && history.currentSequence !== submittedSequence
      lockVersion.value = d.lock_version
      hydratedLock = d.lock_version
      if (editedSinceSubmit) return
      pendingRebase = history !== null
      fields.value = { ...d.fields }
      // First hydration: the stage's initial render shows the draft (loop C §4)
      // — unless a stale stash overlays it, which the reconciliation apply
      // corrects once the stage has loaded.
      if (lastApplied.value === '') {
        lastApplied.value = JSON.stringify(d.fields)
        maybeReconcileStash()
      }
    }
  },
  { immediate: true },
)

const dirty = computed(() => {
  if (history !== null) return historyState.value.dirty || historyState.value.pending
  const loaded = draft.value?.fields ?? {}
  return JSON.stringify(fields.value) !== JSON.stringify(loaded)
})

// ── Preview stage (spec §6) ────────────────────────────────────────────────────
const iframeSrc = ref('')
const previewToken = ref('')
const renderDisabled = ref(false)
const mintFailed = ref(false)
const iframeEl = ref<HTMLIFrameElement | null>(null)
const bridge = useCanvasBridge(iframeEl)
// Apply-to-paint instrumentation (visual builder spec §3.5): marks on every apply, a
// development-only overlay with the medians, p95s and fallback count per path.
const metrics = createApplyMetrics()
const metricsSummary = ref(metrics.summary())
const showMetrics = import.meta.env.DEV
onBeforeUnmount(() => {
  cancelAutoTimer()
  bridge.dispose()
})

async function mintAndLoad(): Promise<void> {
  try {
    const mint = await mintPreviewData(uuid.value, locale.value)
    if (!mint.themeUrl) {
      // Rendered delivery disabled: the route LOADS and explains (spec §6) —
      // never an SPA-side 404.
      renderDisabled.value = true
      return
    }
    renderDisabled.value = false
    mintFailed.value = false
    previewToken.value = mint.token
    // A second editor starts from the accepted state (spec §3.5); a re-mint keeps ours.
    if (accepted.value === null && mint.accepted) accepted.value = mint.accepted
    // The stage iframe IS the design canvas: declare it (?canvas=1) so the
    // render pack annotates blocks. Review previews (open-in-new-tab, the
    // content form's eye button) load the plain token URL and render clean,
    // with live-page behaviors (autoplay, arrows, lightboxes) running.
    iframeSrc.value = mint.themeUrl + (mint.themeUrl.includes('?') ? '&' : '?') + 'canvas=1'
  } catch (e) {
    mintFailed.value = true
    notifyError(e, 'Couldn’t start the preview')
  }
}
void mintAndLoad()

function onIframeLoad(): void {
  bridge.hello()
  refreshGridFill(true) // a freshly loaded stage has heard nothing, whatever the last one was told
  if (lastScrollY > 0) bridge.restoreScroll(lastScrollY)
  stageLoaded = true
  maybeReconcileStash()
}

// ── Stash reconciliation (stale-stash fix) ────────────────────────────────────
// The working-copy stash outlives canvas sessions: keyed by entry+locale (not
// token), cleared only by saveDraft, TTL-bounded. An abandoned session's stash
// overlays the DRAFT on the next open, so the stage's initial render can show
// state the tree doesn't have — and stageStale (fields vs lastApplied) can't
// see it. One initial apply of the hydrated tree overwrites the stash with
// truth. Runs regardless of the Auto toggle: this is honesty, not an edit.
function maybeReconcileStash(): void {
  if (stageSynced || !stageLoaded) return
  if (lastApplied.value === '' || previewToken.value === '') return // hydration/mint pending
  stageSynced = true
  void runApply(true)
}

// ── Page settings tab (modern-default-theme spec §5a) ─────────────────────────
// _presentation is a reserved system key in the SAME fields tree: edits ride
// the deep watcher -> auto-apply -> stash -> render chain like any content
// change, and save/publish/version it with the draft. "Theme default" DELETES
// the key so the theme.json chain shows through.
const inspectorTab = ref('content')
/** The committed history sequence, reactive: gap targets are pinned to it (Phase C.1). */
const historySequence = ref(0)
const caps = useCapabilitiesStore()
const seoEnabled = computed(() => caps.isEnabled('thallo.seo'))
const inspectorTabs = computed(() => [
  ...(selected.value !== null ? [{ label: 'Block', value: 'block', slot: 'block' as const }] : []),
  { label: 'Content', value: 'content', slot: 'content' as const },
  { label: 'Blocks', value: 'blocks', slot: 'blocks' as const },
  { label: 'Outline', value: 'outline', slot: 'outline' as const },
  { label: 'Page', value: 'page', slot: 'page' as const },
  ...(seoEnabled.value ? [{ label: 'SEO', value: 'seo', slot: 'seo' as const }] : []),
  { label: 'Versions', value: 'versions', slot: 'versions' as const },
])

const presentationOverride = computed<Record<string, unknown>>(() => {
  const p = fields.value._presentation
  return p !== null && typeof p === 'object' && !Array.isArray(p)
    ? (p as Record<string, unknown>)
    : {}
})
const presShowTitle = computed(() => {
  const v = presentationOverride.value.show_title
  return v === true ? 'show' : v === false ? 'hide' : 'default'
})
const presLayout = computed(() => {
  const v = presentationOverride.value.layout
  return v === 'full' || v === 'centered' ? v : 'default'
})
// Chrome suppression (global-regions spec §7): 'default'|'hidden'; absent
// follows the theme. The UI maps absent → "Theme default", 'default' → "Show"
// (an explicit override over a theme that hides), 'hidden' → "Hide".
const presChrome = (key: 'header' | 'footer') =>
  computed(() => {
    const v = presentationOverride.value[key]
    return v === 'hidden' ? 'hide' : v === 'default' ? 'show' : 'default'
  })
const presHeader = presChrome('header')
const presFooter = presChrome('footer')

function patchPresentation(
  key: 'show_title' | 'layout' | 'header' | 'footer' | 'style',
  value: unknown,
): void {
  const next = { ...presentationOverride.value }
  if (value === undefined) delete next[key]
  else next[key] = value
  const nextFields = { ...fields.value }
  if (Object.keys(next).length === 0) delete nextFields._presentation
  else nextFields._presentation = next
  fields.value = nextFields // reassign: the deep watcher schedules auto-apply
}
// The page's own style frame (Page tab › Styles): padding, margin and background stored under
// _presentation.style in a block's style shape, painted on <main> by the render with the same
// utility classes. The rows are fixed — the page owns exactly these capabilities.
const PAGE_STYLE_ROWS: StylePropertyRow[] = [
  ...['top', 'right', 'bottom', 'left'].map((s) => ({
    path: `spacing.padding.${s}`,
    group: 'spacing',
    kinds: ['token', 'reset'],
    responsive: true,
    token_domain: 'spacing',
    choices: null,
  })),
  ...['top', 'bottom'].map((s) => ({
    path: `spacing.margin.${s}`,
    group: 'spacing',
    kinds: ['token', 'reset'],
    responsive: true,
    token_domain: 'spacing',
    choices: null,
  })),
  {
    path: 'colors.surface',
    group: 'colors',
    kinds: ['token', 'reset'],
    // Colours are one value for every breakpoint in the style contract.
    responsive: false,
    token_domain: 'color',
    choices: null,
  },
] as StylePropertyRow[]
const pageStyleSides = (prefix: string) =>
  PAGE_STYLE_ROWS.filter((r) => r.path.startsWith(prefix)).map((def) => ({
    key: def.path.slice(prefix.length),
    def,
  }))
const pageBackgroundRow = PAGE_STYLE_ROWS[PAGE_STYLE_ROWS.length - 1]!
const pageStyle = computed<Record<string, unknown>>(() => {
  const s = presentationOverride.value.style
  return s !== null && typeof s === 'object' && !Array.isArray(s)
    ? (s as Record<string, unknown>)
    : {}
})
function writePageStyle(next: Record<string, unknown>): void {
  patchPresentation('style', Object.keys(next).length === 0 ? undefined : next)
}
function onPageStyleSet(path: string, bp: Breakpoint | null, value: StyleValue | null): void {
  writePageStyle(
    setPath(
      pageStyle.value,
      settingSegments(path, bp).slice(1),
      value === null ? { present: false } : { present: true, value },
    ) as Record<string, unknown>,
  )
}
function onPageStyleSetAll(path: string, value: StyleValue): void {
  let next = pageStyle.value
  for (const bp of ['base', 'md', 'lg'] as const) {
    next = setPath(next, settingSegments(path, bp).slice(1), { present: true, value }) as Record<
      string,
      unknown
    >
  }
  writePageStyle(next)
}
function pageStyleDeclaredAt(bp: Breakpoint): boolean {
  return PAGE_STYLE_ROWS.some(
    (row) => readPath(pageStyle.value, settingSegments(row.path, bp).slice(1)).present,
  )
}

function setPresShowTitle(v: string): void {
  patchPresentation('show_title', v === 'default' ? undefined : v === 'show')
}
function setPresLayout(v: string): void {
  patchPresentation('layout', v === 'default' ? undefined : v)
}
function setPresChrome(key: 'header' | 'footer', v: string): void {
  patchPresentation(key, v === 'default' ? undefined : v === 'show' ? 'default' : 'hidden')
}

// Viewport presets (spec §6): stage width — and the active breakpoint every responsive
// control binds to (visual builder spec §3.4), synced both ways, never inferred from the iframe.
const viewport = ref<ViewportPreset>('desktop')
function setViewport(v: ViewportPreset): void {
  viewport.value = v
  setActiveBreakpoint(BREAKPOINT_OF_VIEWPORT[v])
}
function onActiveBreakpoint(bp: Breakpoint): void {
  setActiveBreakpoint(bp)
  viewport.value = VIEWPORT_OF_BREAKPOINT[bp]
}
setActiveBreakpoint(BREAKPOINT_OF_VIEWPORT[viewport.value])
const stageWidth = computed(
  () => ({ desktop: '100%', tablet: '768px', mobile: '390px' })[viewport.value],
)

// ── Selection (spec §5) ────────────────────────────────────────────────────────
interface FieldEditorExposed {
  selectBlockById: (id: string) => boolean
  patchBlockSettingsById: (id: string, settings: Record<string, unknown>) => boolean
  blockById: (id: string) => BlockInstance | null
  moveBlockById: (id: string, delta: number) => { beforeId: string } | { afterId: string } | null
  duplicateBlockById: (id: string) => { newId: string; idMap: Record<string, string> } | null
  deleteBlockById: (id: string) => boolean
  insertAfterById: (id: string, typeSlug: string) => Promise<string | null>
  patchBlockDataById: (id: string, field: string, value: unknown) => boolean
  blockTypeOfBlock: (id: string) => string | null
  parentOfBlockById: (id: string) => BlockInstance | null
}
const fieldEditorRef = ref<FieldEditorExposed | null>(null)
// ── Selection (visual builder spec §5.5): a set of siblings from one slot, the anchor first
// among them. Shift extends, cmd/ctrl toggles, a plain click selects one; every mutation front
// door acts on the whole selection, and the tree re-reads it after every change.
const selection = ref<Selection>(EMPTY_SELECTION)
/** The anchor: the block the inspector's Content and Advanced tabs and single-block actions address. */
const selected = computed<string | null>(() => selection.value.anchor)
const selectionCtx = {
  regionsOf: (slug: string) => regionsOf(slug),
  blockFields: () => blockFields(),
}
const selectionDoc = (): EditorDocument => ({ fields: fields.value as Record<string, unknown> })
function applySelection(
  id: string,
  modifiers: SelectModifiers = { shift: false, meta: false },
): void {
  const doc = selectionDoc()
  clearInsertTarget()
  pendingStageSelect.value = null
  selection.value = modifiers.shift
    ? extend(selection.value, id, doc, selectionCtx)
    : modifiers.meta
      ? toggle(selection.value, id, doc, selectionCtx)
      : single(id, doc, selectionCtx)
}
function selectOne(id: string): void {
  insertTarget.value = null
  targetStale.value = false
  insertAttempt++
  pendingStageSelect.value = null
  const next = single(id, selectionDoc(), selectionCtx)
  // A block the tree does not hold yet (an insert still landing) is selected on trust and
  // re-read on the next change.
  selection.value = next.ids.length > 0 ? next : { ids: [id], parent: null, slot: null, anchor: id }
}
function clearSelection(): void {
  pendingStageSelect.value = null
  selection.value = EMPTY_SELECTION
  clearInsertTarget()
}
/** Ring the whole selection on the stage; the anchor carries the toolbar. */
function ringSelection(): void {
  const s = selection.value
  if (s.anchor !== null) bridge.highlight(s.anchor, s.ids)
}
/** The selection when it is a group containing `id`: the front doors act on all of it. */
function groupFor(id: string): Selection | null {
  const s = selection.value
  return s.ids.length > 1 && s.ids.includes(id) ? s : null
}

/**
 * A stage click the tree could not place yet. The stage is clickable as soon as its own page has
 * loaded, which can be before the schema, the block types or the draft have — and until they do a
 * block has no position to select it at. The click is kept and placed when they arrive; any other
 * selection, or a deselect, forgets it. Once everything has loaded an id that still has no place
 * is simply unknown, and nothing waits for it.
 */
const pendingStageSelect = ref<string | null>(null)
const treeLoaded = (): boolean =>
  contentType.value !== undefined && allBlockTypes.value !== undefined && hydratedLock !== -1

function selectFromStage(id: string, modifiers: SelectModifiers): void {
  applySelection(id, modifiers)
  const anchor = selection.value.anchor
  // Only a plain click waits: a modified one extends a selection that does not exist yet.
  if (anchor === null && !modifiers.shift && !modifiers.meta && !treeLoaded()) {
    pendingStageSelect.value = id
  }
  if (anchor !== null) fieldEditorRef.value?.selectBlockById(anchor)
  // A modified click is judged here, so the stage learns the resulting rings from the parent.
  if (modifiers.shift || modifiers.meta) ringSelection()
  inspectorTab.value = 'block'
}
bridge.onBlockSelect((id, modifiers = { shift: false, meta: false }) =>
  selectFromStage(id, modifiers),
)

// ── The block inspector (visual builder spec §3.4) ────────────────────────────
const { data: styleSchema } = useStyleSchema()
/** The selected block, read off the live tree (every edit re-derives it). */
const selectedBlock = computed<BlockInstance | null>(() => {
  void fields.value // the tree is the dependency; the editor ref only routes the lookup
  return selected.value !== null ? (fieldEditorRef.value?.blockById(selected.value) ?? null) : null
})
const selectedBlockType = computed(
  () => allBlockTypes.value?.find((t) => t.slug === selectedBlock.value?.type) ?? null,
)
/** Every selected block, in document order, read off the live tree. */
const selectedBlocks = computed<BlockInstance[]>(() => {
  void fields.value
  return selection.value.ids
    .map((id) => fieldEditorRef.value?.blockById(id) ?? null)
    .filter((b): b is BlockInstance => b !== null)
})
const selectedBlockTypes = computed(() =>
  selectedBlocks.value.map((b) => allBlockTypes.value?.find((t) => t.slug === b.type) ?? null),
)
/**
 * The parent whose layout the selection sits in (container-layout spec §5). For several blocks it
 * is passed only when they all share one: item controls answer to ONE parent's mode, and a
 * selection spanning two parents has no single answer to give.
 */
const selectedParent = computed<BlockInstance | null>(() => {
  void fields.value
  const ids =
    selection.value.ids.length > 1 ? selection.value.ids : selected.value ? [selected.value] : []
  if (ids.length === 0) return null
  const parents = ids.map((id) => fieldEditorRef.value?.parentOfBlockById(id) ?? null)
  const first = parents[0] ?? null
  return first !== null && parents.every((p) => p?.id === first.id) ? first : null
})
const selectedParentType = computed(
  () => allBlockTypes.value?.find((t) => t.slug === selectedParent.value?.type) ?? null,
)
/** The ids a style edit writes to: the whole selection, in one transaction. */
const styleTargets = (): string[] =>
  selection.value.ids.length > 0 ? selection.value.ids : selected.value ? [selected.value] : []

function writeSettings(
  id: string,
  mutate: (settings: Record<string, unknown>) => Record<string, unknown>,
): void {
  const block = fieldEditorRef.value?.blockById(id)
  if (!block) return
  fieldEditorRef.value?.patchBlockSettingsById(id, mutate(block.settings ?? {}))
}
/**
 * A style edit on a group is one transaction of SetSettings, one per block (spec §5.5),
 * recorded straight into history: the blocks' trees are read from history as the transaction
 * leaves them, so no write can shadow another.
 */
function recordGroupSettings(
  ids: string[],
  path: string,
  breakpoints: (Breakpoint | null)[],
  value: StyleValue | null,
): void {
  if (!history) return
  commitNow()
  history.beginTransaction()
  for (const id of ids) {
    for (const bp of breakpoints) {
      const current = blockFromHistory(id)
      if (!current) continue
      const segments = settingSegments(path, bp).slice(1)
      const from = readPath(
        (current.settings ?? {}) as Record<string, unknown>,
        segments,
      ) as ChangeValue<StyleValue>
      opsSinceApply.push(
        history.record({
          type: 'SetSetting',
          block: id,
          path,
          breakpoint: bp,
          from,
          to: value === null ? absent() : present(value),
        }),
      )
    }
  }
  commitNow()
  void replayHistory().then(() => scheduleCommit(false))
}
function onSetSetting(path: string, bp: Breakpoint | null, value: StyleValue | null): void {
  const ids = styleTargets()
  if (ids.length > 1) {
    recordGroupSettings(ids, path, [bp], value)
    return
  }
  if (selected.value === null) return
  writeSettings(selected.value, (s) =>
    setPath(s, settingSegments(path, bp), value === null ? absent() : present(value)),
  )
}
function onSetAll(path: string, value: StyleValue): void {
  const ids = styleTargets()
  if (ids.length > 1) {
    recordGroupSettings(ids, path, ['base', 'md', 'lg'], value)
    return
  }
  if (selected.value === null) return
  writeSettings(selected.value, (s) => {
    let next = s
    for (const bp of ['base', 'md', 'lg'] as Breakpoint[]) {
      next = setPath(next, settingSegments(path, bp), present(value))
    }
    return next
  })
}
function onSetAdvanced(path: string, value: unknown): void {
  if (selected.value === null) return
  writeSettings(selected.value, (s) =>
    setPath(s, ['advanced', ...path.split('.')], value === null ? absent() : present(value)),
  )
}
function onPatchData(name: string, value: unknown): void {
  if (selected.value !== null) fieldEditorRef.value?.patchBlockDataById(selected.value, name, value)
}

// Stage Escape (keyboard-shortcuts spec §3): the bridge already cleared its
// ring/toolbar — without this the parent's selection would go stale and the
// outline/inspector would lie.
bridge.onBlockDeselect(() => {
  clearSelection()
})

function onOutlineSelect(id: string, modifiers: SelectModifiers): void {
  applySelection(id, modifiers)
  const anchor = selection.value.anchor
  if (anchor === null) return
  fieldEditorRef.value?.selectBlockById(anchor)
  ringSelection()
  bridge.scrollTo(anchor)
}

// ── Stage toolbar intents (stage-toolbar spec §2/§4): mutate through the
// FieldEditor (single tree authority), mirror ONLY after the commit. ──────────
// Shared intent handlers (polish batch §4): the outline's keyboard emits and
// the bridge's stage callbacks drive the SAME functions — no new mutation
// paths, just two front doors.
function moveBlockAndMirror(id: string, delta: 1 | -1): void {
  const group = groupFor(id)
  if (group) {
    moveGroup(group, delta)
    return
  }
  const neighbor = fieldEditorRef.value?.moveBlockById(id, delta) ?? null
  if (neighbor) bridge.mirrorMove(id, neighbor)
}
/** The list a selection lives in, as the tree holds it now. */
function listOfSelection(sel: Selection): BlockInstance[] {
  if (sel.slot === null) return []
  const raw =
    sel.parent === null
      ? fields.value[sel.slot]
      : fieldEditorRef.value?.blockById(sel.parent)?.data[sel.slot]
  return Array.isArray(raw) ? (raw as BlockInstance[]) : []
}
/**
 * A group moves as one transaction through the coordinator (spec §5.5): one step past the
 * sibling before or after it, the zone counted against the list with the group removed.
 */
function moveGroup(sel: Selection, delta: 1 | -1): void {
  const list = listOfSelection(sel)
  const first = list.findIndex((b) => b.id === sel.ids[0])
  const last = list.findIndex((b) => b.id === sel.ids[sel.ids.length - 1])
  if (first === -1 || last === -1) return
  if (delta === -1 && first === 0) return
  if (delta === 1 && last === list.length - 1) return
  coordinator.begin('stage', { blocks: sel.ids })
  void finishDrop({
    parent: sel.parent,
    slot: sel.slot,
    index: delta === -1 ? first - 1 : first + 1,
  })
}

bridge.onBlockMove(moveBlockAndMirror)

// Stage drags are proposals (visual builder spec §5.3): the bridge derives zones from real slot
// geometry and never touches the tree; the coordinator judges each proposal and answers its
// legality, and the drop applies as one transaction. A rejected drop leaves the stage as it is —
// nothing to snap back, since nothing moved.
let stageDragSession: string | null = null
bridge.onDragPropose((session, blocks, zone) => {
  if (zone === null) return // the session left every slot: nothing to judge
  if (stageDragSession !== session) {
    coordinator.begin('stage', { blocks })
    stageDragSession = session
  }
  const proposal = coordinator.propose(zone)
  if (proposal === null) return
  bridge.dragLegality(
    session,
    proposal.verdict.ok,
    proposal.verdict.ok ? '' : proposal.verdict.message,
  )
})
bridge.onBlockDrop((session, _blocks, zone) => {
  // A palette session's answer belongs to the palette drag alone (Phase C.1).
  if (paletteDrag.owns(session)) {
    paletteDrag.answer(zone)
    return
  }
  // Only the session this page is judging may drop: the bridge proposes before it ever drops,
  // so a drop for a cancelled or unknown session is stale and ignored.
  if (stageDragSession !== session) return
  stageDragSession = null
  void finishDrop(zone)
})
bridge.onDragCancel((session) => {
  if (paletteDrag.owns(session)) {
    paletteDrag.answer(null)
    return
  }
  if (stageDragSession !== session) return
  stageDragSession = null
  coordinator.cancel()
})
/** Escape in the parent while the stage drags: end the session on both sides. */
function onParentKeydown(e: KeyboardEvent): void {
  if (e.key !== 'Escape' || stageDragSession === null) return
  e.preventDefault()
  bridge.dragEnd(stageDragSession)
  stageDragSession = null
  coordinator.cancel()
}
onMounted(() => window.addEventListener('keydown', onParentKeydown, true))
onBeforeUnmount(() => window.removeEventListener('keydown', onParentKeydown, true))

function duplicateAndMirror(id: string): void {
  const group = groupFor(id)
  if (group) {
    // One transaction of DuplicateBlocks, each copy after its source (read from history as
    // the transaction leaves it); the copies become the selection.
    if (!history) return
    commitNow()
    history.beginTransaction()
    const listOps = history.applier.listOps
    const copies: string[] = []
    const mirrors: [string, Record<string, string>][] = []
    for (const member of group.ids) {
      const source = blockFromHistory(member)
      if (!source) continue
      const list = listOps.duplicateById([source], member)
      const copy = list[1]
      if (!copy) continue
      const at = history.applier.listOps.locateById(
        (group.parent === null
          ? (history.document.fields[group.slot!] as BlockInstance[] | undefined)
          : (blockFromHistory(group.parent)?.data[group.slot!] as BlockInstance[] | undefined)) ??
          [],
        member,
      )
      if (!at) continue
      opsSinceApply.push(
        history.record({
          type: 'DuplicateBlock',
          source: member,
          position: { parent: group.parent, slot: group.slot, index: at.index + 1 },
          block: copy,
        }),
      )
      // A duplicate lands INSIDE a container as surely as an insert does (spec §6.1).
      picker.onDocumentChange([
        {
          type: 'DuplicateBlock',
          source: member,
          position: { parent: group.parent, slot: group.slot, index: at.index + 1 },
          block: copy,
        },
      ])
      copies.push(copy.id)
      mirrors.push([member, listOps.idMapBetween(source, copy)])
    }
    commitNow()
    if (copies.length === 0) return
    void replayHistory().then(() => {
      for (const [source, idMap] of mirrors) bridge.mirrorDuplicate(source, idMap)
      selection.value = { ids: copies, parent: group.parent, slot: group.slot, anchor: copies[0]! }
      fieldEditorRef.value?.selectBlockById(copies[0]!)
      ringSelection()
      scheduleCommit(true)
    })
    return
  }
  const result = fieldEditorRef.value?.duplicateBlockById(id) ?? null
  if (result) {
    bridge.mirrorDuplicate(id, result.idMap)
    selectOne(result.newId)
    fieldEditorRef.value?.selectBlockById(result.newId)
  }
}

bridge.onBlockDuplicate(duplicateAndMirror)

/**
 * Translate an iframe-viewport anchor into stage-container content
 * coordinates (the container is the positioning context and scrolls); clamp
 * the panel inside the container. Null when the geometry isn't available.
 */
function anchoredPos(
  anchor: { x: number; y: number } | null | undefined,
  panelWidth: number,
): { top: string; left: string } | null {
  if (!anchor || !stageEl.value || !iframeEl.value) return null
  const stageRect = stageEl.value.getBoundingClientRect()
  const iframeRect = iframeEl.value.getBoundingClientRect()
  const rawLeft = iframeRect.left - stageRect.left + stageEl.value.scrollLeft + anchor.x
  const top = iframeRect.top - stageRect.top + stageEl.value.scrollTop + anchor.y + 8
  const maxLeft = Math.max(8, stageEl.value.clientWidth - (panelWidth + 8))
  return {
    top: `${Math.max(8, top)}px`,
    left: `${Math.max(8, Math.min(rawLeft, maxLeft))}px`,
  }
}

// Delete is parent-confirmed (review pin): the bridge only ever REQUESTS.
const deleteRequest = ref<string | null>(null)
const deletePos = ref<{ top: string; left: string } | null>(null)
function openDeleteConfirm(id: string, anchor: BridgeAnchor | null): void {
  deletePos.value = anchoredPos(anchor, 200) // null anchor -> centered fallback
  deleteRequest.value = id
}

bridge.onBlockDeleteRequest(openDeleteConfirm)

// Outline Escape (polish batch §4): clear parent state AND the stage ring —
// the bridge's highlight handler clearSelection()s on an unresolvable id.
function onOutlineDeselect(): void {
  clearSelection()
  bridge.highlight('')
}

function cancelDelete(): void {
  deleteRequest.value = null
}

function confirmDelete(): void {
  const id = deleteRequest.value
  deleteRequest.value = null
  if (id === null) return
  const group = groupFor(id)
  if (group && history) {
    // One transaction of RemoveBlocks, last to first so every position is read as the
    // transaction leaves the list.
    commitNow()
    history.beginTransaction()
    const removed: string[] = []
    for (const member of [...group.ids].reverse()) {
      const block = blockFromHistory(member)
      const list =
        (group.parent === null
          ? (history.document.fields[group.slot!] as BlockInstance[] | undefined)
          : (blockFromHistory(group.parent)?.data[group.slot!] as BlockInstance[] | undefined)) ??
        []
      const index = list.findIndex((b) => b.id === member)
      if (!block || index === -1) continue
      opsSinceApply.push(
        history.record({
          type: 'RemoveBlock',
          position: { parent: group.parent, slot: group.slot, index },
          block,
        }),
      )
      removed.push(member)
    }
    commitNow()
    clearSelection()
    void replayHistory().then(() => {
      for (const member of removed) bridge.mirrorRemove(member)
      scheduleCommit(true)
    })
    return
  }
  if (fieldEditorRef.value?.deleteBlockById(id)) {
    bridge.mirrorRemove(id)
    if (selected.value === id) clearSelection()
  }
}

const stageEl = ref<HTMLElement | null>(null)

// Every "add here" surface arms the Blocks tab (Phase C.1): the stage +, the list's gaps and Add
// block, the card header's /, and the outline's empty slots. No popover, no anchoring.
bridge.onBlockAddAfter((id) => armInsertTarget({ kind: 'after', block: id }))
bridge.onSlotAdd((parent, slot) => armInsertTarget({ kind: 'into', parent, field: slot }))

// ── Edit-in-place (edit-in-place spec §4): grant prose blocks only; typed
// text patches the tree — no mirrors, the contenteditable IS the stage DOM.
const { data: allBlockTypes } = useBlockTypes()

watch([schema, allBlockTypes, fields], () => {
  const id = pendingStageSelect.value
  if (id !== null) selectFromStage(id, { shift: false, meta: false })
})
const regionsOf = (slug: string): string[] => {
  const blockType = allBlockTypes.value?.find((t) => t.slug === slug)
  return (blockType?.schema ?? []).filter((f) => f.type === 'blocks').map((f) => f.name)
}
/**
 * The legality context (visual builder spec §5.2): every known block type's slots with their
 * allow-lists, and the page's root blocks fields with theirs.
 */
function legalityContext(): LegalityContext {
  return {
    regionsOf,
    blockTypes: () =>
      (allBlockTypes.value ?? []).map((t) => ({
        slug: t.slug,
        label: t.label,
        slots: Object.fromEntries(
          t.schema
            .filter((f) => f.type === 'blocks')
            .map((f) => [f.name, { blockTypes: toFieldDef(f).blockTypes ?? [] }]),
        ),
      })),
    rootSlots: () =>
      Object.fromEntries(
        schema.value
          .filter((f) => f.type === 'blocks')
          .map((f) => [f.name, { blockTypes: f.blockTypes ?? [] }]),
      ),
    maxDepth: MAX_BLOCK_DEPTH,
  }
}

/** One coordinator for every drag source (spec §5.1); its drops apply as one transaction. */
const coordinator = createDragCoordinator({
  doc: () => history?.document ?? { fields: snapshotFields() },
  legality: legalityContext,
})

/** The offers as last published, for the proofs: the stage is captured there and renders none. */
let lastStructureOffers: { id: string; presets: { key: string; enabled: boolean }[] }[] = []

/**
 * The structure picker (container-layout spec §6): offered for a container the author just
 * inserted, empty and fresh. The offer lives in this session, never in the document, so undo
 * neither resurrects a picker nor reopens one that content has already consumed.
 */
const picker = createStructurePicker({
  doc: () => history?.document ?? { fields: snapshotFields() },
  legality: legalityContext,
  classesFor: (id) => classRefsFor(fieldEditorRef.value?.blockById(id) ?? null),
  factory: (slug) => blockFactory.instance(slug),
  commit: (ops) => applyDrop(ops),
  publish: (offers) => {
    lastStructureOffers = offers
    bridge.publishStructureOffers(offers)
  },
  notify: (message) => warning(message),
})
bridge.onStructureChoose((id, preset) => void picker.choose(id, preset))
bridge.onStructureSkip((id) => picker.skip(id))
/** A block dropped (or moved to) a zone from any surface: judged, then applied or refused aloud. */
function runDrop(source: DragSource, id: string, zone: DropZone): void {
  coordinator.begin(source, { blocks: [id] })
  void finishDrop(zone)
}
/** Judge the coordinator's current session against `zone` and apply or refuse its drop. */
async function finishDrop(zone: DropZone): Promise<boolean> {
  const proposal = coordinator.propose(zone)
  const ops = coordinator.drop()
  if (ops === null) {
    warning(
      'That move is not allowed',
      proposal && !proposal.verdict.ok ? proposal.verdict.message : '',
    )
    return false
  }
  await applyDrop(ops)
  return true
}
const moveToId = ref<string | null>(null)
/** The document as history holds it, for the dialogs that judge legality. */
function currentDoc(): EditorDocument {
  return history?.document ?? { fields: snapshotFields() }
}

// ── The Blocks tab (visual builder spec §5.1, §5.5 — Phase C.1): one palette. An armed target is
// an intent resolved at the moment of use; a click captures the intent and an attempt token,
// waits for the factory, checks the token, resolves the intent against the document as it is
// then, and inserts through the coordinator. A refusal keeps the target and says why.
const blockFactory = useBlockFactory()
const insertTarget = ref<InsertTarget | null>(null)
const targetStale = ref(false)
let insertAttempt = 0
const paletteTypes = computed(() => (allBlockTypes.value ?? []).filter((t) => t.active))
function effectiveTarget(): InsertTarget | null {
  if (insertTarget.value) return insertTarget.value
  if (selected.value !== null) return { kind: 'after', block: selected.value }
  const first = blockFields()[0]
  return first ? { kind: 'into', parent: null, field: first } : null
}
const resolvedTarget = computed(() => {
  void fields.value
  const target = effectiveTarget()
  return target
    ? resolveTarget(target, currentDoc(), legalityContext(), historySequence.value)
    : null
})
// An armed target that resolves to nothing (its block or gap is gone) is dropped, and said so.
// Watched from mount: the resolver reads helpers declared further down this setup.
onMounted(() =>
  watch(resolvedTarget, (resolved) => {
    if (resolved === null && insertTarget.value !== null) {
      insertTarget.value = null
      targetStale.value = true
      insertAttempt++
    }
  }),
)
const paletteTarget = computed(() =>
  insertTarget.value && resolvedTarget.value ? { label: resolvedTarget.value.label } : null,
)
function paletteClickable(slug: string): Legality {
  const at = resolvedTarget.value
  return at
    ? tilePreflight(slug, at.position, currentDoc(), legalityContext())
    : { ok: false, reason: 'no-slot', message: 'Nowhere to insert' }
}
function armInsertTarget(target: InsertTarget): void {
  insertTarget.value = target
  targetStale.value = false
  insertAttempt++
  inspectorTab.value = 'blocks'
}
/** The Block tab's slot rows: "Add" arms the Blocks tab into that slot of the selected block. */
function onInsertInto(field: string): void {
  const parent = selected.value
  if (parent === null) return
  armInsertTarget({ kind: 'into', parent, field })
}
// A tab can leave the strip under the reader: the Block tab goes with its selection (a delete
// on the stage, Escape). The pane then shows the first tab rather than nothing.
watch(inspectorTabs, (tabs) => {
  if (!tabs.some((tab) => tab.value === inspectorTab.value)) inspectorTab.value = 'content'
})
/** Clear the armed target; every selection change and every arming ends the attempt in flight. */
function clearInsertTarget(): void {
  insertTarget.value = null
  targetStale.value = false
  insertAttempt++
}
// A palette drag onto the stage (Phase C.1): the helper owns the gesture and the bridge's drop
// answer; the page judges its proposals like a stage session and applies the answered zone.
let paletteBlock: BlockInstance | null = null
const paletteDrag = createPaletteDrag({
  bridge,
  iframeRect: () => iframeEl.value?.getBoundingClientRect() ?? null,
  factory: blockFactory,
  coordinator,
  notify: notifyError,
  onClick: (slug) => void insertFromPalette(slug),
  onSession: (session, block) => {
    stageDragSession = session
    paletteBlock = block
  },
  onDrop: (zone) => {
    stageDragSession = null
    const block = paletteBlock
    paletteBlock = null
    void finishDrop(zone).then((ok) => {
      if (!ok || !block) return
      offerStructure(block)
      selectOne(block.id)
      fieldEditorRef.value?.selectBlockById(block.id)
      ringSelection()
      revealAfterPaint = block.id
      inspectorTab.value = 'block'
    })
  },
  onCancel: () => {
    stageDragSession = null
    paletteBlock = null
    coordinator.cancel()
  },
})

/**
 * The structure picker is offered only for a container the AUTHOR inserted, fresh and empty
 * (spec §6.1). A container arriving any other way — a preset's own child, a duplicate, a paste, a
 * version restore, a redo — never gets one, which is why this is called from the two explicit
 * insertion paths rather than from the place where operations are recorded.
 */
function offerStructure(block: BlockInstance): void {
  if (block.type !== 'container') return
  const content = block.data?.content
  if (Array.isArray(content) && content.length > 0) return
  picker.offer(block.id)
}

async function insertFromPalette(slug: string): Promise<void> {
  const intent = effectiveTarget()
  if (!intent) return
  const attempt = ++insertAttempt
  let block: BlockInstance
  try {
    block = await blockFactory.instance(slug)
  } catch (err) {
    notifyError(err, "Couldn't add block")
    return
  }
  if (attempt !== insertAttempt) return // cancelled or replaced while loading: no substitute
  const at = resolveTarget(intent, currentDoc(), legalityContext(), historySequence.value)
  if (!at) {
    insertTarget.value = null
    targetStale.value = true
    return
  }
  coordinator.begin('palette', { block })
  if (!(await finishDrop(at.position))) return // the target stays armed; the reason was shown
  offerStructure(block)
  insertTarget.value = null
  targetStale.value = false
  selectOne(block.id)
  fieldEditorRef.value?.selectBlockById(block.id)
  ringSelection()
  revealAfterPaint = block.id
  inspectorTab.value = 'block'
}
/**
 * A block inserted from the palette is not on the stage until the next apply paints it; once
 * it is, the stage scrolls to it and rings it — an insert below the fold is otherwise invisible.
 */
let revealAfterPaint: string | null = null
function revealInserted(): void {
  const id = revealAfterPaint
  if (id === null) return
  revealAfterPaint = null
  if (selection.value.ids.includes(id)) ringSelection()
  bridge.scrollTo(id)
}
async function applyDrop(ops: OperationBody[] | null): Promise<void> {
  if (!history || ops === null || ops.length === 0) return
  picker.onDocumentChange(ops)
  commitNow()
  history.beginTransaction()
  for (const body of ops) opsSinceApply.push(history.record(body))
  commitNow()
  await replayHistory()
  scheduleCommit(true)
}

function blockFields(): string[] {
  return schema.value.filter((f) => f.type === 'blocks').map((f) => f.name)
}
const snapshotFields = (): Record<string, unknown> =>
  JSON.parse(JSON.stringify(fields.value)) as Record<string, unknown>

const STRUCTURAL = new Set([
  'InsertBlock',
  'InsertBlocks',
  'RemoveBlock',
  'MoveBlock',
  'DuplicateBlock',
])
const TEXT_COMMIT_MS = 500

function commitNow(): void {
  if (commitTimer) {
    clearTimeout(commitTimer)
    commitTimer = null
  }
  history?.commit()
  refreshHistoryState()
}

/** Structure commits at once; text and settings after 500 ms idle, never mid-composition. */
function scheduleCommit(structural: boolean): void {
  if (commitTimer) clearTimeout(commitTimer)
  if (structural && !composing) {
    commitNow()
    return
  }
  commitTimer = setTimeout(() => {
    commitTimer = null
    if (composing) scheduleCommit(false)
    else commitNow()
  }, TEXT_COMMIT_MS)
}

watch(
  fields,
  () => {
    selection.value = reconcile(selection.value, selectionDoc(), selectionCtx)
    if (replaying) return
    const next = { fields: snapshotFields() }
    if (history === null) {
      history = createEditorHistory(next, { session: session.id, regionsOf, blockFields })
      refreshHistoryState()
      return
    }
    if (pendingRebase) {
      pendingRebase = false
      history.rebase(next)
      refreshHistoryState()
      return
    }
    const bodies = diffDocuments(history.document, next, regionsOf, blockFields)
    if (bodies.length === 0) return
    picker.onDocumentChange(bodies)
    const recorded = bodies.map((body) => history!.record(body))
    opsSinceApply.push(...recorded)
    scheduleCommit(bodies.some((b) => STRUCTURAL.has(b.type)))
    refreshHistoryState()
  },
  { deep: true, immediate: true },
)

/** Replay the history's document into the tree (undo/redo): recorded nowhere, applied to the stage. */
async function replayHistory(): Promise<void> {
  if (!history) return
  replaying = true
  fields.value = JSON.parse(JSON.stringify(history.document.fields)) as Record<string, unknown>
  await nextTick()
  replaying = false
  refreshHistoryState()
}

async function undo(): Promise<void> {
  if (!history) return
  commitNow()
  const entry = history.entries().find((e) => e.sequence === history!.currentSequence)
  if (!history.undo()) return
  if (entry)
    for (let i = entry.ops.length - 1; i >= 0; i--)
      opsSinceApply.push(...invertForApply(entry.ops[i]!))
  await replayHistory()
}

async function redo(): Promise<void> {
  if (!history) return
  const before = history.currentSequence
  if (!history.redo()) return
  const entry = history
    .entries()
    .find((e) => e.sequence > before && e.sequence <= history!.currentSequence)
  if (entry) opsSinceApply.push(...entry.ops)
  await replayHistory()
}

function invertForApply(op: Operation): Operation[] {
  return invertOperation(op)
}

function isTypingTarget(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null
  if (!el || typeof el.closest !== 'function') return false
  return (
    el.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]') !== null
  )
}

function onKeydown(e: KeyboardEvent): void {
  if (!(e.metaKey || e.ctrlKey) || e.altKey || e.key.toLowerCase() !== 'z') return
  if (isTypingTarget(e.target)) return // native undo inside inputs stays native
  e.preventDefault()
  void (e.shiftKey ? redo() : undo())
}
// The same two, asked for from the stage: a click there leaves focus in the iframe, so ⌘Z never
// reaches this window. The stage forwards it, outside text editing and its own form fields.
bridge.onHistory((direction) => void (direction === 'redo' ? redo() : undo()))
function onCompositionStart(): void {
  composing = true
}
function onCompositionEnd(): void {
  composing = false
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('compositionstart', onCompositionStart, true)
  window.addEventListener('compositionend', onCompositionEnd, true)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('compositionstart', onCompositionStart, true)
  window.removeEventListener('compositionend', onCompositionEnd, true)
  if (commitTimer) clearTimeout(commitTimer)
})

/**
 * The grant/patch matrix (editable-string-fields spec §4) — the ONE authority
 * both paths use: prose rich field -> 'rich'; schema string -> 'string';
 * schema plain text -> 'text'; everything else -> null (deny).
 */
function editableKindOf(id: string, field: string): EditKind | null {
  const slug = fieldEditorRef.value?.blockTypeOfBlock(id)
  const blockType = slug ? allBlockTypes.value?.find((t) => t.slug === slug) : undefined
  if (!blockType) return null
  const schemaField = (blockType.schema ?? []).find((f) => f.name === field)
  if (!schemaField) return null
  const type = schemaField.type
  const format = (schemaField as { format?: string }).format
  if (type === 'text' && format === 'rich') {
    return proseRichFieldName(blockType) === field ? 'rich' : null
  }
  if (type === 'string') return 'string'
  if (type === 'text') return 'text'
  return null
}

bridge.onEditRequest((id, field) => {
  const kind = editableKindOf(id, field)
  if (kind !== null) bridge.editGrant(id, field, kind)
})

bridge.onTextChanged((id, field, payload) => {
  // Re-validate (v3 pin, matrix-shaped): edit messages are requests, not
  // authority — the payload key must match the re-derived kind.
  const kind = editableKindOf(id, field)
  if (kind === null) return
  if (kind === 'rich' && typeof payload.html === 'string') {
    fieldEditorRef.value?.patchBlockDataById(id, field, payload.html)
  } else if (kind !== 'rich' && typeof payload.text === 'string') {
    fieldEditorRef.value?.patchBlockDataById(id, field, payload.text)
  }
})

// Session suppression keys off ACTUAL session starts (a failed grant never
// posts edit-start, so it can never wedge suppression); edit-end re-arms.
bridge.onEditStart(() => {
  editSessionActive.value = true
  cancelAutoTimer()
})
bridge.onEditEnd(() => {
  editSessionActive.value = false
  scheduleAuto() // all vetoes (auto-off/suspended/not-stale/…) live in the timer
})

// Scroll preservation (auto-apply spec §3): remember the stage's last position,
// restore after every reload's hello. Reset when the entry/locale changes.
let lastScrollY = 0
bridge.onScroll((y) => {
  lastScrollY = y
})
watch([uuid, locale], () => {
  lastScrollY = 0
  stageLoaded = false
  stageSynced = false // a new entry/locale gets its own reconciliation
})

// ── Apply loop (loop C spec §4): ephemeral render, nothing persisted ──────────
const save = useSaveDraft(uuid.value, () => locale.value, type.value)
const applying = ref(false)

// ── Auto-apply (auto-apply spec §1): a SCHEDULER over the one runApply core ──
const autoEnabled = ref(localStorage.getItem('thallo.canvas.auto_apply') !== '0')
const autoSuspended = ref(false) // session-local; never persisted
const editSessionActive = ref(false)
const applyQueued = ref(false) // the coalescing boolean — never a counter
let autoTimer: ReturnType<typeof setTimeout> | null = null

function cancelAutoTimer(): void {
  if (autoTimer) {
    clearTimeout(autoTimer)
    autoTimer = null
  }
}

// Trailing debounce with a MAX-WAIT: a change stream may DELAY the apply,
// never starve it. Anything touching fields more often than the debounce
// window (a browser extension re-emitting editor updates, a theme timer)
// would otherwise restart the timer forever — silently.
const AUTO_DEBOUNCE_MS = 800
const AUTO_MAX_WAIT_MS = 2500
let autoFirstScheduledAt = 0

function scheduleAuto(): void {
  const now = Date.now()
  if (autoTimer === null) autoFirstScheduledAt = now // a fresh burst starts the max-wait clock
  cancelAutoTimer()
  const delay = Math.min(
    AUTO_DEBOUNCE_MS,
    Math.max(50, autoFirstScheduledAt + AUTO_MAX_WAIT_MS - now),
  )
  autoTimer = setTimeout(() => {
    autoTimer = null
    if (!autoEnabled.value || autoSuspended.value || editSessionActive.value) return
    if (renderDisabled.value || mintFailed.value || previewToken.value === '') return
    if (!stageStale.value) return
    if (applying.value) {
      // No concurrent applies (spec pin): queue ONE follow-up and return.
      applyQueued.value = true
      return
    }
    void runApply(true)
  }, delay)
}

watch(
  fields,
  () => {
    metrics.input()
    // No pre-guard here: EVERY veto lives (and logs) in the timer callback —
    // a silent skip at this level made "auto didn't run" undiagnosable.
    scheduleAuto()
  },
  { deep: true },
)

function toggleAuto(): void {
  if (autoSuspended.value) {
    // Click-while-suspended clears suspension and keeps auto enabled.
    autoSuspended.value = false
    if (stageStale.value) scheduleAuto()
    return
  }
  autoEnabled.value = !autoEnabled.value
  localStorage.setItem('thallo.canvas.auto_apply', autoEnabled.value ? '1' : '0')
  if (!autoEnabled.value) cancelAutoTimer()
  else if (stageStale.value) scheduleAuto()
}

/** Applies the server has answered (accepted or refused): the proofs wait on it. */
let appliesAnswered = 0
// Test hooks for the browser proofs (admin/e2e, spec §5.6): read-only snapshots of history, the
// document, the accepted pair and the selection. Present only in an E2E build.
if (import.meta.env.VITE_E2E === '1') {
  ;(window as unknown as { __thalloBuilder: unknown }).__thalloBuilder = {
    snapshot: () => ({
      history: (history?.entries() ?? []).map((e) => ({
        sequence: e.sequence,
        transaction_id: e.transaction_id,
        ops: e.ops,
      })),
      currentSequence: history?.currentSequence ?? 0,
      document: snapshotFields(),
      accepted: accepted.value,
      selection: selection.value,
    }),
    applies: () => appliesAnswered,
    // The picker (container-layout spec §6). The proofs drive it directly because this harness's
    // stage is a captured page that never re-renders, so a container inserted now has no tiles
    // there; what the tiles DO is proven against the bridge asset in preview-bridge-dom.spec.
    structureOffers: () => lastStructureOffers,
    chooseStructure: (id: string, preset: string) => picker.choose(id, preset),
    skipStructure: (id: string) => picker.skip(id),
    gridFillStates: () => lastFillStates,
  }
}

/**
 * A rejected apply never becomes history (visual builder spec §5.3). When the refused ops are
 * one transaction that is still the unchanged tip and the accepted pair is the one the request
 * named, the transaction is discarded — inverted, no entry, no redo — and the stage stays as
 * displayed. Otherwise the edits stay local, the toast says to undo, and the next apply
 * retries with the current document (the refused ops ride again).
 */
async function reportRejection(
  e: ApiError,
  sentOps: Operation[],
  requestBase: number | null,
): Promise<void> {
  const messages = Object.values(e.fieldErrors)
  const detail = messages.length > 0 ? messages.join(' ') : e.message
  const transaction = sentOps[0]?.transaction_id ?? null
  const entries = history?.entries() ?? []
  const tip = entries[entries.length - 1]
  const tipIsRejected =
    history !== null &&
    transaction !== null &&
    sentOps.every((op) => op.transaction_id === transaction) &&
    history.activeTransaction === null &&
    tip !== undefined &&
    tip.transaction_id === transaction &&
    tip.sequence === history.currentSequence
  const baseUnchanged = (accepted.value?.revision ?? null) === requestBase
  if (tipIsRejected && baseUnchanged && history!.discardTip(transaction!)) {
    // The refused ops were re-queued for a retry; the discarded transaction never sends.
    for (let i = opsSinceApply.length - 1; i >= 0; i--) {
      if (opsSinceApply[i]!.transaction_id === transaction) opsSinceApply.splice(i, 1)
    }
    await replayHistory()
    warning('The server refused this change', detail)
    return
  }
  warning('The server refused this change', `${detail} Undo to revert.`)
}

/**
 * The ONE apply path (auto-apply spec §2): token retry, failure reset,
 * banners, and stash bookkeeping live HERE — auto vs manual only differ in
 * flush, suspension, and re-arm side effects.
 */
async function runApply(auto: boolean): Promise<void> {
  applyQueued.value = false
  applying.value = true
  let succeeded = false
  // Immutable payload snapshot (spec pin): the request AND lastApplied must
  // describe the SAME tree. Reading live fields after the await would stamp
  // lastApplied with edits the server never saw — stageStale would read
  // false and the coalesced follow-up would silently skip. Snapshot through
  // JSON (not structuredClone: fields.value is a Vue reactive proxy, which
  // structuredClone rejects with DataCloneError).
  const appliedJson = JSON.stringify(fields.value)
  const payload = JSON.parse(appliedJson) as Record<string, unknown>
  // The request names the pair we accepted and the operations since it (spec §3.5); the
  // sent ops leave the buffer only once the server accepted them.
  const sentOps = opsSinceApply.splice(0, opsSinceApply.length)
  // The base the FIRST request named: a rejection is judged against it (spec §5.3).
  const requestBase = accepted.value?.revision ?? null
  const options = () => ({
    epoch: accepted.value?.epoch ?? null,
    base_revision: accepted.value?.revision ?? null,
    operations: sentOps,
  })
  try {
    let result
    metrics.request()
    try {
      result = await applyPreview(uuid.value, locale.value, previewToken.value, payload, options())
    } catch (e: unknown) {
      if (e instanceof ApiError && (e.status === 410 || e.status === 403)) {
        // Dead token: re-mint ONCE and retry — TTL churn, never a failure
        // (suspension counts only the FINAL outcome, spec pin). The retry sends
        // the SAME snapshot: one run applies one tree.
        await mintAndLoad()
        result = await applyPreview(
          uuid.value,
          locale.value,
          previewToken.value,
          payload,
          options(),
        )
      } else if (e instanceof ApiError && apiErrorCode(e) === 'PREVIEW_REVISION_STALE') {
        // Our pair is not the server's: adopt the current pair, refresh the stage from the
        // accepted state, and retry ONCE from it (spec §3.5).
        const current = apiErrorDetails(e)?.current as RevisionPair | null | undefined
        accepted.value = current ?? null
        reloadStage()
        result = await applyPreview(
          uuid.value,
          locale.value,
          previewToken.value,
          payload,
          options(),
        )
      } else {
        opsSinceApply.unshift(...sentOps)
        throw e
      }
    }
    metrics.response()
    appliesAnswered++
    // A response from another epoch, or a revision not newer than accepted, is dropped.
    const stale =
      accepted.value !== null &&
      (result.epoch !== accepted.value.epoch || result.revision <= accepted.value.revision)
    if (stale) {
      opsSinceApply.unshift(...sentOps)
      return
    }
    accepted.value = { epoch: result.epoch, revision: result.revision }
    styleGeneration.value = result.style_generation
    noteStyleGeneration(result.style_generation)
    lastApplied.value = appliedJson
    await paintStage(result)
    succeeded = true
    if (!auto) autoSuspended.value = false // manual success re-arms auto
  } catch (e: unknown) {
    appliesAnswered++
    // Final failure: discard mirror-only DOM; keep dirty fields (v2/loop C pins).
    reloadStage()
    if (auto) autoSuspended.value = true // one banner now, then quiet until re-armed
    if (e instanceof ApiError && e.status === 422 && apiErrorCode(e) === null) {
      await reportRejection(e, sentOps, requestBase)
    } else if (e instanceof ApiError && apiErrorCode(e) === 'BLOCK_MIGRATION_IN_PROGRESS') {
      const blockType = String(apiErrorDetails(e)?.block_type ?? 'a block type')
      warning(
        `Block type “${blockType}” is being migrated`,
        'Apply is blocked until the migration completes — try again shortly.',
      )
    } else {
      notifyError(e, 'Couldn’t apply the preview')
    }
  } finally {
    applying.value = false
  }
  // Coalesced follow-up (spec §1): at most one, latest tree, success-path only.
  if (succeeded && applyQueued.value && stageStale.value && !editSessionActive.value) {
    void runApply(true)
  } else {
    applyQueued.value = false
  }
}

/**
 * Post-apply stage refresh (dom-patching spec §4): try the in-place patch —
 * the bridge fetches a REAL render of the working copy and swaps only
 * changed block wrappers. Only an explicit 'reload' answer (or the
 * composable's 4s timeout, which resolves 'reload') falls back to the full
 * iframe reload. 'busy' does nothing: the edit-end re-arm re-applies
 * whatever the stage missed.
 */
async function refreshStage(): Promise<StageRefreshMode> {
  const result = await bridge.stageRefresh()
  noteStyleGeneration(result.style_generation)
  if (result.mode === 'patched') {
    if (result.epoch !== null && result.revision !== null) {
      displayed.value = { epoch: result.epoch, revision: result.revision }
    }
    return result.mode
  }
  // A baseline mismatch or a fetch older than displayed refreshes from accepted state.
  if (result.mode === 'reload' || result.mode === 'stale') reloadStage()
  return result.mode
}

/**
 * The stage after an accepted apply (visual builder spec §3.5). With fragments, the stage
 * swaps the roots the server rendered — the patch names the accepted pair and the baseline
 * it expects — and anything but a swap (or a busy stage) falls back to the whole-page
 * refresh, counted as a fallback. Without fragments, the whole-page refresh as before. Every
 * painted path records its apply-to-paint sample.
 */
async function paintStage(result: ApplyPreviewResult): Promise<void> {
  if (result.fragments) {
    const swap = await bridge.stageFragments({
      epoch: result.epoch,
      revision: result.revision,
      baseline_epoch: result.epoch,
      baseline_revision: result.baseline,
      style_generation: result.style_generation,
      fragments: result.fragments,
    })
    noteStyleGeneration(swap.style_generation)
    if (swap.mode === 'patched') {
      if (swap.epoch !== null && swap.revision !== null) {
        displayed.value = { epoch: swap.epoch, revision: swap.revision }
      }
      afterPaint('fragments')
      revealInserted()
      return
    }
    if (swap.mode === 'busy') return
    metrics.fallback('fragments')
  }
  const mode = await refreshStage()
  if (mode !== 'busy') {
    afterPaint('page')
    revealInserted()
  }
}

function afterPaint(path: ApplyPath): void {
  const record = () => {
    metrics.paint(path)
    metricsSummary.value = metrics.summary()
  }
  if (typeof requestAnimationFrame === 'function') requestAnimationFrame(record)
  else record()
}
/**
 * Every carrier of the style generation (visual builder spec §4.3) — the apply response, a
 * stage-refreshed acknowledgement, a fragment swap — compares against the generation of the
 * class list the inspector resolved with; a difference refetches the list, and inherited
 * values show as re-resolving until it settles.
 */
const reResolving = ref(false)
function noteStyleGeneration(generation: number | null | undefined): void {
  const known = styleClassList.value?.generation ?? null
  if (typeof generation !== 'number' || known === null || generation === known) return
  reResolving.value = true
  void Promise.resolve(refetchStyleClassList()).finally(() => {
    reResolving.value = false
  })
}

// The site's style classes (visual builder spec §4.3): the block's ordered references resolve
// through them, and the Style tab names the class a value comes from.
const { data: styleClassList, refetch: refetchStyleClassList } = useStyleClasses()
const classOptions = computed(() =>
  (styleClassList.value?.classes ?? []).map((c) => ({
    id: c.id,
    name: c.name,
    archived: c.archived,
    locked: c.locked_by_job !== null,
  })),
)
const classNames = computed<Record<string, string>>(() => {
  const out: Record<string, string> = {}
  for (const c of styleClassList.value?.classes ?? []) out[c.id] = c.name
  return out
})
function classRefsFor(block: BlockInstance | null): StyleClassRef[] {
  const ids = Array.isArray(block?.settings?.classes) ? (block!.settings.classes as string[]) : []
  const byId = new Map((styleClassList.value?.classes ?? []).map((c) => [c.id, c]))
  const refs: StyleClassRef[] = []
  for (const id of ids) {
    const c = byId.get(id)
    if (c) refs.push({ id: c.id, style: c.style })
  }
  return refs
}

// ── Fill empty cells (container-layout spec §11.3) ─────────────────────────────
// One controller, one availability. The stage's button has no state of its own and the
// inspector's is a prop: both are written here, together, from the same call — so the stage can
// never offer a fill the inspector refuses. Declared after the tree's own watcher, so history
// already holds a change by the time this reads the document.
const gridFill = createGridFill({
  doc: currentDoc,
  legality: legalityContext,
  classesFor: (id) => classRefsFor(fieldEditorRef.value?.blockById(id) ?? null),
  activeBreakpoint: () => activeBreakpoint.value,
  factory: (slug) => blockFactory.instance(slug),
  commit: (ops) => applyDrop(ops),
  notify: (message) => warning(message),
  changed: () => refreshGridFill(),
})
/** The inspector's Fill button, for a single selected block; null when there is none. */
const selectedFill = ref<(FillAvailability & { preparing: boolean }) | null>(null)
/** The states as last published, for the proofs. */
let lastFillStates: StageFillState[] = []
/** The list as the stage last heard it: a message makes it re-mark every slot, so none is idle. */
let fillStatesOnStage = ''
function refreshGridFill(stageIsNew = false): void {
  const breakpoint = activeBreakpoint.value
  lastFillStates = gridFill.stageStates(breakpoint)
  const wire = JSON.stringify(lastFillStates)
  if (stageIsNew || wire !== fillStatesOnStage) {
    fillStatesOnStage = wire
    bridge.publishGridFill(lastFillStates)
  }
  const id = selection.value.ids.length === 1 ? selected.value : null
  selectedFill.value =
    id === null
      ? null
      : { ...gridFill.availability(id, breakpoint), preparing: gridFill.preparing(id) }
}
function fillCells(id: string | null): void {
  if (id !== null) void gridFill.fill(id, activeBreakpoint.value)
}
bridge.onGridFill((id) => fillCells(id))
watch(
  [fields, activeBreakpoint, selection, allBlockTypes, schema, styleClassList],
  () => refreshGridFill(),
  { deep: true, immediate: true },
)

function writeClasses(id: string, mutate: (ids: string[]) => string[]): void {
  writeSettings(id, (s) => {
    const ids = Array.isArray(s.classes) ? (s.classes as string[]) : []
    const next = mutate(ids)
    const { classes: _drop, ...rest } = s
    return next.length === 0 ? rest : { ...rest, classes: next }
  })
}
function onApplyClass(classId: string): void {
  if (selected.value === null) return
  writeClasses(selected.value, (ids) => (ids.includes(classId) ? ids : [...ids, classId]))
}
function onRemoveClass(classId: string): void {
  if (selected.value === null) return
  writeClasses(selected.value, (ids) => ids.filter((id) => id !== classId))
}
function onReorderClasses(ids: string[]): void {
  if (selected.value === null) return
  writeClasses(selected.value, () => ids)
}

/**
 * A detach materialises values (spec §4.4), so it never runs from a stale class list: the list
 * is refetched first and a generation change stops the action after re-resolving.
 */
async function freshStyleClasses(): Promise<boolean> {
  const known = styleClassList.value?.generation ?? null
  await refetchStyleClassList()
  const now = styleClassList.value?.generation ?? null
  if (known !== null && now !== null && known !== now) {
    warning('Style classes changed', 'Review the re-resolved values and try again.')
    return false
  }
  return true
}
function recordDetach(block: BlockInstance, classId: string): void {
  const style =
    typeof block.settings?.style === 'object' && block.settings.style !== null
      ? (block.settings.style as Record<string, unknown>)
      : {}
  const ids = Array.isArray(block.settings?.classes) ? (block.settings.classes as string[]) : []
  const index = ids.indexOf(classId)
  if (index === -1 || !history) return
  const type = allBlockTypes.value?.find((t) => t.slug === block.type)
  const toStyle = detachStyleClass(
    classRefsFor(block),
    style,
    classId,
    capabilityPaths(type?.style_capabilities),
  )
  const op = history.record({
    type: 'DetachStyleClass',
    block: block.id,
    class_id: classId,
    index,
    from_style: style,
    to_style: toStyle,
  })
  opsSinceApply.push(op)
}
async function onDetachClass(classId: string): Promise<void> {
  const block = selectedBlock.value
  if (!block || !history) return
  if (!(await freshStyleClasses())) return
  commitNow()
  history.beginTransaction()
  recordDetach(block, classId)
  commitNow()
  await replayHistory()
  scheduleCommit(false)
}
async function onDetachAll(): Promise<void> {
  const block = selectedBlock.value
  if (!block || !history) return
  if (!(await freshStyleClasses())) return
  commitNow()
  history.beginTransaction()
  const ids = Array.isArray(block.settings?.classes)
    ? [...(block.settings.classes as string[])]
    : []
  for (let i = ids.length - 1; i >= 0; i--) {
    // Each detach reads the block as the transaction has left it so far.
    const current = blockFromHistory(block.id)
    if (current) recordDetach(current, ids[i]!)
  }
  commitNow()
  await replayHistory()
  scheduleCommit(false)
}
const { create: createStyleClass, deleteUnreferenced: deleteStyleClass } = useStyleClassMutations()
const liftDialogOpen = ref(false)
const lifting = ref(false)
const selectedStyle = computed<Record<string, unknown>>(() => {
  const s = selectedBlock.value?.settings?.style
  return typeof s === 'object' && s !== null ? (s as Record<string, unknown>) : {}
})

/**
 * Save as style class (spec §4.5): the record is created first, the reference is inserted
 * last, and the lift is one transaction — every explicit declaration cleared, then the class
 * applied — committed only when the resolver confirms the appearance is unchanged. A lift that
 * cannot preserve appearance deletes its never-referenced record and reports; undo of the
 * transaction restores the block and never touches the record.
 */
async function saveAsStyleClass(name: string, description: string | null): Promise<void> {
  const block = selectedBlock.value
  if (!block || !history) return
  if (!(await freshStyleClasses())) return
  lifting.value = true
  try {
    const style = selectedStyle.value
    const declarations = liftedDeclarations(style)
    if (declarations.length === 0) return
    const created = await createStyleClass.mutateAsync({ name, description, style })
    const type = allBlockTypes.value?.find((t) => t.slug === block.type)
    const allowed = capabilityPaths(type?.style_capabilities)
    const lifted = { id: created.id, style: created.style }
    if (!liftPreservesAppearance(classRefsFor(block), style, lifted, allowed)) {
      await deleteStyleClass.mutateAsync(created.id)
      warning('Could not lift these settings without changing the page', 'Nothing was changed.')
      return
    }
    commitNow()
    history.beginTransaction()
    for (const d of declarations) {
      opsSinceApply.push(
        history.record({
          type: 'SetSetting',
          block: block.id,
          path: d.path,
          breakpoint: d.breakpoint,
          from: present(d.value),
          to: absent(),
        }),
      )
    }
    const ids = Array.isArray(block.settings?.classes) ? (block.settings.classes as string[]) : []
    opsSinceApply.push(
      history.record({
        type: 'ApplyStyleClass',
        block: block.id,
        class_id: created.id,
        index: ids.length,
      }),
    )
    commitNow()
    await replayHistory()
    scheduleCommit(false)
    liftDialogOpen.value = false
    success('Style class created', `“${name}” now carries these settings.`)
  } catch (e) {
    notifyError(e, 'Couldn’t save the style class')
  } finally {
    lifting.value = false
  }
}

/** The block as history's document holds it now (mid-transaction, before replay). */
function blockFromHistory(id: string): BlockInstance | null {
  if (!history) return null
  const field = history.applier.rootOf(history.document, id)
  if (field === null) return null
  return history.applier.listOps.findById(history.applier.rootList(history.document, field), id)
}

async function applyWorking(): Promise<void> {
  if (applying.value) return
  cancelAutoTimer()
  await bridge.editFlush() // commit any in-stage typing before reading fields
  await runApply(false)
}

// Save persists ONLY (loop C spec §4): the stage already shows the applied
// tree, and the server clears the stash — no re-mint, no reload on success.
const saving = ref(false)

async function saveDraftOnly({ quiet = false }: { quiet?: boolean } = {}): Promise<boolean> {
  saving.value = true
  commitNow() // save flushes pending edits first (spec §3.2)
  const sequence = history?.currentSequence ?? 0
  submittedSequence = sequence
  try {
    const result = (await save.mutateAsync({
      fields: fields.value,
      lock_version: lockVersion.value,
      preview_revision: accepted.value?.revision ?? null,
    })) as { data?: { preview_cleared?: boolean } } | undefined
    // The saved position is the SUBMITTED sequence, not the current one (spec §3.5).
    history?.markSaved(sequence)
    refreshHistoryState()
    if (result?.data?.preview_cleared === true) {
      accepted.value = null // the next apply starts a new epoch
      displayed.value = null
    }
    if (!quiet) success('Draft saved')
    return true
  } catch (e: unknown) {
    reloadStage() // discard optimistic mirrors — the stage falls back to last-applied truth
    // BYTE-MIRROR of the editor's onSave 409 branches.
    if (e instanceof ApiError && e.status === 409) {
      if (apiErrorCode(e) === 'BLOCK_MIGRATION_IN_PROGRESS') {
        const blockType = String(apiErrorDetails(e)?.block_type ?? 'a block type')
        warning(
          `Block type “${blockType}” is being migrated`,
          'Saving is blocked until the migration completes — try again shortly.',
        )
      } else {
        warning(
          'This draft changed elsewhere',
          'Reload to get the latest version before saving again.',
        )
      }
    } else {
      notifyError(e, 'Couldn’t save draft')
    }
    return false
  } finally {
    saving.value = false
  }
}

// Navbar publish (parity with the editor): publishing pins the SAVED draft,
// so a dirty canvas saves first and a failed save blocks the publish.
const publish = usePublish(uuid.value, locale.value, type.value)
const { data: entryLocaleSummaries } = useEntryLocales(uuid)
const isPublished = computed(() => {
  const summary = (entryLocaleSummaries.value ?? []).find((s) => s.locale === locale.value)
  return summary ? localeStatus(summary).key === 'published' : false
})
async function onPublish(): Promise<void> {
  // One action, one toast: the save publishing implies stays silent; failures still report.
  if (dirty.value && !(await saveDraftOnly({ quiet: true }))) return
  try {
    await publish.mutateAsync('publish')
    success(isPublished.value ? 'Updated' : 'Published')
  } catch (e) {
    notifyError(e, publishRefusalTitle(e))
  }
}
/**
 * A publish refused for a block's field: the path in the error names the block, so the block
 * is selected on the stage and in the inspector, and the toast says which block and field —
 * a block that paints nothing (an empty feature) is otherwise invisible on the canvas.
 */
function publishRefusalTitle(e: unknown): string {
  if (!(e instanceof ApiError) || e.status !== 422) return 'Couldn’t publish'
  const [path, message] = Object.entries(e.fieldErrors)[0] ?? []
  if (path === undefined) return 'Couldn’t publish'
  const at = blockAtValidationPath(fields.value, path)
  if (at === null) return `Couldn’t publish — ${path} ${message}`
  selectOne(at.id)
  fieldEditorRef.value?.selectBlockById(at.id)
  ringSelection()
  inspectorTab.value = 'block'
  const label = (allBlockTypes.value ?? []).find((t) => t.slug === at.type)?.label ?? at.type
  return `Couldn’t publish — ${label}: ${at.field} ${message}`
}

const stageStale = computed(() => JSON.stringify(fields.value) !== lastApplied.value)

/** Re-mint WITHOUT saving — the expired-token affordance (spec §6). */
function refreshPreview(): void {
  void mintAndLoad()
}

// The editor's "Preview in theme" twin: a FRESH mint opened in a new tab —
// the working-copy overlay rides the session (keyed entry+locale), so the
// tab shows exactly what the stage shows, full-site navigable. The stage's
// own token/iframe are untouched.
const openingPreview = ref(false)
async function openThemePreview(): Promise<void> {
  openingPreview.value = true
  try {
    const mint = await mintPreviewData(uuid.value, locale.value)
    if (mint.themeUrl) window.open(mint.themeUrl, '_blank', 'noopener')
    else warning('Theme preview unavailable — rendered delivery is disabled')
  } catch (e) {
    notifyError(e, 'Preview failed')
  } finally {
    openingPreview.value = false
  }
}

/**
 * Save-failure reset (stage-toolbar spec §2): discard mirror-only DOM by
 * remounting the iframe on the SAME URL (v-if unmount + remount = reload).
 * No re-mint — that stays behind the explicit Refresh preview affordance.
 */
function reloadStage(): void {
  paletteDrag.cancel('stage reloaded') // a palette session cannot survive the swap either
  if (stageDragSession !== null) {
    stageDragSession = null // the iframe goes with its drag session
    coordinator.cancel()
  }
  displayed.value = null // known again after the next in-place patch
  const src = iframeSrc.value
  if (!src) return
  iframeSrc.value = ''
  void nextTick(() => {
    iframeSrc.value = src
  })
}
</script>

<template>
  <UDashboardPanel id="entry-canvas">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            :to="`/content/${type}/${uuid}?locale=${locale}`"
            aria-label="Back to the form editor"
            data-test="canvas-back"
          />
        </template>
        <template #title>
          <span class="capitalize">{{ type }}</span>
          <UBadge size="xs" color="neutral" variant="subtle" class="ml-2"
            >Design · {{ locale }}</UBadge
          >
        </template>
        <template #default>
          <UFieldGroup size="sm">
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-monitor"
              aria-label="Desktop viewport"
              data-test="canvas-viewport-desktop"
              :class="{ 'bg-elevated': viewport === 'desktop' }"
              @click="setViewport('desktop')"
              :ui="{
                base: 'rounded-s',
              }"
            />
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-tablet"
              aria-label="Tablet viewport"
              data-test="canvas-viewport-tablet"
              :class="{ 'bg-elevated': viewport === 'tablet' }"
              @click="setViewport('tablet')"
              :ui="{
                base: 'rounded-none',
              }"
            />
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-smartphone"
              aria-label="Mobile viewport"
              data-test="canvas-viewport-mobile"
              :class="{ 'bg-elevated': viewport === 'mobile' }"
              @click="setViewport('mobile')"
              :ui="{
                base: 'rounded-none',
              }"
            />
          </UFieldGroup>
          <UFieldGroup size="sm">
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-undo-2"
              aria-label="Undo"
              title="Undo (⌘Z)"
              data-test="canvas-undo"
              :disabled="!historyState.canUndo"
              @click="undo()"
            />
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-redo-2"
              aria-label="Redo"
              title="Redo (⇧⌘Z)"
              data-test="canvas-redo"
              :disabled="!historyState.canRedo"
              @click="redo()"
            />
          </UFieldGroup>
          <!-- Preview actions, fused like the viewport group: refresh · Auto ·
               Apply. The stale chip rides the whole group. -->
          <UChip :show="stageStale" color="info" inset>
            <UFieldGroup size="sm">
              <UButton
                v-if="mintFailed || (!renderDisabled && iframeSrc)"
                variant="outline"
                color="neutral"
                icon="i-lucide-refresh-cw"
                aria-label="Refresh preview"
                title="Refresh preview"
                data-test="canvas-refresh-preview"
                :ui="{ base: 'rounded-none' }"
                @click="refreshPreview()"
              />
              <UButton
                :variant="autoEnabled && !autoSuspended ? 'soft' : 'outline'"
                :color="autoSuspended ? 'warning' : autoEnabled ? 'primary' : 'neutral'"
                :icon="autoEnabled && !autoSuspended ? 'i-lucide-zap' : 'i-lucide-zap-off'"
                :aria-label="
                  autoSuspended
                    ? 'Auto-apply paused after an error — click to resume'
                    : 'Toggle auto-apply'
                "
                data-test="canvas-auto-toggle"
                :ui="{ base: 'rounded-none' }"
                @click="toggleAuto()"
              >
                {{ autoSuspended ? 'Auto paused' : autoEnabled ? 'Auto' : 'Auto off' }}
              </UButton>
              <UButton
                :loading="applying"
                data-test="canvas-apply"
                :ui="{ base: 'rounded-ee' }"
                @click="applyWorking()"
              >
                Apply
              </UButton>
            </UFieldGroup>
          </UChip>
        </template>
        <template #right>
          <UButton
            v-if="!renderDisabled && iframeSrc"
            variant="outline"
            color="neutral"
            icon="i-lucide-eye"
            square
            :loading="openingPreview"
            aria-label="Open theme preview in a new tab"
            title="Open theme preview in a new tab"
            data-test="canvas-open-preview"
            @click="openThemePreview()"
          />
          <UChip :show="dirty" color="warning" inset>
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-save"
              square
              aria-label="Save draft"
              title="Save draft"
              :loading="saving"
              data-test="canvas-save"
              @click="
                () => {
                  void saveDraftOnly()
                }
              "
            />
          </UChip>
          <UButton
            :loading="publish.isLoading.value || saving"
            data-test="canvas-publish"
            @click="onPublish()"
          >
            {{ isPublished ? 'Update' : 'Publish' }}
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div
        v-if="renderDisabled"
        class="mx-auto max-w-md space-y-3 py-16 text-center"
        data-test="canvas-disabled"
      >
        <UIcon name="i-lucide-monitor-off" class="mx-auto size-8 text-muted" />
        <p class="font-medium">Rendered delivery is disabled</p>
        <p class="text-sm text-muted">
          The visual canvas previews your site's real theme output. Enable rendered delivery
          (RENDER_ENABLED) to use it — the form editor covers everything else.
        </p>
        <UButton variant="subtle" color="neutral" :to="`/content/${type}/${uuid}?locale=${locale}`">
          Open the form editor
        </UButton>
      </div>

      <div v-else class="flex h-full min-h-0 gap-4">
        <!-- A scrolling column keeps its content off the edge it scrolls at. The gutter is where
             macOS paints its overlay scrollbar — flush content sat underneath it — and it takes in
             the 2px by which a "declared here" dot overhangs the last breakpoint chip, which
             otherwise scrolled the whole panel sideways (overflow-y: auto makes overflow-x auto
             too). The panel is a gutter wider than its content, so the content keeps 24rem; a
             stable scrollbar gutter stops a classic scrollbar shifting it as it comes and goes. -->
        <aside
          class="w-[25rem] shrink-0 overflow-y-auto pe-4 [scrollbar-gutter:stable]"
          data-test="canvas-inspector"
        >
          <!-- Tabbed inspector (modern-default-theme spec §5a). unmount-on-hide
               MUST stay false: the bridge routes every stage intent through
               fieldEditorRef, which must never unmount on a tab switch. -->
          <UTabs
            v-model="inspectorTab"
            :items="inspectorTabs"
            :unmount-on-hide="false"
            size="xs"
            data-test="inspector-tabs"
            variant="link"
          >
            <template #block>
              <BlockInspector
                v-if="selectedBlock"
                :block="selectedBlock"
                :block-type="selectedBlockType"
                :blocks="selectedBlocks"
                :block-types="selectedBlockTypes"
                :schema="styleSchema ?? null"
                :classes="classRefsFor(selectedBlock)"
                :class-names="classNames"
                :class-options="classOptions"
                :re-resolving="reResolving"
                @save-as-class="liftDialogOpen = true"
                @apply-class="onApplyClass"
                @remove-class="onRemoveClass"
                @reorder-classes="onReorderClasses"
                @detach-class="onDetachClass"
                @detach-all="onDetachAll"
                :parent="selectedParent"
                :parent-type="selectedParentType"
                :parent-classes="classRefsFor(selectedParent)"
                :active-breakpoint="activeBreakpoint"
                :fill="selectedFill"
                @fill-cells="fillCells(selected)"
                @patch-data="onPatchData"
                @insert-into="onInsertInto"
                @set-setting="onSetSetting"
                @set-all="onSetAll"
                @set-advanced="onSetAdvanced"
                @update:active-breakpoint="onActiveBreakpoint"
              />
              <p v-else class="text-xs text-muted" data-test="block-inspector-empty">
                Select a block on the stage or in the outline.
              </p>
              <SaveAsStyleClassDialog
                v-model:open="liftDialogOpen"
                :style="selectedStyle"
                :saving="lifting"
                @confirm="saveAsStyleClass"
              />
            </template>
            <template #content>
              <FieldEditor
                ref="fieldEditorRef"
                v-model="fields"
                :schema="schema"
                palette-insert
                @insert-request="
                  (p: Position) =>
                    armInsertTarget({ kind: 'at', position: p, sequence: historySequence })
                "
              />
            </template>
            <template #blocks>
              <div class="pt-2">
                <BlocksPalette
                  :types="paletteTypes"
                  :target="paletteTarget"
                  :stale="targetStale"
                  :clickable="paletteClickable"
                  @insert="insertFromPalette"
                  @clear-target="clearInsertTarget"
                  @pointer-down="(slug: string, e: PointerEvent) => paletteDrag.begin(slug, e)"
                />
              </div>
            </template>
            <template #outline>
              <div class="pt-2" data-test="outline-tab">
                <CanvasOutline
                  :fields="fields"
                  :schema="schema"
                  :selected="selected"
                  :selected-ids="selection.ids"
                  @select="onOutlineSelect"
                  @move="moveBlockAndMirror"
                  @delete-request="(id: string) => openDeleteConfirm(id, null)"
                  @duplicate="duplicateAndMirror"
                  @deselect="onOutlineDeselect"
                  @drop="(id: string, zone: DropZone) => runDrop('outline', id, zone)"
                  @move-to="(id: string) => (moveToId = id)"
                  @insert-request="
                    (parent: string, slot: string) =>
                      armInsertTarget({ kind: 'into', parent, field: slot })
                  "
                />
                <MoveToDialog
                  :open="moveToId !== null"
                  :block-id="moveToId"
                  :doc="currentDoc()"
                  :legality="legalityContext()"
                  @update:open="(v: boolean) => (moveToId = v ? moveToId : null)"
                  @confirm="
                    (zone: DropZone) => {
                      const id = moveToId
                      moveToId = null
                      if (id !== null) runDrop('outline', id, zone)
                    }
                  "
                />
              </div>
            </template>
            <template v-if="seoEnabled" #seo>
              <div class="pt-2">
                <SeoPanel
                  :key="`seo-${uuid}-${locale}`"
                  :uuid="uuid"
                  :locale="locale"
                  :enabled="seoEnabled"
                />
              </div>
            </template>
            <template #versions>
              <div class="pt-2">
                <VersionsPanel
                  :key="`versions-${uuid}-${locale}`"
                  :uuid="uuid"
                  :locale="locale"
                  :type="type"
                />
              </div>
            </template>
            <template #page>
              <div class="space-y-5 pt-2" data-test="page-settings">
                <UFormField
                  label="Show page title"
                  help="Hide it when a hero block owns the page heading."
                >
                  <UFieldGroup>
                    <UButton
                      v-for="opt in [
                        { label: 'Theme default', value: 'default' },
                        { label: 'Show', value: 'show' },
                        { label: 'Hide', value: 'hide' },
                      ]"
                      :key="opt.value"
                      size="xs"
                      :variant="presShowTitle === opt.value ? 'solid' : 'outline'"
                      color="neutral"
                      :data-test="`pres-title-${opt.value}`"
                      @click="setPresShowTitle(opt.value)"
                    >
                      {{ opt.label }}
                    </UButton>
                  </UFieldGroup>
                </UFormField>
                <UFormField
                  label="Layout"
                  help="Full width lets hero and section bands bleed edge-to-edge."
                >
                  <UFieldGroup>
                    <UButton
                      v-for="opt in [
                        { label: 'Theme default', value: 'default' },
                        { label: 'Centered', value: 'centered' },
                        { label: 'Full width', value: 'full' },
                      ]"
                      :key="opt.value"
                      size="xs"
                      :variant="presLayout === opt.value ? 'solid' : 'outline'"
                      color="neutral"
                      :data-test="`pres-layout-${opt.value}`"
                      @click="setPresLayout(opt.value)"
                    >
                      {{ opt.label }}
                    </UButton>
                  </UFieldGroup>
                </UFormField>
                <UFormField
                  label="Header"
                  help="Hide the site header on this page (landing pages)."
                >
                  <UFieldGroup>
                    <UButton
                      v-for="opt in [
                        { label: 'Theme default', value: 'default' },
                        { label: 'Show', value: 'show' },
                        { label: 'Hide', value: 'hide' },
                      ]"
                      :key="opt.value"
                      size="xs"
                      :variant="presHeader === opt.value ? 'solid' : 'outline'"
                      color="neutral"
                      :data-test="`pres-header-${opt.value}`"
                      @click="setPresChrome('header', opt.value)"
                    >
                      {{ opt.label }}
                    </UButton>
                  </UFieldGroup>
                </UFormField>
                <UFormField label="Footer" help="Hide the site footer on this page.">
                  <UFieldGroup>
                    <UButton
                      v-for="opt in [
                        { label: 'Theme default', value: 'default' },
                        { label: 'Show', value: 'show' },
                        { label: 'Hide', value: 'hide' },
                      ]"
                      :key="opt.value"
                      size="xs"
                      :variant="presFooter === opt.value ? 'solid' : 'outline'"
                      color="neutral"
                      :data-test="`pres-footer-${opt.value}`"
                      @click="setPresChrome('footer', opt.value)"
                    >
                      {{ opt.label }}
                    </UButton>
                  </UFieldGroup>
                </UFormField>
                <p class="text-xs text-muted">
                  “Theme default” follows the theme’s settings; overrides save and publish with this
                  page.
                </p>
                <div
                  v-if="styleSchema"
                  class="space-y-3 border-t border-default pt-4"
                  data-test="page-styles"
                >
                  <div class="flex items-center justify-between gap-2">
                    <h4 class="text-[11px] font-semibold tracking-wide text-muted uppercase">
                      Styles
                    </h4>
                    <div class="flex gap-0.5" role="group" aria-label="Breakpoint">
                      <button
                        v-for="bp in ['base', 'md', 'lg'] as const"
                        :key="bp"
                        type="button"
                        class="relative rounded px-1.5 py-0.5 text-[10px]"
                        :class="
                          bp === activeBreakpoint
                            ? 'bg-primary text-inverted'
                            : 'text-muted hover:text-default'
                        "
                        :aria-pressed="bp === activeBreakpoint ? 'true' : 'false'"
                        :title="BREAKPOINT_LABELS[bp]"
                        :data-test="`page-breakpoint-${bp}`"
                        @click="onActiveBreakpoint(bp)"
                      >
                        {{ bp }}
                        <span
                          v-if="pageStyleDeclaredAt(bp)"
                          class="absolute -top-0.5 -right-0.5 size-1.5 rounded-full bg-warning"
                          aria-hidden="true"
                        />
                      </button>
                    </div>
                  </div>
                  <BoxField
                    label="Padding"
                    :sides="pageStyleSides('spacing.padding.')"
                    :style="pageStyle"
                    :classes="[]"
                    :active-breakpoint="activeBreakpoint"
                    :vocabulary="styleSchema.vocabulary"
                    @set="onPageStyleSet"
                    @set-all="onPageStyleSetAll"
                  />
                  <BoxField
                    label="Margin"
                    :sides="pageStyleSides('spacing.margin.')"
                    :style="pageStyle"
                    :classes="[]"
                    :active-breakpoint="activeBreakpoint"
                    :vocabulary="styleSchema.vocabulary"
                    @set="onPageStyleSet"
                    @set-all="onPageStyleSetAll"
                  />
                  <ResponsiveField
                    :def="pageBackgroundRow"
                    label="Background"
                    :style="pageStyle"
                    :classes="[]"
                    :active-breakpoint="activeBreakpoint"
                    :vocabulary="styleSchema.vocabulary"
                    hide-breakpoints
                    @set="onPageStyleSet"
                    @set-all="onPageStyleSetAll"
                    @update:active-breakpoint="onActiveBreakpoint"
                  />
                  <p class="text-xs text-muted">
                    The page’s own spacing and ground, around every block; the theme’s where unset.
                  </p>
                </div>
              </div>
            </template>
          </UTabs>
        </aside>

        <div
          ref="stageEl"
          class="relative min-w-0 flex-1 overflow-auto rounded-lg border border-default bg-elevated/40 p-3"
          data-test="canvas-stage"
        >
          <div class="mx-auto h-full transition-[width]" :style="{ width: stageWidth }">
            <iframe
              v-if="iframeSrc"
              ref="iframeEl"
              :src="iframeSrc"
              class="h-full min-h-[70vh] w-full rounded border border-default bg-white"
              title="Page preview"
              data-test="canvas-iframe"
              @load="onIframeLoad()"
            />
            <p v-else class="py-16 text-center text-sm text-muted">Starting preview…</p>
          </div>

          <!-- Apply-to-paint instrumentation (visual builder spec §3.5): development only. -->
          <div
            v-if="showMetrics && metricsSummary.some((m) => m.count > 0 || m.fallbacks > 0)"
            class="absolute bottom-2 right-2 z-10 rounded border border-default bg-default/90 px-2 py-1 font-mono text-[10px] leading-4 text-muted"
            data-test="apply-metrics"
          >
            <template v-for="m in metricsSummary" :key="m.path">
              <div v-if="m.count > 0 || m.fallbacks > 0" :data-test="`apply-metrics-${m.path}`">
                {{ m.path }} ×{{ m.count }} · request→paint
                {{ Math.round(m.requestToPaint.median) }}/{{ Math.round(m.requestToPaint.p95) }} ms
                · input→paint {{ Math.round(m.inputToPaint.median) }}/{{
                  Math.round(m.inputToPaint.p95)
                }}
                ms · fallbacks {{ m.fallbacks }}
              </div>
            </template>
          </div>

          <!-- Parent-side delete confirm (stage-toolbar spec §4): the bridge only
               requests; anchored to the toolbar's delete button when its rect rode along. -->
          <div
            v-if="deleteRequest"
            class="absolute z-10 w-fit rounded-lg border border-default bg-default p-3 shadow-lg"
            :class="deletePos ? '' : 'inset-x-0 top-3 mx-auto'"
            :style="deletePos ?? undefined"
            data-test="canvas-delete-confirm"
          >
            <p class="mb-2 text-sm font-medium">Delete this block?</p>
            <div class="flex justify-end gap-2">
              <UButton
                size="xs"
                variant="ghost"
                color="neutral"
                data-test="canvas-delete-cancel"
                @click="cancelDelete()"
              >
                Cancel
              </UButton>
              <UButton
                size="xs"
                color="error"
                data-test="canvas-delete-confirm-yes"
                @click="confirmDelete()"
              >
                Delete
              </UButton>
            </div>
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
