import { describe, it, expect, vi, beforeEach, beforeAll, afterEach } from 'vitest'
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
// Every accepted apply names a newer revision (visual builder spec §3.5); a repeated one
// would be dropped as stale, so the default mock counts up.
let revisionCounter = 0
const nextApplied = () => ({
  epoch: 'e1',
  revision: ++revisionCounter,
  baseline: revisionCounter - 1,
  style_generation: 0,
  applied_at: '2026-09-14T00:00:00Z',
})
vi.mock('@/queries/preview', () => ({ mintPreviewData: mintMock, applyPreview: applyMock }))
// A real ref, created in the (async) mock factory: `ref` cannot be reached from vi.hoisted.
const styleSchemaHolder = vi.hoisted(() => ({ data: null as unknown as { value: unknown } }))
vi.mock('@/queries/styleSchema', async (importOriginal) => {
  const { ref: vueRef } = await import('vue')
  styleSchemaHolder.data = vueRef<unknown>(null)
  return {
    ...(await importOriginal<typeof import('@/queries/styleSchema')>()),
    useStyleSchema: () => ({ data: styleSchemaHolder.data }),
  }
})
vi.mock('@/queries/styleClasses', () => ({
  useStyleClasses: () => ({ data: ref({ generation: 0, classes: [] }), refetch: vi.fn() }),
  useStyleClassMutations: () => ({
    create: { mutateAsync: vi.fn(), isLoading: ref(false) },
    deleteUnreferenced: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
}))

const draft = ref<{ fields: Record<string, unknown>; lock_version: number } | null>(null)
const { saveMock } = vi.hoisted(() => ({ saveMock: vi.fn() }))
const publishMock = vi.hoisted(() => vi.fn())
const routesMock = vi.hoisted(() => ({
  rows: [{ locale: 'en', slug: 'home' }] as { locale: string; slug: string }[],
}))
vi.mock('@/queries/routes', () => ({
  useRoutes: () => ({ data: { value: routesMock.rows } }),
  fetchRoutes: async () => routesMock.rows,
}))
vi.mock('@/queries/publish', () => ({
  usePublish: () => ({ mutateAsync: publishMock, isLoading: ref(false) }),
}))
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
    props: { modelValue: String, placeholder: String, pickerTypes: Array, readonly: Boolean },
    emits: ['update:modelValue', 'insert-block'],
    template: '<div data-test="prose-editor-stub" />',
  },
}))

