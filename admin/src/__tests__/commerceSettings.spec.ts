import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type {
  CommerceShippingZone,
  CommerceShippingMethod,
  CommerceShippingLocation,
  ShippingZoneListPage,
  CommerceShippingClass,
  ShippingClassListPage,
  CommerceTaxRate,
  TaxRateListPage,
} from '@/queries/commerceSettings'

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

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

const zonesPage = ref<ShippingZoneListPage | undefined>(undefined)
const zonesStatus = ref<'pending' | 'error' | 'success'>('success')
const classesPage = ref<ShippingClassListPage | undefined>(undefined)
const classesStatus = ref<'pending' | 'error' | 'success'>('success')
const ratesPage = ref<TaxRateListPage | undefined>(undefined)
const ratesStatus = ref<'pending' | 'error' | 'success'>('success')

// Task 15a: mutation mocks, same `{ mutateAsync, isLoading }` shape established by
// commerceOrders.spec.ts/commerceProducts.spec.ts — the real hooks call `useMutation`/
// `useQueryCache` from '@pinia/colada', which this file's harness never installs.
const createZoneMock = vi.hoisted(() => vi.fn())
const updateZoneMock = vi.hoisted(() => vi.fn())
const deleteZoneMock = vi.hoisted(() => vi.fn())
const setLocationsMock = vi.hoisted(() => vi.fn())
const createMethodMock = vi.hoisted(() => vi.fn())
const updateMethodMock = vi.hoisted(() => vi.fn())
const deleteMethodMock = vi.hoisted(() => vi.fn())

// Task 15b: shipping-class mutation mocks, same shape as the zone mocks above.
const createClassMock = vi.hoisted(() => vi.fn())
const updateClassMock = vi.hoisted(() => vi.fn())
const deleteClassMock = vi.hoisted(() => vi.fn())

// Store settings (store-settings spec §3.5): query/mutation mocks for StorePanel.
const storeSettingsData = ref<import('@/queries/commerceSettings').StoreSettings | undefined>(undefined)
const storeSettingsStatus = ref<'pending' | 'error' | 'success'>('success')
const saveStoreSettingsMock = vi.hoisted(() => vi.fn())

// Payments settings (store-settings spec §3.6): query/mutation mocks for PaymentsPanel.
const paymentsData = ref<import('@/queries/commerceSettings').PaymentsSettings | undefined>(undefined)
const paymentsStatus = ref<'pending' | 'error' | 'success'>('success')
const savePaymentsMock = vi.hoisted(() => vi.fn())

// Order-email switches (store-settings spec §4.2 follow-up): mocks for EmailsPanel. Template
// CONTENT comes from @/queries/email (mocked below); the switches from /commerce/emails.
const emailSettingsData = ref<import('@/queries/commerceSettings').CommerceEmailSettings | undefined>(undefined)
const emailSettingsStatus = ref<'pending' | 'error' | 'success'>('success')
const saveEmailSettingsMock = vi.hoisted(() => vi.fn())
const fetchEmailTemplatesMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/email', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/email')>()
  return { ...actual, fetchEmailTemplates: fetchEmailTemplatesMock }
})

// Task 15c: tax-rate mutation mocks, same shape as the zone/class mocks above.
const createRateMock = vi.hoisted(() => vi.fn())
const updateRateMock = vi.hoisted(() => vi.fn())
const deleteRateMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/commerceSettings', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceSettings')>()
  return {
    ...actual,
    useCommerceShippingZones: () => ({ data: zonesPage, status: zonesStatus }),
    useCommerceShippingZoneMutations: () => ({
      createZone: { mutateAsync: createZoneMock, isLoading: ref(false) },
      updateZone: { mutateAsync: updateZoneMock, isLoading: ref(false) },
      deleteZone: { mutateAsync: deleteZoneMock, isLoading: ref(false) },
      setLocations: { mutateAsync: setLocationsMock, isLoading: ref(false) },
      createMethod: { mutateAsync: createMethodMock, isLoading: ref(false) },
      updateMethod: { mutateAsync: updateMethodMock, isLoading: ref(false) },
      deleteMethod: { mutateAsync: deleteMethodMock, isLoading: ref(false) },
    }),
    useCommerceShippingClasses: () => ({ data: classesPage, status: classesStatus }),
    useCommerceShippingClassMutations: () => ({
      createClass: { mutateAsync: createClassMock, isLoading: ref(false) },
      updateClass: { mutateAsync: updateClassMock, isLoading: ref(false) },
      deleteClass: { mutateAsync: deleteClassMock, isLoading: ref(false) },
    }),
    useStoreSettings: () => ({ data: storeSettingsData, status: storeSettingsStatus }),
    useSaveStoreSettings: () => ({ mutateAsync: saveStoreSettingsMock, isLoading: ref(false) }),
    usePaymentsSettings: () => ({ data: paymentsData, status: paymentsStatus }),
    useSavePaymentsSettings: () => ({ mutateAsync: savePaymentsMock, isLoading: ref(false) }),
    useCommerceEmailSettings: () => ({ data: emailSettingsData, status: emailSettingsStatus }),
    useSaveCommerceEmailSettings: () => ({ mutateAsync: saveEmailSettingsMock, isLoading: ref(false) }),
    useCommerceTaxRates: () => ({ data: ratesPage, status: ratesStatus }),
    useCommerceTaxRateMutations: () => ({
      createRate: { mutateAsync: createRateMock, isLoading: ref(false) },
      updateRate: { mutateAsync: updateRateMock, isLoading: ref(false) },
      deleteRate: { mutateAsync: deleteRateMock, isLoading: ref(false) },
    }),
  }
})

import StorePanel from '@/pages/commerce/settings/components/StorePanel.vue'
import ZonesPanel from '@/pages/commerce/settings/components/ZonesPanel.vue'
import ClassesPanel from '@/pages/commerce/settings/components/ClassesPanel.vue'
import TaxRatesPanel from '@/pages/commerce/settings/components/TaxRatesPanel.vue'
import PaymentsPanel from '@/pages/commerce/settings/components/PaymentsPanel.vue'
import EmailsPanel from '@/pages/commerce/settings/components/EmailsPanel.vue'
import SettingsIndex from '@/pages/commerce/settings/index.vue'

function location(overrides: Partial<CommerceShippingLocation> = {}): CommerceShippingLocation {
  return { kind: 'country', value: 'US', ...overrides }
}

function method(overrides: Partial<CommerceShippingMethod> = {}): CommerceShippingMethod {
  return {
    uuid: 'm1',
    zone_uuid: 'z1',
    kind: 'flat',
    label: 'Standard',
    config: { amount: 500 },
    position: 0,
    enabled: true,
    warnings: [],
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    ...overrides,
  }
}

function zone(overrides: Partial<CommerceShippingZone> = {}): CommerceShippingZone {
  return {
    uuid: 'z1',
    name: 'Domestic',
    position: 0,
    revision: 0,
    locations: [location()],
    methods: [method()],
    shadows_later_zones: false,
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    ...overrides,
  }
}

function shippingClass(overrides: Partial<CommerceShippingClass> = {}): CommerceShippingClass {
  return {
    uuid: 'c1',
    slug: 'fragile',
    name: 'Fragile',
    revision: 0,
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    ...overrides,
  }
}

function taxRate(overrides: Partial<CommerceTaxRate> = {}): CommerceTaxRate {
  return {
    uuid: 'r1',
    country: 'US',
    state: null,
    postcode_pattern: null,
    rate_bps: 875,
    label: 'Sales Tax',
    priority: 0,
    shipping_taxable: false,
    class: 'standard',
    revision: 0,
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    ...overrides,
  }
}

