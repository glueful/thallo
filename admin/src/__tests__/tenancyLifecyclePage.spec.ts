import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import EnablementPanel from '@/components/tenancy/EnablementPanel.vue'
import ResolutionPanel from '@/components/tenancy/ResolutionPanel.vue'
import type { EnablementStatus, EnablementStep } from '@/queries/tenancyEnablement'
import type { ResolutionStatus, ResolutionStep } from '@/queries/tenancyResolution'

const UButton = {
  inheritAttrs: false,
  emits: ['click'],
  template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
}
const UBadge = { template: '<span><slot /></span>' }
const UProgress = { template: '<div />' }
const FirstTenantConfirmForm = { template: '<div data-testid="first-tenant-confirm" />' }
const panelStubs = { UButton, UBadge, UProgress, FirstTenantConfirmForm }

function enablement(step: EnablementStep): EnablementStatus {
  return {
    step,
    enabled: step === 'on' || step === 'disabling',
    schema_state: 'none',
    progress: 0,
    reloading: step === 'reloading',
    mode: 'bootstrap_default',
    pending_slug: step === 'retrofitting' ? 'default' : null,
    pending_name: step === 'retrofitting' ? 'Default' : null,
    failure: step === 'failed' ? 'Migration failed.' : null,
    cli_fallback: null,
  }
}

function resolution(step: ResolutionStep): ResolutionStatus {
  return {
    step,
    mode: step === 'full' ? 'full_resolution' : 'bootstrap_default',
    failure: step === 'failed' ? 'Host verification failed.' : null,
    fresh_boot_required: step === 'awaiting_fresh_boot',
  }
}

describe('tenancy lifecycle action map', () => {
  it.each([
    ['off', 'enablement-action-begin'],
    ['awaiting_provider_boot', 'enablement-action-begin'],
    ['enabling_enforcement', 'enablement-action-begin'],
    ['reloading', 'enablement-reload-continue'],
    ['finalizing', 'enablement-reload-continue'],
    ['failed', 'enablement-action-retry'],
    ['on', 'enablement-action-disable'],
    ['disabled_widened', 'enablement-action-begin'],
  ] as const)('renders the prescribed enablement action at %s', (step, testId) => {
    const wrapper = mount(EnablementPanel, {
      props: { status: enablement(step) },
      global: { stubs: panelStubs },
    })

    expect(wrapper.find(`[data-testid="${testId}"]`).exists()).toBe(true)
    expect(wrapper.emitted('action')).toBeUndefined()
  })

  it('renders confirm UI without advancing an awaiting-confirm status read', () => {
    const wrapper = mount(EnablementPanel, {
      props: { status: enablement('awaiting_confirm') },
      global: { stubs: panelStubs },
    })

    expect(wrapper.find('[data-testid="first-tenant-confirm"]').exists()).toBe(true)
    expect(wrapper.emitted()).toEqual({})
  })

  it.each([
    ['inactive', 'resolution-action-activate'],
    ['awaiting_fresh_boot', 'resolution-reload-continue'],
    ['failed', 'resolution-action-activate'],
    ['full', 'resolution-action-deactivate'],
  ] as const)('renders the prescribed resolution action at %s', (step, testId) => {
    const wrapper = mount(ResolutionPanel, {
      props: { status: resolution(step) },
      global: { stubs: { UButton, UBadge } },
    })

    expect(wrapper.find(`[data-testid="${testId}"]`).exists()).toBe(true)
    expect(wrapper.emitted('activate')).toBeUndefined()
    expect(wrapper.emitted('deactivate')).toBeUndefined()
  })

  it('renders refusal text verbatim', () => {
    const wrapper = mount(EnablementPanel, {
      props: { status: enablement('on'), error: 'Deactivate full resolution before disabling.' },
      global: { stubs: panelStubs },
    })

    expect(wrapper.find('[data-testid="enablement-error"]').text()).toBe(
      'Deactivate full resolution before disabling.',
    )
  })
})
