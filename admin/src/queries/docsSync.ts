import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// ── Documentation from GitHub (Thallo\Core\Content\Http\Controllers\DocsSyncController) ─────────
//
// A docs source saved once (a public GitHub repo, a folder, the latest release tag or a branch),
// then Sync now / Preview. Runs are the sync's own, not import-export jobs: the card keeps their
// history and each run's pages. The response payloads are typed loosely by the spec, so the
// contracts are pinned here.

export interface DocsSource {
  repo: string
  folder: string
  follow: 'release' | 'branch'
  branch: string | null
  prereleases: boolean
  content_type: string
  publish: boolean
  exclude: string[]
  locale: string
  edit_branch: string
}

export type DocsOutcome = 'success' | 'partial' | 'refused' | 'failed' | 'cancelled' | 'interrupted'

export interface DocsRun {
  id: string
  mode: 'commit' | 'dry_run'
  ref: string | null
  sha: string | null
  status: 'reserved' | 'dispatched' | 'running' | 'finished'
  outcome: DocsOutcome | null
  created: number
  updated: number
  unchanged: number
  skipped: number
  failed: number
  gone: number
  total: number | null
  message: string | null
  created_by: string | null
  created_at: string | null
  finished_at: string | null
}

export type NoteState =
  | 'none'
  | 'not_synced'
  | 'up_to_date'
  | 'newer'
  | 'branch_moved'
  | 'tag_moved'
  | 'unreachable'
  | 'rate_limited'

export interface DocsNote {
  state: NoteState
  followed: { ref: string; sha: string } | null
  message: string | null
}

export interface DocsSyncState {
  source: DocsSource | null
  revision: number
  active_run: string | null
  releasable: boolean
  synced: DocsRun | null
  recent: DocsRun[]
  note: DocsNote
}

export interface DocsRunPage {
  path: string
  slug: string | null
  status: 'created' | 'updated' | 'unchanged' | 'skipped' | 'failed' | 'gone'
  message: string | null
  broken_links: string | null
  /** The entry the page wrote, or (gone) the entry its file left behind. */
  entry_uuid: string | null
}

const num = (v: unknown): number => (typeof v === 'number' ? v : Number(v ?? 0) || 0)

/** Pages handled so far: everything but the pages gone (those are counted at the end). */
export function handled(run: DocsRun): number {
  return (
    num(run.created) + num(run.updated) + num(run.unchanged) + num(run.skipped) + num(run.failed)
  )
}

const ofTotal = (run: DocsRun): string =>
  run.total ? `${handled(run)} of ${run.total} pages` : `${handled(run)} pages`

const plural = (n: number, one: string): string => `${n} ${one}${n === 1 ? '' : 's'}`

/** What the note under the summary says (§9). */
export function noteText(note: DocsNote, source: DocsSource | null): string {
  switch (note.state) {
    case 'up_to_date':
      return 'Up to date.'
    case 'newer':
      return `${note.followed?.ref ?? 'A newer version'} is out.`
    case 'branch_moved':
      return `${source?.branch ?? 'The branch'} has new commits (${(note.followed?.sha ?? '').slice(0, 7)}).`
    case 'tag_moved':
      return `${note.followed?.ref ?? 'The tag'} has changed since your sync.`
    case 'not_synced':
      return 'Not synced from this source yet.'
    case 'unreachable':
    case 'rate_limited':
      return note.message ?? 'GitHub could not be reached.'
    default:
      return ''
  }
}

/** A finished run's outcome, as the history shows it (§7). */
export function outcomeText(run: DocsRun): string {
  if (run.mode === 'dry_run') {
    if (run.outcome === 'success' || run.outcome === 'partial') {
      return `Would change ${num(run.created) + num(run.updated)}`
    }
  }
  switch (run.outcome) {
    case 'success':
      return 'Up to date'
    case 'partial':
      return `Synced with errors: ${plural(num(run.failed), 'page')} failed`
    case 'refused':
      return `Sync refused: ${run.message ?? ''}`.trim()
    case 'failed':
      return `Sync failed: ${run.message ?? ''}`.trim()
    case 'cancelled':
      return `Cancelled after ${ofTotal(run)}`
    case 'interrupted':
      return `Interrupted after ${ofTotal(run)}${run.message ? `: ${run.message}` : ''}`
    default:
      return run.status === 'finished' ? '' : progressText(run)
  }
}

