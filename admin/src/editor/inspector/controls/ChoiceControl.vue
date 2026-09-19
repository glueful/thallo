<script setup lang="ts">
// A choice property's control (visual builder spec §3.4): the closed set as a segmented control.
defineProps<{
  choices: string[]
  modelValue: string | null
  disabled?: boolean
  name?: string
  /** What a choice reads as, where the stored value is not the word for it (`80` → `80%`). */
  labels?: Record<string, string>
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <div class="flex flex-wrap gap-1" role="group" :aria-label="name">
    <button
      v-for="choice in choices"
      :key="choice"
      type="button"
      class="rounded-md border px-2 py-1 text-xs capitalize"
      :class="
        choice === modelValue
          ? 'border-primary bg-primary/10 font-medium text-primary'
          : 'border-default text-muted hover:text-default'
      "
      :aria-pressed="choice === modelValue ? 'true' : 'false'"
      :disabled="disabled"
      :data-test="`choice-${choice}`"
      @click="emit('update:modelValue', choice)"
    >
      {{ labels?.[choice] ?? choice }}
    </button>
  </div>
</template>
