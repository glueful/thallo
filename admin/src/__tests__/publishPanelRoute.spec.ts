import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

const routes = ref<{ locale: string; slug: string }[]>([])
const saveRoute = vi.hoisted(() => vi.fn())

vi.mock('@/queries/routes', () => ({
  useRoutes: () => ({ data: routes }),
  useSaveRoute: () => ({ mutateAsync: saveRoute, isLoading: ref(false) }),
}))
vi.mock('@/queries/publish', () => ({
  usePublish: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
}))
vi.mock('@/queries/preview', () => ({
  usePreview: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
  useThemePreview: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
  buildPreviewUrl: () => '',
}))
vi.mock('@/queries/generalSettings', () => ({
  useGeneralSettings: () => ({ data: ref({}) }),
  useGeneralSettingsMutations: () => ({ save: { mutateAsync: vi.fn(), isLoading: ref(false) } }),
}))
vi.mock('@/queries/schedules', () => ({
  useSchedules: () => ({ data: ref([]) }),
  useScheduleMutations: () => ({
    create: { mutateAsync: vi.fn(), isLoading: ref(false) },
    cancel: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
}))
vi.mock('@/queries/entries', () => ({ useEntryLocales: () => ({ data: ref([]) }) }))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: () => false }),
}))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { sitePreviewUrl: '' } }))
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import PublishPanel from '@/pages/content/[type]/[uuid]/components/PublishPanel.vue'

type Exposed = { saveRouteIfDirty: () => Promise<boolean> }

const mountPanel = (suggestedSlug = 'home') =>
  mount(PublishPanel, {
    props: { uuid: 'e-1', locale: 'en', type: 'page', suggestedSlug },
    // UTooltip needs UApp's provider; the tooltips are not under test.
    global: {
      stubs: {
        UTooltip: { template: '<div><slot /></div>' },
        Tooltip: { template: '<div><slot /></div>' },
      },
    },
  })

// Publishing pins the saved draft AND the route the panel shows: a slug still sitting unsaved
// in the input (the title-derived suggestion, or an edit) must reach the server before the
// publish, or the page goes live with no URL.
describe('PublishPanel.saveRouteIfDirty', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    routes.value = []
    saveRoute.mockReset()
    saveRoute.mockResolvedValue({})
    notify.error.mockReset()
  })

  it('saves the suggested slug when no route exists yet', async () => {
    const wrapper = mountPanel('home')
    await flushPromises()
    expect(wrapper.find('[data-test="save-route"]').exists()).toBe(true)

    const ok = await (wrapper.vm as unknown as Exposed).saveRouteIfDirty()
    await flushPromises()

    expect(ok).toBe(true)
    expect(saveRoute).toHaveBeenCalledWith('home')
    expect(wrapper.find('[data-test="save-route"]').exists()).toBe(false)
  })

  it('does nothing when the shown slug is the saved one', async () => {
    routes.value = [{ locale: 'en', slug: 'about' }]
    const wrapper = mountPanel('about-us')
    await flushPromises()

    expect(await (wrapper.vm as unknown as Exposed).saveRouteIfDirty()).toBe(true)
    expect(saveRoute).not.toHaveBeenCalled()
  })

  it('does nothing when the slug is empty', async () => {
    const wrapper = mountPanel('')
    await flushPromises()

    expect(await (wrapper.vm as unknown as Exposed).saveRouteIfDirty()).toBe(true)
    expect(saveRoute).not.toHaveBeenCalled()
  })

  it('reports a failed save so the caller can stop the publish', async () => {
    saveRoute.mockRejectedValue(new Error('nope'))
    const wrapper = mountPanel('home')
    await flushPromises()

    expect(await (wrapper.vm as unknown as Exposed).saveRouteIfDirty()).toBe(false)
    expect(notify.error).toHaveBeenCalled()
  })
})
