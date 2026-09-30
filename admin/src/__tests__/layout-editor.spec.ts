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

const SCHEMAS: Record<string, unknown[]> = {
  entry_content: [{ name: 'field', type: 'string', required: false }],
  entry_loop: [{ name: 'card', type: 'blocks', required: false }],
  product_loop: [{ name: 'card', type: 'blocks', required: false }],
}
const bt = (slug: string, category: string | null, layoutOnly = false, label = slug): BlockType =>
  ({
    uuid: `bt-${slug}`,
    slug,
    label,
    icon: null,
    category,
    description: null,
    active: true,
    schema: SCHEMAS[slug] ?? [],
    style_capabilities: ['spacing'],
    style_targets: null,
    flags: layoutOnly ? { layout_only: true } : null,
    starter_content: null,
  }) as unknown as BlockType
const blockTypes = ref<BlockType[]>([
  bt('heading', 'Content'),
  bt('container', 'Layout'),
  bt('entry_title', 'Fields', true, 'Entry title'),
  bt('entry_content', 'Fields', true, 'Entry content'),
  bt('entry_loop', 'Fields', true, 'Entry list'),
  bt('pagination', 'Fields', true, 'Page navigation'),
  bt('product_name', 'Fields', true, 'Product name'),
  bt('product_buy', 'Fields', true, 'Product buy box'),
  bt('product_loop', 'Fields', true, 'Product list'),
  bt('product_tile', 'Fields', true, 'Product tile'),
  bt('product_rating', 'Fields', true, 'Product rating'),
  bt('product_price', 'Fields', true, 'Product price'),
  bt('shop_title', 'Fields', true, 'Shop title'),
  bt('category_rail', 'Fields', true, 'Category chips'),
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
    loops: [],
    bindable: { title: 'string', body: 'blocks' },
    fieldLabels: { title: 'Title', body: 'Body' },
    bindings: { entry_content: ['blocks'] },
    defaultFields: { entry_content: 'body' },
    formatNeeds: {},
    typeName: 'Posts',
    closed: null,
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
    expect(w.find('[data-test="layout-required-refusal"]').text()).toBe(
      'Every post shows its Entry content here, so the layout keeps this block. Move it instead.',
    )
    expect(w.find('[data-test="canvas-delete-confirm-yes"]').exists()).toBe(false)
    await w.find('[data-test="canvas-delete-cancel"]').trigger('click')

    bridge.callbacks.onBlockDeleteRequest!('laytitle0001' as never, null as never)
    await flushPromises()
    expect(w.find('[data-test="layout-required-refusal"]').exists()).toBe(false)
    expect(w.find('[data-test="canvas-delete-confirm-yes"]').exists()).toBe(true)
    w.unmount()
  })

  // Type layouts plan C1: a required block without a field (the product page's Product buy box) is named
  // by its label, in the refusal and in the reason Save is off.
  it('names a required block without a field by its label: the product page keeps its Product buy box', async () => {
    const product = [
      { id: 'prodname0001', type: 'product_name', data: { level: 'h1' }, settings: {} },
      { id: 'prodbuy00001', type: 'product_buy', data: {}, settings: {} },
    ]
    q.mint.mockImplementation(async () =>
      session({
        layout: { blocks: structuredClone(product), settings: {}, lock_version: 0 },
        starterLayout: structuredClone(product),
        required: [{ type: 'product_buy' }],
        palette: ['product_name', 'product_buy'],
        sample: { id: 'product00001', label: 'Linen table lamp' },
        label: 'Products — product page',
        reach: 'Applies to every product',
      }),
    )
    const w = mountPage()
    await flushPromises()
    const groups = w.findAll('[data-test^="palette-group-"]').map((g) => g.attributes('data-test'))
    expect(groups[0]).toBe('palette-group-Fields')
    expect(w.find('[data-test="palette-card-product_buy"]').exists()).toBe(true)

    bridge.callbacks.onBlockDeleteRequest!('prodbuy00001' as never, null as never)
    await flushPromises()
    expect(w.find('[data-test="layout-required-refusal"]').text()).toBe(
      'Every product shows its Product buy box here, so the layout keeps this block. Move it instead.',
    )
    await w.find('[data-test="canvas-delete-cancel"]').trigger('click')
    expect(topBar(w).props('saveBlocked')).toBeNull()
    w.unmount()

    q.mint.mockImplementation(async () =>
      session({
        layout: { blocks: [structuredClone(product[0])], settings: {}, lock_version: 0 },
        required: [{ type: 'product_buy' }],
        palette: ['product_name', 'product_buy'],
        label: 'Products — product page',
      }),
    )
    const without = mountPage()
    await flushPromises()
    expect(topBar(without).props('saveBlocked')).toBe(
      'The layout must show the Product buy box block — add it from the Blocks tab.',
    )
    without.unmount()
  })

  it('keeps the Entry content wording for a required field', async () => {
    q.mint.mockImplementation(async () =>
      session({ layout: { blocks: [structuredClone(BLOCKS[0])], settings: {}, lock_version: 2 } }),
    )
    const w = mountPage()
    await flushPromises()
    expect(topBar(w).props('saveBlocked')).toBe(
      'The layout must show the body — add an Entry content block for it.',
    )
    w.unmount()
  })

  it('a layout that cannot be opened, reached by its address, says why and leads back', async () => {
    const reason =
      'Its blocks are not installed yet. Run php glueful thallo:provision on the server — then reload this page.'
    q.mint
      .mockReset()
      .mockRejectedValue(
        new ApiError(
          'Validation failed',
          422,
          { target: reason },
          { error: { details: { target: reason } } },
        ),
      )
    const w = mountPage()
    await flushPromises()
    const closed = w.find('[data-test="layout-closed"]')
    expect(closed.exists()).toBe(true)
    expect(closed.text()).toContain(reason)
    expect(
      w.find('[data-test="layout-closed-back"]').element.closest('a')?.getAttribute('href'),
    ).toBe('/layouts')
    expect(w.find('[data-test="canvas-inspector"]').exists()).toBe(false)
    w.unmount()
  })

  // Final review: a kept layout whose pages are off the site opens only to be removed — from Site ›
  // Layouts, where its row is — so reached by its address it says why and leads back.
  it('a kept layout whose pages are off the site says so and leads back to Layouts', async () => {
    q.mint.mockImplementation(async () =>
      session({ closed: 'Listing pages are off for Posts.', placeholder: true, sample: null }),
    )
    const w = mountPage()
    await flushPromises()
    const closed = w.find('[data-test="layout-closed"]')
    expect(closed.exists()).toBe(true)
    expect(closed.text()).toContain('Listing pages are off for Posts.')
    expect(closed.text()).toContain('remove it on Site › Layouts')
    expect(w.find('[data-test="canvas-inspector"]').exists()).toBe(false)
    // Nothing to edit, save or remove here: the top bar's actions are gone with the stage.
    expect(topBar(w).exists()).toBe(false)
    w.unmount()
  })

  // Review of aa2801ec: the sample unpublished mid-session, the stage falls back to the placeholder
  // and says so; the picker stops naming the sample and reads the list again.
  it('the picker follows the stage when it falls back to the placeholder', async () => {
    const w = mountPage()
    await flushPromises()
    expect(topBar(w).props('sample')).toBe('posta0000001')
    const reads = q.samples.mock.calls.length
    bridge.callbacks.onStageState!(true as never)
    await flushPromises()
    expect(topBar(w).props('sample')).toBeUndefined()
    expect(q.samples.mock.calls.length).toBeGreaterThan(reads)
    bridge.callbacks.onStageState!(false as never)
    await flushPromises()
    expect(topBar(w).props('sample')).toBe('posta0000001')
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
      'Every post goes back to the theme’s design; your unsaved edits are discarded.',
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

// Type layouts plan B: a listing's editor offers exactly its surface's blocks, keeps the card's
// blocks in the card, and names the Entry list's reach when it refuses to delete it.
describe('the listing layout editor', () => {
  const LISTING = [
    {
      id: 'listloop0001',
      type: 'entry_loop',
      settings: {},
      data: {
        card: [{ id: 'listtitle001', type: 'entry_title', data: { level: 'h2' }, settings: {} }],
      },
    },
    { id: 'listpages001', type: 'pagination', data: {}, settings: {} },
  ]
  function listingSession(): LayoutSession {
    return session({
      layout: { blocks: structuredClone(LISTING), settings: {}, lock_version: 0 },
      starter: true,
      starterLayout: structuredClone(LISTING),
      required: [{ type: 'entry_loop' }],
      palette: ['entry_loop', 'pagination', 'entry_title'],
      loops: [{ type: 'entry_loop', card: 'card', items: ['entry_title'] }],
      sample: { id: '1', label: 'Page 1' },
      label: 'Posts — listing pages',
      reach: 'Applies to every page of the post listing',
    })
  }

  it("offers the general blocks and exactly the surface's own", async () => {
    q.mint.mockImplementation(async () => listingSession())
    const w = mountPage()
    await flushPromises()
    const offered = w
      .findAll('[data-test^="palette-card-"]')
      .map((c) => c.attributes('data-test')!.replace('palette-card-', ''))
    expect(offered).toEqual(
      expect.arrayContaining(['entry_loop', 'pagination', 'entry_title', 'heading', 'container']),
    )
    expect(offered).not.toContain('entry_content')
    expect(offered).not.toContain('product_name')
    expect(offered).not.toContain('product_buy')
    w.unmount()
  })

  it('refuses to delete the Entry list, naming its reach', async () => {
    q.mint.mockImplementation(async () => listingSession())
    const w = mountPage()
    await flushPromises()
    bridge.callbacks.onBlockDeleteRequest!('listloop0001' as never, null as never)
    await flushPromises()
    expect(w.find('[data-test="layout-required-refusal"]').text()).toBe(
      'Every page of the post listing shows its Entry list here, so the layout keeps this block. Move it instead.',
    )
    w.unmount()
  })

  it('the Entry title tile is off while the page is the target and on when the card is', async () => {
    q.mint.mockImplementation(async () => listingSession())
    const w = mountPage()
    await flushPromises()
    const tile = () => w.find('[data-test="palette-card-entry_title"]')
    expect(tile().attributes('aria-disabled')).toBe('true')
    expect(tile().attributes('title')).toBe("Entry title goes inside the Entry list's card")

    bridge.callbacks.onSlotAdd!('listloop0001' as never, 'card' as never)
    await flushPromises()
    expect(tile().attributes('aria-disabled')).toBeUndefined()
    expect(w.find('[data-test="palette-card-pagination"]').attributes('title')).toBe(
      "Page navigation can't go inside a card",
    )
    w.unmount()
  })
})

// Type layouts plan C2: the shop home's editor offers exactly its surface's blocks, keeps the card's
// blocks in the Product list's card, and names the Product list's reach when it refuses to delete it.
describe('the shop home layout editor', () => {
  const SHOP = [
    { id: 'shoptitle001', type: 'shop_title', data: { level: 'h1' }, settings: {} },
    {
      id: 'shoploop0001',
      type: 'product_loop',
      settings: {},
      data: {
        card: [{ id: 'shopname0001', type: 'product_name', data: { level: 'h2' }, settings: {} }],
      },
    },
    { id: 'shoppages001', type: 'pagination', data: {}, settings: {} },
  ]
  const PALETTE = [
    'product_loop',
    'shop_title',
    'category_rail',
    'pagination',
    'product_tile',
    'product_name',
    'product_rating',
    'product_price',
  ]
  function shopSession(layout = SHOP): LayoutSession {
    return session({
      layout: { blocks: structuredClone(layout), settings: {}, lock_version: 0 },
      starter: true,
      starterLayout: structuredClone(SHOP),
      required: [{ type: 'product_loop' }],
      palette: PALETTE,
      loops: [
        {
          type: 'product_loop',
          card: 'card',
          items: ['product_tile', 'product_name', 'product_rating', 'product_price'],
        },
      ],
      sample: { id: '1', label: 'Page 1' },
      label: 'Products — shop home',
      reach: 'Applies to every page of the shop home',
    })
  }

  it("offers the general blocks and exactly the surface's own", async () => {
    q.mint.mockImplementation(async () => shopSession())
    const w = mountPage()
    await flushPromises()
    const offered = w
      .findAll('[data-test^="palette-card-"]')
      .map((c) => c.attributes('data-test')!.replace('palette-card-', ''))
    expect(offered).toEqual(expect.arrayContaining([...PALETTE, 'heading', 'container']))
    expect(offered).not.toContain('product_buy')
    expect(offered).not.toContain('entry_title')
    expect(offered).not.toContain('entry_loop')
    w.unmount()
  })

  it('refuses to delete the Product list, naming its reach', async () => {
    q.mint.mockImplementation(async () => shopSession())
    const w = mountPage()
    await flushPromises()
    bridge.callbacks.onBlockDeleteRequest!('shoploop0001' as never, null as never)
    await flushPromises()
    expect(w.find('[data-test="layout-required-refusal"]').text()).toBe(
      'Every page of the shop home shows its Product list here, so the layout keeps this block. Move it instead.',
    )
    w.unmount()
  })

  it('the Product name tile is off while the page is the target and on when the card is', async () => {
    q.mint.mockImplementation(async () => shopSession())
    const w = mountPage()
    await flushPromises()
    const tile = () => w.find('[data-test="palette-card-product_name"]')
    expect(tile().attributes('aria-disabled')).toBe('true')
    expect(tile().attributes('title')).toBe("Product name goes inside the Product list's card")

    bridge.callbacks.onSlotAdd!('shoploop0001' as never, 'card' as never)
    await flushPromises()
    expect(tile().attributes('aria-disabled')).toBeUndefined()
    expect(w.find('[data-test="palette-card-shop_title"]').attributes('title')).toBe(
      "Shop title can't go inside a card",
    )
    w.unmount()
  })

  it('a working copy without the Product list cannot be saved', async () => {
    const without = SHOP.filter((b) => b.type !== 'product_loop')
    q.mint.mockImplementation(async () => shopSession(without))
    const w = mountPage()
    await flushPromises()
    expect(topBar(w).props('saveBlocked')).toBe(
      'The layout must show the Product list block — add it from the Blocks tab.',
    )
    w.unmount()
  })
})
