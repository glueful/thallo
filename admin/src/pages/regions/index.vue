<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import { usePreviewRegions, useRegions, useSaveRegion, type RegionData } from '@/queries/regions'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import BlocksField from '@/fields/components/BlocksField.vue'
import RegionStyleEditor from './components/RegionStyleEditor.vue'
import RegionBlockInspector from './components/RegionBlockInspector.vue'
import type { Breakpoint } from '@/style/types'
import { STAGE_FRAME_EDGE, STAGE_WIDTHS, type ViewportPreset } from '@/editor/breakpoint'
import { useNotify } from '@/composables/useNotify'
import { ApiError } from '@/api/errors'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status } = useRegions()
const save = useSaveRegion()

// Local editable copy per region, synced from the server ONLY while clean —
// a stale-query refetch must never clobber in-progress edits (the
// settings/general lesson).
interface RegionState {
  blocks: BlockInstance[]
  settings: Record<string, unknown>
  dirty: boolean
}
const state = reactive<Record<string, RegionState>>({})
let syncing = false

watch(
  data,
  (regions) => {
    for (const region of regions ?? []) {
      const existing = state[region.slug]
      if (existing?.dirty) continue
      syncing = true
      state[region.slug] = {
        blocks: JSON.parse(JSON.stringify(region.blocks)) as BlockInstance[],
        settings: { ...region.settings },
        dirty: false,
      }
      void nextTick(() => {
        syncing = false
      })
    }
  },
  { immediate: true, deep: true },
)

watch(
  state,
  () => {
    if (syncing) return
    // A deep mutation marks every non-synced region dirty is too blunt — the
    // per-region touch() below is the real dirty signal; this watch only
    // covers nested block edits inside BlocksField models.
    for (const region of data.value ?? []) {
      const s = state[region.slug]
      if (!s || s.dirty) continue
      const clean =
        JSON.stringify(s.blocks) === JSON.stringify(region.blocks) &&
        JSON.stringify(s.settings) === JSON.stringify(region.settings)
      if (!clean) s.dirty = true
    }
  },
  { deep: true },
)

const regionMeta = computed<Record<string, RegionData>>(() => {
  const out: Record<string, RegionData> = {}
  for (const region of data.value ?? []) out[region.slug] = region
  return out
})

function paletteField(slug: string) {
  return {
    name: 'blocks',
    label: '',
    type: 'blocks' as const,
    blockTypes: regionMeta.value[slug]?.palette ?? [],
  }
}

async function onSave(slug: string): Promise<void> {
  const s = state[slug]
  if (!s) return
  try {
    await save.mutateAsync({ slug, blocks: s.blocks, settings: s.settings })
    s.dirty = false
    success('Region saved', 'Changes are live on the site immediately.')
  } catch (e) {
    notifyError(e, 'Couldn’t save the region')
  }
}

const headerSticky = computed<boolean>({
  get: () => state.header?.settings.sticky === true,
  set: (v) => {
    if (state.header) state.header.settings = { ...state.header.settings, sticky: v }
  },
})
const headerWidth = computed<string>({
  get: () => (state.header?.settings.width as string | undefined) ?? 'contained',
  set: (v) => {
    if (state.header) state.header.settings = { ...state.header.settings, width: v }
  },
})
const footerWidth = computed<string>({
  get: () => (state.footer?.settings.width as string | undefined) ?? 'contained',
  set: (v) => {
    if (state.footer) state.footer.settings = { ...state.footer.settings, width: v }
  },
})
/**
 * A region's own style (its Style tab): `settings.style`, the record a block keeps in the same
 * place. An emptied record is REMOVED, not stored as `{}` — the server stores no style for it, and
 * a region whose last declaration was taken away is clean again, not dirty against `{}`.
 */
function styleOf(slug: string): Record<string, unknown> {
  const style = state[slug]?.settings.style
  return typeof style === 'object' && style !== null ? (style as Record<string, unknown>) : {}
}
function setStyle(slug: string, style: Record<string, unknown>): void {
  const s = state[slug]
  if (!s) return
  const { style: _dropped, ...rest } = s.settings
  s.settings = Object.keys(style).length === 0 ? rest : { ...rest, style }
}

