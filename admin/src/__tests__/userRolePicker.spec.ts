import { beforeEach, describe, expect, it, vi } from 'vitest'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { fetchAssignableRoles } from '@/queries/users'

describe('assignable user roles', () => {
  beforeEach(() => authFetch.mockReset())

  it('uses create mode without a target', async () => {
    authFetch.mockResolvedValueOnce({
      data: { roles: [{ slug: 'editor', name: 'Editor', assignable: true }] },
    })

    expect(await fetchAssignableRoles()).toEqual([
      { slug: 'editor', name: 'Editor', assignable: true },
    ])
    expect(authFetch).toHaveBeenCalledWith('/v1/admin/users/assignable-roles')
  })

  it('requests target-aware rows so protected assignments can stay locked', async () => {
    authFetch.mockResolvedValueOnce({
      data: {
        roles: [
          {
            slug: 'superuser',
            name: 'Superuser',
            assigned: true,
            assignable: false,
            removable: false,
          },
        ],
      },
    })

    const roles = await fetchAssignableRoles('user/with space')
    expect(roles[0]).toMatchObject({ slug: 'superuser', assigned: true, removable: false })
    expect(authFetch).toHaveBeenCalledWith(
      '/v1/admin/users/assignable-roles?target_uuid=user%2Fwith+space',
    )
  })

  it('treats a malformed role envelope as an empty catalog', async () => {
    authFetch.mockResolvedValueOnce({ data: {} })
    await expect(fetchAssignableRoles()).resolves.toEqual([])
  })
})
