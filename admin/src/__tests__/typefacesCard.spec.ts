import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { ApiError } from '@/api/errors'
import { fontLibrary } from './helpers/fontLibraryFixture'
import type { FontLibraryResult, FontUsage } from '@/queries/fontLibrary'

// The Typefaces card (block typeface spec §4.6; plan Task 11): the built-ins and the site's own
// families, each set in its own face; adding a family from .woff2 files; editing, removing (after
// saying where it is used), restoring and deleting permanently; and reading a family's files again.
const library = ref<FontLibraryResult | undefined>(fontLibrary())
const usage = vi.hoisted(() => vi.fn())
const m = vi.hoisted(() => {
  const op = () => ({ mutateAsync: vi.fn(), isLoading: { value: false } })
  return {
    create: op(),
    update: op(),
    addFace: op(),
    remove: op(),
    restore: op(),
    purge: op(),
    readAgain: op(),
  }
})
vi.mock('@/queries/fontLibrary', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/fontLibrary')>()),
  useFontLibrary: () => ({ data: library }),
  fetchFontUsage: usage,
  useFontLibraryMutations: () => m,
}))
const upload = vi.hoisted(() => vi.fn())
vi.mock('@/queries/media', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/media')>()),
  uploadBlob: upload,
}))
vi.mock('@/fonts/loadFamilyFaces', () => ({ loadFamilyFaces: vi.fn(() => Promise.resolve()) }))
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

const { default: TypefacesCard } = await import('@/pages/appearance/components/TypefacesCard.vue')

const NO_USE: FontUsage = {
  entries: [],
  regions: [],
  layouts: [],
  saved_sections: [],
  style_classes: [],
  appearance: { text: false, headings: false },
}

function mountCard() {
  return mount(TypefacesCard, {
    attachTo: document.body,
    global: {
      stubs: {
        // The auto-imported UModal registers under its file name.
        Modal: {
          props: ['open', 'title'],
          template:
            '<div v-if="open" data-test="modal"><h3>{{ title }}</h3><slot name="body" /><slot name="footer" /></div>',
        },
        RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
      },
    },
  })
}

function woff2(name: string): File {
  return new File([new Uint8Array([0x77, 0x4f, 0x46, 0x32])], name, { type: 'font/woff2' })
}

async function choose(w: ReturnType<typeof mountCard>, files: File[]): Promise<void> {
  const input = w.find('[data-test="font-files"]')
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
}

beforeEach(() => {
  library.value = fontLibrary()
  usage.mockReset().mockResolvedValue(NO_USE)
  upload.mockReset()
  for (const op of Object.values(m)) op.mutateAsync.mockReset()
})

