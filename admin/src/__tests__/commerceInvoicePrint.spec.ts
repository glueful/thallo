import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { CommerceInvoiceData } from '@/queries/commerceInvoice'
import type { InvoiceSettings } from '@/queries/commerceSettings'

// Orders-invoices-receipts spec, Task 8: printable invoice/receipt views. This is the complete
// RED-first spec matrix (task-8-brief.md Step 1) for:
//  - the single invoice-data query/type implementation (commerceInvoice.ts, cross-checked against
//    commerceInvoice.spec.ts's runtime "commerceOrders.ts no longer exports it" assertion),
//  - InvoiceDocument.vue rendering all three presets, the untoggleable core, optional-section
//    toggles, the server-derived-URL-only logo rule, escaped footer text, and JPY's zero-decimal
//    exponent,
//  - the print page's per-print (never-persisted) preset override, window.print(), and the
//    fetch-failure retry state,
//  - the layout's `data-print-chrome`/`data-print-shell` structural split.

const ROOT = process.cwd()

function invoice(overrides: Partial<CommerceInvoiceData> = {}): CommerceInvoiceData {
  return {
    schema_version: 2,
    seller: { name: 'Acme Supply Co.', address: '1 Market St', tax_id: 'TAX-1' },
    buyer: {
      email: 'buyer@example.com',
      addresses: {
        shipping: { name: 'Ada Lovelace', line1: '1 Main St', city: 'Springfield' },
        billing: null,
      },
    },
    order: {
      number: 'ORD-2002',
      dates: { placed_at: '2026-01-01 00:00:00', created_at: '2026-01-01 00:00:00', updated_at: null },
      currency: 'USD',
      currency_exponent: 2,
      status: 'paid',
    },
    lines: [
      { name: 'Widget', sku: 'SKU-1', quantity: 2, unit_minor: 1000, subtotal_minor: 2000, addons: [] },
    ],
    totals: {
      subtotal_minor: 2000,
      discount_minor: 0,
      shipping_minor: 500,
      tax_minor: 0,
      grand_minor: 2500,
      refunded_minor: 0,
    },
    refunds: [],
    ...overrides,
  }
}

const baseDocumentProps = {
  logoUrl: null as string | null,
  footerText: '',
  showSku: true,
  showAddresses: true,
  showTaxId: true,
}

// ── InvoiceDocument: pure presentational component — no query mocking needed ────────────────────

