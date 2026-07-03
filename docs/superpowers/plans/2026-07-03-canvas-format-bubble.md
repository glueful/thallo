# Canvas v8.1: Selection-Following Format Bubble Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the v8 region-docked format bar with a TipTap-style bubble on `document.body` that follows the text selection during rich edit sessions.

**Architecture:** Amendment on top of staged-uncommitted v8 (one commit covers both). Bubble geometry via CSSOM `transform` only (reworded CSP pin); visibility class-driven; all v8 action machinery (`applyFormat`/`runCommand`/`isSafeLinkUrl`/normalization/focus-steal prevention/click exemption) unchanged.

**Tech Stack:** Vanilla JS bridge asset, static CSS, Vitest + jsdom.

**Spec:** `docs/superpowers/specs/2026-07-03-canvas-format-bubble-design.md`

## Global Constraints

- NO commits: v8 is already staged; re-stage the amended files at the end and STOP for the user's "commit all".
- Reworded CSP pin: no style ATTRIBUTES in emitted/serialized markup; appearance only in `preview.css`; bridge-owned UI geometry via CSSOM property assignment (`bar.style.transform`) ONLY — never colors/fonts/spacing.
- Strict containment (review caution): the bubble is visible ONLY when `editing.region.contains(range.commonAncestorContainer)` — never "selection overlaps region"; partially-outside selections hide.
- Cleanup ordering (review caution): `endEditing` removes the bubble and its listeners BEFORE `editing = null` (the bubble element lives on `editing.formatBar`).
- Re-anchor after actions (review caution): `applyFormat` calls the positioning update after a successful action — normalization can reshape the DOM around the selection; don't wait for the next `selectionchange`.
- One-eval-per-file test discipline: every granting test ends its session; stubs of `window.getSelection` are restored in `finally`.

---

