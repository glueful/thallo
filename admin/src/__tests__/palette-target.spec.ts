import { describe, it, expect } from 'vitest'
import { resolveTarget, tilePreflight } from '@/editor/palette/target'
import type { LegalityContext, SlotTypeSummary } from '@/editor/structure/legality'
import type { EditorDocument } from '@/editor/ops/types'

const b = (id: string, type: string, data: Record<string, unknown> = {}) => ({
  id,
  type,
  data,
  settings: {},
})
const summaries: SlotTypeSummary[] = [
  { slug: 'section', label: 'Section', slots: { content: { blockTypes: [] } } },
  { slug: 'container', label: 'Container', slots: { content: { blockTypes: [] } } },
  { slug: 'heading', label: 'Heading', slots: {} },
  { slug: 'button', label: 'Button', slots: {} },
]
const ctx: LegalityContext = {
  regionsOf: (slug) => (slug === 'section' || slug === 'container' ? ['content'] : []),
  blockTypes: () => summaries,
  rootSlots: () => ({ body: { blockTypes: [] }, aside: { blockTypes: ['button'] } }),
  maxDepth: 5,
}
const doc: EditorDocument = {
  fields: {
    body: [
      b('h1', 'heading'),
      b('s1', 'section', { content: [b('h2', 'heading'), b('h3', 'heading')] }),
      b('e1', 'section', { content: [] }),
    ],
    aside: [],
  },
}

describe('insertion targets resolve at the moment of use (Phase C.1)', () => {
  it('after: a root block, a nested block in its own sibling list, the last block of a slot; gone → null', () => {
    expect(resolveTarget({ kind: 'after', block: 'h1' }, doc, ctx, 0)).toEqual({
      position: { parent: null, slot: 'body', index: 1 },
      label: 'after Heading',
    })
    expect(resolveTarget({ kind: 'after', block: 'h2' }, doc, ctx, 0)).toEqual({
      position: { parent: 's1', slot: 'content', index: 1 },
      label: 'after Heading',
    })
    expect(resolveTarget({ kind: 'after', block: 'h3' }, doc, ctx, 0)?.position).toEqual({
      parent: 's1',
      slot: 'content',
      index: 2,
    })
    expect(resolveTarget({ kind: 'after', block: 'gone' }, doc, ctx, 0)).toBeNull()
  })

  it('into: an empty slot → 0, a non-empty slot → its length, a root field → its length; gone parent → null', () => {
    expect(resolveTarget({ kind: 'into', parent: 'e1', field: 'content' }, doc, ctx, 0)).toEqual({
      position: { parent: 'e1', slot: 'content', index: 0 },
      label: 'into Section › content',
    })
    expect(
      resolveTarget({ kind: 'into', parent: 's1', field: 'content' }, doc, ctx, 0)?.position,
    ).toEqual({
      parent: 's1',
      slot: 'content',
      index: 2,
    })
    expect(resolveTarget({ kind: 'into', parent: null, field: 'body' }, doc, ctx, 0)).toEqual({
      position: { parent: null, slot: 'body', index: 3 },
      label: 'at the end of body',
    })
    expect(
      resolveTarget({ kind: 'into', parent: 'gone', field: 'content' }, doc, ctx, 0),
    ).toBeNull()
  })

  it('at: the armed position while the history sequence is unchanged; null after a structural change', () => {
    const at = {
      kind: 'at' as const,
      position: { parent: null, slot: 'body', index: 1 },
      sequence: 4,
    }
    expect(resolveTarget(at, doc, ctx, 4)).toEqual({
      position: { parent: null, slot: 'body', index: 1 },
      label: 'at position 2 of body',
    })
    expect(resolveTarget(at, doc, ctx, 5)).toBeNull()
  })

  it('tile preflight is exact for the allow-list and OPTIMISTIC for depth when a starter nests', () => {
    const asideEnd = { parent: null, slot: 'aside', index: 0 }
    expect(tilePreflight('heading', asideEnd, doc, ctx).ok).toBe(false)
    expect(tilePreflight('button', asideEnd, doc, ctx).ok).toBe(true)
    // Four levels down, one level remains: a height-one placeholder fits, though a section whose
    // starter nests three levels would not — the real instance is judged again at commit.
    const deep: EditorDocument = {
      fields: {
        body: [
          b('s', 'section', {
            content: [
              b('c1', 'container', {
                content: [
                  b('c2', 'container', { content: [b('c3', 'container', { content: [] })] }),
                ],
              }),
            ],
          }),
        ],
      },
    }
    expect(
      tilePreflight('section', { parent: 'c3', slot: 'content', index: 0 }, deep, ctx).ok,
    ).toBe(true)
    expect(
      tilePreflight('section', { parent: 'c2', slot: 'content', index: 0 }, deep, ctx).ok,
    ).toBe(true)
  })
})
