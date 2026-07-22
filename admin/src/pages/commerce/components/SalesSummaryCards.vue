<script setup lang="ts">
import { useMoney } from '@/composables/useMoney'
import type { SalesReport, CustomersReport } from '@/queries/commerceReports'

const props = defineProps<{
  sales: SalesReport | undefined
  salesStatus: 'pending' | 'error' | 'success' | 'idle'
  customers: CustomersReport | undefined
  customersStatus: 'pending' | 'error' | 'success' | 'idle'
}>()

const { format } = useMoney()

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// (still pending on first paint) never crashes the tile render (mirrors CustomersTable.vue).
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}
</script>

<template>
  <div class="space-y-4">
    <!-- Sales tiles -->
    <div v-if="props.salesStatus === 'pending'" class="grid grid-cols-2 gap-3 lg:grid-cols-3" data-test="sales-summary-loading">
      <USkeleton v-for="n in 6" :key="n" class="h-20" />
    </div>

    <UAlert
      v-else-if="props.salesStatus === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn't load the sales summary"
      description="Something went wrong loading the sales report. Try again."
      data-test="sales-summary-error"
    />

    <div v-else class="grid grid-cols-2 gap-3 lg:grid-cols-3" data-test="sales-summary-cards">
      <div class="rounded-lg border border-default p-4">
        <div class="text-xs text-muted">Gross revenue</div>
        <div class="text-xl font-semibold text-highlighted" data-test="sales-gross">
          {{ money(props.sales?.summary.gross_minor ?? 0) }}
        </div>
      </div>
      <div class="rounded-lg border border-default p-4">
        <div class="text-xs text-muted">Net revenue</div>
        <div class="text-xl font-semibold text-highlighted" data-test="sales-net">
          {{ money(props.sales?.summary.net_minor ?? 0) }}
        </div>
      </div>
      <div class="rounded-lg border border-default p-4">
        <div class="text-xs text-muted">Refunds</div>
        <div class="text-xl font-semibold text-highlighted" data-test="sales-refunds">
          {{ money(props.sales?.summary.refunds_minor ?? 0) }}
        </div>
      </div>
      <div class="rounded-lg border border-default p-4">
        <div class="text-xs text-muted">Orders</div>
        <div class="text-xl font-semibold text-highlighted" data-test="sales-orders">
          {{ props.sales?.summary.orders_count ?? 0 }}
        </div>
      </div>
      <div class="rounded-lg border border-default p-4">
        <div class="text-xs text-muted">Average order value</div>
        <div class="text-xl font-semibold text-highlighted" data-test="sales-aov">
          {{ money(props.sales?.summary.aov_minor ?? 0) }}
        </div>
      </div>
      <div class="rounded-lg border border-default p-4">
        <div class="text-xs text-muted">Pending orders</div>
        <div class="text-xl font-semibold text-highlighted" data-test="sales-pending">
          {{ props.sales?.summary.pending_orders ?? 0 }}
        </div>
      </div>
    </div>

    <!-- Customer acquisition tiles -->
    <div v-if="props.customersStatus === 'pending'" class="grid grid-cols-3 gap-3" data-test="customers-summary-loading">
      <USkeleton v-for="n in 3" :key="n" class="h-20" />
    </div>

    <UAlert
      v-else-if="props.customersStatus === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn't load customer acquisition"
      description="Something went wrong loading the customer report. Try again."
      data-test="customers-summary-error"
    />

    <div v-else class="grid grid-cols-3 gap-3" data-test="customers-summary-cards">
      <div class="rounded-lg border border-default p-4">
        <div class="text-xs text-muted">New customers</div>
        <div class="text-xl font-semibold text-highlighted" data-test="customers-new">
          {{ props.customers?.summary.new_customers ?? 0 }}
        </div>
      </div>
      <div class="rounded-lg border border-default p-4">
        <div class="text-xs text-muted">Returning customers</div>
        <div class="text-xl font-semibold text-highlighted" data-test="customers-returning">
          {{ props.customers?.summary.returning_customers ?? 0 }}
        </div>
      </div>
      <div class="rounded-lg border border-default p-4">
        <div class="text-xs text-muted">Total customers</div>
        <div class="text-xl font-semibold text-highlighted" data-test="customers-total">
          {{ props.customers?.summary.total_customers ?? 0 }}
        </div>
      </div>
    </div>
  </div>
</template>
