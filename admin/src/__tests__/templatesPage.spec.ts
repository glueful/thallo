import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import type { TemplateDetail, TemplateRow } from '@/queries/templates'

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const fetchTemplatesMock = vi.hoisted(() => vi.fn())
const fetchTemplateMock = vi.hoisted(() => vi.fn())
const saveTemplateMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/templates', async (importOriginal) => ({
  // violationsFrom (pure) comes from the real module; the fetchers are mocked.
  ...(await importOriginal<typeof import('@/queries/templates')>()),
  fetchTemplates: fetchTemplatesMock,
  fetchTemplate: fetchTemplateMock,
  saveTemplate: saveTemplateMock,
  deleteTemplate: vi.fn(),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: notify.success, error: notify.error }),
}))
// Nuxt UI's Link override pulls useRoute from vue-router/auto (UButton renders through it).
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/templates', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import TemplatesPage from '@/pages/templates/index.vue'

const rows = (): TemplateRow[] => [
  { path: 'entry.twig', origin: 'default', overridden: false, updated_at: null },
  { path: 'entry/blog.twig', origin: 'db', overridden: true, updated_at: '2026-07-03 10:00:00' },
]

const detail = (): TemplateDetail => ({
  path: 'entry.twig',
  theme: 'default',
  origin: 'default',
  source: '{% extends "layout.twig" %}',
  version_uuid: null,
})

// CodeMirror needs real DOM APIs jsdom lacks; the editor is stubbed — the page's
// logic (list, open, save, violations) is what's under test.
const mountPage = () =>
  mount(TemplatesPage, { global: { stubs: { TemplateEditor: true, HistoryPanel: true } } })

describe('templates page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    notify.success.mockClear()
    notify.error.mockClear()
    fetchTemplatesMock.mockReset().mockResolvedValue({ theme: 'default', templates: rows() })
    fetchTemplateMock.mockReset().mockResolvedValue(detail())
    saveTemplateMock.mockReset()
  })

  it('lists templates grouped by family with origin badges', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="template-item-entry.twig"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="template-item-entry/blog.twig"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Select a template to view or override it.')
  })

  it('opening a filesystem template shows the copy-from-disk note', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="template-item-entry.twig"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="template-detail"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="fs-origin-note"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="delete-override"]').exists()).toBe(false) // fs: nothing to delete
  })

  it('a 422 save renders the linter violations at their lines', async () => {
    saveTemplateMock.mockRejectedValue(
      new ApiError(
        'Template policy violations.',
        422,
        {},
        {
          success: false,
          message: 'Template policy violations.',
          errors: [{ line: 2, message: 'Filter "raw" is not allowed.' }],
        },
      ),
    )
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="template-item-entry.twig"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="save-template"]').trigger('click')
    await flushPromises()

    const violations = wrapper.findAll('[data-test="violation"]')
    expect(violations).toHaveLength(1)
    expect(violations[0]!.text()).toContain('Line 2')
    expect(violations[0]!.text()).toContain('raw')
    expect(notify.error).not.toHaveBeenCalled() // violations render inline, not as a toast
  })

  it('a successful save toasts and marks the template as a db override', async () => {
    saveTemplateMock.mockResolvedValue({ version_uuid: 'v00000000001' })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="template-item-entry.twig"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="save-template"]').trigger('click')
    await flushPromises()

    expect(saveTemplateMock).toHaveBeenCalledWith(
      'entry.twig',
      '{% extends "layout.twig" %}',
      'default',
    )
    expect(notify.success).toHaveBeenCalled()
    expect(wrapper.find('[data-test="fs-origin-note"]').exists()).toBe(false) // now origin=db
  })
})
