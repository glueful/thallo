import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// ── Webhooks (framework WebhookController, mounted at /v1/admin/webhooks) ─────────────────────────
//
// Calls go through the typed `client` (openapi-fetch). The framework controller wraps lists in a
// `{ subscriptions|deliveries, pagination }` envelope and returns single subscriptions/deliveries
// flat in `data`. Responses come back inside `{ success, message, data }`; nested-DTO list rows
// render as `unknown[]`, so they're cast to the row interfaces below. The three small action
// endpoints (rotate-secret/test/retry) are documented description-only, so their bodies are cast.

export type WebhookDeliveryStatus = 'pending' | 'delivered' | 'failed' | 'retrying'

/** The subscribable Thallo content events (frozen taxonomy) plus wildcard patterns. */
export const WEBHOOK_EVENTS = [
  'entry.created',
  'entry.updated',
  'entry.published',
  'entry.unpublished',
  'entry.deleted',
  'model.created',
  'model.updated',
  'model.deleted',
  'asset.attached',
  'asset.detached',
] as const

/** Wildcard patterns the backend matches (exact | `*` | `prefix.*`). Offered alongside the events. */
export const WEBHOOK_EVENT_PATTERNS = ['*', 'entry.*', 'model.*', 'asset.*'] as const

export interface WebhookSubscription {
  uuid: string
  url: string
  events: string[]
  is_active: boolean
  metadata: Record<string, unknown> | null
  created_at: string | null
  updated_at: string | null
}

export interface WebhookSubscriptionPage {
  subscriptions: WebhookSubscription[]
  total: number
  current_page: number
  per_page: number
  total_pages: number
}

export interface CreateSubscriptionInput {
  url: string
  events: string[]
}

export interface UpdateSubscriptionInput {
  url?: string
  events?: string[]
  is_active?: boolean
}

export interface SubscriptionStats {
  uuid: string
  period_days: number
  total_deliveries: number
  delivered: number
  failed: number
  pending: number
  success_rate: number
}

export interface WebhookDelivery {
  uuid: string
  event: string
  status: WebhookDeliveryStatus
  attempts: number
  response_code: number | null
  delivered_at: string | null
  next_retry_at: string | null
  created_at: string | null
}

export interface WebhookDeliveryDetail extends WebhookDelivery {
  payload: Record<string, unknown> | null
  response_body: string | null
}

export interface WebhookDeliveryPage {
  deliveries: WebhookDelivery[]
  total: number
  current_page: number
  per_page: number
  total_pages: number
}

export interface TestResult {
  status_code: number | null
  response: string | null
}

interface Pagination {
  current_page?: number
  per_page?: number
  total?: number
  total_pages?: number
}

function pageMeta(pagination: Pagination | undefined, fallbackPage: number, fallbackPer: number) {
  const p = pagination ?? {}
  return {
    total: Number(p.total ?? 0),
    current_page: Number(p.current_page ?? fallbackPage),
    per_page: Number(p.per_page ?? fallbackPer),
    total_pages: Number(p.total_pages ?? 1),
  }
}

// ── Subscriptions ────────────────────────────────────────────────────────────────────────────────

export async function fetchSubscriptions(params: {
  page: number
  perPage: number
  activeOnly?: boolean
}): Promise<WebhookSubscriptionPage> {
  const { data, error, response } = await client.GET('/webhooks/subscriptions', {
    params: {
      query: {
        page: params.page,
        per_page: params.perPage,
        ...(params.activeOnly ? { active: true } : {}),
      },
    },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data
  return {
    subscriptions: (d?.subscriptions ?? []) as WebhookSubscription[],
    ...pageMeta(d?.pagination as Pagination | undefined, params.page, params.perPage),
  }
}

export function useSubscriptionList(
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  activeOnly: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => ['webhooks', 'subscriptions', toValue(page), toValue(activeOnly) ? 'active' : 'all'],
    query: () =>
      fetchSubscriptions({
        page: toValue(page),
        perPage: toValue(perPage),
        activeOnly: toValue(activeOnly),
      }),
  })
}

export async function fetchSubscription(uuid: string): Promise<WebhookSubscription> {
  const { data, error, response } = await client.GET('/webhooks/subscriptions/{id}', {
    params: { path: { id: uuid } },
  })
  if (error) throw toApiError(error, response)
  return (data?.data ?? {}) as WebhookSubscription
}

