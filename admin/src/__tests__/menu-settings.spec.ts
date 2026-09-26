import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { computed, ref } from 'vue'

// A role's or user's menus and landing page (Users & Access): each sidebar item hidden or shown,
// and where signing in lands. The endpoints are mocked; what is sent is what the spec reads.

const api = vi.hoisted(() => ({ fetch: vi.fn(), save: vi.fn() }))
vi.mock('@/queries/uiSettings', () => ({
  fetchUiSettings: (...a: unknown[]) => api.fetch(...a),
  useSaveUiSettings: () => api.save,
}))
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))
vi.mock('@/navigation/sidebar', () => ({
  useVisibleNav: () =>
    computed(() => [
      [
        { label: 'Home', to: '/' },
        { label: 'Content', children: [] },
        { label: 'Media', to: '/media' },
        { label: 'Analytics', to: '/analytics' },
      ],
      [{ label: 'Utilities', children: [{ label: 'Health', to: '/utilities/health' }] }],
    ]),
}))
vi.mock('@/queries/contentTypes', () => ({
  useContentTypes: () => ({ data: ref([{ slug: 'page', name: 'Pages' }]) }),
}))

import MenuSettings from '@/components/access/MenuSettings.vue'

const pressed = (w: ReturnType<typeof mount>, state: string, path: string) =>
  w.find(`[data-test="menu-${state}-${path}"]`).attributes('aria-pressed')

beforeEach(() => {
  api.fetch.mockReset()
  api.save.mockReset().mockImplementation(async (_s, _u, settings) => settings)
  notify.success.mockReset()
  notify.error.mockReset()
})

describe('menus and landing page', () => {
  it('lists every sidebar item, the content types among them, with the saved state', async () => {
    api.fetch.mockResolvedValue({ menus: { '/media': 'hidden' }, landing: null })
    const w = mount(MenuSettings, { props: { subject: 'roles', uuid: 'role00000001' } })
    await flushPromises()
    expect(api.fetch).toHaveBeenCalledWith('roles', 'role00000001')
    for (const path of ['/', '/content/page', '/media', '/analytics', '/utilities/health']) {
      expect(w.find(`[data-test="menu-row-${path}"]`).exists()).toBe(true)
    }
    expect(pressed(w, 'hidden', '/media')).toBe('true')
    expect(pressed(w, 'shown', '/analytics')).toBe('true')
    // A role only hides: there is no "as their roles" for a role.
    expect(w.find('[data-test="menu-inherit-/media"]').exists()).toBe(false)
    // Hiding is not taking away: the screen says so.
    expect(w.find('[data-test="menu-settings-note"]').text()).toContain('permission')
  })

  it('a role hides and shows items and sets the landing page, saved as a whole', async () => {
    api.fetch.mockResolvedValue({ menus: { '/media': 'hidden' }, landing: null })
    const w = mount(MenuSettings, { props: { subject: 'roles', uuid: 'role00000001' } })
    await flushPromises()
    await w.find('[data-test="menu-shown-/media"]').trigger('click')
    await w.find('[data-test="menu-hidden-/analytics"]').trigger('click')
    w.findComponent({ name: 'Select' }).vm.$emit('update:modelValue', '/content/page')
    await flushPromises()
    await w.find('[data-test="menu-settings-save"]').trigger('click')
    await flushPromises()
    expect(api.save).toHaveBeenCalledWith('roles', 'role00000001', {
      menus: { '/analytics': 'hidden' },
      landing: '/content/page',
    })
    expect(notify.success).toHaveBeenCalled()
  })

  it('a user can show what their roles hide, or go back to what their roles say', async () => {
    api.fetch.mockResolvedValue({ menus: { '/media': 'hidden' }, landing: '/media' })
    const w = mount(MenuSettings, { props: { subject: 'users', uuid: 'user00000001' } })
    await flushPromises()
    expect(pressed(w, 'inherit', '/analytics')).toBe('true')
    await w.find('[data-test="menu-shown-/analytics"]').trigger('click')
    await w.find('[data-test="menu-inherit-/media"]').trigger('click')
    w.findComponent({ name: 'Select' }).vm.$emit('update:modelValue', '__home')
    await flushPromises()
    await w.find('[data-test="menu-settings-save"]').trigger('click')
    await flushPromises()
    expect(api.save).toHaveBeenCalledWith('users', 'user00000001', {
      menus: { '/analytics': 'shown' },
      landing: null,
    })
  })

  it('a refused save says why and keeps the edits', async () => {
    api.fetch.mockResolvedValue({ menus: {}, landing: null })
    api.save.mockRejectedValue(new Error('nope'))
    const w = mount(MenuSettings, { props: { subject: 'users', uuid: 'user00000001' } })
    await flushPromises()
    await w.find('[data-test="menu-hidden-/media"]').trigger('click')
    await w.find('[data-test="menu-settings-save"]').trigger('click')
    await flushPromises()
    expect(notify.error).toHaveBeenCalled()
    expect(pressed(w, 'hidden', '/media')).toBe('true')
  })
})
