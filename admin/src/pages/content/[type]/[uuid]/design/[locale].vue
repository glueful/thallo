<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useContentTypes } from '@/queries/contentTypes'
import { useBlockTypes } from '@/queries/blockTypes'
import type { BlockType } from '@/queries/blockTypes'
import { proseRichFieldName } from '@/fields/components/blocks/proseDetection'
import { useDraft, useSaveDraft } from '@/queries/drafts'
import { applyPreview, mintPreviewData } from '@/queries/preview'
import { useCanvasBridge } from '@/composables/useCanvasBridge'
import type { BridgeAnchor, EditKind } from '@/composables/useCanvasBridge'
import { useNotify } from '@/composables/useNotify'
import { ApiError, apiErrorCode, apiErrorDetails } from '@/api/errors'
import { toFieldDef } from '@/fields/normalize'
import type { ContentTypeField } from '@/queries/contentTypes'
import type { FieldDef } from '@/fields/types'
import FieldEditor from '@/components/FieldEditor.vue'
import CanvasOutline from './components/CanvasOutline.vue'

// The visual canvas (visual-canvas spec §1): a FULL-SCREEN sibling of the entry
// editor — iframe stage (real theme render via a preview session), left outline,
// right inspector (the editor's exact FieldEditor), explicit Save & refresh.
// This page loads the draft INDEPENDENTLY and saves through the same endpoint
// with lock_version; the stale-lock 409 is the race boundary with the editor.
definePage({ meta: { requiresAuth: true } })

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
watch(
  draft,
  (d) => {
    // Hydrate on load and after saves (lock bump); never clobber in-flight edits
    // from a background refetch of the SAME lock.
    if (d && d.lock_version !== hydratedLock) {
      fields.value = { ...d.fields }
      lockVersion.value = d.lock_version
      hydratedLock = d.lock_version
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
    iframeSrc.value = mint.themeUrl
  } catch (e) {
    mintFailed.value = true
    notifyError(e, 'Couldn’t start the preview')
  }
}
void mintAndLoad()

function onIframeLoad(): void {
  bridge.hello()
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
const inspectorTabs = [
  { label: 'Content', value: 'content', slot: 'content' as const },
  { label: 'Page', value: 'page', slot: 'page' as const },
]

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

function patchPresentation(key: 'show_title' | 'layout', value: unknown): void {
  const next = { ...presentationOverride.value }
  if (value === undefined) delete next[key]
  else next[key] = value
  const nextFields = { ...fields.value }
  if (Object.keys(next).length === 0) delete nextFields._presentation
  else nextFields._presentation = next
  fields.value = nextFields // reassign: the deep watcher schedules auto-apply
}
function setPresShowTitle(v: string): void {
  patchPresentation('show_title', v === 'default' ? undefined : v === 'show')
}
function setPresLayout(v: string): void {
  patchPresentation('layout', v === 'default' ? undefined : v)
}

// Outline panel visibility — same affordance shape as the inspector's
// per-field outline rail toggle. Collapsed by default: the stage is the star.
const outlineOpen = ref(false)
function toggleOutline(): void {
  outlineOpen.value = !outlineOpen.value
}

// Viewport presets (spec §6): stage width only.
const viewport = ref<'desktop' | 'tablet' | 'mobile'>('desktop')
function setViewport(v: 'desktop' | 'tablet' | 'mobile'): void {
  viewport.value = v
}
const stageWidth = computed(
  () => ({ desktop: '100%', tablet: '768px', mobile: '390px' })[viewport.value],
)

// ── Selection (spec §5) ────────────────────────────────────────────────────────
interface FieldEditorExposed {
  selectBlockById: (id: string) => boolean
  moveBlockById: (id: string, delta: number) => { beforeId: string } | { afterId: string } | null
  moveBlockToById: (id: string, neighbor: { beforeId: string } | { afterId: string }) => boolean
  duplicateBlockById: (id: string) => { newId: string; idMap: Record<string, string> } | null
  deleteBlockById: (id: string) => boolean
  insertAfterById: (id: string, typeSlug: string) => string | null
  pickerTypesForBlock: (id: string) => BlockType[]
  patchBlockDataById: (id: string, field: string, value: unknown) => boolean
  blockTypeOfBlock: (id: string) => string | null
}
const fieldEditorRef = ref<FieldEditorExposed | null>(null)
const selected = ref<string | null>(null)

bridge.onBlockSelect((id) => {
  selected.value = id
  fieldEditorRef.value?.selectBlockById(id)
})

// Stage Escape (keyboard-shortcuts spec §3): the bridge already cleared its
// ring/toolbar — without this the parent's selection would go stale and the
// outline/inspector would lie.
bridge.onBlockDeselect(() => {
  selected.value = null
})

function onOutlineSelect(id: string): void {
  selected.value = id
  fieldEditorRef.value?.selectBlockById(id)
  bridge.highlight(id)
  bridge.scrollTo(id)
}

// ── Stage toolbar intents (stage-toolbar spec §2/§4): mutate through the
// FieldEditor (single tree authority), mirror ONLY after the commit. ──────────
// Shared intent handlers (polish batch §4): the outline's keyboard emits and
// the bridge's stage callbacks drive the SAME functions — no new mutation
// paths, just two front doors.
function moveBlockAndMirror(id: string, delta: 1 | -1): void {
  const neighbor = fieldEditorRef.value?.moveBlockById(id, delta) ?? null
  if (neighbor) bridge.mirrorMove(id, neighbor)
}

bridge.onBlockMove(moveBlockAndMirror)

bridge.onBlockMoveTo((id, neighbor) => {
  // The drag WAS the mirror: an accepted drop needs no message back — the
  // tree change rides auto-apply. A rejection must snap the stage back to
  // truth BEFORE anything else can run (honest-stage pin): fields were never
  // mutated, so stageStale is untouched and no auto-apply schedules.
  const ok = fieldEditorRef.value?.moveBlockToById(id, neighbor) ?? false
  if (!ok) reloadStage()
})

function duplicateAndMirror(id: string): void {
  const result = fieldEditorRef.value?.duplicateBlockById(id) ?? null
  if (result) {
    bridge.mirrorDuplicate(id, result.idMap)
    selected.value = result.newId
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
  selected.value = null
  bridge.highlight('')
}

function cancelDelete(): void {
  deleteRequest.value = null
}

function confirmDelete(): void {
  const id = deleteRequest.value
  deleteRequest.value = null
  if (id !== null && fieldEditorRef.value?.deleteBlockById(id)) {
    bridge.mirrorRemove(id)
    if (selected.value === id) selected.value = null
  }
}

// Add-after: parent-side picker over the CONTAINING list's rules (spec §5).
// No mirror — the new block appears in the stage on the next Apply.
const addAfterId = ref<string | null>(null)
const addAfterTypes = ref<BlockType[]>([])
const stageEl = ref<HTMLElement | null>(null)
// Inline top/left when the intent carried the + button's rect (anchored to the
// toolbar button); null = the centered fallback classes.
const addAfterPos = ref<{ top: string; left: string } | null>(null)

// Type-to-filter (same semantics as the editor's BlockInsertMenu): matches
// label/slug/description, Enter picks the first match, Escape cancels.
const addAfterFilter = ref('')
const filteredAddTypes = computed(() => {
  const q = addAfterFilter.value.trim().toLowerCase()
  if (q === '') return addAfterTypes.value
  return addAfterTypes.value.filter(
    (t) =>
      t.label.toLowerCase().includes(q) ||
      t.slug.toLowerCase().includes(q) ||
      (t.description ?? '').toLowerCase().includes(q),
  )
})

function onAddFilterKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape') {
    e.preventDefault()
    cancelAddAfter()
  }
  if (e.key === 'Enter') {
    e.preventDefault()
    const first = filteredAddTypes.value[0]
    if (first) chooseAddType(first.slug)
  }
}

// Autofocus the filter when the picker opens (jsdom-safe: focus() no-ops).
const vFocus = { mounted: (el: HTMLElement) => el.focus() }

bridge.onBlockAddAfter((id, anchor) => {
  addAfterTypes.value = fieldEditorRef.value?.pickerTypesForBlock(id) ?? []
  addAfterFilter.value = '' // fresh search per open
  addAfterPos.value = anchoredPos(anchor, 256) // the w-64 panel
  addAfterId.value = id
})

function cancelAddAfter(): void {
  addAfterId.value = null
}

function chooseAddType(slug: string): void {
  const id = addAfterId.value
  addAfterId.value = null
  const newId = id !== null ? (fieldEditorRef.value?.insertAfterById(id, slug) ?? null) : null
  if (newId !== null) selected.value = newId
}

// ── Edit-in-place (edit-in-place spec §4): grant prose blocks only; typed
// text patches the tree — no mirrors, the contenteditable IS the stage DOM.
const { data: allBlockTypes } = useBlockTypes()

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
const autoEnabled = ref(localStorage.getItem('lemma.canvas.auto_apply') !== '0')
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
  const delay = Math.min(AUTO_DEBOUNCE_MS, Math.max(50, autoFirstScheduledAt + AUTO_MAX_WAIT_MS - now))
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
  localStorage.setItem('lemma.canvas.auto_apply', autoEnabled.value ? '1' : '0')
  if (!autoEnabled.value) cancelAutoTimer()
  else if (stageStale.value) scheduleAuto()
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
  try {
    try {
      await applyPreview(uuid.value, locale.value, previewToken.value, payload)
    } catch (e: unknown) {
      // Dead token: re-mint ONCE and retry — TTL churn, never a failure
      // (suspension counts only the FINAL outcome, spec pin). The retry sends
      // the SAME snapshot: one run applies one tree.
      if (e instanceof ApiError && (e.status === 410 || e.status === 403)) {
        await mintAndLoad()
        await applyPreview(uuid.value, locale.value, previewToken.value, payload)
      } else {
        throw e
      }
    }
    lastApplied.value = appliedJson
    await refreshStage() // in-place patch when provable; reload fallback (dom-patching spec §4)
    succeeded = true
    if (!auto) autoSuspended.value = false // manual success re-arms auto
  } catch (e: unknown) {
    // Final failure: discard mirror-only DOM; keep dirty fields (v2/loop C pins).
    reloadStage()
    if (auto) autoSuspended.value = true // one banner now, then quiet until re-armed
    if (e instanceof ApiError && apiErrorCode(e) === 'BLOCK_MIGRATION_IN_PROGRESS') {
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
async function refreshStage(): Promise<void> {
  const mode = await bridge.stageRefresh()
  if (mode === 'reload') reloadStage()
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

async function saveDraftOnly(): Promise<void> {
  saving.value = true
  try {
    await save.mutateAsync({ fields: fields.value, lock_version: lockVersion.value })
    success('Draft saved')
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
  } finally {
    saving.value = false
  }
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
          <UBadge size="xs" color="neutral" variant="subtle" class="ml-2">Design · {{ locale }}</UBadge>
        </template>
        <template #right>
          <UFieldGroup size="xs">
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-monitor"
              aria-label="Desktop viewport"
              data-test="canvas-viewport-desktop"
              :class="{ 'bg-elevated': viewport === 'desktop' }"
              @click="setViewport('desktop')"
            />
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-tablet"
              aria-label="Tablet viewport"
              data-test="canvas-viewport-tablet"
              :class="{ 'bg-elevated': viewport === 'tablet' }"
              @click="setViewport('tablet')"
            />
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-smartphone"
              aria-label="Mobile viewport"
              data-test="canvas-viewport-mobile"
              :class="{ 'bg-elevated': viewport === 'mobile' }"
              @click="setViewport('mobile')"
            />
          </UFieldGroup>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-list-tree"
            aria-label="Toggle outline"
            data-test="canvas-outline-toggle"
            :class="{ 'bg-elevated': outlineOpen }"
            @click="toggleOutline()"
          />
          <UButton
            v-if="!renderDisabled && iframeSrc"
            variant="ghost"
            color="neutral"
            icon="i-lucide-eye"
            square
            :loading="openingPreview"
            aria-label="Open theme preview in a new tab"
            title="Open theme preview in a new tab"
            data-test="canvas-open-preview"
            @click="openThemePreview()"
          />
          <UButton
            v-if="mintFailed || (!renderDisabled && iframeSrc)"
            variant="ghost"
            color="neutral"
            icon="i-lucide-refresh-cw"
            square
            aria-label="Refresh preview"
            title="Refresh preview"
            data-test="canvas-refresh-preview"
            @click="refreshPreview()"
          />
          <UButton
            :variant="autoEnabled && !autoSuspended ? 'soft' : 'outline'"
            :color="autoSuspended ? 'warning' : autoEnabled ? 'primary' : 'neutral'"
            size="xs"
            :icon="autoEnabled && !autoSuspended ? 'i-lucide-zap' : 'i-lucide-zap-off'"
            :aria-label="autoSuspended ? 'Auto-apply paused after an error — click to resume' : 'Toggle auto-apply'"
            data-test="canvas-auto-toggle"
            @click="toggleAuto()"
          >
            {{ autoSuspended ? 'Auto paused' : autoEnabled ? 'Auto' : 'Auto off' }}
          </UButton>
          <UChip :show="stageStale" color="info" inset>
            <UButton :loading="applying" data-test="canvas-apply" @click="applyWorking()">
              Apply
            </UButton>
          </UChip>
          <UChip :show="dirty" color="warning" inset>
            <UButton
              variant="outline"
              color="neutral"
              :loading="saving"
              data-test="canvas-save"
              @click="saveDraftOnly()"
            >
              Save draft
            </UButton>
          </UChip>
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
          The visual canvas previews your site's real theme output. Enable rendered
          delivery (RENDER_ENABLED) to use it — the form editor covers everything else.
        </p>
        <UButton variant="subtle" color="neutral" :to="`/content/${type}/${uuid}?locale=${locale}`">
          Open the form editor
        </UButton>
      </div>

      <div v-else class="flex h-full min-h-0 gap-4">
       
        <aside class="w-96 shrink-0 overflow-y-auto" data-test="canvas-inspector">
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
            <template #content>
              <FieldEditor ref="fieldEditorRef" v-model="fields" :schema="schema" />
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
                <p class="text-xs text-muted">
                  “Theme default” follows the theme’s settings; overrides save
                  and publish with this page.
                </p>
              </div>
            </template>
          </UTabs>
        </aside>

        <div ref="stageEl" class="relative min-w-0 flex-1 overflow-auto rounded-lg border border-default bg-elevated/40 p-3" data-test="canvas-stage">
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
              <UButton size="xs" color="error" data-test="canvas-delete-confirm-yes" @click="confirmDelete()">
                Delete
              </UButton>
            </div>
          </div>

          <!-- Add-after picker (stage-toolbar spec §5): the containing list's types,
               anchored to the toolbar's + button when its rect rode the intent. -->
          <div
            v-if="addAfterId"
            class="absolute z-10 w-64 rounded-lg border border-default bg-default p-2 shadow-lg"
            :class="addAfterPos ? '' : 'inset-x-0 top-3 mx-auto'"
            :style="addAfterPos ?? undefined"
            data-test="canvas-add-picker"
          >
            <p class="mb-1 px-1 text-xs font-semibold uppercase tracking-wide text-muted">
              Add block after
            </p>
            <input
              v-focus
              v-model="addAfterFilter"
              type="text"
              placeholder="Filter blocks…"
              class="mb-1 w-full rounded border border-default bg-transparent px-2 py-1 text-sm outline-none"
              data-test="canvas-add-filter"
              @keydown="onAddFilterKeydown"
            />
            <div class="grid grid-cols-2 gap-1">
              <button
                v-for="t in filteredAddTypes"
                :key="t.slug"
                class="flex flex-col items-center gap-1 rounded px-2 py-1.5 text-center text-xs hover:bg-elevated"
                type="button"
                :data-test="`canvas-add-type-${t.slug}`"
                @click="chooseAddType(t.slug)"
              >
                <UIcon :name="t.icon || 'i-lucide-box'" class="size-4 text-muted" />
                <span class="truncate font-medium">{{ t.label }}</span>
              </button>
            </div>
            <p v-if="!filteredAddTypes.length" class="px-2 py-1.5 text-sm text-muted">
              No block types available here.
            </p>
            <div class="mt-1 flex justify-end">
              <UButton size="xs" variant="ghost" color="neutral" data-test="canvas-add-cancel" @click="cancelAddAfter()">
                Cancel
              </UButton>
            </div>
          </div>
        </div>
         <aside v-if="outlineOpen" class="w-56 shrink-0 overflow-y-auto">
          <CanvasOutline
            :fields="fields"
            :schema="schema"
            :selected="selected"
            @select="onOutlineSelect"
            @move="moveBlockAndMirror"
            @delete-request="(id: string) => openDeleteConfirm(id, null)"
            @duplicate="duplicateAndMirror"
            @deselect="onOutlineDeselect"
          />
        </aside>
        
      </div>
    </template>
  </UDashboardPanel>
</template>
