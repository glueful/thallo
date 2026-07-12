import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export interface CapabilityDefinition {
  label: string
  group: string
  platform_only: boolean
}

export interface WorkspaceRole {
  slug: string
  name: string
  builtin: boolean
  status: 'active' | 'disabled'
  baseline: string[]
  grants: string[]
  revokes: string[]
  effective: string[]
  drift: string[]
}

export interface RolesPayload {
  roles: WorkspaceRole[]
  catalog: Record<string, CapabilityDefinition>
}

function rolesBase(singleStore = false): string {
  return singleStore
    ? `${runtimeConfig.apiBase}/settings/signup/roles`
    : `${runtimeConfig.apiBase}/tenancy/roles`
}

export async function fetchWorkspaceRoles(singleStore = false): Promise<RolesPayload> {
  const json = await authFetch(rolesBase(singleStore))
  return (json.data ?? json) as RolesPayload
}

export async function saveRoleOverrides(
  slug: string,
  grants: string[],
  revokes: string[],
  singleStore = false,
): Promise<void> {
  await authFetch(`${rolesBase(singleStore)}/${encodeURIComponent(slug)}/overrides`, {
    method: 'PUT',
    body: JSON.stringify({ grants, revokes }),
  })
}

export async function previewRoleOverrides(
  slug: string,
  grants: string[],
  revokes: string[],
  singleStore = false,
) {
  const json = await authFetch(`${rolesBase(singleStore)}/preview`, {
    method: 'POST',
    body: JSON.stringify({ role_slug: slug, grants, revokes }),
  })
  return (json.data ?? json) as {
    preview: { before: string[]; after: string[]; added: string[]; removed: string[] }
  }
}

export async function createWorkspaceRole(
  slug: string,
  name: string,
  singleStore = false,
): Promise<void> {
  await authFetch(rolesBase(singleStore), {
    method: 'POST',
    body: JSON.stringify({ slug, name }),
  })
}

export async function updateWorkspaceRole(
  slug: string,
  change: { name: string } | { status: 'active' | 'disabled' },
  singleStore = false,
): Promise<void> {
  await authFetch(`${rolesBase(singleStore)}/${encodeURIComponent(slug)}`, {
    method: 'PATCH',
    body: JSON.stringify(change),
  })
}

export async function deleteWorkspaceRole(
  slug: string,
  reassignTo?: string,
  singleStore = false,
): Promise<void> {
  const query = reassignTo ? `?reassign_to=${encodeURIComponent(reassignTo)}` : ''
  await authFetch(`${rolesBase(singleStore)}/${encodeURIComponent(slug)}${query}`, {
    method: 'DELETE',
  })
}
