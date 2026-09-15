// The fragment path on the canvas page (visual builder spec §3.5): an apply that answers
// fragments hands them to the stage; a refused swap falls back to the whole-page refresh; every
// path records its apply-to-paint sample for the development overlay.
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
vi.mock('@/queries/styleClasses', () => ({
  useStyleClasses: () => ({ data: ref({ generation: 0, classes: [] }), refetch: vi.fn() }),
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
      stageFragments: vi.fn(),
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
  bridge.instance.stageFragments.mockReset()
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

const paintTick = () => new Promise((resolve) => setTimeout(resolve, 40)) // past a rAF

describe('the fragment path', () => {
  it('an apply answering fragments hands them to the stage with the pair and baseline', async () => {
    applyMock.mockResolvedValueOnce(accepted('e1', 1)).mockResolvedValueOnce({
      ...accepted('e1', 2),
      fragments: {
        blockaaa0001: '<div class="thallo-preview-block" data-thallo-block="blockaaa0001">A2</div>',
      },
    })
    bridge.instance.stageFragments.mockResolvedValue({ mode: 'patched', epoch: 'e1', revision: 2 })
    const wrapper = await mountAndSettle()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.stageFragments).not.toHaveBeenCalled()
    expect(bridge.instance.stageRefresh).toHaveBeenCalledTimes(1) // no fragments: the page path

    bridge.callbacks.move!('blockbbb0002', -1)
    await flushPromises()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.stageFragments).toHaveBeenCalledTimes(1)
    expect(bridge.instance.stageFragments.mock.calls[0]![0]).toEqual({
      epoch: 'e1',
      revision: 2,
      style_generation: 0,
      baseline_epoch: 'e1',
      baseline_revision: 1,
      fragments: {
        blockaaa0001: '<div class="thallo-preview-block" data-thallo-block="blockaaa0001">A2</div>',
      },
    })
    expect(bridge.instance.stageRefresh).toHaveBeenCalledTimes(1) // a swap needs no refresh
    await paintTick()
    await flushPromises()
    expect(wrapper.find('[data-test="apply-metrics-fragments"]').text()).toContain('fragments ×1')
    expect(wrapper.find('[data-test="apply-metrics-page"]').text()).toContain('page ×1')
    wrapper.unmount()
  })

  it('a refused swap falls back to the whole-page refresh and counts the fallback', async () => {
    applyMock.mockResolvedValueOnce({
      ...accepted('e1', 1),
      fragments: {
        blockaaa0001: '<div class="thallo-preview-block" data-thallo-block="blockaaa0001">A</div>',
      },
    })
    bridge.instance.stageFragments.mockResolvedValue({
      mode: 'failed',
      epoch: null,
      revision: null,
    })
    const wrapper = await mountAndSettle()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.stageFragments).toHaveBeenCalledTimes(1)
    expect(bridge.instance.stageRefresh).toHaveBeenCalledTimes(1)
    await paintTick()
    await flushPromises()
    expect(wrapper.find('[data-test="apply-metrics-fragments"]').text()).toContain('fallbacks 1')
    expect(wrapper.find('[data-test="apply-metrics-page"]').text()).toContain('page ×1')
    expect(notify.error).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('a busy stage neither refreshes nor records: the edit-end re-arm re-applies', async () => {
    applyMock.mockResolvedValueOnce({
      ...accepted('e1', 1),
      fragments: {
        blockaaa0001: '<div class="thallo-preview-block" data-thallo-block="blockaaa0001">A</div>',
      },
    })
    bridge.instance.stageFragments.mockResolvedValue({ mode: 'busy', epoch: null, revision: null })
    const wrapper = await mountAndSettle()
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.stageRefresh).not.toHaveBeenCalled()
    await paintTick()
    await flushPromises()
    expect(wrapper.find('[data-test="apply-metrics"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
