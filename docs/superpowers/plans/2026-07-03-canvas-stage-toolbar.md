# Canvas v2: Stage Toolbar Affordances — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Selecting a block in the canvas stage shows an in-iframe toolbar (move up/down, duplicate, delete, add-after); intents route to the owning `BlocksField` via `FieldEditor`, mirrors update the stage DOM optimistically, and the inspector's insert menu learns per-list allowlists.

**Architecture:** The bridge (`preview-bridge.js`) injects one toolbar element by DOM placement (static CSS only — CSP pin) and posts nonce-echoed intents; the canvas page routes them through new `BlocksField`-exposed methods (single mutation authority) and posts mirror commands back only after the tree committed. Add-after has no mirror; save failures reload the current iframe URL to discard mirror-only DOM.

**Tech Stack:** Vue 3 + Nuxt UI 4 (admin SPA), vanilla JS/CSS static assets in `packages/lemma-render`, vitest + jsdom. No PHP/server changes.

**Spec:** `docs/superpowers/specs/2026-07-03-canvas-stage-toolbar-design.md`

## Global Constraints

- **No inline styles anywhere** in the bridge/toolbar — all appearance in static `preview.css` classes; toolbar positioned by DOM placement (anchor class + absolute with constant offsets). Inline geometry is a recorded follow-up requiring a separate CSP decision.
- **Same-list only:** move reorders within the block's current list; add-after inserts a sibling. No cross-container ops from the stage.
- **Mirrors only after commit:** the bridge never mutates on its own; a no-op/rejected intent produces no mirror. Add-after posts **no** mirror.
- **Delete is parent-confirmed:** bridge posts `block-delete-request`; the canvas page owns the confirm UI.
- **Save-failure reset:** any Save & refresh failure reloads the CURRENT iframe URL (no re-mint), keeps dirty fields, shows the existing banner.
- **Nonce discipline unchanged:** every message nonce-echoed; bridge silent until hello; origin-checked both ways.
- **Commit gate:** STAGE files at the end of Task 6 only; commit ONLY on explicit user authorization. No Claude/Anthropic attribution anywhere.
- **Verification commands:** admin: `cd admin && pnpm type-check && pnpm test`. PHP gates in Task 6: `vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"`, `composer boundaries`, `vendor/bin/phpunit --testsuite Unit`, `vendor/bin/phpunit --testsuite Integration` (from the lemma repo root). `phpcs.xml.dist` already excludes `packages/*/assets/*`.

---

### Task 1: Ops layer — `locateById` + `idMapBetween`

**Files:**
- Modify: `admin/src/fields/components/blocks/useBlockListOps.ts`
- Test: `admin/src/__tests__/blockListOps.spec.ts`

**Interfaces:**
- Consumes: existing `createBlockListOps(regionsOf)` factory internals (`asList`, `regionsOf`).
- Produces (used by Tasks 2–3):
  - `locateById(tree: BlockInstance[], id: string): { parentId: string | null; region: string | null; index: number; list: BlockInstance[] } | null` — the list containing `id`, its identity, and the block's index in it.
  - `idMapBetween(source: BlockInstance, copy: BlockInstance): Record<string, string>` — old-id → new-id over a source subtree and its re-id'd copy (same shape by construction, i.e. the output of `reIdSubtree`).
  - Both returned from the ops object (so `BlockListOps` picks them up via `ReturnType`).

- [ ] **Step 1: Write the failing tests**

Append inside the existing `describe('useBlockListOps', …)` block in `admin/src/__tests__/blockListOps.spec.ts` (fixtures `ops`, `leaf`, `nest` already exist at the top of the file):

```ts
  it('locateById names the containing list (root and nested) with the index', () => {
    const tree = [leaf('a'), nest('n', [leaf('x'), leaf('y')])]
    expect(ops.locateById(tree, 'a')).toMatchObject({
      parentId: null,
      region: null,
      index: 0,
    })
    const nested = ops.locateById(tree, 'y')
    expect(nested).toMatchObject({ parentId: 'n', region: 'inner', index: 1 })
    expect(nested!.list.map((b) => b.id)).toEqual(['x', 'y'])
    expect(ops.locateById(tree, 'missing')).toBeNull()
  })

  it('idMapBetween maps every id in the subtree to its fresh copy id', () => {
    const tree = [nest('n', [leaf('a'), nest('m', [leaf('b')])])]
    const out = ops.duplicateById(tree, 'n')
    const source = out[0]!
    const copy = out[1]!
    const map = ops.idMapBetween(source, copy)
    // Whole subtree covered: n, a, m, b — all mapped to fresh ids.
    expect(Object.keys(map).sort()).toEqual(['a', 'b', 'm', 'n'])
    expect(map.n).toBe(copy.id)
    for (const [oldId, newId] of Object.entries(map)) {
      expect(newId).not.toBe(oldId)
    }
    // Structural correspondence: the mapped child ids exist in the copy.
    const copyInner = copy.data.inner as BlockInstance[]
    expect(copyInner[0]!.id).toBe(map.a)
    expect((copyInner[1]!.data.inner as BlockInstance[])[0]!.id).toBe(map.b)
  })
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/blockListOps.spec.ts`
Expected: FAIL — `ops.locateById is not a function`.

- [ ] **Step 3: Implement**

In `admin/src/fields/components/blocks/useBlockListOps.ts`, add after the `findById` function (inside `createBlockListOps`):

Both functions are private to the factory (no top-level `export`) and reach consumers via the returned object:

```ts
  /** The list containing `id`: its identity (parentId/region), the block's index, and the array itself. */
  function locateById(
    tree: BlockInstance[],
    id: string,
    parentId: string | null = null,
    region: string | null = null,
  ): { parentId: string | null; region: string | null; index: number; list: BlockInstance[] } | null {
    const index = tree.findIndex((b) => b.id === id)
    if (index >= 0) return { parentId, region, index, list: tree }
    for (const block of tree) {
      for (const r of regionsOf(block.type)) {
        const hit = locateById(asList(block.data[r]), id, block.id, r)
        if (hit) return hit
      }
    }
    return null
  }

  /**
   * Old-id → new-id map between a source subtree and its reIdSubtree copy —
   * the two have identical shape by construction, so a parallel walk suffices.
   * This is what mirror-duplicate needs to rewrite data-lemma-block in a clone.
   */
  function idMapBetween(source: BlockInstance, copy: BlockInstance): Record<string, string> {
    const map: Record<string, string> = { [source.id]: copy.id }
    for (const r of regionsOf(source.type)) {
      const sourceInner = asList(source.data[r])
      const copyInner = asList(copy.data[r])
      sourceInner.forEach((child, i) => {
        const copyChild = copyInner[i]
        if (copyChild) Object.assign(map, idMapBetween(child, copyChild))
      })
    }
    return map
  }
```

Add both to the returned object:

```ts
  return {
    findById,
    locateById,
    insertAt,
    removeById,
    duplicateById,
    idMapBetween,
    patchDataById,
    moveById,
    moveAcross,
    subtreeDepth,
    depthOf,
    canDropAt,
    splitRichTextAt,
  }
```

- [ ] **Step 4: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/blockListOps.spec.ts`
Expected: PASS (all existing + 2 new).

---

### Task 2: Per-list picker rules (context resolver + inspector alignment)

**Files:**
- Modify: `admin/src/fields/components/blocks/context.ts` (replace `pickerTypes`/`allowlist` members with the resolver)
- Modify: `admin/src/fields/components/BlocksField.vue`
- Modify: `admin/src/fields/components/blocks/BlockList.vue`
- Modify: `admin/src/fields/components/blocks/BlockInsertMenu.vue`
- Modify: `admin/src/fields/components/blocks/BlockCard.vue`
- Modify: `admin/src/fields/components/blocks/ProseBlockEditor.vue`
- Test: `admin/src/__tests__/blocksField.spec.ts`

**Interfaces:**
- Consumes: `ops.findById` (existing), `toFieldDef` (existing normalize helper mapping `block_types` → `blockTypes`).
- Produces (used by Task 3's `pickerTypesFor` and Task 5's add-after picker):
  - `BlocksContext.pickerTypesForList(parentId: string | null, region: string | null): BlockType[]` — active types ∩ that list's own blocks-field allowlist; root list uses the entry field's allowlist; empty allowlist = all active types.
  - `BlockInsertMenu` prop change: `defineProps<{ open: boolean; types: BlockType[] }>()` — the menu no longer reads picker types from context.

**Note:** the field-global `pickerTypes`/`allowlist` context members have exactly TWO consumers (verified by grep): `BlockInsertMenu` (insert dividers/add button) and `ProseBlockEditor` (the `/` menu's "Blocks" group — its widgets insert as split-SIBLINGS in the same list, so per-list rules apply identically). Both switch to a `types` prop resolved by their list-owning parent; the context members are replaced, not kept alongside, so there is exactly one resolver. `BlocksField`'s internal `pickerTypes` computed is deleted; `tailProseType` keeps using `allowlist` directly (unchanged).

- [ ] **Step 1: Write the failing test**

Append to `admin/src/__tests__/blocksField.spec.ts` inside `describe('BlocksField', …)`. First, in the `defaultTypes()` fixture, give `section`'s `content` region its own allowlist by changing its schema entry to:

```ts
    schema: [
      {
        name: 'content',
        type: 'blocks',
        required: false,
        localized: false,
        filterable: false,
        block_types: ['quote'],
      },
    ],
