<script setup lang="ts">
// Orders-invoices-receipts plan, Task 9: cancellation is destructive and lives in the overflow
// menu, but the confirm step and the mutation itself stay right here — the ONE owner of both,
// controlled entirely by the parent's `open` prop (the overflow menu owns opening it; nothing
// else — not the sticky rail, not OrderActions — ever instantiates a second copy of this
// component or calls `cancel.mutateAsync` on its own).
//
// ## The risk-acknowledged second step (payment-links final review, Important 3)
//
// An order whose payment link already handed a payer a provider checkout URL cannot be canceled
// automatically: `PaymentSessionExposureGuard` 409s with
// `error.details.reason = payment_session_risk_unacknowledged` unless the request carries
// `accept_late_payment_risk: true`. That refusal is NOT a retry — it is a question — so this
// dialog switches to a second, explicit step instead of surfacing the 409 as an inline error the
// operator can only re-hit. The SERVER's refusal is what promotes the dialog; the exposure flag
// the payment-link card already reads is used only to state the risk up front, so the two-step
// flow is correct even when that query is unloaded, stale, or absent.
import { computed, ref, watch } from 'vue'
import {
  isPaymentSessionRiskRefusal,
  useCommerceOrderMutations,
  type CommerceOrder,
} from '@/queries/commerceOrders'
import { toApiError } from '@/api/errors'

const props = defineProps<{
  order: CommerceOrder
  open: boolean
  /** The order-level `exposure.requires_risk_acknowledgement` when the caller knows it. */
  sessionExposed?: boolean
}>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const { cancel } = useCommerceOrderMutations()
const error = ref<string | null>(null)
/** Set by the server's own refusal; never cleared by anything but a reopen. */
const riskRefused = ref(false)

/** Show the acknowledgement step when the server demanded it, or when we already know it will. */
const needsAcknowledgement = computed(() => riskRefused.value || props.sessionExposed === true)

// Clear any stale error/refusal from a previous open the moment this reopens.
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      error.value = null
      riskRefused.value = false
    }
  },
)

function dismiss() {
  error.value = null
  emit('update:open', false)
}

async function submit(acceptLatePaymentRisk: boolean) {
  error.value = null
  try {
    await cancel.mutateAsync({ uuid: props.order.uuid, acceptLatePaymentRisk })
    emit('update:open', false)
  } catch (e) {
    if (isPaymentSessionRiskRefusal(e)) {
      // Not an error the operator retries — a decision they have not made yet. Promote the
      // dialog to its acknowledgement step and say nothing in the error slot.
      riskRefused.value = true
      return
    }
    // Server stays authoritative (e.g. a since-changed status races this confirm into a 409) —
    // surfaced inline and the dialog stays open for retry/dismiss, never silently closed as if
    // the cancellation had gone through.
    error.value = toApiError(e).message
  }
}

/** The primary button. Carries the flag only once the risk is actually on screen. */
function confirm() {
  return submit(needsAcknowledgement.value)
}
</script>

<template>
  <UModal
    :open="open"
    title="Cancel order"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div data-test="order-cancel-dialog" class="flex flex-col gap-3 text-sm">
        <p>
          Cancel order <strong>{{ order.order_number }}</strong
          >? Any tracked stock will be released. This can’t be undone.
        </p>
        <UAlert
          v-if="needsAcknowledgement"
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="A payment may still arrive"
          data-test="order-cancel-risk"
          description="A provider checkout session was already exposed for this order, so a payment can still land after you cancel. Canceling releases the stock and accepts the risk of a late payment you would then have to refund."
        />
        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :title="error"
          data-test="order-cancel-error"
        />
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          data-test="order-cancel-dismiss"
          @click="dismiss"
        >
          Dismiss
        </UButton>
        <UButton
          color="error"
          data-test="order-cancel-confirm"
          :loading="cancel.isLoading.value"
          @click="confirm"
        >
          {{ needsAcknowledgement ? 'Cancel and accept the risk' : 'Confirm cancel' }}
        </UButton>
      </div>
    </template>
  </UModal>
</template>
