import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, toValue, nextTick } from 'vue'
import { ApiError } from '@/api/errors'
import type { CommerceDraft, CommerceFinalizedOrder } from '@/queries/commerceDrafts'
import type { CommerceProduct, CommerceVariant } from '@/queries/commerceCatalog'
import type { UserRow } from '@/queries/users'

// Task 14 (admin-order-creation): the walk-in order draft workspace. Covers the brief's full RED
// matrix — route custody (create-once/replace, refresh-never-creates, Resume loads the exact
// uuid, custody cleared on finalize/cancel), finalize idempotency-key custody (mint-once/reuse,
// revision rotation, confirmed-success clear), product-eligibility rendering, the zero-/v1/users-
// requests spy, and per-type finalize conflict rendering. Nullable-email surfaces are covered in
// commerceOrders.spec.ts / commerceOrderDetail.spec.ts / commerceInvoicePrint.spec.ts (extended
// for this same task) — this file adds only the workspace-local nullable-identity seeding check.

const metaData = ref({
  currency: 'USD',
  currency_exponent: 2,
  shop_index_url: '',
  low_stock_threshold: 3,
  can_view: true,
  can_manage: true,
  can_attach_user: false,
})
vi.mock('@/queries/commerceMeta', () => ({
  useCommerceMeta: () => ({ data: metaData }),
}))

const routeState = vi.hoisted(() => ({
  params: {} as Record<string, string>,
  query: {} as Record<string, string>,
}))
const replace = vi.hoisted(() => vi.fn())
const push = vi.hoisted(() => vi.fn())
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ replace, push }),
}))
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ replace, push }),
}))

// ── commerceDrafts: mock every query/mutation hook + createDraft/finalizeDraft, but keep the
// REAL idempotency-key custody functions and normalizers (spread from `actual`) so those tests
// exercise genuine sessionStorage/crypto behavior, not a stub. ──────────────────────────────────

const draftData = ref<CommerceDraft | undefined>(undefined)
const draftStatus = ref<'pending' | 'error' | 'success'>('success')
const refetchDraftMock = vi.hoisted(() => vi.fn())
const lastDraftUuidArg = vi.hoisted(() => ({ current: undefined as unknown }))
const createDraftMock = vi.hoisted(() => vi.fn())
const finalizeDraftMock = vi.hoisted(() => vi.fn())
const updateMock = vi.hoisted(() => vi.fn())
const addLineMock = vi.hoisted(() => vi.fn())
const updateLineMock = vi.hoisted(() => vi.fn())
const deleteLineMock = vi.hoisted(() => vi.fn())
const recalculateMock = vi.hoisted(() => vi.fn())
const cancelMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/commerceDrafts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceDrafts')>()
  return {
    ...actual,
    useCommerceDraft: (uuid: unknown) => {
      lastDraftUuidArg.current = uuid
      return { data: draftData, status: draftStatus, refetch: refetchDraftMock }
    },
    useCommerceDraftMutations: () => ({
      update: { mutateAsync: updateMock, isLoading: ref(false) },
      addLine: { mutateAsync: addLineMock, isLoading: ref(false) },
      updateLine: { mutateAsync: updateLineMock, isLoading: ref(false) },
      deleteLine: { mutateAsync: deleteLineMock, isLoading: ref(false) },
      recalculate: { mutateAsync: recalculateMock, isLoading: ref(false) },
      cancel: { mutateAsync: cancelMock, isLoading: ref(false) },
    }),
    createDraft: (...a: unknown[]) => createDraftMock(...a),
    finalizeDraft: (...a: unknown[]) => finalizeDraftMock(...a),
  }
})

const productSearchPage = ref<
  { products: CommerceProduct[]; total: number; current_page: number; per_page: number } | undefined
