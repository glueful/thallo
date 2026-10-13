import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { PiniaColada, useQueryCache } from '@pinia/colada'
import UpdateCheckNow from '@/components/UpdateCheckNow.vue'
import type { UpdateStatus } from '@/queries/updates'

const POST = vi.hoisted(() => vi.fn())
vi.mock('@/api/client', () => ({ client: { POST } }))

/** The check endpoint answering `update`. */
const answers = (update: UpdateStatus) =>
  POST.mockResolvedValue({
    data: { data: { update } },
    response: new Response(null, { status: 200 }),
  })

const status: UpdateStatus = {
  current: '1.0.0-beta.21',
  latest: null,
  available: false,
  development: false,
  enabled: true,
  checkedAt: '2026-10-12T04:00:00+00:00',
  notesUrl: 'https://github.com/glueful/thallo/blob/main/CHANGELOG.md',
  failedAt: null,
}

function mountWith(props: { status: UpdateStatus | null | undefined }) {
  return mount(UpdateCheckNow, { props, global: { plugins: [createPinia(), PiniaColada] } })
}

// Health's "Check now": asks Packagist without waiting for the daily 04:00 check.
describe('UpdateCheckNow', () => {
  beforeEach(() => {
    POST.mockReset()
  })

  it('offers nothing to a development checkout or an install with the check turned off', () => {
    for (const s of [{ ...status, development: true }, { ...status, enabled: false }, null]) {
      const wrapper = mountWith({ status: s })
      expect(wrapper.find('[data-testid="update-check-now"]').exists()).toBe(false)
    }
  })

  it('says when the last check ran, or that none has', () => {
    expect(mountWith({ status }).find('[data-testid="update-check-last"]').text()).toContain(
      'Last checked',
    )
    expect(
      mountWith({ status: { ...status, checkedAt: null } })
        .find('[data-testid="update-check-last"]')
        .text(),
    ).toBe('Not checked yet')
  })

  it('checks now, says a newer version is out, and hands the status to every notice', async () => {
    const after = { ...status, latest: '1.0.0-beta.22', available: true }
    answers(after)
    const wrapper = mountWith({ status })
    const cache = wrapper.vm.$.appContext.app.runWithContext(() => useQueryCache())

    await wrapper.find('[data-testid="update-check-now"]').trigger('click')
    await flushPromises()

    expect(POST).toHaveBeenCalledWith('/update-status/check')
    expect(wrapper.find('[data-testid="update-check-result"]').text()).toBe(
      'Thallo 1.0.0-beta.22 is available.',
    )
    expect(cache.getQueryData(['utilities', 'update-status'])).toEqual(after)
  })

  it('says when this install is on the newest version', async () => {
    answers({ ...status })
    const wrapper = mountWith({ status })

    await wrapper.find('[data-testid="update-check-now"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="update-check-result"]').text()).toBe(
      'You are on the newest version.',
    )
  })

  it('says when Packagist could not be reached', async () => {
    answers({ ...status, failedAt: '2026-10-13T10:00:00+00:00' })
    const wrapper = mountWith({ status })

    await wrapper.find('[data-testid="update-check-now"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="update-check-result"]').text()).toBe(
      'Could not reach Packagist. Try again later.',
    )
  })

  it('says when the request itself failed, such as too many checks in a minute', async () => {
    POST.mockRejectedValue(new Error('Too Many Requests'))
    const wrapper = mountWith({ status })

    await wrapper.find('[data-testid="update-check-now"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="update-check-result"]').text()).toBe(
      'The check did not run: Too Many Requests',
    )
  })
})
