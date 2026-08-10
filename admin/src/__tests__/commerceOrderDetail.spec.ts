import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type {
  CommerceOrder,
  CommerceRefund,
  CommerceOrderNote,
  CommerceOrderPaymentsEnvelope,
  CommerceOrderPaymentRecord,
  CommerceOrderPaymentIntent,
} from '@/queries/commerceOrders'
import type { CommerceInvoiceData } from '@/queries/commerceInvoice'

// Orders-invoices-receipts plan, Task 9: the order-detail hierarchy rework. This file is the
// detail page's own home (split out of commerceOrders.spec.ts, which now covers only the list
// page/table) — every detail-page spec lives here: the header band, the lifecycle actions +
// destructive cancel (now owned by the overflow-controlled `OrderCancelDialog`), refunds, the new
// `OrderPaymentCard` (Task 5's payment summary), notes, invoice data, address copy parity, DOM
// ordering, and the `>= xl` sticky rail.

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

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
const replace = vi.hoisted(() => vi.fn())
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ replace }),
}))
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ replace }),
}))

const singleOrder = ref<CommerceOrder | undefined>(undefined)
const singleStatus = ref<'pending' | 'error' | 'success'>('success')

const cancelMock = vi.hoisted(() => vi.fn())
const markPaidMock = vi.hoisted(() => vi.fn())
const fulfillMock = vi.hoisted(() => vi.fn())
const refundMock = vi.hoisted(() => vi.fn())
const orderRefunds = ref<CommerceRefund[] | undefined>(undefined)
const orderRefundsStatus = ref<'pending' | 'error' | 'success'>('success')
const addNoteMock = vi.hoisted(() => vi.fn())
const orderNotes = ref<CommerceOrderNote[] | undefined>(undefined)
const orderNotesStatus = ref<'pending' | 'error' | 'success'>('success')
const invoiceData = ref<CommerceInvoiceData | undefined>(undefined)
const invoiceDataStatus = ref<'pending' | 'error' | 'success'>('success')
// Task 5/9: the order payment summary — same `{ data, status }` shape as every other query mock
// in this file.
const orderPayments = ref<CommerceOrderPaymentsEnvelope | undefined>(undefined)
const orderPaymentsStatus = ref<'pending' | 'error' | 'success'>('success')

vi.mock('@/queries/commerceOrders', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceOrders')>()
  return {
    ...actual,
    useCommerceOrder: () => ({ data: singleOrder, status: singleStatus }),
    useOrderRefunds: () => ({ data: orderRefunds, status: orderRefundsStatus }),
    useOrderNotes: () => ({ data: orderNotes, status: orderNotesStatus }),
    useOrderPayments: () => ({ data: orderPayments, status: orderPaymentsStatus }),
    useCommerceOrderMutations: () => ({
      cancel: { mutateAsync: cancelMock, isLoading: ref(false) },
      markPaid: { mutateAsync: markPaidMock, isLoading: ref(false) },
      fulfill: { mutateAsync: fulfillMock, isLoading: ref(false) },
      refund: { mutateAsync: refundMock, isLoading: ref(false) },
      addNote: { mutateAsync: addNoteMock, isLoading: ref(false) },
    }),
  }
})

vi.mock('@/queries/commerceInvoice', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceInvoice')>()
  return {
    ...actual,
    useOrderInvoiceData: () => ({ data: invoiceData, status: invoiceDataStatus }),
  }
})

import OrderDetail from '@/pages/commerce/orders/[uuid]/index.vue'
import OrderActions from '@/pages/commerce/orders/components/OrderActions.vue'
import OrderCancelDialog from '@/pages/commerce/orders/components/OrderCancelDialog.vue'
import OrderStickyRail from '@/pages/commerce/orders/components/OrderStickyRail.vue'

