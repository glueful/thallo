import { describe, it, expect, vi, beforeEach } from 'vitest'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { fetchMenus, fetchMenu, saveTree, createMenu, reorderMenus } from '@/queries/navigation'

describe('navigation query layer', () => {
  beforeEach(() => authFetch.mockReset())

  it('fetchMenus hits /navigation/menus and unwraps data.menus', async () => {
    authFetch.mockResolvedValue({
      data: { menus: [{ slug: 'main', name: 'Main', item_count: 3, lock_version: 2 }] },
    })
    const menus = await fetchMenus()
    expect(menus[0]!.slug).toBe('main')
    expect(authFetch.mock.calls[0][0]).toBe('/v1/admin/navigation/menus')
  })

  it('fetchMenu is locale-keyed: hits /navigation/menus/{slug}?locale=', async () => {
    authFetch.mockResolvedValue({
      data: { slug: 'main', name: 'Main', locale: 'fr', lock_version: 1, items: [] },
    })
    const menu = await fetchMenu('main', 'fr')
    expect(menu.locale).toBe('fr')
    expect(authFetch.mock.calls[0][0]).toBe('/v1/admin/navigation/menus/main?locale=fr')
  })

  it('saveTree PUTs lock_version + items with the locale', async () => {
    authFetch.mockResolvedValue({
      data: { slug: 'main', name: 'Main', locale: 'en', lock_version: 2, items: [] },
    })
    const items = [{ kind: 'url' as const, url: '/about', labels: { en: 'About' }, children: [] }]
    await saveTree('main', 1, items, 'en')
    const [url, init] = authFetch.mock.calls[0] as [string, { method: string; body: string }]
    expect(url).toBe('/v1/admin/navigation/menus/main/items?locale=en')
    expect(init.method).toBe('PUT')
    expect(JSON.parse(init.body)).toEqual({ lock_version: 1, items })
  })

  it('createMenu POSTs slug + name', async () => {
    authFetch.mockResolvedValue({ data: {} })
    await createMenu('footer', 'Footer')
    const [url, init] = authFetch.mock.calls[0] as [string, { method: string; body: string }]
    expect(url).toBe('/v1/admin/navigation/menus')
    expect(JSON.parse(init.body)).toEqual({ slug: 'footer', name: 'Footer' })
  })

  it('reorderMenus POSTs the full slug list to /menus/reorder and unwraps data.menus', async () => {
    authFetch.mockResolvedValue({ data: { menus: [{ slug: 'c', name: 'C', item_count: 0, lock_version: 0 }] } })
    const out = await reorderMenus(['c', 'a', 'b'])
    const [url, init] = authFetch.mock.calls[0] as [string, { method: string; body: string }]
    expect(url).toBe('/v1/admin/navigation/menus/reorder')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body)).toEqual({ slugs: ['c', 'a', 'b'] })
    expect(out[0]!.slug).toBe('c')
  })
})
