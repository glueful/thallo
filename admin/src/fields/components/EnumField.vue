<script setup lang="ts">
import { computed } from 'vue'
import type { FieldDef } from '../types'
import { enumItems, fromSelected, toSelected } from '../enumOptions'

const props = defineProps<{ field: FieldDef }>()
const model = defineModel<string | null>()

// Display labels are presentation metadata (enum_labels in the schema); the STORED value stays
// the bare enum entry — e.g. 'zoom' shows "Zoom (Ken Burns)". An optional enum also offers
// "Default", which stores null (enumOptions.ts).
const items = computed(() => enumItems(props.field))
const selected = computed({
  get: () => toSelected(model.value, props.field),
  set: (v: string) => {
    model.value = fromSelected(v, props.field)
  },
})
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <USelect v-model="selected" :items="items" class="w-full" />
  </UFormField>
</template>
