import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { ApiError } from '@/api/errors'
import type { CommerceOrder } from '@/queries/commerceOrders'
import type {
  PaymentLinkMint,
  PaymentLinkSendEnvelope,
  PaymentLinkStatus,
  PaymentLinkView,
} from '@/queries/commercePaymentLinks'

// ── Payment links Task 13: the admin payment-link card ────────────────────────────────────────
//
// Custody is the whole point of this surface: the mint response's tokened URL is the ONE moment
// the plaintext exists on the client, so it lives in component state only — never a store, never
// re-queried, never derived from the status read (which carries no token at all, and is asserted
// so here). "Send this link" therefore has to hand the visible URL's token back, and it parses
// that URL with the platform `URL` API + a 64-hex shape gate, never ad-hoc string splitting.

const TOKEN = 'a'.repeat(64)
const TOKEN_2 = 'b'.repeat(64)
const URL_1 = `https://shop.test/pay/${TOKEN}`
const URL_2 = `https://shop.test/pay/${TOKEN_2}`

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

const metaData = ref<Record<string, unknown> | undefined>({
  currency: 'USD',
  currency_exponent: 2,
  shop_index_url: '',
  low_stock_threshold: 3,
  can_view: true,
  can_manage: true,
  can_attach_user: false,
  email_available: true,
})
const metaStatus = ref<'pending' | 'error' | 'success'>('success')
vi.mock('@/queries/commerceMeta', () => ({
  useCommerceMeta: () => ({ data: metaData, status: metaStatus }),
}))

// The `payment_request` switch rides the existing order-email settings surface (default OFF
// server-side — it emails a live bearer credential).
const emailSettingsStatus = ref<'pending' | 'error' | 'success'>('success')
const emailSettings = ref<undefined | { templates: { template: string; key: string; enabled: { value: boolean; default: boolean; overridden: boolean } }[]; commerce_mailer_active: boolean }>({
  templates: [
    {
      template: 'payment_request',
      key: 'commerce.payment_request',
      enabled: { value: true, default: false, overridden: true },
    },
  ],
  commerce_mailer_active: false,
})
vi.mock('@/queries/commerceSettings', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceSettings')>()
  return {
    ...actual,
    useCommerceEmailSettings: () => ({ data: emailSettings, status: emailSettingsStatus }),
  }
})

// The payment-link query module: the QUERY + MUTATIONS are mocked, the PURE HELPERS
// (`paymentLinkTokenFromUrl`, `clampPaymentLinkTtl`, `newPaymentLinkIdempotencyKey`) stay REAL —
// the card's URL parsing and TTL clamping are exactly what's under test here.
const linkStatus = ref<PaymentLinkStatus | undefined>(undefined)
const linkStatusState = ref<'pending' | 'error' | 'success'>('success')
const createMock = vi.hoisted(() => vi.fn())
const revokeMock = vi.hoisted(() => vi.fn())
const sendMock = vi.hoisted(() => vi.fn())
const invalidateMock = vi.hoisted(() => vi.fn())
vi.mock('@/queries/commercePaymentLinks', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commercePaymentLinks')>()
  return {
    ...actual,
    useOrderPaymentLink: () => ({ data: linkStatus, status: linkStatusState }),
    useOrderPaymentLinkInvalidation: () => invalidateMock,
    // The three writes are plain awaited functions in the card (never `useMutation()` — see
    // orderPaymentLinkCustody.spec.ts for why), so they are mocked as such.
    createOrderPaymentLink: createMock,
    revokeOrderPaymentLink: revokeMock,
    sendOrderPaymentLink: sendMock,
  }
})

// ── Order-detail page mocks (gating matrix only) ───────────────────────────────────────────────
const routeState = vi.hoisted(() => ({ params: {} as Record<string, string>, query: {} as Record<string, string> }))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ replace: vi.fn() }),
}))
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ replace: vi.fn() }),
}))

