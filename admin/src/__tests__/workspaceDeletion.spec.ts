import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TenantPurgeModal from '@/components/tenancy/TenantPurgeModal.vue'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn().mockResolvedValue({ data: {} }) }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { deleteWorkspace, purgeWorkspace, restoreWorkspace } from '@/queries/tenants'

describe('workspace deletion', () => {
  it('requires the exact workspace slug before permanent purge', async () => {
    const wrapper = mount(TenantPurgeModal, {
      props: {
        open: true,
        workspace: { uuid: 'tenant000001', slug: 'acme', name: 'Acme', status: 'deleted' },
      },
      global: {
        stubs: {
          Modal: { template: '<div><slot name="body"/><slot name="footer"/></div>' },
          FormField: { template: '<label><slot/></label>' },
          Input: {
            props: ['modelValue'],
            emits: ['update:modelValue'],
            template:
              '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
          },
          Button: {
            props: ['disabled'],
            template: '<button :disabled="disabled"><slot/></button>',
          },
        },
      },
    })

    const input = wrapper.get('input')
    const confirm = () => wrapper.get('button[data-testid="purge-confirm"]')
    expect(confirm().attributes('disabled')).toBeDefined()
    await input.setValue('wrong')
    expect(confirm().attributes('disabled')).toBeDefined()
    await input.setValue('acme')
    expect(confirm().attributes('disabled')).toBeUndefined()
  })

  it('calls the exact lifecycle endpoints and confirmation bodies', async () => {
    authFetch.mockClear()
    await deleteWorkspace('tenant000001')
    await restoreWorkspace('tenant000001')
    await purgeWorkspace({ uuid: 'tenant000001', confirm: 'acme' })

    expect(authFetch).toHaveBeenNthCalledWith(1, '/v1/admin/tenancy/tenants/tenant000001', {
      method: 'DELETE',
      body: JSON.stringify({ confirm: true }),
    })
    expect(authFetch).toHaveBeenNthCalledWith(2, '/v1/admin/tenancy/tenants/tenant000001/restore', {
      method: 'POST',
      body: '{}',
    })
    expect(authFetch).toHaveBeenNthCalledWith(3, '/v1/admin/tenancy/tenants/tenant000001/purge', {
      method: 'POST',
      body: JSON.stringify({ confirm: 'acme' }),
    })
  })
})
