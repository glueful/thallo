import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import UpdateAvailableCard from '@/components/UpdateAvailableCard.vue'

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
  }

  it('names both versions, links the release notes, and shows the upgrade command', () => {
    const wrapper = mount(UpdateAvailableCard, { props: { status } })

    expect(wrapper.find('[data-testid="update-available-card"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('1.0.0-beta.22')
    expect(wrapper.text()).toContain('1.0.0-beta.21')
    expect(wrapper.find('[data-testid="update-available-notes"]').attributes('href')).toBe(
      status.notesUrl,
    )
    expect(wrapper.find('[data-testid="update-available-command"]').text()).toBe(
      'composer update && php glueful thallo:provision',
    )
  })

  it('emits dismiss', async () => {
    const wrapper = mount(UpdateAvailableCard, { props: { status } })

    await wrapper.find('[data-testid="update-available-dismiss"]').trigger('click')

    expect(wrapper.emitted('dismiss')).toHaveLength(1)
  })
})
