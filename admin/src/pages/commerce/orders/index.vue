<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { refDebounced } from '@vueuse/core'
import { parseDate, type DateValue } from '@internationalized/date'
import {
  useOrderSearch,
  downloadOrdersCsv,
  ExportTooLargeError,
  parseOrderSearchQuery,
  serializeOrderSearchQuery,
  type OrderSearchFilters,
} from '@/queries/commerceOrderSearch'
import {
  ORDER_STATUSES,
  FULFILLMENT_STATUSES,
  type CommerceOrder,
  type CommerceOrderStatus,
  type CommerceFulfillmentStatus,
} from '@/queries/commerceOrders'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'
import OrdersTable from './components/OrdersTable.vue'

const route = useRoute()
const router = useRouter()
const { warning, error: notifyError } = useNotify()

const { data: meta } = useCommerceMeta()
const canView = computed(() => meta.value?.can_view ?? false)
// Task 14 (admin-order-creation): the walk-in draft workspace entry point — manage-graded (the
// same gate every draft mutation endpoint requires server-side), never view-only.
const canManage = computed(() => meta.value?.can_manage ?? false)

// USelect/reka-ui reserve the empty string as "no selection" and reject a SelectItem with an
// empty `value` — so the "All" option uses a non-empty sentinel, translated to `null` (no filter)
// at the query boundary (mirrors the pre-existing status filter and the discounts/products pages).
const ALL = 'all'

// ── One guarded initial hydration, BEFORE any watcher is installed (URL contract, spec §2.4) ──
// Only enum members / strict round-trip dates / in-range page & per_page survive; everything else
// silently discards back to ORDER_SEARCH_DEFAULTS rather than being forwarded to a backend that
// would 422 on it anyway. This runs exactly once, synchronously, before any watch() below exists —
// so a hydrated non-default page can never be reset by the page-reset watcher.
const hydrated = parseOrderSearchQuery(route.query as Record<string, unknown>)

const rawQuery = ref(hydrated.q)
const statusFilter = ref<typeof ALL | CommerceOrderStatus>(hydrated.status ?? ALL)
const fulfillmentFilter = ref<typeof ALL | CommerceFulfillmentStatus>(hydrated.fulfillment ?? ALL)
const placedFrom = ref<string | null>(hydrated.placedFrom)
const placedTo = ref<string | null>(hydrated.placedTo)
const page = ref(hydrated.page)
const perPage = ref(hydrated.perPage)

// Debounce applies to the free-text search ONLY — every other filter takes effect immediately.
// The initial value mirrors synchronously (vueuse's refDebounced seeds the result ref from the
// source's CURRENT value), so a hydrated `q` is applied immediately, never delayed.
const debouncedQuery = refDebounced(rawQuery, 300)

const filters = computed<OrderSearchFilters>(() => ({
  q: debouncedQuery.value,
  status: statusFilter.value === ALL ? null : statusFilter.value,
  fulfillment: fulfillmentFilter.value === ALL ? null : fulfillmentFilter.value,
  placedFrom: placedFrom.value,
  placedTo: placedTo.value,
  page: page.value,
  perPage: perPage.value,
}))

const { data, status: queryStatus } = useOrderSearch(filters)
const rows = computed<CommerceOrder[]>(() => data.value?.orders ?? [])

// A user change to any filter OTHER than page resets to page 1; page navigation itself (via
// TablePagination) never touches these refs, so it never triggers this watcher. Installed AFTER
// hydration above, so the hydrated initial values never fire it.
watch([debouncedQuery, statusFilter, fulfillmentFilter, placedFrom, placedTo], () => {
  page.value = 1
})

// Canonical URL sync: the serializer omits null/default values, and the equality guard below
// skips a `router.replace` whenever the canonical query already matches the current URL — this is
// what keeps an unrelated re-render (or reverting a filter back to its original value) from
// looping on redundant history entries.
watch(filters, (f) => {
  const next = serializeOrderSearchQuery(f)
  const current = route.query as Record<string, string | undefined>
  const currentKeys = Object.keys(current).filter((k) => current[k] !== undefined)
  const nextKeys = Object.keys(next)
  const same = currentKeys.length === nextKeys.length && nextKeys.every((k) => current[k] === next[k])
  if (!same) router.replace({ query: next })
})

const statusFilterItems = [
  { label: 'All statuses', value: ALL },
  ...ORDER_STATUSES.map((s) => ({ label: s, value: s })),
]
const fulfillmentFilterItems = [
  { label: 'All fulfillment', value: ALL },
  ...FULFILLMENT_STATUSES.map((s) => ({ label: s, value: s })),
]

// ── Date range: two plain UInputDate fields (no time component), plus quick presets ───────────

function toDateValue(v: string | null): DateValue | undefined {
  if (!v) return undefined
  try {
    return parseDate(v)
  } catch {
    return undefined
  }
}

