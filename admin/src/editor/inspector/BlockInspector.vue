<script setup lang="ts">
// The block inspector (visual builder spec §3.4, container-layout spec §5): Content, Layout,
// Style and Advanced for the selected block. Content is the block's schema form; Layout, Style
// and Advanced are generated from the block type's declaration and the style schema, with tab
// membership decided per property by `tabMap`. Every edit leaves as an intent the page turns into
// a tree mutation (and so into history operations).
import { computed, ref, watch } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { StyleSchemaResult } from '@/queries/styleSchema'
import type { Breakpoint, StyleClassRef, StyleValue } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import BlockFields from '@/fields/components/blocks/BlockFields.vue'
import { isProseBlockType, proseRichFieldName } from '@/fields/components/blocks/proseDetection'
import StyleTab from './StyleTab.vue'
import LayoutTab from './LayoutTab.vue'
import { hasTab } from './tabMap'
import AdvancedTab from './AdvancedTab.vue'

const props = defineProps<{
  block: BlockInstance
  blockType: BlockType | null
  /** Null while the schema loads: the Style tab waits. */
  schema: StyleSchemaResult | null
  classes: StyleClassRef[]
  /** Class id => name for the Style tab's source labels; ids absent here are missing. */
  classNames?: Record<string, string>
  classOptions?: { id: string; name: string; archived: boolean; locked: boolean }[]
  reResolving?: boolean
  activeBreakpoint: Breakpoint
  /** A sibling multi-selection (spec §5.5): `block` is its anchor; only Style applies to all. */
  blocks?: BlockInstance[]
  blockTypes?: (BlockType | null)[]
  /** The selected block's immediate parent, for the Layout tab's item controls. */
  parent?: BlockInstance | null
  parentType?: BlockType | null
  parentClasses?: StyleClassRef[]
}>()
const emit = defineEmits<{
  'patch-data': [name: string, value: unknown]
  'insert-into': [field: string]
  'set-setting': [path: string, breakpoint: Breakpoint | null, value: StyleValue | null]
  'set-all': [path: string, value: StyleValue]
  'set-advanced': [
    path: 'anchor' | 'css_classes' | 'attributes' | 'accessibility.label',
    value: unknown,
  ]
  'update:activeBreakpoint': [breakpoint: Breakpoint]
  'apply-class': [id: string]
  'remove-class': [id: string]
  'reorder-classes': [ids: string[]]
  'detach-class': [id: string]
  'detach-all': []
  'save-as-class': []
  /** The Layout tab's link out of a block to the parent whose mode governs it. */
  'select-parent': [id: string]
}>()

const tab = ref('content')
const multi = computed(() => (props.blocks?.length ?? 0) > 1)
const ALL_TABS = [
  { label: 'Content', value: 'content', slot: 'content' as const },
  { label: 'Layout', value: 'layout', slot: 'layout' as const },
  { label: 'Style', value: 'style', slot: 'style' as const },
  { label: 'Advanced', value: 'advanced', slot: 'advanced' as const },
]

/** Every managed path the selection declares, capabilities expanded against the schema. */
const declaredPaths = computed<string[]>(() => {
  const rows = props.schema?.properties ?? []
  const byPath = new Set(rows.map((r) => r.path))
  const types = multi.value ? (props.blockTypes ?? []) : [props.blockType]
  const sets = types.map((type) => {
    const out = new Set<string>()
    for (const entry of type?.style_capabilities ?? []) {
      if (byPath.has(entry)) out.add(entry)
      else for (const row of rows) if (row.group === entry) out.add(row.path)
    }
    return out
  })
  if (sets.length === 0) return []
  const [first, ...rest] = sets
  return [...first!].filter((path) => rest.every((set) => set.has(path)))
})

