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

  it('dispatches intents to their callbacks with nonce filtering', () => {
    const bridge = useCanvasBridge(ref(null))
    const move = vi.fn()
    const dup = vi.fn()
    const del = vi.fn()
    const add = vi.fn()
    bridge.onBlockMove(move)
    bridge.onBlockDuplicate(dup)
    bridge.onBlockDeleteRequest(del)
    bridge.onBlockAddAfter(add)

    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-move', id: 'b1', delta: -1, nonce: bridge.nonce },
      }),
    )
    expect(move).toHaveBeenCalledWith('b1', -1)
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-move', id: 'b1', delta: 1, nonce: 'wrong' },
      }),
    )
    expect(move).toHaveBeenCalledTimes(1)
    // Malformed delta dropped.
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-move', id: 'b1', delta: 5, nonce: bridge.nonce },
      }),
    )
    expect(move).toHaveBeenCalledTimes(1)

    for (const [type, cb] of [
      ['lemma:block-duplicate', dup],
      ['lemma:block-delete-request', del],
      ['lemma:block-add-after', add],
    ] as const) {
      window.dispatchEvent(
        new MessageEvent('message', { data: { type, id: 'b2', nonce: bridge.nonce } }),
      )
      expect(cb).toHaveBeenCalledWith('b2')
    }
    bridge.dispose()
  })

  it('posts mirror commands to the derived origin with the nonce', () => {
    // Same iframe-double pattern as the highlight/scrollTo test above.
    const postSpy = vi.fn()
    const iframe = ref({
      src: 'https://site.test/_preview/tok123',
      contentWindow: { postMessage: postSpy },
    } as unknown as HTMLIFrameElement)
    const bridge = useCanvasBridge(iframe as Ref<HTMLIFrameElement | null>)

    bridge.mirrorMove('b1', { beforeId: 'b2' })
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:mirror-move', id: 'b1', beforeId: 'b2', nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.mirrorMove('b1', { afterId: 'b3' })
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:mirror-move', id: 'b1', afterId: 'b3', nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.mirrorRemove('b1')
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:mirror-remove', id: 'b1', nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.mirrorDuplicate('b1', { b1: 'b9' })
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:mirror-duplicate', sourceId: 'b1', idMap: { b1: 'b9' }, nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.dispose()
  })

  it('edit messages: grant posts, request/text-changed dispatch, flush resolves on ack', async () => {
    const postSpy = vi.fn()
    const iframe = ref({
      src: 'https://site.test/_preview/tok123',
      contentWindow: { postMessage: postSpy },
    } as unknown as HTMLIFrameElement)
    const bridge = useCanvasBridge(iframe as Ref<HTMLIFrameElement | null>)
    const req = vi.fn()
    const text = vi.fn()
    bridge.onEditRequest(req)
    bridge.onTextChanged(text)

    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:edit-request', id: 'b1', nonce: bridge.nonce },
      }),
    )
    expect(req).toHaveBeenCalledWith('b1')

    bridge.editGrant('b1', 'body')
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:edit-grant', id: 'b1', field: 'body', nonce: bridge.nonce },
      'https://site.test',
    )

    window.dispatchEvent(
      new MessageEvent('message', {
        data: {
          type: 'lemma:text-changed',
          id: 'b1',
          field: 'body',
          html: '<p>x</p>',
          nonce: bridge.nonce,
        },
      }),
    )
    expect(text).toHaveBeenCalledWith('b1', 'body', '<p>x</p>')

    // Flush resolves on the ack (no timers needed).
    const flushed = bridge.editFlush()
    window.dispatchEvent(
      new MessageEvent('message', { data: { type: 'lemma:edit-flushed', nonce: bridge.nonce } }),
    )
    await expect(flushed).resolves.toBeUndefined()
    bridge.dispose()
  })

  it('editFlush falls back to the 200ms timeout when no bridge answers', async () => {
    vi.useFakeTimers()
    try {
      const bridge = useCanvasBridge(ref(null))
      const flushed = bridge.editFlush()
      vi.advanceTimersByTime(250)
      await expect(flushed).resolves.toBeUndefined()
      bridge.dispose()
    } finally {
      vi.useRealTimers()
    }
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

  it('routes structural methods to the field that owns the block', async () => {
    const wrapper = mountEditor()
    await warmBlocksField()
    await flushPromises()
    const api = wrapper.vm as unknown as {
      moveBlockById: (id: string, d: number) => { beforeId: string } | { afterId: string } | null
      duplicateBlockById: (id: string) => { newId: string; idMap: Record<string, string> } | null
      deleteBlockById: (id: string) => boolean
      insertAfterById: (id: string, slug: string) => string | null
      pickerTypesForBlock: (id: string) => { slug: string }[]
    }
    // Unknown id -> safe empties, no throw.
    expect(api.moveBlockById('missing', 1)).toBeNull()
    expect(api.duplicateBlockById('missing')).toBeNull()
    expect(api.deleteBlockById('missing')).toBe(false)
    expect(api.insertAfterById('missing', 'card')).toBeNull()
    expect(api.pickerTypesForBlock('missing')).toEqual([])
    // Owned id routes to the owning field (sidebar's block, not body's).
    const dup = api.duplicateBlockById('inside000001')
    expect(dup).not.toBeNull()
    expect(dup!.idMap['inside000001']).toBe(dup!.newId)
    // pickerTypesForBlock resolves through the owning field's per-list rules.
    expect(api.pickerTypesForBlock('inbody000001').map((t) => t.slug)).toEqual(['card'])
    wrapper.unmount()
  })

  it('routes patchBlockDataById and blockTypeOfBlock to the owning field', async () => {
    await warmBlocksField()
    const wrapper = mountEditor()
    await flushPromises()
    const api = wrapper.vm as unknown as {
      patchBlockDataById: (id: string, f: string, v: unknown) => boolean
      blockTypeOfBlock: (id: string) => string | null
    }
    expect(api.patchBlockDataById('missing', 'x', 1)).toBe(false)
    expect(api.blockTypeOfBlock('missing')).toBeNull()
    expect(api.blockTypeOfBlock('inside000001')).toBe('card')
    expect(api.patchBlockDataById('inside000001', 'title', 'patched')).toBe(true)
    wrapper.unmount()
  })
})
