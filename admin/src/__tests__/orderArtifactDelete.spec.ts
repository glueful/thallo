import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { PiniaColada, useQueryCache } from '@pinia/colada'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import type { CommerceOrder } from '@/queries/commerceOrders'
import type OrdersTableType from '@/pages/commerce/orders/components/OrdersTable.vue'

// ── Draft-artifact delete (cleanup-train Task 9; engine Task 5) ────────────────────────────────
//
// `DELETE /commerce/orders/{uuid}/artifact` is the engine's ONLY hard delete of an order row, and
// it is legal for exactly one shape of row: `order_number IS NULL AND status = 'canceled'` — an
// abandoned draft that was canceled, which the database itself proves never touched money (no
// number was ever issued, so there can be no payment and no invoice). Everything else is a typed
// 409 `order_not_deletable`; an unknown or cross-tenant uuid is a 404.
//
// The SPA's half of that contract is not offering what the server will refuse: the trash action
// renders ONLY on a numberless row (the orders list is draft-blind, so a numberless row there IS
// a canceled artifact), behind a confirmation that states plainly what is destroyed and that it
// cannot be undone. The server stays authoritative regardless — a 409 surfaces with the server's
// OWN reason rather than a guess, and a 404 means the row is already gone, which is exactly the
// post-condition the operator asked for.
//
// Nothing of `@/queries/commerceOrders` is mocked here: only `global.fetch` is stubbed, so a
// click travels the real mutation -> real `deleteOrderArtifact()` -> real typed client -> real
// `toApiError()`. The typed client captures `globalThis.fetch` at construction, so the table (and
// everything it transitively imports) is dynamic-imported per test after the stub — the
// established stub-then-dynamic-import pattern (commerceDraftFieldErrors.spec.ts).

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

