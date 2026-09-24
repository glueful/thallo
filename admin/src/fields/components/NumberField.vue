<script setup lang="ts">
import { ref, watch } from 'vue'
import type { FieldDef } from '../types'
import { cleanNumberText, parseNumberText, toNumberText } from '../numberText'

defineProps<{ field: FieldDef }>()
const model = defineModel<number | null>()

// A plain text box that takes only a number — no stepper. Anything else is dropped as it is typed;
// an empty box writes null (not set). The box keeps what was typed ("1." on the way to "1.5").
const text = ref(toNumberText(model.value))
watch(model, (v) => {
  if (parseNumberText(text.value) !== (v ?? null)) text.value = toNumberText(v)
})
function onInput(value: string | number): void {
  const clean = cleanNumberText(String(value))
  text.value = clean
  model.value = parseNumberText(clean)
}
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <UInput
      :model-value="text"
      inputmode="decimal"
      autocomplete="off"
      class="w-full"
      @update:model-value="onInput"
    />
  </UFormField>
</template>
