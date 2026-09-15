// The dashboard panel fills the layout's rounded shell instead of the viewport (beta.29
// dogfooding): Nuxt UI's default `min-h-svh` overflowed the shell by its margins, and an
// overflow-hidden shell still scrolls on focus, clipping the panel header. The theme override in
// vite.config.ts (`ui.dashboardPanel.slots.root`) is what every page relies on.
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import Page from './DashboardPanelProbe.vue'

describe('dashboard panel height', () => {
  it('takes the shell height (min-h-0), never the viewport minimum', () => {
    const w = mount(Page, {
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
    })
    const root = w.find('#dashboard-panel-height-probe')
    expect(root.exists()).toBe(true)
    expect(root.classes()).toContain('min-h-0')
    expect(root.classes()).not.toContain('min-h-svh')
    w.unmount()
  })
})
