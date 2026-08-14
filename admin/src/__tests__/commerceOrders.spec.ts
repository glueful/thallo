import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, toValue } from 'vue'
import type { CommerceOrder } from '@/queries/commerceOrders'
import { ORDER_SEARCH_DEFAULTS, type OrderSearchFilters, type OrderSearchPage } from '@/queries/commerceOrderSearch'

// Orders-invoices-receipts plan: this file covers the orders LIST page (table, search/filters,
// URL contract, CSV export) only — every order-DETAIL spec (header band, lifecycle actions,
// refunds, payments, notes, invoice data, sticky rail) moved to commerceOrderDetail.spec.ts as
// part of Task 9's detail hierarchy rework.

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
// Task 7: the orders list page now does its own URL sync via router.replace() — a plain vi.fn()
// spy (not a real router), same pattern as workspaceRolesSwitch.spec.ts's `useRouter: () =>
// ({ replace })`.
const replace = vi.hoisted(() => vi.fn())
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ replace }),
}))
// Nuxt UI's Link (behind UButton's `to` prop and <RouterLink>) resolves useRoute/useRouter from
// vue-router/auto — mirrors commerceProducts.spec.ts's established pattern.
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ replace }),
}))

// Task 7: orders list search/filter query + CSV export, same `{ data, status }` shape as every
// other query mock in this file; parseOrderSearchQuery/serializeOrderSearchQuery/
// ORDER_SEARCH_DEFAULTS/ExportTooLargeError stay REAL (spread from `actual`) so the hydration
// matrix genuinely exercises the real URL-contract logic through route.query, not a stub.
const orderSearchPage = ref<OrderSearchPage | undefined>(undefined)
const orderSearchStatus = ref<'pending' | 'error' | 'success'>('success')
const lastOrderSearchFilters = vi.hoisted(() => ({ current: undefined as unknown }))
const downloadOrdersCsvMock = vi.hoisted(() => vi.fn())
vi.mock('@/queries/commerceOrderSearch', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceOrderSearch')>()
  return {
    ...actual,
    useOrderSearch: (filters: unknown) => {
      lastOrderSearchFilters.current = filters
      return { data: orderSearchPage, status: orderSearchStatus }
    },
    downloadOrdersCsv: (filters: unknown) => downloadOrdersCsvMock(filters),
  }
})

