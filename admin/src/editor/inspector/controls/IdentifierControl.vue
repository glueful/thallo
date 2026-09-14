<script setup lang="ts">
// An identifier's control (visual builder spec §3.4): validated text — the value only leaves
// the control when it matches; an invalid draft stays local with the reason shown.
import { ref, watch } from 'vue'

const props = defineProps<{
  modelValue: string
  pattern: RegExp
  /** Shown when the draft does not match. */
  invalidMessage: string
  placeholder?: string
  name?: string
  /** A value is rejected when this returns a message. */
  reject?: (value: string) => string | null
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const draft = ref(props.modelValue)
const error = ref<string | null>(null)
watch(
  () => props.modelValue,
  (v) => {
    if (v !== draft.value) {
      draft.value = v
      error.value = null
    }
  },
)

function onInput(value: string): void {
  draft.value = value
  if (value === '') {
    error.value = null
    emit('update:modelValue', '')
    return
  }
  if (!props.pattern.test(value)) {
    error.value = props.invalidMessage
    return
  }
  const rejected = props.reject?.(value) ?? null
  error.value = rejected
  if (rejected === null) emit('update:modelValue', value)
}
</script>

<template>
  <div>
    <UInput
      :model-value="draft"
      :placeholder="placeholder"
      :name="name"
      class="w-full"
      :data-test="`identifier-${name ?? 'value'}`"
      @update:model-value="(v: string | number) => onInput(String(v))"
    />
    <p v-if="error" class="mt-1 text-xs text-error" data-test="identifier-error">{{ error }}</p>
  </div>
</template>
