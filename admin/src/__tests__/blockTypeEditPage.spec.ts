// The block type editor's Style settings card: what it sends, and what it never sends.
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import { ApiError } from '@/api/errors'

const blockTypes = ref<BlockType[]>([])
const styleOptions = ref({ options: ['spacing', 'colors', 'radius'], codeDeclared: ['button'] })
const updateMock = vi.fn()
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const routeSlug = ref('promo')

vi.mock('@/queries/blockTypes', () => ({
  useBlockTypes: () => ({ data: blockTypes, status: ref('success') }),
  useBlockTypeStyleOptions: () => ({ data: styleOptions }),
  useBlockTypeMutations: () => ({
    update: { mutateAsync: updateMock, isLoading: ref(false) },
    setActive: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
  useBlockTypeMigrations: () => ({ data: ref([]) }),
}))
vi.mock('@/queries/contentTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/contentTypes')>()),
  validateContentTypeFields: () => null,
}))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ params: { slug: routeSlug.value } }),
  useRouter: () => ({ push: vi.fn() }),
}))

import EditPage from '@/pages/settings/block-types/[slug].vue'

const type = (slug: string, caps: string[] | null): BlockType => ({
  uuid: `uuid-${slug}`,
  slug,
  label: slug,
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: caps,
  style_targets: null,
  flags: {},
  starter_content: null,
})
const stubs = {
  RouterLink: { template: '<a><slot /></a>' },
  ContentTypeFields: { template: '<div />' },
  BlockTypeLifecycle: { template: '<div />' },
}
const mountPage = () => mount(EditPage, { global: { stubs } })
const box = (w: ReturnType<typeof mountPage>, group: string) =>
  w.find(`[data-test="style-group-option-${group}"] input[type="checkbox"]`)
const save = async (w: ReturnType<typeof mountPage>) => {
  await w.find('[data-test="block-type-save"]').trigger('click')
  await flushPromises()
  return updateMock.mock.calls[updateMock.mock.calls.length - 1]![0] as Record<string, unknown>
}

describe('block type editor: style settings', () => {
  beforeEach(() => {
    routeSlug.value = 'promo'
    blockTypes.value = [type('promo', ['spacing']), type('button', ['spacing', 'radius'])]
    updateMock.mockReset().mockResolvedValue(undefined)
    notify.error.mockClear()
  })

  it('a changed choice is sent; an untouched one is not sent at all', async () => {
    const w = mountPage()
    await flushPromises()
    expect((box(w, 'spacing').element as HTMLInputElement).checked).toBe(true)

    // Untouched: the key is absent, which the server reads as "leave the declaration alone".
    expect(await save(w)).not.toHaveProperty('style_capabilities')

    await box(w, 'radius').setValue(true)
    expect(await save(w)).toMatchObject({
      slug: 'promo',
      style_capabilities: ['spacing', 'radius'],
    })

    // Saved: that choice is the baseline now, so the next untouched save omits it again.
    expect(await save(w)).not.toHaveProperty('style_capabilities')

    // Cleared: an empty list, which the server reads as "no declaration".
    await box(w, 'spacing').setValue(false)
    await box(w, 'radius').setValue(false)
    expect(await save(w)).toMatchObject({ style_capabilities: [] })
  })

  it('the server’s refusal is shown on the card, beside the choice it refuses', async () => {
    updateMock.mockRejectedValue(
      new ApiError(
        'Validation failed',
        422,
        { style_capabilities: 'blocks/promo.twig does not emit these settings yet.' },
        null,
      ),
    )
    const w = mountPage()
    await flushPromises()
    await box(w, 'colors').setValue(true)
    await save(w)
    expect(w.find('[data-test="style-settings-error"]').text()).toContain('does not emit')
  })

  it('a code-declared type shows its groups read-only and never sends them', async () => {
    routeSlug.value = 'button'
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="style-code-declared"]').exists()).toBe(true)
    expect(await save(w)).not.toHaveProperty('style_capabilities')
  })
})
