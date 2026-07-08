<script setup lang="ts">
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
const model = defineModel<number>()
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <UInputNumber v-model="model" class="w-full" />
  </UFormField>
</template>
