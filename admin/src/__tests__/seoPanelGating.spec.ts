import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

const seoEnabled = ref(true)

vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({
    isEnabled: (id: string) => (id === 'thallo.seo' ? seoEnabled.value : true),
  }),
}))
vi.mock('@/queries/seo', () => ({
  useSeoMeta: () => ({ data: ref({}) }),
  useSaveSeoMeta: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
}))
vi.mock('@/queries/contentTypes', () => ({
  useContentTypes: () => ({ data: ref([{ slug: 'blog', schema: [] }]) }),
}))
vi.mock('@/queries/drafts', () => ({
  useDraft: () => ({ data: ref({ fields: {}, lock_version: 0 }), status: ref('success') }),
  useSaveDraft: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
}))
vi.mock('@/queries/entries', () => ({
  useEntryLocales: () => ({ data: ref([]) }),
  useCreateLocaleDraft: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
}))
vi.mock('@/queries/locales', () => ({ useLocales: () => ({ data: ref([]) }) }))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/runtime/config', () => ({
  runtimeConfig: { defaultLocale: 'en', apiBase: '/v1/admin' },
}))
vi.mock('vue-router', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue-router')>()
  return { ...actual, useRoute: () => ({ params: { type: 'blog', uuid: 'e-1' }, query: {} }) }
})

import EntryEditor from '@/pages/content/[type]/[uuid]/index.vue'
import SeoPanel from '@/pages/content/[type]/[uuid]/components/SeoPanel.vue'

// Render only the panel's #body slot (SeoPanel lives there, below PublishPanel). The navbar's
// router-Link buttons are in #header, which this stub omits — so the mount never touches Nuxt UI's
// vue-router Link override. SeoPanel + the sibling panels are stubbed; we assert only the gate.
const factory = () =>
  mount(EntryEditor, {
    global: {
      stubs: {
        UDashboardPanel: { template: '<div><slot name="body" /></div>' },
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
        PublishPanel: true,
        FieldEditor: true,
        SeoPanel: true,
        LocaleSwitcher: true,
        LocaleRoutesModal: true,
        BulkLocaleMenu: true,
      },
    },
  })

describe('SeoPanel render gate (thallo.seo capability)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    seoEnabled.value = true
  })

  it('renders SeoPanel when thallo.seo is enabled', () => {
    expect(factory().findComponent(SeoPanel).exists()).toBe(true)
  })

  it('omits SeoPanel when thallo.seo is disabled', () => {
    seoEnabled.value = false
    expect(factory().findComponent(SeoPanel).exists()).toBe(false)
  })
})
