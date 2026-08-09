<script setup lang="ts">
// Task 11 (thallo-subscriptions Phase B): the workspace billing detail body -- everything
// `WorkspaceDrawer.vue` renders, whether wrapped in a slideover (tenancy ON, opened from a
// directory row) or embedded inline as the tenancy-OFF "This site's plan" panel bound to the
// single-store `default_tenant_uuid`. Factored out into its own component (rather than a dynamic
// `<component :is="embedded ? 'div' : 'USlideover'">` swap) because a `<template #body>` named
// slot silently renders NOTHING once `:is` resolves to a plain element -- this panel's markup
// needs exactly one home regardless of which chrome wraps it.
//
// A subscription carrying `provider_managed: true` means `SubscriptionService::cancelFor()`/
// `changePlanFor()` refuse local mutation (409 `provider_managed_subscription`) -- set-plan/cancel
// are disabled here proactively, WITH the same explanation, rather than only discovering the
// refusal after a submit; the verbatim server message still renders if one slips through anyway
// (e.g. a stale read).
//
// Overrides render EVERY row `OverrideRepository::listForSubject()` returns -- active AND
// expired, `expires_at`/`reason` intact -- never pre-filtered to active-only.
import { computed, reactive, ref, watch } from 'vue'
import {
  useWorkspace,
  useWorkspaceMutations,
  usePlans,
  isOverrideExpired,
  type EntitlementValue,
  type WorkspaceOverride,
} from '@/queries/subscriptionsBilling'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ uuid: string }>()

const { success, error: notifyError } = useNotify()

const { data: detail, status: detailStatus } = useWorkspace(
  () => props.uuid,
  () => !!props.uuid,
)
const { data: plansData } = usePlans()
const { setPlan, cancel, upsertOverride, deleteOverride } = useWorkspaceMutations()

const providerManaged = computed(() => detail.value?.subscription?.provider_managed ?? false)
const hasSubscription = computed(() => detail.value?.subscription != null)

const planItems = computed(() => {
  const plans = plansData.value ?? []
  const currentKey = detail.value?.subscription?.plan_key ?? null
  return plans
    .filter((p) => p.status === 'active' || p.plan_key === currentKey)
    .map((p) => ({ label: p.display_name, value: p.plan_key }))
})

const selectedPlanKey = ref('')
watch(
  () => detail.value?.subscription?.plan_key ?? null,
  (key) => {
    selectedPlanKey.value = key ?? ''
  },
  { immediate: true },
)

const setPlanError = ref<string | null>(null)
async function submitSetPlan() {
  setPlanError.value = null
  if (!selectedPlanKey.value) {
    setPlanError.value = 'Choose a plan.'
    return
  }
  try {
    await setPlan.mutateAsync({ uuid: props.uuid, planKey: selectedPlanKey.value })
    success('Plan set', hasSubscription.value ? 'The workspace plan was changed.' : 'The subscription was started.')
  } catch (e) {
    const err = toApiError(e)
    setPlanError.value = err.message
    notifyError(err, 'Couldn’t set plan')
  }
}

const atPeriodEnd = ref(true)
const cancelError = ref<string | null>(null)
async function submitCancel() {
  cancelError.value = null
  try {
    await cancel.mutateAsync({ uuid: props.uuid, atPeriodEnd: atPeriodEnd.value })
    success('Subscription canceled')
  } catch (e) {
    const err = toApiError(e)
    cancelError.value = err.message
    notifyError(err, 'Couldn’t cancel subscription')
  }
}

// ── Overrides ────────────────────────────────────────────────────────────────

type EntitlementKind = 'granted' | 'denied' | 'limited' | 'unlimited'

const overrideForm = reactive({
  entitlement: '',
  kind: 'granted' as EntitlementKind,
  limitInput: '',
  expiresAt: '',
  reason: '',
})
const overrideError = ref<string | null>(null)

function resetOverrideForm() {
  overrideForm.entitlement = ''
  overrideForm.kind = 'granted'
  overrideForm.limitInput = ''
  overrideForm.expiresAt = ''
  overrideForm.reason = ''
  overrideError.value = null
}

