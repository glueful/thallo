<script setup lang="ts">
import { computed, ref, inject, watch } from 'vue'
import { BlocksContextKey } from './context'
import type { BlockType } from '@/queries/blockTypes'

// The searchable block picker (spec §2): category-grouped, TYPE-TO-FILTER list.
// One component serves the per-list "Add block" button, the hover "+" dividers,
// and the `/` keyboard shortcut — anchored wherever its parent renders it.
const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ select: [type: BlockType]; close: [] }>()

const ctx = inject(BlocksContextKey)!
const filter = ref('')

watch(
  () => props.open,
  (open) => {
    if (open) filter.value = ''
  },
)

const filtered = computed(() => {
  const q = filter.value.trim().toLowerCase()
  if (q === '') return ctx.pickerTypes.value
  return ctx.pickerTypes.value.filter(
    (t) =>
      t.label.toLowerCase().includes(q) ||
      t.slug.toLowerCase().includes(q) ||
      (t.description ?? '').toLowerCase().includes(q),
  )
})

// Grouped by the free-form category (presentation only): named categories
// alphabetical, uncategorized under "Other" last. Headings render only when
// there's more than one group.
const groups = computed(() => {
  const map = new Map<string, BlockType[]>()
  for (const t of filtered.value) {
    const key = t.category?.trim() || 'Other'
    if (!map.has(key)) map.set(key, [])
    map.get(key)!.push(t)
  }
  return [...map.entries()].sort(([a], [b]) =>
    a === 'Other' ? 1 : b === 'Other' ? -1 : a.localeCompare(b),
  )
})

function onFilterKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('close')
  }
  if (event.key === 'Enter') {
    const first = filtered.value[0]
    if (first) emit('select', first)
    event.preventDefault()
  }
}
</script>

<template>
  <div v-if="open" class="mt-2 rounded-lg border border-default p-1" data-test="block-picker">
    <input
      v-focus
      v-model="filter"
      type="text"
      placeholder="Filter blocks…"
      class="mb-1 w-full rounded border border-default bg-transparent px-2 py-1 text-sm outline-none"
      data-test="block-picker-filter"
      @keydown="onFilterKeydown"
    />
    <template v-for="[category, types] in groups" :key="category">
      <p
        v-if="groups.length > 1"
        class="px-2 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-muted"
        :data-test="`picker-group-${category}`"
      >
        {{ category }}
      </p>
      <button
        v-for="t in types"
        :key="t.slug"
        class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-elevated"
        type="button"
        :data-test="`picker-item-${t.slug}`"
        @click="emit('select', t)"
      >
        <UIcon :name="t.icon || 'i-lucide-box'" />
        <span class="font-medium">{{ t.label }}</span>
        <span v-if="t.description" class="truncate text-muted">{{ t.description }}</span>
      </button>
    </template>
    <p v-if="!filtered.length" class="px-2 py-1.5 text-sm text-muted">No block types available.</p>
  </div>
</template>

<script lang="ts">
// Autofocus the filter when the menu opens (jsdom-safe: focus() is a no-op there).
const vFocus = {
  mounted: (el: HTMLElement) => el.focus(),
}
export default { directives: { focus: vFocus } }
</script>
