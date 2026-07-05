<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import { usePreviewRegions, useRegions, useSaveRegion, type RegionData } from '@/queries/regions'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import BlocksField from '@/fields/components/BlocksField.vue'
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

const VIEWPORT_WIDTHS = { desktop: '100%', tablet: '768px', mobile: '390px' } as const
type Viewport = keyof typeof VIEWPORT_WIDTHS
const viewport = ref<Viewport>('desktop')
const stageWidth = computed(() => VIEWPORT_WIDTHS[viewport.value])

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
    previewError.value =
      detail || (e instanceof Error ? e.message : 'Preview failed')
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
            @click="() => { void refreshPreview() }"
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
        <aside class="w-96 shrink-0 overflow-y-auto" data-test="regions-inspector">
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
                  <USwitch
                    v-model="headerSticky"
                    label="Sticky"
                    data-test="region-header-sticky"
                  />
                  <UChip :show="state.header.dirty" color="warning" size="sm">
                    <UButton
                      size="sm"
                      :loading="save.isLoading.value"
                      data-test="save-region-header"
                      @click="() => { void onSave('header') }"
                    >
                      Save
                    </UButton>
                  </UChip>
                </div>
                <p class="text-sm text-muted">
                  Rendered on every page. Empty means the theme’s built-in header;
                  hide per page via the page’s presentation settings.
                </p>
                <UFormField label="Width">
                  <USelect
                    v-model="headerWidth"
                    :items="widthOptions"
                    class="w-full"
                    data-test="region-header-width"
                  />
                </UFormField>
                <BlocksField v-model="state.header.blocks" :field="paletteField('header')" />
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
                      @click="() => { void onSave('footer') }"
                    >
                      Save
                    </UButton>
                  </UChip>
                </div>
                <p class="text-sm text-muted">Empty means the theme’s built-in footer.</p>
                <UFormField label="Width">
                  <USelect
                    v-model="footerWidth"
                    :items="widthOptions"
                    class="w-full"
                    data-test="region-footer-width"
                  />
                </UFormField>
                <BlocksField v-model="state.footer.blocks" :field="paletteField('footer')" />
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
              class="h-full min-h-[70vh] w-full rounded border border-default bg-white"
              data-test="region-preview-frame"
            />
            <p v-else class="py-16 text-center text-sm text-muted">Starting preview…</p>
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
