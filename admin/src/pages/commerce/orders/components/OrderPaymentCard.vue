<script setup lang="ts">
// Orders-invoices-receipts plan, Task 9: the order detail's payment summary, reading Task 5's
// `GET /orders/{uuid}/payments` invariant envelope. Self-querying (mirrors OrderNotes.vue's own
// `useOrderNotes(() => props.orderUuid)` — a feature card owns its own read, the parent just
// supplies the order uuid).
//
// Five render cases, all driven by the SAME two independent checks (never an if/else-if chain
// that lets one array's presence hide the other):
//   1. unavailable   — `available === false`: Payvia's own tables aren't migrated.
//   2. empty         — available, but both `payments` AND `intents` are empty.
//   3. records       — `payments` has rows (intents may or may not).
//   4. attempts-only — `intents` has rows, `payments` is empty.
//   5. both          — payments AND intents both non-empty: BOTH sections render side by side,
//                      proving classification never hides data.
// `refund` is echoed from the order row, not Payvia — always shown as an ORDER-LEVEL aggregate,
// distinct from (and never a substitute for) the per-gateway rows above it.
import { computed, watch } from 'vue'
import { useOrderPayments } from '@/queries/commerceOrders'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useNotify } from '@/composables/useNotify'
import { formatMoney } from '@/composables/useMoney'
import CopyButton from '@/components/CopyButton.vue'

const props = defineProps<{
  orderUuid: string
}>()

const { data, status } = useOrderPayments(() => props.orderUuid)
const { data: meta } = useCommerceMeta()
const { error: notifyError } = useNotify()

// Query error ⇒ card error state + toast — never mistaken for the (legitimate) empty state.
// `immediate: true` so a query that's ALREADY errored by the time this card mounts (the common
// case in tests, and possible in prod if the query settles before this component is created)
// still toasts — a plain `watch()` only fires on a subsequent change, which would silently miss it.
watch(
  status,
  (s) => {
    if (s === 'error') {
      notifyError(new Error("Couldn't load this order's payment details."), "Couldn't load payments")
    }
  },
  { immediate: true },
)

const exponent = computed(() => meta.value?.currency_exponent ?? 2)

// useMoney().format() assumes the ORDER's own currency; a payment/intent row carries its own
// `currency` (almost always the same, but never assumed) — format each row against its own code,
// borrowing only the exponent from /commerce/meta since Payvia doesn't echo one per row.
function money(minor: number, currency: string): string {
  try {
    return formatMoney(minor, { currency, currency_exponent: exponent.value })
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

const hasPayments = computed(() => (data.value?.payments.length ?? 0) > 0)
const hasIntents = computed(() => (data.value?.intents.length ?? 0) > 0)
const isEmpty = computed(
  () => (data.value?.available ?? false) && !hasPayments.value && !hasIntents.value,
)
</script>

<template>
  <UCard>
    <template #header>
      <h3 class="text-sm font-medium">Payments</h3>
    </template>

    <div v-if="status === 'pending'" class="flex justify-center py-6" data-test="order-payments-loading">
      <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
    </div>

    <UAlert
      v-else-if="status === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn’t load payments"
      description="Something went wrong loading this order's payment details. Try again."
      data-test="order-payments-error"
    />

    <template v-else-if="data">
      <UEmpty
        v-if="!data.available"
        icon="i-lucide-credit-card"
        title="Payment details aren’t available"
        description="This tenant hasn’t set up payment processing."
        data-test="order-payments-unavailable"
      />

      <UEmpty
        v-else-if="isEmpty"
        icon="i-lucide-credit-card"
        title="No payments or attempts"
        data-test="order-payments-empty"
      />

      <div v-else class="flex flex-col gap-4">
        <div v-if="hasPayments" data-test="order-payments-section">
          <h4 class="mb-1 text-xs font-medium uppercase text-muted">Payments</h4>
          <ul class="flex flex-col divide-y divide-default">
            <li
              v-for="(p, i) in data.payments"
              :key="i"
              data-test="order-payment-row"
              class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
            >
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-default" data-test="order-payment-amount">
                  {{ money(p.amount, p.currency) }}
                </span>
                <UBadge color="neutral" variant="subtle" size="sm">{{ p.gateway }}</UBadge>
                <UBadge color="neutral" variant="subtle" size="sm" data-test="order-payment-status">
                  {{ p.status }}
                </UBadge>
                <span class="flex items-center gap-1 text-muted">
                  <span data-test="order-payment-reference">{{ p.reference }}</span>
                  <CopyButton :value="p.reference" label="Copy payment reference" data-test="order-payment-reference-copy" />
                </span>
                <span v-if="p.gateway_transaction_id" class="flex items-center gap-1 text-muted">
                  <span data-test="order-payment-txn">{{ p.gateway_transaction_id }}</span>
                  <CopyButton
                    :value="p.gateway_transaction_id"
                    label="Copy gateway transaction id"
                    data-test="order-payment-txn-copy"
                  />
                </span>
              </div>
              <span class="text-muted">{{ fmtDateTime(p.created_at) }}</span>
            </li>
          </ul>
        </div>

        <div v-if="hasIntents" data-test="order-payment-attempts-section">
          <h4 class="mb-1 text-xs font-medium uppercase text-muted">Payment attempts</h4>
          <ul class="flex flex-col divide-y divide-default">
            <li
              v-for="(intent, i) in data.intents"
              :key="i"
              data-test="order-payment-intent-row"
              class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
            >
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-default">{{ money(intent.amount, intent.currency) }}</span>
                <UBadge color="neutral" variant="subtle" size="sm">{{ intent.gateway }}</UBadge>
                <UBadge color="neutral" variant="subtle" size="sm" data-test="order-payment-intent-status">
                  {{ intent.status }}
                </UBadge>
                <span class="flex items-center gap-1 text-muted">
                  <span data-test="order-payment-intent-reference">{{ intent.reference }}</span>
                  <CopyButton
                    :value="intent.reference"
                    label="Copy payment attempt reference"
                    data-test="order-payment-intent-reference-copy"
                  />
                </span>
              </div>
              <span class="text-muted">{{ fmtDateTime(intent.created_at) }}</span>
            </li>
          </ul>
        </div>
      </div>

      <!-- Order-level aggregate — a `commerce_orders.refunded_total` echo, not a Payvia concept,
           shown regardless of the classification above so it's never confused with a gateway row. -->
      <p class="mt-4 border-t border-default pt-3 text-sm" data-test="order-payments-refunded-total">
        Refunded (order total): <span class="font-medium text-default">{{ money(data.refund.refunded_total, meta?.currency ?? '') }}</span>
      </p>
    </template>
  </UCard>
</template>
