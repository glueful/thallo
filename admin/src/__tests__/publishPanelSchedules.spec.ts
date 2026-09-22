import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

const scheduleData = ref<{ schedules: Record<string, unknown>[]; schedulerTicking: boolean }>({
  schedules: [],
  schedulerTicking: true,
})

vi.mock('@/queries/routes', () => ({
  useRoutes: () => ({ data: ref([]) }),
  useSaveRoute: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
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
const createMock = vi.hoisted(() => vi.fn())
vi.mock('@/queries/schedules', () => ({
  useSchedules: () => ({ data: scheduleData }),
  useScheduleMutations: () => ({
    create: { mutateAsync: createMock, isLoading: ref(false) },
    cancel: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
}))
vi.mock('@/queries/entries', () => ({ useEntryLocales: () => ({ data: ref([]) }) }))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: () => false }),
}))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { sitePreviewUrl: '' } }))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))

import PublishPanel from '@/pages/content/[type]/[uuid]/components/PublishPanel.vue'

const mountPanel = () =>
  mount(PublishPanel, {
    props: { uuid: 'e-1', locale: 'en', type: 'page', suggestedSlug: 'home' },
    global: {
      stubs: {
        UTooltip: { template: '<div><slot /></div>' },
        Tooltip: { template: '<div><slot /></div>' },
      },
    },
  })

const row = (over: Record<string, unknown>) => ({
  uuid: 's-1',
  action: 'publish',
  run_at: '2026-09-22T10:00:00+00:00',
  locale: 'en',
  ...over,
})

describe('the Publishing panel’s schedules', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('a failed schedule says why it failed', async () => {
    // The reason was stored on the row and never shown: only a "failed" badge.
    scheduleData.value = {
      schedules: [row({ status: 'failed', failure_reason: 'The entry has no published route.' })],
      schedulerTicking: true,
    }
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="schedule-failure"]').text()).toContain(
      'The entry has no published route.',
    )
  })

  it('warns that a pending schedule cannot run while the scheduler is not ticking', async () => {
    scheduleData.value = { schedules: [row({ status: 'pending' })], schedulerTicking: false }
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="scheduler-not-running"]').text()).toContain('scheduler')
  })

  it('says nothing about the scheduler while it ticks, or when nothing is pending', async () => {
    scheduleData.value = { schedules: [row({ status: 'pending' })], schedulerTicking: true }
    let wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="scheduler-not-running"]').exists()).toBe(false)

    scheduleData.value = { schedules: [row({ status: 'published' })], schedulerTicking: false }
    wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="scheduler-not-running"]').exists()).toBe(false)
  })

  it('schedules an unpublish as well as a publish', async () => {
    scheduleData.value = { schedules: [], schedulerTicking: true }
    createMock.mockReset().mockResolvedValue({})
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="schedule-toggle"]').trigger('click')
    await wrapper.get('[data-test="schedule-action-unpublish"]').trigger('click')
    expect(wrapper.text()).toContain('Unpublish at')
    await wrapper.get('input[type="datetime-local"]').setValue('2026-10-01T09:00')
    await wrapper.get('[data-test="schedule-confirm"]').trigger('click')
    await flushPromises()

    expect(createMock).toHaveBeenCalledWith(expect.objectContaining({ action: 'unpublish' }))
  })
})
