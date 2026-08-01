# Canvas v7: Stage Keyboard Shortcuts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** With a block selected in the stage iframe, the keyboard drives the existing intent protocol — Alt+Arrows move, Backspace/Delete requests the parent-confirmed delete, Cmd/Ctrl+D duplicates, Enter enters edit mode on a single-region block, Escape deselects (with a new `block-deselect` notification so parent state stays honest).

**Architecture:** One document-capture keydown handler in the static bridge asset, guarded against edit sessions, drags, theme form controls, and the bridge's own toolbar. Every action posts an EXISTING intent verbatim; the only new message is the `lemma:block-deselect` notification (iframe → parent, not a mutation). Parent side is one composable callback plus one page line.

**Tech Stack:** Vanilla JS bridge asset (ES5-style, CSP no-inline-styles pin), Vue 3 + TypeScript admin, Vitest + jsdom.

**Spec:** `docs/superpowers/specs/2026-07-03-canvas-keyboard-shortcuts-design.md`

## Global Constraints

- NO commits during execution: stage (`git add`) at the final task only; the user commits with an explicit "commit all". Never stage/commit `CLAUDE.md`.
- Bridge asset stays ES5-flavored (`var`, `function`), no inline styles (CSP pin), silent-until-hello.
- `preview-bridge-dom.spec.ts` is ONE eval per file: selection/suppressor state leaks across tests — tests that need "nothing selected" must establish it explicitly (press Escape first).
- No `Array.prototype.at(-1)` / `findLast` in admin TS (tsconfig lib predates es2022).
- Reuse existing intents byte-identically: `block-move`, `block-delete-request`, `block-duplicate`, `edit-request`. The ONLY new message is `lemma:block-deselect {id}`.
- Enter acts ONLY when the selected block OWNS exactly one `.lemma-edit-region` — regions matched with `[data-lemma-edit-block="<selectedId>"]`, never a bare subtree query (a container block's subtree includes nested CHILD-block regions — review P1). Zero or 2+ owned regions → ignored. One shared helper serves both the keyboard path and the wrapper-level double-click fallback so pointer and keyboard semantics stay aligned.
- Guards (in order): no selection → return; `editing` → return; `drag` → return; target inside `.lemma-canvas-toolbar` → return; target is `input`/`textarea`/`select`/contenteditable → return.

---

### Task 1: Bridge keydown handler + direct-eval tests

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts`

**Interfaces:**
- Consumes: existing bridge state (`selectedId`, `editing`, `drag`, `lastPointer`), `post()`, `findBlock()`, `clearSelection()`.
- Produces: outbound `lemma:block-deselect {id}` message (Task 2's composable branch consumes it); keyboard-posted `lemma:block-move`/`block-delete-request` (rect-less)/`block-duplicate`/`edit-request` in their existing shapes.

- [ ] **Step 1: Write the failing tests**

Append a new describe at the END of `admin/src/__tests__/preview-bridge-dom.spec.ts` (after the drag describe — selection leaked by earlier describes is real; the first test clears it via Escape):

```ts
describe('stage keyboard shortcuts', () => {
  function pressKey(init: KeyboardEventInit, target: Element = document.body): KeyboardEvent {
    const ev = new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init })
    target.dispatchEvent(ev)
    return ev
  }

  function selectByClick(w: HTMLElement): void {
    w.querySelector('section, hr, p')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  }

  it('does nothing while no block is selected', () => {
    // One eval per file: an earlier describe may have leaked a selection —
    // Escape establishes the deselected baseline this test asserts from.
    pressKey({ key: 'Escape' })
    posted.mockClear()
    pressKey({ key: 'ArrowDown', altKey: true })
    pressKey({ key: 'Backspace' })
    pressKey({ key: 'd', metaKey: true })
    pressKey({ key: 'Enter' })
    expect(posted).not.toHaveBeenCalled()
  })

  it('Alt+Arrows post block-move; plain arrows pass through untouched', () => {
    const w = wrapper('kb-mv-000001')
    document.body.appendChild(w)
    selectByClick(w)
    posted.mockClear()

    const up = pressKey({ key: 'ArrowUp', altKey: true })
    expect(lastPost('lemma:block-move')).toMatchObject({ id: 'kb-mv-000001', delta: -1 })
    expect(up.defaultPrevented).toBe(true)

    pressKey({ key: 'ArrowDown', altKey: true })
    expect(lastPost('lemma:block-move')).toMatchObject({ id: 'kb-mv-000001', delta: 1 })

    posted.mockClear()
    const plain = pressKey({ key: 'ArrowDown' }) // no Alt: scrolling stays native
    expect(posted).not.toHaveBeenCalled()
    expect(plain.defaultPrevented).toBe(false)
  })

  it('Backspace and Delete post a rect-less delete request (centered confirm)', () => {
    const w = wrapper('kb-del-00001')
    document.body.appendChild(w)
    selectByClick(w)
    posted.mockClear()

    pressKey({ key: 'Backspace' })
    const req = lastPost('lemma:block-delete-request')!
    expect(req).toMatchObject({ id: 'kb-del-00001' })
    expect(req.rect).toBeUndefined()

    posted.mockClear()
    pressKey({ key: 'Delete' })
    expect(lastPost('lemma:block-delete-request')).toMatchObject({ id: 'kb-del-00001' })
  })

  it('Cmd/Ctrl+D posts block-duplicate and beats the browser bookmark', () => {
    const w = wrapper('kb-dup-00001')
    document.body.appendChild(w)
    selectByClick(w)
    posted.mockClear()

    const meta = pressKey({ key: 'd', metaKey: true })
    expect(lastPost('lemma:block-duplicate')).toMatchObject({ id: 'kb-dup-00001' })
    expect(meta.defaultPrevented).toBe(true)

    posted.mockClear()
    pressKey({ key: 'D', ctrlKey: true })
    expect(lastPost('lemma:block-duplicate')).toMatchObject({ id: 'kb-dup-00001' })

    posted.mockClear()
    pressKey({ key: 'd' }) // unmodified d: plain typing, no intent
    expect(posted).not.toHaveBeenCalled()
  })

  it('Enter posts edit-request ONLY for a single-region wrapper (spec pin)', () => {
    const one = proseWrapper('kb-ent-00001')
    document.body.appendChild(one)
    selectByClick(one)
    posted.mockClear()
    pressKey({ key: 'Enter' })
    expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'kb-ent-00001', field: 'body' })

    // Zero regions: ignored.
    const zero = wrapper('kb-ent-00002')
    document.body.appendChild(zero)
    selectByClick(zero)
    posted.mockClear()
    const zev = pressKey({ key: 'Enter' })
    expect(posted).not.toHaveBeenCalled()
    expect(zev.defaultPrevented).toBe(false)

    // Two regions (CTA-style): ambiguous, ignored.
    const two = wrapper(
      'kb-ent-00003',
      '<section>' +
        '<span class="lemma-edit-region" data-lemma-edit-block="kb-ent-00003" data-lemma-edit-field="heading">H</span>' +
        '<span class="lemma-edit-region" data-lemma-edit-block="kb-ent-00003" data-lemma-edit-field="label">L</span>' +
        '</section>',
    )
    document.body.appendChild(two)
    selectByClick(two)
    posted.mockClear()
    pressKey({ key: 'Enter' })
    expect(lastPost('lemma:edit-request')).toBeUndefined()

    // Container block (nested blocks()): the CHILD's region does not count as
    // the parent's — Enter on the selected parent stays inert (review P1).
    const parent = wrapper(
      'kb-ent-00004',
      '<section><div class="lemma-preview-block" data-lemma-block="kb-ent-child1">' +
        '<section><div class="lemma-edit-region" data-lemma-edit-block="kb-ent-child1" ' +
        'data-lemma-edit-field="body"><p>child</p></div></section></div></section>',
    )
    document.body.appendChild(parent)
    selectByClick(parent) // querySelector('section') hits the OUTER section -> parent selected
    posted.mockClear()
    pressKey({ key: 'Enter' })
    expect(lastPost('lemma:edit-request')).toBeUndefined()
  })

  it('wrapper-level double-click on a container no longer adopts a CHILD region', () => {
    // The shared owned-region helper aligns the POINTER fallback with Enter
    // (review P1): before it, a container double-click posted edit-request
    // for the child block while the parent was the click target.
    const parent = wrapper(
      'kb-dbl-00001',
      '<section><div class="lemma-preview-block" data-lemma-block="kb-dbl-child1">' +
        '<section><div class="lemma-edit-region" data-lemma-edit-block="kb-dbl-child1" ' +
        'data-lemma-edit-field="body"><p>child</p></div></section></div></section>',
    )
    document.body.appendChild(parent)
    posted.mockClear()
    parent.querySelector('section')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toBeUndefined()

    // Double-click INSIDE the child's region still addresses the child directly.
    parent.querySelector('p')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toMatchObject({
      id: 'kb-dbl-child1',
      field: 'body',
    })
  })

  it('Escape clears the selection locally and posts block-deselect', () => {
    const w = wrapper('kb-esc-00001')
    document.body.appendChild(w)
    selectByClick(w)
    expect(w.querySelector('.lemma-canvas-toolbar')).not.toBeNull()
    posted.mockClear()

    pressKey({ key: 'Escape' })
    expect(lastPost('lemma:block-deselect')).toMatchObject({ id: 'kb-esc-00001' })
    expect(w.classList.contains('lemma-canvas-selected')).toBe(false)
    expect(w.querySelector('.lemma-canvas-toolbar')).toBeNull()

    // Deselected: further shortcuts are inert.
    posted.mockClear()
    pressKey({ key: 'ArrowDown', altKey: true })
    expect(posted).not.toHaveBeenCalled()
  })

  it('guards: toolbar focus, theme form controls, and edit sessions swallow nothing', () => {
    const w = proseWrapper('kb-grd-00001')
    document.body.appendChild(w)
    selectByClick(w)

    // Toolbar guard (review pin): Enter on a focused toolbar button keeps its
    // native activation — the handler must not intercept it as "edit block".
    posted.mockClear()
    const dupBtn = w.querySelector('.lemma-canvas-toolbar [data-action="duplicate"]')!
    const tev = pressKey({ key: 'Enter' }, dupBtn)
    expect(lastPost('lemma:edit-request')).toBeUndefined()
    expect(tev.defaultPrevented).toBe(false)
    pressKey({ key: 'Backspace' }, dupBtn)
    expect(lastPost('lemma:block-delete-request')).toBeUndefined()

    // Theme form control guard: Backspace in an input is typing, not delete.
    const formW = wrapper('kb-grd-00002', '<section><input type="text"></section>')
    document.body.appendChild(formW)
    selectByClick(formW)
    posted.mockClear()
    pressKey({ key: 'Backspace' }, formW.querySelector('input')!)
    expect(lastPost('lemma:block-delete-request')).toBeUndefined()

    // Edit-session guard: typing must never move/delete blocks. Re-select the
    // prose wrapper, grant an edit, then hammer the shortcuts.
    selectByClick(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'kb-grd-00001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.lemma-edit-region')!
    expect(region.getAttribute('contenteditable')).toBe('true')
    posted.mockClear()
    pressKey({ key: 'ArrowUp', altKey: true }, region)
    pressKey({ key: 'Backspace' }, region)
    pressKey({ key: 'd', metaKey: true }, region)
    expect(lastPost('lemma:block-move')).toBeUndefined()
    expect(lastPost('lemma:block-delete-request')).toBeUndefined()
    expect(lastPost('lemma:block-duplicate')).toBeUndefined()
    // Escape during editing keeps its commit-and-exit meaning (region handler).
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('lemma:edit-end')).toMatchObject({ id: 'kb-grd-00001' })
    expect(lastPost('lemma:block-deselect')).toBeUndefined()
  })
})
```

ALSO add the drag-guard test (review P2) — but inside the EXISTING `describe('free drag', …)` block (~line 531), after its Escape-rollback test, because it needs that describe's scoped `dragList`/`gripDown`/`pointerMove`/`order` helpers:

```ts
  it('keyboard shortcuts are inert while dragging; Escape means rollback, never deselect', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()
    pointerMove(160)
    expect(order(list)[0]).toBe('fd-b-0000002')

    // Mid-drag, the shortcut handler must bail on the drag guard.
    const press = (init: KeyboardEventInit) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init }),
      )
    press({ key: 'ArrowDown', altKey: true })
    press({ key: 'Backspace' })
    press({ key: 'd', metaKey: true })
    expect(lastPost('lemma:block-move')).toBeUndefined()
    expect(lastPost('lemma:block-delete-request')).toBeUndefined()
    expect(lastPost('lemma:block-duplicate')).toBeUndefined()

    // Escape belongs to the DRAG while one is active: order rolls back, the
    // block STAYS selected, and no block-deselect posts.
    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
    )
    expect(order(list)).toEqual(['fd-a-0000001', 'fd-b-0000002', 'fd-c-0000003'])
    expect(lastPost('lemma:block-deselect')).toBeUndefined()
    expect(a.classList.contains('lemma-canvas-selected')).toBe(true)
  })
