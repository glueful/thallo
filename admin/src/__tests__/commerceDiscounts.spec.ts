import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { CommerceDiscount, DiscountListPage } from '@/queries/commerceDiscounts'
import { ApiError } from '@/api/errors'

// ── Shared mock state (referenced inside vi.mock factories) ────────────────────────────────────
// Mirrors commerceProducts.spec.ts/commerceOrders.spec.ts's established pattern: real refs (not
// vi.hoisted()) so template-bound values (e.g. DiscountsTable's `:rows="rows"`) get Vue's genuine
// ref auto-unwrap.

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

const discountsPage = ref<DiscountListPage | undefined>(undefined)
const discountsStatus = ref<'pending' | 'error' | 'success'>('success')
const createMock = vi.hoisted(() => vi.fn())
const updateMock = vi.hoisted(() => vi.fn())
const removeMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/commerceDiscounts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceDiscounts')>()
  return {
    ...actual,
    useCommerceDiscounts: () => ({ data: discountsPage, status: discountsStatus }),
    useCommerceDiscountMutations: () => ({
      create: { mutateAsync: createMock, isLoading: ref(false) },
      update: { mutateAsync: updateMock, isLoading: ref(false) },
      remove: { mutateAsync: removeMock, isLoading: ref(false) },
    }),
  }
})

import DiscountsTable from '@/pages/commerce/discounts/components/DiscountsTable.vue'
import DiscountsIndex from '@/pages/commerce/discounts/index.vue'

function discount(overrides: Partial<CommerceDiscount> = {}): CommerceDiscount {
  return {
    uuid: 'd1',
    code: 'SAVE10',
    type: 'percentage',
    value: 1000,
    min_subtotal: null,
    usage_limit: null,
    once_per_buyer: false,
    usage_count: 0,
    status: 'active',
    starts_at: null,
    ends_at: null,
    product_scope: null,
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    ...overrides,
  }
}

