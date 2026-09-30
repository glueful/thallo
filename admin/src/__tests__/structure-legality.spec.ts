import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  candidateTree,
  checkInsert,
  checkInsertSequence,
  checkInsertSubtree,
  isInsideCard,
  checkMoves,
  insertCandidate,
  type LegalityContext,
  type SlotTypeSummary,
} from '@/editor/structure/legality'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { EditorDocument } from '@/editor/ops/types'

// Visual builder spec §5.2: one legality module, its rules shared with the server validator
// through `tests/fixtures/structure/legality` — each case names the builder's expectation
// (always enforcing allow-lists) and the server's (enforce_block_types is its switch).
const FIXTURES = join(__dirname, '../../../tests/fixtures/structure/legality')

interface FixtureType {
  slug: string
  label: string
  schema: { name: string; type: string; block_types?: string[] }[]
}
interface FixtureCase {
  name: string
  blockTypes: FixtureType[]
  rootSlots: Record<string, { block_types: string[] }>
  doc: { fields: Record<string, unknown> }
  moves?: { block: string; to: { parent: string | null; slot: string | null; index: number } }[]
  insert?: {
    position: { parent: string | null; slot: string | null; index: number }
    block: BlockInstance
  }
  candidate?: { fields: Record<string, unknown> }
  expect: { builder: string; server: string | null }
}

function contextOf(c: FixtureCase): LegalityContext {
  const types = c.blockTypes.map((t) => ({
    slug: t.slug,
    label: t.label,
    slots: Object.fromEntries(
      t.schema
        .filter((f) => f.type === 'blocks')
        .map((f) => [f.name, { blockTypes: f.block_types ?? [] }]),
    ),
  }))
  return {
    regionsOf: (slug) => Object.keys(types.find((t) => t.slug === slug)?.slots ?? {}),
    blockTypes: () => types,
    rootSlots: () =>
      Object.fromEntries(
        Object.entries(c.rootSlots).map(([k, v]) => [k, { blockTypes: v.block_types }]),
      ),
    maxDepth: 5,
  }
}

const files = readdirSync(FIXTURES).filter((f) => f.endsWith('.json'))

// ── Helpers for the whole-candidate cases ────────────────────────────────────
const heading = (id: string): BlockInstance => ({ id, type: 'heading', data: {}, settings: {} })
const image = (id: string): BlockInstance => ({ id, type: 'image', data: {}, settings: {} })
const tab = (id: string): BlockInstance => ({
  id,
  type: 'tab',
  data: { content: [] },
  settings: {},
})
const gallery = (id: string, ...items: BlockInstance[]): BlockInstance => ({
  id,
  type: 'gallery',
  data: { items },
  settings: {},
})
const container = (id: string, ...content: BlockInstance[]): BlockInstance => ({
  id,
  type: 'container',
  data: { content },
  settings: {},
})

/** Types enough to nest: containers hold anything, a gallery holds images, tabs hold tabs. */
function subtreeContext(): LegalityContext {
  const types: SlotTypeSummary[] = [
    { slug: 'container', label: 'Container', slots: { content: { blockTypes: [] } } },
    { slug: 'gallery', label: 'Gallery', slots: { items: { blockTypes: ['image'] } } },
    { slug: 'tabs', label: 'Tabs', slots: { items: { blockTypes: ['tab'] } } },
    { slug: 'tab', label: 'Tab', slots: { content: { blockTypes: [] } } },
    { slug: 'heading', label: 'Heading', slots: {} },
    { slug: 'image', label: 'Image', slots: {} },
  ]
  return {
    regionsOf: (slug) => Object.keys(types.find((t) => t.slug === slug)?.slots ?? {}),
    blockTypes: () => types,
    rootSlots: () => ({ body: { blockTypes: [] } }),
    maxDepth: 5,
  }
}

