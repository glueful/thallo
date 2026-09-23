import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'

const { GET } = vi.hoisted(() => ({ GET: vi.fn() }))
vi.mock('@/api/client', () => ({ client: { GET } }))

import { fetchEntries, useEntries } from '@/queries/entries'

describe('fetchEntries', () => {
  beforeEach(() => GET.mockReset())

  it('passes the query params and returns the page', async () => {
    GET.mockResolvedValue({
      data: {
        data: {
          entries: [
            {
              uuid: 'e1',
              display_title: 'Home',
              status: 'draft',
              locales: ['en'],
              updated_at: null,
            },
          ],
          total: 1,
          current_page: 1,
          per_page: 20,
        },
      },
      error: undefined,
    })
    const res = await fetchEntries({ type: 'page', page: 1, perPage: 20, q: 'ho' })
    expect(GET).toHaveBeenCalledWith('/entries', {
      params: { query: { type: 'page', page: 1, perPage: 20, q: 'ho' } },
    })
    expect(res.entries[0].display_title).toBe('Home')
    expect(res.total).toBe(1)
  })

  it('omits an empty q and defaults page fields', async () => {
    GET.mockResolvedValue({ data: { data: {} }, error: undefined })
    const res = await fetchEntries({ type: 'page', page: 2, perPage: 10, q: '' })
    expect(GET).toHaveBeenCalledWith('/entries', {
      params: { query: { type: 'page', page: 2, perPage: 10, q: undefined } },
    })
    expect(res.entries).toEqual([])
    expect(res.current_page).toBe(2)
  })

  it('throws on error', async () => {
    GET.mockResolvedValue({ data: undefined, error: { message: 'x' } })
    await expect(fetchEntries({ type: 'page', page: 1, perPage: 20 })).rejects.toBeTruthy()
  })
})

// Rows per page is part of what identifies a page of results: if it is not in the query key, a
// change to it serves the cached page and the table keeps the old rows while the footer counts
// the new ones ("Showing 1–25 of 46" over ten rows). Drives the composable through a tiny host
// component on a real Pinia + PiniaColada, the established pattern (commerceOrderSearch.spec.ts).
describe('useEntries', () => {
  beforeEach(() => GET.mockReset())

  it('refetches when rows per page changes', async () => {
    GET.mockResolvedValue({ data: { data: { entries: [], total: 46 } }, error: undefined })
    const perPage = ref(10)
    const Host = defineComponent({
      setup() {
        useEntries('docs', 1, perPage, '')
        return () => h('div')
      },
    })
    const wrapper = mount(Host, { global: { plugins: [createPinia(), PiniaColada] } })
    await flushPromises()
    expect(GET).toHaveBeenCalledTimes(1)

    perPage.value = 25
    await flushPromises()

    await vi.waitFor(() => expect(GET).toHaveBeenCalledTimes(2), { timeout: 2000, interval: 20 })
    expect(GET.mock.calls[1]![1].params.query.perPage).toBe(25)
    wrapper.unmount()
  })
})
