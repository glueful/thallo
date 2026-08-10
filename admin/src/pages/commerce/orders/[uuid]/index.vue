<script setup lang="ts">
// Orders-invoices-receipts plan, Task 9: detail hierarchy rework. The page now opens on a single
// header band (identity, badges, placed date, customer, grand total, print link, the canonical
// action group, and the destructive/read-only overflow), followed by the commercial blocks (line
// items, totals, payments, refunds, addresses), then the timeline/notes pair at the very bottom —
// and, on `>= xl` viewports, a sticky `OrderStickyRail` alongside the whole thing that links back
// into this SAME structure rather than re-rendering any of it.
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn, DropdownMenuItem } from '@nuxt/ui'
import {
  useCommerceOrder,
  useOrderRefunds,
  canCancelOrder,
  type CommerceOrderAddress,
  type CommerceOrderLine,
} from '@/queries/commerceOrders'
import { useOrderInvoiceData } from '@/queries/commerceInvoice'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useMoney } from '@/composables/useMoney'
import OrderActions from '../components/OrderActions.vue'
import OrderCancelDialog from '../components/OrderCancelDialog.vue'
import OrderPaymentCard from '../components/OrderPaymentCard.vue'
import OrderStickyRail from '../components/OrderStickyRail.vue'
import OrderNotes from '../components/OrderNotes.vue'
import CopyButton from '@/components/CopyButton.vue'

const route = useRoute()
const uuid = computed(() => String(route.params.uuid))

const { data: order, status } = useCommerceOrder(uuid)
const { data: refunds, status: refundsStatus } = useOrderRefunds(uuid)
const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)
const { format } = useMoney()

const invoiceHref = computed(() => `/commerce/orders/${uuid.value}/invoice`)

// Invoice data (Task 13d): view-graded (AdminRouteCatalog 'orders.invoice_data' -> 'view') —
// the trigger is visible regardless of canManage. Fetched lazily: `enabled` is gated on
// `invoiceOpen` so the request only fires once the modal is actually opened, mirroring
// `useCommerceProduct()`'s identical on-demand `enabled` parameter.
const invoiceOpen = ref(false)
const { data: invoice, status: invoiceStatus } = useOrderInvoiceData(uuid, invoiceOpen)

// Overflow menu (Task 9): destructive cancel + the existing (view-graded) invoice-data trigger.
// `UDropdownMenu` (the codebase's established menu primitive — see UserMenu.vue/TenantSwitcher.vue)
// rather than a hand-rolled toggle: Reka UI's DropdownMenu gives Escape-to-close, outside-click
// dismissal, `role="menu"`/`role="menuitem"`, and `aria-haspopup`/`aria-expanded` on the trigger for
// free — a hand-rolled `<div>` would have to reimplement all of that itself. `:portal="false"` keeps
// the menu content in-place in the DOM (never teleported to `document.body`), which is both simpler
// to reason about here (no fixed-position popper escaping the page's own layout/scroll container)
// and directly queryable/clickable in tests without a document-level lookup. Each item's own
// `'data-test'` key rides straight through to its rendered element — Nuxt UI's `pickLinkProps()`
// forwards any `data-*`/`aria-*` key present on the item object verbatim.
const cancelDialogOpen = ref(false)
const canCancel = computed(() => canManage.value && !!order.value && canCancelOrder(order.value.status))

const overflowItems = computed<DropdownMenuItem[]>(() => {
  const items: DropdownMenuItem[] = []
  if (canCancel.value) {
    items.push({
      label: 'Cancel order',
      icon: 'i-lucide-ban',
      color: 'error',
      'data-test': 'order-cancel',
      onSelect: () => {
        cancelDialogOpen.value = true
      },
    })
  }
  items.push({
    label: 'Invoice data',
    icon: 'i-lucide-receipt',
    'data-test': 'order-invoice',
    onSelect: () => {
      invoiceOpen.value = true
    },
  })
  return items
})

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// (still pending on first paint) never crashes the render (mirrors ProductForm.vue).
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

