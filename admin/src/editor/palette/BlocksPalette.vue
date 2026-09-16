<script setup lang="ts">
// The Blocks tab (visual builder spec §5.1, §5.5 — Phase C.1): the Design page's one palette.
// Every active type as a tile in the shared picker order; a click inserts at the armed target
// (or the page's default), a pointer-down starts a drag the page's palette drag owns. A tile the
// target refuses is inert to click and Enter and says why, but stays draggable — the same type
// may be legal elsewhere on the stage.
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { Legality } from '@/editor/structure/legality'
import { groupByCategory, orderTypes } from './order'

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
/** The matching tiles in their category sections (the block-types page's rule); empty ones hide. */
const groups = computed(() => groupByCategory(ordered.value))
const clickableSlugs = computed(() =>
  groups.value
    .flatMap((g) => g.items)
    .filter((t) => verdicts.value.get(t.slug)?.ok !== false)
    .map((t) => t.slug),
)
const reasonOf = (slug: string): string | undefined => {
  const v = verdicts.value.get(slug)
  return v && !v.ok ? v.message : undefined
}

// An armed target focuses the search: `+`, type, Enter stays three actions. The tab may mount
// only when first shown, after the target was armed, so mount focuses too.
function focusSearch(): void {
  // After the tab switch settles (the tabs move focus to their trigger first).
  void nextTick(() => setTimeout(() => search.value?.focus(), 0))
}
watch(
  () => props.target,
  (target) => {
    if (target) focusSearch()
  },
)
onMounted(() => {
  if (props.target) focusSearch()
})

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

/** Keyboard activation inserts; a mouse click (detail > 0) was already judged by the pointer path. */
function onTileClick(slug: string, event: MouseEvent): void {
  if (event.detail > 0 || reasonOf(slug) !== undefined) return
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
    <section
      v-for="group in groups"
      :key="group.category"
      class="space-y-1.5"
      :data-test="`palette-group-${group.category}`"
    >
      <h4 class="text-[11px] font-semibold uppercase tracking-wide text-muted">
        {{ group.category }}
      </h4>
      <div class="grid grid-cols-2 gap-1.5">
        <button
          v-for="t in group.items"
          :key="t.slug"
          type="button"
          class="flex select-none flex-col items-center gap-1.5 rounded-md border border-default bg-default px-2 py-2.5 text-center text-xs cursor-grab transition-colors hover:border-primary hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary active:cursor-grabbing aria-disabled:opacity-50"
          :aria-disabled="reasonOf(t.slug) !== undefined ? 'true' : undefined"
          :title="reasonOf(t.slug) ?? t.description ?? undefined"
          :data-test="`palette-card-${t.slug}`"
          @click="(e: MouseEvent) => onTileClick(t.slug, e)"
          @pointerdown="(e: PointerEvent) => onTilePointerDown(t.slug, e)"
        >
          <UIcon :name="t.icon || 'i-lucide-box'" class="size-5 text-muted" />
          <span class="w-full truncate font-medium">{{ t.label }}</span>
        </button>
      </div>
    </section>
    <p v-if="!ordered.length" class="px-2 py-1.5 text-sm text-muted">No block types match.</p>
  </div>
</template>
