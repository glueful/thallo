import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, toValue } from 'vue'
import type { CommerceOrder, OrderListPage } from '@/queries/commerceOrders'

// ── Shared mock state (referenced inside vi.mock factories) ────────────────────────────────────
// Mirrors commerceProducts.spec.ts's established pattern: real refs (not vi.hoisted()) so they
// exist before the file's own `import { ref } from 'vue'` binding is live is unnecessary here
// (vi.mock factories run lazily), but keeping the same shape as the products suite for consistency.

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

const routeState = vi.hoisted(() => ({
  params: {} as Record<string, string>,
  query: {} as Record<string, string>,
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
}))
// Nuxt UI's Link (behind UButton's `to` prop and <RouterLink>) resolves useRoute/useRouter from
// vue-router/auto — mirrors commerceProducts.spec.ts's established pattern.
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
}))

const ordersPage = ref<OrderListPage | undefined>(undefined)
const ordersStatus = ref<'pending' | 'error' | 'success'>('success')
const singleOrder = ref<CommerceOrder | undefined>(undefined)
const singleStatus = ref<'pending' | 'error' | 'success'>('success')
const lastOrdersFilters = vi.hoisted(() => ({ current: undefined as unknown }))

vi.mock('@/queries/commerceOrders', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceOrders')>()
  return {
    ...actual,
    useCommerceOrders: (filters: unknown) => {
      lastOrdersFilters.current = filters
      return { data: ordersPage, status: ordersStatus }
    },
    useCommerceOrder: () => ({ data: singleOrder, status: singleStatus }),
  }
})

import OrdersTable from '@/pages/commerce/orders/components/OrdersTable.vue'
import OrdersIndex from '@/pages/commerce/orders/index.vue'
import OrderDetail from '@/pages/commerce/orders/[uuid]/index.vue'

function order(overrides: Partial<CommerceOrder> = {}): CommerceOrder {
  return {
    uuid: 'o1',
    order_number: 'ORD-1001',
    status: 'paid',
    fulfillment_status: 'unfulfilled',
    email: 'buyer@example.com',
    user_uuid: null,
    currency: 'USD',
    subtotal: 5000,
    discount_total: 0,
    shipping_total: 500,
    tax_total: 400,
    grand_total: 5900,
    refunded_total: 0,
    discount_code: null,
    shipping_method: 'standard',
    addresses: null,
    placed_at: '2026-01-01 00:00:00',
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    lines: [],
    events: [],
    ...overrides,
  }
}

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
const pageStubs = { RouterLink: RouterLinkStub }

/** Find the Reka SelectRoot ancestor of a USelect carrying `dataTest`, and drive it directly —
 * USelect's options render in a portal, so opening the dropdown in jsdom is unreliable; emitting
 * `update:modelValue` on the underlying SelectRoot is the established pattern
 * (commerceProducts.spec.ts). */
function selectByTestId(wrapper: ReturnType<typeof mount>, dataTest: string) {
  const root = wrapper
    .findAllComponents({ name: 'SelectRoot' })
    .find((r) => r.element.querySelector?.(`[data-test="${dataTest}"]`))
  if (!root) throw new Error(`No SelectRoot found for [data-test="${dataTest}"]`)
  return root
}

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
  routeState.params = {}
  routeState.query = {}
  ordersPage.value = { orders: [], total: 0, current_page: 1, per_page: 24 }
  ordersStatus.value = 'success'
  singleOrder.value = undefined
  singleStatus.value = 'success'
  lastOrdersFilters.current = undefined
})

// ── OrdersTable: rows (number, customer, status badge, total, date), loading/empty/error ───────