```

Notes for the implementer:
- `wrapper`, `proseWrapper`, `lastPost`, `sendToBridge`, `posted` already exist in this file (`wrapper` at ~line 26, `proseWrapper` at ~line 253). `proseWrapper` lives between the first two describes at file top level — it IS in scope for a describe appended at the end.
- Capture-listener ORDER makes the drag-Escape contract work: `onCanvasKeydown` registers at hello (activate), the drag's `onDragKeydown` registers at gripDown — so on one Escape event the shortcut handler runs FIRST, bails on the `drag` guard WITHOUT preventDefault/stopPropagation, and the drag handler then rolls back. The guard paths must never consume the event.
- `selectByClick` uses `'section, hr, p'` because `wrapper()` fixtures render a `<section>` and prose fixtures a `<section><div>…<p>`; clicking any descendant selects through `closest('[data-lemma-block]')`.
- The edit-session guard test dispatches through the REGION (bubbles to document); the capture handler sees it before the region's own listener — the `editing` guard must make it pass through untouched.

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: the 9 new tests FAIL — the 8 keyboard-describe tests because no keydown handler exists (`lastPost('lemma:block-move')` undefined, Escape posts nothing; the container double-click test fails because the OLD fallback posts the child's edit-request), and the drag-guard test only partially (its move/delete/duplicate assertions pass vacuously, but treat any failure as expected pre-implementation). All 27 existing tests still PASS.

- [ ] **Step 3: Implement the keydown handler in the bridge**

In `packages/lemma-render/assets/preview/preview-bridge.js`:

3a. Add the owned-region helper after `regionFor()` (~line 145), and rewrite the double-click fallback to use it. The helper is the ONE authority for "the block's editable region" on both the keyboard and pointer paths (review P1):

```js
  function singleRegionOf(id) {
    // The block's OWN regions only (review P1): a container block's subtree
    // includes nested child-block regions — counting those would start editing
    // a CHILD while the parent is the selected/double-clicked block.
    var w = findBlock(id)
    if (!w) return null
    var regions = w.querySelectorAll(
      '.lemma-edit-region[data-lemma-edit-block="' + cssEscape(id) + '"]'
    )
    return regions.length === 1 ? regions[0] : null
  }
