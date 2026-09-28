import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { computed, ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { LayoutRow } from '@/queries/layouts'

// Site › Layouts (type layouts spec §6.1): every page kind with its state, Edit into the editor, a
// disabled row's reason — and no Remove for an open row: it lives in the editor, which holds the
// session. A turned-off row that keeps a custom layout — its pages off the site — cannot be opened,
// so it is removed from here.

const rows = ref<LayoutRow[] | undefined>(undefined)
const canEdit = ref(true)
const q = vi.hoisted(() => ({ refetch: vi.fn(), mint: vi.fn(), remove: vi.fn() }))
vi.mock('@/queries/layouts', () => ({
  useLayouts: () => ({
    data: computed(() =>
      rows.value === undefined ? undefined : { rows: rows.value, canEdit: canEdit.value },
    ),
    isLoading: ref(false),
    error: ref(null),
    refetch: q.refetch,
  }),
  mintLayoutSession: q.mint,
  removeLayout: q.remove,
}))
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import LayoutsPage from '@/pages/layouts/index.vue'

const row = (overrides: Partial<LayoutRow>): LayoutRow => ({
  surface: 'entry',
  target: 'post',
  label: 'Posts — single post',
  reach: 'Applies to every post',
  state: 'theme',
  enabled: true,
  reason: null,
  link: null,
  lock_version: 0,
  updated_by: null,
  updated_by_name: null,
  updated_at: null,
  ...overrides,
})

function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:any(.*)*', component: { template: '<div />' } }],
  })
  return mount(LayoutsPage, {
    attachTo: document.body,
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
  canEdit.value = true
  rows.value = [
    row({
      state: 'custom',
      lock_version: 2,
      updated_at: '2026-09-26T10:00:00Z',
      updated_by: 'editor000001',
      updated_by_name: 'dana',
    }),
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
    expect(w.find('[data-test="layouts-row-entry-post"]').text()).toContain('by dana')
  })

  it('without the permission to edit, the list offers no editor and says why', async () => {
    canEdit.value = false
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="layouts-edit-entry-post"]').exists()).toBe(false)
    expect(w.find('[data-test="layouts-no-edit"]').text()).toContain('Manage templates')
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

  // Type layouts plan B: an unlisted type's listing row says how to turn its listing pages on.
  it('a disabled listing row links to the setting that turns listing pages on', async () => {
    rows.value = [
      row({
        surface: 'listing',
        target: 'page',
        label: 'Pages — listing pages',
        reach: 'Applies to every page of the page listing',
        enabled: false,
        reason: 'Listing pages are off for Pages.',
        link: '/settings/general',
      }),
    ]
    const w = mountPage()
    await flushPromises()
    const disabled = w.find('[data-test="layouts-row-listing-page"]')
    expect(disabled.find('[data-test="layouts-reason"]').text()).toBe(
      'Listing pages are off for Pages.',
    )
    const link = disabled.find('[data-test="layouts-link"]')
    expect(link.text()).toBe('Turn on listing pages')
    expect(link.attributes('href')).toBe('/settings/general')
    expect(w.find('[data-test="layouts-edit-listing-page"]').exists()).toBe(false)
  })

  it('a turned-off row that keeps a custom layout is removed from the list', async () => {
    rows.value = [
      row({
        surface: 'archive',
        target: 'post:categories',
        label: 'Posts — Categories archive',
        state: 'custom',
        enabled: false,
        reason: 'These pages are not on the site now. The layout is kept until you remove it.',
        lock_version: 4,
      }),
      row({ target: 'quote', label: 'Quotes — single quote', enabled: false, reason: 'Off.' }),
    ]
    q.mint.mockReset().mockResolvedValue({ token: 'closedtok' })
    q.remove.mockReset().mockResolvedValue({ lockVersion: 5 })
    q.refetch.mockReset()
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="layouts-edit-archive-post:categories"]').exists()).toBe(false)
    expect(w.find('[data-test="layouts-remove-entry-quote"]').exists()).toBe(false)

    await w.find('[data-test="layouts-remove-archive-post:categories"]').trigger('click')
    await flushPromises()
    // The dialog is teleported to the document's body.
    const dialog = () => document.body.querySelector('[data-test="layouts-remove-dialog"]')
    expect(dialog()?.textContent).toContain('Posts — Categories archive')
    ;(document.body.querySelector('[data-test="layouts-remove-confirm"]') as HTMLElement).click()
    await flushPromises()
    expect(q.mint).toHaveBeenCalledWith('archive', 'post:categories')
    expect(q.remove).toHaveBeenCalledWith('archive', 'post:categories', {
      token: 'closedtok',
      expected_lock_version: 4,
    })
    expect(q.refetch).toHaveBeenCalled()
    expect(notify.success).toHaveBeenCalled()
    await flushPromises()
    expect(dialog()).toBeNull()
    w.unmount()
  })

  it('without the permission to edit, a kept layout offers no Remove', async () => {
    canEdit.value = false
    rows.value = [
      row({ surface: 'listing', target: 'post', state: 'custom', enabled: false, reason: 'Off.' }),
    ]
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="layouts-remove-listing-post"]').exists()).toBe(false)
  })
})
