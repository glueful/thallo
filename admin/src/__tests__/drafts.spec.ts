import { describe, it, expect, vi, beforeEach } from 'vitest'

const { GET, PUT } = vi.hoisted(() => ({ GET: vi.fn(), PUT: vi.fn() }))
vi.mock('@/api/client', () => ({ client: { GET, PUT } }))

import { fetchDraft, saveDraft } from '@/queries/drafts'

describe('draft query/mutation', () => {
  beforeEach(() => {
    GET.mockReset()
    PUT.mockReset()
  })

  it('fetchDraft returns fields + lock_version', async () => {
    GET.mockResolvedValue({
      data: { data: { draft: { fields: { title: 'Home' }, lock_version: 3 } } },
      error: undefined,
    })
    const res = await fetchDraft('e1', 'en')
    expect(GET).toHaveBeenCalledWith('/entries/{uuid}/draft/{locale}', {
      params: { path: { uuid: 'e1', locale: 'en' } },
    })
    expect(res).toEqual({ fields: { title: 'Home' }, lock_version: 3 })
  })

  it('fetchDraft defaults to empty fields + lock_version 0', async () => {
    GET.mockResolvedValue({ data: { data: {} }, error: undefined })
    expect(await fetchDraft('e1', 'en')).toEqual({ fields: {}, lock_version: 0 })
  })

  it('saveDraft PUTs the fields object and lock_version', async () => {
    PUT.mockResolvedValue({ data: { data: { draft: {} } }, error: undefined })
    await saveDraft('e1', 'en', { fields: { title: 'Hi' }, lock_version: 3 })
    expect(PUT).toHaveBeenCalledWith('/entries/{uuid}/draft/{locale}', {
      params: { path: { uuid: 'e1', locale: 'en' } },
      body: { fields: { title: 'Hi' }, lock_version: 3, preview_revision: null },
    })
  })

  it('saveDraft throws on error (e.g. a 409 lock conflict)', async () => {
    PUT.mockResolvedValue({ data: undefined, error: { status: 409 } })
    await expect(saveDraft('e1', 'en', { fields: {}, lock_version: 1 })).rejects.toBeTruthy()
  })
})
