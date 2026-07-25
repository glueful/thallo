<script setup lang="ts">
// The Store settings tab (store-settings spec §3.5): six runtime-editable settings backed by
// thallo's own `settings` table through Commerce's settings seam — currency (LOCKED once ORDERS
// exist; with only draft products it stays editable and reinterprets their numbers, warned
// inline), tax rate (entered as a percent, stored as basis points), order number format
// (live preview), order expiry, cart TTL, and the low-stock threshold the product Health card
// reads. Each field shows its server default as help when not overridden and offers a per-field
// reset (which sends null — the override row is DELETED server-side, never blanked).
import { computed, reactive, ref, watch } from 'vue'
import {
  useStoreSettings,
  useSaveStoreSettings,
  type StoreSettingsSave,
} from '@/queries/commerceSettings'
import { useNotify } from '@/composables/useNotify'
import { toApiError } from '@/api/errors'

defineProps<{ canManage: boolean }>()

const { success, error: notifyError } = useNotify()
const { data: settings, status } = useStoreSettings()
const save = useSaveStoreSettings()

// Local drafts as STRINGS (inputs), hydrated from the effective values whenever fresh data
// arrives and the form isn't dirty.
const form = reactive({
  currency: '',
  taxPercent: '',
  numberFormat: '',
  expiryMinutes: '',
  cartTtlDays: '',
  lowStockThreshold: '',
  downloadsUrlTtl: '',
  sellerName: '',
  sellerAddress: '',
  sellerTaxId: '',
})
const dirty = ref(false)
let syncing = false

function hydrate(): void {
  const s = settings.value
  if (!s) return
  syncing = true
  form.currency = String(s.settings['commerce.currency']?.value ?? '')
  // bps → percent for humans: 750 bps reads as "7.5".
  form.taxPercent = String(Number(s.settings['commerce.tax.flat_rate_bps']?.value ?? 0) / 100)
  form.numberFormat = String(s.settings['commerce.orders.number_format']?.value ?? '')
  form.expiryMinutes = String(s.settings['commerce.orders.expiry_minutes']?.value ?? '')
  form.cartTtlDays = String(s.settings['commerce.cart.ttl_days']?.value ?? '')
  form.lowStockThreshold = String(s.settings['commerce.reports.low_stock_threshold']?.value ?? '')
  form.downloadsUrlTtl = String(s.settings['commerce.downloads.url_ttl']?.value ?? '')
  form.sellerName = String(s.settings['commerce.seller.name']?.value ?? '')
  form.sellerAddress = String(s.settings['commerce.seller.address']?.value ?? '')
  form.sellerTaxId = String(s.settings['commerce.seller.tax_id']?.value ?? '')
  dirty.value = false
  queueMicrotask(() => {
    syncing = false
  })
}

watch(settings, () => {
  if (!dirty.value) hydrate()
}, { immediate: true })

watch(form, () => {
  if (!syncing) dirty.value = true
})

/** Curated ISO-4217 list for the dropdown — codes the storefront money formatter handles,
 * majors + the African/zero-decimal currencies this product actually meets. The VALUE is always
 * the bare code; an env-configured currency outside this list still renders (appended below). */
const CURRENCIES: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'USD', label: 'USD — US Dollar' },
  { value: 'EUR', label: 'EUR — Euro' },
  { value: 'GBP', label: 'GBP — British Pound' },
  { value: 'GHS', label: 'GHS — Ghanaian Cedi' },
  { value: 'NGN', label: 'NGN — Nigerian Naira' },
  { value: 'KES', label: 'KES — Kenyan Shilling' },
  { value: 'ZAR', label: 'ZAR — South African Rand' },
  { value: 'XOF', label: 'XOF — West African CFA Franc' },
  { value: 'XAF', label: 'XAF — Central African CFA Franc' },
  { value: 'EGP', label: 'EGP — Egyptian Pound' },
  { value: 'MAD', label: 'MAD — Moroccan Dirham' },
  { value: 'TZS', label: 'TZS — Tanzanian Shilling' },
  { value: 'UGX', label: 'UGX — Ugandan Shilling' },
  { value: 'RWF', label: 'RWF — Rwandan Franc' },
  { value: 'CAD', label: 'CAD — Canadian Dollar' },
  { value: 'AUD', label: 'AUD — Australian Dollar' },
  { value: 'JPY', label: 'JPY — Japanese Yen' },
  { value: 'CNY', label: 'CNY — Chinese Yuan' },
  { value: 'INR', label: 'INR — Indian Rupee' },
  { value: 'BRL', label: 'BRL — Brazilian Real' },
  { value: 'MXN', label: 'MXN — Mexican Peso' },
  { value: 'CHF', label: 'CHF — Swiss Franc' },
  { value: 'SEK', label: 'SEK — Swedish Krona' },
  { value: 'NOK', label: 'NOK — Norwegian Krone' },
  { value: 'DKK', label: 'DKK — Danish Krone' },
  { value: 'AED', label: 'AED — UAE Dirham' },
  { value: 'SAR', label: 'SAR — Saudi Riyal' },
]

