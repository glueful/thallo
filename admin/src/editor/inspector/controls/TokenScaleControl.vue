<script setup lang="ts">
// A token property's control (visual builder spec §3.4): the ordinal scale of one vocabulary
// domain as a segmented control, each step previewing the active theme's value in its title.
// The colour domain (custom palette spec §5.2) shows a swatch and a name for every colour. Brand
// colours are their own group after the theme's, in the author's order, with their text colours
// folded behind a disclosure (open when the stored value is one); a manager gets a link to
// Appearance's Colours tab. Removed, never-issued and replacing brand colours are hidden from new
// choices, and a stored one that applies nothing is named by what it was.
import { computed, ref, watch } from 'vue'
import { contrast } from '@/style/contrast'
import type { StyleSchemaResult } from '@/queries/styleSchema'
import TokenSwatchButton from './TokenSwatchButton.vue'

const props = withDefaults(
  defineProps<{
    /** The vocabulary domain, e.g. `spacing`. */
    domain: string
    /** The domain's names in ordinal order. */
    names: string[]
    /** Token name (`spacing.lg`) => the theme's CSS value, for the preview title. */
    values: Record<string, string>
    /** The selected token name (`spacing.lg`), or null when none is set. */
    modelValue: string | null
    disabled?: boolean
    /** The site's palette (the style schema's): swatches, labels and brand slot states. */
    palette?: StyleSchemaResult['palette']
    /** The block sits in a Style block that re-skins accent or neutral: swatches show the site's. */
    scopedSkin?: boolean
    /** Offer the Manage brand colours link (default); off where the picker already sits in Appearance. */
    manageLink?: boolean
  }>(),
  { manageLink: true },
)
const emit = defineEmits<{ 'update:modelValue': [value: string]; clear: [] }>()

const items = computed(() =>
  props.names.map((name) => {
    const token = `${props.domain}.${name}`
    return { name, token, value: props.values[token] ?? '' }
  }),
)

const colour = computed(() => props.domain === 'color' && props.palette !== undefined)

const BRAND = /^color\.brand-(\d+)(?:-contrast)?$/
function slotOf(token: string): number | null {
  const m = BRAND.exec(token)
  return m ? Number(m[1]) : null
}
function slot(n: number) {
  return props.palette?.slots[`brand-${n}`]
}
const isText = (token: string) => slotOf(token) !== null && token.endsWith('-contrast')

/** New choices: a brand colour only while configured (reserved ones stay); never one removed or replaced. */
const visible = computed(() =>
  items.value.filter((item) => {
    if (!colour.value) return true
    const n = slotOf(item.token)
    return n === null || slot(n)?.state === 'configured'
  }),
)
const themeItems = computed(() => visible.value.filter((i) => slotOf(i.token) === null))
const brandItems = computed(() =>
  visible.value.filter((i) => slotOf(i.token) !== null && !isText(i.token)),
)
const brandTexts = computed(() => visible.value.filter((i) => isText(i.token)))
const canManage = computed(() => props.palette?.can_manage === true && props.manageLink)
/** The group shows while brand colours are on and there is a colour to offer or a manager to add one. */
const showBrandGroup = computed(
  () =>
    colour.value &&
    (props.palette?.limit ?? 0) > 0 &&
    (brandItems.value.length > 0 || canManage.value),
)
const showTexts = ref(props.modelValue !== null && isText(props.modelValue))
watch(
  () => props.modelValue,
  (v) => {
    if (v !== null && isText(v)) showTexts.value = true
  },
)

const storedSlot = computed(() =>
  props.modelValue && colour.value ? slotOf(props.modelValue) : null,
)
/** The stored colour names an id nothing configures — removed, never issued, or brand colours off. */
const unavailable = computed<string | null>(() => {
  const n = storedSlot.value
  if (n === null) return null
  const s = slot(n)
  if (s?.state === 'configured' || s?.state === 'replacing') return null
  if (s?.state === 'removed') return `${s.name} (removed)`
  return props.palette?.labels[`color.brand-${n}`] ?? `Brand ${n}`
})
/** The stored colour's slot is being replaced: where it is going. */
const replacing = computed(() =>
  storedSlot.value !== null && slot(storedSlot.value)?.state === 'replacing'
    ? (slot(storedSlot.value)?.replacing ?? null)
    : null,
)

/** A colour's swatch fill; null draws the checkerboard (transparent, or no colour). */
function swatchOf(token: string): string | null {
  const n = slotOf(token)
  if (n === null) return props.palette?.swatches[token] ?? null
  const hex = slot(n)?.hex ?? null
  if (hex === null) return null
  if (!token.endsWith('-contrast')) return hex
  // the server's rule for a brand colour's text: whichever of black or white reads better on it
  return contrast(hex, '#000000') > contrast(hex, '#ffffff') ? '#000000' : '#ffffff'
}

