<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useApiKeyMutations, type SecretResult } from '@/queries/apiKeys'
import { useNotify } from '@/composables/useNotify'
import { useAllTenants } from '@/queries/tenants'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'

const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ created: [result: SecretResult] }>()

const { create } = useApiKeyMutations()
const { error: notifyError } = useNotify()
const access = useTenancyAccessStore()
const tenants = useAllTenants(() => access.access.manage_platform)
// Reka UI's ComboboxItem forbids an empty-string value (reserved for "clear selection"), so the
// "Unbound" option uses a non-empty sentinel that maps back to no-binding at submit.
const UNBOUND = '__unbound__'
const tenantItems = computed(() => [
  { label: 'Unbound', value: UNBOUND },
  ...((tenants.data.value ?? []).map((tenant) => ({ label: tenant.name, value: tenant.uuid }))),
])

const form = reactive({ name: '', expires_at: '', tenant_uuid: UNBOUND })
const scopes = ref<string[]>([])
const allowedIps = ref<string[]>([])
const scopeInput = ref('')
const ipInput = ref('')
const nameError = ref('')

// Reset everything whenever the dialog opens, so it never reappears with a previous draft.
watch(open, (isOpen) => {
  if (isOpen) {
    form.name = ''
    form.expires_at = ''
    form.tenant_uuid = UNBOUND
    scopes.value = []
    allowedIps.value = []
    scopeInput.value = ''
    ipInput.value = ''
    nameError.value = ''
  }
})

function addScope() {
  const token = scopeInput.value.trim()
  if (token && !scopes.value.includes(token)) scopes.value.push(token)
  scopeInput.value = ''
}
function removeScope(token: string) {
  scopes.value = scopes.value.filter((t) => t !== token)
}
function addIp() {
  const token = ipInput.value.trim()
  if (token && !allowedIps.value.includes(token)) allowedIps.value.push(token)
  ipInput.value = ''
}
function removeIp(token: string) {
  allowedIps.value = allowedIps.value.filter((t) => t !== token)
}

async function submit() {
  nameError.value = ''
  if (!form.name.trim()) {
    nameError.value = 'A name is required.'
    return
  }
  try {
    const result = await create.mutateAsync({
      name: form.name.trim(),
      scopes: scopes.value.length ? scopes.value : undefined,
      allowed_ips: allowedIps.value.length ? allowedIps.value : undefined,
      expires_at: form.expires_at || undefined,
      tenant_uuid: form.tenant_uuid === UNBOUND ? undefined : form.tenant_uuid,
    })
    open.value = false
    emit('created', result)
  } catch (e) {
    notifyError(e, 'Could not create the API key')
  }
}
</script>

<template>
  <UModal v-model:open="open" title="New API key" description="Mint a key for programmatic access.">
    <template #body>
      <div class="flex flex-col gap-5">
        <!-- Name -->
        <div>
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted"
            >Name</label
          >
          <UInput
            v-model="form.name"
            placeholder="e.g. CI deploy bot"
            class="w-full"
            autofocus
            @keydown.enter.prevent="submit"
          />
          <p v-if="nameError" class="mt-1 text-xs text-error">{{ nameError }}</p>
        </div>

        <!-- Scopes -->
        <div v-if="access.access.manage_platform">
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">
            Workspace
          </label>
          <USelectMenu v-model="form.tenant_uuid" :items="tenantItems" value-key="value" class="w-full" />
        </div>

        <!-- Scopes -->
        <div>
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted"
            >Scopes</label
          >
          <p class="mb-2 text-xs text-muted">
            Limit what the key can do (e.g. <code>read:*</code>, <code>write:posts</code>). Leave
            empty for full access.
          </p>
          <div v-if="scopes.length" class="mb-2 flex flex-wrap gap-1">
            <UBadge
              v-for="s in scopes"
              :key="s"
              color="neutral"
              variant="subtle"
              size="sm"
              class="cursor-pointer"
              @click="removeScope(s)"
            >
              {{ s }}
              <UIcon name="i-lucide-x" class="ms-1 size-3" />
            </UBadge>
          </div>
          <UInput
            v-model="scopeInput"
            placeholder="Add a scope and press Enter"
            class="w-full"
            @keydown.enter.prevent="addScope"
          />
        </div>

        <!-- Allowed IPs -->
        <div>
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">
            Allowed IPs
          </label>
          <p class="mb-2 text-xs text-muted">
            Restrict to specific IPs or CIDR ranges. Leave empty to allow any.
          </p>
          <div v-if="allowedIps.length" class="mb-2 flex flex-wrap gap-1">
            <UBadge
              v-for="ip in allowedIps"
              :key="ip"
              color="neutral"
              variant="subtle"
              size="sm"
              class="cursor-pointer"
              @click="removeIp(ip)"
            >
              {{ ip }}
              <UIcon name="i-lucide-x" class="ms-1 size-3" />
            </UBadge>
          </div>
          <UInput
            v-model="ipInput"
            placeholder="e.g. 203.0.113.4 or 10.0.0.0/8"
            class="w-full"
            @keydown.enter.prevent="addIp"
          />
        </div>

        <!-- Expiry -->
        <div>
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">
            Expires
          </label>
          <p class="mb-2 text-xs text-muted">Optional. The key stops working after this date.</p>
          <UInput v-model="form.expires_at" type="date" class="w-full" />
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="create.isLoading.value"
          @click="() => { open = false }"
        />
        <UButton
          label="Create key"
          icon="i-lucide-key-round"
          :loading="create.isLoading.value"
          @click="submit"
        />
      </div>
    </template>
  </UModal>
</template>