describe('InvoiceDocument', () => {
  it.each(['a4', 'thermal_80', 'thermal_58'] as const)(
    'renders the %s preset with the document content intact',
    async (preset) => {
      const { default: InvoiceDocument } = await import(
        '@/pages/commerce/orders/components/InvoiceDocument.vue'
      )
      const wrapper = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset, ...baseDocumentProps },
      })
      const doc = wrapper.find('[data-test="invoice-document"]')
      expect(doc.attributes('data-preset')).toBe(preset)
      // The emitted class hyphenates the preset ('thermal_80' -> 'invoice-thermal-80') to match
      // print.css's selectors — see the joined test below for why this can't just re-derive the
      // same string the component computes.
      expect(doc.classes()).toContain(`invoice-${preset.replace(/_/g, '-')}`)
      expect(wrapper.text()).toContain('ORD-2002')
    },
  )

  // Regression guard for the underscore/hyphen mismatch (component emitted `invoice-thermal_80`
  // while print.css only ever defined `.invoice-thermal-80`, silently leaving every thermal
  // preset entirely unstyled — no named page, no 80/58mm content box, no monochrome/dashed
  // rules). Re-deriving the expected class via the SAME template-string logic the component uses
  // (as the test above still does, for readability) would recreate exactly that bug and pass
  // regardless of which side broke — so THIS test instead reads the class the mounted component
  // ACTUALLY renders and looks that exact string up as a real selector in print.css itself,
  // joining the two sides the way a browser would.
  it('the class each preset actually renders has a matching real selector in print.css', async () => {
    const { default: InvoiceDocument } = await import(
      '@/pages/commerce/orders/components/InvoiceDocument.vue'
    )
    const { INVOICE_PAPER_PRESETS } = await import('@/queries/commerceSettings')
    const cssRaw = readFileSync(join(ROOT, 'src/assets/print.css'), 'utf8')
    const css = cssRaw.replace(/\/\*[\s\S]*?\*\//g, '')

    for (const preset of INVOICE_PAPER_PRESETS) {
      const wrapper = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset, ...baseDocumentProps },
      })
      const presetClass = wrapper
        .find('[data-test="invoice-document"]')
        .classes()
        .find((c) => c.startsWith('invoice-') && c !== 'invoice-document')
      expect(presetClass, `no invoice-* preset class rendered for preset "${preset}"`).toBeDefined()

      const escaped = presetClass!.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')
      const selector = new RegExp(`\\.${escaped}\\b`)
      expect(
        css,
        `print.css has no selector for "${presetClass}" (rendered for preset "${preset}")`,
      ).toMatch(selector)
    }
  })

  describe('untoggleable core (spec Ruling 3) — every SKU/addresses/tax-id toggle combination', () => {
    const bools = [true, false]
    for (const showSku of bools) {
      for (const showAddresses of bools) {
        for (const showTaxId of bools) {
          it(`keeps core content when showSku=${showSku} showAddresses=${showAddresses} showTaxId=${showTaxId}`, async () => {
            const { default: InvoiceDocument } = await import(
              '@/pages/commerce/orders/components/InvoiceDocument.vue'
            )
            const wrapper = mount(InvoiceDocument, {
              props: {
                invoice: invoice(),
                preset: 'a4',
                logoUrl: null,
                footerText: '',
                showSku,
                showAddresses,
                showTaxId,
              },
            })
            expect(wrapper.find('[data-test="invoice-order-number"]').text()).toContain('ORD-2002')
            expect(wrapper.find('[data-test="invoice-order-status"]').text()).toBe('Order status: paid')
            expect(wrapper.find('[data-test="invoice-buyer-email"]').text()).toBe('buyer@example.com')
            expect(wrapper.find('[data-test="invoice-line-name"]').text()).toBe('Widget')
            expect(wrapper.find('[data-test="invoice-line-qty"]').text()).toBe('2')
            expect(wrapper.find('[data-test="invoice-line-unit"]').text()).toBe('$10.00')
            expect(wrapper.find('[data-test="invoice-line-total"]').text()).toBe('$20.00')
            expect(wrapper.find('[data-test="invoice-total-subtotal"]').text()).toBe('$20.00')
            expect(wrapper.find('[data-test="invoice-total-grand"]').text()).toBe('$25.00')
            expect(wrapper.find('[data-test="invoice-seller-name"]').text()).toBe('Acme Supply Co.')
          })
        }
      }
    }
  })

  // ── Nullable buyer email (Task 14: admin-order-creation walk-in orders) ────────────────────
  it('renders "Walk-in customer" instead of a bare placeholder when buyer.email is null', async () => {
    const { default: InvoiceDocument } = await import(
      '@/pages/commerce/orders/components/InvoiceDocument.vue'
    )
    const wrapper = mount(InvoiceDocument, {
      props: {
        invoice: invoice({ buyer: { email: null, addresses: null } }),
        preset: 'a4',
        ...baseDocumentProps,
      },
    })
    expect(wrapper.find('[data-test="invoice-buyer-email"]').text()).toBe('Walk-in customer')
  })

  describe('optional sections respond to their own toggle', () => {
    it('shows the SKU column only when showSku is true', async () => {
      const { default: InvoiceDocument } = await import(
        '@/pages/commerce/orders/components/InvoiceDocument.vue'
      )
      const shown = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, showSku: true },
      })
      expect(shown.find('[data-test="invoice-line-sku"]').exists()).toBe(true)

      const hidden = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, showSku: false },
      })
      expect(hidden.find('[data-test="invoice-line-sku"]').exists()).toBe(false)
    })

    it('shows buyer addresses only when showAddresses is true', async () => {
      const { default: InvoiceDocument } = await import(
        '@/pages/commerce/orders/components/InvoiceDocument.vue'
      )
      const shown = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, showAddresses: true },
      })
      expect(shown.find('[data-test="invoice-address-shipping"]').exists()).toBe(true)

      const hidden = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, showAddresses: false },
      })
      expect(hidden.find('[data-test="invoice-addresses"]').exists()).toBe(false)
    })

    it('shows the seller tax id only when showTaxId is true', async () => {
      const { default: InvoiceDocument } = await import(
        '@/pages/commerce/orders/components/InvoiceDocument.vue'
      )
      const shown = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, showTaxId: true },
      })
      expect(shown.find('[data-test="invoice-seller-tax-id"]').text()).toBe('TAX-1')

      const hidden = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, showTaxId: false },
      })
      expect(hidden.find('[data-test="invoice-seller-tax-id"]').exists()).toBe(false)
    })

    it('renders the logo only when logoUrl is set, using it verbatim as the <img> src', async () => {
      const { default: InvoiceDocument } = await import(
        '@/pages/commerce/orders/components/InvoiceDocument.vue'
      )
      const withLogo = mount(InvoiceDocument, {
        props: {
          invoice: invoice(),
          preset: 'a4',
          ...baseDocumentProps,
          logoUrl: 'https://cdn.example.test/logo.png',
        },
      })
      const img = withLogo.find('[data-test="invoice-logo"]')
      expect(img.exists()).toBe(true)
      expect(img.attributes('src')).toBe('https://cdn.example.test/logo.png')

      const withoutLogo = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, logoUrl: null },
      })
      expect(withoutLogo.find('[data-test="invoice-logo"]').exists()).toBe(false)
    })

    it('has no logo-blob-uuid prop at all — structurally cannot synthesize an image URL from a uuid', () => {
      const src = readFileSync(
        join(ROOT, 'src/pages/commerce/orders/components/InvoiceDocument.vue'),
        'utf8',
      )
      expect(src).not.toMatch(/logo_blob_uuid|logoBlobUuid/)
    })

    it('renders footer text only when non-empty', async () => {
      const { default: InvoiceDocument } = await import(
        '@/pages/commerce/orders/components/InvoiceDocument.vue'
      )
      const withFooter = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, footerText: 'Thanks for shopping!' },
      })
      expect(withFooter.find('[data-test="invoice-footer"]').text()).toBe('Thanks for shopping!')

      const withoutFooter = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, footerText: '' },
      })
      expect(withoutFooter.find('[data-test="invoice-footer"]').exists()).toBe(false)
    })
  })

  describe('hostile footer text is always escaped', () => {
    it('renders a hostile footer as plain text, never injecting real markup', async () => {
      const { default: InvoiceDocument } = await import(
        '@/pages/commerce/orders/components/InvoiceDocument.vue'
      )
      const hostile = '<img src=x onerror="alert(1)">'
      const wrapper = mount(InvoiceDocument, {
        props: { invoice: invoice(), preset: 'a4', ...baseDocumentProps, footerText: hostile },
      })
      const footer = wrapper.find('[data-test="invoice-footer"]')
      expect(footer.text()).toBe(hostile)
      expect(footer.find('img').exists()).toBe(false)
      expect(footer.element.innerHTML).not.toContain('<img')
    })

    it('never uses v-html anywhere in the document template', () => {
      const src = readFileSync(
        join(ROOT, 'src/pages/commerce/orders/components/InvoiceDocument.vue'),
        'utf8',
      )
      expect(src).not.toMatch(/v-html/)
    })
  })

  it('renders sanitized addon labels, never variant option values, thumbnails, or links', async () => {
    const { default: InvoiceDocument } = await import(
      '@/pages/commerce/orders/components/InvoiceDocument.vue'
    )
    const wrapper = mount(InvoiceDocument, {
      props: {
        invoice: invoice({
          lines: [
            {
              name: 'Widget',
              sku: 'SKU-1',
              quantity: 1,
              unit_minor: 1000,
              subtotal_minor: 1000,
              addons: [{ name: 'Color', choice_label: 'Red', price_delta: 0 }],
            },
          ],
        }),
        preset: 'a4',
        ...baseDocumentProps,
      },
    })
    expect(wrapper.find('[data-test="invoice-line-addons"]').text()).toBe('Color: Red')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('renders refund rows with exact money and method', async () => {
    const { default: InvoiceDocument } = await import(
      '@/pages/commerce/orders/components/InvoiceDocument.vue'
    )
    const wrapper = mount(InvoiceDocument, {
      props: {
        invoice: invoice({ refunds: [{ date: '2026-01-15 10:00:00', amount_minor: 500, method: 'original' }] }),
        preset: 'a4',
        ...baseDocumentProps,
      },
    })
    const row = wrapper.find('[data-test="invoice-refund"]')
    expect(row.text()).toContain('$5.00')
    expect(row.text()).toContain('original')
  })

  it('renders JPY amounts with no decimal places (currency_exponent 0)', async () => {
    const { default: InvoiceDocument } = await import(
      '@/pages/commerce/orders/components/InvoiceDocument.vue'
    )
    const wrapper = mount(InvoiceDocument, {
      props: {
        invoice: invoice({
          order: {
            number: 'ORD-JPY',
            dates: { placed_at: '2026-01-01 00:00:00', created_at: null, updated_at: null },
            currency: 'JPY',
            currency_exponent: 0,
            status: 'paid',
          },
          lines: [
            { name: 'Widget', sku: 'SKU-1', quantity: 1, unit_minor: 500, subtotal_minor: 500, addons: [] },
          ],
          totals: {
            subtotal_minor: 500,
            discount_minor: 0,
            shipping_minor: 0,
            tax_minor: 0,
            grand_minor: 500,
            refunded_minor: 0,
          },
        }),
        preset: 'a4',
        ...baseDocumentProps,
      },
    })
    const total = wrapper.find('[data-test="invoice-total-grand"]').text()
    expect(total).not.toContain('.')
    expect(total).toContain('500')
  })
})

