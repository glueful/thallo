import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'

const calls = vi.hoisted(() => [] as string[])
const saveDraft = vi.hoisted(() => vi.fn())
const publishMock = vi.hoisted(() => vi.fn())
const saveRouteIfDirty = vi.hoisted(() => vi.fn())

vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: () => false }),
}))
vi.mock('@/queries/contentTypes', () => ({
  useContentTypes: () => ({ data: ref([{ slug: 'page', schema: [] }]) }),
}))
vi.mock('@/queries/drafts', () => ({
  useDraft: () => ({
    data: ref({ fields: { title: 'Home' }, lock_version: 0 }),
    status: ref('success'),
  }),
  useSaveDraft: () => ({ mutateAsync: saveDraft, isLoading: ref(false) }),
}))
vi.mock('@/queries/publish', () => ({
  usePublish: () => ({ mutateAsync: publishMock, isLoading: ref(false) }),
}))
vi.mock('@/queries/entries', () => ({
  useEntryLocales: () => ({ data: ref([]) }),
  useCreateLocaleDraft: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
}))
vi.mock('@/queries/locales', () => ({ useLocales: () => ({ data: ref([]) }) }))
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))
vi.mock('@/runtime/config', () => ({
  runtimeConfig: { defaultLocale: 'en', apiBase: '/v1/admin' },
}))
vi.mock('vue-router', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue-router')>()
  return { ...actual, useRoute: () => ({ params: { type: 'page', uuid: 'e-1' }, query: {} }) }
})
// The real panel drags in every publishing query; the stub keeps only the seam the editor
// relies on when publishing.
vi.mock('@/pages/content/[type]/[uuid]/components/PublishPanel.vue', () => ({
  default: defineComponent({
    name: 'PublishPanel',
    props: ['uuid', 'locale', 'type', 'suggestedSlug'],
    setup(_props, { expose }) {
      expose({ saveRouteIfDirty })
      return () => h('div', { 'data-test': 'publish-panel-stub' })
    },
  }),
}))

import EntryEditor from '@/pages/content/[type]/[uuid]/index.vue'

const factory = () =>
  mount(EntryEditor, {
    global: {
      stubs: {
        UDashboardPanel: {
          template: '<div><slot name="header" /><slot name="body" /></div>',
        },
        UDashboardNavbar: { template: '<div><slot name="right" /></div>' },
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
        FieldEditor: true,
        SeoPanel: true,
        VersionsPanel: true,
        LocaleSwitcher: true,
        LocaleRoutesModal: true,
        BulkLocaleMenu: true,
      },
    },
  })

describe('entry editor: navbar Publish', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    calls.length = 0
    notify.success.mockReset()
    saveDraft.mockReset().mockImplementation(async () => {
      calls.push('save')
    })
    saveRouteIfDirty.mockReset().mockImplementation(async () => {
      calls.push('route')
      return true
    })
    publishMock.mockReset().mockImplementation(async () => {
      calls.push('publish')
    })
  })

  it('saves the draft, then the route shown in the panel, then publishes', async () => {
    const wrapper = factory()
    await flushPromises()

    await wrapper.find('[data-test="navbar-publish"]').trigger('click')
    await flushPromises()

    expect(calls).toEqual(['save', 'route', 'publish'])
  })

  // One action, one toast: the draft and route saves that publishing implies stay silent.
  it('shows a single Published toast, not one per implied save', async () => {
    const wrapper = factory()
    await flushPromises()

    await wrapper.find('[data-test="navbar-publish"]').trigger('click')
    await flushPromises()

    expect(notify.success).toHaveBeenCalledTimes(1)
    expect(notify.success).toHaveBeenCalledWith('Published')
  })

  it('Save draft on its own still confirms with its toast', async () => {
    const wrapper = factory()
    await flushPromises()

    await wrapper.find('[data-test="save-draft"]').trigger('click')
    await flushPromises()

    expect(notify.success).toHaveBeenCalledTimes(1)
    expect(notify.success).toHaveBeenCalledWith('Draft saved')
  })

  it('does not publish when the route could not be saved', async () => {
    saveRouteIfDirty.mockImplementation(async () => false)
    const wrapper = factory()
    await flushPromises()

    await wrapper.find('[data-test="navbar-publish"]').trigger('click')
    await flushPromises()

    expect(publishMock).not.toHaveBeenCalled()
  })
})
