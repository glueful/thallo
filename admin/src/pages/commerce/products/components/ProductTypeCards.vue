<script lang="ts">
// The product-type radio cards — ONE component for both surfaces that show them (user request
// 2026-07-25): the Omnibox Launcher (create) and the Details card (edit, where a locked type
// renders every card disabled with the current one still marked). OUR vocabulary: variants live
// INSIDE physical/digital — never Woo's "variable product". `TYPE_CARDS` is exported so the
// launcher's 1-4 keyboard shortcuts can map digits to types without re-declaring the row.
import type { CommerceProductType } from '@/queries/commerceCatalog'

export const TYPE_CARDS: ReadonlyArray<{
  type: CommerceProductType
  icon: string
  label: string
  teach: string
}> = [
  { type: 'physical', icon: 'i-lucide-package', label: 'Physical', teach: 'shipped, stocked' },
  { type: 'digital', icon: 'i-lucide-download', label: 'Digital', teach: 'downloads' },
  { type: 'external', icon: 'i-lucide-external-link', label: 'External', teach: 'sold elsewhere' },
  { type: 'grouped', icon: 'i-lucide-boxes', label: 'Grouped', teach: 'a bundle' },
]
</script>

<script setup lang="ts">
const props = defineProps<{
  /** Renders every card inert (dimmed except the selected one) — the locked-type edit case. */
  disabled?: boolean
}>()

const model = defineModel<CommerceProductType>({ required: true })

function pick(type: CommerceProductType): void {
  if (props.disabled) return
  model.value = type
}
</script>

<template>
  <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="Product type">
    <button
      v-for="card in TYPE_CARDS"
      :key="card.type"
      type="button"
      role="radio"
      :aria-checked="model === card.type"
      :disabled="disabled"
      :data-test="`type-card-${card.type}`"
      class="rounded-lg border p-2.5 text-center transition"
      :class="[
        model === card.type ? 'border-primary shadow-md' : 'border-default',
        disabled
          ? model === card.type
            ? 'cursor-not-allowed'
            : 'cursor-not-allowed opacity-40'
          : 'hover:border-accented',
      ]"
      @click="pick(card.type)"
    >
      <UIcon
        :name="card.icon"
        class="mx-auto size-5"
        :class="model === card.type ? 'text-primary' : 'text-muted'"
      />
      <div class="mt-1 text-xs font-bold">{{ card.label }}</div>
      <div class="text-[0.65rem] text-muted">{{ card.teach }}</div>
    </button>
  </div>
</template>
