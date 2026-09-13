<script setup lang="ts">
import { computed } from 'vue'
import { useApiKeyList, useApiKeyMutations, type ApiKey } from '@/queries/apiKeys'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ collectionName: string }>()
const { error: notifyError } = useNotify()

const { data, status } = useApiKeyList(1, 100, undefined, undefined)
const { updateScopes } = useApiKeyMutations()

const keys = computed<ApiKey[]>(() => data.value?.api_keys ?? [])
const ACTIONS = ['read', 'write', 'delete'] as const

function scopeFor(action: string): string {
  // Capabilities are namespaced `collections.{name}.{action}` (the backend
  // CollectionAccessResolver form) — the bare `{name}.{action}` no longer grants access.
  return `collections.${props.collectionName}.${action}`
}
function hasScope(key: ApiKey, action: string): boolean {
  return key.scopes.includes(scopeFor(action))
}

// Toggle one capability on a key by replacing the whole scope list (the endpoint is a full replace).
async function toggle(key: ApiKey, action: string) {
  const scope = scopeFor(action)
  const next = key.scopes.includes(scope)
    ? key.scopes.filter((s) => s !== scope)
    : [...key.scopes, scope]
  try {
    await updateScopes.mutateAsync({ uuid: key.uuid, scopes: next })
  } catch (e) {
    notifyError(e, 'Couldn’t update API key scopes')
  }
}
</script>

<template>
  <section class="space-y-3">
    <div>
      <h3 class="text-sm font-medium text-default">API-key access</h3>
      <p class="text-xs text-muted">
        Grant each key the <code>collections.{{ collectionName }}.read</code> / <code>write</code> /
        <code>delete</code> scopes for this collection's public API.
      </p>
    </div>

    <div v-if="status === 'pending'" class="text-sm text-muted">Loading API keys…</div>
    <div v-else-if="keys.length === 0" class="text-sm text-muted">No API keys yet.</div>
    <div v-else class="divide-y divide-default rounded-md border border-default">
      <div
        v-for="key in keys"
        :key="key.uuid"
        data-test="scope-key-row"
        class="flex items-center gap-4 px-3 py-2"
      >
        <span class="flex-1 text-sm text-default">{{ key.name }}</span>
        <USwitch
          v-for="action in ACTIONS"
          :key="action"
          :model-value="hasScope(key, action)"
          :label="action"
          :data-test="`scope-${action}`"
          @update:model-value="() => toggle(key, action)"
        />
      </div>
    </div>
  </section>
</template>