function kindForValue(value: unknown): EntitlementKind {
  if (value === null) return 'unlimited'
  if (typeof value === 'number') return 'limited'
  return value ? 'granted' : 'denied'
}

function editOverride(row: WorkspaceOverride) {
  overrideForm.entitlement = row.entitlement
  overrideForm.kind = kindForValue(row.value)
  overrideForm.limitInput = typeof row.value === 'number' ? String(row.value) : ''
  overrideForm.expiresAt = row.expires_at ? row.expires_at.slice(0, 10) : ''
  overrideForm.reason = row.reason ?? ''
  overrideError.value = null
}

async function submitOverride() {
  overrideError.value = null
  const entitlement = overrideForm.entitlement.trim()
  if (entitlement === '') {
    overrideError.value = 'Entitlement key is required.'
    return
  }

  let value: EntitlementValue
  if (overrideForm.kind === 'granted') {
    value = true
  } else if (overrideForm.kind === 'denied') {
    value = false
  } else if (overrideForm.kind === 'unlimited') {
    value = null
  } else {
    const trimmed = overrideForm.limitInput.trim()
    if (!/^\d+$/.test(trimmed)) {
      overrideError.value = 'Enter a non-negative whole-number limit.'
      return
    }
    value = Number(trimmed)
  }

  try {
    await upsertOverride.mutateAsync({
      uuid: props.uuid,
      entitlement,
      input: {
        value,
        expires_at: overrideForm.expiresAt === '' ? null : overrideForm.expiresAt,
        reason: overrideForm.reason.trim() === '' ? null : overrideForm.reason.trim(),
      },
    })
    success('Override saved', `“${entitlement}” was updated.`)
    resetOverrideForm()
  } catch (e) {
    const err = toApiError(e)
    overrideError.value = err.message
    notifyError(err, 'Couldn’t save override')
  }
}

async function removeOverride(row: WorkspaceOverride) {
  try {
    await deleteOverride.mutateAsync({ uuid: props.uuid, entitlement: row.entitlement })
    success('Override removed', `“${row.entitlement}” was removed.`)
  } catch (e) {
    notifyError(e, 'Couldn’t remove override')
  }
}

function describeValue(value: unknown): string {
  if (value === null) return 'unlimited'
  if (typeof value === 'boolean') return value ? 'granted' : 'denied'
  return String(value)
}
</script>