const singleOrder = ref<CommerceOrder | undefined>(undefined)
vi.mock('@/queries/commerceOrders', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceOrders')>()
  return {
    ...actual,
    useCommerceOrder: () => ({ data: singleOrder, status: ref('success') }),
    useOrderRefunds: () => ({ data: ref([]), status: ref('success') }),
    useOrderNotes: () => ({ data: ref([]), status: ref('success') }),
    useOrderPayments: () => ({
      data: ref({ available: true, payments: [], intents: [], refund: { refunded_total: 0, refund_revision: 0 } }),
      status: ref('success'),
    }),
    useCommerceOrderMutations: () => ({
      cancel: { mutateAsync: vi.fn(), isLoading: ref(false) },
      markPaid: { mutateAsync: vi.fn(), isLoading: ref(false) },
      fulfill: { mutateAsync: vi.fn(), isLoading: ref(false) },
      refund: { mutateAsync: vi.fn(), isLoading: ref(false) },
      addNote: { mutateAsync: vi.fn(), isLoading: ref(false) },
    }),
  }
})
vi.mock('@/queries/commerceInvoice', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceInvoice')>()
  return { ...actual, useOrderInvoiceData: () => ({ data: ref(undefined), status: ref('success') }) }
})
vi.mock('@/queries/commerceDrafts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceDrafts')>()
  return { ...actual, useCompleteSaleMutation: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }) }
})

import OrderPaymentLinkCard from '@/pages/commerce/orders/components/OrderPaymentLinkCard.vue'
import OrderDetail from '@/pages/commerce/orders/[uuid]/index.vue'

function order(overrides: Partial<CommerceOrder> = {}): CommerceOrder {
  return {
    uuid: 'o1',
    order_number: 'ORD-1001',
    status: 'pending_payment',
    fulfillment_status: 'unfulfilled',
    email: 'buyer@example.com',
    user_uuid: null,
    customer_name: null,
    phone_normalized: null,
    phone_display: null,
    fulfillment_mode: 'delivery',
    origin: 'admin',
    currency: 'USD',
    subtotal: 5000,
    discount_total: 0,
    shipping_total: 0,
    tax_total: 0,
    grand_total: 5000,
    refunded_total: 0,
    discount_code: null,
    shipping_method: null,
    addresses: null,
    placed_at: '2026-08-01 00:00:00',
    created_at: '2026-08-01 00:00:00',
    updated_at: null,
    lines: [],
    events: [],
    ...overrides,
  }
}

function link(overrides: Partial<PaymentLinkView> = {}): PaymentLinkView {
  return {
    link_uuid: 'link-1',
    status: 'active',
    expires_at: '2026-08-19 12:00:00',
    provider_session_issued: false,
    ...overrides,
  }
}

function status(overrides: Partial<PaymentLinkStatus> = {}): PaymentLinkStatus {
  return {
    link: link(),
    exposure: {
      reason: 'active_link',
      blocks_automatic_cancellation: true,
      requires_risk_acknowledgement: false,
    },
    ...overrides,
  }
}

function mint(url = URL_1, overrides: Partial<PaymentLinkView> = {}): PaymentLinkMint {
  return { url, link: link(overrides) }
}

function envelope(overrides: Partial<PaymentLinkSendEnvelope> = {}): PaymentLinkSendEnvelope {
  return {
    http_status: 200,
    message: 'the payment link was emailed.',
    receipt: {
      delivery_uuid: 'del-1',
      order_uuid: 'o1',
      link_uuid: 'link-1',
      mode: 'current',
      status: 'sent',
      error_code: null,
      provider_message_id: 'msg-1',
      replayed: false,
      created_at: '2026-08-12 10:00:00',
      updated_at: '2026-08-12 10:00:01',
    },
    link: link(),
    url: null,
    recovery: null,
    ...overrides,
  }
}

const ModalStub = {
  props: ['open'],
  template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>',
}
const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
const stubs = { Modal: ModalStub, Slideover: ModalStub, RouterLink: RouterLinkStub }

