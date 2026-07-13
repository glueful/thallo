import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { ref } from 'vue'

const selectedUuid = ref<string | null>('tenant000001')
const replace = vi.fn()
// Route path drives singleStoreMode (/settings/signup/roles) vs workspace mode; set per test.
const routePath = ref('/workspaces/tenant000001/roles')

vi.mock('vue-router', () => ({
  useRoute: () => ({
    params: { uuid: 'tenant000001' },
    get path() {
      return routePath.value
    },
  }),
  useRouter: () => ({ replace }),
}))
vi.mock('@/composables/useTenantTarget', () => ({
  useTenantTarget: () => ({ ensureTargetSelected: vi.fn().mockResolvedValue(true), selectedUuid }),
}))
vi.mock('@/stores/tenancyAccess', () => ({
  useTenancyAccessStore: () => ({ access: { manage_roles: true } }),
}))
vi.mock('@/queries/tenantRoles', () => ({
  fetchWorkspaceRoles: vi.fn().mockResolvedValue({ roles: [], catalog: {} }),
  saveRoleOverrides: vi.fn(),
  previewRoleOverrides: vi.fn(),
  createWorkspaceRole: vi.fn(),
  updateWorkspaceRole: vi.fn(),
  deleteWorkspaceRole: vi.fn(),
}))

import WorkspaceRolesPage from '@/pages/workspaces/[uuid]/roles.vue'

const mountPage = () => shallowMount(WorkspaceRolesPage)

describe('workspace roles — workspace switch', () => {
  beforeEach(() => {
    replace.mockClear()
    selectedUuid.value = 'tenant000001'
    routePath.value = '/workspaces/tenant000001/roles'
  })

  it('follows the switcher: replaces the URL so the roles list reloads for the new workspace', async () => {
    const wrapper = mountPage()
    await flushPromises()

    selectedUuid.value = 'tenant000002'
    await flushPromises()

    expect(replace).toHaveBeenCalledWith('/workspaces/tenant000002/roles')
    wrapper.unmount()
  })

  it('never navigates in signup-roles (single-store) mode, which has no workspace', async () => {
    routePath.value = '/settings/signup/roles'
    const wrapper = mountPage()
    await flushPromises()

    selectedUuid.value = 'tenant000002'
    await flushPromises()

    expect(replace).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
