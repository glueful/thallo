import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

// The per-entry, per-locale SEO override row. Every field is optional/nullable: an unset override
// is the empty object {}. `robots` is one of 'index' | 'noindex' | 'noindex,nofollow'.
export interface SeoMeta {
  title?: string | null
  description?: string | null
  og_title?: string | null
  og_description?: string | null
  og_image?: string | null
  twitter_card?: string | null
  robots?: string | null
}

// The admin SEO endpoint is under-typed in the OpenAPI spec (query/body/response are `never`), so it
// rides on authFetch (same pattern as queries/analytics.ts) rather than the typed client. If the
// spec is corrected later, this module can move to the typed client without touching SeoPanel.
const url = (uuid: string, locale: string) =>
  `${runtimeConfig.apiBase}/seo/meta/${uuid}?${new URLSearchParams({ locale }).toString()}`

export async function fetchSeoMeta(uuid: string, locale: string): Promise<SeoMeta> {
  const json = await authFetch(url(uuid, locale))
  // The backend wraps the row in `data`; an unset override returns an empty object.
  return ((json.data ?? json) ?? {}) as SeoMeta
}

export function useSeoMeta(
  uuid: MaybeRefOrGetter<string>,
  locale: MaybeRefOrGetter<string>,
  enabled?: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => qk.seoMeta(toValue(uuid), toValue(locale)),
    query: () => fetchSeoMeta(toValue(uuid), toValue(locale)),
    // When `enabled` resolves false the query never runs — a disabled pack must not hit the 404'd
    // route. The panel's parent passes its `thallo.seo` capability flag through here.
    enabled: () => (enabled === undefined ? true : toValue(enabled)),
  })
}

export async function saveSeoMeta(uuid: string, locale: string, payload: SeoMeta): Promise<void> {
  await authFetch(url(uuid, locale), { method: 'PUT', body: JSON.stringify(payload) })
}

export function useSaveSeoMeta(uuid: string, locale: string) {
  const cache = useQueryCache()
  return useMutation({
    mutation: (payload: SeoMeta) => saveSeoMeta(uuid, locale, payload),
    onSettled() {
      cache.invalidateQueries({ key: qk.seoMeta(uuid, locale) })
    },
  })
}