// ── invoice.vue print page: preset override, window.print, retry state ─────────────────────────

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

const routeState = vi.hoisted(() => ({ params: { uuid: 'o1' } as Record<string, string> }))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
}))

const invoiceDataValue = ref<CommerceInvoiceData | undefined>(undefined)
const invoiceStatusValue = ref<'pending' | 'error' | 'success'>('success')
const refetchMock = vi.hoisted(() => vi.fn())
vi.mock('@/queries/commerceInvoice', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceInvoice')>()
  return {
    ...actual,
    useOrderInvoiceData: () => ({
      data: invoiceDataValue,
      status: invoiceStatusValue,
      refetch: refetchMock,
    }),
  }
})

const invoiceSettingsValue = ref<InvoiceSettings | undefined>(undefined)
const invoiceSettingsStatusValue = ref<'pending' | 'error' | 'success'>('success')
vi.mock('@/queries/commerceSettings', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/commerceSettings')>()
  return {
    ...actual,
    useInvoiceSettings: () => ({ data: invoiceSettingsValue, status: invoiceSettingsStatusValue }),
  }
})

import InvoicePage from '@/pages/commerce/orders/[uuid]/invoice.vue'

function settings(overrides: Partial<InvoiceSettings> = {}): InvoiceSettings {
  return {
    logoBlobUuid: '',
    logoUrl: null,
    footerText: '',
    showSku: true,
    showAddresses: true,
    showTaxId: true,
    paperPreset: 'a4',
    ...overrides,
  }
}

