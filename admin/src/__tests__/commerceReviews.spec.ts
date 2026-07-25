import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { CommerceReview, ReviewListPage } from '@/queries/commerceReviews'
import { ApiError } from '@/api/errors'

// ── Shared mock state (referenced inside vi.mock factories) ────────────────────────────────────
// Mirrors commerceDiscounts.spec.ts/commerceProducts.spec.ts's established pattern: real refs (not
// vi.hoisted()) so template-bound values (e.g. ReviewsTable's `:rows="rows"`) get Vue's genuine ref
// auto-unwrap.

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

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

const reviewsPage = ref<ReviewListPage | undefined>(undefined)
const reviewsStatus = ref<'pending' | 'error' | 'success'>('success')
const approveMock = vi.hoisted(() => vi.fn())
const spamMock = vi.hoisted(() => vi.fn())
const removeMock = vi.hoisted(() => vi.fn())
const bulkMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/commerceReviews', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceReviews')>()
  return {
    ...actual,
    useCommerceReviews: () => ({ data: reviewsPage, status: reviewsStatus }),
    useCommerceReviewMutations: () => ({
      create: { mutateAsync: vi.fn(), isLoading: ref(false) },
      approve: { mutateAsync: approveMock, isLoading: ref(false) },
      spam: { mutateAsync: spamMock, isLoading: ref(false) },
      remove: { mutateAsync: removeMock, isLoading: ref(false) },
      bulk: { mutateAsync: bulkMock, isLoading: ref(false) },
    }),
  }
})

import ReviewRow from '@/pages/commerce/reviews/components/ReviewRow.vue'
import ReviewsTable from '@/pages/commerce/reviews/components/ReviewsTable.vue'
import ReviewsIndex from '@/pages/commerce/reviews/index.vue'

function review(overrides: Partial<CommerceReview> = {}): CommerceReview {
  return {
    uuid: 'r1',
    product_uuid: 'p1',
    user_uuid: null,
    author_name: 'Jane Doe',
    author_email: 'jane@example.com',
    rating: 4,
    body: 'Great product, would buy again.',
    status: 'pending',
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    ...overrides,
  }
}

