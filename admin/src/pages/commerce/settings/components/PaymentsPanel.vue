<script setup lang="ts">
// The Payments settings tab (store-settings spec §3.6): gateway configuration through payvia's
// settings seam. Secrets are WRITE-ONLY — the server stores them encrypted and only reports
// `{set, source}` booleans back, so the inputs here never display a stored key: an empty field
// with a "set" hint means "keep the stored value", typing replaces it, and the explicit Clear
// action sends null (the override row is DELETED — the env value, if any, shows through).
// With no gateway extension installed the tab honestly reports manual collection.
import { computed, reactive, ref, watch } from 'vue'
import {
  usePaymentsSettings,
  useSavePaymentsSettings,
  type PaymentsSettingsSave,
} from '@/queries/commerceSettings'
import { useNotify } from '@/composables/useNotify'
import { toApiError } from '@/api/errors'

defineProps<{ canManage: boolean }>()

const { success, error: notifyError } = useNotify()
const { data: payments, status } = usePaymentsSettings()
const save = useSavePaymentsSettings()

interface GatewayDraft {
  enabled: boolean
  secret_key: string
  webhook_secret: string
  clear_secret_key: boolean
  clear_webhook_secret: boolean
}

const form = reactive({
  defaultGateway: '',
  gateways: {} as Record<string, GatewayDraft>,
})
/** Server snapshot at hydrate time — the save sends only what actually changed. */
const baseline = ref<{ defaultGateway: string; enabled: Record<string, boolean> }>({
  defaultGateway: '',
  enabled: {},
})
const dirty = ref(false)
let syncing = false

function hydrate(): void {
  const p = payments.value
  if (!p) return
  syncing = true
  form.defaultGateway = p.default_gateway.value ?? ''
  const enabled: Record<string, boolean> = {}
  const drafts: Record<string, GatewayDraft> = {}
  for (const gateway of p.gateways) {
    enabled[gateway.id] = gateway.enabled.value
    drafts[gateway.id] = {
      enabled: gateway.enabled.value,
      secret_key: '',
      webhook_secret: '',
      clear_secret_key: false,
      clear_webhook_secret: false,
    }
  }
  form.gateways = drafts
  baseline.value = { defaultGateway: form.defaultGateway, enabled }
  dirty.value = false
  queueMicrotask(() => {
    syncing = false
  })
}

watch(payments, () => {
  if (!dirty.value) hydrate()
}, { immediate: true })

watch(form, () => {
  if (!syncing) dirty.value = true
})

const gatewayItems = computed<{ value: string; label: string }[]>(
  () => (payments.value?.gateways ?? []).map((g) => ({ value: g.id, label: g.id })),
)

type SecretField = 'secret_key' | 'webhook_secret'

/** The "set" hint under a secret input — where the effective value currently comes from. */
function secretHelp(id: string, field: SecretField): string {
  const gateway = payments.value?.gateways.find((g) => g.id === id)
  const state = gateway?.[field]
  const draft = form.gateways[id]
  if (draft?.[`clear_${field}`]) return 'Will be cleared on save.'
  if ((draft?.[field] ?? '') !== '') return 'Will replace the stored value on save.'
  if (state?.set && state.source === 'settings') return 'A key is stored (encrypted). Leave blank to keep it.'
  if (state?.set && state.source === 'env') return 'Using the key from .env. Enter a value to override it here.'
  return 'No key set.'
}

function markClear(id: string, field: SecretField): void {
  const draft = form.gateways[id]
  if (!draft) return
  draft[field] = ''
  draft[`clear_${field}`] = true
}

function canClear(id: string, field: SecretField): boolean {
  const gateway = payments.value?.gateways.find((g) => g.id === id)
  return gateway?.[field]?.source === 'settings' && !form.gateways[id]?.[`clear_${field}`]
}

const fieldErrors = reactive<Record<string, string>>({})

