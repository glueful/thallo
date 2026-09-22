import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { MediaDetail } from '@/queries/media'

vi.mock('@/queries/media', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useMediaMutations: () => ({
    update: { mutateAsync: vi.fn(), isLoading: ref(false) },
    remove: { mutateAsync: vi.fn(), isLoading: ref(false) },
    optimize: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
  useMediaUsage: () => ({ data: ref([]), status: ref('success') }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import MediaPanel from '@/pages/media/components/MediaPanel.vue'

const item = (visibility: 'public' | 'private'): MediaDetail => ({
  uuid: 'blob00000001',
  name: 'photo.jpg',
  mime_type: 'image/jpeg',
  size: 2048,
  url: 'uploads/2026/09/photo.jpg',
  display_url: 'https://example.com/v1/blobs/blob00000001',
  thumb_url: 'https://example.com/v1/blobs/blob00000001?width=160',
  visibility,
  tags: [],
  usage_count: 0,
})

describe('the media panel’s file link', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('shows the address the file is served at, not its storage path', () => {
    const wrapper = mount(MediaPanel, { props: { item: item('public') } })
    const input = wrapper.find('input[data-test="media-file-url"]')

    expect((input.element as HTMLInputElement).value).toBe(
      'https://example.com/v1/blobs/blob00000001',
    )
    expect(wrapper.text()).not.toContain('uploads/2026/09/photo.jpg')
  })

  it('says a private file’s link is signed and expires', () => {
    const wrapper = mount(MediaPanel, { props: { item: item('private') } })

    expect(wrapper.find('[data-test="media-file-url-label"]').text()).toMatch(/expires/i)
  })
})
