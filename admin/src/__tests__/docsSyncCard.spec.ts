import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { DocsRun, DocsSyncState } from '@/queries/docsSync'

// Settings › Import / Export › Documentation from GitHub (docs sync design §9).

const state = ref<DocsSyncState | undefined>(undefined)
const start = vi.fn()
const cancel = vi.fn()
const release = vi.fn()
vi.mock('@/queries/docsSync', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/docsSync')>()),
  useDocsSync: () => ({ data: state, isLoading: ref(false), error: ref(null) }),
  useStartDocsSync: () => ({ mutateAsync: start, isLoading: ref(false) }),
  useCancelDocsSync: () => ({ mutateAsync: cancel, isLoading: ref(false) }),
  useReleaseDocsSync: () => ({ mutateAsync: release, isLoading: ref(false) }),
  useRemoveDocsSource: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
  useSaveDocsSource: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
  useDocsSyncRun: () => ({ data: ref(undefined), isLoading: ref(false) }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import DocsSyncCard from '@/pages/settings/import-export/DocsSyncCard.vue'

const run = (over: Partial<DocsRun> = {}): DocsRun => ({
  id: 'run000000001',
  mode: 'commit',
  ref: 'v1.0.0-beta.109',
  sha: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
  status: 'finished',
  outcome: 'success',
  created: 0,
  updated: 3,
  unchanged: 50,
  skipped: 0,
  failed: 0,
  gone: 1,
  total: 53,
  message: null,
  created_by: null,
  created_at: '2026-11-25 14:02:00',
  finished_at: '2026-11-25 14:02:30',
  ...over,
})

const synced = (over: Partial<DocsSyncState> = {}): DocsSyncState => ({
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
  revision: 2,
  active_run: null,
  releasable: false,
  synced: run(),
  recent: [run()],
  note: {
    state: 'newer',
    followed: { ref: 'v1.0.0-beta.110', sha: 'b'.repeat(40) },
    message: null,
  },
  ...over,
})

const stubs = {
  DocsSyncForm: { template: '<div data-test="docs-sync-form" />' },
  DocsSyncRunPages: { template: '<div />' },
}

function mountCard() {
  return mount(DocsSyncCard, { props: { contentTypes: [] }, global: { stubs } })
}

beforeEach(() => {
  state.value = undefined
  start.mockReset()
  cancel.mockReset()
  release.mockReset()
})

describe('Documentation from GitHub', () => {
  it('offers the form when no source is saved', async () => {
    state.value = synced({
      source: null,
      synced: null,
      recent: [],
      note: { state: 'none', followed: null, message: null },
    })
    const w = mountCard()
    await flushPromises()
    expect(w.find('[data-test="docs-sync-form"]').exists()).toBe(true)
  })

  it('sums up the source, the last sync and what is new', async () => {
    state.value = synced()
    const w = mountCard()
    await flushPromises()
    const text = w.text()
    expect(text).toContain(
      'glueful/thallo · docs/ · latest release (pre-releases included) → Docs, published',
    )
    expect(text).toContain('Last synced v1.0.0-beta.109 (a1b2c3d)')
    expect(text).toContain('3 updated · 50 unchanged · 1 gone')
    expect(text).toContain('v1.0.0-beta.110 is out.')
  })

  it('says each note state', async () => {
    const cases: Array<[DocsSyncState['note'], string]> = [
      [{ state: 'up_to_date', followed: null, message: null }, 'Up to date.'],
      [
        {
          state: 'tag_moved',
          followed: { ref: 'v1.0.0-beta.109', sha: 'c'.repeat(40) },
          message: null,
        },
        'v1.0.0-beta.109 has changed since your sync.',
      ],
      [{ state: 'not_synced', followed: null, message: null }, 'Not synced from this source yet.'],
      [
        {
          state: 'rate_limited',
          followed: null,
          message: "GitHub's hourly limit is reached; try again after 15:00.",
        },
        "GitHub's hourly limit is reached; try again after 15:00.",
      ],
    ]
    for (const [note, expected] of cases) {
      state.value = synced({ note })
      const w = mountCard()
      await flushPromises()
      expect(w.text()).toContain(expected)
      w.unmount()
    }
  })

  it('a branch that moved names its short commit', async () => {
    const base = synced()
    state.value = synced({
      source: { ...base.source!, follow: 'branch', branch: 'main' },
      note: {
        state: 'branch_moved',
        followed: { ref: 'main', sha: 'd4e5f6a7' + '0'.repeat(32) },
        message: null,
      },
    })
    const w = mountCard()
    await flushPromises()
    expect(w.text()).toContain('main has new commits (d4e5f6a).')
  })

  it('lists each outcome in the history', async () => {
    state.value = synced({
      recent: [
        run({ id: 'r1', outcome: 'partial', failed: 2 }),
        run({
          id: 'r2',
          outcome: 'refused',
          message: 'The folder `doc` holds no pages.',
          created: 0,
          updated: 0,
          unchanged: 0,
          gone: 0,
        }),
        run({ id: 'r3', outcome: 'failed', message: 'GitHub could not be reached.' }),
        run({ id: 'r4', outcome: 'cancelled', created: 12, updated: 0, unchanged: 0, gone: 0 }),
        run({
          id: 'r5',
          outcome: 'interrupted',
          created: 30,
          updated: 0,
          unchanged: 0,
          gone: 0,
          message: 'Stopped after 5 attempts without finishing.',
        }),
        run({ id: 'r6', mode: 'dry_run', outcome: 'success', created: 1, updated: 2 }),
      ],
    })
    const w = mountCard()
    await flushPromises()
    const text = w.text()
    expect(text).toContain('Synced with errors: 2 pages failed')
    expect(text).toContain('Sync refused: The folder `doc` holds no pages.')
    expect(text).toContain('Sync failed: GitHub could not be reached.')
    expect(text).toContain('Cancelled after 12 of 53 pages')
    expect(text).toContain(
      'Interrupted after 30 of 53 pages: Stopped after 5 attempts without finishing.',
    )
    expect(text).toContain('Would change 3')
  })

  it('while a run holds the slot: progress, Cancel, and the actions held back', async () => {
    const live = run({
      id: 'live',
      status: 'running',
      outcome: null,
      created: 29,
      updated: 0,
      unchanged: 0,
      gone: 0,
    })
    state.value = synced({ active_run: 'live', recent: [live, run()] })
    const w = mountCard()
    await flushPromises()
    expect(w.text()).toContain('Writing page 30 of 53')
    expect(w.find('[data-test="docs-sync-now"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="docs-sync-preview"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="docs-sync-edit"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="docs-sync-release"]').exists()).toBe(false)
    await w.find('[data-test="docs-sync-cancel"]').trigger('click')
    expect(cancel).toHaveBeenCalledWith('live')
  })

  it('offers Release only when the server says the run is releasable', async () => {
    const stuck = run({ id: 'stuck', status: 'dispatched', outcome: null })
    state.value = synced({ active_run: 'stuck', releasable: true, recent: [stuck] })
    const w = mountCard()
    await flushPromises()
    await w.find('[data-test="docs-sync-release"]').trigger('click')
    expect(release).toHaveBeenCalledWith('stuck')
  })

  it('Sync now and Preview start the matching run', async () => {
    state.value = synced()
    const w = mountCard()
    await flushPromises()
    await w.find('[data-test="docs-sync-now"]').trigger('click')
    await w.find('[data-test="docs-sync-preview"]').trigger('click')
    expect(start.mock.calls).toEqual([['commit'], ['dry_run']])
  })
})
