import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue'
import { MAX_BLOCK_DEPTH, useBlockTypes } from '@/queries/blockTypes'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { proseRichFieldName } from '@/fields/components/blocks/proseDetection'
import { useCanvasBridge } from '@/composables/useCanvasBridge'
import { createApplyMetrics, type ApplyPath } from '@/editor/applyMetrics'
import { createEditorHistory, type EditorHistory } from '@/editor/ops/history'
import { diffDocuments } from '@/editor/ops/diff'
import { newEditorSession } from '@/editor/ops/session'
import { absent, present } from '@/editor/ops/types'
import { readPath, setPath, settingSegments } from '@/editor/ops/apply'
import { useStyleSchema } from '@/queries/styleSchema'
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
import { resolveTarget, tilePreflight, type InsertTarget } from '@/editor/palette/target'
import { useBlockFactory } from '@/queries/blockFactory'
import { createPaletteDrag } from '@/editor/palette/usePaletteDrag'
import {
  checkInsertSequence,
  checkInsertSubtree,
  type Legality,
  type LegalityContext,
} from '@/editor/structure/legality'
import { instantiate, isPatternKey, patternSlug, usePatterns } from '@/queries/patterns'
import {
  EMPTY_SELECTION,
  extend,
  reconcile,
  single,
  toggle,
  type Selection,
} from '@/editor/selection'
import type { StyleClassRef } from '@/style/types'
import {
  activeBreakpoint,
  BREAKPOINT_OF_VIEWPORT,
  setActiveBreakpoint,
  STAGE_WIDTHS,
  VIEWPORT_OF_BREAKPOINT,
  type ViewportPreset,
} from '@/editor/breakpoint'
import type { Breakpoint, StyleValue } from '@/style/types'
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
import { toFieldDef } from '@/fields/normalize'
import type {
  ApplyPreviewResult,
  FieldEditorExposed,
  RevisionPair,
  StageHost,
  StageRenewal,
  StageSession,
} from './types'
import { StageRenewalAbandoned } from './types'

export interface StageEditorRefs {
  iframe: Ref<HTMLIFrameElement | null>
  fieldEditor: Ref<FieldEditorExposed | null>
  /** The scrolling stage container: the positioning context for panels anchored to the stage. */
  stage: Ref<HTMLElement | null>
}

/**
 * The stage editor (regions stage spec §5.2): everything the Design view does on its stage —
 * the bridge, history and the three revisions, the apply loop, selection, drag and drop, the
 * Blocks tab, the structure picker, Fill, style classes and in-place text — over a document the
 * host supplies and a preview session the host mints, applies and renews. Moved out of the Design
 * page as it was; the page keeps what only an entry has.
 */
