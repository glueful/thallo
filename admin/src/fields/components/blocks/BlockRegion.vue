<script setup lang="ts">
// One blocks-typed field of a block — a container's content, a hero's links — as the list it is:
// the same BlockList, in the same ops-owning tree, wherever it is hosted. The editor's card and
// the Design page's Block tab both show it, so there is one way to add to, reorder, edit and
// remove a block's children, not a full one and a lesser one.
import { inject } from 'vue'
import type { ContentTypeField } from '@/queries/contentTypes'
import { BlocksContextKey } from './context'
import BlockList from './BlockList.vue'
import type { BlockInstance } from './useBlockListOps'

defineProps<{
  block: BlockInstance
  field: ContentTypeField
  label: string
  /** The depth of `block` itself; its children are one deeper. */
  depth: number
}>()

const ctx = inject(BlocksContextKey)!
</script>

<template>
  <p
    v-if="depth >= ctx.maxDepth"
    class="rounded border border-dashed border-default px-2 py-1.5 text-xs text-muted"
    data-test="max-depth-notice"
  >
    “{{ label }}”: maximum nesting depth ({{ ctx.maxDepth }}) reached.
  </p>
  <UFormField v-else :label="label" :name="field.name">
    <BlockList
      :blocks="(block.data[field.name] as BlockInstance[]) ?? []"
      :parent-id="block.id"
      :region="field.name"
      :depth="depth + 1"
    />
  </UFormField>
</template>
