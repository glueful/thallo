import { describe, it, expect } from 'vitest'
import type { NavigationMenuItem } from '@nuxt/ui'
import { hideMenus, menuCatalog, withContentTypes } from '@/navigation/hiddenMenus'

// The menus a role or user has hidden (Users & Access): the sidebar drops them, and the settings
// screens list every item that can be hidden. Tidying only — a hidden page still opens by address.

const NAV: NavigationMenuItem[] = [
  { label: 'Home', icon: 'i-lucide-house', to: '/' },
  { label: 'Content', icon: 'i-lucide-files', children: [] },
  { label: 'Media', icon: 'i-lucide-image', to: '/media' },
  {
    label: 'Users & Access',
    icon: 'i-lucide-users',
    children: [
      { label: 'Users', to: '/users' },
      { label: 'Roles & Permissions', to: '/roles-permissions' },
    ],
  },
]
const TYPES = [
  { slug: 'page', name: 'Pages' },
  { slug: 'post', name: 'Posts' },
]

describe('hidden menus', () => {
  it('the Content group lists the live content types', () => {
    const content = withContentTypes(NAV, TYPES)[1]!
    expect(content.children!.map((c) => [c.label, c.to])).toEqual([
      ['Pages', '/content/page'],
      ['Posts', '/content/post'],
    ])
  })

  it('drops a hidden item, and a group whose items are all hidden', () => {
    const nav = withContentTypes(NAV, TYPES)
    const shown = hideMenus(nav, ['/media', '/content/post', '/users', '/roles-permissions'])
    expect(shown.map((i) => i.label)).toEqual(['Home', 'Content'])
    expect(shown[1]!.children!.map((c) => c.label)).toEqual(['Pages'])
    // Nothing hidden leaves the sidebar as it was.
    expect(hideMenus(nav, [])).toEqual(nav)
  })

  it('a group with no items of its own is not dropped for being empty', () => {
    expect(hideMenus(NAV, ['/media']).map((i) => i.label)).toContain('Content')
  })

  it('the catalog lists every item that can be hidden, under its group', () => {
    const catalog = menuCatalog(withContentTypes(NAV, TYPES))
    expect(catalog).toEqual([
      { group: null, label: 'Home', path: '/' },
      { group: 'Content', label: 'Pages', path: '/content/page' },
      { group: 'Content', label: 'Posts', path: '/content/post' },
      { group: null, label: 'Media', path: '/media' },
      { group: 'Users & Access', label: 'Users', path: '/users' },
      { group: 'Users & Access', label: 'Roles & Permissions', path: '/roles-permissions' },
    ])
  })
})
