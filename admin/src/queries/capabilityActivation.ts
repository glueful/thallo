import { ref, toValue, onScopeDispose, type MaybeRefOrGetter } from 'vue'
import { useQueryCache } from '@pinia/colada'
import { authFetch } from '@/api/authFetch'
import type { ApiError } from '@/api/errors'
import { runtimeConfig } from '@/runtime/config'
import { useCapabilitiesStore } from '@/stores/capabilities'

// Turning on a feature with an activation flow (Thallo\Core\Http\Controllers\
// CapabilityActivationController, under /v1/admin/capabilities/{id}/activation). Start runs up to
// the step that needs a freshly booted request; each continue is that request. The server owns
// the steps and their order: the client only keeps continuing while the response says so.

export type ActivationStatus = 'idle' | 'preparing' | 'failed' | 'succeeded' | 'superseded'

export interface ActivationRecord {
  capability: string
  generation: number
  status: ActivationStatus
  steps_done: string[]
  next_step: string | null
  failed_step: string | null
  error: string | null
  remedy: string | null
  workspaces: Record<string, string>
  result: Record<string, unknown>
  actor: string
  updated_at: string
}

export interface ActivationResponse {
  activation: ActivationRecord
  continue: boolean
}

const base = (id: string) =>
  `${runtimeConfig.apiBase}/capabilities/${encodeURIComponent(id)}/activation`

function response(json: Record<string, unknown>): ActivationResponse {
  const data = (json.data ?? json) as Record<string, unknown>
  return { activation: data.activation as ActivationRecord, continue: data.continue === true }
}

export async function startActivation(id: string): Promise<ActivationResponse> {
  return response(await authFetch(base(id), { method: 'POST' }))
}

export async function continueActivation(
  id: string,
  generation: number,
): Promise<ActivationResponse> {
  return response(
    await authFetch(`${base(id)}/continue`, {
      method: 'POST',
      body: JSON.stringify({ generation }),
    }),
  )
}

export async function cancelActivation(id: string, generation: number): Promise<ActivationRecord> {
  const json = await authFetch(base(id), { method: 'DELETE', body: JSON.stringify({ generation }) })
  const data = (json.data ?? json) as Record<string, unknown>
  return data.activation as ActivationRecord
}

/** The 409 reason the server gave (`superseded`, `in_progress`), or null. */
export function conflictReason(e: unknown): string | null {
  const err = e as Partial<ApiError> | null
  if (typeof err !== 'object' || err === null || err.status !== 409) return null
  const body = err.body as { error?: { details?: { reason?: unknown } } } | null
  const reason = body?.error?.details?.reason
  return typeof reason === 'string' ? reason : null
}

/** A feature's summary once it is on, built from what the activation recorded. */
export function activationSummary(label: string, activation: ActivationRecord | null): string {
  const result = activation?.result ?? {}
  const blocks = Number(result.blocks_created ?? 0)
  const grants = (result.grants ?? {}) as Record<string, unknown>
  const granted = Object.values(grants).reduce<number>((sum, n) => sum + Number(n ?? 0), 0)
  const parts = [`Added ${blocks} ${blocks === 1 ? 'block' : 'blocks'}`]
  if (granted > 0)
    parts.push(`granted ${granted} new ${granted === 1 ? 'permission' : 'permissions'}`)
  return `${label} is on. ${parts.join(' and ')}.`
}

const MAX_CONTINUES = 5
const POLL_MS = 1500

/**
 * One feature's activation from this browser: start (or Retry/Continue) and keep continuing while
 * the server asks; poll the management view while it runs; on success refresh what the turn-on
 * changes (capabilities, the current user, extensions) and converge the capability store.
 */
export function useActivationFlow(id: MaybeRefOrGetter<string>) {
  const cache = useQueryCache()
  const caps = useCapabilitiesStore()
  const record = ref<ActivationRecord | null>(null)
  const running = ref(false)
  const superseded = ref(false)
  const error = ref<string | null>(null)
  let poll: ReturnType<typeof setInterval> | null = null

  const refreshManage = () => cache.invalidateQueries({ key: ['capabilities', 'manage'] })
  const stopPolling = () => {
    if (poll !== null) clearInterval(poll)
    poll = null
  }
  onScopeDispose(stopPolling)

  async function converge(): Promise<void> {
    await Promise.all([
      cache.invalidateQueries({ key: ['capabilities'] }),
      cache.invalidateQueries({ key: ['me'] }),
      cache.invalidateQueries({ key: ['extensions'] }),
    ])
    void caps.refreshUntilChanged()
  }

  async function drive(first: () => Promise<ActivationResponse>): Promise<void> {
    running.value = true
    superseded.value = false
    error.value = null
    poll = setInterval(() => void refreshManage(), POLL_MS)
    try {
      let res = await first()
      record.value = res.activation
      for (let n = 0; res.continue && n < MAX_CONTINUES; n++) {
        res = await continueActivation(toValue(id), res.activation.generation)
        record.value = res.activation
      }
      if (record.value?.status === 'succeeded') await converge()
    } catch (e) {
      const reason = conflictReason(e)
      if (reason === 'superseded') {
        superseded.value = true
        record.value = null
      } else if (reason === 'in_progress') {
        error.value = 'Another request is turning this on. It updates here when it finishes.'
      } else {
        error.value = e instanceof Error ? e.message : 'The request failed.'
      }
    } finally {
      stopPolling()
      running.value = false
      void refreshManage()
    }
  }

  const start = () => drive(() => startActivation(toValue(id)))
  const resume = (generation: number) => drive(() => continueActivation(toValue(id), generation))

  async function cancel(generation: number): Promise<void> {
    error.value = null
    try {
      record.value = await cancelActivation(toValue(id), generation)
    } catch (e) {
      if (conflictReason(e) === 'superseded') superseded.value = true
      else error.value = e instanceof Error ? e.message : 'The request failed.'
    }
    await converge()
  }

  return { record, running, superseded, error, start, resume, cancel, converge }
}
