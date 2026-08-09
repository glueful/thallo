import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'

// Regression for a review-caught bug: useCommerceOrderMutations()'s `invalidate()` used to target
// qk.commerceOrders() (`['commerce-orders']`), a DIFFERENT prefix than the orders list's real live
// query — useOrderSearch() in commerceOrderSearch.ts, keyed `['commerce','orders','search',...]`.
// pinia-colada's `isSubsetOf` match is element-wise from index 0 (`'commerce-orders' !==
// 'commerce'`), so that invalidation call silently matched NOTHING after Task 7 replaced the old
// list query — cancel/markPaid/fulfill/refund never refreshed the list, which kept showing stale
// status/fulfillment/totals until an unrelated refetch.
//
// commerceOrdersInvalidation.spec.ts mocks `@pinia/colada` wholesale and only asserts the literal
// call ARGS passed to a stubbed `invalidateQueries` — it can prove "invalidate() was called with
// key X" but can never prove X actually matches anything live, so it could not catch this. This
// file installs a REAL Pinia + PiniaColada cache (mirrors analyticsEnabledGate.spec.ts's
// established pattern) with an ACTIVE useOrderSearch query mounted, then proves a lifecycle
// mutation settling actually triggers a real refetch of that query — the only way to prove the two
// key prefixes genuinely match.

vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: 'test-token', refresh: vi.fn(), clear: vi.fn() }),
}))

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } })
}

function canceledOrderBody() {
  return {
    success: true,
    message: 'Order canceled',
    data: {
      uuid: 'o1',
      order_number: 'ORD-1001',
      status: 'canceled',
      fulfillment_status: 'unfulfilled',
      email: 'buyer@example.com',
      user_uuid: null,
      currency: 'USD',
      subtotal: 0,
      discount_total: 0,
      shipping_total: 0,
      tax_total: 0,
      grand_total: 0,
      refunded_total: 0,
      discount_code: null,
      shipping_method: null,
      addresses: null,
      placed_at: null,
      created_at: null,
      updated_at: null,
    },
  }
}

describe('orders list cache is actually invalidated by lifecycle mutations (real cache, not mocked)', () => {
  beforeEach(() => {
    vi.resetModules()
    authFetch.mockReset().mockResolvedValue({ data: [], current_page: 1, per_page: 24, total: 0 })
    // The real `cancelOrder()` goes through the typed openapi-fetch `client`, which calls global
    // `fetch` directly (mirrors commerceOrders.spec.ts's established stub-then-dynamic-import
    // pattern for exercising the real client against a stubbed network).
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(canceledOrderBody())))
  })

  it('a cancel mutation refetches the ACTIVE orders-search list query', async () => {
    const { useOrderSearch, ORDER_SEARCH_DEFAULTS } = await import('@/queries/commerceOrderSearch')
    const { useCommerceOrderMutations } = await import('@/queries/commerceOrders')

    let mutations: ReturnType<typeof useCommerceOrderMutations>
    const Host = defineComponent({
      setup() {
        useOrderSearch(ref({ ...ORDER_SEARCH_DEFAULTS }))
        mutations = useCommerceOrderMutations()
        return () => h('div')
      },
    })
    // Pinia must be installed before PiniaColada (analyticsEnabledGate.spec.ts precedent).
    mount(Host, { global: { plugins: [createPinia(), PiniaColada] } })
    await flushPromises()

    // The list's initial fetch, while the query is freshly mounted and active.
    expect(authFetch).toHaveBeenCalledTimes(1)

    await mutations!.cancel.mutateAsync('o1')
    await flushPromises()

    // If `invalidate()` still targeted the retired, mismatched key prefix, this would stay at 1 —
    // the exact silent failure mode the review caught.
    expect(authFetch).toHaveBeenCalledTimes(2)
  })
})
