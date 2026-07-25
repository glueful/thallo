import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, toValue } from 'vue'
import type {
  CommerceOrder,
  CommerceRefund,
  CommerceOrderNote,
  CommerceInvoiceData,
  OrderListPage,
} from '@/queries/commerceOrders'

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

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

// Task 13b: lifecycle mutation mocks, same shape as commerceProducts.spec.ts's
// useCommerceProductMutations mock (`{ mutateAsync, isLoading }` per action) — the real hook
// calls `useMutation`/`useQueryCache` from '@pinia/colada', which this file's harness (plain
// `setActivePinia(createPinia())`, no `PiniaColada` plugin) never installs.
const cancelMock = vi.hoisted(() => vi.fn())
const markPaidMock = vi.hoisted(() => vi.fn())
const fulfillMock = vi.hoisted(() => vi.fn())
// Task 13c: refund mutation mock, same `{ mutateAsync, isLoading }` shape as the other three.
const refundMock = vi.hoisted(() => vi.fn())
const orderRefunds = ref<CommerceRefund[] | undefined>(undefined)
const orderRefundsStatus = ref<'pending' | 'error' | 'success'>('success')
// Task 13d: notes list + add-note mutation, and invoice-data read query — same established shapes.
const addNoteMock = vi.hoisted(() => vi.fn())
const orderNotes = ref<CommerceOrderNote[] | undefined>(undefined)
const orderNotesStatus = ref<'pending' | 'error' | 'success'>('success')
const invoiceData = ref<CommerceInvoiceData | undefined>(undefined)
const invoiceDataStatus = ref<'pending' | 'error' | 'success'>('success')

