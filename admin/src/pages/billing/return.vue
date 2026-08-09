<script setup lang="ts">
// Task 19 (Phase C, spec §5.2/§5.3): the checkout return page --
// `{admin_url}/billing/return?origination=<uuid>` is the `success_url`/`cancel_url` both the
// Stripe Checkout Session and the Paystack initialize request are given.
//
// **Informational only.** This page NEVER mutates anything -- it imports no mutation from
// `queries/workspaceBilling`, only `useWorkspaceBillingMeta()`. Webhooks plus successful
// strict-lane projection are the ONLY activation authority (design spec's own opening line); a
// return-route "mark this successful" endpoint doesn't exist because the return could be spoofed,
// abandoned, or racing the webhook -- the page polls `GET /meta` and renders whatever state the
// projection has ACTUALLY reached, same as the main Billing page. `origination` is read from the
// URL purely for a "reference: <uuid>" display line; `/meta` itself takes no filter by it (it is
// already workspace-scoped).
import { computed, onBeforeUnmount, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useWorkspaceBillingMeta } from '@/queries/workspaceBilling'

definePage({ meta: { requiresAuth: true, requiresCapability: 'thallo.subscriptions' } })

const route = useRoute()
const originationRef = computed(() => {
  const raw = route.query.origination
  return typeof raw === 'string' && raw.trim() !== '' ? raw.trim() : null
})

const { data: meta, status: metaStatus, refetch: refetchMeta } = useWorkspaceBillingMeta()

type Summary = 'settling' | 'active' | 'blocked' | 'canceled' | 'unknown'

const summary = computed<Summary>(() => {
  const m = meta.value
  if (!m || m.engine !== 'ready') return 'unknown'
  if (m.operator_contact_required) return 'blocked'
  if (m.origination !== null) return 'settling'
  const s = m.subscription
  if (s === null) return 'unknown'
  if (s.status === 'canceled') return 'canceled'
  return 'active'
})

// Light polling while a webhook may still be landing -- cleared on unmount AND (code review
// fix) the moment `summary` leaves `'settling'`: there's nothing left to wait for once the
// projection has actually resolved (or errored/blocked/unknown), so polling forever afterward
// would just be a silent background request every 4s for as long as this tab stays open. A
// manual "Refresh now" button still covers the case a caller wants an out-of-band check.
const POLL_INTERVAL_MS = 4000
let pollHandle: ReturnType<typeof setInterval> | undefined
function stopPolling(): void {
  if (pollHandle !== undefined) {
    clearInterval(pollHandle)
    pollHandle = undefined
  }
}
watch(
  summary,
  (current) => {
    if (current === 'settling') {
      pollHandle ??= setInterval(() => {
        void refetchMeta()
      }, POLL_INTERVAL_MS)
    } else {
      stopPolling()
    }
  },
  { immediate: true },
)
onBeforeUnmount(stopPolling)
</script>

<template>
  <UDashboardPanel id="workspace-billing-return">
    <template #header>
      <UDashboardNavbar title="Checkout" />
    </template>

    <template #body>
      <div class="mx-auto max-w-lg space-y-4 p-6 text-center">
        <div v-if="metaStatus === 'pending'" data-test="return-loading">
          <USkeleton class="h-24 w-full" />
        </div>
        <div v-else-if="metaStatus === 'error'" data-test="return-error">
          <UAlert
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Couldn't check your billing status"
            description="Refresh to try again."
          />
        </div>
        <template v-else>
          <div v-if="summary === 'settling'" data-test="return-settling">
            <UIcon name="i-lucide-loader-2" class="mx-auto size-8 animate-spin text-muted" />
            <p class="mt-2 font-medium">Finishing your checkout…</p>
            <p class="text-sm text-muted">This page updates automatically once it's done.</p>
          </div>
          <div v-else-if="summary === 'active'" data-test="return-active">
            <UIcon name="i-lucide-circle-check" class="mx-auto size-8 text-success" />
            <p class="mt-2 font-medium">Your subscription is active</p>
            <p class="text-sm text-muted">{{ meta?.subscription?.plan_key }}</p>
          </div>
          <div v-else-if="summary === 'blocked'" data-test="return-blocked">
            <UIcon name="i-lucide-headset" class="mx-auto size-8 text-warning" />
            <p class="mt-2 font-medium">This checkout needs operator attention</p>
            <p class="text-sm text-muted">{{ meta?.operator_contact_reason }}</p>
          </div>
          <div v-else-if="summary === 'canceled'" data-test="return-canceled">
            <UIcon name="i-lucide-info" class="mx-auto size-8 text-muted" />
            <p class="mt-2 font-medium">No active subscription</p>
          </div>
          <div v-else data-test="return-unknown">
            <UIcon name="i-lucide-info" class="mx-auto size-8 text-muted" />
            <p class="mt-2 font-medium">We couldn't determine your checkout's status yet</p>
          </div>

          <p v-if="originationRef" class="text-xs text-muted" data-test="return-origination-ref">
            Reference: {{ originationRef }}
          </p>
        </template>

        <div class="flex justify-center gap-2">
          <UButton
            color="neutral"
            variant="subtle"
            icon="i-lucide-refresh-cw"
            label="Refresh now"
            data-test="return-refresh"
            @click="refetchMeta()"
          />
          <UButton to="/billing" variant="ghost" label="Go to Billing" data-test="return-to-billing" />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