describe('OrdersTable', () => {
  const rows = [
    order({ uuid: 'o1', order_number: 'ORD-1001', email: 'ada@example.com', status: 'paid', grand_total: 5900 }),
    order({ uuid: 'o2', order_number: 'ORD-1002', email: 'grace@example.com', status: 'fulfilled', grand_total: 12000 }),
  ]

  it('renders one row per order with number, customer, status, exact total, and date', () => {
    const wrapper = mount(OrdersTable, {
      props: { rows, status: 'success' },
      global: { stubs: pageStubs },
    })

    expect(wrapper.findAll('[data-test="order-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('ORD-1001')
    expect(wrapper.text()).toContain('ada@example.com')
    expect(wrapper.findAll('[data-test="order-status"]')[0]!.text()).toBe('paid')
    expect(wrapper.findAll('[data-test="order-total"]')[0]!.text()).toContain('$59.00')
    expect(wrapper.findAll('[data-test="order-status"]')[1]!.text()).toBe('fulfilled')
  })

  it('shows the loading state', () => {
    const wrapper = mount(OrdersTable, { props: { rows: [], status: 'pending' } })
    expect(wrapper.find('[data-test="orders-loading"]').exists()).toBe(true)
  })

  it('shows the empty state', () => {
    const wrapper = mount(OrdersTable, { props: { rows: [], status: 'success' } })
    expect(wrapper.find('[data-test="orders-empty"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    const wrapper = mount(OrdersTable, { props: { rows: [], status: 'error' } })
    expect(wrapper.find('[data-test="orders-error"]').exists()).toBe(true)
  })

  it('links each row to its order detail page', () => {
    const wrapper = mount(OrdersTable, {
      props: { rows, status: 'success' },
      global: { stubs: pageStubs },
    })
    const links = wrapper.findAll('[data-test="order-row"]')
    expect(links[0]!.attributes('href')).toBe('/commerce/orders/o1')
    expect(links[1]!.attributes('href')).toBe('/commerce/orders/o2')
  })
})

// ── Orders list page: status filter, pagination ─────────────────────────────────────────────

describe('commerce orders list page', () => {
  it('sends no status filter by default (the ALL sentinel translates to undefined)', async () => {
    mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    const resolved = toValue(lastOrdersFilters.current) as { status?: string; page?: number; perPage?: number }
    expect(resolved.status).toBeUndefined()
    expect(resolved.page).toBe(1)
    expect(resolved.perPage).toBe(24)
  })

  it('applies the selected status as an exact filter', async () => {
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    selectByTestId(wrapper, 'order-status-filter').vm.$emit('update:modelValue', 'fulfilled')
    await flushPromises()

    const resolved = toValue(lastOrdersFilters.current) as { status?: string }
    expect(resolved.status).toBe('fulfilled')
  })

  it('renders the orders table with the fetched rows', async () => {
    ordersPage.value = { orders: [order({ uuid: 'o1' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="order-row"]')).toHaveLength(1)
  })

  it('shows pagination controls only once there is at least one order', async () => {
    ordersPage.value = { orders: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.text()).not.toContain('Rows per page')

    ordersPage.value = { orders: [order()], total: 1, current_page: 1, per_page: 24 }
    await flushPromises()
    expect(wrapper.text()).toContain('Rows per page')
  })
})

// ── Order detail page: line items, totals, customer, addresses, status timeline ─────────────

describe('commerce order detail page', () => {
  beforeEach(() => {
    routeState.params = { uuid: 'o1' }
  })

  it('shows the loading state', () => {
    singleStatus.value = 'pending'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    expect(wrapper.find('[data-test="order-detail-loading"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    singleStatus.value = 'error'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    expect(wrapper.find('[data-test="order-detail-error"]').exists()).toBe(true)
  })

  it('renders the order number, status, and line items with exact money', async () => {
    singleOrder.value = order({
      order_number: 'ORD-2002',
      status: 'paid',
      lines: [
        {
          uuid: 'l1',
          product_name: 'Widget',
          sku: 'SKU-1',
          quantity: 2,
          unit_price: 61728,
          line_total: 123456,
          option_values: {},
          addons: [],
        },
      ],
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('ORD-2002')
    expect(wrapper.find('[data-test="order-detail-status"]').text()).toBe('paid')
    expect(wrapper.findAll('[data-test="order-line-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Widget')
    expect(wrapper.text()).toContain('SKU-1')
    expect(wrapper.text()).toContain('$617.28')
    expect(wrapper.text()).toContain('$1,234.56')
  })

  it('renders exact totals for every line via useMoney', async () => {
    singleOrder.value = order({
      subtotal: 100000,
      discount_total: 2000,
      shipping_total: 500,
      tax_total: 800,
      refunded_total: 100,
      grand_total: 99400,
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-total-subtotal"]').text()).toContain('$1,000.00')
    expect(wrapper.find('[data-test="order-total-discount"]').text()).toContain('$20.00')
    expect(wrapper.find('[data-test="order-total-shipping"]').text()).toContain('$5.00')
    expect(wrapper.find('[data-test="order-total-tax"]').text()).toContain('$8.00')
    expect(wrapper.find('[data-test="order-total-refunded"]').text()).toContain('$1.00')
    expect(wrapper.find('[data-test="order-total-grand"]').text()).toContain('$994.00')
  })

  it('shows the customer email and a guest indicator when there is no linked account', async () => {
    singleOrder.value = order({ email: 'guest@example.com', user_uuid: null })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-customer-email"]').text()).toContain('guest@example.com')
    expect(wrapper.find('[data-test="order-customer-type"]').text()).toContain('Guest')
  })

  it('shows a registered-customer indicator when the order has a linked user', async () => {
    singleOrder.value = order({ email: 'ada@example.com', user_uuid: 'u1' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-customer-type"]').text()).toContain('Registered')
  })

  it('shows the "no address on file" state when addresses is null', async () => {
    singleOrder.value = order({ addresses: null })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-addresses-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-address-shipping"]').exists()).toBe(false)
  })

  it('renders the shipping and billing addresses, resolving whichever field aliases are present', async () => {
    singleOrder.value = order({
      addresses: {
        shipping: { first_name: 'Ada', last_name: 'Lovelace', address1: '1 Main St', city: 'Springfield' },
        billing: { name: 'Ada Lovelace', line1: '2 Other St', postal_code: '90210' },
      },
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const shipping = wrapper.find('[data-test="order-address-shipping"]')
    expect(shipping.exists()).toBe(true)
    expect(shipping.text()).toContain('Ada Lovelace')
    expect(shipping.text()).toContain('1 Main St')
    expect(shipping.text()).toContain('Springfield')

    const billing = wrapper.find('[data-test="order-address-billing"]')
    expect(billing.exists()).toBe(true)
    expect(billing.text()).toContain('Ada Lovelace')
    expect(billing.text()).toContain('2 Other St')
    expect(billing.text()).toContain('90210')
  })

  it('renders the status timeline from order events', async () => {
    singleOrder.value = order({
      events: [
        { uuid: 'e1', type: 'placed', payload: { number: 'ORD-1001' }, actor_uuid: null, visibility: 'internal', created_at: '2026-01-01 00:00:00' },
        { uuid: 'e2', type: 'status:paid', payload: null, actor_uuid: null, visibility: 'internal', created_at: '2026-01-01 01:00:00' },
      ],
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const rows = wrapper.findAll('[data-test="order-event-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('placed')
    expect(rows[1]!.text()).toContain('status:paid')
  })

  it('shows an empty timeline state when there are no events', async () => {
    singleOrder.value = order({ events: [] })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-events-empty"]').exists()).toBe(true)
  })

  it('renders no mutation controls at all — 13a is strictly read-only', async () => {
    singleOrder.value = order()
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-mark-paid"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(false)
  })
})

// ── Capability gating: both new routes require auth + thallo.commerce ──────────────────────

describe('commerce orders route gating', () => {
  const ROOT = process.cwd()

  it('the orders list route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/orders/index.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })

  it('the order detail route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/orders/[uuid]/index.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })
})
