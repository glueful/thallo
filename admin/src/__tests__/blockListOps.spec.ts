import { describe, it, expect } from 'vitest'
import {
  createBlockListOps,
  newBlockId,
  isEmptyHtml,
  type BlockInstance,
} from '@/fields/components/blocks/useBlockListOps'
import {
  isProseBlockType,
  defaultProseType,
  proseRichFieldName,
} from '@/fields/components/blocks/proseDetection'
import { MAX_BLOCK_DEPTH, type BlockType } from '@/queries/blockTypes'

// `nest` has one region `inner`; `card` is a leaf.
const ops = createBlockListOps((slug) => (slug === 'nest' ? ['inner'] : []))

const leaf = (id: string): BlockInstance => ({ id, type: 'card', data: { title: id } })
const nest = (id: string, inner: BlockInstance[]): BlockInstance => ({
  id,
  type: 'nest',
  data: { inner },
})

describe('useBlockListOps', () => {
  it('the cap the depth math uses is the three-surface constant', () => {
    expect(MAX_BLOCK_DEPTH).toBe(3)
  })

  it('insertAt / removeById / moveById keep current semantics', () => {
    let tree = [leaf('a'), leaf('b')]
    tree = ops.insertAt(tree, { parentId: null, region: null, index: 1 }, leaf('x'))
    expect(tree.map((b) => b.id)).toEqual(['a', 'x', 'b'])
    tree = ops.moveById(tree, 'x', -1)
    expect(tree.map((b) => b.id)).toEqual(['x', 'a', 'b'])
    tree = ops.moveById(tree, 'x', -1) // clamped at the top
    expect(tree.map((b) => b.id)).toEqual(['x', 'a', 'b'])
    tree = ops.removeById(tree, 'a')
    expect(tree.map((b) => b.id)).toEqual(['x', 'b'])
  })

  it('insertAt / patchDataById address NESTED regions by parent id', () => {
    let tree = [nest('n', [leaf('a')])]
    tree = ops.insertAt(tree, { parentId: 'n', region: 'inner', index: 0 }, leaf('x'))
    expect((tree[0]!.data.inner as BlockInstance[]).map((b) => b.id)).toEqual(['x', 'a'])
    const before = tree
    tree = ops.patchDataById(tree, 'x', 'title', 'patched')
    expect((tree[0]!.data.inner as BlockInstance[])[0]!.data.title).toBe('patched')
    // Purity: the prior tree object was not mutated.
    expect((before[0]!.data.inner as BlockInstance[])[0]!.data.title).toBe('x')
  })

  it('duplicateById DEEP-copies and re-ids every nested block (the aliasing fix)', () => {
    const tree = [nest('n', [leaf('a')])]
    const out = ops.duplicateById(tree, 'n')
    expect(out).toHaveLength(2)
    const copy = out[1]!
    expect(copy.id).not.toBe('n')
    const copiedInner = copy.data.inner as BlockInstance[]
    expect(copiedInner[0]!.id).not.toBe('a')
    // No shared references with the original subtree.
    copiedInner[0]!.data.title = 'mutated'
    expect((out[0]!.data.inner as BlockInstance[])[0]!.data.title).toBe('a')
  })

  it('moveAcross moves a block between container regions preserving id and order', () => {
    let tree = [nest('n1', [leaf('a')]), nest('n2', [leaf('b')])]
    tree = ops.moveAcross(tree, 'a', { parentId: 'n2', region: 'inner', index: 1 })
    expect((tree[0]!.data.inner as BlockInstance[]).length).toBe(0)
    expect((tree[1]!.data.inner as BlockInstance[]).map((b) => b.id)).toEqual(['b', 'a'])
  })

  it('canDropAt enforces targetDepth + subtreeDepth - 1 <= MAX (spec §2)', () => {
    const twoHigh = nest('drag', [leaf('inner1')])
    const deep = [nest('d1', [nest('d2', [])]), twoHigh]
    // Dropping the 2-high subtree into d2's region: 3 + 2 - 1 = 4 > MAX(3) -> false
    expect(ops.canDropAt(deep, 'drag', { parentId: 'd2', region: 'inner' })).toBe(false)
    // A LEAF into d2's region: 3 + 1 - 1 = 3 <= 3 -> true
    const withLeaf = [...deep, leaf('leafy')]
    expect(ops.canDropAt(withLeaf, 'leafy', { parentId: 'd2', region: 'inner' })).toBe(true)
    // Root drop always fine: 1 + 2 - 1 = 2 <= 3
    expect(ops.canDropAt(deep, 'drag', { parentId: null, region: null })).toBe(true)
    expect(ops.subtreeDepth(twoHigh)).toBe(2)
    expect(ops.depthOf(deep, 'd2')).toBe(2)
    // Drop into its OWN descendant: forbidden regardless of depth.
    expect(ops.canDropAt(deep, 'drag', { parentId: 'inner1', region: 'inner' })).toBe(false)
    expect(ops.canDropAt(deep, 'drag', { parentId: 'drag', region: 'inner' })).toBe(false)
    // Missing target parent: rejected, never mistaken for root depth.
    expect(ops.canDropAt(deep, 'drag', { parentId: 'ghost', region: 'inner' })).toBe(false)
  })

  it('splitRichTextAt applies the four identity rules in ONE emission (spec §3)', () => {
    const prose = { id: 'p1', type: 'rich_text', data: { body: '<p>full</p>' } }
    const widget = () => ({ id: newBlockId(), type: 'hero', data: {} })

    // Both halves non-empty: before KEEPS p1's id; widget + after fresh.
    let out = ops.splitRichTextAt([prose], 'p1', 'body', '<p>before</p>', '<p>after</p>', widget())
    expect(out).toHaveLength(3)
    expect(out[0]!.id).toBe('p1')
    expect(out[0]!.data.body).toBe('<p>before</p>')
    expect(out[1]!.type).toBe('hero')
    expect(out[2]!.type).toBe('rich_text')
    expect(out[2]!.id).not.toBe('p1')
    expect(out[2]!.data.body).toBe('<p>after</p>')

    // Empty before: original removed, widget takes its position.
    out = ops.splitRichTextAt([prose], 'p1', 'body', '', '<p>after</p>', widget())
    expect(out).toHaveLength(2)
    expect(out[0]!.type).toBe('hero')
    expect(out.some((b) => b.id === 'p1')).toBe(false)

    // Empty after: no trailing rich_text.
    out = ops.splitRichTextAt([prose], 'p1', 'body', '<p>before</p>', '', widget())
    expect(out).toHaveLength(2)
    expect(out[1]!.type).toBe('hero')

    // "Empty" includes empty-paragraph HTML.
    out = ops.splitRichTextAt([prose], 'p1', 'body', '<p></p>', '<p></p>', widget())
    expect(out).toHaveLength(1)
    expect(out[0]!.type).toBe('hero')
    expect(isEmptyHtml('<p></p>')).toBe(true)
    expect(isEmptyHtml('<p>x</p>')).toBe(false)
  })
})

