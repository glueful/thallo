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
import BlockRegion from '@/fields/components/blocks/BlockRegion.vue'
import BlockProse from '@/fields/components/blocks/BlockProse.vue'
import BlocksContextProvider from '@/fields/components/blocks/BlocksContextProvider.vue'
import type { BlocksHost } from '@/fields/components/blocks/context'
import { isProseBlockType, proseRichFieldName } from '@/fields/components/blocks/proseDetection'
import StyleTab from './StyleTab.vue'
import LayoutTab from './LayoutTab.vue'
import type { FillAvailability } from '@/editor/structure/gridFill'
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
  /**
   * The root blocks field that owns the block, and the block's place in it. With it the Content
   * tab is the form the main Content tab has — a blocks-typed field is its list of cards, a prose
   * body its editor — because they are the same components in the same context. Without it
   * (the field still loading) a blocks-typed field is a summary and a prose body is left to the stage.
   */
  blocksHost?: BlocksHost | null
  /** The block's prose body is being edited on the stage: one text has one owner at a time. */
  proseLocked?: boolean
  /** Fill empty cells (spec §11.3) for the selected block, as the page judged it. */
  fill?: (FillAvailability & { preparing: boolean }) | null
  /**
   * Two things a host without the Design page's stage leaves out (the Regions page): the Content
   * tab, where the block's card is already its content form, and the offer to save the block's
   * declarations as a style class, which is that page's flow. Both default to present.
   */
  noContent?: boolean
  noSaveAsClass?: boolean
  /** The host has a stage that can replay the block's motion (the Design page). */
  canPlayMotion?: boolean
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
  'fill-cells': []
  'apply-class': [id: string]
  'remove-class': [id: string]
  'reorder-classes': [ids: string[]]
  'detach-class': [id: string]
  'detach-all': []
  'save-as-class': []
  /** The Style tab's Play: replay this block's motion on the stage. */
  'play-motion': []
  /** The Layout tab's link out of a block to the parent whose mode governs it. */
}>()

const tab = ref(props.noContent ? 'style' : 'content')
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
    if (t.value === 'content') return !props.noContent && !multi.value
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
        <!-- Keyed by field: `provide` is read once, and another field's block is another context. -->
        <BlocksContextProvider
          v-if="blocksHost"
          :key="blocksHost.context.fieldName"
          :context="blocksHost.context"
        >
          <template v-if="proseField">
            <p
              v-if="proseLocked"
              class="mb-2 rounded bg-elevated px-2 py-1.5 text-xs text-muted"
              data-test="prose-locked"
            >
              Editing on the stage — press Esc there to finish. What you type shows here.
            </p>
            <p v-else class="mb-2 text-xs text-muted" data-test="prose-on-stage">
              Write here, or double-click the text on the stage.
            </p>
            <BlockProse
              class="mb-3 rounded-md border border-default px-3 py-2"
              :block="block"
              :field="proseField"
              :parent-id="blocksHost.parentId"
              :region="blocksHost.region"
              :readonly="proseLocked"
            />
          </template>
          <BlockFields
            :block="block"
            :type="blockType ?? undefined"
            :exclude="proseField ? [proseField] : []"
            @patch="(name, value) => emit('patch-data', name, value)"
            @insert-into="(field) => emit('insert-into', field)"
          >
            <template #blocks="{ field, label }">
              <BlockRegion :block="block" :field="field" :label="label" :depth="blocksHost.depth" />
            </template>
          </BlockFields>
        </BlocksContextProvider>
        <template v-else>
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
          :fill="fill"
          @fill-cells="emit('fill-cells')"
          @set="(path, bp, value) => emit('set-setting', path, bp, value)"
          @set-all="(path, value) => emit('set-all', path, value)"
          @update:active-breakpoint="(bp) => emit('update:activeBreakpoint', bp)"
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
          :no-save-as-class="noSaveAsClass"
          :can-play-motion="canPlayMotion"
          @set="(path, bp, value) => emit('set-setting', path, bp, value)"
          @set-all="(path, value) => emit('set-all', path, value)"
          @update:active-breakpoint="(bp) => emit('update:activeBreakpoint', bp)"
          @save-as-class="emit('save-as-class')"
          @play-motion="emit('play-motion')"
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