```

Then in the `dblclick` listener inside `activate()` (~line 484), replace the fallback lines

```js
      var region = e.target && e.target.closest ? e.target.closest('.lemma-edit-region') : null
      if (!region || !w.contains(region)) {
        var regions = w.querySelectorAll('.lemma-edit-region')
        region = regions.length === 1 ? regions[0] : null
      }
```

with

```js
      var region = e.target && e.target.closest ? e.target.closest('.lemma-edit-region') : null
      if (!region || !w.contains(region)) {
        // Wrapper-level fallback: ONLY the block's own single region (review
        // P1) — shared with keyboard Enter so the two paths stay aligned.
        region = singleRegionOf(w.getAttribute('data-lemma-block'))
      }
```

3b. Add the keydown handler after `endDrag()` (before the Mirrors section, ~line 396):

```js
  // ── Stage keyboard shortcuts (keyboard-shortcuts spec §1/§2) ────────────────
  // Document-capture so theme markup can't shadow it — which is exactly why the
  // guards must be airtight: never during an edit session or drag (their own
  // handlers own Escape), never from the bridge toolbar (native button keyboard
  // semantics stay intact), never from theme form controls.
  function keyTargetIsFormish(t) {
    if (!t || !t.tagName) return false
    var tag = t.tagName
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true
    if (t.isContentEditable) return true
    return !!(t.closest && t.closest('[contenteditable], input, textarea, select'))
  }

  function onCanvasKeydown(e) {
    if (selectedId === null || editing || drag) return
    var t = e.target
    if (t && t.closest && t.closest('.lemma-canvas-toolbar')) return
    if (keyTargetIsFormish(t)) return
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
      e.preventDefault()
      e.stopPropagation()
      post('block-move', { id: selectedId, delta: e.key === 'ArrowUp' ? -1 : 1 })
      return
    }
    if (e.key === 'Backspace' || e.key === 'Delete') {
      e.preventDefault()
      e.stopPropagation()
      post('block-delete-request', { id: selectedId }) // rect-less -> centered confirm
      return
    }
    if ((e.metaKey || e.ctrlKey) && (e.key === 'd' || e.key === 'D')) {
      e.preventDefault() // beat the browser bookmark shortcut
      e.stopPropagation()
      post('block-duplicate', { id: selectedId })
      return
    }
    if (e.key === 'Enter') {
      // Byte-equivalent to the wrapper-level double-click fallback (spec pin):
      // ONLY the block's own single region — zero, 2+, or child-owned regions
      // are not a target (review P1; same helper as the pointer path).
      var region = singleRegionOf(selectedId)
      if (!region) return
      e.preventDefault()
      e.stopPropagation()
      lastPointer = null // keyboard entry: caret placement falls back to focus()
      post('edit-request', {
        id: region.getAttribute('data-lemma-edit-block'),
        field: region.getAttribute('data-lemma-edit-field')
      })
      return
    }
    if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      var deselectedId = selectedId
      clearSelection()
      post('block-deselect', { id: deselectedId })
    }
  }
