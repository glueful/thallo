import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { CommerceDraft, CommerceDraftListPage } from '@/queries/commerceDrafts'

// Task 15 (admin-order-creation cycle 2): the drafts LIST view — the ONE draft-inclusive listing
// anywhere in the admin SPA (the ordinary orders list stays draft-blind, server-enforced, and is
// covered separately in commerceOrders.spec.ts). Covers: list rendering (including the
// order_number placeholder and the walk-in customer fallback), Resume navigating with the exact
// uuid and never calling `createDraft`, cancel's confirm-then-refresh flow, and can_manage gating
// of the Resume/Cancel controls.

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

const draftsPage = ref<CommerceDraftListPage | undefined>(undefined)
const draftsStatus = ref<'pending' | 'error' | 'success'>('success')
const refetchMock = vi.hoisted(() => vi.fn())
const cancelMock = vi.hoisted(() => vi.fn())
const createDraftMock = vi.hoisted(() => vi.fn())
const lastFiltersArg = vi.hoisted(() => ({ current: undefined as unknown }))

vi.mock('@/queries/commerceDrafts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceDrafts')>()
  return {
    ...actual,
    useDraftsList: (filters: unknown) => {
      lastFiltersArg.current = filters
      return { data: draftsPage, status: draftsStatus, refetch: refetchMock }
    },
    useCommerceDraftMutations: () => ({
      cancel: { mutateAsync: cancelMock, isLoading: ref(false) },
    }),
    // Never actually called by this page — asserted directly in the "zero create calls" test.
    createDraft: (...a: unknown[]) => createDraftMock(...a),
  }
})

import DraftsList from '@/pages/commerce/orders/drafts.vue'

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
const pageStubs = { RouterLink: RouterLinkStub }

function draft(overrides: Partial<CommerceDraft> = {}): CommerceDraft {
  return {
    uuid: 'd1',
    order_number: null,
    status: 'draft',
    fulfillment_status: 'unfulfilled',
    email: null,
    user_uuid: null,
    customer_name: null,
    phone_normalized: null,
    phone_display: null,
    fulfillment_mode: 'in_store',
    origin: 'admin',
    currency: 'USD',
    subtotal: 1000,
    discount_total: 0,
    shipping_total: 0,
    tax_total: 0,
    grand_total: 1000,
    refunded_total: 0,
    discount_code: null,
    shipping_method: null,
    addresses: null,
    placed_at: null,
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-02 00:00:00',
    draft_revision: 1,
    lines: [],
    ...overrides,
  }
}

function page(overrides: Partial<CommerceDraftListPage> = {}): CommerceDraftListPage {
  return {
    drafts: [draft()],
    total: 1,
    current_page: 1,
    per_page: 25,
    ...overrides,
  }
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
  draftsPage.value = page()
  draftsStatus.value = 'success'
  refetchMock.mockReset()
  cancelMock.mockReset()
  createDraftMock.mockReset()
  lastFiltersArg.current = undefined
})

