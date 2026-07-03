<script setup lang="ts">
import { computed, inject } from 'vue'
import { BlocksContextKey } from './context'
import type { BlockInstance } from './useBlockListOps'

// The per-field outline (spec §4): a flat render of the nested tree — icon,
// label, summary, indented by depth. Click selects (expand ancestors + scroll +
// focus the header). NO drag, rename, or multi-select in v1 (recorded follow-up).
const props = defineProps<{ blocks: BlockInstance[] }>()

const ctx = inject(BlocksContextKey)!

interface OutlineRow {
  id: string
  depth: number
  label: string
  icon: string
  summary: string
}

function rowsOf(list: BlockInstance[], depth: number): OutlineRow[] {
  const rows: OutlineRow[] = []
  for (const block of list) {
    const type = ctx.bySlug.value.get(block.type)
    let summary = ''
    for (const f of type?.schema ?? []) {
      const v = block.data[f.name]
      if (typeof v === 'string' && v.trim() !== '') {
        summary = v.replace(/<[^>]*>/g, '').slice(0, 40)
        break
      }
    }
    rows.push({
      id: block.id,
      depth,
      label: type?.label ?? block.type,
      icon: type?.icon || 'i-lucide-box',
      summary,
    })
    for (const region of ctx.regionsOf(block.type)) {
      const inner = (block.data[region] as BlockInstance[] | undefined) ?? []
      rows.push(...rowsOf(inner, depth + 1))
    }
  }
  return rows
}

const rows = computed(() => rowsOf(props.blocks, 0))
</script>

<template>
  <div class="rounded-lg border border-default p-1" data-test="block-outline">
    <button
      v-for="row in rows"
      :key="row.id"
      class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-elevated"
      type="button"
      :style="{ paddingLeft: `${8 + row.depth * 16}px` }"
      :data-test="`block-outline-item-${row.id}`"
      @click="ctx.selectBlock(row.id)"
    >
      <UIcon :name="row.icon" class="size-3.5 shrink-0 text-muted" />
      <span class="font-medium">{{ row.label }}</span>
      <span class="truncate text-muted">{{ row.summary }}</span>
    </button>
    <p v-if="!rows.length" class="px-2 py-1 text-sm text-muted">No blocks yet.</p>
  </div>
</template>
