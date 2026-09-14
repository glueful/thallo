<script setup lang="ts">
// A token property's control (visual builder spec §3.4): the ordinal scale of one vocabulary
// domain as a segmented control, each step previewing the active theme's value in its title.
import { computed } from 'vue'

const props = defineProps<{
  /** The vocabulary domain, e.g. `spacing`. */
  domain: string
  /** The domain's names in ordinal order. */
  names: string[]
  /** Token name (`spacing.lg`) => the theme's CSS value, for the preview title. */
  values: Record<string, string>
  /** The selected token name (`spacing.lg`), or null when none is set. */
  modelValue: string | null
  disabled?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const items = computed(() =>
  props.names.map((name) => {
    const token = `${props.domain}.${name}`
    return { name, token, value: props.values[token] ?? '' }
  }),
)
</script>

<template>
  <div class="flex flex-wrap gap-1" role="group" :aria-label="domain">
    <button
      v-for="item in items"
      :key="item.token"
      type="button"
      class="rounded-md border px-2 py-1 text-xs"
      :class="
        item.token === modelValue
          ? 'border-primary bg-primary/10 font-medium text-primary'
          : 'border-default text-muted hover:text-default'
      "
      :aria-pressed="item.token === modelValue ? 'true' : 'false'"
      :title="item.value ? `${item.name}: ${item.value}` : item.name"
      :disabled="disabled"
      :data-test="`token-${item.token}`"
      @click="emit('update:modelValue', item.token)"
    >
      {{ item.name }}
    </button>
  </div>
</template>