>(undefined)
const selectedProductData = ref<CommerceProduct | undefined>(undefined)
vi.mock('@/queries/commerceCatalog', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceCatalog')>()
  return {
    ...actual,
    useCommerceProducts: () => ({ data: productSearchPage, status: ref('success') }),
    useCommerceProduct: () => ({ data: selectedProductData, status: ref('success') }),
  }
})

const usersMockCalled = vi.hoisted(() => vi.fn())
const usersPage = ref<{ users: UserRow[]; total: number; current_page: number; per_page: number }>({
  users: [],
  total: 0,
  current_page: 1,
  per_page: 10,
})
vi.mock('@/queries/users', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/users')>()
  return {
    ...actual,
    useUsers: (...args: unknown[]) => {
      usersMockCalled(...args)
      return { data: usersPage, status: ref('success') }
    },
  }
})

const zonesPage = ref({ zones: [] as never[], total: 0, current_page: 1, per_page: 100 })
vi.mock('@/queries/commerceSettings', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceSettings')>()
  return {
    ...actual,
    useCommerceShippingZones: () => ({ data: zonesPage, status: ref('success') }),
  }
})

import DraftOrderCreate from '@/pages/commerce/orders/create.vue'
import DraftCustomerCard from '@/pages/commerce/orders/components/DraftCustomerCard.vue'
import DraftLineItemsCard from '@/pages/commerce/orders/components/DraftLineItemsCard.vue'
import {
  getOrCreateFinalizeIdempotencyKey,
  clearFinalizeIdempotencyKeys,
} from '@/queries/commerceDrafts'

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
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    draft_revision: 0,
    lines: [],
    ...overrides,
  }
}

function product(overrides: Partial<CommerceProduct> = {}): CommerceProduct {
  return {
    uuid: 'p1',
    slug: 'widget',
    name: 'Widget',
    description: null,
    type: 'physical',
    status: 'active',
    tax_class: null,
    created_at: null,
    updated_at: null,
    variants: [],
    options: {},
    metadata: {},
    admin_draft_eligible: true,
    admin_draft_ineligible_reason: null,
    ...overrides,
  }
}

function variant(overrides: Partial<CommerceVariant> = {}): CommerceVariant {
  return {
    uuid: 'v1',
    sku: 'SKU-1',
    price: 1000,
    compare_at_price: null,
    currency: 'USD',
    status: 'active',
    position: 0,
    option_values: {},
    ...overrides,
  }
}

function conflictError(conflict: string, extra: Record<string, unknown> = {}, message = 'Conflict'): ApiError {
  const body = {
    success: false,
    message,
    error: { code: 409, timestamp: '2026-01-01T00:00:00Z', request_id: 'r1', details: { conflict, ...extra } },
  }
  return new ApiError(message, 409, {}, body)
}

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
const pageStubs = { RouterLink: RouterLinkStub }

beforeEach(() => {
  setActivePinia(createPinia())
  sessionStorage.clear()
  metaData.value = {
    currency: 'USD',
    currency_exponent: 2,
    shop_index_url: '',
    low_stock_threshold: 3,
    can_view: true,
    can_manage: true,
    can_attach_user: false,
  }
  routeState.params = {}
  routeState.query = {}
  replace.mockClear()
  push.mockClear()
  draftData.value = undefined
  draftStatus.value = 'success'
  refetchDraftMock.mockReset()
  lastDraftUuidArg.current = undefined
  createDraftMock.mockReset()
  finalizeDraftMock.mockReset()
  updateMock.mockReset()
  addLineMock.mockReset()
  updateLineMock.mockReset()
  deleteLineMock.mockReset()
  recalculateMock.mockReset()
  cancelMock.mockReset()
  productSearchPage.value = { products: [], total: 0, current_page: 1, per_page: 8 }
  selectedProductData.value = undefined
  usersMockCalled.mockReset()
  usersPage.value = { users: [], total: 0, current_page: 1, per_page: 10 }
})

// ── Route custody (task brief, binding) ────────────────────────────────────────────────────────

