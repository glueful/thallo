<script setup lang="ts">
// The layout editor's top bar (type layouts spec §6.2): the sample picker, the stage widths, Undo /
// Redo, Save — marked while anything is unsaved — with its reach beside it ("Applies to every
// post"), and a menu with Reset to starter and Remove layout; the "Changed by someone else" and
// "removed" states in place of Save when they happen.
import type { DropdownMenuItem } from '@nuxt/ui'
import type { ViewportPreset } from '@/editor/breakpoint'

const props = defineProps<{
  viewport: ViewportPreset
  /** The published items the stage can show. */
  sampleOptions: { label: string; value: string }[]
  sample: string | undefined
  reach: string
  canUndo: boolean
  canRedo: boolean
  dirty: boolean
  saving: boolean
  switching: boolean
  conflict: boolean
  retired: boolean
  /** Why Save is off, when it is (the layout is invalid). */
  saveBlocked: string | null
  /** There is a saved layout to remove. */
  canRemove: boolean
  paused: boolean
}>()
const emit = defineEmits<{
  'update:viewport': [viewport: ViewportPreset]
  'update:sample': [sample: string]
  undo: []
  redo: []
  save: []
  reload: []
  resetStarter: []
  remove: []
  resume: []
}>()

const widths: { value: ViewportPreset; icon: string; label: string }[] = [
  { value: 'desktop', icon: 'i-lucide-monitor', label: 'Desktop viewport' },
  { value: 'tablet', icon: 'i-lucide-tablet', label: 'Tablet viewport' },
  { value: 'mobile', icon: 'i-lucide-smartphone', label: 'Mobile viewport' },
]

const menu = (): DropdownMenuItem[][] => [
  [
    {
      label: 'Reset to starter',
      icon: 'i-lucide-rotate-ccw',
      onSelect: () => emit('resetStarter'),
      'data-test': 'layout-reset-starter',
    } as DropdownMenuItem,
  ],
  [
    {
      label: 'Remove layout',
      icon: 'i-lucide-trash-2',
      color: 'error',
      disabled: !props.canRemove,
      onSelect: () => emit('remove'),
      'data-test': 'layout-remove',
    } as DropdownMenuItem,
  ],
]
</script>

<template>
  <div class="flex w-full flex-wrap items-center gap-3">
    <UFieldGroup size="sm">
      <UButton
        v-for="w in widths"
        :key="w.value"
        variant="outline"
        color="neutral"
        :icon="w.icon"
        :aria-label="w.label"
        :data-test="`layout-viewport-${w.value}`"
        :class="{ 'bg-elevated': viewport === w.value }"
        @click="emit('update:viewport', w.value)"
      />
    </UFieldGroup>
    <USelect
      v-if="sampleOptions.length > 0"
      :model-value="sample"
      :items="sampleOptions"
      size="sm"
      class="w-56"
      placeholder="Sample"
      aria-label="Shown on the stage"
      data-test="layout-sample-picker"
      @update:model-value="(v: string) => emit('update:sample', v)"
    />
    <span v-if="switching" class="text-sm text-muted" data-test="layout-switching">Switching…</span>
    <div class="ms-auto flex items-center gap-2">
      <UFieldGroup size="sm">
        <UButton
          variant="outline"
          color="neutral"
          icon="i-lucide-undo-2"
          aria-label="Undo"
          title="Undo (⌘Z)"
          data-test="layout-undo"
          :disabled="!canUndo"
          @click="emit('undo')"
        />
        <UButton
          variant="outline"
          color="neutral"
          icon="i-lucide-redo-2"
          aria-label="Redo"
          title="Redo (⇧⌘Z)"
          data-test="layout-redo"
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
        data-test="layout-resume"
        @click="emit('resume')"
      >
        Stage paused — resume
      </UButton>
      <span v-if="retired" class="text-sm text-warning" data-test="layout-retired">
        This layout was removed
      </span>
      <div v-else-if="conflict" class="flex items-center gap-2" data-test="layout-conflict">
        <span class="text-sm text-warning">Changed by someone else</span>
        <UButton
          size="sm"
          variant="subtle"
          color="warning"
          data-test="layout-conflict-reload"
          @click="emit('reload')"
        >
          Reload
        </UButton>
      </div>
      <template v-else>
        <span class="text-xs text-muted" data-test="layout-reach">{{ reach }}</span>
        <UTooltip :text="saveBlocked ?? ''" :disabled="saveBlocked === null">
          <UChip :show="dirty" color="warning" inset>
            <UButton
              size="sm"
              :loading="saving"
              :disabled="saveBlocked !== null"
              data-test="layout-save"
              @click="emit('save')"
            >
              Save
            </UButton>
          </UChip>
        </UTooltip>
      </template>
      <UDropdownMenu :items="menu()" :content="{ align: 'end' }">
        <UButton
          size="sm"
          variant="ghost"
          color="neutral"
          icon="i-lucide-ellipsis-vertical"
          aria-label="More"
          data-test="layout-menu"
        />
      </UDropdownMenu>
    </div>
  </div>
</template>
