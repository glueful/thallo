<script setup lang="ts">
// The brand colours (custom palette spec §2.3, §4, §5.1): a list the author adds to, up to the
// deployment's limit, in the order every colour picker offers them. A saved colour is cleared
// through the page's dialog, which checks where it is used; a row not yet saved is simply removed.
// A colour being replaced shows the replacement's progress instead of its fields; one a running
// replacement writes to stays editable but cannot be cleared.
import { computed } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import type { PaletteJob } from '@/queries/palette'
import type { PaletteSlot } from '@/queries/styleSchema'
import { newRow, type BrandRow } from '../brandColors'
import HexInput from './HexInput.vue'

const props = defineProps<{
  rows: BrandRow[]
  /** The style schema's slots (`palette.slots`). */
  palette: Record<string, PaletteSlot>
  jobs: PaletteJob[]
  /** The deployment's limit. */
  limit: number
}>()
const emit = defineEmits<{ 'update:rows': [rows: BrandRow[]]; clear: [id: number] }>()

const NAME_MAX = 32
const full = computed(() => props.rows.length >= props.limit)
const unit = computed(() => (props.limit === 1 ? 'brand colour' : 'brand colours'))

function slotOf(row: BrandRow): PaletteSlot | undefined {
  return row.id === null ? undefined : props.palette[`brand-${row.id}`]
}
function nameOf(row: BrandRow): string {
  return slotOf(row)?.name || row.name || (row.id === null ? 'New colour' : `Brand ${row.id}`)
}
function set(row: BrandRow, part: 'name' | 'hex', value: string): void {
  emit(
    'update:rows',
    props.rows.map((r) => (r.key === row.key ? { ...r, [part]: value } : r)),
  )
}
function add(): void {
  if (!full.value) emit('update:rows', [...props.rows, newRow()])
}
function remove(row: BrandRow): void {
  emit(
    'update:rows',
    props.rows.filter((r) => r.key !== row.key),
  )
}
const replacing = (row: BrandRow) => slotOf(row)?.state === 'replacing'
function progress(row: BrandRow): string {
  const job = props.jobs.find((j) => j.slot === row.id)
  const to = slotOf(row)?.replacing?.to_label ?? 'its replacement'
  const counts = job ? ` — ${job.work_items_done} of ${job.work_items_total}` : ''
  const state = job?.status === 'failed' || job?.status === 'interrupted' ? ` (${job.status})` : ''
  return `Replacing ${nameOf(row)} with ${to}${counts}${state}`
}
/** The replacement writing to this colour, by the name of the colour it replaces. */
function reservedBy(row: BrandRow): string | null {
  if (!slotOf(row)?.reserved) return null
  const token = `color.brand-${row.id}`
  const job = props.jobs.find(
    (j) => j.to === token || j.contrast_to === `${token}-contrast` || j.contrast_to === token,
  )
  if (!job) return 'another'
  return props.palette[`brand-${job.slot}`]?.name ?? `Brand ${job.slot}`
}
</script>

<template>
  <div class="space-y-3" data-test="brand-colors">
    <p class="text-xs text-muted" data-test="brand-count">{{ rows.length }} of {{ limit }}</p>
    <VueDraggable
      :model-value="rows"
      handle="[data-drag]"
      :animation="150"
      class="space-y-3"
      @update:model-value="(next: BrandRow[]) => emit('update:rows', next)"
    >
      <div
        v-for="row in rows"
        :key="row.key"
        class="space-y-2"
        :data-test="`brand-row-${row.id ?? row.key}`"
      >
        <p
          v-if="replacing(row)"
          class="text-sm text-muted"
          :data-test="`brand-row-${row.id}-progress`"
        >
          {{ progress(row) }}
        </p>
        <div v-else class="flex items-start gap-2">
          <button
            type="button"
            data-drag
            class="mt-2 cursor-grab text-dimmed hover:text-default"
            :aria-label="`Move ${nameOf(row)}`"
          >
            <UIcon name="i-lucide-grip-vertical" class="size-4" />
          </button>
          <UInput
            :model-value="row.name"
            :maxlength="NAME_MAX"
            placeholder="Name"
            class="w-40 shrink-0"
            :aria-label="`${nameOf(row)} name`"
            :data-test="`brand-row-${row.id ?? row.key}-name`"
            @update:model-value="(v: string | number) => set(row, 'name', String(v))"
          />
          <HexInput
            class="min-w-0 flex-1"
            :model-value="row.hex"
            :label="nameOf(row)"
            :data-test="`brand-row-${row.id ?? row.key}-hex`"
            @update:model-value="(v: string) => set(row, 'hex', v)"
          />
          <UButton
            v-if="row.id === null"
            color="neutral"
            variant="ghost"
            icon="i-lucide-trash-2"
            :aria-label="`Remove ${nameOf(row)}`"
            data-test="brand-row-remove"
            @click="remove(row)"
          />
          <UButton
            v-else-if="!reservedBy(row)"
            color="neutral"
            variant="ghost"
            icon="i-lucide-x"
            :aria-label="`Clear ${nameOf(row)}`"
            data-test="brand-row-clear"
            @click="emit('clear', row.id)"
          >
            Clear
          </UButton>
        </div>
        <p
          v-if="reservedBy(row)"
          class="text-xs text-muted"
          :data-test="`brand-row-${row.id}-reserved`"
        >
          Reserved by the {{ reservedBy(row) }} replacement
        </p>
      </div>
    </VueDraggable>
    <div class="flex items-center gap-3">
      <UButton
        icon="i-lucide-plus"
        variant="outline"
        color="neutral"
        size="sm"
        :disabled="full"
        data-test="brand-add"
        @click="add"
      >
        Add colour
      </UButton>
      <p v-if="full" class="text-xs text-muted" data-test="brand-limit">
        This site allows {{ limit }} {{ unit }}
      </p>
    </div>
  </div>
</template>
