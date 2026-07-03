<script setup lang="ts">
import { computed } from 'vue'
import type { FieldDef } from '@/fields/types'
import { useBlockTypes } from '@/queries/blockTypes'
import { toFieldDef } from '@/fields/normalize'

// Entry-wide outline (visual-canvas spec §5): every blocks field's tree, grouped
// under field-name headings. Click selects both inspector-side and stage-side
// (the page wires the emit). Read-only — no drag/rename in v1.
interface BlockInstance {
  id: string
  type: string
  data: Record<string, unknown>
}

const props = defineProps<{
  fields: Record<string, unknown>
  schema: FieldDef[]
  selected: string | null
}>()

const emit = defineEmits<{ select: [id: string] }>()

const { data: allTypes } = useBlockTypes()
const bySlug = computed(() => new Map((allTypes.value ?? []).map((t) => [t.slug, t])))

function regionsOf(slug: string): string[] {
  const type = bySlug.value.get(slug)
  if (!type) return []
  return type.schema.filter((f) => toFieldDef(f).type === 'blocks').map((f) => f.name)
}

interface OutlineRow {
  id: string
  depth: number
  label: string
  icon: string
}

function rowsOf(list: BlockInstance[], depth: number): OutlineRow[] {
  const rows: OutlineRow[] = []
  for (const block of list) {
    const type = bySlug.value.get(block.type)
    rows.push({
      id: block.id,
      depth,
      label: type?.label ?? block.type,
      icon: type?.icon || 'i-lucide-box',
    })
    for (const region of regionsOf(block.type)) {
      const inner = (block.data[region] as BlockInstance[] | undefined) ?? []
      rows.push(...rowsOf(inner, depth + 1))
    }
  }
  return rows
}

const groups = computed(() =>
  props.schema
    .filter((f) => f.type === 'blocks')
    .map((f) => ({
      field: f.name,
      rows: rowsOf(((props.fields[f.name] as BlockInstance[] | undefined) ?? []) as BlockInstance[], 0),
    })),
)
</script>

<template>
  <div class="space-y-3" data-test="canvas-outline">
    <div v-for="group in groups" :key="group.field">
      <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-muted">
        {{ group.field }}
      </p>
      <button
        v-for="row in group.rows"
        :key="row.id"
        class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-elevated"
        :class="{ 'bg-elevated': row.id === selected }"
        type="button"
        :style="{ paddingLeft: `${8 + row.depth * 16}px` }"
        :data-test="`canvas-outline-item-${row.id}`"
        @click="emit('select', row.id)"
      >
        <UIcon :name="row.icon" class="size-3.5 shrink-0 text-muted" />
        <span class="truncate">{{ row.label }}</span>
      </button>
      <p v-if="!group.rows.length" class="px-2 py-1 text-xs text-muted">No blocks.</p>
    </div>
    <p v-if="!groups.length" class="px-2 py-1 text-sm text-muted">No blocks fields.</p>
  </div>
</template>
