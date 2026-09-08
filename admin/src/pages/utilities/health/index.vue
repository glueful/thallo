<script setup lang="ts">
import { computed } from 'vue'
import { useHealth, healthStatusColor, formatBytes } from '@/queries/health'

definePage({ meta: { requiresAuth: true } })

const { data, status, refresh, isLoading } = useHealth()
const health = computed(() => data.value)

function fmtTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <UDashboardPanel id="utilities-health">
    <template #header>
      <UDashboardNavbar title="Health">
        <template #right>
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            :loading="isLoading"
            @click="() => { refresh() }"
          >
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-3xl space-y-6">
        <div v-if="status === 'pending'" class="space-y-3">
          <USkeleton class="h-24" />
          <USkeleton class="h-40" />
        </div>

        <template v-else-if="health">
          <!-- Overall -->
          <div class="flex items-center justify-between gap-3 rounded-xl border border-default p-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-muted">Overall status</p>
              <p class="mt-1 text-lg font-semibold capitalize text-highlighted">
                {{ health.status }}
              </p>
            </div>
            <UBadge
              :label="health.status"
              :color="healthStatusColor(health.status)"
              variant="subtle"
              size="lg"
              class="capitalize"
            />
          </div>

          <!-- Checks -->
          <div>
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Checks</h2>
            <div class="flex flex-col gap-2">
              <div
                v-for="c in health.checks"
                :key="c.name"
                class="flex items-start gap-3 rounded-lg border border-default p-3"
              >
                <UBadge
                  :label="c.status"
                  :color="healthStatusColor(c.status)"
                  variant="subtle"
                  size="xs"
                  class="mt-0.5 shrink-0 capitalize"
                />
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium capitalize text-default">{{ c.name }}</p>
                  <p class="truncate text-xs text-muted">{{ c.message }}</p>
                  <ul v-if="c.issues?.length" class="mt-1 space-y-0.5 text-xs text-error" data-test="check-issues">
                    <li v-for="line in c.issues" :key="line">{{ line }}</li>
                  </ul>
                  <ul v-if="c.warnings?.length" class="mt-1 space-y-0.5 text-xs text-warning" data-test="check-warnings">
                    <li v-for="line in c.warnings" :key="line">{{ line }}</li>
                  </ul>
                  <ul v-if="c.recommendations?.length" class="mt-1 space-y-0.5 text-xs text-muted" data-test="check-recommendations">
                    <li v-for="line in c.recommendations" :key="line">Recommendation: {{ line }}</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <!-- System -->
          <div>
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">System</h2>
            <dl
              class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-xl border border-default p-4 text-sm sm:grid-cols-3"
            >
              <div>
                <dt class="text-muted">Framework</dt>
                <dd class="text-default">{{ health.version }}</dd>
              </div>
              <div>
                <dt class="text-muted">Environment</dt>
                <dd class="capitalize text-default">{{ health.environment }}</dd>
              </div>
              <div>
                <dt class="text-muted">PHP</dt>
                <dd class="text-default">{{ health.php_version }}</dd>
              </div>
              <div>
                <dt class="text-muted">Memory</dt>
                <dd class="text-default">
                  {{ formatBytes(health.memory_used) }} / {{ health.memory_limit }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">Peak memory</dt>
                <dd class="text-default">{{ formatBytes(health.memory_peak) }}</dd>
              </div>
              <div>
                <dt class="text-muted">Disk free</dt>
                <dd class="text-default">
                  {{ formatBytes(health.disk_free) }} / {{ formatBytes(health.disk_total) }}
                </dd>
              </div>
              <div class="col-span-2 sm:col-span-3">
                <dt class="text-muted">Checked</dt>
                <dd class="text-default">{{ fmtTime(health.timestamp) }}</dd>
              </div>
            </dl>
          </div>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
