<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { DropZone } from '@/editor/structure/coordinator'
import { activeBreakpoint, STAGE_FRAME_EDGE } from '@/editor/breakpoint'
import { useStageEditor } from '@/editor/stage/useStageEditor'
import type { FieldEditorExposed } from '@/editor/stage/types'
import { fetchLayoutSamples, type LayoutSample } from '@/queries/layouts'
import FieldEditor from '@/components/FieldEditor.vue'
import BlockInspector from '@/editor/inspector/BlockInspector.vue'
import SaveAsStyleClassDialog from '@/editor/inspector/SaveAsStyleClassDialog.vue'
import BlocksPalette from '@/editor/palette/BlocksPalette.vue'
import CanvasOutline from '@/pages/content/[type]/[uuid]/design/components/CanvasOutline.vue'
import MoveToDialog from '@/pages/content/[type]/[uuid]/design/components/MoveToDialog.vue'
import UnsavedChangesModal from '@/components/UnsavedChangesModal.vue'
import { createDirtyRegistry, useUnsavedGuard } from '@/composables/useSectionState'
import LayoutTopBar from '../components/LayoutTopBar.vue'
import LayoutFrameTab from '../components/LayoutFrameTab.vue'
import { SETTINGS_KEY, useLayoutHost } from '../useLayoutHost'

// A layout, edited on the stage (type layouts spec §6.2): the surface's frame around a published
// sample — or a placeholder — with the Design view's stage editing over the layout's own blocks and
// one Save for every page of the kind. The sample's content is context: the stage keeps it inert.
definePage({ meta: { requiresAuth: true, collapseSidebar: true } })

const route = useRoute()
const router = useRouter()
const surface = String(route.params.surface)
const target = String(route.params.target)

const layout = useLayoutHost({ surface, target })
const { session, saving, removing, conflict, retired, switching } = layout

const iframeEl = ref<HTMLIFrameElement | null>(null)
const fieldEditorRef = ref<FieldEditorExposed | null>(null)
const stageEl = ref<HTMLElement | null>(null)
const editor = useStageEditor(layout.host, {
  iframe: iframeEl,
  fieldEditor: fieldEditorRef,
  stage: stageEl,
})
layout.bind(editor)
// The stage applies every edit by itself, as on the Header & footer page.
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
  paletteClickable,
  insertFromPalette,
  clearInsertTarget,
  paletteDrag,
  autoSuspended,
  toggleAuto,
} = editor
const schema = layout.host.schema

// ── Required blocks (spec §3, §4.1): the primary body's Entry content block — or a surface's block
// without a field, the product page's Product buy box — is placed exactly once, so deleting it is refused
// with the reason; moving it is fine. ──
function find(list: unknown, id: string): BlockInstance | null {
  if (!Array.isArray(list)) return null
  for (const block of list as BlockInstance[]) {
    if (block.id === id) return block
    for (const value of Object.values(block.data ?? {})) {
      const inner = find(value, id)
      if (inner) return inner
    }
  }
  return null
}
function placedField(block: BlockInstance): string | null {
  const field = (block.data as Record<string, unknown> | undefined)?.field
  if (typeof field === 'string' && field !== '') return field
  return block.type === 'entry_content' ? 'body' : null
}
/** A block type's name as the palette shows it (a required block without a field is named so). */
function typeLabel(slug: string): string {
  return paletteTypes.value.find((t) => t.slug === slug)?.label ?? slug
}
/** Why a block cannot be deleted, or null when it can. */
function requiredReason(id: string): string | null {
  const block = find(fields.value.blocks, id)
  if (!block) return null
  const rule = session.value?.required.find(
    (r) => r.type === block.type && (r.field === undefined || r.field === placedField(block)),
  )
  if (!rule) return null
  const noun = session.value?.label.split(' — ')[0]?.toLowerCase() ?? 'pages'
  return `Every one of the ${noun} shows its ${rule.field ?? typeLabel(block.type)} here, so the layout keeps this block. Move it instead.`
}
const deleteRefusal = computed(() =>
  deleteRequest.value === null ? null : requiredReason(deleteRequest.value),
)

