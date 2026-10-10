<script setup lang="ts">
// A colour as a hex (custom palette spec §2): typed or picked, kept in its stored spelling
// (#rrggbb, lower case). What is typed stays as typed until it is a colour.
import { ref, watch } from 'vue'
import { normalizeHex } from '@/style/contrast'

const props = defineProps<{ modelValue: string; label: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const typed = ref(props.modelValue)
watch(
  () => props.modelValue,
  (v) => {
    if (normalizeHex(typed.value) !== v) typed.value = v
  },
)

function onTyped(value: string): void {
  typed.value = value
  const hex = normalizeHex(value)
  if (hex !== null) emit('update:modelValue', hex)
}
</script>

<template>
  <div class="flex items-center gap-2">
    <UInput
      :model-value="typed"
      class="w-full font-mono"
      placeholder="#rrggbb"
      :aria-label="`${label}, as a hex`"
      @update:model-value="(v: string | number) => onTyped(String(v))"
    />
    <input
      type="color"
      class="h-8 w-10 shrink-0 cursor-pointer rounded border border-default bg-transparent p-0.5"
      :value="normalizeHex(typed) ?? '#000000'"
      :aria-label="`Pick ${label}`"
      @input="onTyped(($event.target as HTMLInputElement).value)"
    />
  </div>
</template>
