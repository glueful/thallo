import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, toValue } from 'vue'
import type {
  CommerceCustomer,
  CommerceCustomerDetail,
  CommerceCustomerOrder,
  CommerceCustomerAddress,
  CustomerListPage,
} from '@/queries/commerceCustomers'

// ── Shared mock state (referenced inside vi.mock factories) ────────────────────────────────────
// Mirrors commerceOrders.spec.ts's established pattern: real refs (not plain objects) — a test
// that mutates one of these AFTER mount (to simulate a refetch landing) relies on genuine Vue
// reactivity to propagate into the DOM, which a plain `{ value }` object would never trigger.

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
// vue-router/auto — mirrors commerceOrders.spec.ts's established pattern.
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
}))

const customersPage = ref<CustomerListPage | undefined>(undefined)
const customersStatus = ref<'pending' | 'error' | 'success'>('success')
const singleCustomer = ref<CommerceCustomerDetail | undefined>(undefined)
const singleStatus = ref<'pending' | 'error' | 'success'>('success')
const lastCustomersFilters = vi.hoisted(() => ({ current: undefined as unknown }))
const lastCustomerArgs = vi.hoisted(() => ({ key: undefined as unknown, by: undefined as unknown }))

vi.mock('@/queries/commerceCustomers', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceCustomers')>()
  return {
    ...actual,
    useCommerceCustomers: (filters: unknown) => {
      lastCustomersFilters.current = filters
      return { data: customersPage, status: customersStatus }
    },
    useCommerceCustomer: (key: unknown, by: unknown) => {
      lastCustomerArgs.key = key
      lastCustomerArgs.by = by
      return { data: singleCustomer, status: singleStatus }
    },
  }
})

import CustomersTable from '@/pages/commerce/customers/components/CustomersTable.vue'
import CustomersIndex from '@/pages/commerce/customers/index.vue'
import CustomerDetail from '@/pages/commerce/customers/[key]/index.vue'

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

function customerOrder(overrides: Partial<CommerceCustomerOrder> = {}): CommerceCustomerOrder {
  return {
    uuid: 'o1',
    order_number: 'ORD-1001',
    status: 'paid',
    fulfillment_status: 'unfulfilled',
    email: 'ada@example.com',
    currency: 'USD',
    subtotal: 1000,
    discount_total: 0,
    shipping_total: 0,
    tax_total: 0,
    grand_total: 1000,
    refunded_total: 0,
    placed_at: '2026-01-01 00:00:00',
    created_at: '2026-01-01 00:00:00',
    ...overrides,
  }
}

function customerAddress(overrides: Partial<CommerceCustomerAddress> = {}): CommerceCustomerAddress {
  return {
    uuid: 'addr1',
    label: 'Home',
    address: { country: 'US', city: 'Springfield' },
    is_default_shipping: true,
    is_default_billing: false,
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    ...overrides,
  }
}

function customerDetail(overrides: Partial<CommerceCustomerDetail> = {}): CommerceCustomerDetail {
  return {
    ...customer(),
    orders: [],
    addresses: [],
    ...overrides,
  }
}

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
const pageStubs = { RouterLink: RouterLinkStub }

/** Find the Reka SelectRoot ancestor of a USelect carrying `dataTest`, and drive it directly —
 * USelect's options render in a portal, so opening the dropdown in jsdom is unreliable; emitting
 * `update:modelValue` on the underlying SelectRoot is the established pattern
 * (commerceOrders.spec.ts). */
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
  customersPage.value = { customers: [], total: 0, current_page: 1, per_page: 24 }
  customersStatus.value = 'success'
  singleCustomer.value = undefined
  singleStatus.value = 'success'
  lastCustomersFilters.current = undefined
  lastCustomerArgs.key = undefined
  lastCustomerArgs.by = undefined
})

// ── CustomersTable: rows (identity, orders, total, last order), loading/empty/error ────────────

