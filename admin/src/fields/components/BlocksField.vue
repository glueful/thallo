<script setup lang="ts">
import { computed, provide, reactive, nextTick, ref } from 'vue'
import type { FieldDef } from '../types'
import { toFieldDef } from '../normalize'
import { useBlockTypes } from '@/queries/blockTypes'
import { MAX_BLOCK_DEPTH } from '@/queries/blockTypes'
import { BlocksContextKey, type BlocksContext } from './blocks/context'
import { createBlockListOps, newBlockId, type BlockInstance } from './blocks/useBlockListOps'
import { defaultProseType, proseRichFieldName } from './blocks/proseDetection'
import BlockList from './blocks/BlockList.vue'
import BlockOutlineRail from './blocks/BlockOutlineRail.vue'

// The ops-owning ROOT of a blocks field (spec §1): owns the whole {id,type,data}
// tree, provides id-addressed pure operations via context, and renders the root
// BlockList. Container regions recurse through BlockList INSIDE this tree — the
// registry only ever mounts BlocksField for entry-level fields. `depth` is kept
// for the registry contract; nesting depth is tracked through BlockList.
const props = defineProps<{ field: FieldDef; depth?: number }>()
const model = defineModel<BlockInstance[]>({ default: () => [] })

const { data: allTypes } = useBlockTypes()
const bySlug = computed(() => new Map((allTypes.value ?? []).map((t) => [t.slug, t])))

const allowlist = computed(() => props.field.blockTypes ?? [])

// Picker: ACTIVE types, filtered by the field's picker-only allowlist (spec §1).
const pickerTypes = computed(() =>
  (allTypes.value ?? []).filter(
    (t) => t.active && (allowlist.value.length === 0 || allowlist.value.includes(t.slug)),
  ),
)

// Container regions of a block type = its blocks-typed field names.
function regionsOf(slug: string): string[] {
  const type = bySlug.value.get(slug)
  if (!type) return []
  return type.schema.filter((f) => toFieldDef(f).type === 'blocks').map((f) => f.name)
}

const ops = createBlockListOps(regionsOf)
const expanded = reactive<Record<string, boolean>>({})

function apply(fn: (tree: BlockInstance[]) => BlockInstance[]): void {
  model.value = fn(model.value ?? [])
}

function selectBlock(id: string): void {
  // Expand every ancestor so the card is visible, then scroll + focus its header.
  let current: string | null = id
  const tree = model.value ?? []
  while (current) {
    expanded[current] = true
    current = parentOf(tree, current)
  }
  void nextTick(() => {
    const header = document.querySelector<HTMLElement>(`[data-test="block-toggle-${id}"]`)
    header?.scrollIntoView?.({ block: 'center' }) // optional-call: jsdom has no scrollIntoView
    header?.focus()
  })
}

function parentOf(tree: BlockInstance[], id: string, parent: string | null = null): string | null {
  for (const block of tree) {
    if (block.id === id) return parent
    for (const region of regionsOf(block.type)) {
      const inner = (block.data[region] as BlockInstance[] | undefined) ?? []
      const found = parentOf(inner, id, block.id)
      if (found !== null || inner.some((b) => b.id === id)) return found ?? block.id
    }
  }
  return null
}

// ── Drag (spec §2) ─────────────────────────────────────────────────────────
// Target identity comes from the EVENT: for a cross-container drop, `event.to`
// is the destination list's element, which may not be the component handling
// @end — every BlockList carries data-list-parent/-region and every card
// data-block-id, so ONE root handler resolves the intent, gates it with
// canDropAt (subtree-aware, BEFORE mutation), and commits through the ops
// layer. Sortable only ever touched local mirrors; dragVersion re-derives them.
const dragVersion = ref(0)
const dropRejected = ref(false)
let rejectTimer: ReturnType<typeof setTimeout> | null = null

function onDragEnd(event: {
  item: HTMLElement
  to: HTMLElement
  from: HTMLElement
  newIndex?: number
}): void {
  const dragId = event.item.dataset.blockId ?? ''
  const parentId = event.to.dataset.listParent || null
  const region = event.to.dataset.listRegion || null
  const index = event.newIndex ?? 0
  const tree = model.value ?? []
  if (dragId === '' || !ops.canDropAt(tree, dragId, { parentId, region })) {
    dropRejected.value = true
    if (rejectTimer) clearTimeout(rejectTimer)
    rejectTimer = setTimeout(() => (dropRejected.value = false), 3000)
  } else {
    apply((t) => ops.moveAcross(t, dragId, { parentId, region, index }))
  }
  dragVersion.value++
}

const context: BlocksContext = {
  bySlug,
  pickerTypes,
  allowlist: allowlist.value,
  regionsOf,
  apply,
  ops,
  expanded,
  selectBlock,
  dragGroup: `blocks-${newBlockId()}`,
  onDragEnd,
  dragVersion,
  maxDepth: MAX_BLOCK_DEPTH,
}
provide(BlocksContextKey, context)

/** Canvas routing (visual-canvas spec §5): does this field's tree contain `id`? */
function hasBlock(id: string): boolean {
  return ops.findById(model.value ?? [], id) !== null
}

// Exposed API: onDragEnd is the direct-handler testing seam (jsdom cannot
// simulate sortable); selectBlock/hasBlock let the visual canvas route a
// stage selection to this field and expand/scroll/focus the block.
defineExpose({ onDragEnd, selectBlock, hasBlock })

// ── Tail prose (spec §3) ──────────────────────────────────────────────────────
// Selection rule: allowed active rich_text -> first allowed active prose type ->
// hidden. Keeps rich_text the starter default without a hard dependency on it.
const tailProseType = computed(() => defaultProseType(allTypes.value ?? [], allowlist.value))

// Outline rail (spec §4): per-field, hidden behind a header toggle.
const outlineOpen = ref(false)

function toggleOutline(): void {
  outlineOpen.value = !outlineOpen.value
}

function addTailProse(): void {
  const type = tailProseType.value
  if (!type) return
  const name = proseRichFieldName(type)
  const block: BlockInstance = {
    id: newBlockId(),
    type: type.slug,
    data: name ? { [name]: '' } : {},
  }
  apply((t) =>
    ops.insertAt(t, { parentId: null, region: null, index: (model.value ?? []).length }, block),
  )
  expanded[block.id] = true
}
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <template #hint>
      <UButton
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-list-tree"
        aria-label="Toggle outline"
        data-test="block-outline-toggle"
        @click="toggleOutline()"
      />
    </template>
    <div class="space-y-2" data-test="blocks-field">
      <BlockOutlineRail v-if="outlineOpen" :blocks="model ?? []" />
      <p
        v-if="dropRejected"
        class="rounded border border-warning/40 bg-warning/10 px-2 py-1.5 text-xs"
        data-test="drop-rejected"
      >
        That drop would exceed the maximum nesting depth ({{ MAX_BLOCK_DEPTH }}).
      </p>
      <!-- depth honors the prop (default 1): the registry contract kept `depth`
           for nested mounts; the new tree recurses internally, but a caller-set
           starting depth must still cap nested regions correctly. -->
      <BlockList :blocks="model ?? []" :parent-id="null" :region="null" :depth="depth ?? 1" />
      <button
        v-if="tailProseType"
        class="w-full rounded px-1 py-1.5 text-left text-sm text-dimmed hover:text-muted"
        type="button"
        data-test="tail-prose"
        @click="addTailProse()"
      >
        Type here…
      </button>
    </div>
  </UFormField>
</template>