/** The dropdown's items — the current effective value always appears, even when it came from an
 * env config outside the curated list (never render a select whose value isn't among options). */
const currencyItems = computed<{ value: string; label: string }[]>(() => {
  const current = form.currency.trim().toUpperCase()
  if (current !== '' && !CURRENCIES.some((c) => c.value === current)) {
    return [{ value: current, label: current }, ...CURRENCIES]
  }
  return [...CURRENCIES]
})

const currencyLocked = computed(() => settings.value?.currency_locked === true)
/** Priced products, no orders yet: changing currency KEEPS the price numbers — warn honestly. */
const currencyReinterprets = computed(
  () => !currencyLocked.value && settings.value?.has_priced_products === true,
)

/** Help line per field: the server default, shown when the field is NOT overridden. */
function defaultHelp(key: string): string | undefined {
  const entry = settings.value?.settings[key]
  if (!entry || entry.overridden) return undefined
  const shown = key === 'commerce.tax.flat_rate_bps' ? `${Number(entry.default) / 100}%` : entry.default
  return `Default: ${shown} — from server config`
}

function overridden(key: string): boolean {
  return settings.value?.settings[key]?.overridden === true
}

const numberFormatPreview = computed(() =>
  form.numberFormat.includes('{seq}') ? form.numberFormat.replace('{seq}', '1042') : null,
)


const fieldErrors = reactive<Record<string, string>>({})

async function submit(): Promise<void> {
  for (const key of Object.keys(fieldErrors)) delete fieldErrors[key]

  // Percent → bps, via cents-style integer math (never float bps).
  const percent = form.taxPercent.trim()
  const bps = percent === '' ? null : Math.round(Number(percent) * 100)
  if (bps !== null && (!Number.isFinite(bps) || bps < 0)) {
    fieldErrors['commerce.tax.flat_rate_bps'] = 'Enter a valid tax percentage.'
    return
  }

  const body: StoreSettingsSave = {
    'commerce.currency': form.currency.trim() === '' ? null : form.currency.trim().toUpperCase(),
    'commerce.tax.flat_rate_bps': bps,
    'commerce.orders.number_format': form.numberFormat.trim() === '' ? null : form.numberFormat.trim(),
    'commerce.orders.expiry_minutes': form.expiryMinutes.trim() === '' ? null : form.expiryMinutes.trim(),
    'commerce.cart.ttl_days': form.cartTtlDays.trim() === '' ? null : form.cartTtlDays.trim(),
    'commerce.reports.low_stock_threshold':
      form.lowStockThreshold.trim() === '' ? null : form.lowStockThreshold.trim(),
    'commerce.downloads.url_ttl':
      form.downloadsUrlTtl.trim() === '' ? null : form.downloadsUrlTtl.trim(),
    'commerce.seller.name': form.sellerName.trim() === '' ? null : form.sellerName.trim(),
    'commerce.seller.address': form.sellerAddress.trim() === '' ? null : form.sellerAddress.trim(),
    'commerce.seller.tax_id': form.sellerTaxId.trim() === '' ? null : form.sellerTaxId.trim(),
  }

  try {
    await save.mutateAsync(body)
    dirty.value = false
    hydrate()
    success('Store settings saved', 'Changes apply on the next request.')
  } catch (e) {
    const err = toApiError(e)
    for (const [field, message] of Object.entries(err.fieldErrors)) {
      fieldErrors[field] = message
    }
    notifyError(err, 'Couldn’t save store settings')
  }
}

