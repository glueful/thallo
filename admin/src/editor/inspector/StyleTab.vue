<script setup lang="ts">
// The Style tab (visual builder spec §3.4): controls generated from the block type's
// `style_capabilities`, grouped as spacing, typography, colours, effects and visibility.
//
// Only properties `tabMap` assigns to Style appear here; width, placement and content
// distribution moved to the Layout tab (container-layout spec §5), because how wide a box is and
// how it sits in its parent are layout decisions, not styling ones.
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
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
import { effectivePaths, HOVER_OF } from '@/style/capabilities'
import { StageHoverKey, hoverTargets } from '@/editor/stage/stageHover'
import { resolve } from '@/style/resolver'
import { suppliedWeights, useFontLibrary } from '@/queries/fontLibrary'
import type { ComputedTypography } from '@/composables/useCanvasBridge'
import { StageTypographyKey, typographyTarget } from '@/editor/stage/stageTypography'

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
  context?: 'block' | 'class' | 'region' | 'part'
  /** A block's inspector on a page with no save-as-class flow (the Regions page). */
  noSaveAsClass?: boolean
  /** The host has a stage that can replay a block's motion: only then is Play offered. */
  canPlayMotion?: boolean
  /** A part's Style tab (`context` 'part'): the part's name, which the stage marks it by. */
  part?: string
  /** A part's Style tab: whose elements its forced hover reaches (the part's declared scope). */
  partScope?: 'own' | 'children'
  /**
   * The host shows another tab over this one, which it keeps mounted: no forced hover then. (A
   * Boolean prop left out is false, so the default — shown — needs no binding.)
   */
  hidden?: boolean
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
  // A Feature's space between its marker and its text is spacing too.
  {
    key: 'spacing',
    label: 'Spacing',
    match: (r) => r.group === 'spacing' || r.group === 'feature',
  },
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
    match: (r) =>
      r.group === 'radius' || r.group === 'shadow' || r.group === 'border' || r.group === 'opacity',
  },
  // The block's marker — a feature's icon chip or number badge — beside the block's own Effects.
  { key: 'marker', label: 'Marker', match: (r) => r.group === 'marker' },
  // A tabs block's strip — the bar and the active tab's pill; the block's Effects are the panel's.
  { key: 'tabs', label: 'Tabs', match: (r) => r.group === 'tabs' },
  // A hero's aside — its padding and fill; the aside's corners and shadow are the block's Effects.
  { key: 'aside', label: 'Aside', match: (r) => r.group === 'aside' },
  // A Logos block's logo size; the gaps between the logos are the Layout tab's.
  { key: 'logos', label: 'Logos', match: (r) => r.group === 'logos' },
  // The Footer block's divider: the line under its top section.
  { key: 'footer', label: 'Divider', match: (r) => r.group === 'footer' },
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
  'typography.family': 'Typeface',
  'typography.size': 'Size',
  'typography.weight': 'Weight',
  'typography.line_height': 'Line height',
  'typography.letter_spacing': 'Letter spacing',
  'typography.transform': 'Text transform',
  'typography.decoration': 'Text decoration',
  visibility: 'Visibility',
  shadow: 'Shadow',
  radius: 'Corners',
  'marker.radius': 'Corners',
  'marker.shadow': 'Shadow',
  'tabs.bar_radius': 'Bar corners',
  'tabs.tab_radius': 'Active tab corners',
  'aside.surface': 'Background',
  'logos.height': 'Logo size',
  'logos.max_width': 'Logo max width',
  'typography.style': 'Font style',
  'footer.divider_color': 'Colour',
  'footer.divider_width': 'Width',
  'footer.divider_style': 'Style',
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
  'marker.color': 'Colour',
  'marker.background': 'Background',
  'marker.size': 'Size',
  'feature.gap': 'Space after icon',
  'hover.colors.text': 'Text colour',
  'hover.colors.surface': 'Background',
  'hover.colors.border': 'Border colour',
  'hover.opacity': 'Opacity',
  opacity: 'Opacity',
  'backdrop.blur': 'Backdrop blur',
}

/** The paths one type offers: the server's expansion (hover state spec §2.2.1). */
const pathsOf = (type: BlockType | null): Set<string> => effectivePaths(type)

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

/** The Typeface leads Typography: it decides which weights the rest can mean (spec §4.1). */
function ordered(rows: StylePropertyRow[]): StylePropertyRow[] {
  const family = rows.filter((r) => r.path === 'typography.family')
  return [...family, ...rows.filter((r) => r.path !== 'typography.family')]
}

/**
 * Normal or Hover (hover state spec §6.2): one state for the whole tab, back to Normal on a new
 * selection. In Hover a section shows only its rows' hover counterparts; the hover group never has a
 * section of its own.
 */
const hoverState = ref<'normal' | 'hover'>('normal')
// A new selection — another block, or the same anchor extended to (or shrunk from) siblings — starts
// in Normal: its sections are not the ones the Hover was for.
watch(
  () => [props.block.id, (props.blocks ?? []).map((b) => b.id).join(',')].join('|'),
  () => {
    hoverState.value = 'normal'
  },
)
/** Resting path → its hover path. */
const HOVER_FOR: Record<string, string> = Object.fromEntries(
  Object.entries(HOVER_OF).map(([hover, resting]) => [resting, hover]),
)

