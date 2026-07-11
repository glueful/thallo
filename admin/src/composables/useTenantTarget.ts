import { useRouter } from 'vue-router'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import { useTenantStore } from '@/stores/tenant'

export function useTenantTarget() {
  const router = useRouter()
  const tenant = useTenantStore()
  const access = useTenancyAccessStore()

  async function ensureTargetSelected(uuid: string): Promise<boolean> {
    await tenant.ensureLoaded()
    if (!tenant.tenants.some((candidate) => candidate.uuid === uuid)) return false
    if (tenant.selectedUuid !== uuid) tenant.select(uuid)
    await access.refresh()
    return tenant.selectedUuid === uuid
  }

  async function selectThenNavigate(uuid: string, section: 'domains' | 'members'): Promise<void> {
    if (!(await ensureTargetSelected(uuid))) return
    await router.push(`/tenants/${uuid}/${section}`)
  }

  return { ensureTargetSelected, selectThenNavigate }
}
