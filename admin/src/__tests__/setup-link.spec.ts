import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import SetupPage from '@/pages/setup.vue'

// Provision prints /admin/setup?st=<SETUP_TOKEN>. The page reads `st` once, drops it from the
// address bar so it never sits in history or a referrer, and sends it as X-Setup-Token — the
// header the unauthenticated POST /admin/setup requires in production.

vi.mock('@/runtime/config', () => ({ runtimeConfig: { installed: false, defaultLocale: 'en' } }))
// The test runner serves from '/', so the admin's own address is given: what matters is that the
// page sends IT, and not window.location.origin.
vi.mock('@/runtime/adminAddress', () => ({
  runningAdminAddress: () => 'https://example.com/admin',
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ error: vi.fn(), success: vi.fn() }),
}))

const fetchMock = vi.fn()

async function mountAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/setup', component: SetupPage },
      { path: '/login', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  const wrapper = mount(SetupPage, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

async function fillAndSubmit(wrapper: ReturnType<typeof mount>) {
  await wrapper.find('input[placeholder="My Site"]').setValue('My Site')
  await wrapper.find('input[type="email"]').setValue('admin@example.com')
  await wrapper.find('input[type="password"]').setValue('Str0ng!Pass')
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  fetchMock.mockReset()
  fetchMock.mockResolvedValue({ status: 200, json: async () => ({ success: true }) })
  vi.stubGlobal('fetch', fetchMock)
})
afterEach(() => vi.unstubAllGlobals())

describe('setup page — setup link token', () => {
  it('reads ?st= from the link, strips it from the URL, and sends it as X-Setup-Token', async () => {
    const { wrapper, router } = await mountAt('/setup?st=abc123')

    expect(router.currentRoute.value.query.st).toBeUndefined()

    await fillAndSubmit(wrapper)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect((init.headers as Record<string, string>)['X-Setup-Token']).toBe('abc123')
  })

  it('tells the server where this admin is: its full address, not the site’s origin', async () => {
    // The origin alone is the SITE. Saved as the admin's address it sent the preview bar's Edit
    // and Design links to a 404 on every site installed from this screen.
    const { wrapper } = await mountAt('/setup')
    await fillAndSubmit(wrapper)
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const sent = JSON.parse(String(init.body)) as { admin_url: string }
    expect(sent.admin_url).toBe('https://example.com/admin')
  })

  it('sends no token header when the link carries none (local zero-config setup)', async () => {
    const { wrapper } = await mountAt('/setup')

    await fillAndSubmit(wrapper)

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect((init.headers as Record<string, string>)['X-Setup-Token']).toBeUndefined()
  })
})
