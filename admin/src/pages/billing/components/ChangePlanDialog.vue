<script setup lang="ts">
// Change plan on a live subscription. Where the provider can switch it (Stripe), the workspace
// picks another plan and the change is requested; it applies once the provider confirms it, so the
// page shows the old plan until then. Where it cannot (Paystack), the dialog says how to change:
// cancel at the end of the period, then subscribe to the new plan.
import { computed, ref, watch } from 'vue'
import {
  useWorkspaceChangePlanMutation,
  type WorkspacePurchasablePlan,
} from '@/queries/workspaceBilling'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { planChoiceLabel } from '@/utils/planPrice'

const props = defineProps<{
  open: boolean
  plans: WorkspacePurchasablePlan[]
  currentPlanKey: string | null
  supported: boolean
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; 'cancel-instead': [] }>()

const { success, error: notifyError } = useNotify()
const change = useWorkspaceChangePlanMutation()

const choices = computed(() =>
  props.plans
    .filter((p) => p.plan_key !== props.currentPlanKey)
    .map((p) => ({ label: planChoiceLabel(p), value: p.plan_key })),
)
const selected = ref('')
const submitError = ref<string | null>(null)
watch(
  () => [props.open, choices.value] as const,
  ([isOpen]) => {
    if (!isOpen) return
    submitError.value = null
    if (!choices.value.some((c) => c.value === selected.value)) {
      selected.value = choices.value[0]?.value ?? ''
    }
  },
  { immediate: true },
)

async function confirm() {
  if (selected.value === '') return
  submitError.value = null
  try {
    await change.mutateAsync(selected.value)
    success('Plan change requested', 'It applies as soon as your payment provider confirms it.')
    emit('update:open', false)
  } catch (e) {
    const err = toApiError(e)
    submitError.value = err.message
    notifyError(err, 'Couldn’t change the plan')
  }
}
</script>

<template>
  <UModal
    :open="open"
    title="Change plan"
    data-test="change-plan-dialog"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <template v-if="supported">
        <p v-if="choices.length === 0" class="text-sm text-muted">
          There is no other plan to move to.
        </p>
        <UFormField v-else label="New plan">
          <USelect
            v-model="selected"
            :items="choices"
            class="w-full"
            data-test="change-plan-choice"
          />
        </UFormField>
        <p class="mt-3 text-xs text-muted">
          The provider prorates the change: you are charged or credited for the rest of this period.
        </p>
        <UAlert
          v-if="submitError"
          class="mt-4"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :title="submitError"
        />
      </template>
      <div v-else class="space-y-2 text-sm" data-test="change-plan-unsupported">
        <p>Your payment provider can’t move a live subscription to another plan.</p>
        <p class="text-muted">
          Cancel this subscription at the end of the billing period. When it ends, subscribe to the
          new plan from this page.
        </p>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Close"
          @click="emit('update:open', false)"
        />
        <UButton
          v-if="supported"
          label="Change plan"
          :disabled="selected === ''"
          :loading="change.isLoading.value"
          data-test="change-plan-confirm"
          @click="confirm"
        />
        <UButton
          v-else
          color="error"
          variant="soft"
          label="Cancel at the end of the period"
          data-test="change-plan-cancel-instead"
          @click="emit('cancel-instead')"
        />
      </div>
    </template>
  </UModal>
</template>