<template>
  <div class="space-y-6" data-test="workspace-drawer-body">
    <div v-if="detailStatus === 'pending'" data-test="workspace-loading">
      <USkeleton class="h-24 w-full" />
    </div>
    <div v-else-if="detailStatus === 'error'" data-test="workspace-error">
      <UAlert color="error" variant="subtle" title="Couldn't load this workspace." />
    </div>
    <template v-else-if="detail">
      <div>
        <p class="text-lg font-semibold" data-test="workspace-name">{{ detail.tenant.name }}</p>
        <p class="text-sm text-muted" data-test="workspace-slug">{{ detail.tenant.slug }}</p>
      </div>

      <div class="space-y-3 rounded-lg border border-default p-4" data-test="workspace-subscription">
        <template v-if="detail.subscription">
          <div class="flex items-center justify-between">
            <span class="font-medium" data-test="subscription-plan-name">
              {{ detail.subscription.plan_display_name ?? detail.subscription.plan_key }}
            </span>
            <span class="text-sm text-muted" data-test="subscription-status">{{ detail.subscription.status }}</span>
          </div>
          <p v-if="detail.subscription.trial_ends_at" class="text-xs text-muted" data-test="subscription-trial-ends">
            Trial ends {{ detail.subscription.trial_ends_at }}
          </p>
          <p v-if="detail.subscription.grace_ends_at" class="text-xs text-muted" data-test="subscription-grace-ends">
            Grace ends {{ detail.subscription.grace_ends_at }}
          </p>
          <UAlert
            v-if="providerManaged"
            color="warning"
            variant="subtle"
            icon="i-lucide-lock"
            title="Managed by a payment provider"
            description="This subscription is managed by a payment provider and cannot be changed locally."
            data-test="provider-managed-notice"
          />
        </template>
        <p v-else class="text-sm text-muted" data-test="workspace-no-subscription">
          This workspace has no subscription yet.
        </p>

        <div class="flex items-end gap-2">
          <UFormField label="Plan" class="flex-1" :error="setPlanError ?? undefined">
            <USelect
              v-model="selectedPlanKey"
              :items="planItems"
              :disabled="providerManaged"
              class="w-full"
              data-test="workspace-plan-select"
            />
          </UFormField>
          <UButton
            :disabled="providerManaged"
            :loading="setPlan.isLoading.value"
            :label="hasSubscription ? 'Change plan' : 'Start subscription'"
            data-test="workspace-set-plan"
            @click="submitSetPlan"
          />
        </div>

        <div v-if="hasSubscription" class="flex items-end gap-2">
          <UCheckbox v-model="atPeriodEnd" label="At period end" :disabled="providerManaged" data-test="workspace-cancel-at-period-end" />
          <UButton
            color="error"
            variant="soft"
            :disabled="providerManaged"
            :loading="cancel.isLoading.value"
            label="Cancel subscription"
            data-test="workspace-cancel"
            @click="submitCancel"
          />
        </div>
        <UAlert
          v-if="cancelError"
          color="error"
          variant="subtle"
          :title="cancelError"
          data-test="workspace-cancel-error"
        />
      </div>

      <div class="space-y-3">
        <p class="font-medium">Entitlement overrides</p>
        <p v-if="detail.overrides.length === 0" class="text-sm text-muted" data-test="overrides-empty">
          No overrides set.
        </p>
        <ul v-else class="space-y-2">
          <li
            v-for="row in detail.overrides"
            :key="row.entitlement"
            class="flex items-center justify-between gap-2 rounded-lg border border-default p-3"
            data-test="override-row"
          >
            <div>
              <p class="font-mono text-sm" data-test="override-entitlement">{{ row.entitlement }}</p>
              <p class="text-xs text-muted" data-test="override-value">{{ describeValue(row.value) }}</p>
              <p v-if="row.expires_at" class="text-xs text-muted" data-test="override-expires-at">
                Expires {{ row.expires_at }}
                <span v-if="isOverrideExpired(row)" class="text-error" data-test="override-expired-badge">
                  (expired)
                </span>
              </p>
              <p v-if="row.reason" class="text-xs text-muted" data-test="override-reason">{{ row.reason }}</p>
            </div>
            <div class="flex gap-1">
              <UButton size="xs" variant="ghost" label="Edit" data-test="override-edit" @click="editOverride(row)" />
              <UButton
                size="xs"
                color="error"
                variant="ghost"
                label="Remove"
                data-test="override-remove"
                @click="removeOverride(row)"
              />
            </div>
          </li>
        </ul>

        <form class="space-y-2 rounded-lg border border-default p-3" @submit.prevent="submitOverride">
          <div class="flex gap-2">
            <UInput
              v-model="overrideForm.entitlement"
              placeholder="entitlement key"
              class="flex-1"
              data-test="override-entitlement-input"
            />
            <USelect
              v-model="overrideForm.kind"
              :items="[
                { label: 'Granted', value: 'granted' },
                { label: 'Denied', value: 'denied' },
                { label: 'Limited', value: 'limited' },
                { label: 'Unlimited', value: 'unlimited' },
              ]"
              class="w-32"
              data-test="override-kind-input"
            />
            <UInput
              v-if="overrideForm.kind === 'limited'"
              v-model="overrideForm.limitInput"
              placeholder="0"
              class="w-20"
              data-test="override-limit-input"
            />
          </div>
          <div class="flex gap-2">
            <UInput v-model="overrideForm.expiresAt" type="date" class="flex-1" data-test="override-expires-input" />
            <UInput
              v-model="overrideForm.reason"
              placeholder="reason (optional)"
              class="flex-1"
              data-test="override-reason-input"
            />
          </div>
          <UAlert
            v-if="overrideError"
            color="error"
            variant="subtle"
            :title="overrideError"
            data-test="override-form-error"
          />
          <UButton
            type="submit"
            label="Save override"
            :loading="upsertOverride.isLoading.value"
            data-test="override-submit"
          />
        </form>
      </div>
    </template>
  </div>
</template>
