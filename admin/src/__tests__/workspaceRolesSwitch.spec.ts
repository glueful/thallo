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
// Mutable access double: tests control WHEN ensureLoaded settles (the hard-refresh race)
// and what the flags are once it does.
const accessStore = {
  access: { manage_roles: true },
  ensureLoaded: vi.fn().mockResolvedValue(undefined),
}
vi.mock('@/stores/tenancyAccess', () => ({
  useTenancyAccessStore: () => accessStore,
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
import { fetchWorkspaceRoles } from '@/queries/tenantRoles'

const mountPage = () => shallowMount(WorkspaceRolesPage)

describe('workspace roles — workspace switch', () => {
  beforeEach(() => {
    replace.mockClear()
    selectedUuid.value = 'tenant000001'
    routePath.value = '/workspaces/tenant000001/roles'
    accessStore.access = { manage_roles: true }
    accessStore.ensureLoaded = vi.fn().mockResolvedValue(undefined)
    ;(fetchWorkspaceRoles as ReturnType<typeof vi.fn>).mockClear()
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

  // Regression (blank page on hard refresh): the access store starts EMPTY on a full
  // reload; the mount watcher previously read manage_roles at one instant — false while
  // the fetch was in flight — and never re-evaluated, so load() never ran until a
  // remount. The watcher must AWAIT the access answer before gating.
  it('loads once access resolves, even when the flags were empty at mount', async () => {
    accessStore.access = { manage_roles: false } // still fetching at mount time
    let settle!: () => void
    accessStore.ensureLoaded = vi.fn().mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          settle = () => {
            accessStore.access = { manage_roles: true } // the fetch lands
            resolve()
          }
        }),
    )

    const wrapper = mountPage()
    await flushPromises()
    expect(fetchWorkspaceRoles).not.toHaveBeenCalled() // still waiting, not skipped

    settle()
    await flushPromises()
    expect(fetchWorkspaceRoles).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('shows a denied message instead of a silent blank when access is genuinely absent', async () => {
    accessStore.access = { manage_roles: false }

    const wrapper = mountPage()
    await flushPromises()

    expect(fetchWorkspaceRoles).not.toHaveBeenCalled()
    expect((wrapper.vm as unknown as { error: string | null }).error).toContain('permission')
    wrapper.unmount()
  })
})