/**
 * The block whose settings are open, per region: chosen from its card's Block settings button.
 * While one is open the region's own tabs step aside (hidden, not unmounted: the block list keeps
 * its state) and the panel shows that block's Layout, Style and Advanced.
 */
const blockSettingsFor = reactive<Record<string, string | null>>({ header: null, footer: null })

const widthOptions = [
  { label: 'Contained', value: 'contained' },
  { label: 'Full width', value: 'full' },
]

// ── Two-panel layout (design-canvas pattern): left editor tabs, right stage ──
const editorTab = ref('header')
const editorTabs = [
  { label: 'Header', value: 'header', slot: 'header' as const },
  { label: 'Footer', value: 'footer', slot: 'footer' as const },
]
// Inside each region: what it holds, and how the bar itself looks.
const regionTab = reactive<Record<string, string>>({ header: 'content', footer: 'content' })
const regionTabs = [
  { label: 'Content', value: 'content', slot: 'content' as const },
  { label: 'Style', value: 'style', slot: 'style' as const },
]

type Viewport = ViewportPreset
const viewport = ref<Viewport>('desktop')
const stageWidth = computed(() => STAGE_WIDTHS[viewport.value])

// The viewport IS the breakpoint being edited, both ways round: a responsive style value is
// written where the stage is showing it, and choosing a breakpoint in the Style tab resizes the
// stage to match.
const VIEWPORT_BREAKPOINT: Record<Viewport, Breakpoint> = {
  mobile: 'base',
  tablet: 'md',
  desktop: 'lg',
}
const activeBreakpoint = computed<Breakpoint>({
  get: () => VIEWPORT_BREAKPOINT[viewport.value],
  set: (bp) => {
    const match = (Object.keys(VIEWPORT_BREAKPOINT) as Viewport[]).find(
      (v) => VIEWPORT_BREAKPOINT[v] === bp,
    )
    if (match) viewport.value = match
  },
})

// ── Live chrome preview (region-preview plan) ───────────────────────────────
const preview = usePreviewRegions()
const previewUrl = ref('') // blob: object URL (P1 pin — never srcdoc)
const previewError = ref('')
const previewStale = ref(false) // P2 pin: the iframe shows LAST GOOD, not current edits

// Fingerprint of the editable state; debounced so typing doesn't spam renders.
const stateFingerprint = computed(() => JSON.stringify(state))
const debouncedFingerprint = refDebounced(stateFingerprint, 700)

function setPreviewDocument(html: string): void {
  const url = URL.createObjectURL(new Blob([html], { type: 'text/html' }))
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = url
}

async function refreshPreview(): Promise<void> {
  const regions: Record<string, { blocks: BlockInstance[]; settings: Record<string, unknown> }> = {}
  for (const slug of ['header', 'footer']) {
    const s = state[slug]
    if (s) regions[slug] = { blocks: s.blocks, settings: s.settings }
  }
  try {
    setPreviewDocument(await preview.mutateAsync({ regions }))
    previewError.value = ''
    previewStale.value = false
  } catch (e) {
    // Keep the last good preview but say so LOUDLY (P2): the iframe no longer
    // reflects the current (invalid) edits until a refresh succeeds. Surface
    // the dot-path detail — "field validation failed" alone is undebuggable.
    const err = e instanceof ApiError ? e : null
    const detail = err
      ? Object.entries(err.fieldErrors)
          .map(([path, message]) => `${path}: ${message}`)
          .join(' · ')
      : ''
    previewError.value = detail || (e instanceof Error ? e.message : 'Preview failed')
    previewStale.value = true
  }
}

watch(debouncedFingerprint, () => {
  if (Object.keys(state).length > 0) void refreshPreview()
})

onBeforeUnmount(() => {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})
</script>

