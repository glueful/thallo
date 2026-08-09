<script setup lang="ts">
import { computed } from 'vue'
import type { CommerceInvoiceData } from '@/queries/commerceInvoice'
import type { CommerceOrderLineAddon } from '@/queries/commerceOrders'
import type { InvoicePaperPreset } from '@/queries/commerceSettings'
import { formatMoney } from '@/composables/useMoney'

// Orders-invoices-receipts spec §1.3/§2.3: the ONE printable document, parameterized by `preset`.
// Everything rendered unconditionally here is the untoggleable core (order identity/date/status,
// customer identity, line names/quantities/monetary values, totals, refunds) — SKU, buyer
// addresses, seller tax id, logo, and footer are the only optional sections, each gated by its own
// prop below. `logoUrl` is the SOLE thing this component will ever put in an `<img src>` — a stored
// logo uuid alone must NEVER be used to synthesize an image URL (that ownership+servability check
// lives entirely server-side in `InvoiceLogoResolver`).
const props = defineProps<{
  invoice: CommerceInvoiceData
  preset: InvoicePaperPreset
  logoUrl: string | null
  footerText: string
  showSku: boolean
  showAddresses: boolean
  showTaxId: boolean
}>()

const presetClass = computed(() => `invoice-${props.preset}`)

const moneyMeta = computed(() => ({
  currency: props.invoice.order.currency ?? 'USD',
  currency_exponent: props.invoice.order.currency_exponent,
}))

function money(minor: number): string {
  return formatMoney(minor, moneyMeta.value)
}

function fmtDate(v: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}

/** Sanitized addon echo → one display string per addon — never variant options, never a
 * thumbnail/link (Ruling 13). `AddonSnapshot::sanitize()` already sanitized the underlying
 * name/value/choice_label server-side; this only picks the best available label. */
function addonLabel(addon: CommerceOrderLineAddon): string {
  const value =
    addon.choice_label ?? (addon.value !== undefined && addon.value !== null ? String(addon.value) : null)
  return value ? `${addon.name}: ${value}` : addon.name
}

function addonsText(addons: CommerceOrderLineAddon[]): string {
  return addons.map(addonLabel).join(', ')
}

const orderDate = computed(
  () => props.invoice.order.dates.placed_at ?? props.invoice.order.dates.created_at,
)

const shippingAddress = computed(() => props.invoice.buyer.addresses?.shipping ?? null)
const billingAddress = computed(() => props.invoice.buyer.addresses?.billing ?? null)

/** The address shape is deliberately loose (backend accepts several alias groups per field) —
 * mirrors `orders/[uuid]/index.vue`'s identical `addressField()`/projection approach rather than
 * fabricating a fixed schema the API doesn't guarantee. */
function addressLines(address: Record<string, unknown>): string[] {
  const field = (keys: string[]): string | null => {
    for (const key of keys) {
      const v = address[key]
      if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
        const trimmed = String(v).trim()
        if (trimmed !== '') return trimmed
      }
    }
    return null
  }
  const name = field(['name', 'full_name', 'recipient_name', 'recipient'])
  const line1 = field(['line1', 'address1', 'street1', 'line_1'])
  const line2 = field(['line2', 'address2', 'street2', 'line_2'])
  const city = field(['city'])
  const region = field(['region', 'state', 'province'])
  const postcode = field(['postcode', 'postal_code', 'zip', 'zip_code'])
  const country = field(['country'])
  const cityLine = [city, region, postcode].filter((v): v is string => v !== null).join(', ')
  return [name, line1, line2, cityLine === '' ? null : cityLine, country].filter(
    (v): v is string => v !== null,
  )
}
</script>

