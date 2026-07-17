import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createWorkspaceRole,
  deleteWorkspaceRole,
  fetchWorkspaceRoles,
  previewRoleOverrides,
  saveRoleOverrides,
  updateWorkspaceRole,
} from '@/queries/tenantRoles'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

describe('workspace role queries', () => {
  beforeEach(() => authFetch.mockReset())

  it('loads roles and sends aggregate override payloads', async () => {
    authFetch.mockResolvedValueOnce({ data: { roles: [], catalog: {} } })
    await expect(fetchWorkspaceRoles()).resolves.toEqual({ roles: [], catalog: {} })

    authFetch.mockResolvedValueOnce({})
    await saveRoleOverrides('member', ['content.publish'], ['content.delete'])
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/tenancy/roles/member/overrides', {
      method: 'PUT',
      body: JSON.stringify({ grants: ['content.publish'], revokes: ['content.delete'] }),
    })
  })

  it('previews and drives the custom-role lifecycle', async () => {
    authFetch.mockResolvedValueOnce({
      data: { preview: { before: [], after: [], added: [], removed: [] } },
    })
    await previewRoleOverrides('reviewer', ['content.view'], [])
    expect(authFetch).toHaveBeenLastCalledWith(
      '/v1/admin/tenancy/roles/preview',
      expect.any(Object),
    )

    authFetch.mockResolvedValue({})
    await createWorkspaceRole('reviewer', 'Reviewer')
    await updateWorkspaceRole('reviewer', { status: 'disabled' })

    // Built-in disable carries the optional replacement fields the backend guards on:
    // reassign_to when members hold the role, signup_role when member signup assigns it.
    await updateWorkspaceRole('member', {
      status: 'disabled',
      reassign_to: 'viewer',
      signup_role: 'viewer',
    })
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/tenancy/roles/member', {
      method: 'PATCH',
      body: JSON.stringify({ status: 'disabled', reassign_to: 'viewer', signup_role: 'viewer' }),
    })

    await deleteWorkspaceRole('reviewer', 'viewer')
    expect(authFetch).toHaveBeenLastCalledWith(
      '/v1/admin/tenancy/roles/reviewer?reassign_to=viewer',
      {
        method: 'DELETE',
      },
    )
  })

  it('uses the single-store role surface for signup roles', async () => {
    authFetch.mockResolvedValueOnce({ data: { roles: [], catalog: {} } })
    await fetchWorkspaceRoles(true)
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/settings/signup/roles')

    authFetch.mockResolvedValueOnce({})
    await createWorkspaceRole('subscriber', 'Subscriber', true)
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/settings/signup/roles', {
      method: 'POST',
      body: JSON.stringify({ slug: 'subscriber', name: 'Subscriber' }),
    })
  })
})
