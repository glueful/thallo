<script setup lang="ts">
// Task 15c (admin-commerce-area plan, slice 3): tax-rate CRUD
// (`AdminTaxRateController` / `Glueful\Extensions\Commerce\Http\Admin\AdminTaxRateController`,
// backed by `TaxRateService`). Mirrors ClassesPanel.vue's single-file "list + slideover form +
// confirm-modal delete" shape — the task's own file list names only TaxRatesPanel.vue. Unlike a
// shipping class, a tax rate has no cross-table reference to guard at delete time
// (`TaxRateService`'s own docblock: "DELETE is unconditional once claimed") and every field is
// PATCHable (no immutable `slug`-style column), so the edit form always sends the FULL field set
// on submit — mirroring ZonesPanel.vue's zone-form precedent (`{name, position}` always both) —
// rather than a name-only diff like ClassesPanel.vue's locked-slug precedent.
//
// `rate_bps` is a genuine basis-points integer, 0..10000 inclusive (10000 = 100%) — verified
// directly against `TaxRateService::normalizeBps()` and `DbTaxCalculator::applyRate()`'s
// `intdiv($amount * $bps + 5000, 10000)`. This is EXACTLY the same convention as a `percentage`
// discount's `value` field (commerceDiscounts.ts's own docblock: `value / 100` is the percent),
// so the percent input follows the IDENTICAL round-trip discipline as DiscountForm.vue:
// `parseMajorAmountToMinorUnits(input, 2)` on the way in ("8.75" -> 875n), `minorToDecimalString()`
// on the way out (875 -> "8.75") — never `Number()`, never float division. Unlike a percentage
// discount (which requires >=1 bps), 0 is a valid boundary here (`normalizeBps`'s own `< 0`
// check, not `<= 0`) — a genuine 0% rate is a legitimate tax-exempt row.
import { computed, reactive, ref } from 'vue'
import {
  useCommerceTaxRates,
  useCommerceTaxRateMutations,
  type CommerceTaxRate,
} from '@/queries/commerceSettings'
import { parseMajorAmountToMinorUnits } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'

const props = defineProps<{ canManage: boolean }>()

const { success, error: notifyError } = useNotify()

/** Reverse of `parseMajorAmountToMinorUnits()` — plain BigInt division/modulo, no `Number()` on
 * the way, for pre-populating an existing bps integer as an editable percent decimal string
 * (mirrors DiscountForm.vue's identical helper). */
function minorToDecimalString(minor: number, exponent: number): string {
  if (exponent === 0) return String(minor)
  const abs = BigInt(Math.trunc(Math.abs(minor)))
  const scale = 10n ** BigInt(exponent)
  const major = abs / scale
  const fraction = (abs % scale).toString().padStart(exponent, '0')
  return `${major}.${fraction}`
}

/** Row-display percent text: bps/100, trimmed of a trailing ".00" for a round number — mirrors
 * DiscountsTable.vue's `valueText()` for a percentage discount exactly. Safe as plain float
 * division for DISPLAY ONLY (bps is bounded to 0..10000, well within float precision); the
 * round-trip-critical parse/format above never uses it. */
function percentText(rate: CommerceTaxRate): string {
  const percent = rate.rate_bps / 100
  return `${Number.isInteger(percent) ? percent : percent.toFixed(2)}%`
}

function locationSummary(rate: CommerceTaxRate): string | null {
  const parts = [rate.state, rate.postcode_pattern].filter((v): v is string => v !== null && v !== '')
  return parts.length > 0 ? parts.join(' · ') : null
}

// ── Rates list ────────────────────────────────────────────────────────────────

const page = ref(1)
const perPage = ref(24)
const filters = computed(() => ({ page: page.value, perPage: perPage.value }))
const { data, status } = useCommerceTaxRates(filters)
const rates = computed<CommerceTaxRate[]>(() => data.value?.rates ?? [])

const { createRate, updateRate, deleteRate } = useCommerceTaxRateMutations()

// ── Create/edit (shared slideover) ────────────────────────────────────────────

interface FormState {
  countryInput: string
  stateInput: string
  postcodeInput: string
  percentInput: string
  labelInput: string
  priorityInput: string
  shippingTaxable: boolean
  classInput: string
}

function blankForm(): FormState {
  return {
    countryInput: '',
    stateInput: '',
    postcodeInput: '',
    percentInput: '',
    labelInput: '',
    priorityInput: '0',
    shippingTaxable: false,
    classInput: '',
  }
}

const formOpen = ref(false)
const editingRate = ref<CommerceTaxRate | null>(null)
const form = reactive(blankForm())
const formError = ref<string | null>(null)

