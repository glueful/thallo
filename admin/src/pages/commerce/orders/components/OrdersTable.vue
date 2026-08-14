<script setup lang="ts">
import { computed } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { useMoney } from '@/composables/useMoney'
import type { CommerceOrder } from '@/queries/commerceOrders'

const props = defineProps<{
  rows: CommerceOrder[]
  status: 'pending' | 'error' | 'success' | 'idle'
}>()

const { format } = useMoney()

const columns = computed<TableColumn<CommerceOrder>[]>(() => [
  { accessorKey: 'order_number', header: 'Order' },
  { accessorKey: 'email', header: 'Customer' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'fulfillment_status', header: 'Fulfillment' },
  { accessorKey: 'grand_total', header: 'Total' },
  { accessorKey: 'placed_at', header: 'Date' },
  { id: 'actions', header: 'Actions' },
])

// The order-detail page's own Print link composes the SAME `/commerce/orders/{uuid}/invoice`
// path (orders/[uuid]/index.vue's `invoiceHref`, mirrored verbatim by OrderStickyRail.vue) — kept
// as an equally trivial one-line local composition here rather than a shared import, mirroring
// this codebase's established per-file small-helper convention (see OrderStickyRail.vue's own
// note on `statusColor`). Never diverges from the other two: always `uuid` + `/invoice`, no
// alternate field. There is currently no print-availability gating by order status anywhere in
// the codebase — the detail page's Print link renders unconditionally for every order — so this
// action is likewise always enabled.
function invoiceHref(row: CommerceOrder): string {
  return `/commerce/orders/${row.uuid}/invoice`
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

function fulfillmentColor(s: string): 'success' | 'info' | 'warning' | 'neutral' {
  switch (s) {
    case 'fulfilled':
      return 'success'
    case 'partial':
      return 'info'
    case 'unfulfilled':
      return 'warning'
    default:
      return 'neutral'
  }
}

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// (still pending on first paint) never crashes the table render (mirrors ProductForm.vue).
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
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="orders-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load orders"
    description="Something went wrong loading orders. Try again."
    data-test="orders-error"
  />

  <UEmpty
    v-else-if="rows.length === 0"
    icon="i-lucide-receipt"
    title="No orders"
    description="Orders will appear here once customers start checking out."
    data-test="orders-empty"
  />

  <UTable v-else :data="rows" :columns="columns" :ui="{ td: 'align-middle' }">
    <template #order_number-cell="{ row }">
      <RouterLink
        :to="`/commerce/orders/${row.original.uuid}`"
        class="font-medium text-default hover:underline"
        data-test="order-row"
      >
        {{ row.original.order_number }}
      </RouterLink>
    </template>

    <template #email-cell="{ row }">
      <span class="text-sm" :class="{ 'text-muted italic': !row.original.email }">
        {{ row.original.email ?? 'Walk-in customer' }}
      </span>
    </template>

    <template #status-cell="{ row }">
      <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm" data-test="order-status">
        {{ row.original.status }}
      </UBadge>
    </template>

    <template #fulfillment_status-cell="{ row }">
      <UBadge
        :color="fulfillmentColor(row.original.fulfillment_status)"
        variant="subtle"
        size="sm"
        data-test="order-fulfillment"
      >
        {{ row.original.fulfillment_status }}
      </UBadge>
    </template>

    <template #grand_total-cell="{ row }">
      <span data-test="order-total">{{ money(row.original.grand_total) }}</span>
    </template>

    <template #placed_at-cell="{ row }">
      <span class="text-sm text-muted" data-test="order-date">
        {{ fmtDate(row.original.placed_at ?? row.original.created_at) }}
      </span>
    </template>

    <template #actions-cell="{ row }">
      <!-- Icon-only links, aria-labeled with a native `title` tooltip — a plain `<RouterLink>`
           styled as a compact ghost icon button, mirroring the order-number cell's own RouterLink
           (above) and the detail page's Print link (orders/[uuid]/index.vue's `order-print-link`)
           rather than `UButton`'s `to` prop: `UButton` resolves its link through Nuxt UI's `Link`
           wrapper, which only produces a real `href` via the REAL `RouterLink`'s scoped-slot data —
           this codebase's established `RouterLinkStub` (a plain non-scoped stub, used everywhere
           these tables are unit-tested) can't supply that, so a `UButton :to=...` renders hrefless
           in every existing spec. A direct `<RouterLink>` needs no scoped-slot data and stays
           testable exactly like the order-number cell already is. -->
      <div class="flex items-center justify-end gap-1">
        <RouterLink
          :to="`/commerce/orders/${row.original.uuid}`"
          aria-label="View order details"
          title="View order details"
          data-test="order-row-view"
          class="inline-flex size-7 items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-default"
        >
          <UIcon name="i-lucide-eye" class="size-4" />
        </RouterLink>
        <RouterLink
          :to="invoiceHref(row.original)"
          target="_blank"
          rel="noopener"
          aria-label="Print receipt"
          title="Print receipt"
          data-test="order-row-print"
          class="inline-flex size-7 items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-default"
        >
          <UIcon name="i-lucide-printer" class="size-4" />
        </RouterLink>
      </div>
    </template>
  </UTable>
</template>
