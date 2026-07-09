import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { NavTreeItem } from '@/queries/navigation'

// The row hosts the icon picker modal, whose query needs Pinia — mock it.
vi.mock('@/queries/icons', () => ({
  useIcons: () => ({ data: ref({ icons: ['star', 'external-link'], svgs: {} }), status: ref('success') }),
}))

import MenuTreeEditor from '@/pages/navigation/components/MenuTreeEditor.vue'

const url = (label: string, children: NavTreeItem[] = []): NavTreeItem => ({
  uuid: `u-${label}`,
  kind: 'url',
  url: `/${label}`,
  labels: { en: label },
  children,
})

const mountEditor = (items: NavTreeItem[]) =>
  mount(MenuTreeEditor, { props: { items, locale: 'en' } })

describe('MenuTreeEditor', () => {
  it('renders one row per item with the active-locale label', () => {
    const wrapper = mountEditor([url('a'), url('b')])
    const labels = wrapper.findAll('[data-test="tree-item-label"] input, input[data-test="tree-item-label"]')
    expect(labels).toHaveLength(2)
    expect((labels[0]!.element as HTMLInputElement).value).toBe('a')
  })

  it('shows an existing per-item description and writes edits back, coercing [] → object', async () => {
    const withDesc: NavTreeItem = {
      uuid: 'u-d',
      kind: 'url',
      url: '/d',
      labels: { en: 'D' },
      descriptions: { en: 'existing' },
      children: [],
    }
    const wrapper = mountEditor([withDesc])
    const input = wrapper.find(
      '[data-test="tree-item-description"] input, input[data-test="tree-item-description"]',
    )
    expect((input.element as HTMLInputElement).value).toBe('existing')

    // The server sends an empty description map as `[]` (PHP). Setting a key must
    // coerce it to an object, else JSON.stringify would drop the string key on save.
    const arrayDesc = {
      uuid: 'u-e',
      kind: 'url',
      url: '/e',
      labels: { en: 'E' },
      descriptions: [] as unknown as Record<string, string>,
      children: [],
    } as NavTreeItem
    const w2 = mountEditor([arrayDesc])
    await w2
      .find('[data-test="tree-item-description"] input, input[data-test="tree-item-description"]')
      .setValue('typed')
    expect(Array.isArray(arrayDesc.descriptions)).toBe(false)
    expect(arrayDesc.descriptions).toEqual({ en: 'typed' })
  })

  it('entry rows inherit the page title as the label placeholder and show the path', () => {
    // nav-entry-items design: empty label = follow the page title; the SPA
    // never guesses — target_title comes from the admin tree payload.
    const wrapper = mountEditor([
      {
        uuid: 'e-1',
        kind: 'entry',
        entry_uuid: 'entry0000001',
        labels: {},
        target_status: 'published',
        target_url: '/pages/about',
        target_title: 'About us',
        children: [],
      },
    ])
    const label = wrapper.find('[data-test="tree-item-label"] input, input[data-test="tree-item-label"]')
    expect(label.attributes('placeholder')).toBe('About us')
    expect(wrapper.find('[data-test="tree-item-path"]').text()).toBe('/pages/about')
    expect(wrapper.find('[data-test="tree-item-status"]').exists()).toBe(true)
  })

  it('down button reorders siblings in place and emits changed', async () => {
    const items = [url('a'), url('b')]
    const wrapper = mountEditor(items)
    await wrapper.findAll('[data-test="tree-item-down"]')[0]!.trigger('click')

    expect(items.map((i) => i.labels.en)).toEqual(['b', 'a'])
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('indent nests the item under its previous sibling', async () => {
    const items = [url('a'), url('b')]
    const wrapper = mountEditor(items)
    await wrapper.findAll('[data-test="tree-item-indent"]')[1]!.trigger('click')

    expect(items).toHaveLength(1)
    expect(items[0]!.children.map((c) => c.labels.en)).toEqual(['b'])
  })

  it('outdent moves a child up next to its former parent', async () => {
    const items = [url('a', [url('child')]), url('b')]
    const wrapper = mountEditor(items)
    await wrapper.find('[data-test="tree-item-outdent"]').trigger('click')

    expect(items.map((i) => i.labels.en)).toEqual(['a', 'child', 'b'])
    expect(items[0]!.children).toHaveLength(0)
  })

  it('label edits land in the active locale key only', async () => {
    const items: NavTreeItem[] = [
      { uuid: 'u-1', kind: 'url', url: '/x', labels: { fr: 'À propos' }, children: [] },
    ]
    const wrapper = mountEditor(items)
    await wrapper
      .find('[data-test="tree-item-label"] input, input[data-test="tree-item-label"]')
      .setValue('About')

    expect(items[0]!.labels).toEqual({ fr: 'À propos', en: 'About' })
  })

  it('badges entry items with their target status', () => {
    const items: NavTreeItem[] = [
      {
        uuid: 'u-1',
        kind: 'entry',
        entry_uuid: 'e-1',
        labels: { en: 'Post' },
        target_status: 'routeless',
        target_url: null,
        children: [],
      },
    ]
    const wrapper = mountEditor(items)
    expect(wrapper.find('[data-test="tree-item-status"]').text()).toBe('needs a route')
  })

  it('remove deletes the row', async () => {
    const items = [url('a'), url('b')]
    const wrapper = mountEditor(items)
    await wrapper.findAll('[data-test="tree-item-remove"]')[0]!.trigger('click')
    expect(items.map((i) => i.labels.en)).toEqual(['b'])
  })

  it('renders a drag handle on every row', () => {
    const wrapper = mountEditor([url('a'), url('b')])
    expect(wrapper.findAll('[data-test="tree-item-drag"]')).toHaveLength(2)
  })

  it('always renders a droppable children container, even for a childless item (nesting-by-drag target)', () => {
    const wrapper = mountEditor([url('a')])
    // Two sortable lists: the root, PLUS the (empty) child level under item 'a' — proving a
    // childless item still offers a drop target. If the child container were guarded by
    // `children.length > 0`, this would be 1.
    expect(wrapper.findAll('[data-test="tree-children"]').length).toBeGreaterThanOrEqual(2)
  })

  it('onMove rejects dropping a node into its own subtree', () => {
    const wrapper = mountEditor([url('a')])
    const vm = wrapper.vm as unknown as {
      onMove: (e: { dragged: HTMLElement; to: HTMLElement }) => boolean
    }
    const outer = document.createElement('div')
    const inner = document.createElement('div')
    outer.appendChild(inner)
    expect(vm.onMove({ dragged: outer, to: inner })).toBe(false) // into own subtree → reject
    expect(vm.onMove({ dragged: inner, to: outer })).toBe(true) // outward → allow
  })

  it('committing a reordered list mutates items in place and emits changed', () => {
    // The drag-commit path is the `list` computed setter (what vue-draggable-plus writes to).
    // Assigning a reordered array must splice the SAME items array in place and bubble changed.
    const items = [url('a'), url('b')]
    const wrapper = mount(MenuTreeEditor, { props: { items, locale: 'en' } })
    ;(wrapper.vm as unknown as { list: NavTreeItem[] }).list = [items[1]!, items[0]!]
    expect(items.map((i) => i.url)).toEqual(['/b', '/a'])
    expect(wrapper.emitted('changed')).toBeTruthy()
  })
})