describe('route custody', () => {
  it('creates exactly one draft and replaces the URL with its uuid when the URL carries no draft', async () => {
    createDraftMock.mockResolvedValue(draft({ uuid: 'new-d1' }))
    routeState.query = {}
    mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(createDraftMock).toHaveBeenCalledTimes(1)
    expect(replace).toHaveBeenCalledTimes(1)
    expect(replace).toHaveBeenCalledWith({ query: { draft: 'new-d1' } })
  })

  it('never creates a draft when the URL already carries a draft uuid (covers both refresh and Resume)', async () => {
    routeState.query = { draft: 'existing-d1' }
    draftData.value = draft({ uuid: 'existing-d1' })
    mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(createDraftMock).not.toHaveBeenCalled()
    expect(replace).not.toHaveBeenCalled()
  })

  it('Resume (a URL carrying an existing draft uuid) loads the EXACT uuid, never a fresh one', async () => {
    routeState.query = { draft: 'resume-uuid' }
    draftData.value = draft({ uuid: 'resume-uuid' })
    mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(createDraftMock).not.toHaveBeenCalled()
    expect(toValue(lastDraftUuidArg.current)).toBe('resume-uuid')
  })

  it('shows a loading state while the one-time creation is in flight', async () => {
    let resolveCreate: (d: CommerceDraft) => void = () => {}
    createDraftMock.mockReturnValue(new Promise((resolve) => { resolveCreate = resolve }))
    routeState.query = {}
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="draft-creating"]').exists()).toBe(true)

    resolveCreate(draft({ uuid: 'new-d1' }))
    await flushPromises()
    expect(wrapper.find('[data-test="draft-creating"]').exists()).toBe(false)
  })

  it('surfaces a creation failure inline rather than crashing the page', async () => {
    createDraftMock.mockRejectedValue(new ApiError('Could not start a new order.', 500, {}, null))
    routeState.query = {}
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="draft-create-error"]').text()).toContain('Could not start a new order.')
  })
})

// ── Custody clearing on finalize/cancel (task brief, binding) ──────────────────────────────────

describe('custody clearing on finalize/cancel', () => {
  it('finalize success clears every idempotency key minted for this draft, then navigates to the order detail', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 2, grand_total: 5000 })
    finalizeDraftMock.mockResolvedValue({ uuid: 'order-1' } as CommerceFinalizedOrder)
    sessionStorage.setItem('thallo:commerce:draft-finalize-key:d1:2', 'seed-key')

    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    expect(finalizeDraftMock).toHaveBeenCalledWith('d1', 2, 'seed-key')
    expect(sessionStorage.getItem('thallo:commerce:draft-finalize-key:d1:2')).toBeNull()
    expect(push).toHaveBeenCalledWith('/commerce/orders/order-1')
  })

  it('cancel success clears every idempotency key minted for this draft, then navigates to the orders list', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 1 })
    cancelMock.mockResolvedValue(draft({ uuid: 'd1', status: 'canceled' }))
    sessionStorage.setItem('thallo:commerce:draft-finalize-key:d1:1', 'seed')

    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="draft-cancel"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="draft-cancel-confirm"]').trigger('click')
    await flushPromises()

    expect(cancelMock).toHaveBeenCalledWith('d1')
    expect(sessionStorage.getItem('thallo:commerce:draft-finalize-key:d1:1')).toBeNull()
    expect(push).toHaveBeenCalledWith('/commerce/orders')
  })

  it('dismissing the cancel confirm never calls the mutation and never clears custody', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 1 })
    sessionStorage.setItem('thallo:commerce:draft-finalize-key:d1:1', 'seed')

    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="draft-cancel"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="draft-cancel-dismiss"]').trigger('click')
    await flushPromises()

    expect(cancelMock).not.toHaveBeenCalled()
    expect(sessionStorage.getItem('thallo:commerce:draft-finalize-key:d1:1')).toBe('seed')
  })
})

