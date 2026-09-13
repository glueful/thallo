<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useApiKeyList, apiKeyStatusMeta, type ApiKey, type SecretResult } from '@/queries/apiKeys'
import ApiKeyCreateModal from './ApiKeyCreateModal.vue'
import ApiKeySecretModal from './ApiKeySecretModal.vue'

const props = defineProps<{ selectedUuid?: string }>()
const emit = defineEmits<{ select: [item: ApiKey]; created: [item: ApiKey] }>()

const page = ref(1)
const perPage = ref(30)
const status = ref('')
const search = ref('')
const debounced = refDebounced(search, 300)

const { data, status: loadStatus } = useApiKeyList(page, perPage, status, debounced)
const items = computed(() => data.value?.api_keys ?? [])
const total = computed(() => data.value?.total ?? 0)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

watch([status, debounced], () => {
  page.value = 1
})

const filters = [
  { label: 'All', value: '' },
  { label: 'Active', value: 'active' },
  { label: 'Expired', value: 'expired' },
  { label: 'Revoked', value: 'revoked' },
]

const showCreate = ref(false)
const newSecret = ref('')
const showSecret = ref(false)

function onCreated(result: SecretResult) {
  newSecret.value = result.plain
  showSecret.value = true
  if (result.api_key) emit('created', result.api_key)
}
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-col gap-3 lg:w-85 lg:shrink-0">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-highlighted">API Keys</h2>
      <UButton
        icon="i-lucide-plus"
        size="sm"
        class="rounded-xl px-3"
        @click="
          () => {
            showCreate = true
          }
        "
      />
    </div>

    <UInput v-model="search" icon="i-lucide-search" placeholder="Search by name…" />

    <div class="flex flex-wrap gap-1">
      <UButton
        v-for="f in filters"
        :key="f.value"
        :label="f.label"
        size="xs"
        class="rounded-lg"
        :color="status === f.value ? 'primary' : 'neutral'"
        :variant="status === f.value ? 'solid' : 'soft'"
        @click="
          () => {
            status = f.value
          }
        "
      />
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div v-if="loadStatus === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <UEmpty
        v-else-if="!items.length"
        icon="i-lucide-key-round"
        title="No API keys"
        description="Create a key to grant programmatic access."
      />
      <div v-else class="flex flex-col gap-0.5">
        <button
          v-for="k in items"
          :key="k.uuid"
          type="button"
          class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors"
          :class="k.uuid === props.selectedUuid ? 'bg-elevated' : 'hover:bg-elevated/50'"
          @click="emit('select', k)"
        >
          <div class="flex size-10 shrink-0 items-center justify-center rounded-md bg-elevated">
            <UIcon name="i-lucide-key-round" class="size-5 text-muted" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-default">{{ k.name }}</p>
            <p class="truncate font-mono text-xs text-muted">{{ k.key_prefix }}…</p>
          </div>
          <UBadge
            :label="apiKeyStatusMeta(k.status).label"
            :color="apiKeyStatusMeta(k.status).color"
            variant="subtle"
            size="xs"
          />
        </button>
      </div>
    </div>

    <div
      v-if="total > 0"
      class="flex items-center justify-between gap-2 border-t border-default py-2 text-muted"
    >
      <span class="text-xs font-medium uppercase tracking-wide">{{ total }} keys</span>
      <div class="flex items-center gap-1">
        <span class="text-sm">{{ page }} / {{ totalPages }}</span>
        <UButton
          icon="i-lucide-chevron-left"
          color="neutral"
          variant="ghost"
          size="xs"
          :disabled="page <= 1"
          @click="
            () => {
              page--
            }
          "
        />
        <UButton
          icon="i-lucide-chevron-right"
          color="neutral"
          variant="ghost"
          size="xs"
          :disabled="page >= totalPages"
          @click="
            () => {
              page++
            }
          "
        />
      </div>
    </div>

    <ApiKeyCreateModal v-model:open="showCreate" @created="onCreated" />
    <ApiKeySecretModal v-model:open="showSecret" :secret="newSecret" />
  </div>
</template>