// USlideover/UModal teleport their body/footer out of the wrapper — stub both to render the slots
// inline (mirrors commerceOrders.spec.ts/commerceProducts.spec.ts's established pattern).
const SlideoverStub = { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' }
const pageStubs = { Slideover: SlideoverStub, Modal: SlideoverStub }

/** Find the Reka SelectRoot ancestor of a USelect carrying `dataTest` at the given occurrence (for
 * repeated rows) and drive it directly — USelect's options render in a portal, so opening the
 * dropdown in jsdom is unreliable; emitting `update:modelValue` on the underlying SelectRoot is the
 * established pattern (commerceOrders.spec.ts/commerceProducts.spec.ts). */
function selectRootByTestId(wrapper: ReturnType<typeof mount>, dataTest: string, occurrence = 0) {
  const roots = wrapper
    .findAllComponents({ name: 'SelectRoot' })
    .filter((r) => r.element.querySelector?.(`[data-test="${dataTest}"]`))
  const root = roots[occurrence]
  if (!root) throw new Error(`No SelectRoot found for [data-test="${dataTest}"] at occurrence ${occurrence}`)
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
  zonesPage.value = { zones: [zone()], total: 1, current_page: 1, per_page: 24 }
  zonesStatus.value = 'success'
  classesPage.value = { classes: [shippingClass()], total: 1, current_page: 1, per_page: 24 }
  classesStatus.value = 'success'
  ratesPage.value = { rates: [taxRate()], total: 1, current_page: 1, per_page: 24 }
  ratesStatus.value = 'success'
  createZoneMock.mockReset()
  updateZoneMock.mockReset()
  deleteZoneMock.mockReset()
  setLocationsMock.mockReset()
  createMethodMock.mockReset()
  updateMethodMock.mockReset()
  deleteMethodMock.mockReset()
  createClassMock.mockReset()
  updateClassMock.mockReset()
  deleteClassMock.mockReset()
  createRateMock.mockReset()
  updateRateMock.mockReset()
  deleteRateMock.mockReset()
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

function mountPanel() {
  return mount(ZonesPanel, { props: { canManage: true }, global: { stubs: pageStubs } })
}

function mountClassesPanel(canManage = true) {
  return mount(ClassesPanel, { props: { canManage }, global: { stubs: pageStubs } })
}

function mountRatesPanel(canManage = true) {
  return mount(TaxRatesPanel, { props: { canManage }, global: { stubs: pageStubs } })
}

// ── Zones list: rows, loading/empty/error ───────────────────────────────────────────────────

describe('ZonesPanel: zones list', () => {
  it('renders a row per zone with name, locations summary, and methods count', async () => {
    zonesPage.value = {
      zones: [zone({ uuid: 'z1', name: 'Domestic', locations: [location({ value: 'US' })], methods: [method()] })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountPanel()
    await flushPromises()

    const rows = wrapper.findAll('[data-test="zone-row"]')
    expect(rows).toHaveLength(1)
    expect(wrapper.find('[data-test="zone-name"]').text()).toBe('Domestic')
    expect(wrapper.find('[data-test="zone-locations-summary"]').text()).toBe('US')
    expect(wrapper.find('[data-test="zone-methods-count"]').text()).toContain('1 method')
  })

  it('shows "Everywhere" for a zone with no locations', async () => {
    zonesPage.value = { zones: [zone({ locations: [] })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="zone-locations-summary"]').text()).toBe('Everywhere')
  })

  it('shows a shadow warning badge only when shadows_later_zones is true', async () => {
    zonesPage.value = { zones: [zone({ shadows_later_zones: true })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="zone-shadow-warning"]').exists()).toBe(true)

    zonesPage.value = { zones: [zone({ shadows_later_zones: false })], total: 1, current_page: 1, per_page: 24 }
    const wrapper2 = mountPanel()
    await flushPromises()
    expect(wrapper2.find('[data-test="zone-shadow-warning"]').exists()).toBe(false)
  })

  it('shows the loading state', async () => {
    zonesStatus.value = 'pending'
    const wrapper = mountPanel()
    expect(wrapper.find('[data-test="zones-loading"]').exists()).toBe(true)
  })

  it('shows the error state', async () => {
    zonesStatus.value = 'error'
    const wrapper = mountPanel()
    expect(wrapper.find('[data-test="zones-error"]').exists()).toBe(true)
  })

  it('shows the empty state', async () => {
    zonesPage.value = { zones: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="zones-empty"]').exists()).toBe(true)
  })
})

// ── Zone CRUD: create/edit/delete with confirm ──────────────────────────────────────────────

describe('ZonesPanel: zone CRUD', () => {
  it('creates a zone with the entered name and position', async () => {
    createZoneMock.mockResolvedValue(zone({ uuid: 'z2', name: 'International', position: 10 }))
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-zone"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="zone-name-input"]').setValue('International')
    await wrapper.find('[data-test="zone-position-input"]').setValue('10')
    await wrapper.find('form#zone-form').trigger('submit')
    await flushPromises()

    expect(createZoneMock).toHaveBeenCalledTimes(1)
    expect(createZoneMock).toHaveBeenCalledWith({ name: 'International', position: 10 })
    expect(notify.success).toHaveBeenCalledTimes(1)
  })

  it('rejects a blank name client-side without calling the mutation', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-zone"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="zone-name-input"]').setValue('   ')
    await wrapper.find('form#zone-form').trigger('submit')
    await flushPromises()

    expect(createZoneMock).not.toHaveBeenCalled()
  })

  it('pre-fills the edit form and submits an update with the zone uuid', async () => {
    updateZoneMock.mockResolvedValue(zone({ name: 'Domestic Shipping', position: 5 }))
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="zone-edit"]').trigger('click')
    await flushPromises()
    expect((wrapper.find('[data-test="zone-name-input"]').element as HTMLInputElement).value).toBe('Domestic')

    await wrapper.find('[data-test="zone-name-input"]').setValue('Domestic Shipping')
    await wrapper.find('[data-test="zone-position-input"]').setValue('5')
    await wrapper.find('form#zone-form').trigger('submit')
    await flushPromises()

    expect(updateZoneMock).toHaveBeenCalledWith({
      uuid: 'z1',
      input: { name: 'Domestic Shipping', position: 5 },
    })
  })

  it('surfaces a 422 duplicate-name field error inline', async () => {
    // A plain framework error-body object (Response::validation()'s exact envelope shape) rather
    // than a directly-constructed ApiError: this file's global setup.ts resets the module registry
    // before each test, so an `instanceof ApiError` check against an ApiError built from a
    // separately re-imported class would fail cross-module-identity, silently losing fieldErrors
    // (mirrors commerceOrders.spec.ts's identical precedent/comment).
    updateZoneMock.mockRejectedValue({
      success: false,
      message: 'Validation failed',
      error: {
        code: 422,
        timestamp: '2026-01-01T00:00:00Z',
        request_id: 'req_1',
        details: { name: 'Name already in use.' },
      },
    })
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="zone-edit"]').trigger('click')
    await flushPromises()
    await wrapper.find('form#zone-form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Name already in use.')
  })

  it('deletes a zone only after confirming', async () => {
    deleteZoneMock.mockResolvedValue(undefined)
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="zone-delete"]').trigger('click')
    await flushPromises()
    expect(deleteZoneMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="zone-delete-confirm"]').exists()).toBe(true)

    await wrapper.find('[data-test="zone-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(deleteZoneMock).toHaveBeenCalledWith('z1')
  })
})

// ── Locations editing ────────────────────────────────────────────────────────────────────────

describe('ZonesPanel: locations editing', () => {
  async function expandZone(wrapper: ReturnType<typeof mount>) {
    await wrapper.find('[data-test="zone-expand-toggle"]').trigger('click')
    await flushPromises()
  }

  it('pre-fills the current locations when opening the editor', async () => {
    zonesPage.value = {
      zones: [zone({ locations: [location({ kind: 'country', value: 'US' }), location({ kind: 'country', value: 'CA' })] })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="zone-locations-edit"]').trigger('click')
    await flushPromises()

    const rows = wrapper.findAll('[data-test="zone-location-row"]')
    expect(rows).toHaveLength(2)
    const values = wrapper.findAll('[data-test="zone-location-value-input"]')
    expect((values[0]!.element as HTMLInputElement).value).toBe('US')
    expect((values[1]!.element as HTMLInputElement).value).toBe('CA')
  })

  it('saves an edited location set, trimming and dropping blank rows', async () => {
    setLocationsMock.mockResolvedValue([{ kind: 'country', value: 'US' }])
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="zone-locations-edit"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="zone-location-value-input"]').setValue('  us  ')
    await wrapper.find('[data-test="zone-location-add"]').trigger('click')
    await flushPromises()

    const valueInputs = wrapper.findAll('[data-test="zone-location-value-input"]')
    await valueInputs[1]!.setValue('   ')

    await wrapper.find('[data-test="zone-locations-form"]').trigger('submit')
    await flushPromises()

    expect(setLocationsMock).toHaveBeenCalledWith({
      zoneUuid: 'z1',
      locations: [{ kind: 'country', value: 'us' }],
    })
  })

  it('changing the kind to state/postcode_pattern is reflected in the saved payload', async () => {
    setLocationsMock.mockResolvedValue([])
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="zone-locations-edit"]').trigger('click')
    await flushPromises()

    selectRootByTestId(wrapper, 'zone-location-kind-input').vm.$emit('update:modelValue', 'state')
    await flushPromises()
    await wrapper.find('[data-test="zone-location-value-input"]').setValue('US:CA')
    await wrapper.find('[data-test="zone-locations-form"]').trigger('submit')
    await flushPromises()

    expect(setLocationsMock).toHaveBeenCalledWith({
      zoneUuid: 'z1',
      locations: [{ kind: 'state', value: 'US:CA' }],
    })
  })

  it('surfaces the exact postcode-without-country 422 field error verbatim', async () => {
    // Plain error-body object — see the "surfaces a 422 duplicate-name field error inline" test
    // above for why a directly-constructed ApiError can't be used here.
    setLocationsMock.mockRejectedValue({
      success: false,
      message: 'Validation failed',
      error: {
        code: 422,
        timestamp: '2026-01-01T00:00:00Z',
        request_id: 'req_1',
        details: {
          locations: 'A zone with postcode_pattern locations must also include at least one country location.',
        },
      },
    })
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="zone-locations-edit"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="zone-locations-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="zone-locations-error"]').text()).toContain(
      'A zone with postcode_pattern locations must also include at least one country location.',
    )
  })

  it('cancel discards edits without calling the mutation', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="zone-locations-edit"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="zone-location-value-input"]').setValue('ZZ')
    await wrapper.find('[data-test="zone-locations-cancel"]').trigger('click')
    await flushPromises()

    expect(setLocationsMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="zone-locations-form"]').exists()).toBe(false)
  })
})