describe('tree legality fixtures', () => {
  it('has fixtures', () => {
    expect(files.length).toBeGreaterThan(0)
  })

  for (const file of files) {
    const doc = JSON.parse(readFileSync(join(FIXTURES, file), 'utf8')) as { cases: FixtureCase[] }
    for (const c of doc.cases) {
      it(`${file} / ${c.name}`, () => {
        const ctx = contextOf(c)
        const verdict = c.moves
          ? checkMoves(c.doc, c.moves, ctx)
          : // The whole candidate is judged, not only its root: both runtimes see the same tree.
            checkInsertSubtree(c.doc, c.insert!.position, c.insert!.block, ctx)
        expect(verdict.ok ? 'ok' : verdict.reason).toBe(c.expect.builder)
        if (c.candidate && c.moves) {
          expect(candidateTree(c.doc, c.moves, ctx)).toEqual(c.candidate)
        }
      })
    }
  }

  it('a candidate is judged all the way down, not only at its root', () => {
    // Container-layout spec §6.3: a subtree assembled elsewhere can be welcome at its destination
    // and illegal inside itself. `checkInsert` asks only about the root, which is why the picker
    // and the presets ask `checkInsertSubtree`.
    const ctx = subtreeContext()
    const doc = { fields: { body: [container('c1')] } }
    const illegal = container('new', gallery('g1', heading('h1')))
    const position = { parent: 'c1', slot: 'content', index: 0 }

    expect(checkInsert(doc, position, illegal, ctx)).toEqual({ ok: true })
    const verdict = checkInsertSubtree(doc, position, illegal, ctx)
    expect(verdict.ok).toBe(false)
    expect(verdict.ok ? '' : verdict.reason).toBe('type-not-allowed')
    expect(verdict.ok ? '' : verdict.message).toContain('items')

    expect(
      checkInsertSubtree(doc, position, container('new', gallery('g1', image('i1'))), ctx),
    ).toEqual({
      ok: true,
    })
  })

  it('refuses a nested tabs cap inside the candidate', () => {
    const ctx = subtreeContext()
    const doc = { fields: { body: [container('c1')] } }
    const items = Array.from({ length: 13 }, (_, i) => tab(`t${i}`))
    const over = container('new', { id: 'tb', type: 'tabs', data: { items }, settings: {} })
    const verdict = checkInsertSubtree(doc, { parent: 'c1', slot: 'content', index: 0 }, over, ctx)
    expect(verdict.ok ? '' : verdict.reason).toBe('source-slot')

    const under = container('new', {
      id: 'tb',
      type: 'tabs',
      data: { items: items.slice(0, 12) },
      settings: {},
    })
    expect(
      checkInsertSubtree(doc, { parent: 'c1', slot: 'content', index: 0 }, under, ctx).ok,
    ).toBe(true)
  })

  it('counts depth from the destination through the deepest descendant', () => {
    const ctx = subtreeContext()
    // Three containers deep already; a two-high candidate fits at five, a three-high one does not.
    const doc = { fields: { body: [container('c1', container('c2', container('c3')))] } }
    const position = { parent: 'c3', slot: 'content', index: 0 }
    expect(checkInsertSubtree(doc, position, container('a', heading('h')), ctx).ok).toBe(true)
    const deeper = checkInsertSubtree(
      doc,
      position,
      container('a', container('b', heading('h'))),
      ctx,
    )
    expect(deeper.ok ? '' : deeper.reason).toBe('depth')
  })
})

describe('insertCandidate', () => {
  const ctx = subtreeContext()

  it('places a block at a root position and at a nested one', () => {
    const doc = { fields: { body: [container('c1')] } }
    const atRoot = insertCandidate(doc, { parent: null, slot: 'body', index: 0 }, heading('h'), ctx)
    expect(atRoot!.fields.body).toEqual([heading('h'), container('c1')])

    const nested = insertCandidate(
      doc,
      { parent: 'c1', slot: 'content', index: 0 },
      heading('h'),
      ctx,
    )
    expect(nested!.fields.body).toEqual([container('c1', heading('h'))])
    // The original document is untouched: legality never mutates what it is judging.
    expect(doc.fields.body).toEqual([container('c1')])
  })

  it('returns null for a parent the document does not hold', () => {
    const doc = { fields: { body: [container('c1')] } }
    expect(
      insertCandidate(doc, { parent: 'gone', slot: 'content', index: 0 }, heading('h'), ctx),
    ).toBeNull()
    expect(
      insertCandidate(doc, { parent: null, slot: 'nope', index: 0 }, heading('h'), ctx),
    ).toBeNull()
  })
})

