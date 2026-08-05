<script setup lang="ts">
// Task 19 (Phase C, spec §5.3): the "no subscription yet" state -- lists the active gateway's
// `purchasable_plans` (plan_key + name only, no prices -- `SelfBillingController::meta()` never
// returns pricing) and lets the workspace start a checkout. Purely presentational: the parent
// page owns the checkout mutation, the idempotency token, and the resulting redirect/poll -- this
// component only emits the chosen `plan_key`.
//
// Deep-link preselection (spec §5.4): `initialPlanKey` comes from the page's `?plan=` query
// param, survived through the login guard's `redirect` round-trip untouched. A well-formed but
// UNKNOWN key is preselected verbatim even when absent from `plans` -- the bridge makes no
// purchasability promise, so submitting it and letting the server's `plan_not_purchasable` 409
// render is the correct outcome, never a silent fallback to "no selection".
import { computed, ref, watch } from 'vue'
import type { WorkspacePurchasablePlan } from '@/queries/workspaceBilling'

const props = defineProps<{
  plans: WorkspacePurchasablePlan[]
  initialPlanKey?: string | null
  loading?: boolean
  error?: string | null
}>()
const emit = defineEmits<{ subscribe: [planKey: string] }>()

const selected = ref(props.initialPlanKey ?? '')
watch(
  () => props.initialPlanKey,
  (key) => {
    if (key) selected.value = key
  },
  { immediate: true },
)
// No deep-link key: default to the first purchasable plan once the list arrives, rather than
// leaving the picker on an empty, un-submittable selection.
watch(
  () => props.plans,
  (plans) => {
    if (selected.value === '' && plans.length > 0 && !props.initialPlanKey) {
      selected.value = plans[0]!.plan_key
    }
  },
  { immediate: true },
)

const items = computed(() => props.plans.map((p) => ({ label: p.name, value: p.plan_key })))

function submit() {
  const key = selected.value.trim()
  if (key === '') return
  emit('subscribe', key)
}
</script>

<template>
  <div class="space-y-4" data-test="plan-picker">
    <p v-if="plans.length === 0" class="text-sm text-muted" data-test="plan-picker-empty">
      No plans are currently available for checkout.
    </p>
    <template v-else>
      <UFormField label="Choose a plan">
        <USelect v-model="selected" :items="items" class="w-full max-w-sm" data-test="plan-picker-select" />
      </UFormField>
      <UButton
        :disabled="selected.trim() === ''"
        :loading="loading"
        label="Subscribe"
        data-test="plan-picker-subscribe"
        @click="submit"
      />
    </template>
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="error"
      data-test="plan-picker-error"
    />
  </div>
</template>
