import { describe, it, expect, vi, beforeEach } from 'vitest'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import {
  fetchSubmissions,
  fetchSubmission,
  markRead,
  deleteSubmission,
  fetchUnreadCount,
  submissionsExportUrl,
} from '@/queries/formSubmissions'

describe('form submissions query layer', () => {
  beforeEach(() => authFetch.mockReset())

  it('fetchSubmissions hits /form-submissions with filters and unwraps data.submissions', async () => {
    authFetch.mockResolvedValue({
      data: {
        submissions: [
          { uuid: 'u1', form_key: 'k1', form_name: 'Contact', source_url: '/c', status: 'unread', submitted_at: 't' },
        ],
      },
    })
    const rows = await fetchSubmissions({ formKey: 'k1', status: 'unread' })
    expect(rows[0]!.uuid).toBe('u1')
    expect(authFetch.mock.calls[0][0]).toBe('/v1/admin/form-submissions?form_key=k1&status=unread')
  })

  it('fetchSubmissions omits the query string when unfiltered', async () => {
    authFetch.mockResolvedValue({ data: { submissions: [] } })
    await fetchSubmissions()
    expect(authFetch.mock.calls[0][0]).toBe('/v1/admin/form-submissions')
  })

  it('fetchSubmission unwraps data.submission', async () => {
    authFetch.mockResolvedValue({ data: { submission: { uuid: 'u1', values: { email: 'a@b.test' } } } })
    const detail = await fetchSubmission('u1')
    expect(detail.values.email).toBe('a@b.test')
    expect(authFetch.mock.calls[0][0]).toBe('/v1/admin/form-submissions/u1')
  })

  it('markRead PATCHes /{uuid}/read', async () => {
    authFetch.mockResolvedValue({ data: {} })
    await markRead('u1')
    const [url, init] = authFetch.mock.calls[0] as [string, { method: string }]
    expect(url).toBe('/v1/admin/form-submissions/u1/read')
    expect(init.method).toBe('PATCH')
  })

  it('deleteSubmission DELETEs /{uuid}', async () => {
    authFetch.mockResolvedValue({ data: {} })
    await deleteSubmission('u1')
    const [url, init] = authFetch.mock.calls[0] as [string, { method: string }]
    expect(url).toBe('/v1/admin/form-submissions/u1')
    expect(init.method).toBe('DELETE')
  })

  it('fetchUnreadCount unwraps data.count', async () => {
    authFetch.mockResolvedValue({ data: { count: 4 } })
    expect(await fetchUnreadCount()).toBe(4)
    expect(authFetch.mock.calls[0][0]).toBe('/v1/admin/form-submissions/unread-count')
  })

  it('submissionsExportUrl builds the CSV URL with filters', () => {
    expect(submissionsExportUrl({ formKey: 'k1' })).toBe('/v1/admin/form-submissions/export.csv?form_key=k1')
    expect(submissionsExportUrl()).toBe('/v1/admin/form-submissions/export.csv')
  })
})
