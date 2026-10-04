// The Search block's scope in the inspector (search block spec §3.9): a string field that names an
// `options_source` gets its choices from the server, and its stored value — kept even when its
// feature is off — is passed through untouched; the other fields render as they always do.
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { mount } from '@vue/test-utils'
import BlockFields from '@/fields/components/blocks/BlockFields.vue'
import OptionsSourceField from '@/fields/components/OptionsSourceField.vue'
import type { BlockType } from '@/queries/blockTypes'
import { toFieldDef } from '@/fields/normalize'

vi.mock('@/queries/navigation', () => ({ useNavMenus: () => ({ data: ref([]) }) }))
vi.mock('@/queries/fieldOptions', () => ({
  useFieldOptions: () => ({
    data: ref([
      { value: '', label: 'All results', available: true, reason: null },
      { value: 'products', label: 'Products', available: false, reason: 'Requires Commerce' },
    ]),
    status: ref('success'),
  }),
}))

const search = {
  uuid: 'search',
  slug: 'search',
  label: 'Search',
  icon: null,
  category: 'Site',
  description: null,
  active: true,
  schema: [
    { name: 'display', type: 'enum', enum: ['field', 'icon'] },
    { name: 'placeholder', type: 'string' },
    { name: 'scope', type: 'string', options_source: 'thallo-search.scopes' },
    { name: 'live_results', type: 'boolean' },
  ],
  style_capabilities: [],
  style_targets: null,
  flags: null,
  starter_content: null,
} as unknown as BlockType

describe('the Search block in the inspector', () => {
  it('maps options_source onto the field definition', () => {
    expect(
      toFieldDef({ name: 'scope', type: 'string', options_source: 'thallo-search.scopes' } as never)
        .optionsSource,
    ).toBe('thallo-search.scopes')
  })

  it('renders the scope from the server, the placeholder as a plain string field, and keeps the stored scope', async () => {
    const wrapper = mount(BlockFields, {
      props: {
        block: { id: 'b1', type: 'search', data: { scope: 'products' }, settings: {} },
        type: search,
      } as never,
    })
    const scope = wrapper.findAllComponents(OptionsSourceField)
    expect(scope).toHaveLength(1)
    expect(scope[0]!.props('modelValue')).toBe('products')
    expect(wrapper.find('[data-test="options-source-scope"]').text()).toContain('requires commerce')
    expect(wrapper.emitted('patch')).toBeUndefined()

    await scope[0]!.vm.$emit('update:modelValue', '')
    expect(wrapper.emitted('patch')?.[0]).toEqual(['scope', ''])
  })
})
