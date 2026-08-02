<script setup lang="ts">
import { computed } from 'vue'
import type { FieldDef } from '../types'

const props = defineProps<{ field: FieldDef }>()
const model = defineModel<string>()

// Display labels are presentation metadata (enum_labels in the schema); the
// STORED value stays the bare enum entry — e.g. 'zoom' shows "Zoom (Ken Burns)".
const items = computed(() =>
  (props.field.enum ?? []).map((v) => ({ label: props.field.enumLabels?.[v] ?? v, value: v })),
)
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <USelect v-model="model" :items="items" class="w-full" />
  </UFormField>
</template>
