<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useContentTypes } from '@/queries/contentTypes'
import { useDraft, useSaveDraft } from '@/queries/drafts'
import { applyPreview, mintPreviewData } from '@/queries/preview'
import { readPath, setPath, settingSegments } from '@/editor/ops/apply'
import type { StylePropertyRow } from '@/queries/styleSchema'
import type { DropZone } from '@/editor/structure/coordinator'
import MoveToDialog from './components/MoveToDialog.vue'
import BlocksPalette from '@/editor/palette/BlocksPalette.vue'
import BoxField from '@/editor/inspector/controls/BoxField.vue'
import ResponsiveField from '@/editor/inspector/controls/ResponsiveField.vue'
import { activeBreakpoint, BREAKPOINT_LABELS, STAGE_FRAME_EDGE } from '@/editor/breakpoint'
import { holdsPageHeading } from '@/queries/patterns'
import SaveAsStyleClassDialog from '@/editor/inspector/SaveAsStyleClassDialog.vue'
import type { Breakpoint, StyleValue } from '@/style/types'
import BlockInspector from '@/editor/inspector/BlockInspector.vue'
import type { Position } from '@/editor/ops/types'
import { useNotify } from '@/composables/useNotify'
import { ApiError, apiErrorCode, apiErrorDetails } from '@/api/errors'
import { blockAtValidationPath } from '@/editor/validationPath'
import { toFieldDef } from '@/fields/normalize'
import type { ContentTypeField } from '@/queries/contentTypes'
import type { FieldDef } from '@/fields/types'
import FieldEditor from '@/components/FieldEditor.vue'
import SeoPanel from '../components/SeoPanel.vue'
import VersionsPanel from '../components/VersionsPanel.vue'
import { restoreOps } from '@/editor/restoreVersion'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { usePublish } from '@/queries/publish'
import { fetchRoutes, useRoutes } from '@/queries/routes'
import { useEntryLocales } from '@/queries/entries'
import { localeStatus } from '../components/localeStatus'
import CanvasOutline from './components/CanvasOutline.vue'
import { useStageEditor } from '@/editor/stage/useStageEditor'
import type { FieldEditorExposed, StageHost } from '@/editor/stage/types'

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
const lockVersion = ref(0)
let hydratedLock = -1
/** The history sequence the last save submitted: a save completing under newer edits keeps them. */
let submittedSequence = 0
/** The tree the stage editor hydrates from: the draft, on load and after every save's lock bump. */
const initial = ref<Record<string, unknown> | null>(null)

// ── The stage editor and its entry host (regions stage spec §5.2) ─────────────
// The stage editing lives in useStageEditor; this page is its host — the draft's preview session
// is minted and applied per entry and locale, and a dead token re-mints, loads and retries with the
// pair already held. The page keeps what only an entry has: the draft and its lock, save and
// publish, SEO, versions, page settings and the review preview.
const iframeEl = ref<HTMLIFrameElement | null>(null)
const fieldEditorRef = ref<FieldEditorExposed | null>(null)
const stageEl = ref<HTMLElement | null>(null)
const host: StageHost = {
  schema,
  initial,
  mint: () => mintPreviewData(uuid.value, locale.value),
  apply: (token, fields, options) => applyPreview(uuid.value, locale.value, token, fields, options),
  // The working-copy stash outlives canvas sessions: keyed by entry+locale (not token), cleared
  // only by saveDraft, TTL-bounded. An abandoned session's stash overlays the DRAFT on the next
  // open, so one initial apply of the hydrated tree overwrites it with truth.
  reconcileOnOpen: true,
  renew: async () => ({
    ...(await mintPreviewData(uuid.value, locale.value)),
    retryWithExistingPair: true,
  }),
  pageInsert: (blocks) => {
    // A starter page opens with its own h1; the theme's title above it would be a second one.
    if (!holdsPageHeading(blocks) || presentationOverride.value.show_title === false) return null
    const current = fields.value._presentation
    return {
      ops: [
        {
          type: 'SetPageSettings',
          field: '_presentation',
          from: current === undefined ? { present: false } : { present: true, value: current },
          to: { present: true, value: { ...presentationOverride.value, show_title: false } },
        },
      ],
      after: () =>
        success(
          'Page title hidden',
          'The page’s first section carries the heading. Show page title, on the Page tab, brings it back.',
        ),
    }
  },
}
const editor = useStageEditor(host, {
  iframe: iframeEl,
  fieldEditor: fieldEditorRef,
  stage: stageEl,
})
const {
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
  snapshotFields,
  applyDrop,
  iframeSrc,
  renderDisabled,
  mintFailed,
  onIframeLoad,
  refreshPreview,
  reloadStage,
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
  onPatchData,
  playSelectedMotion,
  onInsertInto,
  stageEditingId,
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
  selectedFill,
  fillCells,
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
} = editor

watch(
  draft,
  (d) => {
    // Hydrate on load and after saves (lock bump); never clobber in-flight edits
    // from a background refetch of the SAME lock — nor edits made while a save was in
    // flight (spec §3.5: a save of revision 10 completing after an edit to 11 leaves 11).
    if (d && d.lock_version !== hydratedLock) {
      const editedSinceSubmit =
        editor.hasHistory() && editor.currentSequence() !== submittedSequence
      lockVersion.value = d.lock_version
      hydratedLock = d.lock_version
      if (editedSinceSubmit) return
      initial.value = { ...d.fields }
    }
  },
  { immediate: true },
)

