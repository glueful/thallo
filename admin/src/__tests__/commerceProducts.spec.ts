import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, ref, toValue, type Component } from 'vue'
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
import type {
  AssignedCategory,
  AssignedTag,
  ProductAttributeAssignment,
  ProductChildItem,
  ProductMediaItem,
  SectionEnvelope,
  VariantStock,
} from '@/queries/commerceProductSections'
import { createDirtyRegistry } from '@/composables/useSectionState'
import {
  useProductRevisionCoordinator,
  type ProductRevisionCoordinator,
} from '@/composables/useProductRevisionCoordinator'

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

// Task C1's media section read, mocked the same shape-preserving way as the rest of this file's
// query mocks: `mediaSectionRefetchMock`'s default implementation always resolves with whatever
// `mediaSectionData`/`mediaSectionStatus` currently hold — a Colada `refetch()` calling `DataState`.
// This lets a test simply mutate `mediaSectionData.value` BEFORE triggering an action that causes a
// refetch (coordinator `afterMutation()`/`refresh()`) to control what the "server" hands back,
// without needing to hand-simulate Colada's own internals.
const mediaSectionData = ref<SectionEnvelope<ProductMediaItem> | undefined>(undefined)
const mediaSectionStatus = ref<'pending' | 'error' | 'success'>('success')
const mediaSectionRefetchMock = vi.hoisted(() => vi.fn())

// Task C6: the categories/tags/attributes section reads (Task C1), mocked the same
// shape-preserving way as `mediaSectionData` above — each subsection's own hydration/coordinator
// wiring (`CategoriesTab`/`TagsTab`/`AttributesTab`, product-assignment mode) and the shell's own
// nav-hint subscription (`[uuid]/index.vue`) both read off these same refs.
const categoriesSectionData = ref<SectionEnvelope<AssignedCategory> | undefined>(undefined)
const categoriesSectionStatus = ref<'pending' | 'error' | 'success'>('success')
const categoriesSectionRefetchMock = vi.hoisted(() => vi.fn())
const tagsSectionData = ref<SectionEnvelope<AssignedTag> | undefined>(undefined)
const tagsSectionStatus = ref<'pending' | 'error' | 'success'>('success')
const tagsSectionRefetchMock = vi.hoisted(() => vi.fn())
const attributesSectionData = ref<SectionEnvelope<ProductAttributeAssignment> | undefined>(
  undefined,
)
const attributesSectionStatus = ref<'pending' | 'error' | 'success'>('success')
const attributesSectionRefetchMock = vi.hoisted(() => vi.fn())

// Task C7: the stock section read — mocked the same shape-preserving way as the others above.
// `PricingStockCard` owns this subscription; `VariantsPanel` never imports `useProductStock`
// itself (it stays presentational about stock, fed via props), so this mock is only ever
// exercised through `PricingStockCard` or the full shell (`ProductDetail`).
const stockSectionData = ref<SectionEnvelope<VariantStock> | undefined>(undefined)
const stockSectionStatus = ref<'pending' | 'error' | 'success'>('success')
const stockSectionRefetchMock = vi.hoisted(() => vi.fn())

// Task C8: the children section read — mocked the same shape-preserving way as the others above.
// `ChildrenCard` owns this subscription; the shell holds a second, cheap subscription of its own
// purely for the nav's draft-only "Children · n" hint (mirrors `mediaSectionData` above).
const childrenSectionData = ref<SectionEnvelope<ProductChildItem> | undefined>(undefined)
const childrenSectionStatus = ref<'pending' | 'error' | 'success'>('success')
const childrenSectionRefetchMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/commerceProductSections', () => ({
  useProductMedia: () => ({
    data: mediaSectionData,
    status: mediaSectionStatus,
    refetch: mediaSectionRefetchMock,
  }),
  useProductCategories: () => ({
    data: categoriesSectionData,
    status: categoriesSectionStatus,
    refetch: categoriesSectionRefetchMock,
  }),
  useProductTags: () => ({
    data: tagsSectionData,
    status: tagsSectionStatus,
    refetch: tagsSectionRefetchMock,
  }),
  useProductAttributes: () => ({
    data: attributesSectionData,
    status: attributesSectionStatus,
    refetch: attributesSectionRefetchMock,
  }),
  useProductStock: () => ({
    data: stockSectionData,
    status: stockSectionStatus,
    refetch: stockSectionRefetchMock,
  }),
  useProductChildren: () => ({
    data: childrenSectionData,
    status: childrenSectionStatus,
    refetch: childrenSectionRefetchMock,
  }),
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

// Command Center phase 2 (spec §5.4b): the strip reads per-product order activity from
// commerce 1.6.0's read. Defaults to 'error' — the panels must be ABSENT until a test
// explicitly provides activity (mirrors an admin running against an older commerce).
const productOrderActivityData = ref<
  | {
      window_days: number
      summary: { orders: number; revenue_minor: number }
      recent: Array<Record<string, unknown>>
    }
  | undefined
>(undefined)
const productOrderActivityStatus = ref<'pending' | 'error' | 'success'>('error')
vi.mock('@/queries/commerceOrders', () => ({
  useProductOrderActivity: () => ({
    data: productOrderActivityData,
    status: productOrderActivityStatus,
  }),
}))

// The page (spec §5.4b phase 3) reads the product-link projection for the server-built
// storefront_url; the Linked-content panel itself stays stubbed in these suites (its own
// behavior is covered by commerceLinkPanel.spec.ts) — this mock only has to satisfy the
// module's import surface.
const productLinkData = ref<{ product_uuid: string; storefront_url: string; link: null } | undefined>(
  undefined,
)
vi.mock('@/queries/commerceLinking', () => ({
  useProductLink: () => ({ data: productLinkData, status: ref('success'), refetch: vi.fn() }),
  useEntryLink: () => ({ data: ref(undefined), status: ref('success'), refetch: vi.fn() }),
  useEntrySearch: () => ({ data: ref(undefined) }),
  useProductSearchForLink: () => ({ data: ref(undefined) }),
  useCommerceLinkMutations: () => ({
    link: { mutateAsync: vi.fn(), isLoading: ref(false) },
    unlink: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
}))

const routeState = vi.hoisted(() => ({
  params: {} as Record<string, string>,
  query: {} as Record<string, string>,
}))
const routerPush = vi.hoisted(() => vi.fn())
const routerReplace = vi.hoisted(() => vi.fn())
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ push: routerPush, replace: routerReplace, resolve: vi.fn() }),
}))
// Nuxt UI's Link (behind UButton's `to` prop and <RouterLink>) resolves useRoute/useRouter from
// vue-router/auto — mirrors navigationPage.spec.ts's established pattern. importOriginal keeps
// the real RouterLink export (Link.vue renders through it directly, not by stubbable tag name).
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ push: routerPush, replace: routerReplace, resolve: vi.fn() }),
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

// Task C8: ChildrenCard's "add a child" picker — `useProductSearchForChildren`, mocked the same
// shape-preserving way as the rest of this file's query mocks (`childrenPickerResultsMock`'s
// default implementation always resolves with whatever `childrenPickerResults` currently holds).
const childrenPickerResults = ref<CommerceProduct[] | undefined>(undefined)
const childrenPickerStatus = ref<'pending' | 'error' | 'success'>('success')
const lastChildrenPickerQuery = vi.hoisted(() => ({ current: undefined as unknown }))

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
    useProductSearchForChildren: (q: unknown) => {
      lastChildrenPickerQuery.current = q
      return { data: childrenPickerResults, status: childrenPickerStatus }
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
import PricingStockCard from '@/pages/commerce/products/components/PricingStockCard.vue'
import MediaPanel from '@/pages/commerce/products/components/MediaPanel.vue'
import CategoriesTab from '@/pages/commerce/products/components/CategoriesTab.vue'
import TagsTab from '@/pages/commerce/products/components/TagsTab.vue'
import AttributesTab from '@/pages/commerce/products/components/AttributesTab.vue'
import AddonsPanel from '@/pages/commerce/products/components/AddonsPanel.vue'
import DownloadsPanel from '@/pages/commerce/products/components/DownloadsPanel.vue'
import ChildrenCard from '@/pages/commerce/products/components/ChildrenCard.vue'
import ProductsIndex from '@/pages/commerce/products/index.vue'
import ProductDetail from '@/pages/commerce/products/[uuid]/index.vue'
import ProductCreate from '@/pages/commerce/products/new.vue'
import EditorSectionCard from '@/pages/commerce/products/components/EditorSectionCard.vue'
import SectionNav, {
  resolveSectionIndicator,
  type SectionNavItem,
} from '@/pages/commerce/products/components/SectionNav.vue'
import type { SectionPhase, SectionState } from '@/composables/useSectionState'
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
    options: {},
    metadata: {},
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
    option_values: {},
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

/** A `products.media.index` (Task C1) row — the section-read projection MediaPanel now hydrates
 * from, distinct from `media()` above (the mutation-response shape, which also carries
 * `product_uuid`). */
function mediaItem(overrides: Partial<ProductMediaItem> = {}): ProductMediaItem {
  return {
    uuid: 'm1',
    blob_uuid: 'blob-1',
    role: 'gallery',
    position: 0,
    alt: null,
    variant_uuid: null,
    ...overrides,
  }
}

/** A `products.children.index` (Task C1) row — the section-read projection `ChildrenCard`
 * hydrates from. `deleted: false`/live status by default; individual tests override `deleted`
 * to exercise the honest tombstone rendering. */
function childItem(overrides: Partial<ProductChildItem> = {}): ProductChildItem {
  return {
    uuid: 'child1',
    name: 'Child One',
    slug: 'child-one',
    status: 'active',
    deleted: false,
    position: 0,
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
const teleportStub = {
  props: ['open'],
  template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>',
}
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

/**
 * Mounts a section card (ProductForm/MediaPanel, Task C5) underneath a small host that provides
 * the REAL `DirtyRegistry` (Task C2) and `ProductRevisionCoordinator` (Task C3) — both cards now
 * `useSectionState()` (which THROWS without an ancestor registry) and `inject()` the coordinator to
 * await `afterMutation()`/call `refresh()`. Using the real C2/C3 implementations (already
 * exhaustively tested in their own spec files) rather than a hand-rolled fake coordinator means
 * MediaPanel's `register()`/`adoptRemote()`/`reconcileRemote()` wiring is exercised through the
 * SAME dispatch logic production uses — a test only needs to spy on the returned coordinator's
 * methods for call-count assertions, never re-implement its routing.
 */
function mountWithEditorContext(
  component: Component,
  props: Record<string, unknown>,
  mountOptions: Record<string, unknown> = {},
): {
  wrapper: ReturnType<typeof mount>
  getCoordinator: () => ProductRevisionCoordinator
  getState: () => SectionState
} {
  let coordinator!: ProductRevisionCoordinator
  let state!: SectionState
  // Declares Host's props from the INITIAL keys so they're reactive (not plain fallthrough attrs)
  // — `wrapper.setProps(...)` on the returned wrapper (Host is the mounted root) then correctly
  // re-renders `component` with fresh prop values, exactly like mounting it directly would.
  //
  // `component`'s `state` emit is captured directly via an `onState` handler rather than read back
  // through `wrapper.emitted('state')` — VTU's `emitted()` only tracks events emitted by the
  // WRAPPER's own root component (Host), not a descendant's, since `component` here is Host's
  // child, not the mounted root itself.
  const Host = defineComponent({
    name: 'EditorContextHost',
    props: Object.keys(props),
    setup(hostProps) {
      createDirtyRegistry()
      coordinator = useProductRevisionCoordinator()
      return () =>
        h(component, {
          ...hostProps,
          onState: (s: SectionState) => {
            state = s
          },
        })
    },
  })
  const wrapper = mount(Host, { ...mountOptions, props })
  return { wrapper, getCoordinator: () => coordinator, getState: () => state }
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
  mediaSectionData.value = { revision: 0, items: [] }
  mediaSectionStatus.value = 'success'
  mediaSectionRefetchMock.mockReset()
  mediaSectionRefetchMock.mockImplementation(async () => ({
    status: mediaSectionStatus.value,
    data: mediaSectionData.value,
    error: null,
  }))
  categoriesSectionData.value = { revision: 0, items: [] }
  categoriesSectionStatus.value = 'success'
  categoriesSectionRefetchMock.mockReset()
  categoriesSectionRefetchMock.mockImplementation(async () => ({
    status: categoriesSectionStatus.value,
    data: categoriesSectionData.value,
    error: null,
  }))
  tagsSectionData.value = { revision: 0, items: [] }
  tagsSectionStatus.value = 'success'
  tagsSectionRefetchMock.mockReset()
  tagsSectionRefetchMock.mockImplementation(async () => ({
    status: tagsSectionStatus.value,
    data: tagsSectionData.value,
    error: null,
  }))
  attributesSectionData.value = { revision: 0, items: [] }
  attributesSectionStatus.value = 'success'
  attributesSectionRefetchMock.mockReset()
  attributesSectionRefetchMock.mockImplementation(async () => ({
    status: attributesSectionStatus.value,
    data: attributesSectionData.value,
    error: null,
  }))
  stockSectionData.value = { revision: 0, items: [] }
  stockSectionStatus.value = 'success'
  stockSectionRefetchMock.mockReset()
  stockSectionRefetchMock.mockImplementation(async () => ({
    status: stockSectionStatus.value,
    data: stockSectionData.value,
    error: null,
  }))
  childrenSectionData.value = { revision: 0, items: [] }
  childrenSectionStatus.value = 'success'
  childrenSectionRefetchMock.mockReset()
  childrenSectionRefetchMock.mockImplementation(async () => ({
    status: childrenSectionStatus.value,
    data: childrenSectionData.value,
    error: null,
  }))
  productLinkData.value = undefined
  productOrderActivityData.value = undefined
  productOrderActivityStatus.value = 'error'
  childrenPickerResults.value = undefined
  childrenPickerStatus.value = 'success'
  lastChildrenPickerQuery.current = undefined
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
  const rows = [
    product({ uuid: 'p1', name: 'Widget' }),
    product({ uuid: 'p2', name: 'Gadget', slug: 'gadget' }),
  ]

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
  function mountForm(p: CommerceProduct, canManage = true) {
    return mountWithEditorContext(ProductForm, { product: p, canManage })
  }

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
          option_values: {},
        },
      ],
    })
    const { wrapper } = mountForm(p)
    expect(wrapper.find('[data-test="product-base-price"]').text()).toContain('$1,234.56')
  })

  it('shows no base price section when the product has no variants', () => {
    const { wrapper } = mountForm(product())
    expect(wrapper.find('[data-test="product-base-price"]').exists()).toBe(false)
  })

  it('hides the save button when can_manage is false (read-only)', () => {
    const { wrapper } = mountForm(product(), false)
    expect(wrapper.find('[data-test="product-form-save"]').exists()).toBe(false)
  })

  it('shows the save button and submits the current fields when can_manage is true', async () => {
    const p = product({ uuid: 'p1', name: 'Widget', slug: 'widget' })
    updateMock.mockResolvedValue(p)
    const { wrapper } = mountForm(p)

    expect(wrapper.find('[data-test="product-form-save"]').exists()).toBe(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateMock).toHaveBeenCalledWith({
      uuid: 'p1',
      input: {
        name: 'Widget',
        slug: 'widget',
        description: null,
        status: 'active',
        tax_class: null,
      },
    })
  })

  it('renders type as read-only text (no type select) and never sends it in the update payload', async () => {
    const p = product({ uuid: 'p1', type: 'digital' })
    updateMock.mockResolvedValue(p)
    const { wrapper } = mountForm(p)

    expect(wrapper.find('[data-test="product-type-value"]').text()).toBe('digital')
    expect(wrapper.find('[data-test="product-type-note"]').text()).toContain(
      'Type is set at creation.',
    )
    // Only the Status select remains — Type's editable USelect is gone entirely.
    expect(wrapper.findAllComponents({ name: 'SelectRoot' })).toHaveLength(1)

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(updateMock).toHaveBeenCalledWith(
      expect.objectContaining({ input: expect.not.objectContaining({ type: expect.anything() }) }),
    )
  })

  it('surfaces a 422 field error on the remaining editable fields as a banner', async () => {
    const p = product({ uuid: 'p1', slug: 'widget' })
    updateMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { slug: 'Slug already taken.' }, {}),
    )
    const { wrapper } = mountForm(p)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="product-form-error"]').text()).toContain('Slug already taken.')
  })

  it('wires useSectionState around field edits and the save mutation, awaiting afterMutation() once', async () => {
    const p = product({ uuid: 'p1', name: 'Widget', slug: 'widget' })
    updateMock.mockResolvedValue(p)
    const { wrapper, getCoordinator, getState } = mountForm(p)
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')
    const state = getState()

    expect(state.dirty.value).toBe(false)
    await wrapper.find('[data-test="product-name-input"]').setValue('Widget 2')
    expect(state.dirty.value).toBe(true)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(state.dirty.value).toBe(false)
    expect(state.phase.value).toBe('saved')
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('a failed save keeps the section dirty and does not call afterMutation()', async () => {
    const p = product({ uuid: 'p1' })
    updateMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { slug: 'Already taken.' }, {}),
    )
    const { wrapper, getCoordinator, getState } = mountForm(p)
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')
    const state = getState()

    await wrapper.find('[data-test="product-name-input"]').setValue('Widget 2')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(state.dirty.value).toBe(true)
    expect(state.phase.value).toBe('error')
    expect(afterMutationSpy).not.toHaveBeenCalled()
  })

  it('a clean section adopts a refreshed product prop, but a dirty one keeps its local draft', async () => {
    const p = product({ uuid: 'p1', name: 'Widget' })
    const { wrapper, getState } = mountForm(p)
    const state = getState()

    // Clean: an external product refresh (e.g. another section's afterMutation()) is adopted.
    await wrapper.setProps({ product: product({ uuid: 'p1', name: 'Widget Renamed' }) })
    expect(
      (wrapper.find('[data-test="product-name-input"]').element as HTMLInputElement).value,
    ).toBe('Widget Renamed')

    // Dirty: a local edit survives an external product refresh instead of being silently wiped.
    await wrapper.find('[data-test="product-name-input"]').setValue('My Local Edit')
    expect(state.dirty.value).toBe(true)
    await wrapper.setProps({ product: product({ uuid: 'p1', name: 'Server Wins?' }) })
    expect(
      (wrapper.find('[data-test="product-name-input"]').element as HTMLInputElement).value,
    ).toBe('My Local Edit')
  })

  // ── External link fieldset (spec §5.4 gap fix 2) ───────────────────────────────────────────

  it('renders the External link fieldset ONLY for external products, prefilled from metadata', () => {
    const external = product({
      uuid: 'p1',
      type: 'external',
      metadata: { external_url: 'https://partner.example/x', button_label: 'Buy at Partner' },
    })
    const { wrapper } = mountForm(external)
    expect(
      (wrapper.find('[data-test="product-external-url-input"]').element as HTMLInputElement).value,
    ).toBe('https://partner.example/x')
    expect(
      (wrapper.find('[data-test="product-button-label-input"]').element as HTMLInputElement).value,
    ).toBe('Buy at Partner')

    const { wrapper: physical } = mountForm(product({ uuid: 'p2', type: 'physical' }))
    expect(physical.find('[data-test="product-external-url-input"]').exists()).toBe(false)
    expect(physical.find('[data-test="product-button-label-input"]').exists()).toBe(false)
  })

  it('saving an external product sends the MERGED metadata — unrelated keys survive, blank label is dropped', async () => {
    updateMock.mockResolvedValue(undefined)
    const external = product({
      uuid: 'p1',
      type: 'external',
      metadata: {
        external_url: 'https://partner.example/x',
        button_label: 'Old Label',
        custom_key: 'must-survive',
      },
    })
    const { wrapper } = mountForm(external)

    await wrapper
      .find('[data-test="product-external-url-input"]')
      .setValue('https://partner.example/new')
    await wrapper.find('[data-test="product-button-label-input"]').setValue('')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateMock).toHaveBeenCalledWith({
      uuid: 'p1',
      input: {
        name: 'Widget',
        slug: 'widget',
        description: null,
        status: 'active',
        tax_class: null,
        metadata: { external_url: 'https://partner.example/new', custom_key: 'must-survive' },
      },
    })
  })

  it('an invalid external link fails client-side validation and never calls the mutation', async () => {
    const external = product({
      uuid: 'p1',
      type: 'external',
      metadata: { external_url: 'https://partner.example/x' },
    })
    const { wrapper } = mountForm(external)

    await wrapper.find('[data-test="product-external-url-input"]').setValue('not-a-url')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('A valid http(s) link is required')
  })

  it('non-external products never include metadata in the update payload', async () => {
    updateMock.mockResolvedValue(undefined)
    const { wrapper } = mountForm(product({ uuid: 'p1', metadata: { some_key: 'untouched' } }))

    await wrapper.find('[data-test="product-name-input"]').setValue('Widget Renamed')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateMock).toHaveBeenCalledWith({
      uuid: 'p1',
      input: {
        name: 'Widget Renamed',
        slug: 'widget',
        description: null,
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

  it('shows the New product button when can_manage is true and navigates to the create route', async () => {
    const wrapper = mount(ProductsIndex, { global: { stubs: pageStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="new-product"]').exists()).toBe(true)

    await wrapper.find('[data-test="new-product"]').trigger('click')
    await flushPromises()

    // Spec §5.4: the create slideover is gone — the button navigates to the full-page route.
    expect(routerPush).toHaveBeenCalledWith('/commerce/products/new')
  })
})

// ── The Omnibox Launcher (spec §5.4, approved 2026-07-23) ─────────────────────────────────────

describe('product create page — Omnibox Launcher', () => {
  function mountCreate() {
    return mount(ProductCreate, { global: { stubs: pageStubs } })
  }

  it('renders the launcher: omnibox, four type cards, import doorway — and NO dormant section cards', async () => {
    const wrapper = mountCreate()
    await flushPromises()

    expect(wrapper.find('[data-test="omnibox-input"]').exists()).toBe(true)
    for (const t of ['physical', 'digital', 'external', 'grouped']) {
      expect(wrapper.find(`[data-test="type-card-${t}"]`).exists()).toBe(true)
    }
    expect(wrapper.find('[data-test="type-card-physical"]').attributes('aria-checked')).toBe('true')
    expect(wrapper.find('[data-test="import-doorway"]').exists()).toBe(true)

    // The launcher stands alone (user decision 2026-07-23): sections first appear in the
    // editor the create lands in, never as dormant cards here.
    expect(wrapper.find('[data-test^="create-dormant-"]').exists()).toBe(false)
    expect(wrapper.find('[data-test^="editor-section-"]').exists()).toBe(false)
  })

  it('teaches the parse rules under the omnibox for purchasable types only, in the TENANT currency', async () => {
    const wrapper = mountCreate()
    await flushPromises()

    // Physical (default): the hint explains the money-token syntax INCLUDING the surprising
    // bare-integer rule — currency-neutral, using the tenant's own code (never "$").
    const hint = wrapper.find('[data-test="omnibox-hint"]')
    expect(hint.text()).toContain('end with a price')
    expect(hint.text()).toContain('120 USD')
    expect(hint.text()).toContain('mark them with USD')
    expect(hint.text()).not.toContain('$8')

    // External/grouped collect no price — a price tip there would mislead.
    await wrapper.find('[data-test="type-card-external"]').trigger('click')
    expect(wrapper.find('[data-test="omnibox-hint"]').exists()).toBe(false)
    await wrapper.find('[data-test="type-card-grouped"]').trigger('click')
    expect(wrapper.find('[data-test="omnibox-hint"]').exists()).toBe(false)
    await wrapper.find('[data-test="type-card-digital"]').trigger('click')
    expect(wrapper.find('[data-test="omnibox-hint"]').exists()).toBe(true)
  })

  it('lifts a whole-number price marked with the tenant currency code — no "$" required', async () => {
    createMock.mockResolvedValue(
      product({ uuid: 'new-5', name: 'Aurora Desk Lamp', slug: 'aurora-desk-lamp' }),
    )
    const wrapper = mountCreate()
    await flushPromises()

    await wrapper.find('[data-test="omnibox-input"]').setValue('Aurora Desk Lamp 89 USD')
    await flushPromises()

    expect(wrapper.find('[data-test="chip-name"]').text()).toContain('Aurora Desk Lamp')
    expect(wrapper.find('[data-test="chip-price"]').text()).toContain('89')

    await wrapper.find('[data-test="product-create-submit"]').trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      slug: 'aurora-desk-lamp',
      name: 'Aurora Desk Lamp',
      type: 'physical',
      status: 'draft',
      variants: [{ sku: 'aurora-desk-lamp', price: 8900, currency: 'USD' }],
    })
  })

  it('parses a trailing money token into honest chips and an exact create payload, then REPLACES the route', async () => {
    createMock.mockResolvedValue(
      product({ uuid: 'new-1', name: 'Aurora Desk Lamp', slug: 'aurora-desk-lamp' }),
    )
    const wrapper = mountCreate()
    await flushPromises()

    await wrapper.find('[data-test="omnibox-input"]').setValue('Aurora Desk Lamp $89')
    await flushPromises()

    expect(wrapper.find('[data-test="chip-name"]').text()).toContain('Aurora Desk Lamp')
    expect(wrapper.find('[data-test="chip-slug"]').text()).toContain('aurora-desk-lamp')
    expect(wrapper.find('[data-test="chip-sku"]').text()).toContain('aurora-desk-lamp')
    expect(wrapper.find('[data-test="chip-price"]').text()).toContain('89')
    expect(wrapper.find('[data-test="chip-no-price"]').exists()).toBe(false)

    await wrapper.find('[data-test="product-create-submit"]').trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      slug: 'aurora-desk-lamp',
      name: 'Aurora Desk Lamp',
      type: 'physical',
      status: 'draft',
      variants: [{ sku: 'aurora-desk-lamp', price: 8900, currency: 'USD' }],
    })
    expect(routerReplace).toHaveBeenCalledWith('/commerce/products/new-1')
    expect(routerPush).not.toHaveBeenCalled()
  })

  it('a bare trailing integer stays in the NAME (model numbers are names), price falls back to a $0 draft', async () => {
    createMock.mockResolvedValue(product({ uuid: 'new-2', name: 'Lamp 89', slug: 'lamp-89' }))
    const wrapper = mountCreate()
    await flushPromises()

    await wrapper.find('[data-test="omnibox-input"]').setValue('Lamp 89')
    await flushPromises()

    expect(wrapper.find('[data-test="chip-name"]').text()).toContain('Lamp 89')
    expect(wrapper.find('[data-test="chip-price"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="chip-no-price"]').exists()).toBe(true)

    await wrapper.find('[data-test="product-create-submit"]').trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      slug: 'lamp-89',
      name: 'Lamp 89',
      type: 'physical',
      status: 'draft',
      variants: [{ sku: 'lamp-89', price: 0, currency: 'USD' }],
    })
  })

  it('External: price affordance disappears, the required Link field gates create, payload carries metadata.external_url', async () => {
    createMock.mockResolvedValue(
      product({ uuid: 'new-3', name: 'Partner Desk', slug: 'partner-desk', type: 'external' }),
    )
    const wrapper = mountCreate()
    await flushPromises()

    await wrapper.find('[data-test="omnibox-input"]').setValue('Partner Desk $99')
    await wrapper.find('[data-test="type-card-external"]').trigger('click')
    await flushPromises()

    // The parse still lifts "$99" from the text, but external renders no price chips at all.
    expect(wrapper.find('[data-test="chip-price"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="chip-no-price"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="chip-external"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="chip-link-required"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-test="product-create-submit"]').attributes('disabled'),
    ).toBeDefined()

    await wrapper.find('[data-test="external-url-input"]').setValue('https://partner.example/desk')
    await flushPromises()
    expect(wrapper.find('[data-test="chip-link-ok"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-test="product-create-submit"]').attributes('disabled'),
    ).toBeUndefined()

    await wrapper.find('[data-test="product-create-submit"]').trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      slug: 'partner-desk',
      name: 'Partner Desk',
      type: 'external',
      status: 'draft',
      variants: [],
      metadata: { external_url: 'https://partner.example/desk' },
    })
  })

  it('Grouped: bundle chip, no price, empty variants, no metadata', async () => {
    createMock.mockResolvedValue(
      product({ uuid: 'new-4', name: 'Starter Kit', slug: 'starter-kit', type: 'grouped' }),
    )
    const wrapper = mountCreate()
    await flushPromises()

    await wrapper.find('[data-test="omnibox-input"]').setValue('Starter Kit')
    await wrapper.find('[data-test="type-card-grouped"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="chip-grouped"]').exists()).toBe(true)

    await wrapper.find('[data-test="product-create-submit"]').trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith({
      slug: 'starter-kit',
      name: 'Starter Kit',
      type: 'grouped',
      status: 'draft',
      variants: [],
    })
  })

  it('digital keeps the price affordance', async () => {
    const wrapper = mountCreate()
    await flushPromises()

    await wrapper.find('[data-test="type-card-digital"]').trigger('click')
    await wrapper.find('[data-test="omnibox-input"]').setValue('Ebook $12.50')
    await flushPromises()

    expect(wrapper.find('[data-test="chip-price"]').text()).toContain('12.50')
  })

  it('number keys 1-4 select a type when focus is outside the inputs, and are ignored inside them', async () => {
    const wrapper = mountCreate()
    await flushPromises()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: '3', bubbles: true }))
    await flushPromises()
    expect(wrapper.find('[data-test="type-card-external"]').attributes('aria-checked')).toBe('true')

    // Typing "2" INSIDE the omnibox must not switch types.
    const input = wrapper.find('[data-test="omnibox-input"]').element as HTMLInputElement
    input.dispatchEvent(new KeyboardEvent('keydown', { key: '2', bubbles: true }))
    await flushPromises()
    expect(wrapper.find('[data-test="type-card-external"]').attributes('aria-checked')).toBe('true')

    wrapper.unmount()
  })

  it('a server 422 (slug taken) shows the banner, marks the omnibox, retains the text, and never navigates or retries', async () => {
    createMock.mockRejectedValueOnce(
      new ApiError('Validation failed', 422, { slug: 'Slug already in use.' }, {}),
    )
    const wrapper = mountCreate()
    await flushPromises()

    await wrapper.find('[data-test="omnibox-input"]').setValue('Aurora Desk Lamp $89')
    await wrapper.find('[data-test="product-create-submit"]').trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledTimes(1) // single-flight, no automatic retry
    expect(routerReplace).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="product-create-error"]').text()).toContain(
      'Slug already in use.',
    )
    expect(
      (wrapper.find('[data-test="omnibox-input"]').element as HTMLInputElement).value,
    ).toBe('Aurora Desk Lamp $89')
  })

  it('a server 422 on the external link lands on the Link field', async () => {
    createMock.mockRejectedValueOnce(
      new ApiError(
        'Validation failed',
        422,
        { 'metadata.external_url': 'metadata.external_url must use the http or https scheme.' },
        {},
      ),
    )
    const wrapper = mountCreate()
    await flushPromises()

    await wrapper.find('[data-test="omnibox-input"]').setValue('Partner Desk')
    await wrapper.find('[data-test="type-card-external"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="external-url-input"]').setValue('https://sneaky.example/x')
    await wrapper.find('[data-test="product-create-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('must use the http or https scheme')
    expect(routerReplace).not.toHaveBeenCalled()
  })
})

