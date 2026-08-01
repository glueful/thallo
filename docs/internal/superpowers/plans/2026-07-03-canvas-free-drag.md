# Canvas v6: Free Drag in the Stage — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A grip on the stage toolbar drags the selected block within its list with live sortable-style reordering; one `block-move-to` intent per drag, validated and applied by `BlocksField`.

**Architecture:** Pointer-event drag session in the bridge (live `insertBefore` between same-parent sibling wrappers as the pointer crosses midpoints; the DOM move is the feedback). One intent on `pointerup`, neighbor computed excluding the dragged wrapper. Parent re-checks same-list authority in `BlocksField.moveBlockTo`; rejection reloads the stage with `fields` untouched. No mirror back — the drag was the mirror; auto-apply syncs after the drop.

**Tech Stack:** vanilla-JS bridge (pointer events), Vue 3 admin, vitest jsdom (rect stubs for geometry).

**Spec:** `docs/superpowers/specs/2026-07-03-canvas-free-drag-design.md`

## Global Constraints

- **One intent per drag:** `block-move-to` posts ONLY on `pointerup`, only when the position changed. Pointermove reordering is visual.
- **Neighbor excluding self (review caution):** the posted neighbor is found by scanning outward from the dragged wrapper for the nearest sibling WRAPPER (`[data-lemma-block]`), skipping non-wrapper nodes — it can never be the dragged block or a stale relation.
- **Full rollback (review caution):** Escape/`pointercancel` restore the wrapper before its remembered `originalNext`, remove `.lemma-canvas-dragging`, do NOT set the click suppressor, and detach every drag listener. `endDrag()` is the single cleanup path.
- **Same-parent guard on live moves (review caution):** pointermove candidates whose `parentNode` differs from the dragged wrapper's are skipped — mirror-move's guard, applied to the live path.
- **Authority re-check:** `moveBlockTo` denies unless dragged + reference share `{parentId, region}`; failure returns `false` with NO tree mutation; the page then calls `reloadStage()`.
- **Same-list only; vertical midpoints only** (recorded limitations: cross-container, horizontal lists, edge auto-scroll, touch long-press).
- **CSP pin:** grip cursor + dragging dim are static `preview.css` rules.
- **Commit gate:** STAGE at the end of Task 3 only; commit ONLY on explicit authorization. No attribution trailers.
- **Verification:** admin `cd admin && pnpm type-check && pnpm test`; PHP gates in Task 3 (asset-only render-pack change).

---

### Task 1: Bridge drag session + direct tests

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Modify: `packages/lemma-render/assets/preview/preview.css`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts`

**Interfaces:**
- Produces (Task 2's counterpart message): outbound
  `lemma:block-move-to {id, beforeId}` or `{id, afterId}` (exactly one key),
  posted once per position-changing drag.
- Toolbar action list grows to SIX: `drag` FIRST, then the v2 five — the
  existing "toolbar clicks post intents" test's expected array updates.

- [ ] **Step 1: Update/extend the direct tests**

In `admin/src/__tests__/preview-bridge-dom.spec.ts`:

**(a)** The toolbar-actions assertion in the first test becomes:

```ts
    expect(actions).toEqual(['drag', 'move-up', 'move-down', 'duplicate', 'delete', 'add-after'])