describe('checkInsertSequence', () => {
  const ctx = subtreeContext()

  it('judges each insert against the document the previous one produced', () => {
    // A preset inserts a container and then a block INTO it: the second destination does not exist
    // until the first insert has happened.
    const doc = { fields: { body: [container('c1')] } }
    const verdict = checkInsertSequence(
      doc,
      [
        { position: { parent: 'c1', slot: 'content', index: 0 }, block: container('inner') },
        { position: { parent: 'inner', slot: 'content', index: 0 }, block: heading('h') },
      ],
      ctx,
    )
    expect(verdict).toEqual({ ok: true })
  })

  it('refuses the second insert when the first has used up the room', () => {
    // Three containers deep: a new container lands at four, the deepest a block that holds blocks
    // can sit — its own list needs five. So a second container inside it is the one refused, and
    // only a sequence judged in order, each insert against the tree the last one left, can see that.
    const doc = { fields: { body: [container('c1', container('c2', container('c3')))] } }
    const first = { position: { parent: 'c3', slot: 'content', index: 0 }, block: container('a') }
    expect(checkInsertSequence(doc, [first], ctx)).toEqual({ ok: true })
    // A leaf inside it sits at five: legal.
    expect(
      checkInsertSequence(
        doc,
        [first, { position: { parent: 'a', slot: 'content', index: 0 }, block: heading('h') }],
        ctx,
      ),
    ).toEqual({ ok: true })

    const verdict = checkInsertSequence(
      doc,
      [first, { position: { parent: 'a', slot: 'content', index: 0 }, block: container('b') }],
      ctx,
    )
    expect(verdict.ok ? '' : verdict.reason).toBe('depth')
  })

  it('refuses a sequence whose destination never appears', () => {
    const doc = { fields: { body: [container('c1')] } }
    const verdict = checkInsertSequence(
      doc,
      [{ position: { parent: 'never', slot: 'content', index: 0 }, block: heading('h') }],
      ctx,
    )
    expect(verdict.ok ? '' : verdict.reason).toBe('no-slot')
  })
})

describe('legality messages', () => {
  it('an illegal verdict carries a message a person can act on', () => {
    const ctx: LegalityContext = {
      regionsOf: (slug) => (slug === 'section' ? ['content'] : []),
      blockTypes: () => [
        { slug: 'section', label: 'Section', slots: { content: { blockTypes: [] } } },
      ],
      rootSlots: () => ({ body: { blockTypes: [] } }),
      maxDepth: 5,
    }
    const doc = {
      fields: { body: [{ id: 'a', type: 'section', data: { content: [] }, settings: {} }] },
    }
    const verdict = checkMoves(
      doc,
      [{ block: 'a', to: { parent: 'a', slot: 'content', index: 0 } }],
      ctx,
    )
    expect(verdict).toEqual({ ok: false, reason: 'cycle', message: 'A block cannot hold itself' })
  })
})

