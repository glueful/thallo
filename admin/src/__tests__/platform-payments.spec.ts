import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { PlatformPaymentsSettings } from '@/queries/platformPayments'

// Platform Payments settings (platform-payments-settings spec, Task 7): the app-owned,
// platform-only Settings → Payments page — replacing the retired Commerce Settings → Payments
// tab. Response contract is preserved byte-shape-identical to the retired
// `/commerce/payments` endpoint (see `admin/src/queries/platformPayments.ts`): `mode`, an ordered
// `gateways` list (`id`, `enabled{value,default,overridden}`, `secret_key{set,source}`,
// `webhook_secret{set,source}`, `default`, `webhook_url`), and `default_gateway{value,default,
// overridden}`.

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

const paymentsData = ref<PlatformPaymentsSettings | undefined>(undefined)
const paymentsStatus = ref<'pending' | 'error' | 'success'>('success')
const saveMock = vi.hoisted(() => vi.fn())
const saveLoading = ref(false)

vi.mock('@/queries/platformPayments', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/platformPayments')>()
  return {
    ...actual,
    usePlatformPaymentsSettings: () => ({ data: paymentsData, status: paymentsStatus }),
    useSavePlatformPaymentsSettings: () => ({ mutateAsync: saveMock, isLoading: saveLoading }),
  }
})

import PaymentsPage from '@/pages/settings/payments.vue'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'

function paymentsSettings(overrides: Partial<PlatformPaymentsSettings> = {}): PlatformPaymentsSettings {
  return {
    mode: overrides.mode ?? 'gateway',
    default_gateway: overrides.default_gateway ?? {
      value: 'paystack',
      default: 'paystack',
      overridden: false,
    },
    gateways: overrides.gateways ?? [
      {
        id: 'paystack',
        enabled: { value: true, default: true, overridden: false },
        secret_key: { set: false, source: null },
        webhook_secret: { set: false, source: null },
        default: true,
        webhook_url: 'https://thallo.example/webhooks/paystack',
      },
      {
        id: 'stripe',
        enabled: { value: false, default: false, overridden: false },
        secret_key: { set: false, source: null },
        webhook_secret: { set: false, source: null },
        default: false,
        webhook_url: 'https://thallo.example/webhooks/stripe',
      },
    ],
  }
}

function mountPage() {
  return mount(PaymentsPage)
}