// ── Products list page: row actions ────────────────────────────────────────────────────────────

describe('commerce products list page — row actions', () => {
  it('requires confirmation before deleting a product', async () => {
    productsPage.value = {
      products: [product({ uuid: 'p1', name: 'Widget' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
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
      products: [
        product({ uuid: 'p1', name: 'Widget' }),
        product({ uuid: 'p2', name: 'Gadget', slug: 'gadget' }),
      ],
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
    productsPage.value = {
      products: [product({ uuid: 'p1', name: 'Widget' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
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
    productsPage.value = {
      products: [product({ uuid: 'p1', name: 'Widget' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    tagsPage.value = {
      tags: [tag({ uuid: 'tag1', name: 'Tag 1' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
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
    productsPage.value = {
      products: [product({ uuid: 'p1', name: 'Widget' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributesPage.value = {
      attributes: [attribute({ uuid: 'attr1', name: 'Color' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
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

// ── Product detail page: single-page editor shell (Task C4) ────────────────────────────────
//
// The UTabs layout is gone: every section is a card (`EditorSectionCard`) rendered simultaneously
// on one scrollable page, so these tests assert card PRESENCE (`data-test="editor-section-*"`)
// rather than switching tabs first. No tab component's own internals changed — MediaPanel,
// VariantsPanel etc. are still exercised in their own describe blocks below; here we only check
// the shell wires them into the right card, in the right place, under the right condition.

const detailStubs = { ...pageStubs, MediaPickerModal: MediaPickerModalStub }

describe('commerce product detail page', () => {
  beforeEach(() => {
    routeState.params = { uuid: 'p1' }
    document.body.innerHTML = ''
  })

  it('renders every always-on section card, plus the section nav, for a physical/active product', async () => {
    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      type: 'physical',
      status: 'active',
      variants: [variant()],
    })
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('Widget')
    // Spec §5.4b: the identity bar replaced the draft banner; active products never show
    // the draft Activate action.
    expect(wrapper.find('[data-test="product-identity-bar"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="identity-activate"]').exists()).toBe(false)

    expect(wrapper.find('[data-test="editor-section-details"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-test="editor-section-details"] [data-test="product-form-save"]').exists(),
    ).toBe(true)
    expect(wrapper.find('[data-test="editor-section-media"]').exists()).toBe(true)
    // Task C7: a single-variant, no-option-axes product is the SIMPLE disclosure branch — the
    // Pricing & stock card renders the compact card, not the full variants table.
    expect(
      wrapper.find('[data-test="editor-section-pricing"] [data-test="pricing-compact"]').exists(),
    ).toBe(true)
    expect(wrapper.find('[data-test="editor-section-organization"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="editor-section-addons"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="editor-section-content"]').exists()).toBe(true)

    // Type-conditional cards: neither applies to a physical product.
    expect(wrapper.find('[data-test="editor-section-downloads"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="editor-section-children"]').exists()).toBe(false)

    expect(wrapper.find('[data-test="section-nav"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('stacks Categories, Tags and Attributes inside ONE Organization card', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    categoriesData.value = [category({ uuid: 'cat1', name: 'Cat 1' })]
    tagsPage.value = {
      tags: [tag({ uuid: 'tag1', name: 'Tag 1' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributesPage.value = {
      attributes: [attribute({ uuid: 'attr1', name: 'Color' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="editor-section-organization"]')).toHaveLength(1)
    const organization = wrapper.find('[data-test="editor-section-organization"]')
    expect(organization.find('[data-test="category-assignment-section"]').exists()).toBe(true)
    expect(organization.find('[data-test="tag-assignment-section"]').exists()).toBe(true)
    expect(organization.find('[data-test="attribute-assignment-section"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('shows the Downloads card only for digital products, still collapsed by default internally', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', type: 'physical' })
    const physicalWrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()
    expect(physicalWrapper.find('[data-test="editor-section-downloads"]').exists()).toBe(false)
    physicalWrapper.unmount()

    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      type: 'digital',
      variants: [variant({ uuid: 'v1' })],
    })
    downloadsData.value = [download({ uuid: 'd1', name: 'Ebook (PDF)' })]
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const downloadsCard = wrapper.find('[data-test="editor-section-downloads"]')
    expect(downloadsCard.exists()).toBe(true)
    // Collapsed by default: the per-variant GET isn't fired until the section is expanded —
    // DownloadsPanel's own internal behavior, unchanged by the card wrapping.
    expect(downloadsCard.find('[data-test="download-variant-row"]').exists()).toBe(true)
    expect(downloadsCard.find('[data-test="download-row"]').exists()).toBe(false)

    await downloadsCard.find('[data-test="download-variant-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="download-row"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Ebook (PDF)')
    wrapper.unmount()
  })

  it('shows the real Grouped products (ChildrenCard) only for grouped products, and nowhere inside Pricing & stock (Task C8)', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', type: 'grouped' })
    childrenSectionData.value = { revision: 3, items: [childItem({ uuid: 'child1', name: 'Child One' })] }
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const childrenCard = wrapper.find('[data-test="editor-section-children"]')
    expect(childrenCard.exists()).toBe(true)
    expect(childrenCard.find('[data-test="children-row"]').exists()).toBe(true)
    expect(childrenCard.text()).toContain('Child One')
    // The composition editor lives ONLY in its own card now — never inside Pricing & stock.
    expect(
      wrapper.find('[data-test="editor-section-pricing"] [data-test="children-row"]').exists(),
    ).toBe(false)
    expect(
      wrapper.find('[data-test="editor-section-pricing"] [data-test="children-save"]').exists(),
    ).toBe(false)

    singleProduct.value = product({ uuid: 'p1', name: 'Widget', type: 'physical' })
    const physicalWrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()
    expect(physicalWrapper.find('[data-test="editor-section-children"]').exists()).toBe(false)
    physicalWrapper.unmount()

    wrapper.unmount()
  })

  it('identity bar: draft shows the Activate scroll shortcut (never a mutation); active shows the Health strip instead', async () => {
    const scrollSpy = vi
      .spyOn(HTMLElement.prototype, 'scrollIntoView')
      .mockImplementation(() => undefined)

    singleProduct.value = product({ uuid: 'p1', name: 'Widget', status: 'draft' })
    const draftWrapper = mount(ProductDetail, {
      global: { stubs: detailStubs },
      attachTo: document.body,
    })
    await flushPromises()

    // Spec §5.4b: the bar is the spine — name, slug · type meta line, status pill; the C4
    // draft banner is gone.
    const bar = draftWrapper.find('[data-test="product-identity-bar"]')
    expect(bar.exists()).toBe(true)
    expect(bar.find('[data-test="identity-name"]').text()).toBe('Widget')
    expect(bar.find('[data-test="identity-meta"]').text()).toContain('widget · physical')
    expect(bar.find('[data-test="identity-status"]').text()).toBe('draft')
    expect(draftWrapper.find('[data-test="draft-banner"]').exists()).toBe(false)
    // Drafts lead with the editor — no Health strip.
    expect(draftWrapper.find('[data-test="product-health-strip"]').exists()).toBe(false)

    const activateButton = bar.find('[data-test="identity-activate"]')
    expect(activateButton.exists()).toBe(true)

    // NOT a status mutation — only a scroll shortcut to the Details card.
    await activateButton.trigger('click')
    expect(updateMock).not.toHaveBeenCalled()
    expect(scrollSpy).toHaveBeenCalledTimes(1)
    draftWrapper.unmount()

    singleProduct.value = product({ uuid: 'p1', name: 'Widget', status: 'active' })
    const activeWrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()
    expect(activeWrapper.find('[data-test="identity-activate"]').exists()).toBe(false)
    expect(activeWrapper.find('[data-test="product-health-strip"]').exists()).toBe(true)

    activeWrapper.unmount()
    scrollSpy.mockRestore()
  })

  it('health strip: factual counts from the section reads, warning rows deep-link, stock honesty preserved', async () => {
    const scrollSpy = vi
      .spyOn(HTMLElement.prototype, 'scrollIntoView')
      .mockImplementation(() => undefined)

    // Active product; media present, NO categories, tracked stock at the low threshold.
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', status: 'active', variants: [variant()] })
    mediaSectionData.value = { revision: 1, items: [mediaItem({ uuid: 'm1' })] }
    categoriesSectionData.value = { revision: 1, items: [] }
    stockSectionData.value = {
      revision: 1,
      items: [{ variant_uuid: 'v1', tracked: true, quantity: 2 }],
    }
    const wrapper = mount(ProductDetail, {
      global: { stubs: detailStubs },
      attachTo: document.body,
    })
    await flushPromises()

    const strip = wrapper.find('[data-test="product-health-strip"]')
    expect(strip.find('[data-test="health-images"]').text()).toContain('1 image')
    expect(strip.find('[data-test="health-categories"]').text()).toContain('No categories')
    // low_stock_threshold is 3 in the meta mock; quantity 2 <= 3 → low.
    expect(strip.find('[data-test="health-stock"]').text()).toContain('Low stock — 2 left')

    // Warning rows deep-link (scroll) to their owning sections.
    await strip.find('[data-test="health-jump-organization"]').trigger('click')
    await strip.find('[data-test="health-jump-pricing"]').trigger('click')
    expect(scrollSpy).toHaveBeenCalledTimes(2)

    wrapper.unmount()
    scrollSpy.mockRestore()
  })

  it('health strip: a failed stock read renders "unavailable" — never fabricated zeros', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', status: 'active' })
    mediaSectionData.value = { revision: 1, items: [] }
    categoriesSectionData.value = { revision: 1, items: [] }
    stockSectionStatus.value = 'error'
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const stockRow = wrapper.find('[data-test="health-stock"]')
    expect(stockRow.text()).toContain('Stock data unavailable')
    expect(stockRow.text()).not.toContain('0')

    wrapper.unmount()
  })

  // ── Command Center phase 2: trade tile + recent orders (spec §5.4b) ────────────────────────

  it('renders the trade tile and recent orders when the activity read succeeds, rows linking to orders', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', status: 'active' })
    productOrderActivityStatus.value = 'success'
    productOrderActivityData.value = {
      window_days: 30,
      summary: { orders: 26, revenue_minor: 231400 },
      recent: [
        {
          uuid: 'ord-1',
          order_number: 'ORD-1042',
          status: 'paid',
          grand_total: 8900,
          currency: 'USD',
        },
      ],
    }
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const tile = wrapper.find('[data-test="product-trade-tile"]')
    expect(tile.find('[data-test="trade-revenue"]').text()).toContain('2,314')
    expect(tile.find('[data-test="trade-orders"]').text()).toContain('26 orders')

    const row = wrapper.find('[data-test="recent-order-row"]')
    expect(row.text()).toContain('ORD-1042')
    expect(row.attributes('href')).toBe('/commerce/orders/ord-1')

    wrapper.unmount()
  })

  it('activity panels are ABSENT (never an error banner) when the read fails — older commerce degrades gracefully', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', status: 'active' })
    productOrderActivityStatus.value = 'error'
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    // The Health card still renders; the phase-2 panels simply don't exist.
    expect(wrapper.find('[data-test="product-health-strip"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="product-trade-tile"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-recent-orders"]').exists()).toBe(false)

    wrapper.unmount()
  })

  // ── The Live Mirror (spec §5.4b phase 3) ────────────────────────────────────────────────────

  it('mirror toggle: trades the rail for the REAL storefront iframe on an active product, and back', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', status: 'active' })
    productLinkData.value = {
      product_uuid: 'p1',
      storefront_url: 'https://store.example.test/shop/products/widget',
      link: null,
    }
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="section-nav"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="live-mirror"]').exists()).toBe(false)

    await wrapper.find('[data-test="identity-mirror-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="section-nav"]').exists()).toBe(false)
    const frame = wrapper.find('[data-test="mirror-frame"]')
    expect(frame.exists()).toBe(true)
    // The server-built absolute URL, verbatim — the client never assembles shop URLs.
    expect(frame.attributes('src')).toBe('https://store.example.test/shop/products/widget')
    expect(wrapper.find('[data-test="mirror-mode"]').text()).toContain('as customers see it')

    await wrapper.find('[data-test="identity-mirror-toggle"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="section-nav"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="live-mirror"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('mirror on a draft: an honest placeholder, never a fake preview — and no View-in-store in the bar', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', status: 'draft' })
    productLinkData.value = {
      product_uuid: 'p1',
      storefront_url: 'https://store.example.test/shop/products/widget',
      link: null,
    }
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    // Drafts never link out — the storefront refuses them.
    expect(wrapper.find('[data-test="identity-view-in-store"]').exists()).toBe(false)

    await wrapper.find('[data-test="identity-mirror-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="mirror-frame"]').exists()).toBe(false)
    const placeholder = wrapper.find('[data-test="mirror-draft-placeholder"]')
    expect(placeholder.text()).toContain('can’t preview drafts yet')

    wrapper.unmount()
  })

  it('View in store appears for active products with the server-built URL', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', status: 'active' })
    productLinkData.value = {
      product_uuid: 'p1',
      storefront_url: 'https://store.example.test/shop/products/widget',
      link: null,
    }
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const viewInStore = wrapper.find('[data-test="identity-view-in-store"]')
    expect(viewInStore.exists()).toBe(true)
    expect(viewInStore.attributes('href')).toBe('https://store.example.test/shop/products/widget')

    wrapper.unmount()
  })

  it('hides the delete button and save control when can_manage is false', async () => {
    metaData.value = { ...metaData.value, can_manage: false }
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="product-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="product-form-save"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('deletes the product after confirmation and navigates back to the list', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    removeMock.mockResolvedValue(undefined)
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    await wrapper.find('[data-test="product-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="product-delete-confirm"]').exists()).toBe(true)

    await wrapper.find('[data-test="product-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(removeMock).toHaveBeenCalledWith('p1')
    expect(routerPush).toHaveBeenCalledWith('/commerce/products')
    wrapper.unmount()
  })

  it('renders the section nav with one hook per rendered card, in spec §5.1 order (grouped product: no Downloads)', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', type: 'grouped' })
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const items = wrapper.findAll('[data-test^="section-nav-"]')
    // Condensed-cards pass: Grouped products (a stateful card) sits ahead of the quiet tail
    // (Add-ons / Linked content), matching the card order on the page.
    expect(items.map((item) => item.attributes('data-test'))).toEqual([
      'section-nav-details',
      'section-nav-media',
      'section-nav-pricing',
      'section-nav-organization',
      'section-nav-children',
      'section-nav-addons',
      'section-nav-content',
    ])
    wrapper.unmount()
  })

  it('places Downloads between Add-ons and Linked content for a digital product (no Children item)', async () => {
    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      type: 'digital',
      variants: [variant()],
    })
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const items = wrapper.findAll('[data-test^="section-nav-"]')
    expect(items.map((item) => item.attributes('data-test'))).toEqual([
      'section-nav-details',
      'section-nav-media',
      'section-nav-pricing',
      'section-nav-organization',
      'section-nav-addons',
      'section-nav-downloads',
      'section-nav-content',
    ])
    wrapper.unmount()
  })

  // ── Condensed cards: the composed mock's resting state ──────────────────────────────────────

  it('rests every stateful card collapsed with a digest, and condenses the tail into one quiet row', async () => {
    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      status: 'active',
      variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 1999, compare_at_price: 2999 })],
    })
    stockSectionData.value = {
      revision: 1,
      items: [{ variant_uuid: 'v1', tracked: true, quantity: 24 }],
    }
    // attachTo: isVisible() below relies on jsdom's checkVisibility(), which reports false for
    // any node not connected to the document.
    const wrapper = mount(ProductDetail, {
      global: { stubs: detailStubs },
      attachTo: document.body,
    })
    await flushPromises()

    for (const id of ['details', 'media', 'pricing', 'organization']) {
      expect(wrapper.find(`[data-test="editor-section-${id}"]`).attributes('data-collapsed')).toBe(
        'true',
      )
    }
    // The Pricing digest leads with the variant's real numbers — formatted MAJOR units (never raw
    // minor units) plus the tracked stock quantity from the stock read.
    const pricingSummary = wrapper.find('[data-test="editor-section-pricing-summary"]')
    expect(pricingSummary.text()).toContain('SKU SKU-1')
    expect(pricingSummary.text()).toContain('$19.99')
    expect(pricingSummary.text()).toContain('compare-at $29.99')
    expect(pricingSummary.text()).toContain('24 in stock')

    // The tail (Add-ons / Linked content) rests as ONE quiet row; its cards stay MOUNTED but
    // hidden (v-show) so panel state survives the toggle.
    expect(wrapper.find('[data-test="editor-tail-row"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="editor-section-addons"]').isVisible()).toBe(false)
    await wrapper.find('[data-test="editor-tail-row"]').trigger('click')
    expect(wrapper.find('[data-test="editor-tail-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="editor-section-addons"]').isVisible()).toBe(true)
    expect(wrapper.find('[data-test="editor-section-content"]').isVisible()).toBe(true)
    wrapper.unmount()
  })

  it('expands a card from its header toggle and from the nav, and collapses back when clean', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', variants: [variant()] })
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const details = () => wrapper.find('[data-test="editor-section-details"]')
    expect(details().attributes('data-collapsed')).toBe('true')

    await wrapper.find('[data-test="editor-section-details-toggle"]').trigger('click')
    expect(details().attributes('data-collapsed')).toBe('false')
    await wrapper.find('[data-test="editor-section-details-toggle"]').trigger('click')
    expect(details().attributes('data-collapsed')).toBe('true')

    // A nav click expands the target card — never merely scrolls to a summary row.
    await wrapper.find('[data-test="section-nav-media"]').trigger('click')
    expect(wrapper.find('[data-test="editor-section-media"]').attributes('data-collapsed')).toBe(
      'false',
    )
    wrapper.unmount()
  })

  it('a card holding unsaved edits refuses to collapse (attention beats collapse)', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', variants: [variant()] })
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    await wrapper.find('[data-test="editor-section-details-toggle"]').trigger('click')
    await wrapper
      .find('[data-test="editor-section-details"] [data-test="product-name-input"]')
      .setValue('Renamed')
    await wrapper.find('[data-test="editor-section-details-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="editor-section-details"]').attributes('data-collapsed')).toBe(
      'false',
    )
    wrapper.unmount()
  })

  it('collapsed Images digest shows real thumbnails once the media read resolves', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget', variants: [variant()] })
    mediaSectionData.value = {
      revision: 1,
      items: [mediaItem({ uuid: 'm1', blob_uuid: 'blob-1' })],
    }
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const summary = wrapper.find('[data-test="editor-section-media-summary"]')
    expect(summary.exists()).toBe(true)
    expect(summary.find('img').exists()).toBe(true)
    wrapper.unmount()
  })

  it('shows draft-only "Variants · n" / "Images · n" hints computed from already-loaded data; every other item stays indicator-free', async () => {
    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      status: 'draft',
      variants: [variant({ uuid: 'v1' }), variant({ uuid: 'v2', sku: 'SKU-2' })],
    })
    mediaSectionData.value = { revision: 0, items: [mediaItem({ uuid: 'm1' })] }
    const draftWrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    const pricingItem = draftWrapper.find('[data-test="section-nav-pricing"]')
    expect(pricingItem.attributes('data-indicator')).toBe('hint')
    expect(pricingItem.text()).toContain('· 2')
    const mediaItemNav = draftWrapper.find('[data-test="section-nav-media"]')
    expect(mediaItemNav.attributes('data-indicator')).toBe('hint')
    expect(mediaItemNav.text()).toContain('· 1')
    // Task C6: Organization aggregates the three subsection reads into one draft-only hint too.
    const organizationItemNav = draftWrapper.find('[data-test="section-nav-organization"]')
    expect(organizationItemNav.attributes('data-indicator')).toBe('hint')
    expect(organizationItemNav.text()).toContain('· 0')
    // HONESTY over completeness (Task C4 brief): no fabricated counts for sections whose real
    // data isn't loaded by this shell — the remaining C1 reads are wired card-by-card in C7-C8.
    for (const id of ['details', 'addons', 'content']) {
      expect(
        draftWrapper.find(`[data-test="section-nav-${id}"]`).attributes('data-indicator'),
      ).toBeUndefined()
    }
    draftWrapper.unmount()

    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      status: 'active',
      variants: [variant()],
    })
    const activeWrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()
    // Empty-hints are draft-only (spec §5.1) — an active product gets no Pricing/Images/
    // Organization hint at all.
    expect(
      activeWrapper.find('[data-test="section-nav-pricing"]').attributes('data-indicator'),
    ).toBeUndefined()
    expect(
      activeWrapper.find('[data-test="section-nav-media"]').attributes('data-indicator'),
    ).toBeUndefined()
    expect(
      activeWrapper.find('[data-test="section-nav-organization"]').attributes('data-indicator'),
    ).toBeUndefined()
    activeWrapper.unmount()
  })

  it('shows no Images nav hint while the section read is still loading, even for a draft', async () => {
    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      status: 'draft',
      variants: [variant()],
    })
    mediaSectionStatus.value = 'pending'
    mediaSectionData.value = undefined
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    expect(
      wrapper.find('[data-test="section-nav-media"]').attributes('data-indicator'),
    ).toBeUndefined()
    wrapper.unmount()
  })

  it('shows no Organization nav hint while any of its three section reads is still loading, even for a draft', async () => {
    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      status: 'draft',
      variants: [variant()],
    })
    attributesSectionStatus.value = 'pending'
    attributesSectionData.value = undefined
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    expect(
      wrapper.find('[data-test="section-nav-organization"]').attributes('data-indicator'),
    ).toBeUndefined()
    wrapper.unmount()
  })

  it('aggregates Organization’s nav indicator across its three subsections, worst state wins (error > unsaved > hint)', async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    categoriesData.value = [category({ uuid: 'cat1', name: 'Cat 1' })]
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    // Idle+clean across all three subsections: no indicator (active product, so no hint either).
    expect(
      wrapper.find('[data-test="section-nav-organization"]').attributes('data-indicator'),
    ).toBeUndefined()

    // Dirty a single subsection (Categories) — worst-of-three becomes 'unsaved'.
    const categoryCheckbox = wrapper
      .findAllComponents({ name: 'CheckboxRoot' })
      .find((c) =>
        wrapper.find('[data-test="category-assignment-section"]').element.contains(c.element),
      )
    await categoryCheckbox!.vm.$emit('update:modelValue', true)

    expect(
      wrapper.find('[data-test="section-nav-organization"]').attributes('data-indicator'),
    ).toBe('unsaved')

    // Now fail that subsection's save — worst-of-three becomes 'error'.
    setCategoriesMock.mockRejectedValueOnce(new ApiError('Validation failed', 422, {}, {}))
    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.find('[data-test="section-nav-organization"]').attributes('data-indicator'),
    ).toBe('error')
    wrapper.unmount()
  })

  it("wires each card's emitted SectionState into its EditorSectionCard chip and the nav indicator", async () => {
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    // Idle+clean: no chip, no nav indicator.
    expect(wrapper.find('[data-test="editor-section-details-chip"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-test="section-nav-details"]').attributes('data-indicator'),
    ).toBeUndefined()

    await wrapper.find('[data-test="product-name-input"]').setValue('Widget 2')

    expect(wrapper.find('[data-test="editor-section-details-chip"]').text()).toBe('Unsaved changes')
    expect(wrapper.find('[data-test="section-nav-details"]').attributes('data-indicator')).toBe(
      'unsaved',
    )
    wrapper.unmount()
  })

  it("wires the pricing card's emitted SectionState into its EditorSectionCard chip and the nav indicator (Task C7)", async () => {
    singleProduct.value = product({
      uuid: 'p1',
      name: 'Widget',
      status: 'active',
      variants: [variant({ uuid: 'v1' })],
    })
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    // Idle+clean, active product: no chip, no nav indicator (no draft hint either).
    expect(wrapper.find('[data-test="editor-section-pricing-chip"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-test="section-nav-pricing"]').attributes('data-indicator'),
    ).toBeUndefined()

    await wrapper.find('[data-test="pricing-sku-input"]').setValue('SKU-1B')

    expect(wrapper.find('[data-test="editor-section-pricing-chip"]').text()).toBe('Unsaved changes')
    expect(wrapper.find('[data-test="section-nav-pricing"]').attributes('data-indicator')).toBe(
      'unsaved',
    )
    wrapper.unmount()
  })

  it('wires the page-level unsaved-changes guard (beforeunload listener) exactly once per mount/unmount', async () => {
    const addSpy = vi.spyOn(window, 'addEventListener')
    const removeSpy = vi.spyOn(window, 'removeEventListener')
    singleProduct.value = product({ uuid: 'p1', name: 'Widget' })
    const wrapper = mount(ProductDetail, { global: { stubs: detailStubs } })
    await flushPromises()

    expect(addSpy.mock.calls.filter(([type]) => type === 'beforeunload')).toHaveLength(1)

    wrapper.unmount()
    expect(removeSpy.mock.calls.filter(([type]) => type === 'beforeunload')).toHaveLength(1)
  })
})

// ── EditorSectionCard: card chrome — anchor id, header chip from phase×dirty, slot (Task C4) ──

function fakeSectionState(phase: SectionPhase, dirty: boolean): SectionState {
  return {
    phase: ref(phase),
    dirty: ref(dirty),
    markDirty: vi.fn(),
    beginSave: vi.fn(),
    saveSucceeded: vi.fn(),
    saveFailed: vi.fn(),
    markClean: vi.fn(),
  }
}

describe('EditorSectionCard', () => {
  it('renders the anchor id, data-test hook, title and slot content, with no chip when state is omitted', () => {
    const wrapper = mount(EditorSectionCard, {
      props: { sectionId: 'details', title: 'Details' },
      slots: { default: '<p data-test="inner">Inner content</p>' },
    })

    expect(wrapper.find('#section-details').exists()).toBe(true)
    expect(wrapper.find('[data-test="editor-section-details"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Details')
    expect(wrapper.find('[data-test="inner"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="editor-section-details-chip"]').exists()).toBe(false)
  })

  it.each<[SectionPhase, boolean, string]>([
    ['saving', false, 'Saving…'],
    ['error', true, 'Save failed — unsaved changes'],
    ['saved', false, 'Saved'],
    ['idle', true, 'Unsaved changes'],
  ])('shows the right chip for phase=%s dirty=%s', (phase, dirty, label) => {
    const wrapper = mount(EditorSectionCard, {
      props: { sectionId: 'x', title: 'X', state: fakeSectionState(phase, dirty) },
    })
    expect(wrapper.find('[data-test="editor-section-x-chip"]').text()).toBe(label)
  })

  it('shows no chip when idle and clean', () => {
    const wrapper = mount(EditorSectionCard, {
      props: { sectionId: 'x', title: 'X', state: fakeSectionState('idle', false) },
    })
    expect(wrapper.find('[data-test="editor-section-x-chip"]').exists()).toBe(false)
  })
})

// ── SectionNav: sticky/anchor nav, scroll-spy (jsdom-safe), indicator precedence (Task C4) ────

describe('SectionNav', () => {
  const baseSections: SectionNavItem[] = [
    { id: 'details', label: 'Details', indicator: null },
    { id: 'media', label: 'Images', indicator: 'hint', hint: 'Images · 0' },
    { id: 'pricing', label: 'Pricing & stock', indicator: 'unsaved' },
    { id: 'organization', label: 'Organization', indicator: 'error' },
  ]

  it('renders the nav container and one hook per section, each anchored to its card', () => {
    const wrapper = mount(SectionNav, { props: { sections: baseSections } })

    expect(wrapper.find('[data-test="section-nav"]').exists()).toBe(true)
    for (const section of baseSections) {
      const link = wrapper.find(`[data-test="section-nav-${section.id}"]`)
      expect(link.exists()).toBe(true)
      expect(link.attributes('href')).toBe(`#section-${section.id}`)
      expect(link.text()).toContain(section.label)
    }
  })

  it('renders the resolved indicator per section (dot + optional hint text), leaving a null indicator bare', () => {
    const wrapper = mount(SectionNav, { props: { sections: baseSections } })

    expect(
      wrapper.find('[data-test="section-nav-details"]').attributes('data-indicator'),
    ).toBeUndefined()
    expect(wrapper.find('[data-test="section-nav-media"]').attributes('data-indicator')).toBe(
      'hint',
    )
    expect(wrapper.find('[data-test="section-nav-media"]').text()).toContain('Images · 0')
    expect(wrapper.find('[data-test="section-nav-pricing"]').attributes('data-indicator')).toBe(
      'unsaved',
    )
    expect(
      wrapper.find('[data-test="section-nav-organization"]').attributes('data-indicator'),
    ).toBe('error')
  })

  it('mounts without throwing when IntersectionObserver is unavailable (jsdom-safe no-op fallback)', () => {
    expect(typeof IntersectionObserver).toBe('undefined')
    expect(() => mount(SectionNav, { props: { sections: baseSections } })).not.toThrow()
  })

  it('observes every rendered section element once IntersectionObserver is available', async () => {
    class FakeIntersectionObserver {
      static instances: FakeIntersectionObserver[] = []
      observed: Element[] = []
      callback: IntersectionObserverCallback
      constructor(callback: IntersectionObserverCallback) {
        this.callback = callback
        FakeIntersectionObserver.instances.push(this)
      }
      observe(el: Element) {
        this.observed.push(el)
      }
      unobserve() {
        /* not exercised */
      }
      disconnect() {
        /* not exercised */
      }
      takeRecords(): IntersectionObserverEntry[] {
        return []
      }
    }
    vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver)

    // A dedicated, self-owned container rather than `document.body` directly: this test's fake
    // section elements are plain (non-Vue) DOM nodes, and touching `document.body.innerHTML`
    // directly around an `attachTo` mount can tear a Vue-managed node out from under Vue without
    // going through its own unmount path, corrupting later tests in this file. Scoping every DOM
    // mutation to a container we create and remove ourselves avoids that entirely.
    const container = document.createElement('div')
    container.innerHTML = '<div id="section-details"></div><div id="section-media"></div>'
    document.body.appendChild(container)

    const wrapper = mount(SectionNav, {
      props: {
        sections: [
          { id: 'details', label: 'Details', indicator: null },
          { id: 'media', label: 'Images', indicator: null },
        ],
      },
      attachTo: container,
    })
    await flushPromises()

    expect(FakeIntersectionObserver.instances).toHaveLength(1)
    expect(FakeIntersectionObserver.instances[0]!.observed.map((el) => el.id)).toEqual([
      'section-details',
      'section-media',
    ])

    wrapper.unmount()
    container.remove()
  })
})

describe('resolveSectionIndicator', () => {
  it('picks error over unsaved and hint', () => {
    expect(resolveSectionIndicator(['hint', 'unsaved', 'error'])).toBe('error')
  })

  it('picks unsaved over hint when no error is present', () => {
    expect(resolveSectionIndicator(['hint', 'unsaved'])).toBe('unsaved')
  })

  it('picks hint when only a hint is present', () => {
    expect(resolveSectionIndicator([null, 'hint'])).toBe('hint')
  })

  it('returns null when every input is null (or the list is empty)', () => {
    expect(resolveSectionIndicator([null, null])).toBeNull()
    expect(resolveSectionIndicator([])).toBeNull()
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
    await wrapper.find('[data-test="variant-price-input"]').setValue('25')
    await wrapper.find('[data-test="variant-currency-input"]').setValue('USD')

    await wrapper.find('#variant-add-form').trigger('submit')
    await flushPromises()

    expect(createVariantMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      input: { sku: 'SKU-2', price: 2500, currency: 'USD', status: 'active' },
    })
  })

  // ── compare_at_price: closing the gap (Task C7) ─────────────────────────────────────────────

  it('includes compare_at_price in the create-variant payload when set, omitted when left blank', async () => {
    createVariantMock.mockResolvedValue(variant({ uuid: 'v2', sku: 'SKU-2' }))
    const p = product({ uuid: 'p1', variants: [] })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-add"]').trigger('click')
    await wrapper.find('[data-test="variant-sku-input"]').setValue('SKU-2')
    await wrapper.find('[data-test="variant-price-input"]').setValue('25')
    await wrapper.find('[data-test="variant-currency-input"]').setValue('USD')
    await wrapper.find('[data-test="variant-compare-at-input"]').setValue('30')
    await wrapper.find('#variant-add-form').trigger('submit')
    await flushPromises()

    expect(createVariantMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      input: {
        sku: 'SKU-2',
        price: 2500,
        currency: 'USD',
        status: 'active',
        compare_at_price: 3000,
      },
    })
  })

  it('rejects a non-numeric compare-at price on create without calling the mutation', async () => {
    const p = product({ uuid: 'p1', variants: [] })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-add"]').trigger('click')
    await wrapper.find('[data-test="variant-sku-input"]').setValue('SKU-2')
    await wrapper.find('[data-test="variant-price-input"]').setValue('25')
    await wrapper.find('[data-test="variant-currency-input"]').setValue('USD')
    await wrapper.find('[data-test="variant-compare-at-input"]').setValue('not-a-number')
    await wrapper.find('#variant-add-form').trigger('submit')
    await flushPromises()

    expect(createVariantMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="variant-form-error"]').text()).toContain('Compare-at price')
  })

  it('surfaces the "cannot add variant to type" 422 message instead of vanishing it', async () => {
    createVariantMock.mockRejectedValue(
      new ApiError(
        'Validation failed',
        422,
        { product_uuid: "Cannot add variants to a 'grouped' product." },
        {},
      ),
    )
    const p = product({ uuid: 'p1', type: 'grouped', variants: [] })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-add"]').trigger('click')
    await wrapper.find('[data-test="variant-sku-input"]').setValue('SKU-2')
    await wrapper.find('[data-test="variant-price-input"]').setValue('25')
    await wrapper.find('[data-test="variant-currency-input"]').setValue('USD')
    await wrapper.find('#variant-add-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="variant-form-error"]').text()).toContain(
      "Cannot add variants to a 'grouped' product.",
    )
  })

  it('updates a variant via the inline edit form', async () => {
    updateVariantMock.mockResolvedValue(variant({ uuid: 'v1', price: 3000 }))
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 1999 })],
    })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-edit"]').trigger('click')
    await wrapper.find('[data-test="variant-edit-price-input"]').setValue('30')
    await wrapper.find(`#variant-edit-form-v1`).trigger('submit')
    await flushPromises()

    expect(updateVariantMock).toHaveBeenCalledWith({
      uuid: 'v1',
      productUuid: 'p1',
      input: { sku: 'SKU-1', price: 3000, status: 'active', compare_at_price: null },
    })
  })

  it('includes compare_at_price in the update-variant payload when set, prefilled from the current value', async () => {
    updateVariantMock.mockResolvedValue(variant({ uuid: 'v1' }))
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 1999, compare_at_price: 2500 })],
    })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-edit"]').trigger('click')
    // Prefilled from the variant's own current compare_at_price, matching sku/price's own convention.
    expect(
      (wrapper.find('[data-test="variant-edit-compare-at-input"]').element as HTMLInputElement)
        .value,
    ).toBe('25.00')

    await wrapper.find('[data-test="variant-edit-compare-at-input"]').setValue('45')
    await wrapper.find('#variant-edit-form-v1').trigger('submit')
    await flushPromises()

    expect(updateVariantMock).toHaveBeenCalledWith({
      uuid: 'v1',
      productUuid: 'p1',
      input: { sku: 'SKU-1', price: 1999, status: 'active', compare_at_price: 4500 },
    })
  })

  it('CLEARS an existing compare-at price on variant edit by blanking the field (explicit null)', async () => {
    // C7 review Critical regression pin — mirror of the compact-card clear spec.
    updateVariantMock.mockResolvedValue(variant({ uuid: 'v1' }))
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 1999, compare_at_price: 2500 })],
    })
    const wrapper = mountPanel(p)

    await wrapper.find('[data-test="variant-edit"]').trigger('click')
    await wrapper.find('[data-test="variant-edit-compare-at-input"]').setValue('')
    await wrapper.find('#variant-edit-form-v1').trigger('submit')
    await flushPromises()

    expect(updateVariantMock).toHaveBeenCalledWith({
      uuid: 'v1',
      productUuid: 'p1',
      input: { sku: 'SKU-1', price: 1999, status: 'active', compare_at_price: null },
    })
  })

  it('surfaces a duplicate-SKU 422 message on variant update', async () => {
    updateVariantMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { sku: 'SKU already in use.' }, {}),
    )
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

    await wrapper.find('[data-test="bulk-price-input"]').setValue('50')
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
  })

  // ── Stock: presentational props only (Task C7 — VariantsPanel holds no query of its own) ────

  it('renders per-variant stock quantity from the stock-items prop: tracked shows the quantity, untracked shows —', () => {
    const p = product({
      variants: [variant({ uuid: 'v1' }), variant({ uuid: 'v2', sku: 'SKU-2' })],
    })
    const wrapper = mount(VariantsPanel, {
      props: {
        product: p,
        canManage: true,
        stockItems: [
          { variant_uuid: 'v1', tracked: true, quantity: 42 },
          { variant_uuid: 'v2', tracked: false, quantity: 0 },
        ],
      },
    })
    const rows = wrapper.findAll('[data-test="variant-row"]')
    expect(rows[0]!.find('[data-test="variant-stock-quantity"]').text()).toContain('42')
    expect(rows[1]!.find('[data-test="variant-stock-quantity"]').text()).toContain('—')
  })

  it('shows — (never a fabricated quantity) for a variant absent from the stock-items prop', () => {
    const p = product({ variants: [variant({ uuid: 'v1' })] })
    const wrapper = mount(VariantsPanel, { props: { product: p, canManage: true } })
    expect(wrapper.find('[data-test="variant-stock-quantity"]').text()).toContain('—')
  })

  it('shows the honest stock-unavailable alert and never fabricates a quantity when the stock read errors', () => {
    const p = product({ variants: [variant({ uuid: 'v1' })] })
    const wrapper = mount(VariantsPanel, {
      props: {
        product: p,
        canManage: true,
        stockItems: [{ variant_uuid: 'v1', tracked: true, quantity: 99 }],
        stockUnavailable: true,
      },
    })
    expect(wrapper.find('[data-test="stock-unavailable"]').text()).toContain(
      'Stock data is unavailable for this product',
    )
    expect(wrapper.find('[data-test="variant-stock-quantity"]').text()).toContain('—')
  })

  // ── Coordinator: every successful variant/stock mutation awaits afterMutation() exactly once ─

  it('awaits afterMutation() exactly once after a successful variant create, never on failure', async () => {
    createVariantMock.mockResolvedValueOnce(variant({ uuid: 'v2', sku: 'SKU-2' }))
    const p = product({ uuid: 'p1', variants: [] })
    const { wrapper, getCoordinator } = mountWithEditorContext(VariantsPanel, {
      product: p,
      canManage: true,
    })
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="variant-add"]').trigger('click')
    await wrapper.find('[data-test="variant-sku-input"]').setValue('SKU-2')
    await wrapper.find('[data-test="variant-price-input"]').setValue('25')
    await wrapper.find('[data-test="variant-currency-input"]').setValue('USD')
    await wrapper.find('#variant-add-form').trigger('submit')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)

    createVariantMock.mockRejectedValueOnce(new ApiError('Validation failed', 422, {}, {}))
    await wrapper.find('[data-test="variant-add"]').trigger('click')
    await wrapper.find('[data-test="variant-sku-input"]').setValue('SKU-3')
    await wrapper.find('[data-test="variant-price-input"]').setValue('25')
    await wrapper.find('[data-test="variant-currency-input"]').setValue('USD')
    await wrapper.find('#variant-add-form').trigger('submit')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('awaits afterMutation() exactly once after a successful variant update', async () => {
    updateVariantMock.mockResolvedValue(variant({ uuid: 'v1' }))
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper, getCoordinator } = mountWithEditorContext(VariantsPanel, {
      product: p,
      canManage: true,
    })
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="variant-edit"]').trigger('click')
    await wrapper.find('#variant-edit-form-v1').trigger('submit')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('awaits afterMutation() exactly once after a successful bulk price update', async () => {
    bulkPriceMock.mockResolvedValue({ applied: ['v1'], failed: [] })
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper, getCoordinator } = mountWithEditorContext(VariantsPanel, {
      product: p,
      canManage: true,
    })
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    const checkbox = wrapper.findComponent({ name: 'CheckboxRoot' })
    await checkbox.vm.$emit('update:modelValue', true)
    await flushPromises()
    await wrapper.find('[data-test="bulk-price-input"]').setValue('50')
    await wrapper.find('[data-test="bulk-price-apply"]').trigger('click')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('awaits afterMutation() exactly once after a successful stock adjust, never on failure', async () => {
    stockAdjustMock.mockResolvedValueOnce({ variant_uuid: 'v1', quantity: 12 })
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper, getCoordinator } = mountWithEditorContext(VariantsPanel, {
      product: p,
      canManage: true,
    })
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="stock-adjust"]').trigger('click')
    await wrapper.find('[data-test="stock-adjust-delta"]').setValue('-3')
    await wrapper.find('[data-test="stock-adjust-apply"]').trigger('click')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)

    stockAdjustMock.mockRejectedValueOnce(new ApiError('Validation failed', 422, {}, {}))
    await wrapper.find('[data-test="stock-adjust"]').trigger('click')
    await wrapper.find('[data-test="stock-adjust-delta"]').setValue('-3')
    await wrapper.find('[data-test="stock-adjust-apply"]').trigger('click')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
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

    expect(wrapper.find('[data-test="stock-adjust-error"]').text()).toContain(
      'Stock cannot go below zero.',
    )
  })

  // The grouped-product "Child products" composition editor that used to live here (a plain
  // comma-separated-uuid textarea) has MOVED to its own `ChildrenCard.vue` (Task C8) — see that
  // component's own describe block below for the real hydration/conflict/wipe-parallel coverage.
})

