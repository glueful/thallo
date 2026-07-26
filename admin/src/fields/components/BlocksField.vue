<script setup lang="ts">
import { computed, provide, reactive, nextTick, ref } from 'vue'
import type { FieldDef } from '../types'
import { toFieldDef } from '../normalize'
import { useBlockTypes } from '@/queries/blockTypes'
import { MAX_BLOCK_DEPTH } from '@/queries/blockTypes'
import type { BlockType } from '@/queries/blockTypes'
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

// Container regions of a block type = its blocks-typed field names.
function regionsOf(slug: string): string[] {
  const type = bySlug.value.get(slug)
  if (!type) return []
  return type.schema.filter((f) => toFieldDef(f).type === 'blocks').map((f) => f.name)
}

const ops = createBlockListOps(regionsOf)
const expanded = reactive<Record<string, boolean>>({})

/**
 * Per-list picker rules (stage-toolbar spec §5): ONE resolver for the
 * inspector's insert menus, the prose `/` menu, AND the canvas add-after
 * picker, so they can never drift. Root list -> the entry field's allowlist;
 * nested region -> the containing block type's blocks-field allowlist for
 * that region.
 */
function pickerTypesForList(parentId: string | null, region: string | null): BlockType[] {
  if (listIsFull(parentId, region)) return [] // tabs cap: nothing new enters a full list
  let allowed = allowlist.value
  if (parentId !== null && region !== null) {
    const parent = ops.findById(model.value ?? [], parentId)
    const parentType = parent ? bySlug.value.get(parent.type) : undefined
    const regionField = parentType?.schema.find((f) => f.name === region)
    allowed = (regionField ? toFieldDef(regionField).blockTypes : undefined) ?? []
  }
  return (allTypes.value ?? []).filter(
    (t) => t.active && (allowed.length === 0 || allowed.includes(t.slug)),
  )
}

/**
 * Tabs authoring cap (theme-runtime spec §4): a full tabs items list accepts
 * nothing NEW — the guard gates net additions only (picker, insertAfter,
 * duplicate, cross-list moves); same-list rearrangement is never blocked. The
 * server enforces the same cap at save.
 */
const TABS_MAX_ITEMS = 12
function listIsFull(parentId: string | null, region: string | null): boolean {
  if (parentId === null || region !== 'items') return false
  const parent = ops.findById(model.value ?? [], parentId)
  if (!parent || parent.type !== 'tabs') return false
  return ((parent.data.items as BlockInstance[] | undefined) ?? []).length >= TABS_MAX_ITEMS
}

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
const dropRejected = ref<string | null>(null)
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
  // Tabs cap gates NET ADDITIONS only: a cross-list move adds an item to the
  // destination, so it checks fullness; a same-list reorder never does. Source
  // identity comes from the TREE (authoritative), not the event's from element.
  const source = dragId === '' ? null : ops.locateById(tree, dragId)
  const crossListFull =
    source !== null &&
    (source.parentId !== parentId || source.region !== region) &&
    listIsFull(parentId, region)
  if (dragId === '' || !ops.canDropAt(tree, dragId, { parentId, region }) || crossListFull) {
    dropRejected.value = crossListFull
      ? `Tabs supports at most ${TABS_MAX_ITEMS} items.`
      : `That drop would exceed the maximum nesting depth (${MAX_BLOCK_DEPTH}).`
    if (rejectTimer) clearTimeout(rejectTimer)
    rejectTimer = setTimeout(() => (dropRejected.value = null), 3000)
  } else {
    apply((t) => ops.moveAcross(t, dragId, { parentId, region, index }))
  }
  dragVersion.value++
}

