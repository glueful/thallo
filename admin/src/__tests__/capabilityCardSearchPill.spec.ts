import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { SearchStatus } from '@/queries/searchStatus'

// The Search card on Extensions › Capabilities shows the index's state and links to Settings › Search.
const data = ref<SearchStatus | undefined>(undefined)
vi.mock('@/queries/searchStatus', () => ({ useSearchStatus: () => ({ data }) }))

import SearchStatusPill from '@/pages/extensions/components/SearchStatusPill.vue'

const k = (over: Record<string, unknown>) => ({
  kind: 'entries',
  label: 'Pages & posts',
  available: true,
  reason: null,
  status: 'ready',
  documents: 1,
  processed: 1,
  last_success_at: null,
  last_error: null,
  demand_pending: false,
  stalled: false,
  ...over,
})
const pill = (kinds: Record<string, unknown>[]) => {
  data.value = {
    engine: { ready: true, message: null, version: null },
    kinds,
  } as unknown as SearchStatus
  return mount(SearchStatusPill, {
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
  })
}

describe('the Search status pill', () => {
  it('reads Ready, Rebuilding or Needs attention, and links to Settings › Search', () => {
    expect(pill([k({})]).text()).toContain('Ready')
    expect(pill([k({ status: 'building' })]).text()).toContain('Rebuilding')
    for (const over of [{ status: 'out_of_date' }, { status: 'failed' }, { stalled: true }]) {
      expect(pill([k(over)]).text()).toContain('Needs attention')
    }
    expect(
      pill([k({})])
        .get('[data-test="search-status-pill"]')
        .attributes('href'),
    ).toBe('/settings/search')
  })

  it('ignores kinds that are not available, and shows nothing when none is', () => {
    expect(
      pill([k({ available: false, status: 'failed' })])
        .find('[data-test="search-status-pill"]')
        .exists(),
    ).toBe(false)
  })
})
