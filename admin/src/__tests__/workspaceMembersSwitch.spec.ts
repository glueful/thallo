import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { TenantMember } from '@/queries/tenantMembers'

const members = ref<TenantMember[]>([])
// The active/bound workspace, driven by the sidebar switcher. Starts equal to the route uuid.
const selectedUuid = ref<string | null>('tenant000001')
const replace = vi.fn()

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { uuid: 'tenant000001' } }),
  useRouter: () => ({ replace }),
}))
vi.mock('@/composables/useTenantTarget', () => ({
  useTenantTarget: () => ({ ensureTargetSelected: vi.fn().mockResolvedValue(true), selectedUuid }),
}))
vi.mock('@/stores/tenancyAccess', () => ({
  useTenancyAccessStore: () => ({ access: { manage_members: true } }),
}))
vi.mock('@/queries/tenantMembers', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/tenantMembers')>()
  return {
    ...actual,
    fetchAssignableRoles: vi.fn().mockResolvedValue([]),
    useTenantMembers: () => ({ data: members, status: ref('success') }),
    useTenantMemberMutations: () => ({
      add: { mutateAsync: vi.fn(), isLoading: ref(false) },
      setRole: { mutateAsync: vi.fn(), isLoading: ref(false) },
      remove: { mutateAsync: vi.fn(), isLoading: ref(false) },
    }),
  }
})

import WorkspaceMembersPage from '@/pages/workspaces/[uuid]/members.vue'

const mountPage = () =>
  mount(WorkspaceMembersPage, {
    global: {
      stubs: {
        UDashboardPanel: { template: '<div><slot name="header"/><slot name="body"/></div>' },
        UDashboardNavbar: { template: '<header />' },
        UEmpty: { template: '<div />' },
        MemberAddForm: { template: '<div />' },
        RolePicker: { template: '<div />' },
        USkeleton: { template: '<div />' },
        UBadge: { template: '<span><slot /></span>' },
        UButton: { template: '<button type="button"><slot /></button>' },
      },
    },
  })

describe('workspace members — workspace switch', () => {
  beforeEach(() => {
    replace.mockClear()
    selectedUuid.value = 'tenant000001'
    members.value = []
  })

  it('follows the sidebar switcher: replaces the URL when the active workspace changes', async () => {
    const wrapper = mountPage()
    await flushPromises()

    selectedUuid.value = 'tenant000002'
    await flushPromises()

    expect(replace).toHaveBeenCalledWith('/workspaces/tenant000002/members')
    wrapper.unmount()
  })

  it('does not re-navigate when the active workspace matches the current route', async () => {
    const wrapper = mountPage()
    await flushPromises()

    selectedUuid.value = 'tenant000002'
    await flushPromises()
    replace.mockClear()

    selectedUuid.value = 'tenant000001'
    await flushPromises()

    expect(replace).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
