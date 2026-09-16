<script setup lang="ts">
// The Style tab (visual builder spec §3.4): controls generated from the block type's
// `style_capabilities`, grouped as spacing, size, typography, colours, effects and visibility.
import { computed } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { StylePropertyRow, StyleSchemaResult } from '@/queries/styleSchema'
import type { Breakpoint, StyleClassRef, StyleValue } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import ResponsiveField from './controls/ResponsiveField.vue'
import { readPath, settingSegments } from '@/editor/ops/apply'
import { BREAKPOINTS } from '@/style/types'
import { isFolded, toggleFold } from './styleGroupFolds'

const props = defineProps<{
  block: BlockInstance
  blockType: BlockType | null
  schema: StyleSchemaResult
  classes: StyleClassRef[]
  activeBreakpoint: Breakpoint
  classNames?: Record<string, string>
  /** A generation change: inherited values re-resolve before they are trusted (spec §4.3). */
  reResolving?: boolean
  /** A sibling multi-selection (spec §5.5): `block` is its anchor. */
  blocks?: BlockInstance[]
  blockTypes?: (BlockType | null)[]
}>()

const multi = computed(() => (props.blocks?.length ?? 0) > 1)
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

/** The capability paths of one type: an entry names a path, or a group that expands to its paths. */
function pathsOf(type: BlockType | null): Set<string> {
  const out = new Set<string>()
  const byPath = new Set(props.schema.properties.map((r) => r.path))
  for (const entry of type?.style_capabilities ?? []) {
    if (byPath.has(entry)) out.add(entry)
    else for (const row of props.schema.properties) if (row.group === entry) out.add(row.path)
  }
  return out
}

/** The paths every selected block declares: a property any block lacks renders no control. */
const allowed = computed<Set<string>>(() => {
  const types = multi.value ? (props.blockTypes ?? []) : [props.blockType]
  if (types.length === 0) return new Set()
  const [first, ...rest] = types.map(pathsOf)
  return new Set([...first!].filter((path) => rest.every((set) => set.has(path))))
})

const groups = computed(() =>
  GROUPS.map((g) => ({
    ...g,
    rows: props.schema.properties.filter((r) => allowed.value.has(r.path) && g.match(r)),
  })).filter((g) => g.rows.length > 0),
)

function styleOf(block: BlockInstance): Record<string, unknown> {
  const s = block.settings?.style
  return typeof s === 'object' && s !== null ? (s as Record<string, unknown>) : {}
}
const style = computed<Record<string, unknown>>(() => styleOf(props.block))
const styles = computed(() => (multi.value ? (props.blocks ?? []).map(styleOf) : undefined))

/** How many of a group's properties this block declares at any breakpoint: a folded group's cue. */
function setCount(rows: StylePropertyRow[]): number {
  return rows.filter((row) =>
    BREAKPOINTS.some((bp) => readPath(style.value, settingSegments(row.path, bp).slice(1)).present),
  ).length
}
</script>

<template>
  <div class="space-y-4" data-test="style-tab">
    <p v-if="groups.length === 0" class="text-xs text-muted" data-test="style-none">
      This block declares no styling.
    </p>
    <template v-else>
      <section v-for="group in groups" :key="group.key" :data-test="`style-group-${group.key}`">
        <h4 class="mb-2">
          <button
            type="button"
            class="flex w-full items-center gap-1 rounded text-[11px] font-semibold uppercase tracking-wide text-muted hover:text-default focus-visible:outline-2 focus-visible:outline-primary"
            :aria-expanded="!isFolded(group.key)"
            :aria-controls="`style-group-body-${group.key}`"
            :data-test="`style-group-toggle-${group.key}`"
            @click="toggleFold(group.key)"
          >
            <UIcon
              :name="isFolded(group.key) ? 'i-lucide-chevron-right' : 'i-lucide-chevron-down'"
              class="size-3.5 shrink-0"
            />
            <span>{{ group.label }}</span>
            <span
              v-if="isFolded(group.key) && setCount(group.rows) > 0"
              class="ms-auto rounded-full bg-elevated px-1.5 py-0.5 text-[10px] font-medium normal-case tracking-normal"
              :data-test="`style-group-count-${group.key}`"
            >
              {{ setCount(group.rows) }} set
            </span>
          </button>
        </h4>
        <div v-if="!isFolded(group.key)" :id="`style-group-body-${group.key}`" class="space-y-3">
          <ResponsiveField
            v-for="row in group.rows"
            :key="row.path"
            :def="row"
            :label="LABELS[row.path] ?? row.path"
            :style="style"
            :styles="styles"
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
      <div v-if="!multi" class="border-t border-default pt-3">
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
