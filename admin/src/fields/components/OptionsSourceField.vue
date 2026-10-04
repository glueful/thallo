<script setup lang="ts">
import { computed, toRef } from 'vue'
import type { FieldDef } from '../types'
import { useFieldOptions } from '@/queries/fieldOptions'
import UnavailableChoice from './UnavailableChoice.vue'
import { fromSelectValue, optionItems, toSelectValue } from '../optionsSourceItems'

// A string field whose choices come from the server (`options_source`, search block spec §3.9).
// The stored value is never rewritten: while the choices load, if they fail to load, or if the
// stored one is no longer available, it is shown as it is and saved unchanged.
const props = defineProps<{ field: FieldDef & { optionsSource: string }; modelValue?: unknown }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { data, status } = useFieldOptions(toRef(() => props.field.optionsSource))
const stored = computed(() => (typeof props.modelValue === 'string' ? props.modelValue : ''))
const match = computed(() => data.value?.find((o) => o.value === stored.value))
const unavailable = computed(() => {
  if (status.value !== 'success') return { kind: 'loading' as const }
  if (!match.value) return stored.value === '' ? null : { kind: 'removed' as const }
  return match.value.available
    ? null
    : { kind: 'disabled' as const, label: match.value.label, reason: match.value.reason }
})
const items = computed(() => optionItems(data.value ?? []))
const selected = computed(() => toSelectValue(stored.value))
const choose = (v: unknown) => emit('update:modelValue', fromSelectValue(v))
</script>

<template>
  <UFormField :label="field.label ?? field.name" :name="field.name">
    <div class="space-y-1.5">
      <UnavailableChoice
        v-if="unavailable"
        :state="unavailable"
        :value="stored"
        :data-test="`options-source-${field.name}`"
      />
      <USelect
        v-if="status === 'success'"
        :model-value="selected"
        :items="items"
        class="w-full"
        :data-test="`options-source-select-${field.name}`"
        @update:model-value="choose"
      />
    </div>
  </UFormField>
</template>
