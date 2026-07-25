import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, toValue } from 'vue'
import { rangeFor } from '@/queries/analytics'
import type {
  SalesReport,
  CustomersReport,
  ProductsReportItem,
  ProductsReportPage,
  StockReportItem,
  StockReportPage,
} from '@/queries/commerceReports'
import type { CommerceCustomer, CustomerListPage } from '@/queries/commerceCustomers'

// ── Shared mock state (referenced inside vi.mock factories) ────────────────────────────────────
// Mirrors commerceCustomers.spec.ts's established pattern: real refs (not plain objects) so
// template-bound values get Vue's genuine ref auto-unwrap.

const metaData = ref({
  currency: 'USD',
  currency_exponent: 2,
  shop_index_url: '',
  low_stock_threshold: 3,
  can_view: true,
  can_manage: true,
})
vi.mock('@/queries/commerceMeta', () => ({
  useCommerceMeta: () => ({ data: metaData }),
}))

const salesData = ref<SalesReport | undefined>(undefined)
const salesStatus = ref<'pending' | 'error' | 'success'>('success')
const customersAggData = ref<CustomersReport | undefined>(undefined)
const customersAggStatus = ref<'pending' | 'error' | 'success'>('success')
const productsPage = ref<ProductsReportPage | undefined>(undefined)
const productsStatus = ref<'pending' | 'error' | 'success'>('success')
const stockPage = ref<StockReportPage | undefined>(undefined)
const stockStatus = ref<'pending' | 'error' | 'success'>('success')

const lastSalesFilters = vi.hoisted(() => ({ current: undefined as unknown }))
const lastCustomersAggFilters = vi.hoisted(() => ({ current: undefined as unknown }))
const lastProductsFilters = vi.hoisted(() => ({ current: undefined as unknown }))
const lastStockFilters = vi.hoisted(() => ({ current: undefined as unknown }))

vi.mock('@/queries/commerceReports', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceReports')>()
  return {
    ...actual,
    useCommerceReportSales: (filters: unknown) => {
      lastSalesFilters.current = filters
      return { data: salesData, status: salesStatus }
    },
    useCommerceReportCustomers: (filters: unknown) => {
      lastCustomersAggFilters.current = filters
      return { data: customersAggData, status: customersAggStatus }
    },
    useCommerceReportProducts: (filters: unknown) => {
      lastProductsFilters.current = filters
      return { data: productsPage, status: productsStatus }
    },
    useCommerceReportStock: (filters: unknown) => {
      lastStockFilters.current = filters
      return { data: stockPage, status: stockStatus }
    },
  }
})

const topCustomersPage = ref<CustomerListPage | undefined>(undefined)
const topCustomersStatus = ref<'pending' | 'error' | 'success'>('success')
const lastTopCustomersFilters = vi.hoisted(() => ({ current: undefined as unknown }))

vi.mock('@/queries/commerceCustomers', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceCustomers')>()
  return {
    ...actual,
    useCommerceCustomers: (filters: unknown) => {
      lastTopCustomersFilters.current = filters
      return { data: topCustomersPage, status: topCustomersStatus }
    },
  }
})

import SalesSummaryCards from '@/pages/commerce/components/SalesSummaryCards.vue'
import TopProductsTable from '@/pages/commerce/components/TopProductsTable.vue'
import LowStockList from '@/pages/commerce/components/LowStockList.vue'
import CommerceOverview from '@/pages/commerce/index.vue'

function salesReport(overrides: Partial<SalesReport> = {}): SalesReport {
  return {
    currency: 'USD',
    window: { from: '2026-06-24', to: '2026-07-22', group: 'day' },
    summary: {
      gross_minor: 100000,
      refunds_minor: 5000,
      net_minor: 95000,
      orders_count: 40,
      aov_minor: 2500,
      pending_orders: 3,
      discount_minor: 1200,
      shipping_minor: 800,
      tax_minor: 900,
    },
    series: [],
    ...overrides,
  }
}

