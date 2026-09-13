<script setup lang="ts">
import { computed, ref, watch, toRef } from 'vue'
import { useDeliveryList, deliveryStatusMeta, formatDateTime } from '@/queries/webhooks'
import WebhookDeliveryModal from './WebhookDeliveryModal.vue'

const props = defineProps<{ subscriptionUuid: string }>()

const subscriptionUuid = toRef(props, 'subscriptionUuid')
const status = ref('')
const page = ref(1)
const perPage = ref(15)

// Reset paging when the subscription or the status filter changes.
watch([subscriptionUuid, status], () => {
  page.value = 1
})

const { data, status: loadStatus } = useDeliveryList(subscriptionUuid, status, page, perPage)
const items = computed(() => data.value?.deliveries ?? [])
const total = computed(() => data.value?.total ?? 0)
const totalPages = computed(() => data.value?.total_pages ?? 1)

const filters = [
  { label: 'All', value: '' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Failed', value: 'failed' },
  { label: 'Retrying', value: 'retrying' },
  { label: 'Pending', value: 'pending' },
]

const openUuid = ref('')
const showDelivery = ref(false)
function openDelivery(uuid: string) {
  openUuid.value = uuid
  showDelivery.value = true
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex items-center justify-between">
      <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Recent deliveries</h3>
      <span v-if="total > 0" class="text-xs text-muted">{{ total }} total</span>
    </div>

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

    <div v-if="loadStatus === 'pending'" class="flex justify-center py-6">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin text-muted" />
    </div>
    <p v-else-if="!items.length" class="py-4 text-sm text-muted">No deliveries yet.</p>
    <div v-else class="flex flex-col gap-0.5">
      <button
        v-for="d in items"
        :key="d.uuid"
        type="button"
        class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-elevated/50"
        @click="openDelivery(d.uuid)"
      >
        <UBadge
          :label="deliveryStatusMeta(d.status).label"
          :color="deliveryStatusMeta(d.status).color"
          variant="subtle"
          size="xs"
          class="shrink-0"
        />
        <div class="min-w-0 flex-1">
          <p class="truncate font-mono text-xs text-default">{{ d.event }}</p>
          <p class="truncate text-xs text-muted">{{ formatDateTime(d.created_at) }}</p>
        </div>
        <span v-if="d.attempts > 1" class="shrink-0 text-xs text-muted">×{{ d.attempts }}</span>
        <span v-if="d.response_code" class="shrink-0 font-mono text-xs text-muted">
          {{ d.response_code }}
        </span>
      </button>
    </div>

    <div v-if="total > perPage" class="flex items-center justify-end gap-1 text-muted">
      <span class="text-xs">{{ page }} / {{ totalPages }}</span>
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

    <WebhookDeliveryModal v-if="openUuid" v-model:open="showDelivery" :uuid="openUuid" />
  </div>
</template>