// ── Finalize idempotency-key custody: pure function behavior (task brief, binding) ─────────────

describe('finalize idempotency-key custody (pure functions)', () => {
  it('mints a fresh key on first use and reuses it for the same (draft, revision) pair', () => {
    const spy = vi.spyOn(crypto, 'randomUUID')
    const k1 = getOrCreateFinalizeIdempotencyKey('d1', 0)
    const k2 = getOrCreateFinalizeIdempotencyKey('d1', 0)
    expect(k1).toBe(k2)
    expect(spy).toHaveBeenCalledTimes(1)
  })

  it('mints a NEW key once the revision changes — rotation, never reuse across revisions', () => {
    const spy = vi.spyOn(crypto, 'randomUUID')
    const k1 = getOrCreateFinalizeIdempotencyKey('d1', 0)
    const k2 = getOrCreateFinalizeIdempotencyKey('d1', 1)
    expect(k1).not.toBe(k2)
    expect(spy).toHaveBeenCalledTimes(2)
  })

  it('scopes keys per draft uuid — a different draft at the same revision gets its own key', () => {
    const k1 = getOrCreateFinalizeIdempotencyKey('d1', 0)
    const k2 = getOrCreateFinalizeIdempotencyKey('d2', 0)
    expect(k1).not.toBe(k2)
  })

  it('survives a simulated reload — a fresh call at the same (draft, revision) still reuses sessionStorage', () => {
    const first = getOrCreateFinalizeIdempotencyKey('d1', 5)
    const second = getOrCreateFinalizeIdempotencyKey('d1', 5)
    expect(second).toBe(first)
  })

  it('clearFinalizeIdempotencyKeys removes every revision minted for that draft, and no other draft', () => {
    getOrCreateFinalizeIdempotencyKey('d1', 0)
    getOrCreateFinalizeIdempotencyKey('d1', 1)
    getOrCreateFinalizeIdempotencyKey('d2', 0)
    clearFinalizeIdempotencyKeys('d1')
    expect(sessionStorage.getItem('thallo:commerce:draft-finalize-key:d1:0')).toBeNull()
    expect(sessionStorage.getItem('thallo:commerce:draft-finalize-key:d1:1')).toBeNull()
    expect(sessionStorage.getItem('thallo:commerce:draft-finalize-key:d2:0')).not.toBeNull()
  })

  it('the stored value is an opaque UUID — never customer/order data', () => {
    const key = getOrCreateFinalizeIdempotencyKey('d1', 0)
    expect(key).toMatch(/^[0-9a-f-]{36}$/i)
  })
})

// ── Idempotency-key custody exercised through the finalize flow ────────────────────────────────

describe('idempotency-key custody in the finalize flow', () => {
  it('reuses the SAME idempotency key across a retry after an ambiguous (non-conflict) failure', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 0 })
    finalizeDraftMock.mockRejectedValue(new Error('network down'))
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    expect(finalizeDraftMock).toHaveBeenCalledTimes(2)
    const key1 = finalizeDraftMock.mock.calls[0]![2]
    const key2 = finalizeDraftMock.mock.calls[1]![2]
    expect(key1).toBe(key2)
  })

  it('rotates the idempotency key once draft_revision increments between attempts', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 0 })
    finalizeDraftMock.mockRejectedValue(new Error('network down'))
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    // Simulates the revision bump an invalidation-triggered refetch produces after any successful
    // mutation elsewhere in the workspace (e.g. adding a line, or recalculate).
    draftData.value = draft({ uuid: 'd1', draft_revision: 1 })
    await nextTick()

    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    const rev1 = finalizeDraftMock.mock.calls[0]![1]
    const rev2 = finalizeDraftMock.mock.calls[1]![1]
    const key1 = finalizeDraftMock.mock.calls[0]![2]
    const key2 = finalizeDraftMock.mock.calls[1]![2]
    expect(rev1).toBe(0)
    expect(rev2).toBe(1)
    expect(key1).not.toBe(key2)
  })
})

