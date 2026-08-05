<script setup lang="ts">
// Task 11 (thallo-subscriptions Phase B): single dual-mode slideover for BOTH creating a platform
// plan and editing one -- `plan` absent/null means create mode, mirroring
// `commerce/discounts/components/DiscountForm.vue`'s established dual-mode pattern. `plan_key` is
// immutable once created (PlanPayloadValidator/PlanManagementService reject any attempted change
// with a verbatim 422), so the field is disabled -- never omitted -- once editing.
//
// Entitlements (`PlanPayloadValidator::validateEntitlements()`) are a plain string-keyed map whose
// values are exactly `bool | non-negative int | null` -- this editor represents that as rows of
// {key, kind, limit}, where `kind` picks which of the three value shapes the row currently holds:
// granted (`true`), denied (`false`), limited (a non-negative integer), or unlimited (`null`).
import { computed, reactive, ref, watch } from 'vue'
import {
  usePlanMutations,
  type CreatePlanInput,
  type EntitlementValue,
  type PlanEntitlements,
  type PlanStatus,
  type ProviderIdentifiers,
  type SubscriptionPlan,
  type UpdatePlanInput,
} from '@/queries/subscriptionsBilling'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{
  open: boolean
  plan?: SubscriptionPlan | null
}>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const { success, error: notifyError } = useNotify()
const { create, update } = usePlanMutations()

const editing = computed(() => props.plan ?? null)

const STATUS_ITEMS: { label: string; value: PlanStatus }[] = [
  { label: 'draft', value: 'draft' },
  { label: 'active', value: 'active' },
  { label: 'archived', value: 'archived' },
]

type EntitlementKind = 'granted' | 'denied' | 'limited' | 'unlimited'

interface EntitlementRow {
  key: string
  kind: EntitlementKind
  limitInput: string
}

const KIND_ITEMS: { label: string; value: EntitlementKind }[] = [
  { label: 'Granted', value: 'granted' },
  { label: 'Denied', value: 'denied' },
  { label: 'Limited', value: 'limited' },
  { label: 'Unlimited', value: 'unlimited' },
]

function kindForValue(value: EntitlementValue): EntitlementKind {
  if (value === null) return 'unlimited'
  if (typeof value === 'number') return 'limited'
  return value ? 'granted' : 'denied'
}

function rowsFromEntitlements(entitlements: PlanEntitlements): EntitlementRow[] {
  return Object.entries(entitlements).map(([key, value]) => ({
    key,
    kind: kindForValue(value),
    limitInput: typeof value === 'number' ? String(value) : '',
  }))
}

interface FormState {
  planKey: string
  displayName: string
  description: string
  providerPriceId: string
  status: PlanStatus
  sortOrderInput: string
}

function blankState(): FormState {
  return {
    planKey: '',
    displayName: '',
    description: '',
    providerPriceId: '',
    status: 'draft',
    sortOrderInput: '0',
  }
}

function stateFromPlan(p: SubscriptionPlan): FormState {
  return {
    planKey: p.plan_key,
    displayName: p.display_name,
    description: p.description ?? '',
    providerPriceId: p.provider_price_id ?? '',
    status: p.status,
    sortOrderInput: String(p.sort_order),
  }
}

const state = reactive(blankState())
const entitlementRows = ref<EntitlementRow[]>([])

// Task 19 (spec §4.2/§5.3): `provider_identifiers` -- gateway key -> identifier string rows,
// mirrors the entitlements editor's row-array-over-a-map pattern immediately above. PATCH/POST
// both send the FULL built map (2.2.0's replacement semantics, `subscriptionsBilling.ts`'s own
// docblock) -- there is no separate add/remove endpoint, so a row removed here and submitted is
// gone from the plan, not merely unset.
interface IdentifierRow {
  gateway: string
  identifier: string
}

function rowsFromIdentifiers(map: ProviderIdentifiers): IdentifierRow[] {
  return Object.entries(map).map(([gateway, identifier]) => ({ gateway, identifier }))
}

const identifierRows = ref<IdentifierRow[]>([])

function addIdentifierRow() {
  identifierRows.value.push({ gateway: '', identifier: '' })
}
function removeIdentifierRow(index: number) {
  identifierRows.value.splice(index, 1)
}

const GATEWAY_KEY_PATTERN = /^[a-z0-9_-]{1,50}$/

/** Builds the validated `provider_identifiers` map, or `null` (with an inline row error) on the
 * first bad row -- mirrors {@link buildEntitlements}'s same skip-blank-rows/fail-fast shape. A
 * row with BOTH fields blank is an in-progress row the operator hasn't finished (skipped); a row
 * with only one filled is a genuine validation error, never silently dropped. */
