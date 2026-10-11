<script setup lang="ts">
// A choice that names a set (style/choiceSets.ts) as toggles: which sides a border is drawn on, which
// corners the Corners size rounds. Unset is every one on. Turning one off or on writes the one value
// naming what is left on; the last one on cannot be turned off — none is the modified setting's None.
import { computed } from 'vue'
import { membersOf, valueOf } from '@/style/choiceSets'

const props = defineProps<{
  members: readonly string[]
  labels: Record<string, string>
  /** Why the last one on cannot be turned off. */
  lastOn: string
  modelValue: string | null
  name?: string
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const on = computed(() => membersOf(props.modelValue, props.members))
const isLast = (member: string) => on.value.length === 1 && on.value[0] === member

function toggle(member: string): void {
  if (isLast(member)) return
  const next = on.value.includes(member)
    ? on.value.filter((m) => m !== member)
    : [...on.value, member]
  emit('update:modelValue', valueOf(next, props.members))
}
</script>

<template>
  <div class="flex flex-wrap gap-1" role="group" :aria-label="name">
    <button
      v-for="member in members"
      :key="member"
      type="button"
      class="rounded-md border px-2 py-1 text-xs"
      :class="
        on.includes(member)
          ? 'border-primary bg-primary/10 font-medium text-primary'
          : 'border-default text-muted hover:text-default'
      "
      :aria-pressed="on.includes(member) ? 'true' : 'false'"
      :disabled="isLast(member)"
      :title="isLast(member) ? lastOn : undefined"
      :data-test="`set-${member}`"
      @click="toggle(member)"
    >
      {{ labels[member] ?? member }}
    </button>
  </div>
</template>