vi.mock('@/queries/commerceOrders', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceOrders')>()
  return {
    ...actual,
    useCommerceOrders: (filters: unknown) => {
      lastOrdersFilters.current = filters
      return { data: ordersPage, status: ordersStatus }
    },
    useCommerceOrder: () => ({ data: singleOrder, status: singleStatus }),
    useOrderRefunds: () => ({ data: orderRefunds, status: orderRefundsStatus }),
    useOrderNotes: () => ({ data: orderNotes, status: orderNotesStatus }),
    useOrderInvoiceData: () => ({ data: invoiceData, status: invoiceDataStatus }),
    useCommerceOrderMutations: () => ({
      cancel: { mutateAsync: cancelMock, isLoading: ref(false) },
      markPaid: { mutateAsync: markPaidMock, isLoading: ref(false) },
      fulfill: { mutateAsync: fulfillMock, isLoading: ref(false) },
      refund: { mutateAsync: refundMock, isLoading: ref(false) },
      addNote: { mutateAsync: addNoteMock, isLoading: ref(false) },
    }),
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

function refund(overrides: Partial<CommerceRefund> = {}): CommerceRefund {
  return {
    uuid: 'r1',
    order_uuid: 'o1',
    amount: 1234,
    currency: 'USD',
    method: 'manual',
    status: 'completed',
    reason: null,
    restocked: false,
    failure_reason: null,
    initiated_by: 'admin1',
    created_at: '2026-01-03 00:00:00',
    updated_at: '2026-01-03 00:00:00',
    completed_at: '2026-01-03 00:00:00',
    lines: [],
    ...overrides,
  }
}

function note(overrides: Partial<CommerceOrderNote> = {}): CommerceOrderNote {
  return {
    uuid: 'ev1',
    body: 'Called customer, confirmed address.',
    visibility: 'internal',
    notify: false,
    actor_uuid: 'admin1',
    created_at: '2026-01-02 00:00:00',
    ...overrides,
  }
}

function invoiceDataFixture(overrides: Partial<CommerceInvoiceData> = {}): CommerceInvoiceData {
  return {
    schema_version: 1,
    seller: { name: 'Acme Supply Co.', address: '1 Market St', tax_id: 'TAX-1' },
    buyer: { email: 'buyer@example.com', addresses: null },
    order: {
      number: 'ORD-2002',
      dates: { placed_at: '2026-01-01 00:00:00', created_at: '2026-01-01 00:00:00', updated_at: null },
      currency: 'USD',
      status: 'paid',
    },
    lines: [
      { name: 'Widget', sku: 'SKU-1', quantity: 2, unit_minor: 1000, subtotal_minor: 2000, addons: [] },
    ],
    totals: {
      subtotal_minor: 2000,
      discount_minor: 0,
      shipping_minor: 500,
      tax_minor: 0,
      grand_minor: 2500,
      refunded_minor: 0,
    },
    refunds: [],
    ...overrides,
  }
}

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
// USlideover/UModal teleport their body/footer out of the wrapper — stub both to render the
// slots inline (mirrors commerceProducts.spec.ts's identical Modal + Slideover teleport stubs).
const SlideoverStub = { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' }
const pageStubs = { RouterLink: RouterLinkStub, Slideover: SlideoverStub, Modal: SlideoverStub }

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
  cancelMock.mockReset()
  markPaidMock.mockReset()
  fulfillMock.mockReset()
  refundMock.mockReset()
  orderRefunds.value = []
  orderRefundsStatus.value = 'success'
  addNoteMock.mockReset()
  orderNotes.value = []
  orderNotesStatus.value = 'success'
  invoiceData.value = undefined
  invoiceDataStatus.value = 'success'
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
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
    expect(resolved.perPage).toBe(25)
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

})

// ── Order lifecycle actions (Task 13b/13c): cancel / mark-paid / fulfill / refund ──────────────
// Visibility mirrors OrderStateMachine::ALLOWED exactly (see canCancelOrder()/canMarkOrderPaid()/
// canFulfillOrder()/canRefundOrder() in commerceOrders.ts): pending_payment -> [cancel, mark-paid];
// paid -> [cancel, fulfill, refund]; fulfilled -> [refund only]; canceled/refunded -> none.

describe('order lifecycle actions', () => {
  beforeEach(() => {
    routeState.params = { uuid: 'o1' }
  })

  it('pending_payment shows cancel and mark-paid, never fulfill or refund', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'pending_payment' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-mark-paid"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-refund"]').exists()).toBe(false)
  })

  it('paid shows cancel, fulfill, and refund, never mark-paid', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-mark-paid"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-refund"]').exists()).toBe(true)
  })

  it('fulfilled shows refund only', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'fulfilled' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-mark-paid"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-refund"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-actions"]').exists()).toBe(true)
  })

  it.each(['canceled', 'refunded'])(
    'status %s shows no lifecycle actions at all',
    async (status) => {
      singleOrder.value = order({ uuid: 'o1', status })
      const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
      await flushPromises()

      expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="order-mark-paid"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="order-refund"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="order-actions"]').exists()).toBe(false)
    },
  )

  it('hides every action when can_manage is false, regardless of status', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-mark-paid"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-refund"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-actions"]').exists()).toBe(false)
  })

  // ── Cancel: confirm step, exact payload ───────────────────────────────────────────────────

  it('cancel requires confirmation before calling the mutation', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-cancel"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="order-cancel-confirm"]').exists()).toBe(true)
    expect(cancelMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="order-cancel-confirm"]').trigger('click')
    await flushPromises()
    expect(cancelMock).toHaveBeenCalledTimes(1)
    expect(cancelMock).toHaveBeenCalledWith('o1')
  })

  it('dismissing the cancel confirm never calls the mutation', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-cancel"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="order-cancel-dismiss"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="order-cancel-confirm"]').exists()).toBe(false)
    expect(cancelMock).not.toHaveBeenCalled()
  })

  // ── Mark paid: confirm step, exact payload ────────────────────────────────────────────────

  it('mark-paid requires confirmation and calls the mutation with the order uuid', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'pending_payment' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-mark-paid"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="order-mark-paid-confirm"]').exists()).toBe(true)

    await wrapper.find('[data-test="order-mark-paid-confirm"]').trigger('click')
    await flushPromises()
    expect(markPaidMock).toHaveBeenCalledTimes(1)
    expect(markPaidMock).toHaveBeenCalledWith('o1')
  })

  // ── Fulfill: confirm step carries the DTO's tracking_ref field ───────────────────────────────

  it('fulfill sends the entered tracking_ref in the request payload', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-fulfill"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="order-fulfill-tracking-ref"]').exists()).toBe(true)

    await wrapper.find('[data-test="order-fulfill-tracking-ref"]').setValue('TRACK-123')
    await wrapper.find('[data-test="order-fulfill-confirm"]').trigger('click')
    await flushPromises()

    expect(fulfillMock).toHaveBeenCalledTimes(1)
    expect(fulfillMock).toHaveBeenCalledWith({ uuid: 'o1', input: { tracking_ref: 'TRACK-123' } })
  })

  it('fulfill sends tracking_ref: null when left blank', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-fulfill"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="order-fulfill-confirm"]').trigger('click')
    await flushPromises()

    expect(fulfillMock).toHaveBeenCalledWith({ uuid: 'o1', input: { tracking_ref: null } })
  })

  // ── Server-rejection surfacing: the server stays authoritative ────────────────────────────────

  it('renders the server 409 message inline and keeps the confirm panel open for retry', async () => {
    const { ApiError } = await import('@/api/errors')
    cancelMock.mockRejectedValue(new ApiError('Invalid order transition paid -> paid.', 409, {}, null))
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-cancel"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="order-cancel-confirm"]').trigger('click')
    await flushPromises()

    const errorAlert = wrapper.find('[data-test="order-action-error"]')
    expect(errorAlert.exists()).toBe(true)
    expect(errorAlert.text()).toContain('Invalid order transition paid -> paid.')
    // Server stays authoritative: the confirm panel stays open so the user can retry or dismiss —
    // the failure never silently closes it as if the action had gone through.
    expect(wrapper.find('[data-test="order-cancel-confirm"]').exists()).toBe(true)
  })

  // ── Detail refetch reflects the new status after a successful action (mock sequence) ────────
  // `useCommerceOrder` is mocked to a plain ref in this suite (see the module mock above), so
  // there's no real Pinia Colada cache to invalidate here — this simulates what that invalidation
  // + refetch WOULD produce: the query's own `data` ref receiving the freshly reloaded order.

  it('reflects the canceled status once the (simulated) refetch lands', async () => {
    cancelMock.mockResolvedValue(undefined)
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-detail-status"]').text()).toBe('paid')
    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(true)

    await wrapper.find('[data-test="order-cancel"]').trigger('click')
    await wrapper.find('[data-test="order-cancel-confirm"]').trigger('click')
    await flushPromises()
    expect(cancelMock).toHaveBeenCalledWith('o1')

    // The invalidation-triggered refetch resolving with the now-canceled order.
    singleOrder.value = order({ uuid: 'o1', status: 'canceled' })
    await flushPromises()

    expect(wrapper.find('[data-test="order-detail-status"]').text()).toBe('canceled')
    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-actions"]').exists()).toBe(false)
  })

  it('reflects the fulfilled status once the (simulated) refetch lands', async () => {
    fulfillMock.mockResolvedValue(undefined)
    singleOrder.value = order({ uuid: 'o1', status: 'paid', fulfillment_status: 'unfulfilled' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-fulfill"]').trigger('click')
    await wrapper.find('[data-test="order-fulfill-confirm"]').trigger('click')
    await flushPromises()
    expect(fulfillMock).toHaveBeenCalledWith({ uuid: 'o1', input: { tracking_ref: null } })

    singleOrder.value = order({ uuid: 'o1', status: 'fulfilled', fulfillment_status: 'fulfilled' })
    await flushPromises()

    expect(wrapper.find('[data-test="order-detail-status"]').text()).toBe('fulfilled')
    // Fulfilled still has ONE legal action (refund) — order-actions stays rendered, but fulfill
    // itself is no longer offered.
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-refund"]').exists()).toBe(true)
  })
})

