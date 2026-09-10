import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { BlockType } from '@/queries/blockTypes'

const blockTypes = ref<BlockType[]>([])

vi.mock('@/queries/blockTypes', () => ({
  useBlockTypes: () => ({ data: blockTypes, status: ref('success') }),
  useBlockTypeMutations: () => ({ setActive: { mutateAsync: vi.fn() } }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import BlockTypesPage from '@/pages/settings/block-types/index.vue'

// The "New block type" and edit buttons are RouterLinks: `useLink()` needs an injected router.
function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
  })
  return mount(BlockTypesPage, { global: { plugins: [router] } })
}

const type = (
  slug: string,
  label: string,
  category: string,
  description: string | null = null,
): BlockType => ({
  uuid: `uuid-${slug}`,
  slug,
  label,
  icon: null,
  category,
  description,
  active: true,
  schema: [],
})

// Nuxt UI's UInput forwards attrs to the native input; tolerate either placement.
function searchInput(wrapper: VueWrapper) {
  const direct = wrapper.find('input[data-test="block-type-search"]')
  return direct.exists() ? direct : wrapper.find('[data-test="block-type-search"] input')
}

const rows = (wrapper: VueWrapper): string[] =>
  wrapper
    .findAll('[data-test^="block-type-row-"]')
    .map((el) => el.attributes('data-test')!.replace('block-type-row-', ''))

describe('block types page: search', () => {
  beforeEach(() => {
    blockTypes.value = [
      type('hero', 'Hero', 'Layout'),
      type('product-grid', 'Product grid', 'Commerce', 'A grid of products from a category.'),
      type('rich_text', 'Rich text', 'Content'),
    ]
  })

  it('lists every block type when the search is empty', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(searchInput(wrapper).exists()).toBe(true)
    expect(rows(wrapper).sort()).toEqual(['hero', 'product-grid', 'rich_text'])
  })

  it('matches label, slug or description, ignoring case', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await searchInput(wrapper).setValue('PRODUCT')
    expect(rows(wrapper)).toEqual(['product-grid'])

    await searchInput(wrapper).setValue('rich_')
    expect(rows(wrapper)).toEqual(['rich_text'])

    await searchInput(wrapper).setValue('from a category')
    expect(rows(wrapper)).toEqual(['product-grid'])

    await searchInput(wrapper).setValue('')
    expect(rows(wrapper)).toHaveLength(3)
  })

  it('drops category sections that have no match and explains an empty result', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await searchInput(wrapper).setValue('hero')
    expect(wrapper.text()).toContain('Layout')
    expect(wrapper.text()).not.toContain('Commerce')

    await searchInput(wrapper).setValue('no such block')
    expect(rows(wrapper)).toEqual([])
    expect(wrapper.text()).toContain('No block types match')
  })
})