function customersReport(overrides: Partial<CustomersReport> = {}): CustomersReport {
  return {
    window: { from: '2026-06-24', to: '2026-07-22', group: 'day' },
    summary: { new_customers: 12, returning_customers: 8, total_customers: 20 },
    series: [],
    ...overrides,
  }
}

function productItem(overrides: Partial<ProductsReportItem> = {}): ProductsReportItem {
  return {
    variant_uuid: 'var1',
    sku: 'SKU-1',
    product_name: 'Widget',
    quantity: 5,
    revenue_minor: 10000,
    attributed_refunded_minor: 500,
    attributed_refunded_quantity: 1,
    ...overrides,
  }
}

function stockItem(overrides: Partial<StockReportItem> = {}): StockReportItem {
  return {
    variant_uuid: 'var1',
    sku: 'SKU-1',
    product_name: 'Widget',
    quantity: 2,
    status: 'low_stock',
    threshold: 3,
    ...overrides,
  }
}

function customer(overrides: Partial<CommerceCustomer> = {}): CommerceCustomer {
  return {
    key_type: 'user',
    key: 'usercustu001',
    user_uuid: 'usercustu001',
    email: 'ada@example.com',
    orders_count: 2,
    total_spent_minor: 1500,
    refunded_minor: 0,
    first_order_at: '2026-01-01 00:00:00',
    last_order_at: '2026-01-05 00:00:00',
    username: null,
    ...overrides,
  }
}

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
const pageStubs = { RouterLink: RouterLinkStub }

beforeEach(() => {
  setActivePinia(createPinia())
  metaData.value = {
    currency: 'USD',
    currency_exponent: 2,
    shop_index_url: '',
    low_stock_threshold: 3,
    can_view: true,
    can_manage: true,
  }
  salesData.value = undefined
  salesStatus.value = 'success'
  customersAggData.value = undefined
  customersAggStatus.value = 'success'
  productsPage.value = { items: [], total: 0, current_page: 1, per_page: 10 }
  productsStatus.value = 'success'
  stockPage.value = { items: [], total: 0, current_page: 1, per_page: 10 }
  stockStatus.value = 'success'
  topCustomersPage.value = { customers: [], total: 0, current_page: 1, per_page: 10 }
  topCustomersStatus.value = 'success'
  lastSalesFilters.current = undefined
  lastCustomersAggFilters.current = undefined
  lastProductsFilters.current = undefined
  lastStockFilters.current = undefined
  lastTopCustomersFilters.current = undefined
})

// ── SalesSummaryCards: exact money, per-subsection loading/error ───────────────────────────────

describe('SalesSummaryCards', () => {
  it('renders exact money for gross/net/refunds/AOV and raw counts for orders/pending', () => {
    const wrapper = mount(SalesSummaryCards, {
      props: {
        sales: salesReport(),
        salesStatus: 'success',
        customers: customersReport(),
        customersStatus: 'success',
      },
    })

    expect(wrapper.find('[data-test="sales-gross"]').text()).toBe('$1,000.00')
    expect(wrapper.find('[data-test="sales-net"]').text()).toBe('$950.00')
    expect(wrapper.find('[data-test="sales-refunds"]').text()).toBe('$50.00')
    expect(wrapper.find('[data-test="sales-aov"]').text()).toBe('$25.00')
    expect(wrapper.find('[data-test="sales-orders"]').text()).toBe('40')
    expect(wrapper.find('[data-test="sales-pending"]').text()).toBe('3')
  })

  it('renders exact customer acquisition counts', () => {
    const wrapper = mount(SalesSummaryCards, {
      props: {
        sales: salesReport(),
        salesStatus: 'success',
        customers: customersReport(),
        customersStatus: 'success',
      },
    })

    expect(wrapper.find('[data-test="customers-new"]').text()).toBe('12')
    expect(wrapper.find('[data-test="customers-returning"]').text()).toBe('8')
    expect(wrapper.find('[data-test="customers-total"]').text()).toBe('20')
  })

  it('shows the sales loading state independent of the customers section', () => {
    const wrapper = mount(SalesSummaryCards, {
      props: { sales: undefined, salesStatus: 'pending', customers: customersReport(), customersStatus: 'success' },
    })
    expect(wrapper.find('[data-test="sales-summary-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="sales-summary-cards"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="customers-summary-cards"]').exists()).toBe(true)
  })

  it('shows the sales error state', () => {
    const wrapper = mount(SalesSummaryCards, {
      props: { sales: undefined, salesStatus: 'error', customers: customersReport(), customersStatus: 'success' },
    })
    expect(wrapper.find('[data-test="sales-summary-error"]').exists()).toBe(true)
  })

  it('shows the customers loading state independent of the sales section', () => {
    const wrapper = mount(SalesSummaryCards, {
      props: { sales: salesReport(), salesStatus: 'success', customers: undefined, customersStatus: 'pending' },
    })
    expect(wrapper.find('[data-test="customers-summary-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="sales-summary-cards"]').exists()).toBe(true)
  })

  it('shows the customers error state', () => {
    const wrapper = mount(SalesSummaryCards, {
      props: { sales: salesReport(), salesStatus: 'success', customers: undefined, customersStatus: 'error' },
    })
    expect(wrapper.find('[data-test="customers-summary-error"]').exists()).toBe(true)
  })
})