<template>
  <UDashboardPanel id="regions">
    <template #header>
      <UDashboardNavbar title="Header & footer">
        <template #default>
          <!-- Viewport switcher (design-canvas pattern). -->
          <UFieldGroup size="sm">
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-monitor"
              aria-label="Desktop viewport"
              data-test="regions-viewport-desktop"
              :class="{ 'bg-elevated': viewport === 'desktop' }"
              :ui="{ base: 'rounded-s' }"
              @click="viewport = 'desktop'"
            />
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-tablet"
              aria-label="Tablet viewport"
              data-test="regions-viewport-tablet"
              :class="{ 'bg-elevated': viewport === 'tablet' }"
              :ui="{ base: 'rounded-none' }"
              @click="viewport = 'tablet'"
            />
            <UButton
              variant="outline"
              color="neutral"
              icon="i-lucide-smartphone"
              aria-label="Mobile viewport"
              data-test="regions-viewport-mobile"
              :class="{ 'bg-elevated': viewport === 'mobile' }"
              :ui="{ base: 'rounded-e' }"
              @click="viewport = 'mobile'"
            />
          </UFieldGroup>
        </template>
        <template #right>
          <UBadge
            v-if="previewStale"
            color="warning"
            variant="subtle"
            data-test="region-preview-stale"
          >
            Preview not updated
          </UBadge>
          <UButton
            size="sm"
            variant="subtle"
            color="neutral"
            icon="i-lucide-refresh-cw"
            :loading="preview.isLoading.value"
            aria-label="Refresh preview"
            data-test="region-preview-refresh"
            @click="
              () => {
                void refreshPreview()
              }
            "
          >
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="flex h-full gap-4">
        <USkeleton class="h-full w-96 shrink-0" />
        <USkeleton class="h-full flex-1" />
      </div>

      <div v-else class="flex h-full min-h-0 gap-4">
        <!-- Left: the region editors (design-canvas inspector pattern).
             unmount-on-hide false so edits + dirty state survive tab switches. -->
        <!-- The Design page's inspector column, for the same reasons (its scrollbar proofs): a
             gutter keeps the Style tab's chips and badges clear of the scrollbar, and stops the
             breakpoint dot on a flush-right row from scrolling the panel sideways. -->
        <aside
          class="w-[25rem] shrink-0 overflow-y-auto pe-4 [scrollbar-gutter:stable]"
          data-test="regions-inspector"
        >
          <UTabs
            v-model="editorTab"
            :items="editorTabs"
            :unmount-on-hide="false"
            size="xs"
            variant="link"
            data-test="regions-tabs"
          >
            <template #header>
              <div v-if="state.header" class="space-y-4 pt-3" data-test="region-header">
                <div class="flex items-center justify-between gap-2">
                  <USwitch v-model="headerSticky" label="Sticky" data-test="region-header-sticky" />
                  <UChip :show="state.header.dirty" color="warning" size="sm">
                    <UButton
                      size="sm"
                      :loading="save.isLoading.value"
                      data-test="save-region-header"
                      @click="
                        () => {
                          void onSave('header')
                        }
                      "
                    >
                      Save
                    </UButton>
                  </UChip>
                </div>
                <p class="text-sm text-muted">
                  Rendered on every page. Empty means the theme’s built-in header; hide per page via
                  the page’s presentation settings.
                </p>
                <div
                  v-if="blockSettingsFor.header !== null"
                  class="space-y-3"
                  data-test="region-block-settings-header"
                >
                  <UButton
                    size="xs"
                    variant="ghost"
                    color="neutral"
                    icon="i-lucide-arrow-left"
                    data-test="region-block-settings-back"
                    @click="blockSettingsFor.header = null"
                  >
                    Back to the header
                  </UButton>
                  <RegionBlockInspector
                    v-model:blocks="state.header.blocks"
                    v-model:active-breakpoint="activeBreakpoint"
                    :block-id="blockSettingsFor.header"
                    @close="blockSettingsFor.header = null"
                  />
                </div>
                <UTabs
                  v-show="blockSettingsFor.header === null"
                  v-model="regionTab.header"
                  :items="regionTabs"
                  :unmount-on-hide="false"
                  size="xs"
                  variant="link"
                  data-test="region-header-tabs"
                >
                  <template #content>
                    <div class="space-y-4 pt-3">
                      <UFormField label="Width">
                        <USelect
                          v-model="headerWidth"
                          :items="widthOptions"
                          class="w-full"
                          data-test="region-header-width"
                        />
                      </UFormField>
                      <BlocksField
                        v-model="state.header.blocks"
                        :field="paletteField('header')"
                        block-settings
                        @settings-request="(id) => (blockSettingsFor.header = id)"
                      />
                    </div>
                  </template>
                  <template #style>
                    <div class="pt-3">
                      <RegionStyleEditor
                        v-model:active-breakpoint="activeBreakpoint"
                        region="header"
                        :model-value="styleOf('header')"
                        :capabilities="regionMeta.header?.style_capabilities ?? []"
                        @update:model-value="(style) => setStyle('header', style)"
                      />
                    </div>
                  </template>
                </UTabs>
              </div>
            </template>

            <template #footer>
              <div v-if="state.footer" class="space-y-4 pt-3" data-test="region-footer">
                <div class="flex items-center justify-end gap-2">
                  <UChip :show="state.footer.dirty" color="warning" size="sm">
                    <UButton
                      size="sm"
                      :loading="save.isLoading.value"
                      data-test="save-region-footer"
                      @click="
                        () => {
                          void onSave('footer')
                        }
                      "
                    >
                      Save
                    </UButton>
                  </UChip>
                </div>
                <p class="text-sm text-muted">Empty means the theme’s built-in footer.</p>
                <div
                  v-if="blockSettingsFor.footer !== null"
                  class="space-y-3"
                  data-test="region-block-settings-footer"
                >
                  <UButton
                    size="xs"
                    variant="ghost"
                    color="neutral"
                    icon="i-lucide-arrow-left"
                    data-test="region-block-settings-back"
                    @click="blockSettingsFor.footer = null"
                  >
                    Back to the footer
                  </UButton>
                  <RegionBlockInspector
                    v-model:blocks="state.footer.blocks"
                    v-model:active-breakpoint="activeBreakpoint"
                    :block-id="blockSettingsFor.footer"
                    @close="blockSettingsFor.footer = null"
                  />
                </div>
                <UTabs
                  v-show="blockSettingsFor.footer === null"
                  v-model="regionTab.footer"
                  :items="regionTabs"
                  :unmount-on-hide="false"
                  size="xs"
                  variant="link"
                  data-test="region-footer-tabs"
                >
                  <template #content>
                    <div class="space-y-4 pt-3">
                      <UFormField label="Width">
                        <USelect
                          v-model="footerWidth"
                          :items="widthOptions"
                          class="w-full"
                          data-test="region-footer-width"
                        />
                      </UFormField>
                      <BlocksField
                        v-model="state.footer.blocks"
                        :field="paletteField('footer')"
                        block-settings
                        @settings-request="(id) => (blockSettingsFor.footer = id)"
                      />
                    </div>
                  </template>
                  <template #style>
                    <div class="pt-3">
                      <RegionStyleEditor
                        v-model:active-breakpoint="activeBreakpoint"
                        region="footer"
                        :model-value="styleOf('footer')"
                        :capabilities="regionMeta.footer?.style_capabilities ?? []"
                        @update:model-value="(style) => setStyle('footer', style)"
                      />
                    </div>
                  </template>
                </UTabs>
              </div>
            </template>
          </UTabs>
        </aside>

        <!-- Right: the preview stage. Nothing is live until Save. -->
        <div
          class="relative min-w-0 flex-1 overflow-auto rounded-lg border border-default bg-elevated/40 p-3"
          data-test="region-preview"
        >
          <p v-if="previewError" class="mb-2 text-sm text-error" data-test="region-preview-error">
            {{ previewError }}
          </p>
          <div class="mx-auto h-full transition-[width]" :style="{ width: stageWidth }">
            <iframe
              v-if="previewUrl"
              :src="previewUrl"
              sandbox="allow-same-origin"
              title="Chrome preview"
              class="h-full min-h-[70vh] w-full"
              :class="STAGE_FRAME_EDGE"
              data-test="region-preview-frame"
            />
            <p v-else class="py-16 text-center text-sm text-muted">Starting preview…</p>
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
