<script setup lang="ts">
import { computed, ref } from 'vue'
import { rangeFor, type RangePreset } from '@/queries/analytics'
import {
  useCommerceReportSales,
  useCommerceReportProducts,
  useCommerceReportCustomers,
  useCommerceReportStock,
  type ProductsReportItem,
  type StockReportItem,
} from '@/queries/commerceReports'
import { useCommerceCustomers, type CommerceCustomer } from '@/queries/commerceCustomers'
import { useCommerceMeta } from '@/queries/commerceMeta'
import CustomersTable from '@/pages/commerce/customers/components/CustomersTable.vue'
import SalesSummaryCards from './components/SalesSummaryCards.vue'
import TopProductsTable from './components/TopProductsTable.vue'
import LowStockList from './components/LowStockList.vue'

// Task 18 (admin-commerce-area plan, slice 3, completes phase P6): the Commerce landing page —
// `AdminReportController`'s four report endpoints (sales/products/customers/stock), plus Task 17's
// customer list (sorted by spend, descending) for "top customers by money" — `reports/customers`
// itself carries no money or per-customer identity at all, only new-vs-returning COUNTS (see
// `commerceReports.ts`'s file docblock for the full reasoning). Charts are intentionally omitted
// (YAGNI, per the task brief's own note) — stat tiles + tables surface the same information at a
// glance without the @unovis jsdom overhead. Read-only surface: no mutation endpoint exists on any
// of these, so — like Customers — there is no `can_manage` gating anywhere here either.

const PRESETS: RangePreset[] = [7, 30, 90]
const preset = ref<RangePreset>(30)
const range = computed(() => rangeFor(preset.value))

// Void-returning click handler — an inline `@click="preset = p"` compiles to a handler that
// RETURNS the assigned value, tripping Nuxt UI's `onClick: (e) => void` type (TS2322, mirrors
// analytics/index.vue's identical `setPreset`).
function setPreset(p: RangePreset): void {
  preset.value = p
}

// Governs sales + customer-acquisition + top-products — the only three of the five queries below
// whose DTO actually accepts a `from`/`to` window (`ReportWindowQuery`/`ProductsReportQuery`).
const windowFilters = computed(() => ({ from: range.value.from, to: range.value.to }))

const { data: sales, status: salesStatus } = useCommerceReportSales(windowFilters)
const { data: customersAgg, status: customersAggStatus } = useCommerceReportCustomers(windowFilters)

const productsFilters = computed(() => ({
  from: range.value.from,
  to: range.value.to,
  sort: 'revenue' as const,
  page: 1,
  perPage: 10,
}))
const { data: productsPage, status: productsStatus } = useCommerceReportProducts(productsFilters)
const topProducts = computed<ProductsReportItem[]>(() => productsPage.value?.items ?? [])

// All-time, NOT window-scoped — `CustomerListQuery` has no `from`/`to` at all (commerceCustomers.ts).
const topCustomersFilters = computed(() => ({
  sort: 'total_spent' as const,
  direction: 'desc' as const,
  page: 1,
  perPage: 10,
}))
const { data: topCustomersPage, status: topCustomersStatus } = useCommerceCustomers(topCustomersFilters)
const topCustomers = computed<CommerceCustomer[]>(() => topCustomersPage.value?.customers ?? [])

// Point-in-time — `StockReportQuery` has no `from`/`to` either.
const stockFilters = computed(() => ({ page: 1, perPage: 10 }))
const { data: stockPage, status: stockStatus } = useCommerceReportStock(stockFilters)
const lowStockRows = computed<StockReportItem[]>(() => stockPage.value?.items ?? [])

const { data: meta } = useCommerceMeta()
const lowStockThreshold = computed(() => meta.value?.low_stock_threshold ?? 0)
</script>

<template>
  <UDashboardPanel id="commerce-overview">
    <template #header>
      <UDashboardNavbar title="Overview">
        <template #right>
          <div class="flex gap-1" role="group" aria-label="Reporting period">
            <UButton
              v-for="p in PRESETS"
              :key="p"
              :data-test="`overview-range-${p}`"
              size="xs"
              :variant="preset === p ? 'solid' : 'ghost'"
              :color="preset === p ? 'primary' : 'neutral'"
              @click="setPreset(p)"
            >
              {{ p }}d
            </UButton>
          </div>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-6 p-4">
        <section data-test="overview-sales-section">
          <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted">Sales summary</h2>
          <SalesSummaryCards
            :sales="sales"
            :sales-status="salesStatus"
            :customers="customersAgg"
            :customers-status="customersAggStatus"
          />
        </section>

        <section data-test="overview-products-section">
          <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted">Top products</h2>
          <TopProductsTable :rows="topProducts" :status="productsStatus" />
        </section>

        <section data-test="overview-customers-section">
          <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted">Top customers</h2>
          <CustomersTable :rows="topCustomers" :status="topCustomersStatus" />
        </section>

        <section data-test="overview-stock-section">
          <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted">Low stock</h2>
          <LowStockList :rows="lowStockRows" :status="stockStatus" :threshold="lowStockThreshold" />
        </section>
      </div>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
