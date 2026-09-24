<script setup lang="ts">
// The Style tab (visual builder spec §3.4): controls generated from the block type's
// `style_capabilities`, grouped as spacing, typography, colours, effects and visibility.
//
// Only properties `tabMap` assigns to Style appear here; width, placement and content
// distribution moved to the Layout tab (container-layout spec §5), because how wide a box is and
// how it sits in its parent are layout decisions, not styling ones.
import { computed } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { StylePropertyRow, StyleSchemaResult } from '@/queries/styleSchema'
import type { Breakpoint, StyleClassRef, StyleValue } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import ResponsiveField from './controls/ResponsiveField.vue'
import BoxField from './controls/BoxField.vue'
import { BREAKPOINT_LABELS } from '@/editor/breakpoint'
import { readPath, settingSegments } from '@/editor/ops/apply'
import { BREAKPOINTS } from '@/style/types'
import { isFolded, toggleFold } from './styleGroupFolds'
import { pathsForTab } from './tabMap'

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
  /**
   * A block's inspector (the default), a style class's editor, or a chrome region's Style tab:
   * passed to every field. Only a block offers to save its declarations as a style class — a
   * class already is one, and a region has none.
   */
  context?: 'block' | 'class' | 'region'
  /** A block's inspector on a page with no save-as-class flow (the Regions page). */
  noSaveAsClass?: boolean
  /** The host has a stage that can replay a block's motion: only then is Play offered. */
  canPlayMotion?: boolean
}>()

const multi = computed(() => (props.blocks?.length ?? 0) > 1)
const emit = defineEmits<{
  set: [path: string, breakpoint: Breakpoint | null, value: StyleValue | null]
  'set-all': [path: string, value: StyleValue]
  'update:activeBreakpoint': [breakpoint: Breakpoint]
  /** Lift the block's explicit declarations into a new class (spec §4.5). */
  'save-as-class': []
  /** Play the block's motion once on the stage (it is off while editing). */
  'play-motion': []
}>()

const GROUPS: { key: string; label: string; match: (row: StylePropertyRow) => boolean }[] = [
  { key: 'spacing', label: 'Spacing', match: (r) => r.group === 'spacing' },
  // Alignment splits across tabs: only text alignment is left here.
  { key: 'text', label: 'Text', match: (r) => r.group === 'alignment' },
  { key: 'typography', label: 'Typography', match: (r) => r.group === 'typography' },
  // The backdrop pair modifies the background, so it sits with the colours.
  {
    key: 'colors',
    label: 'Colours',
    match: (r) => r.group === 'colors' || r.group === 'backdrop',
  },
  {
    key: 'effects',
    label: 'Effects',
    match: (r) => r.group === 'radius' || r.group === 'shadow' || r.group === 'border',
  },
  // The block's marker — a feature's icon chip or number badge — beside the block's own Effects.
  { key: 'marker', label: 'Marker', match: (r) => r.group === 'marker' },
  // A tabs block's strip — the bar and the active tab's pill; the block's Effects are the panel's.
  { key: 'tabs', label: 'Tabs', match: (r) => r.group === 'tabs' },
  // A hero's aside — its padding and fill; the aside's corners and shadow are the block's Effects.
  { key: 'aside', label: 'Aside', match: (r) => r.group === 'aside' },
  // How the block enters, how a container spaces out its children's entrances, and Ken Burns:
  // three capability groups, so a block shows only what it can do, under one heading.
  {
    key: 'motion',
    label: 'Motion',
    match: (r) =>
      r.group === 'motion' || r.group === 'motion.children' || r.group === 'motion.media',
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
  'alignment.text': 'Text alignment',
  'typography.size': 'Size',
  'typography.weight': 'Weight',
  'typography.line_height': 'Line height',
  visibility: 'Visibility',
  shadow: 'Shadow',
  radius: 'Corners',
  'marker.radius': 'Corners',
  'marker.shadow': 'Shadow',
  'tabs.bar_radius': 'Bar corners',
  'tabs.tab_radius': 'Active tab corners',
  'aside.surface': 'Background',
  'colors.surface': 'Background',
  'colors.text': 'Text colour',
  'colors.border': 'Border colour',
  'border.width': 'Border width',
  'border.style': 'Border style',
  'border.sides': 'Border sides',
  'motion.entrance': 'Entrance',
  'motion.duration': 'Duration',
  'motion.delay': 'Delay',
  'motion.repeat': 'Repeat',
  'motion.stagger': 'Stagger children',
  'motion.ken_burns': 'Ken Burns',
  'colors.surface_opacity': 'Background opacity',
  'backdrop.blur': 'Backdrop blur',
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
  const shared = [...first!].filter((path) => rest.every((set) => set.has(path)))
  return new Set(pathsForTab(shared, 'style'))
})