const metaData = {
  currency: 'USD',
  currency_exponent: 2,
  shop_index_url: '',
  low_stock_threshold: 3,
  can_view: true,
  can_manage: true,
}
vi.mock('@/queries/commerceMeta', () => ({
  useCommerceMeta: () => ({ data: { value: metaData } }),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

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
    shipping_total: 0,
    tax_total: 0,
    grand_total: 5000,
    refunded_total: 0,
    discount_code: null,
    shipping_method: null,
    addresses: null,
    placed_at: '2026-01-01 00:00:00',
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    lines: [],
    events: [],
    ...overrides,
  }
}

/** The row the delete exists for: canceled, and never numbered. */
function artifact(overrides: Partial<CommerceOrder> = {}): CommerceOrder {
  return order({ uuid: 'a1', order_number: null, status: 'canceled', email: null, ...overrides })
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
const ModalStub = {
  props: ['open'],
  template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>',
}
const stubs = { RouterLink: RouterLinkStub, Modal: ModalStub, Slideover: ModalStub }

/** Captures the query cache the table's own mutation invalidates through. */
const caches: { query: ReturnType<typeof useQueryCache> | null } = { query: null }

async function mountTable(rows: CommerceOrder[], canManage = true) {
  const { default: OrdersTable } = await import(
    '@/pages/commerce/orders/components/OrdersTable.vue'
  )
  const Harness = defineComponent({
    setup() {
      caches.query = useQueryCache()
      vi.spyOn(caches.query, 'invalidateQueries')
      return () =>
        h(OrdersTable as typeof OrdersTableType, { rows, status: 'success', canManage } as never)
    },
  })
  return mount(Harness, { global: { plugins: [createPinia(), PiniaColada], stubs } })
}

/** Every key the table asked the cache to invalidate, flattened for containment checks. */
function invalidatedKeys(): string[] {
  const spy = caches.query?.invalidateQueries as unknown as { mock?: { calls: unknown[][] } }
  return (spy?.mock?.calls ?? []).map((call) => JSON.stringify((call[0] as { key: unknown }).key))
}

beforeEach(() => {
  setActivePinia(createPinia())
  caches.query = null
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

// ── The gating matrix: a numbered row NEVER offers the delete ──────────────────────────────────

describe('OrdersTable draft-artifact delete gating', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ success: true })))
  })

  it.each([
    ['numberless canceled artifact', null, 'canceled', true],
    ['numbered canceled order (real money history)', 'ORD-1001', 'canceled', false],
    ['numbered paid order', 'ORD-1002', 'paid', false],
    ['numbered pending_payment order', 'ORD-1003', 'pending_payment', false],
    ['numbered refunded order', 'ORD-1004', 'refunded', false],
    ['numbered fulfilled order', 'ORD-1005', 'fulfilled', false],
  ])('%s renders the delete action: %s', async (_label, orderNumber, orderStatus, offered) => {
    const wrapper = await mountTable([
      order({ uuid: 'x1', order_number: orderNumber as string | null, status: orderStatus }),
    ])
    expect(wrapper.find('[data-test="order-row-delete"]').exists()).toBe(offered)
  })

  it('offers it on the numberless row ONLY, in a mixed page', async () => {
    const wrapper = await mountTable([
      order({ uuid: 'o1', order_number: 'ORD-1001' }),
      artifact({ uuid: 'a1' }),
      order({ uuid: 'o2', order_number: 'ORD-1002', status: 'canceled' }),
    ])
    const buttons = wrapper.findAll('[data-test="order-row-delete"]')
    expect(buttons).toHaveLength(1)
    expect(buttons[0]!.attributes('aria-label')).toBe('Delete this draft artifact')
  })

  // The route is manage-graded server-side; a view-only operator is never offered it.
  it('offers nothing destructive to a view-only operator', async () => {
    const wrapper = await mountTable([artifact()], false)
    expect(wrapper.find('[data-test="order-row-delete"]').exists()).toBe(false)
    // The row itself is still listed and still reachable — only the delete is withheld.
    expect(wrapper.find('[data-test="order-row-view"]').exists()).toBe(true)
  })

  it('renders the numberless row without pretending it has a number', async () => {
    const wrapper = await mountTable([artifact()])
    expect(wrapper.find('[data-test="order-row"]').text()).toMatch(/no (order )?number/i)
    // Still reachable: a numberless artifact is a real row with a detail page.
    expect(wrapper.find('[data-test="order-row"]').attributes('href')).toBe('/commerce/orders/a1')
  })

  it('never fires a request from the icon alone — the confirmation owns the delete', async () => {
    const wrapper = await mountTable([artifact()])
    await wrapper.find('[data-test="order-row-delete"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="order-artifact-delete-dialog"]').exists()).toBe(true)
    expect(globalThis.fetch).not.toHaveBeenCalled()
  })
})

// ── The confirmation, and the three answers the endpoint can give ──────────────────────────────