```

Then add the test (mount plumbing matches the file's existing tests):

```ts
  it('nested insert menus use the REGION\'s own allowlist, not the root field\'s', async () => {
    const model = ref<{ id: string; type: string; data: Record<string, unknown> }[]>([
      { id: 'sec00000001', type: 'section', data: { content: [] } },
    ])
    const wrapper = mount(BlocksField, {
      props: {
        field, // root field: NO allowlist -> all active types at root
        modelValue: model.value,
        'onUpdate:modelValue': (v: typeof model.value) => (model.value = v),
      },
    })
    await flushPromises()

    // Root list "Add block" -> hero/quote/section all offered (active types).
    await wrapper.find('[data-test="add-block"]').trigger('click')
    expect(wrapper.find('[data-test="picker-item-hero"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="picker-item-quote"]').exists()).toBe(true)

    // Expand the section card, open the NESTED region's add button:
    // its menu offers ONLY the region allowlist (quote).
    await wrapper.find('[data-test="block-toggle-sec00000001"]').trigger('click')
    const addButtons = wrapper.findAll('[data-test="add-block"]')
    await addButtons[addButtons.length - 1]!.trigger('click')
    const pickers = wrapper.findAll('[data-test="block-picker"]')
    const nested = pickers[pickers.length - 1]!
    expect(nested.find('[data-test="picker-item-quote"]').exists()).toBe(true)
    expect(nested.find('[data-test="picker-item-hero"]').exists()).toBe(false)
    expect(nested.find('[data-test="picker-item-section"]').exists()).toBe(false)
  })
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/blocksField.spec.ts`
Expected: FAIL — the nested picker currently offers `hero` (field-global `pickerTypes`), so the `false` assertions fail. (If it fails earlier with a missing `types` prop warning, that's the same signal — proceed.)

- [ ] **Step 3: Implement**

**(a) `context.ts`** — replace the `pickerTypes` + `allowlist` members:

```ts
  /**
   * Picker types for ONE list (stage-toolbar spec §5): active types ∩ that
   * list's own blocks-field allowlist. Root list (null, null) = the entry
   * field's allowlist; a nested region = the containing block type's
   * blocks-typed schema field for that region. Empty allowlist = all active.
   */
  pickerTypesForList: (parentId: string | null, region: string | null) => BlockType[]
```

(Delete the `pickerTypes: ComputedRef<BlockType[]>` and `allowlist: string[]` lines; drop the now-unused `ComputedRef` import member if nothing else uses it — `bySlug` still does, so the import stays.)

**(b) `BlocksField.vue`** — delete the `pickerTypes` computed and add the resolver (after `regionsOf`, before `const ops = …` is fine since it uses `ops` — place it after `const ops = createBlockListOps(regionsOf)`):

```ts
/**
 * Per-list picker rules (stage-toolbar spec §5): ONE resolver for the
 * inspector's insert menus AND the canvas add-after picker, so they can
 * never drift. Root list -> the entry field's allowlist; nested region ->
 * the containing block type's blocks-field allowlist for that region.
 */
function pickerTypesForList(parentId: string | null, region: string | null): BlockType[] {
  let allowed = allowlist.value
  if (parentId !== null && region !== null) {
    const parent = ops.findById(model.value ?? [], parentId)
    const parentType = parent ? bySlug.value.get(parent.type) : undefined
    const regionField = parentType?.schema.find((f) => f.name === region)
    allowed = (regionField ? toFieldDef(regionField).blockTypes : undefined) ?? []
  }
  return (allTypes.value ?? []).filter(
    (t) => t.active && (allowed.length === 0 || allowed.includes(t.slug)),
  )
}
```

Add the import for the `BlockType` type if not present (`import type { BlockType } from '@/queries/blockTypes'` — `MAX_BLOCK_DEPTH` is already imported from there).

In the `context` object literal, replace `pickerTypes,` and `allowlist: allowlist.value,` with:

```ts
  pickerTypesForList,
```

**(c) `BlockList.vue`** — compute the list's types and pass them down. Add to the script (after `const ctx = inject(...)`):

```ts
import { computed } from 'vue' // extend the existing vue import

// This list's picker options (stage-toolbar spec §5): resolved by the ONE
// context resolver from this list's own identity.
const pickerTypes = computed(() => ctx.pickerTypesForList(props.parentId, props.region))
```

Both `<BlockInsertMenu …>` usages gain `:types="pickerTypes"`:

```html
        <BlockInsertMenu
          v-if="menuIndex === index"
          open
          :types="pickerTypes"
          @select="insertType"
          @close="closeMenu"
        />
```

and

```html
      <BlockInsertMenu
        v-if="menuIndex === blocks.length"
        open
        :types="pickerTypes"
        @select="insertType"
        @close="closeMenu"
      />
```

**(d) `BlockInsertMenu.vue`** — take the options as a prop; drop the context dependency entirely:

```ts
const props = defineProps<{ open: boolean; types: BlockType[] }>()
```

Remove `import { inject } from 'vue'`'s `inject` usage, the `BlocksContextKey` import, and the `const ctx = inject(BlocksContextKey)!` line. Replace both `ctx.pickerTypes.value` reads in the `filtered` computed with `props.types`:

```ts
const filtered = computed(() => {
  const q = filter.value.trim().toLowerCase()
  if (q === '') return props.types
  return props.types.filter(
    (t) =>
      t.label.toLowerCase().includes(q) ||
      t.slug.toLowerCase().includes(q) ||
      (t.description ?? '').toLowerCase().includes(q),
  )
})
```

**(e) `BlockCard.vue`** — resolve this card's LIST types (a card knows its list via the existing `parentId`/`region` props) and pass them to the prose editor. Add to the script:

```ts
// This block's containing-list picker rules (stage-toolbar spec §5): the `/`
// menu inserts split-siblings into the SAME list, so it uses the same resolver
// as the insert dividers.
const listPickerTypes = computed(() => ctx.pickerTypesForList(props.parentId, props.region))
```

(`computed` is already imported; `ctx` already injected.) Then extend the `<ProseBlockEditor …>` usage:

```html
    <ProseBlockEditor
      :model-value="(block.data[richField] as string) ?? ''"
      :picker-types="listPickerTypes"
      @update:model-value="(v: string) => patchData(richField!, v)"
      @insert-block="onInsertBlock"
    />
