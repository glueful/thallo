import { beforeEach, describe, expect, it, vi } from 'vitest'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))

import {
  fetchMemberSignupSettings,
  fetchWorkspaceSignupSettings,
  saveMemberSignupSettings,
  saveWorkspaceSignupSettings,
} from '@/queries/signupSettings'

describe('signup settings queries', () => {
  beforeEach(() => authFetch.mockReset())

  it('loads and saves member policy through the workspace-scoped endpoint', async () => {
    const value = {
      enabled: true,
      role: 'viewer',
      email_channel_available: true,
      eligible_roles: ['viewer'],
    }
    authFetch.mockResolvedValue({ data: { settings: value } })
    await expect(fetchMemberSignupSettings()).resolves.toEqual(value)
    await expect(saveMemberSignupSettings(true, 'viewer')).resolves.toEqual(value)
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/tenancy/signup/members', {
      method: 'PUT',
      body: JSON.stringify({ enabled: true, role: 'viewer' }),
    })
  })

  it('uses the system endpoint for single-store member signup', async () => {
    const value = {
      enabled: false,
      role: 'member',
      email_channel_available: true,
      eligible_roles: ['member', 'viewer'],
    }
    authFetch.mockResolvedValue({ data: { settings: value } })
    await expect(fetchMemberSignupSettings('single-store')).resolves.toEqual(value)
    await expect(saveMemberSignupSettings(true, 'member', 'single-store')).resolves.toEqual(value)
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/settings/signup', {
      method: 'PUT',
      body: JSON.stringify({ enabled: true, role: 'member' }),
    })
  })

  it('loads and saves the platform workspace switch independently', async () => {
    const value = { enabled: false, effective: false, email_channel_available: true }
    authFetch.mockResolvedValue({ data: { settings: value } })
    await expect(fetchWorkspaceSignupSettings()).resolves.toEqual(value)
    await expect(saveWorkspaceSignupSettings(true)).resolves.toEqual(value)
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/tenancy/signup/workspaces', {
      method: 'PUT',
      body: JSON.stringify({ enabled: true }),
    })
  })
})
