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
}
const running = (over: Partial<UpdateStatus> = {}): UpdateStatus =>
  ({
    current: '1.0.0-beta.74',
    latest: null,
    available: false,
    development: false,
    notesUrl: 'https://example.test/notes',
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
