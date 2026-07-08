<script setup lang="ts">
import { computed } from 'vue'

// Visual layout picker for the Columns block (cosmetic, keyed by slug in BlockCard —
// same seam as the navigation `menu` select). Each preset is a `widths` enum value
// like "33-67" or "25-50-25"; the swatch draws one proportional rectangle per
// segment. Selecting a preset sets BOTH `layout` (segment count) and `widths` (the
// ratio) so the two coupled fields can never drift.
const props = defineProps<{
  layout: string
  widths: string
  /** The `widths` enum from the block-type schema, e.g. ['50-50','33-67', …]. */
  presets: string[]
}>()

const emit = defineEmits<{ select: [{ layout: string; widths: string }] }>()

// Effective selection: an explicit ratio, else the equal split for the current
// layout (a fresh 2-col block reads as 50-50, a 3-col as 33-33-33).
const selected = computed(() =>
  props.widths !== '' ? props.widths : (props.layout || '2') === '3' ? '33-33-33' : '50-50',
)

const segments = (preset: string): number[] => preset.split('-').map(Number)
const ratioLabel = (preset: string): string => preset.split('-').join(' / ')

function choose(preset: string): void {
  emit('select', { layout: String(segments(preset).length), widths: preset })
}
</script>

<template>
  <div class="flex flex-wrap gap-2" data-test="columns-layout-picker">
    <button
      v-for="p in presets"
      :key="p"
      type="button"
      class="group flex flex-col items-center gap-1 rounded-md p-1"
      :data-test="`columns-preset-${p}`"
      :aria-pressed="p === selected"
      :aria-label="`Columns ${ratioLabel(p)}`"
      @click="choose(p)"
    >
      <!-- Only the swatch carries the selection ring — never the label. -->
      <span
        class="flex h-7 w-12 items-center gap-0.5 rounded border bg-default p-0.5 transition-colors"
        :class="
          p === selected ? 'border-primary ring-2 ring-primary' : 'border-accented group-hover:border-primary'
        "
      >
        <span
          v-for="(seg, i) in segments(p)"
          :key="i"
          class="h-full rounded-sm bg-accented"
          :style="{ flexGrow: seg, flexBasis: 0 }"
        />
      </span>
      <span class="text-xs" :class="p === selected ? 'text-default' : 'text-muted'">
        {{ ratioLabel(p) }}
      </span>
    </button>
  </div>
</template>