```

3c. Register it in `activate()`, after the click listener and before the scroll listener (~line 547):

```js
    document.addEventListener('keydown', onCanvasKeydown, true)
```

- [ ] **Step 4: Run the file to verify all tests pass**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (36 tests). If the drag describe's Escape-rollback tests regress, the `drag` guard is wrong — the new handler must return BEFORE preventDefault so `onDragKeydown` still sees the event.

---

### Task 2: Composable `onBlockDeselect` + dispatch test

**Files:**
- Modify: `admin/src/composables/useCanvasBridge.ts`
- Test: `admin/src/__tests__/canvas-bridge.spec.ts`

**Interfaces:**
- Consumes: the bridge's `lemma:block-deselect {id, nonce}` message (Task 1).
- Produces: `onBlockDeselect(cb: (id: string) => void): void` on the returned bridge object (Task 3 wires it).

- [ ] **Step 1: Write the failing test**

Append to the `describe('useCanvasBridge', …)` block in `admin/src/__tests__/canvas-bridge.spec.ts`:

```ts
  it('block-deselect dispatches the id; missing id dropped', () => {
    const bridge = useCanvasBridge(ref(null))
    const deselect = vi.fn()
    bridge.onBlockDeselect(deselect)

    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-deselect', id: 'b1', nonce: bridge.nonce },
      }),
    )
    expect(deselect).toHaveBeenCalledWith('b1')

    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-deselect', nonce: bridge.nonce },
      }),
    )
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-deselect', id: 'b2', nonce: 'wrong' },
      }),
    )
    expect(deselect).toHaveBeenCalledTimes(1)
    bridge.dispose()
  })
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts`
Expected: FAIL — `bridge.onBlockDeselect is not a function`.

- [ ] **Step 3: Implement in the composable**

In `admin/src/composables/useCanvasBridge.ts`:

3a. Add the callback slot next to `selectCb` (~line 43):

```ts
  let deselectCb: ((id: string) => void) | null = null