<template>
  <div class="invoice-document" :class="presetClass" :data-preset="preset" data-test="invoice-document">
    <!-- Header: logo (server-derived URL only) + seller identity — always printed (core). -->
    <header class="invoice-header">
      <img v-if="logoUrl" :src="logoUrl" alt="" class="invoice-logo" data-test="invoice-logo" />
      <div data-test="invoice-seller">
        <p data-test="invoice-seller-name">{{ invoice.seller.name ?? '—' }}</p>
        <p v-if="invoice.seller.address">{{ invoice.seller.address }}</p>
        <p v-if="showTaxId && invoice.seller.tax_id" data-test="invoice-seller-tax-id">
          {{ invoice.seller.tax_id }}
        </p>
      </div>
    </header>

    <hr class="invoice-rule" />

    <!-- Order identity + customer identity — always printed (core). -->
    <section class="invoice-meta" data-test="invoice-meta">
      <div data-test="invoice-order-number">Order {{ invoice.order.number ?? '—' }}</div>
      <div data-test="invoice-order-date">{{ fmtDate(orderDate) }}</div>
      <div data-test="invoice-order-status">Order status: {{ invoice.order.status ?? '—' }}</div>
      <div data-test="invoice-buyer-email">{{ invoice.buyer.email ?? '—' }}</div>
    </section>

    <!-- Buyer addresses — optional (commerce.invoice.show_addresses). -->
    <section
      v-if="showAddresses && (shippingAddress || billingAddress)"
      class="invoice-addresses"
      data-test="invoice-addresses"
    >
      <div v-if="shippingAddress" data-test="invoice-address-shipping">
        <p class="invoice-address-label">Shipping</p>
        <p v-for="(line, i) in addressLines(shippingAddress)" :key="i">{{ line }}</p>
      </div>
      <div v-if="billingAddress" data-test="invoice-address-billing">
        <p class="invoice-address-label">Billing</p>
        <p v-for="(line, i) in addressLines(billingAddress)" :key="i">{{ line }}</p>
      </div>
    </section>

    <hr class="invoice-rule" />

    <!-- Line items — name/qty/unit price/line total always printed; SKU optional
         (commerce.invoice.show_sku); sanitized addons, never variant options/thumbnails/links. -->
    <table data-test="invoice-lines">
      <thead>
        <tr>
          <th>Item</th>
          <th v-if="showSku">SKU</th>
          <th>Qty</th>
          <th>Unit price</th>
          <th>Line total</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(line, i) in invoice.lines" :key="i" data-test="invoice-line">
          <td>
            <span data-test="invoice-line-name">{{ line.name }}</span>
            <div v-if="line.addons.length > 0" class="invoice-line-addons" data-test="invoice-line-addons">
              {{ addonsText(line.addons) }}
            </div>
          </td>
          <td v-if="showSku" data-test="invoice-line-sku">{{ line.sku }}</td>
          <td data-test="invoice-line-qty">{{ line.quantity }}</td>
          <td data-test="invoice-line-unit">{{ money(line.unit_minor) }}</td>
          <td data-test="invoice-line-total">{{ money(line.subtotal_minor) }}</td>
        </tr>
      </tbody>
    </table>

    <hr class="invoice-rule" />

    <!-- Totals + refunds — always printed (core). -->
    <section class="invoice-totals" data-test="invoice-totals">
      <div>
        <span>Subtotal</span>
        <span data-test="invoice-total-subtotal">{{ money(invoice.totals.subtotal_minor) }}</span>
      </div>
      <div>
        <span>Discount</span>
        <span data-test="invoice-total-discount">{{ money(invoice.totals.discount_minor) }}</span>
      </div>
      <div>
        <span>Shipping</span>
        <span data-test="invoice-total-shipping">{{ money(invoice.totals.shipping_minor) }}</span>
      </div>
      <div>
        <span>Tax</span>
        <span data-test="invoice-total-tax">{{ money(invoice.totals.tax_minor) }}</span>
      </div>
      <div>
        <span>Refunded</span>
        <span data-test="invoice-total-refunded">{{ money(invoice.totals.refunded_minor) }}</span>
      </div>
      <div class="invoice-total-grand">
        <span>Total</span>
        <span data-test="invoice-total-grand">{{ money(invoice.totals.grand_minor) }}</span>
      </div>
    </section>

    <section v-if="invoice.refunds.length > 0" class="invoice-refunds" data-test="invoice-refunds">
      <p class="invoice-address-label">Refunds</p>
      <div v-for="(r, i) in invoice.refunds" :key="i" data-test="invoice-refund">
        <span>{{ money(r.amount_minor) }}</span>
        <span>{{ r.method }}</span>
        <span>{{ fmtDate(r.date) }}</span>
      </div>
    </section>

    <!-- Footer — optional (commerce.invoice.footer_text), always escaped: plain text
         interpolation only (never a raw-HTML directive), regardless of what save-time
         validation already refused (rendering is not the security boundary). -->
    <footer v-if="footerText" class="invoice-footer" data-test="invoice-footer">{{ footerText }}</footer>
  </div>
</template>