describe('invoice.vue print page', () => {
  beforeEach(() => {
    routeState.params = { uuid: 'o1' }
    invoiceDataValue.value = invoice()
    invoiceStatusValue.value = 'success'
    invoiceSettingsValue.value = settings()
    invoiceSettingsStatusValue.value = 'success'
    refetchMock.mockReset()
  })

  it('renders the InvoiceDocument once the invoice resolves', async () => {
    const wrapper = mount(InvoicePage)
    await flushPromises()
    expect(wrapper.find('[data-test="invoice-document"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('ORD-2002')
  })

  it('shows a loading state while pending — never an empty printable', async () => {
    invoiceStatusValue.value = 'pending'
    const wrapper = mount(InvoicePage)
    await flushPromises()
    expect(wrapper.find('[data-test="invoice-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="invoice-document"]').exists()).toBe(false)
  })

  it('shows a retry state, never an empty printable, when the fetch fails', async () => {
    invoiceStatusValue.value = 'error'
    const wrapper = mount(InvoicePage)
    await flushPromises()
    expect(wrapper.find('[data-test="invoice-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="invoice-retry"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="invoice-document"]').exists()).toBe(false)
  })

  it('the retry control calls refetch', async () => {
    invoiceStatusValue.value = 'error'
    const wrapper = mount(InvoicePage)
    await flushPromises()
    await wrapper.find('[data-test="invoice-retry"]').trigger('click')
    expect(refetchMock).toHaveBeenCalledTimes(1)
  })

  it('marks the on-screen toolbar as print chrome', async () => {
    const wrapper = mount(InvoicePage)
    await flushPromises()
    expect(wrapper.find('[data-test="invoice-toolbar"]').attributes('data-print-chrome')).not.toBeUndefined()
  })

  it('initializes the segmented preset control from commerce.invoice.paper_preset', async () => {
    invoiceSettingsValue.value = settings({ paperPreset: 'thermal_80' })
    const wrapper = mount(InvoicePage)
    await flushPromises()
    expect(wrapper.find('[data-test="invoice-document"]').attributes('data-preset')).toBe('thermal_80')
    expect(wrapper.find('[data-test="invoice-preset-thermal_80"]').attributes('aria-pressed')).toBe('true')
  })

  it('changes the rendered preset locally on click WITHOUT writing back to settings', async () => {
    const wrapper = mount(InvoicePage)
    await flushPromises()
    expect(wrapper.find('[data-test="invoice-document"]').attributes('data-preset')).toBe('a4')

    await wrapper.find('[data-test="invoice-preset-thermal_58"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="invoice-document"]').attributes('data-preset')).toBe('thermal_58')
    // The underlying settings value stays exactly as loaded — proves no save mutation ran.
    expect(invoiceSettingsValue.value?.paperPreset).toBe('a4')
  })

  it('clicking Print / Save as PDF calls window.print', async () => {
    const printSpy = vi.spyOn(window, 'print').mockImplementation(() => undefined)
    const wrapper = mount(InvoicePage)
    await flushPromises()

    await wrapper.find('[data-test="invoice-print"]').trigger('click')
    expect(printSpy).toHaveBeenCalledTimes(1)
    printSpy.mockRestore()
  })
})

// ── One invoice-data implementation: both consumers import commerceInvoice.ts ──────────────────

describe('one invoice-data implementation (structural)', () => {
  it('the order-detail formatted modal imports useOrderInvoiceData from commerceInvoice.ts', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/orders/[uuid]/index.vue'), 'utf8')
    expect(src).toMatch(/useOrderInvoiceData[\s\S]*?from ['"]@\/queries\/commerceInvoice['"]/)
  })

  it('the printable invoice page imports useOrderInvoiceData from commerceInvoice.ts', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/orders/[uuid]/invoice.vue'), 'utf8')
    expect(src).toMatch(/useOrderInvoiceData[\s\S]*?from ['"]@\/queries\/commerceInvoice['"]/)
  })

  it('commerceOrders.ts no longer defines the invoice-data endpoint path', () => {
    const src = readFileSync(join(ROOT, 'src/queries/commerceOrders.ts'), 'utf8')
    expect(src).not.toMatch(/invoice-data/)
  })
})

// ── Print route requires auth + capability (mirrors the existing detail-page convention) ───────

describe('invoice print route gating', () => {
  it('the print route requires auth and the thallo.commerce capability', () => {
    const src = readFileSync(join(ROOT, 'src/pages/commerce/orders/[uuid]/invoice.vue'), 'utf8')
    expect(src).toMatch(/requiresAuth:\s*true/)
    expect(src).toMatch(/requiresCapability:\s*thallo\.commerce/)
  })
})

// ── main.ts loads the global print stylesheet ───────────────────────────────────────────────────

describe('print.css is loaded globally', () => {
  it('main.ts imports assets/print.css', () => {
    const src = readFileSync(join(ROOT, 'src/main.ts'), 'utf8')
    expect(src).toMatch(/import ['"]\.\/assets\/print\.css['"]/)
  })

  it('print.css declares valid named-page grammar: A4 sized, thermal size:auto', () => {
    const raw = readFileSync(join(ROOT, 'src/assets/print.css'), 'utf8')
    // Strip comments first — the file's OWN explanatory prose deliberately names the invalid
    // grammar it avoids ("never `size: 80mm auto`"), which would otherwise false-positive the
    // negative assertions below.
    const src = raw.replace(/\/\*[\s\S]*?\*\//g, '')
    expect(src).toMatch(/@page\s+invoice-a4\s*\{[^}]*size:\s*A4/)
    expect(src).toMatch(/@page\s+invoice-thermal\s*\{[^}]*size:\s*auto/)
    // Never the invalid fixed-width + auto-height page-size grammar.
    expect(src).not.toMatch(/size:\s*80mm\s+auto/)
    expect(src).not.toMatch(/size:\s*58mm\s+auto/)
    // Both thermal presets select the SAME named page.
    expect(src).toMatch(/\.invoice-thermal-80,\s*\n?\s*\.invoice-thermal-58\s*\{[^}]*page:\s*invoice-thermal/)
    expect(src).toMatch(/\[data-print-chrome\]/)
    expect(src).toMatch(/\[data-print-shell\]/)
    expect(src).toMatch(/thead\s*\{[^}]*display:\s*table-header-group/)
    expect(src).toMatch(/\.invoice-document tr\s*\{[^}]*break-inside:\s*avoid/)
  })
})

// ── Layout print hooks: sidebar+toolbar are chrome; RouterView's only marked ancestor is the
// printable shell; a chrome element must never be an ancestor of RouterView (structural — mirrors
// the codebase's own precedent for asserting declarative route/template properties from source,
// e.g. commerceOrders.spec.ts's "route gating" describe block). ───────────────────────────────

describe('layout print hooks (default.vue)', () => {
  const src = readFileSync(join(ROOT, 'src/layouts/default.vue'), 'utf8')

  function extract(tag: string): string {
    const match = src.match(new RegExp(`<${tag}[\\s\\S]*?</${tag}>`))
    if (!match) throw new Error(`Could not find a <${tag}>...</${tag}> block in default.vue`)
    return match[0]
  }

  it('marks the sidebar as print chrome', () => {
    expect(extract('UDashboardSidebar')).toMatch(/data-print-chrome/)
  })

  it('never nests RouterView inside the sidebar (chrome) block', () => {
    expect(extract('UDashboardSidebar')).not.toMatch(/<RouterView/)
  })

  it('marks the RouterView content wrapper as the printable shell, and RouterView lives inside it', () => {
    const shellDiv = src.match(/<div\s+data-print-shell[\s\S]*?\n {4}<\/div>/)
    expect(shellDiv).not.toBeNull()
    expect(shellDiv![0]).toMatch(/<RouterView/)
  })

  it('the sidebar block closes before the print-shell div opens (chrome is never an ancestor of the shell)', () => {
    const sidebarBlock = extract('UDashboardSidebar')
    const sidebarEnd = src.indexOf(sidebarBlock) + sidebarBlock.length
    const shellStart = src.indexOf('data-print-shell')
    expect(shellStart).toBeGreaterThan(sidebarEnd)
  })

  // Regression guard for the multi-page truncation bug: `UDashboardGroup`'s theme base is
  // `fixed inset-0 flex overflow-hidden` — a viewport-clipped ancestor of `[data-print-shell]`
  // that silently drops every physical page after the first when printing (an A4 invoice with
  // ~15+ lines, or ANY thermal receipt, loses its totals/footer). A real multi-page print
  // render isn't practically assertable in jsdom (there is no pagination/print engine to run),
  // so this is a structural assertion on the fix's two required pieces instead: the stable hook
  // exists on the actual clipping ancestor, and print.css resets exactly the properties that
  // clip it.
  it('marks UDashboardGroup (the fixed/overflow-hidden shell root) as the print-flow reset hook', () => {
    expect(extract('UDashboardGroup')).toMatch(/data-print-root/)
  })

  it('data-print-root sits OUTSIDE (an ancestor of) the print-shell div, never the reverse', () => {
    const groupBlock = extract('UDashboardGroup')
    const rootAttrIndex = groupBlock.indexOf('data-print-root')
    const shellIndex = groupBlock.indexOf('data-print-shell')
    expect(rootAttrIndex).toBeGreaterThanOrEqual(0)
    expect(shellIndex).toBeGreaterThan(rootAttrIndex)
  })

  it('print.css resets the print-root ancestor out of fixed/overflow-hidden/viewport-height so multi-page content can flow', () => {
    const cssRaw = readFileSync(join(ROOT, 'src/assets/print.css'), 'utf8')
    const css = cssRaw.replace(/\/\*[\s\S]*?\*\//g, '')
    const rule = css.match(/\[data-print-root\]\s*\{([^}]*)\}/)
    expect(rule, 'print.css has no [data-print-root] rule at all').not.toBeNull()
    const body = rule![1]!
    expect(body).toMatch(/position:\s*static/)
    expect(body).toMatch(/overflow:\s*visible/)
    expect(body).toMatch(/height:\s*auto/)
  })
})
