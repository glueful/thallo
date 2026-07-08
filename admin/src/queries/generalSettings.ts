import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// ── General settings (App\Http\Controllers\GeneralSettingsController, /v1/admin/settings/general) ──
//
// Instance settings persisted as env keys in .env. Calls go through the typed `client`; the
// `{ success, message, data: { settings } }` envelope is unwrapped to the flat settings object.

export interface GeneralSettings {
  site_name: string
  site_preview_url: string
  default_locale: string
  default_per_page: number
  max_per_page: number
  cache_ttl: number
  scheduler_enabled: boolean
  webhooks_enabled: boolean
  /** Entry uuid rendered at `/`; '' = no homepage (standalone index). Send '' to clear. */
  homepage_entry: string
  /** Asset uuid of the site logo; '' = unset (site name renders instead). */
  site_logo: string
  /** Dark-scheme logo variant uuid; '' = no override (the main logo renders). */
  site_logo_dark: string
  /** Favicon blob uuid; '' = unset (no link tag renders). */
  site_favicon: string
  /** Live theme name (effective); saving '' clears the override to the env default. */
  theme: string
  /** Theme color config (theme-color-config spec §2): accent + neutral Tailwind families. */
  theme_accent: string
  theme_neutral: string
  /** Admin SPA base URL for the preview bar's Edit/Design links. */
  admin_url: string
  /** Content types with public listings/archives ([] = none). */
  listing_types: string[]
}

export type GeneralSettingsInput = Partial<GeneralSettings>

const qk = () => ['settings', 'general'] as const

export async function fetchGeneralSettings(): Promise<GeneralSettings> {
  const { data, error, response } = await client.GET('/settings/general')
  if (error) throw toApiError(error, response)
  return (data?.data?.settings ?? {}) as GeneralSettings
}

export function useGeneralSettings() {
  return useQuery({ key: qk(), query: fetchGeneralSettings })
}

export async function updateGeneralSettings(input: GeneralSettingsInput): Promise<GeneralSettings> {
  const { data, error, response } = await client.PUT('/settings/general', { body: input })
  if (error) throw toApiError(error, response)
  return (data?.data?.settings ?? {}) as GeneralSettings
}

export function useGeneralSettingsMutations() {
  const cache = useQueryCache()
  const save = useMutation({
    mutation: updateGeneralSettings,
    onSettled: () => cache.invalidateQueries({ key: qk() }),
  })
  return { save }
}
