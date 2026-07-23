import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { ApiError } from '@/api/errors'
import type { CommerceProduct } from '@/queries/commerceCatalog'
import type {
  CommerceProductLink,
  EntrySearchResult,
  ProductLinkProjection,
} from '@/queries/commerceLinking'

// ── Shared mock state (referenced inside vi.mock factories) — real Vue refs, plain consts
// (mirrors commerceProducts.spec.ts's own precedent/rationale for why NOT vi.hoisted() here). ──

const metaData = ref({
  currency: 'USD',
  currency_exponent: 2,
  shop_index_url: '',
  low_stock_threshold: 0,
  can_view: true,
  can_manage: true,
})
vi.mock('@/queries/commerceMeta', () => ({
  useCommerceMeta: () => ({ data: metaData }),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

const linkedProductData = ref<CommerceProduct | undefined>(undefined)
vi.mock('@/queries/commerceCatalog', () => ({
  useCommerceProduct: () => ({ data: linkedProductData, status: ref('success') }),
}))

const productLinkData = ref<ProductLinkProjection | undefined>(undefined)
const productLinkStatus = ref<'pending' | 'error' | 'success'>('success')
const entryLinkData = ref<CommerceProductLink | null | undefined>(undefined)
const entryLinkStatus = ref<'pending' | 'error' | 'success'>('success')
const entrySearchResults = ref<EntrySearchResult[] | undefined>(undefined)
const productSearchResults = ref<CommerceProduct[] | undefined>(undefined)
const linkMock = vi.hoisted(() => vi.fn())
const unlinkMock = vi.hoisted(() => vi.fn())
const refetchProductLinkMock = vi.hoisted(() => vi.fn())
const refetchEntryLinkMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/commerceLinking', () => ({
  useProductLink: () => ({
    data: productLinkData,
    status: productLinkStatus,
    refetch: refetchProductLinkMock,
  }),
  useEntryLink: () => ({
    data: entryLinkData,
    status: entryLinkStatus,
    refetch: refetchEntryLinkMock,
  }),
  useEntrySearch: () => ({ data: entrySearchResults }),
  useProductSearchForLink: () => ({ data: productSearchResults }),
  useCommerceLinkMutations: () => ({
    link: { mutateAsync: linkMock, isLoading: ref(false) },
    unlink: { mutateAsync: unlinkMock, isLoading: ref(false) },
  }),
}))

import ProductEntryLinkPanel from '@/components/commerce/ProductEntryLinkPanel.vue'

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }

function product(overrides: Partial<CommerceProduct> = {}): CommerceProduct {
  return {
    uuid: 'prod1',
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
    ...overrides,
  }
}

function entry(overrides: Partial<EntrySearchResult> = {}): EntrySearchResult {
  return {
    uuid: 'entry1',
    title: 'About Us',
    content_type: 'page',
    status: 'draft',
    locale: 'en',
    ...overrides,
  }
}

function link(overrides: Partial<CommerceProductLink> = {}): CommerceProductLink {
  return {
    uuid: 'link1',
    product_uuid: 'prod1',
    entry_uuid: 'entry1',
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-01 00:00:00',
    ...overrides,
  }
}

