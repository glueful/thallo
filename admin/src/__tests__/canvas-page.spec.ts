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

// Mounting a real UEditor in jsdom is out of harness scope (recorded rule):
// the prose fixture block would otherwise render TipTap inside the inspector.
vi.mock('@/fields/components/blocks/ProseBlockEditor.vue', () => ({
  default: {
    name: 'ProseBlockEditor',
    props: ['modelValue', 'placeholder', 'pickerTypes'],
    emits: ['update:modelValue', 'insert-block'],
    template: '<div data-test="prose-editor-stub" />',
  },
}))

// The REAL composable is covered by canvas-bridge.spec — the page suite asserts
// wiring only: intents in via captured callbacks, mirrors out via spies.
const bridge = vi.hoisted(() => {
  const callbacks: {
    select?: (id: string) => void
    hover?: (id: string) => void
    index?: (ids: string[]) => void
    deselect?: (id: string) => void
    move?: (id: string, d: 1 | -1) => void
    moveTo?: (id: string, neighbor: { beforeId: string } | { afterId: string }) => void
    duplicate?: (id: string) => void
    deleteRequest?: (id: string, anchor?: { x: number; y: number } | null) => void
    addAfter?: (id: string, anchor?: { x: number; y: number } | null) => void
    editRequest?: (id: string, field: string) => void
    textChanged?: (id: string, field: string, payload: { html?: string; text?: string }) => void
    editStart?: (id: string) => void
    editEnd?: (id: string) => void
    scroll?: (y: number) => void
  } = {}
  return {
    callbacks,
    instance: {
      nonce: 'n',
      hello: vi.fn(),
      onBlockSelect: (cb: (id: string) => void) => (callbacks.select = cb),
      onBlockDeselect: (cb: (id: string) => void) => (callbacks.deselect = cb),
      onBlockHover: (cb: (id: string) => void) => (callbacks.hover = cb),
      onBlocksIndex: (cb: (ids: string[]) => void) => (callbacks.index = cb),
      onBlockMove: (cb: (id: string, d: 1 | -1) => void) => (callbacks.move = cb),
      onBlockMoveTo: (
        cb: (id: string, neighbor: { beforeId: string } | { afterId: string }) => void,
      ) => (callbacks.moveTo = cb),
      onBlockDuplicate: (cb: (id: string) => void) => (callbacks.duplicate = cb),
      onBlockDeleteRequest: (
        cb: (id: string, anchor?: { x: number; y: number } | null) => void,
      ) => (callbacks.deleteRequest = cb),
      onBlockAddAfter: (cb: (id: string, anchor?: { x: number; y: number } | null) => void) =>
        (callbacks.addAfter = cb),
      onEditRequest: (cb: (id: string, field: string) => void) => (callbacks.editRequest = cb),
      onTextChanged: (
        cb: (id: string, field: string, payload: { html?: string; text?: string }) => void,
      ) => (callbacks.textChanged = cb),
      editGrant: vi.fn(),
      editFlush: vi.fn().mockResolvedValue(undefined),
      stageRefresh: vi.fn().mockResolvedValue('patched'),
      onEditStart: (cb: (id: string) => void) => (callbacks.editStart = cb),
      onEditEnd: (cb: (id: string) => void) => (callbacks.editEnd = cb),
      onScroll: (cb: (y: number) => void) => (callbacks.scroll = cb),
      restoreScroll: vi.fn(),
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
  blockTypes.value = [
    bt('card'),
    {
      ...bt('rich_text'),
      schema: [
        {
          name: 'body',
          type: 'text',
          format: 'rich',
          required: false,
          localized: false,
          filterable: false,
        },
      ],
    } as BlockType,
  ]
  draft.value = {
    fields: {
      title: 'T',
      body: [
        { id: 'blockaaa0001', type: 'card', data: { title: 'A' } },
        { id: 'blockbbb0002', type: 'card', data: { title: 'B' } },
        { id: 'prose0000003', type: 'rich_text', data: { body: '<p>old</p>' } },
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
  bridge.instance.editGrant.mockClear()
  bridge.instance.editFlush.mockClear()
  bridge.instance.editFlush.mockResolvedValue(undefined)
  bridge.instance.stageRefresh.mockClear()
  bridge.instance.stageRefresh.mockResolvedValue('patched') // default: patch succeeds
  bridge.instance.restoreScroll.mockClear()
  notify.error.mockReset() // the suspension test counts error banners
  localStorage.clear()
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

  it('the preview button mints fresh and opens the theme preview in a new tab', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    const openSpy = vi.spyOn(window, 'open').mockReturnValue(null)
    const wrapper = mountPage()
    await flushPromises()

    mintMock.mockResolvedValueOnce({ token: 'tok2', themeUrl: 'https://site.test/_preview/tok2' })
    await wrapper.find('[data-test="canvas-open-preview"]').trigger('click')
    await flushPromises()
    expect(openSpy).toHaveBeenCalledWith('https://site.test/_preview/tok2', '_blank', 'noopener')
    // The stage itself is untouched: same iframe src, no remount.
    expect(wrapper.find('[data-test="canvas-iframe"]').attributes('src')).toBe(
      'https://site.test/_preview/tok1',
    )
    openSpy.mockRestore()
    wrapper.unmount()
  })

  it('the Page tab edits _presentation and auto-applies; Theme default clears the key', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    vi.useFakeTimers()
    try {
      // Switch to the Page tab via the UTabs trigger.
      const pageTab = wrapper
        .findAll('button')
        .find((b) => b.text() === 'Page' && b.attributes('role') === 'tab')
      expect(pageTab).toBeDefined()
      // Reka tabs activate on mousedown (WAI pattern), not click.
      await pageTab!.trigger('mousedown', { button: 0 })
      await pageTab!.trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-test="page-settings"]').isVisible()).toBe(true)

      // Set an override: the tree gains the reserved key and auto-apply
      // carries it (the SAME chain as any content edit).
      await wrapper.find('[data-test="pres-layout-full"]').trigger('click')
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledWith(
        'entry0000001',
        'en',
        'tok1',
        expect.objectContaining({ _presentation: { layout: 'full' } }),
      )

      await wrapper.find('[data-test="pres-title-hide"]').trigger('click')
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenLastCalledWith(
        'entry0000001',
        'en',
        'tok1',
        expect.objectContaining({ _presentation: { layout: 'full', show_title: false } }),
      )

      // Theme default DELETES the keys — an empty override removes _presentation.
      await wrapper.find('[data-test="pres-layout-default"]').trigger('click')
      await wrapper.find('[data-test="pres-title-default"]').trigger('click')
      await vi.advanceTimersByTimeAsync(900)
      const lastPayload = applyMock.mock.calls[applyMock.mock.calls.length - 1][3] as Record<
        string,
        unknown
      >
      expect('_presentation' in lastPayload).toBe(false)

      // unmount-on-hide=false: the FieldEditor stays MOUNTED while the Page
      // tab shows — stage intents still route through fieldEditorRef.
      bridge.callbacks.move?.('blockaaa0001', 1)
      await flushPromises()
      expect(bridge.instance.mirrorMove).toHaveBeenCalled()
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('opening the canvas reconciles the stash ONCE: one apply of the hydrated tree', async () => {
    // The stash outlives sessions (keyed entry+locale, cleared only by save):
    // an abandoned session's stash overlays the draft on the next open, so the
    // stage and the tree start OUT OF SYNC. The initial reconciliation apply
    // overwrites the stash with tree truth — regardless of the Auto toggle.
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    expect(applyMock).not.toHaveBeenCalled() // not before the stage is loaded

    await wrapper.find('[data-test="canvas-iframe"]').trigger('load')
    await flushPromises()
    expect(applyMock).toHaveBeenCalledTimes(1)
    expect(applyMock).toHaveBeenCalledWith(
      'entry0000001',
      'en',
      'tok1',
      expect.objectContaining({ title: 'T' }), // the HYDRATED tree, verbatim
    )
    expect(bridge.instance.stageRefresh).toHaveBeenCalledTimes(1)

    // A later reload (fallback path) must NOT re-run the reconciliation.
    await wrapper.find('[data-test="canvas-iframe"]').trigger('load')
    await flushPromises()
    expect(applyMock).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('Apply posts token+fields and PATCHES in place — no remount, no re-mint', async () => {
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
    // dom-patching spec §4: success asks the bridge to patch; 'patched'
    // means the iframe is NOT remounted (identity kept, scroll untouched).
    expect(bridge.instance.stageRefresh).toHaveBeenCalledTimes(1)
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1') // SAME URL
    expect(iframe.element).toBe(before) // NOT remounted -> patched in place
    expect(mintMock).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('a reload answer (or timeout) from the bridge falls back to the full remount', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockResolvedValue(undefined)
    bridge.instance.stageRefresh.mockResolvedValue('reload')
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1') // SAME URL
    expect(iframe.element).not.toBe(before) // remounted -> reloaded (today's path)
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
    // stage toolbar must not survive as if they were applied. Failure paths
    // reload DIRECTLY (dom-patching spec §1): stageRefresh is asserted
    // uncalled at the end of this test.
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
    expect(bridge.instance.stageRefresh).not.toHaveBeenCalled() // failure = direct reload
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
            expect.objectContaining({ id: 'prose0000003' }),
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

    // The outline lives in the inspector tabs now — unmount-on-hide is false,
    // so its items are mounted (and clickable) without any toggle.
    await wrapper.find('[data-test="canvas-outline-item-blockaaa0001"]').trigger('click')
    await flushPromises()
    // Inspector selection landed (header focused via selectBlockById).
    expect(document.activeElement?.getAttribute('data-test')).toBe('block-toggle-blockaaa0001')
    wrapper.unmount()
  })

  it('stage Escape deselect clears the parent selection (outline highlight)', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.select?.('blockaaa0001')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-outline-item-blockaaa0001"]').classes()).toContain(
      'bg-elevated',
    )

    bridge.callbacks.deselect?.('blockaaa0001')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-outline-item-blockaaa0001"]').classes()).not.toContain(
      'bg-elevated',
    )
    wrapper.unmount()
  })

  it('outline keyboard shortcuts drive the shared handlers (polish batch §4)', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    const row = () => wrapper.find('[data-test="canvas-outline-item-blockaaa0001"]')

    // No selection: keys are inert.
    await row().trigger('keydown', { key: 'ArrowDown', altKey: true })
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled()

    await row().trigger('click') // select via the outline
    await flushPromises()

    // Plain arrow (no Alt): nothing.
    await row().trigger('keydown', { key: 'ArrowDown' })
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled()

    // Alt+ArrowDown: same tree move + mirror as the stage path.
    await row().trigger('keydown', { key: 'ArrowDown', altKey: true })
    expect(bridge.instance.mirrorMove).toHaveBeenCalledWith('blockaaa0001', {
      beforeId: 'prose0000003',
    })

    // Backspace: parent-confirmed delete, centered variant (null anchor).
    await row().trigger('keydown', { key: 'Backspace' })
    expect(wrapper.find('[data-test="canvas-delete-confirm"]').exists()).toBe(true)
    await wrapper.find('[data-test="canvas-delete-cancel"]').trigger('click')

    // Cmd+D: duplicate through the shared handler; selection follows the clone.
    await row().trigger('keydown', { key: 'd', metaKey: true })
    await flushPromises()
    expect(bridge.instance.mirrorDuplicate).toHaveBeenCalledWith(
      'blockaaa0001',
      expect.any(Object),
    )
    const idMap = (bridge.instance.mirrorDuplicate as ReturnType<typeof vi.fn>).mock
      .calls[0][1] as Record<string, string>
    const newId = idMap['blockaaa0001']
    expect(
      wrapper.find(`[data-test="canvas-outline-item-${newId}"]`).classes(),
    ).toContain('bg-elevated')

    // Escape: parent state clears AND the stage ring clears via highlight('').
    ;(bridge.instance.highlight as ReturnType<typeof vi.fn>).mockClear()
    await wrapper.find(`[data-test="canvas-outline-item-${newId}"]`).trigger('keydown', {
      key: 'Escape',
    })
    expect(bridge.instance.highlight).toHaveBeenCalledWith('')
    expect(
      wrapper.find(`[data-test="canvas-outline-item-${newId}"]`).classes(),
    ).not.toContain('bg-elevated')
    wrapper.unmount()
  })

  it('move intent mutates the tree and posts mirror-move; boundary posts nothing', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.move?.('blockaaa0001', 1)
    await flushPromises()
    expect(bridge.instance.mirrorMove).toHaveBeenCalledWith('blockaaa0001', {
      beforeId: 'prose0000003',
    })

    bridge.instance.mirrorMove.mockClear()
    bridge.callbacks.move?.('blockaaa0001', 1) // to list end
    await flushPromises()
    expect(bridge.instance.mirrorMove).toHaveBeenCalledWith('blockaaa0001', {
      afterId: 'prose0000003',
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

  it('the add-after picker filters by search and Enter picks the first match', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    const picker = wrapper.find('[data-test="canvas-add-picker"]')
    const filter = picker.find('[data-test="canvas-add-filter"]')
    expect(filter.exists()).toBe(true)

    await filter.setValue('rich')
    expect(picker.find('[data-test="canvas-add-type-rich_text"]').exists()).toBe(true)
    expect(picker.find('[data-test="canvas-add-type-card"]').exists()).toBe(false)

    // Enter picks the first (only) match and closes the picker.
    await filter.trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-add-picker"]').exists()).toBe(false)

    // Reopening resets the filter (card visible again); Escape cancels.
    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    const reopened = wrapper.find('[data-test="canvas-add-picker"]')
    expect(reopened.find('[data-test="canvas-add-type-card"]').exists()).toBe(true)
    await reopened.find('[data-test="canvas-add-filter"]').trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[data-test="canvas-add-picker"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('an accepted block-move-to patches the tree; NO mirror is posted back', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    bridge.callbacks.moveTo?.('blockaaa0001', { afterId: 'prose0000003' })
    await flushPromises()
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled() // the drag WAS the mirror
    expect(wrapper.find('[data-test="canvas-iframe"]').element).toBe(before) // no reload

    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
      fields: { body: { id: string }[] }
    }
    expect(saved.fields.body.map((b) => b.id)).toEqual([
      'blockbbb0002',
      'prose0000003',
      'blockaaa0001',
    ])
    wrapper.unmount()
  })

  it('a REJECTED block-move-to reloads the stage and leaves fields untouched', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    bridge.callbacks.moveTo?.('blockaaa0001', { beforeId: 'missing' })
    await flushPromises()
    await flushPromises()
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.element).not.toBe(before) // reloadStage snapped back to truth
    expect(mintMock).toHaveBeenCalledTimes(1) // reload, not re-mint

    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
      fields: { body: { id: string }[] }
    }
    expect(saved.fields.body.map((b) => b.id)).toEqual([
      'blockaaa0001',
      'blockbbb0002',
      'prose0000003',
    ])
    wrapper.unmount()
  })

  it('an anchored delete request positions the confirm at the delete button', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    // jsdom rects are all zeros, so top = anchor.y + 8.
    bridge.callbacks.deleteRequest?.('blockaaa0001', { x: 90, y: 30 })
    await flushPromises()
    const confirm = wrapper.find('[data-test="canvas-delete-confirm"]')
    expect(confirm.exists()).toBe(true)
    expect(confirm.attributes('style')).toContain('top: 38px')
    expect(confirm.classes()).not.toContain('mx-auto')

    // Without an anchor, the centered fallback still applies.
    await confirm.find('[data-test="canvas-delete-cancel"]').trigger('click')
    bridge.callbacks.deleteRequest?.('blockaaa0001')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-delete-confirm"]').classes()).toContain('mx-auto')
    wrapper.unmount()
  })

  it('add-after opens the popover picker with or without a bridge anchor', async () => {
    // Positioning is DELEGATED: the picker is a UPopover on a virtual
    // reference built from the bridge rect (iframe → viewport translation);
    // flipping/shifting is floating-ui's job, so the spec asserts the open/
    // close contract, not coordinate math (jsdom rects are all zeros anyway).
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.addAfter?.('blockaaa0001', { x: 120, y: 42 })
    await flushPromises()
    const picker = wrapper.find('[data-test="canvas-add-picker"]')
    expect(picker.exists()).toBe(true)

    // Cancel closes; an anchor-LESS intent still opens (stage-top fallback
    // reference — the popover never fails to appear over missing geometry).
    await picker.find('[data-test="canvas-add-cancel"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-add-picker"]').exists()).toBe(false)
    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-add-picker"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('the outline is an inspector tab — always mounted, no navbar toggle', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    // Mounted from the start (unmount-on-hide false keeps every tab alive so
    // bridge intents and outline state survive tab switches).
    expect(wrapper.find('[data-test="outline-tab"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="canvas-outline"]').exists()).toBe(true)
    // The old navbar toggle is gone.
    expect(wrapper.find('[data-test="canvas-outline-toggle"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('edit-request grants per the kind matrix; everything else is denied', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    // Prose rich field -> rich; plain string field -> string.
    bridge.callbacks.editRequest?.('prose0000003', 'body')
    bridge.callbacks.editRequest?.('blockaaa0001', 'title')
    await flushPromises()
    expect(bridge.instance.editGrant).toHaveBeenCalledWith('prose0000003', 'body', 'rich')
    expect(bridge.instance.editGrant).toHaveBeenCalledWith('blockaaa0001', 'title', 'string')

    bridge.instance.editGrant.mockClear()
    bridge.callbacks.editRequest?.('blockaaa0001', 'nope') // unknown field
    bridge.callbacks.editRequest?.('missing', 'title') // unknown block
    bridge.callbacks.editRequest?.('prose0000003', 'title') // field not on prose type
    await flushPromises()
    expect(bridge.instance.editGrant).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('text-changed for a wrong field or a non-prose block is IGNORED (review P1)', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()

    // Wrong field on a prose block; unknown field; kind-mismatched payload
    // (rich payload for a string field): all denied, no patch.
    bridge.callbacks.textChanged?.('prose0000003', 'title', { html: '<p>evil</p>' })
    bridge.callbacks.textChanged?.('blockaaa0001', 'nope', { text: 'evil' })
    bridge.callbacks.textChanged?.('blockaaa0001', 'title', { html: '<b>evil</b>' })
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
      fields: { body: { id: string; data: Record<string, unknown> }[] }
    }
    expect(saved.fields.body.find((b) => b.id === 'prose0000003')!.data.body).toBe('<p>old</p>')
    expect(saved.fields.body.find((b) => b.id === 'blockaaa0001')!.data.title).toBe('A')
    wrapper.unmount()
  })

  it('text-changed patches the tree (visible in the next save payload)', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.textChanged?.('prose0000003', 'body', { html: '<p>typed in stage</p>' })
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(saveMock).toHaveBeenLastCalledWith(
      expect.objectContaining({
        fields: expect.objectContaining({
          body: expect.arrayContaining([
            expect.objectContaining({
              id: 'prose0000003',
              data: expect.objectContaining({ body: '<p>typed in stage</p>' }),
            }),
          ]),
        }),
      }),
    )
    wrapper.unmount()
  })

  it('a string text-changed patches the plain value into the tree', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.textChanged?.('blockaaa0001', 'title', { text: 'Retitled' })
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
      fields: { body: { id: string; data: Record<string, unknown> }[] }
    }
    expect(saved.fields.body.find((b) => b.id === 'blockaaa0001')!.data.title).toBe('Retitled')
    wrapper.unmount()
  })

  it('Apply awaits the flush and the FINAL flushed text reaches the apply payload', async () => {
    // Review P2: order alone is not the risk — the last sub-debounce keystroke
    // is. The mocked flush delivers a final text-changed BEFORE resolving, the
    // way the real bridge commits during lemma:edit-flush; Apply must read the
    // tree AFTER that commit landed.
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockResolvedValue(undefined)
    bridge.instance.editFlush.mockImplementationOnce(async () => {
      bridge.callbacks.textChanged?.('prose0000003', 'body', { html: '<p>final keystroke</p>' })
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.editFlush).toHaveBeenCalled()
    const applied = applyMock.mock.calls[applyMock.mock.calls.length - 1]![3] as {
      body: { id: string; data: Record<string, unknown> }[]
    }
    expect(applied.body.find((b) => b.id === 'prose0000003')!.data.body).toBe(
      '<p>final keystroke</p>',
    )
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
            expect.objectContaining({ id: 'prose0000003' }),
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

describe('auto-apply', () => {
  async function mountAuto() {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    return wrapper
  }

  it('an inspector FORM edit auto-applies through the deep fields watcher', async () => {
    // Regression guard (dom-patching bug hunt): inline editing has its own
    // explicit edit-end re-arm, so a broken fields watcher would surface as
    // "auto-apply only works when typing in the stage".
    const wrapper = await mountAuto()
    vi.useFakeTimers()
    try {
      const inputs = wrapper.findAll('input')
      const title = inputs.find((i) => (i.element as HTMLInputElement).value === 'T')
      expect(title).toBeDefined()
      await title!.setValue('T changed in inspector')
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledTimes(1)
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('a perpetual change stream cannot starve auto-apply (max-wait)', async () => {
    // Bug hunt: anything touching fields more often than the 800ms debounce
    // (an extension like Grammarly re-emitting TipTap updates, a theme timer)
    // restarts the timer forever — the apply never fires and the veto
    // breadcrumb never prints. The debounce may DELAY an apply, never
    // starve it: max-wait forces a run ~2.5s after the first change.
    const wrapper = await mountAuto()
    vi.useFakeTimers()
    try {
      for (let i = 0; i < 8; i++) {
        bridge.callbacks.textChanged?.('prose0000003', 'body', { html: `<p>tick ${i}</p>` })
        await vi.advanceTimersByTimeAsync(400) // always inside the 800ms window
      }
      // 3200ms of continuous sub-debounce changes: max-wait must have fired.
      expect(applyMock).toHaveBeenCalled()
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('a tree change auto-applies ONCE after the debounce; a burst coalesces', async () => {
    const wrapper = await mountAuto()
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(400)
      bridge.callbacks.move?.('blockaaa0001', 1) // restarts the debounce
      await vi.advanceTimersByTimeAsync(400)
      expect(applyMock).not.toHaveBeenCalled() // still inside the window
      await vi.advanceTimersByTimeAsync(500)
      expect(applyMock).toHaveBeenCalledTimes(1)
      expect(applyMock).toHaveBeenCalledWith('entry0000001', 'en', 'tok1', expect.anything())
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('no concurrent applies: a change during flight queues EXACTLY one follow-up', async () => {
    const wrapper = await mountAuto()
    let release!: () => void
    applyMock.mockImplementationOnce(() => new Promise<void>((resolve) => (release = resolve)))
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(900) // first run: now in flight
      expect(applyMock).toHaveBeenCalledTimes(1)

      // Two NON-CANCELLING changes during flight (two cancelling moves would
      // legitimately skip the follow-up: honest lastApplied bookkeeping means
      // stageStale re-derives false when the tree returns to the sent state).
      bridge.callbacks.move?.('blockaaa0001', 1)
      bridge.callbacks.textChanged?.('prose0000003', 'body', { html: '<p>mid-flight</p>' })
      await vi.advanceTimersByTimeAsync(900) // debounce fires -> queued, returns
      expect(applyMock).toHaveBeenCalledTimes(1) // STILL one — no overlap

      release()
      await vi.advanceTimersByTimeAsync(100) // settle + follow-up
      expect(applyMock).toHaveBeenCalledTimes(2) // exactly one follow-up
      // The follow-up carries the LATEST tree (snapshot honesty, review P1).
      const followUp = applyMock.mock.calls[1]![3] as {
        body: { id: string; data: Record<string, unknown> }[]
      }
      expect(followUp.body.find((b) => b.id === 'prose0000003')!.data.body).toBe(
        '<p>mid-flight</p>',
      )
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('edit sessions suppress auto-apply; edit-end re-arms it', async () => {
    const wrapper = await mountAuto()
    vi.useFakeTimers()
    try {
      bridge.callbacks.editStart?.('prose0000003')
      bridge.callbacks.textChanged?.('prose0000003', 'body', { html: '<p>typing</p>' })
      await vi.advanceTimersByTimeAsync(2000)
      expect(applyMock).not.toHaveBeenCalled() // suppressed while editing

      bridge.callbacks.editEnd?.('prose0000003')
      await vi.advanceTimersByTimeAsync(900) // edit-end re-armed the debounce
      expect(applyMock).toHaveBeenCalledTimes(1)
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('final failure suspends (one banner, no further autos); manual success re-arms', async () => {
    const wrapper = await mountAuto()
    applyMock.mockRejectedValueOnce(new ApiError('validation failed', 422, {}, { success: false }))
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledTimes(1)
      expect(notify.error).toHaveBeenCalledTimes(1) // one banner

      bridge.callbacks.move?.('blockaaa0001', -1) // suspended: nothing schedules
      await vi.advanceTimersByTimeAsync(2000)
      expect(applyMock).toHaveBeenCalledTimes(1)
    } finally {
      vi.useRealTimers()
    }

    // Manual Apply succeeds -> auto re-arms.
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(applyMock).toHaveBeenCalledTimes(2)
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledTimes(3)
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('a dead-token retry that SUCCEEDS does not suspend', async () => {
    const wrapper = await mountAuto()
    mintMock.mockResolvedValue({ token: 'tok2', themeUrl: 'https://site.test/_preview/tok2' })
    applyMock
      .mockRejectedValueOnce(new ApiError('expired', 410, {}, { success: false }))
      .mockResolvedValue(undefined)
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledTimes(2) // attempt + retry (TTL churn)

      bridge.callbacks.move?.('blockaaa0001', -1) // NOT suspended
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledTimes(3)
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('the toggle disables auto, persists, and re-enables', async () => {
    const wrapper = await mountAuto()
    await wrapper.find('[data-test="canvas-auto-toggle"]').trigger('click')
    expect(localStorage.getItem('lemma.canvas.auto_apply')).toBe('0')
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(2000)
      expect(applyMock).not.toHaveBeenCalled()
    } finally {
      vi.useRealTimers()
    }
    await wrapper.find('[data-test="canvas-auto-toggle"]').trigger('click')
    expect(localStorage.getItem('lemma.canvas.auto_apply')).toBe('1')
    wrapper.unmount()
  })

  it('scroll is remembered and restored after reloads', async () => {
    const wrapper = await mountAuto()
    bridge.callbacks.scroll?.(560)
    // Any reload path re-fires @load -> onIframeLoad -> hello + restore.
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    await iframe.trigger('load')
    expect(bridge.instance.restoreScroll).toHaveBeenCalledWith(560)
    wrapper.unmount()
  })
})
