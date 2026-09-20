<script setup lang="ts">
import { computed, inject } from 'vue'
import { BlocksContextKey } from './context'
import type { BlockInstance } from './useBlockListOps'
import { isProseBlockType, proseRichFieldName } from './proseDetection'
import BlockProse from './BlockProse.vue'
import BlockRegion from './BlockRegion.vue'
import BlockFields from './BlockFields.vue'

// One block: header chrome (icon, label, summary, actions), delete-confirm, and
// the schema-form body. Container regions recurse through BlockList — NOT the
// registry's BlocksField — so the ops-owning root stays the single writer for
// the whole tree (cross-container drag and the outline depend on that).
const props = defineProps<{
  block: BlockInstance
  depth: number
  parentId: string | null
  region: string | null
  index: number
}>()

const emit = defineEmits<{ 'request-insert': [index: number] }>()

const ctx = inject(BlocksContextKey)!

const type = computed(() => ctx.bySlug.value.get(props.block.type))

// Prose seam (spec §3): the CONVENTION predicate decides chromeless rendering.
const prose = computed(() => (type.value ? isProseBlockType(type.value) : false))
const richField = computed(() => (type.value ? proseRichFieldName(type.value) : null))

// The card's one line: what the block says (its title-like field) before what it is set to
// (an icon name); an enum choice never stands in for content — a feature reads
// "One-command upgrades", not "circle-arrow-up", and an unset one reads nothing.
const SAYS_FIRST = ['title', 'heading', 'headline', 'label', 'name', 'question', 'text']
const summary = computed(() => {
  const schema = type.value?.schema ?? []
  const ordered = [
    ...schema.filter((f) => SAYS_FIRST.includes(f.name)),
    ...schema.filter((f) => !SAYS_FIRST.includes(f.name) && f.type !== 'enum'),
  ]
  for (const f of ordered) {
    const v = props.block.data[f.name]
    if (typeof v === 'string' && v.trim() !== '') return v.slice(0, 60)
  }
  return ''
})

// Per-card confirm state (the pre-refactor field-global ref behaved identically
// for a single user; local state avoids plumbing a model through every level).
import { ref } from 'vue'
const pendingDelete = ref<string | null>(null)

function toggleExpanded(): void {
  ctx.expanded[props.block.id] = !ctx.expanded[props.block.id]
}

function move(delta: number): void {
  ctx.apply((t) => ctx.ops.moveById(t, props.block.id, delta))
}

function duplicate(): void {
  ctx.apply((t) => ctx.ops.duplicateById(t, props.block.id))
}

function askDelete(): void {
  pendingDelete.value = props.block.id
}

function cancelDelete(): void {
  pendingDelete.value = null
}

function remove(): void {
  ctx.apply((t) => ctx.ops.removeById(t, props.block.id))
  pendingDelete.value = null
}

function patchData(name: string, value: unknown): void {
  ctx.apply((t) => ctx.ops.patchDataById(t, props.block.id, name, value))
}

/**
 * Keyboard movement on the HEADER only (spec §2): plain typing inside nested
 * field inputs never reaches this handler. Roving tabindex lives on the header
 * button (natively focusable).
 */
/** A modified click selects (the page's multi-selection); a plain click toggles the card. */
function onHeaderClick(event: MouseEvent): void {
  if (event.shiftKey || event.metaKey || event.ctrlKey) {
    ctx.selectIntent(props.block.id, {
      shift: event.shiftKey,
      meta: event.metaKey || event.ctrlKey,
    })
    return
  }
  toggleExpanded()
}

function onHeaderKeydown(event: KeyboardEvent): void {
  const meta = event.metaKey || event.altKey
  if (meta && event.key === 'ArrowUp') {
    move(-1)
  } else if (meta && event.key === 'ArrowDown') {
    move(1)
  } else if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'd') {
    duplicate()
  } else if (event.key === 'Delete' || event.key === 'Backspace') {
    askDelete()
  } else if (event.key === '/') {
    emit('request-insert', props.index + 1)
  } else {
    return // Enter keeps the button's native click -> toggleExpanded
  }
  event.preventDefault()
  event.stopPropagation()
}
</script>

