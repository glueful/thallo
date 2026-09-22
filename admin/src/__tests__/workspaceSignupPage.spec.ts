import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'

const api = vi.hoisted(() => ({
  beginWorkspaceSignup: vi.fn(),
  verifyWorkspaceSignup: vi.fn(),
  continueWorkspaceSignup: vi.fn(),
  resendWorkspaceSignupCode: vi.fn(),
}))
vi.mock('@/api/signup', () => api)

const session = vi.hoisted(() => ({ isAuthenticated: false, login: vi.fn() }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => session }))
const tenant = vi.hoisted(() => ({ select: vi.fn(), ensureLoaded: vi.fn() }))
vi.mock('@/stores/tenant', () => ({ useTenantStore: () => tenant }))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

const route = vi.hoisted(() => ({ query: { plan: 'pro' } as Record<string, string> }))
const router = vi.hoisted(() => ({ replace: vi.fn(), push: vi.fn() }))
vi.mock('vue-router', async (orig) => ({
  ...(await orig<object>()),
  useRoute: () => route,
  useRouter: () => router,
}))
vi.mock('vue-router/auto', async (orig) => ({
  ...(await orig<object>()),
  useRoute: () => route,
  useRouter: () => router,
}))

import { createMemoryHistory, createRouter } from 'vue-router'
import SignupPage from '@/pages/signup.vue'

// A real router instance for the page's links; useRouter/useRoute stay mocked above.
const mountPage = () =>
  mount(SignupPage, {
    global: {
      plugins: [
        createRouter({
          history: createMemoryHistory(),
          routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
        }),
      ],
    },
  })

function fill(w: VueWrapper, name: string, value: string) {
  return w.find(`input[name="${name}"]`).setValue(value)
}

async function submitDetails(w: VueWrapper) {
  await fill(w, 'workspace_name', 'Acme Studio')
  await fill(w, 'first_name', 'Ada')
  await fill(w, 'last_name', 'Byron')
  await fill(w, 'email', 'ada@acme.test')
  await fill(w, 'password', 'correct-horse-battery')
  await w.find('form[data-test="signup-details"]').trigger('submit')
  await flushPromises()
}

async function submitCode(w: VueWrapper) {
  await fill(w, 'code', '123456')
  await w.find('form[data-test="signup-code"]').trigger('submit')
  await flushPromises()
}

describe('the public workspace signup page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    Object.values(api).forEach((f) => f.mockReset())
    session.login.mockReset().mockResolvedValue(null)
    session.isAuthenticated = false
    tenant.select.mockReset()
    router.replace.mockReset()
    route.query = { plan: 'pro' }
    api.beginWorkspaceSignup.mockResolvedValue('intent000001')
  })

  it('sends a visitor who is already signed in straight to billing for the plan', async () => {
    session.isAuthenticated = true
    mountPage()
    await flushPromises()

    expect(router.replace).toHaveBeenCalledWith('/billing?plan=pro')
  })

  it('creates the workspace, signs the new owner in and opens checkout for the plan', async () => {
    api.verifyWorkspaceSignup.mockResolvedValue({
      status: 'active',
      tenant_uuid: 't1',
      user_uuid: 'u1',
    })
    const w = mountPage()
    await flushPromises()

    await submitDetails(w)
    expect(api.beginWorkspaceSignup).toHaveBeenCalledWith(
      expect.objectContaining({ slug: 'acme-studio', username: 'ada', email: 'ada@acme.test' }),
    )
    expect(w.find('form[data-test="signup-code"]').exists()).toBe(true)

    await submitCode(w)
    expect(api.verifyWorkspaceSignup).toHaveBeenCalledWith('intent000001', '123456')
    expect(session.login).toHaveBeenCalledWith('ada@acme.test', 'correct-horse-battery')
    expect(tenant.select).toHaveBeenCalledWith('t1')
    expect(router.replace).toHaveBeenCalledWith('/billing?plan=pro')
  })

  it('tells someone whose email already has an account to sign in instead', async () => {
    api.verifyWorkspaceSignup.mockResolvedValue({
      status: 'consumed',
      outcome: 'existing_account_handoff',
    })
    const w = mountPage()
    await flushPromises()
    await submitDetails(w)
    await submitCode(w)

    expect(w.find('[data-test="signup-existing-account"]').exists()).toBe(true)
    expect(session.login).not.toHaveBeenCalled()
  })

  it('lets a taken workspace address be changed and finishes the signup', async () => {
    api.verifyWorkspaceSignup.mockResolvedValue({
      status: 'conflict',
      code: 'SLUG_CONFLICT',
      continuation_token: 'tok1',
      errors: { slug: 'Choose another slug.' },
    })
    api.continueWorkspaceSignup
      .mockResolvedValueOnce({ status: 'updated', continuation_token: 'tok2' })
      .mockResolvedValueOnce({ status: 'active', tenant_uuid: 't2', user_uuid: 'u2' })
    const w = mountPage()
    await flushPromises()
    await submitDetails(w)
    await submitCode(w)

    expect(w.find('form[data-test="signup-conflict"]').exists()).toBe(true)
    await fill(w, 'conflict_value', 'acme-studio-2')
    await w.find('form[data-test="signup-conflict"]').trigger('submit')
    await flushPromises()

    expect(api.continueWorkspaceSignup).toHaveBeenNthCalledWith(
      1,
      'intent000001',
      'tok1',
      'change_slug',
      {
        slug: 'acme-studio-2',
        name: 'Acme Studio',
      },
    )
    expect(api.continueWorkspaceSignup).toHaveBeenNthCalledWith(2, 'intent000001', 'tok2', 'resume')
    expect(tenant.select).toHaveBeenCalledWith('t2')
    expect(router.replace).toHaveBeenCalledWith('/billing?plan=pro')
  })

  it('opens the admin home when no plan was chosen', async () => {
    route.query = {}
    api.verifyWorkspaceSignup.mockResolvedValue({
      status: 'active',
      tenant_uuid: 't1',
      user_uuid: 'u1',
    })
    const w = mountPage()
    await flushPromises()
    await submitDetails(w)
    await submitCode(w)

    expect(router.replace).toHaveBeenCalledWith('/')
  })
})
