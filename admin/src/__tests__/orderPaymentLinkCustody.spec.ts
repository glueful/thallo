import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref, h } from 'vue'
import { PiniaColada, useMutationCache, useQueryCache } from '@pinia/colada'
import type { CommerceOrder } from '@/queries/commerceOrders'
import type OrderPaymentLinkCardType from '@/pages/commerce/orders/components/OrderPaymentLinkCard.vue'

// ── Payment links Task 13, fix round 1 (Important 1): custody against the COLADA CACHES ────────
//
// This file deliberately mocks NOTHING of `@/queries/commercePaymentLinks` — the card runs against
// the real query module, a real Pinia + PiniaColada install, and a stubbed `fetch`. That is the
// only way to see the failure this pins: `useMutation()` registers every invocation in colada's
// global `_pc_mutation` store, keeping the call's `vars` AND its resolved `data` in an entry for
// gcTime (~60s) — reachable via `useMutationCache().getEntries()`, visible in Pinia devtools, and
// outliving the component. Minting through one would park the ONE-TIME URL there, and a
// `mode=current` send would park the RAW TOKEN there as its vars, surviving "Hide", the
// `payment_link_changed` custody drop, and unmount alike. The card therefore awaits the plain
// functions instead, and this spec sweeps BOTH caches for the sentinels to keep it that way.
//
// Task 14: `commercePaymentLinks.ts` moved onto the typed `client`, which captures
// `globalThis.fetch` once at construction (module load). A static top-level import of the card
// would therefore bind it to a `client` built against whatever `fetch` existed before this file's
// `beforeEach` ever stubbed it. `OrderPaymentLinkCard` is dynamic-imported per test instead, after
// `vi.stubGlobal('fetch', ...)` and the global `vi.resetModules()` in `src/__tests__/setup.ts` —
// mirrors `commerceDraftFieldErrors.spec.ts`'s identical stub-then-dynamic-import pattern.

const TOKEN = 'c'.repeat(64)
const URL_1 = `https://shop.test/pay/${TOKEN}`

vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))
vi.mock('@/stores/tenant', () => ({
  useTenantStore: () => ({
    selectedUuid: null,
    operatorMode: false,
    clearSelection: vi.fn(),
    ensureLoaded: vi.fn(),
  }),
}))

// Only the two ambient settings probes are mocked — they are not what this file is about.
const metaData = ref({
  currency: 'USD',
  currency_exponent: 2,
  shop_index_url: '',
  low_stock_threshold: 3,
  can_view: true,
  can_manage: true,
  can_attach_user: false,
  email_available: true,
})
vi.mock('@/queries/commerceMeta', () => ({
  useCommerceMeta: () => ({ data: metaData, status: ref('success') }),
}))
const emailSettings = ref({
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
    useCommerceEmailSettings: () => ({ data: emailSettings, status: ref('success') }),
  }
})

function order(): CommerceOrder {
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
  }
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

const linkBody = {
  link_uuid: 'link-1',
  status: 'active',
  expires_at: '2026-08-19 12:00:00',
  provider_session_issued: false,
}

/** Routes the card's real requests: the status read, the mint, and the send. The typed client
 * (Task 14) hands `fetch` a `Request` object rather than a bare path string, so this reads the
 * method/pathname off it instead of the old `(path, init)` signature. */
function routeFetch() {
  return vi.fn(async (input: RequestInfo | URL) => {
    const request = input as Request
    const path = new URL(request.url, 'http://localhost').pathname
    const method = request.method.toUpperCase()
    if (path.endsWith('/payment-link/send')) {
      return jsonResponse({
        success: true,
        message: 'the payment link was emailed.',
        data: {
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
          link: linkBody,
          url: null,
          recovery: null,
        },
      })
    }
    if (method === 'POST') {
      return jsonResponse({ success: true, data: { url: URL_1, link: linkBody } }, 201)
    }
    // The status read — never a token, which is the whole reason a mint's URL is unrecoverable.
    return jsonResponse({
      success: true,
      data: {
        link: linkBody,
        exposure: {
          reason: 'active_link',
          blocks_automatic_cancellation: true,
          requires_risk_acknowledgement: false,
        },
      },
    })
  })
}

/** Captures both colada caches from a real injection context around the card. */
const caches: {
  mutation: ReturnType<typeof useMutationCache> | null
  query: ReturnType<typeof useQueryCache> | null
} = { mutation: null, query: null }

