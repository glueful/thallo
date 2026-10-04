<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue'
import { useEventListener } from '@vueuse/core'
import { ApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import {
  isActive,
  useSearchRebuild,
  useSearchStatus,
  type SearchKindStatus,
} from '@/queries/searchStatus'

// Settings › Search (search block spec §3.8): the engine, each kind's index for this workspace, and
// Rebuild. Polls every 5 s while something is pending or building, every minute otherwise, and
// refreshes when the window regains focus — so an open panel learns of a later failure.
definePage({ meta: { requiresAuth: true, requiresCapability: 'thallo.search' } })

const { success, error: notifyError } = useNotify()
const { data, status, error, refresh } = useSearchStatus()
const rebuild = useSearchRebuild()

const forbidden = computed(() => error.value instanceof ApiError && error.value.status === 403)
const active = computed(() => (data.value?.kinds ?? []).some(isActive))
const legacy = computed(() =>
  (data.value?.kinds ?? []).some((k) => k.available && k.format === 'legacy'),
)

// One timer, restarted whenever the pace changes, so the first slow poll comes a full minute after
// work finishes rather than whenever an independent interval happens to tick.
let timer: ReturnType<typeof setTimeout> | undefined
function schedule() {
  clearTimeout(timer)
  timer = setTimeout(
    () => {
      refresh()
      schedule()
    },
    active.value ? 5000 : 60000,
  )
}
watch(active, schedule, { immediate: true })
onBeforeUnmount(() => clearTimeout(timer))
useEventListener(window, 'focus', () => refresh())

const STATUS: Record<
  SearchKindStatus['status'],
  { label: string; color: 'success' | 'warning' | 'error' | 'neutral' | 'info' }
> = {
  ready: { label: 'Ready', color: 'success' },
  building: { label: 'Building', color: 'info' },
  pending: { label: 'Pending', color: 'neutral' },
  out_of_date: { label: 'Out of date', color: 'warning' },
  failed: { label: 'Failed', color: 'error' },
}

async function onRebuild(kind: string | null) {
  try {
    await rebuild.mutateAsync(kind)
    success(kind === null ? 'Rebuild requested for every kind' : 'Rebuild requested')
  } catch (e) {
    notifyError(e, 'Couldn’t request a rebuild')
  }
}
</script>

<template>
  <UDashboardPanel id="settings-search">
    <template #header>
      <UDashboardNavbar title="Search">
        <template #right>
          <UButton
            icon="i-lucide-refresh-cw"
            data-test="search-rebuild-all"
            :loading="rebuild.isLoading.value"
            :disabled="!data"
            @click="onRebuild(null)"
          >
            Rebuild all
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <p v-if="forbidden" class="text-sm text-muted" data-test="search-forbidden">
        Ask an administrator for access to search settings.
      </p>
      <template v-else-if="data">
        <UCard class="mb-4">
          <template #header><h2 class="font-semibold text-default">Engine</h2></template>
          <p v-if="data.engine.ready" class="text-sm" data-test="search-engine">
            Ready<span v-if="data.engine.version"> (Meilisearch {{ data.engine.version }})</span>.
          </p>
          <p v-else class="text-sm text-error" data-test="search-engine">
            {{ data.engine.message }}
          </p>
        </UCard>

        <p v-if="legacy" class="mb-4 text-sm text-muted" data-test="search-cutover">
          The index is being rebuilt into its new format. Search keeps answering from the old index
          meanwhile, where that is safe.
        </p>

        <table class="w-full text-sm" data-test="search-kinds">
          <thead>
            <tr class="text-left text-muted">
              <th class="py-2">Kind</th>
              <th>Status</th>
              <th>Documents</th>
              <th>Last success</th>
              <th>Last error</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="k in data.kinds"
              :key="k.kind"
              class="border-t border-default align-top"
              :data-test="`search-kind-${k.kind}`"
            >
              <td class="py-2 font-medium">{{ k.label }}</td>
              <td>
                <span v-if="!k.available" class="text-muted">{{ k.reason }}</span>
                <UBadge
                  v-else
                  :label="STATUS[k.status].label"
                  :color="STATUS[k.status].color"
                  variant="subtle"
                  size="sm"
                />
                <p v-if="k.available && k.status === 'building'" class="text-xs text-muted">
                  {{ k.processed }} processed
                </p>
                <p v-if="k.stalled" class="mt-1 text-xs text-warning" data-test="search-stalled">
                  Background processing hasn’t picked this up. Make sure the queue worker and
                  scheduler are running, or run <code>php glueful search:reindex --wait</code>.
                </p>
              </td>
              <td>{{ k.documents }}</td>
              <td>{{ k.last_success_at ?? '—' }}</td>
              <td class="max-w-xs break-words text-xs text-muted">{{ k.last_error ?? '—' }}</td>
              <td class="text-right">
                <UButton
                  v-if="k.available"
                  size="xs"
                  variant="soft"
                  :data-test="`search-rebuild-${k.kind}`"
                  @click="onRebuild(k.kind)"
                >
                  Rebuild
                </UButton>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
      <p v-else-if="status === 'pending'" class="text-sm text-muted">Loading…</p>
    </template>
  </UDashboardPanel>
</template>
