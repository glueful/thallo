import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export interface PublicOriginStatus {
  base_domain: string | null
  default_hosts: string[]
  applied_base_domain: string | null
  applied_default_hosts: string[]
  base_domain_source: 'flag' | 'config' | 'unset'
  default_hosts_source: 'flag' | 'config' | 'unset'
  step: string
  origin_restart_required: boolean
}

function unwrap(json: any): PublicOriginStatus {
  return (json?.data?.public_origin ?? json?.public_origin) as PublicOriginStatus
}

const url = `${runtimeConfig.apiBase}/tenancy/public-origin`

export async function fetchPublicOrigin(): Promise<PublicOriginStatus> {
  return unwrap(await authFetch(url))
}

export async function savePublicOrigin(input: {
  base_domain: string | null
  default_hosts: string[]
}): Promise<PublicOriginStatus> {
  return unwrap(await authFetch(url, { method: 'PUT', body: JSON.stringify(input) }))
}
