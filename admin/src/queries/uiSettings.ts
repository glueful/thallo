import { useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// A role's or a user's admin menus and landing page (Users & Access). Tidying only: a hidden
// menu's page stays open to whoever's permissions allow it.

export type UiSubject = 'roles' | 'users'
export type MenuState = 'hidden' | 'shown'

export interface UiSettings {
  /** Sidebar item path => hidden, or for a user also shown (beating their roles). */
  menus: Record<string, MenuState>
  /** Where signing in lands; null for Home. */
  landing: string | null
}

function read(raw: unknown): UiSettings {
  const d = (raw ?? {}) as { menus?: unknown; landing?: unknown }
  const menus: Record<string, MenuState> = {}
  if (d.menus && typeof d.menus === 'object' && !Array.isArray(d.menus)) {
    for (const [path, state] of Object.entries(d.menus as Record<string, unknown>)) {
      if (state === 'hidden' || state === 'shown') menus[path] = state
    }
  }
  return { menus, landing: typeof d.landing === 'string' && d.landing !== '' ? d.landing : null }
}

export async function fetchUiSettings(subject: UiSubject, uuid: string): Promise<UiSettings> {
  const { data, error, response } =
    subject === 'roles'
      ? await client.GET('/ui-settings/roles/{uuid}', { params: { path: { uuid } } })
      : await client.GET('/ui-settings/users/{uuid}', { params: { path: { uuid } } })
  if (error) throw toApiError(error, response)
  return read((data as unknown as { data?: unknown })?.data)
}

/** Save a role's or user's settings. Your own sidebar follows at once when they reach you. */
export function useSaveUiSettings() {
  const cache = useQueryCache()
  return async function save(
    subject: UiSubject,
    uuid: string,
    settings: UiSettings,
  ): Promise<UiSettings> {
    const body = settings as never
    const { data, error, response } =
      subject === 'roles'
        ? await client.PUT('/ui-settings/roles/{uuid}', { params: { path: { uuid } }, body })
        : await client.PUT('/ui-settings/users/{uuid}', { params: { path: { uuid } }, body })
    if (error) throw toApiError(error, response)
    await cache.invalidateQueries({ key: ['me'] })
    return read((data as unknown as { data?: unknown })?.data)
  }
}
