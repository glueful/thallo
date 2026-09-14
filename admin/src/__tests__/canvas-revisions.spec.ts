// The three revisions on the canvas page (visual builder spec §3.5): local (history), accepted
// (the pair the server returned) and displayed (what the stage patched to), plus undo/redo.
import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { ApiError } from '@/api/errors'
import type { BlockType } from '@/queries/blockTypes'

const blockTypes = ref<BlockType[]>([])
vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: blockTypes }),
}))

const { mintMock, applyMock } = vi.hoisted(() => ({ mintMock: vi.fn(), applyMock: vi.fn() }))
vi.mock('@/queries/preview', () => ({ mintPreviewData: mintMock, applyPreview: applyMock }))
vi.mock('@/queries/styleSchema', () => ({ useStyleSchema: () => ({ data: ref(null) }) }))

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
    move?: (id: string, d: 1 | -1) => void
    textChanged?: (id: string, field: string, payload: { html?: string; text?: string }) => void
  } = {}
  const noop = () => undefined
  return {
    callbacks,
    instance: {
      nonce: 'n',
      hello: vi.fn(),
      onBlockSelect: noop,
      onBlockDeselect: noop,
      onBlockHover: noop,
      onBlocksIndex: noop,
      onBlockMove: (cb: (id: string, d: 1 | -1) => void) => (callbacks.move = cb),
      onBlockMoveTo: noop,
      onBlockDuplicate: noop,
      onBlockDeleteRequest: noop,
      onBlockAddAfter: noop,
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
        UDashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
        UDashboardNavbar: {
          template: '<div><slot name="leading" /><slot name="title" /><slot name="right" /></div>',
        },
        RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
      },
    },
    attachTo: document.body,
  })
}

const accepted = (epoch: string, revision: number) => ({
  epoch,
  revision,
  baseline: revision - 1,
  style_generation: 0,
  applied_at: '2026-09-14T00:00:00Z',
})
const lastApplyOptions = () =>
  applyMock.mock.calls[applyMock.mock.calls.length - 1]![4] as {
    epoch: string | null
    base_revision: number | null
    operations: { type: string }[]
  }
const bodyIds = (): string[] =>
  (
    (
      saveMock.mock.calls[saveMock.mock.calls.length - 1]?.[0] as {
        fields: { body: { id: string }[] }
      }
    )?.fields.body ?? []
  ).map((b) => b.id)

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
        { id: 'blockaaa0001', type: 'card', data: { title: 'A' }, settings: {} },
        { id: 'blockbbb0002', type: 'card', data: { title: 'B' }, settings: {} },
      ],
    },
    lock_version: 3,
  }
  mintMock.mockReset()
  applyMock.mockReset()
  saveMock.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
  bridge.instance.stageRefresh.mockReset()
  bridge.instance.stageRefresh.mockResolvedValue({ mode: 'patched', epoch: 'e1', revision: 1 })
  mintMock.mockResolvedValue({
    token: 'tok1',
    themeUrl: 'https://site.test/_preview/tok1',
    accepted: null,
  })
  localStorage.setItem('thallo.canvas.auto_apply', '0')
})

async function mountAndSettle() {
  const wrapper = mountPage()
  await flushPromises()
  await flushPromises()
  return wrapper
}

describe('the three revisions', () => {
  it('the first apply sends a null pair; every later apply names the accepted pair and the ops since it', async () => {
    applyMock.mockResolvedValueOnce(accepted('e1', 1)).mockResolvedValueOnce(accepted('e1', 2))
    const wrapper = await mountAndSettle()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(lastApplyOptions()).toMatchObject({ epoch: null, base_revision: null })

    bridge.callbacks.move!('blockbbb0002', -1) // an edit committed: L advances, A unchanged
    await flushPromises()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    const options = lastApplyOptions()
    expect(options).toMatchObject({ epoch: 'e1', base_revision: 1 })
    expect(options.operations.map((o) => o.type)).toEqual(['MoveBlock'])
    wrapper.unmount()
  })

  it('a mint that names an accepted pair initialises from it (second editor)', async () => {
    mintMock.mockResolvedValue({
      token: 'tok1',
      themeUrl: 'https://site.test/_preview/tok1',
      accepted: { epoch: 'e9', revision: 4 },
    })
    applyMock.mockResolvedValue(accepted('e9', 5))
    const wrapper = await mountAndSettle()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(lastApplyOptions()).toMatchObject({ epoch: 'e9', base_revision: 4 })
    wrapper.unmount()
  })

  it('a stale 409 adopts the current pair, refreshes the stage and retries once', async () => {
    applyMock.mockResolvedValueOnce(accepted('e1', 1))
    const wrapper = await mountAndSettle()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    applyMock
      .mockRejectedValueOnce(
        new ApiError(
          'stale',
          409,
          {},
          {
            error: {
              details: { code: 'PREVIEW_REVISION_STALE', current: { epoch: 'e1', revision: 7 } },
            },
          },
        ),
      )
      .mockResolvedValueOnce(accepted('e1', 8))
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()
    expect(applyMock).toHaveBeenCalledTimes(3)
    expect(lastApplyOptions()).toMatchObject({ epoch: 'e1', base_revision: 7 })
    expect(wrapper.find('[data-test="canvas-iframe"]').element).not.toBe(before) // refreshed from accepted
    expect(notify.error).not.toHaveBeenCalled()

    applyMock.mockResolvedValueOnce(accepted('e1', 9))
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(lastApplyOptions()).toMatchObject({ epoch: 'e1', base_revision: 8 })
    wrapper.unmount()
  })

  it('a response from another epoch or not newer than accepted is dropped', async () => {
    applyMock.mockResolvedValueOnce(accepted('e1', 3)).mockResolvedValueOnce(accepted('e0', 9))
    const wrapper = await mountAndSettle()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.stageRefresh).toHaveBeenCalledTimes(1) // the dropped response never refreshed
    applyMock.mockResolvedValueOnce(accepted('e1', 4))
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(lastApplyOptions()).toMatchObject({ epoch: 'e1', base_revision: 3 })
    wrapper.unmount()
  })

  it('a baseline mismatch or a stale fetch refreshes the stage from accepted state', async () => {
    applyMock.mockResolvedValue(accepted('e1', 1))
    bridge.instance.stageRefresh.mockResolvedValue({ mode: 'stale', epoch: 'e1', revision: 0 })
    const wrapper = await mountAndSettle()
    const before = wrapper.find('[data-test="canvas-iframe"]').element
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-iframe"]').element).not.toBe(before)
    wrapper.unmount()
  })
})

