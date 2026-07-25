<script setup lang="ts">
// The Marketplace settings tab (store-settings spec §3.6, Marketplace group): per-workspace
// activation + the workspace (fallback) commission policy, fronting commerce's own marketplace
// services. The MASTER flag (COMMERCE_MARKETPLACE_ENABLED) is boot-time env by architecture —
// when it's off (the default) this tab is a single honest card, no controls. Sellers, payouts,
// and financials are a future Marketplace admin area, not this tab.
import { computed, reactive, ref, watch } from 'vue'
import {
  useMarketplaceSettings,
  useMarketplaceMutations,
} from '@/queries/commerceSettings'
import { useNotify } from '@/composables/useNotify'
import { toApiError } from '@/api/errors'

defineProps<{ canManage: boolean }>()

const { success, error: notifyError } = useNotify()
const { data: marketplace, status } = useMarketplaceSettings()
const { activate, deactivate, saveCommission, setMaster } = useMarketplaceMutations()

const active = computed(() => marketplace.value?.settings?.status === 'active')

// Activation: optional default seller for attributing existing products.
const defaultSeller = ref<string>('')
const sellerItems = computed(() => [
  { value: '', label: 'No default seller' },
  ...(marketplace.value?.sellers ?? []).map((s) => ({
    value: s.uuid,
    label: `${s.name} (${s.status})`,
  })),
])

// Commission drafts — hydrated from the settings row; kind decides which amount field applies
// (percentage → bps as a percent input; fixed → minor units), matching CommissionPolicyResolver.
const commission = reactive({ kind: 'percentage', percent: '', fixed: '' })
let syncing = false
const dirty = ref(false)

watch(marketplace, () => {
  if (dirty.value) return
  const row = marketplace.value?.settings
  syncing = true
  commission.kind = row?.commission.kind ?? 'percentage'
  commission.percent = row?.commission.bps != null ? String(row.commission.bps / 100) : ''
  commission.fixed = row?.commission.fixed != null ? String(row.commission.fixed) : ''
  queueMicrotask(() => {
    syncing = false
  })
}, { immediate: true })

watch(commission, () => {
  if (!syncing) dirty.value = true
})

const commissionError = ref('')

async function doSetMaster(enabled: boolean): Promise<void> {
  try {
    await setMaster.mutateAsync(enabled)
    success(
      enabled ? 'Marketplace enabled' : 'Marketplace switched off',
      enabled ? 'Activate it per workspace below.' : 'The workspace keeps its sellers and policy.',
    )
  } catch (e) {
    notifyError(toApiError(e), 'Couldn’t change marketplace mode')
  }
}

async function doActivate(): Promise<void> {
  try {
    await activate.mutateAsync(defaultSeller.value === '' ? null : defaultSeller.value)
    success('Marketplace activated', 'This workspace now runs in marketplace mode.')
  } catch (e) {
    notifyError(toApiError(e), 'Couldn’t activate the marketplace')
  }
}

async function doDeactivate(): Promise<void> {
  try {
    await deactivate.mutateAsync()
    success('Marketplace deactivated', 'Non-destructive — sellers and policy are kept.')
  } catch (e) {
    notifyError(toApiError(e), 'Couldn’t deactivate the marketplace')
  }
}