const context: BlocksContext = {
  bySlug,
  pickerTypesForList,
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

// ── Canvas structural ops (stage-toolbar spec §4) ─────────────────────────────
// Same-list, id-addressed, all through the ops layer. Each returns exactly the
// payload the canvas needs to post the matching mirror — or null/false when the
// intent is a no-op, so NO mirror is ever posted for an uncommitted change.

/** Reorder within the block's own list. Returns the moved block's new neighbor. */
function moveBlock(id: string, delta: number): { beforeId: string } | { afterId: string } | null {
  const tree = model.value ?? []
  const loc = ops.locateById(tree, id)
  if (!loc) return null
  const to = loc.index + delta
  if (to < 0 || to >= loc.list.length) return null // boundary no-op — no mirror
  // Compute the next tree ONCE and locate within it — model.value does not
  // reflect the emission synchronously under a parent-controlled v-model.
  const nextTree = ops.moveById(tree, id, delta)
  apply(() => nextTree)
  const after = ops.locateById(nextTree, id)!
  const following = after.list[after.index + 1]
  // A committed move always has >= 1 neighbor (list length >= 2), so when
  // nothing follows, the preceding sibling exists.
  return following ? { beforeId: following.id } : { afterId: after.list[after.index - 1]!.id }
}

/**
 * Free-drag drop (free-drag spec §2): place `id` next to a SAME-LIST
 * reference. The bridge's geometry is a request — this method is the
 * authority: cross-list or unknown references are denied with NO mutation.
 * (Tabs cap needs no check here: same-list moves never change the net count,
 * and cross-list moves are already denied outright.)
 */
function moveBlockTo(
  id: string,
  neighbor: { beforeId: string } | { afterId: string },
): boolean {
  const tree = model.value ?? []
  const dragged = ops.locateById(tree, id)
  const refId = 'beforeId' in neighbor ? neighbor.beforeId : neighbor.afterId
  const ref = ops.locateById(tree, refId)
  if (!dragged || !ref) return false
  if (dragged.parentId !== ref.parentId || dragged.region !== ref.region) return false
  // Target index against the list WITHOUT the dragged block (moveAcross
  // removes before inserting).
  const without = dragged.list.filter((b) => b.id !== id)
  const refPos = without.findIndex((b) => b.id === refId)
  if (refPos < 0) return false
  const index = 'beforeId' in neighbor ? refPos : refPos + 1
  apply((t) =>
    ops.moveAcross(t, id, { parentId: dragged.parentId, region: dragged.region, index }),
  )
  return true
}

/** Duplicate in place. Returns the copy's id + the whole-subtree old->new id map. */
function duplicateBlock(id: string): { newId: string; idMap: Record<string, string> } | null {
  const tree = model.value ?? []
  const origin = ops.locateById(tree, id)
  if (!origin) return null
  // Tabs cap: the copy lands in the block's OWN list — a net addition.
  if (listIsFull(origin.parentId, origin.region)) return null
  const source = origin.list[origin.index]!
  const nextTree = ops.duplicateById(tree, id)
  apply(() => nextTree)
  const loc = ops.locateById(nextTree, id)!
  const copy = loc.list[loc.index + 1]!
  expanded[copy.id] = true
  return { newId: copy.id, idMap: ops.idMapBetween(source, copy) }
}

function deleteBlock(id: string): boolean {
  if (!ops.findById(model.value ?? [], id)) return false
  apply((t) => ops.removeById(t, id))
  return true
}

/** Insert a fresh empty block of `typeSlug` as the next sibling of `id`. */
function insertAfter(id: string, typeSlug: string): string | null {
  const loc = ops.locateById(model.value ?? [], id)
  if (!loc) return null
  // Tabs cap: inserting a sibling is a net addition to the containing list.
  if (listIsFull(loc.parentId, loc.region)) return null
  const block: BlockInstance = { id: newBlockId(), type: typeSlug, data: {} }
  apply((t) =>
    ops.insertAt(t, { parentId: loc.parentId, region: loc.region, index: loc.index + 1 }, block),
  )
  expanded[block.id] = true
  selectBlock(block.id)
  return block.id
}

/** Picker options for inserting NEXT TO `id` — the containing list's rules (§5). */
function pickerTypesFor(id: string): BlockType[] {
  const loc = ops.locateById(model.value ?? [], id)
  if (!loc) return []
  return pickerTypesForList(loc.parentId, loc.region)
}

/** Edit-in-place (spec §4): patch ONE data field of a block, id-addressed. */
function patchBlockData(id: string, fieldName: string, value: unknown): boolean {
  if (!ops.findById(model.value ?? [], id)) return false
  apply((t) => ops.patchDataById(t, id, fieldName, value))
  return true
}

/** The type slug of `id`, for the parent's prose-convention grant check. */
function blockTypeById(id: string): string | null {
  return ops.findById(model.value ?? [], id)?.type ?? null
}

// Exposed API: onDragEnd is the direct-handler testing seam (jsdom cannot
// simulate sortable); selectBlock/hasBlock let the visual canvas route a
// stage selection to this field; the structural methods are the canvas
// toolbar's single mutation path (stage-toolbar spec §4).
defineExpose({
  onDragEnd,
  selectBlock,
  hasBlock,
  moveBlock,
  moveBlockTo,
  duplicateBlock,
  deleteBlock,
  insertAfter,
  pickerTypesFor,
  patchBlockData,
  blockTypeById,
})

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
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
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
        {{ dropRejected }}
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
