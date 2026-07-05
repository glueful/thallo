import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

// Navigation admin API (glueful/lemma-navigation pack, /v1/admin/navigation/*).
// Untyped in the OpenAPI spec for now, so this rides on authFetch like queries/seo.ts.

export type NavTargetStatus = 'published' | 'unpublished' | 'deleted' | 'missing' | 'routeless'

export interface NavTreeItem {
  uuid?: string
  kind: 'entry' | 'url'
  entry_uuid?: string
  url?: string
  /** Optional Lucide icon name rendered before the label (nav-v2). */
  icon?: string | null
  labels: Record<string, string>
  target_status?: NavTargetStatus
  target_url?: string | null
  /** The localized page title an EMPTY label inherits (nav-entry-items design). */
  target_title?: string | null
  children: NavTreeItem[]
}

export interface NavMenuSummary {
  slug: string
  name: string
  item_count: number
  lock_version: number
}

export interface NavMenuDetail {
  slug: string
  name: string
  locale: string
  lock_version: number
  items: NavTreeItem[]
}

const base = () => `${runtimeConfig.apiBase}/navigation`

export async function fetchMenus(): Promise<NavMenuSummary[]> {
  const json = await authFetch(`${base()}/menus`)
  const d = (json.data ?? json) as { menus?: NavMenuSummary[] }
  return d.menus ?? []
}

export async function fetchMenu(slug: string, locale: string): Promise<NavMenuDetail> {
  const qs = new URLSearchParams({ locale })
  const json = await authFetch(`${base()}/menus/${slug}?${qs.toString()}`)
  return (json.data ?? json) as NavMenuDetail
}

export async function createMenu(slug: string, name: string): Promise<void> {
  await authFetch(`${base()}/menus`, { method: 'POST', body: JSON.stringify({ slug, name }) })
}

export async function renameMenu(slug: string, name: string): Promise<void> {
  await authFetch(`${base()}/menus/${slug}`, { method: 'PUT', body: JSON.stringify({ name }) })
}

export async function deleteMenu(slug: string): Promise<void> {
  await authFetch(`${base()}/menus/${slug}`, { method: 'DELETE' })
}

/** Whole-tree replace, guarded by the lock_version from the editor's GET (409 = stale). */
export async function saveTree(
  slug: string,
  lockVersion: number,
  items: NavTreeItem[],
  locale: string,
): Promise<NavMenuDetail> {
  const qs = new URLSearchParams({ locale })
  const json = await authFetch(`${base()}/menus/${slug}/items?${qs.toString()}`, {
    method: 'PUT',
    body: JSON.stringify({ lock_version: lockVersion, items }),
  })
  return (json.data ?? json) as NavMenuDetail
}

export function useNavMenus(enabled?: MaybeRefOrGetter<boolean>) {
  return useQuery({
    key: () => qk.navMenus(),
    query: fetchMenus,
    enabled: () => (enabled === undefined ? true : toValue(enabled)),
  })
}

export function useNavMenu(
  slug: MaybeRefOrGetter<string>,
  locale: MaybeRefOrGetter<string>,
  enabled?: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    // Locale is part of the cache key: switching locale is a new key → automatic refetch,
    // so target_status badges always reflect the locale on screen.
    key: () => qk.navMenu(toValue(slug), toValue(locale)),
    query: () => fetchMenu(toValue(slug), toValue(locale)),
    enabled: () => {
      const on = enabled === undefined ? true : toValue(enabled)
      return on && toValue(slug) !== ''
    },
  })
}

export function useNavigationMutations() {
  const cache = useQueryCache()
  const invalidate = () => {
    cache.invalidateQueries({ key: ['navigation'] })
  }
  return {
    create: useMutation({
      mutation: (input: { slug: string; name: string }) => createMenu(input.slug, input.name),
      onSettled: invalidate,
    }),
    rename: useMutation({
      mutation: (input: { slug: string; name: string }) => renameMenu(input.slug, input.name),
      onSettled: invalidate,
    }),
    remove: useMutation({
      mutation: (slug: string) => deleteMenu(slug),
      onSettled: invalidate,
    }),
    save: useMutation({
      mutation: (input: { slug: string; lockVersion: number; items: NavTreeItem[]; locale: string }) =>
        saveTree(input.slug, input.lockVersion, input.items, input.locale),
      onSettled: invalidate,
    }),
  }
}
