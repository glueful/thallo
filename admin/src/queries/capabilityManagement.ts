import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { useCapabilitiesStore } from '@/stores/capabilities'

// Operator capability switchboard (Thallo\Core\Http\Controllers\CapabilityAdminController::manage/update,
// under /v1/admin/capabilities). Requires system.access — ordinary workspace admins keep only the
// auth-only discovery feed (useCapabilitiesStore). A flip lands on the NEXT request (the server
// registry memoizes per boot), so mutations converge the capabilities store instead of assuming
// the response is instantly authoritative for the whole app.

import type { ActivationRecord } from '@/queries/capabilityActivation'

/** How Extensions › Capabilities switches a capability. */
export type CapabilityManagementKind = 'activation' | 'workspaces' | 'simple'

export interface ManagedCapability {
  id: string
  label?: string | null
  description?: string | null
  requires: string[]
  owning_package: string | null
  requested: boolean
  available: boolean
  reason: string | null
  remedy: string | null
  effective: boolean
  management: CapabilityManagementKind
  /** The open or last activation (activation features only); null before the first. */
  activation: ActivationRecord | null
  /** Whether the engine step could write application files here (activation features only). */
  application_files_writable: boolean | null
  /** Whether the engine's provider is loaded in this boot (activation features only). */
  engine_enabled: boolean | null
}

const base = () => `${runtimeConfig.apiBase}/capabilities`

export async function fetchCapabilityManagement(): Promise<ManagedCapability[]> {
  const json = await authFetch(`${base()}/manage`)
  const data = (json.data ?? json) as Record<string, unknown>
  return Array.isArray(data.capabilities) ? (data.capabilities as ManagedCapability[]) : []
}

export function useCapabilityManagement() {
  return useQuery({
    key: () => ['capabilities', 'manage'],
    query: fetchCapabilityManagement,
  })
}

export async function setCapabilityState(id: string, enabled: boolean): Promise<void> {
  await authFetch(`${base()}/${encodeURIComponent(id)}`, {
    method: 'PUT',
    body: JSON.stringify({ enabled }),
  })
}

export function useCapabilityStateMutations() {
  const cache = useQueryCache()
  const caps = useCapabilitiesStore()
  const converge = () => {
    void cache.invalidateQueries({ key: ['capabilities'] })
    // The effective set feeds the gated nav/panels and only changes on the next boot —
    // poll until it actually moves (same convergence idiom as the extension toggles).
    void caps.refreshUntilChanged()
  }

  const setState = useMutation({
    mutation: ({ id, enabled }: { id: string; enabled: boolean }) =>
      setCapabilityState(id, enabled),
    onSettled: converge,
  })

  return { setState }
}

/** Why the enable switch would be refused, or null when the flip is allowed. */
export function enableBlockedReason(cap: ManagedCapability): string | null {
  if (cap.available) return null
  const remedy = cap.remedy ? ` ${cap.remedy}` : ''
  return `${cap.reason ?? 'The owning engine cannot back this capability.'}${remedy}`.trim()
}