// ── TopProductsTable: exact money, loading/empty/error ──────────────────────────────────────────

describe('TopProductsTable', () => {
  it('renders one row per product with sku, quantity, and exact revenue/refunded money', () => {
    const wrapper = mount(TopProductsTable, {
      props: { rows: [productItem({ revenue_minor: 123456, attributed_refunded_minor: 100 })], status: 'success' },
    })

    expect(wrapper.findAll('[data-test="top-product-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="top-product-name"]').text()).toBe('Widget')
    expect(wrapper.find('[data-test="top-product-quantity"]').text()).toBe('5')
    expect(wrapper.find('[data-test="top-product-revenue"]').text()).toContain('$1,234.56')
    expect(wrapper.find('[data-test="top-product-refunded"]').text()).toContain('$1.00')
  })

  it('shows the loading state', () => {
    const wrapper = mount(TopProductsTable, { props: { rows: [], status: 'pending' } })
    expect(wrapper.find('[data-test="top-products-loading"]').exists()).toBe(true)
  })

  it('shows the empty state', () => {
    const wrapper = mount(TopProductsTable, { props: { rows: [], status: 'success' } })
    expect(wrapper.find('[data-test="top-products-empty"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    const wrapper = mount(TopProductsTable, { props: { rows: [], status: 'error' } })
    expect(wrapper.find('[data-test="top-products-error"]').exists()).toBe(true)
  })
})

// ── LowStockList: threshold boundary case, loading/empty/error ─────────────────────────────────

describe('LowStockList', () => {
  it('flags a row exactly AT the threshold as Low stock (inclusive boundary)', () => {
    const wrapper = mount(LowStockList, {
      props: { rows: [stockItem({ quantity: 3 })], status: 'success', threshold: 3 },
    })
    expect(wrapper.find('[data-test="low-stock-badge"]').text()).toBe('Low stock')
  })

  it('flags a row ABOVE the threshold as OK, never a false Low stock warning', () => {
    const wrapper = mount(LowStockList, {
      props: { rows: [stockItem({ quantity: 4 })], status: 'success', threshold: 3 },
    })
    expect(wrapper.find('[data-test="low-stock-badge"]').text()).toBe('OK')
  })

  it('flags a zero-quantity row as Out of stock regardless of the threshold', () => {
    const wrapper = mount(LowStockList, {
      props: { rows: [stockItem({ quantity: 0 })], status: 'success', threshold: 3 },
    })
    expect(wrapper.find('[data-test="low-stock-badge"]').text()).toBe('Out of stock')
  })

  it('renders product identity and quantity', () => {
    const wrapper = mount(LowStockList, {
      props: { rows: [stockItem({ product_name: 'Gadget', sku: 'SKU-9', quantity: 1 })], status: 'success', threshold: 3 },
    })
    expect(wrapper.find('[data-test="low-stock-name"]').text()).toBe('Gadget')
    expect(wrapper.find('[data-test="low-stock-quantity"]').text()).toBe('1')
  })

  it('shows the loading state', () => {
    const wrapper = mount(LowStockList, { props: { rows: [], status: 'pending', threshold: 3 } })
    expect(wrapper.find('[data-test="low-stock-loading"]').exists()).toBe(true)
  })

  it('shows the empty state', () => {
    const wrapper = mount(LowStockList, { props: { rows: [], status: 'success', threshold: 3 } })
    expect(wrapper.find('[data-test="low-stock-empty"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    const wrapper = mount(LowStockList, { props: { rows: [], status: 'error', threshold: 3 } })
    expect(wrapper.find('[data-test="low-stock-error"]').exists()).toBe(true)
  })
})

// ── Overview page: sections, period selector, wiring ────────────────────────────────────────────

describe('commerce overview page', () => {
  it('renders all four sections', async () => {
    const wrapper = mount(CommerceOverview, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="overview-sales-section"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="overview-products-section"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="overview-customers-section"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="overview-stock-section"]').exists()).toBe(true)
  })

  it('defaults the reporting period to the trailing 30 days', async () => {
    mount(CommerceOverview, { global: { stubs: pageStubs } })
    await flushPromises()

    const expected = rangeFor(30)
    const resolved = toValue(lastSalesFilters.current) as { from?: string; to?: string }
    expect(resolved.from).toBe(expected.from)
    expect(resolved.to).toBe(expected.to)
  })

  it('applies the selected period preset to sales, customer-acquisition, and top-products filters alike', async () => {
    const wrapper = mount(CommerceOverview, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="overview-range-7"]').trigger('click')
    await flushPromises()

    const expected = rangeFor(7)
    const sales = toValue(lastSalesFilters.current) as { from?: string; to?: string }
    const customersAgg = toValue(lastCustomersAggFilters.current) as { from?: string; to?: string }
    const products = toValue(lastProductsFilters.current) as { from?: string; to?: string; sort?: string }
    expect(sales.from).toBe(expected.from)
    expect(sales.to).toBe(expected.to)
    expect(customersAgg.from).toBe(expected.from)
    expect(customersAgg.to).toBe(expected.to)
    expect(products.from).toBe(expected.from)
    expect(products.to).toBe(expected.to)
    expect(products.sort).toBe('revenue')
  })

  it('does NOT window-scope top customers or stock — neither DTO has a from/to at all', async () => {
    const wrapper = mount(CommerceOverview, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="overview-range-90"]').trigger('click')
    await flushPromises()

    const topCustomers = toValue(lastTopCustomersFilters.current) as Record<string, unknown>
    const stock = toValue(lastStockFilters.current) as Record<string, unknown>
    expect(topCustomers).not.toHaveProperty('from')
    expect(topCustomers).not.toHaveProperty('to')
    expect(stock).not.toHaveProperty('from')
    expect(stock).not.toHaveProperty('to')
    expect(topCustomers.sort).toBe('total_spent')
    expect(topCustomers.direction).toBe('desc')
  })

  it('renders the top products table with the fetched rows', async () => {
    productsPage.value = { items: [productItem()], total: 1, current_page: 1, per_page: 10 }
    const wrapper = mount(CommerceOverview, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="top-product-row"]')).toHaveLength(1)
  })

  it('renders the top customers table (reusing the Customers admin surface) with the fetched rows', async () => {
    topCustomersPage.value = { customers: [customer()], total: 1, current_page: 1, per_page: 10 }
    const wrapper = mount(CommerceOverview, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="customer-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="customer-total"]').text()).toContain('$15.00')
  })

  it('renders the low-stock list with the fetched rows, flagged against the live threshold', async () => {
    stockPage.value = { items: [stockItem({ quantity: 3 })], total: 1, current_page: 1, per_page: 10 }
    metaData.value = { ...metaData.value, low_stock_threshold: 3 }
    const wrapper = mount(CommerceOverview, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="low-stock-badge"]').text()).toBe('Low stock')
  })
})

describe('commerce overview route gating', () => {
  it('the Overview route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(process.cwd(), 'src/pages/commerce/index.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })
})
