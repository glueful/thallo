import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import CapabilityErrorPanel from '@/components/CapabilityErrorPanel.vue'

// Nuxt UI components are unstubbable in @vue/test-utils — assert via the data-testid
// hooks the panel exposes, never Nuxt UI internals (house convention).
describe('CapabilityErrorPanel', () => {
  it('renders the default copy and emits retry on click', async () => {
    const wrapper = mount(CapabilityErrorPanel)

    expect(wrapper.find('[data-testid="capability-error-panel"]').exists()).toBe(true)
    expect(wrapper.text()).toContain("We couldn't check which features are enabled")

    await wrapper.find('[data-testid="capability-error-retry"]').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
  })

  it('accepts custom copy for local reuse (e.g. the navigation page)', () => {
    const wrapper = mount(CapabilityErrorPanel, {
      props: { title: 'Custom title', description: 'Custom description' },
    })
    expect(wrapper.text()).toContain('Custom title')
    expect(wrapper.text()).toContain('Custom description')
  })
})