// UModal teleports its body/footer out of the wrapper — stub it to render the slots inline
// (mirrors commerceDiscounts.spec.ts's identical Modal teleport stub).
const teleportStub = { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' }
const pageStubs = { Modal: teleportStub }

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
  reviewsPage.value = { reviews: [], total: 0, current_page: 1, per_page: 24 }
  reviewsStatus.value = 'success'
  approveMock.mockReset()
  spamMock.mockReset()
  removeMock.mockReset()
  bulkMock.mockReset()
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

// ── ReviewRow: rendering, status-conditioned actions, safe text rendering ──────────────────────

describe('ReviewRow', () => {
  it('renders rating, status, product, author, and body text', () => {
    const wrapper = mount(ReviewRow, {
      props: {
        review: review({ rating: 3, status: 'approved' }),
        canManage: true,
        selected: false,
        approveLoading: false,
        spamLoading: false,
      },
    })

    expect(wrapper.find('[data-test="review-rating"]').text()).toContain('3/5')
    expect(wrapper.find('[data-test="review-status"]').text()).toBe('approved')
    expect(wrapper.find('[data-test="review-product"]').text()).toContain('p1')
    expect(wrapper.find('[data-test="review-author"]').text()).toContain('Jane Doe')
    expect(wrapper.find('[data-test="review-author"]').text()).toContain('jane@example.com')
    expect(wrapper.find('[data-test="review-body"]').text()).toBe('Great product, would buy again.')
  })

  it('never renders review body as HTML — a script-like body stays literal text', () => {
    const wrapper = mount(ReviewRow, {
      props: {
        review: review({ body: '<img src=x onerror=alert(1)>' }),
        canManage: true,
        selected: false,
        approveLoading: false,
        spamLoading: false,
      },
    })

    expect(wrapper.find('[data-test="review-body"]').text()).toBe('<img src=x onerror=alert(1)>')
    expect(wrapper.find('[data-test="review-body"]').find('img').exists()).toBe(false)
  })

  it('shows approve, spam, and delete for a pending review when can_manage is true', () => {
    const wrapper = mount(ReviewRow, {
      props: {
        review: review({ status: 'pending' }),
        canManage: true,
        selected: false,
        approveLoading: false,
        spamLoading: false,
      },
    })

    expect(wrapper.find('[data-test="review-approve"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="review-spam"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="review-delete"]').exists()).toBe(true)
  })

  it('shows only spam for an approved review (approve and delete would always fail)', () => {
    const wrapper = mount(ReviewRow, {
      props: {
        review: review({ status: 'approved' }),
        canManage: true,
        selected: false,
        approveLoading: false,
        spamLoading: false,
      },
    })

    expect(wrapper.find('[data-test="review-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-spam"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="review-delete"]').exists()).toBe(false)
  })

  it('shows only delete for a spam review (approve and spam would always fail)', () => {
    const wrapper = mount(ReviewRow, {
      props: {
        review: review({ status: 'spam' }),
        canManage: true,
        selected: false,
        approveLoading: false,
        spamLoading: false,
      },
    })

    expect(wrapper.find('[data-test="review-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-spam"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-delete"]').exists()).toBe(true)
  })

  it('hides all mutation controls when can_manage is false, keeping the row readable', () => {
    const wrapper = mount(ReviewRow, {
      props: {
        review: review({ status: 'pending' }),
        canManage: false,
        selected: false,
        approveLoading: false,
        spamLoading: false,
      },
    })

    expect(wrapper.find('[data-test="review-select"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-spam"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-row"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="review-body"]').exists()).toBe(true)
  })

  it('emits toggle-select with the review uuid when its checkbox changes', async () => {
    const wrapper = mount(ReviewRow, {
      props: {
        review: review({ uuid: 'r9' }),
        canManage: true,
        selected: false,
        approveLoading: false,
        spamLoading: false,
      },
    })
    const checkbox = wrapper.findComponent({ name: 'CheckboxRoot' })
    await checkbox.vm.$emit('update:modelValue', true)
    expect(wrapper.emitted('toggle-select')?.[0]).toEqual(['r9'])
  })

  it('emits approve-request/spam-request/delete-request with the review', async () => {
    const wrapper = mount(ReviewRow, {
      props: {
        review: review({ status: 'pending' }),
        canManage: true,
        selected: false,
        approveLoading: false,
        spamLoading: false,
      },
    })

    await wrapper.find('[data-test="review-approve"]').trigger('click')
    expect(wrapper.emitted('approve-request')?.[0]).toEqual([review({ status: 'pending' })])

    await wrapper.find('[data-test="review-spam"]').trigger('click')
    expect(wrapper.emitted('spam-request')?.[0]).toEqual([review({ status: 'pending' })])

    await wrapper.find('[data-test="review-delete"]').trigger('click')
    expect(wrapper.emitted('delete-request')?.[0]).toEqual([review({ status: 'pending' })])
  })
})

// ── ReviewsTable: loading/empty/error, rows-per-review ──────────────────────────────────────────

describe('ReviewsTable', () => {
  const rows = [review({ uuid: 'r1' }), review({ uuid: 'r2', author_name: 'John Smith' })]

  it('renders one row per review', () => {
    const wrapper = mount(ReviewsTable, {
      props: { rows, status: 'success', canManage: true, selected: [], approveLoading: false, spamLoading: false },
    })
    expect(wrapper.findAll('[data-test="review-row"]')).toHaveLength(2)
  })

  it('shows the loading state', () => {
    const wrapper = mount(ReviewsTable, {
      props: { rows: [], status: 'pending', canManage: true, selected: [], approveLoading: false, spamLoading: false },
    })
    expect(wrapper.find('[data-test="reviews-loading"]').exists()).toBe(true)
  })

  it('shows the empty state', () => {
    const wrapper = mount(ReviewsTable, {
      props: { rows: [], status: 'success', canManage: true, selected: [], approveLoading: false, spamLoading: false },
    })
    expect(wrapper.find('[data-test="reviews-empty"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    const wrapper = mount(ReviewsTable, {
      props: { rows: [], status: 'error', canManage: true, selected: [], approveLoading: false, spamLoading: false },
    })
    expect(wrapper.find('[data-test="reviews-error"]').exists()).toBe(true)
  })
})

// ── Reviews list page: can_manage gating, moderation, bulk, confirm, loading/empty/error ───────

describe('commerce reviews list page', () => {
  it('hides selection, per-row actions, and the bulk bar when can_manage is false, keeping rows visible', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    reviewsPage.value = { reviews: [review()], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="review-select"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-select-all"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-spam"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-bulk-bar"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="review-row"]')).toHaveLength(1)
  })

  it('approves a pending review and notifies success', async () => {
    reviewsPage.value = { reviews: [review({ uuid: 'r1', status: 'pending' })], total: 1, current_page: 1, per_page: 24 }
    approveMock.mockResolvedValue(review({ uuid: 'r1', status: 'approved' }))
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="review-approve"]').trigger('click')
    await flushPromises()

    expect(approveMock).toHaveBeenCalledWith('r1')
    expect(notify.success).toHaveBeenCalled()
  })

  it('surfaces the 409 error instead of vanishing it when approve fails', async () => {
    reviewsPage.value = { reviews: [review({ uuid: 'r1', status: 'pending' })], total: 1, current_page: 1, per_page: 24 }
    approveMock.mockRejectedValue(new ApiError("Review status is 'approved'; expected pending.", 409, {}, {}))
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="review-approve"]').trigger('click')
    await flushPromises()

    expect(notify.error).toHaveBeenCalledWith(
      expect.objectContaining({ message: "Review status is 'approved'; expected pending." }),
      'Couldn’t approve review',
    )
  })

  it('marks a review as spam and notifies success', async () => {
    reviewsPage.value = { reviews: [review({ uuid: 'r1', status: 'pending' })], total: 1, current_page: 1, per_page: 24 }
    spamMock.mockResolvedValue(review({ uuid: 'r1', status: 'spam' }))
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="review-spam"]').trigger('click')
    await flushPromises()

    expect(spamMock).toHaveBeenCalledWith('r1')
    expect(notify.success).toHaveBeenCalled()
  })

  it('requires confirmation before deleting a review', async () => {
    reviewsPage.value = { reviews: [review({ uuid: 'r1', status: 'pending' })], total: 1, current_page: 1, per_page: 24 }
    removeMock.mockResolvedValue(undefined)
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="review-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="review-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="review-delete-confirm"]').exists()).toBe(true)
    expect(removeMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="review-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(removeMock).toHaveBeenCalledWith('r1')
  })

  it('applies a bulk approve to every selected review', async () => {
    reviewsPage.value = {
      reviews: [review({ uuid: 'r1' }), review({ uuid: 'r2' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    bulkMock.mockResolvedValue({ applied: ['r1', 'r2'], failed: [] })
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="review-bulk-bar"]').exists()).toBe(false)

    await wrapper.find('[data-test="review-select-all"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="review-bulk-bar"]').exists()).toBe(true)

    await wrapper.find('[data-test="review-bulk-approve"]').trigger('click')
    await flushPromises()

    expect(bulkMock).toHaveBeenCalledWith({ action: 'approve', uuids: ['r1', 'r2'] })
    // Selection clears once applied.
    expect(wrapper.find('[data-test="review-bulk-bar"]').exists()).toBe(false)
  })

  it('applies a bulk spam action with the exact selected uuids', async () => {
    reviewsPage.value = {
      reviews: [review({ uuid: 'r1' }), review({ uuid: 'r2' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    bulkMock.mockResolvedValue({ applied: ['r1'], failed: [{ uuid: 'r2', reason: 'not_found' }] })
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="review-select-all"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="review-bulk-spam"]').trigger('click')
    await flushPromises()

    expect(bulkMock).toHaveBeenCalledWith({ action: 'spam', uuids: ['r1', 'r2'] })
    expect(notify.warning).toHaveBeenCalled()
  })

  it('requires confirmation before applying a bulk delete', async () => {
    reviewsPage.value = {
      reviews: [review({ uuid: 'r1' }), review({ uuid: 'r2' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    bulkMock.mockResolvedValue({ applied: ['r1', 'r2'], failed: [] })
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="review-select-all"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="review-bulk-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="review-bulk-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="review-bulk-delete-confirm"]').exists()).toBe(true)
    expect(bulkMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="review-bulk-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(bulkMock).toHaveBeenCalledWith({ action: 'delete', uuids: ['r1', 'r2'] })
  })

  // ── Loading/empty/error passthrough ──────────────────────────────────────────────────────────

  it('shows the empty state when there are no reviews', async () => {
    reviewsPage.value = { reviews: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="reviews-empty"]').exists()).toBe(true)
  })

  it('shows the loading state', async () => {
    reviewsStatus.value = 'pending'
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="reviews-loading"]').exists()).toBe(true)
  })

  it('shows the error state', async () => {
    reviewsStatus.value = 'error'
    const wrapper = mount(ReviewsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="reviews-error"]').exists()).toBe(true)
  })
})
