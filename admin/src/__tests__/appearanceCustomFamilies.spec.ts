import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { fontLibrary } from './helpers/fontLibraryFixture'
import type { FontLibraryResult } from '@/queries/fontLibrary'

// Custom's Text and Headings pick from the font library (block typeface spec §2.8; plan Task 11):
// Not set, the built-ins and the site's own families, each in its own face; a saved family since
// removed is named; and "Add a font…" adds a family and picks it.
const library = ref<FontLibraryResult | undefined>(fontLibrary())
const create = vi.hoisted(() => vi.fn())
vi.mock('@/queries/fontLibrary', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/fontLibrary')>()),
  useFontLibrary: () => ({ data: library }),
  useFontLibraryMutations: () => ({
    create: { mutateAsync: create, isLoading: { value: false } },
    update: { mutateAsync: vi.fn(), isLoading: { value: false } },
  }),
}))
const upload = vi.hoisted(() => vi.fn())
vi.mock('@/queries/media', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/media')>()),
  uploadBlob: upload,
}))
vi.mock('@/fonts/loadFamilyFaces', () => ({ loadFamilyFaces: vi.fn(() => Promise.resolve()) }))

const { default: FontFamilyPicker } =
  await import('@/pages/appearance/components/FontFamilyPicker.vue')

function mountPicker(modelValue: string) {
  return mount(FontFamilyPicker, {
    props: { modelValue, label: 'Text' },
    global: {
      stubs: {
        Modal: {
          props: ['open', 'title'],
          template:
            '<div v-if="open" data-test="modal"><slot name="body" /><slot name="footer" /></div>',
        },
      },
    },
  })
}

beforeEach(() => {
  library.value = fontLibrary()
  create.mockReset()
  upload.mockReset()
})

describe('FontFamilyPicker', () => {
  it('offers Not set, the built-ins and the site’s families, each in its own face', () => {
    const w = mountPicker('')
    const options = w.findAll('[data-test^="family-option-"]').map((o) => o.attributes('data-test'))
    expect(options).toEqual([
      'family-option-unset',
      'family-option-theme',
      'family-option-serif',
      'family-option-humanist',
      'family-option-geometric',
      'family-option-slab',
      'family-option-mono',
      'family-option-system',
      'family-option-Ab3dE5fG7hJ9',
      'family-option-Vr3dE5fG7hJ9',
      'family-option-Uk3dE5fG7hJ9',
    ])
    expect(w.find('[data-test="family-option-unset"]').attributes('aria-pressed')).toBe('true')
    expect(w.find('[data-test="family-option-Ab3dE5fG7hJ9"]').attributes('style')).toContain(
      'thallo-font-Ab3dE5fG7hJ9',
    )
  })

  it('picks a family, and Not set clears it', async () => {
    const w = mountPicker('serif')
    await w.find('[data-test="family-option-Ab3dE5fG7hJ9"]').trigger('click')
    await w.find('[data-test="family-option-unset"]').trigger('click')
    expect(w.emitted('update:modelValue')).toEqual([['Ab3dE5fG7hJ9'], ['']])
  })

  it('names a saved family that has since been removed', () => {
    const w = mountPicker('Rm3dE5fG7hJ9')
    expect(w.find('[data-test="family-missing"]').text()).toContain('Removed typeface: Gone')
    expect(w.find('[data-test="family-option-Rm3dE5fG7hJ9"]').exists()).toBe(false)
  })

  it('adds a font from the picker and picks it', async () => {
    upload.mockResolvedValue({ blob_uuid: 'blobnew00001' })
    create.mockResolvedValue('Nw3dE5fG7hJ9')
    const w = mountPicker('')
    await w.find('[data-test="family-add"]').trigger('click')
    await w.find('[data-test="font-family-name"]').setValue('New face')
    const input = w.find('[data-test="font-files"]')
    Object.defineProperty(input.element, 'files', {
      value: [new File(['x'], 'new.woff2', { type: 'font/woff2' })],
    })
    await input.trigger('change')
    await w.find('[data-test="font-family-save"]').trigger('click')
    await flushPromises()
    expect(w.emitted('update:modelValue')).toEqual([['Nw3dE5fG7hJ9']])
  })
})
