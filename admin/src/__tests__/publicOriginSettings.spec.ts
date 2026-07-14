import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'

const { fetchPublicOrigin, savePublicOrigin } = vi.hoisted(() => ({
  fetchPublicOrigin: vi.fn(),
  savePublicOrigin: vi.fn(),
}))
vi.mock('@/queries/publicOrigin', () => ({ fetchPublicOrigin, savePublicOrigin }))

import PublicOriginSettings from '@/components/tenancy/PublicOriginSettings.vue'

interface StatusOverrides {
  step?: string
  origin_restart_required?: boolean
  base_domain?: string | null
  default_hosts?: string[]
}

function status(overrides: StatusOverrides = {}) {
  return {
    base_domain: 'app.example',
    default_hosts: ['app.example'],
    applied_base_domain: 'app.example',
    applied_default_hosts: ['app.example'],
    base_domain_source: 'flag',
    default_hosts_source: 'flag',
    step: 'inactive',
    origin_restart_required: false,
    ...overrides,
  }
}

const stubs = {
  UFormField: { template: '<div><slot /></div>' },
  UAlert: { template: '<div />' },
  USkeleton: { template: '<div />' },
  UInput: {
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template:
      '<input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  },
  UTextarea: {
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template:
      '<textarea :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  },
  UButton: {
    props: ['disabled', 'loading'],
    emits: ['click'],
    template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
  },
}

async function render(overrides: StatusOverrides = {}) {
  fetchPublicOrigin.mockResolvedValue(status(overrides))
  const wrapper = mount(PublicOriginSettings, { global: { stubs } })
  await flushPromises()
  return wrapper
}

describe('PublicOriginSettings', () => {
  beforeEach(() => {
    fetchPublicOrigin.mockReset()
    savePublicOrigin.mockReset()
  })

  it('freezes the form and disables save while resolution is activating', async () => {
    const wrapper = await render({ step: 'mapping_hosts' })
    expect(wrapper.find('[data-testid="public-origin-frozen"]').exists()).toBe(true)
    expect(
      wrapper.get('[data-testid="public-origin-save"]').attributes('disabled'),
    ).toBeDefined()
    expect(wrapper.get('[data-testid="public-origin-base-domain"]').attributes('disabled')).toBeDefined()
  })

  it('shows the restart note when a restart is required', async () => {
    const wrapper = await render({ origin_restart_required: true })
    expect(wrapper.find('[data-testid="public-origin-restart-note"]').exists()).toBe(true)
  })

  it('enables save only once the form is dirty', async () => {
    const wrapper = await render()
    // Pristine: not dirty -> save disabled.
    expect(wrapper.get('[data-testid="public-origin-save"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="public-origin-base-domain"]').setValue('changed.example')
    expect(wrapper.get('[data-testid="public-origin-save"]').attributes('disabled')).toBeUndefined()
  })

  it('prefills the inputs with the current host when nothing is persisted yet', async () => {
    // jsdom serves the page from http://localhost, so window.location.hostname === 'localhost'.
    const wrapper = await render({ base_domain: null, default_hosts: [] })
    const base = wrapper.get('[data-testid="public-origin-base-domain"]').element as HTMLInputElement
    const hosts = wrapper.get('[data-testid="public-origin-hosts"]')
      .element as HTMLTextAreaElement
    expect(base.value).toBe('localhost')
    expect(hosts.value).toBe('localhost')
  })

  it('saves the desired values via the query', async () => {
    savePublicOrigin.mockResolvedValue(status({ origin_restart_required: true }))
    const wrapper = await render()
    await wrapper.get('[data-testid="public-origin-base-domain"]').setValue('changed.example')
    await wrapper.get('[data-testid="public-origin-save"]').trigger('click')
    await flushPromises()
    expect(savePublicOrigin).toHaveBeenCalledWith({
      base_domain: 'changed.example',
      default_hosts: ['app.example'],
    })
  })
})
