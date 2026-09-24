// A block inside a chrome region gets the settings a page's block has — Layout, Style, Advanced —
// from the Regions page. The same inspector as the Design page's, without its stage: the block is
// chosen from its card, and an edit is written straight into the region's working copy (there is
// no operations layer here). Its Content tab is the block's own fields; what only the card can
// hold — a rich text body, the blocks inside — stays on the card, as there is no stage here.
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { ref } from 'vue'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { classEditorSchema } from './helpers/classEditorSchema'

const type = (slug: string, caps: string[], schema: unknown[] = []) => ({
  uuid: slug,
  slug,
  label: slug.charAt(0).toUpperCase() + slug.slice(1),
  icon: null,
  category: null,
  description: null,
  active: true,
  schema,
  style_capabilities: caps,
  style_targets: null,
  flags: null,
  starter_content: null,
})
const TYPES = [
  type(
    'container',
    ['spacing', 'colors', 'layout.display', 'layout.direction'],
    [{ name: 'content', type: 'blocks' }],
  ),
  type(
    'button',
    ['spacing', 'colors', 'radius', 'layout.item'],
    [{ name: 'label', type: 'string' }],
  ),
]
const PANEL = {
  id: 'cls000000001',
  version: 1,
  name: 'Panel',
  description: null,
  style: { radius: { type: 'token', value: 'radius.lg' } },
  archived: false,
  archived_at: null,
  locked_by_job: null,
  created_at: null,
  updated_at: null,
}

vi.mock('@/queries/styleSchema', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/styleSchema')>()),
  useStyleSchema: () => ({ data: ref(classEditorSchema()) }),
}))
vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: ref(TYPES) }),
}))
vi.mock('@/queries/styleClasses', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/styleClasses')>()),
  useStyleClasses: () => ({
    data: ref({ generation: 1, classes: [PANEL] }),
    refetch: () => Promise.resolve(),
  }),
}))

import RegionBlockInspector from '@/pages/regions/components/RegionBlockInspector.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'

const token = (value: string) => ({ type: 'token', value })
const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T
/** A button nested in a container: a region block is not always at the top. */
const tree = (buttonSettings: Record<string, unknown> = {}): BlockInstance[] => [
  { id: 'logo00000001', type: 'button', data: { label: 'First' }, settings: {} },
  {
    id: 'cont00000001',
    type: 'container',
    data: {
      content: [
        { id: 'butn00000001', type: 'button', data: { label: 'Go' }, settings: buttonSettings },
      ],
    },
    settings: {},
  },
]
const buttonIn = (blocks: BlockInstance[]) =>
  (blocks[1]!.data.content as BlockInstance[])[0]!.settings as Record<string, unknown>

/** Mounted as the page mounts it: every emission is fed back as the prop, a tick later. */
function mountInspector(blocks: BlockInstance[], blockId: string, breakpoint = 'base') {
  localStorage.clear()
  resetFolds()
  const emissions: BlockInstance[][] = []
  const w = mount(RegionBlockInspector, {
    props: {
      blocks,
      blockId,
      activeBreakpoint: breakpoint,
      'onUpdate:blocks': (next: BlockInstance[]) => {
        emissions.push(clone(next))
        void w.setProps({ blocks: next })
      },
    } as never,
    // The Content tab's field widgets read the app's stores.
    global: { plugins: [createPinia()] },
    attachTo: document.body,
  })
  return Object.assign(w, { emissions, last: () => emissions[emissions.length - 1]! })
}
type Inspector = ReturnType<typeof mountInspector>
async function openTab(w: Inspector, name: string) {
  const tab = w
    .find('[data-test="block-inspector-tabs"]')
    .findAll('button[role="tab"]')
    .find((b) => b.text() === name)!
  await tab.trigger('mousedown', { button: 0 })
  await tab.trigger('click')
  await flushPromises()
}

