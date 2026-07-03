# Canvas v8.2: Inline Link Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the bubble's `window.prompt` link capture with a TipTap-style inline input panel that keeps the rich-region session alive while focus visits it.

**Architecture:** Amendment on staged-uncommitted v8/v8.1 (one commit covers all). Panel is a second row inside the body-mounted bubble; three focus exemptions + saved/restored selection range + positioning freeze; `closeLinkPanel()` is the idempotent single owner of panel lifecycle state.

**Tech Stack:** Vanilla JS bridge asset, static CSS, Vitest + jsdom.

**Spec:** `docs/superpowers/specs/2026-07-03-canvas-link-panel-design.md`

## Global Constraints

- NO commits; re-stage at the end and STOP for "commit all".
- Reworded CSP pin (v8.1): appearance in `preview.css`; bridge geometry via CSSOM transform only. The panel adds NO new el.style usage.
- Blur exception is defensive (review caution): a null `relatedTarget` behaves like a real outside blur — commit-and-exit.
- Observable order pin (review caution): the saved range must be ACTIVE (`removeAllRanges` + `addRange(saved)`) before `createLink` runs — asserted by spy call order.
- Invalid URL (empty/whitespace included — spec pin: empty ≠ unlink): keep the panel open, keep the input VALUE, keep focus in the input, mark `lemma-canvas-link-invalid`.
- `closeLinkPanel()` idempotent and the ONLY place that clears `savedLinkRange`, the invalid class, and the freeze state (review caution). Called from: link-button toggle, Escape, apply success, `endEditing` (edit-flush ends the session, so it's covered).
- One-eval-per-file test discipline: sessions ended; `window.getSelection` stubs restored in `finally`.

---

### Task 1: Panel + focus surgery + tests

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Modify: `packages/lemma-render/assets/preview/preview.css`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts` (replace the createLink test in the bubble describe; add a link-panel describe)

**Interfaces:**
- Consumes: v8.1 bubble (`editing.formatBar`, `positionFormatBubble`, `preventFocusSteal`), v8 `runCommand`/`isSafeLinkUrl`/`normalizeRichRegion`/`onEditInput`.
- Produces: module state `linkPanel` ({root, input}), `savedLinkRange`, `linkPanelOpen`; `toggleLinkPanel()`, `applyLink()`, `closeLinkPanel()`; `.lemma-canvas-link-panel` / `.lemma-canvas-link-open` / `.lemma-canvas-link-invalid` class contract; `onEditBlur(e)` gains the relatedTarget exception.

- [ ] **Step 1: Rewrite the createLink test and add the link-panel describe (failing first)**

In the bubble describe, REPLACE the test `'createLink validates BEFORE execCommand: bad URLs are a complete no-op'` with a stub-hygiene passthrough (the validation tests move to the new describe):

```ts
  it('link click never prompts (panel flow — see the link-panel describe)', () => {
    const promptSpy = vi.fn()
    window.prompt = promptSpy as unknown as typeof window.prompt
    const w = grantRich('fb-h-000001')
    bubble()!
      .querySelector('[data-format="link"]')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(promptSpy).not.toHaveBeenCalled()
    endSession(w)
  })
```

Append a NEW describe at the end of the file:

```ts
describe('inline link panel (link-panel spec §1–§4)', () => {
  const realGetSelection = window.getSelection

  function grantRich(id: string, inner?: string): HTMLElement {
    const w = inner
      ? wrapper(id, inner)
      : proseWrapper(id)
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id, field: 'body', kind: 'rich' })
    return w
  }

  function endSession(w: HTMLElement): void {
    const region = w.querySelector('.lemma-edit-region')
    region?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  }

  function bubble(): HTMLElement {
    return document.querySelector('body > .lemma-canvas-format-bar') as HTMLElement
  }

  function stubRichSelection(container: Node): {
    removeAllRanges: ReturnType<typeof vi.fn>
    addRange: ReturnType<typeof vi.fn>
    range: object
  } {
    const range = {
      commonAncestorContainer: container,
      cloneRange(): object {
        return this
      },
      getBoundingClientRect: () => ({
        left: 100, top: 200, width: 50, height: 20, bottom: 220, right: 150, x: 100, y: 200,
      }),
    }
    const removeAllRanges = vi.fn()
    const addRange = vi.fn()
    window.getSelection = vi.fn().mockReturnValue({
      isCollapsed: false,
      rangeCount: 1,
      getRangeAt: () => range,
      removeAllRanges,
      addRange,
    }) as unknown as typeof window.getSelection
    return { removeAllRanges, addRange, range }
  }

  function openPanel(w: HTMLElement): HTMLInputElement {
    bubble()
      .querySelector('[data-format="link"]')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    return bubble().querySelector('.lemma-canvas-link-panel input') as HTMLInputElement
  }

  it('opens on link click, prefills from a REGION-contained <a> only, and freezes visibility', () => {
    try {
      // Region wrapped by a theme-level <a> OUTSIDE it: prefill must ignore it.
      const wOutside = grantRich(
        'lp-a-000001',
        '<section><a href="https://theme.test/outer"><div class="lemma-edit-region" ' +
          'data-lemma-edit-block="lp-a-000001" data-lemma-edit-field="body">' +
          '<p>text</p></div></a></section>',
      )
      const regionA = wOutside.querySelector('.lemma-edit-region')!
      stubRichSelection(regionA.querySelector('p')!)
      const inputA = openPanel(wOutside)
      expect(inputA).not.toBeNull()
      expect(inputA.value).toBe('') // outside link ignored (spec pin)
      expect(
        bubble().querySelector('.lemma-canvas-link-panel')!.classList
          .contains('lemma-canvas-link-open'),
      ).toBe(true)

      // Freeze (spec §4): a collapsed selectionchange while open must NOT hide.
      document.dispatchEvent(new Event('selectionchange'))
      // (stub still non-collapsed; the point is position/visibility untouched)
      endSession(wOutside)

      // Region-contained <a>: prefill picks up its href.
      const wInside = grantRich(
        'lp-b-000001',
        '<section><div class="lemma-edit-region" data-lemma-edit-block="lp-b-000001" ' +
          'data-lemma-edit-field="body"><p><a href="https://x.test/old">old</a></p></div></section>',
      )
      const anchor = wInside.querySelector('.lemma-edit-region a')!
      stubRichSelection(anchor.firstChild!)
      const inputB = openPanel(wInside)
      expect(inputB.value).toBe('https://x.test/old')
      endSession(wInside)
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('input mousedown is allowed to focus; format-button mousedown is still cancelled', () => {
    try {
      const w = grantRich('lp-c-000001')
      stubRichSelection(w.querySelector('.lemma-edit-region p')!)
      const input = openPanel(w)

      const onInput = new MouseEvent('mousedown', { bubbles: true, cancelable: true })
      input.dispatchEvent(onInput)
      expect(onInput.defaultPrevented).toBe(false)

      const onButton = new MouseEvent('mousedown', { bubbles: true, cancelable: true })
      bubble().querySelector('[data-format="bold"]')!.dispatchEvent(onButton)
      expect(onButton.defaultPrevented).toBe(true)
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('region blur with relatedTarget in the bubble keeps the session; null relatedTarget ends it', () => {
    try {
      const w = grantRich('lp-d-000001')
      const region = w.querySelector('.lemma-edit-region')!
      stubRichSelection(region.querySelector('p')!)
      const input = openPanel(w)
      posted.mockClear()

      region.dispatchEvent(new FocusEvent('blur', { relatedTarget: input }))
      expect(lastPost('lemma:edit-end')).toBeUndefined()
      expect(region.getAttribute('contenteditable')).toBe('true')

      // Null relatedTarget = REAL outside blur (review caution): commit-and-exit.
      region.dispatchEvent(new FocusEvent('blur'))
      expect(lastPost('lemma:edit-end')).toMatchObject({ id: 'lp-d-000001' })
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('Enter applies: saved range active BEFORE createLink, panel closes, commit fires', () => {
    vi.useFakeTimers()
    try {
      const exec = vi.fn(() => true)
      document.execCommand = exec as unknown as typeof document.execCommand
      const w = grantRich('lp-e-000001')
      const region = w.querySelector('.lemma-edit-region')!
      const spies = stubRichSelection(region.querySelector('p')!)
      const input = openPanel(w)

      input.value = '  https://x.test/new  '
      posted.mockClear()
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))

      expect(spies.addRange).toHaveBeenCalledWith(spies.range)
      expect(exec).toHaveBeenCalledWith('createLink', false, 'https://x.test/new')
      // Observable order pin: range restored BEFORE createLink ran.
      expect(spies.addRange.mock.invocationCallOrder[0]).toBeLessThan(
        exec.mock.invocationCallOrder[0],
      )
      // Success closes the panel AFTER command/normalize/commit scheduling.
      expect(
        bubble().querySelector('.lemma-canvas-link-panel')!.classList
          .contains('lemma-canvas-link-open'),
      ).toBe(false)
      vi.advanceTimersByTime(450)
      expect(lastPost('lemma:text-changed')).toMatchObject({ id: 'lp-e-000001' })
      endSession(w)
    } finally {
      vi.useRealTimers()
      window.getSelection = realGetSelection
    }
  })

  it('invalid URLs (empty included) keep the panel open+focused with value preserved', () => {
    try {
      const exec = vi.fn(() => true)
      document.execCommand = exec as unknown as typeof document.execCommand
      const w = grantRich('lp-f-000001')
      stubRichSelection(w.querySelector('.lemma-edit-region p')!)
      const input = openPanel(w)
      const panel = bubble().querySelector('.lemma-canvas-link-panel')!

      for (const value of ['', '   ', '//evil.test/x', 'javascript:alert(1)', 'data:text/html,x']) {
        input.value = value
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
        expect(panel.classList.contains('lemma-canvas-link-open')).toBe(true)
        expect(panel.classList.contains('lemma-canvas-link-invalid')).toBe(true)
        expect(input.value).toBe(value) // preserved (review caution)
      }
      expect(exec).not.toHaveBeenCalled()

      // The next keystroke clears the invalid mark.
      input.dispatchEvent(new Event('input', { bubbles: true }))
      expect(panel.classList.contains('lemma-canvas-link-invalid')).toBe(false)
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('Escape closes the panel, refocuses the region, and the session survives', () => {
    try {
      const w = grantRich('lp-g-000001')
      const region = w.querySelector('.lemma-edit-region') as HTMLElement
      stubRichSelection(region.querySelector('p')!)
      const input = openPanel(w)

      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
      expect(
        bubble().querySelector('.lemma-canvas-link-panel')!.classList
          .contains('lemma-canvas-link-open'),
      ).toBe(false)
      expect(document.activeElement).toBe(region)
      expect(region.getAttribute('contenteditable')).toBe('true') // session alive
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('a NEW session applies with its OWN saved range (no stale reuse)', () => {
    try {
      const exec = vi.fn(() => true)
      document.execCommand = exec as unknown as typeof document.execCommand

      const w1 = grantRich('lp-h-000001')
      const spies1 = stubRichSelection(w1.querySelector('.lemma-edit-region p')!)
      openPanel(w1)
      endSession(w1) // closes panel via endEditing -> closeLinkPanel

      const w2 = grantRich('lp-i-000001')
      const spies2 = stubRichSelection(w2.querySelector('.lemma-edit-region p')!)
      const input2 = openPanel(w2)
      input2.value = 'https://x.test/two'
      input2.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
      expect(spies2.addRange).toHaveBeenCalledWith(spies2.range)
      expect(spies1.addRange).not.toHaveBeenCalled()
      endSession(w2)
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('typing in the input never triggers stage shortcuts', () => {
    try {
      const w = grantRich('lp-j-000001')
      stubRichSelection(w.querySelector('.lemma-edit-region p')!)
      const input = openPanel(w)
      posted.mockClear()
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Backspace', bubbles: true, cancelable: true }))
      expect(lastPost('lemma:block-delete-request')).toBeUndefined()
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
    }
  })
})
```

- [ ] **Step 2: Run to verify failures**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: the new describe FAILS throughout (no panel exists; link click prompts). The rewritten bubble test passes only if prompt returns undefined-invalid — verify it fails or passes for the RIGHT reason (it fails while prompt is still called).

- [ ] **Step 3: Implement**

In `preview-bridge.js`:

3a. Module state next to the other session vars:

```js
  var linkPanel = null // { root, input } — child of the current bubble
  var savedLinkRange = null // session-scoped (spec pin); cleared by closeLinkPanel
  var linkPanelOpen = false // freeze flag (spec §4)
```

3b. `preventFocusSteal` exempts the input:

```js
  function preventFocusSteal(e) {
    // ... existing comment ...
    if (e.target && e.target.tagName === 'INPUT') return // the link input must focus
    e.preventDefault()
  }
```

3c. `onEditBlur` gains the defensive relatedTarget exception:

```js
  function onEditBlur(e) {
    // Focus visiting the bubble (link panel) keeps the session alive
    // (link-panel spec §2). Null relatedTarget = REAL outside blur (review
    // caution): commit-and-exit as before.
    if (
      e && e.relatedTarget && editing && editing.formatBar
      && editing.formatBar.contains(e.relatedTarget)
    ) return
    commitEditing()
    endEditing()
  }
```

3d. Panel construction + lifecycle (after `applyFormat`):

```js
  function ensureLinkPanel() {
    if (linkPanel && editing && linkPanel.root.parentNode === editing.formatBar) return linkPanel
    var root = document.createElement('div')
    root.className = 'lemma-canvas-link-panel'
    var input = document.createElement('input')
    input.type = 'text'
    input.placeholder = 'Paste a link…'
    input.setAttribute('aria-label', 'Link URL')
    var apply = document.createElement('button')
    apply.type = 'button'
    apply.setAttribute('data-link-apply', '')
    apply.setAttribute('aria-label', 'Apply link')
    apply.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round"><path d="M9 10l-5 5 5 5M4 15h11a4 4 0 0 0 0-8h-1"/></svg>'
    root.appendChild(input)
    root.appendChild(apply)
    input.addEventListener('keydown', onLinkInputKeydown)
    input.addEventListener('input', function () {
      root.classList.remove('lemma-canvas-link-invalid')
    })
    apply.addEventListener('click', function (e) {
      e.preventDefault()
      applyLink()
    })
    editing.formatBar.appendChild(root)
    linkPanel = { root: root, input: input }
    return linkPanel
  }

  function toggleLinkPanel() {
    if (linkPanelOpen) {
      closeLinkPanel()
      return
    }
    var sel = window.getSelection ? window.getSelection() : null
    if (!sel || sel.isCollapsed || sel.rangeCount === 0) return
    var range = sel.getRangeAt(0)
    if (!editing.region.contains(range.commonAncestorContainer)) return
    savedLinkRange = range.cloneRange()
    var panel = ensureLinkPanel()
    // Prefill from the closest <a> ONLY when it lives inside the region
    // (spec pin): a link-like theme wrapper outside the region is ignored.
    var node = range.commonAncestorContainer
    var el = node.nodeType === 1 ? node : node.parentNode
    var a = el && el.closest ? el.closest('a') : null
    panel.input.value = a && editing.region.contains(a) ? a.getAttribute('href') || '' : ''
    panel.root.classList.remove('lemma-canvas-link-invalid')
    panel.root.classList.add('lemma-canvas-link-open')
    linkPanelOpen = true // freeze positioning BEFORE focus collapses the selection
    panel.input.focus()
  }

  /**
   * Idempotent single owner of panel lifecycle state (review caution):
   * saved range, invalid mark, open/freeze flag. Called from the link
   * toggle, Escape, apply success, and endEditing (edit-flush ends the
   * session, so it funnels through endEditing too).
   */
  function closeLinkPanel() {
    savedLinkRange = null
    linkPanelOpen = false
    if (linkPanel) {
      linkPanel.root.classList.remove('lemma-canvas-link-open')
      linkPanel.root.classList.remove('lemma-canvas-link-invalid')
    }
  }

  function onLinkInputKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault()
      applyLink()
    }
    if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      closeLinkPanel()
      if (editing) editing.region.focus()
      positionFormatBubble()
    }
  }

  function applyLink() {
    if (!editing || !linkPanel || !linkPanelOpen) return
    var url = linkPanel.input.value.replace(/^\s+|\s+$/g, '')
    if (!isSafeLinkUrl(url)) {
      // Invalid (empty included — spec pin: empty is NOT unlink): keep the
      // panel open with the VALUE preserved and focus in the input.
      linkPanel.root.classList.add('lemma-canvas-link-invalid')
      linkPanel.input.focus()
      return
    }
    if (!savedLinkRange) {
      closeLinkPanel()
      return
    }
    editing.region.focus()
    var sel = window.getSelection ? window.getSelection() : null
    if (sel && sel.removeAllRanges && sel.addRange) {
      sel.removeAllRanges()
      sel.addRange(savedLinkRange) // order pin: range ACTIVE before createLink
    }
    if (!runCommand('createLink', url)) return // v8 discipline: no side effects
    normalizeRichRegion(editing.region)
    onEditInput()
    closeLinkPanel() // success: AFTER command/normalize/commit scheduling
    positionFormatBubble()
  }
```

3e. `applyFormat`: the `'link'` branch becomes `toggleLinkPanel()`:

```js
    if (action === 'link') {
      toggleLinkPanel()
      return
    }
```
(delete the prompt/`createLink` lines from it; `unlink`/`bold`/`italic` unchanged).

3f. Freeze in `positionFormatBubble` — first guard becomes:

```js
    if (!editing || !editing.formatBar) return
    if (linkPanelOpen) return // freeze (spec §4): never hide while the panel is open
```

3g. `endEditing`: inside the existing `if (editing.formatBar)` block, FIRST line:

```js
      closeLinkPanel()
      linkPanel = null // the bubble (and panel) element is removed below
```

3h. Capture click handler: widen the editing exemption —

```js
        var inBar = e.target && e.target.closest
          ? e.target.closest('.lemma-canvas-format-bar')
          : null
        if (inBar) {
          var fmtBtn = e.target.closest('.lemma-canvas-format-bar [data-format]')
          if (fmtBtn) {
            e.preventDefault()
            e.stopPropagation()
            applyFormat(fmtBtn.getAttribute('data-format'))
          }
          // Panel-internal clicks (input, apply) are handled by the panel's
          // own listeners — never commit-and-exit (link-panel spec §2).
          return
        }
```

3i. Also guard `startEditing`'s early return: opening a NEW session must not
inherit panel state — `startEditing` already returns when `editing` exists;
no change needed (closeLinkPanel ran at the previous endEditing).

In `preview.css`, extend the bubble block:

```css
.lemma-canvas-format-bar { /* add to the existing rule: */ flex-wrap: wrap; }
.lemma-canvas-link-panel {
  display: none;
  flex-basis: 100%;
  gap: 2px;
  padding: 2px;
  align-items: center;
}
.lemma-canvas-link-panel.lemma-canvas-link-open { display: flex; }
.lemma-canvas-link-panel input {
  width: 220px;
  border: 0;
  border-radius: 4px;
  padding: 4px 6px;
  background: #27272a;
  color: #e4e4e7;
  font-size: 12px;
  outline: none;
}
.lemma-canvas-link-panel input::placeholder { color: #71717a; }
.lemma-canvas-link-panel.lemma-canvas-link-invalid input { box-shadow: 0 0 0 1px #ef4444 inset; }
```
(The apply button inherits the shared `.lemma-canvas-format-bar button` rules.)

- [ ] **Step 4: Run to verify all pass**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (all describes; count grows to ~55).

---

### Task 2: Docs, gates, re-stage

- [ ] **Step 1: README** — replace "Link URLs are validated against the safe_url posture before they're applied." with:

```
Links are added through an inline panel in the bubble (TipTap-style, no
browser prompt); URLs are validated against the safe_url posture before
they're applied, and the edit session survives focus moving into the panel.
```

- [ ] **Step 2: CHANGELOG** — in the v8 bullet, replace "Link URLs validate against the safe_url posture before execCommand runs; bad/empty input is a no-op." with:

```
  Links are added through an inline input panel inside the bubble (no
  browser prompt): the edit session survives focus moving into the panel,
  the text selection is saved and restored around the command, URLs
  validate against the safe_url posture before execCommand runs, and
  invalid input keeps the panel open marked invalid.
```

- [ ] **Step 3: Full gates** — admin vitest run, `pnpm type-check`, `pnpm lint`, `vendor/bin/phpunit --filter Preview`.

- [ ] **Step 4: Re-stage (no commit)** — same file list as v8.1 plus `docs/superpowers/specs/2026-07-03-canvas-link-panel-design.md` and `docs/superpowers/plans/2026-07-03-canvas-link-panel.md`. STOP for the user's commit.