```

3b. Add the message branch in `onMessage`, directly under the `lemma:block-select` line (~line 78):

```ts
    if (data.type === 'lemma:block-deselect' && typeof data.id === 'string') deselectCb?.(data.id)
```

3c. Add the registrar to the returned object, directly under `onBlockSelect` (~line 156):

```ts
    onBlockDeselect(cb: (id: string) => void): void {
      deselectCb = cb
    },
```

- [ ] **Step 4: Run it to verify it passes**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts`
Expected: PASS.

---

### Task 3: Page wiring, page test, docs, full gates, STAGE

**Files:**
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`
- Modify: `admin/src/__tests__/canvas-page.spec.ts` (bridge mock + new test)
- Modify: `packages/lemma-render/README.md` (canvas toolbar paragraph)
- Modify: `CHANGELOG.md` (`[Unreleased]` canvas entry)

**Interfaces:**
- Consumes: `bridge.onBlockDeselect` (Task 2), the page's existing `selected: Ref<string | null>`.
- Produces: nothing downstream — this closes the feature.

- [ ] **Step 1: Extend the canvas-page bridge mock**

In `admin/src/__tests__/canvas-page.spec.ts`, in the hoisted mock:

1a. Add to the `callbacks` type (next to `select`, ~line 59):

```ts
    deselect?: (id: string) => void
```

1b. Add to `instance`, directly under `onBlockSelect` (~line 76):

```ts
      onBlockDeselect: (cb: (id: string) => void) => (callbacks.deselect = cb),
