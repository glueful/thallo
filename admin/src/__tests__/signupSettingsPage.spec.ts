import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SignupSettingsPage from '@/pages/settings/signup/index.vue'

describe('single-store signup settings page', () => {
  it('renders the member-signup control', () => {
    const wrapper = mount(SignupSettingsPage, {
      global: {
        stubs: {
          UDashboardPanel: {
            template: '<section><slot name="header"/><slot name="body"/></section>',
          },
          UDashboardNavbar: { props: ['title'], template: '<h1>{{ title }}</h1>' },
          MemberSignupSettings: {
            props: ['scope'],
            template: '<div data-testid="member-signup-settings" :data-scope="scope" />',
          },
          UButton: { props: ['to'], template: '<a :href="to"><slot /></a>' },
          RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
        },
      },
    })

    expect(wrapper.get('h1').text()).toBe('Signup')
    expect(wrapper.find('[data-testid="member-signup-settings"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="member-signup-settings"]').attributes('data-scope')).toBe(
      'single-store',
    )
    expect(wrapper.get('[data-testid="manage-signup-roles"]').text()).toContain('Manage roles')
  })
})
