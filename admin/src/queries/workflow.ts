import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

// The review-workflow admin endpoints (glueful/thallo-workflow pack, /v1/admin/workflow/*).
// Untyped in the OpenAPI spec for now, so this rides on authFetch like queries/seo.ts.

export type WorkflowStateName = 'draft' | 'in_review' | 'approved' | 'changes_requested'

export interface WorkflowTransitionRow {
  from_state: string
  to_state: string
  action: string
  actor_uuid: string | null
  note: string | null
  created_at: string | null
}

export interface WorkflowState {
  state: WorkflowStateName
  submitted_by: string | null
  submitted_at: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  history: WorkflowTransitionRow[]
}

export interface WorkflowQueueItem {
  entry_uuid: string
  locale: string
  submitted_by: string | null
  submitted_at: string | null
  title: string | null
  type_slug: string | null
}

export interface WorkflowQueuePage {
  items: WorkflowQueueItem[]
  total: number
  page: number
  perPage: number
}

export type WorkflowAction = 'submit' | 'approve' | 'request-changes' | 'withdraw'

const base = () => `${runtimeConfig.apiBase}/workflow`

export async function fetchWorkflowState(uuid: string, locale: string): Promise<WorkflowState> {
  const json = await authFetch(`${base()}/entries/${uuid}/${locale}`)
  return (json.data ?? json) as WorkflowState
}

export async function transitionWorkflow(
  uuid: string,
  locale: string,
  action: WorkflowAction,
  note?: string,
): Promise<WorkflowState> {
  const json = await authFetch(`${base()}/entries/${uuid}/${locale}/${action}`, {
    method: 'POST',
    body: JSON.stringify(note ? { note } : {}),
  })
  return (json.data ?? json) as WorkflowState
}

export async function fetchWorkflowQueue(page = 1): Promise<WorkflowQueuePage> {
  const qs = new URLSearchParams({ page: String(page) })
  const json = await authFetch(`${base()}/queue?${qs.toString()}`)
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    items: (d.items as WorkflowQueueItem[] | undefined) ?? [],
    total: Number(d.total ?? 0),
    page: Number(d.page ?? 1),
    perPage: Number(d.perPage ?? 25),
  }
}

export function useWorkflowState(
  uuid: MaybeRefOrGetter<string>,
  locale: MaybeRefOrGetter<string>,
  enabled?: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => qk.workflowState(toValue(uuid), toValue(locale)),
    query: () => fetchWorkflowState(toValue(uuid), toValue(locale)),
    // A disabled pack must not be hit (its routes 404); the caller passes the capability flag.
    enabled: () => (enabled === undefined ? true : toValue(enabled)),
  })
}

export function useWorkflowQueue(enabled?: MaybeRefOrGetter<boolean>) {
  return useQuery({
    key: () => qk.workflowQueue(),
    query: () => fetchWorkflowQueue(),
    enabled: () => (enabled === undefined ? true : toValue(enabled)),
  })
}

export function useWorkflowMutations(uuid: string, locale: string) {
  const cache = useQueryCache()
  const invalidate = () => {
    cache.invalidateQueries({ key: qk.workflowState(uuid, locale) })
    cache.invalidateQueries({ key: qk.workflowQueue() })
  }
  const run = (action: WorkflowAction) =>
    useMutation({
      mutation: (note?: string) => transitionWorkflow(uuid, locale, action, note),
      onSettled: invalidate,
    })
  return {
    submit: run('submit'),
    approve: run('approve'),
    requestChanges: run('request-changes'),
    withdraw: run('withdraw'),
  }
}
