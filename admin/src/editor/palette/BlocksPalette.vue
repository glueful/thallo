<script setup lang="ts">
// The Blocks tab (visual builder spec §5.1, §5.5 — Phase C.1): the Design page's one palette.
// Every active type as a tile in the shared picker order; a click inserts at the armed target
// (or the page's default), a pointer-down starts a drag the page's palette drag owns. A tile the
// target refuses is inert to click and Enter and says why, but stays draggable — the same type
// may be legal elsewhere on the stage.
import { computed, ref, watch } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { Legality } from '@/editor/structure/legality'
import { orderTypes } from './order'

const props = defineProps<{
  types: BlockType[]
  /** The resolved armed target's label, or null when nothing is armed. */
  target: { label: string } | null
  /** The armed target resolved to nothing (its block or gap is gone). */
  stale: boolean
  /** Click availability against the effective target — the page's tile preflight. */
  clickable: (slug: string) => Legality
}>()
const emit = defineEmits<{
  insert: [slug: string]
  'clear-target': []
  'pointer-down': [slug: string, event: PointerEvent]
}>()

const query = ref('')
const search = ref<HTMLInputElement | null>(null)
const ordered = computed(() => orderTypes(props.types, query.value))
const verdicts = computed(
  () => new Map(ordered.value.map((t) => [t.slug, props.clickable(t.slug)])),
)
const clickableSlugs = computed(() =>
  ordered.value.filter((t) => verdicts.value.get(t.slug)?.ok !== false).map((t) => t.slug),
)
const reasonOf = (slug: string): string | undefined => {
  const v = verdicts.value.get(slug)
  return v && !v.ok ? v.message : undefined
}

// An armed target focuses the search: `+`, type, Enter stays three actions.
watch(
  () => props.target,
  (target) => {
    if (target) search.value?.focus()
  },
)

function onSearchKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('clear-target')
    return
  }
  if (event.key === 'Enter') {
    event.preventDefault()
    const first = clickableSlugs.value[0]
    if (first) emit('insert', first)
  }
}

function onTileClick(slug: string): void {
  if (reasonOf(slug) !== undefined) return
  emit('insert', slug)
}

function onTilePointerDown(slug: string, event: PointerEvent): void {
  if (event.button !== 0) return
  emit('pointer-down', slug, event)
}
</script>

<template>
  <div class="space-y-2" data-test="blocks-tab">
    <input
      ref="search"
      v-model="query"
      type="text"
      placeholder="Filter blocks…"
      class="w-full rounded border border-default bg-transparent px-2 py-1 text-sm outline-none"
      data-test="palette-search"
      @keydown="onSearchKeydown"
    />
    <div
      v-if="target || stale"
      class="flex items-center justify-between gap-2 rounded bg-elevated px-2 py-1 text-xs"
      data-test="palette-target"
    >
      <span v-if="target">Inserting {{ target.label }}</span>
      <span v-else class="text-muted"
        >That place is gone — pick a block for the default place.</span
      >
      <button
        v-if="target"
        type="button"
        class="text-muted hover:text-default"
        data-test="palette-target-cancel"
        @click="emit('clear-target')"
      >
        Cancel
      </button>
    </div>
    <div class="grid grid-cols-[repeat(auto-fill,minmax(6.5rem,1fr))] gap-1">
      <button
        v-for="t in ordered"
        :key="t.slug"
        type="button"
        class="flex flex-col items-center gap-1 rounded px-2 py-1.5 text-center text-xs hover:bg-elevated aria-disabled:opacity-50"
        :aria-disabled="reasonOf(t.slug) !== undefined ? 'true' : undefined"
        :title="reasonOf(t.slug) ?? t.description ?? undefined"
        :data-test="`palette-card-${t.slug}`"
        @click="onTileClick(t.slug)"
        @pointerdown="(e: PointerEvent) => onTilePointerDown(t.slug, e)"
      >
        <UIcon :name="t.icon || 'i-lucide-box'" class="size-4 text-muted" />
        <span class="w-full truncate font-medium">{{ t.label }}</span>
      </button>
    </div>
    <p v-if="!ordered.length" class="px-2 py-1.5 text-sm text-muted">No block types match.</p>
  </div>
</template>
