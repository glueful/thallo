// The entry's Design view under a layout (type layouts spec §6.3): the strip names the layout and
// links to its editor; the Page tab's Type layout | Theme template control writes
// `_presentation.use_layout`; and the strip, the control and Show page title follow the layout each
// ACCEPTED apply answers — never a pending or out-of-date one — within one session, with no re-mint.
import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'

const blockTypes = ref<BlockType[]>([])
vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: blockTypes }),
}))

const { mintMock, applyMock } = vi.hoisted(() => ({ mintMock: vi.fn(), applyMock: vi.fn() }))
vi.mock('@/queries/preview', () => ({ mintPreviewData: mintMock, applyPreview: applyMock }))
vi.mock('@/queries/styleSchema', () => ({ useStyleSchema: () => ({ data: ref(null) }) }))
const { styleClassList, refetchClasses, classMutations } = vi.hoisted(() => ({
  styleClassList: {
    value: {
      generation: 0,
      classes: [] as {
        id: string
        name: string
        style: Record<string, unknown>
        archived: boolean
        locked_by_job: string | null
      }[],
    },
  },
  refetchClasses: { fn: async () => {}, calls: 0 },
  classMutations: { create: vi.fn(), deleteUnreferenced: vi.fn() },
}))
vi.mock('@/queries/styleClasses', () => ({
  useStyleClasses: () => ({
    data: styleClassList,
    refetch: () => {
      refetchClasses.calls++
      return refetchClasses.fn()
    },
  }),
  useStyleClassMutations: () => ({
    create: { mutateAsync: classMutations.create, isLoading: { value: false } },
    deleteUnreferenced: {
      mutateAsync: classMutations.deleteUnreferenced,
      isLoading: { value: false },
    },
  }),
}))

const draft = ref<{ fields: Record<string, unknown>; lock_version: number } | null>(null)
const { saveMock } = vi.hoisted(() => ({ saveMock: vi.fn() }))
const publishMock = vi.hoisted(() => vi.fn())
vi.mock('@/queries/publish', () => ({
  usePublish: () => ({ mutateAsync: publishMock, isLoading: ref(false) }),
}))
vi.mock('@/queries/drafts', () => ({
  useDraft: () => ({ data: draft }),
  useSaveDraft: () => ({ mutateAsync: saveMock, isLoading: ref(false) }),
}))

