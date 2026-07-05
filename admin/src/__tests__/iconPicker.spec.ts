import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

// ~200 fake names: predictable filtering + pagination.
const fakeNames = Array.from({ length: 200 }, (_, i) => `glyph-${String(i).padStart(3, '0')}`)
const iconsData = ref({ icons: fakeNames, svgs: {} as Record<string, string> })

vi.mock('@/queries/icons', () => ({
  useIcons: () => ({ data: iconsData, status: ref('success') }),
}))

import IconPickerModal from '@/fields/components/IconPickerModal.vue'
import IconField from '@/fields/components/IconField.vue'

describe('IconPickerModal', () => {
  beforeEach(() => {
    iconsData.value = { icons: fakeNames, svgs: {} }
    document.body.innerHTML = ''
  })

  it('paginates 80 per page with ONE page of tiles in the DOM', async () => {
    const wrapper = mount(IconPickerModal, {
      props: { set: 'lucide' as const, open: true },
      attachTo: document.body,
    })
    await flushPromises()

    const tiles = document.querySelectorAll('[data-test^="icon-tile-"]')
    expect(tiles.length).toBe(80) // never the whole catalog (review pin)
    expect(document.querySelector('[data-test="icon-picker-range"]')!.textContent).toContain(
      'Showing 1–80 of 200',
    )
    expect(document.querySelector('[data-test="icon-tile-glyph-000"]')).toBeTruthy()
    expect(document.querySelector('[data-test="icon-tile-glyph-099"]')).toBeNull() // page 2
    wrapper.unmount()
  })

  it('search filters, resets to page 1, and shows the empty state on no matches', async () => {
    const wrapper = mount(IconPickerModal, {
      props: { set: 'lucide' as const, open: true },
      attachTo: document.body,
    })
    await flushPromises()

    const search = document.querySelector('[data-test="icon-picker-search"] input, input')!
    ;(search as HTMLInputElement).value = 'glyph-19'
    search.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()

    const tiles = document.querySelectorAll('[data-test^="icon-tile-"]')
    expect(tiles.length).toBe(10) // glyph-190..glyph-199
    expect(document.querySelector('[data-test="icon-picker-range"]')!.textContent).toContain(
      'Showing 1–10 of 10',
    )
    ;(search as HTMLInputElement).value = 'zzz-none'
    search.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()
    expect(document.querySelector('[data-test="icon-picker-empty"]')).toBeTruthy()
    wrapper.unmount()
  })

  it('selecting a tile emits the BARE name and closes', async () => {
    const wrapper = mount(IconPickerModal, {
      props: { set: 'lucide' as const, open: true },
      attachTo: document.body,
    })
    await flushPromises()

    document
      .querySelector('[data-test="icon-tile-glyph-003"]')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    const emitted = wrapper.emitted('select')!
    expect(emitted[emitted.length - 1]).toEqual(['glyph-003'])
    expect(wrapper.emitted('update:open')!.some((e) => e[0] === false)).toBe(true)
    wrapper.unmount()
  })
})

describe('IconField', () => {
  beforeEach(() => {
    iconsData.value = { icons: fakeNames, svgs: {} }
    document.body.innerHTML = ''
  })

  it('brand-icon fields display bare names but store brand:-prefixed values (P2)', async () => {
    const wrapper = mount(IconField, {
      props: {
        field: { name: 'icon', type: 'string' as const, format: 'brand-icon' as const },
        modelValue: 'brand:github',
      },
      attachTo: document.body,
    })
    await flushPromises()

    // Displays the bare name…
    expect(wrapper.find('[data-test="icon-field-name"]').text()).toBe('github')

    // …and a selection emits the PREFIXED value.
    await wrapper.find('[data-test="icon-field-choose"]').trigger('click')
    await flushPromises()
    document
      .querySelector('[data-test="icon-tile-glyph-001"]')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    const emitted = wrapper.emitted('update:modelValue')!
    expect(emitted[emitted.length - 1]).toEqual(['brand:glyph-001'])
    wrapper.unmount()
  })

  it('lucide fields store bare names; Clear emits undefined', async () => {
    const wrapper = mount(IconField, {
      props: {
        field: { name: 'icon', type: 'string' as const, format: 'icon' as const },
        modelValue: 'star',
      },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-test="icon-field-clear"]').trigger('click')
    await flushPromises()
    const emitted = wrapper.emitted('update:modelValue')!
    expect(emitted[emitted.length - 1]).toEqual([undefined])
    wrapper.unmount()
  })
})