async function submit(): Promise<void> {
  for (const key of Object.keys(fieldErrors)) delete fieldErrors[key]

  const body: PaymentsSettingsSave = {}
  if (form.defaultGateway !== baseline.value.defaultGateway && form.defaultGateway !== '') {
    body.default_gateway = form.defaultGateway
  }

  const gateways: NonNullable<PaymentsSettingsSave['gateways']> = {}
  for (const [id, draft] of Object.entries(form.gateways)) {
    const fields: (typeof gateways)[string] = {}
    if (draft.enabled !== baseline.value.enabled[id]) fields.enabled = draft.enabled
    for (const field of ['secret_key', 'webhook_secret'] as const) {
      if (draft[`clear_${field}`]) {
        fields[field] = null
      } else if (draft[field].trim() !== '') {
        fields[field] = draft[field].trim()
      }
    }
    if (Object.keys(fields).length > 0) gateways[id] = fields
  }
  if (Object.keys(gateways).length > 0) body.gateways = gateways

  if (Object.keys(body).length === 0) {
    dirty.value = false
    return
  }

  try {
    await save.mutateAsync(body)
    dirty.value = false
    hydrate()
    success('Payment settings saved', 'Changes apply on the next request.')
  } catch (e) {
    const err = toApiError(e)
    for (const [field, message] of Object.entries(err.fieldErrors)) {
      fieldErrors[field] = message
    }
    notifyError(err, 'Couldn’t save payment settings')
  }
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="payments-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load payment settings"
    data-test="payments-error"
  />

  <div
    v-else-if="payments?.mode === 'manual'"
    class="max-w-2xl rounded-md border border-default px-4 py-3 text-sm text-muted"
    data-test="payments-manual"
  >
    <span class="font-medium text-default">Manual collection</span> — no payment gateway
    extension is installed; operators mark orders paid from the order page. Install a gateway
    extension (e.g. glueful/payvia) to configure online payments here.
  </div>

  <div v-else class="max-w-2xl space-y-6" data-test="payments-panel">
    <UFormField
      label="Default gateway"
      :error="fieldErrors['default_gateway']"
      help="Checkout uses this gateway unless one is named explicitly."
    >
      <USelect
        v-model="form.defaultGateway"
        :items="gatewayItems"
        :disabled="!canManage"
        class="w-56"
        data-test="payments-default-select"
      />
    </UFormField>

    <div
      v-for="gateway in payments?.gateways ?? []"
      :key="gateway.id"
      class="space-y-4 rounded-lg border border-default p-4"
      data-test="payments-gateway-card"
    >
      <div class="flex items-center gap-2">
        <span class="font-medium capitalize">{{ gateway.id }}</span>
        <UBadge v-if="gateway.default" size="sm" variant="subtle">default</UBadge>
        <USwitch
          v-if="form.gateways[gateway.id]"
          v-model="form.gateways[gateway.id]!.enabled"
          :disabled="!canManage"
          class="ml-auto"
          :data-test="`payments-enabled-${gateway.id}`"
        />
      </div>

      <UFormField
        label="Secret key"
        :error="fieldErrors[`gateways.${gateway.id}.secret_key`]"
        :help="secretHelp(gateway.id, 'secret_key')"
      >
        <div class="flex items-center gap-2">
          <UInput
            v-if="form.gateways[gateway.id]"
            v-model="form.gateways[gateway.id]!.secret_key"
            type="password"
            autocomplete="off"
            :placeholder="gateway.secret_key.set ? '•••••••• (stored)' : 'Enter secret key'"
            :disabled="!canManage"
            class="flex-1"
            :data-test="`payments-secret-${gateway.id}-secret_key`"
          />
          <UButton
            v-if="canClear(gateway.id, 'secret_key')"
            color="neutral"
            variant="ghost"
            size="sm"
            label="Clear"
            :disabled="!canManage"
            :data-test="`payments-clear-${gateway.id}-secret_key`"
            @click="markClear(gateway.id, 'secret_key')"
          />
        </div>
      </UFormField>

      <UFormField
        label="Webhook secret"
        :error="fieldErrors[`gateways.${gateway.id}.webhook_secret`]"
        :help="secretHelp(gateway.id, 'webhook_secret')"
      >
        <div class="flex items-center gap-2">
          <UInput
            v-if="form.gateways[gateway.id]"
            v-model="form.gateways[gateway.id]!.webhook_secret"
            type="password"
            autocomplete="off"
            :placeholder="gateway.webhook_secret.set ? '•••••••• (stored)' : 'Enter webhook secret'"
            :disabled="!canManage"
            class="flex-1"
            :data-test="`payments-secret-${gateway.id}-webhook_secret`"
          />
          <UButton
            v-if="canClear(gateway.id, 'webhook_secret')"
            color="neutral"
            variant="ghost"
            size="sm"
            label="Clear"
            :disabled="!canManage"
            :data-test="`payments-clear-${gateway.id}-webhook_secret`"
            @click="markClear(gateway.id, 'webhook_secret')"
          />
        </div>
      </UFormField>
    </div>

    <p class="text-xs text-muted">
      Keys are stored encrypted and never shown again after saving. Values set in
      <code>.env</code> keep working as the fallback wherever nothing is stored here.
    </p>

    <div v-if="canManage" class="flex justify-end">
      <UButton
        label="Save payment settings"
        :loading="save.isLoading.value"
        data-test="payments-save"
        @click="submit"
      />
    </div>
  </div>
</template>
