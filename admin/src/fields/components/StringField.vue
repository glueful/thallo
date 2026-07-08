<script setup lang="ts">
import type { FieldDef } from '../types'
import IconField from './IconField.vue'
import ColorField from './ColorField.vue'

// format branches the widget (the TextField→RichText pattern):
//   'icon' | 'brand-icon' → the icon picker field (icon-picker spec §5)
//   'color'               → a swatch + hex picker
//   otherwise             → a plain input
defineProps<{ field: FieldDef }>()
const model = defineModel<string>()
</script>

<template>
  <IconField
    v-if="field.format === 'icon' || field.format === 'brand-icon'"
    v-model="model"
    :field="field"
  />
  <ColorField v-else-if="field.format === 'color'" v-model="model" :field="field" />
  <UFormField v-else :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <UInput v-model="model" class="w-full" />
  </UFormField>
</template>
