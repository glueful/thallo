import { describe, it, expect, vi, beforeEach } from 'vitest'

const { GET, PUT, POST, DELETE } = vi.hoisted(() => ({
  GET: vi.fn(),
  PUT: vi.fn(),
  POST: vi.fn(),
  DELETE: vi.fn(),
}))
vi.mock('@/api/client', () => ({ client: { GET, PUT, POST, DELETE } }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { sitePreviewUrl: 'https://site/preview' } }))

import { fetchRoutes, saveRoute } from '@/queries/routes'
import { publishEntry, unpublishEntry } from '@/queries/publish'
import { fetchSchedules, createSchedule, cancelSchedule } from '@/queries/schedules'
import { mintPreview, buildPreviewUrl } from '@/queries/preview'
import { updateGeneralSettings } from '@/queries/generalSettings'

describe('F4 publishing queries', () => {
  beforeEach(() => {
    GET.mockReset()
    PUT.mockReset()
    POST.mockReset()
    DELETE.mockReset()
  })

  it('fetchRoutes returns data.routes', async () => {
    GET.mockResolvedValue({
      data: { data: { routes: [{ locale: 'en', slug: 'home' }] } },
      error: undefined,
    })
    expect(await fetchRoutes('e1')).toEqual([{ locale: 'en', slug: 'home' }])
  })

  it('saveRoute PUTs the slug', async () => {
    PUT.mockResolvedValue({ data: {}, error: undefined })
    await saveRoute('e1', 'en', 'about')
    expect(PUT).toHaveBeenCalledWith('/entries/{uuid}/routes/{locale}', {
      params: { path: { uuid: 'e1', locale: 'en' } },
      body: { slug: 'about' },
    })
  })

  it('publish/unpublish POST the right paths', async () => {
    POST.mockResolvedValue({ data: {}, error: undefined })
    await publishEntry('e1', 'en')
    expect(POST).toHaveBeenCalledWith('/entries/{uuid}/publish/{locale}', {
      params: { path: { uuid: 'e1', locale: 'en' } },
    })
    await unpublishEntry('e1', 'en')
    expect(POST).toHaveBeenCalledWith('/entries/{uuid}/unpublish/{locale}', {
      params: { path: { uuid: 'e1', locale: 'en' } },
    })
  })

  it('set-as-homepage PUTs homepage_entry; explicit empty string clears', async () => {
    // homepage-setting spec §1: both admin surfaces drive this one mutation.
    PUT.mockResolvedValue({ data: { data: { settings: { homepage_entry: 'e1' } } }, error: undefined })
    await updateGeneralSettings({ homepage_entry: 'e1' })
    expect(PUT).toHaveBeenCalledWith('/settings/general', { body: { homepage_entry: 'e1' } })

    PUT.mockResolvedValue({ data: { data: { settings: { homepage_entry: '' } } }, error: undefined })
    await updateGeneralSettings({ homepage_entry: '' })
    expect(PUT).toHaveBeenLastCalledWith('/settings/general', { body: { homepage_entry: '' } })
  })

  it('createSchedule POSTs action + run_at; cancelSchedule DELETEs', async () => {
    POST.mockResolvedValue({ data: {}, error: undefined })
    DELETE.mockResolvedValue({ data: {}, error: undefined })
    await createSchedule('e1', 'en', { action: 'publish', run_at: '2026-07-01T00:00:00Z' })
    expect(POST).toHaveBeenCalledWith('/entries/{uuid}/schedules/{locale}', {
      params: { path: { uuid: 'e1', locale: 'en' } },
      body: { action: 'publish', run_at: '2026-07-01T00:00:00Z' },
    })
    await cancelSchedule('e1', 's1')
    expect(DELETE).toHaveBeenCalledWith('/entries/{uuid}/schedules/{scheduleUuid}', {
      params: { path: { uuid: 'e1', scheduleUuid: 's1' } },
    })
  })

  it('fetchSchedules returns data.schedules', async () => {
    GET.mockResolvedValue({
      data: { data: { schedules: [{ uuid: 's1', action: 'publish', run_at: 'x' }] } },
      error: undefined,
    })
    expect((await fetchSchedules('e1'))[0].uuid).toBe('s1')
  })

  it('mintPreview returns the token; buildPreviewUrl appends it', async () => {
    POST.mockResolvedValue({ data: { data: { token: 'tok' } }, error: undefined })
    expect(await mintPreview('e1', 'en')).toBe('tok')
    expect(buildPreviewUrl('tok')).toBe('https://site/preview?token=tok')
  })
})
