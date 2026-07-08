<script setup lang="ts">
import { computed } from 'vue'
import type { FieldDef } from '../types'

// Freeform color field (StringField format 'color'): a native swatch picker paired
// with a hex input so the value is both easy to pick and precise to type. The stored
// value is a hex string ('#rrggbb'); empty means "unset" (the swatch shows a neutral
// default but does not write until the user actually picks or types).
defineProps<{ field: FieldDef }>()
const model = defineModel<string>()

const swatch = computed<string>(() => (model.value && /^#[0-9a-fA-F]{6}$/.test(model.value) ? model.value : '#000000'))
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <div class="flex items-center gap-2">
      <input
        type="color"
        :value="swatch"
        class="h-8 w-10 shrink-0 cursor-pointer rounded border border-default bg-default p-0.5"
        :aria-label="`${field.name} color`"
        data-test="color-swatch"
        @input="model = ($event.target as HTMLInputElement).value"
      />
      <UInput v-model="model" placeholder="#000000" class="flex-1" />
      <UButton
        v-if="model"
        icon="i-lucide-x"
        color="neutral"
        variant="ghost"
        size="xs"
        aria-label="Clear color"
        @click="model = undefined"
      />
    </div>
  </UFormField>
</template>
