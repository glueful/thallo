import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }))
const rollback = vi.hoisted(() => ({ mutateAsync: vi.fn().mockResolvedValue(undefined) }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))
vi.mock('@/queries/versions', () => ({
  useVersions: () => ({
    data: ref([{ uuid: 'ver000000001', version: 3, created_at: '2026-09-20T10:00:00Z' }]),
    status: ref('success'),
  }),
  useRollback: () => ({ ...rollback, isLoading: ref(false) }),
}))

import VersionsPanel from '@/pages/content/[type]/[uuid]/components/VersionsPanel.vue'

describe('restoring a version', () => {
  it('says the live page changed and the draft did not, which is what the server does', async () => {
    // The toast used to say "The draft now carries this version's content"; rollback re-pins
    // the published version and never writes the draft.
    const wrapper = mount(VersionsPanel, {
      props: { uuid: 'entry0000001', locale: 'en', type: 'page' },
    })
    await wrapper.get('[data-test="version-restore-ver000000001"]').trigger('click')
    await flushPromises()

    expect(rollback.mutateAsync).toHaveBeenCalledWith('ver000000001')
    const [title, description] = notify.success.mock.calls[0] as [string, string]
    expect(title).toBe('Version restored')
    expect(description).toContain('live page')
    expect(description).toContain('draft is unchanged')
    expect(description).not.toContain('draft now carries')
  })
})
