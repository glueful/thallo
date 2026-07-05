import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import type { RegionData } from '@/queries/regions'

const regionsData = ref<RegionData[] | undefined>(undefined)
const saveMock = vi.fn()
const previewMock = vi.fn()

vi.mock('@/queries/regions', () => ({
  useRegions: () => ({ data: regionsData, status: ref('success') }),
  useSaveRegion: () => ({ mutateAsync: saveMock, isLoading: ref(false) }),
  usePreviewRegions: () => ({ mutateAsync: previewMock, isLoading: ref(false) }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))
// The real blocks editor drags in the block-types query + picker machinery —
// out of scope here. The stub surfaces the palette so the "picker receives the
// server-declared allowlist" contract stays asserted.
vi.mock('@/fields/components/BlocksField.vue', () => ({
  default: defineComponent({
    name: 'BlocksField',
    props: { field: { type: Object, required: true }, modelValue: { type: Array, default: () => [] } },
    setup(props) {
      return () =>
        h('div', {
          'data-test': `blocks-stub-${(props.field as { name: string }).name}`,
          'data-palette': ((props.field as { blockTypes?: string[] }).blockTypes ?? []).join(','),
        })
    },
  }),
}))

import RegionsPage from '@/pages/regions/index.vue'

const region = (slug: string, palette: string[], settingsKeys: string[]): RegionData => ({
  slug,
  blocks: [{ id: 'seedblock0001', type: 'logo', data: { size: 'medium' } }],
  settings: slug === 'header' ? { sticky: false, width: 'contained' } : { width: 'contained' },
  palette,
  settings_keys: settingsKeys,
})

describe('regions page (Header & footer)', () => {
  beforeEach(() => {
    regionsData.value = [
      region('header', ['logo', 'navigation', 'button'], ['sticky', 'width']),
      region('footer', ['logo', 'navigation', 'html'], ['width']),
    ]
    saveMock.mockReset()
    saveMock.mockResolvedValue({})
    previewMock.mockReset()
    previewMock.mockResolvedValue('<!doctype html><html><body>PREVIEW</body></html>')
  })

  it('renders both sections with the server-declared palettes', async () => {
    const wrapper = mount(RegionsPage)
    await flushPromises()

    expect(wrapper.find('[data-test="region-header"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="region-footer"]').exists()).toBe(true)
    const stubs = wrapper.findAll('[data-test="blocks-stub-blocks"]')
    expect(stubs).toHaveLength(2)
    expect(stubs[0]!.attributes('data-palette')).toBe('logo,navigation,button')
    expect(stubs[1]!.attributes('data-palette')).toBe('logo,navigation,html')
    wrapper.unmount()
  })

  it('editing a setting marks the region dirty and Save PUTs only that region', async () => {
    const wrapper = mount(RegionsPage, { attachTo: document.body })
    await flushPromises()

    // Flip sticky through the switch.
    await wrapper.find('[data-test="region-header-sticky"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="save-region-header"]').trigger('click')
    await flushPromises()

    expect(saveMock).toHaveBeenCalledTimes(1)
    const call = saveMock.mock.calls[0]![0] as {
      slug: string
      blocks: unknown[]
      settings: Record<string, unknown>
    }
    expect(call.slug).toBe('header')
    expect(call.settings.sticky).toBe(true)
    expect(call.blocks).toHaveLength(1)
    wrapper.unmount()
  })

  it('manual refresh loads a blob document into the sandboxed iframe', async () => {
    const wrapper = mount(RegionsPage, { attachTo: document.body })
    await flushPromises()

    await wrapper.find('[data-test="region-preview-refresh"]').trigger('click')
    await flushPromises()

    const frame = wrapper.find('[data-test="region-preview-frame"]')
    expect(frame.exists()).toBe(true)
    expect(frame.attributes('src')).toMatch(/^blob:/) // P1: blob URL, not srcdoc
    expect(frame.attributes('sandbox')).toBe('allow-same-origin') // scripts stay blocked
    expect(wrapper.find('[data-test="region-preview-stale"]').exists()).toBe(false)
    // The payload carried BOTH regions' current state.
    const call = previewMock.mock.calls[previewMock.mock.calls.length - 1]![0] as {
      regions: Record<string, unknown>
    }
    expect(Object.keys(call.regions).sort()).toEqual(['footer', 'header'])
    wrapper.unmount()
  })

  it('a failed preview keeps the last good document but flags it stale', async () => {
    const wrapper = mount(RegionsPage, { attachTo: document.body })
    await flushPromises()
    await wrapper.find('[data-test="region-preview-refresh"]').trigger('click')
    await flushPromises()
    const goodUrl = wrapper.find('[data-test="region-preview-frame"]').attributes('src')

    previewMock.mockRejectedValueOnce(new Error("'gallery' is not allowed in the header region"))
    await wrapper.find('[data-test="region-preview-refresh"]').trigger('click')
    await flushPromises()

    // Last good document stays…
    expect(wrapper.find('[data-test="region-preview-frame"]').attributes('src')).toBe(goodUrl)
    // …but the staleness is EXPLICIT (P2): banner + error, until a refresh succeeds.
    expect(wrapper.find('[data-test="region-preview-stale"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="region-preview-error"]').text()).toContain('gallery')

    previewMock.mockResolvedValueOnce('<!doctype html><html><body>FIXED</body></html>')
    await wrapper.find('[data-test="region-preview-refresh"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="region-preview-stale"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="region-preview-error"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('a clean region ignores refetches only while clean (dirty survives sync)', async () => {
    const wrapper = mount(RegionsPage, { attachTo: document.body })
    await flushPromises()

    await wrapper.find('[data-test="region-header-sticky"]').trigger('click')
    await flushPromises()

    // Server refetch arrives with different data — the dirty header must keep its edit.
    regionsData.value = [
      { ...region('header', ['logo'], ['sticky', 'width']), settings: { sticky: false, width: 'full' } },
      region('footer', ['logo'], ['width']),
    ]
    await flushPromises()

    await wrapper.find('[data-test="save-region-header"]').trigger('click')
    await flushPromises()
    const call = saveMock.mock.calls[0]![0] as { settings: Record<string, unknown> }
    expect(call.settings.sticky).toBe(true) // the edit, not the refetched value
    wrapper.unmount()
  })
})
