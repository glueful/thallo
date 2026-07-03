<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import { BlocksContextKey } from './context'
import type { BlockInstance } from './useBlockListOps'
import { newBlockId } from './useBlockListOps'
import BlockCard from './BlockCard.vue'
import BlockInsertMenu from './BlockInsertMenu.vue'
import type { BlockType } from '@/queries/blockTypes'

// One list level (the root or a container region): insert dividers at every gap,
// cards, and the per-level "Add block" button — all opening the ONE searchable
// insert menu. List identity rides on data attributes so drag drops resolve
// their TARGET from event.to (never the handling component).
const props = defineProps<{
  blocks: BlockInstance[]
  parentId: string | null
  region: string | null
  depth: number
}>()

const ctx = inject(BlocksContextKey)!

// This list's picker options (stage-toolbar spec §5): resolved by the ONE
// context resolver from this list's own identity.
const pickerTypes = computed(() => ctx.pickerTypesForList(props.parentId, props.region))

// Sortable mutates this LOCAL mirror only — the model is written exclusively by
// the root's onDragEnd through the ops layer (thin-binding rule). Re-derived
// from props after every drag end (dragVersion covers the cross-container case
// where a reject leaves the NON-handling list's mirror stale).
const localList = ref<BlockInstance[]>([...props.blocks])
watch(
  [() => props.blocks, ctx.dragVersion],
  () => {
    localList.value = [...props.blocks]
  },
  { deep: false },
)

// Which gap the insert menu is open at; null = closed.
const menuIndex = ref<number | null>(null)

function openMenuAt(index: number): void {
  menuIndex.value = menuIndex.value === index ? null : index
}

function closeMenu(): void {
  menuIndex.value = null
}

function insertType(type: BlockType): void {
  const index = menuIndex.value ?? props.blocks.length
  const block: BlockInstance = { id: newBlockId(), type: type.slug, data: {} }
  ctx.apply((t) =>
    ctx.ops.insertAt(t, { parentId: props.parentId, region: props.region, index }, block),
  )
  ctx.expanded[block.id] = true
  menuIndex.value = null
}
</script>

<template>
  <div class="space-y-1">
    <!-- The draggable element IS the list-identity carrier: event.to points at
         it, and its dataset names the destination (parentId/region). Each
         sortable ITEM is the wrapper div (divider + menu + card) so gaps travel
         with their block. -->
    <VueDraggable
      v-model="localList"
      :group="ctx.dragGroup"
      handle="[data-test^='block-drag-']"
      :animation="150"
      class="space-y-1"
      :data-list-parent="parentId ?? ''"
      :data-list-region="region ?? ''"
      @end="ctx.onDragEnd"
    >
      <div
        v-for="(block, index) in localList"
        :key="block.id"
        :data-block-id="block.id"
      >
        <!-- Hover-revealed insert divider at gap `index` (before this block). -->
        <div class="group/divider relative -my-0.5 h-2">
          <button
            class="absolute inset-x-0 -top-1 flex h-4 items-center justify-center opacity-0 transition-opacity group-hover/divider:opacity-100 focus-visible:opacity-100"
            type="button"
            :data-test="`block-insert-${index}`"
            :aria-label="`Insert block at position ${index + 1}`"
            @click="openMenuAt(index)"
          >
            <span class="h-px flex-1 bg-accented" />
            <UIcon name="i-lucide-plus" class="mx-1 size-3.5 text-muted" />
            <span class="h-px flex-1 bg-accented" />
          </button>
        </div>
        <BlockInsertMenu
          v-if="menuIndex === index"
          open
          :types="pickerTypes"
          @select="insertType"
          @close="closeMenu"
        />

        <BlockCard
          :block="block"
          :depth="depth"
          :parent-id="parentId"
          :region="region"
          :index="index"
          @request-insert="openMenuAt"
        />
      </div>
    </VueDraggable>

    <!-- Tail gap divider + the always-visible "Add block" button. -->
    <div v-if="blocks.length > 0" class="group/divider relative -my-0.5 h-2">
      <button
        class="absolute inset-x-0 -top-1 flex h-4 items-center justify-center opacity-0 transition-opacity group-hover/divider:opacity-100 focus-visible:opacity-100"
        type="button"
        :data-test="`block-insert-${blocks.length}`"
        :aria-label="`Insert block at position ${blocks.length + 1}`"
        @click="openMenuAt(blocks.length)"
      >
        <span class="h-px flex-1 bg-accented" />
        <UIcon name="i-lucide-plus" class="mx-1 size-3.5 text-muted" />
        <span class="h-px flex-1 bg-accented" />
      </button>
    </div>

    <div class="relative">
      <UButton
        variant="subtle"
        color="neutral"
        icon="i-lucide-plus"
        data-test="add-block"
        @click="openMenuAt(blocks.length)"
      >
        Add block
      </UButton>
      <BlockInsertMenu
        v-if="menuIndex === blocks.length"
        open
        :types="pickerTypes"
        @select="insertType"
        @close="closeMenu"
      />
    </div>
  </div>
</template>