describe('CustomersTable', () => {
  const rows = [
    customer({ key: 'usercustu001', key_type: 'user', email: 'ada@example.com', orders_count: 3, total_spent_minor: 5900 }),
    customer({ key: 'guest@example.com', key_type: 'email', user_uuid: null, email: 'guest@example.com', orders_count: 1, total_spent_minor: 1200 }),
  ]

  it('renders one row per customer with identity, orders count, exact total, and last-order date', () => {
    const wrapper = mount(CustomersTable, {
      props: { rows, status: 'success' },
      global: { stubs: pageStubs },
    })

    expect(wrapper.findAll('[data-test="customer-row"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-test="customer-email"]')[0]!.text()).toBe('ada@example.com')
    expect(wrapper.findAll('[data-test="customer-orders-count"]')[0]!.text()).toBe('3')
    expect(wrapper.findAll('[data-test="customer-total"]')[0]!.text()).toContain('$59.00')
  })

  it('prefers the enriched username over the raw email, keeping the email as a secondary line', () => {
    const wrapper = mount(CustomersTable, {
      props: { rows: [customer({ username: 'ada', email: 'ada@example.com' })], status: 'success' },
      global: { stubs: pageStubs },
    })

    expect(wrapper.find('[data-test="customer-email"]').text()).toBe('ada')
    expect(wrapper.text()).toContain('ada@example.com')
  })

  it('shows Registered for a user-keyed row and Guest for an email-keyed row', () => {
    const wrapper = mount(CustomersTable, {
      props: { rows, status: 'success' },
      global: { stubs: pageStubs },
    })

    const badges = wrapper.findAll('[data-test="customer-type"]')
    expect(badges[0]!.text()).toBe('Registered')
    expect(badges[1]!.text()).toBe('Guest')
  })

  it('shows the loading state', () => {
    const wrapper = mount(CustomersTable, { props: { rows: [], status: 'pending' } })
    expect(wrapper.find('[data-test="customers-loading"]').exists()).toBe(true)
  })

  it('shows the empty state', () => {
    const wrapper = mount(CustomersTable, { props: { rows: [], status: 'success' } })
    expect(wrapper.find('[data-test="customers-empty"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    const wrapper = mount(CustomersTable, { props: { rows: [], status: 'error' } })
    expect(wrapper.find('[data-test="customers-error"]').exists()).toBe(true)
  })

  it('links a user-keyed row to its detail page with by=user', () => {
    const wrapper = mount(CustomersTable, {
      props: { rows: [rows[0]!], status: 'success' },
      global: { stubs: pageStubs },
    })
    expect(wrapper.find('[data-test="customer-row"]').attributes('href')).toBe(
      '/commerce/customers/usercustu001?by=user',
    )
  })

  it('links an email-keyed row to its detail page with by=email, URL-encoding the "@"', () => {
    const wrapper = mount(CustomersTable, {
      props: { rows: [rows[1]!], status: 'success' },
      global: { stubs: pageStubs },
    })
    expect(wrapper.find('[data-test="customer-row"]').attributes('href')).toBe(
      '/commerce/customers/guest%40example.com?by=email',
    )
  })
})

// ── Customers list page: default filters, sort, search, pagination ────────────────────────────

describe('commerce customers list page', () => {
  it('sends the default sort/direction and no email filter on first load', async () => {
    mount(CustomersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    const resolved = toValue(lastCustomersFilters.current) as {
      email?: string
      sort?: string
      direction?: string
      page?: number
      perPage?: number
    }
    expect(resolved.email).toBeUndefined()
    expect(resolved.sort).toBe('last_order_at')
    expect(resolved.direction).toBe('desc')
    expect(resolved.page).toBe(1)
    expect(resolved.perPage).toBe(25)
  })

  it('applies the selected sort option as the exact sort/direction pair', async () => {
    const wrapper = mount(CustomersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    selectByTestId(wrapper, 'customer-sort').vm.$emit('update:modelValue', 'total_spent:asc')
    await flushPromises()

    const resolved = toValue(lastCustomersFilters.current) as { sort?: string; direction?: string }
    expect(resolved.sort).toBe('total_spent')
    expect(resolved.direction).toBe('asc')
  })

  it('sends the typed search as the email filter after the debounce settles', async () => {
    vi.useFakeTimers()
    try {
      const wrapper = mount(CustomersIndex, { global: { stubs: pageStubs } })
      await flushPromises()

      await wrapper.find('[data-test="customer-search"]').setValue('ada')
      // Not yet applied — still debouncing.
      expect((toValue(lastCustomersFilters.current) as { email?: string }).email).toBeUndefined()

      await vi.advanceTimersByTimeAsync(300)
      expect((toValue(lastCustomersFilters.current) as { email?: string }).email).toBe('ada')
    } finally {
      vi.useRealTimers()
    }
  })

  it('renders the customers table with the fetched rows', async () => {
    customersPage.value = { customers: [customer({ key: 'usercustu001' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(CustomersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="customer-row"]')).toHaveLength(1)
  })

  it('shows pagination controls only once there is at least one customer', async () => {
    customersPage.value = { customers: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mount(CustomersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.text()).not.toContain('Rows per page')

    customersPage.value = { customers: [customer()], total: 1, current_page: 1, per_page: 24 }
    await flushPromises()
    expect(wrapper.text()).toContain('Rows per page')
  })
})

// ── Customer detail page: identity, summary, addresses, recent orders ─────────────────────────

describe('commerce customer detail page', () => {
  beforeEach(() => {
    routeState.params = { key: 'usercustu001' }
    routeState.query = { by: 'user' }
  })

  it('shows the loading state', () => {
    singleStatus.value = 'pending'
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    expect(wrapper.find('[data-test="customer-detail-loading"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    singleStatus.value = 'error'
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    expect(wrapper.find('[data-test="customer-detail-error"]').exists()).toBe(true)
  })

  it('passes the route key and by=user through to the query hook', async () => {
    singleCustomer.value = customerDetail()
    mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(toValue(lastCustomerArgs.key)).toBe('usercustu001')
    expect(toValue(lastCustomerArgs.by)).toBe('user')
  })

  it('defaults an unrecognized/missing by query param to "user"', async () => {
    routeState.query = {}
    singleCustomer.value = customerDetail()
    mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(toValue(lastCustomerArgs.by)).toBe('user')
  })

  it('reads by=email from the route query verbatim', async () => {
    routeState.query = { by: 'email' }
    singleCustomer.value = customerDetail({ key_type: 'email', user_uuid: null, addresses: null })
    mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(toValue(lastCustomerArgs.by)).toBe('email')
  })

  it('shows the Registered badge and email for a user-keyed customer', async () => {
    singleCustomer.value = customerDetail({ key_type: 'user', email: 'ada@example.com' })
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="customer-detail-type"]').text()).toBe('Registered')
    expect(wrapper.find('[data-test="customer-detail-email"]').text()).toContain('ada@example.com')
  })

  it('shows the Guest badge for an email-keyed customer', async () => {
    singleCustomer.value = customerDetail({ key_type: 'email', user_uuid: null, addresses: null })
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="customer-detail-type"]').text()).toBe('Guest')
  })

  it('renders the exact summary figures via useMoney', async () => {
    singleCustomer.value = customerDetail({
      orders_count: 4,
      total_spent_minor: 123456,
      refunded_minor: 100,
    })
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="customer-summary-orders"]').text()).toBe('4')
    expect(wrapper.find('[data-test="customer-summary-total"]').text()).toContain('$1,234.56')
    expect(wrapper.find('[data-test="customer-summary-refunded"]').text()).toContain('$1.00')
  })

  it('omits the addresses section entirely for an email-keyed (guest) customer', async () => {
    singleCustomer.value = customerDetail({ key_type: 'email', user_uuid: null, addresses: null })
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="customer-addresses-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="customer-address-row"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('No addresses saved')
  })

  it('shows the empty-addresses state for a user-keyed customer with no saved addresses', async () => {
    singleCustomer.value = customerDetail({ addresses: [] })
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="customer-addresses-empty"]').exists()).toBe(true)
  })

  it('renders each saved address with its label and default flags', async () => {
    singleCustomer.value = customerDetail({
      addresses: [
        customerAddress({ uuid: 'a1', label: 'Home', is_default_shipping: true, is_default_billing: false }),
        customerAddress({ uuid: 'a2', label: null, is_default_shipping: false, is_default_billing: true }),
      ],
    })
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const rows = wrapper.findAll('[data-test="customer-address-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Home')
    expect(rows[0]!.text()).toContain('Default shipping')
    expect(rows[1]!.text()).toContain('Default billing')
    expect(rows[0]!.text()).toContain('Springfield')
  })

  it('shows the empty state when the customer has no recent orders', async () => {
    singleCustomer.value = customerDetail({ orders: [] })
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="customer-orders-empty"]').exists()).toBe(true)
  })

  it('renders one row per recent order with exact money and links to the order detail page', async () => {
    singleCustomer.value = customerDetail({
      orders: [
        customerOrder({ uuid: 'o1', order_number: 'ORD-1001', status: 'paid', grand_total: 5900 }),
        customerOrder({ uuid: 'o2', order_number: 'ORD-1002', status: 'fulfilled', grand_total: 1200 }),
      ],
    })
    const wrapper = mount(CustomerDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const rows = wrapper.findAll('[data-test="customer-order-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('ORD-1001')
    expect(rows[0]!.text()).toContain('$59.00')
    expect(rows[0]!.find('a').attributes('href')).toBe('/commerce/orders/o1')
    expect(rows[1]!.find('a').attributes('href')).toBe('/commerce/orders/o2')
  })
})

describe('commerce customers route gating', () => {
  const ROOT = process.cwd()

  it('the customers list route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/customers/index.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })

  it('the customer detail route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/customers/[key]/index.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })
})
