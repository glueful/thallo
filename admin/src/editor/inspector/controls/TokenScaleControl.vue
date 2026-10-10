<script setup lang="ts">
// A token property's control (visual builder spec §3.4): the ordinal scale of one vocabulary
// domain as a segmented control, each step previewing the active theme's value in its title.
// The colour domain (custom palette spec §5.2) shows a swatch and a name for every colour — the
// author's names for brand colours — hides brand slots that are unset or being replaced from new
// choices, and says when the stored colour is unavailable.
import { computed, ref } from 'vue'
import { contrast } from '@/style/contrast'
import type { StyleSchemaResult } from '@/queries/styleSchema'

const props = defineProps<{
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
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string]; clear: [] }>()

const items = computed(() =>
  props.names.map((name) => {
    const token = `${props.domain}.${name}`
    return { name, token, value: props.values[token] ?? '' }
  }),
)

const colour = computed(() => props.domain === 'color' && props.palette !== undefined)

function slotOf(token: string): number | null {
  const m = /^color\.brand-([123])(?:-contrast)?$/.exec(token)
  return m ? Number(m[1]) : null
}
function slot(n: number) {
  return props.palette?.slots[`brand-${n}`]
}

/** New choices: every colour but a brand slot that is unset or being replaced (reserved ones stay). */
const visible = computed(() =>
  items.value.filter((item) => {
    if (!colour.value) return true
    const n = slotOf(item.token)
    return n === null || slot(n)?.state === 'configured'
  }),
)

const storedSlot = computed(() =>
  props.modelValue && colour.value ? slotOf(props.modelValue) : null,
)
/** The stored colour names a slot nobody configures: it applies no colour. */
const unavailable = computed(() =>
  storedSlot.value !== null && (slot(storedSlot.value)?.state ?? 'unset') === 'unset'
    ? storedSlot.value
    : null,
)
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

const first = ref<HTMLButtonElement[]>([])
function chooseAnother(): void {
  first.value[0]?.focus()
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
        <span class="font-medium">Unavailable colour: Brand {{ unavailable }}</span>
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
    <div class="flex flex-wrap gap-1" role="group" :aria-label="domain">
      <button
        v-for="item in visible"
        :key="item.token"
        ref="first"
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs"
        :class="
          item.token === modelValue
            ? 'border-primary bg-primary/10 font-medium text-primary'
            : 'border-default text-muted hover:text-default'
        "
        :aria-pressed="item.token === modelValue ? 'true' : 'false'"
        :title="item.value ? `${item.name}: ${item.value}` : item.name"
        :disabled="disabled"
        :data-test="`token-${item.token}`"
        @click="emit('update:modelValue', item.token)"
      >
        <span
          v-if="colour"
          class="inline-block size-3 shrink-0 rounded-full ring-1 ring-default"
          :class="{ 'swatch-checker': swatchOf(item.token) === null }"
          :style="swatchStyle(item.token)"
          data-test="swatch"
        />
        {{ labelOf(item.token, item.name) }}
      </button>
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