const placedFromDate = computed<DateValue | undefined>({
  get: () => toDateValue(placedFrom.value),
  set: (d) => {
    placedFrom.value = d ? d.toString() : null
  },
})
const placedToDate = computed<DateValue | undefined>({
  get: () => toDateValue(placedTo.value),
  set: (d) => {
    placedTo.value = d ? d.toString() : null
  },
})

type DatePreset = 'any' | 'today' | '7d' | '30d' | 'custom'
const datePreset = ref<DatePreset>('any')
const datePresetItems: { label: string; value: DatePreset }[] = [
  { label: 'Any time', value: 'any' },
  { label: 'Today', value: 'today' },
  { label: 'Last 7 days', value: '7d' },
  { label: 'Last 30 days', value: '30d' },
  { label: 'Custom', value: 'custom' },
]

function isoDate(d: Date): string {
  return d.toISOString().slice(0, 10)
}

function applyDatePreset(preset: DatePreset): void {
  datePreset.value = preset
  if (preset === 'any') {
    placedFrom.value = null
    placedTo.value = null
  } else if (preset === 'today') {
    const today = isoDate(new Date())
    placedFrom.value = today
    placedTo.value = today
  } else if (preset === '7d' || preset === '30d') {
    const to = new Date()
    const from = new Date(to)
    from.setDate(from.getDate() - (preset === '7d' ? 6 : 29))
    placedFrom.value = isoDate(from)
    placedTo.value = isoDate(to)
  }
  // 'custom' leaves placedFrom/placedTo exactly as the two date inputs currently hold them.
}

// ── Export ──────────────────────────────────────────────────────────────────────────────────

const exporting = ref(false)
async function exportCsv(): Promise<void> {
  exporting.value = true
  try {
    await downloadOrdersCsv(filters.value)
  } catch (e) {
    if (e instanceof ExportTooLargeError) {
      warning(e.message)
    } else {
      notifyError(e, 'Couldn’t export orders')
    }
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="commerce-orders">
    <template #header>
      <UDashboardNavbar title="Orders">
        <template #right>
          <!-- A plain RouterLink (not UButton's own `to` resolution) mirrors the order detail
               page's identical "Print" link precedent — directly testable against the stubbed
               RouterLink without depending on Nuxt UI's own router integration in jsdom. -->
          <RouterLink
            v-if="canManage"
            to="/commerce/orders/create"
            data-test="orders-create"
            class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-inverted hover:opacity-90"
          >
            <UIcon name="i-lucide-plus" class="size-4" />
            Create order
          </RouterLink>
          <!-- Drafts (Task 15, admin-order-creation cycle 2): the ONE entry point into the
               drafts list — gated can_manage, mirroring "Create order" above (drafts are a
               manage-only concept: every mutation on the resource, including Resume's own
               destination workspace, requires it). The finalized-order list above stays
               draft-blind (server-enforced); this link — the drafts view itself — is the ONLY
               draft-inclusive surface anywhere in the admin SPA. -->
          <RouterLink
            v-if="canManage"
            to="/commerce/orders/drafts"
            data-test="orders-drafts-tab"
            class="inline-flex items-center gap-1.5 rounded-md border border-default px-3 py-1.5 text-sm font-medium text-default hover:bg-elevated"
          >
            <UIcon name="i-lucide-file-clock" class="size-4" />
            Drafts
          </RouterLink>
          <UInput
            v-model="rawQuery"
            icon="i-lucide-search"
            placeholder="Search order # or email…"
            class="w-56"
            data-test="order-search"
          />
          <USelect
            v-model="statusFilter"
            :items="statusFilterItems"
            class="w-40"
            data-test="order-status-filter"
          />
          <USelect
            v-model="fulfillmentFilter"
            :items="fulfillmentFilterItems"
            class="w-40"
            data-test="order-fulfillment-filter"
          />
          <USelect
            v-model="datePreset"
            :items="datePresetItems"
            class="w-36"
            data-test="order-date-preset"
            @update:model-value="applyDatePreset"
          />
          <UInputDate
            v-model="placedFromDate"
            data-test="order-placed-from"
            @update:model-value="() => { datePreset = 'custom' }"
          />
          <UInputDate
            v-model="placedToDate"
            data-test="order-placed-to"
            @update:model-value="() => { datePreset = 'custom' }"
          />
          <UButton
            v-if="canView"
            icon="i-lucide-download"
            color="neutral"
            variant="subtle"
            :loading="exporting"
            data-test="orders-export"
            @click="exportCsv"
          >
            Export CSV
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <OrdersTable :rows="rows" :status="queryStatus" :can-manage="canManage" />

      <TablePagination
        v-if="(data?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="data?.total ?? 0"
        label="orders"
      />
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
