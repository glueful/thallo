<script setup lang="ts">
import { computed } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { useMoney } from '@/composables/useMoney'
import type { ProductsReportItem } from '@/queries/commerceReports'

const props = defineProps<{
  rows: ProductsReportItem[]
  status: 'pending' | 'error' | 'success' | 'idle'
}>()

const { format } = useMoney()

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// (still pending on first paint) never crashes the table render (mirrors CustomersTable.vue).
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

const columns = computed<TableColumn<ProductsReportItem>[]>(() => [
  { accessorKey: 'product_name', header: 'Product' },
  { accessorKey: 'quantity', header: 'Units sold' },
  { accessorKey: 'revenue_minor', header: 'Revenue' },
  { accessorKey: 'attributed_refunded_minor', header: 'Refunded' },
])
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="top-products-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn't load top products"
    description="Something went wrong loading the products report. Try again."
    data-test="top-products-error"
  />

  <UEmpty
    v-else-if="rows.length === 0"
    icon="i-lucide-package"
    title="No product sales"
    description="Product sales appear here once orders start coming in."
    data-test="top-products-empty"
  />

  <UTable v-else :data="rows" :columns="columns" :ui="{ td: 'align-middle' }">
    <template #product_name-cell="{ row }">
      <div data-test="top-product-row" class="flex flex-col">
        <span class="font-medium text-default" data-test="top-product-name">{{ row.original.product_name }}</span>
        <span class="text-xs text-muted">{{ row.original.sku }}</span>
      </div>
    </template>

    <template #quantity-cell="{ row }">
      <span data-test="top-product-quantity">{{ row.original.quantity }}</span>
    </template>

    <template #revenue_minor-cell="{ row }">
      <span data-test="top-product-revenue">{{ money(row.original.revenue_minor) }}</span>
    </template>

    <template #attributed_refunded_minor-cell="{ row }">
      <span class="text-sm text-muted" data-test="top-product-refunded">
        {{ money(row.original.attributed_refunded_minor) }}
      </span>
    </template>
  </UTable>
</template>