<template>
  <!-- PROSE path (spec §3): chromeless flowing text — action chrome on hover
       only, no border, no expand toggle (always "open"). -->
  <div
    v-if="prose && richField"
    class="group/prose relative"
    :data-test="`prose-block-${block.id}`"
  >
    <div
      class="absolute -top-2 right-0 z-10 flex items-center gap-1 opacity-0 transition-opacity group-hover/prose:opacity-100 focus-within:opacity-100"
    >
      <button
        class="cursor-grab"
        type="button"
        :data-test="`block-drag-${block.id}`"
        aria-label="Drag to reorder"
      >
        <UIcon name="i-lucide-grip-vertical" class="size-4 text-muted" />
      </button>
      <UButton
        v-if="ctx.settingsIntent"
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-sliders-horizontal"
        :data-test="`block-settings-${block.id}`"
        aria-label="Block settings"
        title="Layout, style and advanced settings"
        @click="ctx.settingsIntent(block.id)"
      />
      <UButton
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-copy"
        :data-test="`block-duplicate-${block.id}`"
        aria-label="Duplicate"
        @click="duplicate()"
      />
      <UButton
        variant="ghost"
        color="error"
        size="xs"
        icon="i-lucide-trash-2"
        :data-test="`block-delete-${block.id}`"
        aria-label="Delete"
        @click="askDelete()"
      />
    </div>
    <div
      v-if="pendingDelete === block.id"
      class="mb-1 flex items-center gap-2 rounded border border-default px-3 py-2"
    >
      <span class="flex-1 text-sm text-muted">Delete this text block?</span>
      <UButton size="xs" color="error" data-test="block-delete-confirm" @click="remove()">
        Delete
      </UButton>
      <UButton size="xs" variant="ghost" color="neutral" @click="cancelDelete()">Cancel</UButton>
    </div>
    <BlockProse :block="block" :field="richField" :parent-id="parentId" :region="region" />
  </div>

  <!-- WIDGET path: the card. -->
  <div
    v-else
    class="rounded-lg border border-default"
    :data-test="`block-card-${block.id}`"
    :data-block-id="block.id"
  >
    <div class="group/card flex items-center gap-2 px-3 py-2">
      <button
        class="cursor-grab opacity-0 transition-opacity group-hover/card:opacity-100 focus-visible:opacity-100"
        type="button"
        :data-test="`block-drag-${block.id}`"
        aria-label="Drag to reorder"
      >
        <UIcon name="i-lucide-grip-vertical" class="size-4 text-muted" />
      </button>
      <UIcon :name="type?.icon || 'i-lucide-box'" class="shrink-0" />
      <button
        class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm"
        type="button"
        :data-test="`block-toggle-${block.id}`"
        @click="onHeaderClick"
        @keydown="onHeaderKeydown"
      >
        <span class="font-medium">{{ type?.label ?? block.type }}</span>
        <span class="truncate text-muted">{{ summary }}</span>
      </button>
      <UBadge
        v-if="type && !type.active"
        size="xs"
        color="warning"
        variant="subtle"
        :data-test="`block-inactive-${block.id}`"
      >
        inactive
      </UBadge>
      <UButton
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-chevron-up"
        :data-test="`block-move-up-${block.id}`"
        aria-label="Move up"
        @click="move(-1)"
      />
      <UButton
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-chevron-down"
        :data-test="`block-move-down-${block.id}`"
        aria-label="Move down"
        @click="move(1)"
      />
      <UButton
        v-if="ctx.settingsIntent"
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-sliders-horizontal"
        :data-test="`block-settings-${block.id}`"
        aria-label="Block settings"
        title="Layout, style and advanced settings"
        @click="ctx.settingsIntent(block.id)"
      />
      <UButton
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-copy"
        :data-test="`block-duplicate-${block.id}`"
        aria-label="Duplicate"
        @click="duplicate()"
      />
      <UButton
        variant="ghost"
        color="error"
        size="xs"
        icon="i-lucide-trash-2"
        :data-test="`block-delete-${block.id}`"
        aria-label="Delete"
        @click="askDelete()"
      />
    </div>
    <div
      v-if="pendingDelete === block.id"
      class="flex items-center gap-2 border-t border-default px-3 py-2"
    >
      <span class="flex-1 text-sm text-muted">Delete this block?</span>
      <UButton size="xs" color="error" data-test="block-delete-confirm" @click="remove()">
        Delete
      </UButton>
      <UButton size="xs" variant="ghost" color="neutral" @click="cancelDelete()">Cancel</UButton>
    </div>
    <div v-if="ctx.expanded[block.id]" class="border-t border-default p-3">
      <!-- The schema form (BlockFields); container regions nest a BlockList inside the
           same ops-owning tree, or the max-depth notice at the cap. -->
      <BlockFields :block="block" :type="type" @patch="patchData">
        <template #blocks="{ field, label }">
          <BlockRegion :block="block" :field="field" :label="label" :depth="depth" />
        </template>
      </BlockFields>
    </div>
  </div>
</template>