```

**(b)** Append a new describe (uses per-test rect stubs — jsdom rects are zero):

```ts
describe('free drag', () => {
  /** Give each wrapper's first element child a fixed vertical band. */
  function stubRects(wrappers: HTMLElement[], height = 100): void {
    wrappers.forEach((w, i) => {
      const host = w.firstElementChild as HTMLElement
      Object.defineProperty(host, 'getBoundingClientRect', {
        configurable: true,
        value: () => ({
          top: i * height,
          bottom: (i + 1) * height,
          height,
          left: 0,
          right: 500,
          width: 500,
          x: 0,
          y: i * height,
          toJSON: () => ({}),
        }),
      })
    })
  }

  function dragList(): { list: HTMLElement; a: HTMLElement; b: HTMLElement; c: HTMLElement } {
    const list = document.createElement('main')
    const a = wrapper('fd-a-0000001')
    const b = wrapper('fd-b-0000002')
    const c = wrapper('fd-c-0000003')
    list.append(a, b, c)
    document.body.appendChild(list)
    stubRects([a, b, c])
    return { list, a, b, c }
  }

  function gripDown(w: HTMLElement): void {
    // A COMPLETED drag in an earlier test arms the one-shot click suppressor
    // (file-global bridge state under the one-eval-per-file constraint) —
    // consume it with a throwaway non-wrapper click so the select below lands.
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    // Select first (the grip drags the SELECTED block), then press the grip —
    // through its nested SVG (review P3): real pointerdowns target the icon,
    // and the handler must work off currentTarget, not target.
    w.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    const gripSvg = w.querySelector('[data-action="drag"] svg')!
    gripSvg.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, cancelable: true }))
  }

  function pointerMove(y: number): void {
    document.dispatchEvent(
      new MouseEvent('pointermove', { bubbles: true, clientY: y } as MouseEventInit),
    )
  }

  function order(list: HTMLElement): (string | null)[] {
    return [...list.children]
      .filter((el) => el.hasAttribute('data-lemma-block'))
      .map((el) => el.getAttribute('data-lemma-block'))
  }

  it('live-reorders on pointermove WITHOUT posting; pointerup posts ONE block-move-to', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()

    pointerMove(160) // past b's midpoint (150) -> a moves after b
    expect(order(list)).toEqual(['fd-b-0000002', 'fd-a-0000001', 'fd-c-0000003'])
    expect(lastPost('lemma:block-move-to')).toBeUndefined() // visual only

    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    const moves = posted.mock.calls
      .map((c) => c[0] as { type: string })
      .filter((m) => m.type === 'lemma:block-move-to')
    expect(moves).toHaveLength(1)
    expect(moves[0]).toMatchObject({ id: 'fd-a-0000001', beforeId: 'fd-c-0000003' })
  })

  it('a drop at list end posts afterId; a returned-to-origin drop posts nothing', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()
    pointerMove(500) // below every midpoint -> a moves to the end
    expect(order(list)).toEqual(['fd-b-0000002', 'fd-c-0000003', 'fd-a-0000001'])
    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    expect(lastPost('lemma:block-move-to')).toMatchObject({
      id: 'fd-a-0000001',
      afterId: 'fd-c-0000003',
    })

    // Second drag: out and back -> unchanged position -> no post.
    const b = list.children[0] as HTMLElement // now fd-b is first
    stubRects([...list.children] as HTMLElement[])
    gripDown(b)
    posted.mockClear()
    pointerMove(160)
    pointerMove(10) // back above -> restored to first
    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    expect(lastPost('lemma:block-move-to')).toBeUndefined()
  })

  it('Escape rolls back the order, posts nothing, and does NOT swallow the next click', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()
    pointerMove(160)
    expect(order(list)[0]).toBe('fd-b-0000002')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(order(list)).toEqual(['fd-a-0000001', 'fd-b-0000002', 'fd-c-0000003'])
    expect(a.classList.contains('lemma-canvas-dragging')).toBe(false)
    expect(lastPost('lemma:block-move-to')).toBeUndefined()

    // Rollback must not arm the click suppressor: the next click still selects.
    const other = wrapper('fd-d-0000004')
    document.body.appendChild(other)
    other.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(lastPost('lemma:block-select')).toMatchObject({ id: 'fd-d-0000004' })
  })

  it('the click after a completed drag is swallowed once', () => {
    const { a } = dragList()
    gripDown(a)
    pointerMove(160)
    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    posted.mockClear()

    // The post-drag click: swallowed (no select), exactly once.
    a.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(lastPost('lemma:block-select')).toBeUndefined()
    a.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(lastPost('lemma:block-select')).toMatchObject({ id: 'fd-a-0000001' })
  })
})
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: FAIL — no grip action, no drag machinery (and the actions-array test fails on 5 vs 6).

- [ ] **Step 3: Implement the bridge drag session**

In `packages/lemma-render/assets/preview/preview-bridge.js`:

**(a)** State next to `editing`:

```js
  var drag = null // { wrapper, originalNext }
  var suppressClick = false // one-shot: the click after a completed drag
```

**(b)** Prepend the grip to `ACTIONS`:

```js
    { action: 'drag', label: 'Drag to reorder', path: 'M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01' },
```

**(c)** In `ensureToolbar()`, after building the buttons, wire the grip:

