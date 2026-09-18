<script setup lang="ts">
// Why a style class was not saved (container-layout spec §12.5): one line per refused field. The
// draft is untouched — this says what stands between it and a save.
import { computed } from 'vue'
import { describeSaveErrors } from './saveErrors'

const props = defineProps<{ errors: Record<string, string> }>()
const lines = computed(() => describeSaveErrors(props.errors))
</script>

<template>
  <UAlert
    v-if="lines.length > 0"
    color="error"
    variant="subtle"
    icon="i-lucide-circle-alert"
    title="Not saved — your changes are still here"
    data-test="style-class-save-errors"
  >
    <template #description>
      <ul class="space-y-0.5">
        <li v-for="line in lines" :key="line.key" :data-test="`style-class-save-error-${line.key}`">
          <span class="font-medium">{{ line.label }}</span>
          <span v-if="line.breakpoint"> at {{ line.breakpoint }}</span
          >: {{ line.message }}
        </li>
      </ul>
    </template>
  </UAlert>
</template>
