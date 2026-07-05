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
vi.mock('@/queries/locales', () => ({
  useLocales: () => ({ data: ref([{ code: 'en', enabled: true }]) }),
}))
vi.mock('@/queries/contentTypes', () => ({
  useContentTypes: () => ({ data: ref([]) }),
}))
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
  useRoute: () => ({ path: '/settings/general', params: {}, query: {} }),
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
      '@click="$emit(\'update:modelValue\', \'blob00000042\')">{{ modelValue }}</button>',
  },
}))
vi.mock('@/fields/components/ReferencePicker.vue', () => ({
  default: { name: 'ReferencePicker', props: ['target', 'modelValue'], template: '<span />' },
}))

import GeneralSettingsPage from '@/pages/settings/general/index.vue'

const settings = (): GeneralSettings => ({
  site_name: 'Lemma',
  site_preview_url: '',
  default_locale: 'en',
  default_per_page: 20,
  max_per_page: 100,
  cache_ttl: 60,
  scheduler_enabled: true,
  webhooks_enabled: true,
  homepage_entry: '',
  site_logo: '',
  site_logo_dark: '',
  site_favicon: '',
  theme: 'default',
  admin_url: '',
  listing_types: ['post'],
})

describe('general settings page — site logo', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    settingsData.value = settings()
    saveMock.mockReset()
    notify.success.mockClear()
    notify.error.mockClear()
    fetchRenderThemesMock
      .mockReset()
      .mockResolvedValue({ themes: ['default', 'corporate'], active: 'default' })
  })

  it('picking a logo asset saves site_logo with the rest of the form', async () => {
    saveMock.mockResolvedValue({ ...settings(), site_logo: 'blob00000042' })
    const wrapper = mount(GeneralSettingsPage)
    await flushPromises()

    expect(wrapper.find('[data-test="site-logo-picker"]').exists()).toBe(true)
    await wrapper.find('[data-test="stub-logo-pick"]').trigger('click')

    // Save the form; the payload carries the picked asset uuid.
    const saveBtn = wrapper
      .findAll('button')
      .find((b) => b.text().includes('Save'))
    expect(saveBtn).toBeTruthy()
    await saveBtn!.trigger('click')
    await flushPromises()

    expect(saveMock).toHaveBeenCalledTimes(1)
    expect(saveMock.mock.calls[0]![0]).toMatchObject({
      site_logo: 'blob00000042',
      listing_types: ['post'],
    })
    expect(notify.success).toHaveBeenCalled()
  })

  it('hydrates the current logo value from the server payload', async () => {
    settingsData.value = { ...settings(), site_logo: 'blob00000007' }
    const wrapper = mount(GeneralSettingsPage)
    await flushPromises()
    expect(wrapper.find('[data-test="stub-logo-pick"]').text()).toBe('blob00000007')
  })

  it('the Theme card renders from the themes endpoint and saves form.theme', async () => {
    saveMock.mockResolvedValue({ ...settings() })
    const wrapper = mount(GeneralSettingsPage)
    await flushPromises()

    expect(wrapper.find('[data-test="theme-card"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="theme-setting-select"]').text()).toContain('default')

    const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save'))
    await saveBtn!.trigger('click')
    await flushPromises()
    expect(saveMock.mock.calls[0]![0]).toMatchObject({ theme: 'default' })
  })

  it('a failed themes fetch hides the Theme card without an error toast', async () => {
    fetchRenderThemesMock.mockRejectedValue(new Error('403'))
    const wrapper = mount(GeneralSettingsPage)
    await flushPromises()

    expect(wrapper.find('[data-test="theme-card"]').exists()).toBe(false)
    expect(notify.error).not.toHaveBeenCalled()
  })

  it('renders dark-logo and favicon fields; the favicon preview only when set', async () => {
    const wrapper = mount(GeneralSettingsPage)
    await flushPromises()

    expect(wrapper.find('[data-test="site-logo-dark-picker"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="site-favicon-picker"]').exists()).toBe(true)
    // No favicon set: no preview.
    expect(wrapper.find('[data-test="favicon-preview"]').exists()).toBe(false)

    settingsData.value = { ...settings(), site_favicon: 'favic0000001' }
    await flushPromises()

    const preview = wrapper.find('[data-test="favicon-preview"]')
    expect(preview.exists()).toBe(true)
    // Both the app tile and the tab mock render the uploaded blob.
    const imgs = preview.findAll('img')
    expect(imgs.length).toBe(2)
    expect(imgs[0]!.attributes('src')).toBe('/blobs/favic0000001')
    expect(preview.text()).toContain('Lemma')
  })
})
