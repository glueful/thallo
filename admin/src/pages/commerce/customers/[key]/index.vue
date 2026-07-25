<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useCommerceCustomer, type CommerceCustomerKeyType } from '@/queries/commerceCustomers'
import { useMoney } from '@/composables/useMoney'

const route = useRoute()
const key = computed(() => String(route.params.key ?? ''))
// `by` is a REQUIRED field server-side (CustomerLookupQuery 422s a missing/invalid value) — every
// link this admin generates (CustomersTable's detailLink()) always sets it explicitly. Defaulting
// an unrecognized/missing value to 'user' here is defensive only (a hand-edited URL), never a
// state this page's own navigation produces.
const by = computed<CommerceCustomerKeyType>(() => (route.query.by === 'email' ? 'email' : 'user'))

const { data: customer, status } = useCommerceCustomer(key, by)
const { format } = useMoney()

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// (still pending on first paint) never crashes the render (mirrors OrderDetail.vue).
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

function fmtDate(v: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
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
</script>

<template>
  <UDashboardPanel id="commerce-customer-detail">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/commerce/customers"
            aria-label="Back to customers"
          />
        </template>
        <template #title>{{ customer?.username ?? customer?.email ?? 'Customer' }}</template>
        <template #right>
          <UBadge
            v-if="customer"
            :color="customer.key_type === 'user' ? 'info' : 'neutral'"
            variant="subtle"
            data-test="customer-detail-type"
          >
            {{ customer.key_type === 'user' ? 'Registered' : 'Guest' }}
          </UBadge>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="customer-detail-loading">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>

      <UAlert
        v-else-if="status === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load this customer"
        description="Something went wrong loading the customer. Try again."
        data-test="customer-detail-error"
      />

      <div v-else-if="customer" class="flex flex-col gap-6">
        <!-- Identity -->
        <UCard>
          <template #header>
            <h3 class="text-sm font-medium">Customer</h3>
          </template>
          <div class="flex flex-wrap items-center gap-2">
            <span data-test="customer-detail-email">{{ customer.email }}</span>
          </div>
        </UCard>

        <!-- Summary -->
        <UCard>
          <template #header>
            <h3 class="text-sm font-medium">Summary</h3>
          </template>
          <dl class="grid grid-cols-2 gap-y-2 text-sm sm:max-w-sm">
            <dt class="text-muted">Orders</dt>
            <dd class="text-right" data-test="customer-summary-orders">{{ customer.orders_count }}</dd>
            <dt class="text-muted">Total spent</dt>
            <dd class="text-right" data-test="customer-summary-total">{{ money(customer.total_spent_minor) }}</dd>
            <dt class="text-muted">Refunded</dt>
            <dd class="text-right" data-test="customer-summary-refunded">{{ money(customer.refunded_minor) }}</dd>
            <dt class="text-muted">First order</dt>
            <dd class="text-right" data-test="customer-summary-first-order">{{ fmtDate(customer.first_order_at) }}</dd>
            <dt class="text-muted">Last order</dt>
            <dd class="text-right" data-test="customer-summary-last-order">{{ fmtDate(customer.last_order_at) }}</dd>
          </dl>
        </UCard>

        <!-- Addresses: only present for a user-keyed (registered) customer — a guest identity has
             no address book at all (design spec §7), so this section is omitted entirely for
             by=email rather than shown with a misleading "no address on file" empty state. -->
        <UCard v-if="customer.addresses !== null">
          <template #header>
            <h3 class="text-sm font-medium">Addresses</h3>
          </template>
          <UEmpty
            v-if="customer.addresses.length === 0"
            icon="i-lucide-map-pin-off"
            title="No addresses saved"
            data-test="customer-addresses-empty"
          />
          <div v-else class="grid gap-4 sm:grid-cols-2">
            <div
              v-for="address in customer.addresses"
              :key="address.uuid"
              data-test="customer-address-row"
              class="rounded-md border border-default p-3 text-sm"
            >
              <div class="mb-1 flex flex-wrap items-center gap-1">
                <span v-if="address.label" class="font-medium text-default">{{ address.label }}</span>
                <UBadge v-if="address.is_default_shipping" color="neutral" variant="subtle" size="sm">
                  Default shipping
                </UBadge>
                <UBadge v-if="address.is_default_billing" color="neutral" variant="subtle" size="sm">
                  Default billing
                </UBadge>
              </div>
              <p v-for="(value, field) in address.address" :key="field" class="text-muted">
                {{ value }}
              </p>
            </div>
          </div>
        </UCard>

        <!-- Recent orders: attached directly by AdminCustomerController::show() (most-recent-first,
             capped at 25) — links out to the existing order detail page rather than duplicating any
             lifecycle/refund UI here (this whole surface is read-only, design spec §7/task-17). -->
        <UCard :ui="{ body: customer.orders.length > 0 ? 'p-0' : undefined }">
          <template #header>
            <h3 class="text-sm font-medium">Recent orders</h3>
          </template>
          <UEmpty
            v-if="customer.orders.length === 0"
            icon="i-lucide-receipt"
            title="No orders yet"
            data-test="customer-orders-empty"
          />
          <ul v-else class="flex flex-col divide-y divide-default">
            <li v-for="order in customer.orders" :key="order.uuid" data-test="customer-order-row">
              <RouterLink
                :to="`/commerce/orders/${order.uuid}`"
                class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm hover:bg-elevated"
              >
                <div class="flex items-center gap-2">
                  <span class="font-medium text-default">{{ order.order_number }}</span>
                  <UBadge :color="statusColor(order.status)" variant="subtle" size="sm">
                    {{ order.status }}
                  </UBadge>
                </div>
                <div class="flex items-center gap-3 text-muted">
                  <span>{{ money(order.grand_total) }}</span>
                  <span>{{ fmtDateTime(order.placed_at ?? order.created_at) }}</span>
                </div>
              </RouterLink>
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