const contentTypes = ref([
  {
    slug: 'post',
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

vi.mock('@/fields/components/blocks/ProseBlockEditor.vue', () => ({
  default: {
    name: 'ProseBlockEditor',
    props: ['modelValue', 'placeholder', 'pickerTypes'],
    emits: ['update:modelValue', 'insert-block'],
    template: '<div data-test="prose-editor-stub" />',
  },
}))

const bridge = vi.hoisted(() => {
  const callbacks: {
    select?: (id: string) => void
    move?: (id: string, d: 1 | -1) => void
    history?: (direction: 'undo' | 'redo') => void
    textChanged?: (id: string, field: string, payload: { html?: string; text?: string }) => void
  } = {}
  const noop = () => undefined
  return {
    callbacks,
    instance: {
      nonce: 'n',
      hello: vi.fn(),
      onBlockSelect: (cb: (id: string) => void) => (callbacks.select = cb),
      onBlockDeselect: noop,
      onBlockHover: noop,
      onBlocksIndex: noop,
      onBlockMove: (cb: (id: string, d: 1 | -1) => void) => (callbacks.move = cb),
      onDragPropose: noop,
      onBlockDrop: noop,
      onDragCancel: noop,
      onBlockDuplicate: noop,
      onBlockDeleteRequest: noop,
      onBlockAddAfter: noop,
      onSlotAdd: noop,
      publishStructureOffers: noop,
      onStructureChoose: noop,
      onStructureSkip: noop,
      publishGridFill: noop,
      onGridFill: noop,
      onHistory: (cb: (direction: 'undo' | 'redo') => void) => (callbacks.history = cb),
      onEditRequest: noop,
      onTextChanged: (
        cb: (id: string, field: string, payload: { html?: string; text?: string }) => void,
      ) => (callbacks.textChanged = cb),
      editGrant: vi.fn(),
      editFlush: vi.fn().mockResolvedValue(undefined),
      stageRefresh: vi.fn(),
      onEditStart: noop,
      onEditEnd: noop,
      onScroll: noop,
      onSessionExpired: noop,
      restoreScroll: vi.fn(),
      highlight: vi.fn(),
      scrollTo: vi.fn(),
      playMotion: vi.fn(),
      mirrorMove: vi.fn(),
      dragBegin: vi.fn(),
      dragHover: vi.fn(),
      dragLegality: vi.fn(),
      dragEnd: vi.fn(),
      mirrorRemove: vi.fn(),
      mirrorDuplicate: vi.fn(),
      dispose: vi.fn(),
    },
  }
})
vi.mock('@/composables/useCanvasBridge', () => ({ useCanvasBridge: () => bridge.instance }))

vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ params: { type: 'post', uuid: 'entry0000001', locale: 'en' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import DesignPage from '@/pages/content/[type]/[uuid]/design/[locale].vue'

function mountPage() {
  return mount(DesignPage, {
    global: {
      stubs: {
        UDashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
        UDashboardNavbar: {
          template: '<div><slot name="leading" /><slot name="title" /><slot name="right" /></div>',
        },
        RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
        Tooltip: { template: '<div><slot /></div>' },
      },
    },
    attachTo: document.body,
  })
}

const POSTS = { surface: 'entry', target: 'post', label: 'Posts — single post' }
let revision = 0
const accepted = (layout: typeof POSTS | null, rev = ++revision) => ({
  epoch: 'e1',
  revision: rev,
  baseline: rev - 1,
  style_generation: 0,
  applied_at: '2026-09-26T00:00:00Z',
  fragments: null,
  layout,
})
const lastFields = () =>
  applyMock.mock.calls[applyMock.mock.calls.length - 1]![3] as {
    _presentation?: Record<string, unknown>
  }

beforeAll(async () => {
  await import('@/fields/components/BlocksField.vue')
})

beforeEach(() => {
  setActivePinia(createPinia())
  revision = 0
  blockTypes.value = []
  draft.value = { fields: { title: 'Hello', body: [] }, lock_version: 3 }
  mintMock.mockReset().mockResolvedValue({
    token: 'tok1',
    themeUrl: 'https://site.test/_preview/tok1',
    accepted: null,
    layout: POSTS,
  })
  applyMock.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
  bridge.instance.stageRefresh.mockReset()
  bridge.instance.stageRefresh.mockResolvedValue({ mode: 'reloaded', epoch: null, revision: null })
  localStorage.setItem('thallo.canvas.auto_apply', '0')
})

async function mountAndSettle() {
  const wrapper = mountPage()
  await flushPromises()
  await flushPromises()
  return wrapper
}
type Page = Awaited<ReturnType<typeof mountAndSettle>>
const strip = (w: Page) => w.find('[data-test="design-layout-strip"]')
const titleControl = (w: Page) => w.find('[data-test="pres-title-default"]')
async function apply(w: Page) {
  await w.find('[data-test="canvas-apply"]').trigger('click')
  await flushPromises()
}

describe('the Design view under a layout', () => {
  it('names the layout, links to its editor, and says why Show page title does not apply', async () => {
    const w = await mountAndSettle()
    expect(strip(w).text()).toContain('This post uses the Posts layout')
    expect(w.find('[data-test="design-layout-edit"]').attributes('href')).toBe(
      '/layouts/entry/post',
    )
    expect(titleControl(w).exists()).toBe(false)
    expect(w.find('[data-test="pres-title-layout-note"]').exists()).toBe(true)
    expect(w.find('[data-test="page-use-layout-layout"]').exists()).toBe(true)
    w.unmount()
  })

  it('opting out and back in, in one session: the page follows each accepted apply', async () => {
    const w = await mountAndSettle()
    await w.find('[data-test="page-use-layout-theme"]').trigger('click')
    await flushPromises()
    // Pending: the acknowledged state stays shown until the server answers.
    expect(strip(w).exists()).toBe(true)
    applyMock.mockResolvedValueOnce(accepted(null))
    await apply(w)
    expect(lastFields()._presentation).toEqual({ use_layout: false })
    expect(strip(w).exists()).toBe(false)
    expect(titleControl(w).exists()).toBe(true)
    // The control stays so the page can opt back in.
    expect(w.find('[data-test="page-use-layout-theme"]').exists()).toBe(true)

    await w.find('[data-test="page-use-layout-layout"]').trigger('click')
    await flushPromises()
    applyMock.mockResolvedValueOnce(accepted(POSTS))
    await apply(w)
    expect(lastFields()._presentation).toBeUndefined()
    expect(strip(w).exists()).toBe(true)
    expect(titleControl(w).exists()).toBe(false)
    expect(mintMock).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('undo and redo of the opt-out each apply, and the page follows every answer', async () => {
    const w = await mountAndSettle()
    await w.find('[data-test="page-use-layout-theme"]').trigger('click')
    await flushPromises()
    applyMock.mockResolvedValueOnce(accepted(null))
    await apply(w)
    expect(strip(w).exists()).toBe(false)

    await w.find('[data-test="canvas-undo"]').trigger('click')
    await flushPromises()
    applyMock.mockResolvedValueOnce(accepted(POSTS))
    await apply(w)
    expect(strip(w).exists()).toBe(true)

    await w.find('[data-test="canvas-redo"]').trigger('click')
    await flushPromises()
    applyMock.mockResolvedValueOnce(accepted(null))
    await apply(w)
    expect(strip(w).exists()).toBe(false)
    expect(titleControl(w).exists()).toBe(true)
    w.unmount()
  })

  it('an out-of-date answer is dropped and changes neither the strip nor the controls', async () => {
    const w = await mountAndSettle()
    // Apply #1 accepted at revision 5, still under the layout.
    await w.find('[data-test="page-use-layout-theme"]').trigger('click')
    await flushPromises()
    applyMock.mockResolvedValueOnce(accepted(POSTS, 5))
    await apply(w)
    expect(strip(w).exists()).toBe(true)
    // A delayed answer from before it, carrying an older revision and no layout: dropped.
    await w.find('[data-test="page-use-layout-layout"]').trigger('click')
    await flushPromises()
    applyMock.mockResolvedValueOnce(accepted(null, 4))
    await apply(w)
    expect(strip(w).exists()).toBe(true)
    expect(titleControl(w).exists()).toBe(false)
    w.unmount()
  })
})
