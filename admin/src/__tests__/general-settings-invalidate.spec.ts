// A general settings save refreshes the settings and the style schema (custom palette spec §5.2):
// the schema carries the palette every block colour picker offers.
import { describe, expect, it, vi } from 'vitest'

const invalidate = vi.hoisted(() => vi.fn())
const captured = vi.hoisted(() => ({ options: null as null | { onSettled?: () => void } }))
vi.mock('@pinia/colada', () => ({
  useQuery: vi.fn(),
  useQueryCache: () => ({ invalidateQueries: invalidate }),
  useMutation: (options: { onSettled?: () => void }) => {
    captured.options = options
    return {}
  },
}))
vi.mock('@/api/client', () => ({ client: {} }))

import { useGeneralSettingsMutations } from '@/queries/generalSettings'

describe('useGeneralSettingsMutations', () => {
  it('invalidates the settings and the style schema once a save settles', () => {
    useGeneralSettingsMutations()
    captured.options?.onSettled?.()
    expect(invalidate).toHaveBeenCalledWith({ key: ['settings', 'general'] })
    expect(invalidate).toHaveBeenCalledWith({ key: ['style-schema'] })
  })
})
