// The structure picker's session state (container-layout spec §6.1–§6.3). The offer lives in the
// editor session, never in the document, and every path out of it — a choice, a skip, content
// arriving, the container being deleted — ends it exactly once.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createStructurePicker, type PickerDeps } from '@/editor/structure/structurePicker'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { EditorDocument, OperationBody } from '@/editor/ops/types'
import type { LegalityContext, SlotTypeSummary } from '@/editor/structure/legality'

const container = (id: string, content: BlockInstance[] = []): BlockInstance =>
  ({
    id,
    type: 'container',
    data: { content },
    settings: { style: {} },
  }) as unknown as BlockInstance
const heading = (id: string): BlockInstance =>
  ({ id, type: 'heading', data: {}, settings: {} }) as unknown as BlockInstance

function legalityContext(): LegalityContext {
  const types: SlotTypeSummary[] = [
    { slug: 'container', label: 'Container', slots: { content: { blockTypes: [] } } },
    { slug: 'gallery', label: 'Gallery', slots: { items: { blockTypes: ['image'] } } },
    { slug: 'heading', label: 'Heading', slots: {} },
    { slug: 'button', label: 'Button', slots: {} },
    { slug: 'rich_text', label: 'Rich text', slots: {} },
  ]
  return {
    regionsOf: (slug) => Object.keys(types.find((t) => t.slug === slug)?.slots ?? {}),
    blockTypes: () => types,
    rootSlots: () => ({ body: { blockTypes: [] } }),
    maxDepth: 5,
  }
}

interface Harness {
  picker: ReturnType<typeof createStructurePicker>
  committed: OperationBody[][]
  doc: { value: EditorDocument }
  factory: ReturnType<typeof vi.fn>
  published: { id: string; presets: { key: string; enabled: boolean; reason?: string }[] }[][]
}

function harness(overrides: Partial<PickerDeps> = {}): Harness {
  const doc = { value: { fields: { body: [container('c1')] } } as EditorDocument }
  const committed: OperationBody[][] = []
  const published: Harness['published'] = []
  let n = 0
  const factory = vi.fn(async (slug: string) => {
    n += 1
    return {
      id: `f${n}`,
      type: slug,
      data: slug === 'container' ? { content: [] } : {},
      settings: {},
    }
  })
  const deps: PickerDeps = {
    doc: () => doc.value,
    legality: legalityContext,
    classesFor: () => [],
    factory: factory as unknown as PickerDeps['factory'],
    commit: async (ops) => {
      committed.push(ops)
    },
    publish: (offers) => published.push(offers),
    notify: () => {},
    ...overrides,
  }
  return { picker: createStructurePicker(deps), committed, doc, factory, published }
}

/** The most recent publish: the complete live list, which is what the stage renders. */
const last = <T>(all: T[]): T | undefined => all[all.length - 1]

beforeEach(() => vi.clearAllMocks())

/** A factory that answers only once the returned release is called — however many calls there are. */
function gatedFactory() {
  let open: () => void = () => {}
  const gate = new Promise<void>((resolve) => (open = resolve))
  let n = 0
  const factory = vi.fn(async (slug: string) => {
    await gate
    n += 1
    return { id: `f${n}`, type: slug, data: { content: [] }, settings: {} }
  })
  return { factory: factory as unknown as PickerDeps['factory'], release: () => open() }
}

describe('the offer', () => {
  it('is published when made and withdrawn when it ends', () => {
    const h = harness()
    h.picker.offer('c1')
    expect(h.picker.state('c1')).toBe('pending')
    expect(last(h.published)).toEqual([
      expect.objectContaining({ id: 'c1', presets: expect.any(Array) }),
    ])

    h.picker.skip('c1')
    expect(h.picker.state('c1')).toBe('ended')
    expect(last(h.published)).toEqual([])
  })

  it('names every preset it can offer, with the ones that would not fit disabled', () => {
    // A container at depth four cannot hold a three-high Section, and the tile says why rather
    // than failing after the click.
    const h = harness()
    h.doc.value = {
      fields: { body: [container('a', [container('b', [container('c', [container('c1')])])])] },
    }
    h.picker.offer('c1')
    const offered = last(h.published)![0]!.presets
    const section = offered.find((p) => p.key === 'section')!
    expect(section.enabled).toBe(false)
    expect(section.reason).toMatch(/levels/)
    expect(offered.find((p) => p.key === 'stack')!.enabled).toBe(true)
  })

  it('a column split is refused at depth four too: its columns would sit at five with nowhere to put a child', () => {
    // The case that reached an author as a 422: a 67 / 33 inside a tab's content. The columns are
    // empty containers, and a block that holds blocks needs a level below it — the server refuses
    // its list at the cap even while it is empty. Counted as leaves they fitted, and were offered.
    const h = harness()
    h.doc.value = {
      fields: { body: [container('a', [container('b', [container('c', [container('c1')])])])] },
    }
    h.picker.offer('c1')
    const offered = last(h.published)![0]!.presets
    for (const key of [
      'cols-33-67',
      'cols-67-33',
      'cols-halves',
      'cols-thirds',
      'cols-quarters',
      'grid-2x2',
    ]) {
      const preset = offered.find((p) => p.key === key)
      if (!preset) continue
      expect(preset.enabled, key).toBe(false)
      expect(preset.reason, key).toBe('Would nest deeper than 5 levels')
    }
    expect(offered.filter((p) => p.key.startsWith('cols-')).length).toBeGreaterThan(3)

    // One level up there is room for the columns and for what goes in them.
    const shallower = harness()
    shallower.doc.value = {
      fields: { body: [container('a', [container('b', [container('c1')])])] },
    }
    shallower.picker.offer('c1')
    const roomy = last(shallower.published)![0]!.presets
    expect(roomy.find((p) => p.key === 'cols-33-67')!.enabled).toBe(true)
  })
})