// ── Save is off while the layout is missing a required block (the server would refuse it). ──
const saveBlocked = computed<string | null>(() => {
  for (const rule of session.value?.required ?? []) {
    const holds = (list: unknown): boolean =>
      Array.isArray(list) &&
      (list as BlockInstance[]).some(
        (b) =>
          (b.type === rule.type && (rule.field === undefined || placedField(b) === rule.field)) ||
          Object.values(b.data ?? {}).some(holds),
      )
    if (!holds(fields.value.blocks)) {
      return rule.field !== undefined
        ? `The layout must show the ${rule.field} — add an Entry content block for it.`
        : `The layout must show the ${typeLabel(rule.type)} block — add it from the Blocks tab.`
    }
  }
  return null
})

// ── The inspector (spec §6.2): Block when a block is selected, Blocks (Fields first), Frame and
// Outline. ──
type InspectorTab = { value: string; slot: string; label?: string; icon?: string; name?: string }
const inspectorTabs = computed<InspectorTab[]>(() => [
  ...(selected.value !== null ? [{ label: 'Block', value: 'block', slot: 'block' }] : []),
  { label: 'Blocks', value: 'blocks', slot: 'blocks' },
  { label: 'Frame', value: 'frame', slot: 'frame' },
  { name: 'Outline', icon: 'i-lucide-list-tree', value: 'outline', slot: 'outline' },
])
inspectorTab.value = 'blocks'
watch(inspectorTabs, (tabs) => {
  if (!tabs.some((tab) => tab.value === inspectorTab.value)) inspectorTab.value = 'blocks'
})

const frameSettings = computed<Record<string, unknown>>(() => {
  const s = fields.value[SETTINGS_KEY]
  return typeof s === 'object' && s !== null && !Array.isArray(s)
    ? (s as Record<string, unknown>)
    : {}
})
function setFrameSettings(next: Record<string, unknown>): void {
  // Reassigned, so the tree watcher records the change as an edit undo can take back.
  fields.value = { ...fields.value, [SETTINGS_KEY]: next }
}

// ── The sample picker (spec §5.2): the published items, newest first. ──
const samples = ref<LayoutSample[]>([])
async function loadSamples(): Promise<void> {
  try {
    samples.value = (await fetchLayoutSamples(surface, target)).samples
  } catch {
    samples.value = [] // the picker hides; the stage still shows the session's sample
  }
}
void loadSamples()
const sampleOptions = computed(() => samples.value.map((s) => ({ label: s.label, value: s.id })))
const sampleValue = computed(() => layout.sample.value ?? session.value?.sample?.id)
function onSample(value: string): void {
  void layout.switchSample(value)
}

// ── Reset to starter (spec §6.2): the starter as the document, one undoable edit. ──
function resetToStarter(): void {
  const starter = session.value?.starterLayout ?? []
  fields.value = {
    ...fields.value,
    blocks: JSON.parse(JSON.stringify(starter)),
    [SETTINGS_KEY]: {},
  }
}

// ── Remove (spec §5.5): asks first, naming what is lost, then returns to the Layouts page. ──
const removeOpen = ref(false)
// A saved layout exists — loaded as one, or saved from this editor — so there is one to remove.
const canRemove = computed(() => layout.live.value)
async function confirmRemove(): Promise<void> {
  removeOpen.value = false
  if (await layout.remove()) {
    leaving = true
    await router.push('/layouts')
  }
}
const removeCopy = computed(() => {
  const noun = session.value?.label.split(' — ')[0]?.toLowerCase() ?? 'page'
  return `Every one of the ${noun} goes back to the theme’s design; your unsaved edits are discarded.`
})

// ── Leaving with unsaved edits (spec §6.2): the app's own guard. ──
let leaving = false
const registry = createDirtyRegistry()
onBeforeUnmount(
  registry.register({
    id: `layout-${surface}-${target}`,
    label: session.value?.label ?? 'Layout',
    blocked: computed(() => !leaving && !retired.value && (dirty.value || saving.value)),
  }),
)
const { leaveConfirm, resolveLeave } = useUnsavedGuard(registry)
</script>

