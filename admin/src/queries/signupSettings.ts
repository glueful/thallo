import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export interface MemberSignupSettings {
  enabled: boolean
  role: string
  email_channel_available: boolean
  eligible_roles: string[]
}

export interface WorkspaceSignupSettings {
  enabled: boolean
  effective: boolean
  email_channel_available: boolean
}

export type MemberSignupScope = 'workspace' | 'single-store'

function memberBase(scope: MemberSignupScope): string {
  return scope === 'single-store'
    ? `${runtimeConfig.apiBase}/settings/signup`
    : `${runtimeConfig.apiBase}/tenancy/signup/members`
}

function settings<T>(json: unknown): T {
  const envelope = json as { data?: { settings?: T }; settings?: T }
  const value = envelope.data?.settings ?? envelope.settings
  if (!value) throw new Error('Malformed signup settings response.')
  return value
}

export async function fetchMemberSignupSettings(
  scope: MemberSignupScope = 'workspace',
): Promise<MemberSignupSettings> {
  return settings(await authFetch(memberBase(scope)))
}

export async function saveMemberSignupSettings(
  enabled: boolean,
  role: string,
  scope: MemberSignupScope = 'workspace',
): Promise<MemberSignupSettings> {
  return settings(
    await authFetch(memberBase(scope), {
      method: 'PUT',
      body: JSON.stringify({ enabled, role }),
    }),
  )
}

export async function fetchWorkspaceSignupSettings(): Promise<WorkspaceSignupSettings> {
  return settings(await authFetch(`${runtimeConfig.apiBase}/tenancy/signup/workspaces`))
}

export async function saveWorkspaceSignupSettings(
  enabled: boolean,
): Promise<WorkspaceSignupSettings> {
  return settings(
    await authFetch(`${runtimeConfig.apiBase}/tenancy/signup/workspaces`, {
      method: 'PUT',
      body: JSON.stringify({ enabled }),
    }),
  )
}
