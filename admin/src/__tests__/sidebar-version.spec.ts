import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { UpdateStatus } from '@/queries/updates'

// The Thallo version sits at the foot of the sidebar, above the account button, with a link to the
// update when a newer release is published — not inside the account menu.

const status = ref<UpdateStatus | null>(null)
const visible = ref(false)
vi.mock('@/composables/useUpdateNotice', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/composables/useUpdateNotice')>()),
  useUpdateNotice: () => ({ status, visible, dismiss: vi.fn() }),
}))

import SidebarVersion from '@/components/SidebarVersion.vue'

const stubs = {
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
  // A tooltip needs UApp's provider and is not under test (keyed by the component's own name).
  Tooltip: { template: '<div><slot /></div>' },
  // The popover renders its trigger and its content side by side: what opens it is not under test.
  Popover: {
    template: '<div><slot /><div data-test="popover-content"><slot name="content" /></div></div>',
  },
  UpdateCheckNow: {
    props: ['status'],
    template: '<div data-test="check-now">{{ status?.current }}</div>',
  },
}
const running = (over: Partial<UpdateStatus> = {}): UpdateStatus =>
  ({
    current: '1.0.0-beta.74',
    latest: null,
    available: false,
    development: false,
    notesUrl: 'https://example.test/notes',
    failedAt: null,
    ...over,
  }) as UpdateStatus

beforeEach(() => {
  status.value = null
  visible.value = false
})

describe('the version at the foot of the sidebar', () => {
  it('names the version this site runs', () => {
    status.value = running()
    const w = mount(SidebarVersion, { props: { collapsed: false }, global: { stubs } })
    expect(w.find('[data-test="sidebar-version"]').text()).toContain('Thallo 1.0.0-beta.74')
    expect(w.find('[data-test="sidebar-version-update"]').exists()).toBe(false)
  })

  it('links to the update when a newer release is published', () => {
    status.value = running({ latest: '1.0.0-beta.75', available: true })
    visible.value = true
    const w = mount(SidebarVersion, { props: { collapsed: false }, global: { stubs } })
    const link = w.find('[data-test="sidebar-version-update"]')
    expect(link.attributes('href')).toBe('/')
    expect(link.attributes('aria-label')).toBe('Update available: 1.0.0-beta.75')
  })

  it('shows nothing before the version is known', () => {
    const w = mount(SidebarVersion, { props: { collapsed: false }, global: { stubs } })
    expect(w.find('[data-test="sidebar-version"]').exists()).toBe(false)
  })

  it('a collapsed sidebar keeps only the update link', () => {
    status.value = running({ latest: '1.0.0-beta.75', available: true })
    visible.value = true
    const w = mount(SidebarVersion, { props: { collapsed: true }, global: { stubs } })
    expect(w.text()).not.toContain('Thallo 1.0.0-beta.74')
    expect(w.find('[data-test="sidebar-version-update"]').exists()).toBe(true)
  })
})

describe('checking for updates from the version', () => {
  // The daily check runs at 04:00; Health had the only way to check sooner, so the version itself,
  // always on screen, opens it.
  it('the version opens a check for updates', () => {
    status.value = running()
    const w = mount(SidebarVersion, { props: { collapsed: false }, global: { stubs } })
    const toggle = w.find('[data-test="sidebar-version-toggle"]')
    expect(toggle.element.tagName).toBe('BUTTON')
    expect(toggle.text()).toBe('Thallo 1.0.0-beta.74')
    expect(toggle.attributes('aria-label')).toBe('Thallo 1.0.0-beta.74: check for updates')
    expect(w.find('[data-test="popover-content"] [data-test="check-now"]').text()).toBe(
      '1.0.0-beta.74',
    )
  })

  it('keeps the update link beside it when a newer release is published', () => {
    status.value = running({ latest: '1.0.0-beta.75', available: true })
    visible.value = true
    const w = mount(SidebarVersion, { props: { collapsed: false }, global: { stubs } })
    expect(w.find('[data-test="sidebar-version-toggle"]').exists()).toBe(true)
    expect(w.find('[data-test="sidebar-version-update"]').attributes('href')).toBe('/')
  })

  it('a development checkout is never checked, so its version opens nothing', () => {
    status.value = running({ development: true, current: null })
    const w = mount(SidebarVersion, { props: { collapsed: false }, global: { stubs } })
    expect(w.text()).toContain('Thallo — development checkout')
    expect(w.find('[data-test="sidebar-version-toggle"]').exists()).toBe(false)
  })

  it('a collapsed sidebar offers no check: there is no version to click', () => {
    status.value = running({ latest: '1.0.0-beta.75', available: true })
    visible.value = true
    const w = mount(SidebarVersion, { props: { collapsed: true }, global: { stubs } })
    expect(w.find('[data-test="sidebar-version-toggle"]').exists()).toBe(false)
  })
})

describe('where the version sits', () => {
  it('is in the sidebar footer with the account button, so it stays put while the nav scrolls', async () => {
    const { readFileSync } = await import('node:fs')
    const { join } = await import('node:path')
    const layout = readFileSync(join(__dirname, '..', 'layouts', 'default.vue'), 'utf8')
    const footer = layout.slice(
      layout.indexOf('<template #footer'),
      layout.indexOf('</UDashboardSidebar>'),
    )
    expect(footer).toContain('<SidebarVersion')
    expect(footer.indexOf('<SidebarVersion')).toBeLessThan(footer.indexOf('<UserMenu'))
    expect(layout.split('<SidebarVersion').length - 1).toBe(1)
  })

  it('sits above the footer divider, which runs between it and the account button', async () => {
    const { readFileSync } = await import('node:fs')
    const { join } = await import('node:path')
    const layout = readFileSync(join(__dirname, '..', 'layouts', 'default.vue'), 'utf8')
    // The footer itself carries no border, or the line would run above the version.
    expect(layout).not.toMatch(/footer:\s*'[^']*border-t/)
    const footer = layout.slice(
      layout.indexOf('<template #footer'),
      layout.indexOf('</UDashboardSidebar>'),
    )
    const divider = footer.slice(footer.indexOf('<SidebarVersion'), footer.indexOf('<UserMenu'))
    expect(divider).toMatch(/class="[^"]*border-t[^"]*"/)
  })
})
