<script setup lang="ts">
// The Style tab (visual builder spec §3.4): controls generated from the block type's
// `style_capabilities`, grouped as spacing, size, typography, colours, effects and visibility.
import { computed } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { StylePropertyRow, StyleSchemaResult } from '@/queries/styleSchema'
import type { Breakpoint, StyleClassRef, StyleValue } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import ResponsiveField from './controls/ResponsiveField.vue'

const props = defineProps<{
  block: BlockInstance
  blockType: BlockType | null
  schema: StyleSchemaResult
  classes: StyleClassRef[]
  activeBreakpoint: Breakpoint
  classNames?: Record<string, string>
  /** A generation change: inherited values re-resolve before they are trusted (spec §4.3). */
  reResolving?: boolean
}>()
const emit = defineEmits<{
  set: [path: string, breakpoint: Breakpoint | null, value: StyleValue | null]
  'set-all': [path: string, value: StyleValue]
  'update:activeBreakpoint': [breakpoint: Breakpoint]
  /** Lift the block's explicit declarations into a new class (spec §4.5). */
  'save-as-class': []
}>()

const GROUPS: { key: string; label: string; match: (row: StylePropertyRow) => boolean }[] = [
  { key: 'spacing', label: 'Spacing', match: (r) => r.group === 'spacing' },
  { key: 'size', label: 'Size', match: (r) => r.group === 'width' || r.group === 'alignment' },
  { key: 'typography', label: 'Typography', match: (r) => r.group === 'typography' },
  { key: 'colors', label: 'Colours', match: (r) => r.group === 'colors' },
  {
    key: 'effects',
    label: 'Effects',
    match: (r) => r.group === 'radius' || r.group === 'shadow' || r.group === 'border',
  },
  { key: 'visibility', label: 'Visibility', match: (r) => r.group === 'visibility' },
]

const LABELS: Record<string, string> = {
  'spacing.padding.top': 'Padding top',
  'spacing.padding.right': 'Padding right',
  'spacing.padding.bottom': 'Padding bottom',
  'spacing.padding.left': 'Padding left',
  'spacing.margin.top': 'Margin top',
  'spacing.margin.bottom': 'Margin bottom',
  width: 'Width',
  'alignment.text': 'Text alignment',
  'alignment.content': 'Content alignment',
  'alignment.self': 'Placement',
  'typography.size': 'Size',
  'typography.weight': 'Weight',
  visibility: 'Visibility',
  shadow: 'Shadow',
  radius: 'Corners',
  'colors.surface': 'Background',
  'colors.text': 'Text colour',
  'colors.border': 'Border colour',
  'border.width': 'Border width',
  'border.style': 'Border style',
}

/** The capability paths: an entry names a path, or a group that expands to its paths. */
const allowed = computed<Set<string>>(() => {
  const out = new Set<string>()
  const byPath = new Set(props.schema.properties.map((r) => r.path))
  for (const entry of props.blockType?.style_capabilities ?? []) {
    if (byPath.has(entry)) out.add(entry)
    else for (const row of props.schema.properties) if (row.group === entry) out.add(row.path)
  }
  return out
})

const groups = computed(() =>
  GROUPS.map((g) => ({
    ...g,
    rows: props.schema.properties.filter((r) => allowed.value.has(r.path) && g.match(r)),
  })).filter((g) => g.rows.length > 0),
)

const style = computed<Record<string, unknown>>(() => {
  const s = props.block.settings?.style
  return typeof s === 'object' && s !== null ? (s as Record<string, unknown>) : {}
})
</script>

<template>
  <div class="space-y-4" data-test="style-tab">
    <p v-if="groups.length === 0" class="text-xs text-muted" data-test="style-none">
      This block declares no styling.
    </p>
    <template v-else>
      <section v-for="group in groups" :key="group.key" :data-test="`style-group-${group.key}`">
        <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">
          {{ group.label }}
        </h4>
        <div class="space-y-3">
          <ResponsiveField
            v-for="row in group.rows"
            :key="row.path"
            :def="row"
            :label="LABELS[row.path] ?? row.path"
            :style="style"
            :classes="classes"
            :class-names="classNames"
            :re-resolving="reResolving"
            :active-breakpoint="activeBreakpoint"
            :vocabulary="schema.vocabulary"
            @set="(path, bp, value) => emit('set', path, bp, value)"
            @set-all="(path, value) => emit('set-all', path, value)"
            @update:active-breakpoint="(bp) => emit('update:activeBreakpoint', bp)"
          />
        </div>
      </section>
      <div class="border-t border-default pt-3">
        <UButton
          size="xs"
          variant="ghost"
          color="neutral"
          icon="i-lucide-paintbrush"
          :disabled="Object.keys(style).length === 0"
          data-test="save-as-style-class"
          title="Move this block's own declarations into a reusable style class"
          @click="emit('save-as-class')"
        >
          Save as style class
        </UButton>
      </div>
    </template>
  </div>
</template>
