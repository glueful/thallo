import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TenantSwitcher from '@/components/TenantSwitcher.vue'

const { tenantStore } = vi.hoisted(() => {
  const store = {
    tenants: [] as Array<{ uuid: string; slug: string; name: string; status: string }>,
    selectedUuid: null as string | null,
    loaded: true,
    select: vi.fn<(uuid: string) => void>(),
  }
  store.select.mockImplementation((uuid) => {
    store.selectedUuid = uuid
  })
  return { tenantStore: store }
})
vi.mock('@/stores/tenant', () => ({ useTenantStore: () => tenantStore }))
vi.mock('@/queries/tenants', () => ({
  fetchMyTenants: vi.fn().mockResolvedValue([]),
}))

const USelectMenu = {
  props: ['modelValue', 'items'],
  emits: ['update:modelValue'],
  template: `<div><button v-for="item in items" :key="item.value"
    data-testid="tenant-option" @click="$emit('update:modelValue', item.value)">
    <slot name="item" :item="item" />
  </button></div>`,
}

describe('TenantSwitcher', () => {
  beforeEach(() => {
    tenantStore.tenants = []
    tenantStore.selectedUuid = null
    tenantStore.select.mockClear()
  })

  it('renders multiple tenant choices', async () => {
    tenantStore.tenants = [
      { uuid: 'tenant000001', slug: 'alpha', name: 'Alpha', status: 'active' },
      { uuid: 'tenant000002', slug: 'beta', name: 'Beta', status: 'active' },
    ]
    tenantStore.selectedUuid = 'tenant000001'
    const wrapper = mount(TenantSwitcher, {
      global: { stubs: { USelectMenu, 'u-select-menu': USelectMenu } },
    })

    expect(wrapper.find('[data-testid="tenant-switcher"]').exists()).toBe(true)
    expect(wrapper.html()).toContain('Alpha')
    await wrapper.find('[data-testid="tenant-switcher"]').trigger('click')
    await wrapper.vm.$nextTick()
    expect(document.body.textContent).toContain('Beta')
  })

  it('stays hidden for a single-tenant user', () => {
    tenantStore.tenants = [
      { uuid: 'tenant000001', slug: 'alpha', name: 'Alpha', status: 'active' },
    ]
    tenantStore.selectedUuid = 'tenant000001'
    const wrapper = mount(TenantSwitcher, {
      global: { stubs: { USelectMenu, 'u-select-menu': USelectMenu } },
    })

    expect(wrapper.find('[data-testid="tenant-switcher"]').exists()).toBe(false)
  })
})
