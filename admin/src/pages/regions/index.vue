<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRegions } from '@/queries/regions'
import { useEntries } from '@/queries/entries'
import { belongsIn, type SectionPlace } from '@/queries/patterns'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { DropZone } from '@/editor/structure/coordinator'
import { activeBreakpoint, STAGE_FRAME_EDGE } from '@/editor/breakpoint'
import { useStageEditor } from '@/editor/stage/useStageEditor'
import type { FieldEditorExposed } from '@/editor/stage/types'
import FieldEditor from '@/components/FieldEditor.vue'
import BlockInspector from '@/editor/inspector/BlockInspector.vue'
import SaveAsStyleClassDialog from '@/editor/inspector/SaveAsStyleClassDialog.vue'
import BlocksPalette from '@/editor/palette/BlocksPalette.vue'
import CanvasOutline from '@/pages/content/[type]/[uuid]/design/components/CanvasOutline.vue'
import MoveToDialog from '@/pages/content/[type]/[uuid]/design/components/MoveToDialog.vue'
import UnsavedChangesModal from '@/components/UnsavedChangesModal.vue'
import { createDirtyRegistry, useUnsavedGuard } from '@/composables/useSectionState'
import RegionsTopBar from './components/RegionsTopBar.vue'
import RegionSettingsTab from './components/RegionSettingsTab.vue'
import { REGION_SLUGS, useRegionHost, type RegionSlug } from './useRegionHost'

// The header and footer, edited on the stage (regions stage spec §3, §5.3): a real published page
// with the chrome from this editor's working copy, the Design view's stage editing over it, and
// one Save for both regions. The page body is context — the stage keeps it inert.
definePage({ meta: { requiresAuth: true, collapseSidebar: true } })

const { data: regionData } = useRegions()
const region = useRegionHost({ regions: regionData })
const { currentRegion, hidden, saving, conflict, switching } = region

const iframeEl = ref<HTMLIFrameElement | null>(null)
const fieldEditorRef = ref<FieldEditorExposed | null>(null)
const stageEl = ref<HTMLElement | null>(null)
const editor = useStageEditor(region.host, {
  iframe: iframeEl,
  fieldEditor: fieldEditorRef,
  stage: stageEl,
})
region.bind(editor)
// The stage applies every edit by itself: this page has no Apply button, and the Design view's
// Auto switch (remembered per browser) does not reach it.
editor.autoEnabled.value = true
const {
  fields,
  historyState,
  dirty,
  undo,
  redo,
  iframeSrc,
  renderDisabled,
  onIframeLoad,
  viewport,
  setViewport,
  onActiveBreakpoint,
  stageWidth,
  inspectorTab,
  selection,
  selected,
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
  replaceClickable,
  replaceWithPage,
  paletteClickable,
  insertFromPalette,
  clearInsertTarget,
  paletteDrag,
  autoSuspended,
  toggleAuto,
} = editor
const schema = region.host.schema

// ── The library (sections guide): the current region's own sections and templates, never a page
// body's. A template is the whole region: over blocks already there, it asks first. ──
const place = computed<SectionPlace>(() => ({ scope: 'region', region: currentRegion.value }))
const regionPatterns = computed(() => patterns.value.filter((p) => belongsIn(p, place.value)))
const templateClickable = (slug: string) => replaceClickable(slug, currentRegion.value)
const pendingTemplate = ref<{ slug: string; label: string; region: RegionSlug } | null>(null)
watch(currentRegion, () => (pendingTemplate.value = null))
function onInsertTemplate(slug: string): void {
  const target = currentRegion.value
  const blocks = fields.value[target]
  if (Array.isArray(blocks) && blocks.length > 0) {
    const label = patterns.value.find((p) => p.slug === slug)?.label ?? 'this template'
    pendingTemplate.value = { slug, label, region: target }
    return
  }
  void replaceWithPage(slug, target)
}
function confirmTemplate(): void {
  const pending = pendingTemplate.value
  pendingTemplate.value = null
  if (pending) void replaceWithPage(pending.slug, pending.region)
}

