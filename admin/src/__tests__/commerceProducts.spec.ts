import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { CommerceCategory, CommerceProduct, ProductListPage } from '@/queries/commerceCatalog'

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

// MediaPanel's thumbnails resolve blob uuids through this helper — deterministic path, no need
// for the real runtime-config-derived URL (mirrors assetFieldLibrary.spec.ts's mock). This suite
// stubs MediaPickerModal wherever it can actually open, but @vue/test-utils doesn't auto-unmount
// between tests in this file, so a handful of wrapper instances outlive their test; mocking
// `useMediaList`/`useUploadMedia` too (mirrors assetFieldLibrary.spec.ts) means a stray real
// MediaPickerModal instance never crashes with "useUploadMedia is not defined on the mock".
vi.mock('@/queries/media', () => ({
  useMediaList: () => ({ data: ref(undefined), status: ref('success') }),
  useUploadMedia: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
  blobDisplayUrl: (uuid: string) => `/blobs/${uuid}`,
}))

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
const createVariantMock = vi.hoisted(() => vi.fn())
const updateVariantMock = vi.hoisted(() => vi.fn())
const bulkPriceMock = vi.hoisted(() => vi.fn())
const setChildrenMock = vi.hoisted(() => vi.fn())
const stockAdjustMock = vi.hoisted(() => vi.fn())
const attachMediaMock = vi.hoisted(() => vi.fn())
const updateMediaMock = vi.hoisted(() => vi.fn())
const detachMediaMock = vi.hoisted(() => vi.fn())
const reorderMediaMock = vi.hoisted(() => vi.fn())
const setCategoriesMock = vi.hoisted(() => vi.fn())

const categoriesData = ref<CommerceCategory[] | undefined>(undefined)
const categoriesStatus = ref<'pending' | 'error' | 'success'>('success')
const categoryCreateMock = vi.hoisted(() => vi.fn())
const categoryUpdateMock = vi.hoisted(() => vi.fn())
const categoryRemoveMock = vi.hoisted(() => vi.fn())

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
      createVariant: { mutateAsync: createVariantMock, isLoading: ref(false) },
      updateVariant: { mutateAsync: updateVariantMock, isLoading: ref(false) },
      bulkPrice: { mutateAsync: bulkPriceMock, isLoading: ref(false) },
      setChildren: { mutateAsync: setChildrenMock, isLoading: ref(false) },
      stockAdjust: { mutateAsync: stockAdjustMock, isLoading: ref(false) },
      attachMedia: { mutateAsync: attachMediaMock, isLoading: ref(false) },
      updateMedia: { mutateAsync: updateMediaMock, isLoading: ref(false) },
      detachMedia: { mutateAsync: detachMediaMock, isLoading: ref(false) },
      reorderMedia: { mutateAsync: reorderMediaMock, isLoading: ref(false) },
      setCategories: { mutateAsync: setCategoriesMock, isLoading: ref(false) },
    }),
    useCommerceCategories: () => ({ data: categoriesData, status: categoriesStatus }),
    useCommerceCategoryMutations: () => ({
      create: { mutateAsync: categoryCreateMock, isLoading: ref(false) },
      update: { mutateAsync: categoryUpdateMock, isLoading: ref(false) },
      remove: { mutateAsync: categoryRemoveMock, isLoading: ref(false) },
    }),
  }
})

import ProductsTable from '@/pages/commerce/products/components/ProductsTable.vue'
import ProductForm from '@/pages/commerce/products/components/ProductForm.vue'
import VariantsPanel from '@/pages/commerce/products/components/VariantsPanel.vue'
import MediaPanel from '@/pages/commerce/products/components/MediaPanel.vue'
import CategoriesTab from '@/pages/commerce/products/components/CategoriesTab.vue'
import ProductsIndex from '@/pages/commerce/products/index.vue'
import ProductDetail from '@/pages/commerce/products/[uuid]/index.vue'
import { ApiError } from '@/api/errors'
import type { CommerceVariant, CommerceProductMedia } from '@/queries/commerceCatalog'

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