describe('OrdersTable draft-artifact delete flow', () => {
  async function openConfirm(rows: CommerceOrder[] = [artifact()]) {
    const wrapper = await mountTable(rows)
    await wrapper.find('[data-test="order-row-delete"]').trigger('click')
    return wrapper
  }

  it('states exactly what is destroyed, and that nothing about it exists to lose', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ success: true })))
    const wrapper = await openConfirm()
    expect(wrapper.find('[data-test="order-artifact-delete-dialog"]').text()).toContain(
      'This never-completed draft has no order number, payments, or invoices — delete permanently?',
    )
  })

  it('dismisses without deleting', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ success: true })))
    const wrapper = await openConfirm()
    await wrapper.find('[data-test="order-artifact-delete-dismiss"]').trigger('click')
    await flushPromises()

    expect(globalThis.fetch).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="order-artifact-delete-dialog"]').exists()).toBe(false)
  })

  it('DELETEs the artifact path on confirm and refreshes the list', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ success: true, data: { order_uuid: 'a1' } })),
    )
    const wrapper = await openConfirm()
    await wrapper.find('[data-test="order-artifact-delete-confirm"]').trigger('click')
    await flushPromises()

    const request = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]![0] as Request
    expect(request.method).toBe('DELETE')
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/orders/a1/artifact',
    )
    // The list query is what renders this row — it must be re-read, or the row lingers.
    expect(invalidatedKeys().join('|')).toContain('search')
    expect(wrapper.find('[data-test="order-artifact-delete-dialog"]').exists()).toBe(false)
    expect(notify.error).not.toHaveBeenCalled()
  })

  it('deletes ONCE on a double-clicked confirmation', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ success: true })))
    const wrapper = await openConfirm()
    const confirm = wrapper.find('[data-test="order-artifact-delete-confirm"]')
    confirm.trigger('click')
    confirm.trigger('click')
    await flushPromises()

    expect(globalThis.fetch).toHaveBeenCalledTimes(1)
  })

  // The precheck is a courtesy; the DB's compare-and-delete is the authority. A row that stopped
  // being an artifact between render and click is refused, and the operator is told WHY in the
  // server's own words rather than a generic failure.
  it('surfaces the server’s 409 reason instead of pretending the row went away', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse(
          {
            success: false,
            message: 'Cancel this draft before deleting it.',
            error: {
              code: 409,
              details: { reason: 'order_not_deletable', status: 'draft' },
            },
          },
          409,
        ),
      ),
    )
    const wrapper = await openConfirm()
    await wrapper.find('[data-test="order-artifact-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(notify.error).toHaveBeenCalledTimes(1)
    const [thrown] = notify.error.mock.calls[0]!
    expect((thrown as { message: string }).message).toBe('Cancel this draft before deleting it.')
    expect(wrapper.find('[data-test="order-artifact-delete-dialog"]').exists()).toBe(false)
  })

  // 404 is the post-condition, not a failure: a concurrent operator or the purge sweep got there
  // first. The remedy is the refresh that was going to happen anyway.
  it('treats a 404 as already-gone: refreshes, and raises no error toast', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ success: false, message: 'Resource not found.' }, 404)),
    )
    const wrapper = await openConfirm()
    await wrapper.find('[data-test="order-artifact-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(notify.error).not.toHaveBeenCalled()
    expect(invalidatedKeys().join('|')).toContain('search')
    expect(wrapper.find('[data-test="order-artifact-delete-dialog"]').exists()).toBe(false)
  })

  it('reports an unexpected failure as an ordinary error toast', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ success: false, message: 'Server exploded.' }, 500)),
    )
    const wrapper = await openConfirm()
    await wrapper.find('[data-test="order-artifact-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(notify.error).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="order-artifact-delete-dialog"]').exists()).toBe(false)
  })
})

// ── The query layer, straight through ──────────────────────────────────────────────────────────

describe('deleteOrderArtifact', () => {
  it('resolves on 200 and issues exactly one DELETE', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ success: true, data: { order_uuid: 'a1' } })),
    )
    const { deleteOrderArtifact } = await import('@/queries/commerceOrders')
    await expect(deleteOrderArtifact('a1')).resolves.toBeUndefined()
    expect(globalThis.fetch).toHaveBeenCalledTimes(1)
  })

  it('resolves on 404 — the row being absent IS what the caller asked for', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ success: false, message: 'Resource not found.' }, 404)),
    )
    const { deleteOrderArtifact } = await import('@/queries/commerceOrders')
    await expect(deleteOrderArtifact('gone')).resolves.toBeUndefined()
  })

  it('throws the typed 409, carrying the engine’s machine-readable reason', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse(
          {
            success: false,
            message: 'This order has been placed and can never be deleted.',
            error: { code: 409, details: { reason: 'order_not_deletable', status: 'paid' } },
          },
          409,
        ),
      ),
    )
    const { deleteOrderArtifact, isOrderNotDeletable } = await import('@/queries/commerceOrders')
    const { apiErrorDetails } = await import('@/api/errors')
    const caught = await deleteOrderArtifact('o1').then(
      () => null,
      (e: unknown) => e,
    )

    expect(caught).not.toBeNull()
    expect((caught as { status: number }).status).toBe(409)
    expect((caught as { message: string }).message).toBe(
      'This order has been placed and can never be deleted.',
    )
    expect(apiErrorDetails(caught)?.reason).toBe('order_not_deletable')
    expect(isOrderNotDeletable(caught)).toBe(true)
    expect(isOrderNotDeletable(new Error('x'))).toBe(false)
  })
})