// ── PricingStockCard: progressive disclosure, compare-at, real stock (Task C7) ─────────────────

describe('PricingStockCard', () => {
  function mountCard(p: CommerceProduct, canManage = true) {
    return mountWithEditorContext(PricingStockCard, { product: p, canManage })
  }

  // ── Disclosure branches (spec §5.3) ─────────────────────────────────────────────────────────

  it('renders the compact card for exactly one variant with no option axes', async () => {
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper } = mountCard(p)
    await flushPromises()

    expect(wrapper.find('[data-test="pricing-compact"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="variant-row"]').exists()).toBe(false)
  })

  it('renders the full table for two variants', async () => {
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1' }), variant({ uuid: 'v2', sku: 'SKU-2' })],
    })
    const { wrapper } = mountCard(p)
    await flushPromises()

    expect(wrapper.find('[data-test="pricing-compact"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="variant-row"]')).toHaveLength(2)
  })

  it('renders the full table for a single variant whose product already defines option axes', async () => {
    const p = product({
      uuid: 'p1',
      options: { Size: ['S', 'M'] },
      variants: [variant({ uuid: 'v1' })],
    })
    const { wrapper } = mountCard(p)
    await flushPromises()

    expect(wrapper.find('[data-test="pricing-compact"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="variant-row"]').exists()).toBe(true)
  })

  it('renders the full table for a 0-variant grouped product (children live in their own card, not here — Task C8)', async () => {
    const p = product({ uuid: 'p1', type: 'grouped', variants: [] })
    const { wrapper } = mountCard(p)
    await flushPromises()

    expect(wrapper.find('[data-test="pricing-compact"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="variants-empty"]').exists()).toBe(true)
  })

  // ── "Add more variants": UI-only expansion, spec-pinned no-mutation ─────────────────────────

  it('expands to the full variants table without firing any mutation, and can collapse back', async () => {
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper } = mountCard(p)
    await flushPromises()

    await wrapper.find('[data-test="pricing-add-more-variants"]').trigger('click')

    expect(wrapper.find('[data-test="pricing-compact"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="variant-row"]')).toHaveLength(1)
    expect(createVariantMock).not.toHaveBeenCalled()
    expect(updateVariantMock).not.toHaveBeenCalled()
    expect(stockAdjustMock).not.toHaveBeenCalled()

    expect(wrapper.find('[data-test="pricing-collapse"]').exists()).toBe(true)
    await wrapper.find('[data-test="pricing-collapse"]').trigger('click')
    expect(wrapper.find('[data-test="pricing-compact"]').exists()).toBe(true)
  })

  it('does not offer a collapse control once a second variant actually exists', async () => {
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1' }), variant({ uuid: 'v2', sku: 'SKU-2' })],
    })
    const { wrapper } = mountCard(p)
    await flushPromises()
    expect(wrapper.find('[data-test="pricing-collapse"]').exists()).toBe(false)
  })

  // ── Compact-card inline save (SKU/price/compare-at via the existing updateVariant mutation) ──

  it('saves the compact card inline via updateVariant, with compare_at_price included only when set, awaiting afterMutation() once', async () => {
    updateVariantMock.mockResolvedValue(variant({ uuid: 'v1' }))
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 1999, compare_at_price: null })],
    })
    const { wrapper, getCoordinator } = mountCard(p)
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="pricing-sku-input"]').setValue('SKU-1B')
    await wrapper.find('[data-test="pricing-price-input"]').setValue('25')
    await wrapper.find('[data-test="pricing-compare-at-input"]').setValue('35')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateVariantMock).toHaveBeenCalledWith({
      uuid: 'v1',
      productUuid: 'p1',
      input: { sku: 'SKU-1B', price: 2500, compare_at_price: 3500 },
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('sends an explicit compare_at_price null from the compact save when blank (updates always carry the key)', async () => {
    updateVariantMock.mockResolvedValue(variant({ uuid: 'v1' }))
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 1999, compare_at_price: null })],
    })
    const { wrapper } = mountCard(p)
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateVariantMock).toHaveBeenCalledWith({
      uuid: 'v1',
      productUuid: 'p1',
      input: { sku: 'SKU-1', price: 1999, compare_at_price: null },
    })
  })

  it('CLEARS an existing compare-at price by blanking the field (explicit null, not an omitted key)', async () => {
    // C7 review Critical regression pin: set a sale price, later blank it to end the sale —
    // an omitted key would leave the old value silently untouched behind a "saved" toast.
    updateVariantMock.mockResolvedValue(variant({ uuid: 'v1' }))
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 1999, compare_at_price: 2500 })],
    })
    const { wrapper } = mountCard(p)
    await flushPromises()

    expect(
      (wrapper.find('[data-test="pricing-compare-at-input"]').element as HTMLInputElement).value,
    ).toBe('25.00')

    await wrapper.find('[data-test="pricing-compare-at-input"]').setValue('')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateVariantMock).toHaveBeenCalledWith({
      uuid: 'v1',
      productUuid: 'p1',
      input: { sku: 'SKU-1', price: 1999, compare_at_price: null },
    })
  })

  it('shows a formatted money preview of the price via formatMoney (BigInt discipline)', async () => {
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1', sku: 'SKU-1', price: 2500 })],
    })
    const { wrapper } = mountCard(p)
    await flushPromises()
    expect(wrapper.find('[data-test="pricing-price-preview"]').text()).toBe('$25.00')
  })

  it('emits its own "pricing" SectionState — editing the compact fields marks it dirty', async () => {
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper, getState } = mountCard(p)
    await flushPromises()

    expect(getState().dirty.value).toBe(false)
    await wrapper.find('[data-test="pricing-sku-input"]').setValue('NEW-SKU')
    expect(getState().dirty.value).toBe(true)
  })

  // ── Stock: real read (Task C1), registered with the coordinator under 'stock' ───────────────

  it('shows the tracked quantity and an inline adjust control, awaiting afterMutation() once, refreshing the displayed quantity', async () => {
    stockAdjustMock.mockResolvedValue({ variant_uuid: 'v1', quantity: 7 })
    stockSectionData.value = {
      revision: 0,
      items: [{ variant_uuid: 'v1', tracked: true, quantity: 10 }],
    }
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper, getCoordinator } = mountCard(p)
    await flushPromises()

    expect(wrapper.find('[data-test="pricing-quantity"]').text()).toContain('10')
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    // The mocked stock-section refetch (driven by afterMutation()) reflects the post-adjust state.
    stockSectionData.value = {
      revision: 1,
      items: [{ variant_uuid: 'v1', tracked: true, quantity: 7 }],
    }
    await wrapper.find('[data-test="pricing-adjust-toggle"]').trigger('click')
    await wrapper.find('[data-test="pricing-adjust-delta"]').setValue('-3')
    await wrapper.find('[data-test="pricing-adjust-apply"]').trigger('click')
    await flushPromises()

    expect(stockAdjustMock).toHaveBeenCalledWith({
      variantUuid: 'v1',
      productUuid: 'p1',
      input: { delta: -3, reason: 'adjustment' },
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="pricing-quantity"]').text()).toContain('7')
  })

  it('hides the quantity and adjust control entirely for an untracked single variant', async () => {
    stockSectionData.value = {
      revision: 0,
      items: [{ variant_uuid: 'v1', tracked: false, quantity: 0 }],
    }
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper } = mountCard(p)
    await flushPromises()

    expect(wrapper.find('[data-test="pricing-quantity"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="pricing-adjust-toggle"]').exists()).toBe(false)
  })

  it('shows the honest stock-unavailable alert in compact mode and never fabricates a quantity', async () => {
    stockSectionStatus.value = 'error'
    stockSectionData.value = undefined
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper } = mountCard(p)
    await flushPromises()

    expect(wrapper.find('[data-test="stock-unavailable"]').text()).toContain(
      'Stock data is unavailable for this product',
    )
    expect(wrapper.find('[data-test="pricing-quantity"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="pricing-adjust-toggle"]').exists()).toBe(false)
  })

  it('propagates a stock-read error into the full table too (no zeros, honest alert)', async () => {
    stockSectionStatus.value = 'error'
    stockSectionData.value = undefined
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1' }), variant({ uuid: 'v2', sku: 'SKU-2' })],
    })
    const { wrapper } = mountCard(p)
    await flushPromises()

    expect(wrapper.find('[data-test="stock-unavailable"]').text()).toContain(
      'Stock data is unavailable for this product',
    )
    const quantities = wrapper.findAll('[data-test="variant-stock-quantity"]')
    expect(quantities.length).toBeGreaterThan(0)
    for (const q of quantities) expect(q.text()).toContain('—')
  })

  it('feeds the stock read into the full table as tracked/untracked quantities', async () => {
    stockSectionData.value = {
      revision: 0,
      items: [
        { variant_uuid: 'v1', tracked: true, quantity: 42 },
        { variant_uuid: 'v2', tracked: false, quantity: 0 },
      ],
    }
    const p = product({
      uuid: 'p1',
      variants: [variant({ uuid: 'v1' }), variant({ uuid: 'v2', sku: 'SKU-2' })],
    })
    const { wrapper } = mountCard(p)
    await flushPromises()

    const rows = wrapper.findAll('[data-test="variant-row"]')
    expect(rows[0]!.find('[data-test="variant-stock-quantity"]').text()).toContain('42')
    expect(rows[1]!.find('[data-test="variant-stock-quantity"]').text()).toContain('—')
  })

  it('hides canManage-gated controls when can_manage is false, keeping read-only content visible', async () => {
    stockSectionData.value = {
      revision: 0,
      items: [{ variant_uuid: 'v1', tracked: true, quantity: 5 }],
    }
    const p = product({ uuid: 'p1', variants: [variant({ uuid: 'v1' })] })
    const { wrapper } = mountCard(p, false)
    await flushPromises()

    expect(wrapper.find('[data-test="pricing-sku-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="pricing-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="pricing-quantity"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="pricing-adjust-toggle"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="pricing-add-more-variants"]').exists()).toBe(false)
  })
})