describe('proseDetection', () => {
  const t = (slug: string, schema: unknown[]): BlockType =>
    ({
      uuid: slug,
      slug,
      label: slug,
      icon: null,
      category: null,
      description: null,
      active: true,
      schema,
    }) as BlockType
  const richOnly = t('rich_text', [{ name: 'body', type: 'text', format: 'rich' }])
  const customProse = t('note', [{ name: 'content', type: 'text', format: 'rich' }])
  const widget = t('hero', [
    { name: 'heading', type: 'string' },
    { name: 'body', type: 'text', format: 'rich' },
  ])

  it('detects exactly-one-rich-text schemas (the CONVENTION, spec §3)', () => {
    expect(isProseBlockType(richOnly)).toBe(true)
    expect(isProseBlockType(customProse)).toBe(true)
    expect(isProseBlockType(widget)).toBe(false)
    expect(proseRichFieldName(richOnly)).toBe('body')
    expect(proseRichFieldName(customProse)).toBe('content')
    expect(proseRichFieldName(widget)).toBeNull()
  })

  it('defaultProseType: rich_text first, then first allowed prose type, else null', () => {
    expect(defaultProseType([customProse, richOnly, widget], [])?.slug).toBe('rich_text')
    expect(defaultProseType([customProse, widget], [])?.slug).toBe('note')
    expect(defaultProseType([customProse, richOnly], ['note'])?.slug).toBe('note')
    expect(defaultProseType([widget], [])).toBeNull()
    const inactive = { ...richOnly, active: false }
    expect(defaultProseType([inactive], [])).toBeNull()
  })
})
