# BlocksField Notion-like UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the admin block editor Notion-like (insert dividers, searchable picker, cross-container drag, keyboard movement, chromeless prose, tail prose, slash-to-widget) as a pure SPA layer — the stored `{id,type,data}` model and every backend contract untouched.

**Architecture:** The root `BlocksField` owns the WHOLE tree and provides id-addressed pure operations (`useBlockListOps`) via provide/inject; `BlockList` renders any list level (root or container region) with dividers + drag; `BlockCard` renders one block and recurses through `BlockList` for container regions (replacing today's nested-`BlocksField`-via-registry recursion, which trapped ops at each level). TipTap/UEditor stays bounded inside `ProseBlockEditor`: its only structural output is an `insert-block` event carrying `{slug, beforeHtml, afterHtml}`.

**Tech Stack:** Vue 3, Nuxt UI 4.9 (`UEditor`, `UEditorToolbar`, `UEditorSuggestionMenu`), `vue-draggable-plus` (installed), vitest + @vue/test-utils (jsdom). Spec: `docs/superpowers/specs/2026-07-03-blocksfield-notion-ux-design.md`.

## Global Constraints

- **Commit gate:** STAGE at the end (Task 6); commit ONLY on explicit authorization. No Claude/Anthropic attribution anywhere.
- **No new dependencies** (spec §6). No backend changes; PHP suites untouched.
- **Depth guard (spec §2, verbatim):** `targetDepth + draggedSubtreeDepth - 1 <= MAX_BLOCK_DEPTH`, computed in `canDropAt()` BEFORE mutating.
- **Split identity rules (spec §3):** before-half keeps the original id when non-empty; widget + after get fresh ids; empty before → original removed; empty after → no trailing block; ONE model emission per split.
- **Prose detection (spec §3):** convention — exactly one `text` field with `format: 'rich'`; single exported predicate `isProseBlockType()`; explicitly NOT a durable identity contract (`editor_mode` override reserved).
- **Tail prose selection (spec §3):** allowed active `rich_text` → first allowed active prose-detected type → hidden.
- **TipTap bounded (spec §3):** UEditor never controls block order/ids/tree; no ProseMirror types above the prose component.
- **Testing rules (recorded harness):** assert `data-test`/`data-testid` hooks, never Nuxt UI internals or portal DOM; no sortable simulation in jsdom — drag logic tested at ops level; UButton onClick handlers are void methods.
- Preserved hooks: `blocks-field`, `block-card-{id}`, `block-toggle-{id}`, `block-duplicate-{id}`, `block-delete-{id}`, `block-delete-confirm`, `add-block`, `block-picker`, `picker-item-{slug}`, `picker-group-{cat}`, `max-depth-notice`, `block-inactive-{id}`. New hooks: `block-insert-{index}` (per list, index-suffixed), `block-drag-{id}`, `block-outline`, `block-outline-item-{id}`, `prose-block-{id}`, `tail-prose`.
- `pnpm type-check` and `pnpm test` must exit 0 (never pipe tsc through tail).

## File Structure

- Create: `admin/src/fields/components/blocks/useBlockListOps.ts` — pure tree ops (id-addressed).
- Create: `admin/src/fields/components/blocks/proseDetection.ts` — `isProseBlockType`, `defaultProseType`.
- Create: `admin/src/fields/components/blocks/BlockList.vue` — one list level: draggable wrapper, dividers, cards.
- Create: `admin/src/fields/components/blocks/BlockCard.vue` — one block: header/actions/body/regions.
- Create: `admin/src/fields/components/blocks/BlockInsertMenu.vue` — searchable type picker popover.
- Create: `admin/src/fields/components/blocks/BlockOutlineRail.vue` — collapsible tree.
- Create: `admin/src/fields/components/blocks/ProseBlockEditor.vue` — chromeless UEditor + bubble toolbar + suggestion menu + split emit.
- Create: `admin/src/fields/components/blocks/context.ts` — the provide/inject key + context type.
- Create: `admin/src/components/richTextToolbar.ts` — `turnInto`/`bubbleItems` extracted from `RichText.vue` (one source for RichText + ProseBlockEditor).
- Rewrite: `admin/src/fields/components/BlocksField.vue` — thin shell (UFormField, ops provider, root BlockList, tail prose, outline toggle). Public contract (props `field`, `depth`; `defineModel<BlockInstance[]>`) unchanged — the registry entry keeps working.
- Modify: `admin/src/components/RichText.vue` (import the extracted toolbar items).
- Tests: `admin/src/__tests__/blockListOps.spec.ts` (new), `admin/src/__tests__/block-notion-ux.spec.ts` (new), `admin/src/__tests__/blocksField.spec.ts` (updated hooks only).

---

### Task 1: `useBlockListOps` + `proseDetection` (pure units)

**Files:**
- Create: `admin/src/fields/components/blocks/useBlockListOps.ts`, `admin/src/fields/components/blocks/proseDetection.ts`
- Test: `admin/src/__tests__/blockListOps.spec.ts`

**Interfaces:**
- Produces (all PURE — tree in, new tree out; blocks addressed by id, unique across the tree):

```ts
export interface BlockInstance { id: string; type: string; data: Record<string, unknown> }
export type RegionResolver = (typeSlug: string) => string[] // blocks-field names of a block schema

export function newBlockId(): string
export function createBlockListOps(regionsOf: RegionResolver): {
  findById(tree: BlockInstance[], id: string): BlockInstance | null
  insertAt(tree: BlockInstance[], target: { parentId: string | null; region: string | null; index: number }, block: BlockInstance): BlockInstance[]
  removeById(tree: BlockInstance[], id: string): BlockInstance[]
  duplicateById(tree: BlockInstance[], id: string): BlockInstance[] // DEEP copy, re-ids every nested block
  patchDataById(tree: BlockInstance[], id: string, name: string, value: unknown): BlockInstance[]
  moveById(tree: BlockInstance[], id: string, delta: number): BlockInstance[] // within its own list
  moveAcross(tree: BlockInstance[], id: string, target: { parentId: string | null; region: string | null; index: number }): BlockInstance[]
  subtreeDepth(block: BlockInstance): number // leaf = 1
  depthOf(tree: BlockInstance[], id: string): number // root list = 1
  canDropAt(tree: BlockInstance[], dragId: string, target: { parentId: string | null; region: string | null }): boolean
  splitRichTextAt(tree: BlockInstance[], id: string, richFieldName: string, beforeHtml: string, afterHtml: string, newBlock: BlockInstance): BlockInstance[]
}
```

- `proseDetection.ts`:

```ts
export function isProseBlockType(type: Pick<BlockType, 'schema'>): boolean
export function defaultProseType(types: BlockType[], allowlist: string[]): BlockType | null
export function proseRichFieldName(type: Pick<BlockType, 'schema'>): string | null
```

- [ ] **Step 1: Failing tests** — `admin/src/__tests__/blockListOps.spec.ts`:

```ts
import { describe, it, expect } from 'vitest'
import { createBlockListOps, newBlockId, type BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { isProseBlockType, defaultProseType, proseRichFieldName } from '@/fields/components/blocks/proseDetection'
import { MAX_BLOCK_DEPTH, type BlockType } from '@/queries/blockTypes'

// `nest` has one region `inner`; `card` is a leaf.
const ops = createBlockListOps((slug) => (slug === 'nest' ? ['inner'] : []))

const leaf = (id: string): BlockInstance => ({ id, type: 'card', data: { title: id } })
const nest = (id: string, inner: BlockInstance[]): BlockInstance => ({
  id, type: 'nest', data: { inner },
})

describe('useBlockListOps', () => {
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
    tree = ops.patchDataById(tree, 'x', 'title', 'patched')
    expect((tree[0]!.data.inner as BlockInstance[])[0]!.data.title).toBe('patched')
    // Purity: the original object graph was not mutated.
    expect(tree[0]!.data.inner).not.toBe([])
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
    // subtree of height 2 (nest>leaf); regions at depth 2 host children at depth 2+?
    // depthOf(root list) = 1; a block in root sits at depth 1; its region hosts depth 2.
    const twoHigh = nest('drag', [leaf('inner1')])
    const deep = [nest('d1', [nest('d2', [])]), twoHigh]
    // Dropping the 2-high subtree into d2's region: target depth 3, 3 + 2 - 1 = 4 > MAX(3) -> false
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
  })
})

describe('proseDetection', () => {
  const t = (slug: string, schema: BlockType['schema']): BlockType =>
    ({ uuid: slug, slug, label: slug, icon: null, category: null, description: null, active: true, schema }) as BlockType
  const richOnly = t('rich_text', [{ name: 'body', type: 'text', format: 'rich' } as never])
  const customProse = t('note', [{ name: 'content', type: 'text', format: 'rich' } as never])
  const widget = t('hero', [
    { name: 'heading', type: 'string' } as never,
    { name: 'body', type: 'text', format: 'rich' } as never,
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
    expect(defaultProseType([customProse, richOnly], ['note'])?.slug).toBe('note') // allowlist excludes rich_text
    expect(defaultProseType([widget], [])).toBeNull()
    const inactive = { ...richOnly, active: false }
    expect(defaultProseType([inactive], [])).toBeNull() // inactive never selected
  })
})
```

Also assert (same file) that `MAX_BLOCK_DEPTH` is what the math uses: `expect(MAX_BLOCK_DEPTH).toBe(3)`.

- [ ] **Step 2: Verify fail** — `cd admin && pnpm vitest run src/__tests__/blockListOps.spec.ts` → modules not found.

- [ ] **Step 3: Implement `useBlockListOps.ts`:**

```ts
// Pure, id-addressed operations over a blocks tree ({id,type,data} lists; nested
// container regions live at data[<blocks-field name>]). Every function returns a
// NEW tree (structural sharing where untouched) — the root BlocksField assigns the
// result to its model in ONE emission, which keeps operations undo-friendly for
// any future history layer. Ids are client nanoids, unique across the tree, so
// id-addressing needs no path bookkeeping.
export interface BlockInstance {
  id: string
  type: string
  data: Record<string, unknown>
}

export type RegionResolver = (typeSlug: string) => string[]

export function newBlockId(): string {
  return Array.from(crypto.getRandomValues(new Uint8Array(12)))
    .map((b) => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36])
    .join('')
}

/** "Empty" prose: no text content once tags are stripped (e.g. '<p></p>'). */
export function isEmptyHtml(html: string): boolean {
  return html.replace(/<[^>]*>/g, '').trim() === ''
}

export function createBlockListOps(regionsOf: RegionResolver) {
  const asList = (value: unknown): BlockInstance[] =>
    Array.isArray(value) ? (value as BlockInstance[]) : []

  /** Map over every list in the tree (root + regions), rebuilding only touched paths. */
  function mapLists(
    list: BlockInstance[],
    fn: (list: BlockInstance[], parentId: string | null, region: string | null) => BlockInstance[],
    parentId: string | null = null,
    region: string | null = null,
  ): BlockInstance[] {
    const mapped = fn(list, parentId, region).map((block) => {
      let data = block.data
      let changed = false
      for (const r of regionsOf(block.type)) {
        const inner = asList(data[r])
        const next = mapLists(inner, fn, block.id, r)
        if (next !== inner) {
          data = { ...data, [r]: next }
          changed = true
        }
      }
      return changed ? { ...block, data } : block
    })
    return mapped
  }

  function findById(tree: BlockInstance[], id: string): BlockInstance | null {
    for (const block of tree) {
      if (block.id === id) return block
      for (const r of regionsOf(block.type)) {
        const hit = findById(asList(block.data[r]), id)
        if (hit) return hit
      }
    }
    return null
  }

  function insertAt(
    tree: BlockInstance[],
    target: { parentId: string | null; region: string | null; index: number },
    block: BlockInstance,
  ): BlockInstance[] {
    return mapLists(tree, (list, parentId, region) => {
      if (parentId !== target.parentId || region !== target.region) return list
      const next = [...list]
      next.splice(Math.max(0, Math.min(target.index, next.length)), 0, block)
      return next
    })
  }

  function removeById(tree: BlockInstance[], id: string): BlockInstance[] {
    return mapLists(tree, (list) =>
      list.some((b) => b.id === id) ? list.filter((b) => b.id !== id) : list,
    )
  }

  function reIdSubtree(block: BlockInstance): BlockInstance {
    const data: Record<string, unknown> = { ...block.data }
    for (const r of regionsOf(block.type)) {
      data[r] = asList(data[r]).map(reIdSubtree)
    }
    // structuredClone the NON-region values too: nested objects/arrays in field
    // data must not alias the original (the shallow-copy bug this replaces).
    for (const [key, value] of Object.entries(data)) {
      if (!regionsOf(block.type).includes(key) && typeof value === 'object' && value !== null) {
        data[key] = structuredClone(value)
      }
    }
    return { id: newBlockId(), type: block.type, data }
  }

  function duplicateById(tree: BlockInstance[], id: string): BlockInstance[] {
    return mapLists(tree, (list) => {
      const index = list.findIndex((b) => b.id === id)
      if (index < 0) return list
      const next = [...list]
      next.splice(index + 1, 0, reIdSubtree(list[index]!))
      return next
    })
  }

  function patchDataById(tree: BlockInstance[], id: string, name: string, value: unknown): BlockInstance[] {
    return mapLists(tree, (list) =>
      list.some((b) => b.id === id)
        ? list.map((b) => (b.id === id ? { ...b, data: { ...b.data, [name]: value } } : b))
        : list,
    )
  }

  function moveById(tree: BlockInstance[], id: string, delta: number): BlockInstance[] {
    return mapLists(tree, (list) => {
      const from = list.findIndex((b) => b.id === id)
      if (from < 0) return list
      const to = from + delta
      if (to < 0 || to >= list.length) return list
      const next = [...list]
      const [item] = next.splice(from, 1)
      next.splice(to, 0, item!)
      return next
    })
  }

  function moveAcross(
    tree: BlockInstance[],
    id: string,
    target: { parentId: string | null; region: string | null; index: number },
  ): BlockInstance[] {
    const block = findById(tree, id)
    if (!block) return tree
    return insertAt(removeById(tree, id), target, block)
  }

  function subtreeDepth(block: BlockInstance): number {
    let deepest = 0
    for (const r of regionsOf(block.type)) {
      for (const child of asList(block.data[r])) {
        deepest = Math.max(deepest, subtreeDepth(child))
      }
    }
    return 1 + deepest
  }

  function depthOf(tree: BlockInstance[], id: string, depth = 1): number {
    for (const block of tree) {
      if (block.id === id) return depth
      for (const r of regionsOf(block.type)) {
        const found = depthOf(asList(block.data[r]), id, depth + 1)
        if (found > 0) return found
      }
    }
    return 0
  }

  /** True when targetId is dragged's OWN id or lives anywhere inside its subtree. */
  function isSelfOrDescendant(dragged: BlockInstance, targetId: string): boolean {
    if (dragged.id === targetId) return true
    for (const r of regionsOf(dragged.type)) {
      for (const child of asList(dragged.data[r])) {
        if (isSelfOrDescendant(child, targetId)) return true
      }
    }
    return false
  }

  /**
   * Spec §2 (pinned): targetDepth + draggedSubtreeDepth - 1 <= MAX_BLOCK_DEPTH.
   * targetDepth = the depth blocks INSIDE the target list sit at (root = 1; a
   * region of a block at depth d hosts depth d + 1). Rejects, IN ORDER:
   * unknown drag id; target parent that is the dragged block or inside its own
   * subtree (a container can never be dropped into itself); target parent
   * missing from the tree (depthOf returns 0 — checked BEFORE the +1 so it can
   * never masquerade as root depth); then the depth formula.
   */
  function canDropAt(
    tree: BlockInstance[],
    dragId: string,
    target: { parentId: string | null; region: string | null },
  ): boolean {
    const dragged = findById(tree, dragId)
    if (!dragged) return false
    let targetDepth = 1
    if (target.parentId !== null) {
      if (isSelfOrDescendant(dragged, target.parentId)) return false
      const parentDepth = depthOf(tree, target.parentId)
      if (parentDepth === 0) return false // missing parent — never depth-1 by accident
      targetDepth = parentDepth + 1
    }
    return targetDepth + subtreeDepth(dragged) - 1 <= MAX_DEPTH
  }

  return {
    findById,
    insertAt,
    removeById,
    duplicateById,
    patchDataById,
    moveById,
    moveAcross,
    subtreeDepth,
    depthOf,
    canDropAt,
    splitRichTextAt,
  }

  function splitRichTextAt(
    tree: BlockInstance[],
    id: string,
    richFieldName: string,
    beforeHtml: string,
    afterHtml: string,
    newBlock: BlockInstance,
  ): BlockInstance[] {
    return mapLists(tree, (list) => {
      const index = list.findIndex((b) => b.id === id)
      if (index < 0) return list
      const original = list[index]!
      const replacement: BlockInstance[] = []
      if (!isEmptyHtml(beforeHtml)) {
        // Before half KEEPS the original identity (spec §3).
        replacement.push({ ...original, data: { ...original.data, [richFieldName]: beforeHtml } })
      }
      replacement.push(newBlock)
      if (!isEmptyHtml(afterHtml)) {
        replacement.push({
          id: newBlockId(),
          type: original.type,
          data: { ...original.data, [richFieldName]: afterHtml },
        })
      }
      const next = [...list]
      next.splice(index, 1, ...replacement)
      return next
    })
  }
}

// The ONE authoritative cap, mirrored from the backend (nesting amendment §A2) —
// imported from the queries module so the existing three-surface assertion holds.
import { MAX_BLOCK_DEPTH as MAX_DEPTH } from '@/queries/blockTypes'
```

(Hoist the import to the top of the file in the real implementation — shown at the bottom here only to keep the reading order; clean up the `canDropAt` own-subtree check to a single loop over `regionsOf(dragged.type)` + the `target.parentId === dragId` case, as the test exercises the depth math, not descent-into-self which containers make possible.)

`proseDetection.ts`:

```ts
import type { BlockType } from '@/queries/blockTypes'

// CONVENTION, not identity (spec §3): a block type whose schema is EXACTLY one
// rich text field renders as chromeless prose. The reserved durable escape hatch
// is block-type metadata (`editor_mode: prose | card`) — when that lands, this
// predicate consults it FIRST and the convention becomes the fallback. No other
// feature may treat this as a stable identity contract.
type SchemaField = { name: string; type: string; format?: string }

export function isProseBlockType(type: Pick<BlockType, 'schema'>): boolean {
  return proseRichFieldName(type) !== null
}

/** The rich field's name when the type is prose-shaped; null otherwise. */
export function proseRichFieldName(type: Pick<BlockType, 'schema'>): string | null {
  const schema = (type.schema ?? []) as SchemaField[]
  if (schema.length !== 1) return null
  const only = schema[0]!
  return only.type === 'text' && only.format === 'rich' ? only.name : null
}

/**
 * Tail-prose default (spec §3): allowed active `rich_text` -> first allowed
 * active prose-detected type -> null (affordance hidden).
 */
export function defaultProseType(types: BlockType[], allowlist: string[]): BlockType | null {
  const allowed = types.filter(
    (t) => t.active && (allowlist.length === 0 || allowlist.includes(t.slug)),
  )
  const richText = allowed.find((t) => t.slug === 'rich_text' && isProseBlockType(t))
  if (richText) return richText
  return allowed.find((t) => isProseBlockType(t)) ?? null
}
```

- [ ] **Step 4: Verify pass** — `pnpm vitest run src/__tests__/blockListOps.spec.ts` all green.

---

### Task 2: `BlockCard` + `BlockList` + shell rewrite (behavior-preserving refactor)

**Files:**
- Create: `admin/src/fields/components/blocks/context.ts`, `BlockList.vue`, `BlockCard.vue`
- Rewrite: `admin/src/fields/components/BlocksField.vue`
- Test: `admin/src/__tests__/blocksField.spec.ts` (must pass with at most hook-preserving tweaks)

**Interfaces:**
- `context.ts` produces the injected context every blocks component consumes:

```ts
import type { InjectionKey, ComputedRef } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { BlockInstance } from './useBlockListOps'

export interface BlocksContext {
  bySlug: ComputedRef<Map<string, BlockType>>
  pickerTypes: ComputedRef<BlockType[]> // active + allowlist-filtered
  allowlist: string[]
  regionsOf: (slug: string) => string[]
  // Every mutation goes through here — ONE model emission per call at the root.
  apply: (fn: (tree: BlockInstance[]) => BlockInstance[]) => void
  ops: ReturnType<typeof import('./useBlockListOps').createBlockListOps>
  expanded: Record<string, boolean>
  selectBlock: (id: string) => void // outline -> scroll + focus (Task 6 consumer)
  maxDepth: number
}
export const BlocksContextKey: InjectionKey<BlocksContext>
```

- `BlockList.vue` props: `{ blocks: BlockInstance[]; parentId: string | null; region: string | null; depth: number }` — renders cards + (Task 3) dividers + (Task 4) draggable. `BlockCard.vue` props: `{ block: BlockInstance; depth: number; parentId: string | null; region: string | null; index: number }` — header chrome exactly as today (icon/label/summary/inactive badge/move/duplicate/delete/confirm), body renders schema fields via `fieldComponent(...)` EXCEPT `blocks`-type fields, which render a nested `<BlockList :parent-id="block.id" :region="f.name" :depth="depth + 1">` (or the `max-depth-notice` at the cap) — container recursion now stays inside the ops-owning tree instead of registry-recursing into a fresh `BlocksField`.
- `BlocksField.vue` keeps `props: { field: FieldDef; depth?: number }` + `defineModel<BlockInstance[]>` and provides the context (`apply` wraps `model.value = fn(model.value ?? [])`). `regionsOf(slug)` derives from `bySlug` schemas (`.filter(f => toFieldDef(f).type === 'blocks').map(f => f.name)`).

- [ ] **Step 1:** Build the three files + rewrite the shell, moving today's template/behavior verbatim onto the new structure (picker stays the bottom "Add block" button + `block-picker` list for THIS task — dividers arrive in Task 3). Keyboard/`summary()`/`newId` move into `BlockCard`/ops as appropriate (`newId` now `newBlockId` from ops).

- [ ] **Step 2: Verify** — `pnpm vitest run src/__tests__/blocksField.spec.ts`: the existing spec must pass. Where a hook moved element (e.g. the nested add-button ordering the suite already pins), update the SPEC minimally and note it. Then `pnpm type-check`.

---

### Task 3: Insert menu, dividers, keyboard

**Files:**
- Create: `admin/src/fields/components/blocks/BlockInsertMenu.vue`
- Modify: `BlockList.vue` (dividers), `BlockCard.vue` (keyboard), `BlocksField.vue` (menu state)
- Test: `admin/src/__tests__/block-notion-ux.spec.ts` (new; harness mirrors `blocksField.spec.ts` — mocked `useBlockTypes` ref, real components)

**Interfaces:**
- `BlockInsertMenu.vue` props `{ open: boolean }`, emits `select(type: BlockType)` and `close` — renders the category-grouped, TYPE-TO-FILTER list: a text input (`data-test="block-picker-filter"`, autofocused) filtering by label/slug/description, then the existing group/item markup (`picker-group-{cat}` / `picker-item-{slug}` hooks preserved).
- `BlockList.vue` renders `data-test="block-insert-{index}"` hover-revealed dividers at every gap (0..length) opening the menu anchored at that index; selection calls `ctx.apply(t => ctx.ops.insertAt(t, {parentId, region, index}, {id: newBlockId(), type: slug, data: {}}))` and expands the new block.
- `BlockCard` keyboard on the header (`tabindex="0"`, `@keydown`): `⌘/Alt+ArrowUp/Down` → `moveById`, `⌘d` → `duplicateById`, `Delete`/`Backspace` → open the existing confirm, `Enter` → toggle expand, `/` → open the insert menu at `index + 1`. All `preventDefault`ed when handled; plain typing in nested inputs is unaffected (handler lives on the header element only).

- [ ] **Step 1: Failing tests** (`block-notion-ux.spec.ts`):

```ts
it('renders insert dividers and inserts at the clicked gap', async () => {
  // two leaf blocks seeded -> dividers at 0,1,2
  const wrapper = mountBlocksField(twoBlocks())
  await flushPromises()
  expect(wrapper.find('[data-test="block-insert-0"]').exists()).toBe(true)
  expect(wrapper.find('[data-test="block-insert-2"]').exists()).toBe(true)
  await wrapper.find('[data-test="block-insert-1"]').trigger('click')
  await wrapper.find('[data-test="block-picker-filter"]').setValue('hero')
  await wrapper.find('[data-test="picker-item-hero"]').trigger('click')
  expect(model.value.map((b) => b.type)).toEqual(['quote', 'hero', 'quote'])
})

it('keyboard: meta+arrow moves, meta+d duplicates, slash opens the menu', async () => {
  const wrapper = mountBlocksField(twoBlocks())
  await flushPromises()
  const header = wrapper.find(`[data-test="block-toggle-${model.value[1]!.id}"]`)
  await header.trigger('keydown', { key: 'ArrowUp', metaKey: true })
  expect(model.value.map((b) => b.id)).toEqual([id2, id1])
  await header.trigger('keydown', { key: 'd', metaKey: true })
  expect(model.value).toHaveLength(3)
  await header.trigger('keydown', { key: '/' })
  expect(wrapper.find('[data-test="block-picker"]').exists()).toBe(true)
})

it('filter narrows picker items', async () => {
  const wrapper = mountBlocksField([])
  await flushPromises()
  await wrapper.find('[data-test="add-block"]').trigger('click')
  await wrapper.find('[data-test="block-picker-filter"]').setValue('quo')
  expect(wrapper.find('[data-test="picker-item-quote"]').exists()).toBe(true)
  expect(wrapper.find('[data-test="picker-item-hero"]').exists()).toBe(false)
})
```

(Write `mountBlocksField`/`twoBlocks` helpers mirroring `blocksField.spec.ts`'s existing mock/mount pattern — same `useBlockTypes` ref mock, same default types plus `nest`.)

- [ ] **Step 2–4:** Verify fail → implement → `pnpm vitest run src/__tests__/block-notion-ux.spec.ts src/__tests__/blocksField.spec.ts` green; type-check.

---

### Task 4: Drag (vue-draggable-plus) + depth-guarded drops

**Files:**
- Modify: `BlockList.vue`, `BlockCard.vue` (handle), `BlocksField.vue` (notice state)
- Test: extend `block-notion-ux.spec.ts` (ops-level + render-level only — NO sortable simulation)

**Interfaces:** `BlockList` wraps its cards in `<VueDraggable v-model="localList" :group="{ name: dragGroup }" handle="[data-test^='block-drag-']" @end="onDragEnd">` where `dragGroup` is one shared name per BlocksField instance (provide/inject the field-scoped group id). `BlockCard` renders the handle `data-test="block-drag-{id}"` (grip icon, appears on hover/focus like the rest of the chrome).

**Target identity comes from the EVENT, not the component (P1 pin).** For a
cross-container drop, `@end` fires with `event.to` being the DESTINATION list's
element — which may not be the component instance handling the event (the repo's
only existing usage, `CollectionEditSlideover.vue:311`, is flat and proves
nothing about nesting). Therefore:

- every `BlockList` root element carries its identity as data attributes:
  `data-list-parent="{parentId ?? ''}"` and `data-list-region="{region ?? ''}"`;
  every card element carries `data-block-id="{id}"`.
- one shared handler (root-provided via context, so ONE code path):

```ts
function onDragEnd(event: { item: HTMLElement; to: HTMLElement; from: HTMLElement; newIndex?: number }) {
  const dragId = event.item.dataset.blockId ?? ''
  const parentId = event.to.dataset.listParent || null
  const region = event.to.dataset.listRegion || null
  const index = event.newIndex ?? 0
  if (dragId === '' || !ctx.ops.canDropAt(modelTree(), dragId, { parentId, region })) {
    rejectDrop() // transient data-test="drop-rejected" notice (~3s) + local revert
    return
  }
  ctx.apply((t) => ctx.ops.moveAcross(t, dragId, { parentId, region, index }))
}
```

  Sortable mutates only a LOCAL copy (`localList` is a shallow mirror recomputed
  from props); commit or revert always flows through the ops layer — the
  draggable binding never writes the model (the recorded thin-binding rule).

- [ ] **Steps:** failing tests — (a) render: every card exposes `block-drag-{id}`, every list root exposes `data-list-parent`/`data-list-region`; (b) **direct handler test with FAKE event objects** (no Sortable simulation): build the mounted component, call the exposed/injected `onDragEnd` with synthetic `{item, to, from, newIndex}` elements whose datasets describe a valid nested target → model reflects `moveAcross`; repeat with a depth-violating target → model unchanged + `drop-rejected` rendered. Then implement → suite + type-check green. Cross-container correctness itself is already pinned by Task 1's `moveAcross`/`canDropAt` unit tests.

---

### Task 5: Prose seam — chromeless prose, tail prose, slash-to-widget

**Files:**
- Create: `admin/src/fields/components/blocks/ProseBlockEditor.vue`, `admin/src/components/richTextToolbar.ts`
- Modify: `admin/src/components/RichText.vue` (import shared items), `BlockCard.vue` (prose rendering path), `BlockList.vue`/`BlocksField.vue` (tail prose)
- Test: extend `block-notion-ux.spec.ts`

**Interfaces:**
- `richTextToolbar.ts` exports `turnInto` and `bubbleItems` — moved VERBATIM from `RichText.vue` (which now imports them; `toolbarItems` stays local since only RichText uses the fixed bar). Type them `satisfies` exactly as today.
- `ProseBlockEditor.vue`:

```
props:  { modelValue: string; placeholder?: string }
emits:  'update:modelValue'(html: string)
        'insert-block'(payload: { slug: string; beforeHtml: string; afterHtml: string })
```

  Renders `UEditor` (content-type html, `:ui` tuned chromeless: no min-height block, no border), the shared `bubbleItems` bubble `UEditorToolbar`, and a `UEditorSuggestionMenu` whose items are: a "Text" label + Nuxt UI text-construct kinds (heading 1–3, bulletList, orderedList, blockquote) **plus** a "Blocks" label + one item per `ctx.pickerTypes` with `kind: 'lemmaBlock'` and `slug` carried on the item. The custom handler goes on UEditor:

  The handler is registered as `handlers = { lemmaBlock: { canExecute: () => true, isActive: () => false, execute: (editor, item) => { emitSplit(editor, item.slug); return editor.chain() } } } satisfies EditorCustomHandlers`, with the `Editor` type derived the way `RichText.vue:27` does (`Parameters<EditorCustomHandlers[string]['execute']>[0]`).

  **`emitSplit` is an IMPLEMENTATION step, not plan-level code (P2 pin).** The
  constraint that holds: PUBLIC editor commands only — no `@tiptap/pm` import (it
  is not a direct dependency). Two facts are already verified: Nuxt UI's
  `EditorSuggestionMenu.vue:49` DELETES the slash query range before executing
  the handler (no trigger-text cleanup needed), and `getHTML`/`setContent`/
  `deleteRange` are public. What must be worked out AGAINST THE RUNNING EDITOR —
  ProseMirror document positions are not string offsets, and naive
  `deleteRange({from: 0, to: pos})` may land inside node boundaries: capture the
  cursor position AFTER the suggestion cleanup, derive valid before/after ranges
  (e.g. via `editor.state.selection` + `doc.resolve` boundaries reached through
  the editor instance, or snapshot/restore with `setContent` between two
  `deleteRange` calls whose bounds are clamped to valid positions), and produce
  `{beforeHtml, afterHtml}` where before ends at the cursor's paragraph split.
  Acceptance for this step is MANUAL/BROWSER verification (`pnpm dev` against a
  page with a prose block): `/` mid-paragraph → pick a widget → text before the
  cursor stays in the original block, text after lands in the new trailing
  block, no duplicated or lost characters, empty halves produce no empty prose
  blocks (the ops layer drops them). jsdom does not prove cursor splitting and
  the component tests never pretend it does — they stub `ProseBlockEditor` and
  drive the `insert-block` EVENT (the tree-side identity rules are Task 1 unit
  tests).
- `BlockCard`: when `isProseBlockType(bySlug.get(block.type))`, the card renders the PROSE path — no border/box, no expand toggle (always "expanded"), `data-test="prose-block-{id}"` wrapper, hover/focus-revealed action cluster (drag handle, duplicate, delete) — body is `<ProseBlockEditor :model-value="block.data[richField]" @update:model-value="patch" @insert-block="onInsertBlock" />`. `onInsertBlock` calls `ctx.apply(t => ops.splitRichTextAt(t, block.id, richField, beforeHtml, afterHtml, { id: newBlockId(), type: slug, data: {} }))`.
- **Tail prose:** `BlocksField` computes `defaultProseType(allTypes, allowlist)`; when non-null, after the root list render a `data-test="tail-prose"` "Type here…" affordance (button styled as ghost text); click → `apply(insertAt(end, prose block))` + focus. Hidden when null (plain `add-block` button remains either way).

- [ ] **Step 1: Failing tests:**

```ts
it('prose types render chromeless with prose hook; widgets render cards', ...)
it('tail-prose appears when rich_text allowed, uses custom prose type as fallback, hidden when none', ...)
it('insert-block event from the prose editor drives splitRichTextAt (identity rules already unit-tested)', async () => {
  // Mount with one rich_text block; find the ProseBlockEditor stub/component and
  // emit insert-block { slug: 'hero', beforeHtml: '<p>b</p>', afterHtml: '<p>a</p>' }.
  // Assert the model became [rich_text(keeps id, body '<p>b</p>'), hero, rich_text('<p>a</p>')].
})
```

  For these component tests stub `ProseBlockEditor` globally (`{ template: '<div data-test="prose-editor-stub" />', emits: [...] }`) — mounting a real UEditor in jsdom is out of harness scope; the split ROUTINE inside the handler is exercised manually in the browser (recorded limitation, same class as TipTap cursor mechanics).

- [ ] **Step 2–4:** fail → implement → green + type-check. Also re-run the existing `pnpm vitest run src/__tests__` fully — the RichText refactor touches a shared component.

---

### Task 6: Outline rail + docs + full verification + STAGE

**Files:**
- Create: `admin/src/fields/components/blocks/BlockOutlineRail.vue`
- Modify: `BlocksField.vue` (toggle + selectBlock), `CHANGELOG.md`
- Test: extend `block-notion-ux.spec.ts`

- [ ] **Step 1:** `BlockOutlineRail` (`data-test="block-outline"`): tree of `block-outline-item-{id}` rows (icon, label, summary, indent by depth) built by walking the model with `regionsOf`; click calls `ctx.selectBlock(id)` — which expands ancestors, scrolls the card into view (`scrollIntoView`), and focuses its header. The rail is hidden behind a `data-test="block-outline-toggle"` list-tree icon button in the field header row; collapsed by default. Failing test: toggle reveals rail; rows render nested; click sets `expanded` for ancestors and focuses the header (assert `document.activeElement`).

- [ ] **Step 2:** implement + green.

- [ ] **Step 3: CHANGELOG `[Unreleased]`** — append to the block-builder family:

```markdown
  Follow-up: **Notion-like block editor UX** — inline insert dividers with a
  searchable block picker, `/` quick-insert, drag handles with cross-container
  drag (subtree-aware depth guard: a drop that would exceed the nesting cap is
  rejected in place, never a post-hoc validation error), keyboard movement
  (⌘/Alt+↑↓ move, ⌘D duplicate, Enter expand, Delete confirm), an outline rail,
  deep-copy duplicate (fixes nested-list aliasing), and the prose seam: block
  types shaped as a single rich-text field render chromeless as flowing prose,
  an empty tail offers "Type here…", and `/` inside prose can insert a widget
  block mid-text by splitting the prose block (original id kept for the before
  half; one structured-tree operation). TipTap/UEditor remains bounded to text
  editing — the Vue block tree stays canonical. SPA-only; stored model,
  validation, and render contracts unchanged.
```

- [ ] **Step 4: Full verification**

```bash
cd admin && pnpm type-check && pnpm test && cd ..
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"   # untouched, keep honest
composer boundaries
vendor/bin/phpunit --testsuite Integration   # untouched, keep honest
```

- [ ] **Step 5: STAGE** *(commit only when authorized)*

```bash
git add admin/src/fields/components/BlocksField.vue admin/src/fields/components/blocks \
        admin/src/components/RichText.vue admin/src/components/richTextToolbar.ts \
        admin/src/__tests__/blockListOps.spec.ts admin/src/__tests__/block-notion-ux.spec.ts \
        admin/src/__tests__/blocksField.spec.ts CHANGELOG.md docs/superpowers
```

STOP — when authorized:

```bash
git commit -m "feat(admin): Notion-like block editor UX

Rebuilds BlocksField as an ops-owned tree: pure id-addressed operations
(useBlockListOps — insert/remove/move/moveAcross/patch/deep-duplicate/split)
with ONE model emission per operation, provided to BlockList/BlockCard via
context. Inline insert dividers + searchable picker + '/' quick-insert;
drag handles via vue-draggable-plus with a shared cross-container group and
the subtree-aware depth guard (targetDepth + subtreeDepth - 1 <= cap) checked
BEFORE mutation; keyboard movement on block headers; outline rail; duplicate
now deep-copies and re-ids nested subtrees (fixes data aliasing).

Prose seam (Lemma owns the tree; TipTap powers text, not the page model):
single-rich-field block types render chromeless as flowing prose (detection is
an exported CONVENTION predicate, editor_mode override reserved), an empty
tail offers Type-here (rich_text preferred, first allowed prose type as
fallback), and '/' inside prose inserts widget blocks mid-text by splitting
the prose block with pinned identity rules. UEditor's only structural output
is the insert-at-cursor event with before/after HTML; toolbar items shared
with RichText via richTextToolbar.ts. SPA-only — stored {id,type,data} model
and every backend contract untouched."
```

---

## Self-Review Notes (applied)

- **Spec coverage:** §1 architecture/units → Tasks 1–2 (+ context.ts); preserved/new hooks → Global Constraints + per-task tests; §2 drag/depth/insert/keyboard → Tasks 3–4 (formula verbatim in ops with boundary tests; thin binding rule); §3 prose detection convention + escape-hatch comment → Task 1 `proseDetection.ts` docblock; chromeless/tail/split/bounded-TipTap → Task 5 (identity rules unit-tested in Task 1, event-level component test in Task 5); §4 outline → Task 6; §5 follow-ups untouched; §6 no new deps (all imports already installed); §7 test matrix mapped (ops unit tests, component data-test tests, jsdom drag/TipTap limits respected).
- **Type consistency:** `BlockInstance`/`RegionResolver`/ops signatures identical across Tasks 1–5; `newBlockId` replaces `newId`; context shape defined once (Task 2) and consumed by name everywhere; `ProseBlockEditor` emit payload `{slug, beforeHtml, afterHtml}` matches `splitRichTextAt(tree, id, richFieldName, beforeHtml, afterHtml, newBlock)`.
- **Placeholder scan:** Task 5's `emitSplit` is a deliberately implementation-time step with pinned constraints (public commands only) and a MANUAL browser acceptance — jsdom cannot prove cursor splitting and the plan says so instead of pretending.
- **Review fixes (applied):** P1 — drop target identity read from `event.to`/`event.item` datasets (`data-list-parent`/`data-list-region`/`data-block-id`), one shared root-provided `onDragEnd`, direct handler tests with fake event objects (flat-only `CollectionEditSlideover` precedent proves nothing about nesting); P1 — `canDropAt` rewritten with `isSelfOrDescendant` (regions-only walk), missing-parent rejection BEFORE the `+1` (can never masquerade as root depth), plus own-descendant/missing-parent tests; P2 — the PM-position-naive split snippet replaced by the implementation step above, with the suggestion-range fact confirmed against `EditorSuggestionMenu.vue:49`.
- **Refactor safety:** Task 2 is explicitly behavior-preserving with the existing `blocksField.spec.ts` as the harness; the RichText toolbar extraction (Task 5) re-runs the full SPA suite.