// ── ChildrenCard: hydration incl. honest tombstones, picker exclusion, replacement save, ──────
// structured conflict review, wipe-parallel (Task C8) ──────────────────────────────────────────

describe('ChildrenCard', () => {
  function mountCard(p: CommerceProduct, canManage = true) {
    return mountWithEditorContext(ChildrenCard, { product: p, canManage })
  }

  async function openPickerWith(wrapper: ReturnType<typeof mount>, results: CommerceProduct[]) {
    childrenPickerResults.value = results
    await wrapper.find('[data-test="children-add"]').trigger('click')
    await wrapper.find('[data-test="children-picker-search"]').setValue('wid')
    await flushPromises()
  }

  // ── Hydration: honest tombstone rendering ───────────────────────────────────────────────────

  it('hydrates every child from the real read, including an attached tombstone — never hidden', async () => {
    childrenSectionData.value = {
      revision: 5,
      items: [
        childItem({ uuid: 'c1', name: 'Child One' }),
        childItem({ uuid: 'c2', name: 'Ghost Child', status: 'archived', deleted: true }),
      ],
    }
    const { wrapper } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()

    const rows = wrapper.findAll('[data-test="children-row"]')
    expect(rows).toHaveLength(2)

    const liveRow = rows.find((r) => r.attributes('data-uuid') === 'c1')!
    expect(liveRow.attributes('data-deleted')).toBe('false')
    expect(liveRow.find('[data-test="children-deleted-badge"]').exists()).toBe(false)

    const deletedRow = rows.find((r) => r.attributes('data-uuid') === 'c2')!
    expect(deletedRow.attributes('data-deleted')).toBe('true')
    expect(deletedRow.find('[data-test="children-deleted-badge"]').exists()).toBe(true)
    expect(deletedRow.text()).toContain('Deleted')
    expect(deletedRow.text()).toContain('Ghost Child')
  })

  it('shows the empty state once loaded with no children, and the loading/error states honestly', async () => {
    childrenSectionData.value = { revision: 0, items: [] }
    const { wrapper } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()
    expect(wrapper.find('[data-test="children-empty"]').exists()).toBe(true)

    childrenSectionStatus.value = 'pending'
    const { wrapper: loadingWrapper } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    expect(loadingWrapper.find('[data-test="children-loading"]').exists()).toBe(true)

    childrenSectionStatus.value = 'error'
    const { wrapper: errorWrapper } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()
    expect(errorWrapper.find('[data-test="children-load-error"]').exists()).toBe(true)
  })

  it('hides add/remove/reorder/save controls when can_manage is false, keeping rows visible', async () => {
    childrenSectionData.value = { revision: 0, items: [childItem({ uuid: 'c1' })] }
    const { wrapper } = mountCard(product({ uuid: 'p1', type: 'grouped' }), false)
    await flushPromises()

    expect(wrapper.find('[data-test="children-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="children-move-up"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="children-move-down"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="children-remove"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="children-row"]').exists()).toBe(true)
  })

  // ── Picker: never offers a tombstone/non-purchasable product, self, or an already-drafted uuid ─

  it('the add picker excludes non-purchasable types, the product being edited itself, and children already in the draft', async () => {
    childrenSectionData.value = { revision: 0, items: [childItem({ uuid: 'c1', name: 'Child One' })] }
    const { wrapper } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()

    await openPickerWith(wrapper, [
      product({ uuid: 'p1', name: 'Self', type: 'physical' }), // the product being edited
      product({ uuid: 'grp1', name: 'Grouped Co', type: 'grouped' }), // non-purchasable
      product({ uuid: 'ext1', name: 'External Co', type: 'external' }), // non-purchasable
      product({ uuid: 'c1', name: 'Child One', type: 'physical' }), // already in the draft
      product({ uuid: 'ok1', name: 'OK Digital', type: 'digital' }),
      product({ uuid: 'ok2', name: 'OK Physical', type: 'physical' }),
    ])

    const results = wrapper.findAll('[data-test="children-picker-result"]')
    expect(results.map((r) => r.attributes('data-uuid'))).toEqual(['ok1', 'ok2'])
  })

  it('adding a picked product appends it to the draft and marks the section dirty, without calling setChildren', async () => {
    childrenSectionData.value = { revision: 0, items: [childItem({ uuid: 'c1', name: 'Child One' })] }
    const { wrapper, getState } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()
    const state = getState()

    await openPickerWith(wrapper, [product({ uuid: 'ok1', name: 'OK Digital', type: 'digital' })])
    await wrapper.find('[data-test="children-picker-result"]').trigger('click')

    expect(setChildrenMock).not.toHaveBeenCalled()
    expect(state.dirty.value).toBe(true)
    expect(
      wrapper.findAll('[data-test="children-row"]').map((r) => r.attributes('data-uuid')),
    ).toEqual(['c1', 'ok1'])
    // The just-added product disappears from its own candidate list (already in the draft now).
    expect(wrapper.find('[data-test="children-picker-result"]').exists()).toBe(false)
  })

  // ── Reorder / remove: local draft only, no network call until Save ─────────────────────────

  it('moving a row edits a local draft and marks the section dirty WITHOUT submitting immediately', async () => {
    childrenSectionData.value = {
      revision: 0,
      items: [childItem({ uuid: 'c1' }), childItem({ uuid: 'c2', name: 'Child Two', position: 1 })],
    }
    const { wrapper, getState } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()
    const state = getState()

    expect(wrapper.find('[data-test="children-save"]').exists()).toBe(false)
    await wrapper.find('[data-test="children-move-down"]').trigger('click')

    expect(setChildrenMock).not.toHaveBeenCalled()
    expect(state.dirty.value).toBe(true)
    expect(
      wrapper.findAll('[data-test="children-row"]').map((r) => r.attributes('data-uuid')),
    ).toEqual(['c2', 'c1'])
    expect(wrapper.find('[data-test="children-save"]').exists()).toBe(true)
  })

  it('removing a child (including a tombstoned one) marks the section dirty and drops it from the next save', async () => {
    childrenSectionData.value = {
      revision: 9,
      items: [
        childItem({ uuid: 'c1', name: 'Child One' }),
        childItem({ uuid: 'c2', name: 'Ghost Child', deleted: true }),
      ],
    }
    setChildrenMock.mockResolvedValue([])
    const { wrapper, getState } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()
    const state = getState()

    const ghostRow = wrapper.findAll('[data-test="children-row"]').find(
      (r) => r.attributes('data-uuid') === 'c2',
    )!
    await ghostRow.find('[data-test="children-remove"]').trigger('click')

    expect(state.dirty.value).toBe(true)
    await wrapper.find('[data-test="children-save"]').trigger('click')
    await flushPromises()

    expect(setChildrenMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      childUuids: ['c1'],
      expectedRevision: 9,
    })
  })

  // ── Save: replacement built from hydrated state (the wipe class dies here) ─────────────────

  it('"Save" submits the full draft — built from HYDRATED server state, not a fresh input — as an ordered uuid list with expected_revision, awaiting afterMutation() once', async () => {
    childrenSectionData.value = {
      revision: 4,
      items: [childItem({ uuid: 'c1' }), childItem({ uuid: 'c2', name: 'Child Two', position: 1 })],
    }
    setChildrenMock.mockResolvedValue([])
    const { wrapper, getCoordinator, getState } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')
    const state = getState()

    await wrapper.find('[data-test="children-move-down"]').trigger('click')
    await wrapper.find('[data-test="children-save"]').trigger('click')
    await flushPromises()

    expect(setChildrenMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      childUuids: ['c2', 'c1'],
      expectedRevision: 4,
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
    expect(state.dirty.value).toBe(false)
    expect(wrapper.find('[data-test="children-save"]').exists()).toBe(false)
  })

  it('wipe-parallel: adding ONE new child still submits every originally hydrated child, never just the touched one', async () => {
    childrenSectionData.value = {
      revision: 2,
      items: [childItem({ uuid: 'c1', name: 'Child One' }), childItem({ uuid: 'c2', name: 'Child Two', position: 1 })],
    }
    setChildrenMock.mockResolvedValue([])
    const { wrapper } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()

    await openPickerWith(wrapper, [product({ uuid: 'new1', name: 'New Child', type: 'physical' })])
    await wrapper.find('[data-test="children-picker-result"]').trigger('click')
    await wrapper.find('[data-test="children-save"]').trigger('click')
    await flushPromises()

    expect(setChildrenMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      childUuids: ['c1', 'c2', 'new1'],
      expectedRevision: 2,
    })
  })

  it('a non-409 save failure keeps the draft dirty, shows an error, and never calls afterMutation()', async () => {
    childrenSectionData.value = {
      revision: 0,
      items: [childItem({ uuid: 'c1' }), childItem({ uuid: 'c2', name: 'Child Two', position: 1 })],
    }
    setChildrenMock.mockRejectedValue(new ApiError('Validation failed', 422, {}, {}))
    const { wrapper, getCoordinator, getState } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')
    const state = getState()

    await wrapper.find('[data-test="children-move-down"]').trigger('click')
    await wrapper.find('[data-test="children-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="children-save-error"]').exists()).toBe(true)
    expect(state.dirty.value).toBe(true)
    expect(state.phase.value).toBe('error')
    expect(afterMutationSpy).not.toHaveBeenCalled()
  })

  // ── 409 conflict: refresh FIRST, structured review, no automatic retry ─────────────────────

  async function mountReorderedAndConflicted(remoteItems: ProductChildItem[], remoteRevision: number) {
    childrenSectionData.value = {
      revision: 0,
      items: [childItem({ uuid: 'c1' }), childItem({ uuid: 'c2', name: 'Child Two', position: 1 })],
    }
    const { wrapper, getCoordinator, getState } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()

    await wrapper.find('[data-test="children-move-down"]').trigger('click')

    setChildrenMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    childrenSectionData.value = { revision: remoteRevision, items: remoteItems }

    await wrapper.find('[data-test="children-save"]').trigger('click')
    await flushPromises()

    return { wrapper, getCoordinator, getState }
  }

  it('on a 409, refreshes the section FIRST, then shows a conflict review when the remote content genuinely differs (no automatic retry)', async () => {
    const remote = [
      childItem({ uuid: 'c1' }),
      childItem({ uuid: 'c3', name: 'Child Three', position: 1 }),
      childItem({ uuid: 'c2', name: 'Child Two', position: 2 }),
    ]
    const { wrapper } = await mountReorderedAndConflicted(remote, 5)

    expect(setChildrenMock).toHaveBeenCalledTimes(1)
    const conflict = wrapper.find('[data-test="children-conflict"]')
    expect(conflict.exists()).toBe(true)
    expect(conflict.text()).toContain('changed elsewhere — review and save again')
    expect(wrapper.find('[data-test="children-use-latest"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="children-replace-mine"]').exists()).toBe(true)
  })

  it('"Use latest" adopts the remote set, clears dirty, and never resubmits', async () => {
    const remote = [
      childItem({ uuid: 'c1' }),
      childItem({ uuid: 'c3', name: 'Child Three', position: 1 }),
      childItem({ uuid: 'c2', name: 'Child Two', position: 2 }),
    ]
    const { wrapper, getState } = await mountReorderedAndConflicted(remote, 5)
    const state = getState()

    await wrapper.find('[data-test="children-use-latest"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.findAll('[data-test="children-row"]').map((r) => r.attributes('data-uuid')),
    ).toEqual(['c1', 'c3', 'c2'])
    expect(state.dirty.value).toBe(false)
    expect(wrapper.find('[data-test="children-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="children-conflict"]').exists()).toBe(false)
    expect(setChildrenMock).toHaveBeenCalledTimes(1) // never resubmitted
  })

  it('"Replace with mine" resubmits the LOCAL set with the NEW revision, only after explicit confirmation', async () => {
    const remote = [
      childItem({ uuid: 'c1' }),
      childItem({ uuid: 'c3', name: 'Child Three', position: 1 }),
      childItem({ uuid: 'c2', name: 'Child Two', position: 2 }),
    ]
    const { wrapper, getCoordinator } = await mountReorderedAndConflicted(remote, 5)
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    // Still just the one original failed attempt — the review itself never auto-resubmits.
    expect(setChildrenMock).toHaveBeenCalledTimes(1)

    setChildrenMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="children-replace-mine"]').trigger('click')
    await flushPromises()

    expect(setChildrenMock).toHaveBeenCalledTimes(2)
    expect(setChildrenMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      childUuids: ['c2', 'c1'],
      expectedRevision: 5,
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('a silent rebase (remote items unchanged, revision only advanced) keeps the local draft, clears the error, and shows no conflict', async () => {
    const unchanged = [childItem({ uuid: 'c1' }), childItem({ uuid: 'c2', name: 'Child Two', position: 1 })]
    const { wrapper, getState } = await mountReorderedAndConflicted(unchanged, 7)
    const state = getState()

    expect(wrapper.find('[data-test="children-conflict"]').exists()).toBe(false)
    expect(
      wrapper.findAll('[data-test="children-row"]').map((r) => r.attributes('data-uuid')),
    ).toEqual(['c2', 'c1']) // local draft kept
    expect(state.dirty.value).toBe(true)
    expect(state.phase.value).toBe('idle') // NOT 'error' — spec §5.2 "show no conflict"
    expect(setChildrenMock).toHaveBeenCalledTimes(1) // no automatic retry

    // The rebased revision lets the NEXT explicit save succeed.
    setChildrenMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="children-save"]').trigger('click')
    await flushPromises()

    expect(setChildrenMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      childUuids: ['c2', 'c1'],
      expectedRevision: 7,
    })
  })

  it('disables the save button while a 409 recovery refresh is in flight', async () => {
    childrenSectionData.value = {
      revision: 0,
      items: [childItem({ uuid: 'c1' }), childItem({ uuid: 'c2', name: 'Child Two', position: 1 })],
    }
    const { wrapper } = mountCard(product({ uuid: 'p1', type: 'grouped' }))
    await flushPromises()
    await wrapper.find('[data-test="children-move-down"]').trigger('click')

    setChildrenMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    let resolveRefetch: (value: unknown) => void = () => {}
    childrenSectionRefetchMock.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveRefetch = resolve
        }),
    )

    const saveClick = wrapper.find('[data-test="children-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="children-save"]').attributes('disabled')).toBeDefined()

    resolveRefetch({ status: 'success', data: childrenSectionData.value, error: null })
    await saveClick
    await flushPromises()

    expect(wrapper.find('[data-test="children-save"]').attributes('disabled')).toBeUndefined()
  })
})