// ── Nested method editing: create/edit/delete with exact money ────────────────────────────────

describe('ZonesPanel: nested method editing', () => {
  async function expandZone(wrapper: ReturnType<typeof mount>) {
    await wrapper.find('[data-test="zone-expand-toggle"]').trigger('click')
    await flushPromises()
  }

  it('renders a row per method with label, kind, exact rate, and enabled state', async () => {
    zonesPage.value = {
      zones: [zone({ methods: [method({ kind: 'flat', label: 'Standard', config: { amount: 1234 }, enabled: true })] })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    expect(wrapper.find('[data-test="method-label"]').text()).toBe('Standard')
    expect(wrapper.find('[data-test="method-kind"]').text()).toBe('flat')
    expect(wrapper.find('[data-test="method-rate"]').text()).toContain('$12.34')
    expect(wrapper.find('[data-test="method-enabled"]').text()).toContain('Enabled')
  })

  it('shows the empty state for a zone with no methods', async () => {
    zonesPage.value = { zones: [zone({ methods: [] })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    expect(wrapper.find('[data-test="zone-methods-empty"]').exists()).toBe(true)
  })

  it('creates a flat method, converting "5.00" to exact minor units (500)', async () => {
    createMethodMock.mockResolvedValue(method({ uuid: 'm2', kind: 'flat', config: { amount: 500 }, warnings: [] }))
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="method-add"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="method-label-input"]').setValue('Standard')
    await wrapper.find('[data-test="method-amount-input"]').setValue('5.00')
    await wrapper.find('[data-test="method-form"]').trigger('submit')
    await flushPromises()

    expect(createMethodMock).toHaveBeenCalledWith({
      zoneUuid: 'z1',
      input: { kind: 'flat', label: 'Standard', config: { amount: 500 }, position: null, enabled: true },
    })
  })

  it('creates a free_over method with both rate and free-over threshold converted to minor units', async () => {
    createMethodMock.mockResolvedValue(method({ uuid: 'm3', kind: 'free_over' }))
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="method-add"]').trigger('click')
    await flushPromises()
    selectRootByTestId(wrapper, 'method-kind-input').vm.$emit('update:modelValue', 'free_over')
    await flushPromises()

    await wrapper.find('[data-test="method-label-input"]').setValue('Free over $50')
    await wrapper.find('[data-test="method-amount-input"]').setValue('5.00')
    await wrapper.find('[data-test="method-free-over-input"]').setValue('50.00')
    await wrapper.find('[data-test="method-form"]').trigger('submit')
    await flushPromises()

    expect(createMethodMock).toHaveBeenCalledWith({
      zoneUuid: 'z1',
      input: {
        kind: 'free_over',
        label: 'Free over $50',
        config: { amount: 500, free_over: 5000 },
        position: null,
        enabled: true,
      },
    })
  })

  it('creates a per_class_table method with default rate and per-class rates converted to minor units', async () => {
    createMethodMock.mockResolvedValue(method({ uuid: 'm4', kind: 'per_class_table' }))
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="method-add"]').trigger('click')
    await flushPromises()
    selectRootByTestId(wrapper, 'method-kind-input').vm.$emit('update:modelValue', 'per_class_table')
    await flushPromises()

    await wrapper.find('[data-test="method-label-input"]').setValue('By class')
    await wrapper.find('[data-test="method-default-amount-input"]').setValue('5.00')
    await wrapper.find('[data-test="method-class-add"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="method-class-slug-input"]').setValue('fragile')
    await wrapper.find('[data-test="method-class-amount-input"]').setValue('10.00')
    await wrapper.find('[data-test="method-form"]').trigger('submit')
    await flushPromises()

    expect(createMethodMock).toHaveBeenCalledWith({
      zoneUuid: 'z1',
      input: {
        kind: 'per_class_table',
        label: 'By class',
        config: { default_amount: 500, classes: { fragile: 1000 } },
        position: null,
        enabled: true,
      },
    })
  })

  it('shows a warning toast when the server returns unknown-class-slug warnings', async () => {
    createMethodMock.mockResolvedValue(
      method({ uuid: 'm5', kind: 'per_class_table', warnings: ['Unknown shipping class slug: fragile'] }),
    )
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="method-add"]').trigger('click')
    await flushPromises()
    selectRootByTestId(wrapper, 'method-kind-input').vm.$emit('update:modelValue', 'per_class_table')
    await flushPromises()
    await wrapper.find('[data-test="method-label-input"]').setValue('By class')
    await wrapper.find('[data-test="method-default-amount-input"]').setValue('5.00')
    await wrapper.find('[data-test="method-form"]').trigger('submit')
    await flushPromises()

    expect(notify.warning).toHaveBeenCalledTimes(1)
    expect(notify.warning.mock.calls[0]![1]).toContain('Unknown shipping class slug: fragile')
  })

  it('rejects an invalid amount client-side without calling the mutation', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="method-add"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="method-label-input"]').setValue('Standard')
    await wrapper.find('[data-test="method-amount-input"]').setValue('abc')
    await wrapper.find('[data-test="method-form"]').trigger('submit')
    await flushPromises()

    expect(createMethodMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="method-form-error"]').exists()).toBe(true)
  })

  it('pre-fills the edit form from the existing method (kind locked) and submits an update', async () => {
    zonesPage.value = {
      zones: [zone({ methods: [method({ uuid: 'm1', kind: 'flat', label: 'Standard', config: { amount: 500 } })] })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    updateMethodMock.mockResolvedValue(method({ uuid: 'm1', label: 'Standard Shipping', config: { amount: 700 } }))
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="method-edit"]').trigger('click')
    await flushPromises()
    expect((wrapper.find('[data-test="method-label-input"]').element as HTMLInputElement).value).toBe('Standard')
    expect((wrapper.find('[data-test="method-amount-input"]').element as HTMLInputElement).value).toBe('5.00')
    const kindSelect = selectRootByTestId(wrapper, 'method-kind-input')
    expect(kindSelect.props('disabled')).toBe(true)

    await wrapper.find('[data-test="method-label-input"]').setValue('Standard Shipping')
    await wrapper.find('[data-test="method-amount-input"]').setValue('7.00')
    await wrapper.find('[data-test="method-form"]').trigger('submit')
    await flushPromises()

    expect(updateMethodMock).toHaveBeenCalledWith({
      uuid: 'm1',
      zoneUuid: 'z1',
      input: { label: 'Standard Shipping', config: { amount: 700 }, position: 0, enabled: true },
    })
  })

  it('deletes a method only after confirming', async () => {
    deleteMethodMock.mockResolvedValue(undefined)
    const wrapper = mountPanel()
    await flushPromises()
    await expandZone(wrapper)

    await wrapper.find('[data-test="method-delete"]').trigger('click')
    await flushPromises()
    expect(deleteMethodMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="method-delete-confirm"]').exists()).toBe(true)

    await wrapper.find('[data-test="method-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(deleteMethodMock).toHaveBeenCalledWith({ uuid: 'm1', zoneUuid: 'z1' })
  })
})

