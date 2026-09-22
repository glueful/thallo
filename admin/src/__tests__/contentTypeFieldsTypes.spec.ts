import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'
import type { ContentTypeField } from '@/queries/contentTypes'

vi.mock('@/queries/blockTypes', () => ({ useBlockTypes: () => ({ data: ref([]) }) }))
vi.mock('@/queries/styleSchema', () => ({
  useStyleSchema: () => ({
    data: ref({ vocabulary: { domains: { spacing: ['sm'], color: ['accent'] }, values: {} } }),
  }),
}))
vi.mock('@/queries/contentTypes', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useContentTypes: () => ({ data: ref([]) }),
}))

import ContentTypeFields from '@/components/ContentTypeFields.vue'
import { FIELD_TYPES } from '@/queries/contentTypes'

const field = (changes: Partial<ContentTypeField>): ContentTypeField => ({
  name: 'accent',
  type: 'string',
  required: false,
  localized: false,
  filterable: false,
  enum: [],
  ...changes,
})

// USelect's v-model listener sits on Reka's SelectRoot (the data-test lands on the trigger).
function selectRoot(wrapper: VueWrapper, test: string) {
  const root = wrapper
    .findAllComponents({ name: 'SelectRoot' })
    .find((r) => r.element.querySelector?.(`[data-test="${test}"]`))
  if (!root) throw new Error(`no select ${test}`)
  return root
}

function mountWith(fields: ContentTypeField[]) {
  const model = ref(fields)
  const wrapper: VueWrapper = mount(ContentTypeFields, {
    props: {
      modelValue: model.value,
      'onUpdate:modelValue': (v: ContentTypeField[]) => {
        model.value = v
        void wrapper.setProps({ modelValue: v })
      },
    },
  })
  return { wrapper, model }
}

describe('content-type field types', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('offers box, which the server accepts', () => {
    expect(FIELD_TYPES).toContain('box')
  })

  it('a token field picks the vocabulary domain the server requires', async () => {
    const { wrapper, model } = mountWith([field({ type: 'token' })])
    await flushPromises()

    const select = selectRoot(wrapper, 'token-domain')
    select.vm.$emit('update:modelValue', 'color')
    await flushPromises()
    expect(model.value[0]!.domain).toBe('color')
  })

  it('switching away from token drops the domain', async () => {
    const { wrapper, model } = mountWith([field({ type: 'token', domain: 'color' })])
    await flushPromises()
    const typeSelect = selectRoot(wrapper, 'field-type')
    typeSelect.vm.$emit('update:modelValue', 'string')
    await flushPromises()
    expect(model.value[0]!.domain).toBeUndefined()
  })
})