import OrdersTable from '@/pages/commerce/orders/components/OrdersTable.vue'
import OrdersIndex from '@/pages/commerce/orders/index.vue'
import TablePagination from '@/components/TablePagination.vue'

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

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
// USlideover/UModal teleport their body/footer out of the wrapper — stub both to render the
// slots inline (mirrors commerceProducts.spec.ts's identical Modal + Slideover teleport stubs).
const SlideoverStub = { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' }
const pageStubs = { RouterLink: RouterLinkStub, Slideover: SlideoverStub, Modal: SlideoverStub }

/** Find the Reka SelectRoot ancestor of a USelect carrying `dataTest`, and drive it directly —
 * USelect's options render in a portal, so opening the dropdown in jsdom is unreliable; emitting
 * `update:modelValue` on the underlying SelectRoot is the established pattern
 * (commerceProducts.spec.ts).
 *
 * Reka's SelectRoot renders its portal content as the first fragment child, which mounts as a
 * comment node — Vue Test Utils' `.element` getter then falls back to the shared PHYSICAL parent
 * for EVERY sibling SelectRoot on the page, so once a page has more than one select (Task 7: the
 * orders list gained a fulfillment filter alongside status), `.element.querySelector(...)`-based
 * containment can no longer disambiguate between them — every SelectRoot's `.element` resolves to
 * the same toolbar container, which contains every button, so the lookup always matches whichever
 * SelectRoot happens to be first. Match by POSITION instead: SelectRoot instances are collected in
 * the same document order as their rendered `[role="combobox"]` trigger buttons, which DO carry
 * their own `data-test` reliably. */
function selectByTestId(wrapper: ReturnType<typeof mount>, dataTest: string) {
  const roots = wrapper.findAllComponents({ name: 'SelectRoot' })
  const triggers = Array.from(
    (wrapper.element as Element).querySelectorAll<HTMLElement>('button[role="combobox"]'),
  )
  const index = triggers.findIndex((el) => el.getAttribute('data-test') === dataTest)
  if (index === -1 || !roots[index]) throw new Error(`No SelectRoot found for [data-test="${dataTest}"]`)
  return roots[index]
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
  replace.mockClear()
  orderSearchPage.value = { orders: [], total: 0, current_page: 1, per_page: 24 }
  orderSearchStatus.value = 'success'
  lastOrderSearchFilters.current = undefined
  downloadOrdersCsvMock.mockReset()
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

// ── OrdersTable: rows (number, customer, status badge, total, date), loading/empty/error ───────

describe('OrdersTable', () => {
  const rows = [
    order({
      uuid: 'o1',
      order_number: 'ORD-1001',
      email: 'ada@example.com',
      status: 'paid',
      fulfillment_status: 'unfulfilled',
      grand_total: 5900,
    }),
    order({
      uuid: 'o2',
      order_number: 'ORD-1002',
      email: 'grace@example.com',
      status: 'fulfilled',
      fulfillment_status: 'fulfilled',
      grand_total: 12000,
    }),
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

  it('renders a fulfillment-status badge per row', () => {
    const wrapper = mount(OrdersTable, {
      props: { rows, status: 'success' },
      global: { stubs: pageStubs },
    })
    const badges = wrapper.findAll('[data-test="order-fulfillment"]')
    expect(badges).toHaveLength(2)
    expect(badges[0]!.text()).toBe('unfulfilled')
    expect(badges[1]!.text()).toBe('fulfilled')
  })

  it('links only the order-number cell and the Actions column — no other cell navigates', () => {
    const wrapper = mount(OrdersTable, {
      props: { rows, status: 'success' },
      global: { stubs: pageStubs },
    })
    const links = wrapper.findAll('a')
    // Per row: the order-number cell, the View-details action, and the Print-receipt action.
    expect(links).toHaveLength(rows.length * 3)
    const numberLinks = wrapper.findAll('[data-test="order-row"]')
    numberLinks.forEach((link, i) => {
      expect(link.text()).toBe(rows[i]!.order_number)
    })
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

  it('renders "Walk-in customer" (never a blank cell) when email is null', () => {
    const wrapper = mount(OrdersTable, {
      props: { rows: [order({ uuid: 'o3', email: null })], status: 'success' },
      global: { stubs: pageStubs },
    })
    expect(wrapper.text()).toContain('Walk-in customer')
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

  // ── Actions column (discoverability fix): the order-number cell is no longer the ONLY way
  // to reach a row's detail page — a trailing Actions column now carries an explicit View and
  // Print affordance per row too. ─────────────────────────────────────────────────────────────

  it('renders a View-details action per row, linking to the same order detail page as the order-number cell', () => {
    const wrapper = mount(OrdersTable, {
      props: { rows, status: 'success' },
      global: { stubs: pageStubs },
    })
    const views = wrapper.findAll('[data-test="order-row-view"]')
    expect(views).toHaveLength(2)
    expect(views[0]!.attributes('href')).toBe('/commerce/orders/o1')
    expect(views[1]!.attributes('href')).toBe('/commerce/orders/o2')
    expect(views[0]!.attributes('aria-label')).toBe('View order details')
  })

  it('renders a Print-receipt action per row, opening the invoice in a new tab', () => {
    const wrapper = mount(OrdersTable, {
      props: { rows, status: 'success' },
      global: { stubs: pageStubs },
    })
    const prints = wrapper.findAll('[data-test="order-row-print"]')
    expect(prints).toHaveLength(2)
    expect(prints[0]!.attributes('href')).toBe('/commerce/orders/o1/invoice')
    expect(prints[1]!.attributes('href')).toBe('/commerce/orders/o2/invoice')
    prints.forEach((link) => {
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toBe('noopener')
      expect(link.attributes('aria-label')).toBe('Print receipt')
    })
  })

  it('renders an "Actions" column header', () => {
    const wrapper = mount(OrdersTable, {
      props: { rows, status: 'success' },
      global: { stubs: pageStubs },
    })
    expect(wrapper.text()).toContain('Actions')
  })
})

// ── Orders list page (Task 7): search, filters, URL contract, CSV export ────────────────────

describe('commerce orders list page', () => {
  function resolvedFilters(): OrderSearchFilters {
    return toValue(lastOrderSearchFilters.current) as OrderSearchFilters
  }

  it('feeds useOrderSearch the exact ORDER_SEARCH_DEFAULTS when the URL has no query at all', async () => {
    mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(resolvedFilters()).toEqual(ORDER_SEARCH_DEFAULTS)
  })

  it('renders the orders table with the fetched rows', async () => {
    orderSearchPage.value = { orders: [order({ uuid: 'o1' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="order-row"]')).toHaveLength(1)
  })

  it('shows pagination controls only once there is at least one order', async () => {
    orderSearchPage.value = { orders: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.text()).not.toContain('Rows per page')

    orderSearchPage.value = { orders: [order()], total: 1, current_page: 1, per_page: 24 }
    await flushPromises()
    expect(wrapper.text()).toContain('Rows per page')
  })

  // ── URL hydration matrix (spec §2.4): only valid values survive route.query on mount ────────

  it('hydrates every valid filter from the URL on mount', async () => {
    routeState.query = {
      status: 'paid',
      fulfillment: 'fulfilled',
      placed_from: '2026-01-01',
      placed_to: '2026-01-31',
      q: 'ORD-1',
      page: '3',
      per_page: '50',
    }
    mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(resolvedFilters()).toEqual({
      status: 'paid',
      fulfillment: 'fulfilled',
      placedFrom: '2026-01-01',
      placedTo: '2026-01-31',
      q: 'ORD-1',
      page: 3,
      perPage: 50,
    })
  })

  it.each([
    [{ status: 'bogus' }, 'status', null],
    [{ fulfillment: 'bogus' }, 'fulfillment', null],
    [{ placed_from: '2026-02-30' }, 'placedFrom', null],
    [{ placed_to: '2026-13-01' }, 'placedTo', null],
    [{ page: '0' }, 'page', 1],
    [{ per_page: '101' }, 'perPage', 25],
  ])('discards an invalid %j from the URL, falling back to the default', async (query, field, expected) => {
    routeState.query = query as Record<string, string>
    mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect((resolvedFilters() as unknown as Record<string, unknown>)[field as string]).toBe(expected)
  })

  it('preserves a hydrated non-default page across watcher installation', async () => {
    routeState.query = { page: '3' }
    mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    // Would regress to 1 if the page-reset watcher fired on the hydration assignment itself.
    expect(resolvedFilters().page).toBe(3)
  })

  // ── Debounce: q only ─────────────────────────────────────────────────────────────────────

  it('debounces the search input 300ms before it reaches the query filters', async () => {
    vi.useFakeTimers()
    try {
      const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
      await flushPromises()

      await wrapper.find('[data-test="order-search"]').setValue('ORD-9')
      expect(resolvedFilters().q).toBe('')

      await vi.advanceTimersByTimeAsync(300)
      expect(resolvedFilters().q).toBe('ORD-9')
    } finally {
      vi.useRealTimers()
    }
  })

  it('applies a status filter change immediately, without waiting for the debounce', async () => {
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    selectByTestId(wrapper, 'order-status-filter').vm.$emit('update:modelValue', 'fulfilled')
    await flushPromises()
    expect(resolvedFilters().status).toBe('fulfilled')
  })

  it('applies a fulfillment filter change immediately', async () => {
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    selectByTestId(wrapper, 'order-fulfillment-filter').vm.$emit('update:modelValue', 'partial')
    await flushPromises()
    expect(resolvedFilters().fulfillment).toBe('partial')
  })

  // ── Page reset semantics ─────────────────────────────────────────────────────────────────

  it('resets to page 1 when a user changes a filter other than page', async () => {
    routeState.query = { page: '3' }
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(resolvedFilters().page).toBe(3)

    selectByTestId(wrapper, 'order-status-filter').vm.$emit('update:modelValue', 'paid')
    await flushPromises()
    expect(resolvedFilters().page).toBe(1)
  })

  it('does not reset the page on page navigation itself', async () => {
    orderSearchPage.value = { orders: [order()], total: 100, current_page: 1, per_page: 24 }
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.findComponent(TablePagination).vm.$emit('update:page', 2)
    await flushPromises()
    expect(resolvedFilters().page).toBe(2)
  })

  // ── Canonical URL sync + loop guard ──────────────────────────────────────────────────────

  it('replaces the URL with the canonical (defaults-omitted) query when a filter changes', async () => {
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    replace.mockClear()

    selectByTestId(wrapper, 'order-status-filter').vm.$emit('update:modelValue', 'paid')
    await flushPromises()

    expect(replace).toHaveBeenCalledTimes(1)
    expect(replace).toHaveBeenCalledWith({ query: { status: 'paid' } })
  })

  it('does not call router.replace again once filters revert to match the current URL', async () => {
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    replace.mockClear()

    selectByTestId(wrapper, 'order-status-filter').vm.$emit('update:modelValue', 'paid')
    await flushPromises()
    expect(replace).toHaveBeenCalledTimes(1)

    // Reverting back to the ALL sentinel reproduces the ORIGINAL (empty) query — the equality
    // guard must skip a redundant replace rather than looping.
    selectByTestId(wrapper, 'order-status-filter').vm.$emit('update:modelValue', 'all')
    await flushPromises()
    expect(replace).toHaveBeenCalledTimes(1)
  })

  // ── Export: can_view gating, 422 -> warning toast, other errors -> error toast ─────────────

  it('shows the export button when can_view is true', async () => {
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="orders-export"]').exists()).toBe(true)
  })

  it('hides the export button when can_view is false', async () => {
    metaData.value = { ...metaData.value, can_view: false }
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="orders-export"]').exists()).toBe(false)
  })

  // ── "Create order" (Task 14: admin-order-creation) — manage-graded ─────────────────────────

  it('shows the "Create order" action when can_manage is true, linking to the draft workspace', async () => {
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    const button = wrapper.find('[data-test="orders-create"]')
    expect(button.exists()).toBe(true)
    expect(button.attributes('href')).toBe('/commerce/orders/create')
  })

  it('hides "Create order" when can_manage is false', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="orders-create"]').exists()).toBe(false)
  })

  // ── "Drafts" nav entry (Task 15: admin-order-creation cycle 2) — manage-graded, same as
  // "Create order" ─────────────────────────────────────────────────────────────────────────────

  it('shows a "Drafts" tab linking to the drafts view when can_manage is true', async () => {
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    const tab = wrapper.find('[data-test="orders-drafts-tab"]')
    expect(tab.exists()).toBe(true)
    expect(tab.attributes('href')).toBe('/commerce/orders/drafts')
  })

  it('hides the "Drafts" tab when can_manage is false', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="orders-drafts-tab"]').exists()).toBe(false)
  })

  it('surfaces a 422 export-too-large rejection as a warning toast with the exact server message', async () => {
    const { ExportTooLargeError } = await import('@/queries/commerceOrderSearch')
    downloadOrdersCsvMock.mockRejectedValue(
      new ExportTooLargeError('Export exceeds 10,000 rows — narrow your filters.'),
    )
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="orders-export"]').trigger('click')
    await flushPromises()

    expect(downloadOrdersCsvMock).toHaveBeenCalledTimes(1)
    expect(notify.warning).toHaveBeenCalledTimes(1)
    expect(notify.warning.mock.calls[0]![0]).toContain('Export exceeds 10,000 rows')
    expect(notify.error).not.toHaveBeenCalled()
  })

  it('surfaces a non-422 export failure as an error toast, never a warning', async () => {
    downloadOrdersCsvMock.mockRejectedValue(new Error('network down'))
    const wrapper = mount(OrdersIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="orders-export"]').trigger('click')
    await flushPromises()

    expect(notify.warning).not.toHaveBeenCalled()
    expect(notify.error).toHaveBeenCalledTimes(1)
  })
})

describe('commerce orders route gating', () => {
  const ROOT = process.cwd()

  it('the orders list route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/orders/index.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })
})
