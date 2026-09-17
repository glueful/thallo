<script setup lang="ts">
// The grid's track count as proportional swatches (container-layout spec §5). A track set is a
// shape, not a word: `1-2` and `2-1` are the same two numbers in a different order, and reading
// them as bars is immediate where reading them as text is not.
//
// Every option in the contract is drawn from its own name, so a track set added to the schema
// appears here without an edit: digits are that many equal tracks, and a hyphenated name is its
// parts in proportion.
import { computed } from 'vue'

const props = defineProps<{
  choices: string[]
  modelValue: string | null
  disabled?: boolean
  name?: string
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

/** The proportions a track name draws as: `3` → three equal, `1-2` → one then double. */
function partsOf(choice: string): number[] {
  if (choice.includes('-')) {
    return choice.split('-').map((part) => Number(part) || 1)
  }
  const count = Number(choice) || 1
  return Array.from({ length: count }, () => 1)
}

const options = computed(() =>
  props.choices.map((choice) => ({ choice, parts: partsOf(choice), label: labelOf(choice) })),
)

function labelOf(choice: string): string {
  return choice.includes('-')
    ? `${choice.split('-').join(' to ')} split`
    : `${choice} equal ${Number(choice) === 1 ? 'column' : 'columns'}`
}
</script>

<template>
  <div class="grid grid-cols-4 gap-1" role="group" :aria-label="name">
    <button
      v-for="option in options"
      :key="option.choice"
      type="button"
      class="flex flex-col items-center gap-1 rounded-md border px-1.5 py-1.5"
      :class="
        option.choice === modelValue
          ? 'border-primary bg-primary/10 text-primary'
          : 'border-default text-muted hover:text-default'
      "
      :aria-pressed="option.choice === modelValue ? 'true' : 'false'"
      :aria-label="option.label"
      :title="option.label"
      :disabled="disabled"
      :data-test="`track-${option.choice}`"
      @click="emit('update:modelValue', option.choice)"
    >
      <span class="flex h-4 w-full gap-px" aria-hidden="true">
        <span
          v-for="(part, index) in option.parts"
          :key="index"
          class="rounded-[1px]"
          :class="option.choice === modelValue ? 'bg-primary/60' : 'bg-muted'"
          :style="{ flexGrow: part, flexBasis: 0 }"
        />
      </span>
      <span class="text-[10px] tabular-nums">{{ option.choice }}</span>
    </button>
  </div>
</template>
