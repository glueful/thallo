import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, toValue } from 'vue'
import type {
  CommerceAddon,
  CommerceAttribute,
  CommerceAttributeValue,
  CommerceCategory,
  CommerceDownload,
  CommerceProduct,
  CommerceTag,
  AttributeListPage,
  ProductListPage,
  TagListPage,
} from '@/queries/commerceCatalog'

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

const tagsPage = ref<TagListPage | undefined>(undefined)
const tagsStatus = ref<'pending' | 'error' | 'success'>('success')
const lastTagsFilters = vi.hoisted(() => ({ current: undefined as unknown }))
const setTagsMock = vi.hoisted(() => vi.fn())
const tagCreateMock = vi.hoisted(() => vi.fn())
const tagUpdateMock = vi.hoisted(() => vi.fn())
const tagRemoveMock = vi.hoisted(() => vi.fn())

const attributesPage = ref<AttributeListPage | undefined>(undefined)
const attributesStatus = ref<'pending' | 'error' | 'success'>('success')
const lastAttributesFilters = vi.hoisted(() => ({ current: undefined as unknown }))
const setAttributesMock = vi.hoisted(() => vi.fn())
const attributeCreateMock = vi.hoisted(() => vi.fn())
const attributeUpdateMock = vi.hoisted(() => vi.fn())
const attributeRemoveMock = vi.hoisted(() => vi.fn())
const attributeCreateValueMock = vi.hoisted(() => vi.fn())
const attributeUpdateValueMock = vi.hoisted(() => vi.fn())
const attributeRemoveValueMock = vi.hoisted(() => vi.fn())

const addonsData = ref<CommerceAddon[] | undefined>(undefined)
const addonsStatus = ref<'pending' | 'error' | 'success'>('success')
const lastAddonsProductUuid = vi.hoisted(() => ({ current: undefined as unknown }))
const createAddonMock = vi.hoisted(() => vi.fn())
const updateAddonMock = vi.hoisted(() => vi.fn())
const removeAddonMock = vi.hoisted(() => vi.fn())

const downloadsData = ref<CommerceDownload[] | undefined>(undefined)
const downloadsStatus = ref<'pending' | 'error' | 'success'>('success')
const lastDownloadsVariantUuid = vi.hoisted(() => ({ current: undefined as unknown }))
const attachDownloadMock = vi.hoisted(() => vi.fn())
const updateDownloadMock = vi.hoisted(() => vi.fn())
const removeDownloadMock = vi.hoisted(() => vi.fn())

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
      setTags: { mutateAsync: setTagsMock, isLoading: ref(false) },
      setAttributes: { mutateAsync: setAttributesMock, isLoading: ref(false) },
      createAddon: { mutateAsync: createAddonMock, isLoading: ref(false) },
      updateAddon: { mutateAsync: updateAddonMock, isLoading: ref(false) },
      removeAddon: { mutateAsync: removeAddonMock, isLoading: ref(false) },
      attachDownload: { mutateAsync: attachDownloadMock, isLoading: ref(false) },
      updateDownload: { mutateAsync: updateDownloadMock, isLoading: ref(false) },
      removeDownload: { mutateAsync: removeDownloadMock, isLoading: ref(false) },
    }),
    useCommerceProductAddons: (uuid: unknown) => {
      lastAddonsProductUuid.current = uuid
      return { data: addonsData, status: addonsStatus }
    },
    useCommerceVariantDownloads: (uuid: unknown) => {
      lastDownloadsVariantUuid.current = uuid
      return { data: downloadsData, status: downloadsStatus }
    },
    useCommerceCategories: () => ({ data: categoriesData, status: categoriesStatus }),
    useCommerceCategoryMutations: () => ({
      create: { mutateAsync: categoryCreateMock, isLoading: ref(false) },
      update: { mutateAsync: categoryUpdateMock, isLoading: ref(false) },
      remove: { mutateAsync: categoryRemoveMock, isLoading: ref(false) },
    }),
    useCommerceTags: (filters: unknown) => {
      lastTagsFilters.current = filters
      return { data: tagsPage, status: tagsStatus }
    },
    useCommerceTagMutations: () => ({
      create: { mutateAsync: tagCreateMock, isLoading: ref(false) },
      update: { mutateAsync: tagUpdateMock, isLoading: ref(false) },
      remove: { mutateAsync: tagRemoveMock, isLoading: ref(false) },
    }),
    useCommerceAttributes: (filters: unknown) => {
      lastAttributesFilters.current = filters
      return { data: attributesPage, status: attributesStatus }
    },
    useCommerceAttributeMutations: () => ({
      create: { mutateAsync: attributeCreateMock, isLoading: ref(false) },
      update: { mutateAsync: attributeUpdateMock, isLoading: ref(false) },
      remove: { mutateAsync: attributeRemoveMock, isLoading: ref(false) },
      createValue: { mutateAsync: attributeCreateValueMock, isLoading: ref(false) },
      updateValue: { mutateAsync: attributeUpdateValueMock, isLoading: ref(false) },
      removeValue: { mutateAsync: attributeRemoveValueMock, isLoading: ref(false) },
    }),
  }
})

import ProductsTable from '@/pages/commerce/products/components/ProductsTable.vue'
import ProductForm from '@/pages/commerce/products/components/ProductForm.vue'
import VariantsPanel from '@/pages/commerce/products/components/VariantsPanel.vue'
import MediaPanel from '@/pages/commerce/products/components/MediaPanel.vue'
import CategoriesTab from '@/pages/commerce/products/components/CategoriesTab.vue'
import TagsTab from '@/pages/commerce/products/components/TagsTab.vue'
import AttributesTab from '@/pages/commerce/products/components/AttributesTab.vue'
import AddonsPanel from '@/pages/commerce/products/components/AddonsPanel.vue'
import DownloadsPanel from '@/pages/commerce/products/components/DownloadsPanel.vue'
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

function tag(overrides: Partial<CommerceTag> = {}): CommerceTag {
  return {
    uuid: 'tag1',
    slug: 'tag-1',
    name: 'Tag 1',
    ...overrides,
  }
}

function attributeValue(overrides: Partial<CommerceAttributeValue> = {}): CommerceAttributeValue {
  return {
    uuid: 'val1',
    slug: 'red',
    value: 'Red',
    position: 0,
    ...overrides,
  }
}

function attribute(overrides: Partial<CommerceAttribute> = {}): CommerceAttribute {
  return {
    uuid: 'attr1',
    slug: 'color',
    name: 'Color',
    position: 0,
    values: [],
    ...overrides,
  }
}

function addon(overrides: Partial<CommerceAddon> = {}): CommerceAddon {
  return {
    uuid: 'addon1',
    product_uuid: 'p1',
    name: 'Gift wrap',
    field_type: 'checkbox',
    required: false,
    choices: null,
    price_delta: 300,
    position: 0,
    status: 'active',
    ...overrides,
  }
}