```js
    toolbar.querySelector('[data-action="drag"]').addEventListener('pointerdown', onGripDown)
```

**(d)** The toolbar click branch skips the grip (drags are pointer-driven):

```js
        if (action === 'drag') return
```

(first line inside the `if (btn && selectedId !== null)` block, after
`preventDefault`/`stopPropagation`.)

**(e)** The drag session (after the edit-session functions):

```js
  // ── Free drag (free-drag spec §1): live reorder, ONE intent on pointerup ────
  function siblingWrapperFrom(el, dir) {
    // Nearest sibling WRAPPER scanning outward — skips non-wrapper nodes and,
    // by construction, the dragged wrapper itself (review caution).
    var cur = dir > 0 ? el.nextElementSibling : el.previousElementSibling
    while (cur && !(cur.hasAttribute && cur.hasAttribute('data-lemma-block'))) {
      cur = dir > 0 ? cur.nextElementSibling : cur.previousElementSibling
    }
    return cur
  }

  function onGripDown(e) {
    if (editing || drag || selectedId === null) return
    var w = findBlock(selectedId)
    if (!w || !w.parentNode) return
    e.preventDefault()
    drag = { wrapper: w, originalNext: w.nextElementSibling }
    w.classList.add('lemma-canvas-dragging')
    // currentTarget (review P3): the listener sits on the grip BUTTON, but
    // e.target is often the nested svg/path — capture must attach to the
    // element that owns the listener.
    var captureEl = e.currentTarget
    if (captureEl && captureEl.setPointerCapture && typeof e.pointerId === 'number') {
      try { captureEl.setPointerCapture(e.pointerId) } catch (err) { /* jsdom / old engines */ }
    }
    document.addEventListener('pointermove', onDragMove)
    document.addEventListener('pointerup', onDragUp)
    document.addEventListener('pointercancel', onDragCancel)
    document.addEventListener('keydown', onDragKeydown, true)
  }

  function onDragMove(e) {
    if (!drag) return
    var w = drag.wrapper
    if (!w.parentNode) return
    var kids = w.parentNode.children
    var target = null
    for (var i = 0; i < kids.length; i++) {
      var el = kids[i]
      if (el === w) continue
      if (!(el.hasAttribute && el.hasAttribute('data-lemma-block'))) continue
      // Same-parent guard (review caution): mirror-move's rule on the live path.
      if (el.parentNode !== w.parentNode) continue
      var host = el.firstElementChild
      if (!host) continue
      var r = host.getBoundingClientRect()
      if (e.clientY < r.top + r.height / 2) {
        target = el
        break
      }
    }
    if (target) {
      if (w.nextElementSibling !== target) w.parentNode.insertBefore(w, target)
    } else {
      // Below every midpoint: move to the end of the sibling wrappers.
      var lastWrap = null
      for (var j = kids.length - 1; j >= 0; j--) {
        var cand = kids[j]
        if (cand !== w && cand.hasAttribute && cand.hasAttribute('data-lemma-block')) {
          lastWrap = cand
          break
        }
      }
      if (lastWrap && lastWrap.nextSibling !== w) {
        w.parentNode.insertBefore(w, lastWrap.nextSibling)
      }
    }
  }

  function onDragUp() {
    if (!drag) return
    var w = drag.wrapper
    if (w.nextElementSibling !== drag.originalNext) {
      var next = siblingWrapperFrom(w, 1)
      var prev = siblingWrapperFrom(w, -1)
      if (next) {
        post('block-move-to', { id: w.getAttribute('data-lemma-block'), beforeId: next.getAttribute('data-lemma-block') })
      } else if (prev) {
        post('block-move-to', { id: w.getAttribute('data-lemma-block'), afterId: prev.getAttribute('data-lemma-block') })
      }
      suppressClick = true // the click that follows a completed drag
    }
    endDrag()
  }

  function onDragCancel() {
    rollbackDrag()
  }

  function onDragKeydown(e) {
    if (e.key === 'Escape' && drag) {
      e.preventDefault()
      rollbackDrag()
    }
  }

  function rollbackDrag() {
    // Full rollback (review caution): restore order, clear state, no suppressor.
    if (!drag) return
    var w = drag.wrapper
    if (w.parentNode) w.parentNode.insertBefore(w, drag.originalNext) // null -> append
    endDrag()
  }

  function endDrag() {
    if (!drag) return
    drag.wrapper.classList.remove('lemma-canvas-dragging')
    document.removeEventListener('pointermove', onDragMove)
    document.removeEventListener('pointerup', onDragUp)
    document.removeEventListener('pointercancel', onDragCancel)
    document.removeEventListener('keydown', onDragKeydown, true)
    drag = null
  }
```