// ── Capability gating: both new routes require auth + thallo.commerce ──────────────────────

// ── Refunds (Task 13c): slideover, exact minor-unit conversion, ceiling, error surfacing,
// refunds list section, invalidation-triggered refetch ────────────────────────────────────────

describe('order refund action', () => {
  beforeEach(() => {
    routeState.params = { uuid: 'o1' }
  })

  async function openRefund(overrides: Partial<CommerceOrder> = {}) {
    singleOrder.value = order({
      uuid: 'o1',
      status: 'paid',
      grand_total: 5900,
      refunded_total: 0,
      ...overrides,
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="order-refund"]').trigger('click')
    await flushPromises()
    return wrapper
  }

  it('shows the amount input, refundable ceiling, reason, and restock fields once opened', async () => {
    const wrapper = await openRefund()

    expect(wrapper.find('[data-test="refund-amount-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="refund-reason-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="refund-restock-checkbox"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="refund-submit"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="refund-ceiling"]').text()).toContain('$59.00')
  })

  it('shows the ceiling reduced by any prior refunded_total', async () => {
    const wrapper = await openRefund({ grand_total: 5900, refunded_total: 900 })
    expect(wrapper.find('[data-test="refund-ceiling"]').text()).toContain('$50.00')
  })

  // ── Exact decimal -> minor-unit conversion in the submitted payload ─────────────────────────

  it('converts a typed "12.34" (exponent 2) into exact minor units (1234) in the request', async () => {
    refundMock.mockResolvedValue(refund({ amount: 1234 }))
    const wrapper = await openRefund({ grand_total: 999999 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue('12.34')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(refundMock).toHaveBeenCalledTimes(1)
    const call = refundMock.mock.calls[0]![0]
    expect(call.uuid).toBe('o1')
    expect(call.input).toEqual({ amount: 1234, reason: null, restock: false })
    expect(typeof call.idempotencyKey).toBe('string')
    expect(call.idempotencyKey.length).toBeGreaterThan(0)
  })

  it('right-pads a short fraction ("12.3" -> 1230) exactly as parseMajorAmountToMinorUnits does', async () => {
    refundMock.mockResolvedValue(refund({ amount: 1230 }))
    const wrapper = await openRefund({ grand_total: 999999 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue('12.3')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(refundMock.mock.calls[0]![0].input.amount).toBe(1230)
  })

  it('forwards a trimmed reason; restock is disabled and always submits false', async () => {
    // The backend requires line attribution for restock and no line selector exists yet —
    // the checkbox is DISABLED (a control that can only 422 must not be offered), so the
    // submitted flag is pinned false.
    refundMock.mockResolvedValue(refund())
    const wrapper = await openRefund({ grand_total: 999999 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue('5.00')
    await wrapper.find('[data-test="refund-reason-input"]').setValue('  customer request  ')
    const restockCheckbox = wrapper.find('[data-test="refund-restock-checkbox"] button, [data-test="refund-restock-checkbox"] input')
    if (restockCheckbox.exists()) {
      expect(restockCheckbox.attributes('disabled')).toBeDefined()
    }
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(refundMock.mock.calls[0]![0].input).toEqual({
      amount: 500,
      reason: 'customer request',
      restock: false,
    })
  })

  it('sends reason: null when left blank', async () => {
    refundMock.mockResolvedValue(refund())
    const wrapper = await openRefund({ grand_total: 999999 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue('5.00')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(refundMock.mock.calls[0]![0].input.reason).toBeNull()
  })

  // ── Client-side validation: rejects BEFORE calling the mutation (server stays authoritative) ──

  it.each([
    ['', 'Enter an amount.'],
    ['abc', 'Enter a valid amount (up to 2 decimal places).'],
    ['12.345', 'Enter a valid amount (up to 2 decimal places).'],
    ['0', 'Amount must be at least the smallest currency unit.'],
    ['-5', 'Enter a valid amount (up to 2 decimal places).'],
  ])('rejects amount %j client-side without calling the mutation', async (typed, expectedError) => {
    const wrapper = await openRefund({ grand_total: 5900 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue(typed)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(refundMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain(expectedError)
  })

  it('rejects an amount above the client-computed ceiling without calling the mutation', async () => {
    const wrapper = await openRefund({ grand_total: 5900, refunded_total: 0 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue('100.00')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(refundMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('exceeds the refundable balance')
  })

  it('accepts an amount exactly at the ceiling', async () => {
    refundMock.mockResolvedValue(refund({ amount: 5900 }))
    const wrapper = await openRefund({ grand_total: 5900, refunded_total: 0 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue('59.00')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(refundMock).toHaveBeenCalledTimes(1)
    expect(refundMock.mock.calls[0]![0].input.amount).toBe(5900)
  })

  // ── Server rejection surfacing: the server stays authoritative ──────────────────────────────

  it('surfaces a 422 amount-ceiling rejection (error.details.refund) inline and keeps the slideover open', async () => {
    // A plain framework error-body object (Response::validation()'s exact envelope shape) rather
    // than a directly-constructed ApiError: RefundSlideover's own `toApiError()` runs against
    // whichever `@/api/errors` module instance THIS test file's `vi.resetModules()` (setup.ts)
    // left live — an `instanceof ApiError` check against an ApiError built from a separately
    // re-imported class would fail cross-module-identity, silently losing `fieldErrors`. The real
    // server response is a plain object anyway, so this is the more faithful shape besides.
    refundMock.mockRejectedValue({
      success: false,
      message: 'Validation failed',
      error: {
        code: 422,
        timestamp: '2026-01-01T00:00:00Z',
        request_id: 'req_1',
        details: { refund: 'amount: exceeds the remaining refundable balance.' },
      },
    })
    const wrapper = await openRefund({ grand_total: 999999 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue('12.34')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const errorEl = wrapper.find('[data-test="refund-error"]')
    expect(errorEl.exists()).toBe(true)
    expect(errorEl.text()).toContain('amount: exceeds the remaining refundable balance.')
    // Stays open for retry — never silently closes as if it had gone through.
    expect(wrapper.find('[data-test="refund-amount-input"]').exists()).toBe(true)
  })

  it('surfaces a 409 idempotency conflict message verbatim', async () => {
    refundMock.mockRejectedValue({
      success: false,
      message: 'This idempotency key was already used with a different request.',
      error: { code: 409, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_2' },
    })
    const wrapper = await openRefund({ grand_total: 999999 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue('12.34')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="refund-error"]').text()).toContain(
      'This idempotency key was already used with a different request.',
    )
  })

  // ── Success: closes the slideover and notifies ──────────────────────────────────────────────

  it('closes the slideover and shows a success toast once the refund is recorded', async () => {
    refundMock.mockResolvedValue(refund({ amount: 1234 }))
    const wrapper = await openRefund({ grand_total: 999999 })

    await wrapper.find('[data-test="refund-amount-input"]').setValue('12.34')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(notify.success).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="refund-amount-input"]').exists()).toBe(false)
  })

  it('dismissing the slideover never calls the mutation', async () => {
    const wrapper = await openRefund()
    await wrapper.find('[data-test="refund-dismiss"]').trigger('click')
    await flushPromises()

    expect(refundMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="refund-amount-input"]').exists()).toBe(false)
  })

  // ── Refunds list section (per-order GET) ────────────────────────────────────────────────────

  describe('refunds list section', () => {
    it('shows the loading state', async () => {
      orderRefundsStatus.value = 'pending'
      singleOrder.value = order({ uuid: 'o1', status: 'paid' })
      const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
      await flushPromises()
      expect(wrapper.find('[data-test="refunds-loading"]').exists()).toBe(true)
    })

    it('shows the error state', async () => {
      orderRefundsStatus.value = 'error'
      singleOrder.value = order({ uuid: 'o1', status: 'paid' })
      const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
      await flushPromises()
      expect(wrapper.find('[data-test="refunds-error"]').exists()).toBe(true)
    })

    it('shows the empty state when the order has no refunds', async () => {
      orderRefunds.value = []
      singleOrder.value = order({ uuid: 'o1', status: 'paid' })
      const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
      await flushPromises()
      expect(wrapper.find('[data-test="refunds-empty"]').exists()).toBe(true)
    })

    it('renders a row per refund with exact money, status, reason, and restocked flag', async () => {
      orderRefunds.value = [
        refund({ uuid: 'r1', amount: 1234, status: 'completed', reason: 'customer request', restocked: true }),
        refund({ uuid: 'r2', amount: 500, status: 'pending', reason: null, restocked: false }),
      ]
      singleOrder.value = order({ uuid: 'o1', status: 'paid' })
      const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
      await flushPromises()

      const rows = wrapper.findAll('[data-test="refund-row"]')
      expect(rows).toHaveLength(2)
      expect(rows[0]!.text()).toContain('$12.34')
      expect(rows[0]!.text()).toContain('completed')
      expect(rows[0]!.text()).toContain('customer request')
      expect(rows[0]!.text()).toContain('Restocked')
      expect(rows[1]!.text()).toContain('$5.00')
      expect(rows[1]!.text()).toContain('pending')
    })
  })

  // ── Invalidation-triggered refetch: status timeline + refunds list reflect the new state ─────
  // `useCommerceOrder`/`useOrderRefunds` are mocked to plain refs in this suite, so there's no
  // real Pinia Colada cache to invalidate — this simulates what useCommerceOrderMutations().refund's
  // invalidation WOULD produce: both query refs receiving their freshly reloaded data.

  it('reflects the new refund row and a refund.completed timeline entry once the (simulated) refetch lands', async () => {
    refundMock.mockResolvedValue(refund({ uuid: 'r1', amount: 5900 }))
    const wrapper = await openRefund({ uuid: 'o1', grand_total: 5900, refunded_total: 0, status: 'paid' })

    expect(wrapper.find('[data-test="refunds-empty"]').exists()).toBe(true)

    await wrapper.find('[data-test="refund-amount-input"]').setValue('59.00')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(refundMock).toHaveBeenCalledTimes(1)

    // The invalidation-triggered refetch: order now fully refunded, with the audit event
    // attached, and its own refunds list carrying the new row.
    singleOrder.value = order({
      uuid: 'o1',
      status: 'refunded',
      grand_total: 5900,
      refunded_total: 5900,
      events: [
        { uuid: 'e1', type: 'refund.completed', payload: { refund_uuid: 'r1', amount: 5900 }, actor_uuid: null, visibility: 'internal', created_at: '2026-01-03 00:00:00' },
      ],
    })
    orderRefunds.value = [refund({ uuid: 'r1', amount: 5900, status: 'completed' })]
    await flushPromises()

    expect(wrapper.find('[data-test="order-detail-status"]').text()).toBe('refunded')
    const timelineRows = wrapper.findAll('[data-test="order-event-row"]')
    expect(timelineRows).toHaveLength(1)
    expect(timelineRows[0]!.text()).toContain('refund.completed')

    const refundRows = wrapper.findAll('[data-test="refund-row"]')
    expect(refundRows).toHaveLength(1)
    expect(refundRows[0]!.text()).toContain('$59.00')
    // A fully-refunded order has no more legal lifecycle actions.
    expect(wrapper.find('[data-test="order-actions"]').exists()).toBe(false)
  })
})

// ── Order notes (Task 13d): append-only list + gated add-note form ─────────────────────────

describe('order notes section', () => {
  beforeEach(() => {
    routeState.params = { uuid: 'o1' }
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
  })

  it('shows the loading state', async () => {
    orderNotesStatus.value = 'pending'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="order-notes-loading"]').exists()).toBe(true)
  })

  it('shows the error state', async () => {
    orderNotesStatus.value = 'error'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="order-notes-error"]').exists()).toBe(true)
  })

  it('shows the empty state when the order has no notes', async () => {
    orderNotes.value = []
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="order-notes-empty"]').exists()).toBe(true)
  })

  it('renders one row per note in the exact (chronological) order the server returned', async () => {
    orderNotes.value = [
      note({ uuid: 'ev1', body: 'first note', created_at: '2026-01-02 00:00:00' }),
      note({ uuid: 'ev2', body: 'second note', created_at: '2026-01-03 00:00:00' }),
    ]
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const rows = wrapper.findAll('[data-test="order-note"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('first note')
    expect(rows[1]!.text()).toContain('second note')
  })

  it('hides the add-note form for a view-only user, while the notes list stays visible', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    orderNotes.value = [note({ body: 'existing note' })]
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-note-input"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-note-submit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-note"]').exists()).toBe(true)
  })

  it('shows the add-note form for a manager', async () => {
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-note-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-note-submit"]').exists()).toBe(true)
  })

  it('submits the exact { body, visibility: internal, notify: false } payload for the owning order', async () => {
    addNoteMock.mockResolvedValue(undefined)
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-note-input"]').setValue('  Called customer.  ')
    await wrapper.find('[data-test="order-note-submit"]').trigger('click')
    await flushPromises()

    expect(addNoteMock).toHaveBeenCalledTimes(1)
    expect(addNoteMock.mock.calls[0]![0]).toEqual({
      uuid: 'o1',
      input: { body: 'Called customer.', visibility: 'internal', notify: false },
    })
  })

  it('clears the input after a successful submit', async () => {
    addNoteMock.mockResolvedValue(undefined)
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const input = wrapper.find('[data-test="order-note-input"]')
    await input.setValue('A note.')
    await wrapper.find('[data-test="order-note-submit"]').trigger('click')
    await flushPromises()

    expect((wrapper.find('[data-test="order-note-input"]').element as HTMLTextAreaElement).value).toBe('')
  })

  it('rejects a blank note client-side without calling the mutation', async () => {
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-note-input"]').setValue('   ')
    await wrapper.find('[data-test="order-note-submit"]').trigger('click')
    await flushPromises()

    expect(addNoteMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="order-note-error"]').exists()).toBe(true)
  })

  it('surfaces the server error message inline on failure', async () => {
    addNoteMock.mockRejectedValue(new Error('Validation failed'))
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-note-input"]').setValue('x')
    await wrapper.find('[data-test="order-note-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="order-note-error"]').text()).toContain('Validation failed')
  })

  it('reflects the newly added note once the (simulated) invalidation-triggered refetch lands', async () => {
    addNoteMock.mockResolvedValue(undefined)
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="order-notes-empty"]').exists()).toBe(true)

    await wrapper.find('[data-test="order-note-input"]').setValue('Called customer.')
    await wrapper.find('[data-test="order-note-submit"]').trigger('click')
    await flushPromises()

    orderNotes.value = [note({ body: 'Called customer.' })]
    await flushPromises()

    const rows = wrapper.findAll('[data-test="order-note"]')
    expect(rows).toHaveLength(1)
    expect(rows[0]!.text()).toContain('Called customer.')
  })
})

// ── Invoice data (Task 13d): view-graded read-only trigger + modal ──────────────────────────

describe('order invoice data', () => {
  beforeEach(() => {
    routeState.params = { uuid: 'o1' }
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
  })

  it('shows the invoice trigger regardless of can_manage (view-graded)', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="order-invoice"]').exists()).toBe(true)
  })

  it('shows the loading state once opened', async () => {
    invoiceDataStatus.value = 'pending'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="order-invoice"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="order-invoice-loading"]').exists()).toBe(true)
  })

  it('shows the error state once opened', async () => {
    invoiceDataStatus.value = 'error'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="order-invoice"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="order-invoice-error"]').exists()).toBe(true)
  })

  it('renders seller, order, line items, totals, and refunds with exact money strings', async () => {
    invoiceData.value = invoiceDataFixture({
      refunds: [{ date: '2026-01-15 10:00:00', amount_minor: 500, method: 'original' }],
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="order-invoice"]').trigger('click')
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('Acme Supply Co.')
    expect(text).toContain('ORD-2002')
    expect(text).toContain('buyer@example.com')

    const lines = wrapper.findAll('[data-test="order-invoice-line"]')
    expect(lines).toHaveLength(1)
    expect(lines[0]!.text()).toContain('Widget')
    expect(lines[0]!.text()).toContain('$10.00')
    expect(lines[0]!.text()).toContain('$20.00')

    expect(wrapper.find('[data-test="order-invoice-total-grand"]').text()).toContain('$25.00')

    const refunds = wrapper.findAll('[data-test="order-invoice-refund"]')
    expect(refunds).toHaveLength(1)
    expect(refunds[0]!.text()).toContain('$5.00')
  })

  it('renders a null seller identity as present-but-empty, never crashing', async () => {
    invoiceData.value = invoiceDataFixture({ seller: { name: null, address: null, tax_id: null } })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="order-invoice"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="order-invoice-error"]').exists()).toBe(false)
  })
})

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
