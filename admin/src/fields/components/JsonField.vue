<script setup lang="ts">
import { ref, watch } from 'vue'
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
// The MODEL is the parsed value (FieldValidator requires json fields to be an
// object/array — a raw string 422s at save); the textarea is a local buffer.
// Invalid JSON never emits: the last valid value stays in the model and the
// error shows until the text parses again.
const model = defineModel<unknown>()
const error = ref<string | null>(null)

function toText(v: unknown): string {
  if (v == null || v === '') return ''
  // Legacy string values (the old widget stored raw text): show as-is; the
  // next valid edit emits the parsed object and self-heals the data.
  if (typeof v === 'string') return v
  return JSON.stringify(v, null, 2)
}

const text = ref(toText(model.value))

watch(text, (v) => {
  if (v.trim() === '') {
    error.value = null
    model.value = undefined
    return
  }
  try {
    model.value = JSON.parse(v)
    error.value = null
  } catch {
    error.value = 'Invalid JSON.'
  }
})

// External model changes (server sync while clean, undo layers) re-fill the
// buffer — but never while the buffer already parses to the same value, so
// reformatting never fights the user's typing.
watch(model, (v) => {
  const incoming = toText(v)
  try {
    if (text.value.trim() !== '' && JSON.stringify(JSON.parse(text.value)) === JSON.stringify(v)) return
  } catch {
    return // buffer mid-edit (invalid): never clobber typing
  }
  if (incoming !== text.value) text.value = incoming
})
</script>

<template>
  <UFormField
    :label="field.label ?? field.name"
    :required="field.required"
    :name="field.name"
    :error="error ?? undefined"
  >
    <UTextarea v-model="text" :rows="4" class="w-full font-mono text-sm" />
  </UFormField>
</template>
