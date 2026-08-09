<script setup lang="ts">
// Task 10 (orders-invoices-receipts spec): the Invoices & receipts settings tab — logo, footer,
// three optional-section toggles, and the print paper preset (Task 6's six `commerce.invoice.*`
// keys), plus a READ-ONLY mirror of the seller identity fields that live on the Store tab
// (store-settings spec §3.6). This panel never edits name/address/tax id itself — forking one
// printed-document identity into two editable copies would drift them apart — it only points back
// to Store via `edit-store`, which the settings shell (index.vue) handles by switching tabs.
import { computed, reactive, ref, watch } from 'vue'
import {
  useStoreSettings,
  useSaveStoreSettings,
  INVOICE_PAPER_PRESETS,
  type StoreSettingsSave,
  type InvoicePaperPreset,
} from '@/queries/commerceSettings'
import AssetField from '@/fields/components/AssetField.vue'
import { useNotify } from '@/composables/useNotify'
import { toApiError } from '@/api/errors'

defineProps<{ canManage: boolean }>()
const emit = defineEmits<{ 'edit-store': [] }>()

const { success, error: notifyError } = useNotify()
const { data: settings, status } = useStoreSettings()
const save = useSaveStoreSettings()

const FOOTER_MAX = 500

// The brief's exact labels — deliberately different from the print page's short "80mm"/"58mm"
// (`invoice.vue`'s own `PRESET_LABELS`), which is a per-print segmented control, not this setting.
const PAPER_PRESET_ITEMS: { value: InvoicePaperPreset; label: string }[] = [
  { value: 'a4', label: 'A4' },
  { value: 'thermal_80', label: 'Thermal 80mm' },
  { value: 'thermal_58', label: 'Thermal 58mm' },
]

// AssetField drives the same choose-or-upload picker the block editor / site-identity fields use,
// image-only (`mediaType="image"`, its own default — passed explicitly for clarity here).
const logoField = { name: 'invoice_logo_blob_uuid', label: '', type: 'asset' } as const

function isPreset(value: unknown): value is InvoicePaperPreset {
  return (INVOICE_PAPER_PRESETS as readonly string[]).includes(value as string)
}

const form = reactive({
  logoBlobUuid: '',
  footerText: '',
  showSku: true,
  showAddresses: true,
  showTaxId: true,
  paperPreset: 'a4' as InvoicePaperPreset,
})
const dirty = ref(false)
let syncing = false

function hydrate(): void {
  const s = settings.value
  if (!s) return
  syncing = true
  form.logoBlobUuid = String(s.settings['commerce.invoice.logo_blob_uuid']?.value ?? '')
  form.footerText = String(s.settings['commerce.invoice.footer_text']?.value ?? '')
  // Task 6's documented default for all three toggles is `true` — an absent entry normalizes to
  // `''` (never `false`), so `!== false` is "unless explicitly turned off" (mirrors
  // `useInvoiceSettings()`'s identical reasoning in commerceSettings.ts).
  form.showSku = s.settings['commerce.invoice.show_sku']?.value !== false
  form.showAddresses = s.settings['commerce.invoice.show_addresses']?.value !== false
  form.showTaxId = s.settings['commerce.invoice.show_tax_id']?.value !== false
  const rawPreset = s.settings['commerce.invoice.paper_preset']?.value
  form.paperPreset = isPreset(rawPreset) ? rawPreset : 'a4'
  dirty.value = false
  queueMicrotask(() => {
    syncing = false
  })
}

watch(
  settings,
  () => {
    if (!dirty.value) hydrate()
  },
  { immediate: true },
)

watch(form, () => {
  if (!syncing) dirty.value = true
})

// Read-only seller identity mirror — see the top-of-file docblock for why this panel never edits it.
const sellerName = computed(() => String(settings.value?.settings['commerce.seller.name']?.value ?? ''))
const sellerAddress = computed(() =>
  String(settings.value?.settings['commerce.seller.address']?.value ?? ''),
)
const sellerTaxId = computed(() => String(settings.value?.settings['commerce.seller.tax_id']?.value ?? ''))

// The ONE thing this panel may render as `<img src>` — the server-derived, ownership+servability-
// checked URL. `form.logoBlobUuid` alone is NEVER turned into a src here (`invoice_logo_url`'s own
// docblock on `StoreSettings`): a deleted/private/non-image blob still round-trips the stored uuid,
// but the preview honestly shows nothing rather than synthesizing a broken image from it.
const logoPreviewUrl = computed(() => settings.value?.invoice_logo_url ?? null)

const footerCount = computed(() => form.footerText.length)

const fieldErrors = reactive<Record<string, string>>({})

