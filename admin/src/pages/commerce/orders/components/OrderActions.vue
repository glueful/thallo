<script setup lang="ts">
// Task 13b (admin-commerce-area plan, slice 3): cancel / mark-paid / fulfill. Visibility is
// gated by BOTH `canManage` and the current status's legal-transition set (see
// canCancelOrder()/canMarkOrderPaid()/canFulfillOrder() in commerceOrders.ts, which mirror
// OrderStateMachine::ALLOWED field-for-field) — but the server's own CAS remains authoritative:
// a since-changed or otherwise illegal transition still comes back as a 409/422, surfaced inline
// via `actionError` rather than assumed away by the client-side guard.
import { computed, ref } from 'vue'
import {
  useCommerceOrderMutations,
  canCancelOrder,
  canMarkOrderPaid,
  canFulfillOrder,
  canRefundOrder,
  type CommerceOrder,
} from '@/queries/commerceOrders'
import { toApiError } from '@/api/errors'
import RefundSlideover from './RefundSlideover.vue'

const props = defineProps<{
  order: CommerceOrder
  canManage: boolean
}>()

const { cancel, markPaid, fulfill } = useCommerceOrderMutations()

const canCancel = computed(() => props.canManage && canCancelOrder(props.order.status))
const canMarkPaid = computed(() => props.canManage && canMarkOrderPaid(props.order.status))
const canFulfill = computed(() => props.canManage && canFulfillOrder(props.order.status))
const canRefund = computed(() => props.canManage && canRefundOrder(props.order.status))
const hasAnyAction = computed(() => canCancel.value || canMarkPaid.value || canFulfill.value || canRefund.value)

const refundSlideoverOpen = ref(false)

type PendingAction = 'cancel' | 'mark-paid' | 'fulfill' | null
const pendingAction = ref<PendingAction>(null)
const actionError = ref<string | null>(null)
const trackingRef = ref('')

function openConfirm(action: Exclude<PendingAction, null>) {
  pendingAction.value = action
  actionError.value = null
}

function dismissConfirm() {
  pendingAction.value = null
  actionError.value = null
  trackingRef.value = ''
}

async function confirmCancel() {
  actionError.value = null
  try {
    await cancel.mutateAsync(props.order.uuid)
    dismissConfirm()
  } catch (e) {
    actionError.value = toApiError(e).message
  }
}

async function confirmMarkPaid() {
  actionError.value = null
  try {
    await markPaid.mutateAsync(props.order.uuid)
    dismissConfirm()
  } catch (e) {
    actionError.value = toApiError(e).message
  }
}

async function confirmFulfill() {
  actionError.value = null
  try {
    const trimmed = trackingRef.value.trim()
    await fulfill.mutateAsync({
      uuid: props.order.uuid,
      input: { tracking_ref: trimmed === '' ? null : trimmed },
    })
    dismissConfirm()
  } catch (e) {
    actionError.value = toApiError(e).message
  }
}
</script>

<template>
  <div v-if="hasAnyAction" class="flex flex-col gap-3" data-test="order-actions">
    <div class="flex flex-wrap gap-2">
      <UButton
        v-if="canCancel"
        color="error"
        variant="outline"
        size="sm"
        icon="i-lucide-ban"
        data-test="order-cancel"
        @click="openConfirm('cancel')"
      >
        Cancel order
      </UButton>
      <UButton
        v-if="canMarkPaid"
        color="success"
        variant="outline"
        size="sm"
        icon="i-lucide-badge-dollar-sign"
        data-test="order-mark-paid"
        @click="openConfirm('mark-paid')"
      >
        Mark paid
      </UButton>
      <UButton
        v-if="canFulfill"
        color="primary"
        variant="outline"
        size="sm"
        icon="i-lucide-package-check"
        data-test="order-fulfill"
        @click="openConfirm('fulfill')"
      >
        Fulfill
      </UButton>
      <UButton
        v-if="canRefund"
        color="error"
        variant="outline"
        size="sm"
        icon="i-lucide-undo-2"
        data-test="order-refund"
        @click="refundSlideoverOpen = true"
      >
        Refund
      </UButton>
    </div>

    <UAlert
      v-if="actionError"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="actionError"
      data-test="order-action-error"
    />

    <div
      v-if="pendingAction === 'cancel'"
      class="rounded-md border border-error p-3 text-sm"
      data-test="order-cancel-panel"
    >
      <p>Cancel order <strong>{{ order.order_number }}</strong>? Any tracked stock will be released. This can’t be undone.</p>
      <div class="mt-2 flex gap-2">
        <UButton
          size="xs"
          color="error"
          data-test="order-cancel-confirm"
          :loading="cancel.isLoading.value"
          @click="confirmCancel"
        >
          Confirm cancel
        </UButton>
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          data-test="order-cancel-dismiss"
          @click="dismissConfirm"
        >
          Dismiss
        </UButton>
      </div>
    </div>

    <div
      v-if="pendingAction === 'mark-paid'"
      class="rounded-md border border-success p-3 text-sm"
      data-test="order-mark-paid-panel"
    >
      <p>Mark order <strong>{{ order.order_number }}</strong> as paid?</p>
      <div class="mt-2 flex gap-2">
        <UButton
          size="xs"
          color="success"
          data-test="order-mark-paid-confirm"
          :loading="markPaid.isLoading.value"
          @click="confirmMarkPaid"
        >
          Confirm mark paid
        </UButton>
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          data-test="order-mark-paid-dismiss"
          @click="dismissConfirm"
        >
          Dismiss
        </UButton>
      </div>
    </div>

    <div
      v-if="pendingAction === 'fulfill'"
      class="rounded-md border border-primary p-3 text-sm"
      data-test="order-fulfill-panel"
    >
      <p>Fulfill order <strong>{{ order.order_number }}</strong>.</p>
      <UFormField label="Tracking reference" name="tracking_ref" class="mt-2">
        <UInput
          v-model="trackingRef"
          placeholder="Optional"
          class="w-full"
          data-test="order-fulfill-tracking-ref"
        />
      </UFormField>
      <div class="mt-2 flex gap-2">
        <UButton
          size="xs"
          color="primary"
          data-test="order-fulfill-confirm"
          :loading="fulfill.isLoading.value"
          @click="confirmFulfill"
        >
          Confirm fulfill
        </UButton>
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          data-test="order-fulfill-dismiss"
          @click="dismissConfirm"
        >
          Dismiss
        </UButton>
      </div>
    </div>

    <RefundSlideover :order="order" v-model:open="refundSlideoverOpen" />
  </div>
</template>