export function useStageEditor(host: StageHost, refs: StageEditorRefs) {
  const { success, warning, error: notifyError } = useNotify()
  const schema = host.schema
  const iframeEl = refs.iframe
  const fieldEditorRef = refs.fieldEditor
  const stageEl = refs.stage

  const fields = ref<Record<string, unknown>>({})
  // What the stage currently shows (loop C §4): set at first hydration (the
  // initial render is the draft) and after every successful Apply.
  const lastApplied = ref('')
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
  /** Whether the host has hydrated the tree yet: a stage click waits for it. */
  let hydrated = false
  watch(
    host.initial,
    (initial) => {
      // Each new tree replaces the document (a load, a save's lock bump); the host decides when
      // one arrives, and never while edits made since a save are in flight (spec §3.5).
      if (initial === null) return
      hydrated = true
      pendingRebase = history !== null
      fields.value = { ...initial }
      // First hydration: the stage's initial render shows the tree (loop C §4) — unless a stale
      // stash overlays it, which the reconciliation apply corrects once the stage has loaded.
      if (lastApplied.value === '') {
        lastApplied.value = JSON.stringify(initial)
        maybeReconcileStash()
      }
    },
    { immediate: true, flush: 'sync' },
  )

  const dirty = computed(() => {
    if (history !== null) return historyState.value.dirty || historyState.value.pending
    const loaded = host.initial.value ?? {}
    return JSON.stringify(fields.value) !== JSON.stringify(loaded)
  })
  // ── Preview stage (spec §6) ────────────────────────────────────────────────────
  const iframeSrc = ref('')
  const previewToken = ref('')
  const renderDisabled = ref(false)
  const mintFailed = ref(false)
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

  /**
   * Take a session into the stage. A renewal that already applied the document on the new session
   * brings its own pair (`replacePair`); a plain mint keeps ours — a second editor starts from the
   * accepted state (spec §3.5), a re-mint keeps ours.
   */
  function adopt(mint: StageSession, replacePair: boolean): void {
    if (!mint.themeUrl) {
      // Rendered delivery disabled: the route LOADS and explains (spec §6) —
      // never an SPA-side 404.
      renderDisabled.value = true
      return
    }
    renderDisabled.value = false
    mintFailed.value = false
    previewToken.value = mint.token
    if (replacePair) accepted.value = mint.accepted
    else if (accepted.value === null && mint.accepted) accepted.value = mint.accepted
    // The stage iframe IS the design canvas: declare it (?canvas=1) so the
    // render pack annotates blocks. Review previews (open-in-new-tab, the
    // content form's eye button) load the plain token URL and render clean,
    // with live-page behaviors (autoplay, arrows, lightboxes) running.
    iframeSrc.value = mint.themeUrl + (mint.themeUrl.includes('?') ? '&' : '?') + 'canvas=1'
  }

  async function mintAndLoad(): Promise<void> {
    try {
      adopt(await host.mint(), false)
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
    if (!host.reconcileOnOpen) return
    if (stageSynced || !stageLoaded) return
    if (lastApplied.value === '' || previewToken.value === '') return // hydration/mint pending
    stageSynced = true
    void runApply(true)
  }

  const inspectorTab = ref('content')
  /** The committed history sequence, reactive: gap targets are pinned to it (Phase C.1). */
  const historySequence = ref(0)

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
  const stageWidth = computed(() => STAGE_WIDTHS[viewport.value])

  // ── Selection (spec §5) ────────────────────────────────────────────────────────
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
    selection.value =
      next.ids.length > 0 ? next : { ids: [id], parent: null, slot: null, anchor: id }
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
    host.schema.value.length > 0 && allBlockTypes.value !== undefined && hydrated

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
    return selected.value !== null
      ? (fieldEditorRef.value?.blockById(selected.value) ?? null)
      : null
  })
  /** The root field that owns the selected block, so the Block tab can show it with the field's own components. */
  const selectedBlocksHost = computed(() => {
    void fields.value // the tree is the dependency: a block's place changes with it
    return selected.value !== null
      ? (fieldEditorRef.value?.blocksHostFor(selected.value) ?? null)
      : null
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
  /** A part of the selected block (a links block's links): the part's own style record. */
  function onSetPartSetting(
    part: string,
    path: string,
    bp: Breakpoint | null,
    value: StyleValue | null,
  ): void {
    if (selected.value === null) return
    writeSettings(selected.value, (s) =>
      setPath(s, settingSegments(path, bp, part), value === null ? absent() : present(value)),
    )
  }
  function onSetPartAll(part: string, path: string, value: StyleValue): void {
    if (selected.value === null) return
    writeSettings(selected.value, (s) => {
      let next = s
      for (const bp of ['base', 'md', 'lg'] as Breakpoint[]) {
        next = setPath(next, settingSegments(path, bp, part), present(value))
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
    if (selected.value !== null)
      fieldEditorRef.value?.patchBlockDataById(selected.value, name, value)
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

  /**
   * The Style tab's Play. The stage holds motion still so a block can be edited; this replays the
   * selection's entrance or drift once, as a visitor would see it.
   */
  function playSelectedMotion(): void {
    for (const block of selectedBlocks.value) bridge.playMotion(block.id)
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
        selection.value = {
          ids: copies,
          parent: group.parent,
          slot: group.slot,
          anchor: copies[0]!,
        }
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
  // The section and page library rides the same palette. A section is ONE block, so under its
  // `pattern:` key it takes the palette's whole path — click, Enter, drag — with the library
  // standing in for the block factory; only what makes the block differs.
  const { data: patternData } = usePatterns()
  const patterns = computed(() => patternData.value ?? [])
  const patternBySlug = (slug: string) => patterns.value.find((p) => p.slug === slug) ?? null
  const paletteFactory = {
    async instance(key: string): Promise<BlockInstance> {
      if (!isPatternKey(key)) return blockFactory.instance(key)
      const block = patternBySlug(patternSlug(key))
      const made = block ? instantiate(block)[0] : undefined
      if (!made) throw new Error('That section is no longer in the library.')
      return made
    },
  }
  const paletteLabel = (key: string): string =>
    isPatternKey(key)
      ? (patternBySlug(patternSlug(key))?.label ?? 'Section')
      : (paletteTypes.value.find((t) => t.slug === key)?.label ?? key)
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
  const NOWHERE: Legality = { ok: false, reason: 'no-slot', message: 'Nowhere to insert' }
  function paletteClickable(slug: string): Legality {
    const at = resolvedTarget.value
    if (!at) return NOWHERE
    if (!isPatternKey(slug))
      return tilePreflight(slug, at.position, currentDoc(), legalityContext())
    // A section is judged as the tree it is — its depth counts from where it would land.
    const pattern = patternBySlug(patternSlug(slug))
    const block = pattern ? instantiate(pattern)[0] : undefined
    return block ? checkInsertSubtree(currentDoc(), at.position, block, legalityContext()) : NOWHERE
  }
  /** A page's sections, one after another from the target. */
  function pageInserts(slug: string, position: Position) {
    const pattern = patternBySlug(slug)
    return (pattern ? instantiate(pattern) : []).map((block, k) => ({
      position: { ...position, index: position.index + k },
      block,
    }))
  }
  function pageClickable(slug: string): Legality {
    const at = resolvedTarget.value
    if (!at) return NOWHERE
    const inserts = pageInserts(slug, at.position)
    return inserts.length ? checkInsertSequence(currentDoc(), inserts, legalityContext()) : NOWHERE
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
    factory: paletteFactory,
    labelOf: paletteLabel,
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
      block = await paletteFactory.instance(slug)
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
   * A starter page: its sections inserted one after another at the target, as ONE transaction — one
   * undo takes the whole page back out. Judged as a sequence first, so a page that does not fit
   * (a slot that refuses containers, the nesting cap) inserts nothing rather than half of itself.
   */
  async function insertPage(slug: string): Promise<void> {
    const intent = effectiveTarget()
    if (!intent) return
    insertAttempt++
    const at = resolveTarget(intent, currentDoc(), legalityContext(), historySequence.value)
    if (!at) {
      insertTarget.value = null
      targetStale.value = true
      return
    }
    const inserts = pageInserts(slug, at.position)
    if (inserts.length === 0) return
    const verdict = checkInsertSequence(currentDoc(), inserts, legalityContext())
    if (!verdict.ok) {
      warning('That page does not fit here', verdict.message)
      return
    }
    coordinator.cancel()
    const drop: OperationBody[] = inserts.map(({ position, block }) => ({
      type: 'InsertBlock' as const,
      position,
      block,
    }))
    // What the host brings along rides the SAME transaction as the blocks, so one undo takes the
    // whole page back out.
    const extra = host.pageInsert?.(inserts.map((i) => i.block)) ?? null
    if (extra) drop.push(...extra.ops)
    await applyDrop(drop)
    extra?.after?.()
    insertTarget.value = null
    targetStale.value = false
    const first = inserts[0]!.block
    selectOne(first.id)
    fieldEditorRef.value?.selectBlockById(first.id)
    ringSelection()
    revealAfterPaint = first.id
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

  /** The block whose text is being edited on the stage, or null: one text has one owner at a time. */
  const stageEditingId = ref<string | null>(null)

  bridge.onEditRequest((id, field) => {
    const kind = editableKindOf(id, field)
    if (kind === null) return
    // The stage takes over from the panel, and nothing has to be handed across: the panel's editor
    // writes every change as it is made, so there is nothing to flush, and the double-click that asked
    // for this moved the browser's focus into the stage, so the panel no longer holds a caret. The
    // panel goes read-only when the session starts (edit-start) and writable again when it ends.
    bridge.editGrant(id, field, kind)
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
  bridge.onEditStart((id) => {
    editSessionActive.value = true
    stageEditingId.value = typeof id === 'string' ? id : null
    cancelAutoTimer()
  })
  bridge.onEditEnd(() => {
    editSessionActive.value = false
    stageEditingId.value = null
    scheduleAuto() // all vetoes (auto-off/suspended/not-stale/…) live in the timer
  })

  // Scroll preservation (auto-apply spec §3): remember the stage's last position,
  // restore after every reload's hello. Reset when the entry/locale changes.
  let lastScrollY = 0
  bridge.onScroll((y) => {
    lastScrollY = y
  })

  // ── Apply loop (loop C spec §4): ephemeral render, nothing persisted ──────────
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
    // A session switch owns the stage: what is edited meanwhile waits for the new session.
    if (switchingNow) {
      applyQueued.value = true
      return
    }
    applyQueued.value = false
    applying.value = true
    let succeeded = false
    let renewed = false
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
        result = await host.apply(previewToken.value, payload, options())
      } catch (e: unknown) {
        if (e instanceof ApiError && (e.status === 410 || e.status === 403)) {
          // Dead token: the host renews ONCE — TTL churn, never a failure
          // (suspension counts only the FINAL outcome, spec pin). The retry sends
          // the SAME snapshot: one run applies one tree.
          const renewal = await renewWith(host.renew, sentOps, payload)
          if (!renewal.retryWithExistingPair) {
            // The host applied this snapshot on the new session: it is what the stage shows.
            lastApplied.value = appliedJson
            appliesAnswered++
            renewed = true
            return
          }
          result = await host.apply(previewToken.value, payload, options())
        } else if (e instanceof ApiError && apiErrorCode(e) === 'PREVIEW_REVISION_STALE') {
          // Our pair is not the server's: adopt the current pair, refresh the stage from the
          // accepted state, and retry ONCE from it (spec §3.5).
          const current = apiErrorDetails(e)?.current as RevisionPair | null | undefined
          accepted.value = current ?? null
          reloadStage()
          result = await host.apply(previewToken.value, payload, options())
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
      // A newer renewal owns the stage (a page switch): it applies the document itself.
      if (e instanceof StageRenewalAbandoned) return
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
      applying.value = switchingNow
      // Edits made while the session renewed apply once, with the adopted pair (spec §5.2).
      if (renewed && opsSinceApply.length > 0) void runApply(auto)
    }
    if (renewed) return
    // Coalesced follow-up (spec §1): at most one, latest tree, success-path only.
    if (succeeded && applyQueued.value && stageStale.value && !editSessionActive.value) {
      void runApply(true)
    } else {
      applyQueued.value = false
    }
  }

  /**
   * Renew the session through `renew`, given the whole document. The ops the document carries
   * stay queued until the renewal answers, so a failed one loses nothing; the iframe swaps only
   * once it has.
   */
  async function renewWith(
    renew: StageHost['renew'],
    sentOps: Operation[],
    payload: Record<string, unknown>,
  ): Promise<StageRenewal> {
    let renewal: StageRenewal
    try {
      renewal = await renew(payload)
    } catch (e) {
      opsSinceApply.unshift(...sentOps)
      throw e
    }
    adopt(renewal, !renewal.retryWithExistingPair)
    return renewal
  }

  /**
   * A renewal the host starts (a page switch): the same path a dead token takes, the document
   * applied on the new session and edits made meanwhile applied once afterwards.
   */
  async function switchSession(renew: StageHost['renew']): Promise<boolean> {
    cancelAutoTimer()
    const mine = ++switchSeq
    switchingNow = true
    applying.value = true
    const appliedJson = JSON.stringify(fields.value)
    const payload = JSON.parse(appliedJson) as Record<string, unknown>
    const sentOps = opsSinceApply.splice(0, opsSinceApply.length)
    let renewed = false
    try {
      const renewal = await renewWith(renew, sentOps, payload)
      if (renewal.retryWithExistingPair) {
        opsSinceApply.unshift(...sentOps)
        if (mine === switchSeq) switchingNow = false
        applying.value = false
        await runApply(true)
        return true
      }
      lastApplied.value = appliedJson
      renewed = true
      return true
    } catch (e) {
      if (!(e instanceof StageRenewalAbandoned)) notifyError(e, 'Couldn’t start the preview')
      return false
    } finally {
      // A newer switch still in flight keeps the stage busy until it answers.
      if (mine === switchSeq) {
        switchingNow = false
        if (applying.value) applying.value = false
        // Whatever was edited during the switch — queued, or still unsent — applies once now.
        const pending = opsSinceApply.length > 0 || applyQueued.value || stageStale.value
        if (renewed && pending) void runApply(true)
      }
    }
  }
  let switchSeq = 0
  /** A session switch is in flight: applies wait for it. */
  let switchingNow = false
  // A stage showing its expired page renews through the host, as a dead token does (spec §6.5).
  bridge.onSessionExpired(() => void switchSession(host.renew))

  /**
   * Start over from a fresh session and its tree (a Reload after a conflict): the document, its
   * history, the accepted pair and the stage are all replaced; nothing is applied.
   */
  function restart(session: StageSession, tree: Record<string, unknown>): void {
    cancelAutoTimer()
    opsSinceApply.splice(0, opsSinceApply.length)
    clearSelection()
    history = null // the tree watcher starts a fresh history from the new tree
    pendingRebase = false
    fields.value = JSON.parse(JSON.stringify(tree)) as Record<string, unknown>
    lastApplied.value = JSON.stringify(fields.value)
    displayed.value = null
    adopt(session, true)
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
  const { create: createStyleClass, deleteUnreferenced: deleteStyleClass } =
    useStyleClassMutations()
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

  const stageStale = computed(() => JSON.stringify(fields.value) !== lastApplied.value)

  /** Re-mint WITHOUT saving — the expired-token affordance (spec §6). */
  function refreshPreview(): void {
    void mintAndLoad()
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

  /** Forget the stage a new document replaces: its scroll, its load and its reconciliation. */
  function resetStage(): void {
    lastScrollY = 0
    stageLoaded = false
    stageSynced = false // a new document gets its own reconciliation
  }

  return {
    // the document and history
    fields,
    historyState,
    historySequence,
    dirty,
    stageStale,
    accepted,
    displayed,
    undo,
    redo,
    commitNow,
    currentSequence: () => history?.currentSequence ?? 0,
    hasHistory: () => history !== null,
    markSaved(sequence: number): void {
      history?.markSaved(sequence)
      refreshHistoryState()
    },
    snapshotFields,
    applyDrop,
    // the stage
    iframeSrc,
    renderDisabled,
    mintFailed,
    previewToken,
    onIframeLoad,
    remint: mintAndLoad,
    restart,
    refreshPreview,
    reloadStage,
    resetStage,
    switchSession,
    runApply,
    applyWorking,
    applying,
    autoEnabled,
    autoSuspended,
    toggleAuto,
    metricsSummary,
    showMetrics,
    viewport,
    setViewport,
    onActiveBreakpoint,
    stageWidth,
    // selection and the inspector
    inspectorTab,
    selection,
    selected,
    selectOne,
    ringSelection,
    clearSelection,
    allBlockTypes,
    styleSchema,
    selectedBlock,
    selectedBlocksHost,
    selectedBlockType,
    selectedBlocks,
    selectedBlockTypes,
    selectedParent,
    selectedParentType,
    onSetSetting,
    onSetAll,
    onSetAdvanced,
    onSetPartSetting,
    onSetPartAll,
    onPatchData,
    playSelectedMotion,
    onInsertInto,
    stageEditingId,
    // style classes
    classRefsFor,
    classNames,
    classOptions,
    reResolving,
    onApplyClass,
    onRemoveClass,
    onReorderClasses,
    onDetachClass,
    onDetachAll,
    liftDialogOpen,
    lifting,
    selectedStyle,
    saveAsStyleClass,
    // Fill
    selectedFill,
    fillCells,
    // the Outline
    onOutlineSelect,
    onOutlineDeselect,
    moveBlockAndMirror,
    duplicateAndMirror,
    openDeleteConfirm,
    runDrop,
    moveToId,
    currentDoc,
    legalityContext,
    deleteRequest,
    deletePos,
    cancelDelete,
    confirmDelete,
    // the Blocks tab
    armInsertTarget,
    paletteTypes,
    paletteTarget,
    targetStale,
    patterns,
    paletteClickable,
    pageClickable,
    insertPage,
    insertFromPalette,
    clearInsertTarget,
    paletteDrag,
  }
}

export type StageEditor = ReturnType<typeof useStageEditor>
