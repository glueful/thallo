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
  }
})

import ZonesPanel from '@/pages/commerce/settings/components/ZonesPanel.vue'
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
  createZoneMock.mockReset()
  updateZoneMock.mockReset()
  deleteZoneMock.mockReset()
  setLocationsMock.mockReset()
  createMethodMock.mockReset()
  updateMethodMock.mockReset()
  deleteMethodMock.mockReset()
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

function mountPanel() {
  return mount(ZonesPanel, { props: { canManage: true }, global: { stubs: pageStubs } })
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

// ── Settings tab shell ───────────────────────────────────────────────────────────────────────

describe('Settings page tab shell', () => {
  it('renders only the completed Shipping zones tab (no Shipping classes / Tax rates tabs yet)', async () => {
    const wrapper = mount(SettingsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('Shipping zones')
    expect(wrapper.text()).not.toContain('Shipping classes')
    expect(wrapper.text()).not.toContain('Tax rates')
    expect(wrapper.findComponent(ZonesPanel).exists()).toBe(true)
  })

  it('passes can_manage through to ZonesPanel', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    const wrapper = mount(SettingsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.findComponent(ZonesPanel).props('canManage')).toBe(false)
  })
})

describe('commerce settings route gating', () => {
  it('the settings route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(process.cwd(), 'src/pages/commerce/settings/index.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })
})
