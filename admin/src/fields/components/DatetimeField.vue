<script setup lang="ts">
import type { FieldDef } from '../types'
import DateTimePicker from '@/components/DateTimePicker.vue'

defineProps<{ field: FieldDef }>()
// The backend stores a canonical ISO-8601 UTC string; DateTimePicker bridges date + time to it.
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <DateTimePicker v-model="model" />
  </UFormField>
</template>