const groups = computed(() =>
  GROUPS.map((g) => {
    const resting = ordered(
      props.schema.properties.filter((r) => allowed.value.has(r.path) && g.match(r)),
    )
    // The hover counterparts this target offers, in the schema's order.
    const hoverPaths = new Set(
      resting.map((r) => HOVER_FOR[r.path]).filter((p) => p && allowed.value.has(p)),
    )
    const hoverRows = props.schema.properties.filter((r) => hoverPaths.has(r.path))
    const hasHover = hoverRows.length > 0
    const rows = hasHover && hoverState.value === 'hover' ? hoverRows : resting
    return {
      ...g,
      rows,
      hoverRows,
      hasHover,
      items: itemsOf(rows),
      responsive: rows.some((r) => r.responsive),
    }
  }).filter((g) => g.rows.length > 0),
)

// While Hover is on and a section with hover rows is open, the stage shows the hover look of what
// this tab governs (hover state spec §6.3): a block's every hover target, or this part with its
// declared scope. Normal, folding those sections, a new selection or leaving the tab clears it.
const stageHover = inject(StageHoverKey, null)
const hoverOwner = Symbol('style-tab')
const hoverShown = computed(
  () =>
    !props.hidden &&
    hoverState.value === 'hover' &&
    groups.value.some((g) => g.hasHover && !isFolded(g.key)),
)
watch(
  () => [hoverShown.value, props.block.id, multi.value] as const,
  ([shown, id, several]) => {
    if (stageHover === null) return
    // A multi-selection previews nothing: one stage force is one block's look.
    if (several || !shown) return stageHover.clear(hoverOwner)
    const context = props.context ?? 'block'
    if (context === 'part' && props.part) {
      stageHover.force(hoverOwner, {
        id,
        targets: [],
        part: props.part,
        scope: props.partScope ?? 'own',
      })
    } else if (context === 'block') {
      const targets = hoverTargets(props.blockType)
      if (targets.length > 0)
        stageHover.force(hoverOwner, { id, targets, part: null, scope: 'own' })
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => stageHover?.clear(hoverOwner))

/** Whether any of a section's hover values is declared on this target or part (the Hover dot). */
function hoverDeclared(rows: StylePropertyRow[]): boolean {
  return rows.some((row) => readPath(style.value, settingSegments(row.path, null).slice(1)).present)
}

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

// The font library: a class's removed typeface is named, and Weight marks what the chosen family
// does not supply (block typeface spec §4.2, §4.4).
const { data: fontLibrary } = useFontLibrary()
const fonts = computed(
  () =>
    new Map(
      (fontLibrary.value?.families ?? []).map((f) => [f.id, { name: f.name, removed: f.removed }]),
    ),
)
const WEIGHTS: Record<string, number> = { regular: 400, medium: 500, semibold: 600, bold: 700 }
const weightMarks = computed<Record<string, string> | undefined>(() => {
  const row = props.schema.properties.find((r) => r.path === 'typography.family')
  if (!row) return undefined
  const resolved = resolve(row.path, props.classes, style.value, {
    path: row.path,
    group: row.group,
    responsive: row.responsive,
    tokenDomain: row.token_domain,
    choices: row.choices,
    kinds: row.kinds,
  }) as Record<string, { value: StyleValue | null }>
  const value = resolved.base?.value
  const family =
    value?.type === 'font'
      ? fontLibrary.value?.families.find((f) => f.id === value.value)
      : undefined
  const supplied = family && !family.removed ? suppliedWeights(family) : null
  if (supplied === null) return undefined
  const marks: Record<string, string> = {}
  for (const [choice, weight] of Object.entries(WEIGHTS)) {
    if (!supplied.has(weight)) marks[choice] = '— not in this family'
  }
  return marks
})
const styles = computed(() => (multi.value ? (props.blocks ?? []).map(styleOf) : undefined))

// What the stage renders the typeface's target in (plan Task 10), for the not-supplied notice: a
// single block's target or part, asked again after each stage render. Only the latest answer counts.
const stageTypography = inject(StageTypographyKey, null)
const measuredTarget = computed<string | null>(() => {
  if (stageTypography === null || multi.value) return null
  const context = props.context ?? 'block'
  if (context === 'part') return props.part ?? null
  return context === 'block' ? typographyTarget(props.blockType) : null
})
const measured = ref<ComputedTypography | null>(null)
let measureSeq = 0
watch(
  () => [props.block.id, measuredTarget.value, stageTypography?.renders.value] as const,
  ([id, target]) => {
    const seq = ++measureSeq
    if (stageTypography === null || target === null) {
      measured.value = null
      return
    }
    void stageTypography.request(id, target).then((value) => {
      if (seq === measureSeq) measured.value = value
    })
  },
  { immediate: true },
)

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
            v-if="group.hasHover && !isFolded(group.key)"
            class="flex gap-0.5"
            role="group"
            aria-label="State"
            :data-test="`style-state-${group.key}`"
          >
            <button
              v-for="s in ['normal', 'hover'] as const"
              :key="s"
              type="button"
              class="relative rounded px-1.5 py-0.5 text-[10px] normal-case tracking-normal"
              :class="
                s === hoverState ? 'bg-primary text-inverted' : 'text-muted hover:text-default'
              "
              :aria-pressed="s === hoverState ? 'true' : 'false'"
              :data-test="`style-state-${s}-${group.key}`"
              @click="hoverState = s"
            >
              {{ s === 'normal' ? 'Normal' : 'Hover' }}
              <span
                v-if="s === 'hover' && hoverDeclared(group.hoverRows)"
                class="absolute -top-0.5 -right-0.5 size-1.5 rounded-full bg-warning"
                aria-hidden="true"
                :data-test="`style-state-dot-${group.key}`"
              />
            </button>
          </div>
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
              :marks="item.def.path === 'typography.weight' ? weightMarks : undefined"
              :fonts="fonts"
              :computed-typography="item.def.path === 'typography.family' ? measured : undefined"
              :all-sizes-note="group.responsive"
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