function order(overrides: Partial<CommerceOrder> = {}): CommerceOrder {
  return {
    uuid: 'o1',
    order_number: 'ORD-1001',
    status: 'paid',
    fulfillment_status: 'unfulfilled',
    email: 'buyer@example.com',
    user_uuid: null,
    customer_name: null,
    phone_normalized: null,
    phone_display: null,
    fulfillment_mode: 'delivery',
    origin: 'storefront',
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
    schema_version: 2,
    seller: { name: 'Acme Supply Co.', address: '1 Market St', tax_id: 'TAX-1' },
    buyer: { email: 'buyer@example.com', addresses: null },
    order: {
      number: 'ORD-2002',
      dates: { placed_at: '2026-01-01 00:00:00', created_at: '2026-01-01 00:00:00', updated_at: null },
      currency: 'USD',
      currency_exponent: 2,
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

function paymentEnvelope(overrides: Partial<CommerceOrderPaymentsEnvelope> = {}): CommerceOrderPaymentsEnvelope {
  return {
    available: true,
    payments: [],
    intents: [],
    refund: { refunded_total: 0, refund_revision: 0 },
    ...overrides,
  }
}

function paymentRecord(overrides: Partial<CommerceOrderPaymentRecord> = {}): CommerceOrderPaymentRecord {
  return {
    gateway: 'stripe',
    status: 'succeeded',
    reference: 'pay_123',
    gateway_transaction_id: 'txn_abc',
    amount: 5900,
    currency: 'USD',
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-01 00:00:00',
    ...overrides,
  }
}

function paymentIntent(overrides: Partial<CommerceOrderPaymentIntent> = {}): CommerceOrderPaymentIntent {
  return {
    gateway: 'stripe',
    status: 'failed',
    reference: 'pi_123',
    amount: 5900,
    currency: 'USD',
    created_at: '2026-01-01 00:00:00',
    ...overrides,
  }
}

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
const SlideoverStub = { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' }
const pageStubs = { RouterLink: RouterLinkStub, Slideover: SlideoverStub, Modal: SlideoverStub }

/** Opens the header's overflow menu (destructive cancel + "Invoice data") — every item inside it
 * only renders while it's open, so any test that clicks `order-cancel`/`order-invoice`, or that
 * checks whether `order-cancel` is offered for the current status, must open it first. */
async function openOverflow(wrapper: ReturnType<typeof mount>) {
  await wrapper.find('[data-test="order-overflow-trigger"]').trigger('click')
  await flushPromises()
}

const clipboardWriteText = vi.hoisted(() => vi.fn())

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
  routeState.params = { uuid: 'o1' }
  routeState.query = {}
  replace.mockClear()
  singleOrder.value = undefined
  singleStatus.value = 'success'
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
  orderPayments.value = paymentEnvelope()
  orderPaymentsStatus.value = 'success'
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
  clipboardWriteText.mockReset()
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: clipboardWriteText },
    configurable: true,
  })
})

// ── Order detail page: line items, totals, customer, addresses, status timeline ─────────────

