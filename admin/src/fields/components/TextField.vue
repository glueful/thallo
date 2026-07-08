<script setup lang="ts">
import type { FieldDef } from '../types'
import RichText from '@/components/RichText.vue'

defineProps<{ field: FieldDef }>()
// A `text` field stores a string either way; `format` only picks the editing widget:
//   'rich' → the reusable RichText editor (HTML string)
//   'plain' (default) → a plain multiline textarea
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <RichText v-if="field.format === 'rich'" v-model="model" :placeholder="field.name" />
    <UTextarea v-else v-model="model" :rows="4" class="w-full" />
  </UFormField>
</template>
