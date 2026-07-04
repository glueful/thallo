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
})
