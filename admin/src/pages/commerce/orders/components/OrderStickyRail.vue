<script setup lang="ts">
// Orders-invoices-receipts plan, Task 9: a `>= xl` sticky navigation rail alongside the order
// detail's main column. Pure navigation/identity — it renders NO commercial data of its own
// (no line items, no payment rows, no address blocks) and owns NO mutation state: it links back
// to the canonical header action group (mark-paid/fulfill/refund + the cancel/invoice-data
// overflow) rather than instantiating a second `OrderActions`/`OrderCancelDialog`/mutation.
import { computed } from 'vue'
import type { CommerceOrder } from '@/queries/commerceOrders'

const props = defineProps<{
  order: CommerceOrder
  moneyGrandTotal: string
}>()

const invoiceHref = computed(() => `/commerce/orders/${props.order.uuid}/invoice`)

// Same closed-vocabulary color mapping OrderDetail's own header band uses — duplicated locally
// (mirrors this codebase's established per-file small-helper convention, e.g. OrderNotes' own
// `fmtDateTime`) rather than importing a shared function for five lines of logic.
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

const sections: { href: string; label: string }[] = [
  { href: '#section-items', label: 'Items' },
  { href: '#section-totals', label: 'Totals' },
  { href: '#section-payments', label: 'Payments' },
  { href: '#section-addresses', label: 'Addresses' },
  { href: '#section-timeline', label: 'Status timeline' },
  { href: '#section-notes', label: 'Notes' },
]
</script>

<template>
  <aside
    class="hidden xl:sticky xl:top-4 xl:block xl:h-fit xl:w-64 xl:flex-none"
    data-test="order-sticky-rail"
  >
    <div class="flex flex-col gap-4 rounded-lg border border-default p-4 text-sm">
      <div class="flex flex-col gap-1">
        <!-- Never a blank line where the identifier belongs: a never-completed row genuinely has
             no number (see `CommerceOrder.order_number`) and says so. -->
        <span class="font-medium text-default" data-test="order-sticky-number">
          {{ order.order_number ?? 'No order number' }}
        </span>
        <UBadge :color="statusColor(order.status)" variant="subtle" class="w-fit" data-test="order-sticky-status">
          {{ order.status }}
        </UBadge>
        <span class="text-base font-semibold text-default" data-test="order-sticky-total">
          {{ moneyGrandTotal }}
        </span>
      </div>

      <RouterLink
        :to="invoiceHref"
        target="_blank"
        rel="noopener"
        data-test="order-sticky-print"
        class="inline-flex items-center gap-1.5 rounded-md border border-default px-2.5 py-1.5 text-sm font-medium text-default hover:bg-elevated"
      >
        <UIcon name="i-lucide-printer" class="size-4" />
        Print
      </RouterLink>

      <nav class="flex flex-col gap-1" aria-label="Order sections">
        <a
          href="#order-actions"
          data-test="order-sticky-actions-anchor"
          class="rounded px-1.5 py-1 font-medium text-default hover:bg-elevated"
        >
          Actions
        </a>
        <a
          v-for="section in sections"
          :key="section.href"
          :href="section.href"
          data-test="order-sticky-anchor"
          class="rounded px-1.5 py-1 text-muted hover:bg-elevated"
        >
          {{ section.label }}
        </a>
      </nav>
    </div>
  </aside>
</template>
