import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export interface TenancyAccess {
  manage_platform: boolean
  access_any: boolean
  manage_members: boolean
  manage_domains: boolean
  manage_roles?: boolean
}

export async function fetchTenancyAccess(): Promise<TenancyAccess> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/access`)
  const data = (json.data ?? json) as { access?: Partial<TenancyAccess> }
  const access = data.access ?? {}
  return {
    manage_platform: access.manage_platform === true,
    access_any: access.access_any === true,
    manage_members: access.manage_members === true,
    manage_domains: access.manage_domains === true,
    manage_roles: access.manage_roles === true,
  }
}
