<script setup lang="ts">
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
const model = defineModel<boolean>()
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <USwitch v-model="model" />
  </UFormField>
</template>
