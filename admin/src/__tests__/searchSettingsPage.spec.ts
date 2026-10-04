import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { SearchStatus } from '@/queries/searchStatus'
import { ApiError } from '@/api/errors'

// Settings › Search (search block spec §3.8): each kind's state, Rebuild per kind and for all, the
// engine's message verbatim, a notice when background processing has not picked a request up, and
// polling that never stops — fast while work is under way, slow otherwise, and on window focus.
const data = ref<SearchStatus | undefined>(undefined)
const error = ref<unknown>(null)
const refresh = vi.fn()
const rebuild = vi.fn()
vi.mock('@/queries/searchStatus', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/searchStatus')>()
  return {
    ...actual,
    useSearchStatus: () => ({ data, status: ref('success'), error, refresh }),
    useSearchRebuild: () => ({ mutateAsync: rebuild, isLoading: ref(false) }),
  }
})
const success = vi.fn()
const warning = vi.fn()
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success, warning, error: vi.fn() }),
}))

import SearchSettingsPage from '@/pages/settings/search/index.vue'

const kind = (over: Partial<SearchStatus['kinds'][number]>): SearchStatus['kinds'][number] => ({
  kind: 'entries',
  label: 'Pages & posts',
  available: true,
  reason: null,
  status: 'ready',
  documents: 12,
  processed: 12,
  last_success_at: '2026-10-04 10:00:00',
  last_error: null,
  demand_pending: false,
  stalled: false,
  ...over,
})
const status = (
  kinds: SearchStatus['kinds'],
  engine: Partial<SearchStatus['engine']> = {},
): SearchStatus => ({
  engine: { ready: true, message: null, version: null, ...engine },
  kinds,
})

describe('Settings › Search', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    refresh.mockReset()
    rebuild.mockReset()
    error.value = null
    data.value = status([
      kind({}),
      kind({ kind: 'products', label: 'Products', available: false, reason: 'Requires Commerce' }),
    ])
  })
  afterEach(() => vi.useRealTimers())

  it('lists every kind, with the unavailable one’s reason and no Rebuild for it', () => {
    const wrapper = mount(SearchSettingsPage)
    expect(wrapper.get('[data-test="search-kind-entries"]').text()).toContain('Ready')
    expect(wrapper.get('[data-test="search-kind-products"]').text()).toContain('Requires Commerce')
    expect(wrapper.find('[data-test="search-rebuild-products"]').exists()).toBe(false)
  })

  it('Rebuild asks for one kind, Rebuild all for every kind', async () => {
    const wrapper = mount(SearchSettingsPage)
    await wrapper.get('[data-test="search-rebuild-entries"]').trigger('click')
    await wrapper.get('[data-test="search-rebuild-all"]').trigger('click')
    expect(rebuild.mock.calls).toEqual([['entries'], [null]])
  })

  it('says when the rebuild could not be queued', async () => {
    success.mockReset()
    warning.mockReset()
    rebuild.mockResolvedValueOnce({ recorded: true, queued: false })
    rebuild.mockResolvedValueOnce({ recorded: true, queued: true })
    const wrapper = mount(SearchSettingsPage)
    await wrapper.get('[data-test="search-rebuild-entries"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="search-rebuild-entries"]').trigger('click')
    await flushPromises()
    // Not a success: the request is recorded, but nothing will start until background processing runs.
    expect(warning.mock.calls).toEqual([
      ['Rebuild requested; it will start when background processing runs'],
    ])
    expect(success.mock.calls).toEqual([['Rebuild requested']])
  })

  it('says when background processing has not picked a request up', () => {
    data.value = status([kind({ stalled: true, demand_pending: true })])
    expect(mount(SearchSettingsPage).get('[data-test="search-stalled"]').text()).toContain(
      'Background processing hasn’t picked this up. Make sure the queue worker and scheduler are running, or run php glueful search:reindex --wait.',
    )
  })

  it('shows the engine’s message verbatim', () => {
    const message =
      'Meilisearch 1.10 or newer is required for search across kinds (the server reports 1.9.2). Upgrade the server, or set `SEARCH_ENGINE=postgres`.'
    data.value = status([kind({})], { ready: false, message })
    expect(mount(SearchSettingsPage).get('[data-test="search-engine"]').text()).toBe(message)
  })

  it('polls every 5 seconds while something is building and every minute otherwise, and on focus', async () => {
    data.value = status([kind({ status: 'building' })])
    mount(SearchSettingsPage)
    vi.advanceTimersByTime(15000)
    expect(refresh).toHaveBeenCalledTimes(3)

    refresh.mockReset()
    data.value = status([kind({})])
    await flushPromises()
    vi.advanceTimersByTime(59000)
    expect(refresh).toHaveBeenCalledTimes(0)
    vi.advanceTimersByTime(2000)
    expect(refresh).toHaveBeenCalledTimes(1)

    window.dispatchEvent(new Event('focus'))
    expect(refresh).toHaveBeenCalledTimes(2)
  })

  it('asks for access on a 403', () => {
    data.value = undefined
    error.value = new ApiError('Forbidden', 403, {}, null)
    expect(mount(SearchSettingsPage).get('[data-test="search-forbidden"]').text()).toBe(
      'Ask an administrator for access to search settings.',
    )
  })
})
