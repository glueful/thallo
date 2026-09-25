<script setup lang="ts">
// The Regions page's top bar (regions stage spec §3): the Header | Footer switch, the stage widths,
// the page picker, Undo / Redo and the one Save — marked while anything is unsaved — with the
// "Switching…" and "Changed by someone else" states beside it.
import type { ViewportPreset } from '@/editor/breakpoint'

defineProps<{
  currentRegion: 'header' | 'footer'
  viewport: ViewportPreset
  /** The published pages the stage can show; `@home` is the homepage. */
  pageOptions: { label: string; value: string }[]
  page: string
  canUndo: boolean
  canRedo: boolean
  dirty: boolean
  saving: boolean
  switching: boolean
  conflict: boolean
  /** The stage stopped applying after a refused edit; Resume starts it again. */
  paused: boolean
}>()
const emit = defineEmits<{
  'update:currentRegion': [region: 'header' | 'footer']
  'update:viewport': [viewport: ViewportPreset]
  'update:page': [page: string]
  undo: []
  redo: []
  save: []
  reload: []
  resume: []
}>()

const widths: { value: ViewportPreset; icon: string; label: string }[] = [
  { value: 'desktop', icon: 'i-lucide-monitor', label: 'Desktop viewport' },
  { value: 'tablet', icon: 'i-lucide-tablet', label: 'Tablet viewport' },
  { value: 'mobile', icon: 'i-lucide-smartphone', label: 'Mobile viewport' },
]
</script>

<template>
  <div class="flex w-full flex-wrap items-center gap-3">
    <UFieldGroup size="sm">
      <UButton
        v-for="region in ['header', 'footer'] as const"
        :key="region"
        :variant="currentRegion === region ? 'solid' : 'outline'"
        color="neutral"
        :aria-pressed="currentRegion === region ? 'true' : 'false'"
        :data-test="`regions-switch-${region}`"
        @click="emit('update:currentRegion', region)"
      >
        {{ region === 'header' ? 'Header' : 'Footer' }}
      </UButton>
    </UFieldGroup>
    <UFieldGroup size="sm">
      <UButton
        v-for="w in widths"
        :key="w.value"
        variant="outline"
        color="neutral"
        :icon="w.icon"
        :aria-label="w.label"
        :data-test="`regions-viewport-${w.value}`"
        :class="{ 'bg-elevated': viewport === w.value }"
        @click="emit('update:viewport', w.value)"
      />
    </UFieldGroup>
    <USelect
      :model-value="page"
      :items="pageOptions"
      size="sm"
      class="w-56"
      aria-label="Page shown on the stage"
      data-test="regions-page-picker"
      @update:model-value="(v: string) => emit('update:page', v)"
    />
    <span v-if="switching" class="text-sm text-muted" data-test="regions-switching">
      Switching…
    </span>
    <div class="ms-auto flex items-center gap-2">
      <UFieldGroup size="sm">
        <UButton
          variant="outline"
          color="neutral"
          icon="i-lucide-undo-2"
          aria-label="Undo"
          title="Undo (⌘Z)"
          data-test="regions-undo"
          :disabled="!canUndo"
          @click="emit('undo')"
        />
        <UButton
          variant="outline"
          color="neutral"
          icon="i-lucide-redo-2"
          aria-label="Redo"
          title="Redo (⇧⌘Z)"
          data-test="regions-redo"
          :disabled="!canRedo"
          @click="emit('redo')"
        />
      </UFieldGroup>
      <UButton
        v-if="paused"
        size="sm"
        variant="soft"
        color="warning"
        icon="i-lucide-zap-off"
        title="The stage stopped updating after an error — resume it"
        data-test="regions-resume"
        @click="emit('resume')"
      >
        Stage paused — resume
      </UButton>
      <div v-if="conflict" class="flex items-center gap-2" data-test="regions-conflict">
        <span class="text-sm text-warning">Changed by someone else</span>
        <UButton
          size="sm"
          variant="subtle"
          color="warning"
          data-test="regions-conflict-reload"
          @click="emit('reload')"
        >
          Reload
        </UButton>
      </div>
      <UChip :show="dirty" color="warning" inset>
        <UButton size="sm" :loading="saving" data-test="regions-save" @click="emit('save')">
          Save
        </UButton>
      </UChip>
    </div>
  </div>
</template>