function mountCard(overrides: Partial<CommerceOrder> = {}, canManage = true) {
  return mount(OrderPaymentLinkCard, {
    props: { order: order(overrides), canManage },
    global: { stubs },
  })
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
    can_attach_user: false,
    email_available: true,
  }
  emailSettings.value = {
    templates: [
      {
        template: 'payment_request',
        key: 'commerce.payment_request',
        enabled: { value: true, default: false, overridden: true },
      },
    ],
    commerce_mailer_active: false,
  }
  linkStatus.value = status({ link: null, exposure: { reason: 'none', blocks_automatic_cancellation: false, requires_risk_acknowledgement: false } })
  linkStatusState.value = 'success'
  metaStatus.value = 'success'
  emailSettingsStatus.value = 'success'
  createMock.mockReset()
  revokeMock.mockReset()
  sendMock.mockReset()
  invalidateMock.mockReset()
  invalidateMock.mockResolvedValue(undefined)
  notify.success.mockReset()
  notify.error.mockReset()
  routeState.params = { uuid: 'o1' }
  singleOrder.value = order()
  clipboardWriteText.mockReset()
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: clipboardWriteText },
    configurable: true,
  })
})

// ── Gating matrix ──────────────────────────────────────────────────────────────────────────────

describe('payment-link card gating', () => {
  it.each([
    ['admin', 'pending_payment', true],
    ['admin', 'paid', false],
    ['admin', 'fulfilled', false],
    ['admin', 'canceled', false],
    ['admin', 'refunded', false],
    ['storefront', 'pending_payment', false],
    ['storefront', 'paid', false],
  ])('origin=%s status=%s renders the card: %s', (origin, orderStatus, visible) => {
    const wrapper = mountCard({ origin, status: orderStatus })
    expect(wrapper.find('[data-test="payment-link-card"]').exists()).toBe(visible)
  })

  it('the order detail page mounts the card for an admin-origin pending_payment order', async () => {
    singleOrder.value = order({ origin: 'admin', status: 'pending_payment' })
    const wrapper = mount(OrderDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="payment-link-card"]').exists()).toBe(true)
  })

  it('the order detail page hides the card for a storefront order and for a paid admin order', async () => {
    singleOrder.value = order({ origin: 'storefront', status: 'pending_payment' })
    const storefront = mount(OrderDetail, { global: { stubs } })
    await flushPromises()
    expect(storefront.find('[data-test="payment-link-card"]').exists()).toBe(false)

    singleOrder.value = order({ origin: 'admin', status: 'paid' })
    const paid = mount(OrderDetail, { global: { stubs } })
    await flushPromises()
    expect(paid.find('[data-test="payment-link-card"]').exists()).toBe(false)
  })
})

// ── Create + the ONE-TIME copy surface ─────────────────────────────────────────────────────────