describe('saves and the saved position', () => {
  it('a save carries the accepted revision; when it clears the copy the next apply starts fresh', async () => {
    applyMock.mockResolvedValueOnce(accepted('e1', 1)).mockResolvedValueOnce(accepted('e2', 1))
    saveMock.mockResolvedValue({ data: { preview_cleared: true } })
    const wrapper = await mountAndSettle()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(saveMock).toHaveBeenCalledWith(expect.objectContaining({ preview_revision: 1 }))
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(lastApplyOptions()).toMatchObject({ epoch: null, base_revision: null })
    wrapper.unmount()
  })

  it('a save of revision 10 completing after an edit to 11 leaves 11 dirty', async () => {
    let resolveSave: (v: unknown) => void = () => undefined
    saveMock.mockImplementation(() => new Promise((resolve) => (resolveSave = resolve)))
    const wrapper = await mountAndSettle()
    bridge.callbacks.move!('blockbbb0002', -1) // sequence 1: dirty
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-save"]').exists()).toBe(true)
    await wrapper.find('[data-test="canvas-save"]').trigger('click') // submitted from sequence 1
    await flushPromises()
    bridge.callbacks.textChanged!('blockaaa0001', 'title', { text: 'A2' }) // sequence 2 while in flight
    await new Promise((r) => setTimeout(r, 600)) // the text transaction commits after 500 ms idle
    await flushPromises()
    resolveSave({ data: { preview_cleared: false } })
    await flushPromises()
    draft.value = { fields: { ...draft.value!.fields }, lock_version: 4 } // the refetch after the save
    await flushPromises()
    expect(notify.success).toHaveBeenCalled()
    // Still dirty: the edit after the submitted sequence was never saved — and never clobbered.
    expect(wrapper.find('[data-test="canvas-undo"]').attributes('disabled')).toBeUndefined()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[1]![0] as { fields: { body: { data: { title: string } }[] } }
    expect(saved.fields.body.map((b) => b.data.title)).toEqual(['B', 'A2'])
    wrapper.unmount()
  })
})

describe('undo and redo', () => {
  it('undo reverts a stage move and redo replays it; the next apply carries the inverse ops', async () => {
    applyMock.mockResolvedValue(accepted('e1', 1))
    saveMock.mockResolvedValue({ data: { preview_cleared: false } })
    const wrapper = await mountAndSettle()
    expect(wrapper.find('[data-test="canvas-undo"]').attributes('disabled')).toBeDefined()

    bridge.callbacks.move!('blockbbb0002', -1)
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-undo"]').attributes('disabled')).toBeUndefined()

    await wrapper.find('[data-test="canvas-undo"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(bodyIds()).toEqual(['blockaaa0001', 'blockbbb0002'])
    expect(wrapper.find('[data-test="canvas-redo"]').attributes('disabled')).toBeUndefined()

    await wrapper.find('[data-test="canvas-redo"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(bodyIds()).toEqual(['blockbbb0002', 'blockaaa0001'])

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(lastApplyOptions().operations.map((o) => o.type)).toEqual([
      'MoveBlock',
      'MoveBlock',
      'MoveBlock',
    ])
    wrapper.unmount()
  })

  it('⌘Z and ⇧⌘Z drive undo and redo outside text inputs', async () => {
    const wrapper = await mountAndSettle()
    bridge.callbacks.move!('blockbbb0002', -1)
    await flushPromises()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', metaKey: true }))
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-redo"]').attributes('disabled')).toBeUndefined()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', metaKey: true, shiftKey: true }))
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-redo"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })
})