function variant(overrides: Partial<CommerceVariant> = {}): CommerceVariant {
  return {
    uuid: 'v1',
    sku: 'SKU-1',
    price: 1999,
    compare_at_price: null,
    currency: 'USD',
    status: 'active',
    position: 0,
    ...overrides,
  }
}

function media(overrides: Partial<CommerceProductMedia> = {}): CommerceProductMedia {
  return {
    uuid: 'm1',
    product_uuid: 'p1',
    variant_uuid: null,
    blob_uuid: 'blob-1',
    role: 'gallery',
    position: 0,
    alt: null,
    ...overrides,
  }
}

function category(overrides: Partial<CommerceCategory> = {}): CommerceCategory {
  return {
    uuid: 'cat1',
    parent_uuid: null,
    slug: 'cat-1',
    name: 'Category 1',
    description: null,
    position: 0,
    ...overrides,
  }
}

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }
// UModal/USlideover teleport their body/footer out of the wrapper — stub both to render the
// slots inline, mirroring collectionsFieldEditor.spec.ts's DropConfirmModal precedent.
const teleportStub = { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' }
const pageStubs = { RouterLink: RouterLinkStub, Modal: teleportStub, Slideover: teleportStub }

// MediaPanel reuses the app's asset picker (MediaPickerModal — see AssetField.vue) rather than
// building a new uploader. Its own upload/library behavior is exercised in assetFieldLibrary.spec.ts;
// here it's stubbed down to the one thing MediaPanel depends on: opening on demand and emitting a
// chosen blob uuid through `select`.
const MediaPickerModalStub = {
  props: ['open', 'multiple', 'initialTab'],
  emits: ['select', 'update:open'],
  template:
    '<div v-if="open" data-test="media-picker-stub">' +
    '<button type="button" data-test="media-picker-stub-pick" @click="$emit(\'select\', \'blob-new\')">Pick</button>' +
    '</div>',
}

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
  createVariantMock.mockReset()
  updateVariantMock.mockReset()
  bulkPriceMock.mockReset()
  setChildrenMock.mockReset()
  stockAdjustMock.mockReset()
  attachMediaMock.mockReset()
  updateMediaMock.mockReset()
  detachMediaMock.mockReset()
  reorderMediaMock.mockReset()
  setCategoriesMock.mockReset()
  categoriesData.value = []
  categoriesStatus.value = 'success'
  categoryCreateMock.mockReset()
  categoryUpdateMock.mockReset()
  categoryRemoveMock.mockReset()
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

  it('switches to the Categories tab, hiding the Products-only controls, and renders CategoriesTab', async () => {
    productsPage.value = { products: [product({ uuid: 'p1', name: 'Widget' })], total: 1, current_page: 1, per_page: 24 }
    categoriesData.value = [category({ uuid: 'cat1', name: 'Cat 1' })]
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="category-row"]').exists()).toBe(false)

    const tabs = wrapper.findAll('[role="tab"]')
    const categoriesTab = tabs.find((t) => t.text() === 'Categories')
    await categoriesTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    expect(wrapper.find('[data-test="new-product"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="category-row"]').exists()).toBe(true)
    // Management mode: no `product` prop, so CRUD controls render.
    expect(wrapper.find('[data-test="category-add"]').exists()).toBe(true)
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

  it('switches to the Variants tab and renders VariantsPanel', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', variants: [variant()] })
    const wrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="variant-row"]').exists()).toBe(false)

    // reka-ui's TabsTrigger activates on mousedown (or focus/keydown), not `click` — see
    // TabsTrigger.vue's `@mousedown.left` handler.
    const tabs = wrapper.findAll('[role="tab"]')
    const variantsTab = tabs.find((t) => t.text() === 'Variants')
    await variantsTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    expect(wrapper.find('[data-test="variant-row"]').exists()).toBe(true)
  })

  it('switches to the Media tab and renders MediaPanel', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    const wrapper = mount(ProductDetail, {
      global: { stubs: { ...pageStubs, MediaPickerModal: MediaPickerModalStub } },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="media-empty"]').exists()).toBe(false)

    // reka-ui's TabsTrigger activates on mousedown (or focus/keydown), not `click` — see
    // TabsTrigger.vue's `@mousedown.left` handler.
    const tabs = wrapper.findAll('[role="tab"]')
    const mediaTab = tabs.find((t) => t.text() === 'Media')
    await mediaTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    // Fresh mount = unobserved set: the panel must show the honest "not loaded" state,
    // never assert "No media yet" (no admin GET exists to know that).
    expect(wrapper.find('[data-test="media-unknown"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="media-empty"]').exists()).toBe(false)
  })

  it('switches to the Categories tab and renders CategoriesTab in assignment mode', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    categoriesData.value = [category({ uuid: 'cat1', name: 'Cat 1' })]
    const wrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const tabs = wrapper.findAll('[role="tab"]')
    const categoriesTab = tabs.find((t) => t.text() === 'Categories')
    await categoriesTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    expect(wrapper.find('[data-test="category-assignment-section"]').exists()).toBe(true)
    // Assignment mode: CRUD controls are always hidden, even though can_manage is true.
    expect(wrapper.find('[data-test="category-add"]').exists()).toBe(false)
    // Fresh mount = unobserved assignment: the honest "not loaded" state, never a guessed selection.
    expect(wrapper.find('[data-test="category-assignment-unknown"]').exists()).toBe(true)
  })
})

