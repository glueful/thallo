import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'

// Mirrors commerceLinkingEnabledGate.spec.ts's approach: mount against the REAL @pinia/colada
// runtime (the thing under test is `useInvoiceSettings()`'s OWN boolean-default resilience — a
// mocked `useQuery`, or a mocked `@/queries/commerceSettings` module (as commerceInvoicePrint.spec
// and commerceInvoice consumers do for THEIR purposes), would trivially bypass the exact
// defaulting logic under test here) but mocks `@/api/client` directly rather than global fetch,
// so this file needs no per-test dynamic re-import.
const clientGet = vi.hoisted(() => vi.fn())
vi.mock('@/api/client', () => ({ client: { GET: clientGet } }))

import { useInvoiceSettings, type InvoiceSettings } from '@/queries/commerceSettings'

function okResponse(data: unknown) {
  return { data: { data }, error: undefined, response: new Response(null, { status: 200 }) }
}

function baseSettingsPayload(overrides: Record<string, unknown> = {}) {
  return {
    settings: {
      'commerce.invoice.logo_blob_uuid': { value: '', default: '', overridden: false },
      'commerce.invoice.footer_text': { value: '', default: '', overridden: false },
      'commerce.invoice.paper_preset': { value: 'a4', default: 'a4', overridden: false },
      ...overrides,
    },
    invoice_logo_url: null,
    currency_locked: false,
    has_priced_products: false,
    pages: [],
  }
}

function mountWith() {
  let result: { data: { value: InvoiceSettings | undefined } } | undefined
  const Comp = defineComponent({
    setup() {
      result = useInvoiceSettings()
      return () => h('div')
    },
  })
  // Pinia must be installed before PiniaColada.
  const wrapper = mount(Comp, { global: { plugins: [createPinia(), PiniaColada] } })
  return { wrapper, get: () => result! }
}

describe('useInvoiceSettings resilience — absent boolean keys default to true', () => {
  beforeEach(() => {
    clientGet.mockReset()
  })

  it('defaults showSku/showAddresses/showTaxId to true when the server payload omits all three keys entirely', async () => {
    // `commerce.invoice.show_sku` / `show_addresses` / `show_tax_id` are deliberately ABSENT —
    // an older backend or a malformed/partial response, never a real `false`.
    clientGet.mockResolvedValue(okResponse(baseSettingsPayload()))

    const { get } = mountWith()
    await flushPromises()

    const settings = get().data.value
    expect(settings?.showSku).toBe(true)
    expect(settings?.showAddresses).toBe(true)
    expect(settings?.showTaxId).toBe(true)
  })

  it('still honors an explicit false override for each toggle independently', async () => {
    clientGet.mockResolvedValue(
      okResponse(
        baseSettingsPayload({
          'commerce.invoice.show_sku': { value: false, default: true, overridden: true },
          'commerce.invoice.show_addresses': { value: true, default: true, overridden: false },
          'commerce.invoice.show_tax_id': { value: false, default: true, overridden: true },
        }),
      ),
    )

    const { get } = mountWith()
    await flushPromises()

    const settings = get().data.value
    expect(settings?.showSku).toBe(false)
    expect(settings?.showAddresses).toBe(true)
    expect(settings?.showTaxId).toBe(false)
  })

  it('treats a real true value as true (the ordinary, fully-populated case)', async () => {
    clientGet.mockResolvedValue(
      okResponse(
        baseSettingsPayload({
          'commerce.invoice.show_sku': { value: true, default: true, overridden: false },
          'commerce.invoice.show_addresses': { value: true, default: true, overridden: false },
          'commerce.invoice.show_tax_id': { value: true, default: true, overridden: false },
        }),
      ),
    )

    const { get } = mountWith()
    await flushPromises()

    const settings = get().data.value
    expect(settings?.showSku).toBe(true)
    expect(settings?.showAddresses).toBe(true)
    expect(settings?.showTaxId).toBe(true)
  })
})
