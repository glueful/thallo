import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { CommerceProduct, ProductListPage } from '@/queries/commerceCatalog'

// ── Shared mock state (referenced inside vi.mock factories) ────────────────────────────────────
//
// These are real Vue refs (not vi.hoisted() — its callback runs EAGERLY, before the file's own
// `import { ref } from 'vue'` binding is live, so calling ref() inside one crashes). A vi.mock()
// factory itself runs lazily, the first time the mocked module is actually imported — by then
// these plain `const` declarations above it have already executed (mirrors regionsPage.spec.ts's
// `const regionsData = ref(...)` referenced from `vi.mock('@/queries/regions', ...)`). Real refs
// matter here: values that flow through a template prop binding (e.g. ProductDetail's
// `:product="product"`) rely on Vue's auto-unwrap, which only triggers for genuine refs.

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

const routeState = vi.hoisted(() => ({
  params: {} as Record<string, string>,
  query: {} as Record<string, string>,
}))
const routerPush = vi.hoisted(() => vi.fn())
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ push: routerPush, resolve: vi.fn() }),
}))
// Nuxt UI's Link (behind UButton's `to` prop and <RouterLink>) resolves useRoute/useRouter from
// vue-router/auto — mirrors navigationPage.spec.ts's established pattern. importOriginal keeps
// the real RouterLink export (Link.vue renders through it directly, not by stubbable tag name).
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ push: routerPush, resolve: vi.fn() }),
}))

const productsPage = ref<ProductListPage | undefined>(undefined)
const productsStatus = ref<'pending' | 'error' | 'success'>('success')
const singleProduct = ref<CommerceProduct | undefined>(undefined)
const singleStatus = ref<'pending' | 'error' | 'success'>('success')
const createMock = vi.hoisted(() => vi.fn())
const updateMock = vi.hoisted(() => vi.fn())
const removeMock = vi.hoisted(() => vi.fn())
const bulkStatusMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/commerceCatalog', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceCatalog')>()
  return {
    ...actual,
    useCommerceProducts: () => ({ data: productsPage, status: productsStatus }),
    useCommerceProduct: () => ({ data: singleProduct, status: singleStatus }),
    useCommerceProductMutations: () => ({
      create: { mutateAsync: createMock, isLoading: ref(false) },
      update: { mutateAsync: updateMock, isLoading: ref(false) },
      remove: { mutateAsync: removeMock, isLoading: ref(false) },
      bulkStatus: { mutateAsync: bulkStatusMock, isLoading: ref(false) },
    }),
  }
})

import ProductsTable from '@/pages/commerce/products/components/ProductsTable.vue'
import ProductForm from '@/pages/commerce/products/components/ProductForm.vue'
import ProductsIndex from '@/pages/commerce/products/index.vue'
import ProductDetail from '@/pages/commerce/products/[uuid]/index.vue'

function product(overrides: Partial<CommerceProduct> = {}): CommerceProduct {
  return {
    uuid: 'p1',
    slug: 'widget',
    name: 'Widget',
    description: null,
    type: 'physical',
    status: 'active',
    tax_class: null,
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-02 00:00:00',
    variants: [],
    ...overrides,
  }
}

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
// UModal/USlideover teleport their body/footer out of the wrapper — stub both to render the
// slots inline, mirroring collectionsFieldEditor.spec.ts's DropConfirmModal precedent.
const teleportStub = { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' }
const pageStubs = { RouterLink: RouterLinkStub, Modal: teleportStub, Slideover: teleportStub }

/** Find the Reka SelectRoot ancestor of a USelect carrying `dataTest`, and drive it directly —
 * USelect's options render in a portal, so opening the dropdown in jsdom is unreliable; emitting
 * `update:modelValue` on the underlying SelectRoot is the established pattern (navigationPage.spec.ts). */
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
  routeState.params = {}
  routeState.query = {}
  productsPage.value = { products: [], total: 0, current_page: 1, per_page: 24 }
  productsStatus.value = 'success'
  singleProduct.value = undefined
  singleStatus.value = 'success'
  createMock.mockReset()
  updateMock.mockReset()
  removeMock.mockReset()
  bulkStatusMock.mockReset()
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
  routerPush.mockReset()
})

