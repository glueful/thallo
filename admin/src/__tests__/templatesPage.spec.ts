import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import type { TemplateDetail, TemplateRow } from '@/queries/templates'

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const fetchTemplatesMock = vi.hoisted(() => vi.fn())
const fetchTemplateMock = vi.hoisted(() => vi.fn())
const saveTemplateMock = vi.hoisted(() => vi.fn())
const cloneThemeMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/templates', async (importOriginal) => ({
  // violationsFrom (pure) comes from the real module; the fetchers are mocked.
  ...(await importOriginal<typeof import('@/queries/templates')>()),
  fetchTemplates: fetchTemplatesMock,
  fetchTemplate: fetchTemplateMock,
  saveTemplate: saveTemplateMock,
  cloneTheme: cloneThemeMock,
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
    fetchTemplatesMock
      .mockReset()
      .mockResolvedValue({ theme: 'default', themes: ['default'], templates: rows() })
    fetchTemplateMock.mockReset().mockResolvedValue(detail())
    saveTemplateMock.mockReset()
    cloneThemeMock.mockReset()
    document.body.innerHTML = ''
  })

  it('lists templates grouped by family with origin badges', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="template-item-entry.twig"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="template-item-entry/blog.twig"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Select a template to view or override it.')
  })

  it('search filters files and force-opens matching folders', async () => {
    const wrapper = mountPage()
    await flushPromises()

    const search = wrapper.find(
      '[data-test="template-search"] input, input[data-test="template-search"]',
    )
    await search.setValue('blog')
    expect(wrapper.find('[data-test="template-item-entry/blog.twig"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="template-item-entry.twig"]').exists()).toBe(false)
    // The matching folder is force-open while searching.
    expect(wrapper.find('[data-test="template-group-entry"]').attributes('aria-expanded')).toBe(
      'true',
    )

    await search.setValue('zzz')
    expect(wrapper.text()).toContain('No templates match')
  })

  it('duplicating clones the CURRENT theme and switches editing to the clone', async () => {
    cloneThemeMock.mockResolvedValue({ theme: 'corporate', themes: ['default', 'corporate'] })
    const wrapper = mount(TemplatesPage, {
      global: { stubs: { TemplateEditor: true, HistoryPanel: true } },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-test="clone-theme-open"]').trigger('click')
    await flushPromises()

    // The modal teleports — reach it through the document (house rules).
    const input = document.querySelector(
      '[data-test="clone-theme-name"] input, input[data-test="clone-theme-name"]',
    ) as HTMLInputElement
    expect(input).toBeTruthy()
    input.value = 'corporate'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()

    document
      .querySelector('[data-test="clone-theme-submit"]')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(cloneThemeMock).toHaveBeenCalledWith('corporate', 'default')
    expect(notify.success).toHaveBeenCalled()
    // Switching to the clone reloads the listing under ?theme=corporate.
    expect(fetchTemplatesMock).toHaveBeenLastCalledWith('corporate')
    wrapper.unmount()
  })

  it('the theme switcher is always visible and carries the active theme', async () => {
    // Always rendered — even with one theme it shows WHERE switching lives.
    const wrapper = mountPage()
    await flushPromises()
    const select = wrapper.find('[data-test="theme-select"]')
    expect(select.exists()).toBe(true)
    expect(select.text()).toContain('default')
  })

  it('read-only theme files open as viewers without save/history controls', async () => {
    fetchTemplatesMock.mockResolvedValue({
      theme: 'default',
      themes: ['default'],
      templates: [
        ...rows(),
        {
          path: 'assets/site.css',
          origin: 'default',
          overridden: false,
          updated_at: null,
          readonly: true,
        },
      ],
    })
    fetchTemplateMock.mockResolvedValue({
      path: 'assets/site.css',
      theme: 'default',
      origin: 'default',
      source: '.site-header { display: flex; }',
      version_uuid: null,
      readonly: true,
    })
    const wrapper = mountPage()
    await flushPromises()

    const row = wrapper.find('[data-test="template-item-assets/site.css"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('read-only')

    await row.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="readonly-note"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="readonly-badge"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="save-template"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="history-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="delete-override"]').exists()).toBe(false)
  })

  it('the pinned custom.css entry opens the empty state on 404 without an error toast', async () => {
    const wrapper = mountPage()
    await flushPromises()

    const pinned = wrapper.find('[data-test="template-item-custom.css"]')
    expect(pinned.exists()).toBe(true) // always visible, even before the first save
    expect(pinned.text()).toContain('empty')

    fetchTemplateMock.mockRejectedValueOnce(new ApiError('Not Found', 404, {}, {}))
    await pinned.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="template-detail"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="custom-css-note"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="fs-origin-note"]').exists()).toBe(false)
    expect(notify.error).not.toHaveBeenCalled()
  })

  it('a saved custom.css row flips the badge to db and never joins folder groups', async () => {
    fetchTemplatesMock.mockResolvedValue({
      theme: 'default',
      themes: ['default'],
      templates: [
        ...rows(),
        { path: 'custom.css', origin: 'db', overridden: true, updated_at: '2026-07-05 09:00:00' },
      ],
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="template-item-custom.css"]').text()).toContain('db')
    // No 'root' folder entry for it — the pinned Site entry owns the path.
    expect(wrapper.findAll('[data-test="template-item-custom.css"]')).toHaveLength(1)
  })

  it('folders collapse by default except root, and expand independently', async () => {
    const wrapper = mountPage()
    await flushPromises()

    const rootGroup = wrapper.find('[data-test="template-group-root"]')
    const entryGroup = wrapper.find('[data-test="template-group-entry"]')
    expect(rootGroup.exists()).toBe(true)
    expect(entryGroup.exists()).toBe(true)
    expect(rootGroup.attributes('aria-expanded')).toBe('true') // root opens by default
    expect(entryGroup.attributes('aria-expanded')).toBe('false')

    await entryGroup.trigger('click')
    expect(entryGroup.attributes('aria-expanded')).toBe('true')

    await rootGroup.trigger('click') // root still toggles closed
    expect(rootGroup.attributes('aria-expanded')).toBe('false')
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
