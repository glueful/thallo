import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'

// Mirrors analyticsEnabledGate.spec.ts's approach: mount against the REAL @pinia/colada runtime
// (the thing under test IS the `enabled` wiring itself — does the query fire at all — which a
// mocked useQuery would trivially bypass), but mocks `@/api/client` directly (media.spec.ts's
// precedent) rather than global fetch: openapi-fetch's client captures `globalThis.fetch` once at
// construction, so a raw-fetch stub only works with a fresh dynamic re-import per test (the
// fetch-stub pattern used elsewhere in this file's sibling spec) — which would in turn require
// re-importing `@pinia/colada` too, duplicating its module instance against this file's own
// static `PiniaColada`/`createPinia` imports and breaking its plugin-provided injection context.
// Mocking the client sidesteps both problems: everything here is a single, static import.
const clientGet = vi.hoisted(() => vi.fn())
vi.mock('@/api/client', () => ({ client: { GET: clientGet } }))

import { useEntrySearch, useProductSearchForLink } from '@/queries/commerceLinking'

function okResponse() {
  return { data: { data: [] }, error: undefined, response: new Response(null, { status: 200 }) }
}

function mountWith(setup: () => void) {
  const Comp = defineComponent({
    setup() {
      setup()
      return () => h('div')
    },
  })
  // Pinia must be installed before PiniaColada.
  return mount(Comp, { global: { plugins: [createPinia(), PiniaColada] } })
}

describe('useEntrySearch / useProductSearchForLink min-length gating', () => {
  beforeEach(() => {
    clientGet.mockReset().mockResolvedValue(okResponse())
  })

  it('useEntrySearch does NOT hit the backend below 2 characters', async () => {
    const term = ref('a')
    mountWith(() => useEntrySearch(term))
    await flushPromises()
    expect(clientGet).not.toHaveBeenCalled()
  })

  it('useEntrySearch hits GET /commerce/entries at exactly 2 characters', async () => {
    const term = ref('ab')
    mountWith(() => useEntrySearch(term))
    await flushPromises()
    expect(clientGet).toHaveBeenCalledTimes(1)
    expect(clientGet.mock.calls[0]![0]).toBe('/commerce/entries')
  })

  it('useProductSearchForLink does NOT hit the backend below 2 characters', async () => {
    const term = ref('a')
    mountWith(() => useProductSearchForLink(term))
    await flushPromises()
    expect(clientGet).not.toHaveBeenCalled()
  })

  it('useProductSearchForLink hits GET /commerce/products at exactly 2 characters', async () => {
    const term = ref('ab')
    mountWith(() => useProductSearchForLink(term))
    await flushPromises()
    expect(clientGet).toHaveBeenCalledTimes(1)
    expect(clientGet.mock.calls[0]![0]).toBe('/commerce/products')
  })
})