// ── Finalize conflict renderings, per type (task brief, binding) ──────────────────────────────

describe('finalize conflict rendering', () => {
  it.each([
    ['stale_revision', 'draft-conflict-reload'],
    ['not_draft', 'draft-conflict-reload'],
    ['currency', 'draft-conflict-cancel'],
  ])('renders the %s conflict with its guidance action', async (conflict, actionTestId) => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 0 })
    finalizeDraftMock.mockRejectedValue(conflictError(conflict))
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="draft-finalize-conflict"]').exists()).toBe(true)
    expect(wrapper.find(`[data-test="${actionTestId}"]`).exists()).toBe(true)
  })

  it('"stale_revision" reload action refetches the draft', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 0 })
    finalizeDraftMock.mockRejectedValue(conflictError('stale_revision'))
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="draft-conflict-reload"]').trigger('click')
    expect(refetchDraftMock).toHaveBeenCalledTimes(1)
  })

  it('"currency" cancel-guidance action opens the cancel confirm panel', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 0 })
    finalizeDraftMock.mockRejectedValue(conflictError('currency'))
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="draft-conflict-cancel"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="draft-cancel-panel"]').exists()).toBe(true)
  })

  it('renders line_conflicts with a per-line reason, "available" ONLY for the stock reason, and a working refresh-prices action', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 0 })
    finalizeDraftMock.mockRejectedValue(
      conflictError('line_conflicts', {
        lines: [
          {
            line_uuid: 'l1',
            variant_uuid: 'v1',
            sku: 'SKU-1',
            product_name: 'Widget',
            quantity: 1,
            reason: 'drift',
            unit_price: 1000,
            current_unit_price: 1200,
            currency: 'USD',
          },
          {
            line_uuid: 'l2',
            variant_uuid: 'v2',
            sku: 'SKU-2',
            product_name: 'Gadget',
            quantity: 1,
            reason: 'stock',
            available: 2,
          },
        ],
      }),
    )
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    const rows = wrapper.findAll('[data-test="draft-line-conflict-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.find('[data-test="draft-line-conflict-available"]').exists()).toBe(false)
    expect(rows[1]!.find('[data-test="draft-line-conflict-available"]').text()).toContain('2')

    await wrapper.find('[data-test="draft-conflict-refresh-prices"]').trigger('click')
    await flushPromises()
    expect(recalculateMock).toHaveBeenCalledWith({ uuid: 'd1', expectedRevision: 0 })
  })

  it('renders the idempotency_key conflict message verbatim', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1' })
    finalizeDraftMock.mockRejectedValue(
      conflictError('idempotency_key', {}, 'This idempotency key was already used with a different request.'),
    )
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="draft-conflict-idempotency"]').exists()).toBe(true)
  })

  it('renders a shipping_method conflict as inline guidance', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1' })
    finalizeDraftMock.mockRejectedValue(conflictError('shipping_method'))
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="draft-finalize-error"]').text()).toContain('shipping method')
  })

  it('renders a generic 422/ambiguous failure message verbatim, never a client-authored guess', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1' })
    finalizeDraftMock.mockRejectedValue(
      new ApiError('A draft order needs at least one line to be finalized.', 422, {}, {}),
    )
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="draft-finalize-error"]').text()).toBe(
      'A draft order needs at least one line to be finalized.',
    )
  })

  it('a successful finalize clears any prior conflict banner', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1', draft_revision: 0 })
    finalizeDraftMock
      .mockRejectedValueOnce(conflictError('stale_revision'))
      .mockResolvedValueOnce({ uuid: 'order-1' } as CommerceFinalizedOrder)
    const wrapper = mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="draft-finalize-conflict"]').exists()).toBe(true)

    await wrapper.find('[data-test="draft-finalize"]').trigger('click')
    await flushPromises()
    expect(push).toHaveBeenCalledWith('/commerce/orders/order-1')
  })
})

