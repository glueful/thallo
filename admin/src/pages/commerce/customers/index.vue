<script setup lang="ts">
import { computed, ref } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useCommerceCustomers, type CommerceCustomer, type CommerceCustomerSort } from '@/queries/commerceCustomers'
import TablePagination from '@/components/TablePagination.vue'
import CustomersTable from './components/CustomersTable.vue'

// ── Filters ──────────────────────────────────────────────────────────────────
// `CustomerListQuery` exposes `sort` (`last_order_at | total_spent`) and `direction`
// (`asc | desc`) as two independent fields, but a single combo select reads better than two —
// each option below packs both into one `sort:direction` value, split back apart at the query
// boundary (mirrors the "All ..." sentinel pattern the other list pages use for their single
// status select). Email search mirrors the discounts list page's debounced UInput.
const search = ref('')
const debouncedSearch = refDebounced(search, 300)
const page = ref(1)
const perPage = ref(24)

const SORT_OPTIONS: Array<{ label: string; value: string }> = [
  { label: 'Most recent order', value: 'last_order_at:desc' },
  { label: 'Oldest order', value: 'last_order_at:asc' },
  { label: 'Highest spend', value: 'total_spent:desc' },
  { label: 'Lowest spend', value: 'total_spent:asc' },
]
const sortOption = ref<string>('last_order_at:desc')

const filters = computed(() => {
  const [sort, direction] = sortOption.value.split(':') as [CommerceCustomerSort, 'asc' | 'desc']
  return {
    email: debouncedSearch.value || undefined,
    sort,
    direction,
    page: page.value,
    perPage: perPage.value,
  }
})

const { data, status: queryStatus } = useCommerceCustomers(filters)
const rows = computed<CommerceCustomer[]>(() => data.value?.customers ?? [])
</script>

<template>
  <UDashboardPanel id="commerce-customers">
    <template #header>
      <UDashboardNavbar title="Customers">
        <template #right>
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Search by email…"
            class="w-56"
            data-test="customer-search"
          />
          <USelect
            v-model="sortOption"
            :items="SORT_OPTIONS"
            class="w-44"
            data-test="customer-sort"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <CustomersTable :rows="rows" :status="queryStatus" />

      <TablePagination
        v-if="(data?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="data?.total ?? 0"
        label="customers"
      />
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
