import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { switchRootByTestId } from './support/switch'
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
      "@click=\"$emit('update:modelValue', 'blob00000042')\">{{ modelValue }}</button>",
  },
}))
vi.mock('@/fields/components/ReferencePicker.vue', () => ({
  default: { name: 'ReferencePicker', props: ['target', 'modelValue'], template: '<span />' },
}))

import GeneralSettingsPage from '@/pages/settings/general/index.vue'

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

describe('general settings page', () => {
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

  it('the search toggle hydrates from the server and saves with the form', async () => {
    saveMock.mockResolvedValue({ ...settings(), search_enabled: true })
    const wrapper = mount(GeneralSettingsPage)
    await flushPromises()

    // Hydrated OFF (the server default); flip it on and save. USwitch renders a
    // switch button, not an input — drive it via v-model like the other specs.
    const toggle = switchRootByTestId(wrapper, 'search-enabled')
    expect(toggle.exists()).toBe(true)
    toggle.vm.$emit('update:modelValue', true)
    await flushPromises()

    const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save'))
    await saveBtn!.trigger('click')
    await flushPromises()
    expect(saveMock.mock.calls[0]![0]).toMatchObject({ search_enabled: true })
  })

  it('shows how the site behaves, and points to Appearance for how it looks', async () => {
    const wrapper = mount(GeneralSettingsPage)
    await flushPromises()
    for (const gone of [
      'theme-card',
      'theme-colors-card',
      'theme-design-card',
      'site-logo-picker',
    ]) {
      expect(wrapper.find(`[data-test="${gone}"]`).exists(), gone).toBe(false)
    }
    const pointer = wrapper.find('[data-test="appearance-pointer"]')
    expect(pointer.exists()).toBe(true)
    expect(pointer.text()).toContain('Appearance')
  })

  it('saves its own keys and never the appearance ones', async () => {
    // The server leaves an omitted key unchanged. Were this page to send the appearance keys
    // from its own copy, a save here would undo a change made on the Appearance page meanwhile.
    saveMock.mockResolvedValue({ ...settings() })
    const wrapper = mount(GeneralSettingsPage)
    await flushPromises()
    const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save'))
    await saveBtn!.trigger('click')
    await flushPromises()
    const sent = saveMock.mock.calls[0]![0] as Record<string, unknown>
    expect(Object.keys(sent).sort()).toEqual(
      [
        'admin_url',
        'cache_ttl',
        'default_locale',
        'default_per_page',
        'homepage_entry',
        'listing_types',
        'max_per_page',
        'scheduler_enabled',
        'search_enabled',
        'site_name',
        'site_preview_url',
        'webhooks_enabled',
      ].sort(),
    )
  })
})
