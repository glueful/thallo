import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { DocsRun, DocsSyncState } from '@/queries/docsSync'

// Documentation from GitHub lives on the docs pages list: a slim bar above the entries, with the
// details (history, Preview, the source) in a side panel.

const state = ref<DocsSyncState | undefined>(undefined)
const failed = ref<unknown>(null)
const start = vi.fn()
vi.mock('@/queries/docsSync', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/docsSync')>()),
  useDocsSync: () => ({ data: state, error: failed, isLoading: ref(false) }),
  useStartDocsSync: () => ({ mutateAsync: start, isLoading: ref(false) }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import DocsSyncBar from '@/components/docs-sync/DocsSyncBar.vue'

const run = (over: Partial<DocsRun> = {}): DocsRun => ({
  id: 'run000000001',
  mode: 'commit',
  ref: 'v1.0.0-beta.110',
  sha: '0fe1541' + '0'.repeat(33),
  status: 'finished',
  outcome: 'success',
  created: 0,
  updated: 19,
  unchanged: 33,
  skipped: 1,
  failed: 0,
  gone: 0,
  total: 53,
  message: null,
  created_by: null,
  created_at: '2026-11-25 20:39:00',
  finished_at: '2026-11-25 20:40:00',
  ...over,
})

const docsState = (over: Partial<DocsSyncState> = {}): DocsSyncState => ({
  source: {
    repo: 'glueful/thallo',
    folder: 'docs',
    follow: 'release',
    branch: null,
    prereleases: true,
    content_type: 'docs',
    publish: true,
    exclude: [],
    locale: 'en',
    edit_branch: 'main',
  },
  revision: 1,
  active_run: null,
  releasable: false,
  synced: run(),
  recent: [run()],
  note: { state: 'up_to_date', followed: null, message: null },
  ...over,
})

const docsType = {
  slug: 'docs',
  name: 'Docs',
  schema: [{ name: 'title' }, { name: 'body' }, { name: 'source_path' }],
}
const blogType = { slug: 'blog', name: 'Blog', schema: [{ name: 'title' }, { name: 'body' }] }

const stubs = {
  DocsSyncCard: { template: '<div data-test="docs-sync-card" />' },
  USlideover: { template: '<div><slot /><slot name="body" /></div>' },
}

function mountBar(type: string) {
  return mount(DocsSyncBar, {
    props: { type, contentTypes: [docsType, blogType] },
    global: { stubs },
  })
}

beforeEach(() => {
  state.value = undefined
  failed.value = null
  start.mockReset()
})

describe('the docs sync bar on a pages list', () => {
  it('sums up the sync on the type the source imports into', async () => {
    state.value = docsState()
    const w = mountBar('docs')
    await flushPromises()
    expect(w.text()).toContain('Synced from glueful/thallo')
    expect(w.text()).toContain('v1.0.0-beta.110')
    expect(w.text()).toContain('Up to date.')
    await w.find('[data-test="docs-sync-bar-now"]').trigger('click')
    expect(start).toHaveBeenCalledWith('commit')
  })

  it('shows nothing on another type', async () => {
    state.value = docsState()
    const w = mountBar('blog')
    await flushPromises()
    expect(w.find('[data-test="docs-sync-bar"]').exists()).toBe(false)
  })

  it('offers Sync from GitHub on a docs-shaped type with no source', async () => {
    state.value = docsState({
      source: null,
      synced: null,
      recent: [],
      note: { state: 'none', followed: null, message: null },
    })
    expect(mountBar('docs').text()).toContain('Sync from GitHub')
    expect(mountBar('blog').find('[data-test="docs-sync-bar"]').exists()).toBe(false)
  })

  it('follows a running sync and holds Sync now back', async () => {
    const live = run({
      id: 'live',
      status: 'running',
      outcome: null,
      created: 9,
      updated: 0,
      unchanged: 0,
      skipped: 0,
    })
    state.value = docsState({ active_run: 'live', recent: [live, run()] })
    const w = mountBar('docs')
    await flushPromises()
    expect(w.text()).toContain('Writing page 10 of 53')
    expect(w.find('[data-test="docs-sync-bar-now"]').attributes('disabled')).toBeDefined()
  })

  it('shows nothing to someone who may not read imports', async () => {
    failed.value = new Error('Forbidden')
    const w = mountBar('docs')
    await flushPromises()
    expect(w.find('[data-test="docs-sync-bar"]').exists()).toBe(false)
  })
})

describe('where it lives', () => {
  it('is on the pages list, not under Settings › Import / Export', async () => {
    const { readFileSync } = await import('node:fs')
    const { join } = await import('node:path')
    const list = readFileSync(
      join(__dirname, '..', 'pages', 'content', '[type]', 'index.vue'),
      'utf8',
    )
    const imports = readFileSync(
      join(__dirname, '..', 'pages', 'settings', 'import-export', 'index.vue'),
      'utf8',
    )
    expect(list).toContain('<DocsSyncBar')
    expect(imports).not.toContain('DocsSyncCard')
  })
})