describe('choosing a preset', () => {
  it('commits one transaction and ends the offer', async () => {
    const h = harness()
    h.picker.offer('c1')
    await h.picker.choose('c1', 'cols-50-50')

    expect(h.committed).toHaveLength(1)
    const ops = h.committed[0]!
    // The container's own writes first, then one insert per child, in plan order.
    expect(ops.filter((op) => op.type === 'SetSetting').length).toBeGreaterThan(0)
    expect(ops.filter((op) => op.type === 'InsertBlock')).toHaveLength(2)
    expect(h.picker.state('c1')).toBe('ended')
    expect(last(h.published)).toEqual([])
  })

  it('carries the factory instance, with the preset settings merged over it', async () => {
    const h = harness()
    h.picker.offer('c1')
    await h.picker.choose('c1', 'cols-50-50')
    const insert = h.committed[0]!.find((op) => op.type === 'InsertBlock')!
    expect(insert.block.id).toBe('f1') // the id the editor allocated for the factory instance
    expect(insert.block.settings).toMatchObject({
      style: { layout: { direction: { base: { type: 'choice', value: 'column' } } } },
    })
  })

  it('ignores a second choose while the first is preparing', async () => {
    const gate = gatedFactory()
    const h = harness({ factory: gate.factory })
    h.picker.offer('c1')
    const first = h.picker.choose('c1', 'cols-50-50')
    expect(h.picker.state('c1')).toBe('preparing')

    await h.picker.choose('c1', 'section') // ignored: a choice is already preparing
    gate.release()
    await first
    expect(h.committed).toHaveLength(1)
    expect(h.committed[0]!.filter((op) => op.type === 'InsertBlock')).toHaveLength(2)
  })

  it('commits nothing and ends when the plan is empty', async () => {
    // Stack on an already-stacked container: nothing to write, so nothing is recorded (spec §6.4).
    const h = harness()
    h.picker.offer('c1')
    await h.picker.choose('c1', 'stack')
    expect(h.committed).toEqual([])
    expect(h.picker.state('c1')).toBe('ended')
  })

  it('refuses a key no preset defines', async () => {
    const h = harness()
    h.picker.offer('c1')
    await h.picker.choose('c1', 'nope')
    expect(h.committed).toEqual([])
    expect(h.picker.state('c1')).toBe('pending')
  })
})

