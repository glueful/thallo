<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  useApiKeyMutations,
  apiKeyStatusMeta,
  formatDate,
  formatDateTime,
  type ApiKey,
} from '@/queries/apiKeys'
import { useNotify } from '@/composables/useNotify'
import ApiKeySecretModal from './ApiKeySecretModal.vue'
import { useAllTenants } from '@/queries/tenants'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'

const props = defineProps<{ item: ApiKey }>()
const emit = defineEmits<{ revoked: [uuid: string]; rotated: [item: ApiKey] }>()

const { rotate, revoke, updateTenant } = useApiKeyMutations()
const { success, error: notifyError } = useNotify()

const showRotate = ref(false)
const graceHours = ref(24)
const newSecret = ref('')
const showSecret = ref(false)
const pendingRevoke = ref(false)
const access = useTenancyAccessStore()
const tenants = useAllTenants(() => access.access.manage_platform)
// Reka UI's ComboboxItem forbids an empty-string value (reserved for "clear selection"), so the
// "Unbound" option uses a non-empty sentinel that maps back to null at the API boundary.
const UNBOUND = '__unbound__'
const selectedTenant = ref(props.item.tenant_uuid ?? UNBOUND)
const tenantItems = computed(() => [
  { label: 'Unbound', value: UNBOUND },
  ...((tenants.data.value ?? []).map((tenant) => ({ label: tenant.name, value: tenant.uuid }))),
])

async function saveTenantBinding() {
  try {
    await updateTenant.mutateAsync({
      uuid: props.item.uuid,
      tenantUuid: selectedTenant.value === UNBOUND ? null : selectedTenant.value,
    })
    success('Workspace binding updated')
  } catch (e) {
    notifyError(e, 'Could not update the workspace binding')
  }
}

async function copyPrefix() {
  await navigator.clipboard.writeText(props.item.key_prefix)
  success('Key prefix copied')
}

async function confirmRotate() {
  try {
    const result = await rotate.mutateAsync({ uuid: props.item.uuid, graceHours: graceHours.value })
    showRotate.value = false
    newSecret.value = result.plain
    showSecret.value = true
    if (result.api_key) emit('rotated', result.api_key)
  } catch (e) {
    notifyError(e, 'Could not rotate the key')
  }
}

async function confirmRevoke() {
  try {
    await revoke.mutateAsync(props.item.uuid)
    success('Key revoked', props.item.name)
    pendingRevoke.value = false
    emit('revoked', props.item.uuid)
  } catch (e) {
    notifyError(e, 'Could not revoke the key')
  }
}
</script>

