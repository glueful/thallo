<script setup lang="ts">
// A choice property whose options are directions or arrangements (container-layout spec §5): the
// same segmented control as ChoiceControl, but each option shows what it does rather than naming
// it. Row and column are read far faster as arrows than as words, and the four flex directions
// differ only by their arrow's heading.
//
// An option with no icon falls back to its name, so a choice set this map does not cover still
// renders every option rather than a row of blanks.
const props = defineProps<{
  choices: string[]
  modelValue: string | null
  /** path => value => icon, so one control serves direction, wrap and alignment. */
  icons: Record<string, string>
  labels?: Record<string, string>
  disabled?: boolean
  name?: string
  /**
   * The theme's own value, in force while nothing is chosen. It is marked — distinctly from a
   * choice the author made, and never as pressed — so an untouched control does not read as "none"
   * about a value that is very much applied.
   */
  defaultValue?: string | null
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const labelOf = (choice: string): string => props.labels?.[choice] ?? choice.replace(/-/g, ' ')
const isDefault = (choice: string): boolean =>
  props.modelValue === null && props.defaultValue != null && choice === props.defaultValue
const titleOf = (choice: string): string =>
  isDefault(choice) ? `${labelOf(choice)} — the theme's default, in force` : labelOf(choice)
</script>

<template>
  <div class="flex flex-wrap gap-1" role="group" :aria-label="name">
    <button
      v-for="choice in choices"
      :key="choice"
      type="button"
      class="flex items-center justify-center rounded-md border px-2 py-1 text-xs"
      :class="
        choice === modelValue
          ? 'border-primary bg-primary/10 font-medium text-primary'
          : isDefault(choice)
            ? 'border-dashed border-primary/60 text-default'
            : 'border-default text-muted hover:text-default'
      "
      :aria-pressed="choice === modelValue ? 'true' : 'false'"
      :aria-label="titleOf(choice)"
      :title="titleOf(choice)"
      :data-default="isDefault(choice) ? 'true' : undefined"
      :disabled="disabled"
      :data-test="`choice-${choice}`"
      @click="emit('update:modelValue', choice)"
    >
      <UIcon v-if="icons[choice]" :name="icons[choice]!" class="size-4" />
      <span v-else class="capitalize">{{ labelOf(choice) }}</span>
    </button>
  </div>
</template>
