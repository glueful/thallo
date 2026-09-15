<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import type { FieldDef } from '@/fields/types'
import { useBlockTypes } from '@/queries/blockTypes'
import { toFieldDef } from '@/fields/normalize'

// Entry-wide outline (visual builder spec §5.5): every blocks field's tree as one flat list of
// rows that each know their position — parent, slot, index — so a row dragged elsewhere resolves
// to a drop zone the coordinator judges (reorder and reparent with the same rules). An empty
// slot renders as a row of its own so a block can be dropped into it. Click selects both
// inspector-side and stage-side (the page wires the emit); M opens "Move to…".
interface BlockInstance {
  id: string
  type: string
  data: Record<string, unknown>
  settings?: Record<string, unknown>
}

export interface OutlineZone {
  parent: string | null
  slot: string | null
  index: number
}

interface OutlineRow {
  key: string
  kind: 'block' | 'slot'
  id: string | null
  depth: number
  label: string
  icon: string
  /** The position of this row's block, or the zone an empty slot row stands for. */
  zone: OutlineZone
}

const props = defineProps<{
  fields: Record<string, unknown>
  schema: FieldDef[]
  selected: string | null
  /** Every selected id (sibling multi-selection); `selected` is its anchor. */
  selectedIds?: string[]
}>()
const emit = defineEmits<{
  select: [id: string, modifiers: { shift: boolean; meta: boolean }]
  move: [id: string, delta: 1 | -1]
  deleteRequest: [id: string]
  duplicate: [id: string]
  deselect: []
  /** A row dragged to a new place: the block and the zone it now occupies. */
  drop: [id: string, zone: OutlineZone]
  /** Keyboard reparenting: open "Move to…" for the selected block. */
  moveTo: [id: string]
}>()

// Keyboard twin of the stage scheme (polish batch §4): acts on the SELECTED
// block regardless of which row has focus; rows keep native button semantics
// for Enter/Space (selection). No selection -> inert.
function onKeydown(e: KeyboardEvent): void {
  const id = props.selected
  if (!id) return
  if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
    e.preventDefault()
    emit('move', id, e.key === 'ArrowUp' ? -1 : 1)
    return
  }
  if (e.key === 'Backspace' || e.key === 'Delete') {
    e.preventDefault()
    emit('deleteRequest', id)
    return
  }
  if ((e.metaKey || e.ctrlKey) && (e.key === 'd' || e.key === 'D')) {
    e.preventDefault()
    emit('duplicate', id)
    return
  }
  if (e.key === 'm' || e.key === 'M') {
    e.preventDefault()
    emit('moveTo', id)
    return
  }
  if (e.key === 'Escape') {
    e.preventDefault()
    emit('deselect')
  }
}

const { data: allTypes } = useBlockTypes()
const bySlug = computed(() => new Map((allTypes.value ?? []).map((t) => [t.slug, t])))

function regionsOf(slug: string): string[] {
  const type = bySlug.value.get(slug)
  if (!type) return []
  return type.schema.filter((f) => toFieldDef(f).type === 'blocks').map((f) => f.name)
}

function rowsOf(
  list: BlockInstance[],
  depth: number,
  parent: string | null,
  slot: string | null,
): OutlineRow[] {
  const rows: OutlineRow[] = []
  list.forEach((block, index) => {
    const type = bySlug.value.get(block.type)
    rows.push({
      key: `block:${block.id}`,
      kind: 'block',
      id: block.id,
      depth,
      label: type?.label ?? block.type,
      icon: type?.icon || 'i-lucide-box',
      zone: { parent, slot, index },
    })
    for (const region of regionsOf(block.type)) {
      const inner = (block.data[region] as BlockInstance[] | undefined) ?? []
      if (inner.length === 0) {
        rows.push({
          key: `slot:${block.id}:${region}`,
          kind: 'slot',
          id: null,
          depth: depth + 1,
          label: `${region} (empty)`,
          icon: 'i-lucide-square-dashed',
          zone: { parent: block.id, slot: region, index: 0 },
        })
      } else {
        rows.push(...rowsOf(inner, depth + 1, block.id, region))
      }
    }
  })
  return rows
}