```

**(f) `ProseBlockEditor.vue`** — take the types as a prop:

```ts
const props = defineProps<{ modelValue?: string; placeholder?: string; pickerTypes: BlockType[] }>()
```

(add `import type { BlockType } from '@/queries/blockTypes'` if not present). In the `suggestionItems` computed, replace `ctx.pickerTypes.value.map(…)` with `props.pickerTypes.map(…)`. The `ctx` injection stays — the split handlers still use `ctx.apply`/`ctx.ops`.

- [ ] **Step 4: Run to verify pass + no regressions**

Run: `cd admin && pnpm vitest run src/__tests__/blocksField.spec.ts src/__tests__/block-notion-ux.spec.ts src/__tests__/blockListOps.spec.ts`
Expected: PASS. (The Notion-UX suite exercises insert menus heavily — it verifies the prop refactor broke nothing.)

Run: `cd admin && pnpm type-check`
Expected: clean — this catches any missed `ctx.pickerTypes`/`allowlist` consumer.

---

### Task 3: BlocksField structural methods + FieldEditor routing

**Files:**
- Modify: `admin/src/fields/components/BlocksField.vue`
- Modify: `admin/src/components/FieldEditor.vue`
- Test: `admin/src/__tests__/blocksField.spec.ts` (methods), `admin/src/__tests__/canvas-bridge.spec.ts` (routing)

**Interfaces:**
- Consumes: Task 1's `ops.locateById` / `ops.idMapBetween`; Task 2's `pickerTypesForList`.
- Produces (used by Task 5's canvas page):
  - `BlocksField` exposed: `moveBlock(id: string, delta: number): { beforeId: string } | { afterId: string } | null`, `duplicateBlock(id: string): { newId: string; idMap: Record<string, string> } | null`, `deleteBlock(id: string): boolean`, `insertAfter(id: string, typeSlug: string): string | null`, `pickerTypesFor(id: string): BlockType[]`.
  - `FieldEditor` exposed: `moveBlockById`, `duplicateBlockById`, `deleteBlockById`, `insertAfterById`, `pickerTypesForBlock` — same signatures, routed by `hasBlock` (return `null`/`false`/`[]` when no field owns the id).

- [ ] **Step 1: Write the failing BlocksField tests**

Append to `admin/src/__tests__/blocksField.spec.ts`:

```ts
  it('canvas structural methods: move/duplicate/delete/insertAfter/pickerTypesFor', async () => {
    let model: { id: string; type: string; data: Record<string, unknown> }[] = [
      { id: 'aaa000000001', type: 'quote', data: { text: 'A' } },
      { id: 'bbb000000002', type: 'quote', data: { text: 'B' } },
      { id: 'sec00000001', type: 'section', data: { content: [] } },
    ]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const api = wrapper.vm as unknown as {
      moveBlock: (id: string, delta: number) => { beforeId: string } | { afterId: string } | null
      duplicateBlock: (id: string) => { newId: string; idMap: Record<string, string> } | null
      deleteBlock: (id: string) => boolean
      insertAfter: (id: string, slug: string) => string | null
      pickerTypesFor: (id: string) => { slug: string }[]
    }

    // moveBlock down: neighbor is the sibling now following it.
    expect(api.moveBlock('aaa000000001', 1)).toEqual({ beforeId: 'sec00000001' })
    expect(model.map((b) => b.id)).toEqual(['bbb000000002', 'aaa000000001', 'sec00000001'])
    await wrapper.setProps({ modelValue: model })

    // Boundary no-op: first block up -> null, model untouched.
    expect(api.moveBlock('bbb000000002', -1)).toBeNull()
    expect(model.map((b) => b.id)).toEqual(['bbb000000002', 'aaa000000001', 'sec00000001'])

    // Move to LIST END -> afterId (the sibling now preceding it).
    expect(api.moveBlock('aaa000000001', 1)).toEqual({ afterId: 'sec00000001' })
    await wrapper.setProps({ modelValue: model })

    // duplicateBlock: fresh id, idMap keyed by the source id.
    const dup = api.duplicateBlock('bbb000000002')
    expect(dup).not.toBeNull()
    expect(dup!.idMap['bbb000000002']).toBe(dup!.newId)
    expect(model[1]!.id).toBe(dup!.newId)
    await wrapper.setProps({ modelValue: model })

    // insertAfter: sibling position, returns the new id.
    const newId = api.insertAfter('bbb000000002', 'quote')
    expect(newId).not.toBeNull()
    expect(model[1]!.id).toBe(newId)
    expect(model[1]!.type).toBe('quote')
    await wrapper.setProps({ modelValue: model })

    // pickerTypesFor resolves the CONTAINING list's rules: root -> all active;
    // a block inside section.content -> the region allowlist (quote only).
    expect(api.pickerTypesFor('bbb000000002').map((t) => t.slug).sort()).toEqual([
      'hero',
      'quote',
      'section',
    ])

    // deleteBlock: true then the block is gone; unknown id -> false.
    expect(api.deleteBlock('bbb000000002')).toBe(true)
    expect(model.some((b) => b.id === 'bbb000000002')).toBe(false)
    expect(api.deleteBlock('missing')).toBe(false)
    wrapper.unmount()
  })

  it('pickerTypesFor a block INSIDE a region uses the region allowlist', async () => {
    let model: { id: string; type: string; data: Record<string, unknown> }[] = [
      {
        id: 'sec00000001',
        type: 'section',
        data: { content: [{ id: 'inner0000001', type: 'quote', data: {} }] },
      },
    ]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const api = wrapper.vm as unknown as { pickerTypesFor: (id: string) => { slug: string }[] }
    expect(api.pickerTypesFor('inner0000001').map((t) => t.slug)).toEqual(['quote'])
    expect(api.pickerTypesFor('missing')).toEqual([])
    wrapper.unmount()
  })
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/blocksField.spec.ts`
Expected: FAIL — `api.moveBlock is not a function`.

- [ ] **Step 3: Implement the BlocksField methods**

In `admin/src/fields/components/BlocksField.vue`, add after the `hasBlock` function (before `defineExpose`):

```ts
// ── Canvas structural ops (stage-toolbar spec §4) ─────────────────────────────
// Same-list, id-addressed, all through the ops layer. Each returns exactly the
// payload the canvas needs to post the matching mirror — or null/false when the
// intent is a no-op, so NO mirror is ever posted for an uncommitted change.

/** Reorder within the block's own list. Returns the moved block's new neighbor. */
function moveBlock(id: string, delta: number): { beforeId: string } | { afterId: string } | null {
  const tree = model.value ?? []
  const loc = ops.locateById(tree, id)
  if (!loc) return null
  const to = loc.index + delta
  if (to < 0 || to >= loc.list.length) return null // boundary no-op — no mirror
  apply((t) => ops.moveById(t, id, delta))
  const after = ops.locateById(model.value ?? [], id)!
  const following = after.list[after.index + 1]
  // A committed move always has >= 1 neighbor (list length >= 2), so when
  // nothing follows, the preceding sibling exists.
  return following ? { beforeId: following.id } : { afterId: after.list[after.index - 1]!.id }
}

/** Duplicate in place. Returns the copy's id + the whole-subtree old->new id map. */
function duplicateBlock(id: string): { newId: string; idMap: Record<string, string> } | null {
  const tree = model.value ?? []
  const source = ops.findById(tree, id)
  if (!source) return null
  apply((t) => ops.duplicateById(t, id))
  const loc = ops.locateById(model.value ?? [], id)!
  const copy = loc.list[loc.index + 1]!
  expanded[copy.id] = true
  return { newId: copy.id, idMap: ops.idMapBetween(source, copy) }
}

function deleteBlock(id: string): boolean {
  if (!ops.findById(model.value ?? [], id)) return false
  apply((t) => ops.removeById(t, id))
  return true
}

/** Insert a fresh empty block of `typeSlug` as the next sibling of `id`. */
function insertAfter(id: string, typeSlug: string): string | null {
  const loc = ops.locateById(model.value ?? [], id)
  if (!loc) return null
  const block: BlockInstance = { id: newBlockId(), type: typeSlug, data: {} }
  apply((t) =>
    ops.insertAt(t, { parentId: loc.parentId, region: loc.region, index: loc.index + 1 }, block),
  )
  expanded[block.id] = true
  selectBlock(block.id)
  return block.id
}

/** Picker options for inserting NEXT TO `id` — the containing list's rules (§5). */
function pickerTypesFor(id: string): BlockType[] {
  const loc = ops.locateById(model.value ?? [], id)
  if (!loc) return []
  return pickerTypesForList(loc.parentId, loc.region)
}
```

Extend the expose line:

```ts
defineExpose({
  onDragEnd,
  selectBlock,
  hasBlock,
  moveBlock,
  duplicateBlock,
  deleteBlock,
  insertAfter,
  pickerTypesFor,
})
```

- [ ] **Step 4: Run to verify BlocksField pass**

Run: `cd admin && pnpm vitest run src/__tests__/blocksField.spec.ts`
Expected: PASS.

- [ ] **Step 5: Write the failing FieldEditor routing test**

Append to `admin/src/__tests__/canvas-bridge.spec.ts`, inside the existing `describe('FieldEditor.selectBlockById', …)` block. It already provides `warmBlocksField()` (resolves the registry's async BlocksField) and `mountEditor()` (mounts FieldEditor with a `body` field owning block `inbody000001` and a `sidebar` field owning block `inside000001`, both of type `card`). Reuse them exactly:

```ts
  it('routes structural methods to the field that owns the block', async () => {
    await warmBlocksField()
    const wrapper = mountEditor()
    await flushPromises()
    const api = wrapper.vm as unknown as {
      moveBlockById: (id: string, d: number) => { beforeId: string } | { afterId: string } | null
      duplicateBlockById: (id: string) => { newId: string; idMap: Record<string, string> } | null
      deleteBlockById: (id: string) => boolean
      insertAfterById: (id: string, slug: string) => string | null
      pickerTypesForBlock: (id: string) => { slug: string }[]
    }
    // Unknown id -> safe empties, no throw.
    expect(api.moveBlockById('missing', 1)).toBeNull()
    expect(api.duplicateBlockById('missing')).toBeNull()
    expect(api.deleteBlockById('missing')).toBe(false)
    expect(api.insertAfterById('missing', 'card')).toBeNull()
    expect(api.pickerTypesForBlock('missing')).toEqual([])
    // Owned id routes to the owning field (sidebar's block, not body's).
    const dup = api.duplicateBlockById('inside000001')
    expect(dup).not.toBeNull()
    expect(dup!.idMap['inside000001']).toBe(dup!.newId)
    // pickerTypesForBlock resolves through the owning field's per-list rules.
    expect(api.pickerTypesForBlock('inbody000001').map((t) => t.slug)).toEqual(['card'])
    wrapper.unmount()
  })
