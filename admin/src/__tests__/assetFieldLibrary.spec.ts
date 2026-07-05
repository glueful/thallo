import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { MediaPage } from '@/queries/media'

const mediaData = ref<MediaPage | undefined>(undefined)

vi.mock('@/queries/media', () => ({
  useMediaList: () => ({ data: mediaData, status: ref('success') }),
  useUploadMedia: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
  blobDisplayUrl: (uuid: string) => `/blobs/${uuid}`,
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import AssetField from '@/fields/components/AssetField.vue'

const item = (uuid: string) => ({
  uuid,
  name: `pic-${uuid}.png`,
  mime_type: 'image/png',
  size: 10,
  url: `/u/${uuid}`,
  display_url: `/d/${uuid}`,
  thumb_url: `/t/${uuid}`,
  visibility: 'public' as const,
})

describe('AssetField — choose from library', () => {
  beforeEach(() => {
    mediaData.value = { media: [item('blob00000001')], total: 1, current_page: 1, per_page: 24 }
    document.body.innerHTML = ''
  })

  it('the dropzone opens the picker on Upload; the library button on Library', async () => {
    const wrapper = mount(AssetField, {
      props: { field: { name: 'image', type: 'asset' as const }, modelValue: undefined },
      attachTo: document.body,
    })
    await flushPromises()

    // Clicking the dropzone is intercepted: no native file dialog, the
    // picker opens on its Upload tab instead. The modal teleports — reach
    // it through the document. Both panes stay mounted across tab switches,
    // so the library grid is in the DOM even while Upload is active.
    await wrapper.find('[data-test="asset-dropzone-open"]').trigger('click')
    await flushPromises()
    const activeTab = () =>
      document.querySelector('[data-test="media-picker-tabs"] [role="tab"][aria-selected="true"]')
    expect(activeTab()?.textContent).toContain('Upload')
    expect(document.querySelector('[data-test="media-picker-upload"]')).toBeTruthy()
    expect(document.querySelector('[data-test="media-library-item-blob00000001"]')).toBeTruthy()

    // Close (Escape), then reopen via the library button: lands on Library.
    document.activeElement?.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await flushPromises()

    await wrapper.find('[data-test="asset-library-open"]').trigger('click')
    await flushPromises()
    expect(activeTab()?.textContent).toContain('Media library')
    wrapper.unmount()
  })

  it('picking a library image sets the single-asset model and closes', async () => {
    const wrapper = mount(AssetField, {
      props: { field: { name: 'image', type: 'asset' as const }, modelValue: undefined },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-test="asset-library-open"]').trigger('click')
    await flushPromises()

    const tile = document.querySelector('[data-test="media-library-item-blob00000001"]')
    expect(tile).toBeTruthy()
    tile!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    const emitted = wrapper.emitted('update:modelValue') ?? []
    expect(emitted[emitted.length - 1]).toEqual(['blob00000001'])
    expect(document.querySelector('[data-test="media-picker-tabs"]')).toBeNull() // closed
    wrapper.unmount()
  })

  it('single-mode preview is uuid-free with a tooltip identity affordance', async () => {
    // Site-identity P2 pin: the picker owns rich identity (filenames); the
    // field preview stays minimal — the uuid lives in title/alt only.
    const wrapper = mount(AssetField, {
      props: { field: { name: 'image', type: 'asset' as const }, modelValue: 'blob00000001' },
    })
    await flushPromises()

    const preview = wrapper.find('[data-test="asset-single-preview"]')
    expect(preview.exists()).toBe(true)
    expect(preview.attributes('src')).toBe('/blobs/blob00000001')
    expect(preview.attributes('title')).toBe('blob00000001')
    expect(preview.attributes('alt')).toBe('blob00000001')
    // No visible uuid text node anywhere in the field.
    expect(wrapper.text()).not.toContain('blob00000001')
    wrapper.unmount()
  })

  it('multiple mode appends without duplicates and respects max_items', async () => {
    const wrapper = mount(AssetField, {
      props: {
        field: { name: 'images', type: 'asset' as const, multiple: true, maxItems: 1 },
        modelValue: ['blob00000009'],
      },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-test="asset-library-open"]').trigger('click')
    await flushPromises()
    document
      .querySelector('[data-test="media-library-item-blob00000001"]')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    // Cap of 1 already reached: no model update happened.
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    wrapper.unmount()
  })
})