**(f)** Click suppression — FIRST lines of the capture-phase click handler:

```js
      if (suppressClick) {
        suppressClick = false
        e.preventDefault()
        e.stopPropagation()
        return
      }
```

**(g)** Hover suppression — first line of the mouseover handler:

```js
      if (drag) return
```

**(h)** `stripCanvasState`'s class list gains `'lemma-canvas-dragging'`.

- [ ] **Step 4: Styles**

Append to `packages/lemma-render/assets/preview/preview.css`:

```css
/* Free drag (free-drag spec §1): grip affordance + dragging dim. The wrapper
   is display:contents, so the dim styles its children. */
.lemma-canvas-toolbar [data-action="drag"] { cursor: grab; touch-action: none; }
[data-lemma-block].lemma-canvas-dragging > * { opacity: 0.55; }
```

- [ ] **Step 5: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (25 tests: 21 prior + 4 new).

---

### Task 2: SPA — moveBlockTo authority + wiring

**Files:**
- Modify: `admin/src/composables/useCanvasBridge.ts`
- Modify: `admin/src/fields/components/BlocksField.vue`
- Modify: `admin/src/components/FieldEditor.vue`
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`
- Test: `admin/src/__tests__/canvas-bridge.spec.ts`, `admin/src/__tests__/blocksField.spec.ts`, `admin/src/__tests__/canvas-page.spec.ts`

**Interfaces:**
- Consumes: Task 1's message; existing `ops.locateById` / `ops.moveAcross`.
- Produces: `useCanvasBridge.onBlockMoveTo(cb: (id: string, neighbor: {beforeId: string} | {afterId: string}) => void)`; `BlocksField.moveBlockTo(id, neighbor): boolean`; `FieldEditor.moveBlockToById(id, neighbor): boolean`.

- [ ] **Step 1: Write the failing tests**

**(a)** `canvas-bridge.spec.ts` — composable dispatch (append to the `useCanvasBridge` describe):

```ts
  it('block-move-to dispatches with exactly one neighbor key; malformed dropped', () => {
    const bridge = useCanvasBridge(ref(null))
    const moveTo = vi.fn()
    bridge.onBlockMoveTo(moveTo)

    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-move-to', id: 'b1', beforeId: 'b2', nonce: bridge.nonce },
      }),
    )
    expect(moveTo).toHaveBeenCalledWith('b1', { beforeId: 'b2' })
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-move-to', id: 'b1', afterId: 'b3', nonce: bridge.nonce },
      }),
    )
    expect(moveTo).toHaveBeenCalledWith('b1', { afterId: 'b3' })
    // Neither key -> dropped; BOTH keys -> dropped too (XOR, review P2 —
    // never silently prefer one of two contradictory claims).
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:block-move-to', id: 'b1', nonce: bridge.nonce },
      }),
    )
    window.dispatchEvent(
      new MessageEvent('message', {
        data: {
          type: 'lemma:block-move-to',
          id: 'b1',
          beforeId: 'b2',
          afterId: 'b3',
          nonce: bridge.nonce,
        },
      }),
    )
    expect(moveTo).toHaveBeenCalledTimes(2)
    bridge.dispose()
  })