### Task 1: Bubble implementation + migrated tests

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Modify: `packages/lemma-render/assets/preview/preview.css`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts` (rewrite the v8 "in-stage formatting bar" describe)

**Interfaces:**
- Consumes: v8's `FORMAT_ACTIONS`, `applyFormat`, `preventFocusSteal`, `editing` session state.
- Produces: `positionFormatBubble()` (selectionchange/scroll/resize handler + post-action re-anchor); `editing.formatBar` (bubble element on `document.body`); `.lemma-canvas-format-visible` class contract.

- [ ] **Step 1: Rewrite the formatting-bar describe (failing tests first)**

Replace the entire `describe('in-stage formatting bar (format-bar spec §1/§3/§4)', …)` block with:

```ts
describe('selection-following format bubble (format-bubble spec §1/§2)', () => {
  function grantRich(id: string): HTMLElement {
    const w = proseWrapper(id)
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id, field: 'body', kind: 'rich' })
    return w
  }

  function endSession(w: HTMLElement): void {
    const region = w.querySelector('.lemma-edit-region')
    region?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  }

  const realGetSelection = window.getSelection

  function stubSelection(opts: {
    collapsed: boolean
    container: Node
    rect?: { left: number; top: number; width: number; height: number; bottom: number }
  }): void {
    const rect = opts.rect ?? { left: 100, top: 200, width: 50, height: 20, bottom: 220 }
    window.getSelection = vi.fn().mockReturnValue({
      isCollapsed: opts.collapsed,
      rangeCount: 1,
      getRangeAt: () => ({
        commonAncestorContainer: opts.container,
        getBoundingClientRect: () => ({ ...rect, right: rect.left + rect.width, x: rect.left, y: rect.top }),
      }),
    }) as unknown as typeof window.getSelection
  }

  function fireSelectionChange(): void {
    document.dispatchEvent(new Event('selectionchange'))
  }

  function bubble(): HTMLElement | null {
    return document.querySelector('body > .lemma-canvas-format-bar')
  }

  it('a rich grant creates a hidden bubble on body; plain kinds get none; end removes it', () => {
    const w = grantRich('fb-a-000001')
    const bar = bubble()!
    expect(bar).not.toBeNull()
    expect(bar.classList.contains('lemma-canvas-format-visible')).toBe(false)
    const formats = [...bar.querySelectorAll('[data-format]')].map((b) =>
      b.getAttribute('data-format'),
    )
    expect(formats).toEqual(['bold', 'italic', 'link', 'unlink'])

    endSession(w)
    expect(bubble()).toBeNull()

    const s = wrapper(
      'fb-b-000001',
      '<section><h2><span class="lemma-edit-region" data-lemma-edit-block="fb-b-000001" ' +
        'data-lemma-edit-field="heading">Hello</span></h2></section>',
    )
    document.body.appendChild(s)
    sendToBridge({ type: 'lemma:edit-grant', id: 'fb-b-000001', field: 'heading', kind: 'string' })
    expect(s.querySelector('[contenteditable]')).not.toBeNull()
    expect(bubble()).toBeNull()
    endSession(s)
  })

  it('shows over a non-collapsed in-region selection, positioned off the selection rect', () => {
    try {
      const w = grantRich('fb-c-000001')
      const region = w.querySelector('.lemma-edit-region')!
      const bar = bubble()!

      // In-region, non-collapsed: visible, centered above the rect (jsdom
      // bubble rect is all zeros, so x = left + width/2, y = top - 8).
      stubSelection({ collapsed: false, container: region.querySelector('p')! })
      fireSelectionChange()
      expect(bar.classList.contains('lemma-canvas-format-visible')).toBe(true)
      expect(bar.style.transform).toBe('translate(125px, 192px)')

      // Collapsed: hidden.
      stubSelection({ collapsed: true, container: region.querySelector('p')! })
      fireSelectionChange()
      expect(bar.classList.contains('lemma-canvas-format-visible')).toBe(false)

      // Non-collapsed but OUTSIDE the region (strict containment — review
      // caution: partial-outside selections resolve their common ancestor
      // above the region and must hide).
      stubSelection({ collapsed: false, container: document.body })
      fireSelectionChange()
      expect(bar.classList.contains('lemma-canvas-format-visible')).toBe(false)

      endSession(w)
      // Listeners removed with the session: a later selectionchange is inert.
      fireSelectionChange()
      expect(bubble()).toBeNull()
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('flips below when there is no headroom and clamps to the viewport edge', () => {
    try {
      const w = grantRich('fb-d-000001')
      const region = w.querySelector('.lemma-edit-region')!
      const bar = bubble()!

      // top=4 -> above would be y=-4 (<4) -> flip below: bottom + 8 = 32.
      stubSelection({
        collapsed: false,
        container: region.querySelector('p')!,
        rect: { left: 0, top: 4, width: 2, height: 20, bottom: 24 },
      })
      fireSelectionChange()
      // x = 0 + 1 = 1 -> clamps to the 4px margin.
      expect(bar.style.transform).toBe('translate(4px, 32px)')
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('bold/italic clicks run execCommand, normalize the live region, and re-anchor in-session', () => {
    try {
      let region: Element // assigned after grant; the stub only runs at click time
      const exec = vi.fn((cmd: string) => {
        if (cmd === 'bold') region.innerHTML = '<p><b>sel</b> rest</p>'
        if (cmd === 'italic') region.innerHTML = '<p><i>sel</i> rest</p>'
        return true
      })
      document.execCommand = exec as unknown as typeof document.execCommand
      const w = grantRich('fb-e-000001')
      region = w.querySelector('.lemma-edit-region')!
      stubSelection({ collapsed: false, container: region })

      const bar = bubble()!
      bar.querySelector('[data-format="bold"]')!.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
      )
      expect(exec).toHaveBeenCalledWith('bold')
      expect(region.innerHTML).toBe('<p><strong>sel</strong> rest</p>')
      // Post-action re-anchor (review caution): the bubble repositioned from
      // the stubbed selection WITHOUT a selectionchange event.
      expect(bar.classList.contains('lemma-canvas-format-visible')).toBe(true)
      expect(bar.style.transform).toBe('translate(125px, 192px)')

      bar.querySelector('[data-format="italic"]')!.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
      )
      expect(region.innerHTML).toBe('<p><em>sel</em> rest</p>')

      expect(lastPost('lemma:edit-end')).toBeUndefined()
      expect(region.getAttribute('contenteditable')).toBe('true')
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('bubble pointerdown AND mousedown are both default-prevented (focus never leaves)', () => {
    const w = grantRich('fb-f-000001')
    const bar = bubble()!
    const pd = new MouseEvent('pointerdown', { bubbles: true, cancelable: true })
    bar.dispatchEvent(pd)
    expect(pd.defaultPrevented).toBe(true)
    const md = new MouseEvent('mousedown', { bubbles: true, cancelable: true })
    bar.dispatchEvent(md)
    expect(md.defaultPrevented).toBe(true)
    endSession(w)
  })

  it('a bubble click posts text-changed WITHOUT any input event (deterministic commit)', () => {
    vi.useFakeTimers()
    try {
      document.execCommand = vi.fn(() => true) as unknown as typeof document.execCommand
      const w = grantRich('fb-g-000001')
      const region = w.querySelector('.lemma-edit-region')!
      region.innerHTML = '<p><b>x</b></p>' // pretend the engine mutated on execCommand
      posted.mockClear()

      bubble()!.querySelector('[data-format="bold"]')!.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
      )
      expect(lastPost('lemma:text-changed')).toBeUndefined() // debounced, not instant
      vi.advanceTimersByTime(450)
      expect(lastPost('lemma:text-changed')).toMatchObject({
        id: 'fb-g-000001',
        field: 'body',
        html: '<p><strong>x</strong></p>',
      })
      endSession(w)
    } finally {
      vi.useRealTimers()
    }
  })

  it('createLink validates BEFORE execCommand: bad URLs are a complete no-op', () => {
    const exec = vi.fn(() => true)
    document.execCommand = exec as unknown as typeof document.execCommand
    const w = grantRich('fb-h-000001')
    const link = bubble()!.querySelector('[data-format="link"]')!
    const click = () =>
      link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))

    const bad = [null, '', '   ', '//evil.test/x', 'javascript:alert(1)', 'data:text/html,x']
    for (const value of bad) {
      window.prompt = vi.fn().mockReturnValue(value)
      click()
    }
    expect(exec).not.toHaveBeenCalled()

    const good: Array<[string, string]> = [
      ['https://x.test/a', 'https://x.test/a'],
      ['http://x.test', 'http://x.test'],
      ['mailto:a@b.c', 'mailto:a@b.c'],
      ['/relative/path', '/relative/path'],
      ['#anchor', '#anchor'],
    ]
    for (const [value, expected] of good) {
      exec.mockClear()
      window.prompt = vi.fn().mockReturnValue(value)
      click()
      expect(exec).toHaveBeenCalledWith('createLink', false, expected)
    }
    endSession(w)
  })

  it('the bubble never reaches committed HTML or duplicate clones', () => {
    document.execCommand = vi.fn(() => true) as unknown as typeof document.execCommand
    const w = grantRich('fb-i-000001')
    posted.mockClear()
    endSession(w) // commit + end
    const committed = lastPost('lemma:text-changed')!
    expect(String(committed.html)).not.toContain('lemma-canvas-format')

    // The bubble lives on document.body — structurally outside every wrapper —
    // so a duplicate clone can never carry it.
    sendToBridge({ type: 'lemma:edit-grant', id: 'fb-i-000001', field: 'body', kind: 'rich' })
    expect(bubble()).not.toBeNull()
    sendToBridge({
      type: 'lemma:mirror-duplicate',
      sourceId: 'fb-i-000001',
      idMap: { 'fb-i-000001': 'fb-i-000002' },
    })
    const clone = document.querySelector('[data-lemma-block="fb-i-000002"]')!
    expect(clone.querySelector('.lemma-canvas-format-bar')).toBeNull()
    endSession(w)
  })
})
```

Notes: the `translate(125px, 192px)` expectations derive from the default stub rect `{left:100, width:50, top:200}` with a zero-size jsdom bubble rect: `x = 100 + 25 − 0 = 125`, `y = 200 − 0 − 8 = 192`. If jsdom's `window.innerWidth` is 0 in this environment, the clamp would zero X — the implementation treats a non-positive `innerWidth` as unbounded (see Step 3).

- [ ] **Step 2: Run to verify the rewritten tests fail**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: the bubble describe FAILS (bubble still docks inside the wrapper; no visibility class, no transform). Normalization + all older describes PASS.

- [ ] **Step 3: Implement in the bridge**

In `packages/lemma-render/assets/preview/preview-bridge.js`:

3a. Reword the header pin. Replace the header comment's CSP sentence

```
Token-free
// and static on purpose (cacheable). CSP pin: NO inline styles anywhere — all
// appearance lives in preview.css classes; the toolbar is positioned by DOM
// placement inside the selected block's anchor element.
```

with

```
Token-free
// and static on purpose (cacheable). CSP pin (reworded, format-bubble spec):
// no style ATTRIBUTES ever appear in emitted or serialized markup and ALL
// appearance lives in preview.css classes; bridge-owned UI may be positioned
// via CSSOM property assignment (el.style.transform — geometry only), which
// strict style-src does not restrict. Block toolbars stay DOM-placed.
```

3b. Replace `showFormatBar(region)` with a body-mounted builder (no anchor span):

```js
  function showFormatBar() {
    var bar = document.createElement('div')
    bar.className = 'lemma-canvas-format-bar'
    FORMAT_ACTIONS.forEach(function (a) {
      var btn = document.createElement('button')
      btn.type = 'button'
      btn.setAttribute('data-format', a.format)
      btn.setAttribute('aria-label', a.label)
      btn.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round"><path d="' + a.path + '"/></svg>'
      bar.appendChild(btn)
    })
    bar.addEventListener('pointerdown', preventFocusSteal)
    bar.addEventListener('mousedown', preventFocusSteal)
    document.body.appendChild(bar)
    return bar
  }
```

3c. Add the positioner after `showFormatBar`:

```js
  /**
   * Show the bubble over the current selection, or hide it. Visible ONLY when
   * the selection is non-collapsed AND its common ancestor is contained by
   * the active region (strict containment — a partially-outside selection
   * resolves its ancestor above the region and hides). Geometry via CSSOM
   * transform only (reworded CSP pin); appearance stays in preview.css.
   */
  function positionFormatBubble() {
    if (!editing || !editing.formatBar) return
    var bar = editing.formatBar
    var sel = window.getSelection ? window.getSelection() : null
    var placed = false
    if (sel && !sel.isCollapsed && sel.rangeCount > 0) {
      var range = sel.getRangeAt(0)
      if (editing.region.contains(range.commonAncestorContainer)) {
        var rect = range.getBoundingClientRect()
        var size = bar.getBoundingClientRect()
        var x = rect.left + rect.width / 2 - size.width / 2
        var maxX = (window.innerWidth || 0) - size.width - 4
        if (maxX > 4 && x > maxX) x = maxX
        if (x < 4) x = 4
        var y = rect.top - size.height - 8
        if (y < 4) y = rect.bottom + 8
        bar.style.transform = 'translate(' + x + 'px, ' + y + 'px)'
        placed = true
      }
    }
    if (placed) bar.classList.add('lemma-canvas-format-visible')
    else bar.classList.remove('lemma-canvas-format-visible')
  }
```

3d. In `startEditing`, replace the v8 line
`if (kind === 'rich') editing.formatAnchor = showFormatBar(region)` with:

```js
    if (kind === 'rich') {
      editing.formatBar = showFormatBar()
      document.addEventListener('selectionchange', positionFormatBubble)
      window.addEventListener('scroll', positionFormatBubble, true)
      window.addEventListener('resize', positionFormatBubble)
    }
```

and rename the session field in the initializer (`formatAnchor: null` →
`formatBar: null`).

3e. In `endEditing`, replace the v8 anchor-removal block with (BEFORE
`editing = null` — review caution):

```js
    if (editing.formatBar) {
      document.removeEventListener('selectionchange', positionFormatBubble)
      window.removeEventListener('scroll', positionFormatBubble, true)
      window.removeEventListener('resize', positionFormatBubble)
      if (editing.formatBar.parentNode) {
        editing.formatBar.parentNode.removeChild(editing.formatBar)
      }
    }
```

3f. In `applyFormat`, after `onEditInput()` add:

```js
    positionFormatBubble() // re-anchor now: normalization reshapes the DOM (review caution)
```

- [ ] **Step 4: CSS — fixed positioning + visibility class**

In `packages/lemma-render/assets/preview/preview.css`, replace the v8
`.lemma-canvas-format-bar` block (and its comment) with:

```css
/* Selection-following format bubble (format-bubble spec §2): appearance and
   the static base live HERE; the bridge sets ONLY transform via CSSOM
   (reworded CSP pin — strict style-src does not restrict CSSOM geometry).
   Fixed positioning matches the selection rect's viewport coordinates. */
.lemma-canvas-format-bar {
  position: fixed;
  top: 0;
  left: 0;
  visibility: hidden;
  z-index: 2147483000;
  display: flex;
  gap: 2px;
  padding: 2px;
  border-radius: 6px;
  background: #18181b;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
}
.lemma-canvas-format-bar.lemma-canvas-format-visible { visibility: visible; }
```

- [ ] **Step 5: Run to verify all pass**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (46 tests: 45 v8 minus the replaced describe's 6 plus the new 7... count the run output; the describe now holds 8 tests → 47 total if none were dropped — trust the runner, all green is the gate).

---

### Task 2: Docs, gates, re-stage

**Files:**
- Modify: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: README**

Replace the v8 sentence "While a rich session is active, a small formatting
bar docked above the region offers bold, italic, and link/unlink;" with:

```
While a rich session is active, selecting text shows a small formatting
bubble over the selection (bold, italic, link/unlink);
```

- [ ] **Step 2: CHANGELOG**

In the canvas v8 bullet, replace "rich edit sessions dock a small bar above
the region" with "rich edit sessions show a selection-following formatting
bubble (TipTap-style, positioned off the selection rect)" and append to the
bullet's end:

```
  The bridge's CSP pin is reworded: appearance stays in preview.css and no
  style attributes are ever emitted, but bridge-owned UI may be positioned
  via CSSOM transform (which strict style-src does not restrict).
```

- [ ] **Step 3: Full gates**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run
pnpm type-check && pnpm lint
cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit --filter Preview
```

- [ ] **Step 4: Re-stage (no commit)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add packages/lemma-render/assets/preview/preview-bridge.js \
        packages/lemma-render/assets/preview/preview.css \
        packages/lemma-render/README.md \
        admin/src/__tests__/preview-bridge-dom.spec.ts \
        CHANGELOG.md \
        docs/superpowers/specs/2026-07-03-canvas-format-bubble-design.md \
        docs/superpowers/plans/2026-07-03-canvas-format-bubble.md
git status
```

STOP — the user commits v8 + v8.1 together.
