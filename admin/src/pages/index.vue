<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useHomeOverview } from '@/queries/home'
import { useCreateEntry } from '@/queries/entries'
import { useSessionStore } from '@/stores/session'
import { useNotify } from '@/composables/useNotify'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { rangeFor, useAnalyticsSummary } from '@/queries/analytics'

definePage({ meta: { requiresAuth: true } })

const router = useRouter()
const session = useSessionStore()
const { error: notifyError } = useNotify()
const { data, status } = useHomeOverview()
const { mutateAsync: createEntry } = useCreateEntry()

const overview = computed(() => data.value)

const caps = useCapabilitiesStore()
const analyticsOn = computed(() => caps.isEnabled('thallo.analytics'))
const analyticsRange = computed(() => rangeFor(30))
// Pass analyticsOn as the `enabled` gate: when the pack is disabled the summary query never fires,
// so Home never hits the (404'd) /analytics/summary route.
const { data: analyticsSummary } = useAnalyticsSummary(analyticsRange, analyticsOn)
// Format KPI counts the same way the /analytics page does (Intl grouping), so 1,200 reads
// consistently across Home and the dashboard.
const fmtCount = (n: number): string => new Intl.NumberFormat().format(n)
const homeKpi = (event: string): string => fmtCount(analyticsSummary.value?.totals?.[event] ?? 0)
const homeActiveUsers = computed(() => fmtCount(analyticsSummary.value?.active_users ?? 0))

// First-run target: the seeded Pages type if present, otherwise the first type. A fresh install
// seeds a Pages type, so first-run is "create your first page" (one click) rather than the
// cold-start "define a content type" — that only shows when there are no types at all.
const firstRunType = computed(() => {
  const types = overview.value?.types ?? []
  return types.find((t) => t.slug === 'page') ?? types[0]
})

function singularize(name: string): string {
  return name.endsWith('s') ? name.slice(0, -1) : name
}

const creatingSlug = ref('')

async function onCreate(slug: string) {
  if (!slug || creatingSlug.value) return
  creatingSlug.value = slug
  try {
    const uuid = await createEntry(slug)
    if (uuid) router.push(`/content/${slug}/${uuid}`)
  } catch (e) {
    notifyError(e, 'Could not create entry')
  } finally {
    creatingSlug.value = ''
  }
}

function statusColor(s: string): 'success' | 'warning' | 'neutral' {
  if (s === 'published') return 'success'
  if (s === 'scheduled') return 'warning'
  return 'neutral'
}

function fmtTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <UDashboardPanel id="home">
    <template #header>
      <UDashboardNavbar title="Home" />
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-5xl space-y-8">
        <header>
          <h1 class="text-xl font-semibold text-highlighted">
            Welcome{{ session.user?.email ? `, ${session.user.email}` : '' }}
          </h1>
          <p class="text-sm text-muted">Here's a snapshot of your content.</p>
        </header>

        <!-- Analytics KPI strip (only when thallo.analytics is enabled) -->
        <div
          v-if="analyticsOn"
          data-test="home-analytics-strip"
          class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
          <RouterLink
            to="/analytics"
            class="rounded-lg border border-default p-3 hover:bg-elevated"
          >
            <div class="text-xs text-muted">Active users</div>
            <div class="text-xl font-semibold text-highlighted">{{ homeActiveUsers }}</div>
          </RouterLink>
          <RouterLink to="/analytics" class="rounded-lg border border-default p-3 hover:bg-elevated">
            <div class="text-xs text-muted">Logins</div>
            <div class="text-xl font-semibold text-highlighted">{{ homeKpi('auth.login') }}</div>
          </RouterLink>
          <RouterLink to="/analytics" class="rounded-lg border border-default p-3 hover:bg-elevated">
            <div class="text-xs text-muted">Entries created</div>
            <div class="text-xl font-semibold text-highlighted">
              {{ homeKpi('content.entry.created') }}
            </div>
          </RouterLink>
          <RouterLink to="/analytics" class="rounded-lg border border-default p-3 hover:bg-elevated">
            <div class="text-xs text-muted">Rows created</div>
            <div class="text-xl font-semibold text-highlighted">
              {{ homeKpi('collections.row.created') }}
            </div>
          </RouterLink>
        </div>

        <!-- Loading -->
        <div v-if="status === 'pending'" class="space-y-4">
          <USkeleton class="h-28" />
          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <USkeleton v-for="n in 3" :key="n" class="h-24" />
          </div>
        </div>

        <!-- Cold start: no content types at all -->
        <UEmpty
          v-else-if="!overview || !overview.types.length"
          icon="i-lucide-layers"
          title="No content types yet"
          description="Define your first content type in Settings → Content Types to start authoring."
        />

        <!-- First run: types exist but nothing has been authored -->
        <UCard v-else-if="overview.total_entries === 0 && firstRunType">
          <div class="flex flex-col items-start gap-3 py-2">
            <UIcon name="i-lucide-sparkles" class="size-8 text-primary" />
            <div>
              <h2 class="text-lg font-semibold text-highlighted">
                Create your first {{ singularize(firstRunType.name).toLowerCase() }}
              </h2>
              <p class="mt-1 text-sm text-muted">
                Your space is set up. Author your first
                {{ singularize(firstRunType.name).toLowerCase() }} to get the editorial loop going.
              </p>
            </div>
            <UButton
              icon="i-lucide-plus"
              :label="`New ${singularize(firstRunType.name).toLowerCase()}`"
              :loading="creatingSlug === firstRunType.slug"
              @click="onCreate(firstRunType.slug)"
            />
          </div>
        </UCard>

        <!-- Dashboard -->
        <template v-else>
          <!-- Recent entries -->
          <section v-if="overview.recent.length" class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">Recent entries</h2>
            <UCard :ui="{ body: 'p-0 sm:p-0' }">
              <ul class="divide-y divide-default">
                <li
                  v-for="e in overview.recent"
                  :key="e.uuid"
                  class="flex items-center justify-between gap-3 px-4 py-3 transition hover:bg-elevated/50"
                >
                  <RouterLink :to="`/content/${e.type_slug}/${e.uuid}`" class="min-w-0 flex-1">
                    <p class="truncate font-medium text-default">{{ e.display_title }}</p>
                    <p class="mt-0.5 text-xs text-muted">
                      {{ e.type_name }} · updated {{ fmtTime(e.updated_at) }}
                    </p>
                  </RouterLink>
                  <UBadge :color="statusColor(e.status)" variant="subtle" size="sm">
                    {{ e.status }}
                  </UBadge>
                </li>
              </ul>
            </UCard>
          </section>

          <!-- Content types with counts + quick create -->
          <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">Content types</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <UCard v-for="ct in overview.types" :key="ct.slug">
                <div class="flex items-start justify-between gap-3">
                  <RouterLink :to="`/content/${ct.slug}`" class="min-w-0">
                    <div class="flex items-center gap-2">
                      <UIcon name="i-lucide-file-text" class="size-5 shrink-0 text-muted" />
                      <p class="truncate font-medium text-default">{{ ct.name }}</p>
                    </div>
                    <p class="mt-1 text-xs text-muted">
                      {{ ct.count }} {{ ct.count === 1 ? 'entry' : 'entries' }}
                    </p>
                  </RouterLink>
                  <UButton
                    icon="i-lucide-plus"
                    color="neutral"
                    variant="subtle"
                    size="xs"
                    :aria-label="`New ${singularize(ct.name)}`"
                    :loading="creatingSlug === ct.slug"
                    @click="onCreate(ct.slug)"
                  />
                </div>
              </UCard>
            </div>
          </section>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
