<script setup lang="ts">
import { computed, ref } from 'vue'
import { useSubscriptionList, type WebhookSubscription } from '@/queries/webhooks'
import WebhookCreateModal from './WebhookCreateModal.vue'
import WebhookSecretModal from './WebhookSecretModal.vue'

const props = defineProps<{ selectedUuid?: string }>()
const emit = defineEmits<{
  select: [item: WebhookSubscription]
  created: [item: WebhookSubscription]
}>()

const page = ref(1)
const perPage = ref(30)
const activeOnly = ref(false)

const { data, status: loadStatus } = useSubscriptionList(page, perPage, activeOnly)
const items = computed(() => data.value?.subscriptions ?? [])
const total = computed(() => data.value?.total ?? 0)
const totalPages = computed(() => data.value?.total_pages ?? 1)

const showCreate = ref(false)
const newSecret = ref('')
const showSecret = ref(false)

function onCreated(result: { subscription: WebhookSubscription; secret: string }) {
  newSecret.value = result.secret
  showSecret.value = true
  emit('created', result.subscription)
}

function host(url: string): string {
  try {
    return new URL(url).host
  } catch {
    return url
  }
}
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-col gap-3 lg:w-85 lg:shrink-0">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-highlighted">Webhooks</h2>
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

    <USwitch v-model="activeOnly" label="Active only" size="sm" />

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div v-if="loadStatus === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <UEmpty
        v-else-if="!items.length"
        icon="i-lucide-webhook"
        title="No webhooks"
        description="Create one to forward content events to an endpoint."
      />
      <div v-else class="flex flex-col gap-0.5">
        <button
          v-for="s in items"
          :key="s.uuid"
          type="button"
          class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors"
          :class="s.uuid === props.selectedUuid ? 'bg-elevated' : 'hover:bg-elevated/50'"
          @click="emit('select', s)"
        >
          <div class="flex size-10 shrink-0 items-center justify-center rounded-md bg-elevated">
            <UIcon name="i-lucide-webhook" class="size-5 text-muted" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-default">{{ host(s.url) }}</p>
            <p class="truncate text-xs text-muted">
              {{ s.events.length }} event{{ s.events.length === 1 ? '' : 's' }}
            </p>
          </div>
          <UBadge
            :label="s.is_active ? 'Active' : 'Paused'"
            :color="s.is_active ? 'success' : 'neutral'"
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
      <span class="text-xs font-medium uppercase tracking-wide">{{ total }} webhooks</span>
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

    <WebhookCreateModal v-model:open="showCreate" @created="onCreated" />
    <WebhookSecretModal
      v-model:open="showSecret"
      :secret="newSecret"
      title="Your webhook signing secret"
    />
  </div>
</template>
