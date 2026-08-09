<script setup lang="ts">
// Task 19 (Phase C, workspace self-serve checkout plan, spec §5.3): the workspace-scoped Billing
// page -- meta-first fetch (binding behavior rule, mirrors subscriptions/billing/index.vue)
// decides which of the pinned states to render. `GET /billing/meta` is 200 always once route
// authorization succeeds (`SelfBillingController::meta()`'s own docblock), so `metaStatus ===
// 'error'` is its own branch, never conflated with any engine/subscription state (Task 11's
// "final-wave fix E" lesson, repeated here from day one per the brief).
//
// State precedence (deliberately NOT the spec bullet-list order verbatim -- see the rationale
// inline below at each branch):
//   1. engine unavailable -- total override, nothing else is even projected server-side.
//   2. operator_contact_required -- an independent banner, layered over whatever else renders
//      below it (a blocked GUARD does not erase an already-active subscription's own state).
//   3. a live origination (guard state 'live') -- initializing (no url yet) or pending
//      (resume/abandon) -- takes precedence over subscription state because a fresh attempt in
//      flight is the most actionable thing on the page.
//   4. no subscription, or a canceled one -- the checkout-available section (switch-off notice,
//      or the plan picker + purchasable-plans-empty case). A canceled subscription banners on
//      top of the SAME section, since starting a new one is exactly a "no active subscription"
//      checkout.
//   5. non_renewing -- access-until date, no picker (still entitled through period end).
//   6. entitling but NOT provider_managed -- "provider-managed-elsewhere": granted directly by
//      the platform operator (no `provider_subscription_id`), so a self-serve `POST /cancel`
//      would always 409 `not_provider_managed` -- the UI never offers a button that can only fail.
//   7. active (entitling + provider_managed) -- plan, period end, Cancel with per-mode confirm.
//
// Plan changes on an active/non_renewing subscription are never offered (§1 ruling: "cancel
// first or contact your platform operator") -- both those panels render that pinned message next
// to a disabled control rather than a working plan-change picker.
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  useWorkspaceBillingMeta,
  useWorkspaceCheckoutMutation,
  CheckoutAttemptTracker,
  isTerminalCheckoutStatus,
  isTerminalCheckoutErrorCode,
  navigateToCheckout,
  type WorkspaceBillingMeta,
  type WorkspaceBillingSubscription,
} from '@/queries/workspaceBilling'
import { apiErrorCode, toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import EngineStateNotice from '@/pages/subscriptions/components/EngineStateNotice.vue'
import PlanPicker from './components/PlanPicker.vue'
import CheckoutPendingPanel from './components/CheckoutPendingPanel.vue'
import CancelDialog from './components/CancelDialog.vue'

definePage({ meta: { requiresAuth: true, requiresCapability: 'thallo.subscriptions' } })

const route = useRoute()
const { error: notifyError } = useNotify()

const { data: meta, status: metaStatus, refetch: refetchMeta } = useWorkspaceBillingMeta()

const subscription = computed(() => meta.value?.subscription ?? null)
const origination = computed(() => meta.value?.origination ?? null)
const purchasablePlans = computed(() => meta.value?.purchasable_plans ?? [])
const selfServeEnabled = computed(() => meta.value?.self_serve_checkout_enabled ?? false)

type BillingView =
  | 'initializing'
  | 'pending'
  | 'switch_off'
  | 'plan_picker'
  | 'non_renewing'
  | 'provider_managed_elsewhere'
  | 'active'

/**
 * Mirrors `SelfBillingController::isEntitling()` EXACTLY (spec §4.1, the same predicate
 * `POST /checkout`'s pre-check enforces server-side): active/trialing/past_due are always
 * entitling; non_renewing only while its `current_period_end` is still in the future. Getting
 * this wrong stranded the page (code review finding): treating every `non_renewing` row -- or a
 * non-entitling `incomplete` row left behind by a failed/expired checkout attempt -- as "there's
 * a subscription here" routed the workspace into the read-only `provider_managed_elsewhere` or
 * `non_renewing` panels FOREVER, with no picker offered, even though the server would happily
 * accept a brand new checkout attempt for exactly this workspace.
 */
function isEntitling(s: WorkspaceBillingSubscription): boolean {
  if (s.status === 'active' || s.status === 'trialing' || s.status === 'past_due') return true
  if (s.status === 'non_renewing') {
    return s.current_period_end !== null && new Date(s.current_period_end).getTime() > Date.now()
  }
  return false
}

const view = computed<BillingView | null>(() => {
  const m = meta.value
  if (!m || m.engine !== 'ready') return null

  const o = origination.value
  if (o !== null) return o.status === 'initializing' ? 'initializing' : 'pending'

  const s = subscription.value
  if (s === null || !isEntitling(s)) {
    return selfServeEnabled.value ? 'plan_picker' : 'switch_off'
  }
  if (s.status === 'non_renewing') return 'non_renewing'
  if (!s.provider_managed) return 'provider_managed_elsewhere'
  return 'active'
})

// Only the terminal `canceled` status gets its own explanatory banner above the picker --
// `incomplete` (a stale reservation from an abandoned/failed attempt) and an EXPIRED
// `non_renewing` row are silently superseded by a fresh checkout attempt, exactly like "no
// subscription at all", with nothing notable to call out.
const showCanceledBanner = computed(() => subscription.value?.status === 'canceled')

// ── Idempotency token discipline (spec §5.3) ────────────────────────────────
// ONE tracker instance for the lifetime of this page mount -- retained across re-renders because
// it's created once in setup(), never inside the computed/handler that uses it.
const tracker = new CheckoutAttemptTracker()
watch(origination, (o) => tracker.observeMeta(o))

// ── Deep-link ?plan= preselection (spec §5.4) ───────────────────────────────
const initialPlanKey = computed(() => {
  const raw = route.query.plan
  return typeof raw === 'string' && raw.trim() !== '' ? raw.trim() : null
})

const checkoutMutation = useWorkspaceCheckoutMutation()
const checkoutError = ref<string | null>(null)

async function subscribe(planKey: string) {
  checkoutError.value = null
  const token = tracker.ensureToken()
  try {
    const result = await checkoutMutation.mutateAsync({ planKey, idempotencyKey: token })
    if (result.status === 'pending' && result.checkout_url) {
      navigateToCheckout(result.checkout_url)
      return
    }
    if (isTerminalCheckoutStatus(result.status)) {
      tracker.markTerminal()
    }
    // 'initializing' (202) and any other in-flight status: keep the SAME token, meta refetch
    // (already triggered by the mutation's onSettled) will surface the live origination.
  } catch (e) {
    // `isTerminalCheckoutErrorCode` is the SAME set `subscribe()`'s success branch consults via
    // `isTerminalCheckoutStatus` -- covers `checkout_failed`/`checkout_expired`/
    // `checkout_abandoned` AND `idempotency_conflict` (code review fix: an orphaned token from an
    // out-of-band guard release must rotate here too, or every further click re-poisons itself).
    if (isTerminalCheckoutErrorCode(apiErrorCode(e))) {
      tracker.markTerminal()
    }
    const err = toApiError(e)
    checkoutError.value = err.message
    notifyError(err, 'Couldn’t start checkout')
  }
}

// Retained purely so a stuck-initializing attempt started by THIS session can offer a manual
// "Try again" using the SAME token -- a fresh page load (no local record of which plan was being
// purchased) offers only a manual refresh instead, never a blind resubmit.
const lastAttemptedPlanKey = ref<string | null>(null)

async function retrySameAttempt() {
  if (lastAttemptedPlanKey.value === null) {
    await refetchMeta()
    return
  }
  await subscribe(lastAttemptedPlanKey.value)
}

async function onPickerSubscribe(planKey: string) {
  lastAttemptedPlanKey.value = planKey
  await subscribe(planKey)
}

// ── Cancel dialog ────────────────────────────────────────────────────────────
const cancelOpen = ref(false)

function planLabelFor(m: WorkspaceBillingMeta | undefined, planKey: string | null): string | null {
  if (planKey === null) return null
  const found = m?.purchasable_plans.find((p) => p.plan_key === planKey)
  return found?.name ?? planKey
}
const activePlanLabel = computed(() => planLabelFor(meta.value, subscription.value?.plan_key ?? null))
</script>

<template>
  <UDashboardPanel id="workspace-billing">
    <template #header>
      <UDashboardNavbar title="Billing" />
    </template>

    <template #body>
      <div v-if="metaStatus === 'pending'" class="p-6" data-test="billing-meta-loading">
        <USkeleton class="h-24 w-full" />
      </div>
      <div v-else-if="metaStatus === 'error'" class="p-6" data-test="billing-meta-error">
        <UAlert
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Couldn't load billing status"
          description="The billing status check failed. Check your connection and try again."
        />
        <UButton
          class="mt-4"
          color="neutral"
          variant="subtle"
          icon="i-lucide-refresh-cw"
          label="Retry"
          data-test="billing-meta-retry"
          @click="refetchMeta()"
        />
      </div>
      <EngineStateNotice
        v-else-if="meta && meta.engine !== 'ready'"
        :state="meta.engine === 'schema_not_ready' ? 'schema_not_ready' : 'engine_disabled'"
        :show-action="false"
      />
      <div v-else class="space-y-4 p-6">
        <UAlert
          v-if="meta?.operator_contact_required"
          color="warning"
          variant="subtle"
          icon="i-lucide-headset"
          title="This workspace's billing needs operator attention"
          :description="meta.operator_contact_reason ?? 'Contact your platform operator to resolve this before trying again.'"
          data-test="billing-blocked-banner"
        />

        <template v-if="view === 'initializing'">
          <div class="flex items-center gap-3 rounded-lg border border-default p-4" data-test="checkout-initializing">
            <UIcon name="i-lucide-loader-2" class="size-5 animate-spin text-muted" />
            <div>
              <p class="font-medium">Preparing your checkout…</p>
              <p class="text-sm text-muted">This usually takes just a moment.</p>
            </div>
            <UButton
              class="ml-auto"
              color="neutral"
              variant="soft"
              size="sm"
              :label="lastAttemptedPlanKey ? 'Try again' : 'Refresh'"
              :loading="checkoutMutation.isLoading.value"
              data-test="checkout-initializing-retry"
              @click="retrySameAttempt"
            />
          </div>
        </template>

        <CheckoutPendingPanel v-else-if="view === 'pending'" :checkout-url="origination?.checkout_url ?? null" />

        <template v-else-if="view === 'switch_off' || view === 'plan_picker'">
          <UAlert
            v-if="showCanceledBanner"
            color="neutral"
            variant="subtle"
            icon="i-lucide-info"
            title="Your previous subscription was canceled"
            data-test="subscription-canceled"
          />
          <UAlert
            v-if="view === 'switch_off'"
            color="neutral"
            variant="subtle"
            icon="i-lucide-info"
            title="Self-serve billing is not enabled on this platform"
            description="Contact your platform operator to start a subscription."
            data-test="self-serve-off"
          />
          <PlanPicker
            v-else
            :plans="purchasablePlans"
            :initial-plan-key="initialPlanKey"
            :loading="checkoutMutation.isLoading.value"
            :error="checkoutError"
            @subscribe="onPickerSubscribe"
          />
        </template>

        <template v-else-if="view === 'non_renewing'">
          <div class="space-y-2 rounded-lg border border-default p-4" data-test="subscription-non-renewing">
            <p class="font-medium">{{ subscription?.plan_key }}</p>
            <p class="text-sm text-muted">
              Access continues until
              <span data-test="subscription-access-until">{{ subscription?.current_period_end }}</span>
              — this subscription will not renew.
            </p>
            <p class="text-xs text-muted">Cancel first or contact your platform operator to change plans.</p>
          </div>
        </template>

        <template v-else-if="view === 'provider_managed_elsewhere'">
          <div class="space-y-2 rounded-lg border border-default p-4" data-test="provider-managed-elsewhere">
            <p class="font-medium">{{ subscription?.plan_key }}</p>
            <p class="text-sm text-muted">{{ subscription?.status }}</p>
            <UAlert
              color="neutral"
              variant="subtle"
              icon="i-lucide-info"
              description="This plan is managed by your platform operator. Contact them to change or cancel it."
            />
          </div>
        </template>

        <template v-else-if="view === 'active'">
          <div class="space-y-3 rounded-lg border border-default p-4" data-test="subscription-active">
            <div class="flex items-center justify-between">
              <p class="font-medium" data-test="subscription-plan">{{ subscription?.plan_key }}</p>
              <span class="text-sm text-muted" data-test="subscription-status">{{ subscription?.status }}</span>
            </div>
            <p v-if="subscription?.current_period_end" class="text-sm text-muted">
              Renews {{ subscription.current_period_end }}
            </p>
            <div class="flex items-center gap-2">
              <UButton color="error" variant="soft" label="Cancel subscription" data-test="billing-cancel" @click="cancelOpen = true" />
              <UButton
                disabled
                color="neutral"
                variant="ghost"
                size="sm"
                label="Change plan"
                data-test="billing-change-plan-disabled"
              />
            </div>
            <p class="text-xs text-muted">Cancel first or contact your platform operator to change plans.</p>
          </div>
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <CancelDialog v-model:open="cancelOpen" :plan-label="activePlanLabel" />
</template>
