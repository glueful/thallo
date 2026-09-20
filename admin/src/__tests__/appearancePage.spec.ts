// Site › Appearance: how the site looks — the theme, its colours, the design settings, the logos
// and site icon. It shares the general settings endpoint with Settings › General and saves ONLY its
// own keys: the server leaves an omitted key unchanged, so neither page can overwrite the other's
// settings with a stale copy.
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { GeneralSettings } from '@/queries/generalSettings'

const settingsData = ref<GeneralSettings | undefined>(undefined)
const saveMock = vi.fn()
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/queries/generalSettings', () => ({
  useGeneralSettings: () => ({ data: settingsData, status: ref('success') }),
  useGeneralSettingsMutations: () => ({
    save: { mutateAsync: saveMock, isLoading: ref(false) },
  }),
}))
// "Preview on site" mints a preview session through the homepage entry.
const postMock = vi.hoisted(() => vi.fn())
vi.mock('@/api/client', () => ({ client: { POST: postMock } }))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: notify.success, error: notify.error }),
}))
// The page renders FaviconPreview from blobDisplayUrl(form.site_favicon).
vi.mock('@/queries/media', () => ({
  blobDisplayUrl: (uuid: string) => `/blobs/${uuid}`,
}))
// Theme card options come from the render pack's themes endpoint.
const fetchRenderThemesMock = vi.hoisted(() => vi.fn())
vi.mock('@/queries/templates', () => ({
  fetchRenderThemes: fetchRenderThemesMock,
}))
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/appearance', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))
// The real AssetField opens the blob picker; the page only needs v-model.
vi.mock('@/fields/components/AssetField.vue', () => ({
  default: {
    name: 'AssetField',
    props: { field: { type: Object, required: true }, modelValue: { type: String, default: '' } },
    emits: ['update:modelValue'],
    template:
      '<button type="button" data-test="stub-logo-pick" ' +
      "@click=\"$emit('update:modelValue', 'blob00000042')\">{{ modelValue }}</button>",
  },
}))

import AppearancePage from '@/pages/appearance/index.vue'

const settings = (): GeneralSettings => ({
  site_name: 'Thallo',
  site_preview_url: '',
  default_locale: 'en',
  default_per_page: 20,
  max_per_page: 100,
  cache_ttl: 60,
  scheduler_enabled: true,
  webhooks_enabled: true,
  search_enabled: false,
  homepage_entry: '',
  site_logo: '',
  site_logo_dark: '',
  site_favicon: '',
  theme: 'default',
  theme_accent: 'blue',
  theme_neutral: 'slate',
  theme_radius: 'round',
  theme_font: 'sans',
  theme_background: 'plain',
  admin_url: '',
  listing_types: ['post'],
})

/** Exactly the keys this page owns, as the server sent them. */
const APPEARANCE = {
  theme: 'default',
  theme_accent: 'blue',
  theme_neutral: 'slate',
  theme_radius: 'round',
  theme_font: 'sans',
  theme_background: 'plain',
  site_logo: '',
  site_logo_dark: '',
  site_favicon: '',
}
async function save(wrapper: ReturnType<typeof mount>) {
  const button = wrapper.find('[data-test="appearance-save"]')
  expect(button.exists()).toBe(true)
  await button.trigger('click')
  await flushPromises()
  const calls = saveMock.mock.calls
  return calls[calls.length - 1]![0] as Record<string, unknown>
}

