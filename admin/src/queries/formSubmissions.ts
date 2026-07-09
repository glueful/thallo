import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { responseError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

// Form submissions admin API (core, /v1/admin/form-submissions/*). Untyped in the OpenAPI
// spec for now, so it rides on authFetch like queries/navigation.ts.

export type SubmissionStatus = 'unread' | 'read'

export interface SubmissionSummary {
  uuid: string
  form_key: string
  form_name: string
  source_url: string | null
  status: SubmissionStatus
  submitted_at: string
}

export interface SubmissionFieldSnapshot {
  key: string
  label: string
  type: string
  required?: boolean
}

export interface SubmissionDetail extends SubmissionSummary {
  fields_snapshot: SubmissionFieldSnapshot[]
  values: Record<string, unknown>
  descriptor_version: number
  ip: string | null
  user_agent: string | null
}

export interface SubmissionFilter {
  formKey?: string
  status?: SubmissionStatus | ''
}

const base = () => `${runtimeConfig.apiBase}/form-submissions`

function filterQuery(filter: SubmissionFilter): string {
  const qs = new URLSearchParams()
  if (filter.formKey) qs.set('form_key', filter.formKey)
  if (filter.status) qs.set('status', filter.status)
  const s = qs.toString()
  return s === '' ? '' : `?${s}`
}

export async function fetchSubmissions(filter: SubmissionFilter = {}): Promise<SubmissionSummary[]> {
  const json = await authFetch(`${base()}${filterQuery(filter)}`)
  const d = (json.data ?? json) as { submissions?: SubmissionSummary[] }
  return d.submissions ?? []
}

export async function fetchSubmission(uuid: string): Promise<SubmissionDetail> {
  const json = await authFetch(`${base()}/${uuid}`)
  const d = (json.data ?? json) as { submission?: SubmissionDetail }
  return d.submission as SubmissionDetail
}

export async function markRead(uuid: string): Promise<void> {
  await authFetch(`${base()}/${uuid}/read`, { method: 'PATCH' })
}

export async function deleteSubmission(uuid: string): Promise<void> {
  await authFetch(`${base()}/${uuid}`, { method: 'DELETE' })
}

export async function fetchUnreadCount(): Promise<number> {
  const json = await authFetch(`${base()}/unread-count`)
  const d = (json.data ?? json) as { count?: number }
  return d.count ?? 0
}

/** The CSV export URL (auth-gated; use downloadSubmissionsCsv, not a bare href). */
export function submissionsExportUrl(filter: SubmissionFilter = {}): string {
  return `${base()}/export.csv${filterQuery(filter)}`
}

/**
 * Fetch the CSV with the session bearer and trigger a browser download — a plain anchor
 * can't attach the Authorization header the export route requires (mirrors downloadExport).
 */
export async function downloadSubmissionsCsv(filter: SubmissionFilter = {}): Promise<void> {
  const token = useSessionStore().accessToken
  const res = await fetch(submissionsExportUrl(filter), {
    headers: token ? { authorization: `Bearer ${token}` } : {},
  })
  if (!res.ok) throw await responseError(res, 'Could not download submissions.')
  const blob = await res.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'form-submissions.csv'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

export function useSubmissions(filter: MaybeRefOrGetter<SubmissionFilter>) {
  return useQuery({
    key: () => {
      const f = toValue(filter)
      return qk.formSubmissions(f.formKey ?? '', f.status ?? '')
    },
    query: () => fetchSubmissions(toValue(filter)),
  })
}

export function useSubmission(
  uuid: MaybeRefOrGetter<string>,
  enabled?: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => qk.formSubmission(toValue(uuid)),
    query: () => fetchSubmission(toValue(uuid)),
    enabled: () => {
      const on = enabled === undefined ? true : toValue(enabled)
      return on && toValue(uuid) !== ''
    },
  })
}

export function useUnreadCount() {
  return useQuery({
    key: () => qk.formSubmissionsUnread(),
    query: fetchUnreadCount,
  })
}

export function useSubmissionMutations() {
  const cache = useQueryCache()
  const invalidate = () => {
    cache.invalidateQueries({ key: ['form-submissions'] })
  }
  return {
    markRead: useMutation({
      mutation: (uuid: string) => markRead(uuid),
      onSettled: invalidate,
    }),
    remove: useMutation({
      mutation: (uuid: string) => deleteSubmission(uuid),
      onSettled: invalidate,
    }),
  }
}