// USlideover/UModal teleport their body/footer out of the wrapper — stub both to render the
// slots inline (mirrors commerceOrders.spec.ts's identical Slideover + Modal teleport stubs).
const teleportStub = { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' }
const pageStubs = { Slideover: teleportStub, Modal: teleportStub }

/** Find the Reka SelectRoot ancestor of a USelect carrying `dataTest`, and drive it directly —
 * USelect's options render in a portal, so opening the dropdown in jsdom is unreliable; emitting
 * `update:modelValue` on the underlying SelectRoot is the established pattern
 * (commerceProducts.spec.ts/commerceOrders.spec.ts). */
function selectByTestId(wrapper: ReturnType<typeof mount>, dataTest: string) {
  const root = wrapper
    .findAllComponents({ name: 'SelectRoot' })
    .find((r) => r.element.querySelector?.(`[data-test="${dataTest}"]`))
  if (!root) throw new Error(`No SelectRoot found for [data-test="${dataTest}"]`)
  return root
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
  discountsPage.value = { discounts: [], total: 0, current_page: 1, per_page: 24 }
  discountsStatus.value = 'success'
  createMock.mockReset()
  updateMock.mockReset()
  removeMock.mockReset()
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

// ── DiscountsTable: rows, loading/empty/error, can_manage gating ───────────────────────────────

describe('DiscountsTable', () => {
  const rows = [
    discount({ uuid: 'd1', code: 'SAVE10', type: 'percentage', value: 1000, usage_count: 2, usage_limit: 10 }),
    discount({
      uuid: 'd2',
      code: 'FLAT5',
      type: 'fixed',
      value: 500,
      status: 'inactive',
      starts_at: '2026-01-01 00:00:00',
      ends_at: '2026-01-31 00:00:00',
    }),
  ]

  it('renders one row per discount with code, type/value, usage, window, and status', () => {
    const wrapper = mount(DiscountsTable, { props: { rows, status: 'success', canManage: true } })

    expect(wrapper.findAll('[data-test="discount-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('SAVE10')
    expect(wrapper.text()).toContain('FLAT5')

    const values = wrapper.findAll('[data-test="discount-value"]')
    expect(values[0]!.text()).toBe('10%')
    expect(values[1]!.text()).toBe('$5.00')

    expect(wrapper.findAll('[data-test="discount-usage"]')[0]!.text()).toBe('2/10')
    expect(wrapper.findAll('[data-test="discount-usage"]')[1]!.text()).toBe('0/∞')

    expect(wrapper.findAll('[data-test="discount-window"]')[0]!.text()).toBe('Always')
    expect(wrapper.findAll('[data-test="discount-window"]')[1]!.text()).toContain('–')

    const statuses = wrapper.findAll('[data-test="discount-status"]')
    expect(statuses[0]!.text()).toBe('active')
    expect(statuses[1]!.text()).toBe('inactive')
  })

  it('shows the loading state', () => {
    const wrapper = mount(DiscountsTable, { props: { rows: [], status: 'pending', canManage: true } })
    expect(wrapper.find('[data-test="discounts-loading"]').exists()).toBe(true)
  })

  it('shows the empty state', () => {
    const wrapper = mount(DiscountsTable, { props: { rows: [], status: 'success', canManage: true } })
    expect(wrapper.find('[data-test="discounts-empty"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    const wrapper = mount(DiscountsTable, { props: { rows: [], status: 'error', canManage: true } })
    expect(wrapper.find('[data-test="discounts-error"]').exists()).toBe(true)
  })

  it('hides edit/delete controls when can_manage is false, keeping rows visible (read-only)', () => {
    const wrapper = mount(DiscountsTable, { props: { rows, status: 'success', canManage: false } })
    expect(wrapper.find('[data-test="discount-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="discount-delete"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="discount-row"]')).toHaveLength(2)
  })

  it('shows edit/delete controls when can_manage is true', () => {
    const wrapper = mount(DiscountsTable, { props: { rows, status: 'success', canManage: true } })
    expect(wrapper.findAll('[data-test="discount-edit"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-test="discount-delete"]')).toHaveLength(2)
  })

  it('emits edit-request with the row when its edit button is clicked', async () => {
    const wrapper = mount(DiscountsTable, { props: { rows, status: 'success', canManage: true } })
    await wrapper.findAll('[data-test="discount-edit"]')[0]!.trigger('click')
    expect(wrapper.emitted('edit-request')?.[0]).toEqual([rows[0]])
  })

  it('emits delete-request with the row when its delete button is clicked', async () => {
    const wrapper = mount(DiscountsTable, { props: { rows, status: 'success', canManage: true } })
    await wrapper.findAll('[data-test="discount-delete"]')[0]!.trigger('click')
    expect(wrapper.emitted('delete-request')?.[0]).toEqual([rows[0]])
  })
})

// ── Discounts list page: can_manage gating, create/edit/delete flows ───────────────────────────

describe('commerce discounts list page', () => {
  it('hides the New discount button when can_manage is false, keeping the list readable', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    discountsPage.value = { discounts: [discount()], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="new-discount"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="discount-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="discount-delete"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="discount-row"]')).toHaveLength(1)
  })

  it('shows the New discount button and opens the create slideover', async () => {
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="new-discount"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="discount-form-submit"]').exists()).toBe(false)

    await wrapper.find('[data-test="new-discount"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="discount-form-submit"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="discount-code-input"]').exists()).toBe(true)
  })

  // ── Create flow: type-dependent value entry (percentage bps vs fixed minor units) ────────────

  it('creates a PERCENTAGE discount, converting the typed percent into exact basis points', async () => {
    createMock.mockResolvedValue(discount({ uuid: 'new-1', code: 'TEN', type: 'percentage', value: 1000 }))
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="new-discount"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="discount-code-input"]').setValue('TEN')
    await wrapper.find('[data-test="discount-value-input"]').setValue('10')
    await wrapper.find('#discount-form').trigger('submit')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      code: 'TEN',
      type: 'percentage',
      value: 1000,
      min_subtotal: null,
      usage_limit: null,
      once_per_buyer: false,
      status: 'active',
      starts_at: null,
      ends_at: null,
    })
  })

  it('creates a FIXED discount, converting the typed decimal amount into exact minor units (the SAME discipline as RefundSlideover)', async () => {
    createMock.mockResolvedValue(discount({ uuid: 'new-2', code: 'FIVEOFF', type: 'fixed', value: 500 }))
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="new-discount"]').trigger('click')
    await flushPromises()

    selectByTestId(wrapper, 'discount-type-input').vm.$emit('update:modelValue', 'fixed')
    await flushPromises()

    await wrapper.find('[data-test="discount-code-input"]').setValue('FIVEOFF')
    await wrapper.find('[data-test="discount-value-input"]').setValue('5.00')
    await wrapper.find('[data-test="discount-min-subtotal-input"]').setValue('20.00')
    await wrapper.find('[data-test="discount-usage-limit-input"]').setValue('100')
    await wrapper.find('#discount-form').trigger('submit')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      code: 'FIVEOFF',
      type: 'fixed',
      value: 500,
      min_subtotal: 2000,
      usage_limit: 100,
      once_per_buyer: false,
      status: 'active',
      starts_at: null,
      ends_at: null,
    })
  })

  it('rejects a percentage above 100 client-side without calling the mutation', async () => {
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="new-discount"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="discount-code-input"]').setValue('TOOBIG')
    await wrapper.find('[data-test="discount-value-input"]').setValue('150')
    await wrapper.find('#discount-form').trigger('submit')
    await flushPromises()

    expect(createMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Enter a percentage between 0.01 and 100.')
  })

  it('rejects a blank code client-side without calling the mutation', async () => {
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="new-discount"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="discount-value-input"]').setValue('10')
    await wrapper.find('#discount-form').trigger('submit')
    await flushPromises()

    expect(createMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Code is required.')
  })

  it('rejects an end date before the start date client-side', async () => {
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="new-discount"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="discount-code-input"]').setValue('BADWINDOW')
    await wrapper.find('[data-test="discount-value-input"]').setValue('10')
    await wrapper.find('[data-test="discount-starts-at-input"]').setValue('2026-02-01')
    await wrapper.find('[data-test="discount-ends-at-input"]').setValue('2026-01-01')
    await wrapper.find('#discount-form').trigger('submit')
    await flushPromises()

    expect(createMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('End date must be after start date.')
  })

  it('surfaces a 422 duplicate-code rejection instead of vanishing it', async () => {
    createMock.mockRejectedValue(new ApiError('Validation failed', 422, { code: 'Code already in use.' }, {}))
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    await wrapper.find('[data-test="new-discount"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="discount-code-input"]').setValue('DUP')
    await wrapper.find('[data-test="discount-value-input"]').setValue('10')
    await wrapper.find('#discount-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="discount-form-error"]').text()).toContain('Code already in use.')
  })

  // ── Edit flow: pre-populates from the row, submits the exact update payload ─────────────────

  it('opens the edit slideover pre-populated from the row and submits the update', async () => {
    discountsPage.value = {
      discounts: [
        discount({
          uuid: 'd1',
          code: 'SAVE10',
          type: 'percentage',
          value: 1000,
          min_subtotal: 2000,
          usage_limit: 5,
          once_per_buyer: true,
          status: 'active',
          starts_at: '2026-01-01',
          ends_at: '2026-01-31',
        }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    updateMock.mockResolvedValue(discount({ uuid: 'd1', status: 'inactive' }))
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="discount-edit"]').trigger('click')
    await flushPromises()

    expect((wrapper.find('[data-test="discount-code-input"]').element as HTMLInputElement).value).toBe('SAVE10')
    expect((wrapper.find('[data-test="discount-value-input"]').element as HTMLInputElement).value).toBe('10.00')
    expect((wrapper.find('[data-test="discount-min-subtotal-input"]').element as HTMLInputElement).value).toBe(
      '20.00',
    )
    expect((wrapper.find('[data-test="discount-usage-limit-input"]').element as HTMLInputElement).value).toBe('5')

    await wrapper.find('#discount-form').trigger('submit')
    await flushPromises()

    expect(updateMock).toHaveBeenCalledWith({
      uuid: 'd1',
      input: {
        code: 'SAVE10',
        type: 'percentage',
        value: 1000,
        min_subtotal: 2000,
        usage_limit: 5,
        once_per_buyer: true,
        status: 'active',
        starts_at: '2026-01-01',
        ends_at: '2026-01-31',
      },
    })
  })

  // ── Delete flow: requires confirmation ──────────────────────────────────────────────────────

  it('requires confirmation before deleting a discount', async () => {
    discountsPage.value = { discounts: [discount({ uuid: 'd1', code: 'SAVE10' })], total: 1, current_page: 1, per_page: 24 }
    removeMock.mockResolvedValue(undefined)
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="discount-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="discount-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="discount-delete-confirm"]').exists()).toBe(true)
    expect(removeMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="discount-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(removeMock).toHaveBeenCalledWith('d1')
  })

  it('surfaces the 409 redeemed-discount message on delete instead of vanishing it', async () => {
    discountsPage.value = { discounts: [discount({ uuid: 'd1', code: 'SAVE10' })], total: 1, current_page: 1, per_page: 24 }
    removeMock.mockRejectedValue(
      new ApiError('This discount has been redeemed and cannot be deleted. Disable it via status instead.', 409, {}, {}),
    )
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="discount-delete"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="discount-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(notify.error).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'This discount has been redeemed and cannot be deleted. Disable it via status instead.' }),
      'Couldn’t delete discount',
    )
  })

  // ── Loading/empty/error passthrough ──────────────────────────────────────────────────────────

  it('shows the empty state when there are no discounts', async () => {
    discountsPage.value = { discounts: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="discounts-empty"]').exists()).toBe(true)
  })

  it('shows the loading state', async () => {
    discountsStatus.value = 'pending'
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="discounts-loading"]').exists()).toBe(true)
  })

  it('shows the error state', async () => {
    discountsStatus.value = 'error'
    const wrapper = mount(DiscountsIndex, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="discounts-error"]').exists()).toBe(true)
  })
})