// ── MediaPanel: attach/reorder/update/detach, stable ordering, read-only, rollback ─────────

describe('MediaPanel', () => {
  function mountPanel(p: CommerceProduct, canManage = true) {
    return mountWithEditorContext(
      MediaPanel,
      { product: p, canManage },
      { global: { stubs: { MediaPickerModal: MediaPickerModalStub } } },
    )
  }

  async function attachOne(wrapper: ReturnType<typeof mount>) {
    await wrapper.find('[data-test="media-add"]').trigger('click')
    await wrapper.find('[data-test="media-picker-stub-pick"]').trigger('click')
    await flushPromises()
  }

  /** Mounts with a 2-item baseline (`m1`, `m2`, revision 0), moves `m1` down (a dirty local
   * reorder draft: `[m2, m1]`), then fails the reorder save with a 409 while the mocked section
   * read reflects `remoteItems`/`remoteRevision` — the shape every 409-recovery spec below starts
   * from. */
  async function mountReorderedAndConflicted(
    remoteItems: ProductMediaItem[],
    remoteRevision: number,
  ) {
    mediaSectionData.value = {
      revision: 0,
      items: [
        mediaItem({ uuid: 'm1' }),
        mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }),
      ],
    }
    const { wrapper, getCoordinator, getState } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()

    await wrapper.find('[data-test="media-move-down"]').trigger('click')

    reorderMediaMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    mediaSectionData.value = { revision: remoteRevision, items: remoteItems }

    await wrapper.find('[data-test="media-reorder-save"]').trigger('click')
    await flushPromises()

    return { wrapper, getCoordinator, getState }
  }

  // ── Item mutations locked while a reorder draft is dirty (C5 review Important) ──────────────

  it('disables add/edit/detach while a reorder draft is dirty, and re-enables once saved', async () => {
    mediaSectionData.value = {
      revision: 0,
      items: [
        mediaItem({ uuid: 'm1' }),
        mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }),
      ],
    }
    const { wrapper } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()

    // Clean: item-scoped controls live, no lock hint.
    expect(wrapper.find('[data-test="media-add"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="media-item-mutations-locked"]').exists()).toBe(false)

    await wrapper.find('[data-test="media-move-down"]').trigger('click')

    // Dirty order draft: attach/edit/detach all locked, hint shown; move controls stay live
    // (they ARE the draft).
    expect(wrapper.find('[data-test="media-add"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="media-edit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="media-detach"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="media-item-mutations-locked"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="media-move-up"]').exists()).toBe(true)

    reorderMediaMock.mockResolvedValueOnce(undefined)
    await wrapper.find('[data-test="media-reorder-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="media-add"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="media-item-mutations-locked"]').exists()).toBe(false)
  })

  // ── Hydration (server truth replaces the old session-only `knownMedia` tracking) ────────────

  it('hydrates and renders media rows from the section read, in the order the server returns', () => {
    mediaSectionData.value = {
      revision: 3,
      items: [
        mediaItem({ uuid: 'm1', blob_uuid: 'blob-1' }),
        mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }),
      ],
    }
    const { wrapper } = mountPanel(product({ uuid: 'p1' }))

    expect(
      wrapper.findAll('[data-test="media-row"]').map((r) => r.attributes('data-uuid')),
    ).toEqual(['m1', 'm2'])
    expect(wrapper.find('[data-test="media-thumb"]').attributes('src')).toBe('/blobs/blob-1')
  })

  it('shows a genuinely empty state once loaded — the old "media-unknown" alert is gone entirely', () => {
    mediaSectionData.value = { revision: 0, items: [] }
    const { wrapper } = mountPanel(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="media-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="media-unknown"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-row"]').exists()).toBe(false)
  })

  it('never renders the deleted "media-unknown" alert, even before the section read resolves', () => {
    mediaSectionStatus.value = 'pending'
    mediaSectionData.value = undefined
    const { wrapper } = mountPanel(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="media-unknown"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="media-empty"]').exists()).toBe(false)
  })

  it('shows a load-error state when the section read fails', () => {
    mediaSectionStatus.value = 'error'
    mediaSectionData.value = undefined
    const { wrapper } = mountPanel(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="media-load-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="media-unknown"]').exists()).toBe(false)
  })

  it('renders a variant-attribution badge only on items with variant_uuid set', () => {
    mediaSectionData.value = {
      revision: 0,
      items: [
        mediaItem({ uuid: 'm1', variant_uuid: null }),
        mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1, variant_uuid: 'v1' }),
      ],
    }
    const { wrapper } = mountPanel(product({ uuid: 'p1' }))

    const rows = wrapper.findAll('[data-test="media-row"]')
    expect(rows[0]!.find('[data-test="media-variant-badge"]').exists()).toBe(false)
    expect(rows[1]!.find('[data-test="media-variant-badge"]').exists()).toBe(true)
  })

  // ── Attach / update / detach: item-scoped, unguarded, each awaits afterMutation() once ──────

  it('attaches media via the picker, awaiting afterMutation() once, and reflects the refreshed section read', async () => {
    mediaSectionData.value = { revision: 0, items: [] }
    attachMediaMock.mockResolvedValue(media({ uuid: 'm1', blob_uuid: 'blob-1', role: 'gallery' }))
    const { wrapper, getCoordinator } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    mediaSectionData.value = {
      revision: 1,
      items: [mediaItem({ uuid: 'm1', blob_uuid: 'blob-1' })],
    }
    await attachOne(wrapper)

    expect(attachMediaMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      input: { blob_uuid: 'blob-new', role: 'gallery' },
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
    expect(wrapper.findAll('[data-test="media-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="media-thumb"]').attributes('src')).toBe('/blobs/blob-1')
  })

  it('surfaces the "blob already attached" 422 message on attach failure, without calling afterMutation()', async () => {
    mediaSectionData.value = { revision: 0, items: [] }
    attachMediaMock.mockRejectedValue(
      new ApiError(
        'Validation failed',
        422,
        { blob_uuid: 'This blob is already attached to the product.' },
        {},
      ),
    )
    const { wrapper, getCoordinator } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await attachOne(wrapper)

    expect(wrapper.find('[data-test="media-attach-error"]').text()).toContain(
      'This blob is already attached to the product.',
    )
    expect(wrapper.find('[data-test="media-row"]').exists()).toBe(false)
    expect(afterMutationSpy).not.toHaveBeenCalled()
  })

  it('updates alt text via the inline edit form, awaiting afterMutation() once', async () => {
    mediaSectionData.value = {
      revision: 0,
      items: [mediaItem({ uuid: 'm1', blob_uuid: 'blob-1' })],
    }
    updateMediaMock.mockResolvedValue(
      media({ uuid: 'm1', blob_uuid: 'blob-1', role: 'gallery', alt: 'Front view' }),
    )
    const { wrapper, getCoordinator } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    mediaSectionData.value = {
      revision: 1,
      items: [mediaItem({ uuid: 'm1', blob_uuid: 'blob-1', alt: 'Front view' })],
    }
    await wrapper.find('[data-test="media-edit"]').trigger('click')
    await wrapper.find('[data-test="media-edit-alt-input"]').setValue('Front view')
    await wrapper.find('[data-test="media-edit-save"]').trigger('click')
    await flushPromises()

    expect(updateMediaMock).toHaveBeenCalledWith({
      uuid: 'm1',
      productUuid: 'p1',
      input: { alt: 'Front view', role: 'gallery' },
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="media-alt"]').text()).toContain('Front view')
  })

  it('surfaces a validation 422 message on media update, without calling afterMutation()', async () => {
    mediaSectionData.value = { revision: 0, items: [mediaItem({ uuid: 'm1' })] }
    updateMediaMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { alt: 'Alt text too long.' }, {}),
    )
    const { wrapper, getCoordinator } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="media-edit"]').trigger('click')
    await wrapper.find('[data-test="media-edit-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="media-edit-error"]').text()).toContain('Alt text too long.')
    expect(afterMutationSpy).not.toHaveBeenCalled()
  })

  it('detaches media, awaiting afterMutation() once, and reflects the refreshed (now empty) section read', async () => {
    mediaSectionData.value = { revision: 0, items: [mediaItem({ uuid: 'm1' })] }
    detachMediaMock.mockResolvedValue(undefined)
    const { wrapper, getCoordinator } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    mediaSectionData.value = { revision: 1, items: [] }
    await wrapper.find('[data-test="media-detach"]').trigger('click')
    await flushPromises()

    expect(detachMediaMock).toHaveBeenCalledWith({ uuid: 'm1', productUuid: 'p1' })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="media-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-empty"]').exists()).toBe(true)
  })

  it('hides mutation controls when can_manage is false, keeping media rows visible', () => {
    mediaSectionData.value = {
      revision: 0,
      items: [
        mediaItem({ uuid: 'm1' }),
        mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }),
      ],
    }
    const { wrapper } = mountPanel(product({ uuid: 'p1' }), false)

    expect(wrapper.find('[data-test="media-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-detach"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-move-up"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-move-down"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="media-row"]')).toHaveLength(2)
  })

  // ── Reorder: local draft + explicit "Save order" (the replacement mutation) ─────────────────

  it('moving a row edits a local draft and marks the section dirty WITHOUT submitting immediately', async () => {
    mediaSectionData.value = {
      revision: 0,
      items: [
        mediaItem({ uuid: 'm1' }),
        mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }),
      ],
    }
    const { wrapper, getState } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()
    const state = getState()

    expect(wrapper.find('[data-test="media-reorder-save"]').exists()).toBe(false)
    await wrapper.find('[data-test="media-move-down"]').trigger('click')

    expect(reorderMediaMock).not.toHaveBeenCalled()
    expect(state.dirty.value).toBe(true)
    expect(
      wrapper.findAll('[data-test="media-row"]').map((r) => r.attributes('data-uuid')),
    ).toEqual(['m2', 'm1'])
    expect(wrapper.find('[data-test="media-reorder-save"]').exists()).toBe(true)
  })

  it('"Save order" submits the full draft order with expected_revision = baseRevision, awaiting afterMutation() once', async () => {
    mediaSectionData.value = {
      revision: 4,
      items: [
        mediaItem({ uuid: 'm1' }),
        mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }),
      ],
    }
    reorderMediaMock.mockResolvedValue([])
    const { wrapper, getCoordinator, getState } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')
    const state = getState()

    await wrapper.find('[data-test="media-move-down"]').trigger('click')
    await wrapper.find('[data-test="media-reorder-save"]').trigger('click')
    await flushPromises()

    expect(reorderMediaMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      orderedUuids: ['m2', 'm1'],
      expectedRevision: 4,
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
    expect(state.dirty.value).toBe(false)
    expect(wrapper.find('[data-test="media-reorder-save"]').exists()).toBe(false)
  })

  it('a non-409 reorder failure keeps the draft dirty, shows an error, and never calls afterMutation()', async () => {
    mediaSectionData.value = {
      revision: 0,
      items: [
        mediaItem({ uuid: 'm1' }),
        mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }),
      ],
    }
    reorderMediaMock.mockRejectedValue(new ApiError('Validation failed', 422, {}, {}))
    const { wrapper, getCoordinator, getState } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')
    const state = getState()

    await wrapper.find('[data-test="media-move-down"]').trigger('click')
    await wrapper.find('[data-test="media-reorder-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="media-reorder-error"]').exists()).toBe(true)
    expect(state.dirty.value).toBe(true)
    expect(state.phase.value).toBe('error')
    expect(afterMutationSpy).not.toHaveBeenCalled()
    // Draft order is preserved — a failed save never reverts to server order.
    expect(
      wrapper.findAll('[data-test="media-row"]').map((r) => r.attributes('data-uuid')),
    ).toEqual(['m2', 'm1'])
  })

  it('on a 409, refreshes the section FIRST, then shows a conflict review when the remote content genuinely differs (no automatic retry)', async () => {
    const remote = [
      mediaItem({ uuid: 'm1' }),
      mediaItem({ uuid: 'm3', blob_uuid: 'blob-3', position: 1 }),
      mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 2 }),
    ]
    const { wrapper } = await mountReorderedAndConflicted(remote, 5)

    expect(reorderMediaMock).toHaveBeenCalledTimes(1)
    const conflict = wrapper.find('[data-test="media-reorder-conflict"]')
    expect(conflict.exists()).toBe(true)
    expect(conflict.text()).toContain('changed elsewhere — review and save again')
    expect(wrapper.find('[data-test="media-reorder-use-latest"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="media-reorder-replace-mine"]').exists()).toBe(true)
  })

  it('"Use latest" adopts the remote order, clears dirty, and never resubmits', async () => {
    const remote = [
      mediaItem({ uuid: 'm1' }),
      mediaItem({ uuid: 'm3', blob_uuid: 'blob-3', position: 1 }),
      mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 2 }),
    ]
    const { wrapper, getState } = await mountReorderedAndConflicted(remote, 5)
    const state = getState()

    await wrapper.find('[data-test="media-reorder-use-latest"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.findAll('[data-test="media-row"]').map((r) => r.attributes('data-uuid')),
    ).toEqual(['m1', 'm3', 'm2'])
    expect(state.dirty.value).toBe(false)
    expect(wrapper.find('[data-test="media-reorder-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="media-reorder-conflict"]').exists()).toBe(false)
    expect(reorderMediaMock).toHaveBeenCalledTimes(1) // never resubmitted
  })

  it('"Replace with mine" resubmits the LOCAL order with the NEW revision, only after explicit confirmation', async () => {
    const remote = [
      mediaItem({ uuid: 'm1' }),
      mediaItem({ uuid: 'm3', blob_uuid: 'blob-3', position: 1 }),
      mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 2 }),
    ]
    const { wrapper, getCoordinator } = await mountReorderedAndConflicted(remote, 5)
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    // Still just the one original failed attempt — the review itself never auto-resubmits.
    expect(reorderMediaMock).toHaveBeenCalledTimes(1)

    reorderMediaMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="media-reorder-replace-mine"]').trigger('click')
    await flushPromises()

    expect(reorderMediaMock).toHaveBeenCalledTimes(2)
    expect(reorderMediaMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      orderedUuids: ['m2', 'm1'],
      expectedRevision: 5,
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('a silent rebase (remote items unchanged, revision only advanced) keeps the local draft, clears the error, and shows no conflict', async () => {
    const unchanged = [
      mediaItem({ uuid: 'm1' }),
      mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }),
    ]
    const { wrapper, getState } = await mountReorderedAndConflicted(unchanged, 7)
    const state = getState()

    expect(wrapper.find('[data-test="media-reorder-conflict"]').exists()).toBe(false)
    expect(
      wrapper.findAll('[data-test="media-row"]').map((r) => r.attributes('data-uuid')),
    ).toEqual(['m2', 'm1']) // local draft kept
    expect(state.dirty.value).toBe(true)
    expect(state.phase.value).toBe('idle') // NOT 'error' — spec §5.2 "show no conflict"
    expect(reorderMediaMock).toHaveBeenCalledTimes(1) // no automatic retry

    // The rebased revision lets the NEXT explicit save succeed.
    reorderMediaMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="media-reorder-save"]').trigger('click')
    await flushPromises()

    expect(reorderMediaMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      orderedUuids: ['m2', 'm1'],
      expectedRevision: 7,
    })
  })

  it('disables the reorder save button while a 409 recovery refresh is in flight', async () => {
    mediaSectionData.value = {
      revision: 0,
      items: [
        mediaItem({ uuid: 'm1' }),
        mediaItem({ uuid: 'm2', blob_uuid: 'blob-2', position: 1 }),
      ],
    }
    const { wrapper } = mountPanel(product({ uuid: 'p1' }))
    await flushPromises()
    await wrapper.find('[data-test="media-move-down"]').trigger('click')

    reorderMediaMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    let resolveRefetch: (value: unknown) => void = () => {}
    mediaSectionRefetchMock.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveRefetch = resolve
        }),
    )

    const saveClick = wrapper.find('[data-test="media-reorder-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="media-reorder-save"]').attributes('disabled')).toBeDefined()

    resolveRefetch({ status: 'success', data: mediaSectionData.value, error: null })
    await saveClick
    await flushPromises()

    expect(wrapper.find('[data-test="media-reorder-save"]').attributes('disabled')).toBeUndefined()
  })
})