describe('payment-link create and one-time custody', () => {
  it('shows the loading and error states', () => {
    linkStatusState.value = 'pending'
    expect(mountCard().find('[data-test="payment-link-loading"]').exists()).toBe(true)
    linkStatusState.value = 'error'
    expect(mountCard().find('[data-test="payment-link-error"]').exists()).toBe(true)
  })

  it('offers create with the default 7-day TTL when the order has no link', () => {
    const wrapper = mountCard()
    expect(wrapper.find('[data-test="payment-link-empty"]').exists()).toBe(true)
    expect((wrapper.find('[data-test="payment-link-ttl"]').element as HTMLInputElement).value).toBe('7')
  })

  it('creates with the entered TTL', async () => {
    createMock.mockResolvedValue(mint())
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-ttl"]').setValue('14')
    await wrapper.find('[data-test="payment-link-create"]').trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith('o1', 14)
  })

  it('clamps the TTL to 1..30 in the UI and in the request', async () => {
    createMock.mockResolvedValue(mint())
    const wrapper = mountCard()

    await wrapper.find('[data-test="payment-link-ttl"]').setValue('99')
    await wrapper.find('[data-test="payment-link-ttl"]').trigger('blur')
    expect((wrapper.find('[data-test="payment-link-ttl"]').element as HTMLInputElement).value).toBe('30')
    await wrapper.find('[data-test="payment-link-create"]').trigger('click')
    await flushPromises()
    expect(createMock).toHaveBeenLastCalledWith('o1', 30)

    await wrapper.find('[data-test="payment-link-ttl"]').setValue('0')
    await wrapper.find('[data-test="payment-link-ttl"]').trigger('blur')
    expect((wrapper.find('[data-test="payment-link-ttl"]').element as HTMLInputElement).value).toBe('1')
    await wrapper.find('[data-test="payment-link-create"]').trigger('click')
    await flushPromises()
    expect(createMock).toHaveBeenLastCalledWith('o1', 1)
  })

  it('renders the raw URL EXACTLY ONCE, copyable, with the shown-once warning', async () => {
    createMock.mockResolvedValue(mint())
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-create"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payment-link-url"]').text()).toBe(URL_1)
    expect(wrapper.findAll('[data-test="payment-link-url"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="payment-link-url-once"]').text()).toMatch(/once/i)

    await wrapper.find('[data-test="payment-link-url-copy"]').trigger('click')
    expect(clipboardWriteText).toHaveBeenCalledWith(URL_1)
  })

  it('the URL is gone after the card is re-created, and no status read ever carries a token', async () => {
    createMock.mockResolvedValue(mint())
    const first = mountCard()
    await first.find('[data-test="payment-link-create"]').trigger('click')
    await flushPromises()
    expect(first.html()).toContain(TOKEN)

    // The status read is the only thing that survives navigation/refresh — and it has no token.
    linkStatus.value = status()
    const second = mountCard()
    await flushPromises()
    expect(second.find('[data-test="payment-link-url"]').exists()).toBe(false)
    expect(second.html()).not.toContain(TOKEN)
    expect(JSON.stringify(linkStatus.value)).not.toContain(TOKEN)
  })

  it('dismissing the one-time surface drops the URL for good', async () => {
    createMock.mockResolvedValue(mint())
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-create"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="payment-link-url-dismiss"]').trigger('click')

    expect(wrapper.find('[data-test="payment-link-url"]').exists()).toBe(false)
    expect(wrapper.html()).not.toContain(TOKEN)
  })

  // Fix round 1 (minor 6): the guard is in the handler, not merely in the button's loading prop.
  it('mints ONCE on a double-click', async () => {
    createMock.mockResolvedValue(mint())
    const wrapper = mountCard()
    const button = wrapper.find('[data-test="payment-link-create"]')
    button.trigger('click')
    button.trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledTimes(1)
  })

  it('regenerates ONCE on a double-clicked confirmation', async () => {
    linkStatus.value = status()
    createMock.mockResolvedValue(mint(URL_2))
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    const confirm = wrapper.find('[data-test="payment-link-regenerate-confirm"]')
    confirm.trigger('click')
    confirm.trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledTimes(1)
  })

  it('surfaces a create refusal inline', async () => {
    createMock.mockRejectedValue(new Error('This store has no public payment-link address configured.'))
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-create"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payment-link-action-error"]').text()).toContain(
      'no public payment-link address',
    )
  })
})

// ── Live link: expiry copy, exposure warning, honesty copy ─────────────────────────────────────

describe('payment-link live state copy', () => {
  it('reads "Stock reserved until …" before any provider session is exposed', () => {
    linkStatus.value = status()
    const wrapper = mountCard()

    expect(wrapper.find('[data-test="payment-link-status"]').text()).toContain('active')
    expect(wrapper.find('[data-test="payment-link-reserved"]').text()).toMatch(/Stock reserved until/)
    expect(wrapper.find('[data-test="payment-link-reserved"]').text()).toContain('2026')
    expect(wrapper.find('[data-test="payment-link-exposed-warning"]').exists()).toBe(false)
  })

  it('switches to the post-exposure warning variant once a checkout session was issued (Ruling 3)', () => {
    linkStatus.value = status({
      link: link({ provider_session_issued: true }),
      exposure: {
        reason: 'session_exposed',
        blocks_automatic_cancellation: true,
        requires_risk_acknowledgement: true,
      },
    })
    const wrapper = mountCard()

    expect(wrapper.find('[data-test="payment-link-reserved"]').exists()).toBe(false)
    const warning = wrapper.find('[data-test="payment-link-exposed-warning"]')
    expect(warning.exists()).toBe(true)
    expect(warning.text()).toMatch(/reserved/i)
    expect(warning.text()).toMatch(/risk/i)
    expect(warning.text()).toContain('2026')
  })

  it('carries the honest gateway copy: no revive/invalidate claims, only real recovery paths', () => {
    linkStatus.value = status({ link: link({ provider_session_issued: true }) })
    const note = mountCard().find('[data-test="payment-link-gateway-note"]')

    expect(note.exists()).toBe(true)
    expect(note.text()).toMatch(/mark the order paid/i)
    expect(note.text()).toMatch(/risk/i)
    expect(note.text()).not.toMatch(/revive|invalidat/i)
  })
})

