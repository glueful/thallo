import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }))
const rollback = vi.hoisted(() => ({ mutateAsync: vi.fn().mockResolvedValue(undefined) }))
const fields = { title: 'Original' }
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))
vi.mock('@/queries/versions', () => ({
  useVersions: () => ({
    data: ref([{ uuid: 'ver000000001', version: 3, created_at: '2026-09-20T10:00:00Z', fields }]),
    status: ref('success'),
  }),
  useRollback: () => ({ ...rollback, isLoading: ref(false) }),
}))

import VersionsPanel from '@/pages/content/[type]/[uuid]/components/VersionsPanel.vue'

const mountPanel = () =>
  mount(VersionsPanel, { props: { uuid: 'entry0000001', locale: 'en', type: 'page' } })

beforeEach(() => {
  notify.success.mockClear()
  rollback.mutateAsync.mockClear()
})

describe('a version', () => {
  it('can be restored to the draft: the page is told, and nothing is published', async () => {
    const wrapper = mountPanel()
    await wrapper.get('[data-test="version-restore-draft-ver000000001"]').trigger('click')

    expect(wrapper.emitted('restore-draft')).toEqual([[{ version: 3, fields }]])
    expect(rollback.mutateAsync).not.toHaveBeenCalled()
  })

  it('can be made live: the server re-pins it, and the draft is unchanged', async () => {
    // Rollback re-pins the published version and never writes the draft; the button and the
    // toast both say so, so it is not mistaken for bringing the version back into the editor.
    const wrapper = mountPanel()
    const button = wrapper.get('[data-test="version-make-live-ver000000001"]')
    expect(button.text()).toBe('Make live')
    await button.trigger('click')
    await flushPromises()

    expect(rollback.mutateAsync).toHaveBeenCalledWith('ver000000001')
    const [title, description] = notify.success.mock.calls[0] as [string, string]
    expect(title).toBe('Version 3 is live')
    expect(description).toContain('draft is unchanged')
    expect(wrapper.emitted('restore-draft')).toBeUndefined()
  })
})
