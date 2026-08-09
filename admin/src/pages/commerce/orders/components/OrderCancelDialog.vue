<script setup lang="ts">
// Orders-invoices-receipts plan, Task 9: cancellation is destructive and lives in the overflow
// menu, but the confirm step and the mutation itself stay right here — the ONE owner of both,
// controlled entirely by the parent's `open` prop (the overflow menu owns opening it; nothing
// else — not the sticky rail, not OrderActions — ever instantiates a second copy of this
// component or calls `cancel.mutateAsync` on its own).
import { ref, watch } from 'vue'
import { useCommerceOrderMutations, type CommerceOrder } from '@/queries/commerceOrders'
import { toApiError } from '@/api/errors'

const props = defineProps<{
  order: CommerceOrder
  open: boolean
}>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const { cancel } = useCommerceOrderMutations()
const error = ref<string | null>(null)

// Clear any stale error from a previous open/rejection the moment this reopens.
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) error.value = null
  },
)

function dismiss() {
  error.value = null
  emit('update:open', false)
}

async function confirm() {
  error.value = null
  try {
    await cancel.mutateAsync(props.order.uuid)
    emit('update:open', false)
  } catch (e) {
    // Server stays authoritative (e.g. a since-changed status races this confirm into a 409) —
    // surfaced inline and the dialog stays open for retry/dismiss, never silently closed as if
    // the cancellation had gone through.
    error.value = toApiError(e).message
  }
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
          Cancel order <strong>{{ order.order_number }}</strong>? Any tracked stock will be
          released. This can’t be undone.
        </p>
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
          Confirm cancel
        </UButton>
      </div>
    </template>
  </UModal>
</template>
