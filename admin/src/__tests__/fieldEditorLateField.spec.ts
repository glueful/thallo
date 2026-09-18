import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { computed, defineAsyncComponent, defineComponent, h, nextTick } from 'vue'

// The blocks field is an async component, so it can register with FieldEditor AFTER the page has
// already asked for a block — a stage click lands while its chunk is still loading. The page reads
// the selected block through a computed over `blockById`; that computed must see the field arrive,
// or the inspector stays on "Select a block" for a block the stage shows as selected.

const BLOCK = { id: 'grid00000001', type: 'container', data: {}, settings: {} }

let release: () => void = () => undefined
const loaded = new Promise<void>((resolve) => {
  release = resolve
})

vi.mock('@/fields/registry', () => ({
  fieldComponent: () =>
    defineAsyncComponent(async () => {
      await loaded
      return defineComponent({
        props: { field: { type: Object, required: true }, modelValue: null },
        setup(_, { expose }) {
          expose({
            hasBlock: (id: string) => id === BLOCK.id,
            findBlock: (id: string) => (id === BLOCK.id ? BLOCK : null),
          })
          return () => h('div')
        },
      })
    }),
}))

import FieldEditor from '@/components/FieldEditor.vue'

describe('FieldEditor with a blocks field that loads late', () => {
  it('lets a computed over blockById see the field once it registers', async () => {
    const wrapper = mount(FieldEditor, {
      props: { schema: [{ name: 'body', type: 'blocks' }], modelValue: { body: [BLOCK] } },
    })
    const editor = wrapper.vm as unknown as { blockById: (id: string) => typeof BLOCK | null }
    const selectedBlock = computed(() => editor.blockById(BLOCK.id))

    // Asked before the field's chunk has loaded: nothing owns the block yet.
    expect(selectedBlock.value).toBeNull()

    release()
    await flushPromises()
    await nextTick()

    expect(selectedBlock.value).toEqual(BLOCK)
  })
})
