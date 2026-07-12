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

export async function fetchWorkspaceRoles(): Promise<RolesPayload> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/roles`)
  return (json.data ?? json) as RolesPayload
}

export async function saveRoleOverrides(
  slug: string,
  grants: string[],
  revokes: string[],
): Promise<void> {
  await authFetch(`${runtimeConfig.apiBase}/tenancy/roles/${encodeURIComponent(slug)}/overrides`, {
    method: 'PUT',
    body: JSON.stringify({ grants, revokes }),
  })
}

export async function previewRoleOverrides(slug: string, grants: string[], revokes: string[]) {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/roles/preview`, {
    method: 'POST',
    body: JSON.stringify({ role_slug: slug, grants, revokes }),
  })
  return (json.data ?? json) as {
    preview: { before: string[]; after: string[]; added: string[]; removed: string[] }
  }
}

export async function createWorkspaceRole(slug: string, name: string): Promise<void> {
  await authFetch(`${runtimeConfig.apiBase}/tenancy/roles`, {
    method: 'POST',
    body: JSON.stringify({ slug, name }),
  })
}

export async function updateWorkspaceRole(
  slug: string,
  change: { name: string } | { status: 'active' | 'disabled' },
): Promise<void> {
  await authFetch(`${runtimeConfig.apiBase}/tenancy/roles/${encodeURIComponent(slug)}`, {
    method: 'PATCH',
    body: JSON.stringify(change),
  })
}

export async function deleteWorkspaceRole(slug: string, reassignTo?: string): Promise<void> {
  const query = reassignTo ? `?reassign_to=${encodeURIComponent(reassignTo)}` : ''
  await authFetch(`${runtimeConfig.apiBase}/tenancy/roles/${encodeURIComponent(slug)}${query}`, {
    method: 'DELETE',
  })
}
