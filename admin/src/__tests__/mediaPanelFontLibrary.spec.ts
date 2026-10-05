import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { MediaDetail, MediaUsage } from '@/queries/media'

// A font file the font library uses (block typeface spec §2.6): the media panel names the family
// under Used in — "(removed)" for a removed one — with the way to the Typefaces card, and offers no
// Delete, which the server would refuse.
const usage = ref<MediaUsage>({ entries: [], font_library: [] })
const authFetch = vi.hoisted(() => vi.fn())
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/queries/media', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useMediaMutations: () => ({
    update: { mutateAsync: vi.fn(), isLoading: ref(false) },
    remove: { mutateAsync: vi.fn(), isLoading: ref(false) },
    optimize: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
  useMediaUsage: () => ({ data: usage, status: ref('success') }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

const { default: MediaPanel } = await import('@/pages/media/components/MediaPanel.vue')
const { fetchMediaUsage } = await import('@/queries/media')

const font: MediaDetail = {
  uuid: 'fontblob0001',
  name: 'Brand.woff2',
  mime_type: 'font/woff2',
  size: 2048,
  url: 'uploads/brand.woff2',
  display_url: 'https://example.com/v1/blobs/fontblob0001',
  thumb_url: 'https://example.com/v1/blobs/fontblob0001',
  visibility: 'public',
  tags: [],
  usage_count: 0,
}

const mountPanel = () =>
  mount(MediaPanel, {
    props: { item: font },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
  })

beforeEach(() => {
  setActivePinia(createPinia())
  usage.value = { entries: [], font_library: [] }
})

describe('a font file in the media panel', () => {
  it('reads the font library usage alongside the entries', async () => {
    authFetch.mockResolvedValue({
      data: {
        usage: [{ entry_uuid: 'entry0000001', type: 'page', status: 'published' }],
        font_library: [{ id: 'Ab3dE5fG7hJ9', name: 'Brand', removed: false }],
      },
    })
    await expect(fetchMediaUsage('fontblob0001')).resolves.toEqual({
      entries: [{ entry_uuid: 'entry0000001', type: 'page', status: 'published' }],
      font_library: [{ id: 'Ab3dE5fG7hJ9', name: 'Brand', removed: false }],
    })
    authFetch.mockResolvedValue({ data: { usage: [] } })
    await expect(fetchMediaUsage('fontblob0001')).resolves.toEqual({
      entries: [],
      font_library: [],
    })
  })

  it('names each family using it, and links to the Typefaces card', () => {
    usage.value = {
      entries: [],
      font_library: [
        { id: 'Ab3dE5fG7hJ9', name: 'Brand', removed: false },
        { id: 'Rm3dE5fG7hJ9', name: 'Old brand', removed: true },
      ],
    }
    const w = mountPanel()
    const rows = w.findAll('[data-test="media-usage-font"]')
    expect(rows.map((r) => r.text())).toEqual([
      'Font library · Brand',
      'Font library · Old brand (removed)',
    ])
    expect(rows[0]!.find('a').attributes('href')).toBe('/appearance#typefaces')
    expect(w.text()).not.toContain('not currently used anywhere')
  })

  it('offers no Delete while a family uses it, and says why', () => {
    usage.value = {
      entries: [],
      font_library: [{ id: 'Ab3dE5fG7hJ9', name: 'Brand', removed: false }],
    }
    const w = mountPanel()
    const remove = w.find('[data-test="media-delete"]')
    expect(remove.attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="media-delete-blocked"]').text()).toBe(
      'A font in the library: delete the family permanently on Site › Appearance first.',
    )
  })

  it('a file no family uses can be deleted as before', () => {
    const w = mountPanel()
    expect(w.find('[data-test="media-delete"]').attributes('disabled')).toBeUndefined()
    expect(w.find('[data-test="media-delete-blocked"]').exists()).toBe(false)
  })
})
