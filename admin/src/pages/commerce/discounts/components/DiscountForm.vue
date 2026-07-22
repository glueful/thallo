<script setup lang="ts">
// Task 14 (admin-commerce-area plan, slice 3): single dual-mode slideover for BOTH creating a
// discount and editing one — `discount` absent/null means create mode (mirrors CategoriesTab.vue's
// dual-mode pattern, adapted to a slideover since Discounts is a single-page domain with no detail
// route to host an inline edit form).
//
// `value`/`min_subtotal` are both genuine currency-shaped decimal entry, following the SAME
// decimal-string -> minor-units discipline as RefundSlideover.vue: the admin types a human decimal
// ("10.00", "12.34"), and `parseMajorAmountToMinorUnits()` parses it with plain BigInt/regex string
// math — never `Number()`, never float division — so no rounding can smuggle in an off-by-one
// minor-unit (or off-by-one-basis-point) amount.
//
// For a `percentage` discount, `value` on the wire is basis points of a percent (`value / 100` is
// the percent — see commerceDiscounts.ts's own docblock and PricingEngine::price()'s
// `intdiv($base * value + 5000, 10000)`), which is EXACTLY what `parseMajorAmountToMinorUnits(input,
// 2)` already computes for a "2-decimal-place currency" — reused here as a bps parser: "12.34"
// (percent) -> 1234 (bps), with the same no-float-rounding guarantee, not a genuine money amount.
import { computed, reactive, ref, watch } from 'vue'
import {
  useCommerceDiscountMutations,
  DISCOUNT_TYPES,
  DISCOUNT_STATUSES,
  type CommerceDiscount,
  type CreateDiscountInput,
  type UpdateDiscountInput,
} from '@/queries/commerceDiscounts'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { parseMajorAmountToMinorUnits } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{
  open: boolean
  discount?: CommerceDiscount | null
}>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const { success, error: notifyError } = useNotify()
const { create, update } = useCommerceDiscountMutations()
const { data: meta } = useCommerceMeta()

const editing = computed(() => props.discount ?? null)
const currencyExponent = computed(() => meta.value?.currency_exponent ?? 2)

const typeItems = DISCOUNT_TYPES.map((t) => ({ label: t, value: t }))
const statusItems = DISCOUNT_STATUSES.map((s) => ({ label: s, value: s }))

/** Reverse of `parseMajorAmountToMinorUnits()` — plain BigInt division/modulo, no `Number()` on
 * the way, for pre-populating an existing minor-unit (or bps) integer as an editable decimal
 * string. */
function minorToDecimalString(minor: number, exponent: number): string {
  if (exponent === 0) return String(minor)
  const abs = BigInt(Math.trunc(Math.abs(minor)))
  const scale = 10n ** BigInt(exponent)
  const major = abs / scale
  const fraction = (abs % scale).toString().padStart(exponent, '0')
  return `${major}.${fraction}`
}

interface FormState {
  code: string
  type: (typeof DISCOUNT_TYPES)[number]
  valueInput: string
  minSubtotalInput: string
  usageLimitInput: string
  oncePerBuyer: boolean
  status: (typeof DISCOUNT_STATUSES)[number]
  startsAt: string
  endsAt: string
}

function blankState(): FormState {
  return {
    code: '',
    type: 'percentage',
    valueInput: '',
    minSubtotalInput: '',
    usageLimitInput: '',
    oncePerBuyer: false,
    status: 'active',
    startsAt: '',
    endsAt: '',
  }
}

function stateFromDiscount(d: CommerceDiscount): FormState {
  const valueExponent = d.type === 'fixed' ? currencyExponent.value : 2
  return {
    code: d.code,
    type: d.type === 'fixed' ? 'fixed' : 'percentage',
    valueInput: minorToDecimalString(d.value, valueExponent),
    minSubtotalInput:
      d.min_subtotal !== null ? minorToDecimalString(d.min_subtotal, currencyExponent.value) : '',
    usageLimitInput: d.usage_limit !== null ? String(d.usage_limit) : '',
    oncePerBuyer: d.once_per_buyer,
    status: d.status === 'inactive' ? 'inactive' : 'active',
    startsAt: (d.starts_at ?? '').slice(0, 10),
    endsAt: (d.ends_at ?? '').slice(0, 10),
  }
}