// ── VariantsPanel: variant lifecycle, bulk price, stock, children ──────────────────────────

describe('VariantsPanel', () => {
  function mountPanel(p: CommerceProduct, canManage = true) {
    return mount(VariantsPanel, { props: { product: p, canManage } })
  }

  it('renders each variant with its exact formatted price', () => {
    const p = product({ variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 123456 })] })
    const wrapper = mountPanel(p)
    expect(wrapper.findAll('[data-test="variant-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="variant-price"]').text()).toContain('$1,234.56')
  })

  it('shows the empty state when there are no variants', () => {
    const wrapper = mountPanel(product({ variants: [] }))
    expect(wrapper.find('[data-test="variants-empty"]').exists()).toBe(true)
  })

  it('hides mutation controls when can_manage is false, keeping variant rows visible', () => {
    const p = product({ variants: [variant()] })
    const wrapper = mountPanel(p, false)

    expect(wrapper.find('[data-test="variant-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="variant-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="stock-adjust"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="variant-select"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="variant-row"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="variant-price"]').exists()).toBe(true)
  })

  it('creates a variant from the add-variant form', async () => {
    createVariantMock.mockResolvedValue(variant({ uuid: 'v2', sku: 'SKU-2' }))
    const p = product({ uuid: 'p1', variants: [] })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-add"]').trigger('click')
    await wrapper.find('[data-test="variant-sku-input"]').setValue('SKU-2')
    await wrapper.find('[data-test="variant-price-input"]').setValue('2500')
    await wrapper.find('[data-test="variant-currency-input"]').setValue('USD')

    await wrapper.find('#variant-add-form').trigger('submit')
    await flushPromises()

    expect(createVariantMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      input: { sku: 'SKU-2', price: 2500, currency: 'USD', status: 'active' },
    })
  })

  it('surfaces the "cannot add variant to type" 422 message instead of vanishing it', async () => {
    createVariantMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { product_uuid: "Cannot add variants to a 'grouped' product." }, {}),
    )
    const p = product({ uuid: 'p1', type: 'grouped', variants: [] })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-add"]').trigger('click')
    await wrapper.find('[data-test="variant-sku-input"]').setValue('SKU-2')
    await wrapper.find('[data-test="variant-price-input"]').setValue('2500')
    await wrapper.find('[data-test="variant-currency-input"]').setValue('USD')
    await wrapper.find('#variant-add-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="variant-form-error"]').text()).toContain(
      "Cannot add variants to a 'grouped' product.",
    )
  })

  it('updates a variant via the inline edit form', async () => {
    updateVariantMock.mockResolvedValue(variant({ uuid: 'v1', price: 3000 }))
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 1999 })] })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-edit"]').trigger('click')
    await wrapper.find('[data-test="variant-edit-price-input"]').setValue('3000')
    await wrapper.find(`#variant-edit-form-v1`).trigger('submit')
    await flushPromises()

    expect(updateVariantMock).toHaveBeenCalledWith({
      uuid: 'v1',
      productUuid: 'p1',
      input: { sku: 'SKU-1', price: 3000, status: 'active' },
    })
  })

  it('surfaces a duplicate-SKU 422 message on variant update', async () => {
    updateVariantMock.mockRejectedValue(new ApiError('Validation failed', 422, { sku: 'SKU already in use.' }, {}))
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-edit"]').trigger('click')
    await wrapper.find(`#variant-edit-form-v1`).trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="variant-edit-error"]').text()).toContain('SKU already in use.')
  })

  it('applies a bulk price to every selected variant with an exact payload', async () => {
    bulkPriceMock.mockResolvedValue({ applied: ['v1', 'v2'], failed: [] })
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1' }), variant({ uuid: 'v2', sku: 'SKU-2' })],
    })
    const wrapper = mountPanel(p)

    expect(wrapper.find('[data-test="bulk-price-bar"]').exists()).toBe(false)

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await checkboxes[1]!.vm.$emit('update:modelValue', true)
    await flushPromises()

    expect(wrapper.find('[data-test="bulk-price-bar"]').exists()).toBe(true)

    await wrapper.find('[data-test="bulk-price-input"]').setValue('5000')
    await wrapper.find('[data-test="bulk-price-apply"]').trigger('click')
    await flushPromises()

    expect(bulkPriceMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      items: [
        { uuid: 'v1', price: 5000 },
        { uuid: 'v2', price: 5000 },
      ],
    })
    // Selection clears once applied.
    expect(wrapper.find('[data-test="bulk-price-bar"]').exists()).toBe(false)
  })

  it('adjusts stock with an exact request body including the reason', async () => {
    stockAdjustMock.mockResolvedValue({ variant_uuid: 'v1', quantity: 12 })
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="stock-adjust"]').trigger('click')
    await wrapper.find('[data-test="stock-adjust-delta"]').setValue('-3')
    await wrapper.find('[data-test="stock-adjust-reason"]').setValue('damaged')
    await wrapper.find('[data-test="stock-adjust-apply"]').trigger('click')
    await flushPromises()

    expect(stockAdjustMock).toHaveBeenCalledWith({
      variantUuid: 'v1',
      productUuid: 'p1',
      input: { delta: -3, reason: 'damaged' },
    })
    expect(wrapper.find('[data-test="variant-stock-quantity"]').text()).toContain('12')
  })

  it('surfaces a "stock cannot go below zero" 422 message', async () => {
    stockAdjustMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { quantity: 'Stock cannot go below zero.' }, {}),
    )
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="stock-adjust"]').trigger('click')
    await wrapper.find('[data-test="stock-adjust-delta"]').setValue('-9999')
    await wrapper.find('[data-test="stock-adjust-apply"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="stock-adjust-error"]').text()).toContain('Stock cannot go below zero.')
  })

  it('hides the children editor for a non-grouped product', () => {
    const wrapper = mountPanel(product({ type: 'physical' }))
    expect(wrapper.find('[data-test="children-section"]').exists()).toBe(false)
  })

  it('shows the children editor for a grouped product and sets children with an exact payload', async () => {
    setChildrenMock.mockResolvedValue([
      { uuid: 'child-1', slug: 'child-one', name: 'Child One', description: null, type: 'physical', status: 'active', tax_class: null, created_at: null, updated_at: null, variants: [] },
    ])
    const p = product({ uuid: 'p1', type: 'grouped' })
    const wrapper = mountPanel(p)

    expect(wrapper.find('[data-test="children-section"]').exists()).toBe(true)

    await wrapper.find('[data-test="children-input"]').setValue('child-1, child-2')
    await wrapper.find('[data-test="children-save"]').trigger('click')
    await flushPromises()

    expect(setChildrenMock).toHaveBeenCalledWith({ productUuid: 'p1', childUuids: ['child-1', 'child-2'] })
    expect(wrapper.find('[data-test="children-list"]').text()).toContain('Child One')
  })

  it('surfaces the "only grouped products can have children" 422 message', async () => {
    setChildrenMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { type: 'Only grouped products can have children.' }, {}),
    )
    const wrapper = mountPanel(product({ uuid: 'p1', type: 'grouped' }))

    await wrapper.find('[data-test="children-input"]').setValue('child-1')
    await wrapper.find('[data-test="children-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="children-error"]').text()).toContain(
      'Only grouped products can have children.',
    )
  })

  it('hides the children save control when can_manage is false', () => {
    const wrapper = mountPanel(product({ type: 'grouped' }), false)
    expect(wrapper.find('[data-test="children-section"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="children-save"]').exists()).toBe(false)
  })
})