```

**(b)** `blocksField.spec.ts` — append inside the BlocksField describe:

```ts
  it('moveBlockTo places a block next to a SAME-LIST reference; cross-list denied', async () => {
    let model: { id: string; type: string; data: Record<string, unknown> }[] = [
      { id: 'aaa000000001', type: 'quote', data: { text: 'A' } },
      { id: 'bbb000000002', type: 'quote', data: { text: 'B' } },
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
    const api = wrapper.vm as unknown as {
      moveBlockTo: (
        id: string,
        n: { beforeId: string } | { afterId: string },
      ) => boolean
    }

    // afterId at list end: aaa moves after sec.
    expect(api.moveBlockTo('aaa000000001', { afterId: 'sec00000001' })).toBe(true)
    expect(model.map((b) => b.id)).toEqual(['bbb000000002', 'sec00000001', 'aaa000000001'])
    await wrapper.setProps({ modelValue: model })

    // beforeId back to the front.
    expect(api.moveBlockTo('aaa000000001', { beforeId: 'bbb000000002' })).toBe(true)
    expect(model.map((b) => b.id)).toEqual(['aaa000000001', 'bbb000000002', 'sec00000001'])
    await wrapper.setProps({ modelValue: model })

    // Cross-list reference (nested block) -> denied, NO mutation.
    const before = model.map((b) => b.id)
    expect(api.moveBlockTo('aaa000000001', { beforeId: 'inner0000001' })).toBe(false)
    expect(model.map((b) => b.id)).toEqual(before)
    // Unknown ids -> denied.
    expect(api.moveBlockTo('missing', { beforeId: 'bbb000000002' })).toBe(false)
    expect(api.moveBlockTo('aaa000000001', { beforeId: 'missing' })).toBe(false)
    wrapper.unmount()
  })
```

**(c)** `canvas-page.spec.ts` — mock additions:

```ts
    moveTo?: (id: string, neighbor: { beforeId: string } | { afterId: string }) => void
```

(in the callbacks type), and in `instance`:

```ts
      onBlockMoveTo: (
        cb: (id: string, neighbor: { beforeId: string } | { afterId: string }) => void,
      ) => (callbacks.moveTo = cb),
```

New tests in `describe('canvas page', …)`:

```ts
  it('an accepted block-move-to patches the tree; NO mirror is posted back', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    bridge.callbacks.moveTo?.('blockaaa0001', { afterId: 'prose0000003' })
    await flushPromises()
    expect(bridge.instance.mirrorMove).not.toHaveBeenCalled() // the drag WAS the mirror
    expect(wrapper.find('[data-test="canvas-iframe"]').element).toBe(before) // no reload

    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
      fields: { body: { id: string }[] }
    }
    expect(saved.fields.body.map((b) => b.id)).toEqual([
      'blockbbb0002',
      'prose0000003',
      'blockaaa0001',
    ])
    wrapper.unmount()
  })

  it('a REJECTED block-move-to reloads the stage and leaves fields untouched', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    bridge.callbacks.moveTo?.('blockaaa0001', { beforeId: 'missing' })
    await flushPromises()
    await flushPromises()
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.element).not.toBe(before) // reloadStage snapped back to truth
    expect(mintMock).toHaveBeenCalledTimes(1) // reload, not re-mint

    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
      fields: { body: { id: string }[] }
    }
    expect(saved.fields.body.map((b) => b.id)).toEqual([
      'blockaaa0001',
      'blockbbb0002',
      'prose0000003',
    ])
    wrapper.unmount()
  })
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts src/__tests__/blocksField.spec.ts src/__tests__/canvas-page.spec.ts`
Expected: FAIL — missing methods/messages.

- [ ] **Step 3: Implement**

**(a) `useCanvasBridge.ts`** — `BridgeMessage` gains `beforeId?: string; afterId?: string`. Slot + branch + API:

```ts
  let moveToCb:
    | ((id: string, neighbor: { beforeId: string } | { afterId: string }) => void)
    | null = null
```

```ts
    if (data.type === 'lemma:block-move-to' && typeof data.id === 'string') {
      // XOR (review P2): exactly one neighbor key — both or neither is
      // malformed and dropped, never a silent preference.
      const hasBefore = typeof data.beforeId === 'string'
      const hasAfter = typeof data.afterId === 'string'
      if (hasBefore && !hasAfter) moveToCb?.(data.id, { beforeId: data.beforeId as string })
      else if (hasAfter && !hasBefore) moveToCb?.(data.id, { afterId: data.afterId as string })
    }
```

```ts
    onBlockMoveTo(
      cb: (id: string, neighbor: { beforeId: string } | { afterId: string }) => void,
    ): void {
      moveToCb = cb
    },
```

**(b) `BlocksField.vue`** — after `moveBlock`:

```ts
/**
 * Free-drag drop (free-drag spec §2): place `id` next to a SAME-LIST
 * reference. The bridge's geometry is a request — this method is the
 * authority: cross-list or unknown references are denied with NO mutation.
 */
