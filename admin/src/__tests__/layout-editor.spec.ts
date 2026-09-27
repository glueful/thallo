import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { ApiError } from '@/api/errors'
import type { LayoutSession } from '@/queries/layouts'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

// The layout editor (type layouts spec §6.2): the Blocks tab with the Fields first, a required block
// that cannot be deleted, Save with its reach, the conflict and removed states, the placeholder
// notice, Remove from the menu, and leaving with unsaved edits. The host's own behaviour is
// layout-host.spec's.

const q = vi.hoisted(() => ({
  mint: vi.fn(),
  apply: vi.fn(),
  save: vi.fn(),
  remove: vi.fn(),
  samples: vi.fn(),
}))
vi.mock('@/queries/layouts', () => ({
  mintLayoutSession: q.mint,
  applyLayout: q.apply,
  saveLayout: q.save,
  removeLayout: q.remove,
  fetchLayoutSamples: q.samples,
}))

const bt = (slug: string, category: string | null, layoutOnly = false): BlockType =>
  ({
    uuid: `bt-${slug}`,
    slug,
    label: slug,
    icon: null,
    category,
    description: null,
    active: true,
    schema: slug === 'entry_content' ? [{ name: 'field', type: 'string', required: false }] : [],
    style_capabilities: ['spacing'],
    style_targets: null,
    flags: layoutOnly ? { layout_only: true } : null,
    starter_content: null,
  }) as unknown as BlockType
const blockTypes = ref<BlockType[]>([
  bt('heading', 'Content'),
  bt('container', 'Layout'),
  bt('entry_title', 'Fields', true),
  bt('entry_content', 'Fields', true),
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
vi.mock('@/queries/blockFactory', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockFactory')>()),
  useBlockFactory: () => ({ make: vi.fn(), instance: vi.fn() }),
}))
vi.mock('@/queries/patterns', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/patterns')>()),
  usePatterns: () => ({ data: ref([]) }),
}))
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

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
        if (key.startsWith('on')) return (cb: (...args: never[]) => void) => (callbacks[key] = cb)
        return () => {}
      },
    },
  )
  return { callbacks, instance }
})
vi.mock('@/composables/useCanvasBridge', () => ({ useCanvasBridge: () => bridge.instance }))

const nav = vi.hoisted(() => ({ push: vi.fn(), leave: null as null | (() => unknown) }))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  onBeforeRouteLeave: (fn: () => unknown) => (nav.leave = fn),
  useRoute: () => ({ params: { surface: 'entry', target: 'post' }, query: {} }),
  useRouter: () => ({ push: nav.push, resolve: vi.fn() }),
}))

import LayoutEditor from '@/pages/layouts/[surface]/[target].vue'
import LayoutTopBar from '@/pages/layouts/components/LayoutTopBar.vue'

const BLOCKS = [
  { id: 'laytitle0001', type: 'entry_title', data: { level: 'h1' }, settings: {} },
  { id: 'laybody00001', type: 'entry_content', data: { field: 'body' }, settings: {} },
]
function session(overrides: Partial<LayoutSession> = {}): LayoutSession {
  return {
    token: 'tok1',
    themeUrl: '/_preview/tok1',
    layout: { blocks: structuredClone(BLOCKS), settings: {}, lock_version: 2 },
    starter: false,
    starterLayout: structuredClone(BLOCKS),
    required: [{ type: 'entry_content', field: 'body' }],
    palette: ['entry_title', 'entry_content'],
    sample: { id: 'posta0000001', label: 'Post A' },
    placeholder: false,
    label: 'Posts — single post',
    reach: 'Applies to every post',
    ...overrides,
  }
}

function mountPage() {
  return mount(LayoutEditor, {
    global: {
      stubs: {
        UDashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
        UDashboardNavbar: { template: '<div><slot name="leading" /><slot /></div>' },
        RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
        UTooltip: { template: '<div><slot /></div>' },
        Tooltip: { template: '<div><slot /></div>' },
        UnsavedChangesModal: true,
      },
    },
    attachTo: document.body,
  })
}
type Page = ReturnType<typeof mountPage>
const topBar = (w: Page) => w.findComponent(LayoutTopBar)

beforeAll(async () => {
  await import('@/fields/components/BlocksField.vue')
})

beforeEach(() => {
  setActivePinia(createPinia())
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
  q.remove.mockReset()
  q.samples.mockReset().mockResolvedValue({
    samples: [{ id: 'posta0000001', label: 'Post A' }],
    default: 'posta0000001',
  })
  nav.push.mockReset()
  nav.leave = null
  notify.success.mockReset()
  notify.error.mockReset()
  localStorage.clear()
  localStorage.setItem('thallo.canvas.auto_apply', '0')
})

