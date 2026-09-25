import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import type { RegionData, RegionSession } from '@/queries/regions'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

// The Header & footer page on the stage (regions stage spec §3, §5.3): the top bar's switch and
// the selection, the Blocks tab's palettes, the Region tab through history, the hidden-region
// notice, and leaving with unsaved edits. The host's own behaviour is region-host.spec's.

const regionsData = ref<RegionData[] | undefined>(undefined)
const q = vi.hoisted(() => ({ mint: vi.fn(), apply: vi.fn(), save: vi.fn() }))
vi.mock('@/queries/regions', () => ({
  useRegions: () => ({ data: regionsData, status: ref('success') }),
  mintRegionSession: q.mint,
  applyRegions: q.apply,
  saveRegions: q.save,
}))
vi.mock('@/queries/entries', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/entries')>()),
  useEntries: () => ({ data: ref({ entries: [], total: 0, current_page: 1, per_page: 100 }) }),
}))

const bt = (slug: string, schema: unknown[] = []): BlockType =>
  ({
    uuid: `bt-${slug}`,
    slug,
    label: slug,
    icon: null,
    category: null,
    description: null,
    active: true,
    schema,
    style_capabilities: ['spacing', 'radius'],
    style_targets: null,
    flags: null,
    starter_content: null,
  }) as unknown as BlockType
