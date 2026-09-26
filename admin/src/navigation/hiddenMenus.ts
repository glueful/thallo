import type { NavigationMenuItem } from '@nuxt/ui'

// The menus a role or user has hidden (Users & Access › Menus): the sidebar drops them, and the
// settings screens list every item that can be hidden, by its path. Tidying only — a hidden page
// still opens by its address when the user's permissions allow it.

/** One sidebar item that can be hidden: its path, its label, and the group it sits in. */
export interface MenuEntry {
  group: string | null
  label: string
  path: string
}

/** The Content group's children are the site's content types, one item each. */
export function withContentTypes(
  items: NavigationMenuItem[],
  types: { slug?: string | null; name?: string | null }[],
): NavigationMenuItem[] {
  return items.map((item) =>
    item.label === 'Content'
      ? {
          ...item,
          children: types.map((ct) => ({
            label: ct.name ?? ct.slug ?? 'Untitled',
            icon: 'i-lucide-file-text',
            to: `/content/${ct.slug}`,
          })),
        }
      : item,
  )
}

const pathOf = (item: NavigationMenuItem): string | null =>
  typeof item.to === 'string' ? item.to : null

/** The sidebar without the hidden items; a group left with none of its items goes too. */
export function hideMenus(
  items: NavigationMenuItem[],
  hidden: readonly string[],
): NavigationMenuItem[] {
  if (hidden.length === 0) return items
  const off = new Set(hidden)
  const out: NavigationMenuItem[] = []
  for (const item of items) {
    const path = pathOf(item)
    if (path !== null && off.has(path)) continue
    const children = item.children as NavigationMenuItem[] | undefined
    if (children && children.length > 0) {
      const kept = children.filter((child) => {
        const p = pathOf(child)
        return p === null || !off.has(p)
      })
      if (kept.length === 0) continue
      out.push(kept.length === children.length ? item : { ...item, children: kept })
      continue
    }
    out.push(item)
  }
  return out
}

/** Every item that can be hidden, in sidebar order, a group's items under its label. */
export function menuCatalog(items: NavigationMenuItem[]): MenuEntry[] {
  const out: MenuEntry[] = []
  for (const item of items) {
    const path = pathOf(item)
    if (path !== null) out.push({ group: null, label: String(item.label ?? path), path })
    for (const child of (item.children as NavigationMenuItem[] | undefined) ?? []) {
      const p = pathOf(child)
      if (p !== null)
        out.push({ group: String(item.label ?? ''), label: String(child.label ?? p), path: p })
    }
  }
  return out
}