function openCreate() {
  editingRate.value = null
  Object.assign(form, blankForm())
  formError.value = null
  formOpen.value = true
}

function openEdit(rate: CommerceTaxRate) {
  editingRate.value = rate
  Object.assign(form, {
    countryInput: rate.country,
    stateInput: rate.state ?? '',
    postcodeInput: rate.postcode_pattern ?? '',
    percentInput: minorToDecimalString(rate.rate_bps, 2),
    labelInput: rate.label,
    priorityInput: String(rate.priority),
    shippingTaxable: rate.shipping_taxable,
    classInput: rate.class,
  })
  formError.value = null
  formOpen.value = true
}

function closeForm() {
  formOpen.value = false
}

const mutationLoading = computed(() => createRate.isLoading.value || updateRate.isLoading.value)

/** Parses+validates the percent field into an exact bps integer (0..10000 inclusive — 0 is a
 * valid boundary, unlike a percentage discount's value). Sets `formError` and returns `null` on
 * any failure. */
function validatePercent(): number | null {
  const trimmed = form.percentInput.trim()
  if (trimmed === '') {
    formError.value = 'Enter a rate.'
    return null
  }
  const bps = parseMajorAmountToMinorUnits(trimmed, 2)
  if (bps === null || bps > 10000n) {
    formError.value = 'Enter a percentage between 0 and 100 (up to 2 decimal places).'
    return null
  }
  return Number(bps)
}

async function submitForm() {
  formError.value = null

  const country = form.countryInput.trim()
  if (country === '') {
    formError.value = 'Country is required.'
    return
  }
  const label = form.labelInput.trim()
  if (label === '') {
    formError.value = 'Label is required.'
    return
  }
  const rateBps = validatePercent()
  if (rateBps === null) return

  const parsedPriority = Number.parseInt(form.priorityInput, 10)
  const priority = Number.isFinite(parsedPriority) ? parsedPriority : 0

  const payload = {
    country,
    state: form.stateInput.trim() === '' ? null : form.stateInput.trim(),
    postcode_pattern: form.postcodeInput.trim() === '' ? null : form.postcodeInput.trim(),
    rate_bps: rateBps,
    label,
    priority,
    shipping_taxable: form.shippingTaxable,
    class: form.classInput.trim() === '' ? null : form.classInput.trim(),
  }

  try {
    if (editingRate.value) {
      await updateRate.mutateAsync({ uuid: editingRate.value.uuid, input: payload })
      success('Tax rate saved', `“${label}” was updated.`)
    } else {
      await createRate.mutateAsync(payload)
      success('Tax rate created', `“${label}” is ready.`)
    }
    formOpen.value = false
  } catch (e) {
    const err = toApiError(e)
    formError.value =
      err.fieldErrors.country ??
      err.fieldErrors.state ??
      err.fieldErrors.postcode_pattern ??
      err.fieldErrors.rate_bps ??
      err.fieldErrors.label ??
      err.fieldErrors.class ??
      err.message
    notifyError(err, editingRate.value ? 'Couldn’t save tax rate' : 'Couldn’t create tax rate')
  }
}

// ── Delete ─────────────────────────────────────────────────────────────────────