function moveBlockTo(
  id: string,
  neighbor: { beforeId: string } | { afterId: string },
): boolean {
  const tree = model.value ?? []
  const dragged = ops.locateById(tree, id)
  const refId = 'beforeId' in neighbor ? neighbor.beforeId : neighbor.afterId
  const ref = ops.locateById(tree, refId)
  if (!dragged || !ref) return false
  if (dragged.parentId !== ref.parentId || dragged.region !== ref.region) return false
  // Target index against the list WITHOUT the dragged block (moveAcross
  // removes before inserting).
  const without = dragged.list.filter((b) => b.id !== id)
  const refPos = without.findIndex((b) => b.id === refId)
  if (refPos < 0) return false
  const index = 'beforeId' in neighbor ? refPos : refPos + 1
  apply((t) =>
    ops.moveAcross(t, id, { parentId: dragged.parentId, region: dragged.region, index }),
  )
  return true
}
```

Add `moveBlockTo` to `defineExpose`.

**(c) `FieldEditor.vue`** — `BlocksFieldExposed` gains:

```ts
  moveBlockTo: (id: string, neighbor: { beforeId: string } | { afterId: string }) => boolean
```

`defineExpose` gains:

```ts
  moveBlockToById(id: string, neighbor: { beforeId: string } | { afterId: string }) {
    return fieldOwning(id)?.moveBlockTo(id, neighbor) ?? false
  },
```

**(d) Canvas page** — `FieldEditorExposed` gains the same `moveBlockToById`
signature; wiring next to `onBlockMove`:

```ts
bridge.onBlockMoveTo((id, neighbor) => {
  // The drag WAS the mirror: an accepted drop needs no message back — the
  // tree change rides auto-apply. A rejection must snap the stage back to
  // truth BEFORE anything else can run (honest-stage pin): fields were never
  // mutated, so stageStale is untouched and no auto-apply schedules.
  const ok = fieldEditorRef.value?.moveBlockToById(id, neighbor) ?? false
  if (!ok) reloadStage()
})
```

- [ ] **Step 4: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts src/__tests__/blocksField.spec.ts src/__tests__/canvas-page.spec.ts && pnpm type-check`
Expected: PASS, type-check clean.

---

### Task 3: Docs, full gates, STAGE (stop for commit authorization)

**Files:**
- Modify: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: README**

In the canvas toolbar sentence of `packages/lemma-render/README.md`, extend the
action list: change "(move up/down, duplicate, delete, add block after)" to
"(drag to reorder, move up/down, duplicate, delete, add block after)".

- [ ] **Step 2: CHANGELOG**

Append to `[Unreleased]` after the auto-apply bullet:

```markdown
- Free drag (canvas v6): drag the stage toolbar's grip to reorder a block
  within its list, sortable-style — the page reorders live under the
  pointer, one move lands in the block tree on drop (validated same-list by
  the inspector's ops), Escape cancels, and a rejected drop snaps the stage
  back to truth. Cross-container moves stay in the inspector.
```

- [ ] **Step 3: Full verification (all gates)**

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
  packages/lemma-render \
  admin/src/composables/useCanvasBridge.ts \
  admin/src/fields/components/BlocksField.vue \
  admin/src/components/FieldEditor.vue \
  "admin/src/pages/content/[type]/[uuid]/design/[locale].vue" \
  admin/src/__tests__ \
  CHANGELOG.md \
  docs/superpowers
git status --short
```

Then STOP and report, awaiting explicit commit authorization. Prepared message:

```
feat(admin): free drag — reorder blocks by dragging on the rendered page

- Toolbar grip starts a pointer-driven drag; the stage reorders LIVE
  between same-parent sibling wrappers as the pointer crosses midpoints
  (the mirror-move operation, applied continuously); Escape/cancel
  restore the original order
- ONE block-move-to intent per drag, posted on pointerup with the
  neighbor computed excluding the dragged wrapper; BlocksField remains
  the sole authority (same-list re-check; rejection reloads the stage
  with fields untouched); the accepted drop rides auto-apply
- Same-list only — cross-container moves stay in the inspector
```

Recorded manual/browser acceptance (report as outstanding): drag feel on the
real theme, long pages near the fold, touchpad behavior, drag + auto-apply
rhythm — plus the earlier canvas items.
