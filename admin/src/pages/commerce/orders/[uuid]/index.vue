<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import {
  useCommerceOrder,
  type CommerceOrderAddress,
  type CommerceOrderLine,
} from '@/queries/commerceOrders'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useMoney } from '@/composables/useMoney'
import OrderActions from '../components/OrderActions.vue'

const route = useRoute()
const uuid = computed(() => String(route.params.uuid))

const { data: order, status } = useCommerceOrder(uuid)
const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)
const { format } = useMoney()

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
        <template #right>
          <UBadge
            v-if="order"
            :color="statusColor(order.status)"
            variant="subtle"
            data-test="order-detail-status"
          >
            {{ order.status }}
          </UBadge>
        </template>
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

      <div v-else-if="order" class="flex flex-col gap-6">
        <!-- Lifecycle actions (Task 13b): cancel / mark-paid / fulfill — renders nothing when
             can_manage is false or the current status has no legal action. -->
        <OrderActions :order="order" :can-manage="canManage" />

        <!-- Customer -->
        <UCard>
          <template #header>
            <h3 class="text-sm font-medium">Customer</h3>
          </template>
          <div class="flex flex-wrap items-center gap-2">
            <span data-test="order-customer-email">{{ order.email }}</span>
            <UBadge color="neutral" variant="subtle" size="sm" data-test="order-customer-type">
              {{ order.user_uuid ? 'Registered customer' : 'Guest checkout' }}
            </UBadge>
          </div>
        </UCard>

        <!-- Line items -->
        <UCard :ui="{ body: 'p-0' }">
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
        <UCard>
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

        <!-- Addresses -->
        <UCard>
          <template #header>
            <h3 class="text-sm font-medium">Addresses</h3>
          </template>
          <UEmpty
            v-if="!shippingDisplay && !billingDisplay"
            icon="i-lucide-map-pin-off"
            title="No address on file"
            data-test="order-addresses-empty"
          />
          <div v-else class="grid gap-4 sm:grid-cols-2">
            <div v-if="shippingDisplay" data-test="order-address-shipping" class="text-sm">
              <h4 class="mb-1 text-xs font-medium uppercase text-muted">Shipping</h4>
              <p v-if="shippingDisplay.name">{{ shippingDisplay.name }}</p>
              <p v-if="shippingDisplay.company">{{ shippingDisplay.company }}</p>
              <p v-if="shippingDisplay.line1">{{ shippingDisplay.line1 }}</p>
              <p v-if="shippingDisplay.line2">{{ shippingDisplay.line2 }}</p>
              <p v-if="shippingDisplay.cityLine">{{ shippingDisplay.cityLine }}</p>
              <p v-if="shippingDisplay.country">{{ shippingDisplay.country }}</p>
              <p v-if="shippingDisplay.phone">{{ shippingDisplay.phone }}</p>
            </div>
            <div v-if="billingDisplay" data-test="order-address-billing" class="text-sm">
              <h4 class="mb-1 text-xs font-medium uppercase text-muted">Billing</h4>
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

        <!-- Status timeline -->
        <UCard>
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
      </div>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
