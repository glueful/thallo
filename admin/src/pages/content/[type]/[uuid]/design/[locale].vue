<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useContentTypes } from '@/queries/contentTypes'
import { useDraft, useSaveDraft } from '@/queries/drafts'
import { mintPreviewData } from '@/queries/preview'
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
const fieldEditorRef = ref<{ selectBlockById: (id: string) => boolean } | null>(null)
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

// ── Apply loop (spec §6): explicit save -> re-mint -> reload ──────────────────
const save = useSaveDraft(uuid.value, () => locale.value, type.value)
const applying = ref(false)

async function saveAndRefresh(): Promise<void> {
  applying.value = true
  try {
    await save.mutateAsync({ fields: fields.value, lock_version: lockVersion.value })
    success('Draft saved')
    await mintAndLoad() // fresh session per apply — never stretch a 10-minute token
  } catch (e: unknown) {
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
    applying.value = false
  }
}

/** Re-mint WITHOUT saving — the expired-token affordance (spec §6). */
function refreshPreview(): void {
  void mintAndLoad()
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
          <UChip :show="dirty" color="warning" inset>
            <UButton :loading="applying" data-test="canvas-save" @click="saveAndRefresh()">
              Save &amp; refresh
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
        <aside class="w-56 shrink-0 overflow-y-auto">
          <CanvasOutline
            :fields="fields"
            :schema="schema"
            :selected="selected"
            @select="onOutlineSelect"
          />
        </aside>

        <div class="min-w-0 flex-1 overflow-auto rounded-lg border border-default bg-elevated/40 p-3" data-test="canvas-stage">
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
        </div>

        <aside class="w-96 shrink-0 overflow-y-auto" data-test="canvas-inspector">
          <FieldEditor ref="fieldEditorRef" v-model="fields" :schema="schema" />
        </aside>
      </div>
    </template>
  </UDashboardPanel>
</template>
