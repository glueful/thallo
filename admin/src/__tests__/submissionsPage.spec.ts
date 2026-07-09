import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { SubmissionDetail, SubmissionSummary } from '@/queries/formSubmissions'

const listData = ref<SubmissionSummary[] | undefined>(undefined)
const detailData = ref<SubmissionDetail | undefined>(undefined)
const markReadMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const removeMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const downloadMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/queries/formSubmissions', () => ({
  useSubmissions: () => ({ data: listData }),
  useSubmission: () => ({ data: detailData }),
  useUnreadCount: () => ({ data: ref(0) }),
  useSubmissionMutations: () => ({
    markRead: { mutateAsync: markReadMock },
    remove: { mutateAsync: removeMock },
  }),
  downloadSubmissionsCsv: (...a: unknown[]) => downloadMock(...a),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: notify.success, error: notify.error }),
}))
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/submissions', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import SubmissionsPage from '@/pages/submissions/index.vue'

const mountPage = () =>
  mount(SubmissionsPage, {
    global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
  })

const summary = (over: Partial<SubmissionSummary> = {}): SubmissionSummary => ({
  uuid: 'u1',
  form_key: 'k1',
  form_name: 'Contact',
  source_url: '/contact',
  status: 'unread',
  submitted_at: '2026-07-09 10:00:00',
  ...over,
})

const detail = (): SubmissionDetail => ({
  ...summary(),
  fields_snapshot: [
    { key: 'email', label: 'Email', type: 'email' },
    { key: 'consent', label: 'I agree', type: 'checkbox' },
  ],
  values: { email: 'ada@x.test', consent: true },
  descriptor_version: 1,
  ip: '127.0.0.1',
  user_agent: 'test',
})

describe('submissions page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    listData.value = undefined
    detailData.value = undefined
    markReadMock.mockClear()
    removeMock.mockClear()
    downloadMock.mockClear()
    notify.success.mockClear()
    notify.error.mockClear()
  })

  it('shows the empty state when there are no submissions', async () => {
    listData.value = []
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="submissions-empty"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="submission-row"]')).toHaveLength(0)
  })

  it('renders rows and flags unread ones with a dot', async () => {
    listData.value = [summary(), summary({ uuid: 'u2', status: 'read' })]
    const wrapper = mountPage()
    await flushPromises()
    const rows = wrapper.findAll('[data-test="submission-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.find('[data-test="submission-unread-dot"]').exists()).toBe(true)
    expect(rows[1]!.find('[data-test="submission-unread-dot"]').exists()).toBe(false)
  })

  it('clicking a row selects it, marks it read, and shows the labelled detail', async () => {
    listData.value = [summary()]
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="submission-row"]').trigger('click')
    detailData.value = detail()
    await flushPromises()

    expect(markReadMock).toHaveBeenCalledWith('u1')
    const values = wrapper.findAll('[data-test="submission-value"]').map((v) => v.text())
    expect(values).toContain('ada@x.test')
    expect(values).toContain('Yes') // checkbox bool → Yes
    expect(wrapper.text()).toContain('I agree') // sealed label, not key
  })

  it('the status filter buttons toggle the active filter', async () => {
    listData.value = [summary()]
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="filter-all"]').attributes('aria-pressed')).toBe('true')
    await wrapper.find('[data-test="filter-unread"]').trigger('click')
    expect(wrapper.find('[data-test="filter-unread"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('[data-test="filter-all"]').attributes('aria-pressed')).toBe('false')
  })

  it('delete requires the confirm modal before removing', async () => {
    listData.value = [summary()]
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="submission-row"]').trigger('click')
    detailData.value = detail()
    await flushPromises()

    await wrapper.find('[data-test="submission-delete-open"]').trigger('click')
    await flushPromises()
    expect(removeMock).not.toHaveBeenCalled() // arming the modal deletes nothing

    const confirm = document.body.querySelector('[data-test="submission-delete"]') as HTMLElement
    expect(confirm).not.toBeNull()
    confirm.click()
    await flushPromises()
    expect(removeMock).toHaveBeenCalledWith('u1')
    expect(notify.success).toHaveBeenCalled()
  })

  it('Export CSV triggers the authenticated download with the active filter', async () => {
    listData.value = [summary()]
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="filter-read"]').trigger('click')
    await wrapper.find('[data-test="submissions-export"]').trigger('click')
    await flushPromises()
    expect(downloadMock).toHaveBeenCalledWith({ status: 'read' })
  })
})