describe('the layout editor', () => {
  it('loads the stage from the session and offers the Fields first, the general blocks after', async () => {
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="layout-stage"]').attributes('src')).toBe('/_preview/tok1?canvas=1')
    const groups = w.findAll('[data-test^="palette-group-"]').map((g) => g.attributes('data-test'))
    expect(groups[0]).toBe('palette-group-Fields')
    expect(groups).toContain('palette-group-Content')
    expect(w.find('[data-test="palette-card-entry_title"]').exists()).toBe(true)
    expect(w.find('[data-test="palette-card-heading"]').exists()).toBe(true)
    w.unmount()
  })

  it('refuses to delete a required block, saying why; another block asks as usual', async () => {
    const w = mountPage()
    await flushPromises()
    bridge.callbacks.onBlockDeleteRequest!('laybody00001' as never, null as never)
    await flushPromises()
    expect(w.find('[data-test="layout-required-refusal"]').text()).toContain('body')
    expect(w.find('[data-test="canvas-delete-confirm-yes"]').exists()).toBe(false)
    await w.find('[data-test="canvas-delete-cancel"]').trigger('click')

    bridge.callbacks.onBlockDeleteRequest!('laytitle0001' as never, null as never)
    await flushPromises()
    expect(w.find('[data-test="layout-required-refusal"]').exists()).toBe(false)
    expect(w.find('[data-test="canvas-delete-confirm-yes"]').exists()).toBe(true)
    w.unmount()
  })

  it('Save shows its reach and posts the version loaded', async () => {
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="layout-reach"]').text()).toBe('Applies to every post')
    q.save.mockResolvedValueOnce({
      layout: { blocks: BLOCKS, settings: {}, lock_version: 3 },
      previewCleared: false,
    })
    await w.find('[data-test="layout-save"]').trigger('click')
    await flushPromises()
    expect(q.save).toHaveBeenCalledWith(
      'entry',
      'post',
      expect.objectContaining({ token: 'tok1', expected_lock_version: 2 }),
    )
    w.unmount()
  })

  it('a conflict offers Reload only; a removed layout says so', async () => {
    const w = mountPage()
    await flushPromises()
    q.save.mockRejectedValueOnce(
      new ApiError(
        'moved',
        409,
        {},
        { error: { details: { code: 'LAYOUT_VERSION_CONFLICT', current: 3 } } },
      ),
    )
    await w.find('[data-test="layout-save"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="layout-conflict"]').exists()).toBe(true)
    expect(w.find('[data-test="layout-conflict-reload"]').exists()).toBe(true)
    expect(w.find('[data-test="layout-save"]').exists()).toBe(false)
    w.unmount()

    const r = mountPage()
    await flushPromises()
    q.save.mockRejectedValueOnce(
      new ApiError('gone', 410, {}, { error: { details: { code: 'LAYOUT_SESSION_RETIRED' } } }),
    )
    await r.find('[data-test="layout-save"]').trigger('click')
    await flushPromises()
    expect(r.find('[data-test="layout-retired"]').exists()).toBe(true)
    r.unmount()
  })

  it('names a placeholder sample', async () => {
    q.mint.mockImplementation(async () => session({ placeholder: true, sample: null }))
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="layout-placeholder-notice"]').exists()).toBe(true)
    w.unmount()
  })

  it('Remove becomes available as soon as a first save makes the layout', async () => {
    q.mint.mockImplementation(async () =>
      session({
        starter: true,
        layout: { blocks: structuredClone(BLOCKS), settings: {}, lock_version: 0 },
      }),
    )
    const w = mountPage()
    await flushPromises()
    expect(topBar(w).props('canRemove')).toBe(false)
    q.save.mockResolvedValueOnce({
      layout: { blocks: BLOCKS, settings: {}, lock_version: 1 },
      previewCleared: false,
    })
    await w.find('[data-test="layout-save"]').trigger('click')
    await flushPromises()
    expect(topBar(w).props('canRemove')).toBe(true)
    w.unmount()
  })

  it('Remove asks first, naming the consequence, then removes with the session and returns to the list', async () => {
    const w = mountPage()
    await flushPromises()
    topBar(w).vm.$emit('remove')
    await flushPromises()
    // The dialog renders into the body (the modal teleports).
    expect(document.body.textContent).toContain(
      'Every one of the posts goes back to the theme’s design; your unsaved edits are discarded.',
    )
    q.remove.mockResolvedValueOnce({ lockVersion: 3 })
    ;(document.body.querySelector('[data-test="layout-remove-confirm"]') as HTMLElement).click()
    await flushPromises()
    expect(q.remove).toHaveBeenCalledWith('entry', 'post', {
      token: 'tok1',
      expected_lock_version: 2,
    })
    expect(nav.push).toHaveBeenCalledWith('/layouts')
    w.unmount()
  })

  it('Reset to starter puts the starter back as one edit undo takes back; leaving then asks', async () => {
    const extra = { id: 'layhead00001', type: 'heading', data: { text: 'Mine' }, settings: {} }
    q.mint.mockImplementation(async () =>
      session({
        layout: { blocks: [...structuredClone(BLOCKS), extra], settings: {}, lock_version: 2 },
      }),
    )
    const w = mountPage()
    await flushPromises()
    expect(topBar(w).props('dirty')).toBe(false)
    expect(nav.leave!()).toBe(true)

    topBar(w).vm.$emit('resetStarter')
    await flushPromises()
    expect(topBar(w).props('dirty')).toBe(true)
    expect(topBar(w).props('canUndo')).toBe(true)
    expect(nav.leave!()).toBeInstanceOf(Promise)

    topBar(w).vm.$emit('undo')
    await flushPromises()
    expect(topBar(w).props('dirty')).toBe(false)
    w.unmount()
  })
})
