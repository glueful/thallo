import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const fetchAccountSettings = vi.fn()
const saveAccountRedirects = vi.fn()

// Mock the network calls but keep the REAL isSafeReturnPath (the page's client validation).
vi.mock('@/queries/accountSettings', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/accountSettings')>()
  return {
    ...actual,
    fetchAccountSettings: (...a: unknown[]) => fetchAccountSettings(...a),
    saveAccountRedirects: (...a: unknown[]) => saveAccountRedirects(...a),
  }
})
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/settings/accounts', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))

import AccountSettingsPage from '@/pages/settings/accounts/index.vue'
import PathCombobox from '@/pages/settings/accounts/components/PathCombobox.vue'

const settings = () => ({
  pages: [
    { label: 'Sign in', path: '/account/login' },
    { label: 'Account dashboard', path: '/account' },
  ],
  after_login: '/account/orders',
  after_logout: null as string | null,
  suggestions: {
    after_login: [{ label: 'Account', path: '/account' }],
    after_logout: [
      { label: 'Home', path: '/' },
      { label: 'Sign in', path: '/account/login' },
    ],
  },
})

describe('settings/accounts page', () => {
  beforeEach(() => {
    fetchAccountSettings.mockReset().mockResolvedValue(settings())
    saveAccountRedirects.mockReset().mockResolvedValue(settings())
  })

  it('renders the allowlisted page inventory and the current redirect', async () => {
    const wrapper = mount(AccountSettingsPage)
    await flushPromises()

    const links = wrapper.findAll('[data-testid="account-page-link"]')
    expect(links.map((l) => l.text())).toEqual(['/account/login', '/account'])
    const loginInput = wrapper.find('[data-testid="after-login-input"]').element as HTMLInputElement
    expect(loginInput.value).toBe('/account/orders')
  })

  it('offers the curated redirect suggestions when a field is focused', async () => {
    const wrapper = mount(AccountSettingsPage)
    await flushPromises()

    // Focusing the input fires focusin (bubbles) in the browser; trigger it on the combobox root.
    const login = wrapper.findAllComponents(PathCombobox)[0]
    await login.trigger('focusin')
    const opts = login.findAll('[data-testid="path-suggestion"]').map((o) => o.text())
    expect(opts.some((t) => t.includes('/account'))).toBe(true)
  })

  it('fills the field from a picked suggestion', async () => {
    const wrapper = mount(AccountSettingsPage)
    await flushPromises()

    const logout = wrapper.findAllComponents(PathCombobox)[1]
    await logout.trigger('focusin')
    const signIn = logout
      .findAll('[data-testid="path-suggestion"]')
      .find((o) => o.text().includes('/account/login'))
    await signIn?.trigger('mousedown')
    await flushPromises()

    const input = wrapper.find('[data-testid="after-logout-input"]').element as HTMLInputElement
    expect(input.value).toBe('/account/login')
  })

  it('saves valid redirects, clearing a blanked field to null', async () => {
    const wrapper = mount(AccountSettingsPage)
    await flushPromises()

    await wrapper.find('[data-testid="after-login-input"]').setValue('/account/dashboard')
    await wrapper.find('[data-testid="after-logout-input"]').setValue('')
    await wrapper.find('[data-testid="save-account-redirects"]').trigger('click')
    await flushPromises()

    expect(saveAccountRedirects).toHaveBeenCalledWith('/account/dashboard', null)
  })

  it('blocks a hostile redirect client-side and does not call the API', async () => {
    const wrapper = mount(AccountSettingsPage)
    await flushPromises()

    await wrapper.find('[data-testid="after-login-input"]').setValue('//evil.example')
    await wrapper.find('[data-testid="save-account-redirects"]').trigger('click')
    await flushPromises()

    expect(saveAccountRedirects).not.toHaveBeenCalled()
  })
})
