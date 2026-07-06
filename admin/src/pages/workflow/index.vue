<script setup lang="ts">
import { computed } from 'vue'
import { useWorkflowQueue } from '@/queries/workflow'
import { useCapabilitiesStore } from '@/stores/capabilities'

definePage({ meta: { requiresAuth: true } })

const caps = useCapabilitiesStore()
const enabled = computed(() => caps.isEnabled('thallo.workflow'))

const { data, isLoading, error } = useWorkflowQueue(enabled)
const items = computed(() => data.value?.items ?? [])

function ageOf(submittedAt: string | null): string {
  if (!submittedAt) return '—'
  const ms = Date.now() - new Date(submittedAt + 'Z').getTime()
  const hours = Math.max(0, Math.floor(ms / 3_600_000))
  if (hours < 1) return '<1h'
  if (hours < 24) return `${hours}h`
  return `${Math.floor(hours / 24)}d`
}
</script>

<template>
  <div class="space-y-6 p-6" data-test="workflow-queue">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-semibold">Review queue</h1>
      <UBadge v-if="data" color="info" variant="subtle">{{ data.total }} waiting</UBadge>
    </div>

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      title="Could not load the review queue"
      data-test="workflow-queue-error"
    />

    <div v-else-if="!isLoading && items.length === 0" data-test="workflow-queue-empty">
      <UCard>
        <p class="text-muted text-sm">Nothing waiting for review.</p>
      </UCard>
    </div>

    <UCard v-else-if="items.length > 0" :ui="{ body: 'p-0' }">
      <ul class="divide-default divide-y">
        <li v-for="item in items" :key="`${item.entry_uuid}-${item.locale}`">
          <RouterLink
            :to="`/content/${item.type_slug}/${item.entry_uuid}?locale=${item.locale}`"
            class="hover:bg-elevated flex items-center justify-between gap-4 px-4 py-3"
            data-test="workflow-queue-row"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ item.title ?? item.entry_uuid }}</p>
              <p class="text-muted text-xs">
                {{ item.type_slug }} · {{ item.locale }} · by {{ item.submitted_by ?? 'unknown' }}
              </p>
            </div>
            <span class="text-muted shrink-0 text-xs">{{ ageOf(item.submitted_at) }}</span>
          </RouterLink>
        </li>
      </ul>
    </UCard>
  </div>
</template>