describe('appearance page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    settingsData.value = settings()
    saveMock.mockReset().mockResolvedValue({ ...settings() })
    notify.success.mockClear()
    notify.error.mockClear()
    fetchRenderThemesMock
      .mockReset()
      .mockResolvedValue({ themes: ['default', 'corporate'], active: 'default' })
  })

  it('shows the four appearance cards and nothing of how the site behaves', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    for (const card of ['theme-card', 'theme-colors-card', 'theme-design-card', 'logos-card']) {
      expect(wrapper.find(`[data-test="${card}"]`).exists(), card).toBe(true)
    }
    expect(wrapper.find('[data-test="search-enabled"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Content delivery')
  })

  it('saves its own keys and no others', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    expect(await save(wrapper)).toEqual(APPEARANCE)
    expect(notify.success).toHaveBeenCalled()
  })

  it('picking a logo is saved; the current one is shown', async () => {
    settingsData.value = { ...settings(), site_logo: 'blob00000007' }
    const wrapper = mount(AppearancePage)
    await flushPromises()
    expect(wrapper.find('[data-test="site-logo-picker"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="stub-logo-pick"]').text()).toBe('blob00000007')
    await wrapper.find('[data-test="stub-logo-pick"]').trigger('click')
    expect(await save(wrapper)).toMatchObject({ site_logo: 'blob00000042' })
  })

  it('saved colours and design settings hydrate and are sent back as they are', async () => {
    const stored = {
      theme_accent: 'emerald',
      theme_neutral: 'zinc',
      theme_radius: 'sharp',
      theme_font: 'editorial',
      theme_background: 'tinted',
    }
    settingsData.value = { ...settings(), ...stored }
    const wrapper = mount(AppearancePage)
    await flushPromises()
    for (const field of [
      'theme-accent',
      'theme-neutral',
      'theme-radius',
      'theme-font',
      'theme-background',
    ]) {
      expect(wrapper.find(`[data-test="${field}"]`).exists(), field).toBe(true)
    }
    expect(await save(wrapper)).toEqual({ ...APPEARANCE, ...stored })
  })

  it('the Theme card lists the themes; a failed fetch hides it without an error', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    expect(wrapper.find('[data-test="theme-setting-select"]').text()).toContain('default')

    fetchRenderThemesMock.mockRejectedValue(new Error('403'))
    const hidden = mount(AppearancePage)
    await flushPromises()
    expect(hidden.find('[data-test="theme-card"]').exists()).toBe(false)
    expect(notify.error).not.toHaveBeenCalled()
  })

  it('a themes response with no list hides the Theme card; the page still renders', async () => {
    // Not a failed request: a body that lacks the list. It once left the whole page on its
    // loading skeletons, because the template read the length of something that was not there.
    // The failure was a throw DURING a re-render, which Vue reports and survives with the old
    // DOM — so the DOM alone cannot show it. Any render error fails this test.
    const renderErrors: unknown[] = []
    fetchRenderThemesMock.mockResolvedValue({})
    const wrapper = mount(AppearancePage, {
      global: { config: { errorHandler: (error) => renderErrors.push(error) } },
    })
    await flushPromises()
    expect(renderErrors).toEqual([])
    expect(wrapper.find('[data-test="theme-card"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="theme-colors-card"]').exists()).toBe(true)
  })

  it('renders dark-logo and favicon fields; the favicon preview only when set', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    expect(wrapper.find('[data-test="site-logo-dark-picker"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="site-favicon-picker"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="favicon-preview"]').exists()).toBe(false)

    settingsData.value = { ...settings(), site_favicon: 'favic0000001' }
    await flushPromises()
    const preview = wrapper.find('[data-test="favicon-preview"]')
    expect(preview.exists()).toBe(true)
    const imgs = preview.findAll('img')
    expect(imgs.length).toBe(2)
    expect(imgs[0]!.attributes('src')).toBe('/blobs/favic0000001')
    expect(preview.text()).toContain('Thallo')
  })

  it('Preview on site opens the homepage with the PENDING colours, in the site’s default locale', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    postMock.mockReset().mockResolvedValue({ data: { data: { theme_url: '/_preview/tok' } } })

    // No homepage: nothing to preview through, and the message says where to set one.
    const wrapper = mount(AppearancePage)
    await flushPromises()
    await wrapper.find('[data-test="theme-colors-preview"]').trigger('click')
    await flushPromises()
    expect(postMock).not.toHaveBeenCalled()
    expect(notify.error).toHaveBeenCalledTimes(1)

    settingsData.value = {
      ...settings(),
      homepage_entry: 'homeentry001',
      default_locale: 'fr',
      theme_accent: 'emerald',
    }
    const ready = mount(AppearancePage)
    await flushPromises()
    await ready.find('[data-test="theme-colors-preview"]').trigger('click')
    await flushPromises()
    expect(postMock).toHaveBeenCalledWith('/entries/{uuid}/preview/{locale}', {
      params: { path: { uuid: 'homeentry001', locale: 'fr' } },
      body: { accent: 'emerald', neutral: 'slate' },
    })
    expect(open).toHaveBeenCalledWith('/_preview/tok', '_blank', 'noopener')
    open.mockRestore()
  })

  it('a refetch never overwrites an unsaved edit', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    await wrapper.find('[data-test="stub-logo-pick"]').trigger('click')
    settingsData.value = { ...settings(), site_logo: 'fromserver01' }
    await flushPromises()
    expect(await save(wrapper)).toMatchObject({ site_logo: 'blob00000042' })
  })
})
