import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import type { MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// Platform Payments settings (platform-payments-settings spec §2, Task 7): the app-owned,
// platform-only Settings → Payments surface — `GET/PUT /v1/admin/settings/payments`
// (`thallo.settings.payments.{show,update}`, authority `tenancy.manage`). This replaces the
// retired `thallo.commerce`-owned `/v1/admin/commerce/payments` (`admin/src/queries/commerceSettings.ts`,
// Task 6 in the backend, Task 7 here on the SPA) — the response contract is preserved
// BYTE-SHAPE-IDENTICAL: `mode`, an ordered `gateways` list (`id`, `enabled{value,default,overridden}`,
// `secret_key{set,source}`, `webhook_secret{set,source}`, `default`, `webhook_url`), and
// `default_gateway{value,default,overridden}`. Secrets are WRITE-ONLY: the server stores them
// encrypted and only ever reports `{set, source}` booleans back; the PUT body carries a new value,
// `null` to clear (row deleted — config/env fallback shows through), or omits the field to leave
// the stored value untouched.

/** A secret field's reportable state — the server never sends key material. */
export interface SecretFieldState {
  set: boolean
  source: 'settings' | 'env' | null
}

/** A generic tri-state settings flag: the effective value, the fallback default, and whether the
 * effective value has been explicitly overridden away from that default. */
export interface TriStateFlag {
  value: boolean
  default: boolean
  overridden: boolean
}

export interface PlatformPaymentsGatewayRow {
  id: string
  enabled: TriStateFlag
  secret_key: SecretFieldState
  webhook_secret: SecretFieldState
  default: boolean
  /** Absolute URL for the gateway dashboard's webhook field; null when no origin is resolvable. */
  webhook_url: string | null
}

export interface PlatformPaymentsSettings {
  /** `manual` = no gateway extension configures gateways (operator mark-paid is the flow). */
  mode: 'gateway' | 'manual'
  default_gateway: { value: string | null; default: string; overridden: boolean }
  gateways: PlatformPaymentsGatewayRow[]
}

/** PUT body — every field optional; secrets: value = replace, null = clear, absent = keep. */
export interface PlatformPaymentsSettingsSave {
  default_gateway?: string | null
  gateways?: Record<
    string,
    { enabled?: boolean; secret_key?: string | null; webhook_secret?: string | null }
  >
}

function normalizeSecretState(raw: unknown): SecretFieldState {
  const data = (raw ?? {}) as { set?: unknown; source?: unknown }
  return {
    set: data.set === true,
    source: data.source === 'settings' || data.source === 'env' ? data.source : null,
  }
}

function normalizePlatformPaymentsSettings(raw: unknown): PlatformPaymentsSettings {
  const data = (raw ?? {}) as {
    mode?: unknown
    default_gateway?: unknown
    gateways?: unknown
  }
  const dg = (data.default_gateway ?? {}) as { value?: unknown; default?: unknown; overridden?: unknown }
  const gateways = Array.isArray(data.gateways) ? data.gateways : []
  return {
    mode: data.mode === 'gateway' ? 'gateway' : 'manual',
    default_gateway: {
      value: typeof dg.value === 'string' ? dg.value : null,
      default: typeof dg.default === 'string' ? dg.default : '',
      overridden: dg.overridden === true,
    },
    gateways: gateways.map((g) => {
      const row = (g ?? {}) as Record<string, unknown>
      const enabled = (row.enabled ?? {}) as { value?: unknown; default?: unknown; overridden?: unknown }
      return {
        id: String(row.id ?? ''),
        enabled: {
          value: enabled.value === true,
          default: enabled.default === true,
          overridden: enabled.overridden === true,
        },
        secret_key: normalizeSecretState(row.secret_key),
        webhook_secret: normalizeSecretState(row.webhook_secret),
        default: row.default === true,
        webhook_url: typeof row.webhook_url === 'string' ? row.webhook_url : null,
      }
    }),
  }
}

const qk = () => ['settings', 'payments'] as const

export async function fetchPlatformPaymentsSettings(): Promise<PlatformPaymentsSettings> {
  const { data, error, response } = await client.GET('/settings/payments')
  if (error) throw toApiError(error, response)
  return normalizePlatformPaymentsSettings((data as { data?: unknown } | undefined)?.data)
}

export async function savePlatformPaymentsSettings(
  body: PlatformPaymentsSettingsSave,
): Promise<PlatformPaymentsSettings> {
  const { data, error, response } = await client.PUT('/settings/payments', { body: body as never })
  if (error) throw toApiError(error, response)
  return normalizePlatformPaymentsSettings((data as { data?: unknown } | undefined)?.data)
}

export function usePlatformPaymentsSettings(enabled: MaybeRefOrGetter<boolean> = true) {
  return useQuery({ key: qk(), query: fetchPlatformPaymentsSettings, enabled })
}

export function useSavePlatformPaymentsSettings() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (body: PlatformPaymentsSettingsSave) => savePlatformPaymentsSettings(body),
    onSettled: async () => {
      await cache.invalidateQueries({ key: qk() })
    },
  })
}