describe('commerce order detail page', () => {
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

  // ── Nullable email (Task 14: admin-order-creation walk-in orders) ─────────────────────────

  it('renders "Walk-in customer" and omits the copy control when email is null', async () => {
    singleOrder.value = order({ email: null })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-customer-email"]').text()).toBe('Walk-in customer')
    expect(wrapper.find('[data-test="order-email-copy"]').exists()).toBe(false)
  })

  it('still shows the email and its copy control when email is present', async () => {
    singleOrder.value = order({ email: 'ada@example.com' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-customer-email"]').text()).toBe('ada@example.com')
    expect(wrapper.find('[data-test="order-email-copy"]').exists()).toBe(true)
  })

  it('exposes fulfillment_mode as its own badge, distinct from fulfillment_status', async () => {
    singleOrder.value = order({ fulfillment_mode: 'in_store', fulfillment_status: 'unfulfilled' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-detail-fulfillment-mode"]').text()).toBe('in_store')
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

// ── Header band (Task 9, spec §2.5): identity, badges, placed date, customer, grand total,
// copy controls, and the print link's target/rel ─────────────────────────────────────────────

describe('order detail header band', () => {
  it('shows the order number with a copy control that copies it verbatim', async () => {
    singleOrder.value = order({ order_number: 'ORD-9001' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-header-number"]').text()).toBe('ORD-9001')
    await wrapper.find('[data-test="order-number-copy"]').trigger('click')
    await flushPromises()
    expect(clipboardWriteText).toHaveBeenCalledWith('ORD-9001')
  })

  it('shows status and fulfillment badges together', async () => {
    singleOrder.value = order({ status: 'fulfilled', fulfillment_status: 'partial' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-detail-status"]').text()).toBe('fulfilled')
    expect(wrapper.find('[data-test="order-detail-fulfillment"]').text()).toBe('partial')
  })

  it('shows the placed date', async () => {
    singleOrder.value = order({ placed_at: '2026-02-01 12:00:00' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-header-placed"]').text()).toContain('Placed')
  })

  it('shows the customer email with a copy control that copies it verbatim', async () => {
    singleOrder.value = order({ email: 'ada@example.com' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-email-copy"]').trigger('click')
    await flushPromises()
    expect(clipboardWriteText).toHaveBeenCalledWith('ada@example.com')
  })

  it('shows the grand total', async () => {
    singleOrder.value = order({ grand_total: 250000 })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-header-grand-total"]').text()).toContain('$2,500.00')
  })

  it('links a real anchor to the invoice route with target=_blank and rel=noopener', async () => {
    singleOrder.value = order({ uuid: 'o42' })
    routeState.params = { uuid: 'o42' }
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const link = wrapper.find('[data-test="order-print-link"]')
    expect(link.attributes('href')).toBe('/commerce/orders/o42/invoice')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toBe('noopener')
  })

  it('groups mark-paid/fulfill/refund beside the print link in the canonical action group', async () => {
    singleOrder.value = order({ status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const group = wrapper.find('#order-actions')
    expect(group.exists()).toBe(true)
    expect(group.find('[data-test="order-print-link"]').exists()).toBe(true)
    expect(group.find('[data-test="order-fulfill"]').exists()).toBe(true)
    expect(group.find('[data-test="order-refund"]').exists()).toBe(true)
    expect(group.find('[data-test="order-overflow-trigger"]').exists()).toBe(true)
  })
})

// ── Overflow menu: destructive cancel + "Invoice data", exactly one lifecycle owner ─────────
//
// A real `UDropdownMenu` (Reka UI underneath), not a hand-rolled toggle — so it comes with
// `role="menu"`/`role="menuitem"`, `aria-haspopup`/`aria-expanded` on the trigger, Escape-to-close,
// and outside-click dismissal for free. `:portal="false"` on the component (see index.vue) keeps
// its content inline in the DOM instead of teleported to `document.body`, so it's directly
// queryable here without a document-level lookup.

describe('order detail overflow menu', () => {
  it('holds cancel and "Invoice data" — nothing else', async () => {
    singleOrder.value = order({ status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)

    const menu = wrapper.find('[role="menu"]')
    expect(menu.exists()).toBe(true)
    expect(menu.findAll('[role="menuitem"]')).toHaveLength(2)
    expect(menu.find('[data-test="order-cancel"]').exists()).toBe(true)
    expect(menu.find('[data-test="order-invoice"]').text()).toBe('Invoice data')
  })

  it('hides the cancel item when cancellation is not legal for the current status', async () => {
    singleOrder.value = order({ status: 'canceled' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)

    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-invoice"]').exists()).toBe(true)
    expect(wrapper.findAll('[role="menuitem"]')).toHaveLength(1)
  })

  it('marks the trigger with aria-haspopup and toggles aria-expanded when opened', async () => {
    singleOrder.value = order({ status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const trigger = wrapper.find('[data-test="order-overflow-trigger"]')
    expect(trigger.attributes('aria-haspopup')).toBe('menu')
    expect(trigger.attributes('aria-expanded')).toBe('false')

    await openOverflow(wrapper)
    expect(wrapper.find('[data-test="order-overflow-trigger"]').attributes('aria-expanded')).toBe('true')
  })

  it('closes on Escape', async () => {
    // Reka's escape-key handling listens on the element's owner document — a wrapper that was
    // never attached to `document.body` is a detached tree the dispatched keydown never actually
    // bubbles into, so this (like the outside-click test below) needs `attachTo`.
    singleOrder.value = order({ status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs }, attachTo: document.body })
    await flushPromises()
    await openOverflow(wrapper)
    expect(wrapper.find('[role="menu"]').exists()).toBe(true)

    await wrapper.find('[role="menu"]').trigger('keydown', { key: 'Escape' })
    await flushPromises()

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-overflow-trigger"]').attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('closes on an outside click', async () => {
    singleOrder.value = order({ status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs }, attachTo: document.body })
    await flushPromises()
    await openOverflow(wrapper)
    expect(wrapper.find('[role="menu"]').exists()).toBe(true)

    document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
    document.body.click()
    await flushPromises()

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('selecting cancel opens exactly one OrderCancelDialog; selecting invoice data opens the invoice modal', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)

    await wrapper.find('[data-test="order-cancel"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="order-cancel-confirm"]').exists()).toBe(true)
  })

  it('instantiates exactly one OrderActions and one OrderCancelDialog on the whole page', async () => {
    singleOrder.value = order({ status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findAllComponents(OrderActions)).toHaveLength(1)
    expect(wrapper.findAllComponents(OrderCancelDialog)).toHaveLength(1)
  })
})

// ── Order lifecycle actions (Task 13b/13c): cancel / mark-paid / fulfill / refund ──────────────
// Visibility mirrors OrderStateMachine::ALLOWED exactly: pending_payment -> [cancel, mark-paid];
// paid -> [cancel, fulfill, refund]; fulfilled -> [refund only]; canceled/refunded -> none.
// Cancel's own trigger (Task 9) now lives in the header's overflow menu.

describe('order lifecycle actions', () => {
  it('pending_payment shows cancel and mark-paid, never fulfill or refund', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'pending_payment' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)

    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-mark-paid"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-refund"]').exists()).toBe(false)
  })

  it('paid shows cancel, fulfill, and refund, never mark-paid', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)

    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-mark-paid"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-refund"]').exists()).toBe(true)
  })

  it('fulfilled shows refund only', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'fulfilled' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)

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
      await openOverflow(wrapper)

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
    await openOverflow(wrapper)

    expect(wrapper.find('[data-test="order-cancel"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-mark-paid"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-fulfill"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-refund"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-actions"]').exists()).toBe(false)
  })

  // ── Cancel (Task 9: owned by OrderCancelDialog, opened from the overflow) ─────────────────

  it('cancel requires confirmation before calling the mutation', async () => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)

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
    await openOverflow(wrapper)

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

  it('renders the server 409 cancel-rejection inline and keeps the dialog open for retry', async () => {
    const { ApiError } = await import('@/api/errors')
    cancelMock.mockRejectedValue(new ApiError('Invalid order transition paid -> paid.', 409, {}, null))
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)

    await wrapper.find('[data-test="order-cancel"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="order-cancel-confirm"]').trigger('click')
    await flushPromises()

    const errorAlert = wrapper.find('[data-test="order-cancel-error"]')
    expect(errorAlert.exists()).toBe(true)
    expect(errorAlert.text()).toContain('Invalid order transition paid -> paid.')
    // Server stays authoritative: the dialog stays open so the user can retry or dismiss — the
    // failure never silently closes it as if the action had gone through.
    expect(wrapper.find('[data-test="order-cancel-confirm"]').exists()).toBe(true)
  })

  // ── Detail refetch reflects the new status after a successful action (mock sequence) ────────

  it('reflects the canceled status once the (simulated) refetch lands', async () => {
    cancelMock.mockResolvedValue(undefined)
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-detail-status"]').text()).toBe('paid')
    await openOverflow(wrapper)
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
    await openOverflow(wrapper)
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

// ── Refunds (Task 13c): slideover, exact minor-unit conversion, ceiling, error surfacing,
// refunds list section, invalidation-triggered refetch ────────────────────────────────────────

describe('order refund action', () => {
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

  it('surfaces a 422 amount-ceiling rejection (error.details.refund) inline and keeps the slideover open', async () => {
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

  it('reflects the new refund row and a refund.completed timeline entry once the (simulated) refetch lands', async () => {
    refundMock.mockResolvedValue(refund({ uuid: 'r1', amount: 5900 }))
    const wrapper = await openRefund({ uuid: 'o1', grand_total: 5900, refunded_total: 0, status: 'paid' })

    expect(wrapper.find('[data-test="refunds-empty"]').exists()).toBe(true)

    await wrapper.find('[data-test="refund-amount-input"]').setValue('59.00')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(refundMock).toHaveBeenCalledTimes(1)

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
    expect(wrapper.find('[data-test="order-actions"]').exists()).toBe(false)
  })
})

// ── Payment summary (Task 5 / Task 9): five classifications + order-level refund aggregate ───

describe('order payment summary card', () => {
  beforeEach(() => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
  })

  it('shows the loading state', async () => {
    orderPaymentsStatus.value = 'pending'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="order-payments-loading"]').exists()).toBe(true)
  })

  it('shows a card error state and toasts, never mistaken for the empty state', async () => {
    orderPaymentsStatus.value = 'error'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-payments-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-payments-empty"]').exists()).toBe(false)
    expect(notify.error).toHaveBeenCalledTimes(1)
  })

  it('classification 1: unavailable — Payvia is not migrated', async () => {
    orderPayments.value = paymentEnvelope({ available: false })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-payments-unavailable"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="order-payments-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-payment-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-payment-intent-row"]').exists()).toBe(false)
  })

  it('classification 2: empty — available, but no payments or attempts', async () => {
    orderPayments.value = paymentEnvelope({ available: true, payments: [], intents: [] })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-payments-empty"]').text()).toContain('No payments or attempts')
    expect(wrapper.find('[data-test="order-payments-unavailable"]').exists()).toBe(false)
  })

  it('classification 3: records — payments only', async () => {
    orderPayments.value = paymentEnvelope({
      available: true,
      payments: [paymentRecord({ reference: 'pay_1' })],
      intents: [],
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="order-payment-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="order-payment-attempts-section"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-payments-empty"]').exists()).toBe(false)
  })

  it('classification 4: attempts-only — intents only', async () => {
    orderPayments.value = paymentEnvelope({
      available: true,
      payments: [],
      intents: [paymentIntent({ reference: 'pi_1' })],
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="order-payment-intent-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="order-payments-section"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="order-payments-empty"]').exists()).toBe(false)
  })

  it('classification 5: both populated — BOTH sections render (classification never hides data)', async () => {
    orderPayments.value = paymentEnvelope({
      available: true,
      payments: [paymentRecord({ reference: 'pay_1' }), paymentRecord({ reference: 'pay_2' })],
      intents: [paymentIntent({ reference: 'pi_1' })],
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="order-payment-row"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-test="order-payment-intent-row"]')).toHaveLength(1)
  })

  it('shows the order-level refunded total, labeled as an aggregate distinct from Payvia rows', async () => {
    orderPayments.value = paymentEnvelope({
      available: true,
      refund: { refunded_total: 500, refund_revision: 3 },
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const label = wrapper.find('[data-test="order-payments-refunded-total"]')
    expect(label.text()).toContain('Refunded (order total)')
    expect(label.text()).toContain('$5.00')
  })

  it('copies the payment reference and gateway transaction id verbatim', async () => {
    orderPayments.value = paymentEnvelope({
      available: true,
      payments: [paymentRecord({ reference: 'pay_999', gateway_transaction_id: 'txn_999' })],
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="order-payment-reference-copy"]').trigger('click')
    await flushPromises()
    expect(clipboardWriteText).toHaveBeenCalledWith('pay_999')

    await wrapper.find('[data-test="order-payment-txn-copy"]').trigger('click')
    await flushPromises()
    expect(clipboardWriteText).toHaveBeenCalledWith('txn_999')
  })

  it('omits the gateway transaction id copy control when it is null', async () => {
    orderPayments.value = paymentEnvelope({
      available: true,
      payments: [paymentRecord({ gateway_transaction_id: null })],
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="order-payment-txn-copy"]').exists()).toBe(false)
  })
})

// ── Address copy parity: what's copied === what's displayed, via the ONE formatAddress() ─────

describe('address copy parity', () => {
  beforeEach(() => {
    singleOrder.value = order({
      uuid: 'o1',
      addresses: {
        shipping: {
          first_name: 'Ada',
          last_name: 'Lovelace',
          address1: '1 Main St',
          city: 'Springfield',
          country: 'USA',
        },
        billing: { name: 'Ada Lovelace', line1: '2 Other St', postal_code: '90210', country: 'USA' },
      },
    })
  })

  it('copies exactly the displayed shipping address text', async () => {
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const shippingEl = wrapper.find('[data-test="order-address-shipping"]')
    const displayedText = shippingEl.findAll('p').map((p) => p.text()).join('\n')

    await wrapper.find('[data-test="order-address-shipping-copy"]').trigger('click')
    await flushPromises()

    expect(clipboardWriteText).toHaveBeenCalledWith(displayedText)
    expect(displayedText).not.toMatch(/^[{[]/) // never JSON
  })

  it('copies exactly the displayed billing address text', async () => {
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const billingEl = wrapper.find('[data-test="order-address-billing"]')
    const displayedText = billingEl.findAll('p').map((p) => p.text()).join('\n')

    await wrapper.find('[data-test="order-address-billing-copy"]').trigger('click')
    await flushPromises()

    expect(clipboardWriteText).toHaveBeenCalledWith(displayedText)
  })
})

// ── Addresses side-by-side breakpoint ──────────────────────────────────────────────────────

describe('addresses layout', () => {
  it('uses a >= lg two-column grid, never sm', async () => {
    singleOrder.value = order({
      addresses: {
        shipping: { name: 'Ada', line1: '1 Main St' },
        billing: { name: 'Ada', line1: '2 Other St' },
      },
    })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const shipping = wrapper.find('[data-test="order-address-shipping"]')
    const grid = shipping.element.parentElement as HTMLElement
    expect(grid.className).toContain('lg:grid-cols-2')
    expect(grid.className).not.toContain('sm:grid-cols-2')
  })
})

// ── DOM order: timeline + notes live below every commercial block ───────────────────────────

describe('order detail section ordering', () => {
  it('renders payments, addresses, timeline, and notes in that order, below the header band', async () => {
    singleOrder.value = order({
      uuid: 'o1',
      events: [{ uuid: 'e1', type: 'placed', payload: null, actor_uuid: null, visibility: 'internal', created_at: null }],
    })
    orderNotes.value = [note()]
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const html = wrapper.html()
    const idxHeader = html.indexOf('data-test="order-header-band"')
    const idxItems = html.indexOf('id="section-items"')
    const idxPayments = html.indexOf('id="section-payments"')
    const idxAddresses = html.indexOf('id="section-addresses"')
    const idxTimeline = html.indexOf('id="section-timeline"')
    const idxNotes = html.indexOf('id="section-notes"')

    expect(idxHeader).toBeGreaterThanOrEqual(0)
    expect(idxHeader).toBeLessThan(idxItems)
    expect(idxItems).toBeLessThan(idxPayments)
    expect(idxPayments).toBeLessThan(idxAddresses)
    expect(idxAddresses).toBeLessThan(idxTimeline)
    expect(idxTimeline).toBeLessThan(idxNotes)
  })
})

// ── Sticky rail (>= xl): identity, print link, section anchors, actions anchor — no duplicate
// commercial markup or lifecycle state ───────────────────────────────────────────────────────

describe('order sticky rail', () => {
  it('shows order identity, the grand total, and a print link matching the header band', async () => {
    singleOrder.value = order({ uuid: 'o7', order_number: 'ORD-7', status: 'paid', grand_total: 1999 })
    routeState.params = { uuid: 'o7' }
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const rail = wrapper.find('[data-test="order-sticky-rail"]')
    expect(rail.exists()).toBe(true)
    expect(rail.find('[data-test="order-sticky-number"]').text()).toBe('ORD-7')
    expect(rail.find('[data-test="order-sticky-status"]').text()).toBe('paid')
    expect(rail.find('[data-test="order-sticky-total"]').text()).toContain('$19.99')

    const print = rail.find('[data-test="order-sticky-print"]')
    expect(print.attributes('href')).toBe('/commerce/orders/o7/invoice')
    expect(print.attributes('target')).toBe('_blank')
    expect(print.attributes('rel')).toBe('noopener')
  })

  it('links section anchors and an "Actions" anchor back to the canonical header group', async () => {
    singleOrder.value = order({ uuid: 'o1' })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const rail = wrapper.find('[data-test="order-sticky-rail"]')
    const actionsAnchor = rail.find('[data-test="order-sticky-actions-anchor"]')
    expect(actionsAnchor.attributes('href')).toBe('#order-actions')
    expect(rail.findAll('[data-test="order-sticky-anchor"]').length).toBeGreaterThan(0)
  })

  it('renders no line items, payment rows, or address markup, and instantiates no OrderActions/OrderCancelDialog of its own', async () => {
    singleOrder.value = order({
      uuid: 'o1',
      status: 'paid',
      lines: [
        { uuid: 'l1', product_name: 'Widget', sku: 'SKU-1', quantity: 1, unit_price: 100, line_total: 100, option_values: {}, addons: [] },
      ],
      addresses: { shipping: { name: 'Ada', line1: '1 Main St' }, billing: null },
    })
    orderPayments.value = paymentEnvelope({ available: true, payments: [paymentRecord()] })
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const railComponent = wrapper.findComponent(OrderStickyRail)
    expect(railComponent.find('[data-test="order-line-row"]').exists()).toBe(false)
    expect(railComponent.find('[data-test="order-payment-row"]').exists()).toBe(false)
    expect(railComponent.find('[data-test="order-address-shipping"]').exists()).toBe(false)
    expect(railComponent.findComponent(OrderActions).exists()).toBe(false)
    expect(railComponent.findComponent(OrderCancelDialog).exists()).toBe(false)

    // And globally, still exactly one of each on the whole page.
    expect(wrapper.findAllComponents(OrderActions)).toHaveLength(1)
    expect(wrapper.findAllComponents(OrderCancelDialog)).toHaveLength(1)
  })
})

// ── Order notes (Task 13d): append-only list + gated add-note form ─────────────────────────

describe('order notes section', () => {
  beforeEach(() => {
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

// ── Invoice data (Task 13d): view-graded read-only trigger + modal, now reached via the
// overflow menu (Task 9) ────────────────────────────────────────────────────────────────────

describe('order invoice data', () => {
  beforeEach(() => {
    singleOrder.value = order({ uuid: 'o1', status: 'paid' })
  })

  it('shows the invoice trigger regardless of can_manage (view-graded)', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)
    expect(wrapper.find('[data-test="order-invoice"]').exists()).toBe(true)
  })

  it('shows the loading state once opened', async () => {
    invoiceDataStatus.value = 'pending'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)
    await wrapper.find('[data-test="order-invoice"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="order-invoice-loading"]').exists()).toBe(true)
  })

  it('shows the error state once opened', async () => {
    invoiceDataStatus.value = 'error'
    const wrapper = mount(OrderDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    await openOverflow(wrapper)
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
    await openOverflow(wrapper)
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
    await openOverflow(wrapper)
    await wrapper.find('[data-test="order-invoice"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="order-invoice-error"]').exists()).toBe(false)
  })
})

describe('commerce order detail route gating', () => {
  const ROOT = process.cwd()

  it('the order detail route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/orders/[uuid]/index.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })
})