// ── Product-eligibility rendering (task brief, binding) ─────────────────────────────────────────

describe('DraftLineItemsCard: product-eligibility rendering', () => {
  it('renders an eligible product as selectable, with no ineligibility reason shown', async () => {
    productSearchPage.value = {
      products: [product({ uuid: 'p1', name: 'Widget', admin_draft_eligible: true })],
      total: 1,
      current_page: 1,
      per_page: 8,
    }
    const wrapper = mount(DraftLineItemsCard, { props: { draft: draft() } })
    await flushPromises()

    expect(wrapper.find('[data-test="draft-product-select"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="draft-product-ineligible-reason"]').exists()).toBe(false)
  })

  it.each([
    ['digital', 'Digital product — cannot be added to a walk-in order.'],
    ['marketplace', 'Marketplace seller product — cannot be added.'],
    ['unavailable', 'Unavailable.'],
  ])('renders the closed reason "%s" and offers no select action for an ineligible product', async (reason, label) => {
    productSearchPage.value = {
      products: [
        product({ uuid: 'p1', admin_draft_eligible: false, admin_draft_ineligible_reason: reason as never }),
      ],
      total: 1,
      current_page: 1,
      per_page: 8,
    }
    const wrapper = mount(DraftLineItemsCard, { props: { draft: draft() } })
    await flushPromises()

    expect(wrapper.find('[data-test="draft-product-select"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="draft-product-ineligible-reason"]').text()).toBe(label)
  })

  it('selecting an eligible product then a variant adds a line with the exact payload, including expected_revision', async () => {
    productSearchPage.value = {
      products: [product({ uuid: 'p1' })],
      total: 1,
      current_page: 1,
      per_page: 8,
    }
    selectedProductData.value = product({ uuid: 'p1', variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 1500 })] })
    addLineMock.mockResolvedValue(draft())
    const wrapper = mount(DraftLineItemsCard, { props: { draft: draft({ uuid: 'd1', draft_revision: 3 }) } })
    await flushPromises()

    await wrapper.find('[data-test="draft-product-select"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="draft-variant-radio"]').setValue()
    await wrapper.find('[data-test="draft-line-qty-input"]').setValue(2)
    await wrapper.find('[data-test="draft-line-add"]').trigger('click')
    await flushPromises()

    expect(addLineMock).toHaveBeenCalledWith({
      uuid: 'd1',
      input: { variant_uuid: 'v1', quantity: 2, expected_revision: 3 },
    })
  })

  it('renders existing lines with quantity and a remove control', async () => {
    const wrapper = mount(DraftLineItemsCard, {
      props: {
        draft: draft({
          lines: [
            {
              uuid: 'l1',
              variant_uuid: 'v1',
              product_name: 'Widget',
              sku: 'SKU-1',
              quantity: 2,
              unit_price: 1000,
              line_total: 2000,
              option_values: {},
              addons: [],
            },
          ],
        }),
      },
    })
    await flushPromises()

    expect(wrapper.findAll('[data-test="draft-line-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="draft-line-remove"]').exists()).toBe(true)
  })

  it('removing a line calls deleteLine with the draft/line uuids and expected_revision', async () => {
    deleteLineMock.mockResolvedValue(draft())
    const wrapper = mount(DraftLineItemsCard, {
      props: {
        draft: draft({
          uuid: 'd1',
          draft_revision: 4,
          lines: [
            {
              uuid: 'l1',
              variant_uuid: 'v1',
              product_name: 'Widget',
              sku: 'SKU-1',
              quantity: 1,
              unit_price: 1000,
              line_total: 1000,
              option_values: {},
              addons: [],
            },
          ],
        }),
      },
    })
    await flushPromises()

    await wrapper.find('[data-test="draft-line-remove"]').trigger('click')
    await flushPromises()

    expect(deleteLineMock).toHaveBeenCalledWith({ uuid: 'd1', lineUuid: 'l1', expectedRevision: 4 })
  })
})