/** Four-sided properties present as one box row: the box label and the side each path names. */
const BOXES: { label: string; prefix: string }[] = [
  { label: 'Padding', prefix: 'spacing.padding.' },
  { label: 'Margin', prefix: 'spacing.margin.' },
  // A hero's aside pads a side at a time too, and is drawn the same way.
  { label: 'Padding', prefix: 'aside.padding.' },
]
type Item =
  | { kind: 'box'; label: string; sides: { key: string; def: StylePropertyRow }[] }
  | { kind: 'row'; def: StylePropertyRow }
function itemsOf(rows: StylePropertyRow[]): Item[] {
  const items: Item[] = []
  const boxed = new Set<StylePropertyRow>()
  for (const box of BOXES) {
    const sides = rows
      .filter((r) => r.path.startsWith(box.prefix))
      .map((def) => ({ key: def.path.slice(box.prefix.length), def }))
    if (sides.length === 0) continue
    for (const s of sides) boxed.add(s.def)
    items.push({ kind: 'box', label: box.label, sides })
  }
  for (const def of rows) if (!boxed.has(def)) items.push({ kind: 'row', def })
  // Keep the schema's order: a box sits where its first side sat.
  const position = (item: Item) => rows.indexOf(item.kind === 'box' ? item.sides[0]!.def : item.def)
  return items.sort((a, b) => position(a) - position(b))
}

const groups = computed(() =>
  GROUPS.map((g) => {
    const rows = props.schema.properties.filter((r) => allowed.value.has(r.path) && g.match(r))
    return { ...g, rows, items: itemsOf(rows), responsive: rows.some((r) => r.responsive) }
  }).filter((g) => g.rows.length > 0),
)

/** Which breakpoints carry an exact declaration for any property of the group (a dot). */
function declaredAt(rows: StylePropertyRow[]): Breakpoint[] {
  return BREAKPOINTS.filter((bp) =>
    rows.some((row) => readPath(style.value, settingSegments(row.path, bp).slice(1)).present),
  )
}

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
        <h4 class="mb-2 flex items-center justify-between gap-2">
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-1 rounded text-[11px] font-semibold uppercase tracking-wide text-muted hover:text-default focus-visible:outline-2 focus-visible:outline-primary"
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
          <button
            v-if="
              canPlayMotion &&
              group.key === 'motion' &&
              !isFolded(group.key) &&
              setCount(group.rows) > 0
            "
            type="button"
            class="ms-auto inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium normal-case tracking-normal text-primary hover:bg-elevated"
            title="Animations are off while you edit. Play this block's once on the stage."
            data-test="motion-play"
            @click="emit('play-motion')"
          >
            <UIcon name="i-lucide-play" class="size-3" />
            Play
          </button>
          <div
            v-if="!isFolded(group.key) && group.responsive"
            class="flex gap-0.5"
            role="group"
            aria-label="Breakpoint"
          >
            <button
              v-for="bp in BREAKPOINTS"
              :key="bp"
              type="button"
              class="relative rounded px-1.5 py-0.5 text-[10px] normal-case tracking-normal"
              :class="
                bp === activeBreakpoint
                  ? 'bg-primary text-inverted'
                  : 'text-muted hover:text-default'
              "
              :aria-pressed="bp === activeBreakpoint ? 'true' : 'false'"
              :title="BREAKPOINT_LABELS[bp]"
              :data-test="`group-breakpoint-${bp}`"
              @click="emit('update:activeBreakpoint', bp)"
            >
              {{ bp }}
              <span
                v-if="declaredAt(group.rows).includes(bp)"
                class="absolute -top-0.5 -right-0.5 size-1.5 rounded-full bg-warning"
                aria-hidden="true"
              />
            </button>
          </div>
        </h4>
        <div v-if="!isFolded(group.key)" :id="`style-group-body-${group.key}`" class="space-y-3">
          <template
            v-for="item in group.items"
            :key="item.kind === 'box' ? item.label : item.def.path"
          >
            <BoxField
              :context="context"
              v-if="item.kind === 'box'"
              :label="item.label"
              :sides="item.sides"
              :style="style"
              :styles="styles"
              :classes="classes"
              :class-names="classNames"
              :re-resolving="reResolving"
              :active-breakpoint="activeBreakpoint"
              :vocabulary="schema.vocabulary"
              @set="(path, bp, value) => emit('set', path, bp, value)"
              @set-all="(path, value) => emit('set-all', path, value)"
            />
            <ResponsiveField
              :context="context"
              v-else
              :def="item.def"
              :label="LABELS[item.def.path] ?? item.def.path"
              :style="style"
              :styles="styles"
              :classes="classes"
              :class-names="classNames"
              :re-resolving="reResolving"
              :active-breakpoint="activeBreakpoint"
              :vocabulary="schema.vocabulary"
              hide-breakpoints
              @set="(path, bp, value) => emit('set', path, bp, value)"
              @set-all="(path, value) => emit('set-all', path, value)"
              @update:active-breakpoint="(bp) => emit('update:activeBreakpoint', bp)"
            />
          </template>
        </div>
      </section>
      <div
        v-if="!multi && !noSaveAsClass && (context ?? 'block') === 'block'"
        class="border-t border-default pt-3"
      >
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