export function useSubscription(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['webhooks', 'subscriptions', 'detail', toValue(uuid) ?? ''],
    query: () => fetchSubscription(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

export async function fetchSubscriptionStats(uuid: string, days = 30): Promise<SubscriptionStats> {
  const { data, error, response } = await client.GET('/webhooks/subscriptions/{id}/stats', {
    params: { path: { id: uuid }, query: { days } },
  })
  if (error) throw toApiError(error, response)
  return (data?.data ?? {}) as SubscriptionStats
}

export function useSubscriptionStats(uuid: MaybeRefOrGetter<string | undefined>, days = 30) {
  return useQuery({
    key: () => ['webhooks', 'subscriptions', 'stats', toValue(uuid) ?? '', days],
    query: () => fetchSubscriptionStats(toValue(uuid) as string, days),
    enabled: () => !!toValue(uuid),
  })
}

export async function createSubscription(
  input: CreateSubscriptionInput,
): Promise<{ subscription: WebhookSubscription; secret: string }> {
  const { data, error, response } = await client.POST('/webhooks/subscriptions', {
    body: { url: input.url, events: input.events },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data as (WebhookSubscription & { secret?: string }) | undefined
  return { subscription: (d ?? {}) as WebhookSubscription, secret: String(d?.secret ?? '') }
}

export async function updateSubscription(
  uuid: string,
  input: UpdateSubscriptionInput,
): Promise<WebhookSubscription> {
  const { data, error, response } = await client.PATCH('/webhooks/subscriptions/{id}', {
    params: { path: { id: uuid } },
    body: { url: input.url, events: input.events, is_active: input.is_active },
  })
  if (error) throw toApiError(error, response)
  return (data?.data ?? {}) as WebhookSubscription
}

export async function deleteSubscription(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/webhooks/subscriptions/{id}', {
    params: { path: { id: uuid } },
  })
  if (error) throw toApiError(error, response)
}

export async function rotateSubscriptionSecret(uuid: string): Promise<string> {
  const { data, error, response } = await client.POST(
    '/webhooks/subscriptions/{id}/rotate-secret',
    {
      params: { path: { id: uuid } },
    },
  )
  if (error) throw toApiError(error, response)
  const d = (data as { data?: { secret?: string } } | undefined)?.data
  return String(d?.secret ?? '')
}

export async function testSubscription(uuid: string): Promise<TestResult> {
  const { data, error, response } = await client.POST('/webhooks/subscriptions/{id}/test', {
    params: { path: { id: uuid } },
  })
  if (error) throw toApiError(error, response)
  const d = (data as { data?: { status_code?: number; response?: string } } | undefined)?.data
  return {
    status_code: d?.status_code != null ? Number(d.status_code) : null,
    response: d?.response != null ? String(d.response) : null,
  }
}

// ── Deliveries ───────────────────────────────────────────────────────────────────────────────────

export async function fetchDeliveries(params: {
  subscription?: string
  status?: string
  page: number
  perPage: number
}): Promise<WebhookDeliveryPage> {
  const { data, error, response } = await client.GET('/webhooks/deliveries', {
    params: {
      query: {
        page: params.page,
        per_page: params.perPage,
        ...(params.subscription ? { subscription: params.subscription } : {}),
        ...(params.status ? { status: params.status } : {}),
      },
    },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data
  return {
    deliveries: (d?.deliveries ?? []) as WebhookDelivery[],
    ...pageMeta(d?.pagination as Pagination | undefined, params.page, params.perPage),
  }
}

export function useDeliveryList(
  subscription: MaybeRefOrGetter<string | undefined>,
  status: MaybeRefOrGetter<string | undefined>,
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
) {
  return useQuery({
    key: () => [
      'webhooks',
      'deliveries',
      toValue(subscription) ?? '',
      toValue(status) ?? '',
      toValue(page),
    ],
    query: () =>
      fetchDeliveries({
        subscription: toValue(subscription) || undefined,
        status: toValue(status) || undefined,
        page: toValue(page),
        perPage: toValue(perPage),
      }),
    enabled: () => !!toValue(subscription),
  })
}

export async function fetchDelivery(uuid: string): Promise<WebhookDeliveryDetail> {
  const { data, error, response } = await client.GET('/webhooks/deliveries/{id}', {
    params: { path: { id: uuid } },
  })
  if (error) throw toApiError(error, response)
  return (data?.data ?? {}) as WebhookDeliveryDetail
}

export function useDelivery(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['webhooks', 'deliveries', 'detail', toValue(uuid) ?? ''],
    query: () => fetchDelivery(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

export async function retryDelivery(uuid: string): Promise<void> {
  const { error, response } = await client.POST('/webhooks/deliveries/{id}/retry', {
    params: { path: { id: uuid } },
  })
  if (error) throw toApiError(error, response)
}

export function useWebhookMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: ['webhooks'] })

  const create = useMutation({ mutation: createSubscription, onSettled: invalidate })
  const update = useMutation({
    mutation: (vars: { uuid: string; input: UpdateSubscriptionInput }) =>
      updateSubscription(vars.uuid, vars.input),
    onSettled: invalidate,
  })
  const remove = useMutation({ mutation: deleteSubscription, onSettled: invalidate })
  const rotateSecret = useMutation({ mutation: rotateSubscriptionSecret })
  const test = useMutation({ mutation: testSubscription })
  const retry = useMutation({ mutation: retryDelivery, onSettled: invalidate })

  return { create, update, remove, rotateSecret, test, retry }
}

const DELIVERY_STATUS_META: Record<
  WebhookDeliveryStatus,
  { label: string; color: 'success' | 'warning' | 'error' | 'neutral' }
> = {
  delivered: { label: 'Delivered', color: 'success' },
  pending: { label: 'Pending', color: 'neutral' },
  retrying: { label: 'Retrying', color: 'warning' },
  failed: { label: 'Failed', color: 'error' },
}

export function deliveryStatusMeta(status: WebhookDeliveryStatus) {
  return DELIVERY_STATUS_META[status] ?? DELIVERY_STATUS_META.pending
}

export function formatDateTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(String(v).replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
