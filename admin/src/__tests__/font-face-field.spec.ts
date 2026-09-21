import { describe, it, expect, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

// One of the site's own typefaces: a woff2 uploaded to the media library and named by its uuid.
// The field shows the face itself — a specimen set in it — because a file name says nothing about
// what a font looks like.
const upload = vi.hoisted(() => vi.fn())
const notifyError = vi.hoisted(() => vi.fn())
vi.mock('@/queries/media', () => ({
  useUploadMedia: () => ({ mutateAsync: upload, isLoading: { value: false } }),
  blobDisplayUrl: (uuid: string) => `/v1/blobs/${uuid}`,
}))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => ({ error: notifyError }) }))

import FontFaceField from '@/pages/appearance/components/FontFaceField.vue'

const mountField = (modelValue: string) =>
  mount(FontFaceField, { props: { modelValue, role: 'body', label: 'Text' } })
const pick = async (w: ReturnType<typeof mountField>, file: File) => {
  const input = w.find('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
  await flushPromises()
}

beforeEach(() => {
  upload.mockReset()
  notifyError.mockReset()
})

describe('FontFaceField', () => {
  it('with no face, offers the upload and only takes woff2', () => {
    const w = mountField('')
    expect(w.find('[data-test="font-specimen-body"]').exists()).toBe(false)
    expect(w.find('input[type="file"]').attributes('accept')).toBe('.woff2,font/woff2')
    expect(w.text()).toContain('Upload a .woff2')
  })

  it('uploads a woff2 as a public file and becomes its uuid', async () => {
    upload.mockResolvedValue({ blob_uuid: 'fontbody0001' })
    const w = mountField('')
    const file = new File(['x'], 'Brand.woff2', { type: 'font/woff2' })
    await pick(w, file)
    // Public: the site serves it to every visitor, like a logo.
    expect(upload).toHaveBeenCalledWith({ file, visibility: 'public' })
    expect(w.emitted('update:modelValue')).toEqual([['fontbody0001']])
  })

  it('refuses anything that is not a woff2 before it is sent', async () => {
    const w = mountField('')
    await pick(w, new File(['x'], 'Brand.ttf', { type: 'font/ttf' }))
    expect(upload).not.toHaveBeenCalled()
    expect(notifyError).toHaveBeenCalled()
    expect(w.emitted('update:modelValue')).toBeUndefined()
    // A browser that names no type is judged by the extension.
    upload.mockResolvedValue({ blob_uuid: 'fontbody0002' })
    await pick(w, new File(['x'], 'Brand.WOFF2', { type: '' }))
    expect(upload).toHaveBeenCalledTimes(1)
  })

  it('shows a specimen set in the face, and takes it off', async () => {
    const w = mountField('fontbody0001')
    const specimen = w.find('[data-test="font-specimen-body"]')
    expect(specimen.exists()).toBe(true)
    // The face is declared from the media library's URL under a name of this field's own.
    const css = w.find('style').text()
    expect(css).toContain('url("/v1/blobs/fontbody0001")')
    expect(specimen.attributes('style')).toContain('thallo-admin-face-body')
    expect(css).toContain('thallo-admin-face-body')

    await w.find('[data-test="font-remove-body"]').trigger('click')
    expect(w.emitted('update:modelValue')).toEqual([['']])
  })

  it('a uuid is the only thing that ever reaches the stylesheet', () => {
    const w = mountField('x") } body { display:none } /*')
    expect(w.find('style').exists()).toBe(false)
    expect(w.find('[data-test="font-specimen-body"]').exists()).toBe(false)
  })
})