describe('Settings → Payments page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useTenancyAccessStore().access.manage_platform = true
    paymentsData.value = paymentsSettings()
    paymentsStatus.value = 'success'
    saveMock.mockReset()
    saveMock.mockResolvedValue(paymentsSettings())
    saveLoading.value = false
    notify.success.mockClear()
    notify.error.mockClear()
  })

  // ── Page states (Step 1 matrix: loading / error / loaded) ────────────────────────────────────

  it('shows a loading state while the query is pending', async () => {
    paymentsStatus.value = 'pending'
    paymentsData.value = undefined
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="payments-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payments-panel"]').exists()).toBe(false)
  })

  it('shows an error state when the query fails', async () => {
    paymentsStatus.value = 'error'
    paymentsData.value = undefined
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="payments-error"]').exists()).toBe(true)
  })

  it('shows the manual-collection note when no gateway extension is installed', async () => {
    paymentsData.value = paymentsSettings({ mode: 'manual', gateways: [] })
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="payments-manual"]').text()).toContain('Manual collection')
    expect(wrapper.findAll('[data-test="payments-gateway-card"]')).toHaveLength(0)
  })

  it('renders the loaded gateway panel with a card per gateway', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="payments-panel"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="payments-gateway-card"]')).toHaveLength(2)
  })

  // ── §1.3 limitation notice: exact copy, rendered prominently regardless of load state ────────

  const LIMITATION_COPY =
    'Until Payvia supports explicit merchant connections, EVERY Thallo storefront and SaaS ' +
    'subscription settles through the single platform gateway account. It is not workspace ' +
    'payment isolation.'

  it('renders the §1.3 limitation notice verbatim, prominently, in every load state', async () => {
    // Loaded state.
    const loaded = mountPage()
    await flushPromises()
    expect(loaded.find('[data-test="payments-limitation-notice"]').text()).toContain(LIMITATION_COPY)

    // Loading state — the notice is a standing policy statement, not gated on the query.
    paymentsStatus.value = 'pending'
    paymentsData.value = undefined
    const loading = mountPage()
    await flushPromises()
    expect(loading.find('[data-test="payments-limitation-notice"]').text()).toContain(LIMITATION_COPY)

    // Error state.
    paymentsStatus.value = 'error'
    const errored = mountPage()
    await flushPromises()
    expect(errored.find('[data-test="payments-limitation-notice"]').text()).toContain(LIMITATION_COPY)
  })

  // ── Secret presence badges: boolean-only, never a value (seeded fixture) ─────────────────────

  it('renders write-only secret inputs with boolean set/source badges — never a stored value', async () => {
    paymentsData.value = paymentsSettings({
      gateways: [
        {
          id: 'paystack',
          enabled: { value: true, default: true, overridden: false },
          secret_key: { set: true, source: 'settings' },
          webhook_secret: { set: true, source: 'env' },
          default: true,
          webhook_url: 'https://thallo.example/webhooks/paystack',
        },
      ],
    })
    const wrapper = mountPage()
    await flushPromises()

    const secretInput = wrapper.find('[data-test="payments-secret-paystack-secret_key"]')
    // Write-only: the input NEVER carries a stored value — only the boolean set-state placeholder.
    expect((secretInput.element as HTMLInputElement).value).toBe('')
    expect(secretInput.attributes('placeholder')).toContain('stored')
    expect(wrapper.text()).toContain('A key is stored (encrypted). Leave blank to keep it.')

    const webhookInput = wrapper.find('[data-test="payments-secret-paystack-webhook_secret"]')
    expect((webhookInput.element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).toContain('Using the key from .env.')

    // Defense-in-depth: even if a `set`/`source` state object carried a stray value-shaped key
    // (it never should — SecretFieldState has no such field), the rendered page must never
    // surface it. Cast through `unknown` to simulate that regression without fighting the type.
    const leaked = 'sk_live_should_never_render_00000000'
    paymentsData.value = paymentsSettings({
      gateways: [
        {
          id: 'paystack',
          enabled: { value: true, default: true, overridden: false },
          secret_key: { set: true, source: 'settings', value: leaked } as unknown as {
            set: boolean
            source: 'settings' | 'env' | null
          },
          webhook_secret: { set: false, source: null },
          default: true,
          webhook_url: null,
        },
      ],
    })
    await flushPromises()
    expect(wrapper.html()).not.toContain(leaked)
  })

  it('never renders a gateway webhook URL text as a stored-secret hint (webhook URLs are not secrets)', async () => {
    const wrapper = mountPage()
    await flushPromises()
    const urlRow = wrapper.find('[data-test="payments-webhook-url-paystack"]')
    expect(urlRow.text()).toContain('https://thallo.example/webhooks/paystack')
    expect(wrapper.find('[data-test="payments-webhook-copy-paystack"]').exists()).toBe(true)
  })

  // ── Save round-trip + 422 surface ─────────────────────────────────────────────────────────────

  it('sends only changed fields on save and shows a success toast', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="payments-secret-paystack-secret_key"]').setValue('sk_live_new123')
    await wrapper.find('[data-test="payments-save"]').trigger('click')
    await flushPromises()

    expect(saveMock).toHaveBeenCalledTimes(1)
    const body = saveMock.mock.calls[0]![0]
    expect(body.gateways.paystack.secret_key).toBe('sk_live_new123')
    expect(body.gateways.paystack).not.toHaveProperty('webhook_secret')
    expect(body.gateways.paystack).not.toHaveProperty('enabled')
    expect(body).not.toHaveProperty('default_gateway')
    expect(notify.success).toHaveBeenCalled()
  })

  it('Clear sends an explicit null for a settings-stored secret', async () => {
    paymentsData.value = paymentsSettings({
      gateways: [
        {
          id: 'paystack',
          enabled: { value: true, default: true, overridden: false },
          secret_key: { set: true, source: 'settings' },
          webhook_secret: { set: false, source: null },
          default: true,
          webhook_url: 'https://thallo.example/webhooks/paystack',
        },
      ],
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="payments-clear-paystack-secret_key"]').trigger('click')
    await wrapper.find('[data-test="payments-save"]').trigger('click')
    await flushPromises()

    const body = saveMock.mock.calls[0]![0]
    expect(body.gateways.paystack.secret_key).toBeNull()
  })

  it('renders a 422 field error verbatim and does not clear the form', async () => {
    // A plain framework error-body object (Response::validation()'s exact envelope shape) rather
    // than a directly-constructed ApiError: this file's global setup.ts resets the module
    // registry before each test, so an `instanceof ApiError` check against an ApiError built from
    // a separately re-imported class would fail cross-module-identity, silently losing
    // fieldErrors (mirrors commerceSettings.spec.ts's identical precedent/comment).
    saveMock.mockRejectedValueOnce({
      success: false,
      message: 'Validation failed',
      error: {
        code: 422,
        timestamp: '2026-01-01T00:00:00Z',
        request_id: 'req_1',
        details: { 'gateways.paystack.secret_key': 'Secret key must be at least 8 characters.' },
      },
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="payments-secret-paystack-secret_key"]').setValue('short')
    await wrapper.find('[data-test="payments-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Secret key must be at least 8 characters.')
    expect(notify.error).toHaveBeenCalled()
    // The typed value is preserved so the operator can correct it rather than retype it.
    expect(
      (wrapper.find('[data-test="payments-secret-paystack-secret_key"]').element as HTMLInputElement)
        .value,
    ).toBe('short')
  })

  // ── Platform gating (mirrors /settings/workspaces) ───────────────────────────────────────────

  it('hides Save and disables inputs without manage_platform', async () => {
    useTenancyAccessStore().access.manage_platform = false
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="payments-save"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-test="payments-secret-paystack-secret_key"]').attributes('disabled'),
    ).toBeDefined()
  })
})