// ── Regenerate + Revoke ────────────────────────────────────────────────────────────────────────

describe('payment-link regenerate and revoke', () => {
  beforeEach(() => {
    linkStatus.value = status()
  })

  it('regenerate confirms that the existing link is invalidated, then mints and shows the new URL once', async () => {
    createMock.mockResolvedValue(mint(URL_2))
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    await flushPromises()

    const dialog = wrapper.find('[data-test="payment-link-regenerate-dialog"]')
    expect(dialog.exists()).toBe(true)
    expect(dialog.text()).toMatch(/invalidat/i)

    await wrapper.find('[data-test="payment-link-regenerate-confirm"]').trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith('o1', 7)
    expect(wrapper.find('[data-test="payment-link-url"]').text()).toBe(URL_2)
    expect(wrapper.find('[data-test="payment-link-regenerate-dialog"]').exists()).toBe(false)
  })

  it('regenerate can be dismissed without minting', async () => {
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    await wrapper.find('[data-test="payment-link-regenerate-dismiss"]').trigger('click')
    await flushPromises()

    expect(createMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="payment-link-regenerate-dialog"]').exists()).toBe(false)
  })

  it('revoke confirms, calls DELETE, and clears any visible URL', async () => {
    createMock.mockResolvedValue(mint())
    revokeMock.mockResolvedValue(undefined)
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    await wrapper.find('[data-test="payment-link-regenerate-confirm"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="payment-link-url"]').exists()).toBe(true)

    await wrapper.find('[data-test="payment-link-revoke"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="payment-link-revoke-dialog"]').text()).toMatch(/stop working/i)

    await wrapper.find('[data-test="payment-link-revoke-confirm"]').trigger('click')
    await flushPromises()

    expect(revokeMock).toHaveBeenCalledWith('o1')
    expect(wrapper.find('[data-test="payment-link-url"]').exists()).toBe(false)
    expect(wrapper.html()).not.toContain(TOKEN)
  })

  it('keeps the revoke dialog open and reports the failure when the server refuses', async () => {
    revokeMock.mockRejectedValue(new Error('This order is no longer awaiting payment.'))
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-revoke"]').trigger('click')
    await wrapper.find('[data-test="payment-link-revoke-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payment-link-revoke-dialog"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payment-link-action-error"]').text()).toContain(
      'no longer awaiting payment',
    )
  })

  it('hides every mutating control for a view-only operator', () => {
    const wrapper = mountCard({}, false)
    expect(wrapper.find('[data-test="payment-link-regenerate"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payment-link-revoke"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payment-link-send-current"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payment-link-status"]').exists()).toBe(true)
  })
})

// ── Send preconditions ─────────────────────────────────────────────────────────────────────────

