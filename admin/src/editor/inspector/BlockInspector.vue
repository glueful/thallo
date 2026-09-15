<script setup lang="ts">
// The block inspector (visual builder spec §3.4): Content, Style and Advanced for the selected
// block. Content is the block's schema form; Style and Advanced are generated from the block
// type's declaration and the style schema. Every edit leaves as an intent the page turns into
// a tree mutation (and so into history operations).
import { computed, ref, watch } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { StyleSchemaResult } from '@/queries/styleSchema'
import type { Breakpoint, StyleClassRef, StyleValue } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import BlockFields from '@/fields/components/blocks/BlockFields.vue'
import { isProseBlockType, proseRichFieldName } from '@/fields/components/blocks/proseDetection'
import StyleTab from './StyleTab.vue'
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
}>()
const emit = defineEmits<{
  'patch-data': [name: string, value: unknown]
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
}>()

const tab = ref('content')
const multi = computed(() => (props.blocks?.length ?? 0) > 1)
const ALL_TABS = [
  { label: 'Content', value: 'content', slot: 'content' as const },
  { label: 'Style', value: 'style', slot: 'style' as const },
  { label: 'Advanced', value: 'advanced', slot: 'advanced' as const },
]
const tabs = computed(() => (multi.value ? ALL_TABS.filter((t) => t.value === 'style') : ALL_TABS))
watch(
  multi,
  (isMulti) => {
    if (isMulti) tab.value = 'style'
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
