import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import DomainVerifyInstructions from '@/components/tenancy/DomainVerifyInstructions.vue'
import EnablementPanel from '@/components/tenancy/EnablementPanel.vue'
import { TENANT_ROLES } from '@/queries/tenantMembers'

const UButton = { template: '<button><slot /></button>' }
const UBadge = { template: '<span><slot /></span>' }
const UProgress = { template: '<div />' }
const FirstTenantConfirmForm = { template: '<div data-testid="first-tenant-confirm" />' }

describe('tenancy components', () => {
  it('renders both DNS TXT coordinates', () => {
    const wrapper = mount(DomainVerifyInstructions, {
      props: { name: '_thallo-verify.example.com', value: 'token123' },
      global: { stubs: { UButton } },
    })
    expect(wrapper.text()).toContain('_thallo-verify.example.com')
    expect(wrapper.text()).toContain('token123')
  })

  it('offers exactly the ratified membership roles', () => {
    expect(TENANT_ROLES).toEqual(['owner', 'admin', 'member', 'viewer'])
  })

  it('uses pending tenant data to resume retrofitting', async () => {
    const wrapper = mount(EnablementPanel, {
      props: {
        status: {
          step: 'retrofitting',
          enabled: false,
          schema_state: 'none',
          progress: 75,
          reloading: false,
          mode: 'bootstrap_default',
          pending_slug: 'default',
          pending_name: 'Default',
          failure: null,
          cli_fallback: null,
        },
      },
      global: { stubs: { UButton, UBadge, UProgress, FirstTenantConfirmForm } },
    })
    await wrapper.find('[data-testid="enablement-action-confirm"]').trigger('click')
    expect(wrapper.emitted('confirm')?.[0]).toEqual([{ slug: 'default', name: 'Default' }])
  })

  it('falls back to the confirm form when retry data is missing', () => {
    const wrapper = mount(EnablementPanel, {
      props: {
        status: {
          step: 'retrofitting',
          enabled: false,
          schema_state: 'none',
          progress: 75,
          reloading: false,
          mode: 'bootstrap_default',
          pending_slug: null,
          pending_name: null,
          failure: null,
          cli_fallback: null,
        },
      },
      global: { stubs: { UButton, UBadge, UProgress, FirstTenantConfirmForm } },
    })
    expect(wrapper.find('[data-testid="first-tenant-confirm"]').exists()).toBe(true)
  })
})