// ── MediaPanel: attach/reorder/update/detach, stable ordering, read-only, rollback ─────────

describe('MediaPanel', () => {
  function mountPanel(p: CommerceProduct, canManage = true) {
    return mount(MediaPanel, {
      props: { product: p, canManage },
      global: { stubs: { MediaPickerModal: MediaPickerModalStub } },
    })
  }

  async function attachOne(wrapper: ReturnType<typeof mount>) {
    await wrapper.find('[data-test="media-add"]').trigger('click')
    await wrapper.find('[data-test="media-picker-stub-pick"]').trigger('click')
    await flushPromises()
  }

  it('shows the not-loaded state on fresh mount (unknown, never a false "no media" claim)', () => {
    const wrapper = mountPanel(product({ uuid: 'p1' }))
    expect(wrapper.find('[data-test="media-unknown"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="media-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-row"]').exists()).toBe(false)
  })

  it('attaches media via the picker and renders the row', async () => {
    attachMediaMock.mockResolvedValue(media({ uuid: 'm1', blob_uuid: 'blob-1', role: 'gallery' }))
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await attachOne(wrapper)

    expect(attachMediaMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      input: { blob_uuid: 'blob-new', role: 'gallery' },
    })
    expect(wrapper.findAll('[data-test="media-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="media-thumb"]').attributes('src')).toBe('/blobs/blob-1')
  })

  it('surfaces the "blob already attached" 422 message on attach failure', async () => {
    attachMediaMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { blob_uuid: 'This blob is already attached to the product.' }, {}),
    )
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await attachOne(wrapper)

    expect(wrapper.find('[data-test="media-attach-error"]').text()).toContain(
      'This blob is already attached to the product.',
    )
    expect(wrapper.find('[data-test="media-row"]').exists()).toBe(false)
  })

  it('updates alt text via the inline edit form', async () => {
    attachMediaMock.mockResolvedValue(media({ uuid: 'm1', blob_uuid: 'blob-1', role: 'gallery' }))
    updateMediaMock.mockResolvedValue(media({ uuid: 'm1', blob_uuid: 'blob-1', role: 'gallery', alt: 'Front view' }))
    const wrapper = mountPanel(product({ uuid: 'p1' }))
    await attachOne(wrapper)

    await wrapper.find('[data-test="media-edit"]').trigger('click')
    await wrapper.find('[data-test="media-edit-alt-input"]').setValue('Front view')
    await wrapper.find('[data-test="media-edit-save"]').trigger('click')
    await flushPromises()

    expect(updateMediaMock).toHaveBeenCalledWith({
      uuid: 'm1',
      productUuid: 'p1',
      input: { alt: 'Front view', role: 'gallery' },
    })
    expect(wrapper.find('[data-test="media-alt"]').text()).toContain('Front view')
  })

  it('surfaces a validation 422 message on media update', async () => {
    attachMediaMock.mockResolvedValue(media({ uuid: 'm1' }))
    updateMediaMock.mockRejectedValue(new ApiError('Validation failed', 422, { alt: 'Alt text too long.' }, {}))
    const wrapper = mountPanel(product({ uuid: 'p1' }))
    await attachOne(wrapper)

    await wrapper.find('[data-test="media-edit"]').trigger('click')
    await wrapper.find('[data-test="media-edit-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="media-edit-error"]').text()).toContain('Alt text too long.')
  })

  it('promotes a row to cover and demotes the previously-known local cover', async () => {
    attachMediaMock
      .mockResolvedValueOnce(media({ uuid: 'm1', blob_uuid: 'blob-1', role: 'cover', position: 0 }))
      .mockResolvedValueOnce(media({ uuid: 'm2', blob_uuid: 'blob-2', role: 'gallery', position: 1 }))
    updateMediaMock.mockResolvedValue(media({ uuid: 'm2', blob_uuid: 'blob-2', role: 'cover', position: 1 }))
    const wrapper = mountPanel(product({ uuid: 'p1' }))
    await attachOne(wrapper)
    await attachOne(wrapper)

    await wrapper.findAll('[data-test="media-edit"]')[1]!.trigger('click')
    selectByTestId(wrapper, 'media-edit-role-input').vm.$emit('update:modelValue', 'cover')
    await wrapper.find('[data-test="media-edit-save"]').trigger('click')
    await flushPromises()

    expect(updateMediaMock).toHaveBeenCalledWith({
      uuid: 'm2',
      productUuid: 'p1',
      input: { alt: null, role: 'cover' },
    })
    const badges = wrapper.findAll('[data-test="media-role-badge"]')
    expect(badges.map((b) => b.text())).toEqual(['gallery', 'cover'])
  })

  it('detaches media and removes the row', async () => {
    attachMediaMock.mockResolvedValue(media({ uuid: 'm1' }))
    detachMediaMock.mockResolvedValue(undefined)
    const wrapper = mountPanel(product({ uuid: 'p1' }))
    await attachOne(wrapper)
    expect(wrapper.findAll('[data-test="media-row"]')).toHaveLength(1)

    await wrapper.find('[data-test="media-detach"]').trigger('click')
    await flushPromises()

    expect(detachMediaMock).toHaveBeenCalledWith({ uuid: 'm1', productUuid: 'p1' })
    expect(wrapper.find('[data-test="media-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-empty"]').exists()).toBe(true)
  })

  it('renders media rows ordered by position and reorders with the full uuid list on move', async () => {
    attachMediaMock
      .mockResolvedValueOnce(media({ uuid: 'm1', blob_uuid: 'blob-1', position: 0 }))
      .mockResolvedValueOnce(media({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }))
    reorderMediaMock.mockResolvedValue([
      media({ uuid: 'm2', blob_uuid: 'blob-2', position: 0 }),
      media({ uuid: 'm1', blob_uuid: 'blob-1', position: 1 }),
    ])
    const wrapper = mountPanel(product({ uuid: 'p1' }))
    await attachOne(wrapper)
    await attachOne(wrapper)

    expect(wrapper.findAll('[data-test="media-row"]').map((r) => r.attributes('data-uuid'))).toEqual([
      'm1',
      'm2',
    ])

    await wrapper.findAll('[data-test="media-move-down"]')[0]!.trigger('click')
    await flushPromises()

    expect(reorderMediaMock).toHaveBeenCalledWith({ productUuid: 'p1', orderedUuids: ['m2', 'm1'] })
    expect(wrapper.findAll('[data-test="media-row"]').map((r) => r.attributes('data-uuid'))).toEqual([
      'm2',
      'm1',
    ])
  })

  it('rolls back an optimistic reorder when the mutation fails', async () => {
    attachMediaMock
      .mockResolvedValueOnce(media({ uuid: 'm1', blob_uuid: 'blob-1', position: 0 }))
      .mockResolvedValueOnce(media({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }))
    reorderMediaMock.mockRejectedValue(new ApiError('Validation failed', 422, {}, {}))
    const wrapper = mountPanel(product({ uuid: 'p1' }))
    await attachOne(wrapper)
    await attachOne(wrapper)

    await wrapper.findAll('[data-test="media-move-down"]')[0]!.trigger('click')
    await flushPromises()

    expect(reorderMediaMock).toHaveBeenCalledWith({ productUuid: 'p1', orderedUuids: ['m2', 'm1'] })
    // Reverted to the pre-reorder order once the mutation rejects.
    expect(wrapper.findAll('[data-test="media-row"]').map((r) => r.attributes('data-uuid'))).toEqual([
      'm1',
      'm2',
    ])
  })

  it('hides mutation controls when can_manage is false, keeping media rows visible', async () => {
    attachMediaMock
      .mockResolvedValueOnce(media({ uuid: 'm1', position: 0 }))
      .mockResolvedValueOnce(media({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }))
    const wrapper = mountPanel(product({ uuid: 'p1' }))
    await attachOne(wrapper)
    await attachOne(wrapper)
    expect(wrapper.find('[data-test="media-move-down"]').exists()).toBe(true)

    await wrapper.setProps({ canManage: false })

    expect(wrapper.find('[data-test="media-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-detach"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-move-up"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-move-down"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="media-row"]')).toHaveLength(2)
  })

  it('hides the Add media button when can_manage is false', () => {
    const wrapper = mountPanel(product({ uuid: 'p1' }), false)
    expect(wrapper.find('[data-test="media-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-unknown"]').exists()).toBe(true)
  })
})