function fmtDateTime(v: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

function statusColor(s: string): 'success' | 'info' | 'warning' | 'error' | 'neutral' {
  switch (s) {
    case 'fulfilled':
      return 'success'
    case 'paid':
      return 'info'
    case 'pending_payment':
      return 'warning'
    case 'canceled':
      return 'error'
    case 'refunded':
      return 'neutral'
    default:
      return 'neutral'
  }
}

// Task 13c: `commerce_refunds.status` is `'pending' | 'completed' | 'failed'` — see
// CommerceRefund's docblock in commerceOrders.ts.
function refundStatusColor(s: string): 'success' | 'warning' | 'error' | 'neutral' {
  switch (s) {
    case 'completed':
      return 'success'
    case 'pending':
      return 'warning'
    case 'failed':
      return 'error'
    default:
      return 'neutral'
  }
}

const lineColumns = computed<TableColumn<CommerceOrderLine>[]>(() => [
  { accessorKey: 'product_name', header: 'Product' },
  { accessorKey: 'sku', header: 'SKU' },
  { accessorKey: 'quantity', header: 'Qty' },
  { accessorKey: 'unit_price', header: 'Unit price' },
  { accessorKey: 'line_total', header: 'Line total' },
])

// ── Address display ──────────────────────────────────────────────────────────
// `addresses.shipping`/`addresses.billing` are a deliberately loose shape — the backend accepts
// several aliases per field (see commerceOrders.ts's CommerceOrderAddress docblock). This mirrors
// the SAME alias groups as the backend's own SellerOrderService::shippingAddressProjection() so
// admin display stays consistent with the seller-facing surface, without fabricating a fixed
// schema the API doesn't actually guarantee.
function addressField(address: CommerceOrderAddress, keys: string[]): string | null {
  for (const key of keys) {
    const value = address[key]
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
      const trimmed = String(value).trim()
      if (trimmed !== '') return trimmed
    }
  }
  return null
}

function assembleName(address: CommerceOrderAddress): string | null {
  const direct = addressField(address, ['name', 'full_name', 'recipient_name', 'recipient'])
  if (direct !== null) return direct
  const first = addressField(address, ['first_name', 'given_name'])
  const last = addressField(address, ['last_name', 'family_name', 'surname'])
  const combined = `${first ?? ''} ${last ?? ''}`.trim()
  return combined === '' ? null : combined
}

interface DisplayAddress {
  name: string | null
  company: string | null
  line1: string | null
  line2: string | null
  cityLine: string | null
  country: string | null
  phone: string | null
}

function projectAddress(address: CommerceOrderAddress): DisplayAddress {
  const city = addressField(address, ['city'])
  const region = addressField(address, ['region', 'state', 'province'])
  const postcode = addressField(address, ['postcode', 'postal_code', 'zip', 'zip_code'])
  const cityLine = [city, region, postcode].filter((v): v is string => v !== null).join(', ')
  return {
    name: assembleName(address),
    company: addressField(address, ['company']),
    line1: addressField(address, ['line1', 'address1', 'street1', 'line_1']),
    line2: addressField(address, ['line2', 'address2', 'street2', 'line_2']),
    cityLine: cityLine === '' ? null : cityLine,
    country: addressField(address, ['country']),
    phone: addressField(address, ['phone', 'telephone']),
  }
}

// The ONE function producing address text — both what's displayed (split on '\n', one <p> per
// line) and what CopyButton copies verbatim. Never JSON: a plain human-readable block, so what a
// user pastes elsewhere reads exactly like what they saw on screen.
function formatAddress(address: DisplayAddress): string {
  return [
    address.name,
    address.company,
    address.line1,
    address.line2,
    address.cityLine,
    address.country,
    address.phone,
  ]
    .filter((v): v is string => v !== null)
    .join('\n')
}

const shippingDisplay = computed(() => {
  const raw = order.value?.addresses?.shipping
  return raw ? projectAddress(raw) : null
})
const billingDisplay = computed(() => {
  const raw = order.value?.addresses?.billing
  return raw ? projectAddress(raw) : null
})
</script>