// ── CategoriesTab: management mode (no `product` prop) — full category CRUD ────────────────

describe('CategoriesTab (category management)', () => {
  function mountTab(canManage = true) {
    return mount(CategoriesTab, {
      props: { canManage },
      global: { stubs: { Modal: teleportStub } },
    })
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

    expect(wrapper.find('[data-test="category-form-error"]').text()).toContain(
      'Slug already in use.',
    )
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

  it('renders no assignment section or state chip in taxonomy-management mode (no `product` prop)', () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    const wrapper = mountTab()

    expect(wrapper.find('[data-test="category-assignment-section"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="categories-state-chip"]').exists()).toBe(false)
  })
})

// ── CategoriesTab: assignment mode (`product` prop given) ───────────────────────────────────

describe('CategoriesTab (product assignment)', () => {
  function mountAssignment(p: CommerceProduct, canManage = true) {
    return mountWithEditorContext(CategoriesTab, { product: p, canManage })
  }

  it('hydrates the selection from the section read — the old "not loaded" warning is gone entirely', () => {
    categoriesData.value = [
      category({ uuid: 'c1', name: 'Cat 1' }),
      category({ uuid: 'c2', name: 'Cat 2' }),
    ]
    categoriesSectionData.value = {
      revision: 3,
      items: [{ uuid: 'c1', name: 'Cat 1', slug: 'cat-1' }],
    }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="category-assignment-unknown"]').exists()).toBe(false)
    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    expect(checkboxes[0]!.props('modelValue')).toBe(true)
    expect(checkboxes[1]!.props('modelValue')).toBe(false)
  })

  it('hides category CRUD controls in assignment mode even when can_manage is true', () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="category-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="category-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="category-delete"]').exists()).toBe(false)
  })

  it('selects categories and saves the exact uuid list with expected_revision, awaiting afterMutation() once', async () => {
    categoriesData.value = [
      category({ uuid: 'c1', name: 'Cat 1' }),
      category({ uuid: 'c2', name: 'Cat 2' }),
    ]
    categoriesSectionData.value = { revision: 2, items: [] }
    setCategoriesMock.mockResolvedValue([])
    const { wrapper, getCoordinator } = mountAssignment(product({ uuid: 'p1' }))
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setCategoriesMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      categoryUuids: ['c1'],
      expectedRevision: 2,
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('a category assigned before this session survives an unrelated toggle+save round-trip (wipe-class regression)', async () => {
    categoriesData.value = [
      category({ uuid: 'catA', name: 'Cat A' }),
      category({ uuid: 'catB', name: 'Cat B' }),
    ]
    categoriesSectionData.value = {
      revision: 2,
      items: [{ uuid: 'catA', name: 'Cat A', slug: 'cat-a' }],
    }
    setCategoriesMock.mockResolvedValue([])
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    // catA was never touched by the user — only catB is toggled on.
    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[1]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setCategoriesMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      categoryUuids: ['catA', 'catB'],
      expectedRevision: 2,
    })
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
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="category-assignment-error"]').text()).toContain(
      'category_uuids must reference existing categories in this tenant.',
    )
    expect(checkboxes[0]!.props('modelValue')).toBe(true)
  })

  it('on a 409 where the remote set is unchanged since the baseline, rebases silently: keeps the draft, advances the revision, no banner, no automatic retry', async () => {
    categoriesData.value = [
      category({ uuid: 'catA', name: 'A' }),
      category({ uuid: 'catB', name: 'B' }),
    ]
    categoriesSectionData.value = { revision: 0, items: [{ uuid: 'catA', name: 'A', slug: 'a' }] }
    const { wrapper, getState } = mountAssignment(product({ uuid: 'p1' }))
    const state = getState()

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[1]!.vm.$emit('update:modelValue', true) // catB — local-only addition

    setCategoriesMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    categoriesSectionData.value = { revision: 9, items: [{ uuid: 'catA', name: 'A', slug: 'a' }] } // unchanged set

    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="category-merge-banner"]').exists()).toBe(false)
    expect(state.dirty.value).toBe(true)
    expect(state.phase.value).toBe('idle') // NOT 'error' — spec §5.2 "show no conflict"
    expect(setCategoriesMock).toHaveBeenCalledTimes(1)

    setCategoriesMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setCategoriesMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      categoryUuids: ['catA', 'catB'],
      expectedRevision: 9,
    })
  })

  it('on a 409 where the remote content genuinely diverged, merges deterministically: REPLACES the draft, shows a review banner, stays dirty, and the next save uses the advanced revision', async () => {
    categoriesData.value = [
      category({ uuid: 'catA', name: 'A' }),
      category({ uuid: 'catB', name: 'B' }),
      category({ uuid: 'catC', name: 'C' }),
    ]
    categoriesSectionData.value = { revision: 0, items: [{ uuid: 'catA', name: 'A', slug: 'a' }] }
    const { wrapper, getState } = mountAssignment(product({ uuid: 'p1' }))
    const state = getState()

    // Local addition: catB.
    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[1]!.vm.$emit('update:modelValue', true)

    setCategoriesMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    // Remote addition: catC (genuinely diverged from the baseline [catA]).
    categoriesSectionData.value = {
      revision: 5,
      items: [
        { uuid: 'catA', name: 'A', slug: 'a' },
        { uuid: 'catC', name: 'C', slug: 'c' },
      ],
    }

    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="category-merge-banner"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="category-merge-banner"]').text()).toContain(
      'merged with remote changes — review and save',
    )
    // Draft replaced by the deterministic merge: R (catA, catC) plus the local addition (catB).
    const checked = wrapper
      .findAllComponents({ name: 'CheckboxRoot' })
      .map((c) => c.props('modelValue'))
    expect(checked).toEqual([true, true, true])
    expect(state.dirty.value).toBe(true) // stays dirty — no auto-save
    expect(setCategoriesMock).toHaveBeenCalledTimes(1) // never auto-resubmitted

    setCategoriesMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setCategoriesMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      categoryUuids: ['catA', 'catC', 'catB'],
      expectedRevision: 5,
    })
  })

  it('disables the save button while a 409 recovery refresh is in flight', async () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    categoriesSectionData.value = { revision: 0, items: [] }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)

    setCategoriesMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    let resolveRefetch: (value: unknown) => void = () => {}
    categoriesSectionRefetchMock.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveRefetch = resolve
        }),
    )

    const saveClick = wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.find('[data-test="category-assignment-save"]').attributes('disabled'),
    ).toBeDefined()

    resolveRefetch({ status: 'success', data: categoriesSectionData.value, error: null })
    await saveClick
    await flushPromises()

    expect(
      wrapper.find('[data-test="category-assignment-save"]').attributes('disabled'),
    ).toBeUndefined()
  })

  it('hides the save control and disables checkboxes when can_manage is false', () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }), false)

    expect(wrapper.find('[data-test="category-assignment-save"]').exists()).toBe(false)
    const checkbox = wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]
    expect(checkbox!.props('disabled')).toBe(true)
  })

  it('renders its own state chip in the assignment header, tracking idle → dirty → saved (spec §5.1 item 4)', async () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    categoriesSectionData.value = { revision: 1, items: [] }
    setCategoriesMock.mockResolvedValue([])
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="categories-state-chip"]').exists()).toBe(false)

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)

    expect(wrapper.find('[data-test="categories-state-chip"]').text()).toBe('Unsaved changes')

    await wrapper.find('[data-test="category-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="categories-state-chip"]').text()).toBe('Saved')
  })

  it('shows a load-error alert for the per-product section read and keeps save disabled', () => {
    categoriesData.value = [category({ uuid: 'c1', name: 'Cat 1' })]
    categoriesSectionStatus.value = 'error'
    categoriesSectionData.value = undefined
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="categories-section-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="categories-section-error"]').text()).toContain(
      'Couldn’t load current assignments. Try again.',
    )
    expect(
      wrapper.find('[data-test="category-assignment-save"]').attributes('disabled'),
    ).toBeDefined()
  })
})

