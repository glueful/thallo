<script setup lang="ts">
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <USelect v-model="model" :items="field.enum ?? []" class="w-full" />
  </UFormField>
</template>