describe('payment-link send preconditions', () => {
  beforeEach(() => {
    linkStatus.value = status()
  })

  it('enables Send when the order has an email, the toggle is on, and email is available', () => {
    const wrapper = mountCard()
    expect(wrapper.find('[data-test="payment-link-send-regenerate"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.findAll('[data-test="payment-link-send-reason"]')).toHaveLength(0)
  })

  it('disables Send with its OWN reason for each missing precondition', async () => {
    const noEmail = mountCard({ email: null })
    expect(noEmail.find('[data-test="payment-link-send-regenerate"]').attributes('disabled')).toBeDefined()
    expect(noEmail.findAll('[data-test="payment-link-send-reason"]')).toHaveLength(1)
    expect(noEmail.find('[data-test="payment-link-send-reason"]').text()).toMatch(/email address/i)

    metaData.value = { ...metaData.value, email_available: false }
    const noChannel = mountCard()
    expect(noChannel.find('[data-test="payment-link-send-regenerate"]').attributes('disabled')).toBeDefined()
    expect(noChannel.findAll('[data-test="payment-link-send-reason"]')).toHaveLength(1)
    expect(noChannel.find('[data-test="payment-link-send-reason"]').text()).toMatch(/email channel/i)
    metaData.value = { ...metaData.value, email_available: true }

    emailSettings.value = {
      commerce_mailer_active: false,
      templates: [
        {
          template: 'payment_request',
          key: 'commerce.payment_request',
          enabled: { value: false, default: false, overridden: false },
        },
      ],
    }
    const toggledOff = mountCard()
    expect(toggledOff.find('[data-test="payment-link-send-regenerate"]').attributes('disabled')).toBeDefined()
    expect(toggledOff.findAll('[data-test="payment-link-send-reason"]')).toHaveLength(1)
    expect(toggledOff.find('[data-test="payment-link-send-reason"]').text()).toMatch(/switched off/i)
  })

  it('lists all three reasons independently when all three are missing', () => {
    metaData.value = { ...metaData.value, email_available: false }
    emailSettings.value = {
      templates: [],
      commerce_mailer_active: false,
    }
    const wrapper = mountCard({ email: null })

    expect(wrapper.findAll('[data-test="payment-link-send-reason"]')).toHaveLength(3)
  })

  // Fix round 1 (Important 3): an unresolved probe is NOT a `false`. While /commerce/meta or
  // /commerce/emails are in flight the card must not state that the store has no email channel or
  // has switched the template off — those are assertions about a misconfiguration that may not
  // exist.
  it.each([
    ['meta', () => (metaData.value = undefined)],
    ['email settings', () => (emailSettings.value = undefined)],
    ['both', () => {
      metaData.value = undefined
      emailSettings.value = undefined
    }],
  ])('says it is still checking (and asserts nothing) while %s is loading', (_label, unresolve) => {
    metaStatus.value = 'pending'
    emailSettingsStatus.value = 'pending'
    unresolve()
    const wrapper = mountCard()

    expect(wrapper.find('[data-test="payment-link-send-checking"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="payment-link-send-reason"]')).toHaveLength(0)
    expect(wrapper.find('[data-test="payment-link-send-regenerate"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="payment-link-send-current"]').attributes('disabled')).toBeDefined()
  })

  it('reports a FAILED probe as its own reason rather than checking forever', () => {
    metaData.value = undefined
    metaStatus.value = 'error'
    emailSettings.value = undefined
    emailSettingsStatus.value = 'error'
    const wrapper = mountCard()

    expect(wrapper.find('[data-test="payment-link-send-checking"]').exists()).toBe(false)
    const reasons = wrapper.findAll('[data-test="payment-link-send-reason"]').map((r) => r.text())
    expect(reasons).toHaveLength(2)
    expect(reasons.join(' ')).toMatch(/couldn’t check/i)
    expect(wrapper.find('[data-test="payment-link-send-regenerate"]').attributes('disabled')).toBeDefined()
  })
})

// ── Send this link (mode=current) ──────────────────────────────────────────────────────────────

describe('payment-link send: current', () => {
  beforeEach(() => {
    linkStatus.value = status()
    createMock.mockResolvedValue(mint())
  })

  async function withVisibleUrl(url = URL_1) {
    createMock.mockResolvedValue(mint(url))
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    await wrapper.find('[data-test="payment-link-regenerate-confirm"]').trigger('click')
    await flushPromises()
    return wrapper
  }

  it('is disabled with a reason while no URL is visible', () => {
    const wrapper = mountCard()
    expect(wrapper.find('[data-test="payment-link-send-current"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="payment-link-current-reason"]').text()).toMatch(/shown once/i)
  })

  it('submits ONLY the visible URL’s shape-validated final path segment', async () => {
    sendMock.mockResolvedValue(envelope())
    const wrapper = await withVisibleUrl(`https://shop.test/checkout/pay/${TOKEN}?ref=x`)
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()

    expect(sendMock).toHaveBeenCalledTimes(1)
    const [uuid, input, idempotencyKey] = sendMock.mock.calls[0]
    expect(uuid).toBe('o1')
    expect(input).toEqual({ mode: 'current', token: TOKEN })
    expect(typeof idempotencyKey).toBe('string')
    expect(wrapper.find('[data-test="payment-link-send-status"]').text()).toContain('sent')
  })

  it('disables current-send when the visible URL is malformed, and says why', async () => {
    const wrapper = await withVisibleUrl('not-a-url-at-all')
    expect(wrapper.find('[data-test="payment-link-send-current"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="payment-link-current-reason"]').text()).toMatch(/can’t be read|cannot be read/i)
    expect(sendMock).not.toHaveBeenCalled()
  })

  it('disables current-send when the visible URL’s final segment is not a 64-hex token', async () => {
    const wrapper = await withVisibleUrl('https://shop.test/pay/NOT-A-TOKEN')
    expect(wrapper.find('[data-test="payment-link-send-current"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="payment-link-current-reason"]').exists()).toBe(true)
  })

  it('sends ONCE on a double-click, reusing the one key for the intent', async () => {
    sendMock.mockResolvedValue(envelope())
    const wrapper = await withVisibleUrl()
    const button = wrapper.find('[data-test="payment-link-send-current"]')
    button.trigger('click')
    button.trigger('click')
    await flushPromises()

    expect(sendMock).toHaveBeenCalledTimes(1)
  })

  it('reuses the SAME Idempotency-Key when retrying the same failed send intent', async () => {
    sendMock.mockResolvedValue(
      envelope({
        http_status: 502,
        message: 'the payment link could not be emailed.',
        receipt: { ...envelope().receipt, status: 'failed', error_code: 'send_failed' },
      }),
    )
    const wrapper = await withVisibleUrl()
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()

    expect(sendMock).toHaveBeenCalledTimes(2)
    expect(sendMock.mock.calls[1][2]).toBe(sendMock.mock.calls[0][2])
  })

  it('uses a FRESH key for a new intent after a successful send', async () => {
    sendMock.mockResolvedValue(envelope())
    const wrapper = await withVisibleUrl()
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="payment-link-send-dismiss"]').trigger('click')
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()

    expect(sendMock).toHaveBeenCalledTimes(2)
    expect(sendMock.mock.calls[1][2]).not.toBe(sendMock.mock.calls[0][2])
  })

  // Fix round 1 (Important 2): the ledger fingerprint is (order, mode, recipient, ttl_days) and
  // carries NO link, so a key held across a regenerate would replay the OLD link's failure and
  // email nothing for the link now on screen. A different link is a different intent.
  it('starts a NEW send intent after a regenerate, so the old failure is never replayed', async () => {
    sendMock.mockResolvedValue(
      envelope({
        http_status: 502,
        message: 'the payment link could not be emailed.',
        receipt: { ...envelope().receipt, status: 'failed', error_code: 'send_failed' },
      }),
    )
    const wrapper = await withVisibleUrl()
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()

    createMock.mockResolvedValue(mint(URL_2))
    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    await wrapper.find('[data-test="payment-link-regenerate-confirm"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()

    expect(sendMock).toHaveBeenCalledTimes(2)
    expect(sendMock.mock.calls[1][1]).toEqual({ mode: 'current', token: TOKEN_2 })
    expect(sendMock.mock.calls[1][2]).not.toBe(sendMock.mock.calls[0][2])
  })

  it('starts a new send intent after a revoke too', async () => {
    sendMock.mockResolvedValue(
      envelope({
        http_status: 502,
        receipt: { ...envelope().receipt, status: 'failed', error_code: 'send_failed' },
      }),
    )
    revokeMock.mockResolvedValue(undefined)
    const wrapper = await withVisibleUrl()
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="payment-link-revoke"]').trigger('click')
    await wrapper.find('[data-test="payment-link-revoke-confirm"]').trigger('click')
    await flushPromises()

    createMock.mockResolvedValue(mint(URL_2))
    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    await wrapper.find('[data-test="payment-link-regenerate-confirm"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()

    expect(sendMock.mock.calls[1][2]).not.toBe(sendMock.mock.calls[0][2])
  })

  it('renders a replayed receipt with its recovery instruction', async () => {
    sendMock.mockResolvedValue(
      envelope({
        http_status: 502,
        message: 'Replayed: the payment link could not be emailed.',
        receipt: { ...envelope().receipt, status: 'failed', error_code: 'send_failed', replayed: true },
        link: null,
        recovery: 'use_a_new_idempotency_key_or_regenerate',
      }),
    )
    const wrapper = await withVisibleUrl()
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payment-link-send-replayed"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payment-link-send-recovery"]').text()).toMatch(/new .*key|regenerate/i)
  })

  it('surfaces a refusal (payment_link_changed) inline and drops the now-dead visible URL', async () => {
    sendMock.mockRejectedValue(
      new ApiError('This payment link is no longer the order’s current one.', 409, {}, {
        error: { details: { reason: 'payment_link_changed' } },
      }),
    )
    const wrapper = await withVisibleUrl()
    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payment-link-action-error"]').text()).toContain(
      'no longer the order’s current one',
    )
    // The server just said this address is not the order's link — it must stop being offered.
    expect(wrapper.find('[data-test="payment-link-url"]').exists()).toBe(false)
    expect(wrapper.html()).not.toContain(TOKEN)
    expect(wrapper.find('[data-test="payment-link-send-current"]').attributes('disabled')).toBeDefined()
  })
})

