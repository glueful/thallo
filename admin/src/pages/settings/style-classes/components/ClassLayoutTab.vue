<script setup lang="ts">
// A style class's Layout tab (container-layout spec §12.2–12.3): every layout property a class
// can hold — width, placement and content alignment among them — always present and editable,
// each family under a permanent label saying where it applies.
//
// A class has no context, and this component invents none. It does not know a mode in force, a
// parent, or a theme default, so it has no dormancy, no item visibility, no default markers and
// no structural action: those are the block inspector's, and stay in LayoutTab.vue. What it shares
// with that tab is the field controls, the property names, and the tab map that decides which
// properties are layout at all.
import { computed } from 'vue'
import type { StylePropertyRow, StyleSchemaResult } from '@/queries/styleSchema'
import type { Breakpoint, StyleValue } from '@/style/types'
import { BREAKPOINTS } from '@/style/types'
import { BREAKPOINT_LABELS } from '@/editor/breakpoint'
import { pathsForTab } from '@/editor/inspector/tabMap'
import { classFieldState } from '@/editor/inspector/classFieldState'
import {
  DIRECTION_ICONS,
  LAYOUT_LABELS,
  WRAP_ICONS,
  WRAP_LABELS,
} from '@/editor/inspector/layoutLabels'
import ResponsiveField from '@/editor/inspector/controls/ResponsiveField.vue'
import BoxField from '@/editor/inspector/controls/BoxField.vue'
import IconChoiceControl from '@/editor/inspector/controls/IconChoiceControl.vue'
import TrackSwatchControl from '@/editor/inspector/controls/TrackSwatchControl.vue'
import { CLASS_LAYOUT_SECTIONS, FAMILY_PATHS, unplacedLayoutPaths } from './classLayoutSections'

const props = defineProps<{
  /** The class's `style`: the only layer there is. */
  modelStyle: Record<string, unknown>
  schema: StyleSchemaResult
  activeBreakpoint: Breakpoint
}>()
const emit = defineEmits<{
  set: [path: string, breakpoint: Breakpoint | null, value: StyleValue | null]
  'set-all': [path: string, value: StyleValue]
  'update:activeBreakpoint': [breakpoint: Breakpoint]
}>()

const GAP_PATHS = ['layout.gap.column', 'layout.gap.row']
/** Choosers that draw their options rather than naming them; the row around them is the same. */
const ICONS: Record<string, { icons: Record<string, string>; labels?: Record<string, string> }> = {
  'layout.direction': { icons: DIRECTION_ICONS },
  'layout.wrap': { icons: WRAP_ICONS, labels: WRAP_LABELS },
}

const rows = computed(() => new Map(props.schema.properties.map((row) => [row.path, row])))
const layoutPaths = computed(() =>
  pathsForTab(
    props.schema.properties.map((row) => row.path),
    'layout',
  ),
)

type Item =
  | { kind: 'field'; row: StylePropertyRow; fallback: boolean }
  | { kind: 'gap'; sides: { key: string; def: StylePropertyRow }[] }

/** A group's rows as they render: the two gaps are one box, wherever the first of them falls. */
function itemsOf(paths: readonly string[], fallback = false): Item[] {
  const out: Item[] = []
  for (const path of paths) {
    const row = rows.value.get(path)
    if (!row) continue
    if (GAP_PATHS.includes(path)) {
      if (out.some((item) => item.kind === 'gap')) continue
      const sides = GAP_PATHS.filter((p) => paths.includes(p))
        .map((p) => rows.value.get(p))
        .filter((def): def is StylePropertyRow => def !== undefined)
        .map((def) => ({ key: def.path.slice('layout.gap.'.length), def }))
      out.push({ kind: 'gap', sides })
      continue
    }
    out.push({ kind: 'field', row, fallback })
  }
  return out
}

const sections = computed(() =>
  CLASS_LAYOUT_SECTIONS.map((section) => ({
    ...section,
    groups: [
      ...section.groups.map((group) => ({ ...group, items: itemsOf(group.paths) })),
      // A Layout property the contract has and this list does not: editable, at the end of Box.
      ...(section.key === 'box'
        ? [
            {
              family: undefined,
              label: undefined,
              paths: [],
              items: itemsOf(unplacedLayoutPaths(layoutPaths.value), true),
            },
          ]
        : []),
    ].filter((group) => group.items.length > 0),
  })),
)

/** Whether the class holds any declaration for a path, at any breakpoint. */
function declares(path: string): boolean {
  let node: unknown = props.modelStyle
  for (const part of path.split('.')) {
    if (typeof node !== 'object' || node === null) return false
    node = (node as Record<string, unknown>)[part]
  }
  return typeof node === 'object' && node !== null && Object.keys(node).length > 0
}

/**
 * The mode THIS CLASS supplies at the breakpoint being edited, or null: not the mode in force on
 * any block. Only used to say that the other family's settings are retained — never to hide them.
 */