/** The Layout tab appears only for a block that declares something belonging to it (spec §5). */
const hasLayout = computed(() => hasTab(declaredPaths.value, 'layout'))
const tabs = computed(() =>
  ALL_TABS.filter((t) => {
    if (t.value === 'layout') return hasLayout.value
    // A multi-selection edits settings only: Layout and Style, never Content or Advanced.
    return multi.value ? t.value === 'style' : true
  }),
)
watch(
  [multi, hasLayout],
  () => {
    if (multi.value && tab.value !== 'style' && tab.value !== 'layout') tab.value = 'style'
    if (tab.value === 'layout' && !hasLayout.value) tab.value = 'style'
  },
  { immediate: true },
)
const title = computed(() =>
  multi.value ? `${props.blocks!.length} blocks` : (props.blockType?.label ?? props.block.type),
)
/** A prose block's body is edited in place on the stage, never through a second editor here. */
const proseField = computed(() =>
  props.blockType && isProseBlockType(props.blockType) ? proseRichFieldName(props.blockType) : null,
)
</script>

<template>
  <div class="space-y-3" data-test="block-inspector">
    <div class="flex items-center gap-2">
      <UIcon :name="blockType?.icon || 'i-lucide-box'" class="shrink-0" />
      <span class="text-sm font-medium" data-test="block-inspector-title">{{ title }}</span>
    </div>
    <UTabs
      v-model="tab"
      :items="tabs"
      :unmount-on-hide="false"
      size="xs"
      variant="link"
      data-test="block-inspector-tabs"
    >
      <template v-if="!multi" #content>
        <p v-if="proseField" class="mb-2 text-xs text-muted" data-test="prose-on-stage">
          Edit the text directly on the stage.
        </p>
        <BlockFields
          :block="block"
          :type="blockType ?? undefined"
          :exclude="proseField ? [proseField] : []"
          @patch="(name, value) => emit('patch-data', name, value)"
          @insert-into="(field) => emit('insert-into', field)"
        />
      </template>
      <template #layout>
        <p v-if="schema === null" class="text-xs text-muted" data-test="layout-loading">
          Loading the style schema…
        </p>
        <LayoutTab
          v-else
          :block="block"
          :block-type="blockType"
          :schema="schema"
          :classes="classes"
          :class-names="classNames"
          :re-resolving="reResolving"
          :active-breakpoint="activeBreakpoint"
          :blocks="blocks"
          :block-types="blockTypes"
          :parent="parent"
          :parent-type="parentType"
          :parent-classes="parentClasses"
          @set="(path, bp, value) => emit('set-setting', path, bp, value)"
          @set-all="(path, value) => emit('set-all', path, value)"
          @update:active-breakpoint="(bp) => emit('update:activeBreakpoint', bp)"
          @select-parent="(id) => emit('select-parent', id)"
        />
      </template>
      <template #style>
        <p v-if="schema === null" class="text-xs text-muted" data-test="style-loading">
          Loading the style schema…
        </p>
        <StyleTab
          v-else
          :block="block"
          :block-type="blockType"
          :schema="schema"
          :classes="classes"
          :class-names="classNames"
          :re-resolving="reResolving"
          :active-breakpoint="activeBreakpoint"
          :blocks="blocks"
          :block-types="blockTypes"
          @set="(path, bp, value) => emit('set-setting', path, bp, value)"
          @set-all="(path, value) => emit('set-all', path, value)"
          @update:active-breakpoint="(bp) => emit('update:activeBreakpoint', bp)"
          @save-as-class="emit('save-as-class')"
        />
      </template>
      <template v-if="!multi" #advanced>
        <AdvancedTab
          :block="block"
          :class-names="classNames"
          :class-options="classOptions"
          @set="(path, value) => emit('set-advanced', path, value)"
          @apply-class="(id) => emit('apply-class', id)"
          @remove-class="(id) => emit('remove-class', id)"
          @reorder-classes="(ids) => emit('reorder-classes', ids)"
          @detach-class="(id) => emit('detach-class', id)"
          @detach-all="emit('detach-all')"
        />
      </template>
    </UTabs>
  </div>
</template>