const state = reactive(blankState())

const codeError = ref<string | null>(null)
const valueError = ref<string | null>(null)
const minSubtotalError = ref<string | null>(null)
const usageLimitError = ref<string | null>(null)
const datesError = ref<string | null>(null)
const submitError = ref<string | null>(null)

function resetErrors() {
  codeError.value = null
  valueError.value = null
  minSubtotalError.value = null
  usageLimitError.value = null
  datesError.value = null
  submitError.value = null
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return
    Object.assign(state, editing.value ? stateFromDiscount(editing.value) : blankState())
    resetErrors()
  },
)

const isLoading = computed(() => create.isLoading.value || update.isLoading.value)

/** Parses+validates the type-dependent `value` field. Percentage: 0.01–100 (a friendly UX guard —
 * the server's own non-negative check stays authoritative). Fixed: at least 1 minor unit, no
 * client-side ceiling (the server caps a fixed discount to the cart base dynamically). */
function validateValue(): number | null {
  const trimmed = state.valueInput.trim()
  if (trimmed === '') {
    valueError.value = 'Enter a value.'
    return null
  }

  if (state.type === 'percentage') {
    const bps = parseMajorAmountToMinorUnits(trimmed, 2)
    if (bps === null) {
      valueError.value = 'Enter a valid percentage (up to 2 decimal places).'
      return null
    }
    if (bps < 1n || bps > 10000n) {
      valueError.value = 'Enter a percentage between 0.01 and 100.'
      return null
    }
    valueError.value = null
    return Number(bps)
  }

  const minor = parseMajorAmountToMinorUnits(trimmed, currencyExponent.value)
  if (minor === null) {
    valueError.value =
      currencyExponent.value === 0
        ? 'Enter a whole-number amount.'
        : `Enter a valid amount (up to ${currencyExponent.value} decimal places).`
    return null
  }
  if (minor < 1n) {
    valueError.value = 'Amount must be at least the smallest currency unit.'
    return null
  }
  valueError.value = null
  return Number(minor)
}

/** Optional minimum-cart-subtotal money field — empty means "no minimum" (`null`). */
function validateMinSubtotal(): number | null | undefined {
  const trimmed = state.minSubtotalInput.trim()
  if (trimmed === '') {
    minSubtotalError.value = null
    return null
  }
  const minor = parseMajorAmountToMinorUnits(trimmed, currencyExponent.value)
  if (minor === null) {
    minSubtotalError.value =
      currencyExponent.value === 0
        ? 'Enter a whole-number amount.'
        : `Enter a valid amount (up to ${currencyExponent.value} decimal places).`
    return undefined
  }
  minSubtotalError.value = null
  return Number(minor)
}

/** Optional usage-limit count — a plain non-negative integer, empty means "unlimited" (`null`). */
function validateUsageLimit(): number | null | undefined {
  const trimmed = state.usageLimitInput.trim()
  if (trimmed === '') {
    usageLimitError.value = null
    return null
  }
  if (!/^\d+$/.test(trimmed)) {
    usageLimitError.value = 'Enter a whole, non-negative number.'
    return undefined
  }
  usageLimitError.value = null
  return Number(trimmed)
}

function validateDates(): boolean {
  if (state.startsAt !== '' && state.endsAt !== '' && state.startsAt > state.endsAt) {
    datesError.value = 'End date must be after start date.'
    return false
  }
  datesError.value = null
  return true
}

