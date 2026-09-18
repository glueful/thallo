<script setup lang="ts">
// The Layout tab (container-layout spec §5): where a block's own box sits, and — when it can
// arrange children — how it arranges them.
//
// Three sections, all capability-driven, so every block gets the ones it declares and no more:
//
//   Box        the block's own size, placement, minimum height and overflow.
//   Container  the mode it arranges its children in, and the measure its content sits within.
//   Children   the controls that mode actually uses, at the active breakpoint. A grid offers
//              tracks; a flex row offers direction and wrap; block mode offers neither and says
//              so, because a control that does nothing is worse than no control.
//   As an item how the block sits in ITS parent, resolved against the parent's mode at the same
//              breakpoint: a grid parent offers span, a flex parent offers basis, grow and shrink.
//
// A mode switch never deletes anything, so both roles disclose what is being retained and
// ignored — the grid tracks a flex container is keeping, the span a flex parent is not using.
//
// The mode is read through the real cascade (`effectiveDisplay`), so what the tab offers is what
// the page is doing at that width — including a mode a style class supplied.
import { computed } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { StylePropertyRow, StyleSchemaResult } from '@/queries/styleSchema'
import type { Breakpoint, Resolution, StyleClassRef, StyleValue } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { resolve } from '@/style/resolver'
import { readPath, settingSegments } from '@/editor/ops/apply'
import { BREAKPOINTS } from '@/style/types'
import { BREAKPOINT_LABELS } from '@/editor/breakpoint'
import { isFolded, toggleFold } from './styleGroupFolds'
import { dormantPaths, effectiveDisplay } from './layoutContext'
import { pathsForTab } from './tabMap'
import ResponsiveField from './controls/ResponsiveField.vue'
import BoxField from './controls/BoxField.vue'
import IconChoiceControl from './controls/IconChoiceControl.vue'
import TrackSwatchControl from './controls/TrackSwatchControl.vue'

const props = defineProps<{
  block: BlockInstance
  blockType: BlockType | null
  schema: StyleSchemaResult
  classes: StyleClassRef[]
  activeBreakpoint: Breakpoint
  classNames?: Record<string, string>
  reResolving?: boolean
  /** A sibling multi-selection (visual builder spec §5.5): `block` is its anchor. */
  blocks?: BlockInstance[]
  blockTypes?: (BlockType | null)[]
  /**
   * The block's immediate parent, when it has one. For a multi-selection it is passed only when
   * every selected block shares it: item controls resolve against ONE parent's mode, and two
   * parents have no single answer (spec §5).
   */
  parent?: BlockInstance | null
  parentType?: BlockType | null
  /** The parent's own style classes, so its mode resolves through the same cascade. */
  parentClasses?: StyleClassRef[]
}>()
const emit = defineEmits<{
  set: [path: string, breakpoint: Breakpoint | null, value: StyleValue | null]
  'set-all': [path: string, value: StyleValue]
  'update:activeBreakpoint': [breakpoint: Breakpoint]
  /** Select the parent, so the author can change the mode the item controls answer to. */
  'select-parent': [id: string]
}>()

const multi = computed(() => (props.blocks?.length ?? 0) > 1)

const LABELS: Record<string, string> = {
  width: 'Width',
  'alignment.self': 'Placement',
  'layout.min_height': 'Minimum height',
  'layout.overflow': 'Overflow',
  'layout.display': 'Children',
  'layout.content_width': 'Content width',
  'layout.gutter': 'Gutter',
  'layout.direction': 'Direction',
  'layout.wrap': 'Wrap',
  'alignment.content': 'Distribute',
  'layout.align_items': 'Align',
  'layout.columns': 'Columns',
  'layout.span': 'Span',
  'layout.basis': 'Basis',
  'layout.grow': 'Grow',
  'layout.shrink': 'Shrink',
  'layout.align_self': 'Align self',
}

/** In the Box section the same property names what the block does with its own content. */
const BOX_LABELS: Record<string, string> = { 'alignment.content': 'Content alignment' }