<template>
  <UDashboardPanel id="layout-editor">
    <template #header>
      <UDashboardNavbar :title="session?.label ?? 'Layout'">
        <template #leading>
          <UButton
            to="/layouts"
            size="sm"
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            aria-label="Layouts"
          />
        </template>
        <template #default>
          <LayoutTopBar
            :viewport="viewport"
            :sample-options="sampleOptions"
            :sample="sampleValue"
            :reach="session?.reach ?? ''"
            :can-undo="historyState.canUndo"
            :can-redo="historyState.canRedo"
            :dirty="dirty"
            :saving="saving"
            :switching="switching"
            :conflict="conflict"
            :retired="retired"
            :save-blocked="saveBlocked"
            :can-remove="canRemove"
            :paused="autoSuspended"
            @update:viewport="setViewport"
            @update:sample="onSample"
            @undo="undo()"
            @redo="redo()"
            @save="layout.save()"
            @reload="layout.reload()"
            @reset-starter="resetToStarter()"
            @remove="removeOpen = true"
            @resume="toggleAuto()"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div
        v-if="renderDisabled"
        class="mx-auto max-w-md space-y-3 py-16 text-center"
        data-test="layout-disabled"
      >
        <UIcon name="i-lucide-monitor-off" class="mx-auto size-8 text-muted" />
        <p class="font-medium">Rendered delivery is disabled</p>
        <p class="text-sm text-muted">
          Layouts are edited on your site's real theme output. Turn on Rendered delivery under
          Extensions › Capabilities to use them.
        </p>
      </div>

      <div v-else class="flex h-full min-h-0 gap-4">
        <aside
          class="w-[25rem] shrink-0 overflow-y-auto pe-4 [scrollbar-gutter:stable]"
          data-test="canvas-inspector"
        >
          <!-- The tree's single authority, mounted out of sight as on the Header & footer page. -->
          <div class="hidden">
            <FieldEditor ref="fieldEditorRef" v-model="fields" :schema="schema" palette-insert />
          </div>
          <p
            v-if="session?.placeholder"
            class="mb-2 rounded border border-default bg-elevated/50 p-2 text-xs text-muted"
            data-test="layout-placeholder-notice"
          >
            Nothing is published here yet — the stage shows a placeholder. Real samples appear in
            the picker as soon as there are some.
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
                Select a block of the layout on the stage or in the outline.
              </p>
              <SaveAsStyleClassDialog
                v-model:open="liftDialogOpen"
                :style="selectedStyle"
                :saving="lifting"
                @confirm="saveAsStyleClass"
              />
            </template>
            <template #blocks>
              <div class="pt-2" data-test="layout-tab-blocks">
                <BlocksPalette
                  :types="paletteTypes"
                  :target="paletteTarget"
                  :stale="targetStale"
                  :clickable="paletteClickable"
                  lead-category="Fields"
                  @insert="insertFromPalette"
                  @clear-target="clearInsertTarget"
                  @pointer-down="(slug: string, e: PointerEvent) => paletteDrag.begin(slug, e)"
                />
              </div>
            </template>
            <template #frame>
              <div data-test="layout-tab-frame">
                <LayoutFrameTab :settings="frameSettings" @update:settings="setFrameSettings" />
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
              title="The layout on a sample page"
              data-test="layout-stage"
              @load="onIframeLoad()"
            />
            <p v-else class="py-16 text-center text-sm text-muted">Starting preview…</p>
          </div>

          <!-- Parent-side delete confirm; a required block's delete is refused with its reason. -->
          <div
            v-if="deleteRequest"
            class="absolute z-10 w-fit max-w-sm rounded-lg border border-default bg-default p-3 shadow-lg"
            :class="deletePos ? '' : 'inset-x-0 top-3 mx-auto'"
            :style="deletePos ?? undefined"
            data-test="canvas-delete-confirm"
          >
            <template v-if="deleteRefusal">
              <p class="mb-2 text-sm" data-test="layout-required-refusal">{{ deleteRefusal }}</p>
              <div class="flex justify-end">
                <UButton
                  size="xs"
                  variant="ghost"
                  color="neutral"
                  data-test="canvas-delete-cancel"
                  @click="cancelDelete()"
                >
                  OK
                </UButton>
              </div>
            </template>
            <template v-else>
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
            </template>
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="removeOpen" title="Remove this layout?" data-test="layout-remove-dialog">
    <template #body>
      <p class="text-sm">{{ removeCopy }}</p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton variant="ghost" color="neutral" @click="removeOpen = false">Keep it</UButton>
        <UButton
          color="error"
          :loading="removing"
          data-test="layout-remove-confirm"
          @click="confirmRemove()"
        >
          Remove layout
        </UButton>
      </div>
    </template>
  </UModal>

  <UnsavedChangesModal :state="leaveConfirm" @resolve="resolveLeave" />
</template>