describe('TypefacesCard', () => {
  it('lists the built-ins, each in its own face', () => {
    const w = mountCard()
    const rows = w.findAll('[data-test^="typeface-builtin-"]')
    expect(rows.map((r) => r.attributes('data-test'))).toEqual([
      'typeface-builtin-theme',
      'typeface-builtin-serif',
      'typeface-builtin-humanist',
      'typeface-builtin-geometric',
      'typeface-builtin-slab',
      'typeface-builtin-mono',
      'typeface-builtin-system',
    ])
    expect(w.find('[data-test="typeface-builtin-serif"] [data-test="specimen"]').attributes('style')).toContain(
      'Georgia',
    )
  })

  it('shows each family: its name as text, its specimen, faces, fallback, and how often it is used', async () => {
    library.value = fontLibrary()
    library.value.families.find((f) => f.id === 'Ab3dE5fG7hJ9')!.name = '<b>Brand</b>'
    usage.mockImplementation(async (id: string) =>
      id === 'Ab3dE5fG7hJ9' ? { ...NO_USE, regions: ['header'], appearance: { text: true, headings: false } } : NO_USE,
    )
    const w = mountCard()
    const row = w.find('[data-test="typeface-family-Ab3dE5fG7hJ9"]')
    expect(row.find('[data-test="family-name"]').text()).toBe('<b>Brand</b>')
    expect(row.find('b').exists()).toBe(false)
    expect(row.find('[data-test="specimen"]').attributes('style')).toContain('thallo-font-Ab3dE5fG7hJ9')
    expect(row.text()).toContain('Faces: 400, 700, 400 italic')
    expect(row.text()).toContain('Falls back to serif')
    await flushPromises()
    expect(usage).toHaveBeenCalledWith('Ab3dE5fG7hJ9')
    expect(row.find('[data-test="family-usage"]').text()).toBe('Used in 2 places')
    // Removed families are not among them.
    expect(w.find('[data-test="typeface-family-Rm3dE5fG7hJ9"]').exists()).toBe(false)
  })

  it('adds a family: each file uploaded, then the family made from them', async () => {
    upload.mockImplementation(async (file: File) => ({ blob_uuid: `blob-${file.name}` }))
    m.create.mutateAsync.mockResolvedValue('Nw3dE5fG7hJ9')
    const w = mountCard()
    await w.find('[data-test="typefaces-add"]').trigger('click')
    await w.find('[data-test="font-family-name"]').setValue('New face')
    await choose(w, [woff2('a.woff2'), woff2('b.woff2')])
    await w.find('[data-test="font-family-save"]').trigger('click')
    await flushPromises()
    expect(upload.mock.calls.map((c) => (c[0] as File).name)).toEqual(['a.woff2', 'b.woff2'])
    expect(m.create.mutateAsync).toHaveBeenCalledWith({
      name: 'New face',
      fallback: 'sans-serif',
      blob_uuids: ['blob-a.woff2', 'blob-b.woff2'],
    })
    expect(w.find('[data-test="modal"]').exists()).toBe(false)
  })

  it("says why a file was refused, and flags a file chosen twice", async () => {
    upload.mockImplementation(async (file: File) => ({ blob_uuid: `blob-${file.name}` }))
    m.create.mutateAsync.mockRejectedValue(
      new ApiError('bad.woff2: Not a WOFF2 file', 422, { blob_uuids: 'bad.woff2: Not a WOFF2 file' }, null),
    )
    const w = mountCard()
    await w.find('[data-test="typefaces-add"]').trigger('click')
    await w.find('[data-test="font-family-name"]').setValue('Bad')
    await choose(w, [woff2('bad.woff2'), woff2('bad.woff2')])
    expect(w.find('[data-test="font-file-duplicate"]').text()).toContain('bad.woff2 is chosen twice')
    await w.find('[data-test="font-family-save"]').trigger('click')
    await flushPromises()
    expect(upload).toHaveBeenCalledTimes(1)
    expect(w.find('[data-test="font-family-error"]').text()).toBe('bad.woff2: Not a WOFF2 file')
    expect(w.find('[data-test="modal"]').exists()).toBe(true)
  })

  it('accepts only .woff2 files', async () => {
    const w = mountCard()
    await w.find('[data-test="typefaces-add"]').trigger('click')
    await choose(w, [new File(['x'], 'face.ttf', { type: 'font/ttf' })])
    expect(w.find('[data-test="font-file-refused"]').text()).toContain('face.ttf isn’t a .woff2 file')
    expect(w.find('[data-test="font-family-save"]').attributes('disabled')).toBeDefined()
  })

  it('edits a family’s name and fallback', async () => {
    m.update.mutateAsync.mockResolvedValue('Ab3dE5fG7hJ9')
    const w = mountCard()
    await w.find('[data-test="typeface-family-Ab3dE5fG7hJ9"] [data-test="family-edit"]').trigger('click')
    const name = w.find('[data-test="font-family-name"]')
    expect((name.element as HTMLInputElement).value).toBe('Brand')
    await name.setValue('Brand Two')
    await w.find('[data-test="font-family-fallback-monospace"]').trigger('click')
    await w.find('[data-test="font-family-save"]').trigger('click')
    await flushPromises()
    expect(m.update.mutateAsync).toHaveBeenCalledWith({
      id: 'Ab3dE5fG7hJ9',
      name: 'Brand Two',
      fallback: 'monospace',
    })
  })

  it('removing says where the family is used and what happens there, then removes it', async () => {
    usage.mockResolvedValue({
      ...NO_USE,
      entries: [{ uuid: 'entry0000001', title: 'About', locale: 'en', draft: true, published: true, versions: false }],
      regions: ['header'],
      style_classes: [{ id: 'class0000001', name: 'Brand type' }],
      appearance: { text: true, headings: true },
    })
    m.remove.mutateAsync.mockResolvedValue({})
    const w = mountCard()
    await flushPromises()
    await w.find('[data-test="typeface-family-Ab3dE5fG7hJ9"] [data-test="family-remove"]').trigger('click')
    await flushPromises()
    const dialog = w.find('[data-test="font-usage"]')
    expect(dialog.find('[data-test="usage-entries"]').text()).toContain('About')
    expect(dialog.find('[data-test="usage-regions"]').text()).toContain('Header')
    expect(dialog.find('[data-test="usage-style_classes"]').text()).toContain('Brand type')
    expect(dialog.text()).toContain("Blocks using it inherit their parent's font.")
    expect(dialog.text()).toContain(
      "Appearance falls back: Text to the theme's face, Headings to Text.",
    )
    expect(dialog.find('a[href="/regions"]').exists()).toBe(true)
    expect(dialog.find('a[href="/settings/style-classes/class0000001"]').exists()).toBe(true)
    await w.find('[data-test="font-usage-confirm"]').trigger('click')
    await flushPromises()
    expect(m.remove.mutateAsync).toHaveBeenCalledWith('Ab3dE5fG7hJ9')
  })

  it('keeps removed families folded away, with Restore and Delete permanently', async () => {
    m.restore.mutateAsync.mockResolvedValue({})
    m.purge.mutateAsync.mockResolvedValue({})
    const w = mountCard()
    const removed = w.find('[data-test="typefaces-removed"]')
    expect(removed.find('[data-test="typeface-removed-Rm3dE5fG7hJ9"]').isVisible()).toBe(false)
    await removed.find('[data-test="typefaces-removed-toggle"]').trigger('click')
    const row = w.find('[data-test="typeface-removed-Rm3dE5fG7hJ9"]')
    expect(row.isVisible()).toBe(true)
    await row.find('[data-test="family-restore"]').trigger('click')
    await flushPromises()
    expect(m.restore.mutateAsync).toHaveBeenCalledWith('Rm3dE5fG7hJ9')
    await row.find('[data-test="family-purge"]').trigger('click')
    expect(m.purge.mutateAsync).not.toHaveBeenCalled()
    await w.find('[data-test="family-purge-confirm"]').trigger('click')
    await flushPromises()
    expect(m.purge.mutateAsync).toHaveBeenCalledWith('Rm3dE5fG7hJ9')
  })

  it('offers Read again only for unknown faces, warning that the font may render differently', async () => {
    m.readAgain.mutateAsync.mockResolvedValue({})
    const w = mountCard()
    expect(w.find('[data-test="typeface-family-Ab3dE5fG7hJ9"] [data-test="family-read-again"]').exists()).toBe(
      false,
    )
    const row = w.find('[data-test="typeface-family-Uk3dE5fG7hJ9"]')
    await row.find('[data-test="family-read-again"]').trigger('click')
    expect(w.text()).toContain('If this succeeds, the font may render differently.')
    await w.find('[data-test="family-read-again-confirm"]').trigger('click')
    await flushPromises()
    expect(m.readAgain.mutateAsync).toHaveBeenCalledWith('Uk3dE5fG7hJ9')
  })
})
