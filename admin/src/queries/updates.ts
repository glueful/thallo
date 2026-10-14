import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'

// ── Update status (Thallo\Core\Http\Controllers\UpdateStatusController, /v1/admin/update-status) ──
//
// The update notice: what this install runs, the newest published glueful/thallo-core it may
// move to (from the daily Packagist check), and the release notes. Any signed-in admin reads the
// status; preflight and start require system.config (the owner). The update itself never runs in
// this browser or in PHP-FPM — the update runner, scheduled by the owner as the code owner, does it.

export interface UpdateStatus {
  current: string | null
  latest: string | null
  available: boolean
  development: boolean
  enabled: boolean
  checkedAt: string | null
  notesUrl: string
  /** When the last check could not reach Packagist; null once a check completes. */
  failedAt: string | null
}

export const UPGRADE_COMMAND =
  'composer update glueful/thallo-core -W && php glueful thallo:provision && php glueful queue:restart'

export async function fetchUpdateStatus(): Promise<UpdateStatus> {
  const { data, error, response } = await client.GET('/update-status')
  if (error) throw toApiError(error, response)
  return (data?.data?.update ?? {}) as UpdateStatus
}

const UPDATE_STATUS_KEY = ['utilities', 'update-status']

export function useUpdateStatus() {
  return useQuery({
    key: UPDATE_STATUS_KEY,
    query: fetchUpdateStatus,
    // The server checks once a day; an hour of staleness costs nothing here.
    staleTime: 60 * 60 * 1000,
  })
}

/** Health's "Check now": asks Packagist without waiting for the daily check (system.access). */
export async function checkForUpdates(): Promise<UpdateStatus> {
  const { data, error, response } = await client.POST('/update-status/check')
  if (error) throw toApiError(error, response)
  return (data?.data?.update ?? {}) as UpdateStatus
}

/** The checked status replaces the cached one, so every notice (Home, sidebar, nav badge) follows. */
export function useCheckForUpdates() {
  const cache = useQueryCache()
  return useMutation({
    mutation: checkForUpdates,
    onSuccess: (status) => cache.setQueryData(UPDATE_STATUS_KEY, status),
  })
}

// ── Updating from the admin (AdminUpdateController, /v1/admin/updates) ────────────────────────────

export interface UpdatePreflight {
  ready: boolean
  current: string | null
  target: string | null
  reasons: string[]
}

/** Where the started update's one-time token waits for the /_update page (same tab, same origin). */
export const UPDATE_TOKEN_KEY = 'thallo.update.token'

/** One sentence per preflight reason (spec §4), each saying what to do about it. */
export const REASON_TEXT: Record<string, string> = {
  disabled: 'Updating from the admin is turned off (THALLO_ADMIN_UPDATES).',
  runner_missing:
    'The update runner has not checked in. Add its cron line as the user that owns the code.',
  outside_constraint:
    "This release is outside what your site's composer.json allows. Read the upgrade notes and update from the terminal.",
  not_writable: 'The update runner cannot write the code or the update folder.',
  web_can_write_code:
    'PHP-FPM can write the code. Give the code to the deploy user so only the runner can change it.',
  composer_missing: 'Composer is missing for the update runner, or older than 2.2.',
  composer_config_unsupported:
    "Your site's composer.json uses a setup the updater does not support. Update from the terminal.",
  backup_unavailable: 'pg_dump or pg_restore is missing, so the update cannot take its backup.',
  low_disk: 'There is not enough free disk for the update, its backup and the rollback copy.',
  guard_missing:
    'The update guard is not installed or not included by public/index.php and glueful. Run php glueful thallo:provision.',
  web_verify_unreachable:
    'The update runner cannot reach PHP-FPM to verify a release. Check THALLO_UPDATE_VERIFY_URL.',
  opcache_preload:
    'Your server preloads PHP code, which needs a PHP-FPM restart. Update from the terminal.',
  opcache_frozen: 'Your server caches PHP code until PHP-FPM reloads. Update from the terminal.',
  persistent_runtime:
    'This site runs on a persistent PHP runtime, which keeps old code. Update from the terminal.',
  in_progress: 'An update is already in progress.',
  recovery_required: 'A previous update needs attention. Open /_update to recover it.',
  unguarded_processes:
    'Processes started before the update guard are still running. Restart them, then run php bin/thallo-updater confirm-restart --yes.',
  insecure_layout:
    'The update folders are not laid out safely (owner, modes, or a writable parent folder). Run php glueful thallo:provision as the code owner.',
  target_changed: 'A newer version appeared since this dialog opened. Close it and check again.',
  restart_required:
    'Restart PHP-FPM and every worker, then confirm with php bin/thallo-updater confirm-restart --yes.',
  no_update: 'There is no newer version to install.',
  launch_unavailable:
    'PHP-FPM cannot start the update runner (bin/thallo-updater missing, or proc_open disabled). Run php glueful thallo:provision, or update from the terminal.',
}

/** Null on 403: updating from here is not this user's to run, so the notice stays as it was. */
export async function fetchUpdatePreflight(): Promise<UpdatePreflight | null> {
  const { data, error, response } = await client.GET('/updates/preflight')
  if (response?.status === 403) return null
  if (error) throw toApiError(error, response)
  return ((data as { data?: { preflight?: UpdatePreflight } } | undefined)?.data?.preflight ??
    null) as UpdatePreflight | null
}

export function useUpdatePreflight(enabled: MaybeRefOrGetter<boolean>) {
  return useQuery({
    key: ['utilities', 'update-preflight'],
    query: fetchUpdatePreflight,
    enabled: () => toValue(enabled),
    staleTime: 60_000,
  })
}

/** The reasons a refused start (409) carries, or [] for any other error. */
export function refusalReasons(err: unknown): string[] {
  if (!(err instanceof ApiError)) return []
  const reasons = (err.body as { error?: { details?: { reasons?: unknown } } } | null)?.error
    ?.details?.reasons
  return Array.isArray(reasons) ? reasons.map(String) : []
}

/** Files the request for `target`, the version the dialog showed (409 `target_changed` if it moved on). */
export async function startUpdate(target: string): Promise<{ id: string; token: string }> {
  const { data, error, response } = await client.POST('/updates', { body: { target } })
  if (error) throw toApiError(error, response)
  return (data as { data: { update: { id: string; token: string } } }).data.update
}