async function submit(): Promise<void> {
  for (const key of Object.keys(fieldErrors)) delete fieldErrors[key]

  const body: StoreSettingsSave = {
    'commerce.invoice.logo_blob_uuid':
      form.logoBlobUuid.trim() === '' ? null : form.logoBlobUuid.trim(),
    'commerce.invoice.footer_text': form.footerText.trim() === '' ? null : form.footerText,
    'commerce.invoice.show_sku': form.showSku,
    'commerce.invoice.show_addresses': form.showAddresses,
    'commerce.invoice.show_tax_id': form.showTaxId,
    'commerce.invoice.paper_preset': form.paperPreset,
  }

  try {
    await save.mutateAsync(body)
    dirty.value = false
    hydrate()
    success('Invoice settings saved', 'Applies to new invoices and receipts.')
  } catch (e) {
    const err = toApiError(e)
    for (const [field, message] of Object.entries(err.fieldErrors)) {
      fieldErrors[field] = message
    }
    notifyError(err, 'Couldn’t save invoice settings')
  }
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="invoices-settings-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load invoice settings"
    data-test="invoices-settings-error"
  />

  <div v-else class="max-w-2xl space-y-6 pb-5" data-test="invoices-settings-panel">
    <!-- Seller identity: read-only mirror, editable only from Store (ledgered Task 8 pointer). -->
    <div class="space-y-3 rounded-md border border-default p-4">
      <div class="flex items-center justify-between gap-2">
        <p class="text-[0.68rem] font-bold tracking-wider text-muted uppercase">Seller identity</p>
        <UButton
          size="xs"
          variant="ghost"
          color="neutral"
          label="Edit in Store settings"
          data-test="invoices-edit-store"
          @click="emit('edit-store')"
        />
      </div>
      <dl class="grid gap-3 sm:grid-cols-2">
        <div>
          <dt class="text-xs text-muted">Store name</dt>
          <dd class="text-sm" data-test="invoices-seller-name">{{ sellerName || '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted">Tax ID</dt>
          <dd class="text-sm" data-test="invoices-seller-tax-id">{{ sellerTaxId || '—' }}</dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="text-xs text-muted">Business address</dt>
          <dd class="text-sm whitespace-pre-line" data-test="invoices-seller-address">
            {{ sellerAddress || '—' }}
          </dd>
        </div>
      </dl>
    </div>

    <!-- Logo: image-only picker, uuid stored, preview is the server-derived URL only. -->
    <UFormField label="Invoice logo" :error="fieldErrors['commerce.invoice.logo_blob_uuid']">
      <AssetField
        v-if="canManage"
        v-model="form.logoBlobUuid"
        :field="logoField"
        :library-button="false"
        :preview="false"
        media-type="image"
      />
      <img
        v-if="logoPreviewUrl"
        :src="logoPreviewUrl"
        alt="Invoice logo"
        class="mt-2 max-h-16 max-w-full rounded object-contain"
        data-test="invoices-logo-preview"
      />
      <p v-else class="mt-1 text-xs text-muted" data-test="invoices-logo-empty">No logo set.</p>
    </UFormField>

    <!-- Footer -->
    <UFormField label="Invoice footer" :error="fieldErrors['commerce.invoice.footer_text']">
      <UTextarea
        v-model="form.footerText"
        :rows="3"
        class="w-full"
        :maxlength="FOOTER_MAX"
        :disabled="!canManage"
        data-test="invoices-footer-input"
      />
      <p class="mt-1 text-xs text-muted" data-test="invoices-footer-counter">
        {{ footerCount }} / {{ FOOTER_MAX }}
      </p>
    </UFormField>

    <!-- Optional-section toggles -->
    <div class="space-y-3">
      <div class="flex items-center justify-between gap-3">
        <span class="text-sm">Show product SKUs</span>
        <USwitch v-model="form.showSku" :disabled="!canManage" data-test="invoices-toggle-sku" />
      </div>
      <!-- This governs the BUYER/customer addresses on the printed document — the seller address
           above always renders regardless (Task 8 review pointer: the old "Show addresses" label
           was ambiguous about which side it controlled). -->
      <div class="flex items-center justify-between gap-3">
        <span class="text-sm">Show customer addresses</span>
        <USwitch
          v-model="form.showAddresses"
          :disabled="!canManage"
          data-test="invoices-toggle-addresses"
        />
      </div>
      <div class="flex items-center justify-between gap-3">
        <span class="text-sm">Show tax ID</span>
        <USwitch v-model="form.showTaxId" :disabled="!canManage" data-test="invoices-toggle-tax-id" />
      </div>
    </div>

    <!-- Print paper preset -->
    <UFormField label="Print paper size" :error="fieldErrors['commerce.invoice.paper_preset']">
      <USelect
        v-model="form.paperPreset"
        :items="PAPER_PRESET_ITEMS"
        class="w-full max-w-xs"
        :disabled="!canManage"
        data-test="invoices-paper-preset"
      />
    </UFormField>

    <UButton
      v-if="canManage"
      label="Save settings"
      :loading="save.isLoading.value"
      data-test="invoices-settings-save"
      @click="submit"
    />
  </div>
</template>
