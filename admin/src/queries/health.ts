import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// ── System health (Thallo\Core\Http\Controllers\HealthAdminController, /v1/admin/health) ──────────────────

export interface HealthCheck {
  name: string
  status: string
  message: string
  /** Present only when the framework check carries detail (the config check). */
  issues?: string[]
  warnings?: string[]
  recommendations?: string[]
}

export interface Health {
  status: string
  version: string
  environment: string
  timestamp: string
  php_version: string
  memory_used: number
  memory_peak: number
  memory_limit: string
  disk_free: number
  disk_total: number
  checks: HealthCheck[]
}

export async function fetchHealth(): Promise<Health> {
  const { data, error, response } = await client.GET('/health')
  if (error) throw toApiError(error, response)
  return (data?.data?.health ?? {}) as Health
}

export function useHealth() {
  return useQuery({ key: ['utilities', 'health'], query: fetchHealth })
}

const STATUS_COLOR: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  ok: 'success',
  warning: 'warning',
  error: 'error',
}

export function healthStatusColor(status: string) {
  return STATUS_COLOR[status] ?? 'neutral'
}

export function formatBytes(bytes: number): string {
  if (!bytes || bytes < 1024) return `${bytes || 0} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = bytes / 1024
  let i = 0
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024
    i++
  }
  return `${value.toFixed(value < 10 ? 1 : 0)} ${units[i]}`
}
