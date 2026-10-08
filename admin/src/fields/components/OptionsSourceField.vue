<script setup lang="ts">
import { computed, toRef } from 'vue'
import type { FieldDef } from '../types'
import { useFieldOptions } from '@/queries/fieldOptions'
import UnavailableChoice from './UnavailableChoice.vue'
import { fromSelectValue, optionItems, toSelectValue } from '../optionsSourceItems'

// A string field whose choices come from the server (`options_source`, search block spec §3.9).
// The stored value is never rewritten: while the choices load, if they fail to load, or if a
// stored one is no longer available, it is shown as it is and saved unchanged. With `multiple`
// (product grid spec §5.3) the value is a list and the control a multi-select.
const props = defineProps<{ field: FieldDef & { optionsSource: string }; modelValue?: unknown }>()
const emit = defineEmits<{ 'update:modelValue': [value: string | string[]] }>()

const { data, status } = useFieldOptions(toRef(() => props.field.optionsSource))
const items = computed(() => optionItems(data.value ?? []))

const stored = computed(() => (typeof props.modelValue === 'string' ? props.modelValue : ''))
const match = computed(() => data.value?.find((o) => o.value === stored.value))
const unavailable = computed(() => {
  if (status.value !== 'success') return { kind: 'loading' as const }
  if (!match.value) return stored.value === '' ? null : { kind: 'removed' as const }
  return match.value.available
    ? null
    : { kind: 'disabled' as const, label: match.value.label, reason: match.value.reason }
})
const selected = computed(() => toSelectValue(stored.value))
const choose = (v: unknown) => emit('update:modelValue', fromSelectValue(v))

const storedList = computed<string[]>(() =>
  Array.isArray(props.modelValue)
    ? props.modelValue.filter((v): v is string => typeof v === 'string')
    : [],
)
const unavailableInList = computed(() =>
  status.value !== 'success'
    ? []
    : storedList.value.filter((v) => !data.value?.some((o) => o.value === v && o.available)),
)
// At most `maxItems` (the server refuses more): a choice past the limit is not taken.
const atLimit = computed(
  () => props.field.maxItems !== undefined && storedList.value.length >= props.field.maxItems,
)
// A stored value that is no longer a choice has no row in the select to clear: it is removed here.
const remove = (value: string) =>
  emit(
    'update:modelValue',
    storedList.value.filter((v) => v !== value),
  )
const chooseMany = (v: unknown) => {
  const next = Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : []
  const max = props.field.maxItems
  emit('update:modelValue', max !== undefined && next.length > max ? next.slice(0, max) : next)
}
</script>

<template>
  <UFormField :label="field.label ?? field.name" :name="field.name">
    <div v-if="field.multiple" class="space-y-1.5">
      <div v-for="value in unavailableInList" :key="value" class="flex items-start gap-1">
        <UnavailableChoice
          class="flex-1"
          :state="{ kind: 'gone' }"
          :value="value"
          :data-test="`options-source-${field.name}-${value}`"
        />
        <UButton
          icon="i-lucide-x"
          size="xs"
          color="neutral"
          variant="ghost"
          :aria-label="`Remove ${value}`"
          :data-test="`options-source-remove-${field.name}-${value}`"
          @click="remove(value)"
        />
      </div>
      <USelectMenu
        :model-value="storedList"
        :items="items"
        value-key="value"
        multiple
        class="w-full"
        :loading="status === 'pending'"
        :data-test="`options-source-select-${field.name}`"
        @update:model-value="chooseMany"
      />
      <p
        v-if="atLimit"
        class="text-xs text-muted"
        :data-test="`options-source-limit-${field.name}`"
      >
        At most {{ field.maxItems }}.
      </p>
    </div>
    <div v-else class="space-y-1.5">
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
