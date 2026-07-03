import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, type Ref } from 'vue'
import { useCanvasBridge } from '@/composables/useCanvasBridge'
import type { BlockType } from '@/queries/blockTypes'

const blockTypes = ref<BlockType[]>([])

vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: blockTypes }),
}))
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/x', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import FieldEditor from '@/components/FieldEditor.vue'

describe('useCanvasBridge', () => {
  it('drops messages with a foreign nonce and dispatches matching ones', () => {
    const iframe = ref<HTMLIFrameElement | null>(null)
    const bridge = useCanvasBridge(iframe)
    const seen: string[] = []
    bridge.onBlockSelect((id) => seen.push(id))
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-select', nonce: 'WRONG', id: 'b1' },
      }),
    )
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-select', nonce: bridge.nonce, id: 'b2' },
      }),
    )
    expect(seen).toEqual(['b2'])
    bridge.dispose()
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-select', nonce: bridge.nonce, id: 'b3' },
      }),
    )
    expect(seen).toEqual(['b2']) // disposed: listener removed
  })

  it('highlight/scrollTo post nonce-carrying messages to the iframe origin', () => {
    const postMessage = vi.fn()
    const iframe = ref({
      src: 'https://site.test/_preview/tok123',
      contentWindow: { postMessage },
    } as unknown as HTMLIFrameElement)
    const bridge = useCanvasBridge(iframe as Ref<HTMLIFrameElement | null>)
    bridge.highlight('b1')
    bridge.scrollTo('b2')
    bridge.hello()
    expect(postMessage).toHaveBeenNthCalledWith(
      1,
      { type: 'lemma:highlight', id: 'b1', nonce: bridge.nonce },
      'https://site.test',
    )
    expect(postMessage).toHaveBeenNthCalledWith(
      2,
      { type: 'lemma:scroll-to', id: 'b2', nonce: bridge.nonce },
      'https://site.test',
    )
    expect(postMessage).toHaveBeenNthCalledWith(
      3,
      { type: 'lemma:canvas-hello', nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.dispose()
  })

  it('blocks-index dispatch filters non-strings', () => {
    const bridge = useCanvasBridge(ref(null))
    let ids: string[] = []
    bridge.onBlocksIndex((v) => (ids = v))
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:blocks-index', nonce: bridge.nonce, ids: ['a', 1, 'b', null] },
      }),
    )
    expect(ids).toEqual(['a', 'b'])
    bridge.dispose()
  })
})

describe('FieldEditor.selectBlockById', () => {
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

  beforeEach(() => {
    setActivePinia(createPinia())
    blockTypes.value = [bt('card')]
  })

  const twoFieldSchema = [
    { name: 'title', type: 'string' as const, required: false },
    { name: 'body', type: 'blocks' as const, required: false },
    { name: 'sidebar', type: 'blocks' as const, required: false },
  ]

  // The registry loads BlocksField via defineAsyncComponent: awaiting the module
  // import (then a flush) is what deterministically resolves it in jsdom.
  async function warmBlocksField(): Promise<void> {
    await import('@/fields/components/BlocksField.vue')
    await flushPromises()
    await flushPromises()
  }

  function mountEditor(schema = twoFieldSchema) {
    const model = ref<Record<string, unknown>>({
      title: 'X',
      body: [{ id: 'inbody000001', type: 'card', data: { title: 'A' } }],
      sidebar: [{ id: 'inside000001', type: 'card', data: { title: 'B' } }],
    })
    const wrapper = mount(FieldEditor, {
      props: {
        schema,
        modelValue: model.value,
        'onUpdate:modelValue': (v: Record<string, unknown>) => (model.value = v),
      },
      attachTo: document.body,
    })
    return wrapper
  }

  it('routes selection to the OWNING blocks field across multiple fields', async () => {
    const wrapper = mountEditor()
    await warmBlocksField()
    const vm = wrapper.vm as unknown as { selectBlockById: (id: string) => boolean }
    expect(vm.selectBlockById('inside000001')).toBe(true)
    await flushPromises()
    // The sidebar field's block got expanded/focused (header focus is the signal).
    expect(document.activeElement?.getAttribute('data-test')).toBe('block-toggle-inside000001')
    expect(vm.selectBlockById('nonexistent1')).toBe(false)
    wrapper.unmount()
  })

  it('does not consult stale refs after a blocks field is removed from the schema', async () => {
    const wrapper = mountEditor()
    await warmBlocksField()
    // Remove `sidebar` from the schema (rerender) — its ref must be DELETED.
    await wrapper.setProps({
      schema: twoFieldSchema.filter((f) => f.name !== 'sidebar'),
    })
    await flushPromises()
    const vm = wrapper.vm as unknown as { selectBlockById: (id: string) => boolean }
    expect(vm.selectBlockById('inside000001')).toBe(false) // never throws, never stale
    expect(vm.selectBlockById('inbody000001')).toBe(true)
    wrapper.unmount()
  })
})
