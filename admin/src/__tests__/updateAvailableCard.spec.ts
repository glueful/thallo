import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import UpdateAvailableCard from '@/components/UpdateAvailableCard.vue'
import { UPGRADE_COMMAND, REASON_TEXT } from '@/queries/updates'

vi.mock('@/queries/updates', async (importActual) => ({
  ...(await importActual<typeof import('@/queries/updates')>()),
  startUpdate: vi.fn(),
}))

// Nuxt UI components are unstubbable in @vue/test-utils — assert via the data-testid hooks.
describe('UpdateAvailableCard', () => {
  const status = {
    current: '1.0.0-beta.21',
    latest: '1.0.0-beta.22',
    available: true,
    development: false,
    enabled: true,
    checkedAt: '2026-09-12T04:00:00+00:00',
    notesUrl: 'https://github.com/glueful/thallo/blob/main/CHANGELOG.md',
    failedAt: null,
  }

  it('names both versions, links the release notes, and shows the upgrade command', () => {
    const wrapper = mount(UpdateAvailableCard, { props: { status } })

    expect(wrapper.find('[data-testid="update-available-card"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('1.0.0-beta.22')
    expect(wrapper.text()).toContain('1.0.0-beta.21')
    expect(wrapper.find('[data-testid="update-available-notes"]').attributes('href')).toBe(
      status.notesUrl,
    )
    expect(wrapper.find('[data-testid="update-available-command"]').text()).toBe(UPGRADE_COMMAND)
    expect(UPGRADE_COMMAND).toBe(
      'composer update glueful/thallo-core -W && php glueful thallo:provision && php glueful queue:restart',
    )
  })

  it('emits dismiss', async () => {
    const wrapper = mount(UpdateAvailableCard, { props: { status } })

    await wrapper.find('[data-testid="update-available-dismiss"]').trigger('click')

    expect(wrapper.emitted('dismiss')).toHaveLength(1)
  })

  it('offers no button when updating from here is not this user’s to run, or is off', () => {
    for (const preflight of [
      null,
      { ready: false, current: null, target: null, reasons: ['disabled'] },
    ]) {
      const wrapper = mount(UpdateAvailableCard, { props: { status, preflight } })
      expect(wrapper.find('[data-testid="update-available-start"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="update-available-reasons"]').exists()).toBe(false)
    }
  })

  it('offers the update when preflight is ready', () => {
    const preflight = {
      ready: true,
      current: '1.0.0-beta.21',
      target: '1.0.0-beta.22',
      reasons: [],
    }
    const wrapper = mount(UpdateAvailableCard, { props: { status, preflight } })

    expect(wrapper.find('[data-testid="update-available-start"]').text()).toContain(
      'Update to 1.0.0-beta.22',
    )
  })

  it('says, in a sentence each, why updating from here is unavailable', () => {
    const preflight = {
      ready: false,
      current: '1.0.0-beta.21',
      target: '1.0.0-beta.22',
      reasons: ['opcache_preload', 'guard_missing'],
    }
    const wrapper = mount(UpdateAvailableCard, { props: { status, preflight } })
    const reasons = wrapper.find('[data-testid="update-available-reasons"]')

    expect(reasons.text()).toContain(REASON_TEXT.opcache_preload)
    expect(reasons.text()).toContain(REASON_TEXT.guard_missing)
    expect(wrapper.find('[data-testid="update-available-start"]').exists()).toBe(false)
  })

  it('has a sentence for simple mode’s own refusal: the runner cannot be started', () => {
    const preflight = {
      ready: false,
      current: '1.0.0-beta.21',
      target: '1.0.0-beta.22',
      reasons: ['launch_unavailable'],
    }
    const wrapper = mount(UpdateAvailableCard, { props: { status, preflight } })
    expect(REASON_TEXT.launch_unavailable).toBeTruthy()
    expect(wrapper.find('[data-testid="update-available-reasons"]').text()).toContain(
      REASON_TEXT.launch_unavailable,
    )
  })
})
