import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, toValue } from 'vue'

const saveMock = vi.fn().mockResolvedValue(undefined)
const seoData = ref<Record<string, unknown> | undefined>(undefined)
// Capture the `enabled` arg the panel passes into useSeoMeta (proves the query gate is wired).
const h = vi.hoisted(() => ({ enabledArg: null as unknown }))

vi.mock('@/queries/seo', () => ({
  useSeoMeta: (_uuid: unknown, _locale: unknown, enabled?: unknown) => {
    h.enabledArg = enabled
    return { data: seoData }
  },
  useSaveSeoMeta: () => ({ mutateAsync: saveMock, isLoading: ref(false) }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))

import SeoPanel from '@/pages/content/[type]/[uuid]/components/SeoPanel.vue'

const val = (wrapper: ReturnType<typeof mount>, hook: string) =>
  (wrapper.find(`[data-test="${hook}"]`).element as HTMLInputElement).value

// The panel is now a flat tab section (the sidebar tab replaced the old collapsible
// header), so fields mount immediately — just settle the initial render.
const openPanel = async (_wrapper: ReturnType<typeof mount>) => {
  await flushPromises()
}

describe('SeoPanel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    saveMock.mockClear()
    seoData.value = undefined
    h.enabledArg = null
  })

  it('hydrates from the override and saves only the 7 writable fields, empties → null', async () => {
    seoData.value = { title: 'Existing', description: '', robots: 'noindex' }
    const wrapper = mount(SeoPanel, { props: { uuid: 'e-1', locale: 'en', enabled: true } })
    await openPanel(wrapper)
    expect(val(wrapper, 'seo-title')).toBe('Existing')

    await wrapper.find('[data-test="seo-save"]').trigger('click')

    expect(saveMock).toHaveBeenCalledTimes(1)
    expect(saveMock).toHaveBeenCalledWith({
      title: 'Existing',
      description: null,
      og_title: null,
      og_description: null,
      og_image: null,
      twitter_card: null,
      robots: 'noindex',
    })
  })

  it('clearing a previously-set field sends null, not empty string', async () => {
    seoData.value = { title: 'Set', robots: 'index' }
    const wrapper = mount(SeoPanel, { props: { uuid: 'e-1', locale: 'en', enabled: true } })
    await openPanel(wrapper)
    await wrapper.find('[data-test="seo-title"]').setValue('')
    await wrapper.find('[data-test="seo-save"]').trigger('click')
    expect((saveMock.mock.calls[0][0] as { title: unknown }).title).toBeNull()
  })

  it('passes the enabled gate through to useSeoMeta', () => {
    mount(SeoPanel, { props: { uuid: 'e-1', locale: 'en', enabled: false } })
    expect(toValue(h.enabledArg)).toBe(false)
  })

  it('a background refetch does not clobber unsaved edits (hydrate once per key)', async () => {
    seoData.value = { title: 'First', robots: 'index' }
    const wrapper = mount(SeoPanel, { props: { uuid: 'e-1', locale: 'en', enabled: true } })
    await openPanel(wrapper)
    await wrapper.find('[data-test="seo-title"]').setValue('My edit')
    seoData.value = { title: 'Server changed', robots: 'index' } // simulate background refetch
    await wrapper.vm.$nextTick()
    expect(val(wrapper, 'seo-title')).toBe('My edit')
  })
})
