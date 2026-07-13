import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import { useTenantStore } from '@/stores/tenant'

export function useTenantTarget() {
  const router = useRouter()
  const tenant = useTenantStore()
  const access = useTenancyAccessStore()

  // The currently bound workspace. Workspace-scoped admin pages must call their tenant-scoped APIs
  // with THIS uuid (it equals the X-Tenant-Id header), not the route uuid — the backend rejects a
  // path/header mismatch with 403.
  const selectedUuid = computed(() => tenant.selectedUuid)

  async function ensureTargetSelected(uuid: string): Promise<boolean> {
    await tenant.ensureLoaded()
    if (!tenant.tenants.some((candidate) => candidate.uuid === uuid)) return false
    if (tenant.selectedUuid !== uuid) tenant.select(uuid)
    await access.refresh()
    return tenant.selectedUuid === uuid
  }

  async function selectThenNavigate(uuid: string, section: 'domains' | 'members'): Promise<void> {
    if (!(await ensureTargetSelected(uuid))) return
    await router.push(`/workspaces/${uuid}/${section}`)
  }

  return { ensureTargetSelected, selectThenNavigate, selectedUuid }
}