const groups = computed(() =>
  props.schema
    .filter((f) => f.type === 'blocks')
    .map((f) => ({
      field: f.name,
      rows: rowsOf(
        ((props.fields[f.name] as BlockInstance[] | undefined) ?? []) as BlockInstance[],
        0,
        null,
        f.name,
      ),
    })),
)

/** Local mirrors per group for the drag list; re-derived whenever the tree changes. */
const localRows = ref<Record<string, OutlineRow[]>>({})
watch(
  groups,
  (gs) => {
    const next: Record<string, OutlineRow[]> = {}
    for (const g of gs) next[g.field] = [...g.rows]
    localRows.value = next
  },
  { immediate: true, deep: true },
)

/**
 * A row dropped at a new place: the block goes where the row now sits — before the next row
 * (its zone), after the previous block row, or at the end of the field's root list.
 */
function onDragEnd(field: string, event: { item: HTMLElement; newIndex?: number }): void {
  const id = event.item.dataset.outlineId ?? ''
  const rows = localRows.value[field] ?? []
  const at = event.newIndex ?? rows.length - 1
  if (id === '') return
  const others = rows.filter((r) => r.id !== id)
  const next = others[at] ?? null
  // Landing after an empty slot row lands after the block that owns the slot: walk back to the
  // nearest block row.
  let p = Math.min(at, others.length) - 1
  while (p >= 0 && others[p]!.kind !== 'block') p--
  const prev = p >= 0 ? others[p]! : null
  let zone: OutlineZone
  if (next !== null) {
    zone = { ...next.zone }
  } else if (prev !== null) {
    zone = { ...prev.zone, index: prev.zone.index + 1 }
  } else {
    zone = { parent: null, slot: field, index: 0 }
  }
  emit('drop', id, zone)
}
defineExpose({ onDragEnd })
</script>

<template>
  <div class="space-y-3" data-test="canvas-outline" @keydown="onKeydown">
    <div v-for="group in groups" :key="group.field">
      <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-muted">
        {{ group.field }}
      </p>
      <VueDraggable
        v-if="localRows[group.field]"
        v-model="localRows[group.field]"
        :animation="150"
        handle="[data-outline-id]"
        filter="[data-outline-slot]"
        :data-test="`canvas-outline-list-${group.field}`"
        @end="
          (e) => onDragEnd(group.field, e as unknown as { item: HTMLElement; newIndex?: number })
        "
      >
        <template v-for="row in localRows[group.field]" :key="row.key">
          <button
            v-if="row.kind === 'block'"
            class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-elevated"
            :class="{ 'bg-elevated': row.id === selected || (selectedIds ?? []).includes(row.id!) }"
            type="button"
            :style="{ paddingLeft: `${8 + row.depth * 16}px` }"
            :data-outline-id="row.id"
            :data-test="`canvas-outline-item-${row.id}`"
            @click="
              (e: MouseEvent) =>
                emit('select', row.id!, { shift: e.shiftKey, meta: e.metaKey || e.ctrlKey })
            "
          >
            <UIcon :name="row.icon" class="size-3.5 shrink-0 text-muted" />
            <span class="truncate">{{ row.label }}</span>
          </button>
          <p
            v-else
            class="rounded border border-dashed border-default px-2 py-1 text-xs text-muted"
            :style="{ marginLeft: `${8 + row.depth * 16}px` }"
            data-outline-slot="1"
            :data-test="`canvas-outline-slot-${row.zone.parent}-${row.zone.slot}`"
          >
            {{ row.label }}
          </p>
        </template>
      </VueDraggable>
      <p v-if="!group.rows.length" class="px-2 py-1 text-xs text-muted">No blocks.</p>
    </div>
    <p v-if="!groups.length" class="px-2 py-1 text-sm text-muted">No blocks fields.</p>
  </div>
</template>