// ── The current region follows the selection (spec §3): a footer block selected is Footer. ──
function holds(list: unknown, id: string): boolean {
  if (!Array.isArray(list)) return false
  return (list as BlockInstance[]).some(
    (b) =>
      b.id === id ||
      Object.values(b.data ?? {}).some((value) => Array.isArray(value) && holds(value, id)),
  )
}
function regionOf(id: string): RegionSlug | null {
  return REGION_SLUGS.find((slug) => holds(fields.value[slug], id)) ?? null
}
watch(selected, (id) => {
  const owner = id === null ? null : regionOf(id)
  if (owner !== null) currentRegion.value = owner
})
function setCurrentRegion(next: RegionSlug): void {
  if (next === currentRegion.value) return
  // A selection in the other region does not follow the switch.
  if (selected.value !== null && regionOf(selected.value) !== next) editor.clearSelection()
  currentRegion.value = next
}

// ── The inspector (spec §3): Block when a block is selected, Blocks, Region, and Outline. ──
type InspectorTab = { value: string; slot: string; label?: string; icon?: string; name?: string }
const inspectorTabs = computed<InspectorTab[]>(() => [
  ...(selected.value !== null ? [{ label: 'Block', value: 'block', slot: 'block' }] : []),
  { label: 'Blocks', value: 'blocks', slot: 'blocks' },
  { label: 'Region', value: 'region', slot: 'region' },
  { name: 'Outline', icon: 'i-lucide-list-tree', value: 'outline', slot: 'outline' },
])
inspectorTab.value = 'blocks'
watch(inspectorTabs, (tabs) => {
  if (!tabs.some((tab) => tab.value === inspectorTab.value)) inspectorTab.value = 'blocks'
})

/** The current region's settings, as the document holds them. */
const regionSettings = computed<Record<string, unknown>>(() => {
  const s = fields.value[`_region_${currentRegion.value}`]
  return typeof s === 'object' && s !== null && !Array.isArray(s)
    ? (s as Record<string, unknown>)
    : {}
})
function setRegionSettings(next: Record<string, unknown>): void {
  // Reassigned, so the tree watcher records the change as an edit undo can take back.
  fields.value = { ...fields.value, [`_region_${currentRegion.value}`]: next }
}
const regionCapabilities = computed(
  () => regionData.value?.find((r) => r.slug === currentRegion.value)?.style_capabilities ?? [],
)
/** The Outline shows the current region's tree. */
const outlineSchema = computed(() => schema.value.filter((f) => f.name === currentRegion.value))

// ── The page picker (spec §3): published pages, the homepage by default. ──
const HOME = '@home'
const { data: pageRows } = useEntries('page', 1, 100, undefined)
const pageOptions = computed(() => [
  { label: 'Homepage', value: HOME },
  ...(pageRows.value?.entries ?? [])
    .filter((row) => row.status === 'published')
    .map((row) => ({ label: row.display_title, value: row.uuid })),
])
const pageValue = computed(() => region.page.value ?? HOME)
function onPage(value: string): void {
  void region.switchPage(value === HOME ? undefined : value)
}

// ── Leaving with unsaved edits (spec §5.3): the app's own guard. ──
const registry = createDirtyRegistry()
onBeforeUnmount(
  registry.register({
    id: 'regions',
    label: 'Header & footer',
    blocked: computed(() => dirty.value || saving.value),
  }),
)
const { leaveConfirm, resolveLeave } = useUnsavedGuard(registry)
</script>

