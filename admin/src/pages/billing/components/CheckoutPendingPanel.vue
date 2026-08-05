<script setup lang="ts">
// Task 19 (Phase C, spec §5.3/§5.2): the "pending origination" state -- a hosted checkout session
// already exists (`checkout_url` non-null). Offers Resume (reopen the SAME hosted session -- never
// a new one; that would mint a second live attempt) and Abandon (`POST /checkout/abandon`, spec
// §3.1/§5.2 -- succeeds only on the provider's `confirmed_dead` outcome).
//
// Paystack has no abandonment capability (`SubscriptionCheckoutLifecycleCapableGateway` unimplemented)
// -- the endpoint's structured 409 `checkout_abandonment_unsupported` is rendered as its OWN
// terminal notice, and the abandon control is then withdrawn entirely: the UI offers resume or
// "contact your platform operator", NEVER a workspace reopen action (spec §5.2 pins this
// explicitly).
import { ref } from 'vue'
import { useWorkspaceAbandonMutation } from '@/queries/workspaceBilling'
import { apiErrorCode, toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

defineProps<{ checkoutUrl: string | null }>()

const { success, error: notifyError } = useNotify()
const abandonMutation = useWorkspaceAbandonMutation()
const abandonError = ref<string | null>(null)
const abandonUnsupported = ref(false)

async function abandon() {
  abandonError.value = null
  try {
    await abandonMutation.mutateAsync()
    success('Checkout abandoned', 'You can start a new checkout now.')
  } catch (e) {
    const code = apiErrorCode(e)
    const err = toApiError(e)
    if (code === 'checkout_abandonment_unsupported') {
      abandonUnsupported.value = true
    } else {
      abandonError.value = err.message
    }
    notifyError(err, 'Couldn’t abandon checkout')
  }
}
</script>

<template>
  <div class="space-y-3 rounded-lg border border-default p-4" data-test="checkout-pending-panel">
    <p class="font-medium">A checkout is already in progress</p>
    <p class="text-sm text-muted">Resume it to finish, or abandon it to start over.</p>

    <div class="flex flex-wrap items-center gap-2">
      <!-- Plain anchor, not UButton `to` -- `checkoutUrl` is an absolute, off-app provider URL,
           and this needs a real testable `href`, never a router-resolved one. -->
      <a
        v-if="checkoutUrl"
        :href="checkoutUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-inverted hover:opacity-90"
        data-test="checkout-resume-link"
      >
        <UIcon name="i-lucide-external-link" class="size-4" />
        Resume checkout
      </a>
      <UButton
        v-if="!abandonUnsupported"
        color="neutral"
        variant="soft"
        :loading="abandonMutation.isLoading.value"
        label="Abandon checkout"
        data-test="checkout-abandon"
        @click="abandon"
      />
    </div>

    <UAlert
      v-if="abandonUnsupported"
      color="warning"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="This payment provider doesn’t support abandoning a checkout"
      description="Resume the checkout above, or contact your platform operator to have it resolved."
      data-test="checkout-abandon-unsupported"
    />
    <UAlert
      v-else-if="abandonError"
      color="error"
      variant="subtle"
      :description="abandonError"
      data-test="checkout-abandon-error"
    />
  </div>
</template>