// ── Page settings tab (modern-default-theme spec §5a) ─────────────────────────
// _presentation is a reserved system key in the SAME fields tree: edits ride
// the deep watcher -> auto-apply -> stash -> render chain like any content
// change, and save/publish/version it with the draft. "Theme default" DELETES
// the key so the theme.json chain shows through.
const caps = useCapabilitiesStore()
const seoEnabled = computed(() => caps.isEnabled('thallo.seo'))
// The tabs for editing carry labels; Outline, SEO and Versions are icons, named by a tooltip and
// for screen readers, so the labels fit the panel even with a block selected (seven labels did
// not, and each was cut short). Outline's icon is the one a blocks field's outline toggle uses.
type InspectorTab = { value: string; slot: string; label?: string; icon?: string; name?: string }
const inspectorTabs = computed<InspectorTab[]>(() => [
  ...(selected.value !== null ? [{ label: 'Block', value: 'block', slot: 'block' }] : []),
  { label: 'Content', value: 'content', slot: 'content' },
  { label: 'Blocks', value: 'blocks', slot: 'blocks' },
  { label: 'Page', value: 'page', slot: 'page' },
  { name: 'Outline', icon: 'i-lucide-list-tree', value: 'outline', slot: 'outline' },
  ...(seoEnabled.value
    ? [{ name: 'SEO', icon: 'i-lucide-search', value: 'seo', slot: 'seo' }]
    : []),
  { name: 'Versions', icon: 'i-lucide-history', value: 'versions', slot: 'versions' },
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

// A tab can leave the strip under the reader: the Block tab goes with its selection (a delete
// on the stage, Escape). The pane then shows the first tab rather than nothing.
watch(inspectorTabs, (tabs) => {
  if (!tabs.some((tab) => tab.value === inspectorTab.value)) inspectorTab.value = 'content'
})

/**
 * Restore to draft (Versions tab): the version's content becomes the draft in one transaction, so
 * one undo takes it back out. Autosave keeps it as any edit is kept; nothing goes live.
 */
async function restoreVersionToDraft(v: {
  version: number | undefined
  fields: Record<string, unknown>
}) {
  const name = v.version === undefined ? 'That version' : `Version ${v.version}`
  const ops = restoreOps(snapshotFields(), v.fields)
  if (ops.length === 0) {
    success(`Your draft already matches ${name.charAt(0).toLowerCase()}${name.slice(1)}`)
    return
  }
  // Blocks the version does not have cannot stay selected.
  clearSelection()
  await applyDrop(ops)
  success(`${name} is in your draft`, 'Undo takes it back out. Publish to make it live.')
}

// A new entry or locale gets its own stage: scroll, load and reconciliation start over.
watch([uuid, locale], () => editor.resetStage())

const save = useSaveDraft(uuid.value, () => locale.value, type.value)

// Save persists ONLY (loop C spec §4): the stage already shows the applied
// tree, and the server clears the stash — no re-mint, no reload on success.
const saving = ref(false)

async function saveDraftOnly({ quiet = false }: { quiet?: boolean } = {}): Promise<boolean> {
  saving.value = true
  commitNow() // save flushes pending edits first (spec §3.2)
  const sequence = editor.currentSequence()
  submittedSequence = sequence
  try {
    const result = (await save.mutateAsync({
      fields: fields.value,
      lock_version: lockVersion.value,
      preview_revision: accepted.value?.revision ?? null,
    })) as { data?: { preview_cleared?: boolean } } | undefined
    // The saved position is the SUBMITTED sequence, not the current one (spec §3.5).
    editor.markSaved(sequence)
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
const { data: routeRows } = useRoutes(uuid)
const { data: entryLocaleSummaries } = useEntryLocales(uuid)
const isPublished = computed(() => {
  const summary = (entryLocaleSummaries.value ?? []).find((s) => s.locale === locale.value)
  return summary ? localeStatus(summary).key === 'published' : false
})
/**
 * Whether this page has a URL in the language being edited. Publishing succeeds without one and
 * the page then renders nowhere; the form editor saves the slug before publishing, and the
 * Design view has no slug field of its own, so it refuses instead.
 */
async function hasUrl(): Promise<boolean> {
  const rows = routeRows.value ?? (await fetchRoutes(uuid.value))
  return rows.some((r) => r.locale === locale.value && r.slug !== '')
}
async function onPublish(): Promise<void> {
  if (!(await hasUrl())) {
    warning(
      'Give this page a URL before publishing',
      'Without one it would be published but reachable nowhere. Set its slug in the editor’s Publishing panel.',
    )
    return
  }
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
          The visual canvas previews your site's real theme output. Turn on Rendered delivery under
          Extensions › Capabilities to use it — the form editor covers everything else.
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
            <template #leading="{ item }">
              <template v-if="item.name">
                <UTooltip :text="item.name" :content="{ side: 'bottom' }">
                  <UIcon
                    :name="item.icon!"
                    class="size-4 shrink-0"
                    aria-hidden="true"
                    :data-test="`inspector-tab-icon-${item.value}`"
                  />
                </UTooltip>
                <span class="sr-only">{{ item.name }}</span>
              </template>
            </template>
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
                can-play-motion
                @play-motion="playSelectedMotion"
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
                :blocks-host="selectedBlocksHost"
                :prose-locked="stageEditingId !== null && stageEditingId === selected"
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
                  :patterns="patterns"
                  :clickable="paletteClickable"
                  :page-clickable="pageClickable"
                  @insert-page="insertPage"
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
                  @restore-draft="restoreVersionToDraft"
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
              class="h-full min-h-[70vh] w-full"
              :class="STAGE_FRAME_EDGE"
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