const pendingDelete = ref<CommerceTaxRate | null>(null)
async function confirmDelete() {
  const rate = pendingDelete.value
  if (!rate) return
  try {
    await deleteRate.mutateAsync(rate.uuid)
    success('Tax rate deleted', `“${rate.label}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete tax rate')
    pendingDelete.value = null
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-medium text-default">Tax rates</h2>
      <UButton
        v-if="props.canManage"
        icon="i-lucide-plus"
        data-test="new-rate"
        @click="openCreate"
      >
        New rate
      </UButton>
    </div>

    <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="rates-loading">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>

    <UAlert
      v-else-if="status === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn’t load tax rates"
      description="Something went wrong loading tax rates. Try again."
      data-test="rates-error"
    />

    <UEmpty
      v-else-if="rates.length === 0"
      icon="i-lucide-percent"
      title="No tax rates"
      description="Create a rate to start taxing orders by country."
      data-test="rates-empty"
    />

    <div v-else class="space-y-2">
      <div
        v-for="rate in rates"
        :key="rate.uuid"
        data-test="rate-row"
        :data-uuid="rate.uuid"
        class="flex flex-wrap items-center gap-3 rounded-md border border-default p-3"
      >
        <span data-test="rate-label" class="font-medium text-default">{{ rate.label }}</span>
        <UBadge color="neutral" variant="subtle" size="sm" data-test="rate-country">{{ rate.country }}</UBadge>
        <span v-if="locationSummary(rate)" data-test="rate-location" class="text-sm text-muted">
          {{ locationSummary(rate) }}
        </span>
        <UBadge color="primary" variant="subtle" size="sm" data-test="rate-percent">{{ percentText(rate) }}</UBadge>
        <UBadge color="neutral" variant="subtle" size="sm" data-test="rate-class">{{ rate.class }}</UBadge>
        <UBadge color="neutral" variant="subtle" size="sm" data-test="rate-priority">priority {{ rate.priority }}</UBadge>
        <UBadge
          v-if="rate.shipping_taxable"
          color="neutral"
          variant="subtle"
          size="sm"
          data-test="rate-shipping-taxable"
        >
          Shipping taxable
        </UBadge>

        <div v-if="props.canManage" class="ml-auto flex gap-1">
          <UButton
            color="neutral"
            variant="ghost"
            size="xs"
            icon="i-lucide-pencil"
            aria-label="Edit rate"
            data-test="rate-edit"
            @click="openEdit(rate)"
          />
          <UButton
            color="error"
            variant="ghost"
            size="xs"
            icon="i-lucide-trash-2"
            aria-label="Delete rate"
            data-test="rate-delete"
            @click="() => { pendingDelete = rate }"
          />
        </div>
      </div>
    </div>

    <TablePagination
      v-if="(data?.total ?? 0) > 0"
      v-model:page="page"
      v-model:per-page="perPage"
      :total="data?.total ?? 0"
      label="rates"
    />
  </div>

  <!-- Create/edit slideover -->
  <USlideover
    :open="formOpen"
    :title="editingRate ? 'Edit tax rate' : 'Create tax rate'"
    :ui="{ content: 'sm:max-w-md' }"
    @update:open="(v: boolean) => { if (!v) closeForm() }"
  >
    <template #body>
      <form id="rate-form" class="space-y-4" @submit.prevent="submitForm">
        <div class="grid grid-cols-2 gap-3">
          <UFormField label="Country" name="country" required help="ISO-3166 alpha-2, e.g. US">
            <UInput v-model="form.countryInput" class="w-full" data-test="rate-country-input" />
          </UFormField>
          <UFormField label="Rate" name="rate" required help="Percent, e.g. 8.75 for 8.75%">
            <UInput v-model="form.percentInput" placeholder="0.00" class="w-full" data-test="rate-percent-input" />
          </UFormField>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <UFormField
            label="State"
            name="state"
            help="Optional — COUNTRY:REGION, e.g. US:CA. Country prefix must match above."
          >
            <UInput v-model="form.stateInput" placeholder="US:CA" class="w-full" data-test="rate-state-input" />
          </UFormField>
          <UFormField label="Postcode pattern" name="postcode" help="Optional — exact or trailing *, e.g. 90*">
            <UInput
              v-model="form.postcodeInput"
              placeholder="90210"
              class="w-full"
              data-test="rate-postcode-input"
            />
          </UFormField>
        </div>

        <UFormField label="Label" name="label" required>
          <UInput v-model="form.labelInput" class="w-full" data-test="rate-label-input" />
        </UFormField>

        <div class="grid grid-cols-2 gap-3">
          <UFormField label="Priority" name="priority" help="Lower priority is evaluated first.">
            <UInput v-model="form.priorityInput" type="number" class="w-full" data-test="rate-priority-input" />
          </UFormField>
          <UFormField label="Class" name="class" help="Optional — defaults to “standard”.">
            <UInput
              v-model="form.classInput"
              placeholder="standard"
              class="w-full"
              data-test="rate-class-input"
            />
          </UFormField>
        </div>

        <UCheckbox
          v-model="form.shippingTaxable"
          label="Tax shipping at this rate"
          data-test="rate-shipping-taxable-checkbox"
        />

        <UAlert
          v-if="formError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :title="formError"
          data-test="rate-form-error"
        />
      </form>
    </template>
    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          data-test="rate-dismiss"
          @click="closeForm"
        />
        <UButton
          type="submit"
          form="rate-form"
          data-test="rate-form-submit"
          :loading="mutationLoading"
          :label="editingRate ? 'Save' : 'Create'"
        />
      </div>
    </template>
  </USlideover>

  <!-- Delete confirm -->
  <UModal
    :open="pendingDelete !== null"
    title="Delete tax rate"
    @update:open="(v: boolean) => { if (!v) pendingDelete = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.label }}”</span>? This can’t be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="deleteRate.isLoading.value"
          @click="() => { pendingDelete = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          data-test="rate-delete-confirm"
          :loading="deleteRate.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
