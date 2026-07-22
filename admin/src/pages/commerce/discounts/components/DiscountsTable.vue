<script setup lang="ts">
import { computed } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { useMoney } from '@/composables/useMoney'
import type { CommerceDiscount } from '@/queries/commerceDiscounts'

const props = defineProps<{
  rows: CommerceDiscount[]
  status: 'pending' | 'error' | 'success' | 'idle'
  canManage: boolean
}>()

const emit = defineEmits<{
  'edit-request': [row: CommerceDiscount]
  'delete-request': [row: CommerceDiscount]
}>()

const { format } = useMoney()

const columns = computed<TableColumn<CommerceDiscount>[]>(() => [
  { accessorKey: 'code', header: 'Code' },
  { id: 'value', header: 'Type / value' },
  { id: 'usage', header: 'Usage' },
  { id: 'window', header: 'Active window' },
  { accessorKey: 'status', header: 'Status' },
  ...(props.canManage ? [{ id: 'actions', header: '' }] : []),
])

function statusColor(s: string): 'success' | 'neutral' {
  return s === 'active' ? 'success' : 'neutral'
}

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// never crashes the table render (mirrors OrdersTable.vue's identical `money()` helper).
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

/** `value` is bps of a percent for `percentage` discounts (see commerceDiscounts.ts's own
 * docblock: `value / 100` is the percent) — rendered without a currency conversion; `fixed`
 * discounts are a genuine minor-unit amount, formatted through `useMoney`. */
function valueText(d: CommerceDiscount): string {
  if (d.type === 'percentage') {
    const percent = d.value / 100
    return `${Number.isInteger(percent) ? percent : percent.toFixed(2)}%`
  }
  return money(d.value)
}

function usageText(d: CommerceDiscount): string {
  return `${d.usage_count}/${d.usage_limit ?? '∞'}`
}

function fmtDate(v: string | null): string | null {
  if (!v) return null
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? null : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}

function windowText(d: CommerceDiscount): string {
  const starts = fmtDate(d.starts_at)
  const ends = fmtDate(d.ends_at)
  if (!starts && !ends) return 'Always'
  if (starts && ends) return `${starts} – ${ends}`
  if (starts) return `From ${starts}`
  return `Until ${ends}`
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="discounts-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load discounts"
    description="Something went wrong loading discounts. Try again."
    data-test="discounts-error"
  />

  <UEmpty
    v-else-if="rows.length === 0"
    icon="i-lucide-ticket-percent"
    title="No discounts"
    description="Create your first discount code to start offering promotions."
    data-test="discounts-empty"
  />

  <UTable v-else :data="rows" :columns="columns" :ui="{ td: 'align-middle' }">
    <template #code-cell="{ row }">
      <span data-test="discount-row" :data-uuid="row.original.uuid" class="font-medium text-default">
        {{ row.original.code }}
      </span>
    </template>

    <template #value-cell="{ row }">
      <div class="flex items-center gap-2">
        <UBadge color="neutral" variant="subtle" size="sm">{{ row.original.type }}</UBadge>
        <span data-test="discount-value" class="text-sm">{{ valueText(row.original) }}</span>
      </div>
    </template>

    <template #usage-cell="{ row }">
      <span data-test="discount-usage" class="text-sm text-muted">{{ usageText(row.original) }}</span>
    </template>

    <template #window-cell="{ row }">
      <span data-test="discount-window" class="text-sm text-muted">{{ windowText(row.original) }}</span>
    </template>

    <template #status-cell="{ row }">
      <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm" data-test="discount-status">
        {{ row.original.status }}
      </UBadge>
    </template>

    <template v-if="canManage" #actions-cell="{ row }">
      <div class="flex justify-end gap-1">
        <UButton
          color="neutral"
          variant="ghost"
          size="xs"
          icon="i-lucide-pencil"
          aria-label="Edit discount"
          data-test="discount-edit"
          @click="emit('edit-request', row.original)"
        />
        <UButton
          color="error"
          variant="ghost"
          size="xs"
          icon="i-lucide-trash-2"
          aria-label="Delete discount"
          data-test="discount-delete"
          @click="emit('delete-request', row.original)"
        />
      </div>
    </template>
  </UTable>
</template>
