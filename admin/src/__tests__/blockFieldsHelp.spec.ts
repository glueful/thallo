import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { mount } from '@vue/test-utils'
import BlockFields from '@/fields/components/blocks/BlockFields.vue'

vi.mock('@/queries/navigation', () => ({ useNavMenus: () => ({ data: ref([]) }) }))

// Product grid spec §3.5, §5.2: a block field shows its schema label, and its help under it.
describe('block field labels and help', () => {
  it('shows the schema label and the help under the field', () => {
    const w = mount(BlockFields, {
      props: {
        block: { id: 'b1', type: 'product-grid', data: {}, settings: {} },
        type: {
          slug: 'product-grid',
          schema: [
            {
              name: 'limit',
              label: 'Products to show',
              type: 'number',
              help: 'Columns step down on phones.',
            },
            { name: 'columns', type: 'number' },
          ],
        },
      } as never,
    })
    expect(w.text()).toContain('Products to show')
    expect(w.find('[data-test="field-help-limit"]').text()).toBe('Columns step down on phones.')
    expect(w.find('[data-test="field-help-columns"]').exists()).toBe(false)
  })
})