// ── ProductsTable: rows, loading/empty/error, can_manage gating ─────────────────────────────

describe('ProductsTable', () => {
  const rows = [product({ uuid: 'p1', name: 'Widget' }), product({ uuid: 'p2', name: 'Gadget', slug: 'gadget' })]

  it('renders one row per product', () => {
    const wrapper = mount(ProductsTable, {
      props: { rows, status: 'success', canManage: true, selected: [] },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })
    expect(wrapper.findAll('[data-test="product-row"]')).toHaveLength(2)
  })

  it('shows the loading state', () => {
    const wrapper = mount(ProductsTable, {
      props: { rows: [], status: 'pending', canManage: true, selected: [] },
    })
    expect(wrapper.find('[data-test="products-loading"]').exists()).toBe(true)
  })

  it('shows the empty state', () => {
    const wrapper = mount(ProductsTable, {
      props: { rows: [], status: 'success', canManage: true, selected: [] },
    })
    expect(wrapper.find('[data-test="products-empty"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    const wrapper = mount(ProductsTable, {
      props: { rows: [], status: 'error', canManage: true, selected: [] },
    })
    expect(wrapper.find('[data-test="products-error"]').exists()).toBe(true)
  })

  it('hides all mutation controls when can_manage is false', () => {
    const wrapper = mount(ProductsTable, {
      props: { rows, status: 'success', canManage: false, selected: [] },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })
    expect(wrapper.find('[data-test="product-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-select"]').exists()).toBe(false)
    // Read-only: rows themselves stay visible.
    expect(wrapper.findAll('[data-test="product-row"]')).toHaveLength(2)
  })

  it('shows mutation controls when can_manage is true', () => {
    const wrapper = mount(ProductsTable, {
      props: { rows, status: 'success', canManage: true, selected: [] },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })
    expect(wrapper.findAll('[data-test="product-delete"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-test="product-select"]')).toHaveLength(2)
  })

  it('emits delete-request with the row when its delete button is clicked', async () => {
    const wrapper = mount(ProductsTable, {
      props: { rows, status: 'success', canManage: true, selected: [] },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })
    await wrapper.findAll('[data-test="product-delete"]')[0]!.trigger('click')
    expect(wrapper.emitted('delete-request')?.[0]).toEqual([rows[0]])
  })

  it('emits toggle-select with the row uuid when its checkbox changes', async () => {
    const wrapper = mount(ProductsTable, {
      props: { rows, status: 'success', canManage: true, selected: [] },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })
    const checkbox = wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]
    await checkbox!.vm.$emit('update:modelValue', true)
    expect(wrapper.emitted('toggle-select')?.[0]).toEqual(['p1'])
  })
})

// ── ProductForm: exact money rendering, read-only gating, save ──────────────────────────────

describe('ProductForm', () => {
  it('renders the exact formatted base price from the first variant', () => {
    const p = product({
      variants: [
        {
          uuid: 'v1',
          sku: 'SKU-1',
          price: 123456,
          compare_at_price: null,
          currency: 'USD',
          status: 'active',
          position: 0,
        },
      ],
    })
    const wrapper = mount(ProductForm, { props: { product: p, canManage: true } })
    expect(wrapper.find('[data-test="product-base-price"]').text()).toContain('$1,234.56')
  })

  it('shows no base price section when the product has no variants', () => {
    const wrapper = mount(ProductForm, { props: { product: product(), canManage: true } })
    expect(wrapper.find('[data-test="product-base-price"]').exists()).toBe(false)
  })

  it('hides the save button when can_manage is false (read-only)', () => {
    const wrapper = mount(ProductForm, { props: { product: product(), canManage: false } })
    expect(wrapper.find('[data-test="product-form-save"]').exists()).toBe(false)
  })

  it('shows the save button and submits the current fields when can_manage is true', async () => {
    const p = product({ uuid: 'p1', name: 'Widget', slug: 'widget' })
    updateMock.mockResolvedValue(p)
    const wrapper = mount(ProductForm, { props: { product: p, canManage: true } })

    expect(wrapper.find('[data-test="product-form-save"]').exists()).toBe(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateMock).toHaveBeenCalledWith({
      uuid: 'p1',
      input: {
        name: 'Widget',
        slug: 'widget',
        description: null,
        type: 'physical',
        status: 'active',
        tax_class: null,
      },
    })
  })
})

