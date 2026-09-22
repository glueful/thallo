import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

const updateScopes = vi.fn()
vi.mock('@/queries/apiKeys', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useApiKeyMutations: () => ({
    rotate: { mutateAsync: vi.fn(), isLoading: ref(false) },
    revoke: { mutateAsync: vi.fn(), isLoading: ref(false) },
    updateTenant: { mutateAsync: vi.fn(), isLoading: ref(false) },
    updateScopes: { mutateAsync: updateScopes, isLoading: ref(false) },
  }),
}))
vi.mock('@/queries/tenants', () => ({ useAllTenants: () => ({ data: ref([]) }) }))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import ApiKeyDetailPane from '@/pages/developers/api-keys/components/ApiKeyDetailPane.vue'
import type { ApiKey } from '@/queries/apiKeys'

const key = (): ApiKey =>
  ({
    uuid: 'key000000001',
    name: 'Website',
    key_prefix: 'gf_live_ab',
    status: 'active',
    scopes: ['read:content:posts'],
    allowed_ips: [],
    tenant_uuid: null,
    tenant_name: null,
  }) as unknown as ApiKey

describe('editing an API key’s scopes', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    updateScopes.mockReset()
    updateScopes.mockResolvedValue({})
  })

  it('adds a scope, removes one, and saves the list', async () => {
    const wrapper = mount(ApiKeyDetailPane, { props: { item: key() } })

    await wrapper.find('[data-test="scopes-edit"]').trigger('click')
    const input = wrapper.find('input[data-test="scope-input"]')
    await input.setValue('read:content:pages')
    await input.trigger('keydown', { key: 'Enter' })
    await wrapper.find('[data-test="scope-chip-read:content:posts"]').trigger('click')
    await wrapper.find('[data-test="scopes-save"]').trigger('click')
    await flushPromises()

    expect(updateScopes).toHaveBeenCalledWith({
      uuid: 'key000000001',
      scopes: ['read:content:pages'],
    })
  })

  it('warns that an empty list means full access', async () => {
    const wrapper = mount(ApiKeyDetailPane, { props: { item: key() } })

    await wrapper.find('[data-test="scopes-edit"]').trigger('click')
    await wrapper.find('[data-test="scope-chip-read:content:posts"]').trigger('click')

    expect(wrapper.find('[data-test="scopes-full-access"]').exists()).toBe(true)
  })
})
