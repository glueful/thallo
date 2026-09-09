import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref, toValue, type MaybeRefOrGetter } from 'vue'

// On an install where Commerce is off, every entry page still requested /v1/admin/commerce/meta:
// the registry runs each panel's gate hook before filtering by capability (composables cannot
// be called conditionally), and the commerce gate fetched meta unconditionally. The gate must
// pass the capability state to the query so a disabled capability never issues the request.

const enabledIds = new Set<string>()
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: (id: string) => enabledIds.has(id) }),
}))

const metaCalls: Array<{ enabled?: MaybeRefOrGetter<boolean> } | undefined> = []
const metaStatus = ref<'pending' | 'error' | 'success'>('success')
const metaData = ref<{ can_view: boolean } | undefined>({ can_view: true })
vi.mock('@/queries/commerceMeta', () => ({
  useCommerceMeta: (opts?: { enabled?: MaybeRefOrGetter<boolean> }) => {
    metaCalls.push(opts)
    return { data: metaData, status: metaStatus }
  },
}))

import { useCommerceLinkGate } from '@/queries/commerceLinking'

describe('useCommerceLinkGate', () => {
  beforeEach(() => {
    enabledIds.clear()
    metaCalls.length = 0
    metaStatus.value = 'success'
    metaData.value = { can_view: true }
  })

  it('does not enable the meta query while the commerce capability is off', () => {
    const gate = useCommerceLinkGate()

    expect(gate.value).toBe('hidden')
    expect(metaCalls).toHaveLength(1)
    expect(toValue(metaCalls[0]?.enabled)).toBe(false)
  })

  it('enables the meta query and admits on can_view once the capability is on', () => {
    enabledIds.add('thallo.commerce')
    const gate = useCommerceLinkGate()

    expect(toValue(metaCalls[0]?.enabled)).toBe(true)
    expect(gate.value).toBe('ready')
    metaData.value = { can_view: false }
    expect(gate.value).toBe('hidden')
  })
})