// The REAL composable is covered by canvas-bridge.spec — the page suite asserts
// wiring only: intents in via captured callbacks, mirrors out via spies.
const bridge = vi.hoisted(() => {
  /** Every structure-offer publish the page makes, newest last (container-layout spec §6.2). */
  const published: unknown[] = []
  /** Every Fill state list the page publishes, newest last (container-layout spec §11.3). */
  const fillStates: unknown[] = []
  const callbacks: {
    select?: (id: string, modifiers?: { shift: boolean; meta: boolean }) => void
    hover?: (id: string) => void
    index?: (ids: string[]) => void
    deselect?: (id: string) => void
    move?: (id: string, d: 1 | -1) => void
    dragPropose?: (session: string, blocks: string[], zone: StageZone | null) => void
    blockDrop?: (session: string, blocks: string[], zone: StageZone) => void
    dragCancel?: (session: string) => void
    duplicate?: (id: string) => void
    deleteRequest?: (id: string, anchor?: { x: number; y: number } | null) => void
    addAfter?: (id: string) => void
    slotAdd?: (parent: string | null, slot: string) => void
    structureChoose?: (id: string, preset: string) => void
    structureSkip?: (id: string) => void
    gridFill?: (id: string) => void
    editRequest?: (id: string, field: string) => void
    textChanged?: (id: string, field: string, payload: { html?: string; text?: string }) => void
    editStart?: (id: string) => void
    editEnd?: (id: string) => void
    scroll?: (y: number) => void
  } = {}
  return {
    callbacks,
    published,
    fillStates,
    instance: {
      nonce: 'n',
      hello: vi.fn(),
      onBlockSelect: (cb: (id: string, modifiers?: { shift: boolean; meta: boolean }) => void) =>
        (callbacks.select = cb),
      onBlockDeselect: (cb: (id: string) => void) => (callbacks.deselect = cb),
      onBlockHover: (cb: (id: string) => void) => (callbacks.hover = cb),
      onBlocksIndex: (cb: (ids: string[]) => void) => (callbacks.index = cb),
      onBlockMove: (cb: (id: string, d: 1 | -1) => void) => (callbacks.move = cb),
      onDragPropose: (cb: (session: string, blocks: string[], zone: StageZone | null) => void) =>
        (callbacks.dragPropose = cb),
      onBlockDrop: (cb: (session: string, blocks: string[], zone: StageZone) => void) =>
        (callbacks.blockDrop = cb),
      onDragCancel: (cb: (session: string) => void) => (callbacks.dragCancel = cb),
      dragBegin: vi.fn(),
      dragHover: vi.fn(),
      dragLegality: vi.fn(),
      dragDrop: vi.fn(),
      dragEnd: vi.fn(),
      onBlockDuplicate: (cb: (id: string) => void) => (callbacks.duplicate = cb),
      onBlockDeleteRequest: (cb: (id: string, anchor?: { x: number; y: number } | null) => void) =>
        (callbacks.deleteRequest = cb),
      onBlockAddAfter: (cb: (id: string) => void) => (callbacks.addAfter = cb),
      onSlotAdd: (cb: (parent: string | null, slot: string) => void) => (callbacks.slotAdd = cb),
      publishStructureOffers: vi.fn((offers: unknown) => published.push(offers)),
      onStructureChoose: (cb: (id: string, preset: string) => void) =>
        (callbacks.structureChoose = cb),
      onStructureSkip: (cb: (id: string) => void) => (callbacks.structureSkip = cb),
      publishGridFill: vi.fn((states: unknown) => fillStates.push(states)),
      onGridFill: (cb: (id: string) => void) => (callbacks.gridFill = cb),
      onHistory: vi.fn(),
      onEditRequest: (cb: (id: string, field: string) => void) => (callbacks.editRequest = cb),
      onTextChanged: (
        cb: (id: string, field: string, payload: { html?: string; text?: string }) => void,
      ) => (callbacks.textChanged = cb),
      editGrant: vi.fn(),
      editFlush: vi.fn().mockResolvedValue(undefined),
      stageRefresh: vi.fn().mockResolvedValue({ mode: 'patched', epoch: null, revision: null }),
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
// The server block factory (visual builder spec §5.5): stubbed per slug — a fresh id, the
// canonical defaults the server would send, and the starter merged in.
const factoryStarter: Record<string, Record<string, unknown>> = {
  hero: { headline: 'Headline', links: [] },
  card: { title: 'Card', body: [] },
}
const factory = vi.hoisted(() => ({ instance: vi.fn() }))
const defaultInstance = async (slug: string) => ({
  id: 'f' + Math.random().toString(36).slice(2, 13).padEnd(11, '0'),
  type: slug,
  data: { ...(factoryStarter[slug] ?? {}) },
  settings: {},
})
vi.mock('@/queries/blockFactory', () => ({
  useBlockFactory: () => ({ make: vi.fn(), instance: factory.instance }),
}))

vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ params: { type: 'page', uuid: 'entry0000001', locale: 'en' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import DesignPage from '@/pages/content/[type]/[uuid]/design/[locale].vue'
import type { StageZone } from '@/composables/useCanvasBridge'

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
        // A tooltip needs UApp's provider; the tooltips are not under test. Keyed by the component's
        // own name: the Nuxt UI plugin imports it directly, so `UTooltip` would not match.
        Tooltip: { template: '<div><slot /></div>' },
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
  revisionCounter = 0
  factory.instance.mockReset()
  factory.instance.mockImplementation(defaultInstance)
  applyMock.mockImplementation(async () => nextApplied())
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
  bridge.instance.stageRefresh.mockResolvedValue({ mode: 'patched', epoch: null, revision: null }) // default: patch succeeds
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
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1?canvas=1')

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

  it('Publish on a dirty canvas saves silently and shows a single Published toast', async () => {
    mintMock.mockResolvedValue({ token: 't1', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    publishMock.mockReset().mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    bridge.callbacks.textChanged?.('prose0000003', 'body', { html: '<p>edited</p>' })
    await flushPromises()

    await wrapper.find('[data-test="canvas-publish"]').trigger('click')
    await flushPromises()

    expect(saveMock).toHaveBeenCalledTimes(1)
    expect(publishMock).toHaveBeenCalledWith('publish')
    expect(notify.success).toHaveBeenCalledTimes(1)
    expect(notify.success).toHaveBeenCalledWith('Published')
    wrapper.unmount()
  })

  it('a page with no URL in this language is not published, and the toast says where to give it one', async () => {
    // Publishing succeeds without a route, and the page then renders nowhere: the form editor
    // saves the slug first, the Design view had no slug and published anyway.
    mintMock.mockResolvedValue({ token: 't1', themeUrl: 'https://site.test/_preview/tok1' })
    publishMock.mockReset().mockResolvedValue(undefined)
    routesMock.rows = [{ locale: 'fr', slug: 'accueil' }]
    try {
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-test="canvas-publish"]').trigger('click')
      await flushPromises()

      expect(publishMock).not.toHaveBeenCalled()
      expect(notify.warning).toHaveBeenCalledTimes(1)
      expect(String(notify.warning.mock.calls[0]![0])).toContain('URL')
      expect(String(notify.warning.mock.calls[0]![1])).toContain('Publishing')
      wrapper.unmount()
    } finally {
      routesMock.rows = [{ locale: 'en', slug: 'home' }]
    }
  })

  it('a publish refused for a nested block selects that block, opens its Block tab and names the field', async () => {
    mintMock.mockResolvedValue({ token: 't1', themeUrl: 'https://site.test/_preview/tok1' })
    publishMock
      .mockReset()
      .mockRejectedValue(
        new ApiError('Validation failed', 422, { 'body.1.title': 'is required' }, {}),
      )
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="canvas-publish"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="inspector-tabs"] [aria-selected="true"]').text()).toBe('Block')
    expect(wrapper.find('[data-test="block-inspector-title"]').text()).toBe('card')
    expect(bridge.instance.highlight).toHaveBeenCalledWith('blockbbb0002', ['blockbbb0002'])
    expect(notify.error).toHaveBeenCalledTimes(1)
    expect(String(notify.error.mock.calls[0]![1])).toContain('card')
    expect(String(notify.error.mock.calls[0]![1])).toContain('title')
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
      'https://site.test/_preview/tok1?canvas=1',
    )
    openSpy.mockRestore()
    wrapper.unmount()
  })

  it("the Page tab's Styles section writes padding and background into _presentation.style", async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    styleSchemaHolder.data.value = {
      version: 1,
      breakpoints: { base: 0, md: 768, lg: 1024 },
      properties: [],
      advanced: [],
      vocabulary: {
        version: 1,
        domains: { spacing: ['none', 'sm', 'lg'], color: ['background', 'surface', 'accent'] },
        values: { 'spacing.lg': 'var(--space-4)' },
      },
    }
    const wrapper = mountPage()
    await flushPromises()
    vi.useFakeTimers()
    try {
      const pageTab = wrapper
        .findAll('button')
        .find((b) => b.text() === 'Page' && b.attributes('role') === 'tab')
      await pageTab!.trigger('mousedown', { button: 0 })
      await pageTab!.trigger('click')
      await flushPromises()
      const styles = wrapper.find('[data-test="page-styles"]')
      expect(styles.exists()).toBe(true)
      // Padding: the box row, linked, writes every side at the active breakpoint.
      await styles.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
      await styles.find('[data-test="token-spacing.lg"]').trigger('click')
      await vi.advanceTimersByTimeAsync(900)
      // The Design page's active breakpoint defaults to lg (the desktop viewport).
      const lg = { lg: { type: 'token', value: 'spacing.lg' } }
      expect(applyMock).toHaveBeenLastCalledWith(
        'entry0000001',
        'en',
        'tok1',
        expect.objectContaining({
          _presentation: {
            style: { spacing: { padding: { top: lg, right: lg, bottom: lg, left: lg } } },
          },
        }),
        expect.anything(),
      )
      // Background: one token row.
      await styles.find('[data-test="token-color.accent"]').trigger('click')
      await vi.advanceTimersByTimeAsync(900)
      const last = applyMock.mock.calls[applyMock.mock.calls.length - 1][3] as {
        _presentation: { style: { colors: { surface: unknown } } }
      }
      // Colours are not responsive in the style contract: one value, no breakpoint key.
      expect(last._presentation.style.colors.surface).toEqual({
        type: 'token',
        value: 'color.accent',
      })
      // Reset on the open padding cell writes a reset; clearing everything drops the key.
      await styles.find('[data-test="style-reset"]').trigger('click')
      await vi.advanceTimersByTimeAsync(900)
      const reset = applyMock.mock.calls[applyMock.mock.calls.length - 1][3] as {
        _presentation: { style: { spacing: { padding: { top: unknown } } } }
      }
      expect(reset._presentation.style.spacing.padding.top).toEqual({ lg: { type: 'reset' } })
    } finally {
      vi.useRealTimers()
      styleSchemaHolder.data.value = null
      wrapper.unmount()
    }
  })

  it('the Page tab edits _presentation and auto-applies; Theme default clears the key', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
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
        expect.anything(),
      )

      await wrapper.find('[data-test="pres-title-hide"]').trigger('click')
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenLastCalledWith(
        'entry0000001',
        'en',
        'tok1',
        expect.objectContaining({ _presentation: { layout: 'full', show_title: false } }),
        expect.anything(),
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
      expect.anything(),
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
      expect.anything(),
    )
    // dom-patching spec §4: success asks the bridge to patch; 'patched'
    // means the iframe is NOT remounted (identity kept, scroll untouched).
    expect(bridge.instance.stageRefresh).toHaveBeenCalledTimes(1)
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1?canvas=1') // SAME URL
    expect(iframe.element).toBe(before) // NOT remounted -> patched in place
    expect(mintMock).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('a reload answer (or timeout) from the bridge falls back to the full remount', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    bridge.instance.stageRefresh.mockResolvedValue({ mode: 'reload', epoch: null, revision: null })
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1?canvas=1') // SAME URL
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
    expect(applyMock).toHaveBeenLastCalledWith(
      'entry0000001',
      'en',
      'tok2',
      expect.anything(),
      expect.anything(),
    )
    wrapper.unmount()
  })

  it('Apply surfaces the migration 409 with the editor-mirror banner', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockRejectedValueOnce(
      new ApiError(
        "block type 'card' has a migration in progress",
        409,
        {},
        {
          success: false,
          error: {
            code: 409,
            details: { code: 'BLOCK_MIGRATION_IN_PROGRESS', block_type: 'card' },
          },
        },
      ),
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
    // uncalled at the end of this test. A 500 here: a 422 on the unchanged tip
    // rolls the transaction back instead (canvas-revisions.spec.ts).
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockRejectedValueOnce(new ApiError('server error', 500, {}, { success: false }))
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
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1?canvas=1') // SAME URL
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
    expect(notify.warning).toHaveBeenCalledWith('This draft changed elsewhere', expect.any(String))

    saveMock.mockRejectedValueOnce(
      new ApiError(
        "block type 'card' has a migration in progress",
        409,
        {},
        {
          success: false,
          error: {
            code: 409,
            details: { code: 'BLOCK_MIGRATION_IN_PROGRESS', block_type: 'card' },
          },
        },
      ),
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

  // The stage is clickable as soon as its page loads, which can be before the content-type schema
  // has: without the schema the tree has no root blocks fields, so the block cannot be placed yet.
  describe('a stage click that arrives before the schema', () => {
    const loaded = contentTypes.value
    const withoutSchema = async () => {
      contentTypes.value = undefined as unknown as typeof loaded
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      const wrapper = mountPage()
      await flushPromises()
      return wrapper
    }
    afterEach(() => {
      contentTypes.value = loaded
    })

    it('is kept, and selects the block once the schema arrives', async () => {
      const wrapper = await withoutSchema()
      bridge.callbacks.select?.('blockaaa0001')
      await flushPromises()
      expect(wrapper.find('[data-test="block-inspector"]').exists()).toBe(false)

      contentTypes.value = loaded
      await flushPromises()
      expect(wrapper.find('[data-test="block-inspector"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="canvas-outline-item-blockaaa0001"]').classes()).toContain(
        'bg-elevated',
      )
      wrapper.unmount()
    })

    it('gives way to a later click: the last one is the one selected', async () => {
      const wrapper = await withoutSchema()
      bridge.callbacks.select?.('blockaaa0001')
      bridge.callbacks.select?.('blockbbb0002')
      contentTypes.value = loaded
      await flushPromises()
      expect(wrapper.find('[data-test="canvas-outline-item-blockbbb0002"]').classes()).toContain(
        'bg-elevated',
      )
      expect(
        wrapper.find('[data-test="canvas-outline-item-blockaaa0001"]').classes(),
      ).not.toContain('bg-elevated')
      wrapper.unmount()
    })

    it('is forgotten when the stage deselects before the schema arrives', async () => {
      const wrapper = await withoutSchema()
      bridge.callbacks.select?.('blockaaa0001')
      bridge.callbacks.deselect?.('blockaaa0001')
      contentTypes.value = loaded
      await flushPromises()
      expect(wrapper.find('[data-test="block-inspector"]').exists()).toBe(false)
      wrapper.unmount()
    })
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
    expect(bridge.instance.mirrorDuplicate).toHaveBeenCalledWith('blockaaa0001', expect.any(Object))
    const idMap = (bridge.instance.mirrorDuplicate as ReturnType<typeof vi.fn>).mock
      .calls[0][1] as Record<string, string>
    const newId = idMap['blockaaa0001']
    expect(wrapper.find(`[data-test="canvas-outline-item-${newId}"]`).classes()).toContain(
      'bg-elevated',
    )

    // Escape: parent state clears AND the stage ring clears via highlight('').
    ;(bridge.instance.highlight as ReturnType<typeof vi.fn>).mockClear()
    await wrapper.find(`[data-test="canvas-outline-item-${newId}"]`).trigger('keydown', {
      key: 'Escape',
    })
    expect(bridge.instance.highlight).toHaveBeenCalledWith('')
    expect(wrapper.find(`[data-test="canvas-outline-item-${newId}"]`).classes()).not.toContain(
      'bg-elevated',
    )
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

  it('the stage + arms the Blocks tab after the block; Enter inserts the first match there and selects it', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-add-picker"]').exists()).toBe(false) // no popover
    const tab = wrapper.find('[data-test="blocks-tab"]')
    expect(tab.exists()).toBe(true)
    expect(tab.find('[data-test="palette-target"]').text()).toContain('Inserting after card')
    const search = tab.find('[data-test="palette-search"]')
    expect(document.activeElement).toBe(search.element)

    await search.setValue('card')
    await search.trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled()
    expect(bridge.instance.mirrorDuplicate).not.toHaveBeenCalled()
    expect(tab.find('[data-test="palette-target"]').exists()).toBe(false) // consumed
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
      fields: { body: { id: string; type: string; data: Record<string, unknown> }[] }
    }
    expect(saved.fields.body.map((b) => b.type)).toEqual(['card', 'card', 'card', 'rich_text'])
    expect(saved.fields.body[1]!.id).not.toBe('blockbbb0002')
    expect(saved.fields.body[1]!.data).toEqual({ title: 'Card', body: [] })
    expect(bridge.instance.highlight).toHaveBeenLastCalledWith(saved.fields.body[1]!.id, [
      saved.fields.body[1]!.id,
    ])
    wrapper.unmount()
  })

  it('Escape clears the armed target and leaves the tab open; selecting another block clears it too', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()
    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    const tab = wrapper.find('[data-test="blocks-tab"]')
    await tab.find('[data-test="palette-search"]').trigger('keydown', { key: 'Escape' })
    expect(tab.find('[data-test="palette-target"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="blocks-tab"]').exists()).toBe(true)

    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    expect(tab.find('[data-test="palette-target"]').exists()).toBe(true)
    bridge.callbacks.select?.('blockbbb0002')
    await flushPromises()
    expect(tab.find('[data-test="palette-target"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('an armed after-target whose block is deleted is gone with it', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    bridge.callbacks.addAfter?.('blockbbb0002')
    await flushPromises()
    const tab = wrapper.find('[data-test="blocks-tab"]')
    expect(tab.find('[data-test="palette-target"]').text()).toContain('Inserting after card')
    bridge.callbacks.deleteRequest?.('blockbbb0002')
    await flushPromises()
    await wrapper.find('[data-test="canvas-delete-confirm-yes"]').trigger('click')
    await flushPromises()
    // The target resolves to nothing and is dropped; the strip says so until the next arming.
    expect(tab.find('[data-test="palette-target"]').text()).toContain('That place is gone')
    expect(tab.find('[data-test="palette-target-cancel"]').exists()).toBe(false)
    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    expect(tab.find('[data-test="palette-target"]').text()).toContain('Inserting after card')
    wrapper.unmount()
  })

  it('a gap target from the list dies with the next structural change; an after-target survives it', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    // The Content tab's list gap (position 1 of body) arms the tab instead of opening a menu.
    await wrapper.find('[data-test="block-insert-1"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="block-picker"]').exists()).toBe(false)
    const tab = wrapper.find('[data-test="blocks-tab"]')
    expect(tab.find('[data-test="palette-target"]').text()).toContain(
      'Inserting at position 2 of body',
    )
    bridge.callbacks.move?.('prose0000003', -1) // a structural change: the gap is gone
    await flushPromises()
    expect(tab.find('[data-test="palette-target"]').text()).toContain('That place is gone')

    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    bridge.callbacks.move?.('blockaaa0001', 1) // the anchor moves; the target follows it
    await flushPromises()
    expect(tab.find('[data-test="palette-target"]').text()).toContain('Inserting after card')
    await tab.find('[data-test="palette-card-card"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
      fields: { body: { id: string }[] }
    }
    // body was [a, b, prose] → prose up → [a, prose, b] → a down → [prose, a, b] → after a.
    expect(saved.fields.body.map((b) => b.id).slice(0, 2)).toEqual(['prose0000003', 'blockaaa0001'])
    expect(saved.fields.body[3]!.id).toBe('blockbbb0002')
    wrapper.unmount()
  })

  it('a stage proposal is answered with its legality; the accepted drop patches the tree with NO mirror', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    // The coordinator's index convention: counted against the tree with the moving block
    // removed, so index 2 of body is "after prose0000003".
    const zone = { parent: null, slot: 'body', index: 2, layout: 'linear-vertical' as const }
    bridge.callbacks.dragPropose?.('s1', ['blockaaa0001'], zone)
    expect(bridge.instance.dragLegality).toHaveBeenCalledWith('s1', true, '')

    bridge.callbacks.blockDrop?.('s1', ['blockaaa0001'], zone)
    await flushPromises()
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled() // the patch is the mirror
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

  it('a refused proposal carries its reason; a refused drop warns and leaves the fields and stage alone', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    const zone = { parent: 'missing', slot: 'items', index: 0, layout: 'linear-vertical' as const }
    bridge.callbacks.dragPropose?.('s2', ['blockaaa0001'], zone)
    expect(bridge.instance.dragLegality).toHaveBeenCalledWith('s2', false, expect.any(String))
    const reason = (bridge.instance.dragLegality as ReturnType<typeof vi.fn>).mock.calls[0]![2]
    expect(reason).not.toBe('')

    bridge.callbacks.blockDrop?.('s2', ['blockaaa0001'], zone)
    await flushPromises()
    // The tree was never touched by the stage, so nothing snaps back: same iframe, no re-mint.
    expect(wrapper.find('[data-test="canvas-iframe"]').element).toBe(before)
    expect(mintMock).toHaveBeenCalledTimes(1)
    expect(notify.warning).toHaveBeenCalledWith('That move is not allowed', reason)

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

  it('a stage drag-cancel ends the coordinator session: a later drop for it is ignored', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const zone = { parent: null, slot: 'body', index: 2, layout: 'linear-vertical' as const }
    bridge.callbacks.dragPropose?.('s3', ['blockaaa0001'], zone)
    bridge.callbacks.dragCancel?.('s3')
    bridge.callbacks.blockDrop?.('s3', ['blockaaa0001'], zone)
    await flushPromises()
    expect(notify.warning).not.toHaveBeenCalled()
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

  describe('sibling multi-selection (visual builder spec §5.5)', () => {
    async function selectPair(wrapper: ReturnType<typeof mountPage>) {
      bridge.callbacks.select?.('blockaaa0001', { shift: false, meta: false })
      bridge.callbacks.select?.('blockbbb0002', { shift: true, meta: false })
      await flushPromises()
      expect(bridge.instance.highlight).toHaveBeenLastCalledWith('blockaaa0001', [
        'blockaaa0001',
        'blockbbb0002',
      ])
      expect(wrapper.find('[data-test="canvas-outline-item-blockbbb0002"]').classes()).toContain(
        'bg-elevated',
      )
    }
    const lastOps = () =>
      (
        applyMock.mock.calls[applyMock.mock.calls.length - 1]![4] as {
          operations: { type: string; transaction_id: string }[]
        }
      ).operations
    const savedIds = () =>
      (
        saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
          fields: { body: { id: string }[] }
        }
      ).fields.body.map((b) => b.id)

    it('a group move is one transaction of MoveBlocks; the stage is patched, not mirrored', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      const wrapper = mountPage()
      await flushPromises()
      await selectPair(wrapper)

      bridge.callbacks.move?.('blockaaa0001', 1)
      await flushPromises()
      expect(bridge.instance.mirrorMove).not.toHaveBeenCalled()
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      expect(savedIds()).toEqual(['prose0000003', 'blockaaa0001', 'blockbbb0002'])

      await wrapper.find('[data-test="canvas-apply"]').trigger('click')
      await flushPromises()
      const ops = lastOps()
      expect(ops.map((o) => o.type)).toEqual(['MoveBlock', 'MoveBlock'])
      expect(new Set(ops.map((o) => o.transaction_id)).size).toBe(1)

      // Up again from the end: back to the start, still selected as a group.
      bridge.callbacks.move?.('blockbbb0002', -1)
      await flushPromises()
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      expect(savedIds()).toEqual(['blockaaa0001', 'blockbbb0002', 'prose0000003'])
      bridge.callbacks.move?.('blockbbb0002', -1) // boundary: nothing moves
      await flushPromises()
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      expect(savedIds()).toEqual(['blockaaa0001', 'blockbbb0002', 'prose0000003'])
      wrapper.unmount()
    })

    it('a group duplicate copies each block after itself and selects the copies', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      const wrapper = mountPage()
      await flushPromises()
      await selectPair(wrapper)

      bridge.callbacks.duplicate?.('blockbbb0002')
      await flushPromises()
      expect(bridge.instance.mirrorDuplicate).toHaveBeenCalledTimes(2)
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      const ids = savedIds()
      expect(ids).toHaveLength(5)
      expect([ids[0], ids[2], ids[4]]).toEqual(['blockaaa0001', 'blockbbb0002', 'prose0000003'])
      expect(bridge.instance.highlight).toHaveBeenLastCalledWith(ids[1], [ids[1], ids[3]])
      wrapper.unmount()
    })

    it('a group delete removes every selected block on one confirm', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      const wrapper = mountPage()
      await flushPromises()
      await selectPair(wrapper)

      bridge.callbacks.deleteRequest?.('blockaaa0001')
      await flushPromises()
      await wrapper.find('[data-test="canvas-delete-confirm-yes"]').trigger('click')
      await flushPromises()
      expect(bridge.instance.mirrorRemove).toHaveBeenCalledTimes(2)
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      expect(savedIds()).toEqual(['prose0000003'])
      expect(wrapper.find('[data-test="block-inspector"]').exists()).toBe(false) // nothing selected
      wrapper.unmount()
    })

    it('a style edit writes one SetSetting per selected block in one transaction', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      const wrapper = mountPage()
      await flushPromises()
      await selectPair(wrapper)
      const inspector = wrapper.findComponent({ name: 'BlockInspector' })
      expect(inspector.props('blocks')).toHaveLength(2)
      inspector.vm.$emit('set-setting', 'spacing.padding.top', 'base', {
        type: 'token',
        value: 'spacing.lg',
      })
      await flushPromises()
      await wrapper.find('[data-test="canvas-apply"]').trigger('click')
      await flushPromises()
      const ops = lastOps() as ({ type: string; transaction_id: string } & Record<
        string,
        unknown
      >)[]
      expect(ops.map((o) => o.type)).toEqual(['SetSetting', 'SetSetting'])
      expect(ops.map((o) => o.block)).toEqual(['blockaaa0001', 'blockbbb0002'])
      expect(new Set(ops.map((o) => o.transaction_id)).size).toBe(1)
      wrapper.unmount()
    })

    it('a block from another slot, or an edit that moves one away, narrows the selection', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      const wrapper = mountPage()
      await flushPromises()
      await selectPair(wrapper)
      // cmd-click drops one sibling out.
      bridge.callbacks.select?.('blockaaa0001', { shift: false, meta: true })
      await flushPromises()
      expect(bridge.instance.highlight).toHaveBeenLastCalledWith('blockbbb0002', ['blockbbb0002'])
      // Deleting the remaining selected block empties the selection.
      bridge.callbacks.deleteRequest?.('blockbbb0002')
      await flushPromises()
      await wrapper.find('[data-test="canvas-delete-confirm-yes"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-test="block-inspector"]').exists()).toBe(false) // nothing selected
      wrapper.unmount()
    })
  })

  describe('the Blocks tab (Phase C.1)', () => {
    const savedBody = () =>
      (
        saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
          fields: { body: { id: string; type: string; data: Record<string, unknown> }[] }
        }
      ).fields.body
    async function openBlocks(wrapper: ReturnType<typeof mountPage>) {
      await wrapper
        .find('[data-test="inspector-tabs"]')
        .findAll('button')
        .find((b) => b.text() === 'Blocks')!
        .trigger('click')
      await flushPromises()
      return wrapper.find('[data-test="blocks-tab"]')
    }

    it('deleting the selected block from the stage falls the inspector back to Content, never a blank pane', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      const wrapper = mountPage()
      await flushPromises()
      bridge.callbacks.select?.('blockaaa0001')
      await flushPromises()
      const active = () =>
        wrapper.find('[data-test="inspector-tabs"] [aria-selected="true"]').text()
      expect(active()).toBe('Block')
      bridge.callbacks.deleteRequest?.('blockaaa0001')
      await flushPromises()
      await wrapper.find('[data-test="canvas-delete-confirm-yes"]').trigger('click')
      await flushPromises()
      expect(active()).toBe('Content')
      expect(wrapper.find('[data-test="block-toggle-blockbbb0002"]').exists()).toBe(true)
      wrapper.unmount()
    })

    it("the stage's empty-slot + arms the Blocks tab into that slot of that block", async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      const wrapper = mountPage()
      await flushPromises()
      bridge.callbacks.slotAdd?.('blockbbb0002', 'body')
      await flushPromises()
      const active = () =>
        wrapper.find('[data-test="inspector-tabs"] [aria-selected="true"]').text()
      expect(active()).toBe('Blocks')
      expect(wrapper.find('[data-test="palette-target"]').text()).toContain(
        'Inserting into card › body',
      )
      bridge.callbacks.slotAdd?.(null, 'body')
      await flushPromises()
      expect(wrapper.find('[data-test="palette-target"]').text()).toContain(
        'Inserting at the end of body',
      )
      wrapper.unmount()
    })

    it('a palette insert opens the Block tab on the new block; its slot rows arm the Blocks tab into that slot', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      blockTypes.value = blockTypes.value.map((t) =>
        t.slug === 'card'
          ? ({
              ...t,
              schema: [
                ...t.schema,
                {
                  name: 'body',
                  type: 'blocks',
                  required: false,
                  localized: false,
                  filterable: false,
                },
              ],
            } as BlockType)
          : t,
      )
      const wrapper = mountPage()
      await flushPromises()
      const tab = await openBlocks(wrapper)
      await tab.find('[data-test="palette-card-card"]').trigger('click')
      await flushPromises()
      const active = () =>
        wrapper.find('[data-test="inspector-tabs"] [aria-selected="true"]').text()
      expect(active()).toBe('Block')
      expect(wrapper.find('[data-test="block-inspector-title"]').text()).toBe('card')
      await wrapper.find('[data-test="block-inspector"] [data-test="add-block"]').trigger('click')
      await flushPromises()
      expect(active()).toBe('Blocks')
      expect(wrapper.find('[data-test="palette-target"]').text()).toContain('card › body')
      wrapper.unmount()
    })

    it('with nothing selected a tile click inserts at the end of body and selects the block', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      const wrapper = mountPage()
      await flushPromises()
      const tab = await openBlocks(wrapper)
      await tab.find('[data-test="palette-card-card"]').trigger('click')
      await flushPromises()
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      const body = savedBody()
      expect(body.map((b) => b.type)).toEqual(['card', 'card', 'rich_text', 'card'])
      expect(body[3]!.data).toEqual({ title: 'Card', body: [] })
      expect(bridge.instance.highlight).toHaveBeenLastCalledWith(body[3]!.id, [body[3]!.id])
      wrapper.unmount()
    })

    it('with a nested block selected the insert lands after it in its OWN sibling list', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      // The card carries a body slot here, so the nested block is part of the tree walk.
      blockTypes.value = blockTypes.value.map((t) =>
        t.slug === 'card'
          ? ({
              ...t,
              schema: [
                ...t.schema,
                {
                  name: 'body',
                  type: 'blocks',
                  required: false,
                  localized: false,
                  filterable: false,
                },
              ],
            } as BlockType)
          : t,
      )
      draft.value = {
        fields: {
          title: 'T',
          body: [
            {
              id: 'blockaaa0001',
              type: 'card',
              data: {
                title: 'A',
                body: [{ id: 'inner0000001', type: 'rich_text', data: { body: '<p>i</p>' } }],
              },
            },
            { id: 'blockbbb0002', type: 'card', data: { title: 'B' } },
          ],
        },
        lock_version: 3,
      }
      const wrapper = mountPage()
      await flushPromises()
      bridge.callbacks.select?.('inner0000001')
      await flushPromises()
      const tab = await openBlocks(wrapper)
      expect(tab.find('[data-test="palette-target"]').exists()).toBe(false) // the default, not armed
      await tab.find('[data-test="palette-card-rich_text"]').trigger('click')
      await flushPromises()
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      const body = savedBody()
      expect(body.map((b) => b.id)).toEqual(['blockaaa0001', 'blockbbb0002'])
      const inner = body[0]!.data.body as { id: string; type: string }[]
      expect(inner.map((b) => b.type)).toEqual(['rich_text', 'rich_text'])
      expect(inner[0]!.id).toBe('inner0000001')
      wrapper.unmount()
    })

    it('a nested-starter refusal keeps the target, warns with the reason and changes nothing', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      blockTypes.value = [
        ...blockTypes.value,
        {
          ...bt('section'),
          schema: [
            {
              name: 'content',
              type: 'blocks',
              required: false,
              localized: false,
              filterable: false,
            },
          ],
        } as BlockType,
      ]
      // Four levels down: one level remains. The tile passes (a height-one placeholder fits);
      // the real instance nests three levels and is refused at commit.
      const nest = (id: string, inner: unknown[]) => ({
        id,
        type: 'section',
        data: { content: inner },
      })
      draft.value = {
        fields: { title: 'T', body: [nest('s1', [nest('s2', [nest('s3', [nest('s4', [])])])])] },
        lock_version: 3,
      }
      factory.instance.mockImplementation(async (slug: string) => ({
        id: 'fresh0000001',
        type: slug,
        data: { content: [nest('n1', [nest('n2', [])])] },
        settings: {},
      }))
      const wrapper = mountPage()
      await flushPromises()
      bridge.callbacks.select?.('s4')
      await flushPromises()
      const tab = await openBlocks(wrapper)
      const before = JSON.stringify(draft.value.fields)
      expect(
        tab.find('[data-test="palette-card-section"]').attributes('aria-disabled'),
      ).toBeUndefined()
      await tab.find('[data-test="palette-card-section"]').trigger('click')
      await flushPromises()
      expect(notify.warning).toHaveBeenCalledWith(
        'That move is not allowed',
        expect.stringMatching(/deep|five|level/i),
      )
      expect(wrapper.find('[data-test="canvas-undo"]').attributes('disabled')).toBeDefined()
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      expect(
        JSON.stringify(
          (saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as { fields: unknown }).fields,
        ),
      ).toBe(before)
      wrapper.unmount()
    })

    it('a click whose target is cleared while the factory loads inserts nothing, with no default substituted', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      let resolveFactory: (b: unknown) => void = () => {}
      factory.instance.mockImplementation(() => new Promise((r) => (resolveFactory = r)))
      const wrapper = mountPage()
      await flushPromises()
      bridge.callbacks.select?.('blockaaa0001')
      await flushPromises()
      const tab = await openBlocks(wrapper)
      await tab.find('[data-test="palette-card-card"]').trigger('click')
      bridge.callbacks.deselect?.('blockaaa0001') // the attempt's intent is gone
      await flushPromises()
      resolveFactory({ id: 'late00000001', type: 'card', data: {}, settings: {} })
      await flushPromises()
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      expect(savedBody().map((b) => b.id)).toEqual(['blockaaa0001', 'blockbbb0002', 'prose0000003'])
      wrapper.unmount()
    })

    it('a click whose anchor moves while the factory loads lands after the new position of the anchor', async () => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
      saveMock.mockResolvedValue(undefined)
      let resolveFactory: (b: unknown) => void = () => {}
      factory.instance.mockImplementation(() => new Promise((r) => (resolveFactory = r)))
      const wrapper = mountPage()
      await flushPromises()
      bridge.callbacks.select?.('blockaaa0001')
      await flushPromises()
      const tab = await openBlocks(wrapper)
      await tab.find('[data-test="palette-card-card"]').trigger('click')
      bridge.callbacks.move?.('blockaaa0001', 1) // the anchor moves while the factory answers
      await flushPromises()
      resolveFactory({ id: 'late00000002', type: 'card', data: {}, settings: {} })
      await flushPromises()
      await wrapper.find('[data-test="canvas-save"]').trigger('click')
      await flushPromises()
      expect(savedBody().map((b) => b.id)).toEqual([
        'blockbbb0002',
        'blockaaa0001',
        'late00000002',
        'prose0000003',
      ])
      wrapper.unmount()
    })
    describe('the structure picker (container-layout spec §6)', () => {
      /** The page knows containers, and the factory makes empty ones. */
      function withContainers() {
        blockTypes.value = [
          ...blockTypes.value,
          {
            ...bt('container'),
            style_capabilities: [
              'layout.display',
              'layout.columns',
              'layout.gap.column',
              'layout.gap.row',
            ],
            schema: [
              {
                name: 'content',
                type: 'blocks',
                required: false,
                localized: false,
                filterable: false,
              },
            ],
          } as BlockType,
        ]
        factory.instance.mockImplementation(async (slug: string) => ({
          id: 'f' + Math.random().toString(36).slice(2, 13).padEnd(11, '0'),
          type: slug,
          data: slug === 'container' ? { content: [] } : { ...(factoryStarter[slug] ?? {}) },
          settings: {},
        }))
      }
      const offered = () =>
        bridge.published[bridge.published.length - 1] as { id: string }[] | undefined

      it('inserting a container from the Blocks tab publishes an offer; a card publishes none', async () => {
        withContainers()
        const wrapper = mountPage()
        await flushPromises()
        const tab = await openBlocks(wrapper)

        await tab.find('[data-test="palette-card-card"]').trigger('click')
        await flushPromises()
        expect(offered() ?? []).toEqual([])

        await (
          await openBlocks(wrapper)
        )
          .find('[data-test="palette-card-container"]')
          .trigger('click')
        await flushPromises()
        expect(offered()).toHaveLength(1)
        wrapper.unmount()
      })

      it('choosing a preset sends every operation in one transaction, in plan order', async () => {
        withContainers()
        const wrapper = mountPage()
        await flushPromises()
        const tab = await openBlocks(wrapper)
        await tab.find('[data-test="palette-card-container"]').trigger('click')
        await flushPromises()
        const id = offered()![0]!.id

        applyMock.mockClear()
        bridge.callbacks.structureChoose!(id, 'cols-33-67')
        await flushPromises()
        await wrapper.find('[data-test="canvas-apply"]').trigger('click')
        await flushPromises()

        const calls = applyMock.mock.calls
        // The operations ride in the apply's options, alongside the pair the client last accepted.
        const sent = calls[calls.length - 1]![4] as {
          operations: { type: string; transaction_id: string }[]
        }
        // The preset's own operations: the container's settings and the columns going into it. The
        // palette's earlier insert of the container is a separate transaction and stays out of this.
        const picked = (
          sent.operations as unknown as {
            type: string
            transaction_id: string
            block?: unknown
            position?: { parent: string | null }
          }[]
        ).filter(
          (op) =>
            (op.type === 'SetSetting' && op.block === id) ||
            (op.type === 'InsertBlock' && op.position?.parent === id),
        )
        expect(picked.length).toBeGreaterThan(2)
        // One transaction: undo takes the whole preset back, never half of it.
        expect(new Set(picked.map((op) => op.transaction_id)).size).toBe(1)
        const types = picked.map((op) => op.type)
        expect(types.lastIndexOf('SetSetting')).toBeLessThan(types.indexOf('InsertBlock'))
        wrapper.unmount()
      })

      it('a legality refusal leaves the document, history and the pending operations alone', async () => {
        withContainers()
        const wrapper = mountPage()
        await flushPromises()
        const tab = await openBlocks(wrapper)
        await tab.find('[data-test="palette-card-container"]').trigger('click')
        await flushPromises()
        const id = offered()![0]!.id

        // The factory answers with a container that already holds a card, which makes the candidate
        // one level deeper than the placeholder the tile was judged on.
        factory.instance.mockImplementation(async (slug: string) => ({
          id: 'f' + Math.random().toString(36).slice(2, 13).padEnd(11, '0'),
          type: slug,
          data: {
            content: [
              {
                id: 'deep00000001',
                type: 'container',
                data: {
                  content: [
                    {
                      id: 'deep00000002',
                      type: 'container',
                      data: {
                        content: [
                          {
                            id: 'deep00000003',
                            type: 'container',
                            data: {
                              content: [
                                {
                                  id: 'deep00000004',
                                  type: 'container',
                                  data: { content: [] },
                                  settings: {},
                                },
                              ],
                            },
                            settings: {},
                          },
                        ],
                      },
                      settings: {},
                    },
                  ],
                },
                settings: {},
              },
            ],
          },
          settings: {},
        }))

        applyMock.mockClear()
        bridge.callbacks.structureChoose!(id, 'cols-33-67')
        await flushPromises()
        await flushPromises()

        // Nothing was sent, and nothing was written: the container is still the empty one.
        expect(applyMock).not.toHaveBeenCalled()
        await wrapper.find('[data-test="canvas-save"]').trigger('click')
        await flushPromises()
        const container = savedBody().find((b) => b.type === 'container')!
        expect(container.data.content).toEqual([])
        // The offer is still standing, with the refusal shown on the tile that caused it.
        const presets = (
          bridge.published[bridge.published.length - 1] as {
            presets: { key: string; enabled: boolean }[]
          }[]
        )[0]!.presets
        expect(presets.find((preset) => preset.key === 'cols-33-67')!.enabled).toBe(false)
        wrapper.unmount()
      })
    })

    describe('Fill empty cells (container-layout spec §11.3)', () => {
      // The stage button has no state of its own, so the two surfaces can only disagree if the
      // page hands them different answers. These compare what the page publishes to the stage
      // with what it hands the inspector, for the same container, after each kind of change.
      const choice = (value: string) => ({ type: 'choice', value })
      const gridOf = (id: string, content: unknown[] = [], at = 'base') => ({
        id,
        type: 'container',
        data: { content },
        settings: {
          style: { layout: { display: { [at]: choice('grid') }, columns: { [at]: choice('3') } } },
        },
      })
      const plainOf = (id: string, content: unknown[]) => ({
        id,
        type: 'container',
        data: { content },
        settings: {},
      })
      function withGrids(body: unknown[]) {
        blockTypes.value = [
          ...blockTypes.value,
          {
            ...bt('container'),
            style_capabilities: ['layout.display', 'layout.columns'],
            schema: [
              {
                name: 'content',
                type: 'blocks',
                required: false,
                localized: false,
                filterable: false,
              },
            ],
          } as BlockType,
        ]
        draft.value = { fields: { title: 'T', body }, lock_version: 3 }
        factory.instance.mockImplementation(async (slug: string) => ({
          id: 'f' + Math.random().toString(36).slice(2, 13).padEnd(11, '0'),
          type: slug,
          data: slug === 'container' ? { content: [] } : { ...factoryStarter[slug] },
          settings: {},
        }))
      }
      type Stage = { id: string; enabled: boolean; preparing: boolean; reason?: string }
      const stage = () => (bridge.fillStates[bridge.fillStates.length - 1] ?? []) as Stage[]
      const onStage = (id: string) => stage().find((entry) => entry.id === id)
      const inInspector = (wrapper: ReturnType<typeof mountPage>) =>
        wrapper.findComponent({ name: 'BlockInspector' }).props('fill') as
          | (Omit<Stage, 'id'> & { visible: boolean; cells: number })
          | null
      /** The inspector's answer in the stage's shape, or undefined where Fill does not apply. */
      const asStage = (wrapper: ReturnType<typeof mountPage>, id: string) => {
        const fill = inInspector(wrapper)
        if (!fill || !fill.visible) return undefined
        return {
          id,
          enabled: fill.enabled,
          preparing: fill.preparing,
          ...(fill.reason !== undefined ? { reason: fill.reason } : {}),
        }
      }
      async function select(id: string) {
        bridge.callbacks.select!(id)
        await flushPromises()
      }

      beforeEach(() => {
        bridge.fillStates.length = 0
      })

      it('publishes every EMPTY grid on load: enabled, disabled with its reason, and nothing else', async () => {
        withGrids([
          gridOf('gridempty001'),
          plainOf('plainempty01', []),
          gridOf('gridfull0001', [{ id: 'cardingrid01', type: 'card', data: { title: 'x' } }]),
          plainOf('wrapa0000001', [
            plainOf('wrapb0000001', [plainOf('wrapc0000001', [gridOf('griddeep0001')])]),
          ]),
        ])
        const wrapper = mountPage()
        await flushPromises()
        expect(stage()).toEqual([
          { id: 'gridempty001', enabled: true, preparing: false },
          {
            id: 'griddeep0001',
            enabled: false,
            preparing: false,
            reason: 'A cell here could not hold a block: blocks nest at most 5 levels deep',
          },
        ])
        wrapper.unmount()
      })

      it('the inspector is handed the same answer as the stage, for an enabled and a refused grid', async () => {
        withGrids([
          gridOf('gridempty001'),
          plainOf('wrapa0000001', [
            plainOf('wrapb0000001', [plainOf('wrapc0000001', [gridOf('griddeep0001')])]),
          ]),
        ])
        const wrapper = mountPage()
        await flushPromises()
        await select('gridempty001')
        expect(asStage(wrapper, 'gridempty001')).toEqual(onStage('gridempty001'))
        expect(inInspector(wrapper)!.cells).toBe(3)
        await select('griddeep0001')
        expect(asStage(wrapper, 'griddeep0001')).toEqual(onStage('griddeep0001'))
        expect(inInspector(wrapper)!.enabled).toBe(false)
        wrapper.unmount()
      })

      it('a block selected that is not a grid is handed nothing', async () => {
        withGrids([plainOf('plainempty01', [])])
        const wrapper = mountPage()
        await flushPromises()
        await select('plainempty01')
        expect(inInspector(wrapper)?.visible ?? false).toBe(false)
        expect(stage()).toEqual([])
        wrapper.unmount()
      })

      it('a breakpoint change moves both: a grid only from md is no grid at base', async () => {
        withGrids([gridOf('gridmd000001', [], 'md')])
        const wrapper = mountPage()
        await flushPromises()
        await select('gridmd000001')
        expect(onStage('gridmd000001')).toMatchObject({ enabled: true })
        expect(asStage(wrapper, 'gridmd000001')).toEqual(onStage('gridmd000001'))

        wrapper
          .findComponent({ name: 'BlockInspector' })
          .vm.$emit('update:activeBreakpoint', 'base')
        await flushPromises()
        expect(onStage('gridmd000001')).toBeUndefined()
        expect(asStage(wrapper, 'gridmd000001')).toBeUndefined()
        wrapper.unmount()
      })

      it('a mode change moves both: the grid set to flex loses Fill on both surfaces', async () => {
        withGrids([gridOf('gridempty001')])
        const wrapper = mountPage()
        await flushPromises()
        await select('gridempty001')
        expect(onStage('gridempty001')).toBeDefined()

        wrapper
          .findComponent({ name: 'BlockInspector' })
          .vm.$emit('set-setting', 'layout.display', 'base', choice('flex'))
        await flushPromises()
        expect(onStage('gridempty001')).toBeUndefined()
        expect(asStage(wrapper, 'gridempty001')).toBeUndefined()
        wrapper.unmount()
      })

      it('a block put into the grid takes the stage entry away and leaves the inspector enabled for the rest of the row', async () => {
        withGrids([gridOf('gridempty001')])
        const wrapper = mountPage()
        await flushPromises()
        bridge.callbacks.slotAdd!('gridempty001', 'content')
        await flushPromises()
        await (await openBlocks(wrapper)).find('[data-test="palette-card-card"]').trigger('click')
        await flushPromises()

        expect(onStage('gridempty001')).toBeUndefined() // no placeholder left to carry a button
        await select('gridempty001')
        expect(inInspector(wrapper)).toMatchObject({ visible: true, enabled: true, cells: 2 })
        wrapper.unmount()
      })

      it('a pending fill is busy on both surfaces, and one request commits one transaction of cells', async () => {
        withGrids([gridOf('gridempty001')])
        let release: (() => void) | null = null
        factory.instance.mockImplementation(
          (slug: string) =>
            new Promise((resolve) => {
              release = () =>
                resolve({ id: 'madecell0001', type: slug, data: { content: [] }, settings: {} })
            }),
        )
        const wrapper = mountPage()
        await flushPromises()
        await select('gridempty001')

        bridge.callbacks.gridFill!('gridempty001')
        await flushPromises()
        expect(onStage('gridempty001')).toMatchObject({ preparing: true })
        expect(asStage(wrapper, 'gridempty001')).toEqual(onStage('gridempty001'))
        bridge.callbacks.gridFill!('gridempty001') // a second request while preparing: ignored
        await flushPromises()
        expect(factory.instance).toHaveBeenCalledTimes(1)

        applyMock.mockClear()
        release!()
        await flushPromises()
        // Filled: not empty any more, so the stage names nothing; the inspector says the row is full.
        expect(onStage('gridempty001')).toBeUndefined()
        expect(inInspector(wrapper)).toMatchObject({
          visible: true,
          enabled: false,
          preparing: false,
          reason: 'No empty cells in the last row',
        })

        await wrapper.find('[data-test="canvas-apply"]').trigger('click')
        await flushPromises()
        const calls = applyMock.mock.calls
        const sent = calls[calls.length - 1]![4] as {
          operations: { type: string; transaction_id: string; position: { parent: string } }[]
        }
        expect(sent.operations.map((op) => op.type)).toEqual([
          'InsertBlock',
          'InsertBlock',
          'InsertBlock',
        ])
        expect(new Set(sent.operations.map((op) => op.transaction_id)).size).toBe(1)
        expect(sent.operations.every((op) => op.position.parent === 'gridempty001')).toBe(true)
        wrapper.unmount()
      })

      it("the inspector's Fill runs the same fill, at the active breakpoint", async () => {
        withGrids([gridOf('gridempty001')])
        const wrapper = mountPage()
        await flushPromises()
        await select('gridempty001')
        wrapper.findComponent({ name: 'BlockInspector' }).vm.$emit('fill-cells')
        await flushPromises()
        expect(inInspector(wrapper)).toMatchObject({ enabled: false, cells: 0 })
        expect(onStage('gridempty001')).toBeUndefined()
        wrapper.unmount()
      })

      it('tells the stage only when the answer changes, and always a stage that has just loaded', async () => {
        // Every message makes the stage re-mark its slots and redraw the outline: an edit that
        // changes nothing about Fill — typing in a card — must not send one.
        mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
        withGrids([
          gridOf('gridempty001'),
          { id: 'cardplain001', type: 'card', data: { title: 'A' } },
        ])
        const wrapper = mountPage()
        await flushPromises()
        await select('cardplain001')
        const sent = bridge.fillStates.length
        expect(sent).toBeGreaterThan(0)

        wrapper.findComponent({ name: 'BlockInspector' }).vm.$emit('patch-data', 'title', 'AB')
        await flushPromises()
        expect(bridge.fillStates.length).toBe(sent)

        await wrapper.find('[data-test="canvas-iframe"]').trigger('load')
        await flushPromises()
        expect(bridge.fillStates.length).toBe(sent + 1)
        expect(onStage('gridempty001')).toMatchObject({ enabled: true })
        wrapper.unmount()
      })

      it('a stage request for a container that cannot be filled commits nothing', async () => {
        withGrids([plainOf('plainempty01', [])])
        const wrapper = mountPage()
        await flushPromises()
        bridge.callbacks.gridFill!('plainempty01')
        bridge.callbacks.gridFill!('nosuchblock1')
        await flushPromises()
        expect(factory.instance).not.toHaveBeenCalled()
        wrapper.unmount()
      })
    })

    describe('a palette drag onto the stage (Phase C.1)', () => {
      const savedBody = () =>
        (
          saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
            fields: { body: { id: string; type: string; data: Record<string, unknown> }[] }
          }
        ).fields.body
      /** Begin a tile drag and move past the threshold over the stage; returns the bridge session. */
      async function dragTile(wrapper: ReturnType<typeof mountPage>, slug: string) {
        const iframe = wrapper.find('[data-test="canvas-iframe"]').element as HTMLIFrameElement
        iframe.getBoundingClientRect = () =>
          ({
            left: 100,
            top: 50,
            width: 400,
            height: 300,
            right: 500,
            bottom: 350,
            x: 100,
            y: 50,
            toJSON: () => ({}),
          }) as DOMRect
        await wrapper
          .find('[data-test="inspector-tabs"]')
          .findAll('button')
          .find((b) => b.text() === 'Blocks')!
          .trigger('click')
        await flushPromises()
        const tile = wrapper.find(`[data-test="palette-card-${slug}"]`).element
        tile.dispatchEvent(
          new MouseEvent('pointerdown', { button: 0, bubbles: true, clientX: 10, clientY: 10 }),
        )
        tile.dispatchEvent(
          new MouseEvent('pointermove', { bubbles: true, clientX: 200, clientY: 100 }),
        )
        await flushPromises() // the factory answers; the session begins
        const begin = bridge.instance.dragBegin as ReturnType<typeof vi.fn>
        expect(begin).toHaveBeenCalledTimes(1)
        const session = begin.mock.calls[0]![0] as string
        expect(begin.mock.calls[0]![1]).toEqual([])
        expect(bridge.instance.dragHover).toHaveBeenLastCalledWith(session, 100, 50)
        return { tile, session }
      }
      const zone = { parent: null, slot: 'body', index: 1, layout: 'linear-vertical' as const }

      it('a proposal is judged, the release asks the stage, and the answered zone inserts one block', async () => {
        mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
        saveMock.mockResolvedValue(undefined)
        const wrapper = mountPage()
        await flushPromises()
        const { tile, session } = await dragTile(wrapper, 'card')
        bridge.callbacks.dragPropose?.(session, [], zone)
        expect(bridge.instance.dragLegality).toHaveBeenLastCalledWith(session, true, '')
        bridge.callbacks.dragPropose?.(session, [], null) // left every slot: nothing to judge
        tile.dispatchEvent(
          new MouseEvent('pointerup', { bubbles: true, clientX: 210, clientY: 110 }),
        )
        expect(bridge.instance.dragDrop).toHaveBeenCalledWith(session, 110, 60)
        expect(notify.warning).not.toHaveBeenCalled()
        bridge.callbacks.blockDrop?.(session, [], zone) // the zone under the released pointer
        await flushPromises()
        expect(bridge.instance.dragEnd).toHaveBeenCalledWith(session)
        await wrapper.find('[data-test="canvas-save"]').trigger('click')
        await flushPromises()
        const body = savedBody()
        expect(body.map((b) => b.type)).toEqual(['card', 'card', 'card', 'rich_text'])
        expect(body[1]!.data).toEqual({ title: 'Card', body: [] })
        expect(bridge.instance.highlight).toHaveBeenLastCalledWith(body[1]!.id, [body[1]!.id])
        wrapper.unmount()
      })

      // A page created with a title and nothing else has no `body` in its fields at all: the
      // stage's empty body slot is still a place a block can be dropped.
      it.each([
        ['never set', { title: 'Blank' }],
        ['null', { title: 'Blank', body: null }],
      ])(
        'a tile dropped on the empty body of a new page (body %s) inserts the first block',
        async (_, fields) => {
          draft.value = { fields, lock_version: 1 } as unknown as typeof draft.value
          mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
          saveMock.mockResolvedValue(undefined)
          const wrapper = mountPage()
          await flushPromises()
          const first = { parent: null, slot: 'body', index: 0, layout: 'linear-vertical' as const }
          const { tile, session } = await dragTile(wrapper, 'card')
          bridge.callbacks.dragPropose?.(session, [], first)
          expect(bridge.instance.dragLegality).toHaveBeenLastCalledWith(session, true, '')
          tile.dispatchEvent(
            new MouseEvent('pointerup', { bubbles: true, clientX: 210, clientY: 110 }),
          )
          bridge.callbacks.blockDrop?.(session, [], first)
          await flushPromises()
          expect(notify.warning).not.toHaveBeenCalled()
          await wrapper.find('[data-test="canvas-save"]').trigger('click')
          await flushPromises()
          expect(savedBody().map((b) => b.type)).toEqual(['card'])
          wrapper.unmount()
        },
      )

      it('a permitted hover then a release over a forbidden slot: the final zone is judged and refused', async () => {
        mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
        saveMock.mockResolvedValue(undefined)
        const wrapper = mountPage()
        await flushPromises()
        const { tile, session } = await dragTile(wrapper, 'card')
        bridge.callbacks.dragPropose?.(session, [], zone)
        expect(bridge.instance.dragLegality).toHaveBeenLastCalledWith(session, true, '')
        tile.dispatchEvent(
          new MouseEvent('pointerup', { bubbles: true, clientX: 210, clientY: 110 }),
        )
        // The stage answers with a slot that does not exist on the anchor: refused at commit.
        bridge.callbacks.blockDrop?.(session, [], {
          parent: 'blockaaa0001',
          slot: 'nope',
          index: 0,
          layout: 'linear-vertical',
        })
        await flushPromises()
        expect(notify.warning).toHaveBeenCalledWith('That move is not allowed', expect.any(String))
        expect(bridge.instance.dragEnd).toHaveBeenCalledWith(session)
        await wrapper.find('[data-test="canvas-save"]').trigger('click')
        await flushPromises()
        expect(savedBody().map((b) => b.id)).toEqual([
          'blockaaa0001',
          'blockbbb0002',
          'prose0000003',
        ])
        wrapper.unmount()
      })

      it('a drag-cancel answer ends the session with nothing inserted; a stale block-drop afterwards is ignored', async () => {
        mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
        saveMock.mockResolvedValue(undefined)
        const wrapper = mountPage()
        await flushPromises()
        const { tile, session } = await dragTile(wrapper, 'card')
        tile.dispatchEvent(
          new MouseEvent('pointerup', { bubbles: true, clientX: 210, clientY: 110 }),
        )
        bridge.callbacks.dragCancel?.(session)
        await flushPromises()
        expect(bridge.instance.dragEnd).toHaveBeenCalledWith(session)
        bridge.callbacks.blockDrop?.(session, [], zone) // late: the helper no longer awaits
        await flushPromises()
        await wrapper.find('[data-test="canvas-save"]').trigger('click')
        await flushPromises()
        expect(savedBody().map((b) => b.id)).toEqual([
          'blockaaa0001',
          'blockbbb0002',
          'prose0000003',
        ])
        wrapper.unmount()
      })
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
      // way the real bridge commits during thallo:edit-flush; Apply must read the
      // tree AFTER that commit landed.
      mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
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
      expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1?canvas=1') // SAME URL
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

  describe('the Block tab edits what the Content tab edits', () => {
    // The Block → Content tab used to be a lesser form: a blocks-typed field was a line of text
    // ("content: 2 blocks") and a rich text body was not there at all. Both now come from the same
    // components the main Content tab uses, inside the root blocks field's own context — the
    // single writer for the tree — so every change is the same operation it always was.
    const childCard = (id: string, title: string) => ({ id, type: 'card', data: { title } })
    function withAContainer() {
      blockTypes.value = [
        ...blockTypes.value,
        {
          ...bt('container'),
          schema: [
            {
              name: 'content',
              type: 'blocks',
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
            {
              id: 'cont00000001',
              type: 'container',
              data: {
                content: [childCard('kidaaaa00001', 'First'), childCard('kidbbbb00002', 'Second')],
              },
            },
            { id: 'prose0000003', type: 'rich_text', data: { body: '<p>old</p>' } },
          ],
        },
        lock_version: 3,
      }
    }
    const inspector = (wrapper: ReturnType<typeof mountPage>) =>
      wrapper.find('[data-test="block-inspector"]')
    async function selectBlock(id: string) {
      bridge.callbacks.select!(id)
      await flushPromises()
    }
    async function applied(wrapper: ReturnType<typeof mountPage>) {
      await wrapper.find('[data-test="canvas-apply"]').trigger('click')
      await flushPromises()
      const calls = applyMock.mock.calls
      return (calls[calls.length - 1]![4] as { operations: Record<string, unknown>[] }).operations
    }

    beforeEach(() => {
      mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    })

    it('a field that holds blocks shows them — the same cards — not a count', async () => {
      withAContainer()
      const wrapper = mountPage()
      await flushPromises()
      await selectBlock('cont00000001')

      expect(inspector(wrapper).find('[data-test="block-card-kidaaaa00001"]').exists()).toBe(true)
      expect(inspector(wrapper).find('[data-test="block-card-kidbbbb00002"]').exists()).toBe(true)
      expect(inspector(wrapper).find('[data-test="region-summary-content"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('a child is edited, reordered and removed from there, each as the operation it always was', async () => {
      withAContainer()
      const wrapper = mountPage()
      await flushPromises()
      await selectBlock('cont00000001')
      applyMock.mockClear()

      await inspector(wrapper).find('[data-test="block-toggle-kidaaaa00001"]').trigger('click')
      await flushPromises()
      const title = inspector(wrapper).find('[data-test="block-card-kidaaaa00001"] input')
      await title.setValue('First, edited')
      await flushPromises()
      await inspector(wrapper).find('[data-test="block-move-down-kidaaaa00001"]').trigger('click')
      await flushPromises()
      await inspector(wrapper).find('[data-test="block-delete-kidbbbb00002"]').trigger('click')
      await flushPromises()
      await inspector(wrapper).find('[data-test="block-delete-confirm"]').trigger('click')
      await flushPromises()

      const ops = await applied(wrapper)
      expect(ops.map((op) => op.type)).toEqual(['SetField', 'MoveBlock', 'RemoveBlock'])
      expect(ops[0]).toMatchObject({
        block: 'kidaaaa00001',
        field: 'title',
        to: { present: true, value: 'First, edited' },
      })
      // "The first one down" is recorded as the equivalent "the second one up".
      expect(ops[1]).toMatchObject({
        block: 'kidbbbb00002',
        from: { parent: 'cont00000001', slot: 'content', index: 1 },
        to: { parent: 'cont00000001', slot: 'content', index: 0 },
      })
      // A removal carries the block it removed, so undo can put it back.
      expect(ops[2]).toMatchObject({
        position: { parent: 'cont00000001', slot: 'content' },
        block: { id: 'kidbbbb00002' },
      })
      // The container is still the selection: working on its children did not leave it.
      expect(wrapper.find('[data-test="block-inspector-title"]').text()).toBe('container')
      wrapper.unmount()
    })

    it("the list's Add arms the Blocks tab into that slot, as the inspector's own Add did", async () => {
      withAContainer()
      const wrapper = mountPage()
      await flushPromises()
      await selectBlock('cont00000001')
      await inspector(wrapper).find('[data-test="add-block"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-test="inspector-tabs"] [aria-selected="true"]').text()).toBe(
        'Blocks',
      )
      expect(wrapper.find('[data-test="palette-target"]').text()).toContain('container › content')
      wrapper.unmount()
    })

    it('a rich text body can be written in the panel: the stage is one way in, not the only one', async () => {
      withAContainer()
      const wrapper = mountPage()
      await flushPromises()
      await selectBlock('prose0000003')
      applyMock.mockClear()

      const editor = inspector(wrapper).findComponent({ name: 'ProseBlockEditor' })
      expect(editor.exists()).toBe(true)
      expect(editor.props('modelValue')).toBe('<p>old</p>')
      expect(editor.props('readonly')).toBe(false)
      expect(inspector(wrapper).find('[data-test="prose-on-stage"]').text()).toContain(
        'on the stage',
      )

      editor.vm.$emit('update:modelValue', '<p>new</p>')
      await flushPromises()
      const ops = await applied(wrapper)
      expect(ops).toEqual([
        expect.objectContaining({ type: 'SetField', block: 'prose0000003', field: 'body' }),
      ])
      wrapper.unmount()
    })

    it('while that block is being edited on the stage the panel editor is read-only, and says why', async () => {
      withAContainer()
      const wrapper = mountPage()
      await flushPromises()
      await selectBlock('prose0000003')
      const editor = () => inspector(wrapper).findComponent({ name: 'ProseBlockEditor' })

      bridge.callbacks.editStart!('prose0000003')
      await flushPromises()
      expect(editor().props('readonly')).toBe(true)
      expect(inspector(wrapper).find('[data-test="prose-locked"]').text()).toContain('Esc')
      // What is typed on the stage shows here as it arrives: one text, one owner at a time.
      bridge.callbacks.textChanged!('prose0000003', 'body', { html: '<p>from the stage</p>' })
      await flushPromises()
      expect(editor().props('modelValue')).toBe('<p>from the stage</p>')

      bridge.callbacks.editEnd!('prose0000003')
      await flushPromises()
      expect(editor().props('readonly')).toBe(false)
      expect(inspector(wrapper).find('[data-test="prose-locked"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('a stage session on ANOTHER block locks nothing here', async () => {
      withAContainer()
      const wrapper = mountPage()
      await flushPromises()
      await selectBlock('prose0000003')
      bridge.callbacks.editStart!('kidaaaa00001')
      await flushPromises()
      expect(inspector(wrapper).findComponent({ name: 'ProseBlockEditor' }).props('readonly')).toBe(
        false,
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
        useCapabilitiesStore: () => ({ isEnabled: () => false, isVisible: () => false }),
      }))
      // Task 12 registered a real entry-editor panel (commerce-link) whose useGate wraps
      // useCommerceMeta() — mock it so this Design-action test (unrelated to Commerce) never
      // depends on the real query/client stack.
      vi.doMock('@/queries/commerceMeta', () => ({
        useCommerceMeta: () => ({ data: ref(undefined), status: ref('error') }),
      }))
      const { default: EditorPage } = await import('@/pages/content/[type]/[uuid]/index.vue')
      const wrapper = mount(EditorPage, {
        shallow: true,
        global: {
          stubs: {
            // Shallow resolves auto-imported Nuxt UI names WITHOUT the U prefix.
            DashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
            DashboardNavbar: {
              template:
                '<div><slot name="leading" /><slot name="title" /><slot name="right" /></div>',
            },
          },
        },
      })
      await flushPromises()
      const link = wrapper.find('[data-test="design-link"]')
      expect(link.exists()).toBe(true)
      expect(link.attributes('to')).toBe('/content/page/entry0000001/design/en')
      wrapper.unmount()

      // A type with no blocks field has nothing to design: no button.
      const loaded = contentTypes.value
      contentTypes.value = loaded.map((t) => ({
        ...t,
        schema: t.schema.filter((f) => f.type !== 'blocks'),
      })) as typeof loaded
      try {
        const bare = mount(EditorPage, {
          shallow: true,
          global: {
            stubs: {
              DashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
              DashboardNavbar: {
                template:
                  '<div><slot name="leading" /><slot name="title" /><slot name="right" /></div>',
              },
            },
          },
        })
        await flushPromises()
        expect(bare.find('[data-test="design-link"]').exists()).toBe(false)
        bare.unmount()
      } finally {
        contentTypes.value = loaded
      }
    })
  })

  describe('auto-apply', () => {
    async function mountAuto() {
      mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
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
        expect(applyMock).toHaveBeenCalledWith(
          'entry0000001',
          'en',
          'tok1',
          expect.anything(),
          expect.anything(),
        )
      } finally {
        vi.useRealTimers()
      }
      wrapper.unmount()
    })

    it('no concurrent applies: a change during flight queues EXACTLY one follow-up', async () => {
      const wrapper = await mountAuto()
      let release!: () => void
      applyMock.mockImplementationOnce(
        () => new Promise((resolve) => (release = () => resolve(nextApplied()))),
      )
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
      applyMock.mockRejectedValueOnce(new ApiError('server error', 500, {}, { success: false }))
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
        .mockImplementation(async () => nextApplied())
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
      expect(localStorage.getItem('thallo.canvas.auto_apply')).toBe('0')
      vi.useFakeTimers()
      try {
        bridge.callbacks.move?.('blockaaa0001', 1)
        await vi.advanceTimersByTimeAsync(2000)
        expect(applyMock).not.toHaveBeenCalled()
      } finally {
        vi.useRealTimers()
      }
      await wrapper.find('[data-test="canvas-auto-toggle"]').trigger('click')
      expect(localStorage.getItem('thallo.canvas.auto_apply')).toBe('1')
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
})

describe('the inspector tabs', () => {
  it('names the page-wide Versions tab with an icon, its label for screen readers and a tooltip', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()
    const tabs = wrapper.find('[data-test="inspector-tabs"]').findAll('[role="tab"]')
    const versions = tabs.find((t) => t.find('[data-test="inspector-tab-icon-versions"]').exists())!
    expect(versions).toBeDefined()
    // No visible label: the name is screen-reader text, so the tab still reads "Versions".
    expect(versions.find('[data-slot="label"]').exists()).toBe(false)
    expect(versions.find('.sr-only').text()).toBe('Versions')
    // The content tabs keep their visible labels.
    const labelled = tabs.map((t) => t.find('[data-slot="label"]')).filter((l) => l.exists())
    expect(labelled.map((l) => l.text())).toEqual(['Content', 'Blocks', 'Outline', 'Page'])
    wrapper.unmount()
  })
})
