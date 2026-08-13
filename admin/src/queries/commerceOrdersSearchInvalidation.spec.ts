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

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } })
}

function emptySearchBody() {
  return { success: true, message: 'Orders retrieved', data: [], current_page: 1, per_page: 24, total: 0 }
}

/** `fetch` is shared by BOTH `useOrderSearch()`'s list query and `cancelOrder()`'s mutation now
 * that `fetchOrderSearch` also rides the typed `client` (Task 16 regeneration) — count only the
 * `/orders/search` calls so the assertions still isolate "did the list actually refetch",
 * regardless of the cancel POST sharing the same stubbed `fetch`. */
function requestUrl(input: unknown): string {
  if (typeof input === 'string') return input
  if (input instanceof URL) return input.toString()
  return (input as Request).url
}
function searchCallCount(mock: ReturnType<typeof vi.fn>): number {
  return mock.mock.calls.filter(([req]) => requestUrl(req).includes('/orders/search')).length
}

// The typed `client`'s auth middleware does `await import('@/stores/session')` (and,
// tenant-scoped, `@/stores/tenant`) on every request — genuine dynamic imports that, combined with
// this file's own per-test `vi.resetModules()`, outlast a single `flushPromises()` tick (mirrors
// commerceDraftFieldErrors.spec.ts's identical note). `vi.waitFor` polls with real timers until the
// assertion holds, instead of guessing how many `flushPromises()` calls are enough.
async function waitFor(assertion: () => void): Promise<void> {
  await vi.waitFor(assertion, { timeout: 2000, interval: 20 })
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
  let fetchMock: ReturnType<typeof vi.fn>

  beforeEach(() => {
    vi.resetModules()
    // Both `useOrderSearch()`'s list query and `cancelOrder()`'s mutation go through the typed
    // openapi-fetch `client`, which calls global `fetch` directly (mirrors commerceOrders.spec.ts's
    // established stub-then-dynamic-import pattern for exercising the real client against a
    // stubbed network) — route by URL so each gets its own well-formed envelope.
    fetchMock = vi.fn((input: unknown) =>
      Promise.resolve(
        requestUrl(input).includes('/cancel')
          ? jsonResponse(canceledOrderBody())
          : jsonResponse(emptySearchBody()),
      ),
    )
    vi.stubGlobal('fetch', fetchMock)
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
    await waitFor(() => expect(searchCallCount(fetchMock)).toBe(1))

    await mutations!.cancel.mutateAsync('o1')
    await flushPromises()

    // If `invalidate()` still targeted the retired, mismatched key prefix, this would stay at 1 —
    // the exact silent failure mode the review caught.
    await waitFor(() => expect(searchCallCount(fetchMock)).toBe(2))
  })
})