// ── CategoriesTab: management mode (no `product` prop) — full category CRUD ────────────────

describe('CategoriesTab (category management)', () => {
  function mountTab(canManage = true) {
    return mount(CategoriesTab, { props: { canManage }, global: { stubs: { Modal: teleportStub } } })
  }

  it('renders each category with its parent relationship', () => {
    categoriesData.value = [
      category({ uuid: 'root1', name: 'Root', slug: 'root', parent_uuid: null }),
      category({ uuid: 'child1', name: 'Child', slug: 'child', parent_uuid: 'root1' }),
    ]
    const wrapper = mountTab()

    expect(wrapper.findAll('[data-test="category-row"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="category-parent"]').text()).toContain('Root')
  })

  it('shows the loading state', () => {
    categoriesStatus.value = 'pending'
    const wrapper = mountTab()
    expect(wrapper.find('[data-test="categories-loading"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    categoriesStatus.value = 'error'
    const wrapper = mountTab()
    expect(wrapper.find('[data-test="categories-error"]').exists()).toBe(true)
  })

  it('shows the empty state when there are no categories', () => {
    categoriesData.value = []
    const wrapper = mountTab()
    expect(wrapper.find('[data-test="categories-empty"]').exists()).toBe(true)
  })

  it('creates a category from the add form', async () => {
    categoriesData.value = []
    categoryCreateMock.mockResolvedValue(category({ uuid: 'new-1', name: 'New', slug: 'new' }))
    const wrapper = mountTab()

    await wrapper.find('[data-test="category-add"]').trigger('click')
    await wrapper.find('[data-test="category-name-input"]').setValue('New')
    await wrapper.find('[data-test="category-slug-input"]').setValue('new')
    await wrapper.find('#category-form').trigger('submit')
    await flushPromises()

    expect(categoryCreateMock).toHaveBeenCalledWith({
      name: 'New',
      slug: 'new',
      description: null,
      parent_uuid: null,
      position: 0,
    })
  })

  it('surfaces a duplicate-slug 422 message instead of vanishing it', async () => {
    categoriesData.value = []
    categoryCreateMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { slug: 'Slug already in use.' }, {}),
    )
    const wrapper = mountTab()

    await wrapper.find('[data-test="category-add"]').trigger('click')
    await wrapper.find('[data-test="category-name-input"]').setValue('Dup')
    await wrapper.find('[data-test="category-slug-input"]').setValue('dup')
    await wrapper.find('#category-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="category-form-error"]').text()).toContain('Slug already in use.')
  })

  it('updates a category via the edit form', async () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Old', slug: 'old' })]
    categoryUpdateMock.mockResolvedValue(category({ uuid: 'c1', name: 'New name', slug: 'old' }))
    const wrapper = mountTab()

    await wrapper.find('[data-test="category-edit"]').trigger('click')
    await wrapper.find('[data-test="category-name-input"]').setValue('New name')
    await wrapper.find('#category-form').trigger('submit')
    await flushPromises()

    expect(categoryUpdateMock).toHaveBeenCalledWith({
      uuid: 'c1',
      input: { name: 'New name', slug: 'old', description: null, parent_uuid: null, position: 0 },
    })
  })

  it('surfaces a validation 422 message on category update', async () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Old', slug: 'old' })]
    categoryUpdateMock.mockRejectedValue(
      new ApiError(
        'Validation failed',
        422,
        { parent_uuid: 'parent_uuid would create a cycle in the category tree.' },
        {},
      ),
    )
    const wrapper = mountTab()

    await wrapper.find('[data-test="category-edit"]').trigger('click')
    await wrapper.find('#category-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="category-form-error"]').text()).toContain(
      'parent_uuid would create a cycle in the category tree.',
    )
  })

  it('requires confirmation before deleting a category', async () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Old' })]
    categoryRemoveMock.mockResolvedValue(undefined)
    const wrapper = mountTab()

    expect(wrapper.find('[data-test="category-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="category-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="category-delete-confirm"]').exists()).toBe(true)
    expect(categoryRemoveMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="category-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(categoryRemoveMock).toHaveBeenCalledWith('c1')
  })

  it('hides all mutation controls when can_manage is false, keeping categories visible', () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Old' })]
    const wrapper = mountTab(false)

    expect(wrapper.find('[data-test="category-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="category-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="category-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="category-row"]').exists()).toBe(true)
  })
})

