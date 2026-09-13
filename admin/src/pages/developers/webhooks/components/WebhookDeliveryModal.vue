<script setup lang="ts">
import { computed } from 'vue'
import {
  useDelivery,
  useWebhookMutations,
  deliveryStatusMeta,
  formatDateTime,
} from '@/queries/webhooks'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ uuid: string }>()
const open = defineModel<boolean>('open', { required: true })

const { data: delivery, status } = useDelivery(() => (open.value ? props.uuid : undefined))
const { retry } = useWebhookMutations()
const { success, error: notifyError } = useNotify()

const canRetry = computed(
  () => delivery.value?.status === 'failed' || delivery.value?.status === 'retrying',
)

const payloadJson = computed(() =>
  delivery.value?.payload ? JSON.stringify(delivery.value.payload, null, 2) : '',
)

async function runRetry() {
  try {
    await retry.mutateAsync(props.uuid)
    success('Delivery queued for retry')
    open.value = false
  } catch (e) {
    notifyError(e, 'Could not retry the delivery')
  }
}
</script>

<template>
  <UModal v-model:open="open" title="Delivery" :ui="{ content: 'sm:max-w-2xl' }">
    <template #body>
      <div v-if="status === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <div v-else-if="delivery" class="flex flex-col gap-4">
        <dl class="grid grid-cols-3 gap-y-2 text-sm">
          <dt class="text-muted">Event</dt>
          <dd class="col-span-2 font-mono text-default">{{ delivery.event }}</dd>
          <dt class="text-muted">Status</dt>
          <dd class="col-span-2">
            <UBadge
              :label="deliveryStatusMeta(delivery.status).label"
              :color="deliveryStatusMeta(delivery.status).color"
              variant="subtle"
              size="sm"
            />
          </dd>
          <dt class="text-muted">Attempts</dt>
          <dd class="col-span-2 text-default">{{ delivery.attempts }}</dd>
          <dt class="text-muted">Response code</dt>
          <dd class="col-span-2 text-default">{{ delivery.response_code ?? '—' }}</dd>
          <dt class="text-muted">Created</dt>
          <dd class="col-span-2 text-default">{{ formatDateTime(delivery.created_at) }}</dd>
          <dt class="text-muted">Delivered</dt>
          <dd class="col-span-2 text-default">{{ formatDateTime(delivery.delivered_at) }}</dd>
          <template v-if="delivery.next_retry_at">
            <dt class="text-muted">Next retry</dt>
            <dd class="col-span-2 text-default">{{ formatDateTime(delivery.next_retry_at) }}</dd>
          </template>
        </dl>

        <div>
          <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">Payload</h3>
          <pre
            class="max-h-48 overflow-auto rounded-lg border border-default bg-elevated/50 p-3 font-mono text-xs text-default"
            >{{ payloadJson }}</pre
          >
        </div>

        <div v-if="delivery.response_body">
          <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">
            Response body
          </h3>
          <pre
            class="max-h-40 overflow-auto rounded-lg border border-default bg-elevated/50 p-3 font-mono text-xs text-default"
            >{{ delivery.response_body }}</pre
          >
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Close"
          @click="
            () => {
              open = false
            }
          "
        />
        <UButton
          v-if="canRetry"
          label="Retry"
          icon="i-lucide-refresh-cw"
          :loading="retry.isLoading.value"
          @click="runRetry"
        />
      </div>
    </template>
  </UModal>
</template>
