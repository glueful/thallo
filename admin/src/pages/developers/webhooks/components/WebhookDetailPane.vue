<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import {
  useWebhookMutations,
  useSubscriptionStats,
  WEBHOOK_EVENTS,
  WEBHOOK_EVENT_PATTERNS,
  type WebhookSubscription,
} from '@/queries/webhooks'
import { useNotify } from '@/composables/useNotify'
import WebhookSecretModal from './WebhookSecretModal.vue'
import WebhookDeliveriesPane from './WebhookDeliveriesPane.vue'

const props = defineProps<{ item: WebhookSubscription }>()
const emit = defineEmits<{ deleted: [uuid: string] }>()

const { update, remove, rotateSecret, test } = useWebhookMutations()
const { success, error: notifyError } = useNotify()
const { data: stats } = useSubscriptionStats(() => props.item.uuid)

const eventItems: string[] = [...WEBHOOK_EVENT_PATTERNS, ...WEBHOOK_EVENTS]

const form = reactive({ url: '', is_active: true })
const events = ref<string[]>([])

watch(
  () => props.item,
  (it) => {
    form.url = it.url
    form.is_active = it.is_active
    events.value = [...it.events]
  },
  { immediate: true },
)

const dirty = computed(
  () =>
    form.url !== props.item.url ||
    form.is_active !== props.item.is_active ||
    JSON.stringify([...events.value].sort()) !== JSON.stringify([...props.item.events].sort()),
)

const newSecret = ref('')
const showSecret = ref(false)
const pendingDelete = ref(false)

async function save() {
  try {
    await update.mutateAsync({
      uuid: props.item.uuid,
      input: { url: form.url.trim(), events: events.value, is_active: form.is_active },
    })
    success('Webhook saved')
  } catch (e) {
    notifyError(e, 'Could not save the webhook')
  }
}

async function runTest() {
  try {
    const res = await test.mutateAsync(props.item.uuid)
    success('Test delivered', res.status_code ? `Endpoint responded ${res.status_code}` : undefined)
  } catch (e) {
    notifyError(e, 'Test delivery failed')
  }
}

async function runRotate() {
  try {
    newSecret.value = await rotateSecret.mutateAsync(props.item.uuid)
    showSecret.value = true
  } catch (e) {
    notifyError(e, 'Could not rotate the secret')
  }
}

async function confirmDelete() {
  try {
    await remove.mutateAsync(props.item.uuid)
    success('Webhook deleted')
    pendingDelete.value = false
    emit('deleted', props.item.uuid)
  } catch (e) {
    notifyError(e, 'Could not delete the webhook')
  }
}
</script>

<template>
  <div class="flex flex-col gap-5">
    <!-- Endpoint -->
    <div>
      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">
        Endpoint URL
      </label>
      <UInput v-model="form.url" class="w-full" />
    </div>

    <!-- Events -->
    <div>
      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted"
        >Events</label
      >
      <USelectMenu v-model="events" :items="eventItems" multiple class="w-full" />
    </div>

    <!-- Active -->
    <USwitch
      v-model="form.is_active"
      label="Active"
      description="Paused webhooks receive no deliveries."
    />

    <!-- Save -->
    <UButton
      label="Save changes"
      icon="i-lucide-save"
      block
      :disabled="!dirty"
      :loading="update.isLoading.value"
      @click="save"
    />

    <!-- Stats -->
    <div v-if="stats">
      <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
        Last {{ stats.period_days }} days
      </h3>
      <div class="grid grid-cols-4 gap-2 text-center">
        <div class="rounded-lg border border-default p-2">
          <p class="text-sm font-semibold text-default">{{ Math.round(stats.success_rate) }}%</p>
          <p class="text-xs text-muted">Success</p>
        </div>
        <div class="rounded-lg border border-default p-2">
          <p class="text-sm font-semibold text-default">{{ stats.delivered }}</p>
          <p class="text-xs text-muted">Delivered</p>
        </div>
        <div class="rounded-lg border border-default p-2">
          <p class="text-sm font-semibold text-default">{{ stats.failed }}</p>
          <p class="text-xs text-muted">Failed</p>
        </div>
        <div class="rounded-lg border border-default p-2">
          <p class="text-sm font-semibold text-default">{{ stats.pending }}</p>
          <p class="text-xs text-muted">Pending</p>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="flex flex-col gap-2">
      <span class="text-xs font-semibold uppercase tracking-wide text-muted">Actions</span>
      <UButton
        label="Send test event"
        icon="i-lucide-send"
        color="neutral"
        variant="outline"
        block
        :loading="test.isLoading.value"
        @click="runTest"
      />
      <UButton
        label="Rotate signing secret"
        icon="i-lucide-refresh-cw"
        color="neutral"
        variant="outline"
        block
        :loading="rotateSecret.isLoading.value"
        @click="runRotate"
      />
      <UButton
        label="Delete webhook"
        icon="i-lucide-trash-2"
        color="error"
        variant="soft"
        block
        @click="
          () => {
            pendingDelete = true
          }
        "
      />
    </div>

    <!-- Deliveries -->
    <div class="border-t border-default pt-4">
      <WebhookDeliveriesPane :subscription-uuid="item.uuid" />
    </div>

    <!-- Delete confirm -->
    <UModal v-model:open="pendingDelete" title="Delete webhook">
      <template #body>
        <p class="text-sm text-muted">
          Delete this webhook? Its delivery history is removed and the endpoint will stop receiving
          events. This cannot be undone.
        </p>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            label="Cancel"
            :disabled="remove.isLoading.value"
            @click="
              () => {
                pendingDelete = false
              }
            "
          />
          <UButton
            color="error"
            icon="i-lucide-trash-2"
            label="Delete"
            :loading="remove.isLoading.value"
            @click="confirmDelete"
          />
        </div>
      </template>
    </UModal>

    <WebhookSecretModal
      v-model:open="showSecret"
      :secret="newSecret"
      title="Your new signing secret"
    />
  </div>
</template>
