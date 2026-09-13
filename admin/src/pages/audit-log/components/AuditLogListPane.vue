<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  useAuditLogs,
  auditActionMeta,
  AUDIT_CATEGORIES,
  AUDIT_ACTIONS,
  type AuditLogRow,
  type AuditLogFilters,
} from '@/queries/audit'
import AuditLogListItem from './AuditLogListItem.vue'

const props = defineProps<{ selectedUuid?: string }>()
const emit = defineEmits<{ select: [row: AuditLogRow] }>()

// Reka UI's Select reserves the empty string for "cleared", so the "All" option uses a non-empty
// sentinel that we translate back to "no filter" when building the query.
const ALL = 'all'

const page = ref(1)
const perPage = ref(25)
const category = ref(ALL)
const action = ref(ALL)

const filters = computed<AuditLogFilters>(() => ({
  category: category.value === ALL ? undefined : category.value,
  action: action.value === ALL ? undefined : action.value,
}))

// Any filter change resets to the first page.
watch(filters, () => {
  page.value = 1
})

const { data, status } = useAuditLogs(page, perPage, filters)

const total = computed(() => data.value?.total ?? 0)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

const categoryItems = computed(() => [
  { label: 'All categories', value: ALL },
  ...AUDIT_CATEGORIES.map((c) => ({ label: c.charAt(0).toUpperCase() + c.slice(1), value: c })),
])
const actionItems = computed(() => [
  { label: 'All actions', value: ALL },
  ...AUDIT_ACTIONS.map((a) => ({ label: auditActionMeta(a).label, value: a })),
])
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-col gap-3 lg:w-90 lg:shrink-0">
    <h2 class="text-lg font-semibold text-highlighted">Audit log</h2>

    <div class="flex gap-2">
      <USelect v-model="category" :items="categoryItems" size="sm" class="flex-1" />
      <USelect v-model="action" :items="actionItems" size="sm" class="flex-1" />
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div v-if="status === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <UEmpty
        v-else-if="!(data?.rows ?? []).length"
        icon="i-lucide-scroll-text"
        title="No activity"
        description="Nothing matches these filters yet."
      />
      <div v-else class="flex flex-col gap-0.5">
        <AuditLogListItem
          v-for="row in data?.rows ?? []"
          :key="row.uuid"
          :row="row"
          :selected="row.uuid === props.selectedUuid"
          @select="emit('select', row)"
        />
      </div>
    </div>

    <div
      v-if="total > 0"
      class="flex items-center justify-between gap-2 border-t border-default py-3 text-muted"
    >
      <span class="text-xs font-medium uppercase tracking-wide">{{ total }} entries</span>
      <div class="flex items-center gap-1">
        <span class="text-sm">Page {{ page }} / {{ totalPages }}</span>
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
  </div>
</template>