/** A running run's progress. */
export function progressText(run: DocsRun): string {
  if (run.status === 'reserved' || run.status === 'dispatched') return 'Waiting for a queue worker…'
  if (run.total) return `Writing page ${Math.min(handled(run) + 1, run.total)} of ${run.total}`
  return 'Reading the repository…'
}

/** The counts line: "3 updated · 50 unchanged · 1 gone". */
export function countsText(run: DocsRun): string {
  const parts: string[] = []
  for (const key of ['created', 'updated', 'unchanged', 'skipped', 'failed', 'gone'] as const) {
    const n = num(run[key])
    if (n > 0) parts.push(`${n} ${key}`)
  }
  return parts.join(' · ')
}

// ── API ──────────────────────────────────────────────────────────────────────────────────────

const STATE_KEY = ['settings', 'docs-sync']

const payload = (data: unknown): Record<string, unknown> =>
  ((data as { data?: Record<string, unknown> } | undefined)?.data ?? {}) as Record<string, unknown>

export async function fetchDocsSync(): Promise<DocsSyncState> {
  const { data, error, response } = await client.GET('/docs-sync')
  if (error) throw toApiError(error, response)
  return payload(data) as unknown as DocsSyncState
}

/** Polls every 2 s while a run holds the slot, so progress and the outcome appear on their own. */
export function useDocsSync() {
  return useQuery({
    key: STATE_KEY,
    query: fetchDocsSync,
    refetchInterval: (state: { data?: DocsSyncState }) => (state.data?.active_run ? 2000 : false),
  } as never) as ReturnType<typeof useQuery<DocsSyncState>>
}

function invalidating<T, R>(fn: (input: T) => Promise<R>) {
  const cache = useQueryCache()
  return useMutation({
    mutation: fn,
    onSettled: () => cache.invalidateQueries({ key: STATE_KEY }),
  })
}

export function useSaveDocsSource() {
  return invalidating(async (source: Partial<DocsSource>) => {
    const { error, response } = await client.PUT('/docs-sync/source', { body: source as never })
    if (error) throw toApiError(error, response)
  })
}

export function useRemoveDocsSource() {
  return invalidating(async () => {
    const { error, response } = await client.DELETE('/docs-sync/source')
    if (error) throw toApiError(error, response)
  })
}

export function useStartDocsSync() {
  return invalidating(async (mode: 'commit' | 'dry_run') => {
    const { error, response } = await client.POST('/docs-sync/run', { body: { mode } as never })
    if (error) throw toApiError(error, response)
  })
}

export function useCancelDocsSync() {
  return invalidating(async (run: string) => {
    const { error, response } = await client.POST('/docs-sync/runs/{run}/cancel', {
      params: { path: { run } },
    })
    if (error) throw toApiError(error, response)
  })
}

export function useReleaseDocsSync() {
  return invalidating(async (run: string) => {
    const { error, response } = await client.POST('/docs-sync/runs/{run}/release', {
      params: { path: { run } },
    })
    if (error) throw toApiError(error, response)
  })
}

export function useDocsSyncRun(id: MaybeRefOrGetter<string | null>) {
  return useQuery({
    key: () => ['settings', 'docs-sync', 'run', toValue(id) ?? ''],
    enabled: () => !!toValue(id),
    query: async () => {
      const run = toValue(id) ?? ''
      const { data, error, response } = await client.GET('/docs-sync/runs/{run}', {
        params: { path: { run } },
      })
      if (error) throw toApiError(error, response)
      return payload(data) as unknown as { run: DocsRun; pages: DocsRunPage[] }
    },
  })
}