function buildProviderIdentifiers(): ProviderIdentifiers | null {
  const out: ProviderIdentifiers = {}
  for (const row of identifierRows.value) {
    const gateway = row.gateway.trim()
    const identifier = row.identifier.trim()
    if (gateway === '' && identifier === '') continue
    if (!GATEWAY_KEY_PATTERN.test(gateway)) {
      submitError.value = `Gateway key "${row.gateway}" must be 1-50 characters of lowercase letters, digits, underscore, or hyphen.`
      return null
    }
    if (identifier === '' || identifier.length > 191) {
      submitError.value = `Identifier for "${gateway}" must be 1-191 characters.`
      return null
    }
    out[gateway] = identifier
  }
  return out
}

const planKeyError = ref<string | null>(null)
const displayNameError = ref<string | null>(null)
const sortOrderError = ref<string | null>(null)
const submitError = ref<string | null>(null)

function resetErrors() {
  planKeyError.value = null
  displayNameError.value = null
  sortOrderError.value = null
  submitError.value = null
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return
    Object.assign(state, editing.value ? stateFromPlan(editing.value) : blankState())
    entitlementRows.value = editing.value ? rowsFromEntitlements(editing.value.entitlements) : []
    identifierRows.value = editing.value ? rowsFromIdentifiers(editing.value.provider_identifiers) : []
    resetErrors()
  },
)

function addEntitlementRow() {
  entitlementRows.value.push({ key: '', kind: 'granted', limitInput: '' })
}
function removeEntitlementRow(index: number) {
  entitlementRows.value.splice(index, 1)
}

const isLoading = computed(() => create.isLoading.value || update.isLoading.value)

/** Builds the validated entitlements map, or `null` (with an inline row error) on the first bad
 * row -- a blank key is silently skipped (an in-progress row the operator hasn't finished). */
function buildEntitlements(): PlanEntitlements | null {
  const out: PlanEntitlements = {}
  for (const row of entitlementRows.value) {
    const key = row.key.trim()
    if (key === '') continue
    if (row.kind === 'granted') {
      out[key] = true
    } else if (row.kind === 'denied') {
      out[key] = false
    } else if (row.kind === 'unlimited') {
      out[key] = null
    } else {
      const trimmed = row.limitInput.trim()
      if (!/^\d+$/.test(trimmed)) {
        submitError.value = `Entitlement "${key}" needs a non-negative whole-number limit.`
        return null
      }
      out[key] = Number(trimmed)
    }
  }
  return out
}

async function submit() {
  resetErrors()

  if (!editing.value && state.planKey.trim() === '') {
    planKeyError.value = 'Plan key is required.'
  }
  if (state.displayName.trim() === '') {
    displayNameError.value = 'Display name is required.'
  }
  const sortTrimmed = state.sortOrderInput.trim()
  const sortOrder = sortTrimmed === '' ? 0 : Number(sortTrimmed)
  if (!/^-?\d+$/.test(sortTrimmed === '' ? '0' : sortTrimmed)) {
    sortOrderError.value = 'Sort order must be a whole number.'
  }

  const entitlements = buildEntitlements()
  const providerIdentifiers = entitlements === null ? null : buildProviderIdentifiers()

  if (
    planKeyError.value !== null ||
    displayNameError.value !== null ||
    sortOrderError.value !== null ||
    entitlements === null ||
    providerIdentifiers === null
  ) {
    return
  }

  try {
    if (editing.value) {
      const payload: UpdatePlanInput = {
        display_name: state.displayName.trim(),
        description: state.description.trim() === '' ? null : state.description.trim(),
        entitlements,
        provider_price_id: state.providerPriceId.trim() === '' ? null : state.providerPriceId.trim(),
        provider_identifiers: providerIdentifiers,
        status: state.status,
        sort_order: sortOrder,
      }
      await update.mutateAsync({ planKey: editing.value.plan_key, input: payload })
      success('Plan saved', `“${payload.display_name}” was updated.`)
    } else {
      const payload: CreatePlanInput = {
        plan_key: state.planKey.trim(),
        display_name: state.displayName.trim(),
        description: state.description.trim() === '' ? null : state.description.trim(),
        entitlements,
        provider_price_id: state.providerPriceId.trim() === '' ? null : state.providerPriceId.trim(),
        provider_identifiers: providerIdentifiers,
        status: state.status,
        sort_order: sortOrder,
      }
      await create.mutateAsync(payload)
      success('Plan created', `“${payload.display_name}” is ready.`)
    }
    emit('update:open', false)
  } catch (e) {
    const err = toApiError(e)
    submitError.value = err.message
    notifyError(err, editing.value ? 'Couldn’t save plan' : 'Couldn’t create plan')
  }
}
</script>