// ── TagsTab: management mode (no `product` prop) — tag CRUD + pagination/search ────────────

describe('TagsTab (tag management)', () => {
  function mountTab(canManage = true) {
    return mount(TagsTab, { props: { canManage }, global: { stubs: { Modal: teleportStub } } })
  }

  it('renders each tag', () => {
    tagsPage.value = {
      tags: [
        tag({ uuid: 't1', name: 'Sale', slug: 'sale' }),
        tag({ uuid: 't2', name: 'New', slug: 'new' }),
      ],
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
    tagCreateMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { slug: 'Slug already in use.' }, {}),
    )
    const wrapper = mountTab()

    await wrapper.find('[data-test="tag-add"]').trigger('click')
    await wrapper.find('[data-test="tag-name-input"]').setValue('Dup')
    await wrapper.find('[data-test="tag-slug-input"]').setValue('dup')
    await wrapper.find('#tag-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="tag-form-error"]').text()).toContain('Slug already in use.')
  })

  it('updates a tag via the edit form, sending ONLY the name — slug is immutable', async () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Old', slug: 'old' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
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
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Old', slug: 'old' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    tagUpdateMock.mockRejectedValue(
      new ApiError(
        'Validation failed',
        422,
        { slug: 'slug is immutable and cannot be changed after creation.' },
        {},
      ),
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
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Old' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
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
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Old' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountTab(false)

    expect(wrapper.find('[data-test="tag-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-row"]').exists()).toBe(true)
  })

  it('renders no assignment section or state chip in taxonomy-management mode (no `product` prop)', () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Tag 1' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const wrapper = mountTab()

    expect(wrapper.find('[data-test="tag-assignment-section"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tags-state-chip"]').exists()).toBe(false)
  })
})

// ── TagsTab: assignment mode (`product` prop given) ─────────────────────────────────────────