// Type layouts plan B (spec §5.6): a surface's loops declare their card. A block a card holds goes
// inside that card only; inside a card, no page-level block of the surface — a loop, a surface
// block that is not the card's — goes. General content blocks go anywhere.
describe('card rules', () => {
  const block = (id: string, type: string, data: Record<string, unknown> = {}): BlockInstance => ({
    id,
    type,
    data,
    settings: {},
  })
  const loop = (id: string, ...card: BlockInstance[]) => block(id, 'entry_loop', { card })

  function cardContext(withRules = true): LegalityContext {
    const types: SlotTypeSummary[] = [
      { slug: 'container', label: 'Container', slots: { content: { blockTypes: [] } } },
      { slug: 'entry_loop', label: 'Entry list', slots: { card: { blockTypes: [] } } },
      { slug: 'entry_title', label: 'Entry title', slots: {} },
      { slug: 'pagination', label: 'Page navigation', slots: {} },
      { slug: 'heading', label: 'Heading', slots: {} },
    ]
    return {
      regionsOf: (slug) => Object.keys(types.find((t) => t.slug === slug)?.slots ?? {}),
      blockTypes: () => types,
      rootSlots: () => ({ blocks: { blockTypes: [] } }),
      maxDepth: 5,
      ...(withRules
        ? {
            cards: {
              loops: [{ type: 'entry_loop', card: 'card', items: ['entry_title'] }],
              palette: ['entry_loop', 'pagination', 'entry_title'],
            },
          }
        : {}),
    }
  }
  const doc = () => ({
    fields: { blocks: [loop('loop1', block('box1', 'container', { content: [] }))] },
  })
  const root = { parent: null, slot: 'blocks', index: 0 }
  const card = { parent: 'loop1', slot: 'card', index: 0 }
  const inBox = { parent: 'box1', slot: 'content', index: 0 }

  it('a card block goes inside its loop card only', () => {
    const ctx = cardContext()
    expect(checkInsertSubtree(doc(), root, block('t1', 'entry_title'), ctx)).toEqual({
      ok: false,
      reason: 'item-outside-card',
      message: "Entry title goes inside the Entry list's card",
    })
    expect(checkInsertSubtree(doc(), card, block('t1', 'entry_title'), ctx)).toEqual({ ok: true })
    expect(checkInsertSubtree(doc(), inBox, block('t1', 'entry_title'), ctx)).toEqual({
      ok: true,
    })
  })

  it('a page-level block never goes inside a card', () => {
    const ctx = cardContext()
    for (const [type, label] of [
      ['pagination', 'Page navigation'],
      ['entry_loop', 'Entry list'],
    ]) {
      for (const at of [card, inBox]) {
        expect(checkInsertSubtree(doc(), at, block('p1', type!), ctx)).toEqual({
          ok: false,
          reason: 'not-in-card',
          message: `${label} can't go inside a card`,
        })
      }
    }
    expect(checkInsertSubtree(doc(), card, block('h1', 'heading'), ctx)).toEqual({ ok: true })
    expect(checkInsertSubtree(doc(), root, block('p1', 'pagination'), ctx)).toEqual({ ok: true })
  })

  it('a pasted container holding a card block is refused at the root', () => {
    const ctx = cardContext()
    const pasted = block('c9', 'container', { content: [block('t9', 'entry_title')] })
    expect(checkInsert(doc(), root, block('c8', 'container', { content: [] }), ctx).ok).toBe(true)
    const verdict = checkInsertSubtree(doc(), root, pasted, ctx)
    expect(verdict.ok ? '' : verdict.reason).toBe('item-outside-card')
    expect(checkInsertSubtree(doc(), card, pasted, ctx)).toEqual({ ok: true })
  })

  it('moving a card block out of its card is refused', () => {
    const ctx = cardContext()
    const withTitle = {
      fields: { blocks: [loop('loop1', block('t1', 'entry_title'))] },
    }
    const verdict = checkMoves(withTitle, [{ block: 't1', to: root }], ctx)
    expect(verdict.ok ? '' : verdict.reason).toBe('item-outside-card')
  })

  it('knows whether a block sits inside a card, however deep', () => {
    const rules = cardContext().cards!
    const inner = block('t1', 'entry_title')
    const nested = block('c2', 'container', { content: [block('t2', 'entry_title')] })
    const tree: EditorDocument = {
      fields: { blocks: [loop('l1', inner, nested), block('h1', 'heading')] },
    }
    expect(isInsideCard(tree, 't1', rules)).toBe(true)
    expect(isInsideCard(tree, 't2', rules)).toBe(true)
    expect(isInsideCard(tree, 'l1', rules)).toBe(false)
    expect(isInsideCard(tree, 'h1', rules)).toBe(false)
    expect(isInsideCard(tree, 't1', null)).toBe(false)
  })

  it('without card rules nothing changes', () => {
    const ctx = cardContext(false)
    expect(checkInsertSubtree(doc(), root, block('t1', 'entry_title'), ctx)).toEqual({ ok: true })
    expect(checkInsertSubtree(doc(), card, block('p1', 'pagination'), ctx)).toEqual({ ok: true })
  })
})
