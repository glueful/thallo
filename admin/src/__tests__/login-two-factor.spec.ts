import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const login = vi.fn()
const completeTwoFactor = vi.fn()
const notify = { success: vi.fn(), error: vi.fn() }
vi.mock('@/stores/session', () => ({ useSessionStore: () => ({ login, completeTwoFactor }) }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import LoginPage from '@/pages/login.vue'

function router() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', component: LoginPage },
      { path: '/', component: { template: '<div>home</div>' } },
      { path: '/forgot-password', component: { template: '<div />' } },
    ],
  })
}

async function signIn(wrapper: ReturnType<typeof mount>) {
  await wrapper.get('input[type="email"]').setValue('a@b.co')
  await wrapper.get('input[autocomplete="current-password"]').setValue('secret')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('signing in with two-factor on', () => {
  beforeEach(() => {
    login.mockReset()
    completeTwoFactor.mockReset()
  })

  it('asks for the emailed code, then completes the sign-in with it', async () => {
    // Login used to throw "Malformed login response." on a two-factor account: no way in.
    login.mockResolvedValue({ token: 'chal-1', expiresIn: 300, deliveredTo: 'a***@b.c' })
    completeTwoFactor.mockResolvedValue(undefined)
    const r = router()
    await r.push('/login')
    const wrapper = mount(LoginPage, { global: { plugins: [r] }, attachTo: document.body })

    await signIn(wrapper)

    expect(wrapper.text()).toContain('a***@b.c')
    expect(r.currentRoute.value.path).toBe('/login')
    await wrapper.get('input[autocomplete="one-time-code"]').setValue('123456')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(completeTwoFactor).toHaveBeenCalledWith('chal-1', '123456')
    expect(r.currentRoute.value.path).toBe('/')
    wrapper.unmount()
  })

  it('an account without two-factor goes straight in', async () => {
    login.mockResolvedValue(null)
    const r = router()
    await r.push('/login')
    const wrapper = mount(LoginPage, { global: { plugins: [r] }, attachTo: document.body })

    await signIn(wrapper)

    expect(completeTwoFactor).not.toHaveBeenCalled()
    expect(r.currentRoute.value.path).toBe('/')
    wrapper.unmount()
  })
})