const DIRECTION_ICONS: Record<string, string> = {
  row: 'i-lucide-arrow-right',
  column: 'i-lucide-arrow-down',
  'row-reverse': 'i-lucide-arrow-left',
  'column-reverse': 'i-lucide-arrow-up',
}
const WRAP_ICONS: Record<string, string> = {
  nowrap: 'i-lucide-move-horizontal',
  wrap: 'i-lucide-corner-down-left',
}
const WRAP_LABELS: Record<string, string> = {
  nowrap: 'One line',
  wrap: 'Wrap onto more lines',
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

/** The layout paths every selected block declares: a property any block lacks renders no control. */
const allowed = computed<Set<string>>(() => {
  const types = multi.value ? (props.blockTypes ?? []) : [props.blockType]
  if (types.length === 0) return new Set()
  const [first, ...rest] = types.map(pathsOf)
  const shared = [...first!].filter((path) => rest.every((set) => set.has(path)))
  return new Set(pathsForTab(shared, 'layout'))
})

const rows = computed(() => new Map(props.schema.properties.map((r) => [r.path, r])))
/** The schema row for a path the selection declares, or null when it declares none. */
function rowFor(path: string): StylePropertyRow | null {
  return allowed.value.has(path) ? (rows.value.get(path) ?? null) : null
}
function rowsFor(paths: string[]): StylePropertyRow[] {
  return paths.map(rowFor).filter((row): row is StylePropertyRow => row !== null)
}

function styleOf(block: BlockInstance): Record<string, unknown> {
  const style = (block.settings as { style?: unknown } | undefined)?.style
  return typeof style === 'object' && style !== null ? (style as Record<string, unknown>) : {}
}
const style = computed(() => styleOf(props.block))
const styles = computed(() => (multi.value ? (props.blocks ?? []).map(styleOf) : undefined))

function definitionOf(row: StylePropertyRow) {
  return {
    path: row.path,
    group: row.group,
    responsive: row.responsive,
    tokenDomain: row.token_domain,
    choices: row.choices,
  }
}

/** The value in force for a path at the active breakpoint, or null when it is mixed or default. */
function valueOf(row: StylePropertyRow): string | null {
  const at = row.responsive ? props.activeBreakpoint : 'base'
  const read = (s: Record<string, unknown>) =>
    (resolve(row.path, props.classes, s, definitionOf(row)) as Record<string, Resolution>)[at]
      ?.value ?? null
  const all = styles.value ?? []
  if (all.length > 1) {
    const first = JSON.stringify(read(all[0]!))
    if (all.some((s) => JSON.stringify(read(s)) !== first)) return null
  }
  const value = read(style.value)
  return value && 'value' in value ? value.value : null
}

function write(row: StylePropertyRow, raw: string): void {
  emit(
    'set',
    row.path,
    row.responsive ? props.activeBreakpoint : null,
    row.token_domain !== null ? { type: 'token', value: raw } : { type: 'choice', value: raw },
  )
}

// ── The sections ─────────────────────────────────────────────────────────────
const BOX_PATHS = ['width', 'alignment.self', 'layout.min_height', 'layout.overflow']
const CONTAINER_PATHS = ['layout.display', 'layout.content_width', 'layout.gutter']

const containerRows = computed(() => rowsFor(CONTAINER_PATHS))

const displayRow = computed(() => rowFor('layout.display'))
/** The mode the children are arranged in at the active breakpoint. */
const display = computed(() => effectiveDisplay(props.block, props.activeBreakpoint, props.classes))

const directionRow = computed(() => rowFor('layout.direction'))
const wrapRow = computed(() => rowFor('layout.wrap'))
const columnsRow = computed(() => rowFor('layout.columns'))
const contentRow = computed(() => rowFor('alignment.content'))
const alignRow = computed(() => rowFor('layout.align_items'))
/** Both gaps as one two-cell row: Column and Row, the way padding and margin are presented. */
const gapSides = computed(() => {
  const sides: { key: string; def: StylePropertyRow }[] = []
  const column = rowFor('layout.gap.column')
  const row = rowFor('layout.gap.row')
  if (column) sides.push({ key: 'column', def: column })
  if (row) sides.push({ key: 'row', def: row })
  return sides
})

/** Whether the block can arrange children at all: only then is there a Children section. */
const arranges = computed(
  () =>
    displayRow.value !== null ||
    columnsRow.value !== null ||
    directionRow.value !== null ||
    gapSides.value.length > 0 ||
    alignRow.value !== null,
)

/**
 * A block that distributes its own content without being a container — Button, Navigation — keeps
 * that control against its own target (spec §5). It sits with the block's own box, because there
 * is no mode to arrange anything in and no children to speak of.
 */
const contentOnly = computed(() => !arranges.value && contentRow.value !== null)

const boxRows = computed(() =>
  rowsFor(contentOnly.value ? [...BOX_PATHS, 'alignment.content'] : BOX_PATHS),
)

const childrenRows = computed(() => {
  if (display.value === 'flex') {
    return [directionRow.value, wrapRow.value, contentRow.value, alignRow.value]
  }
  if (display.value === 'grid') return [columnsRow.value, contentRow.value, alignRow.value]
  return []
})

// ── As an item (spec §5) ─────────────────────────────────────────────────────
const ITEM_PATHS = [
  'layout.span',
  'layout.basis',
  'layout.grow',
  'layout.shrink',
  'layout.align_self',
]

/** Whether the parent is something that arranges its children at all. */
const parentArranges = computed(() => {
  const type = props.parentType
  if (!props.parent || !type) return false
  const caps = type.style_capabilities ?? []
  return caps.includes('layout.display') || caps.includes('layout')
})

/** The mode the parent has in force at the active breakpoint, or null with no parent to ask. */
const parentDisplay = computed(() =>
  props.parent && parentArranges.value
    ? effectiveDisplay(props.parent, props.activeBreakpoint, props.parentClasses ?? [])
    : null,
)

/** The item rows the parent's mode actually uses; block mode uses none. */
const itemRows = computed(() => {
  if (parentDisplay.value === 'grid') return rowsFor(['layout.span', 'layout.align_self'])
  if (parentDisplay.value === 'flex') {
    return rowsFor(['layout.basis', 'layout.grow', 'layout.shrink', 'layout.align_self'])
  }
  return []
})

/** Whether the block can be an item at all: it declares item properties and has a parent. */
const isItem = computed(() => parentDisplay.value !== null && rowsFor(ITEM_PATHS).length > 0)

/** The settings each role is keeping and ignoring at this breakpoint (spec §5). */
const dormantParent = computed(() =>
  arranges.value ? dormantPaths(props.block, props.activeBreakpoint, props.classes, 'parent') : [],
)
const dormantItem = computed(() =>
  parentDisplay.value === null
    ? []
    : dormantPaths(props.block, props.activeBreakpoint, props.classes, parentDisplay.value),
)
const labelsOf = (paths: string[]): string => paths.map((p) => LABELS[p] ?? p).join(', ')

const sections = computed(() =>
  [
    { key: 'box', label: 'Box', rows: boxRows.value, shown: boxRows.value.length > 0 },
    {
      key: 'container',
      label: 'Container',
      rows: containerRows.value,
      shown: containerRows.value.length > 0,
    },
    {
      key: 'children',
      label: 'Children',
      rows: [
        ...childrenRows.value.filter((r): r is StylePropertyRow => r !== null),
        ...gapSides.value.map((s) => s.def),
      ],
      shown: arranges.value,
    },
    {
      key: 'item',
      label: 'As an item',
      rows: [...itemRows.value, ...rowsFor(ITEM_PATHS)].filter(
        (row, index, all) => all.indexOf(row) === index,
      ),
      shown: isItem.value,
    },
  ].filter((section) => section.shown),
)

/** Which breakpoints carry an exact declaration for any property of the section (a dot). */
function declaredAt(sectionRows: StylePropertyRow[]): Breakpoint[] {
  return BREAKPOINTS.filter((bp) =>
    sectionRows.some(
      (row) => readPath(style.value, settingSegments(row.path, bp).slice(1)).present,
    ),
  )
}
function setCount(sectionRows: StylePropertyRow[]): number {
  return sectionRows.filter((row) =>
    BREAKPOINTS.some((bp) => readPath(style.value, settingSegments(row.path, bp).slice(1)).present),
  ).length
}
function responsive(sectionRows: StylePropertyRow[]): boolean {
  return sectionRows.some((row) => row.responsive)
}

/**
 * The gutter the theme applies when none is set, which depends on the content width in force:
 * a boxed container carries the page gutter, a full-width one carries none (spec §3.4).
 */
const gutterDefault = computed(() => {
  const row = rowFor('layout.content_width')
  const width = row ? valueOf(row) : null
  if (width === null) return null
  return width === 'width.full' ? 'none' : 'the page gutter'
})
</script>

<template>
  <div class="space-y-4" data-test="layout-tab">
    <p v-if="sections.length === 0" class="text-xs text-muted" data-test="layout-none">
      This block declares no layout.
    </p>
    <template v-else>
      <section
        v-for="section in sections"
        :key="section.key"
        :data-test="`layout-group-${section.key}`"
      >
        <h4 class="mb-2 flex items-center justify-between gap-2">
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-1 rounded text-[11px] font-semibold uppercase tracking-wide text-muted hover:text-default focus-visible:outline-2 focus-visible:outline-primary"
            :aria-expanded="!isFolded(`layout-${section.key}`)"
            :aria-controls="`layout-group-body-${section.key}`"
            :data-test="`layout-group-toggle-${section.key}`"
            @click="toggleFold(`layout-${section.key}`)"
          >
            <UIcon
              :name="
                isFolded(`layout-${section.key}`)
                  ? 'i-lucide-chevron-right'
                  : 'i-lucide-chevron-down'
              "
              class="size-3.5 shrink-0"
            />
            <span>{{ section.label }}</span>
            <span
              v-if="isFolded(`layout-${section.key}`) && setCount(section.rows) > 0"
              class="ms-auto rounded-full bg-elevated px-1.5 py-0.5 text-[10px] font-medium normal-case tracking-normal"
              :data-test="`layout-group-count-${section.key}`"
            >
              {{ setCount(section.rows) }} set
            </span>
          </button>
          <div
            v-if="!isFolded(`layout-${section.key}`) && responsive(section.rows)"
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
              :data-test="`layout-breakpoint-${section.key}-${bp}`"
              @click="emit('update:activeBreakpoint', bp)"
            >
              {{ bp }}
              <span
                v-if="declaredAt(section.rows).includes(bp)"
                class="absolute -top-0.5 -right-0.5 size-1.5 rounded-full bg-warning"
                aria-hidden="true"
              />
            </button>
          </div>
        </h4>

        <div
          v-if="!isFolded(`layout-${section.key}`)"
          :id="`layout-group-body-${section.key}`"
          class="space-y-3"
        >
          <!-- Box and Container are plain property rows, in the contract's order. -->
          <template v-if="section.key === 'box' || section.key === 'container'">
            <template v-for="row in section.rows" :key="row.path">
              <ResponsiveField
                :def="row"
                :label="
                  (section.key === 'box' ? BOX_LABELS[row.path] : null) ??
                  LABELS[row.path] ??
                  row.path
                "
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
              <p
                v-if="row.path === 'layout.overflow'"
                class="-mt-2 text-[11px] text-muted"
                data-test="layout-overflow-note"
              >
                Applies at all sizes.
              </p>
              <p
                v-if="row.path === 'layout.gutter' && gutterDefault"
                class="-mt-2 text-[11px] text-muted"
                data-test="layout-gutter-default"
              >
                Unset, this container uses {{ gutterDefault }}.
              </p>
            </template>
          </template>

          <!-- Children follows the mode in force at the active breakpoint. -->
          <template v-else-if="section.key === 'children'">
            <template v-if="display === 'block' && arranges">
              <p class="text-xs text-muted" data-test="layout-children-stack">
                Children stack. Switch to flex or grid to arrange them.
              </p>
              <div v-if="displayRow" class="space-y-1.5">
                <span class="text-xs font-medium">{{ LABELS['layout.display'] }}</span>
                <IconChoiceControl
                  :choices="displayRow.choices ?? []"
                  :model-value="valueOf(displayRow)"
                  :icons="{
                    block: 'i-lucide-rows-3',
                    flex: 'i-lucide-columns-3',
                    grid: 'i-lucide-layout-grid',
                  }"
                  :labels="{ block: 'Stack', flex: 'Flex', grid: 'Grid' }"
                  name="Children"
                  data-test="layout-display-switch"
                  @update:model-value="(v: string) => write(displayRow!, v)"
                />
              </div>
            </template>

            <template v-else>
              <div
                v-if="display === 'grid' && columnsRow"
                class="space-y-1.5"
                data-test="layout-field-layout.columns"
              >
                <span class="text-xs font-medium">{{ LABELS['layout.columns'] }}</span>
                <TrackSwatchControl
                  :choices="columnsRow.choices ?? []"
                  :model-value="valueOf(columnsRow)"
                  name="Columns"
                  @update:model-value="(v: string) => write(columnsRow!, v)"
                />
              </div>
              <div
                v-if="display === 'flex' && directionRow"
                class="space-y-1.5"
                data-test="layout-field-layout.direction"
              >
                <span class="text-xs font-medium">{{ LABELS['layout.direction'] }}</span>
                <IconChoiceControl
                  :choices="directionRow.choices ?? []"
                  :model-value="valueOf(directionRow)"
                  :icons="DIRECTION_ICONS"
                  name="Direction"
                  @update:model-value="(v: string) => write(directionRow!, v)"
                />
              </div>
              <div
                v-if="display === 'flex' && wrapRow"
                class="space-y-1.5"
                data-test="layout-field-layout.wrap"
              >
                <span class="text-xs font-medium">{{ LABELS['layout.wrap'] }}</span>
                <IconChoiceControl
                  :choices="wrapRow.choices ?? []"
                  :model-value="valueOf(wrapRow)"
                  :icons="WRAP_ICONS"
                  :labels="WRAP_LABELS"
                  name="Wrap"
                  @update:model-value="(v: string) => write(wrapRow!, v)"
                />
              </div>
              <ResponsiveField
                v-if="contentRow"
                :def="contentRow"
                :label="LABELS['alignment.content']!"
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
              <ResponsiveField
                v-if="alignRow && display !== 'block'"
                :def="alignRow"
                :label="LABELS['layout.align_items']!"
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
              <BoxField
                v-if="gapSides.length > 0 && display !== 'block'"
                label="Gap"
                :sides="gapSides"
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
            </template>
            <p
              v-if="dormantParent.length > 0"
              class="rounded bg-elevated px-2 py-1.5 text-[11px] text-muted"
              data-test="layout-dormant-parent"
            >
              Kept but unused in this mode: {{ labelsOf(dormantParent) }}.
            </p>
          </template>

          <!-- As an item: resolved against the parent's mode at the same breakpoint. -->
          <template v-else>
            <template v-if="parentDisplay === 'block'">
              <p class="text-xs text-muted" data-test="layout-item-stacks">
                This block's parent stacks its children, so it has nothing to size itself against.
              </p>
              <UButton
                v-if="parent"
                size="xs"
                variant="ghost"
                color="neutral"
                icon="i-lucide-corner-left-up"
                data-test="layout-item-parent-link"
                @click="emit('select-parent', parent.id)"
              >
                Select the parent
              </UButton>
            </template>
            <ResponsiveField
              v-for="row in itemRows"
              v-else
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
              hide-breakpoints
              @set="(path, bp, value) => emit('set', path, bp, value)"
              @set-all="(path, value) => emit('set-all', path, value)"
              @update:active-breakpoint="(bp) => emit('update:activeBreakpoint', bp)"
            />
            <p
              v-if="dormantItem.length > 0"
              class="rounded bg-elevated px-2 py-1.5 text-[11px] text-muted"
              data-test="layout-dormant-item"
            >
              Kept but unused by this parent: {{ labelsOf(dormantItem) }}.
            </p>
          </template>
        </div>
      </section>
    </template>
  </div>
</template>
