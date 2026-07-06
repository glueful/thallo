import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { core } from '@/api/client'
import { responseError, toApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { runtimeConfig } from '@/runtime/config'

// ── Import/Export (glueful/import-export extension, /import-export/*) ──────────────────────────────
// The job API lives on the root `core` client. Two endpoints are Thallo-owned (under /v1/admin):
// the export-result download and the import-file upload — both need raw fetch (binary / multipart),
// so they go through the session bearer directly rather than the typed client.
// The OpenAPI spec types the nested response payloads loosely, so we pin the contracts here.

export interface IeAdapter {
  key: string
  label: string
}
export interface IeAdapters {
  importers: IeAdapter[]
  exporters: IeAdapter[]
}

export type IeJobType = 'import' | 'export'
export type IeJobStatus =
  | 'pending'
  | 'planning'
  | 'queued'
  | 'running'
  | 'completed'
  | 'failed'
  | 'cancelled'
  | (string & {})

export interface IeJob {
  uuid: string
  type: IeJobType
  adapter: string
  status: IeJobStatus
  mode: string | null
  format: string | null
  total_records: number
  processed_records: number
  failed_records: number
  error_overflow_count: number
  created_at: string | null
  started_at: string | null
  finished_at: string | null
}

export interface IeJobError {
  uuid: string
  record_number: number | null
  severity: string
  code: string
  message: string
  created_at: string | null
}

export interface IeReport {
  uuid: string
  job_uuid: string
  summary: Record<string, unknown>
  created_at: string | null
}

const ACTIVE_STATUSES: ReadonlySet<string> = new Set(['pending', 'planning', 'queued', 'running'])
/** Whether a job is still progressing (so the page should keep polling). */
export function isJobActive(status: string): boolean {
  return ACTIVE_STATUSES.has(status)
}

function payload(data: unknown): Record<string, unknown> {
  const envelope = data as { data?: unknown } | undefined
  return (envelope?.data ?? {}) as Record<string, unknown>
}

// ── Adapters ──────────────────────────────────────────────────────────────────────────────────────
export async function fetchAdapters(): Promise<IeAdapters> {
  const { data, error, response } = await core.GET('/import-export/adapters')
  if (error) throw toApiError(error, response)
  const d = payload(data)
  return {
    importers: (d.importers as IeAdapter[] | undefined) ?? [],
    exporters: (d.exporters as IeAdapter[] | undefined) ?? [],
  }
}
export function useAdapters() {
  return useQuery({ key: ['import-export', 'adapters'], query: fetchAdapters })
}

// ── Jobs ────────────────────────────────────────────────────────────────────────────────────────
export async function fetchJobs(): Promise<IeJob[]> {
  const { data, error, response } = await core.GET('/import-export/jobs')
  if (error) throw toApiError(error, response)
  return (payload(data).jobs as IeJob[] | undefined) ?? []
}
export function useJobs() {
  return useQuery({ key: ['import-export', 'jobs'], query: fetchJobs })
}

export async function fetchJobErrors(uuid: string): Promise<IeJobError[]> {
  // The query is created up-front with an empty uuid (no job selected yet) — don't hit /jobs//errors.
  if (!uuid) return []
  const { data, error, response } = await core.GET('/import-export/jobs/{uuid}/errors', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  return (payload(data).errors as IeJobError[] | undefined) ?? []
}
export function useJobErrors(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => ['import-export', 'job-errors', toValue(uuid)],
    query: () => fetchJobErrors(toValue(uuid)),
  })
}

export async function fetchJobReport(uuid: string): Promise<IeReport | null> {
  if (!uuid) return null
  const { data, error, response } = await core.GET('/import-export/jobs/{uuid}/report', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  return (payload(data).report as IeReport | undefined) ?? null
}
export function useJobReport(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => ['import-export', 'job-report', toValue(uuid)],
    query: () => fetchJobReport(toValue(uuid)),
  })
}

// ── Create / lifecycle mutations ────────────────────────────────────────────────────────────────
export async function createExport(input: {
  adapter: string
  format?: string
}): Promise<IeJob | null> {
  const { data, error, response } = await core.POST('/import-export/exports', {
    body: { adapter: input.adapter, format: input.format ?? 'ndjson' } as never,
  })
  if (error) throw toApiError(error, response)
  return (payload(data).job as IeJob | undefined) ?? null
}

export interface CreateImportInput {
  adapter: string
  disk: string
  path: string
  mode: 'dry_run' | 'commit'
  /** Adapter-specific config (e.g. CSV: content_type, mapping, locale, publish). */
  options?: Record<string, unknown>
}
export async function createImport(input: CreateImportInput): Promise<IeJob | null> {
  const { data, error, response } = await core.POST('/import-export/imports', {
    body: {
      adapter: input.adapter,
      disk: input.disk,
      path: input.path,
      mode: input.mode,
      options: input.options ?? {},
    } as never,
  })
  if (error) throw toApiError(error, response)
  return (payload(data).job as IeJob | undefined) ?? null
}

export async function cancelJob(uuid: string): Promise<void> {
  const { error, response } = await core.POST('/import-export/jobs/{uuid}/cancel', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

export async function retryJob(uuid: string): Promise<void> {
  const { error, response } = await core.POST('/import-export/jobs/{uuid}/retry', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

export function useImportExportMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: ['import-export', 'jobs'] })

  const runExport = useMutation({ mutation: createExport, onSettled: invalidate })
  const runImport = useMutation({ mutation: createImport, onSettled: invalidate })
  const cancel = useMutation({ mutation: cancelJob, onSettled: invalidate })
  const retry = useMutation({ mutation: retryJob, onSettled: invalidate })

  return { runExport, runImport, cancel, retry }
}

// ── Thallo-owned upload (multipart) + download (binary) ────────────────────────────────────────────
export interface UploadedImport {
  disk: string
  path: string
  name?: string
  mime_type?: string
}

/** Upload an .ndjson import file to the uploads disk; returns {disk, path} for createImport. */
export async function uploadImportFile(file: File): Promise<UploadedImport> {
  const token = useSessionStore().accessToken
  const form = new FormData()
  form.append('file', file)
  const res = await fetch(`${runtimeConfig.apiBase}/import-export/upload`, {
    method: 'POST',
    headers: token ? { authorization: `Bearer ${token}` } : {},
    body: form,
  })
  if (!res.ok) throw await responseError(res, 'Could not upload the import file.')
  const json = (await res.json().catch(() => ({}))) as { data?: UploadedImport }
  return json.data ?? { disk: 'uploads', path: '' }
}

/** Fetch a completed export's result and trigger a browser download. */
export async function downloadExport(uuid: string): Promise<void> {
  const token = useSessionStore().accessToken
  const res = await fetch(`${runtimeConfig.apiBase}/import-export/jobs/${uuid}/download`, {
    headers: token ? { authorization: `Bearer ${token}` } : {},
  })
  if (!res.ok) throw await responseError(res, 'Could not download the export.')
  const blob = await res.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `export-${uuid}.ndjson`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
