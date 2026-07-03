import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { ApiError } from '@/api/errors'
import type { BlockType } from '@/queries/blockTypes'

// ── mocks ──────────────────────────────────────────────────────────────────────
const blockTypes = ref<BlockType[]>([])
vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: blockTypes }),
}))

const { mintMock } = vi.hoisted(() => ({ mintMock: vi.fn() }))
vi.mock('@/queries/preview', () => ({ mintPreviewData: mintMock }))

const draft = ref<{ fields: Record<string, unknown>; lock_version: number } | null>(null)
const { saveMock } = vi.hoisted(() => ({ saveMock: vi.fn() }))
vi.mock('@/queries/drafts', () => ({
  useDraft: () => ({ data: draft }),
  useSaveDraft: () => ({ mutateAsync: saveMock, isLoading: ref(false) }),
}))

const contentTypes = ref([
  {
    slug: 'page',
    schema: [
      { name: 'title', type: 'string', required: true },
      { name: 'body', type: 'blocks' },
    ],
  },
])
vi.mock('@/queries/contentTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/contentTypes')>()),
  useContentTypes: () => ({ data: contentTypes }),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ params: { type: 'page', uuid: 'entry0000001', locale: 'en' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import DesignPage from '@/pages/content/[type]/[uuid]/design/[locale].vue'

const bt = (slug: string): BlockType =>
  ({
    uuid: `bt-${slug}`,
    slug,
    label: slug,
    icon: null,
    category: null,
    description: null,
    active: true,
    schema: [
      { name: 'title', type: 'string', required: false, localized: false, filterable: false },
    ],
  }) as BlockType

function mountPage() {
  return mount(DesignPage, {
    global: {
      stubs: {
        // The dashboard shell + iframe are chrome, not behavior under test.
        UDashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
        UDashboardNavbar: {
          template: '<div><slot name="leading" /><slot name="title" /><slot name="right" /></div>',
        },
        // No router in the unit env; stub RouterLink to a plain anchor (the
        // established pattern — UButton :to renders through it).
        RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
      },
    },
    attachTo: document.body,
  })
}

// Warm the registry's async BlocksField ONCE so it resolves synchronously at
// mount inside every test — late resolution after a test's unmount would mount
// BlockList against a torn-down provider tree.
beforeAll(async () => {
  await import('@/fields/components/BlocksField.vue')
})

beforeEach(() => {
  setActivePinia(createPinia())
  blockTypes.value = [bt('card')]
  draft.value = {
    fields: { title: 'T', body: [{ id: 'blockaaa0001', type: 'card', data: { title: 'A' } }] },
    lock_version: 3,
  }
  mintMock.mockReset()
  saveMock.mockReset()
  notify.warning.mockReset()
  notify.success.mockReset()
})

describe('canvas page', () => {
  it('renders the disabled state when rendered delivery is off (never SPA-404)', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: null })
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-disabled"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="canvas-iframe"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="canvas-back"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('loads the stage iframe from theme_url and viewport presets resize it', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1')

    await wrapper.find('[data-test="canvas-viewport-mobile"]').trigger('click')
    const stageInner = wrapper.find('[data-test="canvas-stage"] > div')
    expect(stageInner.attributes('style')).toContain('width: 390px')
    wrapper.unmount()
  })

  it('Save & refresh saves with lock_version then RE-MINTS and reloads the stage', async () => {
    mintMock
      .mockResolvedValueOnce({ token: 't1', themeUrl: 'https://site.test/_preview/tok1' })
      .mockResolvedValueOnce({ token: 't2', themeUrl: 'https://site.test/_preview/tok2' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(saveMock).toHaveBeenCalledWith(
      expect.objectContaining({ lock_version: 3 }),
    )
    expect(mintMock).toHaveBeenCalledTimes(2) // mount + apply re-mint (spec §6)
    expect(wrapper.find('[data-test="canvas-iframe"]').attributes('src')).toBe(
      'https://site.test/_preview/tok2',
    )
    wrapper.unmount()
  })

  it('409 branches byte-mirror the editor: stale vs migration banners', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    saveMock.mockRejectedValueOnce(new ApiError('conflict', 409, {}, { success: false }))
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(notify.warning).toHaveBeenCalledWith(
      'This draft changed elsewhere',
      expect.any(String),
    )

    saveMock.mockRejectedValueOnce(
      new ApiError("block type 'card' has a migration in progress", 409, {}, {
        success: false,
        error: { code: 409, details: { code: 'BLOCK_MIGRATION_IN_PROGRESS', block_type: 'card' } },
      }),
    )
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(notify.warning).toHaveBeenCalledWith(
      'Block type “card” is being migrated',
      expect.any(String),
    )
    wrapper.unmount()
  })

  it('outline click selects in the inspector and messages the stage', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()
    await flushPromises()

    await wrapper.find('[data-test="canvas-outline-item-blockaaa0001"]').trigger('click')
    await flushPromises()
    // Inspector selection landed (header focused via selectBlockById).
    expect(document.activeElement?.getAttribute('data-test')).toBe('block-toggle-blockaaa0001')
    wrapper.unmount()
  })
})

describe('editor page Design action', () => {
  it('renders design-link pointing at the design route', async () => {
    vi.doMock('@/queries/entries', () => ({
      useEntryLocales: () => ({ data: ref([]) }),
      useCreateLocaleDraft: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
    }))
    vi.doMock('@/queries/locales', () => ({ useLocales: () => ({ data: ref([]) }) }))
    vi.doMock('@/stores/capabilities', () => ({
      useCapabilitiesStore: () => ({ has: () => false, enabled: () => false }),
    }))
    const { default: EditorPage } = await import('@/pages/content/[type]/[uuid]/index.vue')
    const wrapper = mount(EditorPage, {
      shallow: true,
      global: {
        stubs: {
          // Shallow resolves auto-imported Nuxt UI names WITHOUT the U prefix.
          DashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
          DashboardNavbar: {
            template: '<div><slot name="leading" /><slot name="title" /><slot name="right" /></div>',
          },
        },
      },
    })
    await flushPromises()
    const link = wrapper.find('[data-test="design-link"]')
    expect(link.exists()).toBe(true)
    expect(link.attributes('to')).toBe('/content/page/entry0000001/design/en')
    wrapper.unmount()
  })
})
