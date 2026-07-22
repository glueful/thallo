import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

// Task 18 (admin-commerce-area plan, slice 3): Overview (reports), READ-ONLY —
// `AdminReportController` / `Glueful\Extensions\Commerce\Http\Admin\AdminReportController`. Four
// independent report endpoints: `sales` (money/order aggregates over a date window), `products`
// (ranked variant sales, house-paginated), `customers` (new-vs-returning COUNTS over the same
// window shape as `sales` — no money, no per-customer identity at all), and `stock`
// (point-in-time, no window). The Overview page's "top customers by spend" table does NOT come
// from this file's `customers` fetcher — it reuses `useCommerceCustomers({sort: 'total_spent', ...})`
// from `commerceCustomers.ts` (Task 17), the real per-customer money surface. No mutation endpoint
// exists on any of these four, so — like Customers — there is no `can_manage` gating anywhere here.

export const REPORT_GROUPS = ['day', 'week', 'month'] as const
export type ReportGroup = (typeof REPORT_GROUPS)[number]

export const PRODUCTS_REPORT_SORT_FIELDS = ['quantity', 'revenue'] as const
export type ProductsReportSort = (typeof PRODUCTS_REPORT_SORT_FIELDS)[number]

export const STOCK_REPORT_STATUSES = ['out_of_stock', 'low_stock'] as const
export type StockReportStatus = (typeof STOCK_REPORT_STATUSES)[number]

/** The shared `[from, to)` window echoed back verbatim on both `sales` and `customers`
 * (`ReportWindow::fromDate()`/`toDate()`/`group()`). */
export interface ReportWindowEcho {
  from: string
  to: string
  group: ReportGroup
}

/** One `ReportRollup::fold()`-derived bucket of the sales series. Normalized for contract
 * completeness — the Overview dashboard's cards render the `summary` only (no charts, per the
 * task brief's own YAGNI note), never this list. */
export interface SalesReportSeriesPoint {
  bucket: string
  gross_minor: number
  refunds_minor: number
  net_minor: number
  orders_count: number
  aov_minor: number
}

export interface SalesReportSummary {
  gross_minor: number
  refunds_minor: number
  net_minor: number
  orders_count: number
  aov_minor: number
  pending_orders: number
  discount_minor: number
  shipping_minor: number
  tax_minor: number
}

export interface SalesReport {
  currency: string
  window: ReportWindowEcho
  summary: SalesReportSummary
  series: SalesReportSeriesPoint[]
}

export interface SalesReportFilters {
  from?: string
  to?: string
  group?: ReportGroup
}

/** One ranked variant row (`ProductSalesReportRepository::paginate()`) — `variant_uuid` only, no
 * `product_uuid`. Deliberately not linked to a product detail page: no route can be derived from
 * a variant id alone in this admin SPA. */
export interface ProductsReportItem {
  variant_uuid: string
  sku: string
  product_name: string
  quantity: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  revenue_minor: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  attributed_refunded_minor: number
  attributed_refunded_quantity: number
}

export interface ProductsReportPage {
  items: ProductsReportItem[]
  total: number
  current_page: number
  per_page: number
}

export interface ProductsReportFilters {
  from?: string
  to?: string
  sort?: ProductsReportSort
  page?: number
  perPage?: number
}

/** One `bucketCounts()` series point — new-vs-returning customer COUNTS for one bucket, never
 * money, never a per-customer row. */
export interface CustomersReportSeriesPoint {
  bucket: string
  new_customers: number
  returning_customers: number
}

export interface CustomersReportSummary {
  new_customers: number
  returning_customers: number
  total_customers: number
}

export interface CustomersReport {
  window: ReportWindowEcho
  summary: CustomersReportSummary
  series: CustomersReportSeriesPoint[]
}

export interface CustomersReportFilters {
  from?: string
  to?: string
  group?: ReportGroup
}

/** A `commerce_stock` row at/below the effective threshold (`StockReportRepository::paginate()`).
 * `threshold` is the one resolved, effective value the CONTROLLER stamped onto this specific
 * item — the query override when the caller passed one, else whatever the server's configured
 * default was AT THE TIME OF THIS RESPONSE. The Overview page deliberately does NOT trust this
 * per-item field for its own low-stock badge severity (see `LowStockList.vue`'s docblock) — it
 * flags against the live `useCommerceMeta().low_stock_threshold` instead, so the badge never goes
 * stale relative to the rest of the page (which already renders against that same live value via
 * `useMoney`). */