```

- [ ] **Step 2: Write the failing page test**

Append near the outline tests in `canvas-page.spec.ts`:

```ts
  it('stage Escape deselect clears the parent selection (outline highlight)', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="canvas-outline-toggle"]').trigger('click')
    bridge.callbacks.select?.('blockaaa0001')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-outline-item-blockaaa0001"]').classes()).toContain(
      'bg-elevated',
    )

    bridge.callbacks.deselect?.('blockaaa0001')
    await flushPromises()
    expect(wrapper.find('[data-test="canvas-outline-item-blockaaa0001"]').classes()).not.toContain(
      'bg-elevated',
    )
    wrapper.unmount()
  })
```

(`CanvasOutline` marks the selected row with `:class="{ 'bg-elevated': row.id === selected }"` — `design/components/CanvasOutline.vue:78`. If that binding sits on a different element than `data-test="canvas-outline-item-*"`, assert on the element that carries both; check the component template first.)

- [ ] **Step 3: Run it to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/canvas-page.spec.ts`
Expected: the new test FAILS (callbacks.deselect never registered — `bg-elevated` still present); everything else PASSES. If instead OTHER tests fail with "onBlockDeselect is not a function", the page wiring landed before the mock — Step 1 must come first.

- [ ] **Step 4: Wire the page**

In `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`, directly after the `bridge.onBlockSelect(…)` block (~line 142):

```ts
// Stage Escape (keyboard-shortcuts spec §3): the bridge already cleared its
// ring/toolbar — without this the parent's selection would go stale and the
// outline/inspector would lie.
bridge.onBlockDeselect(() => {
  selected.value = null
})
```

- [ ] **Step 5: Run the page suite to verify it passes**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/canvas-page.spec.ts`
Expected: PASS.

- [ ] **Step 6: Document**

6a. `packages/lemma-render/README.md` — extend the canvas toolbar paragraph (the one beginning "In a canvas session the bridge also renders a small toolbar…", ~line 158). After the sentence ending "…gets selection but no toolbar.", append to the same paragraph:

```
The selected block also answers the keyboard: Alt/Option+Arrow moves it,
Backspace/Delete asks the admin's delete confirm, Cmd/Ctrl+D duplicates,
Enter opens in-place editing when the block has exactly one editable region
of its own (a container's child-block regions don't count — the same rule
the wrapper-level double-click uses), and Escape deselects. Shortcuts stay
inert while editing in-place, while dragging, and while focus sits in the
toolbar or the theme's own form fields.
```

6b. `CHANGELOG.md` — in `## [Unreleased]`, find the visual-canvas entry chain (the `Follow-up (same day):` lines under the canvas bullet) and append one more follow-up in the same style:

```
  Follow-up (same day): **stage keyboard shortcuts** — with a block selected in
  the stage, Alt/Option+Arrows move it, Backspace/Delete opens the delete
  confirm, Cmd/Ctrl+D duplicates, Enter enters in-place editing (only when the
  block OWNS exactly one editable region — keyboard Enter stays equivalent to
  the wrapper double-click fallback, and both now share one owned-region rule,
  fixing the pointer fallback adopting a container's CHILD region), Escape
  deselects (new `block-deselect` notification keeps outline/inspector
  selection honest). Guarded against edit sessions, drags, the bridge toolbar,
  and theme form controls.
```

- [ ] **Step 7: Full gates**

Run, all from `/Users/michaeltawiahsowah/Sites/glueful/lemma`:

```bash
cd admin && pnpm vitest run          # full admin suite
pnpm type-check                      # vue-tsc — do NOT pipe through tail
pnpm lint
cd .. && vendor/bin/phpunit --filter Preview   # bridge-injection PHP tests untouched but adjacent
```

Expected: all green. The PHP filter run is a safety net — no PHP changed, so any failure there means an injection-regex assertion caught an unintended asset change.

- [ ] **Step 8: STAGE (no commit)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add packages/lemma-render/assets/preview/preview-bridge.js \
        packages/lemma-render/README.md \
        admin/src/composables/useCanvasBridge.ts \
        "admin/src/pages/content/[type]/[uuid]/design/[locale].vue" \
        admin/src/__tests__/preview-bridge-dom.spec.ts \
        admin/src/__tests__/canvas-bridge.spec.ts \
        admin/src/__tests__/canvas-page.spec.ts \
        CHANGELOG.md \
        docs/superpowers/specs/2026-07-03-canvas-keyboard-shortcuts-design.md \
        docs/superpowers/plans/2026-07-03-canvas-keyboard-shortcuts.md
git status
```

Expected: all listed files staged, nothing else. STOP — the user commits.