// ── Regenerate and send (mode=regenerate) ──────────────────────────────────────────────────────

describe('payment-link send: regenerate', () => {
  beforeEach(() => {
    linkStatus.value = status()
  })

  it('works with no URL on screen, behind the invalidation confirmation', async () => {
    sendMock.mockResolvedValue(envelope({ receipt: { ...envelope().receipt, mode: 'regenerate' } }))
    const wrapper = mountCard()
    expect(wrapper.find('[data-test="payment-link-url"]').exists()).toBe(false)

    await wrapper.find('[data-test="payment-link-send-regenerate"]').trigger('click')
    await flushPromises()
    const dialog = wrapper.find('[data-test="payment-link-send-regenerate-dialog"]')
    expect(dialog.exists()).toBe(true)
    expect(dialog.text()).toMatch(/invalidat/i)

    await wrapper.find('[data-test="payment-link-send-regenerate-confirm"]').trigger('click')
    await flushPromises()

    expect(sendMock).toHaveBeenCalledTimes(1)
    expect(sendMock.mock.calls[0][1]).toEqual({ mode: 'regenerate', ttl_days: 7 })
    expect(wrapper.find('[data-test="payment-link-send-status"]').text()).toContain('sent')
  })

  it('renders the delivery-failure URL copyable and explains the link is still active', async () => {
    sendMock.mockResolvedValue(
      envelope({
        http_status: 502,
        message: 'the payment link was created but could not be emailed; copy the link and send it manually.',
        receipt: { ...envelope().receipt, mode: 'regenerate', status: 'failed', error_code: 'send_failed' },
        url: URL_2,
      }),
    )
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-send-regenerate"]').trigger('click')
    await wrapper.find('[data-test="payment-link-send-regenerate-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payment-link-url"]').text()).toBe(URL_2)
    expect(wrapper.find('[data-test="payment-link-failure-note"]').text()).toMatch(/still active/i)

    await wrapper.find('[data-test="payment-link-url-copy"]').trigger('click')
    expect(clipboardWriteText).toHaveBeenCalledWith(URL_2)
  })

  it('sends ONCE on a double-clicked confirmation', async () => {
    sendMock.mockResolvedValue(envelope())
    const wrapper = mountCard()
    await wrapper.find('[data-test="payment-link-send-regenerate"]').trigger('click')
    const confirm = wrapper.find('[data-test="payment-link-send-regenerate-confirm"]')
    confirm.trigger('click')
    confirm.trigger('click')
    await flushPromises()

    expect(sendMock).toHaveBeenCalledTimes(1)
  })
})