export interface StockReportItem {
  variant_uuid: string
  sku: string
  product_name: string
  quantity: number
  status: StockReportStatus
  threshold: number
}

export interface StockReportPage {
  items: StockReportItem[]
  total: number
  current_page: number
  per_page: number
}

export interface StockReportFilters {
  status?: StockReportStatus
  threshold?: number
  page?: number
  perPage?: number
}

// The admin envelopes are doc-only in the OpenAPI schema (see commerceCustomers.ts's identical
// note), so normalize the raw JSON into the stricter hand-written shapes above at the boundary.

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

function num(value: unknown): number {
  return typeof value === 'number' ? value : 0
}

function str(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback
}

function normalizeGroup(value: unknown): ReportGroup {
  return value === 'week' || value === 'month' ? value : 'day'
}

function normalizeWindow(raw: Record<string, unknown>): ReportWindowEcho {
  const window = asRecord(raw.window)
  return {
    from: str(window.from),
    to: str(window.to),
    group: normalizeGroup(window.group),
  }
}

function normalizeSalesSeriesPoint(raw: Record<string, unknown>): SalesReportSeriesPoint {
  return {
    bucket: str(raw.bucket),
    gross_minor: num(raw.gross_minor),
    refunds_minor: num(raw.refunds_minor),
    net_minor: num(raw.net_minor),
    orders_count: num(raw.orders_count),
    aov_minor: num(raw.aov_minor),
  }
}

function normalizeSalesSummary(raw: Record<string, unknown>): SalesReportSummary {
  return {
    gross_minor: num(raw.gross_minor),
    refunds_minor: num(raw.refunds_minor),
    net_minor: num(raw.net_minor),
    orders_count: num(raw.orders_count),
    aov_minor: num(raw.aov_minor),
    pending_orders: num(raw.pending_orders),
    discount_minor: num(raw.discount_minor),
    shipping_minor: num(raw.shipping_minor),
    tax_minor: num(raw.tax_minor),
  }
}

function normalizeProductsItem(raw: Record<string, unknown>): ProductsReportItem {
  return {
    variant_uuid: str(raw.variant_uuid),
    sku: str(raw.sku),
    product_name: str(raw.product_name),
    quantity: num(raw.quantity),
    revenue_minor: num(raw.revenue_minor),
    attributed_refunded_minor: num(raw.attributed_refunded_minor),
    attributed_refunded_quantity: num(raw.attributed_refunded_quantity),
  }
}

function normalizeCustomersSeriesPoint(raw: Record<string, unknown>): CustomersReportSeriesPoint {
  return {
    bucket: str(raw.bucket),
    new_customers: num(raw.new_customers),
    returning_customers: num(raw.returning_customers),
  }
}

function normalizeCustomersSummary(raw: Record<string, unknown>): CustomersReportSummary {
  return {
    new_customers: num(raw.new_customers),
    returning_customers: num(raw.returning_customers),
    total_customers: num(raw.total_customers),
  }
}

function normalizeStockStatus(value: unknown): StockReportStatus {
  return value === 'out_of_stock' ? 'out_of_stock' : 'low_stock'
}

function normalizeStockItem(raw: Record<string, unknown>): StockReportItem {
  return {
    variant_uuid: str(raw.variant_uuid),
    sku: str(raw.sku),
    product_name: str(raw.product_name),
    quantity: num(raw.quantity),
    status: normalizeStockStatus(raw.status),
    threshold: num(raw.threshold),
  }
}

// ── Fetchers ─────────────────────────────────────────────────────────────────

/** `GET /commerce/reports/sales` — `ReportWindowQuery`'s exact param set is `{from, to, group}`. */
export async function fetchCommerceReportSales(filters: SalesReportFilters = {}): Promise<SalesReport> {
  const { data, error, response } = await client.GET('/commerce/reports/sales', {
    params: { query: { from: filters.from, to: filters.to, group: filters.group } },
  })
  if (error) throw toApiError(error, response)
  const raw = asRecord((data as { data?: unknown } | undefined)?.data)
  return {
    currency: str(raw.currency, 'USD'),
    window: normalizeWindow(raw),
    summary: normalizeSalesSummary(asRecord(raw.summary)),
    series: Array.isArray(raw.series) ? raw.series.map((p) => normalizeSalesSeriesPoint(asRecord(p))) : [],
  }
}