async function submit() {
  resetErrors()

  if (state.code.trim() === '') {
    codeError.value = 'Code is required.'
  }
  const value = validateValue()
  const minSubtotal = validateMinSubtotal()
  const usageLimit = validateUsageLimit()
  const datesOk = validateDates()

  if (
    codeError.value !== null ||
    value === null ||
    minSubtotal === undefined ||
    usageLimit === undefined ||
    !datesOk
  ) {
    return
  }

  const payload = {
    code: state.code.trim(),
    type: state.type,
    value,
    min_subtotal: minSubtotal,
    usage_limit: usageLimit,
    once_per_buyer: state.oncePerBuyer,
    status: state.status,
    starts_at: state.startsAt || null,
    ends_at: state.endsAt || null,
  }

  try {
    if (editing.value) {
      await update.mutateAsync({ uuid: editing.value.uuid, input: payload as UpdateDiscountInput })
      success('Discount saved', `“${payload.code}” was updated.`)
    } else {
      await create.mutateAsync(payload as CreateDiscountInput)
      success('Discount created', `“${payload.code}” is ready.`)
    }
    emit('update:open', false)
  } catch (e) {
    const err = toApiError(e)
    submitError.value =
      err.fieldErrors.code ??
      err.fieldErrors.type ??
      err.fieldErrors.value ??
      err.fieldErrors.min_subtotal ??
      err.fieldErrors.usage_limit ??
      err.fieldErrors.ends_at ??
      err.message
    notifyError(err, editing.value ? 'Couldn’t save discount' : 'Couldn’t create discount')
  }
}
</script>

<template>
  <USlideover
    :open="open"
    :title="editing ? 'Edit discount' : 'Create discount'"
    :ui="{ content: 'sm:max-w-lg' }"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <form id="discount-form" class="space-y-4" @submit.prevent="submit">
        <UFormField label="Code" name="code" required :error="codeError ?? undefined">
          <UInput v-model="state.code" placeholder="e.g. SAVE10" class="w-full" data-test="discount-code-input" />
        </UFormField>

        <UFormField label="Type" name="type">
          <USelect v-model="state.type" :items="typeItems" class="w-full" data-test="discount-type-input" />
        </UFormField>

        <UFormField
          :label="state.type === 'percentage' ? 'Percentage' : 'Amount'"
          name="value"
          required
          :error="valueError ?? undefined"
          :help="state.type === 'percentage' ? 'e.g. 10 for 10% off' : 'e.g. 5.00 off the order'"
        >
          <UInput v-model="state.valueInput" placeholder="0.00" class="w-full" data-test="discount-value-input" />
        </UFormField>

        <UFormField
          label="Minimum cart subtotal"
          name="minSubtotal"
          :error="minSubtotalError ?? undefined"
          help="Optional — leave blank for no minimum."
        >
          <UInput
            v-model="state.minSubtotalInput"
            placeholder="0.00"
            class="w-full"
            data-test="discount-min-subtotal-input"
          />
        </UFormField>

        <UFormField
          label="Usage limit"
          name="usageLimit"
          :error="usageLimitError ?? undefined"
          help="Optional — leave blank for unlimited uses."
        >
          <UInput
            v-model="state.usageLimitInput"
            placeholder="Unlimited"
            class="w-full"
            data-test="discount-usage-limit-input"
          />
        </UFormField>

        <UCheckbox
          v-model="state.oncePerBuyer"
          label="Limit to one use per buyer"
          data-test="discount-once-per-buyer-checkbox"
        />

        <UFormField label="Status" name="status">
          <USelect v-model="state.status" :items="statusItems" class="w-full" data-test="discount-status-input" />
        </UFormField>

        <div class="grid grid-cols-2 gap-4">
          <UFormField label="Starts" name="startsAt" :error="datesError ?? undefined">
            <UInput v-model="state.startsAt" type="date" class="w-full" data-test="discount-starts-at-input" />
          </UFormField>
          <UFormField label="Ends" name="endsAt">
            <UInput v-model="state.endsAt" type="date" class="w-full" data-test="discount-ends-at-input" />
          </UFormField>
        </div>

        <UAlert
          v-if="submitError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :title="submitError"
          data-test="discount-form-error"
        />
      </form>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          data-test="discount-dismiss"
          @click="emit('update:open', false)"
        />
        <UButton
          type="submit"
          form="discount-form"
          data-test="discount-form-submit"
          :loading="isLoading"
          :label="editing ? 'Save' : 'Create'"
        />
      </div>
    </template>
  </USlideover>
</template>