describe('drafts list page', () => {
  it('shows the loading state', () => {
    draftsStatus.value = 'pending'
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    expect(wrapper.find('[data-test="drafts-loading"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    draftsStatus.value = 'error'
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    expect(wrapper.find('[data-test="drafts-error"]').exists()).toBe(true)
  })

  it('shows the empty state when there are no drafts', () => {
    draftsPage.value = page({ drafts: [], total: 0 })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    expect(wrapper.find('[data-test="drafts-empty"]').exists()).toBe(true)
  })

  it('renders a placeholder for the (always-null) order number', async () => {
    draftsPage.value = page({ drafts: [draft({ uuid: 'd1', order_number: null })] })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    const row = wrapper.find('[data-test="draft-row"]')
    expect(row.find('[data-test="draft-number"]').text()).not.toBe('')
    expect(row.find('[data-test="draft-number"]').text()).not.toContain('null')
  })

  it('shows customer name, then email, then falls back to "Walk-in customer"', async () => {
    draftsPage.value = page({
      drafts: [
        draft({ uuid: 'd1', customer_name: 'Ada Lovelace', email: 'ada@example.com' }),
        draft({ uuid: 'd2', customer_name: null, email: 'grace@example.com' }),
        draft({ uuid: 'd3', customer_name: null, email: null }),
      ],
    })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    const customers = wrapper.findAll('[data-test="draft-customer"]')
    expect(customers).toHaveLength(3)
    expect(customers[0]!.text()).toBe('Ada Lovelace')
    expect(customers[1]!.text()).toBe('grace@example.com')
    expect(customers[2]!.text()).toBe('Walk-in customer')
  })

  it('shows created/updated timestamps', async () => {
    draftsPage.value = page({
      drafts: [draft({ uuid: 'd1', created_at: '2026-01-01 00:00:00', updated_at: '2026-01-05 00:00:00' })],
    })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="draft-created"]').text()).toContain('Created')
    expect(wrapper.find('[data-test="draft-updated"]').text()).toContain('Updated')
  })

  it('shows an advisory total, and NO line-count cell (the list endpoint never hydrates lines)', async () => {
    draftsPage.value = page({
      drafts: [
        draft({
          uuid: 'd1',
          grand_total: 12345,
          // Mirrors production: `AdminOrderDraftController::index()` never hydrates `lines` for
          // the list endpoint, so this stays `[]` even for a real draft with actual line items.
          lines: [],
        }),
      ],
    })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="draft-total"]').text()).toContain('$123.45')
    // No count cell at all — rendering `lines.length` here would be a confidently WRONG "0
    // item(s)" for every real draft, not merely an approximate figure (review fix).
    expect(wrapper.find('[data-test="draft-line-count"]').exists()).toBe(false)
  })

  // ── Resume: exact uuid, never creates ───────────────────────────────────────────────────────

  it('Resume links straight to the workspace with the exact draft uuid, never creating a new draft', async () => {
    draftsPage.value = page({ drafts: [draft({ uuid: 'abc-123' })] })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    const resume = wrapper.find('[data-test="draft-resume"]')
    expect(resume.exists()).toBe(true)
    expect(resume.attributes('href')).toBe('/commerce/orders/create?draft=abc-123')

    await resume.trigger('click')
    await flushPromises()
    expect(createDraftMock).not.toHaveBeenCalled()
  })

  it('hides Resume and Cancel when can_manage is false, while the list itself stays visible', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    draftsPage.value = page({ drafts: [draft({ uuid: 'd1' })] })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="draft-row"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="draft-resume"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="draft-cancel"]').exists()).toBe(false)
  })

  // ── Cancel: confirm-gated, then a list refresh ──────────────────────────────────────────────

  it('cancel requires confirmation before calling the mutation, then refreshes the list', async () => {
    draftsPage.value = page({ drafts: [draft({ uuid: 'd1' })] })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="draft-cancel"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="draft-cancel-panel"]').exists()).toBe(true)
    expect(cancelMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="draft-cancel-confirm"]').trigger('click')
    await flushPromises()

    expect(cancelMock).toHaveBeenCalledTimes(1)
    expect(cancelMock).toHaveBeenCalledWith('d1')
    expect(refetchMock).toHaveBeenCalledTimes(1)
  })

  it('dismissing the cancel confirm never calls the mutation or refetches', async () => {
    draftsPage.value = page({ drafts: [draft({ uuid: 'd1' })] })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="draft-cancel"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="draft-cancel-dismiss"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="draft-cancel-panel"]').exists()).toBe(false)
    expect(cancelMock).not.toHaveBeenCalled()
    expect(refetchMock).not.toHaveBeenCalled()
  })

  it('surfaces a cancel rejection inline and keeps the confirm panel open for retry', async () => {
    const { ApiError } = await import('@/api/errors')
    cancelMock.mockRejectedValue(new ApiError('This draft was already canceled.', 409, {}, null))
    draftsPage.value = page({ drafts: [draft({ uuid: 'd1' })] })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="draft-cancel"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="draft-cancel-confirm"]').trigger('click')
    await flushPromises()

    const error = wrapper.find('[data-test="draft-cancel-error"]')
    expect(error.exists()).toBe(true)
    expect(error.text()).toContain('This draft was already canceled.')
    expect(wrapper.find('[data-test="draft-cancel-panel"]').exists()).toBe(true)
    expect(refetchMock).not.toHaveBeenCalled()
  })

  it('shows pagination once there are drafts', async () => {
    draftsPage.value = page({ drafts: [draft()], total: 40, per_page: 25, current_page: 1 })
    const wrapper = mount(DraftsList, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('of 40 drafts')
  })
})

describe('commerce drafts route gating', () => {
  const ROOT = process.cwd()

  it('the drafts route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/orders/drafts.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })
})
