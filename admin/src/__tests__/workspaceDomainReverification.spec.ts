import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { TenantDomain } from '@/queries/tenantDomains'

const domains = ref<TenantDomain[]>([])
const reverify = vi.fn().mockResolvedValue(undefined)

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { uuid: 'tenant000001' } }),
}))
vi.mock('@/composables/useTenantTarget', () => ({
  useTenantTarget: () => ({ ensureTargetSelected: vi.fn().mockResolvedValue(true) }),
}))
vi.mock('@/stores/tenancyAccess', () => ({
  useTenancyAccessStore: () => ({ access: { manage_domains: true } }),
}))
vi.mock('@/queries/tenantDomains', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/tenantDomains')>()
  return {
    ...actual,
    useTenantDomains: () => ({ data: domains, status: ref('success') }),
    useTenantDomainMutations: () => ({
      add: { mutateAsync: vi.fn(), isLoading: ref(false) },
      verify: { mutateAsync: vi.fn(), isLoading: ref(false) },
      reverify: { mutateAsync: reverify, isLoading: ref(false) },
      enable: { mutateAsync: vi.fn(), isLoading: ref(false) },
      disable: { mutateAsync: vi.fn(), isLoading: ref(false) },
      remove: { mutateAsync: vi.fn(), isLoading: ref(false) },
    }),
  }
})

import WorkspaceDomainsPage from '@/pages/workspaces/[uuid]/domains.vue'

const mountPage = () =>
  mount(WorkspaceDomainsPage, {
    global: {
      stubs: {
        UDashboardPanel: { template: '<div><slot name="header"/><slot name="body"/></div>' },
        UDashboardNavbar: { template: '<header />' },
        UEmpty: { template: '<div />' },
        DomainAddForm: { template: '<div />' },
        DomainVerifyInstructions: { template: '<div />' },
        USkeleton: { template: '<div />' },
        UBadge: { template: '<span><slot /></span>' },
        UButton: {
          props: ['loading'],
          emits: ['click'],
          template: '<button type="button" @click="$emit(\'click\')"><slot /></button>',
        },
      },
    },
  })

const domain = (status: string): TenantDomain => ({
  uuid: 'domain000001',
  host: 'www.example.test',
  verification_status: status,
  status: 'active',
  last_checked_at: '2026-07-11 10:00:00',
  last_check_status: 'mismatch',
  consecutive_failures: 3,
})

describe('workspace domain re-verification', () => {
  beforeEach(() => reverify.mockClear())

  it('keeps initial verification exclusive to pending domains', async () => {
    domains.value = [domain('pending')]
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="domain-verify-domain000001"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="domain-reverify-domain000001"]').exists()).toBe(false)
  })

  it('shows revoked state and runs the re-verification mutation', async () => {
    domains.value = [domain('revoked')]
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Not resolving')
    expect(wrapper.text()).toContain('3 consecutive failures')
    await wrapper.get('[data-testid="domain-reverify-domain000001"]').trigger('click')
    expect(reverify).toHaveBeenCalledWith('domain000001')
  })
})