<template>
  <UDashboardPanel id="commerce-order-detail">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/commerce/orders"
            aria-label="Back to orders"
          />
        </template>
        <template #title>{{ order?.order_number ?? 'Order' }}</template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="order-detail-loading">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>

      <UAlert
        v-else-if="status === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load this order"
        description="Something went wrong loading the order. Try again."
        data-test="order-detail-error"
      />

      <div v-else-if="order" class="flex flex-col gap-6 xl:flex-row xl:items-start xl:gap-8">
        <div class="flex min-w-0 flex-1 flex-col gap-6">
          <!-- Header band (spec §2.5): identity + badges + placed date + customer + grand total,
               the print link, the canonical action group, and the overflow (cancel + invoice
               data). This is the ONE place `OrderActions`/`OrderCancelDialog` are instantiated. -->
          <div class="flex flex-col gap-4 rounded-lg border border-default p-4" data-test="order-header-band">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div class="flex flex-col gap-2">
                <div class="flex items-center gap-1">
                  <h2 class="text-lg font-semibold text-default" data-test="order-header-number">
                    {{ order.order_number }}
                  </h2>
                  <CopyButton :value="order.order_number" label="Copy order number" data-test="order-number-copy" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <UBadge :color="statusColor(order.status)" variant="subtle" data-test="order-detail-status">
                    {{ order.status }}
                  </UBadge>
                  <UBadge color="neutral" variant="subtle" data-test="order-detail-fulfillment">
                    {{ order.fulfillment_status }}
                  </UBadge>
                  <!-- Task 14: walk-in orders carry their own fulfillment MODE (distinct from
                       fulfillment STATUS above) — Task 15's Complete-sale gating consumes this
                       same field, so it's surfaced here rather than left invisible. -->
                  <UBadge color="neutral" variant="subtle" data-test="order-detail-fulfillment-mode">
                    {{ order.fulfillment_mode }}
                  </UBadge>
                  <span class="text-sm text-muted" data-test="order-header-placed">
                    Placed {{ fmtDateTime(order.placed_at) }}
                  </span>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                  <!-- Nullable email (Task 14, admin-order-creation): a walk-in order may have no
                       email at all (Ruling 4 — never a fabricated placeholder). "Walk-in customer"
                       renders instead, and the copy control is omitted entirely rather than
                       offering to copy nothing. -->
                  <span
                    data-test="order-customer-email"
                    :class="{ 'italic text-muted': !order.email }"
                  >
                    {{ order.email ?? 'Walk-in customer' }}
                  </span>
                  <CopyButton
                    v-if="order.email"
                    :value="order.email"
                    label="Copy customer email"
                    data-test="order-email-copy"
                  />
                  <UBadge color="neutral" variant="subtle" size="sm" data-test="order-customer-type">
                    {{ order.user_uuid ? 'Registered customer' : 'Guest checkout' }}
                  </UBadge>
                </div>
              </div>

              <div class="flex flex-col items-end gap-1">
                <span class="text-xs uppercase text-muted">Grand total</span>
                <span class="text-xl font-semibold text-default" data-test="order-header-grand-total">
                  {{ money(order.grand_total) }}
                </span>
              </div>
            </div>

            <div id="order-actions" class="flex flex-wrap items-center gap-2 scroll-mt-20">
              <RouterLink
                :to="invoiceHref"
                target="_blank"
                rel="noopener"
                data-test="order-print-link"
                class="inline-flex items-center gap-1.5 rounded-md border border-default px-2.5 py-1.5 text-sm font-medium text-default hover:bg-elevated"
              >
                <UIcon name="i-lucide-printer" class="size-4" />
                Print
              </RouterLink>

              <!-- Lifecycle actions (Task 13b): mark-paid / fulfill / refund — renders nothing
                   when can_manage is false or the current status has no legal action. -->
              <OrderActions :order="order" :can-manage="canManage" />

              <UDropdownMenu :items="overflowItems" :portal="false" :content="{ align: 'end' }">
                <UButton
                  icon="i-lucide-ellipsis-vertical"
                  variant="ghost"
                  color="neutral"
                  aria-label="More actions"
                  data-test="order-overflow-trigger"
                />
              </UDropdownMenu>
            </div>
          </div>

          <!-- Line items -->
          <UCard id="section-items" :ui="{ body: 'p-0' }">
            <template #header>
              <h3 class="text-sm font-medium">Items</h3>
            </template>
            <UTable :data="order.lines" :columns="lineColumns" :ui="{ td: 'align-middle' }">
              <template #product_name-cell="{ row }">
                <span data-test="order-line-row" class="font-medium text-default">
                  {{ row.original.product_name }}
                </span>
              </template>
              <template #sku-cell="{ row }">
                <span class="text-sm text-muted">{{ row.original.sku }}</span>
              </template>
              <template #unit_price-cell="{ row }">
                <span>{{ money(row.original.unit_price) }}</span>
              </template>
              <template #line_total-cell="{ row }">
                <span>{{ money(row.original.line_total) }}</span>
              </template>
            </UTable>
          </UCard>

          <!-- Totals -->
          <UCard id="section-totals">
            <template #header>
              <h3 class="text-sm font-medium">Totals</h3>
            </template>
            <dl class="grid grid-cols-2 gap-y-2 text-sm sm:max-w-xs">
              <dt class="text-muted">Subtotal</dt>
              <dd class="text-right" data-test="order-total-subtotal">{{ money(order.subtotal) }}</dd>
              <dt class="text-muted">Discount</dt>
              <dd class="text-right" data-test="order-total-discount">{{ money(order.discount_total) }}</dd>
              <dt class="text-muted">Shipping</dt>
              <dd class="text-right" data-test="order-total-shipping">{{ money(order.shipping_total) }}</dd>
              <dt class="text-muted">Tax</dt>
              <dd class="text-right" data-test="order-total-tax">{{ money(order.tax_total) }}</dd>
              <dt class="text-muted">Refunded</dt>
              <dd class="text-right" data-test="order-total-refunded">{{ money(order.refunded_total) }}</dd>
              <dt class="font-medium text-default">Grand total</dt>
              <dd class="text-right font-medium text-default" data-test="order-total-grand">
                {{ money(order.grand_total) }}
              </dd>
            </dl>
          </UCard>

          <!-- Payments (Task 5 / Task 9): gateway payment/attempt history + the order-level
               refunded-total aggregate. Self-querying — see OrderPaymentCard.vue. -->
          <div id="section-payments">
            <OrderPaymentCard :order-uuid="uuid" />
          </div>

          <!-- Refunds (Task 13c) — per-order GET, amounts via useMoney. A completed refund appears
               in the status timeline below too (RefundService::applyCompletion() records a
               `refund.completed`/`refund.failed` order event), reflected after the mutation's
               invalidation-triggered refetch of both this list and the order detail itself. -->
          <UCard :ui="{ body: refunds && refunds.length > 0 ? 'p-0' : undefined }">
            <template #header>
              <h3 class="text-sm font-medium">Refunds</h3>
            </template>
            <div
              v-if="refundsStatus === 'pending'"
              class="flex justify-center py-6"
              data-test="refunds-loading"
            >
              <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
            </div>
            <UAlert
              v-else-if="refundsStatus === 'error'"
              color="error"
              variant="subtle"
              icon="i-lucide-triangle-alert"
              title="Couldn’t load refunds"
              description="Something went wrong loading this order's refunds. Try again."
              data-test="refunds-error"
            />
            <UEmpty
              v-else-if="!refunds || refunds.length === 0"
              icon="i-lucide-undo-2"
              title="No refunds yet"
              data-test="refunds-empty"
            />
            <ul v-else class="flex flex-col divide-y divide-default">
              <li
                v-for="r in refunds"
                :key="r.uuid"
                data-test="refund-row"
                class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
              >
                <div class="flex items-center gap-2">
                  <span class="font-medium text-default" data-test="refund-amount">{{ money(r.amount) }}</span>
                  <UBadge :color="refundStatusColor(r.status)" variant="subtle" size="sm" data-test="refund-status">
                    {{ r.status }}
                  </UBadge>
                  <UBadge v-if="r.restocked" color="neutral" variant="subtle" size="sm">Restocked</UBadge>
                </div>
                <div class="flex items-center gap-3 text-muted">
                  <span v-if="r.reason" data-test="refund-reason">{{ r.reason }}</span>
                  <span>{{ fmtDateTime(r.completed_at ?? r.created_at) }}</span>
                </div>
              </li>
            </ul>
          </UCard>

          <!-- Addresses: side by side >= lg, stacked below. -->
          <UCard id="section-addresses">
            <template #header>
              <h3 class="text-sm font-medium">Addresses</h3>
            </template>
            <UEmpty
              v-if="!shippingDisplay && !billingDisplay"
              icon="i-lucide-map-pin-off"
              title="No address on file"
              data-test="order-addresses-empty"
            />
            <div v-else class="grid gap-4 lg:grid-cols-2">
              <div v-if="shippingDisplay" data-test="order-address-shipping" class="text-sm">
                <div class="mb-1 flex items-center gap-1">
                  <h4 class="text-xs font-medium uppercase text-muted">Shipping</h4>
                  <CopyButton
                    :value="formatAddress(shippingDisplay)"
                    label="Copy shipping address"
                    data-test="order-address-shipping-copy"
                  />
                </div>
                <p v-if="shippingDisplay.name">{{ shippingDisplay.name }}</p>
                <p v-if="shippingDisplay.company">{{ shippingDisplay.company }}</p>
                <p v-if="shippingDisplay.line1">{{ shippingDisplay.line1 }}</p>
                <p v-if="shippingDisplay.line2">{{ shippingDisplay.line2 }}</p>
                <p v-if="shippingDisplay.cityLine">{{ shippingDisplay.cityLine }}</p>
                <p v-if="shippingDisplay.country">{{ shippingDisplay.country }}</p>
                <p v-if="shippingDisplay.phone">{{ shippingDisplay.phone }}</p>
              </div>
              <div v-if="billingDisplay" data-test="order-address-billing" class="text-sm">
                <div class="mb-1 flex items-center gap-1">
                  <h4 class="text-xs font-medium uppercase text-muted">Billing</h4>
                  <CopyButton
                    :value="formatAddress(billingDisplay)"
                    label="Copy billing address"
                    data-test="order-address-billing-copy"
                  />
                </div>
                <p v-if="billingDisplay.name">{{ billingDisplay.name }}</p>
                <p v-if="billingDisplay.company">{{ billingDisplay.company }}</p>
                <p v-if="billingDisplay.line1">{{ billingDisplay.line1 }}</p>
                <p v-if="billingDisplay.line2">{{ billingDisplay.line2 }}</p>
                <p v-if="billingDisplay.cityLine">{{ billingDisplay.cityLine }}</p>
                <p v-if="billingDisplay.country">{{ billingDisplay.country }}</p>
                <p v-if="billingDisplay.phone">{{ billingDisplay.phone }}</p>
              </div>
            </div>
          </UCard>

          <!-- Status timeline + Notes: BELOW every commercial block (spec §2.5). -->
          <UCard id="section-timeline">
            <template #header>
              <h3 class="text-sm font-medium">Status timeline</h3>
            </template>
            <UEmpty
              v-if="order.events.length === 0"
              icon="i-lucide-history"
              title="No history yet"
              data-test="order-events-empty"
            />
            <ul v-else class="flex flex-col gap-2">
              <li
                v-for="event in order.events"
                :key="event.uuid"
                data-test="order-event-row"
                class="flex items-center justify-between gap-2 text-sm"
              >
                <span class="font-medium text-default">{{ event.type }}</span>
                <span class="text-muted">{{ fmtDateTime(event.created_at) }}</span>
              </li>
            </ul>
          </UCard>

          <!-- Notes (Task 13d): list is view-graded (always visible); the add-note form is
               manage-graded, hidden for a view-only user inside OrderNotes itself. -->
          <div id="section-notes">
            <OrderNotes :order-uuid="uuid" :can-manage="canManage" />
          </div>
        </div>

        <OrderStickyRail :order="order" :money-grand-total="money(order.grand_total)" />
      </div>

      <!-- Cancel (Task 9): the ONE cancel dialog/mutation owner, opened only from the header's
           overflow menu above. -->
      <OrderCancelDialog v-if="order" :order="order" v-model:open="cancelDialogOpen" />

      <!-- Invoice data (Task 13d): view-graded read-only modal, fetched lazily once opened. Lives
           inside the SAME #body slot as everything above -- UDashboardPanel's default slot has a
           fallback that renders #header/#body/#footer itself, but that fallback is skipped
           entirely once the caller supplies ANY default-slot content, so this modal must stay
           nested inside a named slot, never a bare sibling of <UDashboardPanel>. -->
      <UModal v-model:open="invoiceOpen" title="Invoice data" :ui="{ content: 'sm:max-w-2xl' }">
      <template #body>
        <div data-test="order-invoice-modal" class="flex flex-col gap-4 text-sm">
          <div
            v-if="invoiceStatus === 'pending'"
            class="flex justify-center py-6"
            data-test="order-invoice-loading"
          >
            <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
          </div>
          <UAlert
            v-else-if="invoiceStatus === 'error'"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Couldn’t load invoice data"
            description="Something went wrong loading this order's invoice data. Try again."
            data-test="order-invoice-error"
          />
          <template v-else-if="invoice">
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <h4 class="mb-1 text-xs font-medium uppercase text-muted">Seller</h4>
                <p data-test="order-invoice-seller-name">{{ invoice.seller.name ?? '—' }}</p>
                <p v-if="invoice.seller.address" class="text-muted">{{ invoice.seller.address }}</p>
                <p v-if="invoice.seller.tax_id" class="text-muted">{{ invoice.seller.tax_id }}</p>
              </div>
              <div>
                <h4 class="mb-1 text-xs font-medium uppercase text-muted">Order</h4>
                <p>{{ invoice.order.number ?? '—' }} · {{ invoice.order.status ?? '—' }}</p>
                <p class="text-muted">{{ invoice.buyer.email ?? '—' }}</p>
                <p class="text-muted">{{ fmtDateTime(invoice.order.dates.placed_at ?? invoice.order.dates.created_at) }}</p>
              </div>
            </div>

            <UTable
              :data="invoice.lines"
              :columns="[
                { accessorKey: 'name', header: 'Product' },
                { accessorKey: 'sku', header: 'SKU' },
                { accessorKey: 'quantity', header: 'Qty' },
                { accessorKey: 'unit_minor', header: 'Unit price' },
                { accessorKey: 'subtotal_minor', header: 'Subtotal' },
              ]"
              :ui="{ td: 'align-middle' }"
            >
              <template #name-cell="{ row }">
                <span data-test="order-invoice-line" class="font-medium text-default">
                  {{ row.original.name }} ({{ row.original.sku }}, {{ money(row.original.unit_minor) }},
                  {{ money(row.original.subtotal_minor) }})
                </span>
              </template>
              <template #unit_minor-cell="{ row }">{{ money(row.original.unit_minor) }}</template>
              <template #subtotal_minor-cell="{ row }">{{ money(row.original.subtotal_minor) }}</template>
            </UTable>

            <dl class="grid grid-cols-2 gap-y-2 sm:max-w-xs">
              <dt class="text-muted">Subtotal</dt>
              <dd class="text-right" data-test="order-invoice-total-subtotal">{{ money(invoice.totals.subtotal_minor) }}</dd>
              <dt class="text-muted">Discount</dt>
              <dd class="text-right" data-test="order-invoice-total-discount">{{ money(invoice.totals.discount_minor) }}</dd>
              <dt class="text-muted">Shipping</dt>
              <dd class="text-right" data-test="order-invoice-total-shipping">{{ money(invoice.totals.shipping_minor) }}</dd>
              <dt class="text-muted">Tax</dt>
              <dd class="text-right" data-test="order-invoice-total-tax">{{ money(invoice.totals.tax_minor) }}</dd>
              <dt class="text-muted">Refunded</dt>
              <dd class="text-right" data-test="order-invoice-total-refunded">{{ money(invoice.totals.refunded_minor) }}</dd>
              <dt class="font-medium text-default">Grand total</dt>
              <dd class="text-right font-medium text-default" data-test="order-invoice-total-grand">
                {{ money(invoice.totals.grand_minor) }}
              </dd>
            </dl>

            <div v-if="invoice.refunds.length > 0">
              <h4 class="mb-1 text-xs font-medium uppercase text-muted">Refunds</h4>
              <ul class="flex flex-col gap-1">
                <li
                  v-for="(r, i) in invoice.refunds"
                  :key="i"
                  data-test="order-invoice-refund"
                  class="flex items-center justify-between gap-2"
                >
                  <span>{{ money(r.amount_minor) }} — {{ r.method }}</span>
                  <span class="text-muted">{{ fmtDateTime(r.date) }}</span>
                </li>
              </ul>
            </div>
          </template>
        </div>
      </template>
    </UModal>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
