import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { StyleClass, StyleClassList, StyleClassUsage } from '@/queries/styleClasses'
import { ApiError } from '@/api/errors'

const list = ref<StyleClassList>({ generation: 0, classes: [] })
const usage = ref<StyleClassUsage | null>(null)
const mutate = vi.fn()
const archiveMutate = vi.fn()
const refetch = vi.fn()
const queueMutate = vi.fn()
const job = ref<Record<string, unknown> | null>(null)
const notify = { success: vi.fn(), error: vi.fn() }

vi.mock('@/queries/styleClasses', () => ({
  useStyleClasses: () => ({ data: list, status: ref('success'), refetch }),
  useStyleClassUsage: () => ({ data: usage, refetch: vi.fn() }),
  useStyleClassJob: () => ({ data: job, refetch: vi.fn() }),
  useStyleClassMutations: () => ({
    create: { mutateAsync: mutate, isLoading: ref(false) },
    update: { mutateAsync: mutate, isLoading: ref(false) },
    archive: { mutateAsync: archiveMutate, isLoading: ref(false) },
    queueJob: { mutateAsync: queueMutate, isLoading: ref(false) },
  }),
}))
vi.mock('@/queries/styleSchema', () => ({
  useStyleSchema: () => ({
    data: ref({
      version: 1,
      breakpoints: { base: 0, md: 768, lg: 1024 },
      properties: [
        {
          path: 'spacing.padding.top',
          group: 'spacing',
          kinds: ['token', 'reset'],
          responsive: true,
          token_domain: 'spacing',
          choices: null,
        },
        {
          path: 'radius',
          group: 'radius',
          kinds: ['token', 'reset'],
          responsive: false,
          token_domain: 'radius',
          choices: null,
        },
      ],
      advanced: [],
      vocabulary: {
        version: 1,
        domains: { spacing: ['sm', 'lg'], radius: ['none', 'lg'] },
        values: {},
      },
    }),
  }),
}))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import IndexPage from '@/pages/settings/style-classes/index.vue'
import EditPage from '@/pages/settings/style-classes/[id].vue'

const band: StyleClass = {
  id: 'band00000001',
  version: 3,
  name: 'Hero band',
  description: 'The hero strip',
  style: { spacing: { padding: { top: { md: { type: 'token', value: 'spacing.lg' } } } } },
  archived: false,
  archived_at: null,
  locked_by_job: null,
  created_at: null,
  updated_at: null,
}

function router(path: string) {
  const r = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settings/style-classes/:id', component: EditPage },
      { path: '/:pathMatch(.*)*', component: { template: '<div />' } },
    ],
  })
  r.push(path)
  return r
}