const classMode = computed<'flex' | 'grid' | null>(() => {
  const row = rows.value.get('layout.display')
  if (!row) return null
  const state = classFieldState(
    {
      path: row.path,
      group: row.group,
      responsive: row.responsive,
      tokenDomain: row.token_domain,
      choices: row.choices,
    },
    props.modelStyle,
    props.activeBreakpoint,
  )
  if (state.kind !== 'set' && state.kind !== 'inherited') return null
  const value = state.value && 'value' in state.value ? state.value.value : null
  return value === 'flex' || value === 'grid' ? value : null
})
/** The family whose settings this class keeps while supplying the other mode, or null. */
const retained = computed<'flex' | 'grid' | null>(() => {
  if (classMode.value === null) return null
  const other = classMode.value === 'grid' ? 'flex' : 'grid'
  return FAMILY_PATHS[other].some(declares) ? other : null
})
const RETAINED_NOTE = {
  flex: "Flex settings are retained. They apply wherever the block's effective layout is Flex.",
  grid: "Grid settings are retained. They apply wherever the block's effective layout is Grid.",
}
const ITEM_NOTE: Record<string, { test: string; text: string }> = {
  'grid-parent': {
    test: 'grid',
    text: "These apply wherever the block's parent lays out its children as a grid.",
  },
  'flex-parent': {
    test: 'flex',
    text: "These apply wherever the block's parent lays out its children as flex.",
  },
}
</script>

<template>
  <div class="space-y-5" data-test="class-layout-tab">
    <div class="flex items-center justify-between gap-2">
      <span class="text-[11px] text-muted">Editing at</span>
      <div class="flex gap-0.5" role="group" aria-label="Breakpoint">
        <button
          v-for="bp in BREAKPOINTS"
          :key="bp"
          type="button"
          class="rounded px-1.5 py-0.5 text-[10px]"
          :class="
            bp === activeBreakpoint ? 'bg-primary text-inverted' : 'text-muted hover:text-default'
          "
          :aria-pressed="bp === activeBreakpoint ? 'true' : 'false'"
          :title="BREAKPOINT_LABELS[bp]"
          :data-test="`class-layout-breakpoint-${bp}`"
          @click="emit('update:activeBreakpoint', bp)"
        >
          {{ bp }}
        </button>
      </div>
    </div>

    <section
      v-for="section in sections"
      :key="section.key"
      class="space-y-3"
      :data-test="`class-layout-group-${section.key}`"
    >
      <h4 class="text-xs font-semibold uppercase tracking-wide text-muted">{{ section.label }}</h4>
      <div
        v-for="(group, index) in section.groups"
        :key="group.family ?? `${section.key}-${index}`"
        class="space-y-3"
        :class="group.family ? 'border-s border-default ps-3' : ''"
        :data-test="group.family ? `class-layout-family-${group.family}` : undefined"
      >
        <template v-if="group.family">
          <p class="text-[11px] font-medium text-muted">{{ group.label }}</p>
          <p
            v-if="ITEM_NOTE[group.family]"
            class="-mt-2 text-[11px] text-muted"
            :data-test="`class-layout-item-note-${ITEM_NOTE[group.family]!.test}`"
          >
            {{ ITEM_NOTE[group.family]!.text }}
          </p>
          <p
            v-if="retained === group.family"
            class="-mt-2 rounded bg-elevated px-2 py-1.5 text-[11px] text-muted"
            :data-test="`class-layout-retained-${group.family}`"
          >
            {{ RETAINED_NOTE[retained] }}
          </p>
        </template>
        <template v-for="item in group.items" :key="item.kind === 'gap' ? 'gap' : item.row.path">
          <BoxField
            v-if="item.kind === 'gap'"
            label="Gap"
            :sides="item.sides"
            :style="modelStyle"
            :classes="[]"
            :active-breakpoint="activeBreakpoint"
            :vocabulary="schema.vocabulary"
            context="class"
            @set="(path, bp, value) => emit('set', path, bp, value)"
            @set-all="(path, value) => emit('set-all', path, value)"
          />
          <ResponsiveField
            v-else
            :def="item.row"
            :label="LAYOUT_LABELS[item.row.path] ?? item.row.path"
            :style="modelStyle"
            :classes="[]"
            :active-breakpoint="activeBreakpoint"
            :vocabulary="schema.vocabulary"
            context="class"
            hide-breakpoints
            :data-fallback="item.fallback ? 'true' : undefined"
            @set="(path, bp, value) => emit('set', path, bp, value)"
            @set-all="(path, value) => emit('set-all', path, value)"
          >
            <!-- The icon and track choosers draw their options; the state, the declaring
                 breakpoint and both actions stay the row's. No default-value: a class has no
                 default in force to mark. -->
            <template v-if="ICONS[item.row.path]" #control="{ value, pick }">
              <IconChoiceControl
                :choices="item.row.choices ?? []"
                :model-value="value"
                :icons="ICONS[item.row.path]!.icons"
                :labels="ICONS[item.row.path]!.labels"
                :name="LAYOUT_LABELS[item.row.path]"
                @update:model-value="pick"
              />
            </template>
            <template v-else-if="item.row.path === 'layout.columns'" #control="{ value, pick }">
              <TrackSwatchControl
                :choices="item.row.choices ?? []"
                :model-value="value"
                name="Columns"
                @update:model-value="pick"
              />
            </template>
          </ResponsiveField>
        </template>
      </div>
    </section>
  </div>
</template>
