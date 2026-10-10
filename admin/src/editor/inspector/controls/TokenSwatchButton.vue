<script setup lang="ts">
// One step of a token scale (TokenScaleControl): its label, and for a colour a swatch of the site's
// value — a checkerboard when there is none to show (transparent, or no colour).
defineProps<{
  token: string
  name: string
  /** The theme's CSS value, for the title. */
  value: string
  label: string
  selected: boolean
  disabled?: boolean
  /** The colour domain: show a swatch. */
  colour: boolean
  /** The swatch fill; null draws the checkerboard. */
  swatch: string | null
}>()
const emit = defineEmits<{ choose: [] }>()
</script>

<template>
  <button
    type="button"
    class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs"
    :class="
      selected
        ? 'border-primary bg-primary/10 font-medium text-primary'
        : 'border-default text-muted hover:text-default'
    "
    :aria-pressed="selected ? 'true' : 'false'"
    :title="value ? `${name}: ${value}` : name"
    :disabled="disabled"
    :data-test="`token-${token}`"
    @click="emit('choose')"
  >
    <span
      v-if="colour"
      class="inline-block size-3 shrink-0 rounded-full ring-1 ring-default"
      :class="{ 'swatch-checker': swatch === null }"
      :style="swatch === null ? {} : { background: swatch }"
      data-test="swatch"
    />
    {{ label }}
  </button>
</template>

<style scoped>
/* Transparent, or a colour with nothing behind it: a checkerboard. */
.swatch-checker {
  background: repeating-conic-gradient(#ccc 0 25%, #fff 0 50%) 0 0 / 6px 6px;
}
</style>
