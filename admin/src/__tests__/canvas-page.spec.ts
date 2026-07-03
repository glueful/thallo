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

const { mintMock, applyMock } = vi.hoisted(() => ({ mintMock: vi.fn(), applyMock: vi.fn() }))
vi.mock('@/queries/preview', () => ({ mintPreviewData: mintMock, applyPreview: applyMock }))

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

// The REAL composable is covered by canvas-bridge.spec — the page suite asserts
// wiring only: intents in via captured callbacks, mirrors out via spies.
const bridge = vi.hoisted(() => {
  const callbacks: {
    select?: (id: string) => void
    hover?: (id: string) => void
    index?: (ids: string[]) => void
    move?: (id: string, d: 1 | -1) => void
    duplicate?: (id: string) => void
    deleteRequest?: (id: string) => void
    addAfter?: (id: string) => void
  } = {}
  return {
    callbacks,
    instance: {
      nonce: 'n',
      hello: vi.fn(),
      onBlockSelect: (cb: (id: string) => void) => (callbacks.select = cb),
      onBlockHover: (cb: (id: string) => void) => (callbacks.hover = cb),
      onBlocksIndex: (cb: (ids: string[]) => void) => (callbacks.index = cb),
      onBlockMove: (cb: (id: string, d: 1 | -1) => void) => (callbacks.move = cb),
      onBlockDuplicate: (cb: (id: string) => void) => (callbacks.duplicate = cb),
      onBlockDeleteRequest: (cb: (id: string) => void) => (callbacks.deleteRequest = cb),
      onBlockAddAfter: (cb: (id: string) => void) => (callbacks.addAfter = cb),
      highlight: vi.fn(),
      scrollTo: vi.fn(),
      mirrorMove: vi.fn(),
      mirrorRemove: vi.fn(),
      mirrorDuplicate: vi.fn(),
      dispose: vi.fn(),
    },
  }
})
vi.mock('@/composables/useCanvasBridge', () => ({ useCanvasBridge: () => bridge.instance }))

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
    fields: {
      title: 'T',
      body: [
        { id: 'blockaaa0001', type: 'card', data: { title: 'A' } },
        { id: 'blockbbb0002', type: 'card', data: { title: 'B' } },
      ],
    },
    lock_version: 3,
  }
  mintMock.mockReset()
  applyMock.mockReset()
  saveMock.mockReset()
  notify.warning.mockReset()
  notify.success.mockReset()
  bridge.instance.mirrorMove.mockClear()
  bridge.instance.mirrorRemove.mockClear()
  bridge.instance.mirrorDuplicate.mockClear()
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

  it('Save draft saves with lock_version and does NOT re-mint or reload the stage', async () => {
    mintMock.mockResolvedValue({ token: 't1', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(saveMock).toHaveBeenCalledWith(expect.objectContaining({ lock_version: 3 }))
    expect(mintMock).toHaveBeenCalledTimes(1) // mount only — save never re-mints
    expect(wrapper.find('[data-test="canvas-iframe"]').element).toBe(before) // no reload
    wrapper.unmount()
  })

  it('Apply posts token+fields, reloads the SAME stage URL, no re-mint', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()
    expect(applyMock).toHaveBeenCalledWith(
      'entry0000001',
      'en',
      'tok1',
      expect.objectContaining({ title: 'T' }),
    )
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1') // SAME URL
    expect(iframe.element).not.toBe(before) // remounted -> reloaded
    expect(mintMock).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('Apply on a dead token re-mints ONCE and retries', async () => {
    mintMock
      .mockResolvedValueOnce({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
      .mockResolvedValueOnce({ token: 'tok2', themeUrl: 'https://site.test/_preview/tok2' })
    applyMock
      .mockRejectedValueOnce(new ApiError('expired', 410, {}, { success: false }))
      .mockResolvedValueOnce(undefined)
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()
    expect(mintMock).toHaveBeenCalledTimes(2)
    expect(applyMock).toHaveBeenCalledTimes(2)
    expect(applyMock).toHaveBeenLastCalledWith('entry0000001', 'en', 'tok2', expect.anything())
    wrapper.unmount()
  })

  it('Apply surfaces the migration 409 with the editor-mirror banner', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockRejectedValueOnce(
      new ApiError("block type 'card' has a migration in progress", 409, {}, {
        success: false,
        error: { code: 409, details: { code: 'BLOCK_MIGRATION_IN_PROGRESS', block_type: 'card' } },
      }),
    )
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(notify.warning).toHaveBeenCalledWith(
      'Block type “card” is being migrated',
      expect.any(String),
    )
    wrapper.unmount()
  })

  it('Apply failure resets the stage (mirror DOM discarded) and keeps dirty fields', async () => {
    // Review P1: a rejected Apply wrote NO stash — optimistic mirrors from the
    // stage toolbar must not survive as if they were applied.
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockRejectedValueOnce(
      new ApiError('validation failed', 422, {}, { success: false }),
    )
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    // Structural op first (the mirror-then-reject scenario).
    bridge.callbacks.move?.('blockaaa0001', 1)
    await flushPromises()

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()

    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1') // SAME URL
    expect(iframe.element).not.toBe(before) // remounted -> mirror DOM discarded
    expect(mintMock).toHaveBeenCalledTimes(1) // no re-mint on failure
    // Dirty local fields survive: a retry save still submits the MOVED order.
    saveMock.mockResolvedValue(undefined)
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(saveMock).toHaveBeenLastCalledWith(
      expect.objectContaining({
        fields: expect.objectContaining({
          body: [
            expect.objectContaining({ id: 'blockbbb0002' }),
            expect.objectContaining({ id: 'blockaaa0001' }),
          ],
        }),
      }),
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

  it('move intent mutates the tree and posts mirror-move; boundary posts nothing', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.move?.('blockaaa0001', 1)
    await flushPromises()
    expect(bridge.instance.mirrorMove).toHaveBeenCalledWith('blockaaa0001', {
      afterId: 'blockbbb0002',
    })

    bridge.instance.mirrorMove.mockClear()
    bridge.callbacks.move?.('blockaaa0001', 1) // now last -> boundary no-op
    await flushPromises()
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('delete intent needs the parent-side confirm; cancel does nothing', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.deleteRequest?.('blockaaa0001')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-delete-confirm"]').exists()).toBe(true)
    expect(bridge.instance.mirrorRemove).not.toHaveBeenCalled()

    await wrapper.find('[data-test="canvas-delete-cancel"]').trigger('click')
    expect(wrapper.find('[data-test="canvas-delete-confirm"]').exists()).toBe(false)
    expect(bridge.instance.mirrorRemove).not.toHaveBeenCalled()

    bridge.callbacks.deleteRequest?.('blockaaa0001')
    await flushPromises()
    await wrapper.find('[data-test="canvas-delete-confirm-yes"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.mirrorRemove).toHaveBeenCalledWith('blockaaa0001')
    wrapper.unmount()
  })

  it('duplicate intent posts mirror-duplicate with the idMap and selects the copy', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.duplicate?.('blockaaa0001')
    await flushPromises()
    expect(bridge.instance.mirrorDuplicate).toHaveBeenCalledWith(
      'blockaaa0001',
      expect.objectContaining({ blockaaa0001: expect.any(String) }),
    )
    wrapper.unmount()
  })

  it('add-after opens the per-list picker; choosing inserts, selects, and posts NO mirror', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    const picker = wrapper.find('[data-test="canvas-add-picker"]')
    expect(picker.exists()).toBe(true)
    await picker.find('[data-test="canvas-add-type-card"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled()
    expect(bridge.instance.mirrorDuplicate).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="canvas-add-picker"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('save failure reloads the SAME iframe URL without re-minting, keeping dirty fields', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    // Make the tree dirty via a structural op (the mirror-then-fail scenario).
    bridge.callbacks.move?.('blockaaa0001', 1)
    await flushPromises()

    saveMock.mockRejectedValueOnce(new ApiError('conflict', 409, {}, { success: false }))
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    await flushPromises()

    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1') // SAME URL
    expect(iframe.element).not.toBe(before) // remounted -> reloaded
    expect(mintMock).toHaveBeenCalledTimes(1) // NO re-mint on failure
    expect(notify.warning).toHaveBeenCalled() // banner still shows
    // Pinned product rule: local edits SURVIVE the stage reset. Assert
    // behaviorally (no Nuxt UI internals): a retry save still submits the
    // MOVED order — the failed save discarded nothing.
    saveMock.mockResolvedValue(undefined)
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(saveMock).toHaveBeenLastCalledWith(
      expect.objectContaining({
        fields: expect.objectContaining({
          body: [
            expect.objectContaining({ id: 'blockbbb0002' }),
            expect.objectContaining({ id: 'blockaaa0001' }),
          ],
        }),
      }),
    )
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
