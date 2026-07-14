import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

export type RangePreset = 7 | 30 | 90
export interface DateRange {
  from: string
  to: string
}

export interface SeriesPoint {
  day: string
  count: number
}
export interface SummaryResponse {
  from: string
  to: string
  totals: Record<string, number>
  active_users: number
  // True when the read was bound to a single workspace. Platform-level auth rollups (logins,
  // active users) carry no tenant and fall out of a scoped read, so the page drops those panels
  // when this is true. Absent/false on unscoped (single-store or tenancy-off) reads.
  scoped?: boolean
}
export interface BreakdownItem {
  subject: string
  count: number
}

const base = () => `${runtimeConfig.apiBase}/analytics`

/** Inclusive N-day window ending today: from = to − (days − 1). Both YYYY-MM-DD (UTC). */
export function rangeFor(days: RangePreset, today: Date = new Date()): DateRange {
  const to = today
  const from = new Date(to)
  from.setUTCDate(from.getUTCDate() - (days - 1))
  const fmt = (d: Date) => d.toISOString().slice(0, 10)
  return { from: fmt(from), to: fmt(to) }
}

export async function fetchSeries(metric: string, from: string, to: string): Promise<SeriesPoint[]> {
  const qs = new URLSearchParams({ metric, from, to })
  const json = await authFetch(`${base()}/series?${qs.toString()}`)
  const data = (json.data ?? json) as { series?: SeriesPoint[] }
  return Array.isArray(data.series) ? data.series : []
}

export async function fetchSummary(from: string, to: string): Promise<SummaryResponse> {
  const qs = new URLSearchParams({ from, to })
  const json = await authFetch(`${base()}/summary?${qs.toString()}`)
  return (json.data ?? json) as SummaryResponse
}

export async function fetchBreakdown(
  event: string,
  from: string,
  to: string,
  limit = 10,
): Promise<BreakdownItem[]> {
  const qs = new URLSearchParams({ event, from, to, limit: String(limit) })
  const json = await authFetch(`${base()}/breakdown?${qs.toString()}`)
  const data = (json.data ?? json) as { breakdown?: BreakdownItem[] }
  return Array.isArray(data.breakdown) ? data.breakdown : []
}

export function useAnalyticsSummary(
  range: MaybeRefOrGetter<DateRange>,
  enabled?: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => qk.analyticsSummary(toValue(range).from, toValue(range).to),
    query: () => {
      const r = toValue(range)
      return fetchSummary(r.from, r.to)
    },
    // When `enabled` resolves false the query never runs — the Home strip passes the
    // `thallo.analytics` capability flag so a disabled pack never hits the (404'd) backend route.
    enabled: () => (enabled === undefined ? true : toValue(enabled)),
  })
}

export function useAnalyticsSeries(metric: string, range: MaybeRefOrGetter<DateRange>) {
  return useQuery({
    key: () => qk.analyticsSeries(metric, toValue(range).from, toValue(range).to),
    query: () => {
      const r = toValue(range)
      return fetchSeries(metric, r.from, r.to)
    },
  })
}

export function useAnalyticsBreakdown(
  event: MaybeRefOrGetter<string>,
  range: MaybeRefOrGetter<DateRange>,
) {
  return useQuery({
    key: () => qk.analyticsBreakdown(toValue(event), toValue(range).from, toValue(range).to),
    query: () => {
      const r = toValue(range)
      return fetchBreakdown(toValue(event), r.from, r.to)
    },
  })
}