// ── Products list page: filters, create, bulk status, delete ───────────────────────────────

describe('commerce products list page', () => {
  it('hides the New product button and bulk controls when can_manage is false', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    productsPage.value = { products: [product()], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="new-product"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-select-all"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="bulk-status-bar"]').exists()).toBe(false)
    // The list itself stays readable.
    expect(wrapper.findAll('[data-test="product-row"]')).toHaveLength(1)
  })

  it('shows the New product button when can_manage is true and opens the create slideover', async () => {
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="new-product"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="product-create-submit"]').exists()).toBe(false)

    await wrapper.find('[data-test="new-product"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="product-create-submit"]').exists()).toBe(true)
  })

  it('creates a product from the slideover and navigates to its detail page', async () => {
    createMock.mockResolvedValue(product({ uuid: 'new-1', name: 'Widget', slug: 'widget' }))
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="new-product"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="product-name-input"]').setValue('Widget')
    await wrapper.find('[data-test="product-sku-input"]').setValue('SKU-1')
    await wrapper.find('[data-test="product-price-input"]').setValue('1999')
    await wrapper.find('[data-test="product-currency-input"]').setValue('USD')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      slug: 'widget',
      name: 'Widget',
      description: null,
      type: 'physical',
      status: 'draft',
      tax_class: null,
      variants: [{ sku: 'SKU-1', price: 1999, currency: 'USD' }],
    })
    expect(routerPush).toHaveBeenCalledWith('/commerce/products/new-1')
  })

  it('requires confirmation before deleting a product', async () => {
    productsPage.value = { products: [product({ uuid: 'p1', name: 'Widget' })], total: 1, current_page: 1, per_page: 24 }
    removeMock.mockResolvedValue(undefined)
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="product-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="product-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="product-delete-confirm"]').exists()).toBe(true)
    expect(removeMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="product-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(removeMock).toHaveBeenCalledWith('p1')
  })

  it('applies a bulk status change to every selected product', async () => {
    productsPage.value = {
      products: [product({ uuid: 'p1', name: 'Widget' }), product({ uuid: 'p2', name: 'Gadget', slug: 'gadget' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    bulkStatusMock.mockResolvedValue({ applied: ['p1', 'p2'], failed: [] })
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="bulk-status-bar"]').exists()).toBe(false)

    await wrapper.find('[data-test="product-select-all"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="bulk-status-bar"]').exists()).toBe(true)

    selectByTestId(wrapper, 'bulk-status').vm.$emit('update:modelValue', 'active')
    await flushPromises()

    await wrapper.find('[data-test="bulk-status-apply"]').trigger('click')
    await flushPromises()

    expect(bulkStatusMock).toHaveBeenCalledWith({ uuids: ['p1', 'p2'], status: 'active' })
    // Selection clears once applied.
    expect(wrapper.find('[data-test="bulk-status-bar"]').exists()).toBe(false)
  })
})

// ── Product detail page ──────────────────────────────────────────────────────────────────────

describe('commerce product detail page', () => {
  beforeEach(() => {
    routeState.params = { uuid: 'p1' }
  })

  it('renders the Details tab for the loaded product', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    const wrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('Widget')
    expect(wrapper.find('[data-test="product-form-save"]').exists()).toBe(true)
  })

  it('hides the delete button and save control when can_manage is false', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    const wrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="product-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-form-save"]').exists()).toBe(false)
  })

  it('deletes the product after confirmation and navigates back to the list', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    removeMock.mockResolvedValue(undefined)
    const wrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="product-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="product-delete-confirm"]').exists()).toBe(true)

    await wrapper.find('[data-test="product-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(removeMock).toHaveBeenCalledWith('p1')
    expect(routerPush).toHaveBeenCalledWith('/commerce/products')
  })
})