```

- [ ] **Step 6: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts`
Expected: FAIL — `api.moveBlockById is not a function`.

- [ ] **Step 7: Implement FieldEditor routing**

In `admin/src/components/FieldEditor.vue`, extend the exposed-interface and add a lookup helper + routed methods:

```ts
interface BlocksFieldExposed {
  hasBlock: (id: string) => boolean
  selectBlock: (id: string) => void
  moveBlock: (id: string, delta: number) => { beforeId: string } | { afterId: string } | null
  duplicateBlock: (id: string) => { newId: string; idMap: Record<string, string> } | null
  deleteBlock: (id: string) => boolean
  insertAfter: (id: string, typeSlug: string) => string | null
  pickerTypesFor: (id: string) => BlockType[]
}
```

Add `import type { BlockType } from '@/queries/blockTypes'` at the top.

Below `trackField`, add:

```ts
/** The live blocks field owning `id` — entry-wide id uniqueness makes this unambiguous. */
function fieldOwning(id: string): BlocksFieldExposed | null {
  for (const field of blocksFields.values()) {
    if (field.hasBlock?.(id)) return field
  }
  return null
}
```

Replace the `defineExpose` block with:

```ts
defineExpose({
  /**
   * Find the blocks field containing `id` and drive its selectBlock. Returns
   * true when found — entry-wide block-id uniqueness makes the bare id
   * unambiguous across fields (visual-canvas spec §5). Iterates only LIVE refs.
   */
  selectBlockById(id: string): boolean {
    const field = fieldOwning(id)
    if (field) field.selectBlock(id)
    return field !== null
  },
  // Canvas structural routing (stage-toolbar spec §4): same shape as selection —
  // route to the owning field, return that field's result, safe empties otherwise.
  moveBlockById(id: string, delta: number) {
    return fieldOwning(id)?.moveBlock(id, delta) ?? null
  },
  duplicateBlockById(id: string) {
    return fieldOwning(id)?.duplicateBlock(id) ?? null
  },
  deleteBlockById(id: string) {
    return fieldOwning(id)?.deleteBlock(id) ?? false
  },
  insertAfterById(id: string, typeSlug: string) {
    return fieldOwning(id)?.insertAfter(id, typeSlug) ?? null
  },
  pickerTypesForBlock(id: string): BlockType[] {
    return fieldOwning(id)?.pickerTypesFor(id) ?? []
  },
})
```

- [ ] **Step 8: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts src/__tests__/blocksField.spec.ts && pnpm type-check`
Expected: PASS, type-check clean.

---

### Task 4: Bridge asset — toolbar, intents, mirrors + direct jsdom tests

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js` (full rewrite below)
- Modify: `packages/lemma-render/assets/preview/preview.css` (append toolbar rules)
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts` (new — the direct-eval pattern)

**Interfaces:**
- Consumes: nothing from other tasks (pure asset + its own test).
- Produces (Task 5's counterpart messages): intents `lemma:block-move {id, delta}`, `lemma:block-duplicate {id}`, `lemma:block-delete-request {id}`, `lemma:block-add-after {id}`; consumes mirrors `lemma:mirror-move {id, beforeId?|afterId?}`, `lemma:mirror-remove {id}`, `lemma:mirror-duplicate {sourceId, idMap}`. All nonce-echoed on the v1 envelope.

- [ ] **Step 1: Write the failing direct-eval test suite**

Create `admin/src/__tests__/preview-bridge-dom.spec.ts`:

```ts
import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// Direct tests for the STATIC bridge asset (stage-toolbar spec §6): the file is
// evaluated ONCE in this jsdom document and driven with synthetic message
// events. One eval per file — the IIFE registers window/document listeners we
// cannot remove — so the hello/session is established in beforeAll and every
// test builds its own uniquely-id'd fixtures.
// Vitest runs from the admin/ root and import.meta.url is not a file:// URL in
// the jsdom environment (same convention as schemaBoundary.spec.ts) — resolve
// from cwd.
const source = readFileSync(
  resolve(process.cwd(), '../packages/lemma-render/assets/preview/preview-bridge.js'),
  'utf8',
)

const NONCE = 'test-nonce-1'
const posted = vi.fn()

function sendToBridge(data: Record<string, unknown>, origin = 'https://admin.test'): void {
  window.dispatchEvent(new MessageEvent('message', { data: { nonce: NONCE, ...data }, origin }))
}

function wrapper(id: string, inner = `<section><a href="/x">link ${id}</a></section>`): HTMLElement {
  const el = document.createElement('div')
  el.className = 'lemma-preview-block'
  el.setAttribute('data-lemma-block', id)
  el.innerHTML = inner
  return el
}

beforeAll(() => {
  // The bridge calls window.parent.postMessage at CALL time; in jsdom
  // window.parent === window, so stubbing window.postMessage captures posts.
  window.postMessage = posted as unknown as typeof window.postMessage
  new Function(source)()
  // Silent until hello (v1 pin), then session = { origin, nonce }.
  window.dispatchEvent(
    new MessageEvent('message', {
      data: { type: 'lemma:canvas-hello', nonce: NONCE },
      origin: 'https://admin.test',
    }),
  )
})

beforeEach(() => {
  posted.mockClear()
  document.body.innerHTML = ''
})

function lastPost(type: string): Record<string, unknown> | undefined {
  return posted.mock.calls.map((c) => c[0] as Record<string, unknown>).findLast((m) => m.type === type)
}

