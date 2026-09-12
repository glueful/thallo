import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// ── Update status (Thallo\Core\Http\Controllers\UpdateStatusController, /v1/admin/update-status) ──
//
// The update notice: what this install runs, the newest published glueful/thallo-core it may
// move to (from the daily Packagist check), and the release notes. Operator-only (system.access);
// a 403 for other users simply yields no notice. Never an updater — the upgrade is a server-side
// command run by the deploy user.

export interface UpdateStatus {
  current: string | null
  latest: string | null
  available: boolean
  development: boolean
  enabled: boolean
  checkedAt: string | null
  notesUrl: string
}

export const UPGRADE_COMMAND = 'composer update && php glueful thallo:provision'

export async function fetchUpdateStatus(): Promise<UpdateStatus> {
  const { data, error, response } = await client.GET('/update-status')
  if (error) throw toApiError(error, response)
  return (data?.data?.update ?? {}) as UpdateStatus
}

export function useUpdateStatus() {
  return useQuery({
    key: ['utilities', 'update-status'],
    query: fetchUpdateStatus,
    // The server checks once a day; an hour of staleness costs nothing here.
    staleTime: 60 * 60 * 1000,
  })
}