describe('style classes page', () => {
  beforeEach(() => {
    list.value = {
      generation: 4,
      classes: [band, { ...band, id: 'old000000001', name: 'Old', archived: true }],
    }
    usage.value = null
    job.value = null
    mutate.mockReset()
    queueMutate.mockReset()
    archiveMutate.mockReset()
    notify.error.mockReset()
  })

  it('lists every class with its declared properties and marks archived ones', async () => {
    const wrapper = mount(IndexPage, { global: { plugins: [router('/settings/style-classes')] } })
    await flushPromises()
    expect(wrapper.find('[data-test="style-class-row-band00000001"]').text()).toContain(
      'spacing.padding.top',
    )
    expect(
      wrapper
        .find('[data-test="style-class-row-old000000001"] [data-test="style-class-archived"]')
        .exists(),
    ).toBe(true)
    expect(wrapper.find('[data-test="style-class-archive-old000000001"]').exists()).toBe(false)
  })

  it('archiving asks the mutation and reports that old revisions still restore', async () => {
    const wrapper = mount(IndexPage, { global: { plugins: [router('/settings/style-classes')] } })
    await flushPromises()
    await wrapper.find('[data-test="style-class-archive-band00000001"]').trigger('click')
    await flushPromises()
    expect(archiveMutate).toHaveBeenCalledWith('band00000001')
    expect(notify.success).toHaveBeenCalled()
  })

  it('the editor writes the active breakpoint and the save dialog shows usage before saving', async () => {
    usage.value = {
      references: 3,
      by_source: { entry_drafts: 1, entry_published: 1, entry_versions: 0, regions: 1 },
      active: 3,
      dormant: 0,
      properties: { 'spacing.padding.top': { active: 3, dormant: 0 } },
    }
    const r = router('/settings/style-classes/band00000001')
    await r.isReady()
    const wrapper = mount(EditPage, { global: { plugins: [r] }, attachTo: document.body })
    await flushPromises()
    expect((wrapper.find('[data-test="style-class-name"]').element as HTMLInputElement).value).toBe(
      'Hero band',
    )

    await wrapper.find('[data-test="style-class-save"]').trigger('click')
    await flushPromises()
    expect(mutate).not.toHaveBeenCalled()
    const dialog = document.body.querySelector('[data-test="style-class-usage-summary"]')
    expect(dialog?.textContent).toContain('3')
    ;(document.body.querySelector('[data-test="style-class-save-confirm"]') as HTMLElement).click()
    await flushPromises()
    expect(mutate).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'band00000001', version: 3, name: 'Hero band' }),
    )
    wrapper.unmount()
  })

  it('detach everywhere asks, queues the job, and the form locks while it runs', async () => {
    queueMutate.mockResolvedValue({ id: 'job00000001', status: 'running', kind: 'detach' })
    const r = router('/settings/style-classes/band00000001')
    await r.isReady()
    const wrapper = mount(EditPage, { global: { plugins: [r] }, attachTo: document.body })
    await flushPromises()
    await wrapper.find('[data-test="style-class-detach-everywhere"]').trigger('click')
    await flushPromises()
    ;(document.body.querySelector('[data-test="style-class-job-confirm"]') as HTMLElement).click()
    await flushPromises()
    expect(queueMutate).toHaveBeenCalledWith({ id: 'band00000001', kind: 'detach' })

    list.value = { generation: 5, classes: [{ ...band, locked_by_job: 'job00000001' }] }
    job.value = {
      id: 'job00000001',
      class_id: 'band00000001',
      kind: 'detach',
      status: 'running',
      passes: 1,
      work_items_total: 6,
      work_items_done: 2,
      work_items_failed: 0,
      failure_report: [],
    }
    await flushPromises()
    expect(wrapper.find('[data-test="style-class-job"]').text()).toContain('2 of 6')
    expect(wrapper.find('[data-test="style-class-save"]').attributes('disabled')).toBeDefined()
    expect(
      wrapper.find('[data-test="style-class-remove-everywhere"]').attributes('disabled'),
    ).toBeDefined()
    wrapper.unmount()
  })

  it('a failed job shows its id and runs again after confirming', async () => {
    queueMutate
      .mockReset()
      .mockResolvedValue({ id: 'job00000002', status: 'running', kind: 'remove' })
    job.value = {
      id: 'job00000001',
      class_id: 'band00000001',
      kind: 'remove',
      status: 'failed',
      passes: 3,
      work_items_total: 1,
      work_items_done: 0,
      work_items_failed: 1,
      failure_report: [{ source: 'entry_draft', id: 'e1', reason: 'still references the class' }],
    }
    list.value = { generation: 5, classes: [{ ...band, locked_by_job: null }] }
    const r = router('/settings/style-classes/band00000001')
    await r.isReady()
    const wrapper = mount(EditPage, { global: { plugins: [r] }, attachTo: document.body })
    await flushPromises()

    expect(wrapper.find('[data-test="style-class-job-id"]').text()).toContain('job00000001')
    await wrapper.find('[data-test="style-class-job-retry"]').trigger('click')
    await flushPromises()
    ;(document.body.querySelector('[data-test="style-class-job-confirm"]') as HTMLElement).click()
    await flushPromises()

    expect(queueMutate).toHaveBeenCalledWith({ id: 'band00000001', kind: 'remove' })
    wrapper.unmount()
  })

  it('a version conflict keeps the edits, adopts the current version and refetches', async () => {
    mutate.mockRejectedValueOnce(
      new ApiError(
        'conflict',
        409,
        {},
        {
          error: { details: { code: 'STYLE_CLASS_VERSION_CONFLICT', current_version: 5 } },
        },
      ),
    )
    const r = router('/settings/style-classes/band00000001')
    await r.isReady()
    const wrapper = mount(EditPage, { global: { plugins: [r] }, attachTo: document.body })
    await flushPromises()
    await wrapper.find('[data-test="style-class-name"]').setValue('Hero strip')
    await wrapper.find('[data-test="style-class-save"]').trigger('click')
    await flushPromises()
    ;(document.body.querySelector('[data-test="style-class-save-confirm"]') as HTMLElement).click()
    await flushPromises()
    expect(refetch).toHaveBeenCalled()
    expect((wrapper.find('[data-test="style-class-name"]').element as HTMLInputElement).value).toBe(
      'Hero strip',
    )
    expect(wrapper.text()).toContain('Version 5')
    expect(notify.error).toHaveBeenCalled()
    wrapper.unmount()
  })
})