function swatchStyle(token: string): Record<string, string> {
  const fill = swatchOf(token)
  return fill === null ? {} : { background: fill }
}

function labelOf(token: string, name: string): string {
  return colour.value ? (props.palette?.labels[token] ?? name) : name
}

/** The theme's colours: Choose another moves focus to the first of them. */
const themeGroup = ref<HTMLElement | null>(null)
function chooseAnother(): void {
  themeGroup.value?.querySelector('button')?.focus()
}
</script>

<template>
  <div class="space-y-2">
    <div
      v-if="unavailable !== null"
      class="space-y-1 rounded-md border border-warning/40 bg-warning/5 p-2 text-xs"
      data-test="unavailable-colour"
    >
      <button type="button" disabled class="flex items-center gap-2 text-left text-default">
        <span class="swatch-checker inline-block size-3 rounded-full ring-1 ring-default" />
        <span class="font-medium">Unavailable colour: {{ unavailable }}</span>
      </button>
      <p class="text-muted">No colour applied</p>
      <div class="flex gap-2">
        <UButton
          size="xs"
          variant="outline"
          color="neutral"
          data-test="unavailable-choose"
          @click="chooseAnother"
        >
          Choose another
        </UButton>
        <UButton
          size="xs"
          variant="ghost"
          color="neutral"
          data-test="unavailable-clear"
          @click="emit('clear')"
        >
          Clear
        </UButton>
      </div>
    </div>
    <p
      v-if="replacing"
      class="flex items-center gap-2 text-xs text-muted"
      data-test="replacing-colour"
    >
      <span
        class="inline-block size-3 rounded-full ring-1 ring-default"
        :class="{ 'swatch-checker': swatchOf(modelValue ?? '') === null }"
        :style="swatchStyle(modelValue ?? '')"
      />
      {{ labelOf(modelValue ?? '', '') }} — being replaced by {{ replacing.to_label }}
    </p>
    <div ref="themeGroup" class="flex flex-wrap gap-1" role="group" :aria-label="domain">
      <TokenSwatchButton
        v-for="item in themeItems"
        :key="item.token"
        :token="item.token"
        :name="item.name"
        :value="item.value"
        :label="labelOf(item.token, item.name)"
        :selected="item.token === modelValue"
        :disabled="disabled"
        :colour="colour"
        :swatch="swatchOf(item.token)"
        @choose="emit('update:modelValue', item.token)"
      />
    </div>
    <div v-if="showBrandGroup" class="space-y-1" data-test="brand-group">
      <div class="flex items-center justify-between text-[11px] text-dimmed">
        <span>Brand colours</span>
        <RouterLink
          v-if="canManage"
          to="/appearance?tab=colours"
          class="text-primary hover:underline"
          data-test="manage-brand-colours"
        >
          Manage brand colours
        </RouterLink>
      </div>
      <div
        v-if="brandItems.length > 0"
        class="flex flex-wrap gap-1"
        role="group"
        aria-label="Brand colours"
      >
        <TokenSwatchButton
          v-for="item in brandItems"
          :key="item.token"
          :token="item.token"
          :name="item.name"
          :value="item.value"
          :label="labelOf(item.token, item.name)"
          :selected="item.token === modelValue"
          :disabled="disabled"
          :colour="colour"
          :swatch="swatchOf(item.token)"
          @choose="emit('update:modelValue', item.token)"
        />
      </div>
      <button
        v-if="brandTexts.length > 0"
        type="button"
        class="text-[11px] text-muted hover:text-default"
        :aria-expanded="showTexts ? 'true' : 'false'"
        data-test="brand-text-toggle"
        @click="showTexts = !showTexts"
      >
        Text colours
      </button>
      <div
        v-if="showTexts && brandTexts.length > 0"
        class="flex flex-wrap gap-1"
        role="group"
        aria-label="Brand text colours"
        data-test="brand-text-colours"
      >
        <TokenSwatchButton
          v-for="item in brandTexts"
          :key="item.token"
          :token="item.token"
          :name="item.name"
          :value="item.value"
          :label="labelOf(item.token, item.name)"
          :selected="item.token === modelValue"
          :disabled="disabled"
          :colour="colour"
          :swatch="swatchOf(item.token)"
          @choose="emit('update:modelValue', item.token)"
        />
      </div>
    </div>
    <p v-if="colour && scopedSkin" class="text-[11px] text-dimmed" data-test="swatch-site-default">
      Site default: this block's Style re-skins accent and neutral; brand colours stay as shown.
    </p>
  </div>
</template>

<style scoped>
/* Transparent, or a colour with nothing behind it: a checkerboard. */
.swatch-checker {
  background: repeating-conic-gradient(#ccc 0 25%, #fff 0 50%) 0 0 / 6px 6px;
}
</style>
