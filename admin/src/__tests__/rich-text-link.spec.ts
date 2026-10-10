// The rich-text link popover applies the link the person meant: what is typed goes through
// normalizeLinkUrl, so `www.example.com` links off the site instead of to a page on it.
import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import RichTextLink from '@/components/RichTextLink.vue'

function fakeEditor() {
  const setLink = vi.fn()
  const chain = {
    focus: () => chain,
    extendMarkRange: () => chain,
    setLink: (attrs: { href: string }) => {
      setLink(attrs)
      return chain
    },
    insertContent: () => chain,
    unsetLink: () => chain,
    run: () => true,
  }
  const editor = {
    isEditable: true,
    isActive: () => false,
    getAttributes: () => ({}),
    state: { selection: { empty: false } },
    chain: () => chain,
  }
  return { editor, setLink }
}

let wrapper: VueWrapper | null = null
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})

describe('RichTextLink', () => {
  it.each([
    ['www.example.com', 'https://www.example.com'],
    ['+233 59 747 8403', 'tel:+233597478403'],
    ['tel:+233597478403', 'tel:+233597478403'],
  ])('applies %s as %s', async (typed, href) => {
    const { editor, setLink } = fakeEditor()
    wrapper = mount(RichTextLink, {
      props: { editor: editor as never },
      attachTo: document.body,
      // A tooltip needs UApp's provider and is not under test (keyed by the component's own name).
      global: { stubs: { Tooltip: { template: '<div><slot /></div>' } } },
    })
    await wrapper.find('button[aria-label="Link"]').trigger('click')
    await flushPromises()
    const input = document.body.querySelector<HTMLInputElement>(
      'input[placeholder="Paste a link…"]',
    )
    expect(input).not.toBeNull()
    input!.value = typed
    input!.dispatchEvent(new Event('input'))
    input!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()
    expect(setLink).toHaveBeenCalledWith({ href })
  })
})