describe('a region block’s inspector', () => {
  it('has the Design page’s four tabs, and opens on Content', () => {
    const w = mountInspector(tree(), 'butn00000001')
    expect(w.find('[data-test="block-inspector-title"]').text()).toBe('Button')
    const tabs = w.find('[data-test="block-inspector-tabs"]').findAll('button[role="tab"]')
    expect(tabs.map((b) => b.text())).toEqual(['Content', 'Layout', 'Style', 'Advanced'])
    expect(tabs[0]!.attributes('aria-selected')).toBe('true')
    // Saving a block's declarations as a class is the Design page's flow.
    expect(w.find('[data-test="save-as-style-class"]').exists()).toBe(false)
    w.unmount()
  })

  it('a Content edit lands on the nested block’s data, and only there', async () => {
    const w = mountInspector(tree(), 'butn00000001')
    await w
      .find('[data-test="block-inspector-tabs"] [role="tabpanel"] input')
      .setValue('Get started')
    await flushPromises()
    const content = w.last()[1]!.data.content as BlockInstance[]
    expect(content[0]!.data).toEqual({ label: 'Get started' })
    expect(w.last()[0]!.data).toEqual({ label: 'First' })
    w.unmount()
  })

  it('a container’s blocks are left to its card: no summary or add button with nowhere to go', () => {
    const w = mountInspector(tree(), 'cont00000001')
    expect(w.find('[data-test="region-summary-content"]').exists()).toBe(false)
    expect(w.find('[data-test="region-add-content"]').exists()).toBe(false)
    expect(w.find('[data-test="content-on-card"]').exists()).toBe(true)
    w.unmount()
  })

  it('a style edit lands on the nested block, and only there', async () => {
    const w = mountInspector(tree(), 'butn00000001')
    await openTab(w, 'Style')
    await w
      .find('[data-test="style-field-radius"] [data-test="token-radius.full"]')
      .trigger('click')
    await flushPromises()
    const expected = tree({ style: { radius: token('radius.full') } })
    expect(w.last()).toEqual(expected)
    w.unmount()
  })

  it('linked padding: one click writes all four sides and all four survive', async () => {
    const w = mountInspector(tree(), 'butn00000001', 'md')
    await openTab(w, 'Style')
    const padding = w.find('[data-test="box-padding"]')
    await padding.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
    await padding.find('[data-test="box-panel"] [data-test="token-spacing.xl"]').trigger('click')
    await flushPromises()
    const side = { md: token('spacing.xl') }
    expect(buttonIn(w.last())).toEqual({
      style: { spacing: { padding: { top: side, right: side, bottom: side, left: side } } },
    })
    w.unmount()
  })

  it('style classes: applied, then removed — and an emptied list is dropped, not stored', async () => {
    const w = mountInspector(tree(), 'butn00000001')
    await openTab(w, 'Advanced')
    expect(w.find('[data-test="style-classes-empty"]').exists()).toBe(true)
    w.findComponent({ name: 'AdvancedTab' }).vm.$emit('apply-class', PANEL.id)
    await flushPromises()
    expect(buttonIn(w.last())).toEqual({ classes: [PANEL.id] })
    expect(w.find(`[data-test="style-class-${PANEL.id}"]`).text()).toContain('Panel')

    await w.find(`[data-test="style-class-remove-${PANEL.id}"]`).trigger('click')
    await flushPromises()
    expect(buttonIn(w.last())).toEqual({})
    w.unmount()
  })

  it('detaching a class writes what it contributed to the block and removes the reference', async () => {
    const w = mountInspector(tree({ classes: [PANEL.id] }), 'butn00000001')
    await openTab(w, 'Advanced')
    await w.find(`[data-test="style-class-detach-${PANEL.id}"]`).trigger('click')
    await flushPromises()
    expect(buttonIn(w.last())).toEqual({ style: { radius: token('radius.lg') } })
    w.unmount()
  })

  it('an Advanced field is written under `advanced`', async () => {
    const w = mountInspector(tree(), 'cont00000001')
    await openTab(w, 'Advanced')
    w.findComponent({ name: 'AdvancedTab' }).vm.$emit('set', 'anchor', 'site-top')
    await flushPromises()
    expect(w.last()[1]!.settings).toEqual({ advanced: { anchor: 'site-top' } })
    w.unmount()
  })

  it('closes when its block is gone — deleted from its card while the panel was open', async () => {
    const w = mountInspector(tree(), 'butn00000001')
    await w.setProps({ blocks: [tree()[0]!] } as never)
    await flushPromises()
    expect(w.emitted('close')).toHaveLength(1)
    w.unmount()
  })
})