/** `GET /commerce/reports/products` — `ProductsReportQuery`'s exact param set is
 * `{from, to, sort, page, per_page}`. No `group`: this is a ranked list, not a bucketed series. */
export async function fetchCommerceReportProducts(
  filters: ProductsReportFilters = {},
): Promise<ProductsReportPage> {
  const { data, error, response } = await client.GET('/commerce/reports/products', {
    params: {
      query: {
        from: filters.from,
        to: filters.to,
        sort: filters.sort,
        page: filters.page,
        per_page: filters.perPage,
      },
    },
  })
  if (error) throw toApiError(error, response)
  const body = data as
    | { data?: unknown[]; current_page?: number; per_page?: number; total?: number }
    | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return {
    items: rows.map((r) => normalizeProductsItem(asRecord(r))),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

/** `GET /commerce/reports/customers` — `ReportWindowQuery`'s exact param set is
 * `{from, to, group}`. New-vs-returning acquisition rollup ONLY — no money, no per-customer
 * identity (see the file docblock for where "top customers by spend" actually comes from). */
export async function fetchCommerceReportCustomers(
  filters: CustomersReportFilters = {},
): Promise<CustomersReport> {
  const { data, error, response } = await client.GET('/commerce/reports/customers', {
    params: { query: { from: filters.from, to: filters.to, group: filters.group } },
  })
  if (error) throw toApiError(error, response)
  const raw = asRecord((data as { data?: unknown } | undefined)?.data)
  return {
    window: normalizeWindow(raw),
    summary: normalizeCustomersSummary(asRecord(raw.summary)),
    series: Array.isArray(raw.series)
      ? raw.series.map((p) => normalizeCustomersSeriesPoint(asRecord(p)))
      : [],
  }
}

/** `GET /commerce/reports/stock` — `StockReportQuery`'s exact param set is
 * `{status, threshold, page, per_page}`. Point-in-time: no `from`/`to` at all. */
export async function fetchCommerceReportStock(filters: StockReportFilters = {}): Promise<StockReportPage> {
  const { data, error, response } = await client.GET('/commerce/reports/stock', {
    params: {
      query: {
        status: filters.status,
        threshold: filters.threshold,
        page: filters.page,
        per_page: filters.perPage,
      },
    },
  })
  if (error) throw toApiError(error, response)
  const body = data as
    | { data?: unknown[]; current_page?: number; per_page?: number; total?: number }
    | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return {
    items: rows.map((r) => normalizeStockItem(asRecord(r))),
    total: body?.total ?? 0,
    current_page: body?.current_page ?? filters.page ?? 1,
    per_page: body?.per_page ?? filters.perPage ?? 24,
  }
}

// ── Query wrappers ───────────────────────────────────────────────────────────

export function useCommerceReportSales(filters: MaybeRefOrGetter<SalesReportFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return qk.commerceReportSales(f.from ?? '', f.to ?? '', f.group ?? 'day')
    },
    query: () => fetchCommerceReportSales(toValue(filters)),
  })
}

export function useCommerceReportProducts(filters: MaybeRefOrGetter<ProductsReportFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return qk.commerceReportProducts(
        f.from ?? '',
        f.to ?? '',
        f.sort ?? 'revenue',
        f.page ?? 1,
        f.perPage ?? 24,
      )
    },
    query: () => fetchCommerceReportProducts(toValue(filters)),
  })
}

export function useCommerceReportCustomers(filters: MaybeRefOrGetter<CustomersReportFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return qk.commerceReportCustomers(f.from ?? '', f.to ?? '', f.group ?? 'day')
    },
    query: () => fetchCommerceReportCustomers(toValue(filters)),
  })
}

export function useCommerceReportStock(filters: MaybeRefOrGetter<StockReportFilters>) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return qk.commerceReportStock(f.status ?? '', f.page ?? 1, f.perPage ?? 24)
    },
    query: () => fetchCommerceReportStock(toValue(filters)),
  })
}
