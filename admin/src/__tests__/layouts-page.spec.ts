import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { LayoutRow } from '@/queries/layouts'

// Site › Layouts (type layouts spec §6.1): every page kind with its state, Edit into the editor, a
// disabled row's reason — and no Remove here: it lives in the editor, which holds the session.

const rows = ref<LayoutRow[] | undefined>(undefined)
vi.mock('@/queries/layouts', () => ({
  useLayouts: () => ({ data: rows, isLoading: ref(false), error: ref(null) }),
}))

import LayoutsPage from '@/pages/layouts/index.vue'

const row = (overrides: Partial<LayoutRow>): LayoutRow => ({
  surface: 'entry',
  target: 'post',
  label: 'Posts — single post',
  reach: 'Applies to every post',
  state: 'theme',
  enabled: true,
  reason: null,
  lock_version: 0,
  updated_by: null,
  updated_at: null,
  ...overrides,
})

function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:any(.*)*', component: { template: '<div />' } }],
  })
  return mount(LayoutsPage, {
    global: {
      plugins: [router],
      stubs: {
        UDashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
        UDashboardNavbar: { template: '<div><slot /></div>' },
        UButton: { props: ['to'], template: '<a :href="to"><slot /></a>' },
      },
    },
  })
}

beforeEach(() => {
  rows.value = [
    row({ state: 'custom', lock_version: 2, updated_at: '2026-09-26T10:00:00Z' }),
    row({ target: 'pages', label: 'Pages — single page', reach: 'Applies to every page' }),
    row({
      target: 'quote',
      label: 'Quotes — single quote',
      enabled: false,
      reason: 'Quotes are not published on the site.',
    }),
  ]
})

describe('the Layouts page', () => {
  it('lists each page kind with Theme template or Custom layout', async () => {
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="layouts-list"]').exists()).toBe(true)
    expect(w.find('[data-test="layouts-state-entry-post"]').text()).toBe('Custom layout')
    expect(w.find('[data-test="layouts-state-entry-pages"]').text()).toBe('Theme template')
    expect(w.find('[data-test="layouts-row-entry-post"]').text()).toContain('Posts — single post')
    expect(w.find('[data-test="layouts-row-entry-post"]').text()).toContain('Saved')
  })

  it('Edit opens the editor for that page kind; there is no Remove on the list', async () => {
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="layouts-edit-entry-post"]').attributes('href')).toBe(
      '/layouts/entry/post',
    )
    expect(w.find('[data-test="layouts-edit-entry-pages"]').attributes('href')).toBe(
      '/layouts/entry/pages',
    )
    expect(w.text()).not.toContain('Remove')
  })

  it('a row that cannot have a layout shows why, and offers no Edit', async () => {
    const w = mountPage()
    await flushPromises()
    const disabled = w.find('[data-test="layouts-row-entry-quote"]')
    expect(disabled.find('[data-test="layouts-reason"]').text()).toBe(
      'Quotes are not published on the site.',
    )
    expect(w.find('[data-test="layouts-edit-entry-quote"]').exists()).toBe(false)
  })
})
