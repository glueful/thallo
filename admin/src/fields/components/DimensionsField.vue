<script setup lang="ts">
import { ref, watch } from 'vue'
import { cleanNumberText, parseNumberText, toNumberText } from '../numberText'

// Width × Height on one row, each a whole number of pixels and each optional: an empty box is
// null, so one alone leaves the other to the picture's proportions.
const props = defineProps<{ width: unknown; height: unknown }>()
const emit = defineEmits<{
  'update:width': [value: number | null]
  'update:height': [value: number | null]
}>()

const w = ref(toNumberText(props.width))
const h = ref(toNumberText(props.height))
watch(
  () => props.width,
  (v) => {
    if (parseNumberText(w.value) !== ((v as number | null) ?? null)) w.value = toNumberText(v)
  },
)
watch(
  () => props.height,
  (v) => {
    if (parseNumberText(h.value) !== ((v as number | null) ?? null)) h.value = toNumberText(v)
  },
)
function onWidth(value: string | number): void {
  w.value = cleanNumberText(String(value), { integer: true })
  emit('update:width', parseNumberText(w.value))
}
function onHeight(value: string | number): void {
  h.value = cleanNumberText(String(value), { integer: true })
  emit('update:height', parseNumberText(h.value))
}
</script>

<template>
  <UFormField label="size (px)" name="size" data-test="field-size">
    <div class="flex items-start gap-2">
      <label class="flex-1 space-y-1">
        <UInput
          :model-value="w"
          inputmode="numeric"
          autocomplete="off"
          placeholder="auto"
          class="w-full"
          @update:model-value="onWidth"
        />
        <span class="block text-center text-xs text-muted">Width</span>
      </label>
      <span class="pt-1.5 text-muted" aria-hidden="true">×</span>
      <label class="flex-1 space-y-1">
        <UInput
          :model-value="h"
          inputmode="numeric"
          autocomplete="off"
          placeholder="auto"
          class="w-full"
          @update:model-value="onHeight"
        />
        <span class="block text-center text-xs text-muted">Height</span>
      </label>
    </div>
  </UFormField>
</template>
