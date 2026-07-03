<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useContentTypes } from '@/queries/contentTypes'
import type { BlockType } from '@/queries/blockTypes'
import { useDraft, useSaveDraft } from '@/queries/drafts'
import { applyPreview, mintPreviewData } from '@/queries/preview'
import { useCanvasBridge } from '@/composables/useCanvasBridge'
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
watch(
  draft,
  (d) => {
    // Hydrate on load and after saves (lock bump); never clobber in-flight edits
    // from a background refetch of the SAME lock.
    if (d && d.lock_version !== hydratedLock) {
      fields.value = { ...d.fields }
      lockVersion.value = d.lock_version
      hydratedLock = d.lock_version
      // First hydration: the stage's initial render shows the draft (loop C §4).
      if (lastApplied.value === '') lastApplied.value = JSON.stringify(d.fields)
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
onBeforeUnmount(() => bridge.dispose())

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
  duplicateBlockById: (id: string) => { newId: string; idMap: Record<string, string> } | null
  deleteBlockById: (id: string) => boolean
  insertAfterById: (id: string, typeSlug: string) => string | null
  pickerTypesForBlock: (id: string) => BlockType[]
}
const fieldEditorRef = ref<FieldEditorExposed | null>(null)
const selected = ref<string | null>(null)

bridge.onBlockSelect((id) => {
  selected.value = id
  fieldEditorRef.value?.selectBlockById(id)
})

function onOutlineSelect(id: string): void {
  selected.value = id
  fieldEditorRef.value?.selectBlockById(id)
  bridge.highlight(id)
  bridge.scrollTo(id)
}

// ── Stage toolbar intents (stage-toolbar spec §2/§4): mutate through the
// FieldEditor (single tree authority), mirror ONLY after the commit. ──────────
bridge.onBlockMove((id, delta) => {
  const neighbor = fieldEditorRef.value?.moveBlockById(id, delta) ?? null
  if (neighbor) bridge.mirrorMove(id, neighbor)
})

bridge.onBlockDuplicate((id) => {
  const result = fieldEditorRef.value?.duplicateBlockById(id) ?? null
  if (result) {
    bridge.mirrorDuplicate(id, result.idMap)
    selected.value = result.newId
    fieldEditorRef.value?.selectBlockById(result.newId)
  }
})

// Delete is parent-confirmed (review pin): the bridge only ever REQUESTS.
const deleteRequest = ref<string | null>(null)
bridge.onBlockDeleteRequest((id) => {
  deleteRequest.value = id
})

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
// No mirror — the new block appears in the stage on the next Save & refresh.
const addAfterId = ref<string | null>(null)
const addAfterTypes = ref<BlockType[]>([])
bridge.onBlockAddAfter((id) => {
  addAfterTypes.value = fieldEditorRef.value?.pickerTypesForBlock(id) ?? []
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

// ── Apply loop (loop C spec §4): ephemeral render, nothing persisted ──────────
const save = useSaveDraft(uuid.value, () => locale.value, type.value)
const applying = ref(false)

async function applyWorking(): Promise<void> {
  applying.value = true
  try {
    try {
      await applyPreview(uuid.value, locale.value, previewToken.value, fields.value)
    } catch (e: unknown) {
      // Token died mid-session (expired/invalid): re-mint ONCE and retry (spec §4).
      if (e instanceof ApiError && (e.status === 410 || e.status === 403)) {
        await mintAndLoad()
        await applyPreview(uuid.value, locale.value, previewToken.value, fields.value)
      } else {
        throw e
      }
    }
    lastApplied.value = JSON.stringify(fields.value)
    reloadStage() // same-URL reload — the stash is behind the SAME token URL
  } catch (e: unknown) {
    // Apply-failure reset (review P1): the server rejected the working tree and
    // wrote NO stash — optimistic mirror DOM (move/delete/duplicate) must not
    // keep masquerading as applied. Reload the stage back to last-applied truth;
    // local dirty fields are kept.
    reloadStage()
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
          <UButtonGroup size="xs">
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
          </UButtonGroup>
          <UButton
            v-if="mintFailed || (!renderDisabled && iframeSrc)"
            variant="ghost"
            color="neutral"
            icon="i-lucide-refresh-cw"
            data-test="canvas-refresh-preview"
            @click="refreshPreview()"
          >
            Refresh preview
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
          <FieldEditor ref="fieldEditorRef" v-model="fields" :schema="schema" />
        </aside>

        <div class="relative min-w-0 flex-1 overflow-auto rounded-lg border border-default bg-elevated/40 p-3" data-test="canvas-stage">
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

          <!-- Parent-side delete confirm (stage-toolbar spec §4): the bridge only requests. -->
          <div
            v-if="deleteRequest"
            class="absolute inset-x-0 top-3 z-10 mx-auto w-fit rounded-lg border border-default bg-default p-3 shadow-lg"
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

          <!-- Add-after picker (stage-toolbar spec §5): the containing list's types. -->
          <div
            v-if="addAfterId"
            class="absolute inset-x-0 top-3 z-10 mx-auto w-64 rounded-lg border border-default bg-default p-2 shadow-lg"
            data-test="canvas-add-picker"
          >
            <p class="mb-1 px-1 text-xs font-semibold uppercase tracking-wide text-muted">
              Add block after (visible on next Apply)
            </p>
            <button
              v-for="t in addAfterTypes"
              :key="t.slug"
              class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-elevated"
              type="button"
              :data-test="`canvas-add-type-${t.slug}`"
              @click="chooseAddType(t.slug)"
            >
              <UIcon :name="t.icon || 'i-lucide-box'" />
              <span class="font-medium">{{ t.label }}</span>
            </button>
            <p v-if="!addAfterTypes.length" class="px-2 py-1.5 text-sm text-muted">
              No block types available here.
            </p>
            <div class="mt-1 flex justify-end">
              <UButton size="xs" variant="ghost" color="neutral" data-test="canvas-add-cancel" @click="cancelAddAfter()">
                Cancel
              </UButton>
            </div>
          </div>
        </div>
         <aside class="w-56 shrink-0 overflow-y-auto">
          <CanvasOutline
            :fields="fields"
            :schema="schema"
            :selected="selected"
            @select="onOutlineSelect"
          />
        </aside>
        
      </div>
    </template>
  </UDashboardPanel>
</template>