async function doSaveCommission(): Promise<void> {
  commissionError.value = ''
  let bps: number | null = null
  let fixed: number | null = null
  if (commission.kind === 'percentage') {
    const percent = Number(commission.percent)
    bps = Math.round(percent * 100)
    if (commission.percent.trim() === '' || !Number.isFinite(bps) || bps < 0 || bps > 10000) {
      commissionError.value = 'Enter a commission percentage between 0 and 100.'
      return
    }
  } else {
    fixed = Number(commission.fixed)
    if (commission.fixed.trim() === '' || !Number.isInteger(fixed) || fixed < 0) {
      commissionError.value = 'Enter a non-negative fixed amount in minor units.'
      return
    }
  }

  try {
    await saveCommission.mutateAsync({ kind: commission.kind, bps, fixed })
    dirty.value = false
    success('Commission policy saved', 'Applies to new orders from the next request.')
  } catch (e) {
    const err = toApiError(e)
    commissionError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t save the commission policy')
  }
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="marketplace-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load marketplace settings"
    data-test="marketplace-error"
  />

  <div
    v-else-if="!marketplace?.master_enabled"
    class="max-w-2xl space-y-3 rounded-md border border-default px-4 py-3 text-sm text-muted"
    data-test="marketplace-master-off"
  >
    <p>
      <span class="font-medium text-default">Marketplace mode is switched off.</span>
      Turn it on to run this site as a multi-seller marketplace — sellers list products,
      orders attribute to them, and commissions settle through payouts.
    </p>
    <UButton
      v-if="canManage"
      label="Enable marketplace"
      :loading="setMaster.isLoading.value"
      data-test="marketplace-master-enable"
      @click="doSetMaster(true)"
    />
  </div>

  <div v-else class="max-w-2xl space-y-6" data-test="marketplace-panel">
    <div class="flex items-center gap-3 rounded-lg border border-default p-4">
      <div class="min-w-0">
        <p class="font-medium">Marketplace mode</p>
        <p class="text-xs text-muted">
          {{ active ? 'Active — orders attribute to sellers and settle through payouts.'
                    : 'Inactive — this workspace sells as a single store.' }}
        </p>
      </div>
      <UBadge
        class="ml-auto"
        :color="active ? 'success' : 'neutral'"
        variant="subtle"
        data-test="marketplace-status-badge"
      >
        {{ active ? 'active' : 'inactive' }}
      </UBadge>
    </div>

    <div v-if="!active && canManage" class="space-y-3 rounded-lg border border-default p-4">
      <p class="text-sm font-medium">Activate marketplace mode</p>
      <UFormField
        label="Default seller for existing products"
        help="Existing products must belong to a seller; pick one to adopt them, or leave empty if the catalog is empty."
      >
        <USelect
          v-model="defaultSeller"
          :items="sellerItems"
          class="w-full"
          data-test="marketplace-default-seller"
        />
      </UFormField>
      <UButton
        label="Activate marketplace"
        :loading="activate.isLoading.value"
        data-test="marketplace-activate"
        @click="doActivate"
      />
    </div>

    <div v-if="active" class="space-y-4 rounded-lg border border-default p-4">
      <p class="text-sm font-medium">Workspace commission policy</p>
      <p class="text-xs text-muted">
        The fallback commission when neither the product nor the seller sets one.
      </p>
      <UFormField label="Kind" :error="commissionError || undefined">
        <USelect
          v-model="commission.kind"
          :items="[
            { value: 'percentage', label: 'Percentage of each order line' },
            { value: 'fixed', label: 'Fixed amount per order' },
          ]"
          :disabled="!canManage"
          class="w-72"
          data-test="marketplace-commission-kind"
        />
      </UFormField>
      <UFormField v-if="commission.kind === 'percentage'" label="Commission (%)">
        <UInput
          v-model="commission.percent"
          inputmode="decimal"
          :disabled="!canManage"
          class="w-40"
          data-test="marketplace-commission-percent"
        />
      </UFormField>
      <UFormField v-else label="Fixed amount (minor units)">
        <UInput
          v-model="commission.fixed"
          inputmode="numeric"
          :disabled="!canManage"
          class="w-40"
          data-test="marketplace-commission-fixed"
        />
      </UFormField>
      <div v-if="canManage" class="flex items-center gap-3">
        <UButton
          label="Save commission policy"
          :loading="saveCommission.isLoading.value"
          data-test="marketplace-commission-save"
          @click="doSaveCommission"
        />
        <UButton
          color="neutral"
          variant="ghost"
          label="Deactivate marketplace"
          :loading="deactivate.isLoading.value"
          data-test="marketplace-deactivate"
          @click="doDeactivate"
        />
      </div>
    </div>

    <!-- Switching OFF entirely is offered only while the workspace is inactive — deactivate
         first, then switch off, so the two levels can't be collapsed by one destructive click. -->
    <div v-if="!active && canManage" class="flex justify-start">
      <UButton
        color="neutral"
        variant="ghost"
        size="sm"
        label="Switch off marketplace mode"
        :loading="setMaster.isLoading.value"
        data-test="marketplace-master-disable"
        @click="doSetMaster(false)"
      />
    </div>

    <p class="text-xs text-muted">
      Sellers, payouts, and financial reports get their own Marketplace area — this tab covers
      activation and the workspace policy. Commerce’s direct marketplace REST API (external
      integrations) additionally needs <code>COMMERCE_MARKETPLACE_ENABLED</code> in the
      environment.
    </p>
  </div>
</template>
