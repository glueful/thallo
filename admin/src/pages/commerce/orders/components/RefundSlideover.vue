<script setup lang="ts">
// Task 13c (admin-commerce-area plan, slice 3): issue an order refund. Visibility of the trigger
// that opens this slideover is gated by BOTH `canManage` and `canRefundOrder(order.status)` at the
// call site (OrderActions.vue) — this component only builds and submits the request once opened.
//
// The amount field is entered in MAJOR units (e.g. "12.34") but every byte sent over the wire is
// an EXACT minor-unit integer: `parseMajorAmountToMinorUnits()` parses the typed decimal string
// with plain BigInt/regex string math — never `Number()`, never float division — and rejects
// anything with more fractional digits than the currency's own exponent allows. The refundable
// "ceiling" shown here (`order.grand_total - order.refunded_total`) is UX guidance ONLY — it can't
// see a gateway refund's own PENDING sum, which only the server does (RefundService::validate()'s
// `remaining` calculation) — so the server's own 422/409 stays authoritative and is surfaced
// verbatim on rejection, never replaced by a generic message.
import { computed, ref, watch } from 'vue'
import { useCommerceOrderMutations, type CommerceOrder } from '@/queries/commerceOrders'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useMoney, parseMajorAmountToMinorUnits } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{
  order: CommerceOrder
  open: boolean
}>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const { success } = useNotify()
const { refund } = useCommerceOrderMutations()
const { data: meta } = useCommerceMeta()
const { format } = useMoney()

const exponent = computed(() => meta.value?.currency_exponent ?? 2)
// UX guidance only (see module docblock) — the server remains authoritative on the real ceiling.
const ceilingMinor = computed(() => Math.max(0, props.order.grand_total - props.order.refunded_total))

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// never crashes the render (mirrors OrderDetail's identical `money()` helper).
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

const amountInput = ref('')
const reason = ref('')
const restock = ref(false)
const amountFieldError = ref<string | null>(null)
const submitError = ref<string | null>(null)
// Regenerated every time the slideover opens: retries WITHIN one open session (e.g. after a
// server rejection) safely replay against the same key, while reopening the slideover always
// starts a genuinely new logical request (see createRefund()'s docblock in commerceOrders.ts).
const idempotencyKey = ref('')

function resetForm() {
  amountInput.value = ''
  reason.value = ''
  restock.value = false
  amountFieldError.value = null
  submitError.value = null
  idempotencyKey.value = crypto.randomUUID()
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) resetForm()
  },
)

/**
 * Parses and validates the typed amount against the exact minor-unit discipline (task-13c brief):
 * rejects blank/non-numeric input, more fractional digits than the currency exponent allows,
 * amounts under 1 minor unit, and amounts over the client-computed ceiling. Returns the parsed
 * `BigInt` on success, or `null` after setting `amountFieldError` for inline display.
 */
function validateAmount(): bigint | null {
  const trimmed = amountInput.value.trim()
  if (trimmed === '') {
    amountFieldError.value = 'Enter an amount.'
    return null
  }

  const minor = parseMajorAmountToMinorUnits(trimmed, exponent.value)
  if (minor === null) {
    amountFieldError.value =
      exponent.value === 0
        ? 'Enter a whole-number amount.'
        : `Enter a valid amount (up to ${exponent.value} decimal place${exponent.value === 1 ? '' : 's'}).`
    return null
  }
  if (minor < 1n) {
    amountFieldError.value = 'Amount must be at least the smallest currency unit.'
    return null
  }
  if (minor > BigInt(ceilingMinor.value)) {
    amountFieldError.value = `Amount exceeds the refundable balance of ${money(ceilingMinor.value)}.`
    return null
  }

  amountFieldError.value = null
  return minor
}

async function submit() {
  submitError.value = null
  const minor = validateAmount()
  if (minor === null) return

  // Bounded by ceilingMinor (already a safe-integer `number`, same as every other order total in
  // this codebase) — this Number() conversion is lossless, never a re-introduction of float risk.
  const amountMinor = Number(minor)

  try {
    await refund.mutateAsync({
      uuid: props.order.uuid,
      input: {
        amount: amountMinor,
        reason: reason.value.trim() === '' ? null : reason.value.trim(),
        restock: restock.value,
      },
      idempotencyKey: idempotencyKey.value,
    })
    success('Refund recorded', `Refunded ${money(amountMinor)} on order ${props.order.order_number}.`)
    emit('update:open', false)
  } catch (e) {
    // Response::validation() puts the SPECIFIC reason under error.details (-> fieldErrors.refund /
    // .lines), while body.message stays the generic "Validation failed"; a 409/503 carries no
    // details, so err.message IS already the specific server message there (see errors.ts's
    // fieldErrorsFromDetails()/toApiError() docblocks). Either way the server's exact wording
    // surfaces verbatim — never replaced by a client-authored guess.
    const err = toApiError(e)
    submitError.value = err.fieldErrors.refund ?? err.fieldErrors.lines ?? err.message
  }
}
</script>

<template>
  <USlideover
    :open="open"
    title="Issue refund"
    :ui="{ content: 'sm:max-w-lg' }"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <form id="refund-form" class="space-y-4" @submit.prevent="submit">
        <p class="text-sm text-muted" data-test="refund-ceiling">
          Refundable balance:
          <span class="font-medium text-default">{{ money(ceilingMinor) }}</span>
        </p>

        <UFormField label="Amount" name="amount" required :error="amountFieldError ?? undefined">
          <UInput
            v-model="amountInput"
            placeholder="0.00"
            class="w-full"
            data-test="refund-amount-input"
          />
        </UFormField>

        <UFormField label="Reason" name="reason" help="Optional, up to 1000 characters.">
          <UTextarea v-model="reason" class="w-full" :rows="3" data-test="refund-reason-input" />
        </UFormField>

        <UCheckbox v-model="restock" label="Restock inventory" data-test="refund-restock-checkbox" />

        <UAlert
          v-if="submitError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :title="submitError"
          data-test="refund-error"
        />
      </form>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          data-test="refund-dismiss"
          @click="emit('update:open', false)"
        />
        <UButton
          type="submit"
          form="refund-form"
          color="error"
          data-test="refund-submit"
          :loading="refund.isLoading.value"
          label="Issue refund"
        />
      </div>
    </template>
  </USlideover>
</template>