describe('TagsTab (product assignment)', () => {
  function mountAssignment(p: CommerceProduct, canManage = true) {
    return mountWithEditorContext(TagsTab, { product: p, canManage })
  }

  it('hydrates the selection from the section read — the old "not loaded" warning is gone entirely', () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Tag 1' }), tag({ uuid: 't2', name: 'Tag 2' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    tagsSectionData.value = { revision: 4, items: [{ uuid: 't1', name: 'Tag 1', slug: 'tag-1' }] }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="tag-assignment-unknown"]').exists()).toBe(false)
    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    expect(checkboxes[0]!.props('modelValue')).toBe(true)
    expect(checkboxes[1]!.props('modelValue')).toBe(false)
  })

  it('hides tag CRUD controls in assignment mode even when can_manage is true', () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Tag 1' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="tag-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tag-delete"]').exists()).toBe(false)
  })

  it('selects tags and saves the exact uuid list with expected_revision, awaiting afterMutation() once', async () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Tag 1' }), tag({ uuid: 't2', name: 'Tag 2' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    tagsSectionData.value = { revision: 6, items: [] }
    setTagsMock.mockResolvedValue([])
    const { wrapper, getCoordinator } = mountAssignment(product({ uuid: 'p1' }))
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="tag-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setTagsMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      tagUuids: ['t1'],
      expectedRevision: 6,
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('a tag assigned before this session, on a page never visited, survives an unrelated toggle+save round-trip (wipe-class regression)', async () => {
    // Page 1 shows only t1 — t-offpage is assigned server-side but its page is never visited.
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Tag 1' })],
      total: 2,
      current_page: 1,
      per_page: 1,
    }
    tagsSectionData.value = {
      revision: 3,
      items: [{ uuid: 't-offpage', name: 'Off page', slug: 'off-page' }],
    }
    setTagsMock.mockResolvedValue([])
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true) // toggles t1 on
    await wrapper.find('[data-test="tag-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setTagsMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      tagUuids: ['t-offpage', 't1'],
      expectedRevision: 3,
    })
  })

  it('surfaces a validation 422 message on save without discarding the current selection', async () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Tag 1' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    setTagsMock.mockRejectedValue(
      new ApiError(
        'Validation failed',
        422,
        { tag_uuids: 'tag_uuids must reference existing tags in this tenant.' },
        {},
      ),
    )
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="tag-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="tag-assignment-error"]').text()).toContain(
      'tag_uuids must reference existing tags in this tenant.',
    )
  })

  it('on a 409 where the remote set is unchanged since the baseline, rebases silently: keeps the draft, advances the revision, no banner', async () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Tag 1' }), tag({ uuid: 't2', name: 'Tag 2' })],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    tagsSectionData.value = { revision: 0, items: [{ uuid: 't1', name: 'Tag 1', slug: 'tag-1' }] }
    const { wrapper, getState } = mountAssignment(product({ uuid: 'p1' }))
    const state = getState()

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[1]!.vm.$emit('update:modelValue', true) // t2 — local-only addition

    setTagsMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    tagsSectionData.value = { revision: 8, items: [{ uuid: 't1', name: 'Tag 1', slug: 'tag-1' }] } // unchanged set

    await wrapper.find('[data-test="tag-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="tag-merge-banner"]').exists()).toBe(false)
    expect(state.dirty.value).toBe(true)
    expect(state.phase.value).toBe('idle')
    expect(setTagsMock).toHaveBeenCalledTimes(1)

    setTagsMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="tag-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setTagsMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      tagUuids: ['t1', 't2'],
      expectedRevision: 8,
    })
  })

  it('on a 409 where the remote content genuinely diverged, merges deterministically: REPLACES the draft, shows a review banner, stays dirty', async () => {
    tagsPage.value = {
      tags: [
        tag({ uuid: 't1', name: 'Tag 1' }),
        tag({ uuid: 't2', name: 'Tag 2' }),
        tag({ uuid: 't3', name: 'Tag 3' }),
      ],
      total: 3,
      current_page: 1,
      per_page: 24,
    }
    tagsSectionData.value = { revision: 0, items: [{ uuid: 't1', name: 'Tag 1', slug: 'tag-1' }] }
    const { wrapper, getState } = mountAssignment(product({ uuid: 'p1' }))
    const state = getState()

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[1]!.vm.$emit('update:modelValue', true) // local addition: t2

    setTagsMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    tagsSectionData.value = {
      revision: 5,
      items: [
        { uuid: 't1', name: 'Tag 1', slug: 'tag-1' },
        { uuid: 't3', name: 'Tag 3', slug: 'tag-3' },
      ],
    } // remote addition: t3

    await wrapper.find('[data-test="tag-assignment-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="tag-merge-banner"]').exists()).toBe(true)
    expect(state.dirty.value).toBe(true)
    expect(setTagsMock).toHaveBeenCalledTimes(1)

    setTagsMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="tag-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setTagsMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      tagUuids: ['t1', 't3', 't2'],
      expectedRevision: 5,
    })
  })

  it('hides the save control and disables checkboxes when can_manage is false', () => {
    tagsPage.value = {
      tags: [tag({ uuid: 't1', name: 'Tag 1' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }), false)

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
    return mount(AttributesTab, {
      props: { canManage },
      global: { stubs: { Modal: teleportStub } },
    })
  }

  it('renders each attribute', () => {
    attributesPage.value = {
      attributes: [
        attribute({ uuid: 'a1', name: 'Color', slug: 'color' }),
        attribute({ uuid: 'a2', name: 'Size', slug: 'size' }),
      ],
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
    attributeCreateMock.mockResolvedValue(
      attribute({ uuid: 'new-1', name: 'Material', slug: 'material' }),
    )
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-add"]').trigger('click')
    await wrapper.find('[data-test="attribute-name-input"]').setValue('Material')
    await wrapper.find('[data-test="attribute-slug-input"]').setValue('material')
    await wrapper.find('#attribute-form').trigger('submit')
    await flushPromises()

    expect(attributeCreateMock).toHaveBeenCalledWith({
      slug: 'material',
      name: 'Material',
      position: 0,
    })
  })

  it('surfaces a duplicate-slug 422 message instead of vanishing it', async () => {
    attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
    attributeCreateMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { slug: 'Slug already in use.' }, {}),
    )
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-add"]').trigger('click')
    await wrapper.find('[data-test="attribute-name-input"]').setValue('Dup')
    await wrapper.find('[data-test="attribute-slug-input"]').setValue('dup')
    await wrapper.find('#attribute-form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="attribute-form-error"]').text()).toContain(
      'Slug already in use.',
    )
  })

  it('updates an attribute via the edit form, sending slug/name/position — attribute slug stays editable', async () => {
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', name: 'Old', slug: 'old' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributeUpdateMock.mockResolvedValue(
      attribute({ uuid: 'a1', name: 'New name', slug: 'new-slug' }),
    )
    const wrapper = mountTab()

    await wrapper.find('[data-test="attribute-edit"]').trigger('click')
    // Unlike tags, the slug field stays editable while editing an attribute.
    expect(
      wrapper.find('[data-test="attribute-slug-input"]').attributes('disabled'),
    ).toBeUndefined()
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
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', name: 'Old' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
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
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', name: 'Old' })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
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
    attributeCreateValueMock.mockResolvedValue(
      attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' }),
    )
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
      new ApiError(
        'Validation failed',
        422,
        { slug: 'Slug already in use for this attribute.' },
        {},
      ),
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
        attribute({
          uuid: 'a1',
          name: 'Color',
          values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })],
        }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributeUpdateValueMock.mockResolvedValue(
      attributeValue({ uuid: 'v1', value: 'Crimson', slug: 'red' }),
    )
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
        attribute({
          uuid: 'a1',
          name: 'Color',
          values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })],
        }),
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
        attribute({
          uuid: 'a1',
          name: 'Color',
          values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })],
        }),
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
    return mountWithEditorContext(AttributesTab, { product: p, canManage })
  }

  function assignedRow(
    overrides: Partial<ProductAttributeAssignment> = {},
  ): ProductAttributeAssignment {
    return {
      attribute_uuid: null,
      name: null,
      values: [],
      used_for_variants: false,
      visible: true,
      position: 0,
      ...overrides,
    }
  }

  /** The "include this attribute" checkbox for one attribute row, found by scoping to that row's
   * own `attribute-assign-row` first — a flat `findAllComponents({name: 'CheckboxRoot'})` index
   * shifts depending on how many OTHER rows happen to be included (each included row renders 2-3
   * extra nested checkboxes), which is exactly the kind of off-by-one this helper avoids. The
   * include checkbox is always the FIRST `CheckboxRoot` within its row (the nested value/flag
   * checkboxes only render after it, and only when included). */
  function includeCheckbox(wrapper: ReturnType<typeof mount>, attrUuid: string) {
    const row = wrapper
      .findAll('[data-test="attribute-assign-row"]')
      .find((r) => r.attributes('data-uuid') === attrUuid)
    if (!row) throw new Error(`No attribute-assign-row for uuid ${attrUuid}`)
    return row.findComponent({ name: 'CheckboxRoot' })
  }

  /** Mounts with a 1-row baseline (`a1` included, revision 0), toggles a second attribute (`a2`)
   * on (a dirty local draft), then fails the save with a 409 while the mocked section read
   * reflects `remoteRows`/`remoteRevision` — the shape every 409-recovery spec below starts from. */
  async function mountAndConflicted(
    remoteRows: ProductAttributeAssignment[],
    remoteRevision: number,
  ) {
    attributesPage.value = {
      attributes: [
        attribute({ uuid: 'a1', slug: 'color', name: 'Color', values: [] }),
        attribute({ uuid: 'a2', slug: 'size', name: 'Size', values: [] }),
      ],
      total: 2,
      current_page: 1,
      per_page: 24,
    }
    attributesSectionData.value = {
      revision: 0,
      items: [assignedRow({ attribute_uuid: 'a1' })],
    }
    const { wrapper, getCoordinator, getState } = mountAssignment(product({ uuid: 'p1' }))

    await includeCheckbox(wrapper, 'a2').vm.$emit('update:modelValue', true) // local addition: include a2

    setAttributesMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    attributesSectionData.value = { revision: remoteRevision, items: remoteRows }

    await wrapper.find('[data-test="attribute-assignment-save"]').trigger('click')
    await flushPromises()

    return { wrapper, getCoordinator, getState }
  }

  it('hydrates the assignment from the section read — the old "not loaded" warning is gone entirely', () => {
    attributesPage.value = {
      attributes: [
        attribute({
          uuid: 'a1',
          name: 'Color',
          values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })],
        }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributesSectionData.value = {
      revision: 2,
      items: [assignedRow({ attribute_uuid: 'a1', values: ['red'], used_for_variants: true })],
    }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="attribute-assignment-unknown"]').exists()).toBe(false)
    const checkbox = wrapper.findAllComponents({ name: 'CheckboxRoot' })[0]
    expect(checkbox!.props('modelValue')).toBe(true)
  })

  it('hides attribute CRUD and value CRUD controls in assignment mode even when can_manage is true', async () => {
    attributesPage.value = {
      attributes: [
        attribute({
          uuid: 'a1',
          name: 'Color',
          values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })],
        }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    expect(wrapper.find('[data-test="attribute-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-delete"]').exists()).toBe(false)

    await wrapper.find('[data-test="attribute-values-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="attribute-value-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-value-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="attribute-value-delete"]').exists()).toBe(false)
  })

  it('builds and saves the exact row shape for an included attribute with expected_revision, awaiting afterMutation() once', async () => {
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
    attributesSectionData.value = { revision: 7, items: [] }
    setAttributesMock.mockResolvedValue([])
    const { wrapper, getCoordinator } = mountAssignment(product({ uuid: 'p1' }))
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

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
      expectedRevision: 7,
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
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
    attributesSectionData.value = { revision: 4, items: [] }
    setAttributesMock.mockResolvedValue([])
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

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
      expectedRevision: 4,
    })
  })

  it('an attribute assigned before this session, on a page never visited, survives an unrelated toggle+save round-trip (wipe-class regression)', async () => {
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', slug: 'color', name: 'Color', values: [] })],
      total: 2,
      current_page: 1,
      per_page: 1,
    }
    attributesSectionData.value = {
      revision: 5,
      items: [assignedRow({ attribute_uuid: 'a-offpage', values: ['x'] })],
    }
    setAttributesMock.mockResolvedValue([])
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    // a-offpage was never touched by the user — only a1 (the currently visible page) is included.
    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)
    await wrapper.find('[data-test="attribute-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setAttributesMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      rows: [
        { attribute_uuid: 'a-offpage', values: ['x'], used_for_variants: false, visible: true },
        { attribute_uuid: 'a1', values: [], used_for_variants: false, visible: true },
      ],
      expectedRevision: 5,
    })
  })

  it('adds a custom attribute row and saves it with a name and free-text values, no attribute_uuid', async () => {
    attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
    attributesSectionData.value = { revision: 1, items: [] }
    setAttributesMock.mockResolvedValue([])
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="attribute-assign-custom-add"]').trigger('click')
    await wrapper.find('[data-test="attribute-assign-custom-name"]').setValue('Material')
    await wrapper.find('[data-test="attribute-assign-custom-values"]').setValue('Cotton, Wool')

    await wrapper.find('[data-test="attribute-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setAttributesMock).toHaveBeenCalledWith({
      productUuid: 'p1',
      rows: [
        { name: 'Material', values: ['Cotton', 'Wool'], used_for_variants: false, visible: true },
      ],
      expectedRevision: 1,
    })
  })

  it('removes a custom attribute row before saving', async () => {
    attributesPage.value = { attributes: [], total: 0, current_page: 1, per_page: 24 }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    await wrapper.find('[data-test="attribute-assign-custom-add"]').trigger('click')
    expect(wrapper.find('[data-test="attribute-assign-custom-row"]').exists()).toBe(true)

    await wrapper.find('[data-test="attribute-assign-custom-remove"]').trigger('click')
    expect(wrapper.find('[data-test="attribute-assign-custom-row"]').exists()).toBe(false)
  })

  it('surfaces the composite-conflict "must not reference the same attribute more than once" 422 without discarding the selection', async () => {
    attributesPage.value = {
      attributes: [
        attribute({
          uuid: 'a1',
          name: 'Color',
          values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })],
        }),
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
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

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

  // ── 409 / conflict recovery (Task C3's structured rebase — explicit review, never auto-retry) ──

  it('on a 409, refreshes the section FIRST, then shows an explicit conflict review when the remote content genuinely differs (no automatic retry)', async () => {
    const remote = [assignedRow({ attribute_uuid: 'a1' }), assignedRow({ attribute_uuid: 'a2' })]
    const { wrapper } = await mountAndConflicted(remote, 5)

    expect(setAttributesMock).toHaveBeenCalledTimes(1)
    const conflict = wrapper.find('[data-test="attribute-conflict"]')
    expect(conflict.exists()).toBe(true)
    expect(conflict.text()).toContain('changed elsewhere — review and save again')
    expect(wrapper.find('[data-test="attribute-use-latest"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="attribute-replace-mine"]').exists()).toBe(true)
  })

  it('"Use latest" adopts the remote assignment, clears dirty, and never resubmits', async () => {
    const remote = [assignedRow({ attribute_uuid: 'a1' }), assignedRow({ attribute_uuid: 'a2' })]
    const { wrapper, getState } = await mountAndConflicted(remote, 5)
    const state = getState()

    await wrapper.find('[data-test="attribute-use-latest"]').trigger('click')
    await flushPromises()

    expect(includeCheckbox(wrapper, 'a1').props('modelValue')).toBe(true)
    expect(includeCheckbox(wrapper, 'a2').props('modelValue')).toBe(true) // adopted from remote
    expect(state.dirty.value).toBe(false)
    expect(wrapper.find('[data-test="attribute-conflict"]').exists()).toBe(false)
    expect(setAttributesMock).toHaveBeenCalledTimes(1) // never resubmitted
  })

  it('"Replace with mine" resubmits the LOCAL assignment with the NEW revision, only after explicit confirmation', async () => {
    const remote = [assignedRow({ attribute_uuid: 'a1' }), assignedRow({ attribute_uuid: 'a2' })]
    const { wrapper, getCoordinator } = await mountAndConflicted(remote, 5)
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    expect(setAttributesMock).toHaveBeenCalledTimes(1)

    setAttributesMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="attribute-replace-mine"]').trigger('click')
    await flushPromises()

    expect(setAttributesMock).toHaveBeenCalledTimes(2)
    expect(setAttributesMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      rows: [
        { attribute_uuid: 'a1', values: [], used_for_variants: false, visible: true },
        { attribute_uuid: 'a2', values: [], used_for_variants: false, visible: true },
      ],
      expectedRevision: 5,
    })
    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('a silent rebase (remote assignment unchanged, revision only advanced) keeps the local draft, clears the error, and shows no conflict', async () => {
    const unchanged = [assignedRow({ attribute_uuid: 'a1' })]
    const { wrapper, getState } = await mountAndConflicted(unchanged, 7)
    const state = getState()

    expect(wrapper.find('[data-test="attribute-conflict"]').exists()).toBe(false)
    expect(includeCheckbox(wrapper, 'a1').props('modelValue')).toBe(true) // local draft kept
    expect(includeCheckbox(wrapper, 'a2').props('modelValue')).toBe(true) // local draft kept
    expect(state.dirty.value).toBe(true)
    expect(state.phase.value).toBe('idle') // NOT 'error' — spec §5.2 "show no conflict"
    expect(setAttributesMock).toHaveBeenCalledTimes(1) // no automatic retry

    setAttributesMock.mockResolvedValueOnce([])
    await wrapper.find('[data-test="attribute-assignment-save"]').trigger('click')
    await flushPromises()

    expect(setAttributesMock).toHaveBeenNthCalledWith(2, {
      productUuid: 'p1',
      rows: [
        { attribute_uuid: 'a1', values: [], used_for_variants: false, visible: true },
        { attribute_uuid: 'a2', values: [], used_for_variants: false, visible: true },
      ],
      expectedRevision: 7,
    })
  })

  it('disables the save button while a 409 recovery refresh is in flight', async () => {
    attributesPage.value = {
      attributes: [attribute({ uuid: 'a1', slug: 'color', name: 'Color', values: [] })],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    attributesSectionData.value = { revision: 0, items: [] }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }))

    const checkboxes = wrapper.findAllComponents({ name: 'CheckboxRoot' })
    await checkboxes[0]!.vm.$emit('update:modelValue', true)

    setAttributesMock.mockRejectedValueOnce(new ApiError('Conflict', 409, {}, {}))
    let resolveRefetch: (value: unknown) => void = () => {}
    attributesSectionRefetchMock.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveRefetch = resolve
        }),
    )

    const saveClick = wrapper.find('[data-test="attribute-assignment-save"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.find('[data-test="attribute-assignment-save"]').attributes('disabled'),
    ).toBeDefined()

    resolveRefetch({ status: 'success', data: attributesSectionData.value, error: null })
    await saveClick
    await flushPromises()

    expect(
      wrapper.find('[data-test="attribute-assignment-save"]').attributes('disabled'),
    ).toBeUndefined()
  })

  it('hides the save control and disables checkboxes when can_manage is false', () => {
    attributesPage.value = {
      attributes: [
        attribute({
          uuid: 'a1',
          name: 'Color',
          values: [attributeValue({ uuid: 'v1', value: 'Red', slug: 'red' })],
        }),
      ],
      total: 1,
      current_page: 1,
      per_page: 24,
    }
    const { wrapper } = mountAssignment(product({ uuid: 'p1' }), false)

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
    addonsData.value = [
      addon({ uuid: 'a1', name: 'Gift wrap', field_type: 'checkbox', price_delta: 350 }),
    ]
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
    await wrapper
      .findAllComponents({ name: 'CheckboxRoot' })[0]!
      .vm.$emit('update:modelValue', true)
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
    expect(wrapper.find('[data-test="addon-choices-error"]').text()).toContain(
      'Add at least one choice.',
    )
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
    expect(wrapper.find('[data-test="addon-choices-error"]').text()).toContain(
      'Duplicate choice key',
    )
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
    expect(wrapper.find('[data-test="addon-form-error"]').text()).toContain(
      'Enter a valid price delta',
    )
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
    createAddonMock.mockRejectedValue(
      new ApiError('Validation failed', 422, { name: 'Name is required.' }, {}),
    )
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

    expect((wrapper.find('[data-test="addon-name-input"]').element as HTMLInputElement).value).toBe(
      'Gift wrap',
    )
    expect(
      (wrapper.find('[data-test="addon-price-delta-input"]').element as HTMLInputElement).value,
    ).toBe('3.50')
    expect(
      (wrapper.find('[data-test="addon-position-input"]').element as HTMLInputElement).value,
    ).toBe('1')

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

  // ── Coordinator: every successful add-on mutation awaits afterMutation() exactly once (Task C8) ─
  // Add-on mutations are in C1's invalidation matrix (createAddon/updateAddon/removeAddon already
  // invalidate the owning product + its six section reads), so a save here must also settle the
  // shared page-level revision coordinator. Coordinator is OPTIONAL (`inject(..., null)`) — every
  // test ABOVE mounts this panel with plain `mount()` (no ancestor coordinator) and still passes,
  // proving the `coordinator?.afterMutation()` calls are no-ops without one.

  function mountPanelWithCoordinator(p: CommerceProduct = product({ uuid: 'p1' })) {
    return mountWithEditorContext(
      AddonsPanel,
      { product: p, canManage: true },
      { global: { stubs: { Modal: teleportStub } } },
    )
  }

  it('awaits afterMutation() exactly once after a successful create, never on failure', async () => {
    addonsData.value = []
    createAddonMock.mockResolvedValueOnce(addon({ uuid: 'a1', name: 'Gift wrap' }))
    const { wrapper, getCoordinator } = mountPanelWithCoordinator()
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="addon-add"]').trigger('click')
    await wrapper.find('[data-test="addon-name-input"]').setValue('Gift wrap')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)

    createAddonMock.mockRejectedValueOnce(new ApiError('Validation failed', 422, {}, {}))
    await wrapper.find('[data-test="addon-add"]').trigger('click')
    await wrapper.find('[data-test="addon-name-input"]').setValue('Engraving')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('awaits afterMutation() exactly once after a successful update', async () => {
    addonsData.value = [addon({ uuid: 'a1', name: 'Gift wrap' })]
    updateAddonMock.mockResolvedValue(addon({ uuid: 'a1' }))
    const { wrapper, getCoordinator } = mountPanelWithCoordinator(product({ uuid: 'p1' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="addon-edit"]').trigger('click')
    await wrapper.find('#addon-form').trigger('submit')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('awaits afterMutation() exactly once after a successful delete', async () => {
    addonsData.value = [addon({ uuid: 'a1', name: 'Gift wrap' })]
    removeAddonMock.mockResolvedValue(undefined)
    const { wrapper, getCoordinator } = mountPanelWithCoordinator(product({ uuid: 'p1' }))
    await flushPromises()
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="addon-delete"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="addon-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })
})

// ── DownloadsPanel: per-variant digital-download CRUD — a real per-variant GET (unlike
// Categories/Tags/Attributes' assignment sections), but only fetched once a variant's section is
// expanded (only one at a time, mirroring VariantsPanel's adjustingUuid/editingUuid pattern). ────

describe('DownloadsPanel', () => {
  function mountPanel(
    p: CommerceProduct = product({
      uuid: 'p1',
      type: 'digital',
      variants: [variant({ uuid: 'v1' })],
    }),
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
      product({
        uuid: 'p1',
        type: 'digital',
        variants: [variant({ uuid: 'v1' }), variant({ uuid: 'v2', sku: 'SKU-2' })],
      }),
    )
    expect(wrapper.findAll('[data-test="download-variant-row"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="download-row"]').exists()).toBe(false)
  })

  it('expands a variant and renders each download from the real per-variant GET, with exact limit/expiry text', async () => {
    downloadsData.value = [
      download({ uuid: 'd1', name: 'Ebook (PDF)', download_limit: 3, expiry_days: 30 }),
      download({
        uuid: 'd2',
        name: 'Bonus chapter',
        download_limit: null,
        expiry_days: null,
        status: 'inactive',
      }),
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
    const wrapper = mountPanel(
      product({ uuid: 'p1', type: 'digital', variants: [variant({ uuid: 'v1' })] }),
    )
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
      productUuid: 'p1',
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
      productUuid: 'p1',
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
    expect(wrapper.find('[data-test="download-form-error"]').text()).toContain(
      'Choose a file first.',
    )
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
      download({
        uuid: 'd1',
        name: 'Ebook (PDF)',
        download_limit: 3,
        expiry_days: 30,
        position: 1,
        status: 'active',
      }),
    ]
    updateDownloadMock.mockResolvedValue(download({ uuid: 'd1', status: 'inactive' }))
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)

    await wrapper.find('[data-test="download-edit"]').trigger('click')

    expect(
      (wrapper.find('[data-test="download-name-input"]').element as HTMLInputElement).value,
    ).toBe('Ebook (PDF)')
    expect(
      (wrapper.find('[data-test="download-limit-input"]').element as HTMLInputElement).value,
    ).toBe('3')
    expect(
      (wrapper.find('[data-test="download-expiry-input"]').element as HTMLInputElement).value,
    ).toBe('30')
    expect(
      (wrapper.find('[data-test="download-position-input"]').element as HTMLInputElement).value,
    ).toBe('1')
    // The blob can never change after attach (UpdateDownloadData has no blob_uuid field) — no
    // "choose file" affordance in edit mode.
    expect(wrapper.find('[data-test="download-choose-file"]').exists()).toBe(false)

    selectByTestId(wrapper, 'download-status-input').vm.$emit('update:modelValue', 'inactive')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(updateDownloadMock).toHaveBeenCalledWith({
      uuid: 'd1',
      variantUuid: 'v1',
      productUuid: 'p1',
      input: {
        name: 'Ebook (PDF)',
        download_limit: 3,
        expiry_days: 30,
        position: 1,
        status: 'inactive',
      },
    })
  })

  it('pre-populates blank limit/expiry as empty inputs (unlimited/never), and saves them back as null', async () => {
    downloadsData.value = [
      download({
        uuid: 'd1',
        name: 'Bonus chapter',
        download_limit: null,
        expiry_days: null,
        position: 0,
      }),
    ]
    updateDownloadMock.mockResolvedValue(download({ uuid: 'd1' }))
    const wrapper = mountPanel()
    await expandFirstVariant(wrapper)

    await wrapper.find('[data-test="download-edit"]').trigger('click')
    expect(
      (wrapper.find('[data-test="download-limit-input"]').element as HTMLInputElement).value,
    ).toBe('')
    expect(
      (wrapper.find('[data-test="download-expiry-input"]').element as HTMLInputElement).value,
    ).toBe('')

    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(updateDownloadMock).toHaveBeenCalledWith({
      uuid: 'd1',
      variantUuid: 'v1',
      productUuid: 'p1',
      input: {
        name: 'Bonus chapter',
        download_limit: null,
        expiry_days: null,
        position: 0,
        status: 'active',
      },
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
    expect(removeDownloadMock).toHaveBeenCalledWith({
      uuid: 'd1',
      variantUuid: 'v1',
      productUuid: 'p1',
    })
  })

  // ── Coordinator: every successful download mutation awaits afterMutation() exactly once ────
  // (Task C8 — C1 review "Important" carry-over): `productUuid` was added to attachDownload/
  // updateDownload/removeDownload's vars in Task C1 but never actually passed by this panel until
  // now, so a save here settles the shared page-level revision coordinator the same way every
  // other section mutation on this page does. Coordinator is OPTIONAL (`inject(..., null)`) —
  // every test ABOVE mounts this panel with plain `mount()` (no ancestor coordinator) and still
  // passes, proving the `coordinator?.afterMutation()` calls are no-ops without one.

  function mountPanelWithCoordinator(
    p: CommerceProduct = product({ uuid: 'p1', type: 'digital', variants: [variant({ uuid: 'v1' })] }),
  ) {
    return mountWithEditorContext(
      DownloadsPanel,
      { product: p, canManage: true },
      { global: { stubs: { Modal: teleportStub, MediaPickerModal: MediaPickerModalStub } } },
    )
  }

  it('awaits afterMutation() exactly once after a successful attach, never on failure', async () => {
    downloadsData.value = []
    attachDownloadMock.mockResolvedValueOnce(download({ uuid: 'new-d', name: 'Ebook (PDF)' }))
    const { wrapper, getCoordinator } = mountPanelWithCoordinator()
    await expandFirstVariant(wrapper)
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="download-add"]').trigger('click')
    await pickAFile(wrapper)
    await wrapper.find('[data-test="download-name-input"]').setValue('Ebook (PDF)')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)

    attachDownloadMock.mockRejectedValueOnce(new ApiError('Validation failed', 422, {}, {}))
    await wrapper.find('[data-test="download-add"]').trigger('click')
    await pickAFile(wrapper)
    await wrapper.find('[data-test="download-name-input"]').setValue('Ebook 2')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('awaits afterMutation() exactly once after a successful update', async () => {
    downloadsData.value = [download({ uuid: 'd1', name: 'Ebook (PDF)' })]
    updateDownloadMock.mockResolvedValue(download({ uuid: 'd1' }))
    const { wrapper, getCoordinator } = mountPanelWithCoordinator()
    await expandFirstVariant(wrapper)
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="download-edit"]').trigger('click')
    await wrapper.find('#download-form').trigger('submit')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })

  it('awaits afterMutation() exactly once after a successful detach', async () => {
    downloadsData.value = [download({ uuid: 'd1', name: 'Ebook (PDF)' })]
    removeDownloadMock.mockResolvedValue(undefined)
    const { wrapper, getCoordinator } = mountPanelWithCoordinator()
    await expandFirstVariant(wrapper)
    const afterMutationSpy = vi.spyOn(getCoordinator(), 'afterMutation')

    await wrapper.find('[data-test="download-delete"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="download-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(afterMutationSpy).toHaveBeenCalledTimes(1)
  })
})