// The confirm buttons live in UModal, which teleports its content out of the wrapper's tree.
const ModalStub = {
  props: ['open'],
  template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>',
}

/** Dynamic-imports `OrderPaymentLinkCard` (and everything it transitively pulls in, including the
 * typed `client`) AFTER the caller has already stubbed `global.fetch`, so the freshly re-created
 * `client` singleton captures the stub rather than whatever `fetch` was global before this test's
 * `beforeEach` ran. Must be called after `vi.stubGlobal('fetch', ...)` in every test. */
async function mountHarness() {
  const { default: OrderPaymentLinkCard } = await import(
    '@/pages/commerce/orders/components/OrderPaymentLinkCard.vue'
  )
  const Harness = defineComponent({
    setup() {
      caches.mutation = useMutationCache()
      caches.query = useQueryCache()
      return () =>
        h(OrderPaymentLinkCard as typeof OrderPaymentLinkCardType, {
          order: order(),
          canManage: true,
        })
    },
  })
  return mount(Harness, {
    global: { plugins: [createPinia(), PiniaColada], stubs: { Modal: ModalStub } },
  })
}

/** Everything either cache is holding, as one searchable string. */
function cacheDump(): string {
  const mutationEntries = (caches.mutation?.getEntries() ?? []).map((e) => ({
    key: e.key,
    vars: e.vars,
    data: e.state.value.data,
    error: e.state.value.error,
  }))
  const queryEntries = (caches.query?.getEntries() ?? []).map((e) => ({
    key: e.key,
    data: e.state.value.data,
  }))
  return JSON.stringify({ mutationEntries, queryEntries })
}

beforeEach(() => {
  setActivePinia(createPinia())
  caches.mutation = null
  caches.query = null
  vi.stubGlobal('fetch', routeFetch())
})

describe('payment-link card custody against the colada caches', () => {
  it('leaves NO minted URL or token in the mutation cache after a mint and a current-send', async () => {
    const wrapper = await mountHarness()
    await flushPromises()

    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    await wrapper.find('[data-test="payment-link-regenerate-confirm"]').trigger('click')
    await flushPromises()
    // The URL really was minted and rendered — otherwise the sweep below would prove nothing.
    expect(wrapper.find('[data-test="payment-link-url"]').text()).toBe(URL_1)

    await wrapper.find('[data-test="payment-link-send-current"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="payment-link-send-status"]').text()).toContain('sent')

    // The send really did carry the token — on the wire, which is the only place it belongs.
    const fetchCalls = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls as [Request][]
    const sendCall = fetchCalls.find((call) =>
      new URL(call[0].url, 'http://localhost').pathname.endsWith('/payment-link/send'),
    )
    expect(await sendCall![0].clone().json()).toEqual({ mode: 'current', token: TOKEN })

    // Neither cache may hold the URL or the token — as `vars`, as `data`, or anywhere else.
    expect(caches.mutation?.getEntries()).toHaveLength(0)
    expect(cacheDump()).not.toContain(TOKEN)
    expect(cacheDump()).not.toContain(URL_1)
  })

  it('keeps the caches clean after the URL is hidden and after unmount', async () => {
    const wrapper = await mountHarness()
    await flushPromises()
    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    await wrapper.find('[data-test="payment-link-regenerate-confirm"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="payment-link-url-dismiss"]').trigger('click')
    expect(cacheDump()).not.toContain(TOKEN)

    wrapper.unmount()
    // gcTime would have kept a mutation entry alive well past this point.
    expect(cacheDump()).not.toContain(TOKEN)
    expect(cacheDump()).not.toContain(URL_1)
  })

  it('the cached STATUS query carries no token — a lost URL is genuinely unrecoverable', async () => {
    const wrapper = await mountHarness()
    await flushPromises()
    await wrapper.find('[data-test="payment-link-regenerate"]').trigger('click')
    await wrapper.find('[data-test="payment-link-regenerate-confirm"]').trigger('click')
    await flushPromises()

    const statusEntry = caches.query
      ?.getEntries()
      .find((e) => JSON.stringify(e.key).includes('commerce-order-payment-link'))
    expect(statusEntry).toBeDefined()
    expect(JSON.stringify(statusEntry!.state.value.data)).not.toContain(TOKEN)
  })
})