// ── Read-only state (can_manage: false) ─────────────────────────────────────────────────────

describe('ZonesPanel: read-only state', () => {
  it('hides every mutation control while still rendering zone and method content', async () => {
    const wrapper = mount(ZonesPanel, { props: { canManage: false }, global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="new-zone"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="zone-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="zone-delete"]').exists()).toBe(false)

    await wrapper.find('[data-test="zone-expand-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="zone-locations-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="method-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="method-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="method-delete"]').exists()).toBe(false)

    // Read-only content stays visible.
    expect(wrapper.find('[data-test="zone-name"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="method-label"]').exists()).toBe(true)
  })
})

// ── Task 15b: ClassesPanel ───────────────────────────────────────────────────────────────────

describe('ClassesPanel: classes list', () => {
  it('renders a row per class with slug and name', async () => {
    classesPage.value = {
      classes: [shippingClass({ uuid: 'c1', slug: 'fragile', name: 'Fragile' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountClassesPanel()
    await flushPromises()

    const rows = wrapper.findAll('[data-test="class-row"]')
    expect(rows).toHaveLength(1)
    expect(wrapper.find('[data-test="class-slug"]').text()).toBe('fragile')
    expect(wrapper.find('[data-test="class-name"]').text()).toBe('Fragile')
  })

  it('shows the loading state', async () => {
    classesStatus.value = 'pending'
    const wrapper = mountClassesPanel()
    expect(wrapper.find('[data-test="classes-loading"]').exists()).toBe(true)
  })

  it('shows the error state', async () => {
    classesStatus.value = 'error'
    const wrapper = mountClassesPanel()
    expect(wrapper.find('[data-test="classes-error"]').exists()).toBe(true)
  })

  it('shows the empty state', async () => {
    classesPage.value = { classes: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mountClassesPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="classes-empty"]').exists()).toBe(true)
  })
})

describe('ClassesPanel: create/edit/delete', () => {
  it('creates a class with the entered slug and name', async () => {
    createClassMock.mockResolvedValue(shippingClass({ uuid: 'c2', slug: 'bulky', name: 'Bulky' }))
    const wrapper = mountClassesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-class"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="class-slug-input"]').setValue('bulky')
    await wrapper.find('[data-test="class-name-input"]').setValue('Bulky')
    await wrapper.find('form#class-form').trigger('submit')
    await flushPromises()

    expect(createClassMock).toHaveBeenCalledTimes(1)
    expect(createClassMock).toHaveBeenCalledWith({ slug: 'bulky', name: 'Bulky' })
    expect(notify.success).toHaveBeenCalledTimes(1)
  })

  it('rejects a blank slug or name client-side without calling the mutation', async () => {
    const wrapper = mountClassesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-class"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="class-name-input"]').setValue('Bulky')
    await wrapper.find('form#class-form').trigger('submit')
    await flushPromises()

    expect(createClassMock).not.toHaveBeenCalled()
  })

  it('pre-fills the edit form with the slug locked and submits an update with name only', async () => {
    updateClassMock.mockResolvedValue(shippingClass({ name: 'Extra Fragile' }))
    const wrapper = mountClassesPanel()
    await flushPromises()

    await wrapper.find('[data-test="class-edit"]').trigger('click')
    await flushPromises()
    const slugInput = wrapper.find('[data-test="class-slug-input"]')
    expect((slugInput.element as HTMLInputElement).value).toBe('fragile')
    expect((slugInput.element as HTMLInputElement).disabled).toBe(true)

    await wrapper.find('[data-test="class-name-input"]').setValue('Extra Fragile')
    await wrapper.find('form#class-form').trigger('submit')
    await flushPromises()

    expect(updateClassMock).toHaveBeenCalledWith({ uuid: 'c1', input: { name: 'Extra Fragile' } })
  })

  it('surfaces a 422 duplicate-slug field error inline', async () => {
    // Plain error-body object — see ZonesPanel's identical "surfaces a 422 duplicate-name field
    // error inline" test above for why a directly-constructed ApiError can't be used here.
    createClassMock.mockRejectedValue({
      success: false,
      message: 'Validation failed',
      error: {
        code: 422,
        timestamp: '2026-01-01T00:00:00Z',
        request_id: 'req_1',
        details: { slug: 'Slug already in use.' },
      },
    })
    const wrapper = mountClassesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-class"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="class-slug-input"]').setValue('fragile')
    await wrapper.find('[data-test="class-name-input"]').setValue('Fragile')
    await wrapper.find('form#class-form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Slug already in use.')
  })

  it('deletes a class only after confirming', async () => {
    deleteClassMock.mockResolvedValue(undefined)
    const wrapper = mountClassesPanel()
    await flushPromises()

    await wrapper.find('[data-test="class-delete"]').trigger('click')
    await flushPromises()
    expect(deleteClassMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="class-delete-confirm"]').exists()).toBe(true)

    await wrapper.find('[data-test="class-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(deleteClassMock).toHaveBeenCalledWith('c1')
  })

  it('surfaces the referenced-class 409 refusal as a toast, verbatim, rather than removing the row', async () => {
    // Plain error-body object, same reasoning as the 422 test above.
    deleteClassMock.mockRejectedValue({
      success: false,
      message: 'This shipping class is still assigned to one or more variants. Detach it first.',
      error: { code: 409, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1' },
    })
    const wrapper = mountClassesPanel()
    await flushPromises()

    await wrapper.find('[data-test="class-delete"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="class-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(deleteClassMock).toHaveBeenCalledWith('c1')
    expect(notify.error).toHaveBeenCalledTimes(1)
    expect(notify.error).toHaveBeenCalledWith(
      expect.objectContaining({
        message: 'This shipping class is still assigned to one or more variants. Detach it first.',
      }),
      expect.any(String),
    )
    // The row is still present — a 409 refusal must never be treated as a successful delete.
    expect(wrapper.find('[data-test="class-row"]').exists()).toBe(true)
  })
})

describe('ClassesPanel: read-only state', () => {
  it('hides every mutation control while still rendering class content', async () => {
    const wrapper = mountClassesPanel(false)
    await flushPromises()

    expect(wrapper.find('[data-test="new-class"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="class-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="class-delete"]').exists()).toBe(false)

    // Read-only content stays visible.
    expect(wrapper.find('[data-test="class-slug"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="class-name"]').exists()).toBe(true)
  })
})

// ── Task 15c: TaxRatesPanel ──────────────────────────────────────────────────────────────────
//
// `rate_bps` is bps of a percent, IDENTICAL convention to a `percentage` discount's `value`
// (commerceSettings.ts's own `CommerceTaxRate` docblock) — the form must round-trip it via
// `parseMajorAmountToMinorUnits(input, 2)`/`minorToDecimalString(bps, 2)` EXACTLY like
// DiscountForm.vue's percentage handling, never `Number()`.

describe('TaxRatesPanel: rates list', () => {
  it('renders a row per rate with label, country, percent, class, and priority', async () => {
    ratesPage.value = {
      rates: [taxRate({ uuid: 'r1', country: 'US', label: 'Sales Tax', rate_bps: 875, class: 'standard', priority: 3 })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountRatesPanel()
    await flushPromises()

    const rows = wrapper.findAll('[data-test="rate-row"]')
    expect(rows).toHaveLength(1)
    expect(wrapper.find('[data-test="rate-label"]').text()).toBe('Sales Tax')
    expect(wrapper.find('[data-test="rate-country"]').text()).toBe('US')
    expect(wrapper.find('[data-test="rate-percent"]').text()).toBe('8.75%')
    expect(wrapper.find('[data-test="rate-class"]').text()).toBe('standard')
    expect(wrapper.find('[data-test="rate-priority"]').text()).toContain('3')
  })

  it('shows an integer percent without a trailing fraction (1000 bps -> "10%")', async () => {
    ratesPage.value = { rates: [taxRate({ rate_bps: 1000 })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountRatesPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="rate-percent"]').text()).toBe('10%')
  })

  it('shows the exact fractional percent for a non-round bps value (875 -> "8.75%")', async () => {
    ratesPage.value = { rates: [taxRate({ rate_bps: 875 })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountRatesPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="rate-percent"]').text()).toBe('8.75%')
  })

  it('shows the 100% boundary exactly (10000 bps -> "100%")', async () => {
    ratesPage.value = { rates: [taxRate({ rate_bps: 10000 })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountRatesPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="rate-percent"]').text()).toBe('100%')
  })

  it('shows the state/postcode narrowing when present, nothing when absent', async () => {
    ratesPage.value = {
      rates: [taxRate({ state: 'US:CA', postcode_pattern: '90*' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountRatesPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="rate-location"]').text()).toContain('US:CA')
    expect(wrapper.find('[data-test="rate-location"]').text()).toContain('90*')

    ratesPage.value = { rates: [taxRate({ state: null, postcode_pattern: null })], total: 1, current_page: 1, per_page: 24 }
    const wrapper2 = mountRatesPanel()
    await flushPromises()
    expect(wrapper2.find('[data-test="rate-location"]').exists()).toBe(false)
  })

  it('shows a shipping-taxable badge only when true', async () => {
    ratesPage.value = { rates: [taxRate({ shipping_taxable: true })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountRatesPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="rate-shipping-taxable"]').exists()).toBe(true)

    ratesPage.value = { rates: [taxRate({ shipping_taxable: false })], total: 1, current_page: 1, per_page: 24 }
    const wrapper2 = mountRatesPanel()
    await flushPromises()
    expect(wrapper2.find('[data-test="rate-shipping-taxable"]').exists()).toBe(false)
  })

  it('shows the loading state', async () => {
    ratesStatus.value = 'pending'
    const wrapper = mountRatesPanel()
    expect(wrapper.find('[data-test="rates-loading"]').exists()).toBe(true)
  })

  it('shows the error state', async () => {
    ratesStatus.value = 'error'
    const wrapper = mountRatesPanel()
    expect(wrapper.find('[data-test="rates-error"]').exists()).toBe(true)
  })

  it('shows the empty state', async () => {
    ratesPage.value = { rates: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mountRatesPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="rates-empty"]').exists()).toBe(true)
  })
})

describe('TaxRatesPanel: rate create/edit', () => {
  it('creates a rate, converting the entered percent to exact bps', async () => {
    createRateMock.mockResolvedValue(taxRate({ uuid: 'r2', country: 'CA', rate_bps: 500, label: 'GST' }))
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-rate"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="rate-country-input"]').setValue('ca')
    await wrapper.find('[data-test="rate-label-input"]').setValue('GST')
    await wrapper.find('[data-test="rate-percent-input"]').setValue('5.00')
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(createRateMock).toHaveBeenCalledTimes(1)
    expect(createRateMock).toHaveBeenCalledWith({
      country: 'ca',
      state: null,
      postcode_pattern: null,
      rate_bps: 500,
      label: 'GST',
      priority: 0,
      shipping_taxable: false,
      class: null,
    })
    expect(notify.success).toHaveBeenCalledTimes(1)
  })

  it('allows a 0% rate — bps 0 is a valid boundary (unlike a percentage discount, which requires >=1)', async () => {
    createRateMock.mockResolvedValue(taxRate({ uuid: 'r3', rate_bps: 0 }))
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-rate"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="rate-country-input"]').setValue('US')
    await wrapper.find('[data-test="rate-label-input"]').setValue('Zero Rate')
    await wrapper.find('[data-test="rate-percent-input"]').setValue('0.00')
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(createRateMock).toHaveBeenCalledWith(expect.objectContaining({ rate_bps: 0 }))
  })

  it('captures optional state/postcode/priority/shipping-taxable/class fields', async () => {
    createRateMock.mockResolvedValue(taxRate({ uuid: 'r4' }))
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-rate"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="rate-country-input"]').setValue('US')
    await wrapper.find('[data-test="rate-state-input"]').setValue('US:CA')
    await wrapper.find('[data-test="rate-postcode-input"]').setValue('90*')
    await wrapper.find('[data-test="rate-label-input"]').setValue('CA Sales Tax')
    await wrapper.find('[data-test="rate-percent-input"]').setValue('7.25')
    await wrapper.find('[data-test="rate-priority-input"]').setValue('5')
    await wrapper.find('[data-test="rate-class-input"]').setValue('reduced')
    // UCheckbox's root is a Reka UI CheckboxRoot (a <button>, not a native input) — drive it
    // directly, same as `selectRootByTestId()`'s reasoning for USelect elsewhere in this file
    // (mirrors commerceProducts.spec.ts's established `CheckboxRoot` + `update:modelValue` pattern).
    await wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(createRateMock).toHaveBeenCalledWith({
      country: 'US',
      state: 'US:CA',
      postcode_pattern: '90*',
      rate_bps: 725,
      label: 'CA Sales Tax',
      priority: 5,
      shipping_taxable: true,
      class: 'reduced',
    })
  })

  it('rejects a blank country or label client-side without calling the mutation', async () => {
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-rate"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="rate-percent-input"]').setValue('5.00')
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(createRateMock).not.toHaveBeenCalled()
  })

  it('rejects an unparseable percent client-side without calling the mutation', async () => {
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-rate"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="rate-country-input"]').setValue('US')
    await wrapper.find('[data-test="rate-label-input"]').setValue('Sales Tax')
    await wrapper.find('[data-test="rate-percent-input"]').setValue('abc')
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(createRateMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="rate-form-error"]').exists()).toBe(true)
  })

  it('rejects a percent above 100 client-side without calling the mutation', async () => {
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-rate"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="rate-country-input"]').setValue('US')
    await wrapper.find('[data-test="rate-label-input"]').setValue('Sales Tax')
    await wrapper.find('[data-test="rate-percent-input"]').setValue('100.01')
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(createRateMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="rate-form-error"]').exists()).toBe(true)
  })

  it('pre-fills the edit form with the exact percent round-trip and submits an update with the full field set', async () => {
    ratesPage.value = {
      rates: [
        taxRate({
          uuid: 'r1',
          country: 'US',
          state: 'US:CA',
          postcode_pattern: '90*',
          rate_bps: 875,
          label: 'Sales Tax',
          priority: 5,
          shipping_taxable: true,
          class: 'reduced',
        }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    updateRateMock.mockResolvedValue(taxRate({ label: 'Updated Tax' }))
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="rate-edit"]').trigger('click')
    await flushPromises()

    expect((wrapper.find('[data-test="rate-country-input"]').element as HTMLInputElement).value).toBe('US')
    expect((wrapper.find('[data-test="rate-state-input"]').element as HTMLInputElement).value).toBe('US:CA')
    expect((wrapper.find('[data-test="rate-postcode-input"]').element as HTMLInputElement).value).toBe('90*')
    expect((wrapper.find('[data-test="rate-percent-input"]').element as HTMLInputElement).value).toBe('8.75')
    expect((wrapper.find('[data-test="rate-label-input"]').element as HTMLInputElement).value).toBe('Sales Tax')
    expect((wrapper.find('[data-test="rate-priority-input"]').element as HTMLInputElement).value).toBe('5')
    expect((wrapper.find('[data-test="rate-class-input"]').element as HTMLInputElement).value).toBe('reduced')
    // See the "captures optional ... fields" test above for why CheckboxRoot is driven directly.
    expect(wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]!.props('modelValue')).toBe(true)

    await wrapper.find('[data-test="rate-label-input"]').setValue('Updated Tax')
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(updateRateMock).toHaveBeenCalledWith({
      uuid: 'r1',
      input: {
        country: 'US',
        state: 'US:CA',
        postcode_pattern: '90*',
        rate_bps: 875,
        label: 'Updated Tax',
        priority: 5,
        shipping_taxable: true,
        class: 'reduced',
      },
    })
  })

  it('round-trips the 100% boundary (10000 bps) through the edit form without float drift', async () => {
    ratesPage.value = { rates: [taxRate({ rate_bps: 10000 })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="rate-edit"]').trigger('click')
    await flushPromises()
    expect((wrapper.find('[data-test="rate-percent-input"]').element as HTMLInputElement).value).toBe('100.00')
  })

  it('clears state and postcode_pattern when left blank on an edit that previously had them', async () => {
    ratesPage.value = {
      rates: [taxRate({ uuid: 'r1', state: 'US:CA', postcode_pattern: '90210' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    updateRateMock.mockResolvedValue(taxRate({ state: null, postcode_pattern: null }))
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="rate-edit"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="rate-state-input"]').setValue('')
    await wrapper.find('[data-test="rate-postcode-input"]').setValue('')
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(updateRateMock).toHaveBeenCalledWith(
      expect.objectContaining({ input: expect.objectContaining({ state: null, postcode_pattern: null }) }),
    )
  })

  it("sends class:'standard' when the Class field is blanked on an EDIT (null would silently no-op server-side)", async () => {
    // planUpdate() applies class only when non-null — so edit-blank must send 'standard'
    // explicitly to honor the help text; create-blank keeps null (create defaults it).
    ratesPage.value = {
      rates: [taxRate({ uuid: 'r1', class: 'reduced' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    updateRateMock.mockResolvedValue(taxRate({ class: 'standard' }))
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="rate-edit"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="rate-class-input"]').setValue('')
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(updateRateMock).toHaveBeenCalledWith(
      expect.objectContaining({ input: expect.objectContaining({ class: 'standard' }) }),
    )
  })

  it('surfaces a 422 field error inline', async () => {
    // Plain error-body object — see ZonesPanel's identical 422 test for why a directly-constructed
    // ApiError can't be used here (this file's module registry is reset per test).
    createRateMock.mockRejectedValue({
      success: false,
      message: 'Validation failed',
      error: {
        code: 422,
        timestamp: '2026-01-01T00:00:00Z',
        request_id: 'req_1',
        details: { country: 'country must be an ISO-3166 alpha-2 code.' },
      },
    })
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="new-rate"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="rate-country-input"]').setValue('USA')
    await wrapper.find('[data-test="rate-label-input"]').setValue('Bad')
    await wrapper.find('[data-test="rate-percent-input"]').setValue('5.00')
    await wrapper.find('form#rate-form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('country must be an ISO-3166 alpha-2 code.')
  })

  it('deletes a rate only after confirming', async () => {
    deleteRateMock.mockResolvedValue(undefined)
    const wrapper = mountRatesPanel()
    await flushPromises()

    await wrapper.find('[data-test="rate-delete"]').trigger('click')
    await flushPromises()
    expect(deleteRateMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="rate-delete-confirm"]').exists()).toBe(true)

    await wrapper.find('[data-test="rate-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(deleteRateMock).toHaveBeenCalledWith('r1')
  })
})

describe('TaxRatesPanel: read-only state', () => {
  it('hides every mutation control while still rendering rate content', async () => {
    const wrapper = mountRatesPanel(false)
    await flushPromises()

    expect(wrapper.find('[data-test="new-rate"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rate-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rate-delete"]').exists()).toBe(false)

    // Read-only content stays visible.
    expect(wrapper.find('[data-test="rate-label"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="rate-percent"]').exists()).toBe(true)
  })
})

// ── Settings tab shell ───────────────────────────────────────────────────────────────────────

describe('Settings page tab shell', () => {
  it('renders all four tabs with Store leading as the default', async () => {
    const wrapper = mount(SettingsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('Store')
    expect(wrapper.text()).toContain('Shipping zones')
    expect(wrapper.text()).toContain('Shipping classes')
    expect(wrapper.text()).toContain('Tax rates')
    // Store is the DEFAULT tab (store-settings spec §3.5); Zones renders only after a switch.
    expect(wrapper.findComponent(StorePanel).exists()).toBe(true)
    expect(wrapper.findComponent(ZonesPanel).exists()).toBe(false)
  })

  it('passes can_manage through to the panels (Store default, Zones after a switch)', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    const wrapper = mount(SettingsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findComponent(StorePanel).props('canManage')).toBe(false)

    const tabs = wrapper.findAll('[role="tab"]')
    const zonesTab = tabs.find((t) => t.text() === 'Shipping zones')
    await zonesTab!.trigger('mousedown', { button: 0 })
    await flushPromises()
    expect(wrapper.findComponent(ZonesPanel).props('canManage')).toBe(false)
  })

  it('switches to the Shipping classes tab, rendering ClassesPanel with can_manage passed through', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    const wrapper = mount(SettingsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findComponent(ClassesPanel).exists()).toBe(false)

    const tabs = wrapper.findAll('[role="tab"]')
    const classesTab = tabs.find((t) => t.text() === 'Shipping classes')
    await classesTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    expect(wrapper.findComponent(ZonesPanel).exists()).toBe(false)
    expect(wrapper.findComponent(ClassesPanel).exists()).toBe(true)
    expect(wrapper.findComponent(ClassesPanel).props('canManage')).toBe(false)
  })

  it('switches to the Tax rates tab, rendering TaxRatesPanel with can_manage passed through', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    const wrapper = mount(SettingsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findComponent(TaxRatesPanel).exists()).toBe(false)

    const tabs = wrapper.findAll('[role="tab"]')
    const ratesTab = tabs.find((t) => t.text() === 'Tax rates')
    await ratesTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    expect(wrapper.findComponent(ZonesPanel).exists()).toBe(false)
    expect(wrapper.findComponent(ClassesPanel).exists()).toBe(false)
    expect(wrapper.findComponent(TaxRatesPanel).exists()).toBe(true)
    expect(wrapper.findComponent(TaxRatesPanel).props('canManage')).toBe(false)
  })
})

// ── StorePanel: runtime store settings (store-settings spec §3.5) ─────────────────────────────

function storeSettings(
  overrides: Partial<import('@/queries/commerceSettings').StoreSettings> = {},
): import('@/queries/commerceSettings').StoreSettings {
  return {
    settings: {
      'commerce.currency': { value: 'USD', default: 'USD', overridden: false },
      'commerce.tax.flat_rate_bps': { value: 0, default: 0, overridden: false },
      'commerce.orders.number_format': { value: 'ORD-{seq}', default: 'ORD-{seq}', overridden: false },
      'commerce.orders.expiry_minutes': { value: 60, default: 60, overridden: false },
      'commerce.cart.ttl_days': { value: 30, default: 30, overridden: false },
      'commerce.reports.low_stock_threshold': { value: 2, default: 2, overridden: false },
      'commerce.downloads.url_ttl': { value: 300, default: 300, overridden: false },
      'commerce.seller.name': { value: '', default: '', overridden: false },
      'commerce.seller.address': { value: '', default: '', overridden: false },
      'commerce.seller.tax_id': { value: '', default: '', overridden: false },
      ...overrides.settings,
    },
    currency_locked: overrides.currency_locked ?? false,
    has_priced_products: overrides.has_priced_products ?? false,
  }
}

describe('StorePanel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    storeSettingsData.value = storeSettings()
    storeSettingsStatus.value = 'success'
    saveStoreSettingsMock.mockReset()
    saveStoreSettingsMock.mockResolvedValue(storeSettings())
  })

  function mountPanel(canManage = true) {
    return mount(StorePanel, { props: { canManage }, global: { stubs: pageStubs } })
  }

  it('hydrates every field from the effective values (bps shown as a percent)', async () => {
    storeSettingsData.value = storeSettings({
      settings: {
        'commerce.currency': { value: 'GHS', default: 'USD', overridden: true },
        'commerce.tax.flat_rate_bps': { value: 750, default: 0, overridden: true },
      } as never,
    })
    const wrapper = mountPanel()
    await flushPromises()

    // The currency control is a USelect now — the trigger renders the selected item's label.
    expect(wrapper.find('[data-test="store-currency-input"]').text()).toContain('GHS')
    // 750 bps reads as 7.5 (%).
    expect((wrapper.find('[data-test="store-tax-input"]').element as HTMLInputElement).value).toBe('7.5')
    expect(
      (wrapper.find('[data-test="store-number-format-input"]').element as HTMLInputElement).value,
    ).toBe('ORD-{seq}')
  })

  it('shows the default-help on unoverridden fields and a live number-format preview', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.text()).toContain('Default: USD — from server config')
    expect(wrapper.find('[data-test="store-number-format-preview"]').text()).toContain('ORD-1042')

    await wrapper.find('[data-test="store-number-format-input"]').setValue('THL-{seq}')
    expect(wrapper.find('[data-test="store-number-format-preview"]').text()).toContain('THL-1042')
  })

  it('locks the currency input with the lock reason once priced products exist', async () => {
    storeSettingsData.value = storeSettings({ currency_locked: true })
    const wrapper = mountPanel()
    await flushPromises()

    const input = wrapper.find('[data-test="store-currency-input"]')
    expect(input.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Locked — orders exist')
  })

  it('warns (without locking) when priced products exist but no orders do', async () => {
    storeSettingsData.value = storeSettings({ has_priced_products: true })
    const wrapper = mountPanel()
    await flushPromises()

    // Editable — the lock is about recorded money, not catalog contents.
    expect(
      wrapper.find('[data-test="store-currency-input"]').attributes('disabled'),
    ).toBeUndefined()
    expect(wrapper.text()).toContain('Existing prices keep their numbers')
  })

  it('saves percent as basis points and blanks as null (clear-to-default)', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="store-tax-input"]').setValue('7.5')
    await wrapper.find('[data-test="store-cart-ttl-input"]').setValue('')
    await wrapper.find('[data-test="store-settings-save"]').trigger('click')
    await flushPromises()

    expect(saveStoreSettingsMock).toHaveBeenCalledTimes(1)
    const body = saveStoreSettingsMock.mock.calls[0]![0]
    expect(body['commerce.tax.flat_rate_bps']).toBe(750)
    expect(body['commerce.cart.ttl_days']).toBeNull()
    // Currency rides uppercased.
    expect(body['commerce.currency']).toBe('USD')
  })

  it('maps a server field 422 onto the field', async () => {
    // Raw framework error envelope, not a constructed ApiError — module-identity precedent
    // documented at this file's zone 422 test.
    saveStoreSettingsMock.mockRejectedValue({
      success: false,
      message: 'Validation failed',
      error: {
        code: 422,
        timestamp: '2026-01-01T00:00:00Z',
        request_id: 'req_1',
        details: {
          'commerce.currency':
            'Currency is locked once priced products exist — every variant price is an integer in the store currency.',
        },
      },
    })
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="store-settings-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('locked once priced products exist')
  })

  it('saves the store identity fields, blank as null', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="store-seller-name-input"]').setValue('Aurora Lighting Co.')
    await wrapper.find('[data-test="store-seller-tax-id-input"]').setValue('GH-TIN-0042')
    await wrapper.find('[data-test="store-downloads-ttl-input"]').setValue('3600')
    await wrapper.find('[data-test="store-settings-save"]').trigger('click')
    await flushPromises()

    const body = saveStoreSettingsMock.mock.calls[0]![0]
    expect(body['commerce.seller.name']).toBe('Aurora Lighting Co.')
    expect(body['commerce.seller.tax_id']).toBe('GH-TIN-0042')
    expect(body['commerce.seller.address']).toBeNull()
    expect(body['commerce.downloads.url_ttl']).toBe('3600')
  })

  it('renders the Settings › Email pointer for order emails', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    const pointer = wrapper.find('[data-test="store-email-pointer"]')
    expect(pointer.exists()).toBe(true)
    expect(pointer.text()).toContain('Emails tab')
  })

  it('disables inputs and hides Save without manage rights', async () => {
    const wrapper = mountPanel(false)
    await flushPromises()

    expect(wrapper.find('[data-test="store-settings-save"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-test="store-tax-input"]').attributes('disabled'),
    ).toBeDefined()
  })
})

describe('commerce settings route gating', () => {
  it('the settings route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(process.cwd(), 'src/pages/commerce/settings/index.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })
})

// ── PaymentsPanel: gateway configuration (store-settings spec §3.6) ───────────────────────────

function paymentsSettings(
  overrides: Partial<import('@/queries/commerceSettings').PaymentsSettings> = {},
): import('@/queries/commerceSettings').PaymentsSettings {
  return {
    mode: overrides.mode ?? 'gateway',
    default_gateway: overrides.default_gateway ?? { value: 'paystack', default: 'paystack', overridden: false },
    gateways: overrides.gateways ?? [
      {
        id: 'paystack',
        enabled: { value: true, default: true, overridden: false },
        secret_key: { set: false, source: null },
        webhook_secret: { set: false, source: null },
        default: true,
        webhook_url: 'https://shop.example/webhooks/paystack',
      },
      {
        id: 'stripe',
        enabled: { value: false, default: false, overridden: false },
        secret_key: { set: false, source: null },
        webhook_secret: { set: false, source: null },
        default: false,
        webhook_url: 'https://shop.example/webhooks/stripe',
      },
    ],
  }
}

describe('PaymentsPanel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    paymentsData.value = paymentsSettings()
    paymentsStatus.value = 'success'
    savePaymentsMock.mockReset()
    savePaymentsMock.mockResolvedValue(paymentsSettings())
  })

  function mountPayments(canManage = true) {
    return mount(PaymentsPanel, { props: { canManage }, global: { stubs: pageStubs } })
  }

  it('shows the manual-collection note when no gateway extension is installed', async () => {
    paymentsData.value = paymentsSettings({ mode: 'manual', gateways: [] })
    const wrapper = mountPayments()
    await flushPromises()

    expect(wrapper.find('[data-test="payments-manual"]').text()).toContain('Manual collection')
    expect(wrapper.findAll('[data-test="payments-gateway-card"]')).toHaveLength(0)
  })

  it('renders a card per gateway with write-only secret inputs (never a stored value)', async () => {
    paymentsData.value = paymentsSettings({
      gateways: [
        {
          id: 'paystack',
          enabled: { value: true, default: true, overridden: false },
          secret_key: { set: true, source: 'settings' },
          webhook_secret: { set: true, source: 'env' },
          default: true,
          webhook_url: 'https://shop.example/webhooks/paystack',
        },
      ],
    })
    const wrapper = mountPayments()
    await flushPromises()

    const input = wrapper.find('[data-test="payments-secret-paystack-secret_key"]')
    // Write-only: the input NEVER carries a stored value — only the set-state placeholder.
    expect((input.element as HTMLInputElement).value).toBe('')
    expect(input.attributes('placeholder')).toContain('stored')
    expect(wrapper.text()).toContain('A key is stored (encrypted). Leave blank to keep it.')
    expect(wrapper.text()).toContain('Using the key from .env.')
    // The copy-able dashboard URL renders per card.
    const urlRow = wrapper.find('[data-test="payments-webhook-url-paystack"]')
    expect(urlRow.text()).toContain('https://shop.example/webhooks/paystack')
    expect(wrapper.find('[data-test="payments-webhook-copy-paystack"]').exists()).toBe(true)
  })

  it('sends only changed fields: typed secrets ride the payload, untouched ones are absent', async () => {
    const wrapper = mountPayments()
    await flushPromises()

    await wrapper.find('[data-test="payments-secret-paystack-secret_key"]').setValue('sk_live_new123')
    await wrapper.find('[data-test="payments-save"]').trigger('click')
    await flushPromises()

    const body = savePaymentsMock.mock.calls[0]![0]
    expect(body.gateways.paystack.secret_key).toBe('sk_live_new123')
    expect(body.gateways.paystack).not.toHaveProperty('webhook_secret')
    expect(body.gateways.paystack).not.toHaveProperty('enabled')
    expect(body).not.toHaveProperty('default_gateway')
  })

  it('Clear sends an explicit null for a settings-stored secret', async () => {
    paymentsData.value = paymentsSettings({
      gateways: [
        {
          id: 'paystack',
          enabled: { value: true, default: true, overridden: false },
          secret_key: { set: true, source: 'settings' },
          webhook_secret: { set: false, source: null },
          default: true,
          webhook_url: 'https://shop.example/webhooks/paystack',
        },
      ],
    })
    const wrapper = mountPayments()
    await flushPromises()

    await wrapper.find('[data-test="payments-clear-paystack-secret_key"]').trigger('click')
    await wrapper.find('[data-test="payments-save"]').trigger('click')
    await flushPromises()

    const body = savePaymentsMock.mock.calls[0]![0]
    expect(body.gateways.paystack.secret_key).toBeNull()
  })

  it('hides Save and disables inputs without manage rights', async () => {
    const wrapper = mountPayments(false)
    await flushPromises()

    expect(wrapper.find('[data-test="payments-save"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-test="payments-secret-paystack-secret_key"]').attributes('disabled'),
    ).toBeDefined()
  })
})

// ── EmailsPanel: order-email switches + relocated template editors (spec §4.2 follow-up) ──────

function emailSwitches(
  overrides: Partial<import('@/queries/commerceSettings').CommerceEmailSettings> = {},
): import('@/queries/commerceSettings').CommerceEmailSettings {
  return {
    templates: overrides.templates ?? [
      { template: 'order_confirmation', key: 'commerce.order_confirmation', enabled: { value: true, default: true, overridden: false } },
      { template: 'order_paid', key: 'commerce.order_paid', enabled: { value: true, default: true, overridden: false } },
      { template: 'order_fulfilled', key: 'commerce.order_fulfilled', enabled: { value: true, default: true, overridden: false } },
      { template: 'order_canceled', key: 'commerce.order_canceled', enabled: { value: false, default: true, overridden: true } },
    ],
    commerce_mailer_active: overrides.commerce_mailer_active ?? false,
  }
}

function registryTemplate(key: string, owner = 'thallo-commerce') {
  return {
    key,
    label: key.replace('commerce.', '').replace(/_/g, ' '),
    description: `Sent for ${key}`,
    owner,
    placeholders: [],
    subject: 'Subject',
    body: '<p>Body</p>',
    overridden: false,
  }
}

describe('EmailsPanel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    emailSettingsData.value = emailSwitches()
    emailSettingsStatus.value = 'success'
    saveEmailSettingsMock.mockReset()
    saveEmailSettingsMock.mockResolvedValue(emailSwitches())
    fetchEmailTemplatesMock.mockReset()
    fetchEmailTemplatesMock.mockResolvedValue({
      templates: [
        registryTemplate('commerce.order_confirmation'),
        registryTemplate('commerce.order_paid'),
        registryTemplate('commerce.order_fulfilled'),
        registryTemplate('commerce.order_canceled'),
        registryTemplate('password.reset', 'glueful/email-notification'),
      ],
      partials: [],
    })
  })

  function mountEmails(canManage = true) {
    return mount(EmailsPanel, {
      props: { canManage },
      global: { stubs: { ...pageStubs, TemplateRow: true } },
    })
  }

  it('renders one card per commerce template — foreign-owner templates never leak in', async () => {
    const wrapper = mountEmails()
    await flushPromises()

    expect(wrapper.findAll('[data-test="emails-template-card"]')).toHaveLength(4)
    expect(wrapper.text()).not.toContain('password.reset')
    // The editor sits behind a collapsed trigger (same shape as Settings › Email), with the
    // custom/default badge visible without expanding.
    expect(wrapper.find('[data-test="emails-edit-toggle-order_paid"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="emails-edit-badge-order_paid"]').text()).toBe('default')
  })

  it('a toggle change saves that single switch immediately', async () => {
    const wrapper = mountEmails()
    await flushPromises()

    const toggle = wrapper.findComponent<{ $emit: (e: string, v: boolean) => void }>(
      '[data-test="emails-toggle-order_paid"]',
    )
    toggle.vm.$emit('update:modelValue', false)
    await flushPromises()

    expect(saveEmailSettingsMock).toHaveBeenCalledWith({ templates: { order_paid: false } })
  })

  it('banners when the commerce extension’s own mailer is active', async () => {
    emailSettingsData.value = emailSwitches({ commerce_mailer_active: true })
    const wrapper = mountEmails()
    await flushPromises()

    expect(wrapper.find('[data-test="emails-mailer-active"]').exists()).toBe(true)
  })

  it('disables the switches without manage rights', async () => {
    const wrapper = mountEmails(false)
    await flushPromises()

    const toggle = wrapper.findComponent('[data-test="emails-toggle-order_paid"]')
    expect(toggle.attributes('disabled')).toBeDefined()
  })
})
