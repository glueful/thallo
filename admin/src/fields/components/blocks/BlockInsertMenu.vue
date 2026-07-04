<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { BlockType } from '@/queries/blockTypes'

// The searchable block picker (spec §2): a FLAT, type-to-filter tile grid
// (no category headings — Gutenberg-style); categories only order the tiles
// so related blocks stay adjacent.
// One component serves the per-list "Add block" button, the hover "+" dividers,
// and the `/` keyboard shortcut — anchored wherever its parent renders it.
// `types` is the CONTAINING LIST's options (stage-toolbar spec §5): the parent
// resolves them via pickerTypesForList, so this menu carries no list identity.
const props = defineProps<{ open: boolean; types: BlockType[] }>()
const emit = defineEmits<{ select: [type: BlockType]; close: [] }>()

const filter = ref('')

watch(
  () => props.open,
  (open) => {
    if (open) filter.value = ''
  },
)

const filtered = computed(() => {
  const q = filter.value.trim().toLowerCase()
  if (q === '') return props.types
  return props.types.filter(
    (t) =>
      t.label.toLowerCase().includes(q) ||
      t.slug.toLowerCase().includes(q) ||
      (t.description ?? '').toLowerCase().includes(q),
  )
})

// Ordered by the free-form category (presentation only): named categories
// alphabetical, uncategorized last — the tiles CLUSTER by category but no
// headings render.
const ordered = computed(() => {
  const rank = (t: BlockType): string => t.category?.trim() || '\uffff'
  return [...filtered.value].sort((a, b) => rank(a).localeCompare(rank(b)))
})

function onFilterKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('close')
  }
  if (event.key === 'Enter') {
    // The first VISIBLE tile (category-ordered), matching what the eye sees.
    const first = ordered.value[0]
    if (first) emit('select', first)
    event.preventDefault()
  }
}
</script>

<template>
  <!-- Rendered inside a UPopover (BlockList): the popover theme owns the
       ring/shadow/rounded chrome; this panel only sizes and pads itself. -->
  <div v-if="open" class="w-72 p-1" data-test="block-picker">
    <input
      v-focus
      v-model="filter"
      type="text"
      placeholder="Filter blocks…"
      class="mb-1 w-full rounded border border-default bg-transparent px-2 py-1 text-sm outline-none"
      data-test="block-picker-filter"
      @keydown="onFilterKeydown"
    />
    <!-- Internal scroll: 30 seeded types would otherwise grow the menu past
         the viewport; the filter input stays pinned above. -->
    <div class="max-h-72 overflow-y-auto overscroll-contain" data-test="block-picker-scroll">
    <!-- One flat tile grid (no category headings); category still orders the
         tiles and the description moves into the tooltip. -->
    <div class="grid grid-cols-[repeat(auto-fill,minmax(7rem,1fr))] gap-1">
      <button
        v-for="t in ordered"
        :key="t.slug"
        class="flex flex-col items-center gap-1 rounded px-2 py-1.5 text-center text-xs hover:bg-elevated"
        type="button"
        :title="t.description ?? undefined"
        :data-test="`picker-item-${t.slug}`"
        @click="emit('select', t)"
      >
        <UIcon :name="t.icon || 'i-lucide-box'" class="size-4 text-muted" />
        <span class="w-full truncate font-medium">{{ t.label }}</span>
      </button>
    </div>
    <p v-if="!filtered.length" class="px-2 py-1.5 text-sm text-muted">No block types available.</p>
    </div>
  </div>
</template>

<script lang="ts">
// Autofocus the filter when the menu opens (jsdom-safe: focus() is a no-op there).
const vFocus = {
  mounted: (el: HTMLElement) => el.focus(),
}
export default { directives: { focus: vFocus } }
</script>