beforeEach(() => {
  metaData.value = {
    currency: 'USD',
    currency_exponent: 2,
    shop_index_url: '',
    low_stock_threshold: 0,
    can_view: true,
    can_manage: true,
  }
  linkedProductData.value = undefined
  productLinkData.value = undefined
  productLinkStatus.value = 'success'
  entryLinkData.value = undefined
  entryLinkStatus.value = 'success'
  entrySearchResults.value = undefined
  productSearchResults.value = undefined
  linkMock.mockReset()
  unlinkMock.mockReset()
  refetchProductLinkMock.mockReset()
  refetchEntryLinkMock.mockReset()
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

function mountProduct(productUuid = 'prod1') {
  return mount(ProductEntryLinkPanel, {
    props: { mode: 'product', productUuid },
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
}

function mountEntry(entryUuid = 'entry1') {
  return mount(ProductEntryLinkPanel, {
    props: { mode: 'entry', entryUuid },
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
}

// ── Product mode ─────────────────────────────────────────────────────────────────────────────

describe('ProductEntryLinkPanel — product mode', () => {
  it('shows the loading state while the link query is pending', () => {
    productLinkStatus.value = 'pending'
    const wrapper = mountProduct()
    expect(wrapper.find('[data-test="link-loading"]').exists()).toBe(true)
  })

  it('shows a generic error state without revealing why', () => {
    productLinkStatus.value = 'error'
    const wrapper = mountProduct()
    expect(wrapper.find('[data-test="link-error"]').exists()).toBe(true)
  })

  it('shows "not linked" and the preview anchor pointing at storefront_url verbatim', () => {
    productLinkData.value = {
      product_uuid: 'prod1',
      storefront_url: 'https://shop.test/shop/products/widget',
      link: null,
    }
    const wrapper = mountProduct()

    expect(wrapper.find('[data-test="link-none"]').exists()).toBe(true)
    const preview = wrapper.find('[data-test="link-preview"]')
    expect(preview.exists()).toBe(true)
    expect(preview.attributes('href')).toBe('https://shop.test/shop/products/widget')
  })

  it('shows the linked entry by uuid when its title has never been observed this session', () => {
    productLinkData.value = {
      product_uuid: 'prod1',
      storefront_url: 'https://shop.test/shop/products/widget',
      link: link({ entry_uuid: 'entryX' }),
    }
    const wrapper = mountProduct()

    expect(wrapper.find('[data-test="link-current-unknown"]').text()).toContain('entryX')
  })

  it('hides search/link/unlink controls when can_manage is false, keeping state + preview visible', () => {
    metaData.value = { ...metaData.value, can_manage: false }
    productLinkData.value = {
      product_uuid: 'prod1',
      storefront_url: 'https://shop.test/shop/products/widget',
      link: link({ entry_uuid: 'entryX' }),
    }
    const wrapper = mountProduct()

    expect(wrapper.find('[data-test="link-search-section"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="link-set"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="link-relink"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="link-unlink"]').exists()).toBe(false)
    // Read-only: state and preview stay visible.
    expect(wrapper.find('[data-test="link-current-unknown"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="link-preview"]').exists()).toBe(true)
  })

  it('links a fresh (unlinked) product directly, with no confirm step', async () => {
    productLinkData.value = {
      product_uuid: 'prod1',
      storefront_url: 'https://shop.test/x',
      link: null,
    }
    entrySearchResults.value = [entry({ uuid: 'entryA', title: 'About Us' })]
    linkMock.mockResolvedValue(link({ entry_uuid: 'entryA' }))
    const wrapper = mountProduct()

    await wrapper.find('[data-test="entry-search-result"]').trigger('click')
    expect(wrapper.find('[data-test="link-set"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="link-relink"]').exists()).toBe(false)

    await wrapper.find('[data-test="link-set"]').trigger('click')
    await flushPromises()

    expect(linkMock).toHaveBeenCalledWith({ productUuid: 'prod1', entryUuid: 'entryA' })
    expect(wrapper.find('[data-test="relink-confirm"]').exists()).toBe(false)
  })

  it('requires an explicit relink confirm before replacing an existing link, submitting expected_entry_uuid', async () => {
    productLinkData.value = {
      product_uuid: 'prod1',
      storefront_url: 'https://shop.test/x',
      link: link({ entry_uuid: 'entryOld' }),
    }
    entrySearchResults.value = [entry({ uuid: 'entryNew', title: 'New Landing Page' })]
    linkMock.mockResolvedValue(link({ entry_uuid: 'entryNew' }))
    const wrapper = mountProduct()

    await wrapper.find('[data-test="entry-search-result"]').trigger('click')
    expect(wrapper.find('[data-test="link-relink"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="link-set"]').exists()).toBe(false)

    await wrapper.find('[data-test="link-relink"]').trigger('click')
    const confirm = wrapper.find('[data-test="relink-confirm"]')
    expect(confirm.exists()).toBe(true)
    expect(confirm.text()).toContain('entryOld')
    expect(confirm.text()).toContain('New Landing Page')
    expect(linkMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="relink-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(linkMock).toHaveBeenCalledWith({
      productUuid: 'prod1',
      entryUuid: 'entryNew',
      expectedEntryUuid: 'entryOld',
      previousEntryUuid: 'entryOld',
    })
    expect(wrapper.find('[data-test="relink-confirm"]').exists()).toBe(false)
  })

  it('a 409 during relink shows link-conflict, and its refresh action re-fetches the link', async () => {
    productLinkData.value = {
      product_uuid: 'prod1',
      storefront_url: 'https://shop.test/x',
      link: link({ entry_uuid: 'entryOld' }),
    }
    entrySearchResults.value = [entry({ uuid: 'entryNew', title: 'New Landing Page' })]
    linkMock.mockRejectedValue(new ApiError('Conflict', 409, {}, {}))
    const wrapper = mountProduct()

    await wrapper.find('[data-test="entry-search-result"]').trigger('click')
    await wrapper.find('[data-test="link-relink"]').trigger('click')
    await wrapper.find('[data-test="relink-confirm-submit"]').trigger('click')
    await flushPromises()

    const conflict = wrapper.find('[data-test="link-conflict"]')
    expect(conflict.exists()).toBe(true)
    expect(conflict.text()).toContain('The link changed underneath you.')
    expect(wrapper.find('[data-test="relink-confirm"]').exists()).toBe(false)

    await wrapper.find('[data-test="link-conflict-refresh"]').trigger('click')
    await flushPromises()

    expect(refetchProductLinkMock).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="link-conflict"]').exists()).toBe(false)
  })

  it('requires confirmation before unlinking, then submits with the current entry uuid', async () => {
    productLinkData.value = {
      product_uuid: 'prod1',
      storefront_url: 'https://shop.test/x',
      link: link({ entry_uuid: 'entryOld' }),
    }
    unlinkMock.mockResolvedValue(undefined)
    const wrapper = mountProduct()

    expect(wrapper.find('[data-test="unlink-confirm"]').exists()).toBe(false)
    await wrapper.find('[data-test="link-unlink"]').trigger('click')
    expect(wrapper.find('[data-test="unlink-confirm"]').exists()).toBe(true)
    expect(unlinkMock).not.toHaveBeenCalled()

    await wrapper.find('[data-test="link-unlink-confirm"]').trigger('click')
    await flushPromises()

    expect(unlinkMock).toHaveBeenCalledWith({ productUuid: 'prod1', entryUuid: 'entryOld' })
  })
})

// ── Entry mode ───────────────────────────────────────────────────────────────────────────────

describe('ProductEntryLinkPanel — entry mode', () => {
  it('shows the linked product with a working link to its detail page', () => {
    entryLinkData.value = link({ product_uuid: 'prodX', entry_uuid: 'entry1' })
    linkedProductData.value = product({ uuid: 'prodX', name: 'Widget' })
    const wrapper = mountEntry()

    expect(wrapper.find('[data-test="link-current"]').text()).toContain('Widget')
    const detailLink = wrapper.find('[data-test="link-product-detail"]')
    expect(detailLink.attributes('href')).toBe('/commerce/products/prodX')
  })

  it('shows "not linked" when the entry has no product link', () => {
    entryLinkData.value = null
    const wrapper = mountEntry()
    expect(wrapper.find('[data-test="link-none"]').exists()).toBe(true)
  })

  it('links directly to a searched product when the entry is not currently linked', async () => {
    entryLinkData.value = null
    productSearchResults.value = [product({ uuid: 'prodNew', name: 'Gadget' })]
    linkMock.mockResolvedValue(link({ product_uuid: 'prodNew' }))
    const wrapper = mountEntry('entry1')

    await wrapper.find('[data-test="product-search-result"]').trigger('click')
    await wrapper.find('[data-test="link-set"]').trigger('click')
    await flushPromises()

    expect(linkMock).toHaveBeenCalledWith({ productUuid: 'prodNew', entryUuid: 'entry1' })
  })

  it('moving to a different product requires confirmation, unlinking the old product then linking the new one', async () => {
    entryLinkData.value = link({ product_uuid: 'prodOld', entry_uuid: 'entry1' })
    linkedProductData.value = product({ uuid: 'prodOld', name: 'Old Product' })
    productSearchResults.value = [product({ uuid: 'prodNew', name: 'New Product' })]
    unlinkMock.mockResolvedValue(undefined)
    linkMock.mockResolvedValue(link({ product_uuid: 'prodNew', entry_uuid: 'entry1' }))
    const wrapper = mountEntry('entry1')

    await wrapper.find('[data-test="product-search-result"]').trigger('click')
    expect(wrapper.find('[data-test="link-relink"]').exists()).toBe(true)

    await wrapper.find('[data-test="link-relink"]').trigger('click')
    const confirm = wrapper.find('[data-test="relink-confirm"]')
    expect(confirm.text()).toContain('Old Product')
    expect(confirm.text()).toContain('New Product')

    await wrapper.find('[data-test="relink-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(unlinkMock).toHaveBeenCalledWith({ productUuid: 'prodOld', entryUuid: 'entry1' })
    expect(linkMock).toHaveBeenCalledWith({ productUuid: 'prodNew', entryUuid: 'entry1' })
    const unlinkOrder = unlinkMock.mock.invocationCallOrder[0]!
    const linkOrder = linkMock.mock.invocationCallOrder[0]!
    expect(unlinkOrder).toBeLessThan(linkOrder)
  })

  it('messages the partial state honestly when the unlink succeeds but the follow-up link fails', async () => {
    entryLinkData.value = link({ product_uuid: 'prodOld', entry_uuid: 'entry1' })
    linkedProductData.value = product({ uuid: 'prodOld', name: 'Old Product' })
    productSearchResults.value = [product({ uuid: 'prodNew', name: 'New Product' })]
    unlinkMock.mockResolvedValue(undefined)
    linkMock.mockRejectedValue(new ApiError('conflict', 409, {}, {}))
    const wrapper = mountEntry('entry1')

    await wrapper.find('[data-test="product-search-result"]').trigger('click')
    await wrapper.find('[data-test="link-relink"]').trigger('click')
    await wrapper.find('[data-test="relink-confirm-submit"]').trigger('click')
    await flushPromises()

    // The entry is genuinely unlinked now — the panel must say SO, and must NOT claim a
    // concurrent change (the misleading 409 copy) for this move-specific partial state.
    const notice = wrapper.find('[data-test="link-move-incomplete"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('New Product')
    expect(wrapper.find('[data-test="link-conflict"]').exists()).toBe(false)
  })

  it('hides mutation controls when can_manage is false, keeping the linked-product state visible', () => {
    metaData.value = { ...metaData.value, can_manage: false }
    entryLinkData.value = link({ product_uuid: 'prodX', entry_uuid: 'entry1' })
    linkedProductData.value = product({ uuid: 'prodX', name: 'Widget' })
    const wrapper = mountEntry()

    expect(wrapper.find('[data-test="link-search-section"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="link-unlink"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="link-current"]').exists()).toBe(true)
  })
})
