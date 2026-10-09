<script setup lang="ts">
// A page's own style frame (the Page tab's Styles, and a layout's Frame tab): padding, margin and
// background stored in a block's style shape, painted on <main> by the render with the same
// utility classes. The rows are fixed — the page owns exactly these capabilities. It edits a style
// record and hands back a new one; an empty record means nothing is set.
import { watch } from 'vue'
import { readPath, setPath, settingSegments } from '@/editor/ops/apply'
import type { StylePropertyRow } from '@/queries/styleSchema'
import BoxField from '@/editor/inspector/controls/BoxField.vue'
import ResponsiveField from '@/editor/inspector/controls/ResponsiveField.vue'
import { BREAKPOINT_LABELS } from '@/editor/breakpoint'
import type { Breakpoint, StyleValue } from '@/style/types'

const props = defineProps<{
  style: Record<string, unknown>
  vocabulary: { domains: Record<string, string[]>; values: Record<string, string> }
  activeBreakpoint: Breakpoint
}>()
const emit = defineEmits<{
  'update:style': [style: Record<string, unknown>]
  'update:activeBreakpoint': [bp: Breakpoint]
}>()

const ROWS: StylePropertyRow[] = [
  ...['top', 'right', 'bottom', 'left'].map((s) => ({
    path: `spacing.padding.${s}`,
    group: 'spacing',
    kinds: ['token', 'reset'],
    responsive: true,
    token_domain: 'spacing',
    choices: null,
  })),
  ...['top', 'bottom'].map((s) => ({
    path: `spacing.margin.${s}`,
    group: 'spacing',
    kinds: ['token', 'reset'],
    responsive: true,
    token_domain: 'spacing',
    choices: null,
  })),
  {
    path: 'colors.surface',
    group: 'colors',
    kinds: ['token', 'reset'],
    // Colours are one value for every breakpoint in the style contract.
    responsive: false,
    token_domain: 'color',
    choices: null,
  },
] as StylePropertyRow[]
const sides = (prefix: string) =>
  ROWS.filter((r) => r.path.startsWith(prefix)).map((def) => ({
    key: def.path.slice(prefix.length),
    def,
  }))
const backgroundRow = ROWS[ROWS.length - 1]!

// The record the next write builds on. A linked box writes its sides one after another, before
// the parent's new record comes back down as the prop, so each write builds on the one before.
let working = props.style
watch(
  () => props.style,
  (style) => (working = style),
)
function write(next: Record<string, unknown>): void {
  working = next
  emit('update:style', next)
}
function onSet(path: string, bp: Breakpoint | null, value: StyleValue | null): void {
  write(
    setPath(
      working,
      settingSegments(path, bp).slice(1),
      value === null ? { present: false } : { present: true, value },
    ) as Record<string, unknown>,
  )
}
function onSetAll(path: string, value: StyleValue): void {
  let next = working
  for (const bp of ['base', 'md', 'lg'] as const) {
    next = setPath(next, settingSegments(path, bp).slice(1), { present: true, value }) as Record<
      string,
      unknown
    >
  }
  write(next)
}
function declaredAt(bp: Breakpoint): boolean {
  return ROWS.some((row) => readPath(props.style, settingSegments(row.path, bp).slice(1)).present)
}
</script>

<template>
  <div class="space-y-3 border-t border-default pt-4" data-test="page-styles">
    <div class="flex items-center justify-between gap-2">
      <h4 class="text-[11px] font-semibold tracking-wide text-muted uppercase">Styles</h4>
      <div class="flex gap-0.5" role="group" aria-label="Breakpoint">
        <button
          v-for="bp in ['base', 'md', 'lg'] as const"
          :key="bp"
          type="button"
          class="relative rounded px-1.5 py-0.5 text-[10px]"
          :class="
            bp === activeBreakpoint ? 'bg-primary text-inverted' : 'text-muted hover:text-default'
          "
          :aria-pressed="bp === activeBreakpoint ? 'true' : 'false'"
          :title="BREAKPOINT_LABELS[bp]"
          :data-test="`page-breakpoint-${bp}`"
          @click="emit('update:activeBreakpoint', bp)"
        >
          {{ bp }}
          <span
            v-if="declaredAt(bp)"
            class="absolute -top-0.5 -right-0.5 size-1.5 rounded-full bg-warning"
            aria-hidden="true"
          />
        </button>
      </div>
    </div>
    <BoxField
      label="Padding"
      :sides="sides('spacing.padding.')"
      :style="style"
      :classes="[]"
      :active-breakpoint="activeBreakpoint"
      :vocabulary="vocabulary"
      @set="onSet"
      @set-all="onSetAll"
    />
    <BoxField
      label="Margin"
      :sides="sides('spacing.margin.')"
      :style="style"
      :classes="[]"
      :active-breakpoint="activeBreakpoint"
      :vocabulary="vocabulary"
      @set="onSet"
      @set-all="onSetAll"
    />
    <ResponsiveField
      :def="backgroundRow"
      label="Background"
      :style="style"
      :classes="[]"
      :active-breakpoint="activeBreakpoint"
      :vocabulary="vocabulary"
      hide-breakpoints
      @set="onSet"
      @set-all="onSetAll"
      @update:active-breakpoint="(bp: Breakpoint) => emit('update:activeBreakpoint', bp)"
    />
    <p class="text-xs text-muted"><slot /></p>
  </div>
</template>