function download(overrides: Partial<CommerceDownload> = {}): CommerceDownload {
  return {
    uuid: 'd1',
    variant_uuid: 'v1',
    blob_uuid: 'blob-1',
    name: 'Ebook (PDF)',
    download_limit: 3,
    expiry_days: 30,
    position: 0,
    status: 'active',
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
  tagsPage.value = { tags: [], total: 0, current_page: 1, per_page: 24 }
  tagsStatus.value = 'success'
  lastTagsFilters.current = undefined
  setTagsMock.mockReset()
  tagCreateMock.mockReset()
  tagUpdateMock.mockReset()
  tagRemoveMock.mockReset()
  attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
  attributesStatus.value = 'success'
  lastAttributesFilters.current = undefined
  setAttributesMock.mockReset()
  attributeCreateMock.mockReset()
  attributeUpdateMock.mockReset()
  attributeRemoveMock.mockReset()
  attributeCreateValueMock.mockReset()
  attributeUpdateValueMock.mockReset()
  attributeRemoveValueMock.mockReset()
  addonsData.value = undefined
  addonsStatus.value = 'success'
  lastAddonsProductUuid.current = undefined
  createAddonMock.mockReset()
  updateAddonMock.mockReset()
  removeAddonMock.mockReset()
  downloadsData.value = undefined
  downloadsStatus.value = 'success'
  lastDownloadsVariantUuid.current = undefined
  attachDownloadMock.mockReset()
  updateDownloadMock.mockReset()
  removeDownloadMock.mockReset()
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

  it('creates a draft from name/type/price, deriving slug/SKU/currency, and navigates to its detail page', async () => {
    createMock.mockResolvedValue(product({ uuid: 'new-1', name: 'Wireless Mouse', slug: 'wireless-mouse' }))
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="new-product"]').trigger('click')
    await flushPromises()

    // Draft-first: the form asks ONLY for name/type/price — slug, SKU, currency
    // and status are derived, surfaced in the preview line, and refined in the
    // editor the page navigates into.
    expect(wrapper.find('[data-test="product-slug-input"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-sku-input"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-currency-input"]').exists()).toBe(false)

    await wrapper.find('[data-test="product-name-input"]').setValue('Wireless Mouse')
    await wrapper.find('[data-test="product-price-input"]').setValue('1999')
    await flushPromises()

    expect(wrapper.find('[data-test="derived-preview"]').text()).toContain('wireless-mouse')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      slug: 'wireless-mouse',
      name: 'Wireless Mouse',
      type: 'physical',
      status: 'draft',
      variants: [{ sku: 'wireless-mouse', price: 1999, currency: 'USD' }],
    })
    expect(routerPush).toHaveBeenCalledWith('/commerce/products/new-1')
  })

  it('hides the price field and sends no variants for non-purchasable types', async () => {
    createMock.mockResolvedValue(product({ uuid: 'new-2', name: 'Partner Listing', type: 'external' }))
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    await wrapper.find('[data-test="new-product"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="product-name-input"]').setValue('Partner Listing')
    selectByTestId(wrapper, 'product-type-select').vm.$emit('update:modelValue', 'external')
    await flushPromises()

    // external/grouped products reject variants server-side — no price asked,
    // and the payload carries an EMPTY variants list.
    expect(wrapper.find('[data-test="product-price-input"]').exists()).toBe(false)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      slug: 'partner-listing',
      name: 'Partner Listing',
      type: 'external',
      status: 'draft',
      variants: [],
    })
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

  // Task 19a: taxonomy grows a THIRD tab (Products | Categories | Tags).

  it('switches to the Tags tab, hiding the Products-only controls, and renders TagsTab', async () => {
    productsPage.value = { products: [product({ uuid: 'p1', name: 'Widget' })], total: 1, current_page: 1, per_page: 24 }
    tagsPage.value = { tags: [tag({ uuid: 'tag1', name: 'Tag 1' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="tag-row"]').exists()).toBe(false)

    const tabs = wrapper.findAll('[role="tab"]')
    const tagsTab = tabs.find((t) => t.text() === 'Tags')
    await tagsTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    expect(wrapper.find('[data-test="new-product"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-row"]').exists()).toBe(true)
    // Management mode: no `product` prop, so CRUD controls render.
    expect(wrapper.find('[data-test="tag-add"]').exists()).toBe(true)
  })

  // Task 19b: taxonomy grows a FOURTH tab (Products | Categories | Tags | Attributes).

  it('renders exactly four tabs: Products, Categories, Tags, Attributes', async () => {
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    const tabs = wrapper.findAll('[role="tab"]')
    expect(tabs.map((t) => t.text())).toEqual(['Products', 'Categories', 'Tags', 'Attributes'])
  })

  it('switches to the Attributes tab, hiding the Products-only controls, and renders AttributesTab', async () => {
    productsPage.value = { products: [product({ uuid: 'p1', name: 'Widget' })], total: 1, current_page: 1, per_page: 24 }
    attributesPage.value = { attributes: [attribute({ uuid: 'attr1', name: 'Color' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="attribute-row"]').exists()).toBe(false)

    const tabs = wrapper.findAll('[role="tab"]')
    const attributesTab = tabs.find((t) => t.text() === 'Attributes')
    await attributesTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    expect(wrapper.find('[data-test="new-product"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-row"]').exists()).toBe(true)
    // Management mode: no `product` prop, so CRUD controls render.
    expect(wrapper.find('[data-test="attribute-add"]').exists()).toBe(true)
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

  it('shows the draft callout for draft products and hides it once active', async () => {
    singleProduct.value = product({ uuid: 'p1', status: 'draft' })
    const draftWrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(draftWrapper.find('[data-test="product-draft-callout"]').exists()).toBe(true)

    singleProduct.value = product({ uuid: 'p1', status: 'active' })
    const activeWrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()
    expect(activeWrapper.find('[data-test="product-draft-callout"]').exists()).toBe(false)
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

  it('switches to the Tags tab and renders TagsTab in assignment mode', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    tagsPage.value = { tags: [tag({ uuid: 'tag1', name: 'Tag 1' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const tabs = wrapper.findAll('[role="tab"]')
    const tagsTab = tabs.find((t) => t.text() === 'Tags')
    await tagsTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    expect(wrapper.find('[data-test="tag-assignment-section"]').exists()).toBe(true)
    // Assignment mode: CRUD controls are always hidden, even though can_manage is true.
    expect(wrapper.find('[data-test="tag-add"]').exists()).toBe(false)
    // Fresh mount = unobserved assignment: the honest "not loaded" state, never a guessed selection.
    expect(wrapper.find('[data-test="tag-assignment-unknown"]').exists()).toBe(true)
  })

  it('switches to the Attributes tab and renders AttributesTab in assignment mode', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    attributesPage.value = { attributes: [attribute({ uuid: 'attr1', name: 'Color' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const tabs = wrapper.findAll('[role="tab"]')
    const attributesTab = tabs.find((t) => t.text() === 'Attributes')
    await attributesTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    expect(wrapper.find('[data-test="attribute-assignment-section"]').exists()).toBe(true)
    // Assignment mode: CRUD controls are always hidden, even though can_manage is true.
    expect(wrapper.find('[data-test="attribute-add"]').exists()).toBe(false)
    // Fresh mount = unobserved assignment: the honest "not loaded" state, never a guessed selection.
    expect(wrapper.find('[data-test="attribute-assignment-unknown"]').exists()).toBe(true)
  })

  it('switches to the Add-ons tab and renders AddonsPanel, hydrated from the real per-product GET', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    addonsData.value = [addon({ uuid: 'a1', name: 'Gift wrap' })]
    const wrapper = mount(ProductDetail, { global: { stubs: pageStubs } })
    await flushPromises()

    const tabs = wrapper.findAll('[role="tab"]')
    const addonsTab = tabs.find((t) => t.text() === 'Add-ons')
    await addonsTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    // Unlike Categories/Tags/Attributes' assignment sections, add-ons have a real admin GET — the
    // row renders straight from it, no "assignment not loaded" placeholder involved at all.
    expect(wrapper.find('[data-test="addon-row"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Gift wrap')
    expect(lastAddonsProductUuid.current).toBeTruthy()
  })

  it('switches to the Downloads tab and renders DownloadsPanel, hydrated from the real per-variant GET once expanded', async () => {
    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      type: 'digital',
      variants: [variant({ uuid: 'v1' })],
    })
    downloadsData.value = [download({ uuid: 'd1', name: 'Ebook (PDF)' })]
    const wrapper = mount(ProductDetail, {
      global: { stubs: { ...pageStubs, MediaPickerModal: MediaPickerModalStub } },
    })
    await flushPromises()

    const tabs = wrapper.findAll('[role="tab"]')
    const downloadsTab = tabs.find((t) => t.text() === 'Downloads')
    await downloadsTab!.trigger('mousedown', { button: 0 })
    await flushPromises()

    // Collapsed by default: the per-variant GET isn't fired until the section is expanded.
    expect(wrapper.find('[data-test="download-variant-row"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="download-row"]').exists()).toBe(false)

    await wrapper.find('[data-test="download-variant-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="download-row"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Ebook (PDF)')
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

// ── TagsTab: management mode (no `product` prop) — tag CRUD + pagination/search ────────────

describe('TagsTab (tag management)', () => {
  function mountTab(canManage = true) {
    return mount(TagsTab, { props: { canManage }, global: { stubs: { Modal: teleportStub } } })
  }

  it('renders each tag', () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Sale', slug: 'sale' }), tag({ uuid: 't2', name: 'New', slug: 'new' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountTab()

    expect(wrapper.findAll('[data-test="tag-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Sale')
  })

  it('shows the loading state', () => {
    tagsStatus.value = 'pending'
    const wrapper = mountTab()
    expect(wrapper.find('[data-test="tags-loading"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    tagsStatus.value = 'error'
    const wrapper = mountTab()
    expect(wrapper.find('[data-test="tags-error"]').exists()).toBe(true)
  })

  it('shows the empty state when there are no tags', () => {
    tagsPage.value = { tags: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mountTab()
    expect(wrapper.find('[data-test="tags-empty"]').exists()).toBe(true)
  })

  it('sends the typed search as the q filter after the debounce settles', async () => {
    vi.useFakeTimers()
    try {
      const wrapper = mountTab()

      await wrapper.find('[data-test="tag-search"]').setValue('sal')
      // Not yet applied — still debouncing.
      expect((toValue(lastTagsFilters.current) as { q?: string }).q).toBeUndefined()

      await vi.advanceTimersByTimeAsync(300)
      expect((toValue(lastTagsFilters.current) as { q?: string }).q).toBe('sal')
    } finally {
      vi.useRealTimers()
    }
  })

  it('shows pagination controls only once there is at least one tag', async () => {
    tagsPage.value = { tags: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mountTab()
    expect(wrapper.text()).not.toContain('Rows per page')

    tagsPage.value = { tags: [tag()], total: 1, current_page: 1, per_page: 24 }
    await flushPromises()
    expect(wrapper.text()).toContain('Rows per page')
  })

  it('creates a tag from the add form', async () => {
    tagsPage.value = { tags: [], total: 0, current_page: 1, per_page: 24 }
    tagCreateMock.mockResolvedValue(tag({ uuid: 'new-1', name: 'New', slug: 'new' }))
    const wrapper = mountTab()

    await wrapper.find('[data-test="tag-add"]').trigger('click')
    await wrapper.find('[data-test="tag-name-input"]').setValue('New')
    await wrapper.find('[data-test="tag-slug-input"]').setValue('new')
    await wrapper.find('#tag-form').trigger('submit')
    await flushPromises()

    expect(tagCreateMock).toHaveBeenCalledWith({ slug: 'new', name: 'New' })
  })

  it('surfaces a duplicate-slug 422 message instead of vanishing it', async () => {
    tagsPage.value = { tags: [], total: 0, current_page: 1, per_page: 24 }
    tagCreateMock.mockRejectedValue(new ApiError('Validation failed', 422, { slug: 'Slug already in use.' }, {}))
    const wrapper = mountTab()

    await wrapper.find('[data-test="tag-add"]').trigger('click')
    await wrapper.find('[data-test="tag-name-input"]').setValue('Dup')
    await wrapper.find('[data-test="tag-slug-input"]').setValue('dup')
    await wrapper.find('#tag-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="tag-form-error"]').text()).toContain('Slug already in use.')
  })

  it('updates a tag via the edit form, sending ONLY the name — slug is immutable', async () => {
    tagsPage.value = { tags: [tag({ uuid: 't1', name: 'Old', slug: 'old' })], total: 1, current_page: 1, per_page: 24 }
    tagUpdateMock.mockResolvedValue(tag({ uuid: 't1', name: 'New name', slug: 'old' }))
    const wrapper = mountTab()

    await wrapper.find('[data-test="tag-edit"]').trigger('click')
    // The slug field is disabled while editing — it can be shown, never submitted.
    expect(wrapper.find('[data-test="tag-slug-input"]').attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="tag-name-input"]').setValue('New name')
    await wrapper.find('#tag-form').trigger('submit')
    await flushPromises()

    expect(tagUpdateMock).toHaveBeenCalledWith({ uuid: 't1', input: { name: 'New name' } })
  })

  it('surfaces the slug-immutability 422 message on tag update', async () => {
    tagsPage.value = { tags: [tag({ uuid: 't1', name: 'Old', slug: 'old' })], total: 1, current_page: 1, per_page: 24 }
    tagUpdateMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { slug: 'slug is immutable and cannot be changed after creation.' }, {}),
    )
    const wrapper = mountTab()

    await wrapper.find('[data-test="tag-edit"]').trigger('click')
    await wrapper.find('#tag-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="tag-form-error"]').text()).toContain(
      'slug is immutable and cannot be changed after creation.',
    )
  })

  it('requires confirmation before deleting a tag', async () => {
    tagsPage.value = { tags: [tag({ uuid: 't1', name: 'Old' })], total: 1, current_page: 1, per_page: 24 }
    tagRemoveMock.mockResolvedValue(undefined)
    const wrapper = mountTab()

    expect(wrapper.find('[data-test="tag-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="tag-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="tag-delete-confirm"]').exists()).toBe(true)
    expect(tagRemoveMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="tag-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(tagRemoveMock).toHaveBeenCalledWith('t1')
  })

  it('hides all mutation controls when can_manage is false, keeping tags visible', () => {
    tagsPage.value = { tags: [tag({ uuid: 't1', name: 'Old' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountTab(false)

    expect(wrapper.find('[data-test="tag-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-row"]').exists()).toBe(true)
  })
})

// ── TagsTab: assignment mode (`product` prop given) ─────────────────────────────────────────

describe('TagsTab (product assignment)', () => {
  function mountAssignment(p: CommerceProduct, canManage = true) {
    return mount(TagsTab, { props: { product: p, canManage } })
  }

  it('shows the honest not-loaded state on fresh mount (never a guessed selection)', () => {
    tagsPage.value = { tags: [tag({ uuid: 't1', name: 'Tag 1' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="tag-assignment-unknown"]').exists()).toBe(true)
    const checkbox = wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]
    expect(checkbox!.props('modelValue')).toBe(false)
  })

  it('hides tag CRUD controls in assignment mode even when can_manage is true', () => {
    tagsPage.value = { tags: [tag({ uuid: 't1', name: 'Tag 1' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="tag-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-delete"]').exists()).toBe(false)
  })

  it('selects tags and saves the exact uuid list, then reflects the positively-known set', async () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Tag 1' }), tag({ uuid: 't2', name: 'Tag 2' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    setTagsMock.mockResolvedValue([tag({ uuid: 't1', name: 'Tag 1' })])
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="tag-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setTagsMock).toHaveBeenCalledWith({ productUuid: 'p1', tagUuids: ['t1'] })
    // Once the set-list response comes back, the unknown-state banner clears — the set is now
    // positively established, not a guess.
    expect(wrapper.find('[data-test="tag-assignment-unknown"]').exists()).toBe(false)
  })

  it('surfaces a validation 422 message on save without discarding the current selection', async () => {
    tagsPage.value = { tags: [tag({ uuid: 't1', name: 'Tag 1' })], total: 1, current_page: 1, per_page: 24 }
    setTagsMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { tag_uuids: 'tag_uuids must reference existing tags in this tenant.' }, {}),
    )
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="tag-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="tag-assignment-error"]').text()).toContain(
      'tag_uuids must reference existing tags in this tenant.',
    )
  })

  it('hides the save control and disables checkboxes when can_manage is false', () => {
    tagsPage.value = { tags: [tag({ uuid: 't1', name: 'Tag 1' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountAssignment(product({ uuid: 'p1' }), false)

    expect(wrapper.find('[data-test="tag-assignment-save"]').exists()).toBe(false)
    const checkbox = wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]
    expect(checkbox!.props('disabled')).toBe(true)
  })
})

// ── AttributesTab: management mode (no `product` prop) — attribute CRUD + nested values editor ──
// Unlike tags/categories, attributes carry a VALUES sub-collection (embedded, batch-loaded by
// `AttributeService::list()`) and — unlike tags — the slug stays editable after creation
// (`AttributeService::update()` has no tag-style immutability trap), so the edit-form test below
// asserts the FULL {slug, name, position} payload rather than a name-only one.

describe('AttributesTab (attribute management)', () => {
  function mountTab(canManage = true) {
    return mount(AttributesTab, { props: { canManage }, global: { stubs: { Modal: teleportStub } } })
  }

  it('renders each attribute', () => {
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', name: 'Color', slug: 'color' }), attribute({ uuid: 'a2', name: 'Size', slug: 'size' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountTab()

    expect(wrapper.findAll('[data-test="attribute-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Color')
  })

  it('shows the loading state', () => {
    attributesStatus.value = 'pending'
    const wrapper = mountTab()
    expect(wrapper.find('[data-test="attributes-loading"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    attributesStatus.value = 'error'
    const wrapper = mountTab()
    expect(wrapper.find('[data-test="attributes-error"]').exists()).toBe(true)
  })

  it('shows the empty state when there are no attributes', () => {
    attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mountTab()
    expect(wrapper.find('[data-test="attributes-empty"]').exists()).toBe(true)
  })

  it('sends the typed search as the q filter after the debounce settles', async () => {
    vi.useFakeTimers()
    try {
      const wrapper = mountTab()

      await wrapper.find('[data-test="attribute-search"]').setValue('col')
      expect((toValue(lastAttributesFilters.current) as { q?: string }).q).toBeUndefined()

      await vi.advanceTimersByTimeAsync(300)
      expect((toValue(lastAttributesFilters.current) as { q?: string }).q).toBe('col')
    } finally {
      vi.useRealTimers()
    }
  })

  it('shows pagination controls only once there is at least one attribute', async () => {
    attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mountTab()
    expect(wrapper.text()).not.toContain('Rows per page')

    attributesPage.value = { attributes: [attribute()], total: 1, current_page: 1, per_page: 24 }
    await flushPromises()
    expect(wrapper.text()).toContain('Rows per page')
  })

  it('creates an attribute from the add form', async () => {
    attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
    attributeCreateMock.mockResolvedValue(attribute({ uuid: 'new-1', name: 'Material', slug: 'material' }))
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-add"]').trigger('click')
    await wrapper.find('[data-test="attribute-name-input"]').setValue('Material')
    await wrapper.find('[data-test="attribute-slug-input"]').setValue('material')
    await wrapper.find('#attribute-form').trigger('submit')
    await flushPromises()

    expect(attributeCreateMock).toHaveBeenCalledWith({ slug: 'material', name: 'Material', position: 0 })
  })

  it('surfaces a duplicate-slug 422 message instead of vanishing it', async () => {
    attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
    attributeCreateMock.mockRejectedValue(new ApiError('Validation failed', 422, { slug: 'Slug already in use.' }, {}))
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-add"]').trigger('click')
    await wrapper.find('[data-test="attribute-name-input"]').setValue('Dup')
    await wrapper.find('[data-test="attribute-slug-input"]').setValue('dup')
    await wrapper.find('#attribute-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="attribute-form-error"]').text()).toContain('Slug already in use.')
  })

  it('updates an attribute via the edit form, sending slug/name/position — attribute slug stays editable', async () => {
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', name: 'Old', slug: 'old' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributeUpdateMock.mockResolvedValue(attribute({ uuid: 'a1', name: 'New name', slug: 'new-slug' }))
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-edit"]').trigger('click')
    // Unlike tags, the slug field stays editable while editing an attribute.
    expect(wrapper.find('[data-test="attribute-slug-input"]').attributes('disabled')).toBeUndefined()
    await wrapper.find('[data-test="attribute-name-input"]').setValue('New name')
    await wrapper.find('[data-test="attribute-slug-input"]').setValue('new-slug')
    await wrapper.find('#attribute-form').trigger('submit')
    await flushPromises()

    expect(attributeUpdateMock).toHaveBeenCalledWith({
      uuid: 'a1',
      input: { name: 'New name', slug: 'new-slug', position: 0 },
    })
  })

  it('requires confirmation before deleting an attribute', async () => {
    attributesPage.value = { attributes: [attribute({ uuid: 'a1', name: 'Old' })], total: 1, current_page: 1, per_page: 24 }
    attributeRemoveMock.mockResolvedValue(undefined)
    const wrapper = mountTab()

    expect(wrapper.find('[data-test="attribute-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="attribute-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="attribute-delete-confirm"]').exists()).toBe(true)
    expect(attributeRemoveMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="attribute-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(attributeRemoveMock).toHaveBeenCalledWith('a1')
  })

  it('hides all mutation controls when can_manage is false, keeping attributes visible', () => {
    attributesPage.value = { attributes: [attribute({ uuid: 'a1', name: 'Old' })], total: 1, current_page: 1, per_page: 24 }
    const wrapper = mountTab(false)

    expect(wrapper.find('[data-test="attribute-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-row"]').exists()).toBe(true)
  })

  // ── Nested values editor: add/edit/delete values per attribute ──────────────────────────────

  it('expands an attribute row to show its values, empty state included', async () => {
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', name: 'Color', slug: 'color', values: [] })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountTab()

    expect(wrapper.find('[data-test="attribute-values-panel"]').exists()).toBe(false)

    await wrapper.find('[data-test="attribute-values-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="attribute-values-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="attribute-values-empty"]').exists()).toBe(true)
  })

  it('lists each value once an attribute is expanded', async () => {
    attributesPage.value = {
      attributes: [
        attribute({
          uuid: 'a1',
          name: 'Color',
          values: [
            attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' }),
            attributeValue({ uuid: 'v2', value: 'Blue', slug: 'blue' }),
          ],
        }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-values-toggle"]').trigger('click')
    expect(wrapper.findAll('[data-test="attribute-value-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Red')
    expect(wrapper.text()).toContain('Blue')
  })

  it('adds a value to an attribute from the nested add-value form', async () => {
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', name: 'Color', values: [] })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributeCreateValueMock.mockResolvedValue(attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' }))
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-values-toggle"]').trigger('click')
    await wrapper.find('[data-test="attribute-value-add"]').trigger('click')
    await wrapper.find('[data-test="attribute-value-value-input"]').setValue('Red')
    await wrapper.find('[data-test="attribute-value-slug-input"]').setValue('red')
    await wrapper.find('#attribute-value-form').trigger('submit')
    await flushPromises()

    expect(attributeCreateValueMock).toHaveBeenCalledWith({
      attributeUuid: 'a1',
      input: { slug: 'red', value: 'Red', position: 0 },
    })
  })

  it('surfaces the composite-conflict "slug already in use for this attribute" 422 on add-value, not vanishing it', async () => {
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', name: 'Color', values: [] })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributeCreateValueMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { slug: 'Slug already in use for this attribute.' }, {}),
    )
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-values-toggle"]').trigger('click')
    await wrapper.find('[data-test="attribute-value-add"]').trigger('click')
    await wrapper.find('[data-test="attribute-value-value-input"]').setValue('Red')
    await wrapper.find('[data-test="attribute-value-slug-input"]').setValue('red')
    await wrapper.find('#attribute-value-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="attribute-value-form-error"]').text()).toContain(
      'Slug already in use for this attribute.',
    )
  })

  it('edits a value via the nested edit form', async () => {
    attributesPage.value = {
      attributes: [
        attribute({ uuid: 'a1', name: 'Color', values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })] }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributeUpdateValueMock.mockResolvedValue(attributeValue({ uuid: 'v1', value: 'Crimson', slug: 'red' }))
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-values-toggle"]').trigger('click')
    await wrapper.find('[data-test="attribute-value-edit"]').trigger('click')
    await wrapper.find('[data-test="attribute-value-value-input"]').setValue('Crimson')
    await wrapper.find('#attribute-value-form').trigger('submit')
    await flushPromises()

    expect(attributeUpdateValueMock).toHaveBeenCalledWith({
      uuid: 'v1',
      input: { slug: 'red', value: 'Crimson', position: 0 },
    })
  })

  it('requires confirmation before deleting a value', async () => {
    attributesPage.value = {
      attributes: [
        attribute({ uuid: 'a1', name: 'Color', values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })] }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributeRemoveValueMock.mockResolvedValue(undefined)
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-values-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="attribute-value-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="attribute-value-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="attribute-value-delete-confirm"]').exists()).toBe(true)
    expect(attributeRemoveValueMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="attribute-value-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(attributeRemoveValueMock).toHaveBeenCalledWith('v1')
  })

  it('hides value mutation controls when can_manage is false, keeping values visible', async () => {
    attributesPage.value = {
      attributes: [
        attribute({ uuid: 'a1', name: 'Color', values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })] }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountTab(false)

    await wrapper.find('[data-test="attribute-values-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="attribute-value-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-value-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-value-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-value-row"]').exists()).toBe(true)
  })
})

// ── AttributesTab: assignment mode (`product` prop given) ──────────────────────────────────
// Assignment rows are far richer than tags/categories' bare uuid list (SetProductAttributesData:
// each row is `{attribute_uuid?, name?, values?, used_for_variants?, visible?, position?}`), so
// these pin the EXACT payload shape for both an attribute-linked row and a custom (name-only) row.

describe('AttributesTab (product assignment)', () => {
  function mountAssignment(p: CommerceProduct, canManage = true) {
    return mount(AttributesTab, { props: { product: p, canManage } })
  }

  it('shows the honest not-loaded state on fresh mount (never a guessed selection)', () => {
    attributesPage.value = {
      attributes: [
        attribute({ uuid: 'a1', name: 'Color', values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })] }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="attribute-assignment-unknown"]').exists()).toBe(true)
    const checkbox = wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]
    expect(checkbox!.props('modelValue')).toBe(false)
  })

  it('hides attribute CRUD and value CRUD controls in assignment mode even when can_manage is true', async () => {
    attributesPage.value = {
      attributes: [
        attribute({ uuid: 'a1', name: 'Color', values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })] }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="attribute-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-delete"]').exists()).toBe(false)

    await wrapper.find('[data-test="attribute-values-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="attribute-value-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-value-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-value-delete"]').exists()).toBe(false)
  })

  it('builds and saves the exact row shape for an included attribute — attribute_uuid + selected value slugs + flags', async () => {
    attributesPage.value = {
      attributes: [
        attribute({
          uuid: 'a1',
          name: 'Color',
          values: [
            attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' }),
            attributeValue({ uuid: 'v2', value: 'Blue', slug: 'blue' }),
          ],
        }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    setAttributesMock.mockResolvedValue([
      {
        uuid: 'pa1',
        product_uuid: 'p1',
        attribute_uuid: 'a1',
        attribute_slug: 'color',
        attribute_name: 'Color',
        name: null,
        values: ['red'],
        used_for_variants: true,
        visible: true,
        position: 0,
      },
    ])
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    // [0] include the attribute.
    let checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await flushPromises()

    // Once included: [0] include, [1] value "red", [2] value "blue", [3] used-for-variants, [4] visible.
    checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    expect(checkboxes).toHaveLength(5)
    await checkboxes[1]!.vm.$emit('update:modelValue', true)
    await checkboxes[3]!.vm.$emit('update:modelValue', true)

    await wrapper.find('[data-test="attribute-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setAttributesMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      rows: [{ attribute_uuid: 'a1', values: ['red'], used_for_variants: true, visible: true }],
    })
    // Once the set-list response comes back, the unknown-state banner clears — the assignment is
    // now positively established, not a guess.
    expect(wrapper.find('[data-test="attribute-assignment-unknown"]').exists()).toBe(false)
  })

  it('keeps an included attribute in the saved payload after the visible page changes (wholesale replace must not drop off-page selections)', async () => {
    // The PUT replaces ALL assignments — a checked attribute that scrolls off-page via
    // search/pagination before Save must still be submitted, or it gets silently WIPED.
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', slug: 'color', name: 'Color', values: [] })],
      total: 2,
      current_page: 1,
      per_page: 1,
    }
    setAttributesMock.mockResolvedValue([])
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    // Include a1 while it is visible.
    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await flushPromises()

    // Simulate paging/searching away: the visible page now shows only a2.
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a2', slug: 'size', name: 'Size', values: [] })],
      total: 2,
      current_page: 2,
      per_page: 1,
    }
    await flushPromises()

    await wrapper.find('[data-test="attribute-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setAttributesMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      rows: [{ attribute_uuid: 'a1', values: [], used_for_variants: false, visible: true }],
    })
  })

  it('adds a custom attribute row and saves it with a name and free-text values, no attribute_uuid', async () => {
    attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
    setAttributesMock.mockResolvedValue([
      {
        uuid: 'pa1',
        product_uuid: 'p1',
        attribute_uuid: null,
        attribute_slug: null,
        attribute_name: null,
        name: 'Material',
        values: ['Cotton', 'Wool'],
        used_for_variants: false,
        visible: true,
        position: 0,
      },
    ])
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="attribute-assign-custom-add"]').trigger('click')
    await wrapper.find('[data-test="attribute-assign-custom-name"]').setValue('Material')
    await wrapper.find('[data-test="attribute-assign-custom-values"]').setValue('Cotton, Wool')

    await wrapper.find('[data-test="attribute-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setAttributesMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      rows: [{ name: 'Material', values: ['Cotton', 'Wool'], used_for_variants: false, visible: true }],
    })
  })

  it('removes a custom attribute row before saving', async () => {
    attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="attribute-assign-custom-add"]').trigger('click')
    expect(wrapper.find('[data-test="attribute-assign-custom-row"]').exists()).toBe(true)

    await wrapper.find('[data-test="attribute-assign-custom-remove"]').trigger('click')
    expect(wrapper.find('[data-test="attribute-assign-custom-row"]').exists()).toBe(false)
  })

  it('surfaces the composite-conflict "must not reference the same attribute more than once" 422 without discarding the selection', async () => {
    attributesPage.value = {
      attributes: [
        attribute({ uuid: 'a1', name: 'Color', values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })] }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    setAttributesMock.mockRejectedValue(
      new ApiError(
        'Validation failed',
        422,
        { attributes: 'attributes must not reference the same attribute more than once.' },
        {},
      ),
    )
    const wrapper = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="attribute-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="attribute-assignment-error"]').text()).toContain(
      'attributes must not reference the same attribute more than once.',
    )
    // The selection survives the failed save.
    expect(wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]!.props('modelValue')).toBe(true)
  })

  it('hides the save control and disables checkboxes when can_manage is false', () => {
    attributesPage.value = {
      attributes: [
        attribute({ uuid: 'a1', name: 'Color', values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })] }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountAssignment(product({ uuid: 'p1' }), false)

    expect(wrapper.find('[data-test="attribute-assignment-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-assign-custom-add"]').exists()).toBe(false)
    const checkbox = wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]
    expect(checkbox!.props('disabled')).toBe(true)
  })
})

// ── AddonsPanel: per-product add-on CRUD — a real per-product GET (unlike Categories/Tags/
// Attributes' assignment sections, which have none), so this suite never needs an "unknown
// assignment" fixture; every list assertion below asserts straight off `addonsData`. ─────────────

describe('AddonsPanel', () => {
  function mountPanel(p: CommerceProduct = product({ uuid: 'p1' }), canManage = true) {
    return mount(AddonsPanel, {
      props: { product: p, canManage },
      global: { stubs: { Modal: teleportStub } },
    })
  }

  // ── List: real GET, loading/error/empty, money display ─────────────────────────────────────

  it('renders each add-on from the real per-product GET', () => {
    addonsData.value = [
      addon({ uuid: 'a1', name: 'Gift wrap', field_type: 'checkbox', price_delta: 300 }),
      addon({ uuid: 'a2', name: 'Engraving', field_type: 'text', price_delta: 0 }),
    ]
    const wrapper = mountPanel()

    expect(wrapper.findAll('[data-test="addon-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Gift wrap')
    expect(wrapper.text()).toContain('Engraving')
    expect(lastAddonsProductUuid.current).toBeTruthy()
  })

  it('shows the loading state', () => {
    addonsStatus.value = 'pending'
    const wrapper = mountPanel()
    expect(wrapper.find('[data-test="addons-loading"]').exists()).toBe(true)
  })

  it('shows the error state', () => {
    addonsStatus.value = 'error'
    const wrapper = mountPanel()
    expect(wrapper.find('[data-test="addons-error"]').exists()).toBe(true)
  })

  it('shows the empty state when there are no add-ons', () => {
    addonsData.value = []
    const wrapper = mountPanel()
    expect(wrapper.find('[data-test="addons-empty"]').exists()).toBe(true)
  })

  it('always shows the snapshot-immutability notice, regardless of can_manage', () => {
    addonsData.value = []
    const wrapper = mountPanel(product({ uuid: 'p1' }), false)
    expect(wrapper.find('[data-test="addon-snapshot-notice"]').exists()).toBe(true)
  })

  it('formats a checkbox/text add-on price delta with useMoney, never a raw digit string', () => {
    addonsData.value = [addon({ uuid: 'a1', name: 'Gift wrap', field_type: 'checkbox', price_delta: 350 })]
    const wrapper = mountPanel()

    expect(wrapper.find('[data-test="addon-price"]').text()).toBe('$3.50')
  })

  it('formats a SELECT add-on’s per-choice deltas (including a negative one) and shows no row-level price', () => {
    addonsData.value = [
      addon({
        uuid: 'a1',
        name: 'Color',
        field_type: 'select',
        price_delta: 0,
        choices: [
          { key: 'red', label: 'Red', price_delta: 100 },
          { key: 'small', label: 'Small', price_delta: -125 },
        ],
      }),
    ]
    const wrapper = mountPanel()

    expect(wrapper.find('[data-test="addon-price"]').exists()).toBe(false)
    const choicePrices = wrapper.findAll('[data-test="addon-choice-price"]')
    expect(choicePrices.map((c) => c.text())).toEqual(['$1.00', '-$1.25'])
  })

  it('hides add/edit/delete controls and the form when can_manage is false, keeping rows visible', () => {
    addonsData.value = [addon({ uuid: 'a1', name: 'Gift wrap' })]
    const wrapper = mountPanel(product({ uuid: 'p1' }), false)

    expect(wrapper.find('[data-test="addon-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="addon-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="addon-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="addon-row"]').exists()).toBe(true)
  })

  // ── Create: money exactness (signed decimal -> exact minor units, never Number()) ──────────

  it('creates a checkbox add-on, converting a signed decimal price delta into exact minor units', async () => {
    addonsData.value = []
    createAddonMock.mockResolvedValue(
      addon({ uuid: 'new-1', name: 'Gift wrap', field_type: 'checkbox', price_delta: 350 }),
    )
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    selectByTestId(wrapper, 'addon-field-type-input').vm.$emit('update:modelValue', 'checkbox')
    await flushPromises()

    await wrapper.find('[data-test="addon-name-input"]').setValue('Gift wrap')
    await wrapper.find('[data-test="addon-price-delta-input"]').setValue('3.50')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(createAddonMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      input: {
        name: 'Gift wrap',
        field_type: 'checkbox',
        required: false,
        choices: null,
        price_delta: 350,
        position: null,
        status: 'active',
      },
    })
  })

  it('creates a text add-on with a NEGATIVE price delta, preserving the sign through exact minor units', async () => {
    addonsData.value = []
    createAddonMock.mockResolvedValue(
      addon({ uuid: 'new-2', name: 'Discount slot', field_type: 'text', price_delta: -200 }),
    )
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    await wrapper.find('[data-test="addon-name-input"]').setValue('Discount slot')
    await wrapper.find('[data-test="addon-price-delta-input"]').setValue('-2.00')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(createAddonMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      input: {
        name: 'Discount slot',
        field_type: 'text',
        required: false,
        choices: null,
        price_delta: -200,
        position: null,
        status: 'active',
      },
    })
  })

  it('defaults a blank price delta to 0 minor units', async () => {
    addonsData.value = []
    createAddonMock.mockResolvedValue(addon({ uuid: 'new-3' }))
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    await wrapper.find('[data-test="addon-name-input"]').setValue('Custom note')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(createAddonMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      input: expect.objectContaining({ price_delta: 0 }),
    })
  })

  it('creates a SELECT add-on with choices, converting each exact price delta (one negative) into minor units', async () => {
    addonsData.value = []
    createAddonMock.mockResolvedValue(
      addon({
        uuid: 'new-4',
        name: 'Color',
        field_type: 'select',
        required: true,
        position: 2,
        choices: [
          { key: 'red', label: 'Red', price_delta: 100 },
          { key: 'small', label: 'Small', price_delta: -125 },
        ],
      }),
    )
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    selectByTestId(wrapper, 'addon-field-type-input').vm.$emit('update:modelValue', 'select')
    await flushPromises()

    await wrapper.find('[data-test="addon-name-input"]').setValue('Color')
    await wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="addon-position-input"]').setValue('2')

    await wrapper.find('[data-test="addon-choice-add"]').trigger('click')
    await wrapper.find('[data-test="addon-choice-add"]').trigger('click')
    const keyInputs = wrapper.findAll('[data-test="addon-choice-key-input"]')
    await keyInputs[0]!.setValue('red')
    await keyInputs[1]!.setValue('small')
    const labelInputs = wrapper.findAll('[data-test="addon-choice-label-input"]')
    await labelInputs[0]!.setValue('Red')
    await labelInputs[1]!.setValue('Small')
    const deltaInputs = wrapper.findAll('[data-test="addon-choice-price-delta-input"]')
    await deltaInputs[0]!.setValue('1.00')
    await deltaInputs[1]!.setValue('-1.25')

    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(createAddonMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      input: {
        name: 'Color',
        field_type: 'select',
        required: true,
        choices: [
          { key: 'red', label: 'Red', price_delta: 100 },
          { key: 'small', label: 'Small', price_delta: -125 },
        ],
        price_delta: 0,
        position: 2,
        status: 'active',
      },
    })
  })

  // ── Create: client-side validation ──────────────────────────────────────────────────────────

  it('rejects a SELECT add-on with no choices client-side, without calling the mutation', async () => {
    addonsData.value = []
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    selectByTestId(wrapper, 'addon-field-type-input').vm.$emit('update:modelValue', 'select')
    await flushPromises()

    await wrapper.find('[data-test="addon-name-input"]').setValue('Color')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(createAddonMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="addon-choices-error"]').text()).toContain('Add at least one choice.')
  })

  it('rejects duplicate choice keys client-side, without calling the mutation', async () => {
    addonsData.value = []
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    selectByTestId(wrapper, 'addon-field-type-input').vm.$emit('update:modelValue', 'select')
    await flushPromises()
    await wrapper.find('[data-test="addon-name-input"]').setValue('Color')

    await wrapper.find('[data-test="addon-choice-add"]').trigger('click')
    await wrapper.find('[data-test="addon-choice-add"]').trigger('click')
    const keyInputs = wrapper.findAll('[data-test="addon-choice-key-input"]')
    await keyInputs[0]!.setValue('red')
    await keyInputs[1]!.setValue('red')
    const labelInputs = wrapper.findAll('[data-test="addon-choice-label-input"]')
    await labelInputs[0]!.setValue('Red')
    await labelInputs[1]!.setValue('Crimson')
    const deltaInputs = wrapper.findAll('[data-test="addon-choice-price-delta-input"]')
    await deltaInputs[0]!.setValue('1.00')
    await deltaInputs[1]!.setValue('1.50')

    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(createAddonMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="addon-choices-error"]').text()).toContain('Duplicate choice key')
  })

  it('rejects an invalid price delta client-side, without calling the mutation', async () => {
    addonsData.value = []
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    await wrapper.find('[data-test="addon-name-input"]').setValue('Gift wrap')
    await wrapper.find('[data-test="addon-price-delta-input"]').setValue('not-a-number')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(createAddonMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="addon-form-error"]').text()).toContain('Enter a valid price delta')
  })

  it('rejects a non-numeric position client-side, without calling the mutation', async () => {
    addonsData.value = []
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    await wrapper.find('[data-test="addon-name-input"]').setValue('Gift wrap')
    await wrapper.find('[data-test="addon-position-input"]').setValue('abc')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(createAddonMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="addon-form-error"]').text()).toContain(
      'Position must be a whole, non-negative number.',
    )
  })

  it('removes a choice row before saving', async () => {
    addonsData.value = []
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    selectByTestId(wrapper, 'addon-field-type-input').vm.$emit('update:modelValue', 'select')
    await flushPromises()

    await wrapper.find('[data-test="addon-choice-add"]').trigger('click')
    expect(wrapper.findAll('[data-test="addon-choice-row"]')).toHaveLength(1)

    await wrapper.find('[data-test="addon-choice-remove"]').trigger('click')
    expect(wrapper.findAll('[data-test="addon-choice-row"]')).toHaveLength(0)
  })

  it('surfaces a server 422 message instead of vanishing it', async () => {
    addonsData.value = []
    createAddonMock.mockRejectedValue(new ApiError('Validation failed', 422, { name: 'Name is required.' }, {}))
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    await wrapper.find('[data-test="addon-name-input"]').setValue('Gift wrap')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="addon-form-error"]').text()).toContain('Name is required.')
  })

  // ── Edit: pre-populates from the row (including choices), submits the FULL replace payload ──

  it('opens the edit form pre-populated from a CHECKBOX add-on row and submits the exact update payload', async () => {
    addonsData.value = [
      addon({
        uuid: 'a1',
        name: 'Gift wrap',
        field_type: 'checkbox',
        required: true,
        price_delta: 350,
        position: 1,
        status: 'inactive',
      }),
    ]
    updateAddonMock.mockResolvedValue(addon({ uuid: 'a1' }))
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-edit"]').trigger('click')

    expect((wrapper.find('[data-test="addon-name-input"]').element as HTMLInputElement).value).toBe('Gift wrap')
    expect((wrapper.find('[data-test="addon-price-delta-input"]').element as HTMLInputElement).value).toBe('3.50')
    expect((wrapper.find('[data-test="addon-position-input"]').element as HTMLInputElement).value).toBe('1')

    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(updateAddonMock).toHaveBeenCalledWith({
      uuid: 'a1',
      productUuid: 'p1',
      input: {
        name: 'Gift wrap',
        field_type: 'checkbox',
        required: true,
        choices: null,
        price_delta: 350,
        position: 1,
        status: 'inactive',
      },
    })
  })

  it('opens the edit form pre-populated from a SELECT add-on row, including its choices, and submits the update', async () => {
    addonsData.value = [
      addon({
        uuid: 'a1',
        name: 'Color',
        field_type: 'select',
        price_delta: 0,
        choices: [
          { key: 'red', label: 'Red', price_delta: 100 },
          { key: 'small', label: 'Small', price_delta: -125 },
        ],
      }),
    ]
    updateAddonMock.mockResolvedValue(addon({ uuid: 'a1' }))
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="addon-edit"]').trigger('click')

    const keyInputs = wrapper.findAll('[data-test="addon-choice-key-input"]')
    expect(keyInputs.map((i) => (i.element as HTMLInputElement).value)).toEqual(['red', 'small'])
    const deltaInputs = wrapper.findAll('[data-test="addon-choice-price-delta-input"]')
    expect(deltaInputs.map((i) => (i.element as HTMLInputElement).value)).toEqual(['1.00', '-1.25'])

    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(updateAddonMock).toHaveBeenCalledWith({
      uuid: 'a1',
      productUuid: 'p1',
      input: {
        name: 'Color',
        field_type: 'select',
        required: false,
        choices: [
          { key: 'red', label: 'Red', price_delta: 100 },
          { key: 'small', label: 'Small', price_delta: -125 },
        ],
        price_delta: 0,
        position: 0,
        status: 'active',
      },
    })
  })

  // ── Delete: requires confirmation ───────────────────────────────────────────────────────────

  it('requires confirmation before deleting an add-on', async () => {
    addonsData.value = [addon({ uuid: 'a1', name: 'Gift wrap' })]
    removeAddonMock.mockResolvedValue(undefined)
    const wrapper = mountPanel(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="addon-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="addon-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="addon-delete-confirm"]').exists()).toBe(true)
    expect(removeAddonMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="addon-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(removeAddonMock).toHaveBeenCalledWith({ uuid: 'a1', productUuid: 'p1' })
  })
})

// ── DownloadsPanel: per-variant digital-download CRUD — a real per-variant GET (unlike
// Categories/Tags/Attributes' assignment sections), but only fetched once a variant's section is
// expanded (only one at a time, mirroring VariantsPanel's adjustingUuid/editingUuid pattern). ────

describe('DownloadsPanel', () => {
  function mountPanel(
    p: CommerceProduct = product({ uuid: 'p1', type: 'digital', variants: [variant({ uuid: 'v1' })] }),
    canManage = true,
  ) {
    return mount(DownloadsPanel, {
      props: { product: p, canManage },
      global: { stubs: { Modal: teleportStub, MediaPickerModal: MediaPickerModalStub } },
    })
  }

  async function expandFirstVariant(wrapper: ReturnType<typeof mount>) {
    await wrapper.find('[data-test="download-variant-toggle"]').trigger('click')
    await flushPromises()
  }

  async function pickAFile(wrapper: ReturnType<typeof mount>) {
    await wrapper.find('[data-test="download-choose-file"]').trigger('click')
    await wrapper.find('[data-test="media-picker-stub-pick"]').trigger('click')
    await flushPromises()
  }

  // ── List: honest collapsed-by-default state, real GET once expanded, loading/error/empty ────

  it('shows the no-variants state when the product has no variants', () => {
    const wrapper = mountPanel(product({ uuid: 'p1', type: 'digital', variants: [] }))
    expect(wrapper.find('[data-test="downloads-no-variants"]').exists()).toBe(true)
  })

  it('renders a row per variant, collapsed by default (the per-variant GET is not fired)', () => {
    const wrapper = mountPanel(
      product({ uuid: 'p1', type: 'digital', variants: [variant({ uuid: 'v1' }), variant({ uuid: 'v2', sku: 'SKU-2' })] }),
    )
    expect(wrapper.findAll('[data-test="download-variant-row"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="download-row"]').exists()).toBe(false)
  })

  it('expands a variant and renders each download from the real per-variant GET, with exact limit/expiry text', async () => {
    downloadsData.value = [
      download({ uuid: 'd1', name: 'Ebook (PDF)', download_limit: 3, expiry_days: 30 }),
      download({ uuid: 'd2', name: 'Bonus chapter', download_limit: null, expiry_days: null, status: 'inactive' }),
    ]
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)

    const rows = wrapper.findAll('[data-test="download-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Ebook (PDF)')
    const limits = wrapper.findAll('[data-test="download-limit"]')
    const expiries = wrapper.findAll('[data-test="download-expiry"]')
    expect(limits[0]!.text()).toBe('3 download(s)')
    expect(expiries[0]!.text()).toBe('Expires 30 day(s) after purchase')
    expect(limits[1]!.text()).toBe('Unlimited downloads')
    expect(expiries[1]!.text()).toBe('Never expires')
    expect(toValue(lastDownloadsVariantUuid.current)).toBe('v1')
  })

  it('shows the loading state for the expanded variant', async () => {
    downloadsStatus.value = 'pending'
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)
    expect(wrapper.find('[data-test="downloads-loading"]').exists()).toBe(true)
  })

  it('shows the error state for the expanded variant', async () => {
    downloadsStatus.value = 'error'
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)
    expect(wrapper.find('[data-test="downloads-error"]').exists()).toBe(true)
  })

  it('shows the empty state for the expanded variant', async () => {
    downloadsData.value = []
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)
    expect(wrapper.find('[data-test="downloads-empty"]').exists()).toBe(true)
  })

  it('collapsing an expanded variant hides its downloads section again', async () => {
    downloadsData.value = [download({ uuid: 'd1' })]
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)
    expect(wrapper.find('[data-test="download-row"]').exists()).toBe(true)

    await expandFirstVariant(wrapper) // toggling again collapses
    expect(wrapper.find('[data-test="download-row"]').exists()).toBe(false)
  })

  // ── Gating: digital-only attach, can_manage ─────────────────────────────────────────────────

  it('shows the digital-only gate notice and hides Add download for a non-digital product', async () => {
    downloadsData.value = []
    const wrapper = mountPanel(
      product({ uuid: 'p1', type: 'physical', variants: [variant({ uuid: 'v1' })] }),
    )
    expect(wrapper.find('[data-test="downloads-type-gate"]').exists()).toBe(true)

    await expandFirstVariant(wrapper)
    expect(wrapper.find('[data-test="download-add"]').exists()).toBe(false)
  })

  it('hides the digital-only gate notice for a digital product', () => {
    const wrapper = mountPanel(product({ uuid: 'p1', type: 'digital', variants: [variant({ uuid: 'v1' })] }))
    expect(wrapper.find('[data-test="downloads-type-gate"]').exists()).toBe(false)
  })

  it('hides add/edit/detach controls and the form when can_manage is false, keeping rows visible', async () => {
    downloadsData.value = [download({ uuid: 'd1', name: 'Ebook (PDF)' })]
    const wrapper = mountPanel(
      product({ uuid: 'p1', type: 'digital', variants: [variant({ uuid: 'v1' })] }),
      false,
    )
    await expandFirstVariant(wrapper)

    expect(wrapper.find('[data-test="download-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="download-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="download-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="download-row"]').exists()).toBe(true)
    // The variant toggle itself is a read (GET), never gated on can_manage.
    expect(wrapper.find('[data-test="download-variant-toggle"]').exists()).toBe(true)
  })

  // ── Attach: blob picker reuse (MediaPickerModal precedent), exact payload ──────────────────

  it('attaches a download via the picker with the exact payload (blank limit/expiry/position -> null)', async () => {
    downloadsData.value = []
    attachDownloadMock.mockResolvedValue(download({ uuid: 'new-d', name: 'Ebook (PDF)' }))
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)

    await wrapper.find('[data-test="download-add"]').trigger('click')
    await pickAFile(wrapper)
    expect(wrapper.find('[data-test="download-chosen-blob"]').text()).toContain('blob-new')

    await wrapper.find('[data-test="download-name-input"]').setValue('Ebook (PDF)')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(attachDownloadMock).toHaveBeenCalledWith({
      variantUuid: 'v1',
      input: {
        blob_uuid: 'blob-new',
        name: 'Ebook (PDF)',
        download_limit: null,
        expiry_days: null,
        position: null,
      },
    })
  })

  it('attaches with explicit limit/expiry/position, converted to whole numbers', async () => {
    downloadsData.value = []
    attachDownloadMock.mockResolvedValue(download({ uuid: 'new-d' }))
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)

    await wrapper.find('[data-test="download-add"]').trigger('click')
    await pickAFile(wrapper)
    await wrapper.find('[data-test="download-name-input"]').setValue('Ebook (PDF)')
    await wrapper.find('[data-test="download-limit-input"]').setValue('5')
    await wrapper.find('[data-test="download-expiry-input"]').setValue('14')
    await wrapper.find('[data-test="download-position-input"]').setValue('2')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(attachDownloadMock).toHaveBeenCalledWith({
      variantUuid: 'v1',
      input: {
        blob_uuid: 'blob-new',
        name: 'Ebook (PDF)',
        download_limit: 5,
        expiry_days: 14,
        position: 2,
      },
    })
  })

  it('rejects submit without a name, without calling the mutation', async () => {
    downloadsData.value = []
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)
    await wrapper.find('[data-test="download-add"]').trigger('click')
    await pickAFile(wrapper)
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(attachDownloadMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="download-form-error"]').text()).toContain('Name is required.')
  })

  it('rejects submit without choosing a file first, without calling the mutation', async () => {
    downloadsData.value = []
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)
    await wrapper.find('[data-test="download-add"]').trigger('click')
    await wrapper.find('[data-test="download-name-input"]').setValue('Ebook')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(attachDownloadMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="download-form-error"]').text()).toContain('Choose a file first.')
  })

  it('rejects an invalid download limit client-side, without calling the mutation', async () => {
    downloadsData.value = []
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)
    await wrapper.find('[data-test="download-add"]').trigger('click')
    await pickAFile(wrapper)
    await wrapper.find('[data-test="download-name-input"]').setValue('Ebook')
    await wrapper.find('[data-test="download-limit-input"]').setValue('not-a-number')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(attachDownloadMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="download-form-error"]').text()).toContain(
      'Download limit must be a whole, non-negative number',
    )
  })

  it('rejects an invalid expiry-days value client-side, without calling the mutation', async () => {
    downloadsData.value = []
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)
    await wrapper.find('[data-test="download-add"]').trigger('click')
    await pickAFile(wrapper)
    await wrapper.find('[data-test="download-name-input"]').setValue('Ebook')
    await wrapper.find('[data-test="download-expiry-input"]').setValue('-5')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(attachDownloadMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="download-form-error"]').text()).toContain(
      'Expiry days must be a whole, non-negative number',
    )
  })

  it('surfaces a server 422 message instead of vanishing it', async () => {
    downloadsData.value = []
    attachDownloadMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { name: 'name is required.' }, {}),
    )
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)
    await wrapper.find('[data-test="download-add"]').trigger('click')
    await pickAFile(wrapper)
    await wrapper.find('[data-test="download-name-input"]').setValue('Ebook')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="download-form-error"]').text()).toContain('name is required.')
  })

  // ── Edit: pre-populates from the row, no "choose file" (blob is immutable after attach) ─────

  it('opens the edit form pre-populated from a download row and submits the exact update payload, including status', async () => {
    downloadsData.value = [
      download({ uuid: 'd1', name: 'Ebook (PDF)', download_limit: 3, expiry_days: 30, position: 1, status: 'active' }),
    ]
    updateDownloadMock.mockResolvedValue(download({ uuid: 'd1', status: 'inactive' }))
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)

    await wrapper.find('[data-test="download-edit"]').trigger('click')

    expect((wrapper.find('[data-test="download-name-input"]').element as HTMLInputElement).value).toBe(
      'Ebook (PDF)',
    )
    expect((wrapper.find('[data-test="download-limit-input"]').element as HTMLInputElement).value).toBe('3')
    expect((wrapper.find('[data-test="download-expiry-input"]').element as HTMLInputElement).value).toBe('30')
    expect((wrapper.find('[data-test="download-position-input"]').element as HTMLInputElement).value).toBe('1')
    // The blob can never change after attach (UpdateDownloadData has no blob_uuid field) — no
    // "choose file" affordance in edit mode.
    expect(wrapper.find('[data-test="download-choose-file"]').exists()).toBe(false)

    selectByTestId(wrapper, 'download-status-input').vm.$emit('update:modelValue', 'inactive')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(updateDownloadMock).toHaveBeenCalledWith({
      uuid: 'd1',
      variantUuid: 'v1',
      input: { name: 'Ebook (PDF)', download_limit: 3, expiry_days: 30, position: 1, status: 'inactive' },
    })
  })

  it('pre-populates blank limit/expiry as empty inputs (unlimited/never), and saves them back as null', async () => {
    downloadsData.value = [
      download({ uuid: 'd1', name: 'Bonus chapter', download_limit: null, expiry_days: null, position: 0 }),
    ]
    updateDownloadMock.mockResolvedValue(download({ uuid: 'd1' }))
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)

    await wrapper.find('[data-test="download-edit"]').trigger('click')
    expect((wrapper.find('[data-test="download-limit-input"]').element as HTMLInputElement).value).toBe('')
    expect((wrapper.find('[data-test="download-expiry-input"]').element as HTMLInputElement).value).toBe('')

    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(updateDownloadMock).toHaveBeenCalledWith({
      uuid: 'd1',
      variantUuid: 'v1',
      input: { name: 'Bonus chapter', download_limit: null, expiry_days: null, position: 0, status: 'active' },
    })
  })

  // ── Detach: requires confirmation ───────────────────────────────────────────────────────────

  it('requires confirmation before detaching a download', async () => {
    downloadsData.value = [download({ uuid: 'd1', name: 'Ebook (PDF)' })]
    removeDownloadMock.mockResolvedValue(undefined)
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)

    expect(wrapper.find('[data-test="download-delete-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="download-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="download-delete-confirm"]').exists()).toBe(true)
    expect(removeDownloadMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="download-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(removeDownloadMock).toHaveBeenCalledWith({ uuid: 'd1', variantUuid: 'v1' })
  })
})
