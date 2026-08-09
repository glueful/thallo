<script setup lang="ts">
// Task 19 (Phase C, spec §5.2/§5.3): the "cancel with per-mode confirm" control.
//
// **Why the mode isn't pre-enumerated.** `SelfBillingController::cancel()` validates `mode`
// against the ACTIVE gateway driver's own declared `cancellationModes()`
// ({@see SubscriptionCancellationModeProvider}) -- a value `/meta` never exposes (deliberately:
// meta is a read of subscription/origination state, not a capability probe). This dialog
// therefore can't pre-populate a verified mode list. Design choice, pinned here: default the
// selection to `stop_renewal` (§1's ruling names it the default cancellation mode -- "cancel
// through the active gateway (default: stop renewal)"), offer `immediate` as the other
// commonly-supported option, and if the driver refuses the chosen mode the controller's 422
// `invalid_cancellation_mode` (whose `error.details.modes` names what IS supported) renders
// verbatim -- the dialog stays open so the workspace can pick a different mode without
// re-opening it.
import { computed, ref, watch } from 'vue'
import { useWorkspaceCancelMutation } from '@/queries/workspaceBilling'
import { apiErrorDetails, toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ open: boolean; planLabel?: string | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const { success, error: notifyError } = useNotify()
const cancelMutation = useWorkspaceCancelMutation()

const MODE_ITEMS = [
  { label: 'Cancel at the end of the billing period', value: 'stop_renewal' },
  { label: 'Cancel immediately', value: 'immediate' },
]
const mode = ref('stop_renewal')
const submitError = ref<string | null>(null)
const supportedModes = ref<string[] | null>(null)

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return
    mode.value = 'stop_renewal'
    submitError.value = null
    supportedModes.value = null
  },
)

const description = computed(() =>
  props.planLabel
    ? `Cancel the “${props.planLabel}” subscription?`
    : 'Cancel this subscription?',
)

async function confirm() {
  submitError.value = null
  try {
    await cancelMutation.mutateAsync(mode.value)
    success('Cancellation requested', 'The provider will confirm the change shortly.')
    emit('update:open', false)
  } catch (e) {
    const err = toApiError(e)
    submitError.value = err.message
    const details = apiErrorDetails(e)
    const modes = details?.modes
    supportedModes.value = Array.isArray(modes) ? modes.filter((m): m is string => typeof m === 'string') : null
    notifyError(err, 'Couldn’t cancel subscription')
  }
}
</script>

<template>
  <UModal
    :open="open"
    title="Cancel subscription"
    data-test="cancel-dialog"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <p class="text-sm text-muted">{{ description }}</p>

      <URadioGroup
        v-model="mode"
        class="mt-4"
        :items="MODE_ITEMS"
        value-key="value"
        data-test="cancel-mode"
      />

      <UAlert
        v-if="submitError"
        class="mt-4"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="submitError"
        :description="supportedModes && supportedModes.length > 0 ? `Supported modes: ${supportedModes.join(', ')}` : undefined"
        data-test="cancel-mode-error"
      />
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Keep subscription"
          data-test="cancel-dismiss"
          @click="emit('update:open', false)"
        />
        <UButton
          color="error"
          label="Cancel subscription"
          data-test="cancel-confirm"
          :loading="cancelMutation.isLoading.value"
          @click="confirm"
        />
      </div>
    </template>
  </UModal>
</template>