describe('preview bridge (direct eval)', () => {
  it('click selects, injects the toolbar into the anchor, and posts block-select', () => {
    const w = wrapper('blk-sel-0001')
    document.body.appendChild(w)
    w.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    expect(lastPost('lemma:block-select')).toMatchObject({ id: 'blk-sel-0001', nonce: NONCE })
    expect(w.classList.contains('lemma-canvas-selected')).toBe(true)
    const host = w.firstElementChild!
    expect(host.classList.contains('lemma-canvas-anchor')).toBe(true)
    const toolbar = host.querySelector(':scope > .lemma-canvas-toolbar')
    expect(toolbar).not.toBeNull()
    // All five actions present.
    const actions = [...toolbar!.querySelectorAll('[data-action]')].map((b) =>
      b.getAttribute('data-action'),
    )
    expect(actions).toEqual(['move-up', 'move-down', 'duplicate', 'delete', 'add-after'])
  })

  it('toolbar clicks post intents and never re-select', () => {
    const w = wrapper('blk-int-0001')
    document.body.appendChild(w)
    w.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    posted.mockClear()

    const toolbar = w.querySelector('.lemma-canvas-toolbar')!
    const click = (action: string) =>
      toolbar
        .querySelector(`[data-action="${action}"]`)!
        .dispatchEvent(new MouseEvent('click', { bubbles: true }))

    click('move-up')
    expect(lastPost('lemma:block-move')).toMatchObject({ id: 'blk-int-0001', delta: -1 })
    click('move-down')
    expect(lastPost('lemma:block-move')).toMatchObject({ id: 'blk-int-0001', delta: 1 })
    click('duplicate')
    expect(lastPost('lemma:block-duplicate')).toMatchObject({ id: 'blk-int-0001' })
    click('delete')
    expect(lastPost('lemma:block-delete-request')).toMatchObject({ id: 'blk-int-0001' })
    click('add-after')
    expect(lastPost('lemma:block-add-after')).toMatchObject({ id: 'blk-int-0001' })
    expect(lastPost('lemma:block-select')).toBeUndefined()
  })

  it('mirror-move places the wrapper next to the named sibling (beforeId and afterId)', () => {
    const list = document.createElement('main')
    const a = wrapper('mv-a-0000001')
    const b = wrapper('mv-b-0000002')
    const c = wrapper('mv-c-0000003')
    list.append(a, b, c)
    document.body.appendChild(list)

    sendToBridge({ type: 'lemma:mirror-move', id: 'mv-c-0000003', beforeId: 'mv-a-0000001' })
    expect([...list.children].map((el) => el.getAttribute('data-lemma-block'))).toEqual([
      'mv-c-0000003',
      'mv-a-0000001',
      'mv-b-0000002',
    ])
    sendToBridge({ type: 'lemma:mirror-move', id: 'mv-c-0000003', afterId: 'mv-b-0000002' })
    expect([...list.children].map((el) => el.getAttribute('data-lemma-block'))).toEqual([
      'mv-a-0000001',
      'mv-b-0000002',
      'mv-c-0000003',
    ])
    // Missing wrapper -> ignored, no throw.
    sendToBridge({ type: 'lemma:mirror-move', id: 'nope', beforeId: 'mv-a-0000001' })
  })

  it('mirror-move ignores a reference wrapper in ANOTHER parent (same-list guard)', () => {
    const listA = document.createElement('main')
    const listB = document.createElement('aside')
    const a = wrapper('gd-a-0000001')
    const b = wrapper('gd-b-0000002')
    listA.appendChild(a)
    listB.appendChild(b)
    document.body.append(listA, listB)

    // Stale/mismatched reference lives in a different container: the block
    // must NOT cross parents (same-list-only pin) — the mirror is a no-op.
    sendToBridge({ type: 'lemma:mirror-move', id: 'gd-a-0000001', beforeId: 'gd-b-0000002' })
    expect(a.parentNode).toBe(listA)
    sendToBridge({ type: 'lemma:mirror-move', id: 'gd-a-0000001', afterId: 'gd-b-0000002' })
    expect(a.parentNode).toBe(listA)
    expect([...listB.children].map((el) => el.getAttribute('data-lemma-block'))).toEqual([
      'gd-b-0000002',
    ])
  })

  it('mirror-remove drops the wrapper and detaches the toolbar when it was selected', () => {
    const w = wrapper('rm-a-0000001')
    document.body.appendChild(w)
    w.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(document.querySelector('.lemma-canvas-toolbar')).not.toBeNull()

    sendToBridge({ type: 'lemma:mirror-remove', id: 'rm-a-0000001' })
    expect(document.querySelector('[data-lemma-block="rm-a-0000001"]')).toBeNull()
    expect(document.querySelector('.lemma-canvas-toolbar')).toBeNull()
  })

  it('mirror-duplicate clones, STRIPS canvas UI state, and rewrites ids via idMap', () => {
    const w = wrapper(
      'dup-a-000001',
      '<section><div class="lemma-preview-block" data-lemma-block="dup-child-01"><p>inner</p></div></section>',
    )
    document.body.appendChild(w)
    // Select the source so its clone WOULD carry toolbar/anchor/ring state.
    w.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    sendToBridge({
      type: 'lemma:mirror-duplicate',
      sourceId: 'dup-a-000001',
      idMap: { 'dup-a-000001': 'dup-b-000002', 'dup-child-01': 'dup-child-02' },
    })
    const copy = document.querySelector('[data-lemma-block="dup-b-000002"]')
    expect(copy).not.toBeNull()
    expect(copy!.previousElementSibling).toBe(w)
    // Subtree id rewritten via the map.
    expect(copy!.querySelector('[data-lemma-block="dup-child-02"]')).not.toBeNull()
    expect(copy!.querySelector('[data-lemma-block="dup-child-01"]')).toBeNull()
    // Canvas UI state stripped from the clone (review P2).
    expect(copy!.querySelector('.lemma-canvas-toolbar')).toBeNull()
    expect(copy!.classList.contains('lemma-canvas-selected')).toBe(false)
    expect(copy!.querySelector('.lemma-canvas-anchor')).toBeNull()
    // The SOURCE keeps its selected state untouched.
    expect(w.classList.contains('lemma-canvas-selected')).toBe(true)
  })

  it('drops messages with a wrong nonce or origin', () => {
    const list = document.createElement('main')
    const a = wrapper('sec-a-000001')
    const b = wrapper('sec-b-000002')
    list.append(a, b)
    document.body.appendChild(list)
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:mirror-move', id: 'sec-b-000002', beforeId: 'sec-a-000001', nonce: 'wrong' },
        origin: 'https://admin.test',
      }),
    )
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:mirror-move', id: 'sec-b-000002', beforeId: 'sec-a-000001', nonce: NONCE },
        origin: 'https://evil.test',
      }),
    )
    expect([...list.children].map((el) => el.getAttribute('data-lemma-block'))).toEqual([
      'sec-a-000001',
      'sec-b-000002',
    ])
  })
})
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: FAIL — no toolbar/intents exist yet (the select test's toolbar assertion fails; mirror tests fail).

- [ ] **Step 3: Rewrite the bridge**

Replace `packages/lemma-render/assets/preview/preview-bridge.js` in full:

```js
// Lemma canvas bridge (visual-canvas spec §3 + stage-toolbar spec §1–§3).
// SILENT until a canvas parent says hello; a plain preview tab never messages
// anyone. The nonce is a correlation token, not auth — it stops stale frames/
// same-window noise from impersonating the active canvas session. Token-free
// and static on purpose (cacheable). CSP pin: NO inline styles anywhere — all
// appearance lives in preview.css classes; the toolbar is positioned by DOM
// placement inside the selected block's anchor element. Mirrors are DOM-only
// and applied ONLY on parent command, after the tree mutation committed.
(function () {
  'use strict'
  var session = null // { origin, nonce }
  var selectedId = null
  var toolbar = null
  var anchorEl = null

  function post(type, payload) {
    if (!session) return
    var msg = { type: 'lemma:' + type, nonce: session.nonce }
    if (payload) {
      for (var key in payload) {
        if (Object.prototype.hasOwnProperty.call(payload, key)) msg[key] = payload[key]
      }
    }
    window.parent.postMessage(msg, session.origin)
  }

  function idsIndex() {
    return Array.prototype.map.call(
      document.querySelectorAll('[data-lemma-block]'),
      function (el) { return el.getAttribute('data-lemma-block') }
    )
  }

  function wrapperFor(target) {
    return target && target.closest ? target.closest('[data-lemma-block]') : null
  }

  function clearClass(cls) {
    Array.prototype.forEach.call(document.querySelectorAll('.' + cls), function (el) {
      el.classList.remove(cls)
    })
  }

  function findBlock(id) {
    return document.querySelector('[data-lemma-block="' + CSS.escape(String(id)) + '"]')
  }

  // ── Toolbar (stage-toolbar spec §3) ─────────────────────────────────────────
  var ACTIONS = [
    { action: 'move-up', label: 'Move up', path: 'M18 15l-6-6-6 6' },
    { action: 'move-down', label: 'Move down', path: 'M6 9l6 6 6-6' },
    { action: 'duplicate', label: 'Duplicate', path: 'M8 8h12v12H8zM16 8V4H4v12h4' },
    { action: 'delete', label: 'Delete', path: 'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v6M14 10v6' },
    { action: 'add-after', label: 'Add block after', path: 'M12 5v14M5 12h14' }
  ]

  function ensureToolbar() {
    if (toolbar) return toolbar
    toolbar = document.createElement('div')
    toolbar.className = 'lemma-canvas-toolbar'
    ACTIONS.forEach(function (a) {
      var btn = document.createElement('button')
      btn.type = 'button'
      btn.setAttribute('data-action', a.action)
      btn.setAttribute('aria-label', a.label)
      btn.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round"><path d="' + a.path + '"/></svg>'
      toolbar.appendChild(btn)
    })
    return toolbar
  }

  function detachToolbar() {
    if (toolbar && toolbar.parentNode) toolbar.parentNode.removeChild(toolbar)
    if (anchorEl) {
      anchorEl.classList.remove('lemma-canvas-anchor')
      anchorEl = null
    }
  }

  function selectWrapper(w) {
    clearClass('lemma-canvas-selected')
    detachToolbar()
    w.classList.add('lemma-canvas-selected')
    selectedId = w.getAttribute('data-lemma-block')
    var host = w.firstElementChild
    if (host) {
      // DOM placement, static CSS (spec §3): anchor gets position:relative from
      // its class; the toolbar is absolute against it with constant offsets.
      // Text-only renders (no element child) get selection but no toolbar.
      anchorEl = host
      host.classList.add('lemma-canvas-anchor')
      host.insertBefore(ensureToolbar(), host.firstChild)
    }
  }

  function clearSelection() {
    clearClass('lemma-canvas-selected')
    detachToolbar()
    selectedId = null
  }

  // ── Mirrors (stage-toolbar spec §1): DOM-only, parent-commanded ─────────────
  function stripCanvasState(root) {
    Array.prototype.forEach.call(root.querySelectorAll('.lemma-canvas-toolbar'), function (el) {
      el.parentNode.removeChild(el)
    })
    var classes = [
      'lemma-canvas-anchor', 'lemma-canvas-selected', 'lemma-canvas-hover',
      'lemma-canvas-selected-target', 'lemma-canvas-hover-target'
    ]
    classes.forEach(function (cls) {
      root.classList.remove(cls)
      Array.prototype.forEach.call(root.querySelectorAll('.' + cls), function (el) {
        el.classList.remove(cls)
      })
    })
  }

  function mirrorMove(id, beforeId, afterId) {
    var w = findBlock(id)
    if (!w || !w.parentNode) return
    // Same-list-only pin: the reference wrapper must be a DOM SIBLING. A stale
    // or mismatched reference in another container must never move the block
    // across parents — ignore the mirror instead.
    if (typeof beforeId === 'string') {
      var ref = findBlock(beforeId)
      if (ref && ref.parentNode === w.parentNode) ref.parentNode.insertBefore(w, ref)
    } else if (typeof afterId === 'string') {
      var prev = findBlock(afterId)
      if (prev && prev.parentNode === w.parentNode) prev.parentNode.insertBefore(w, prev.nextSibling)
    }
  }

  function mirrorRemove(id) {
    var w = findBlock(id)
    if (!w) return
    if (selectedId === id) clearSelection() // detach the toolbar BEFORE the wrapper goes
    if (w.parentNode) w.parentNode.removeChild(w)
  }

  function mirrorDuplicate(sourceId, idMap) {
    var src = findBlock(sourceId)
    if (!src || !src.parentNode || !idMap) return
    var clone = src.cloneNode(true)
    stripCanvasState(clone) // the source is usually SELECTED — never clone live UI state
    var ownId = clone.getAttribute('data-lemma-block')
    if (idMap[ownId]) clone.setAttribute('data-lemma-block', idMap[ownId])
    Array.prototype.forEach.call(clone.querySelectorAll('[data-lemma-block]'), function (el) {
      var next = idMap[el.getAttribute('data-lemma-block')]
      if (next) el.setAttribute('data-lemma-block', next)
    })
    src.parentNode.insertBefore(clone, src.nextSibling)
  }

  function activate() {
    document.addEventListener('mouseover', function (e) {
      var w = wrapperFor(e.target)
      clearClass('lemma-canvas-hover')
      if (w) {
        w.classList.add('lemma-canvas-hover')
        post('block-hover', { id: w.getAttribute('data-lemma-block') })
      }
    })
    // Capture phase: block-internal links/buttons are INERT while active
    // (spec §3) — editing must not navigate the stage. Toolbar clicks are the
    // ONE branch that dispatches an intent instead of (re)selecting.
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest
        ? e.target.closest('.lemma-canvas-toolbar [data-action]')
        : null
      if (btn && selectedId !== null) {
        e.preventDefault()
        e.stopPropagation()
        var action = btn.getAttribute('data-action')
        if (action === 'move-up') post('block-move', { id: selectedId, delta: -1 })
        if (action === 'move-down') post('block-move', { id: selectedId, delta: 1 })
        if (action === 'duplicate') post('block-duplicate', { id: selectedId })
        if (action === 'delete') post('block-delete-request', { id: selectedId })
        if (action === 'add-after') post('block-add-after', { id: selectedId })
        return
      }
      var w = wrapperFor(e.target)
      if (!w) return
      e.preventDefault()
      e.stopPropagation()
      selectWrapper(w)
      post('block-select', { id: w.getAttribute('data-lemma-block') })
    }, true)
    post('blocks-index', { ids: idsIndex() })
  }

  window.addEventListener('message', function (event) {
    var data = event.data || {}
    if (!session) {
      if (data.type === 'lemma:canvas-hello' && typeof data.nonce === 'string') {
        session = { origin: event.origin, nonce: data.nonce }
        activate()
      }
      return
    }
    if (event.origin !== session.origin || data.nonce !== session.nonce) return
    if (data.type === 'lemma:highlight') {
      // Outline-driven selection now behaves like a stage click: ring + toolbar.
      var el = findBlock(data.id)
      if (el) selectWrapper(el)
      else clearSelection()
    }
    if (data.type === 'lemma:scroll-to') {
      var t = findBlock(data.id)
      if (t && t.firstElementChild) {
        t.firstElementChild.scrollIntoView({ block: 'center', behavior: 'smooth' })
      }
    }
    if (data.type === 'lemma:mirror-move') mirrorMove(data.id, data.beforeId, data.afterId)
    if (data.type === 'lemma:mirror-remove') mirrorRemove(data.id)
    if (data.type === 'lemma:mirror-duplicate') mirrorDuplicate(data.sourceId, data.idMap)
  })
})()
```

- [ ] **Step 4: Append the toolbar styles**

Append to `packages/lemma-render/assets/preview/preview.css`:

```css
/* Stage toolbar (stage-toolbar spec §3): positioned by DOM placement + these
   STATIC rules only — never inline geometry (CSP pin). The anchor class goes
   on the selected block's first element child; the toolbar sits absolute
   against it with constant offsets. */
.lemma-canvas-anchor { position: relative; }
.lemma-canvas-toolbar {
  position: absolute;
  top: -14px;
  right: 8px;
  z-index: 2147483000;
  display: flex;
  gap: 2px;
  padding: 2px;
  border-radius: 6px;
  background: #18181b;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
}
.lemma-canvas-toolbar button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  padding: 0;
  border: 0;
  border-radius: 4px;
  background: transparent;
  color: #e4e4e7;
  cursor: pointer;
}
.lemma-canvas-toolbar button:hover { background: #3f3f46; }
.lemma-canvas-toolbar svg { width: 14px; height: 14px; }
```

- [ ] **Step 5: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (7 tests).

Also run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Render/PreviewAnnotationTest.php`
Expected: PASS — the asset-serving tests assert content/headers, not byte equality; if any assert on file substrings, update them to a stable marker (e.g. `lemma:canvas-hello`), which is unchanged.

---

### Task 5: Canvas-side bridge API + canvas page wiring

**Files:**
- Modify: `admin/src/composables/useCanvasBridge.ts`
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`
- Test: `admin/src/__tests__/canvas-bridge.spec.ts` (composable messages), `admin/src/__tests__/canvas-page.spec.ts` (wiring)

**Interfaces:**
- Consumes: Task 3's `FieldEditor` exposed methods; Task 4's message shapes.
- Produces: `useCanvasBridge` additions — `onBlockMove(cb: (id: string, delta: 1 | -1) => void)`, `onBlockDuplicate(cb)`, `onBlockDeleteRequest(cb)`, `onBlockAddAfter(cb)` (all `(id: string) => void` unless noted), `mirrorMove(id: string, neighbor: { beforeId: string } | { afterId: string })`, `mirrorRemove(id: string)`, `mirrorDuplicate(sourceId: string, idMap: Record<string, string>)`.

- [ ] **Step 1: Write the failing composable tests**

Append to the `useCanvasBridge` describe block in `admin/src/__tests__/canvas-bridge.spec.ts` (reuse the file's existing iframe-ref + MessageEvent helpers):

```ts
  it('dispatches intents to their callbacks with nonce filtering', () => {
    const bridge = useCanvasBridge(ref(null))
    const move = vi.fn()
    const dup = vi.fn()
    const del = vi.fn()
    const add = vi.fn()
    bridge.onBlockMove(move)
    bridge.onBlockDuplicate(dup)
    bridge.onBlockDeleteRequest(del)
    bridge.onBlockAddAfter(add)

    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-move', id: 'b1', delta: -1, nonce: bridge.nonce },
      }),
    )
    expect(move).toHaveBeenCalledWith('b1', -1)
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-move', id: 'b1', delta: 1, nonce: 'wrong' },
      }),
    )
    expect(move).toHaveBeenCalledTimes(1)
    // Malformed delta dropped.
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-move', id: 'b1', delta: 5, nonce: bridge.nonce },
      }),
    )
    expect(move).toHaveBeenCalledTimes(1)

    for (const [type, cb] of [
      ['lemma:block-duplicate', dup],
      ['lemma:block-delete-request', del],
      ['lemma:block-add-after', add],
    ] as const) {
      window.dispatchEvent(
        new MessageEvent('message', { data: { type, id: 'b2', nonce: bridge.nonce } }),
      )
      expect(cb).toHaveBeenCalledWith('b2')
    }
    bridge.dispose()
  })

  it('posts mirror commands to the derived origin with the nonce', () => {
    // Same iframe-double pattern as the existing highlight/scrollTo test.
    const postSpy = vi.fn()
    const iframe = ref({
      src: 'https://site.test/_preview/tok123',
      contentWindow: { postMessage: postSpy },
    } as unknown as HTMLIFrameElement)
    const bridge = useCanvasBridge(iframe as Ref<HTMLIFrameElement | null>)

    bridge.mirrorMove('b1', { beforeId: 'b2' })
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:mirror-move', id: 'b1', beforeId: 'b2', nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.mirrorMove('b1', { afterId: 'b3' })
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:mirror-move', id: 'b1', afterId: 'b3', nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.mirrorRemove('b1')
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:mirror-remove', id: 'b1', nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.mirrorDuplicate('b1', { b1: 'b9' })
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:mirror-duplicate', sourceId: 'b1', idMap: { b1: 'b9' }, nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.dispose()
  })
```

(The first test needs no iframe at all — intent dispatch only reads incoming messages; the second reuses the file's existing iframe-double object pattern.)

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts`
Expected: FAIL — `bridge.onBlockMove is not a function`.

- [ ] **Step 3: Implement the composable additions**

In `admin/src/composables/useCanvasBridge.ts`:

Extend `BridgeMessage`:

```ts
interface BridgeMessage {
  type?: string
  nonce?: string
  id?: string
  ids?: string[]
  delta?: number
}
```

Add the callback slots next to the existing ones:

```ts
  let moveCb: ((id: string, delta: 1 | -1) => void) | null = null
  let duplicateCb: ((id: string) => void) | null = null
  let deleteRequestCb: ((id: string) => void) | null = null
  let addAfterCb: ((id: string) => void) | null = null
```

Extend `onMessage` (after the existing branches):

```ts
    if (data.type === 'lemma:block-move' && typeof data.id === 'string') {
      if (data.delta === 1 || data.delta === -1) moveCb?.(data.id, data.delta)
    }
    if (data.type === 'lemma:block-duplicate' && typeof data.id === 'string') {
      duplicateCb?.(data.id)
    }
    if (data.type === 'lemma:block-delete-request' && typeof data.id === 'string') {
      deleteRequestCb?.(data.id)
    }
    if (data.type === 'lemma:block-add-after' && typeof data.id === 'string') {
      addAfterCb?.(data.id)
    }
```

Extend the returned object (before `dispose`):

```ts
    onBlockMove(cb: (id: string, delta: 1 | -1) => void): void {
      moveCb = cb
    },
    onBlockDuplicate(cb: (id: string) => void): void {
      duplicateCb = cb
    },
    onBlockDeleteRequest(cb: (id: string) => void): void {
      deleteRequestCb = cb
    },
    onBlockAddAfter(cb: (id: string) => void): void {
      addAfterCb = cb
    },
    // Mirrors (stage-toolbar spec §1): posted ONLY after the tree committed.
    mirrorMove(id: string, neighbor: { beforeId: string } | { afterId: string }): void {
      post({ type: 'lemma:mirror-move', id, ...neighbor })
    },
    mirrorRemove(id: string): void {
      post({ type: 'lemma:mirror-remove', id })
    },
    mirrorDuplicate(sourceId: string, idMap: Record<string, string>): void {
      post({ type: 'lemma:mirror-duplicate', sourceId, idMap })
    },
```

- [ ] **Step 4: Run to verify composable pass**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts`
Expected: PASS.

- [ ] **Step 5: Write the failing canvas-page tests**

In `admin/src/__tests__/canvas-page.spec.ts`, mock the bridge composable (add near the other mocks; the REAL composable is covered by canvas-bridge.spec, so the page suite asserts wiring only):

```ts
const bridge = vi.hoisted(() => {
  const callbacks: {
    select?: (id: string) => void
    hover?: (id: string) => void
    index?: (ids: string[]) => void
    move?: (id: string, d: 1 | -1) => void
    duplicate?: (id: string) => void
    deleteRequest?: (id: string) => void
    addAfter?: (id: string) => void
  } = {}
  return {
    callbacks,
    instance: {
      nonce: 'n',
      hello: vi.fn(),
      onBlockSelect: (cb: (id: string) => void) => (callbacks.select = cb),
      onBlockHover: (cb: (id: string) => void) => (callbacks.hover = cb),
      onBlocksIndex: (cb: (ids: string[]) => void) => (callbacks.index = cb),
      onBlockMove: (cb: (id: string, d: 1 | -1) => void) => (callbacks.move = cb),
      onBlockDuplicate: (cb: (id: string) => void) => (callbacks.duplicate = cb),
      onBlockDeleteRequest: (cb: (id: string) => void) => (callbacks.deleteRequest = cb),
      onBlockAddAfter: (cb: (id: string) => void) => (callbacks.addAfter = cb),
      highlight: vi.fn(),
      scrollTo: vi.fn(),
      mirrorMove: vi.fn(),
      mirrorRemove: vi.fn(),
      mirrorDuplicate: vi.fn(),
      dispose: vi.fn(),
    },
  }
})
vi.mock('@/composables/useCanvasBridge', () => ({ useCanvasBridge: () => bridge.instance }))
```

In `beforeEach`, add resets:

```ts
    bridge.instance.mirrorMove.mockClear()
    bridge.instance.mirrorRemove.mockClear()
    bridge.instance.mirrorDuplicate.mockClear()
```

And make the draft fixture two blocks so a move has room (replace the existing `draft.value =` assignment):

```ts
  draft.value = {
    fields: {
      title: 'T',
      body: [
        { id: 'blockaaa0001', type: 'card', data: { title: 'A' } },
        { id: 'blockbbb0002', type: 'card', data: { title: 'B' } },
      ],
    },
    lock_version: 3,
  }
```

Append the new tests to `describe('canvas page', …)`:

```ts
  it('move intent mutates the tree and posts mirror-move; boundary posts nothing', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.move?.('blockaaa0001', 1)
    await flushPromises()
    expect(bridge.instance.mirrorMove).toHaveBeenCalledWith('blockaaa0001', {
      afterId: 'blockbbb0002',
    })

    bridge.instance.mirrorMove.mockClear()
    bridge.callbacks.move?.('blockaaa0001', 1) // now last -> boundary no-op
    await flushPromises()
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('delete intent needs the parent-side confirm; cancel does nothing', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.deleteRequest?.('blockaaa0001')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-delete-confirm"]').exists()).toBe(true)
    expect(bridge.instance.mirrorRemove).not.toHaveBeenCalled()

    await wrapper.find('[data-test="canvas-delete-cancel"]').trigger('click')
    expect(wrapper.find('[data-test="canvas-delete-confirm"]').exists()).toBe(false)
    expect(bridge.instance.mirrorRemove).not.toHaveBeenCalled()

    bridge.callbacks.deleteRequest?.('blockaaa0001')
    await flushPromises()
    await wrapper.find('[data-test="canvas-delete-confirm-yes"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.mirrorRemove).toHaveBeenCalledWith('blockaaa0001')
    wrapper.unmount()
  })

  it('duplicate intent posts mirror-duplicate with the idMap and selects the copy', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.duplicate?.('blockaaa0001')
    await flushPromises()
    expect(bridge.instance.mirrorDuplicate).toHaveBeenCalledWith(
      'blockaaa0001',
      expect.objectContaining({ blockaaa0001: expect.any(String) }),
    )
    wrapper.unmount()
  })

  it('add-after opens the per-list picker; choosing inserts, selects, and posts NO mirror', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.addAfter?.('blockaaa0001')
    await flushPromises()
    const picker = wrapper.find('[data-test="canvas-add-picker"]')
    expect(picker.exists()).toBe(true)
    await picker.find('[data-test="canvas-add-type-card"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled()
    expect(bridge.instance.mirrorDuplicate).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="canvas-add-picker"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('save failure reloads the SAME iframe URL without re-minting, keeping dirty fields', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    // Make the tree dirty via a structural op (this is the mirror-then-fail scenario).
    bridge.callbacks.move?.('blockaaa0001', 1)
    await flushPromises()

    saveMock.mockRejectedValueOnce(new ApiError('conflict', 409, {}, { success: false }))
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    await flushPromises()

    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1') // SAME URL
    expect(iframe.element).not.toBe(before) // remounted -> reloaded
    expect(mintMock).toHaveBeenCalledTimes(1) // NO re-mint on failure
    expect(notify.warning).toHaveBeenCalled() // banner still shows
    // Pinned product rule: local edits SURVIVE the stage reset — the dirty chip
    // (UChip show) stays on because fields still differ from the loaded draft.
    expect(wrapper.findComponent({ name: 'UChip' }).props('show')).toBe(true)
    wrapper.unmount()
  })
```

- [ ] **Step 6: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-page.spec.ts`
Expected: FAIL — the page registers none of the new callbacks; the confirm/picker hooks don't exist.

- [ ] **Step 7: Wire the canvas page**

In `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`:

**(a)** Extend imports: add `nextTick` to the vue import; add `import type { BlockType } from '@/queries/blockTypes'`.

**(b)** Widen the FieldEditor ref type (replace the existing `fieldEditorRef` line):

```ts
interface FieldEditorExposed {
  selectBlockById: (id: string) => boolean
  moveBlockById: (id: string, delta: number) => { beforeId: string } | { afterId: string } | null
  duplicateBlockById: (id: string) => { newId: string; idMap: Record<string, string> } | null
  deleteBlockById: (id: string) => boolean
  insertAfterById: (id: string, typeSlug: string) => string | null
  pickerTypesForBlock: (id: string) => BlockType[]
}
const fieldEditorRef = ref<FieldEditorExposed | null>(null)
```

**(c)** After the existing `onOutlineSelect`, add the structural wiring:

```ts
// ── Stage toolbar intents (stage-toolbar spec §2/§4): mutate through the
// FieldEditor (single tree authority), mirror ONLY after the commit. ─────────
bridge.onBlockMove((id, delta) => {
  const neighbor = fieldEditorRef.value?.moveBlockById(id, delta) ?? null
  if (neighbor) bridge.mirrorMove(id, neighbor)
})

bridge.onBlockDuplicate((id) => {
  const result = fieldEditorRef.value?.duplicateBlockById(id) ?? null
  if (result) {
    bridge.mirrorDuplicate(id, result.idMap)
    selected.value = result.newId
    fieldEditorRef.value?.selectBlockById(result.newId)
  }
})

// Delete is parent-confirmed (review pin): the bridge only ever REQUESTS.
const deleteRequest = ref<string | null>(null)
bridge.onBlockDeleteRequest((id) => {
  deleteRequest.value = id
})

function confirmDelete(): void {
  const id = deleteRequest.value
  deleteRequest.value = null
  if (id !== null && fieldEditorRef.value?.deleteBlockById(id)) {
    bridge.mirrorRemove(id)
    if (selected.value === id) selected.value = null
  }
}

// Add-after: parent-side picker over the CONTAINING list's rules (spec §5).
// No mirror — the new block appears in the stage on the next Save & refresh.
const addAfterId = ref<string | null>(null)
const addAfterTypes = ref<BlockType[]>([])
bridge.onBlockAddAfter((id) => {
  addAfterTypes.value = fieldEditorRef.value?.pickerTypesForBlock(id) ?? []
  addAfterId.value = id
})

function chooseAddType(slug: string): void {
  const id = addAfterId.value
  addAfterId.value = null
  const newId = id !== null ? (fieldEditorRef.value?.insertAfterById(id, slug) ?? null) : null
  if (newId !== null) selected.value = newId
}
```

**(d)** Save-failure reset (review pin). Add the helper after `refreshPreview`:

```ts
/**
 * Save-failure reset (stage-toolbar spec §2): discard mirror-only DOM by
 * remounting the iframe on the SAME URL (v-if unmount + remount = reload).
 * No re-mint — that stays behind the explicit Refresh preview affordance.
 */
function reloadStage(): void {
  const src = iframeSrc.value
  if (!src) return
  iframeSrc.value = ''
  void nextTick(() => {
    iframeSrc.value = src
  })
}
```

And in `saveAndRefresh`'s `catch`, add `reloadStage()` as the first line (before the `if (e instanceof ApiError …)` branching), so every failure path resets the stage:

```ts
  } catch (e: unknown) {
    reloadStage() // discard optimistic mirrors — the stage falls back to last-applied truth
    // BYTE-MIRROR of the editor's onSave 409 branches.
    if (e instanceof ApiError && e.status === 409) {
```

**(e)** Template: inside the stage container `div` (`data-test="canvas-stage"`), after the iframe's enclosing width `div`, add the two floating panels:

```html
          <!-- Parent-side delete confirm (stage-toolbar spec §4): the bridge only requests. -->
          <div
            v-if="deleteRequest"
            class="absolute inset-x-0 top-3 z-10 mx-auto w-fit rounded-lg border border-default bg-default p-3 shadow-lg"
            data-test="canvas-delete-confirm"
          >
            <p class="mb-2 text-sm font-medium">Delete this block?</p>
            <div class="flex justify-end gap-2">
              <UButton
                size="xs"
                variant="ghost"
                color="neutral"
                data-test="canvas-delete-cancel"
                @click="deleteRequest = null"
              >
                Cancel
              </UButton>
              <UButton size="xs" color="error" data-test="canvas-delete-confirm-yes" @click="confirmDelete()">
                Delete
              </UButton>
            </div>
          </div>

          <!-- Add-after picker (stage-toolbar spec §5): the containing list's types. -->
          <div
            v-if="addAfterId"
            class="absolute inset-x-0 top-3 z-10 mx-auto w-64 rounded-lg border border-default bg-default p-2 shadow-lg"
            data-test="canvas-add-picker"
          >
            <p class="mb-1 px-1 text-xs font-semibold uppercase tracking-wide text-muted">Add block after</p>
            <button
              v-for="t in addAfterTypes"
              :key="t.slug"
              class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-elevated"
              type="button"
              :data-test="`canvas-add-type-${t.slug}`"
              @click="chooseAddType(t.slug)"
            >
              <UIcon :name="t.icon || 'i-lucide-box'" />
              <span class="font-medium">{{ t.label }}</span>
            </button>
            <p v-if="!addAfterTypes.length" class="px-2 py-1.5 text-sm text-muted">
              No block types available here.
            </p>
            <div class="mt-1 flex justify-end">
              <UButton size="xs" variant="ghost" color="neutral" data-test="canvas-add-cancel" @click="addAfterId = null">
                Cancel
              </UButton>
            </div>
          </div>
```

Also add `relative` to the stage container's classes so the panels anchor to it (change `class="min-w-0 flex-1 overflow-auto rounded-lg …"` to include `relative`).

- [ ] **Step 8: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-page.spec.ts && pnpm type-check`
Expected: PASS (existing 6 + new 5), type-check clean. Note: the existing outline test asserts inspector focus after `canvas-outline-item` click — unaffected. The v1 iframe/viewport/409 tests keep passing against the mocked bridge.

---

### Task 6: Docs, full gates, STAGE (stop for commit authorization)

**Files:**
- Modify: `packages/lemma-render/README.md` (extend the "Canvas annotation (preview only)" paragraph)
- Modify: `CHANGELOG.md` (`[Unreleased]` bullet)

- [ ] **Step 1: README**

In `packages/lemma-render/README.md`, extend the "Canvas annotation (preview only)" section with one paragraph after the existing bridge description:

```markdown
In a canvas session the bridge also renders a small toolbar on the selected
block (move up/down, duplicate, delete, add block after). The toolbar posts
intents to the admin canvas; the block tree is mutated there, and the canvas
answers with mirror commands that update the preview DOM optimistically until
the next Save & refresh re-renders the truth. All toolbar styling lives in the
static `/_preview.css` (never inline styles); the toolbar is positioned by DOM
placement inside the selected block's first element, so blocks whose templates
render no element (text-only output) get selection but no toolbar.
```

- [ ] **Step 2: CHANGELOG**

Append to the `[Unreleased]` section of `CHANGELOG.md`, after the visual canvas (v1) bullet:

```markdown
- Canvas stage toolbar (v2): selecting a block in the Design view's stage now
  shows an in-preview toolbar — move up/down, duplicate, delete (confirmed in
  the admin), and add-after (per-list block picker). Structural edits route
  through the inspector's block tree and mirror optimistically in the stage
  until the next Save & refresh; save failures reload the stage to the
  last-applied render. The inspector's insert menus now respect a nested
  container region's own `block_types` allowlist.
```

- [ ] **Step 3: Full verification (honest — all gates)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"          # expect PHPCS_EXIT=0
composer boundaries                                 # expect "Pack boundaries OK"
vendor/bin/phpunit --testsuite Unit                 # expect OK
vendor/bin/phpunit --testsuite Integration          # expect OK (1 pre-existing skip)
cd admin && pnpm type-check && pnpm test            # expect clean + all pass
```

- [ ] **Step 4: STAGE (commit only when authorized)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add \
  admin/src/fields/components/blocks/useBlockListOps.ts \
  admin/src/fields/components/blocks/context.ts \
  admin/src/fields/components/blocks/BlockList.vue \
  admin/src/fields/components/blocks/BlockInsertMenu.vue \
  admin/src/fields/components/BlocksField.vue \
  admin/src/components/FieldEditor.vue \
  admin/src/composables/useCanvasBridge.ts \
  "admin/src/pages/content/[type]/[uuid]/design/[locale].vue" \
  admin/src/__tests__ \
  packages/lemma-render \
  CHANGELOG.md \
  docs/superpowers
git status --short
```

Then STOP and report, awaiting explicit commit authorization. Prepared message:

```
feat(admin): canvas stage toolbar — structural editing from the preview

- In-iframe toolbar on the selected block: move up/down, duplicate,
  delete (parent-confirmed), add-after (per-list picker)
- Intent -> BlocksField mutation -> mirror protocol: the stage DOM
  mirrors committed ops optimistically; save failures reload the stage
- Per-list picker rules: nested container regions' block_types
  allowlists now respected by BOTH the inspector insert menus and the
  canvas add-after picker (one context resolver)
- Bridge direct-test suite: preview-bridge.js evaluated in jsdom
```

Recorded manual/browser acceptance (report as outstanding): toolbar placement across real theme markup, mirror fidelity vs post-apply truth after mixed op sequences, confirm modal flow, plus the outstanding v1 items.
