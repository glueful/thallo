<script setup lang="ts">
import { computed, ref, type Ref } from 'vue'
import {
  rangeFor,
  useAnalyticsSummary,
  useAnalyticsSeries,
  useAnalyticsBreakdown,
  type RangePreset,
} from '@/queries/analytics'
import AnalyticsLineChart, {
  type LineSeries,
} from '@/pages/analytics/components/AnalyticsLineChart.vue'
import AnalyticsBarChart from '@/pages/analytics/components/AnalyticsBarChart.vue'

const preset = ref<RangePreset>(30)
const range = computed(() => rangeFor(preset.value))
const PRESETS: RangePreset[] = [7, 30, 90]

const { data: summary } = useAnalyticsSummary(range)
const kpi = (event: string) => summary.value?.totals?.[event] ?? 0
const activeUsers = computed(() => summary.value?.active_users ?? 0)
// When the read is workspace-scoped, auth rollups (logins, active users) carry no tenant and read
// zero — they're platform-level, not workspace data — so drop those panels in a workspace view.
const scoped = computed(() => summary.value?.scoped ?? false)

const logins = useAnalyticsSeries('auth.login', range)
const loginsFailed = useAnalyticsSeries('auth.login_failed', range)
const entries = useAnalyticsSeries('content.entry.created', range)
const rows = useAnalyticsSeries('collections.row.created', range)
const activeUsersSeries = useAnalyticsSeries('active_users', range)

const pts = (q: { data: Ref<{ day: string; count: number }[] | undefined> }) => q.data.value ?? []

const activityTrend = computed<LineSeries[]>(() => {
  const series: LineSeries[] = []
  // Logins is a platform-level auth metric — omit it in a workspace-scoped view.
  if (!scoped.value) {
    series.push({ key: 'logins', label: 'Logins', color: 'var(--ui-primary)', points: pts(logins) })
  }
  series.push({ key: 'entries', label: 'Entries', color: 'var(--ui-success)', points: pts(entries) })
  series.push({ key: 'rows', label: 'Rows', color: 'var(--ui-warning)', points: pts(rows) })
  return series
})
const activeUsersTrend = computed<LineSeries[]>(() => [
  { key: 'active', label: 'Active users', color: 'var(--ui-primary)', points: pts(activeUsersSeries) },
])
const authHealth = computed<LineSeries[]>(() => [
  { key: 'ok', label: 'Login', color: 'var(--ui-success)', points: pts(logins) },
  { key: 'failed', label: 'Failed', color: 'var(--ui-error)', points: pts(loginsFailed) },
])

// Breakdown: one event at a time via the segmented control.
type BreakdownSegment = 'collections' | 'types'
const segment = ref<BreakdownSegment>('types')
const breakdownEvent = computed(() =>
  segment.value === 'collections' ? 'collections.row.created' : 'content.entry.created',
)
const { data: breakdown } = useAnalyticsBreakdown(breakdownEvent, range)
const breakdownItems = computed(() => breakdown.value ?? [])

function fmt(n: number): string {
  return new Intl.NumberFormat().format(n)
}

// Void-returning click handlers. An inline `@click="preset = p"` compiles to a handler that RETURNS
// the assigned value, which trips Nuxt UI's `onClick: (e) => void` type (TS2322). These wrappers
// keep the handler `void`.
function setPreset(p: RangePreset): void {
  preset.value = p
}
function setSegment(seg: BreakdownSegment): void {
  segment.value = seg
}
</script>

<template>
  <UDashboardPanel id="analytics">
    <template #body>
      <div class="flex flex-col gap-4 p-4">
        <!-- Header: title + range presets -->
        <div class="flex items-center justify-between">
          <h1 class="text-lg font-semibold text-highlighted">Analytics</h1>
          <div class="flex gap-1" role="group" aria-label="Time range">
            <UButton
              v-for="p in PRESETS"
              :key="p"
              :data-test="`range-${p}`"
              size="xs"
              :variant="preset === p ? 'solid' : 'ghost'"
              :color="preset === p ? 'primary' : 'neutral'"
              @click="setPreset(p)"
            >
              {{ p }}d
            </UButton>
          </div>
        </div>

        <!-- KPI cards -->
        <div class="grid grid-cols-2 gap-3" :class="scoped ? 'lg:grid-cols-2' : 'lg:grid-cols-4'">
          <div v-if="!scoped" data-test="kpi-active" class="rounded-lg border border-default p-4">
            <div class="text-xs text-muted">Active users</div>
            <div class="text-2xl font-semibold text-highlighted">{{ fmt(activeUsers) }}</div>
          </div>
          <div v-if="!scoped" data-test="kpi-logins" class="rounded-lg border border-default p-4">
            <div class="text-xs text-muted">Logins</div>
            <div class="text-2xl font-semibold text-highlighted">{{ fmt(kpi('auth.login')) }}</div>
          </div>
          <div data-test="kpi-entries" class="rounded-lg border border-default p-4">
            <div class="text-xs text-muted">Entries created</div>
            <div class="text-2xl font-semibold text-highlighted">
              {{ fmt(kpi('content.entry.created')) }}
            </div>
          </div>
          <div data-test="kpi-rows" class="rounded-lg border border-default p-4">
            <div class="text-xs text-muted">Rows created</div>
            <div class="text-2xl font-semibold text-highlighted">
              {{ fmt(kpi('collections.row.created')) }}
            </div>
          </div>
        </div>

        <!-- Activity trend -->
        <section class="rounded-lg border border-default p-4">
          <h2 class="mb-2 text-sm font-medium text-highlighted">Activity trend</h2>
          <AnalyticsLineChart :series="activityTrend" />
        </section>

        <!-- Active users / day + Auth health — platform-level auth metrics, hidden in a workspace view -->
        <div v-if="!scoped" class="grid gap-4 lg:grid-cols-2">
          <section class="rounded-lg border border-default p-4">
            <h2 class="mb-2 text-sm font-medium text-highlighted">Active users / day</h2>
            <AnalyticsLineChart :series="activeUsersTrend" />
          </section>
          <section class="rounded-lg border border-default p-4">
            <h2 class="mb-2 text-sm font-medium text-highlighted">Auth health</h2>
            <AnalyticsLineChart :series="authHealth" />
          </section>
        </div>

        <!-- Breakdown -->
        <section class="rounded-lg border border-default p-4">
          <div class="mb-2 flex items-center justify-between">
            <h2 class="text-sm font-medium text-highlighted">Most active</h2>
            <div class="flex gap-1" role="group" aria-label="Breakdown dimension">
               <UButton
                data-test="seg-types"
                size="xs"
                :variant="segment === 'types' ? 'solid' : 'ghost'"
                :color="segment === 'types' ? 'primary' : 'neutral'"
                @click="setSegment('types')"
              >
                Content types
              </UButton>
              <UButton
                data-test="seg-collections"
                size="xs"
                :variant="segment === 'collections' ? 'solid' : 'ghost'"
                :color="segment === 'collections' ? 'primary' : 'neutral'"
                @click="setSegment('collections')"
              >
                Collections
              </UButton>
            </div>
          </div>
          <AnalyticsBarChart :items="breakdownItems" />
        </section>
      </div>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.analytics
</route>