const blockTypes = ref<BlockType[]>([
  bt('logo'),
  bt('navigation'),
  bt('links'),
  bt('heading', [{ name: 'text', type: 'string', required: false }]),
  bt('container', [
    { name: 'content', type: 'blocks', block_types: ['heading', 'logo'], required: false },
  ]),
])
vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: blockTypes }),
}))
vi.mock('@/queries/styleSchema', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/styleSchema')>()),
  useStyleSchema: () => ({ data: ref(classEditorSchema()) }),
}))
vi.mock('@/queries/styleClasses', () => ({
  useStyleClasses: () => ({ data: ref({ generation: 0, classes: [] }), refetch: vi.fn() }),
  useStyleClassMutations: () => ({
    create: { mutateAsync: vi.fn(), isLoading: ref(false) },
    deleteUnreferenced: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
}))
vi.mock('@/queries/blockFactory', () => ({
  useBlockFactory: () => ({ make: vi.fn(), instance: vi.fn() }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))

const bridge = vi.hoisted(() => {
  const callbacks: Record<string, (...args: never[]) => void> = {}
  const instance = new Proxy(
    {
      editFlush: async () => undefined,
      stageRefresh: async () => ({ mode: 'patched', epoch: null, revision: null }),
    } as Record<string, unknown>,
    {
      get(target, key: string) {
        if (key in target) return target[key]
        // onX(cb) registers a stage callback; everything else is a no-op command.
        if (key.startsWith('on')) return (cb: (...args: never[]) => void) => (callbacks[key] = cb)
        return () => {}
      },
    },
  )
  return { callbacks, instance }
})
vi.mock('@/composables/useCanvasBridge', () => ({ useCanvasBridge: () => bridge.instance }))

const leaveGuard = vi.hoisted(() => ({ fn: null as null | (() => unknown) }))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  onBeforeRouteLeave: (fn: () => unknown) => (leaveGuard.fn = fn),
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import RegionsPage from '@/pages/regions/index.vue'

const HEADER = [
  { id: 'hdr000000001', type: 'logo', data: {}, settings: {} },
  { id: 'hdr000000002', type: 'container', data: { content: [] }, settings: {} },
]
const FOOTER = [{ id: 'ftr000000001', type: 'links', data: {}, settings: {} }]
const meta = (slug: 'header' | 'footer', palette: string[]): RegionData => ({
  slug,
  blocks: slug === 'header' ? HEADER : FOOTER,
  settings: {},
  palette,
  settings_keys: slug === 'header' ? ['sticky', 'width'] : ['width'],
  style_capabilities: ['spacing'],
  lock_version: slug === 'header' ? 3 : null,
})
function session(overrides: Partial<RegionSession> = {}): RegionSession {
  return {
    token: 'tok1',
    themeUrl: '/_preview/tok1',
    regions: {
      header: { blocks: structuredClone(HEADER), settings: {}, lock_version: 3 },
      footer: { blocks: structuredClone(FOOTER), settings: {}, lock_version: null },
    },
    hidden: { header: false, footer: false },
    ...overrides,
  }
}

function mountPage() {
  return mount(RegionsPage, {
    global: {
      stubs: {
        UDashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
        UDashboardNavbar: { template: '<div><slot /><slot name="right" /></div>' },
        RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
        Tooltip: { template: '<div><slot /></div>' },
        UnsavedChangesModal: true,
      },
    },
    attachTo: document.body,
  })
}
type Page = ReturnType<typeof mountPage>
const pressed = (w: Page, region: string) =>
  w.find(`[data-test="regions-switch-${region}"]`).attributes('aria-pressed')
async function openTab(w: Page, label: string) {
  const tab = w
    .find('[data-test="inspector-tabs"]')
    .findAll('button[role="tab"]')
    .find((b) => b.text() === label)!
  await tab.trigger('mousedown', { button: 0 })
  await tab.trigger('click')
  await flushPromises()
}
const disabled = (w: Page, slug: string) =>
  w.find(`[data-test="palette-card-${slug}"]`).attributes('aria-disabled') === 'true'

beforeAll(async () => {
  await import('@/fields/components/BlocksField.vue')
})

beforeEach(() => {
  setActivePinia(createPinia())
  regionsData.value = [
    meta('header', ['logo', 'navigation', 'container']),
    meta('footer', ['logo', 'links']),
  ]
  q.mint.mockReset().mockImplementation(async () => session())
  q.apply.mockReset().mockImplementation(async () => ({
    epoch: 'e1',
    revision: 1,
    baseline: 0,
    style_generation: 0,
    applied_at: 'now',
    fragments: null,
  }))
  q.save.mockReset()
  leaveGuard.fn = null
  localStorage.clear()
  localStorage.setItem('thallo.canvas.auto_apply', '0')
})

describe('the Header & footer page on the stage', () => {
  it('loads the stage from the session; the switch follows a selection into the footer', async () => {
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="regions-stage"]').attributes('src')).toBe('/_preview/tok1?canvas=1')
    expect(pressed(w, 'header')).toBe('true')

    bridge.callbacks.onBlockSelect!('ftr000000001' as never)
    await flushPromises()
    expect(pressed(w, 'footer')).toBe('true')

    await w.find('[data-test="regions-switch-header"]').trigger('click')
    await flushPromises()
    expect(pressed(w, 'header')).toBe('true')
    w.unmount()
  })

  it('the Blocks tab offers the current region’s palette at its root; a container’s own rules inside it', async () => {
    const w = mountPage()
    await flushPromises()
    await openTab(w, 'Blocks')
    expect(disabled(w, 'navigation')).toBe(false)
    expect(disabled(w, 'links')).toBe(true) // the footer's, not the header's
    expect(disabled(w, 'heading')).toBe(true) // no region takes a heading at its root

    await w.find('[data-test="regions-switch-footer"]').trigger('click')
    await flushPromises()
    expect(disabled(w, 'links')).toBe(false)
    expect(disabled(w, 'navigation')).toBe(true)

    // Into the header's container: its slot takes a heading.
    bridge.callbacks.onSlotAdd!('hdr000000002' as never, 'content' as never)
    await flushPromises()
    expect(disabled(w, 'heading')).toBe(false)
    expect(disabled(w, 'navigation')).toBe(true)
    w.unmount()
  })

  it('the Region tab edits Sticky through history: undo reverts it', async () => {
    const w = mountPage()
    await flushPromises()
    await openTab(w, 'Region')
    const sticky = () => w.find('button[data-test="region-header-sticky"]')
    expect(sticky().attributes('aria-checked')).toBe('false')
    await sticky().trigger('click')
    await flushPromises()
    expect(sticky().attributes('aria-checked')).toBe('true')
    expect(w.find('[data-test="regions-undo"]').attributes('disabled')).toBeUndefined()
    // The stage applies the edit by itself, though the Design view's Auto is off in this browser.
    await vi.waitFor(() => expect(q.apply).toHaveBeenCalledTimes(1), { timeout: 3000 })
    expect(q.apply.mock.calls[0]![1].header.settings).toEqual({ sticky: true })

    await w.find('[data-test="regions-undo"]').trigger('click')
    await flushPromises()
    expect(sticky().attributes('aria-checked')).toBe('false')
    w.unmount()
  })

  it('says so when the picked page hides a region', async () => {
    q.mint.mockImplementation(async () => session({ hidden: { header: true, footer: false } }))
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="regions-hidden-notice"]').text()).toContain('header')
    w.unmount()
  })

  it('with unsaved edits, closing the tab and navigating away both ask; a fresh mount starts from the saved regions', async () => {
    const w = mountPage()
    await flushPromises()
    const unload = () => {
      const event = new Event('beforeunload', { cancelable: true }) as BeforeUnloadEvent
      window.dispatchEvent(event)
      return event
    }
    expect(unload().defaultPrevented).toBe(false)
    expect(leaveGuard.fn!()).toBe(true)

    await openTab(w, 'Region')
    await w.find('button[data-test="region-header-sticky"]').trigger('click')
    await flushPromises()
    const blocked = unload()
    expect(blocked.defaultPrevented).toBe(true)
    expect(leaveGuard.fn!()).toBeInstanceOf(Promise)
    w.unmount()

    // The browser reloaded: a new session from the saved regions, and nothing applied.
    q.mint.mockClear()
    const fresh = mountPage()
    await flushPromises()
    expect(q.mint).toHaveBeenCalledTimes(1)
    expect(q.apply).not.toHaveBeenCalled()
    await openTab(fresh, 'Region')
    expect(fresh.find('button[data-test="region-header-sticky"]').attributes('aria-checked')).toBe(
      'false',
    )
    fresh.unmount()
  })
})
