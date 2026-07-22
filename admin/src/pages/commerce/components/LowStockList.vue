<script setup lang="ts">
import { computed } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import type { StockReportItem } from '@/queries/commerceReports'

const props = defineProps<{
  rows: StockReportItem[]
  status: 'pending' | 'error' | 'success' | 'idle'
  /** The LIVE `useCommerceMeta().low_stock_threshold` — NOT each row's own `threshold` field.
   * See `commerceReports.ts`'s `StockReportItem` docblock: a row's own `threshold` reflects
   * whatever was resolved for the API call that produced it, which could go stale relative to the
   * meta value the rest of this page already renders against. Severity below is computed fresh
   * from `quantity` vs THIS prop on every render. */
  threshold: number
}>()

interface Severity {
  label: string
  color: 'error' | 'warning' | 'neutral'
}

// `quantity <= 0` is always "Out of stock" regardless of threshold. Otherwise "Low stock" at or
// below the live threshold — boundary INCLUSIVE, mirroring `StockReportRepository`'s own
// `quantity <= threshold` predicate. A row above the threshold (never actually returned by the
// real endpoint, which pre-filters server-side) falls back to a neutral "OK" rather than a false
// warning, so this stays correct even if a row's own stale `threshold` diverges from the live one.
function severity(row: StockReportItem): Severity {
  if (row.quantity <= 0) return { label: 'Out of stock', color: 'error' }
  if (row.quantity <= props.threshold) return { label: 'Low stock', color: 'warning' }
  return { label: 'OK', color: 'neutral' }
}

const columns = computed<TableColumn<StockReportItem>[]>(() => [
  { accessorKey: 'product_name', header: 'Product' },
  { accessorKey: 'quantity', header: 'Quantity' },
  { id: 'status', header: 'Status' },
])
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="low-stock-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn't load stock levels"
    description="Something went wrong loading the stock report. Try again."
    data-test="low-stock-error"
  />

  <UEmpty
    v-else-if="rows.length === 0"
    icon="i-lucide-package-check"
    title="Stock levels look healthy"
    description="Nothing is at or below the low-stock threshold right now."
    data-test="low-stock-empty"
  />

  <UTable v-else :data="rows" :columns="columns" :ui="{ td: 'align-middle' }">
    <template #product_name-cell="{ row }">
      <div data-test="low-stock-row" class="flex flex-col">
        <span class="font-medium text-default" data-test="low-stock-name">{{ row.original.product_name }}</span>
        <span class="text-xs text-muted">{{ row.original.sku }}</span>
      </div>
    </template>

    <template #quantity-cell="{ row }">
      <span data-test="low-stock-quantity">{{ row.original.quantity }}</span>
    </template>

    <template #status-cell="{ row }">
      <UBadge :color="severity(row.original).color" variant="subtle" size="sm" data-test="low-stock-badge">
        {{ severity(row.original).label }}
      </UBadge>
    </template>
  </UTable>
</template>
