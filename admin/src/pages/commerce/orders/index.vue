<script setup lang="ts">
import { computed, ref } from 'vue'
import { useCommerceOrders, ORDER_STATUSES, type CommerceOrder } from '@/queries/commerceOrders'
import TablePagination from '@/components/TablePagination.vue'
import OrdersTable from './components/OrdersTable.vue'

// ── Filters ──────────────────────────────────────────────────────────────────
// USelect/reka-ui reserve the empty string as "no selection" and reject a SelectItem with an
// empty `value` — so the "All" option uses a non-empty sentinel, translated to `undefined` (no
// filter) at the query boundary (mirrors the products list page).
const ALL = 'all'
const statusFilter = ref(ALL)
const page = ref(1)
const perPage = ref(24)

const statusFilterItems = [
  { label: 'All statuses', value: ALL },
  ...ORDER_STATUSES.map((s) => ({ label: s, value: s })),
]

const filters = computed(() => ({
  status: statusFilter.value === ALL ? undefined : statusFilter.value,
  page: page.value,
  perPage: perPage.value,
}))

const { data, status: queryStatus } = useCommerceOrders(filters)
const rows = computed<CommerceOrder[]>(() => data.value?.orders ?? [])
</script>

<template>
  <UDashboardPanel id="commerce-orders">
    <template #header>
      <UDashboardNavbar title="Orders">
        <template #right>
          <USelect
            v-model="statusFilter"
            :items="statusFilterItems"
            class="w-44"
            data-test="order-status-filter"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <OrdersTable :rows="rows" :status="queryStatus" />

      <TablePagination
        v-if="(data?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="data?.total ?? 0"
        label="orders"
      />
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
