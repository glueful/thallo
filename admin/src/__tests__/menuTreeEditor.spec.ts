import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import type { NavTreeItem } from '@/queries/navigation'
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
})