// ── Zero /v1/users requests unless can_attach_user (task brief, binding spy test) ──────────────

describe('user-attachment picker: zero /v1/users requests unless can_attach_user', () => {
  it('never calls useUsers (and so never fetches /v1/users) when can_attach_user is false', async () => {
    mount(DraftCustomerCard, { props: { draft: draft(), canAttachUser: false } })
    await flushPromises()
    expect(usersMockCalled).not.toHaveBeenCalled()
  })

  it('renders the user picker and calls useUsers when can_attach_user is true', async () => {
    const wrapper = mount(DraftCustomerCard, { props: { draft: draft(), canAttachUser: true } })
    await flushPromises()
    expect(usersMockCalled).toHaveBeenCalled()
    expect(wrapper.find('[data-test="draft-user-picker"]').exists()).toBe(true)
  })

  it('the workspace page itself never mounts the picker (and so never calls useUsers) when meta.can_attach_user is false', async () => {
    routeState.query = { draft: 'd1' }
    draftData.value = draft({ uuid: 'd1' })
    metaData.value = { ...metaData.value, can_attach_user: false }
    mount(DraftOrderCreate, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(usersMockCalled).not.toHaveBeenCalled()
  })
})

// ── Nullable identity, workspace-local (extends the brief's nullable-email matrix) ──────────────

describe('nullable customer identity in the workspace', () => {
  it('seeds the email/phone/name fields to blank — never the literal string "null" — when the draft has none', () => {
    const wrapper = mount(DraftCustomerCard, {
      props: { draft: draft({ email: null, phone_display: null, customer_name: null }), canAttachUser: false },
    })
    expect((wrapper.find('[data-test="draft-customer-email"]').element as HTMLInputElement).value).toBe('')
    expect((wrapper.find('[data-test="draft-customer-phone"]').element as HTMLInputElement).value).toBe('')
    expect((wrapper.find('[data-test="draft-customer-name"]').element as HTMLInputElement).value).toBe('')
  })

  it('saving blank fields sends explicit nulls (clearing), never empty strings', async () => {
    updateMock.mockResolvedValue(draft())
    const wrapper = mount(DraftCustomerCard, {
      props: { draft: draft({ uuid: 'd1', draft_revision: 1, email: 'old@example.com' }), canAttachUser: false },
    })
    await wrapper.find('[data-test="draft-customer-email"]').setValue('')
    await wrapper.find('[data-test="draft-customer-save"]').trigger('click')
    await flushPromises()

    expect(updateMock).toHaveBeenCalledWith({
      uuid: 'd1',
      input: { email: null, phone: null, customer_name: null, user_uuid: null, expected_revision: 1 },
    })
  })

  it('the phone field posts the raw typed input verbatim (no trimming/reshaping)', async () => {
    updateMock.mockResolvedValue(draft())
    const wrapper = mount(DraftCustomerCard, { props: { draft: draft({ uuid: 'd1', draft_revision: 0 }), canAttachUser: false } })
    await wrapper.find('[data-test="draft-customer-phone"]').setValue('+1 (555) 010-9999')
    await wrapper.find('[data-test="draft-customer-save"]').trigger('click')
    await flushPromises()

    expect(updateMock.mock.calls[0]![0].input.phone).toBe('+1 (555) 010-9999')
  })

  it('renders a 422 field error on the phone field verbatim', async () => {
    updateMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { phone: 'phone must be a phone number in international format, e.g. +15550109999.' }, {}),
    )
    const wrapper = mount(DraftCustomerCard, { props: { draft: draft({ uuid: 'd1' }), canAttachUser: false } })
    await wrapper.find('[data-test="draft-customer-phone"]').setValue('not-a-phone')
    await wrapper.find('[data-test="draft-customer-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('phone must be a phone number in international format')
  })
})
