import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'

// Audit trail via the glueful/audit extension (GET /audit-logs, GET /audit-logs/{uuid}).
// Append-only and read-only; needs the `audit.view` permission. Responses aren't in the generated
// OpenAPI spec, so the shape is pinned by hand here — it mirrors the extension's AuditLogData DTO.
export interface AuditChange {
  from?: unknown
  to?: unknown
}

export interface AuditContext {
  ip?: string
  user_agent?: string
  request_id?: string
  event_id?: string
  [k: string]: unknown
}

export interface AuditLogRow {
  uuid: string
  occurred_at: string
  actor_uuid?: string | null
  actor_label?: string | null
  action: string
  category: string
  target_type?: string | null
  target_uuid?: string | null
  target_label?: string | null
  changes?: Record<string, AuditChange> | null
  context?: AuditContext | null
  created_at?: string | null
}

export interface AuditLogFilters {
  category?: string
  action?: string
  actor?: string
  target_type?: string
  from?: string
  to?: string
}

export interface AuditLogsPage {
  rows: AuditLogRow[]
  total: number
  current_page: number
  per_page: number
}

// The API serves the `changes` / `context` JSON columns as raw strings (PostgreSQL `json` comes back
// stringified and the read repo doesn't decode it), so parse them here — defensively, since a fixed
// server would send objects. Without this, the structured fields stay blank and metadata prints escaped.
function parseJsonField<T>(value: unknown): T | null {
  if (value == null) return null
  if (typeof value === 'object') return value as T
  if (typeof value === 'string') {
    if (value === '') return null
    try {
      return JSON.parse(value) as T
    } catch {
      return null
    }
  }
  return null
}

function normalizeRow(raw: AuditLogRow): AuditLogRow {
  return {
    ...raw,
    changes: parseJsonField<Record<string, AuditChange>>(raw.changes),
    context: parseJsonField<AuditContext>(raw.context),
  }
}

export async function fetchAuditLogs(params: {
  page: number
  perPage: number
  filters?: AuditLogFilters
}): Promise<AuditLogsPage> {
  const q = new URLSearchParams({ page: String(params.page), per_page: String(params.perPage) })
  for (const [k, v] of Object.entries(params.filters ?? {})) {
    if (v) q.set(k, v)
  }
  // Flat framework pagination envelope: rows in `data`, meta at the root (Response::paginated()).
  const json = await authFetch(`/audit-logs?${q.toString()}`)
  const rows = Array.isArray(json.data) ? (json.data as AuditLogRow[]).map(normalizeRow) : []
  return {
    rows,
    total: Number(json.total ?? rows.length),
    current_page: Number(json.current_page ?? params.page),
    per_page: Number(json.per_page ?? params.perPage),
  }
}

export function useAuditLogs(
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  filters: MaybeRefOrGetter<AuditLogFilters>,
) {
  return useQuery({
    key: () => ['audit-logs', toValue(page), JSON.stringify(toValue(filters) ?? {})],
    query: () =>
      fetchAuditLogs({ page: toValue(page), perPage: toValue(perPage), filters: toValue(filters) }),
  })
}

/** A single audit row, GET /audit-logs/{uuid} → { audit_log: row }. */
export async function fetchAuditLog(uuid: string): Promise<AuditLogRow> {
  const json = await authFetch(`/audit-logs/${encodeURIComponent(uuid)}`)
  const data = (json.data ?? json) as Record<string, unknown>
  return normalizeRow((data.audit_log ?? data) as AuditLogRow)
}

export function useAuditLog(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['audit-logs', 'detail', toValue(uuid) ?? ''],
    query: () => fetchAuditLog(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

// ── Display helpers ──────────────────────────────────────────────────────────

export type AuditBadgeColor =
  | 'primary'
  | 'secondary'
  | 'success'
  | 'info'
  | 'warning'
  | 'error'
  | 'neutral'

export interface AuditActionMeta {
  label: string
  color: AuditBadgeColor
  icon: string
}

// Known actions emitted by the framework + aegis subscribers. Unknown actions fall back to a
// humanized label with a neutral badge, so new event types render sensibly without a code change.
const ACTION_META: Record<string, AuditActionMeta> = {
  login: { label: 'Login', color: 'success', icon: 'i-lucide-log-in' },
  logout: { label: 'Logout', color: 'neutral', icon: 'i-lucide-log-out' },
  login_failed: { label: 'Login failed', color: 'error', icon: 'i-lucide-shield-alert' },
  created: { label: 'Created', color: 'success', icon: 'i-lucide-plus' },
  updated: { label: 'Updated', color: 'info', icon: 'i-lucide-pencil' },
  deleted: { label: 'Deleted', color: 'error', icon: 'i-lucide-trash-2' },
  role_assigned: { label: 'Role assigned', color: 'success', icon: 'i-lucide-user-check' },
  role_revoked: { label: 'Role revoked', color: 'warning', icon: 'i-lucide-user-minus' },
  permission_assigned: { label: 'Permission assigned', color: 'success', icon: 'i-lucide-key' },
  permission_revoked: { label: 'Permission revoked', color: 'warning', icon: 'i-lucide-key' },
  role_permission_assigned: {
    label: 'Permission granted',
    color: 'success',
    icon: 'i-lucide-key-round',
  },
  role_permission_revoked: {
    label: 'Permission removed',
    color: 'warning',
    icon: 'i-lucide-key-round',
  },
  rate_limit_exceeded: { label: 'Rate limit exceeded', color: 'warning', icon: 'i-lucide-gauge' },
  security_violation: { label: 'Security violation', color: 'error', icon: 'i-lucide-shield-x' },
  // Content lifecycle (Thallo content events)
  published: { label: 'Published', color: 'success', icon: 'i-lucide-globe' },
  unpublished: { label: 'Unpublished', color: 'warning', icon: 'i-lucide-eye-off' },
  attached: { label: 'Attached', color: 'info', icon: 'i-lucide-paperclip' },
  detached: { label: 'Detached', color: 'neutral', icon: 'i-lucide-unlink' },
}

export function auditActionMeta(action: string): AuditActionMeta {
  return (
    ACTION_META[action] ?? {
      label: action.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
      color: 'neutral',
      icon: 'i-lucide-activity',
    }
  )
}

/**
 * Categories the audit subscriber actually emits (for the filter dropdown). There is no
 * `system` bucket — the subscriber's category map never produces one; "system" only ever
 * appears as an actor label (e.g. CLI-originated rows).
 */
export const AUDIT_CATEGORIES = [
  'auth',
  'rbac',
  'user',
  'content',
  'media',
  'security',
  'data',
] as const

/** Known actions, for the action filter dropdown. */
export const AUDIT_ACTIONS = Object.keys(ACTION_META)

export function auditActorName(row: AuditLogRow): string {
  return row.actor_label || (row.actor_uuid ? row.actor_uuid.slice(0, 8) : 'System')
}

export function auditTargetLabel(row: AuditLogRow): string | null {
  if (row.target_label) return row.target_label
  if (row.target_type && row.target_uuid) {
    return `${row.target_type} · ${row.target_uuid.slice(0, 8)}`
  }
  return row.target_type ?? null
}