describe('the offer ends when content arrives', () => {
  it('for an insert into the container, and a later choose commits nothing', async () => {
    const h = harness()
    h.picker.offer('c1')
    h.picker.onDocumentChange([
      {
        type: 'InsertBlock',
        position: { parent: 'c1', slot: 'content', index: 0 },
        block: heading('h1'),
      },
    ])
    expect(h.picker.state('c1')).toBe('ended')

    await h.picker.choose('c1', 'cols-50-50')
    expect(h.committed).toEqual([])
  })

  it('for a move or a duplicate into it, but not for one elsewhere', () => {
    const moved = harness()
    moved.picker.offer('c1')
    moved.picker.onDocumentChange([
      {
        type: 'MoveBlock',
        block: 'h1',
        from: { parent: null, slot: 'body', index: 0 },
        to: { parent: 'c1', slot: 'content', index: 0 },
      },
    ])
    expect(moved.picker.state('c1')).toBe('ended')

    const duplicated = harness()
    duplicated.picker.offer('c1')
    duplicated.picker.onDocumentChange([
      {
        type: 'DuplicateBlock',
        source: 'h1',
        position: { parent: 'c1', slot: 'content', index: 0 },
        block: heading('h2'),
      },
    ])
    expect(duplicated.picker.state('c1')).toBe('ended')

    const elsewhere = harness()
    elsewhere.picker.offer('c1')
    elsewhere.picker.onDocumentChange([
      {
        type: 'InsertBlock',
        position: { parent: null, slot: 'body', index: 1 },
        block: heading('h3'),
      },
    ])
    expect(elsewhere.picker.state('c1')).toBe('pending')
  })

  it('even while a choice is preparing, and the commit never happens', async () => {
    const gate = gatedFactory()
    const h = harness({ factory: gate.factory })
    h.picker.offer('c1')
    const choosing = h.picker.choose('c1', 'cols-50-50')
    h.picker.onDocumentChange([
      {
        type: 'InsertBlock',
        position: { parent: 'c1', slot: 'content', index: 0 },
        block: heading('h1'),
      },
    ])
    gate.release()
    await choosing
    expect(h.committed).toEqual([])
    expect(h.picker.state('c1')).toBe('ended')
  })

  it('and undoing that insert does not reopen it', () => {
    const h = harness()
    h.picker.offer('c1')
    h.picker.onDocumentChange([
      {
        type: 'InsertBlock',
        position: { parent: 'c1', slot: 'content', index: 0 },
        block: heading('h1'),
      },
    ])
    h.picker.onDocumentChange([
      {
        type: 'RemoveBlock',
        position: { parent: 'c1', slot: 'content', index: 0 },
        block: heading('h1'),
      },
    ])
    expect(h.picker.state('c1')).toBe('ended')
  })
})

describe('what can go wrong while preparing', () => {
  it('a container that gained content during the factory call commits nothing', async () => {
    const gate = gatedFactory()
    const h = harness({ factory: gate.factory })
    h.picker.offer('c1')
    const choosing = h.picker.choose('c1', 'cols-50-50')
    // The document changes without the picker being told: emptiness is re-read, not remembered.
    h.doc.value = { fields: { body: [container('c1', [heading('h1')])] } }
    gate.release()
    await choosing
    expect(h.committed).toEqual([])
  })

  it('a deleted container commits nothing', async () => {
    const gate = gatedFactory()
    const h = harness({ factory: gate.factory })
    h.picker.offer('c1')
    const choosing = h.picker.choose('c1', 'cols-50-50')
    h.doc.value = { fields: { body: [] } }
    gate.release()
    await choosing
    expect(h.committed).toEqual([])
    expect(h.picker.state('c1')).toBe('ended')
  })

  it('a factory failure leaves the offer pending and says so', async () => {
    const notify = vi.fn()
    const h = harness({
      factory: vi.fn(async () => {
        throw new Error('offline')
      }) as unknown as PickerDeps['factory'],
      notify,
    })
    h.picker.offer('c1')
    await h.picker.choose('c1', 'cols-50-50')
    expect(h.committed).toEqual([])
    expect(h.picker.state('c1')).toBe('pending')
    expect(notify).toHaveBeenCalled()
  })

  it('a candidate the real instances make illegal is refused, and the reasons are republished', async () => {
    // The placeholder used for the tile's legality is an empty container; the factory returns one
    // carrying a gallery with a heading in it, which its own slot forbids. Only checking the REAL
    // instances catches that (spec §6.3).
    const h = harness({
      factory: vi.fn(async (slug: string) => ({
        id: 'f1',
        type: slug,
        data: {
          content: [
            {
              id: 'g1',
              type: 'gallery',
              data: { items: [{ id: 'h9', type: 'heading', data: {}, settings: {} }] },
              settings: {},
            },
          ],
        },
        settings: {},
      })) as unknown as PickerDeps['factory'],
    })
    h.picker.offer('c1')
    const before = h.published.length
    await h.picker.choose('c1', 'cols-50-50')

    expect(h.committed).toEqual([])
    expect(h.picker.state('c1')).toBe('pending')
    // The tiles are republished so the refusal is visible where the choice was made.
    expect(h.published.length).toBeGreaterThan(before)
    const presets = last(h.published)![0]!.presets
    expect(presets.find((p) => p.key === 'cols-50-50')!.enabled).toBe(false)
  })
})

describe('offers are per container', () => {
  it('ending one leaves another alone', () => {
    const h = harness()
    h.doc.value = { fields: { body: [container('c1'), container('c2')] } }
    h.picker.offer('c1')
    h.picker.offer('c2')
    expect(last(h.published)!.map((o) => o.id)).toEqual(['c1', 'c2'])

    h.picker.skip('c1')
    expect(last(h.published)!.map((o) => o.id)).toEqual(['c2'])
    expect(h.picker.state('c2')).toBe('pending')
  })
})
