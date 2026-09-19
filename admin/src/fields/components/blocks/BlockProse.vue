<script setup lang="ts">
// A prose block's body, edited as the document it is — the chromeless editor with its `/` menu —
// writing through the root blocks field like everything else in the tree. Hosted by the editor's
// card and by the Design page's Block tab, so the panel is not a dead end for text that is
// awkward to edit in place: a block hidden at this breakpoint, a narrow column, a long body.
import { computed, inject } from 'vue'
import { BlocksContextKey } from './context'
import ProseBlockEditor from './ProseBlockEditor.vue'
import { newBlockId, type BlockInstance } from './useBlockListOps'

const props = defineProps<{
  block: BlockInstance
  /** The rich field that is the block's body. */
  field: string
  /** The list the block sits in: what a `/` block is inserted into, beside it. */
  parentId: string | null
  region: string | null
  readonly?: boolean
}>()

const ctx = inject(BlocksContextKey)!
const pickerTypes = computed(() => ctx.pickerTypesForList(props.parentId, props.region))

function onUpdate(html: string): void {
  ctx.apply((t) => ctx.ops.patchDataById(t, props.block.id, props.field, html))
}

function onInsertBlock(payload: { slug: string; beforeHtml: string; afterHtml: string }): void {
  if (payload.slug === '') return
  ctx.apply((t) =>
    ctx.ops.splitRichTextAt(t, props.block.id, props.field, payload.beforeHtml, payload.afterHtml, {
      id: newBlockId(),
      type: payload.slug,
      data: {},
      settings: {},
    }),
  )
}
</script>

<template>
  <ProseBlockEditor
    :model-value="(block.data[field] as string) ?? ''"
    :picker-types="pickerTypes"
    :readonly="readonly"
    @update:model-value="onUpdate"
    @insert-block="onInsertBlock"
  />
</template>
