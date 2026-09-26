<script setup lang="ts">
// The Blocks tab (visual builder spec §5.1, §5.5 — Phase C.1): the Design page's one palette.
// Every active type as a tile in the shared picker order; a click inserts at the armed target
// (or the page's default), a pointer-down starts a drag the page's palette drag owns. A tile the
// target refuses is inert to click and Enter and says why, but stays draggable — the same type
// may be legal elsewhere on the stage.
//
// It also holds the section and page library (Sections, Pages). A section is ONE block, so its
// card is a tile in every respect — the same click, Enter and drag, emitted down the same path
// under a `pattern:` key the page tells apart from a type's slug. A page is several sections:
// it is inserted whole, by click, and has no drag.
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import {
  patternKey,
  patternThumbnail,
  patternThumbnailSize,
  type Pattern,
} from '@/queries/patterns'
import type { Legality } from '@/editor/structure/legality'
import { groupByCategory, orderTypes } from './order'
import SavedSectionActions from './SavedSectionActions.vue'

const props = defineProps<{
  types: BlockType[]
  /** The section and page library; empty hides the view switch. */
  patterns?: Pattern[]
  /** The resolved armed target's label, or null when nothing is armed. */
  target: { label: string } | null
  /** The armed target resolved to nothing (its block or gap is gone). */
  stale: boolean
  /** Click availability against the effective target — the page's tile preflight. A section is
   *  asked about by its `pattern:` key. */
  clickable: (slug: string) => Legality
  /** Whether a page may be inserted whole at the effective target. */
  pageClickable?: (slug: string) => Legality
  /** The Pages view's name: the header and footer call theirs Templates. */
  pagesLabel?: string
}>()
const emit = defineEmits<{
  /** A block type's slug, or a section's `pattern:` key. */
  insert: [slug: string]
  'insert-page': [slug: string]
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

// ── The library ─────────────────────────────────────────────────────────────
type View = 'blocks' | 'sections' | 'pages'
const ALL_VIEWS: { value: View; label: string }[] = [
  { value: 'blocks', label: 'Blocks' },
  { value: 'sections', label: 'Sections' },
  { value: 'pages', label: 'Pages' },
]
// Where no page can be inserted (the header and footer), the Pages view is not offered.
const VIEWS = computed(() =>
  props.pageClickable === undefined
    ? ALL_VIEWS.filter((v) => v.value !== 'pages')
    : ALL_VIEWS.map((v) =>
        v.value === 'pages' && props.pagesLabel ? { ...v, label: props.pagesLabel } : v,
      ),
)
const view = ref<View>('blocks')
/** The view's name as its button says it, lowercase — `templates` in the header and footer. */
const viewLabel = computed(
  () => VIEWS.value.find((v) => v.value === view.value)?.label.toLowerCase() ?? view.value,
)
const library = computed(() => props.patterns ?? [])
const kind = computed(() => (view.value === 'pages' ? 'page' : 'section'))
const matching = computed(() => {
  const q = query.value.trim().toLowerCase()
  return library.value.filter(
    (p) =>
      p.kind === kind.value &&
      (q === '' || `${p.label} ${p.category} ${p.description}`.toLowerCase().includes(q)),
  )
})
/** The matching patterns under their categories, in the library's own order. */
const patternGroups = computed(() => {
  const out: { category: string; items: Pattern[] }[] = []
  for (const p of matching.value) {
    const group = out.find((g) => g.category === p.category)
    if (group) group.items.push(p)
    else out.push({ category: p.category, items: [p] })
  }
  return out
})
function patternReason(p: Pattern): string | undefined {
  const verdict =
    p.kind === 'page'
      ? (props.pageClickable?.(p.slug) ?? { ok: true as const })
      : props.clickable(patternKey(p.slug))
  return verdict.ok ? undefined : verdict.message
}
// A thumbnail that does not load gives way to a plain card, never a broken image.
const missingThumbs = reactive(new Set<string>())

function insertPattern(p: Pattern): void {
  if (patternReason(p) !== undefined) return
  if (p.kind === 'page') emit('insert-page', p.slug)
  else emit('insert', patternKey(p.slug))
}
/** A page has no drag, so every click inserts; a section's mouse click is the pointer path's. */
function onPatternClick(p: Pattern, event: MouseEvent): void {
  if (p.kind === 'section' && event.detail > 0) return
  insertPattern(p)
}
function onPatternPointerDown(p: Pattern, event: PointerEvent): void {
  if (p.kind !== 'section' || event.button !== 0) return
  emit('pointer-down', patternKey(p.slug), event)
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
    if (view.value === 'blocks') {
      const first = clickableSlugs.value[0]
      if (first) emit('insert', first)
      return
    }
    const first = matching.value.find((p) => patternReason(p) === undefined)
    if (first) insertPattern(first)
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
      :placeholder="`Filter ${viewLabel}…`"
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
    <div
      v-if="library.length > 0"
      class="grid gap-0.5 rounded-md bg-elevated p-0.5"
      :class="VIEWS.length === 3 ? 'grid-cols-3' : 'grid-cols-2'"
      data-test="palette-views"
    >
      <button
        v-for="v in VIEWS"
        :key="v.value"
        type="button"
        class="rounded px-2 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        :class="
          view === v.value ? 'bg-default text-default shadow-sm' : 'text-muted hover:text-default'
        "
        :aria-pressed="view === v.value ? 'true' : 'false'"
        :data-test="`palette-view-${v.value}`"
        @click="view = v.value"
      >
        {{ v.label }}
      </button>
    </div>
    <template v-if="view !== 'blocks'">
      <section
        v-for="group in patternGroups"
        :key="group.category"
        class="space-y-1.5"
        :data-test="`pattern-group-${group.category}`"
      >
        <h4
          v-if="view === 'sections'"
          class="text-[11px] font-semibold uppercase tracking-wide text-muted"
        >
          {{ group.category }}
        </h4>
        <div class="grid gap-2" :class="view === 'pages' ? 'grid-cols-2' : 'grid-cols-1'">
          <div
            v-for="p in group.items"
            :key="p.slug"
            class="overflow-hidden rounded-md"
            :class="p.saved ? 'border border-default' : ''"
          >
            <button
              type="button"
              class="flex w-full select-none flex-col overflow-hidden bg-default text-left text-xs transition-colors hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary aria-disabled:opacity-50"
              :class="[
                p.saved ? '' : 'rounded-md border border-default',
                p.kind === 'section' ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
              ]"
              :aria-disabled="patternReason(p) !== undefined ? 'true' : undefined"
              :title="patternReason(p) ?? p.description"
              :data-test="`pattern-card-${p.slug}`"
              @click="(e: MouseEvent) => onPatternClick(p, e)"
              @pointerdown="(e: PointerEvent) => onPatternPointerDown(p, e)"
            >
              <!-- A saved section has no picture: its name and description say what it is. -->
              <template v-if="p.saved">
                <span class="flex items-center gap-2 px-2 pt-2 font-medium">
                  <UIcon name="i-lucide-bookmark" class="size-3.5 shrink-0 text-muted" />
                  <span class="truncate">{{ p.label }}</span>
                </span>
                <span v-if="p.description" class="line-clamp-2 px-2 pb-2 pt-0.5 text-muted">
                  {{ p.description }}
                </span>
                <span v-else class="pb-2" />
              </template>
              <!-- A section is shown whole, up to a height; a page is its first screens. -->
              <span
                v-else
                class="block w-full overflow-hidden border-b border-default bg-white"
                :class="view === 'pages' ? 'aspect-[3/4]' : 'max-h-44'"
              >
                <img
                  v-if="!missingThumbs.has(p.slug)"
                  :src="patternThumbnail(p.slug)"
                  :width="patternThumbnailSize(p.slug)?.[0]"
                  :height="patternThumbnailSize(p.slug)?.[1]"
                  alt=""
                  loading="lazy"
                  decoding="async"
                  draggable="false"
                  class="pointer-events-none block h-auto w-full"
                  :class="view === 'pages' ? 'h-full object-cover object-top' : ''"
                  @error="missingThumbs.add(p.slug)"
                />
                <span
                  v-else
                  class="flex h-16 w-full items-center justify-center bg-elevated text-muted"
                  data-test="pattern-thumb-missing"
                >
                  <UIcon name="i-lucide-layout-template" class="size-5" />
                </span>
              </span>
              <span v-if="!p.saved" class="truncate px-2 py-1.5 font-medium">{{ p.label }}</span>
            </button>
            <SavedSectionActions v-if="p.saved" :pattern="p" />
          </div>
        </div>
      </section>
      <p v-if="!matching.length" class="px-2 py-1.5 text-sm text-muted">
        No {{ viewLabel }} match.
      </p>
    </template>
    <section
      v-for="group in view === 'blocks' ? groups : []"
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
    <p v-if="view === 'blocks' && !ordered.length" class="px-2 py-1.5 text-sm text-muted">
      No block types match.
    </p>
  </div>
</template>
