<script setup lang="ts">
// A stored choice the contract no longer offers (container-layout spec §11.1). It stands in the
// control's place rather than beside it: showing the theme default there would read as valid while
// the save is refused. It says what is stored and where, and offers the way out — a replacement
// and a removal where the value is this record's own, or the way to the class that holds it.
import type { Breakpoint } from '@/style/types'

const props = defineProps<{
  /** What the property is called in the panel it sits in. */
  label: string
  path: string
  value: string
  /** Where the value is declared — the breakpoint a repair writes at. */
  breakpoint: Breakpoint
  /** What Replace writes; absent, only Remove is offered. */
  replacement?: string
  /** Set when a style class supplies the value: the record here cannot repair it. */
  heldByClass?: { id: string; name: string }
}>()
const emit = defineEmits<{
  replace: [path: string, breakpoint: Breakpoint, value: string]
  remove: [path: string, breakpoint: Breakpoint]
}>()

const title = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)
</script>

<template>
  <div
    class="space-y-1.5 rounded border border-warning/40 bg-warning/5 px-2 py-1.5"
    :data-test="`invalid-choice-${props.path}`"
  >
    <span class="text-xs font-medium">{{ label }}</span>
    <p class="text-[11px] text-muted">
      <code class="text-default">{{ value }}</code> is not a {{ label.toLowerCase() }} this version
      offers — set at <span class="font-medium text-default">{{ breakpoint }}</span
      ><template v-if="heldByClass">
        by the style class
        <span class="font-medium text-default">{{ heldByClass.name }}</span></template
      >. It cannot be saved as it is.
    </p>
    <div class="flex flex-wrap gap-1.5">
      <template v-if="heldByClass">
        <UButton
          size="xs"
          variant="soft"
          color="neutral"
          icon="i-lucide-external-link"
          :to="`/settings/style-classes/${heldByClass.id}`"
          data-test="invalid-choice-open-class"
        >
          Open class
        </UButton>
      </template>
      <template v-else>
        <UButton
          v-if="replacement"
          size="xs"
          variant="soft"
          data-test="invalid-choice-replace"
          @click="emit('replace', path, breakpoint, replacement)"
        >
          Replace with {{ title(replacement) }}
        </UButton>
        <UButton
          size="xs"
          variant="ghost"
          color="neutral"
          data-test="invalid-choice-remove"
          @click="emit('remove', path, breakpoint)"
        >
          Remove
        </UButton>
      </template>
    </div>
  </div>
</template>