/** Per-field reset: clear the draft; the save sends null for blank fields (= back to default). */
function resetField(field: keyof typeof form): void {
  form[field] = ''
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="store-settings-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load store settings"
    data-test="store-settings-error"
  />

  <div v-else class="max-w-2xl space-y-6" data-test="store-settings-panel">
    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField
        label="Currency"
        :error="fieldErrors['commerce.currency']"
        :help="
          currencyLocked
            ? 'Locked — orders exist; recorded amounts are integers in the order’s currency.'
            : currencyReinterprets
              ? 'Existing prices keep their numbers ($700.00 becomes GH₵700.00) — review prices after changing.'
              : defaultHelp('commerce.currency')
        "
      >
        <USelect
          v-model="form.currency"
          :items="currencyItems"
          class="w-full"
          placeholder="Choose a currency"
          :disabled="!canManage || currencyLocked"
          data-test="store-currency-input"
        />
      </UFormField>

      <UFormField
        label="Tax rate (%)"
        :error="fieldErrors['commerce.tax.flat_rate_bps']"
        :help="defaultHelp('commerce.tax.flat_rate_bps')"
      >
        <UInput
          v-model="form.taxPercent"
          inputmode="decimal"
          placeholder="0"
          class="w-full"
          :disabled="!canManage"
          data-test="store-tax-input"
        />
      </UFormField>

      <UFormField
        label="Order number format"
        class="sm:col-span-2"
        :error="fieldErrors['commerce.orders.number_format']"
        :help="defaultHelp('commerce.orders.number_format')"
      >
        <UInput
          v-model="form.numberFormat"
          placeholder="ORD-{seq}"
          class="w-full"
          :disabled="!canManage"
          data-test="store-number-format-input"
        />
        <p v-if="numberFormatPreview" class="mt-1 text-xs text-muted" data-test="store-number-format-preview">
          Preview: {{ numberFormatPreview }}
        </p>
      </UFormField>

      <UFormField
        label="Order payment window (minutes)"
        :error="fieldErrors['commerce.orders.expiry_minutes']"
        :help="defaultHelp('commerce.orders.expiry_minutes')"
      >
        <UInput
          v-model="form.expiryMinutes"
          inputmode="numeric"
          class="w-full"
          :disabled="!canManage"
          data-test="store-expiry-input"
        />
      </UFormField>

      <UFormField
        label="Cart lifetime (days)"
        :error="fieldErrors['commerce.cart.ttl_days']"
        :help="defaultHelp('commerce.cart.ttl_days')"
      >
        <UInput
          v-model="form.cartTtlDays"
          inputmode="numeric"
          class="w-full"
          :disabled="!canManage"
          data-test="store-cart-ttl-input"
        />
      </UFormField>

      <UFormField
        label="Low-stock threshold"
        :error="fieldErrors['commerce.reports.low_stock_threshold']"
        help="Products at or below this tracked quantity show a low-stock warning."
      >
        <UInput
          v-model="form.lowStockThreshold"
          inputmode="numeric"
          class="w-full"
          :disabled="!canManage"
          data-test="store-low-stock-input"
        />
      </UFormField>

      <UFormField
        label="Download link lifetime (seconds)"
        :error="fieldErrors['commerce.downloads.url_ttl']"
        :help="defaultHelp('commerce.downloads.url_ttl') ?? 'Signed download URLs expire after this many seconds (60–604800).'"
      >
        <UInput
          v-model="form.downloadsUrlTtl"
          inputmode="numeric"
          class="w-full"
          :disabled="!canManage"
          data-test="store-downloads-ttl-input"
        />
      </UFormField>
    </div>

    <!-- Store identity (spec §3.6): the invoice header — name, address, tax id. -->
    <div class="space-y-4">
      <p class="text-[0.68rem] font-bold tracking-wider text-muted uppercase">Store identity</p>
      <div class="grid gap-4 sm:grid-cols-2">
        <UFormField
          label="Store name"
          :error="fieldErrors['commerce.seller.name']"
          :help="defaultHelp('commerce.seller.name') ?? 'Shown on invoices and order emails.'"
        >
          <UInput
            v-model="form.sellerName"
            class="w-full"
            maxlength="200"
            :disabled="!canManage"
            data-test="store-seller-name-input"
          />
        </UFormField>

        <UFormField
          label="Tax ID"
          :error="fieldErrors['commerce.seller.tax_id']"
          help="Optional — VAT/TIN shown on invoices."
        >
          <UInput
            v-model="form.sellerTaxId"
            class="w-full"
            maxlength="64"
            :disabled="!canManage"
            data-test="store-seller-tax-id-input"
          />
        </UFormField>

        <UFormField
          label="Business address"
          class="sm:col-span-2"
          :error="fieldErrors['commerce.seller.address']"
          help="Optional — shown on invoices."
        >
          <UTextarea
            v-model="form.sellerAddress"
            :rows="2"
            class="w-full"
            maxlength="500"
            :disabled="!canManage"
            data-test="store-seller-address-input"
          />
        </UFormField>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
      <UButton
        v-if="canManage"
        label="Save settings"
        :loading="save.isLoading.value"
        data-test="store-settings-save"
        @click="submit"
      />
      <UButton
        v-if="canManage && overridden('commerce.tax.flat_rate_bps')"
        size="xs"
        color="neutral"
        variant="ghost"
        label="Reset tax to default"
        data-test="store-tax-reset"
        @click="resetField('taxPercent')"
      />
    </div>

    <!-- Discoverability for the order emails (spec §3.5): editing lives on the EXISTING page. -->
    <div
      class="flex items-center gap-2 rounded-md border border-default px-3 py-2 text-sm text-muted"
      data-test="store-email-pointer"
    >
      <UIcon name="i-lucide-mail" class="size-4" />
      <span>
        Order emails (confirmation, payment, fulfillment, cancellation) are managed in the
        <span class="font-medium text-default">Emails</span> tab of these settings.
      </span>
    </div>
  </div>
</template>
