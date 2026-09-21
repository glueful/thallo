import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import {
  folderOptions,
  pageTypes,
  reportLines,
  type TypeLike,
} from '@/pages/settings/import-export/markdownFolder'

const setupDocs = vi.fn()
vi.mock('@/queries/docs', () => ({
  useSetupDocs: () => ({ mutateAsync: setupDocs, isLoading: { value: false } }),
}))
const notify = { success: vi.fn(), error: vi.fn() }
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import MarkdownFolderFields from '@/pages/settings/import-export/MarkdownFolderFields.vue'

const field = (name: string, type = 'string') => ({ name, type })
const DOCS = { slug: 'docs', name: 'Docs', schema: [field('title'), field('body', 'text')] }
const BLOG = { slug: 'blog', name: 'Blog', schema: [field('title'), field('body', 'text')] }
const TEAM = { slug: 'team', name: 'Team', schema: [field('name')] }

describe('a Markdown folder import: what is sent', () => {
  it('sends the type, the publish choice and the locale, and leaves out what was left empty', () => {
    expect(
      folderOptions({ type: 'docs', publish: true, editBase: '  ', exclude: '', locale: 'en' }),
    ).toEqual({ content_type: 'docs', publish: true, locale: 'en', exclude: [] })
  })

  it('reads the folders to leave out as a list, however they were typed', () => {
    const sent = folderOptions({
      type: 'docs',
      publish: false,
      editBase: ' https://github.com/acme/site/edit/main/docs/ ',
      exclude: ' internal, drafts ,, /private/ ',
      locale: 'en',
    })
    expect(sent.exclude).toEqual(['internal', 'drafts', 'private'])
    expect(sent.edit_base).toBe('https://github.com/acme/site/edit/main/docs')
  })

  it('offers only the content types that can hold a page: a title and a body', () => {
    expect(pageTypes([DOCS, TEAM, BLOG]).map((t) => t.value)).toEqual(['docs', 'blog'])
  })
})

describe('a Markdown folder import: its report', () => {
  it('leads with what needs attention, then says what each page became', () => {
    const lines = reportLines([
      {
        uuid: '1',
        record_number: 1,
        severity: 'info',
        code: 'markdown_page_created',
        message: 'a.md was created at /docs/a',
        created_at: null,
      },
      {
        uuid: '2',
        record_number: 2,
        severity: 'error',
        code: 'markdown_page_failed',
        message: 'b.md: no title',
        created_at: null,
      },
      {
        uuid: '3',
        record_number: 1,
        severity: 'warning',
        code: 'markdown_broken_link',
        message: 'a.md links to x.md',
        created_at: null,
      },
    ])
    expect(lines.map((l) => l.severity)).toEqual(['error', 'warning', 'info'])
  })
})

describe('Set up documentation', () => {
  beforeEach(() => {
    setupDocs.mockReset()
    notify.success.mockReset()
  })

  const lastType = (wrapper: ReturnType<typeof mountFields>): unknown => {
    const emitted = wrapper.emitted('update:type') ?? []
    return emitted.length ? emitted[emitted.length - 1]![0] : undefined
  }
  const mountFields = (contentTypes: TypeLike[]) =>
    mount(MarkdownFolderFields, {
      props: { contentTypes, type: '', publish: false, editBase: '', exclude: '' },
    })

  it('is offered when the site has no docs section, and one click makes it and chooses it', async () => {
    setupDocs.mockResolvedValue({
      type: 'docs',
      created: true,
      listed: true,
      missing: [],
      url: '/docs',
    })
    const wrapper = mountFields([BLOG])
    const button = wrapper.get('[data-test="setup-docs"]')

    await button.trigger('click')
    await flushPromises()

    expect(setupDocs).toHaveBeenCalledWith({})
    expect(lastType(wrapper)).toBe('docs')
    expect(notify.success).toHaveBeenCalled()
  })

  it('is not offered once the docs section exists, which is then the type chosen', async () => {
    const wrapper = mountFields([BLOG, DOCS])
    await flushPromises()
    expect(wrapper.find('[data-test="setup-docs"]').exists()).toBe(false)
    expect(lastType(wrapper)).toBe('docs')
  })

  it('says what an existing docs type is missing instead of pretending it is ready', async () => {
    setupDocs.mockResolvedValue({
      type: 'docs',
      created: false,
      listed: false,
      missing: ['section', 'order'],
      url: '/docs',
    })
    const wrapper = mountFields([])
    await wrapper.get('[data-test="setup-docs"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="setup-docs-missing"]').text()).toContain('section, order')
  })
})
