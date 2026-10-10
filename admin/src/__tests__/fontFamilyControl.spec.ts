import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { fontLibrary } from './helpers/fontLibraryFixture'
import type { FontLibraryResult } from '@/queries/fontLibrary'

// The Typeface control (block typeface spec §4.1–§4.4; plan Task 9): the built-ins and the site's
// own families, each set in its own face; the faces line for the chosen one; and a stored family
// that is removed or unknown, named and explained, with a way out.
const library = ref<FontLibraryResult | undefined>(fontLibrary())
vi.mock('@/queries/fontLibrary', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/fontLibrary')>()),
  useFontLibrary: () => ({ data: library }),
}))
vi.mock('@/fonts/loadFamilyFaces', () => ({ loadFamilyFaces: vi.fn(() => Promise.resolve()) }))

const { default: FontFamilyControl } =
  await import('@/editor/inspector/controls/FontFamilyControl.vue')
const { loadFamilyFaces } = await import('@/fonts/loadFamilyFaces')

function mountControl(value: string | null, context: 'block' | 'part' | 'class' = 'block') {
  return mount(FontFamilyControl, {
    props: { value: value === null ? null : { type: 'font', value }, context },
    global: { stubs: { RouterLink: { template: '<a :data-to="to"><slot /></a>', props: ['to'] } } },
  })
}

beforeEach(() => {
  library.value = fontLibrary()
})

describe('FontFamilyControl', () => {
  it('lists the built-ins, then the site’s own families, each set in its own face', () => {
    const w = mountControl(null)
    const builtin = w.find('[data-test="typeface-group-builtin"]')
    const yours = w.find('[data-test="typeface-group-uploaded"]')
    expect(builtin.text()).toContain('Built-in')
    expect(
      builtin.findAll('[data-test^="typeface-option-"]').map((o) => o.attributes('data-test')),
    ).toEqual([
      'typeface-option-theme',
      'typeface-option-serif',
      'typeface-option-humanist',
      'typeface-option-geometric',
      'typeface-option-slab',
      'typeface-option-mono',
      'typeface-option-system',
    ])
    expect(yours.text()).toContain('Your fonts')
    const ids = yours
      .findAll('[data-test^="typeface-option-"]')
      .map((o) => o.attributes('data-test'))
    expect(ids).toEqual([
      'typeface-option-Ab3dE5fG7hJ9',
      'typeface-option-Vr3dE5fG7hJ9',
      'typeface-option-Uk3dE5fG7hJ9',
    ])
    expect(w.find('[data-test="typeface-option-Ab3dE5fG7hJ9"]').attributes('style')).toContain(
      'thallo-font-Ab3dE5fG7hJ9',
    )
    expect(w.find('[data-test="typeface-option-serif"]').attributes('style')).toContain(
      'Iowan Old Style',
    )
    expect(w.find('[data-test="typeface-option-theme"]').attributes('style')).toContain(
      'thallo-theme-face',
    )
    expect(w.find('[data-test="typeface-option-theme"]').attributes('title')).toBe(
      'The theme’s original face.',
    )
    expect(loadFamilyFaces).toHaveBeenCalled()
  })

  it('picks a family by its ID', async () => {
    const w = mountControl(null)
    await w.find('[data-test="typeface-option-Vr3dE5fG7hJ9"]').trigger('click')
    expect(w.emitted('pick')).toEqual([['Vr3dE5fG7hJ9']])
    expect(w.find('[data-test="typeface-option-Vr3dE5fG7hJ9"]').attributes('aria-pressed')).toBe(
      'false',
    )
    expect(
      mountControl('Vr3dE5fG7hJ9')
        .find('[data-test="typeface-option-Vr3dE5fG7hJ9"]')
        .attributes('aria-pressed'),
    ).toBe('true')
  })

  it.each([
    ['Ab3dE5fG7hJ9', 'Faces: 400, 700, 400 italic'],
    ['Vr3dE5fG7hJ9', 'Faces: 300–900 variable'],
    ['Uk3dE5fG7hJ9', 'Unknown faces'],
    ['serif', 'Provided by the visitor’s device'],
    ['theme', 'Supplied by the theme'],
  ])('describes %s’s faces', (id, line) => {
    expect(mountControl(id).find('[data-test="typeface-faces"]').text()).toBe(line)
  })

  it('says when the theme declares no face', () => {
    library.value = fontLibrary({ theme_face: { declared: false, family: null, files: [] } })
    expect(mountControl('theme').find('[data-test="typeface-faces"]').text()).toBe(
      'This theme declares no face; Theme uses the system stack',
    )
  })

  it('names a removed family and explains what renders', async () => {
    const w = mountControl('Rm3dE5fG7hJ9')
    expect(w.find('[data-test="typeface-missing-option"]').text()).toBe('Removed typeface: Gone')
    expect(w.find('[data-test="typeface-missing-option"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="typeface-missing-line"]').text()).toBe(
      'Renders inheriting the enclosing font.',
    )
    expect(w.find('[data-test="typeface-restore"]').attributes('data-to')).toBe(
      '/appearance?tab=typefaces#typefaces',
    )
    await w.find('[data-test="typeface-clear"]').trigger('click')
    expect(w.emitted('clear')).toEqual([[]])
    expect(w.find('[data-test="typeface-choose-another"]').exists()).toBe(true)
  })

  it('names an unknown ID, and offers Restore only to who can manage the library', () => {
    library.value = fontLibrary({ can_manage: false })
    const w = mountControl('Zz9yX8wV7uT6')
    expect(w.find('[data-test="typeface-missing-option"]').text()).toBe(
      'Unknown typeface (Zz9yX8wV7uT6)',
    )
    expect(w.find('[data-test="typeface-restore"]').exists()).toBe(false)
    expect(mountControl('Rm3dE5fG7hJ9').find('[data-test="typeface-restore"]').exists()).toBe(false)
  })

  it('in a style class, shows the same list and faces line', () => {
    const w = mountControl('Ab3dE5fG7hJ9', 'class')
    expect(w.findAll('[data-test^="typeface-option-"]')).toHaveLength(10)
    expect(w.find('[data-test="typeface-faces"]').text()).toBe('Faces: 400, 700, 400 italic')
  })
})