<template>
  <UDashboardPanel id="regions">
    <template #header>
      <UDashboardNavbar title="Header & footer">
        <template #default>
          <RegionsTopBar
            :current-region="currentRegion"
            :viewport="viewport"
            :page-options="pageOptions"
            :page="pageValue"
            :can-undo="historyState.canUndo"
            :can-redo="historyState.canRedo"
            :dirty="dirty"
            :saving="saving"
            :switching="switching"
            :conflict="conflict"
            :paused="autoSuspended"
            @update:current-region="setCurrentRegion"
            @update:viewport="setViewport"
            @update:page="onPage"
            @undo="undo()"
            @redo="redo()"
            @save="region.save()"
            @reload="region.reload()"
            @resume="toggleAuto()"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div
        v-if="renderDisabled"
        class="mx-auto max-w-md space-y-3 py-16 text-center"
        data-test="regions-disabled"
      >
        <UIcon name="i-lucide-monitor-off" class="mx-auto size-8 text-muted" />
        <p class="font-medium">Rendered delivery is disabled</p>
        <p class="text-sm text-muted">
          The header and footer are edited on your site's real theme output. Turn on Rendered
          delivery under Extensions › Capabilities to use it.
        </p>
      </div>

      <div v-else class="flex h-full min-h-0 gap-4">
        <!-- The Design view's inspector column, for the same reasons: a stable gutter keeps chips
             and badges clear of the scrollbar and stops the panel scrolling sideways. -->
        <aside
          class="w-[25rem] shrink-0 overflow-y-auto pe-4 [scrollbar-gutter:stable]"
          data-test="canvas-inspector"
        >
          <!-- The tree's single authority: the stage routes every intent through it, so it stays
               mounted, out of sight — the stage and the Outline are how the regions are edited. -->
          <div class="hidden">
            <FieldEditor ref="fieldEditorRef" v-model="fields" :schema="schema" palette-insert />
          </div>
          <p
            v-if="hidden.header || hidden.footer"
            class="mb-2 rounded border border-default bg-elevated/50 p-2 text-xs text-muted"
            data-test="regions-hidden-notice"
          >
            This page hides its
            {{
              hidden.header && hidden.footer
                ? 'header and footer'
                : hidden.header
                  ? 'header'
                  : 'footer'
            }}
            in its own settings; pick another page to see it on the stage.
          </p>
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
                :section-place="place"
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
                @set-part-setting="onSetPartSetting"
                @set-part-all="onSetPartAll"
                @update:active-breakpoint="onActiveBreakpoint"
              />
              <p v-else class="text-xs text-muted" data-test="block-inspector-empty">
                Select a header or footer block on the stage or in the outline.
              </p>
              <SaveAsStyleClassDialog
                v-model:open="liftDialogOpen"
                :style="selectedStyle"
                :saving="lifting"
                @confirm="saveAsStyleClass"
              />
            </template>
            <template #blocks>
              <div class="space-y-2 pt-2" data-test="regions-tab-blocks">
                <div
                  v-if="pendingTemplate"
                  class="space-y-2 rounded-md border border-warning/40 bg-warning/10 p-2 text-xs"
                  role="alertdialog"
                  data-test="template-replace"
                >
                  <p>
                    Replace the whole {{ pendingTemplate.region }} with
                    <strong>{{ pendingTemplate.label }}</strong
                    >? Undo brings it back.
                  </p>
                  <div class="flex justify-end gap-1">
                    <UButton
                      size="xs"
                      variant="ghost"
                      color="neutral"
                      data-test="template-replace-keep"
                      @click="pendingTemplate = null"
                    >
                      Keep
                    </UButton>
                    <UButton
                      size="xs"
                      data-test="template-replace-confirm"
                      @click="confirmTemplate"
                    >
                      Replace
                    </UButton>
                  </div>
                </div>
                <BlocksPalette
                  :types="paletteTypes"
                  :target="paletteTarget"
                  :stale="targetStale"
                  :patterns="regionPatterns"
                  :clickable="paletteClickable"
                  :page-clickable="templateClickable"
                  @insert-page="onInsertTemplate"
                  @insert="insertFromPalette"
                  @clear-target="clearInsertTarget"
                  @pointer-down="(slug: string, e: PointerEvent) => paletteDrag.begin(slug, e)"
                />
              </div>
            </template>
            <template #region>
              <div data-test="regions-tab-region">
                <RegionSettingsTab
                  :key="currentRegion"
                  :region="currentRegion"
                  :settings="regionSettings"
                  :capabilities="regionCapabilities"
                  :active-breakpoint="activeBreakpoint"
                  @update:settings="setRegionSettings"
                  @update:active-breakpoint="onActiveBreakpoint"
                />
              </div>
            </template>
            <template #outline>
              <div class="pt-2" data-test="outline-tab">
                <CanvasOutline
                  :fields="fields"
                  :schema="outlineSchema"
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
              title="Header and footer on a page"
              data-test="regions-stage"
              @load="onIframeLoad()"
            />
            <p v-else class="py-16 text-center text-sm text-muted">Starting preview…</p>
          </div>

          <!-- Parent-side delete confirm, as on the Design view: the stage only requests. -->
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

  <UnsavedChangesModal :state="leaveConfirm" @resolve="resolveLeave" />
</template>