<template>
  <div class="flex flex-col gap-5">
    <!-- Header -->
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <h2 class="truncate text-lg font-semibold text-highlighted">{{ item.name }}</h2>
        <button
          type="button"
          class="mt-0.5 flex items-center gap-1 font-mono text-xs text-muted transition-colors hover:text-default"
          title="Copy key prefix"
          @click="copyPrefix"
        >
          <span>{{ item.key_prefix }}…</span>
          <UIcon name="i-lucide-copy" class="size-3" />
        </button>
      </div>
      <UBadge
        :label="apiKeyStatusMeta(item.status).label"
        :color="apiKeyStatusMeta(item.status).color"
        variant="subtle"
      />
    </div>

    <UAlert
      v-if="item.status === 'revoked'"
      color="error"
      variant="subtle"
      icon="i-lucide-ban"
      title="This key is revoked"
      description="It no longer authenticates requests."
    />
    <UAlert
      v-else-if="item.status === 'expired'"
      color="warning"
      variant="subtle"
      icon="i-lucide-clock-alert"
      title="This key has expired"
      description="Rotate it to issue a fresh key with the same settings."
    />

    <!-- Scopes -->
    <div>
      <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Workspace</h3>
      <div v-if="access.access.manage_platform" class="flex gap-2">
        <USelectMenu v-model="selectedTenant" :items="tenantItems" value-key="value" class="min-w-0 flex-1" />
        <UButton
          icon="i-lucide-save"
          color="neutral"
          variant="outline"
          :loading="updateTenant.isLoading.value"
          aria-label="Save workspace binding"
          @click="saveTenantBinding"
        />
      </div>
      <p v-else class="text-sm text-muted">{{ item.tenant_name ?? 'Unbound' }}</p>
    </div>

    <!-- Scopes -->
    <div>
      <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Scopes</h3>
      <div v-if="item.scopes.length" class="flex flex-wrap gap-1">
        <UBadge
          v-for="s in item.scopes"
          :key="s"
          :label="s"
          color="neutral"
          variant="subtle"
          size="sm"
        />
      </div>
      <p v-else class="text-sm text-muted">Full access — no scope restriction.</p>
    </div>

    <!-- Allowed IPs -->
    <div>
      <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Allowed IPs</h3>
      <div v-if="item.allowed_ips.length" class="flex flex-wrap gap-1">
        <UBadge
          v-for="ip in item.allowed_ips"
          :key="ip"
          :label="ip"
          color="neutral"
          variant="subtle"
          size="sm"
          class="font-mono"
        />
      </div>
      <p v-else class="text-sm text-muted">Any IP.</p>
    </div>

    <!-- Actions -->
    <div class="flex flex-col gap-2">
      <span class="text-xs font-semibold uppercase tracking-wide text-muted">Actions</span>
      <UButton
        label="Rotate key"
        icon="i-lucide-refresh-cw"
        color="neutral"
        variant="outline"
        block
        :disabled="item.status === 'revoked'"
        @click="() => { graceHours = 24; showRotate = true }"
      />
      <UButton
        label="Revoke key"
        icon="i-lucide-ban"
        color="error"
        variant="soft"
        block
        :disabled="item.status === 'revoked'"
        @click="() => { pendingRevoke = true }"
      />
    </div>

    <!-- Details -->
    <div>
      <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Details</h3>
      <dl class="grid grid-cols-3 gap-y-2 text-sm">
        <dt class="text-muted">Owner</dt>
        <dd class="col-span-2 truncate text-default">{{ item.owner_label ?? '—' }}</dd>
        <dt class="text-muted">Created</dt>
        <dd class="col-span-2 text-default">{{ formatDateTime(item.created_at) }}</dd>
        <dt class="text-muted">Expires</dt>
        <dd class="col-span-2 text-default">
          {{ item.expires_at ? formatDate(item.expires_at) : 'Never' }}
        </dd>
        <template v-if="item.revoked_at">
          <dt class="text-muted">Revoked</dt>
          <dd class="col-span-2 text-default">{{ formatDateTime(item.revoked_at) }}</dd>
        </template>
        <template v-if="item.is_rotated">
          <dt class="text-muted">Origin</dt>
          <dd class="col-span-2 text-default">Rotated from an earlier key</dd>
        </template>
      </dl>
    </div>

    <!-- Rotate modal -->
    <UModal v-model:open="showRotate" title="Rotate API key">
      <template #body>
        <div class="flex flex-col gap-4">
          <p class="text-sm text-muted">
            A new key is issued with the same scopes, IPs and expiry. The current key keeps working
            for a grace window so you can swap it out without downtime.
          </p>
          <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">
              Grace window (hours)
            </label>
            <UInput v-model.number="graceHours" type="number" :min="1" :max="720" class="w-full" />
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            label="Cancel"
            :disabled="rotate.isLoading.value"
            @click="() => { showRotate = false }"
          />
          <UButton
            label="Rotate"
            icon="i-lucide-refresh-cw"
            :loading="rotate.isLoading.value"
            @click="confirmRotate"
          />
        </div>
      </template>
    </UModal>

    <!-- Revoke confirm -->
    <UModal v-model:open="pendingRevoke" title="Revoke API key">
      <template #body>
        <p class="text-sm text-muted">
          Revoke <span class="text-default">“{{ item.name }}”</span>? Any client using this key will
          immediately stop authenticating. This cannot be undone.
        </p>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            label="Cancel"
            :disabled="revoke.isLoading.value"
            @click="() => { pendingRevoke = false }"
          />
          <UButton
            color="error"
            icon="i-lucide-ban"
            label="Revoke"
            :loading="revoke.isLoading.value"
            @click="confirmRevoke"
          />
        </div>
      </template>
    </UModal>

    <ApiKeySecretModal
      v-model:open="showSecret"
      :secret="newSecret"
      title="Your rotated API key"
      subtitle="The previous key still works until the grace window ends. Update your clients to this new key now — it won’t be shown again."
    />
  </div>
</template>
