import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TenantSwitcher from '@/components/TenantSwitcher.vue'

const { tenantStore } = vi.hoisted(() => {
  const store = {
    tenants: [] as Array<{ uuid: string; slug: string; name: string; status: string }>,
    selectedUuid: null as string | null,
    loaded: true,
    operatorMode: false,
    select: vi.fn<(uuid: string) => void>(),
    setOperatorMode: vi.fn<(enabled: boolean) => void>(),
  }
  store.select.mockImplementation((uuid) => {
    store.selectedUuid = uuid
  })
  return { tenantStore: store }
})
vi.mock('@/stores/tenant', () => ({ useTenantStore: () => tenantStore }))
const accessStore = { reset: vi.fn(), refresh: vi.fn().mockResolvedValue(undefined) }
vi.mock('@/stores/tenancyAccess', () => ({ useTenancyAccessStore: () => accessStore }))
vi.mock('@/queries/tenants', () => ({
  fetchMyTenants: vi.fn().mockResolvedValue([]),
}))

const USelectMenu = {
  name: 'USelectMenu',
  props: ['modelValue', 'items', 'open'],
  emits: ['update:modelValue', 'update:open'],
  template: `<div :data-open="String(open)"><button v-for="item in items" :key="item.value"
    data-testid="tenant-option" @click="$emit('update:modelValue', item.value)">
    <slot name="item" :item="item" />
  </button></div>`,
}

describe('TenantSwitcher', () => {
  beforeEach(() => {
    tenantStore.tenants = []
    tenantStore.selectedUuid = null
    tenantStore.select.mockClear()
    tenantStore.setOperatorMode.mockClear()
    accessStore.reset.mockClear()
    accessStore.refresh.mockClear()
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
    wrapper.unmount()
  })

  it('stays hidden for a single-tenant user', () => {
    tenantStore.tenants = [{ uuid: 'tenant000001', slug: 'alpha', name: 'Alpha', status: 'active' }]
    tenantStore.selectedUuid = 'tenant000001'
    const wrapper = mount(TenantSwitcher, {
      global: { stubs: { USelectMenu, 'u-select-menu': USelectMenu } },
    })

    expect(wrapper.find('[data-testid="tenant-switcher"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('opens the actual selector and refreshes access when switching is required', async () => {
    tenantStore.tenants = [
      { uuid: 'tenant000001', slug: 'alpha', name: 'Alpha', status: 'active' },
      { uuid: 'tenant000002', slug: 'beta', name: 'Beta', status: 'active' },
    ]
    const wrapper = mount(TenantSwitcher, {
      global: { stubs: { USelectMenu, 'u-select-menu': USelectMenu } },
    })

    window.dispatchEvent(new CustomEvent('tenant-switch-required'))
    await wrapper.vm.$nextTick()

    expect(tenantStore.setOperatorMode).toHaveBeenCalledWith(false)
    expect(accessStore.reset).toHaveBeenCalled()
    expect(accessStore.refresh).toHaveBeenCalled()
    expect(wrapper.find('[data-switcher-open="true"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
