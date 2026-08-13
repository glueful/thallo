<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useOrderInvoiceData } from '@/queries/commerceInvoice'
import {
  useInvoiceSettings,
  INVOICE_PAPER_PRESETS,
  type InvoicePaperPreset,
} from '@/queries/commerceSettings'
import InvoiceDocument from '../components/InvoiceDocument.vue'

// Orders-invoices-receipts spec §2.3: a standalone print route (opened via `target="_blank"` from
// order detail, Task 9) — this page must render a complete, printable document on its own from
// just the `:uuid` param, with no dependency on navigation state from the order-detail page.

const route = useRoute()
const uuid = computed(() => String(route.params.uuid))

const { data: invoice, status: invoiceStatus, refetch } = useOrderInvoiceData(uuid)
const { data: settings } = useInvoiceSettings()

const PRESET_LABELS: Record<InvoicePaperPreset, string> = {
  a4: 'A4',
  thermal_80: '80mm',
  thermal_58: '58mm',
}

// Per-print paper override (Ruling 12): temporary UI state ONLY. Initialized once from
// `commerce.invoice.paper_preset` the moment settings resolve, then never written back —
// changing the segmented control here never calls a settings-save mutation.
const preset = ref<InvoicePaperPreset>('a4')
const presetInitialized = ref(false)
watch(
  settings,
  (resolved) => {
    if (resolved && !presetInitialized.value) {
      preset.value = resolved.paperPreset
      presetInitialized.value = true
    }
  },
  { immediate: true },
)

function selectPreset(next: InvoicePaperPreset): void {
  preset.value = next
}

function printDocument(): void {
  window.print()
}

async function retry(): Promise<void> {
  await refetch()
}
</script>

<template>
  <div class="flex flex-col gap-4 p-4">
    <div
      data-print-chrome
      data-test="invoice-toolbar"
      class="flex flex-wrap items-center justify-between gap-3 border-b border-default pb-3"
    >
      <div
        role="group"
        aria-label="Paper size"
        data-test="invoice-preset-control"
        class="flex items-center gap-1 rounded-md border border-default p-1"
      >
        <button
          v-for="p in INVOICE_PAPER_PRESETS"
          :key="p"
          type="button"
          :data-test="`invoice-preset-${p}`"
          :aria-pressed="preset === p"
          :class="preset === p ? 'font-semibold' : ''"
          @click="selectPreset(p)"
        >
          {{ PRESET_LABELS[p] }}
        </button>
      </div>
      <button type="button" data-test="invoice-print" @click="printDocument">
        Print / Save as PDF
      </button>
    </div>

    <div v-if="invoiceStatus === 'pending'" class="flex justify-center py-10" data-test="invoice-loading">
      Loading…
    </div>

    <div v-else-if="invoiceStatus === 'error' || !invoice" class="flex flex-col items-start gap-2" data-test="invoice-error">
      <p>Couldn’t load this invoice. Try again.</p>
      <button type="button" data-test="invoice-retry" @click="retry">Retry</button>
    </div>

    <InvoiceDocument
      v-else
      :invoice="invoice"
      :preset="preset"
      :logo-url="settings?.logoUrl ?? null"
      :footer-text="settings?.footerText ?? ''"
      :show-sku="settings?.showSku ?? true"
      :show-addresses="settings?.showAddresses ?? true"
      :show-tax-id="settings?.showTaxId ?? true"
    />
  </div>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
