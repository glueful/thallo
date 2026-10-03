import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import { createRouter, createMemoryHistory } from 'vue-router'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { ManagedCapability } from '@/queries/capabilityManagement'

const rows = ref<ManagedCapability[] | undefined>(undefined)
const queryStatus = ref<'pending' | 'success' | 'error'>('success')
const setStateMock = vi.fn()
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/queries/capabilityManagement', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/capabilityManagement')>()),
  useCapabilityManagement: () => ({ data: rows, status: queryStatus }),
  useCapabilityStateMutations: () => ({
    setState: { mutateAsync: setStateMock, isLoading: ref(false) },
  }),
}))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ refreshUntilChanged: vi.fn() }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: notify.success, error: notify.error }),
}))

import ExtensionsPage from '@/pages/extensions/index.vue'

const cap = (over: Partial<ManagedCapability> = {}): ManagedCapability => ({
  id: 'thallo.render',
  label: 'Rendered delivery',
  description: null,
  requires: [],
  owning_package: null,
  requested: true,
  available: true,
  reason: null,
  remedy: null,
  effective: true,
  management: 'simple',
  activation: null,
  application_files_writable: null,
  engine_enabled: null,
  ...over,
})

function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:p(.*)*', component: { template: '<div />' } }],
  })
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(ExtensionsPage, {
    global: {
      plugins: [pinia, PiniaColada, router],
    },
  })
}

// A capability without an activation flow keeps a plain switch on the Extensions page.
describe('Extensions page: plain-switch capabilities', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    rows.value = undefined
    queryStatus.value = 'success'
    setStateMock.mockReset()
    notify.success.mockReset()
    notify.error.mockReset()
  })

  it('labels each state: effective on, requested-but-unavailable, and off', async () => {
    rows.value = [
      cap(),
      cap({
        id: 'thallo.search',
        label: 'Search',
        owning_package: 'glueful/meilisearch',
        requested: true,
        available: false,
        reason: 'glueful/meilisearch is installed but not enabled.',
        remedy: 'php glueful extensions:enable glueful/meilisearch',
        effective: false,
      }),
      cap({ id: 'thallo.workflow', label: 'Workflow', requested: false, effective: false }),
    ]
    const wrapper = mountPage()
    await flushPromises()

    const on = wrapper.find('[data-test="capability-thallo.render"]')
    expect(on.find('[data-test="state-badge"]').text()).toBe('On')

    const degraded = wrapper.find('[data-test="capability-thallo.search"]')
    expect(degraded.find('[data-test="state-badge"]').text()).toContain('engine unavailable')
    expect(degraded.find('[data-test="unavailable-reason"]').text()).toContain('not enabled')
    expect(degraded.find('[data-test="unavailable-reason"]').text()).toContain(
      'php glueful extensions:enable glueful/meilisearch',
    )

    const off = wrapper.find('[data-test="capability-thallo.workflow"]')
    expect(off.find('[data-test="state-badge"]').text()).toBe('Off')
  })

  it('refuses enabling an unavailable capability without a request', async () => {
    rows.value = [
      cap({
        id: 'thallo.search',
        label: 'Search',
        requested: false,
        available: false,
        reason: 'engine down',
        remedy: 'fix it',
        effective: false,
      }),
    ]
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="capability-thallo.search"] [role="switch"]').trigger('click')
    await flushPromises()

    expect(setStateMock).not.toHaveBeenCalled()
    expect(notify.error).toHaveBeenCalled()
    const [err, title] = notify.error.mock.calls[0] ?? []
    expect(String((err as Error).message)).toContain('engine down')
    expect(String(title)).toContain('Cannot enable')
  })

  it('always allows disabling, even while unavailable', async () => {
    rows.value = [
      cap({
        id: 'thallo.search',
        requested: true,
        available: false,
        reason: 'engine down',
        effective: false,
      }),
    ]
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="capability-thallo.search"] [role="switch"]').trigger('click')
    await flushPromises()

    expect(setStateMock).toHaveBeenCalledWith({ id: 'thallo.search', enabled: false })
    expect(notify.success).toHaveBeenCalled()
  })

  it('shows the operator-access empty state on a 403', async () => {
    queryStatus.value = 'error'
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Operator access required')
    expect(wrapper.text()).toContain('system.access')
    expect(wrapper.findAll('[role="switch"]')).toHaveLength(0)
  })
})