// ── CategoriesTab: assignment mode (`product` prop given) ───────────────────────────────────

describe('CategoriesTab (product assignment)', () => {
  function mountAssignment(p: CommerceProduct, canManage = true) {
    return mount(CategoriesTab, { props: { product: p, canManage } })
  }

  it('shows the honest not-loaded state on fresh mount (never a guessed selection)', () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="category-assignment-unknown"]').exists()).toBe(true)
    const checkbox = wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]
    expect(checkbox!.props('modelValue')).toBe(false)
  })

  it('hides category CRUD controls in assignment mode even when can_manage is true', () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="category-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="category-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="category-delete"]').exists()).toBe(false)
  })

  it('selects categories and saves the exact uuid list, then reflects the positively-known set', async () => {
    categoriesData.value = [
      category({ uuid: 'c1', name: 'Cat 1' }),
      category({ uuid: 'c2', name: 'Cat 2' }),
    ]
    setCategoriesMock.mockResolvedValue([category({ uuid: 'c1', name: 'Cat 1' })])
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setCategoriesMock).toHaveBeenCalledWith({ productUuid: 'p1', categoryUuids: ['c1'] })
    // Once the set-list response comes back, the unknown-state banner clears — the set is now
    // positively established, not a guess.
    expect(wrapper.find('[data-test="category-assignment-unknown"]').exists()).toBe(false)
  })

  it('surfaces a validation 422 message on save without discarding the current selection', async () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    setCategoriesMock.mockRejectedValue(
      new ApiError(
        'Validation failed',
        422,
        { category_uuids: 'category_uuids must reference existing categories in this tenant.' },
        {},
      ),
    )
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="category-assignment-error"]').text()).toContain(
      'category_uuids must reference existing categories in this tenant.',
    )
  })

  it('hides the save control and disables checkboxes when can_manage is false', () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    const wrapper = mountAssignment(product({ uuid: 'p1' }), false)

    expect(wrapper.find('[data-test="category-assignment-save"]').exists()).toBe(false)
    const checkbox = wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]
    expect(checkbox!.props('disabled')).toBe(true)
  })
})