<template>
  <USlideover
    :open="open"
    :title="editing ? 'Edit plan' : 'Create plan'"
    :ui="{ content: 'sm:max-w-lg' }"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <form id="plan-form" class="space-y-4" @submit.prevent="submit">
        <UFormField
          label="Plan key"
          name="planKey"
          required
          :error="planKeyError ?? undefined"
          :help="editing ? 'Immutable once created.' : 'e.g. pro — lowercase, digits, ., _, -.'"
        >
          <UInput
            v-model="state.planKey"
            :disabled="!!editing"
            placeholder="e.g. pro"
            class="w-full"
            data-test="plan-key-input"
          />
        </UFormField>

        <UFormField label="Display name" name="displayName" required :error="displayNameError ?? undefined">
          <UInput v-model="state.displayName" class="w-full" data-test="plan-display-name-input" />
        </UFormField>

        <UFormField label="Description" name="description" help="Optional.">
          <UTextarea v-model="state.description" class="w-full" :rows="2" data-test="plan-description-input" />
        </UFormField>

        <UFormField label="Status" name="status">
          <USelect v-model="state.status" :items="STATUS_ITEMS" class="w-full" data-test="plan-status-input" />
        </UFormField>

        <UFormField label="Provider price ID" name="providerPriceId" help="Optional.">
          <UInput v-model="state.providerPriceId" class="w-full" data-test="plan-provider-price-id-input" />
        </UFormField>

        <UFormField label="Sort order" name="sortOrder" :error="sortOrderError ?? undefined">
          <UInput v-model="state.sortOrderInput" class="w-full" data-test="plan-sort-order-input" />
        </UFormField>

        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium">Entitlements</span>
            <UButton
              size="xs"
              variant="ghost"
              icon="i-lucide-plus"
              label="Add"
              data-test="plan-entitlement-add"
              @click="addEntitlementRow"
            />
          </div>
          <div
            v-for="(row, index) in entitlementRows"
            :key="index"
            class="flex items-center gap-2"
            data-test="plan-entitlement-row"
          >
            <UInput
              v-model="row.key"
              placeholder="entitlement key"
              class="flex-1"
              data-test="plan-entitlement-key-input"
            />
            <USelect v-model="row.kind" :items="KIND_ITEMS" class="w-32" data-test="plan-entitlement-kind-input" />
            <UInput
              v-if="row.kind === 'limited'"
              v-model="row.limitInput"
              placeholder="0"
              class="w-20"
              data-test="plan-entitlement-limit-input"
            />
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-x"
              data-test="plan-entitlement-remove"
              @click="removeEntitlementRow(index)"
            />
          </div>
          <p v-if="entitlementRows.length === 0" class="text-sm text-muted" data-test="plan-entitlement-empty">
            No entitlements yet.
          </p>
        </div>

        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium">Provider identifiers</span>
            <UButton
              size="xs"
              variant="ghost"
              icon="i-lucide-plus"
              label="Add"
              data-test="plan-identifier-add"
              @click="addIdentifierRow"
            />
          </div>
          <div
            v-for="(row, index) in identifierRows"
            :key="index"
            class="flex items-center gap-2"
            data-test="plan-identifier-row"
          >
            <UInput
              v-model="row.gateway"
              placeholder="gateway (e.g. stripe)"
              class="w-32"
              data-test="plan-identifier-gateway-input"
            />
            <UInput
              v-model="row.identifier"
              placeholder="identifier (e.g. price_123)"
              class="flex-1"
              data-test="plan-identifier-value-input"
            />
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-x"
              data-test="plan-identifier-remove"
              @click="removeIdentifierRow(index)"
            />
          </div>
          <p v-if="identifierRows.length === 0" class="text-sm text-muted" data-test="plan-identifier-empty">
            No provider identifiers yet — this plan isn't purchasable through any gateway.
          </p>
        </div>

        <UAlert
          v-if="submitError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :title="submitError"
          data-test="plan-form-error"
        />
      </form>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          data-test="plan-dismiss"
          @click="emit('update:open', false)"
        />
        <UButton
          type="submit"
          form="plan-form"
          data-test="plan-form-submit"
          :loading="isLoading"
          :label="editing ? 'Save' : 'Create'"
        />
      </div>
    </template>
  </USlideover>
</template>
