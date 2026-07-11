<script setup lang="ts">
import { ref } from 'vue'
import type { FieldDef } from '../types'

// A 4-side spacing/radius control (padding, margin, border-radius): top/right/bottom/
// left number inputs in px, with a link toggle that edits all four at once (the
// Elementor pattern). The model is an object holding only the sides that are set; an
// empty object means "unset" (the block falls back to its preset/default).
type Box = { top?: number; right?: number; bottom?: number; left?: number }
const SIDES = ['top', 'right', 'bottom', 'left'] as const
type Side = (typeof SIDES)[number]

defineProps<{ field: FieldDef }>()
const model = defineModel<Box>({ default: () => ({}) })

const linked = ref(true)

function toggleLink(): void {
  linked.value = !linked.value
}

function sideValue(side: Side): number | undefined {
  return model.value?.[side]
}

function setSide(side: Side, raw: number | undefined): void {
  const next: Box = { ...model.value }
  const apply = (s: Side) => {
    if (raw === undefined || Number.isNaN(raw)) delete next[s]
    else next[s] = raw
  }
  if (linked.value) SIDES.forEach(apply)
  else apply(side)
  model.value = next
}

function onInput(side: Side, e: Event): void {
  const v = (e.target as HTMLInputElement).value
  setSide(side, v === '' ? undefined : Number(v))
}
</script>

<template>
  <UFormField :label="field.label ?? field.name" :name="field.name">
    <div class="flex items-stretch gap-2">
      <div class="grid flex-1 grid-cols-4 gap-1">
        <div v-for="side in SIDES" :key="side" class="flex flex-col items-center gap-0.5">
          <UInput
            type="number"
            :model-value="sideValue(side)"
            :min="0"
            placeholder="0"
            :ui="{ base: 'text-center' }"
            :data-test="`box-${side}`"
            @input="onInput(side, $event)"
          />
          <span class="text-[11px] capitalize text-muted">{{ side }}</span>
        </div>
      </div>
      <div class="flex flex-col items-center gap-0.5">
        <UButton
          :icon="linked ? 'i-lucide-link' : 'i-lucide-unlink'"
          :color="linked ? 'primary' : 'neutral'"
          :variant="linked ? 'soft' : 'ghost'"
          size="sm"
          :aria-label="linked ? 'Unlink sides' : 'Link sides'"
          :aria-pressed="linked"
          data-test="box-link"
          @click="toggleLink"
        />
        <span class="text-[11px] text-muted">px</span>
      </div>
    </div>
  </UFormField>
</template>
