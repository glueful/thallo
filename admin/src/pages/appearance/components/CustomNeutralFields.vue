<script setup lang="ts">
// The Custom neutral (custom palette spec §2.1): the six light-mode colours, the family dark mode
// is built from — shown only when the site has a dark mode — and Reset back to a family.
import type { NeutralKey } from '@/queries/palette'
import HexInput from './HexInput.vue'

const props = defineProps<{
  modelValue: Record<NeutralKey, string>
  darkBase: string
  showDarkBase: boolean
  /** The family Reset returns to. */
  family: string
}>()
const emit = defineEmits<{
  'update:modelValue': [value: Record<NeutralKey, string>]
  'update:darkBase': [value: string]
  reset: []
}>()

const FIELDS: Array<{ key: NeutralKey; label: string; help: string }> = [
  { key: 'bg', label: 'Background', help: 'The page.' },
  { key: 'surface', label: 'Surface', help: 'Panels and cards.' },
  { key: 'surface_2', label: 'Surface 2', help: 'Raised or alternate panels.' },
  { key: 'ink', label: 'Text', help: 'Body text and headings.' },
  { key: 'muted', label: 'Muted', help: 'Secondary text.' },
  { key: 'line', label: 'Line', help: 'Borders and dividers.' },
]
const FAMILIES = ['slate', 'gray', 'zinc', 'neutral', 'stone']

function set(key: NeutralKey, value: string): void {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}
</script>

<template>
  <div class="space-y-4" data-test="neutral-custom">
    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField v-for="f in FIELDS" :key="f.key" :label="f.label" :description="f.help">
        <HexInput
          :model-value="modelValue[f.key]"
          :label="f.label"
          :data-test="`neutral-custom-${f.key}`"
          @update:model-value="(v: string) => set(f.key, v)"
        />
      </UFormField>
    </div>
    <UFormField
      v-if="showDarkBase"
      label="Dark mode base"
      description="Dark mode is built from this family; your six colours are for light mode."
    >
      <USelect
        :model-value="darkBase"
        :items="FAMILIES"
        class="w-full"
        data-test="theme-dark-base"
        @update:model-value="(v: string) => emit('update:darkBase', v)"
      />
    </UFormField>
    <UButton
      color="neutral"
      variant="outline"
      size="sm"
      icon="i-lucide-rotate-ccw"
      data-test="neutral-reset"
      @click="emit('reset')"
    >
      Reset to {{ family }}
    </UButton>
  </div>
</template>
