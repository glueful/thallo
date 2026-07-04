import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { ContentType } from '@/queries/contentTypes'
import { ApiError } from '@/api/errors'

const typeData = ref<ContentType | undefined>(undefined)
const updateMetaMock = vi.fn()
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/queries/contentTypes', () => ({
  useContentType: () => ({ data: typeData, status: ref('success'), error: ref(null) }),
  useContentTypes: () => ({ data: ref([]), status: ref('success'), error: ref(null) }),
  useContentTypeMutations: () => ({
    updateMeta: { mutateAsync: updateMetaMock, isLoading: ref(false) },
    updateSchema: { mutateAsync: vi.fn(), isLoading: ref(false) },
    remove: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
  validateContentTypeFields: () => null,
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: notify.success, error: notify.error }),
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useRoute: () => ({ path: '/settings/content-types/pages', params: { slug: 'pages' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/settings/content-types/pages', params: { slug: 'pages' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
  // UButton's `to` renders through the Link override, which resolves RouterLink.
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))

import ContentTypeEditor from '@/pages/settings/content-types/[slug].vue'

const pagesType = (): ContentType => ({
  slug: 'pages',
  name: 'Pages',
  description: null,
  cache_ttl: null,
  public_delivery: true,
  mount_at_root: false,
  status: 'active',
  schema: [],
  schema_version: 1,
  updated_at: null,
})

describe('content-type editor toggles', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    typeData.value = pagesType()
    updateMetaMock.mockReset()
    notify.success.mockClear()
    notify.error.mockClear()
  })

  it('PATCHes mount_at_root when the toggle flips', async () => {
    updateMetaMock.mockResolvedValue({ ...pagesType(), mount_at_root: true })
    const wrapper = mount(ContentTypeEditor)
    await flushPromises()

    await wrapper.find('[data-test="mount-at-root-toggle"]').trigger('click')
    await flushPromises()

    expect(updateMetaMock).toHaveBeenCalledWith({ slug: 'pages', meta: { mount_at_root: true } })
    expect(notify.success).toHaveBeenCalled()
  })

  it('a 409 conflict reverts the switch and surfaces the error', async () => {
    updateMetaMock.mockRejectedValue(
      new ApiError("Cannot mount at root: 'v1' is a reserved path segment", 409, {}, null),
    )
    const wrapper = mount(ContentTypeEditor)
    await flushPromises()

    const toggle = wrapper.find('[data-test="mount-at-root-toggle"]')
    await toggle.trigger('click')
    await flushPromises()

    expect(notify.error).toHaveBeenCalled()
    // The switch reverted: the flag never flips partially server-side.
    expect(toggle.attributes('aria-checked')).toBe('false')
  })

  it('PATCHes public_delivery from its own toggle', async () => {
    updateMetaMock.mockResolvedValue({ ...pagesType(), public_delivery: false })
    const wrapper = mount(ContentTypeEditor)
    await flushPromises()

    await wrapper.find('[data-test="public-delivery-toggle"]').trigger('click')
    await flushPromises()

    expect(updateMetaMock).toHaveBeenCalledWith({ slug: 'pages', meta: { public_delivery: false } })
  })
})
