<script setup lang="ts">
// Compact icon field (icon-picker spec §4): preview + name + Choose, opening
// the picker modal. format decides the set; for brand-icon fields the STORED
// value carries the brand: prefix (the pattern demands it — icon-picker spec
// §7/§8) while the picker displays bare names.
import { computed, ref } from 'vue'
import type { FieldDef } from '../types'
import { useIcons } from '@/queries/icons'
import IconPickerModal from './IconPickerModal.vue'

const props = defineProps<{ field: FieldDef }>()
const model = defineModel<string>()

const brand = computed(() => props.field.format === 'brand-icon')
const display = computed(() =>
  brand.value ? (model.value ?? '').replace(/^brand:/, '') || undefined : model.value || undefined,
)
// Brand previews come from the VENDORED svgs (the admin has no simple-icons
// pipeline); lucide previews use the admin's own i-lucide-* set.
const { data: inventory } = useIcons(() => (brand.value ? 'brands' : 'lucide'))
const brandSvg = computed(() =>
  brand.value && display.value ? inventory.value?.svgs[display.value] : undefined,
)

const pickerOpen = ref(false)

function onSelect(name: string): void {
  model.value = brand.value ? `brand:${name}` : name
}
function onClear(): void {
  model.value = undefined
}
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <div class="flex items-center gap-2">
      <template v-if="display">
        <span
          v-if="brand && brandSvg"
          class="inline-flex size-5 shrink-0 items-center justify-center [&>svg]:h-full [&>svg]:w-full"
          v-html="brandSvg"
        />
        <UIcon v-else-if="!brand" :name="`i-lucide-${display}`" class="size-5 shrink-0" />
        <code class="truncate text-sm text-muted" data-test="icon-field-name">{{ display }}</code>
      </template>
      <span v-else class="text-sm text-muted">No icon</span>
      <span class="grow" />
      <UButton
        size="xs"
        variant="subtle"
        color="neutral"
        data-test="icon-field-choose"
        @click="()=>{pickerOpen = true}"
      >
        Choose
      </UButton>
      <UButton
        v-if="display && !field.required"
        size="xs"
        variant="ghost"
        color="neutral"
        icon="i-lucide-x"
        aria-label="Clear icon"
        data-test="icon-field-clear"
        @click="onClear()"
      />
    </div>

    <IconPickerModal
      v-model:open="pickerOpen"
      :set="brand ? 'brands' : 'lucide'"
      :model-value="display"
      @select="onSelect"
      @clear="onClear"
    />
  </UFormField>
</template>
