<script setup lang="ts">
import { computed } from 'vue'
import type { FieldDef } from '../types'
import ReferencePicker from './ReferencePicker.vue'
import MultiReferencePicker from './MultiReferencePicker.vue'

const props = defineProps<{ field: FieldDef }>()
const model = defineModel<string | string[]>()
const target = computed(() => props.field.referenceType ?? '')
const multiModel = computed<string[]>({
  get: () => (Array.isArray(model.value) ? model.value : []),
  set: (v) => (model.value = v),
})
const singleModel = computed<string | undefined>({
  get: () => (typeof model.value === 'string' ? model.value : undefined),
  set: (v) => (model.value = v),
})
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <MultiReferencePicker
      v-if="field.multiple && target"
      v-model="multiModel"
      :target="target"
      :max-items="field.maxItems"
    />
    <ReferencePicker v-else-if="target" v-model="singleModel" :target="target" />
    <UInput v-else v-model="singleModel" placeholder="Entry UUID" class="w-full" />
  </UFormField>
</template>
