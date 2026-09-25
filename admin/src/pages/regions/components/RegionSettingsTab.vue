<script setup lang="ts">
// The Region tab (regions stage spec §3): the current region's own settings — the header's Sticky,
// each region's Width and its Style. It edits a settings record and hands back a new one; the page
// writes it into the document, so every change is an edit history records and undo reverts.
import { computed } from 'vue'
import type { Breakpoint } from '@/style/types'
import RegionStyleEditor from './RegionStyleEditor.vue'

const props = defineProps<{
  region: 'header' | 'footer'
  settings: Record<string, unknown>
  capabilities: string[]
  activeBreakpoint: Breakpoint
}>()
const emit = defineEmits<{
  'update:settings': [settings: Record<string, unknown>]
  'update:activeBreakpoint': [breakpoint: Breakpoint]
}>()

const widthOptions = [
  { label: 'Contained', value: 'contained' },
  { label: 'Full width', value: 'full' },
]

const sticky = computed<boolean>({
  get: () => props.settings.sticky === true,
  set: (v) => emit('update:settings', { ...props.settings, sticky: v }),
})
const width = computed<string>({
  get: () => (props.settings.width as string | undefined) ?? 'contained',
  set: (v) => emit('update:settings', { ...props.settings, width: v }),
})
const style = computed<Record<string, unknown>>(() => {
  const s = props.settings.style
  return typeof s === 'object' && s !== null ? (s as Record<string, unknown>) : {}
})
/**
 * An emptied style record is REMOVED, not stored as `{}` — the server stores no style for it, and a
 * region whose last declaration was taken away is clean again, not dirty against `{}`.
 */
function setStyle(next: Record<string, unknown>): void {
  const { style: _dropped, ...rest } = props.settings
  emit('update:settings', Object.keys(next).length === 0 ? rest : { ...rest, style: next })
}
</script>

<template>
  <div class="space-y-4 pt-3" :data-test="`region-settings-${region}`">
    <p class="text-sm text-muted">
      <template v-if="region === 'header'">
        Rendered on every page. Empty means the theme’s built-in header; hide it per page in the
        page’s settings.
      </template>
      <template v-else>Rendered on every page. Empty means the theme’s built-in footer.</template>
    </p>
    <USwitch
      v-if="region === 'header'"
      v-model="sticky"
      label="Sticky"
      data-test="region-header-sticky"
    />
    <UFormField label="Width">
      <USelect
        v-model="width"
        :items="widthOptions"
        class="w-full"
        :data-test="`region-${region}-width`"
      />
    </UFormField>
    <div class="border-t border-default pt-3">
      <RegionStyleEditor
        :region="region"
        :model-value="style"
        :capabilities="capabilities"
        :active-breakpoint="activeBreakpoint"
        @update:model-value="setStyle"
        @update:active-breakpoint="(bp: Breakpoint) => emit('update:activeBreakpoint', bp)"
      />
    </div>
  </div>
</template>
