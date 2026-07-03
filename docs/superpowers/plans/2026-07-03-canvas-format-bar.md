# Canvas v8: In-Stage Formatting Bar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** During a rich edit session in the stage iframe, a bridge-owned bar docked above the region offers Bold / Italic / Link / Unlink, with a normalization pass that keeps ALL rich-region HTML sanitizer-allowlist-shaped (also fixing the latent v3 bug where native Cmd+B output vanishes at apply).

**Architecture:** Bridge-asset + preview.css only — no new bridge messages, no parent/admin changes. Task 1 ships the normalization authority + commit-time pass (the latent-bug fix, independently valuable); Task 2 ships the bar UI and actions on top of it; Task 3 documents, gates, and stages.

**Tech Stack:** Vanilla JS bridge asset (ES5-style), static CSS, Vitest + jsdom direct-eval tests.

**Spec:** `docs/superpowers/specs/2026-07-03-canvas-format-bar-design.md`

## Global Constraints

- NO commits during execution: stage (`git add`) at the final task only; the user commits on "commit all". Never stage/commit `CLAUDE.md`.
- Bridge asset stays ES5-flavored (`var`, `function`); NO inline styles / injected `<style>` (CSP pin) — all appearance in `preview.css`; positioning by DOM placement.
- `preview-bridge-dom.spec.ts` is ONE eval per file: every test that grants an edit session MUST end it (Escape) before returning, or `editing` leaks into later tests.
- jsdom has no `document.execCommand` and no working `window.prompt` — stub both per test.
- `normalizeRichRegion` is only ever called with the active region or its detached clone (spec pin) — never the document/wrapper.
- Sanitizer contract (`TipTapHtmlSanitizer`): `strong`, `em`, `s`, `u`, `a[href]` (http/https/mailto/relative; protocol-relative blocked) survive; `b`, `i`, `span[style]` are dropped WITH CHILDREN.
- Every successful bar action calls `onEditInput()` after normalization (spec §4 — deterministic commit, never rely on engines firing `input`).
- `createLink` validates BEFORE `execCommand`; empty/cancelled/invalid prompt → complete no-op (spec pin).

---

### Task 1: Normalization authority + commit-time pass (latent-bug fix)

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts`

**Interfaces:**
- Produces: `normalizeRichRegion(root)` — bridge-internal; rewrites `b→strong`, `i→em`, unwraps `span[style]`, only within `root` (Task 2 calls it after each bar action). `commitEditing()`'s rich branch now posts a normalized detached clone's innerHTML.

- [ ] **Step 1: Write the failing tests**

Append a new describe at the END of `admin/src/__tests__/preview-bridge-dom.spec.ts` (existing helpers `wrapper`, `proseWrapper`, `sendToBridge`, `lastPost`, `posted` are all top-level; the keyboard describe precedes this one):

```ts
describe('rich-region normalization (format-bar spec §2)', () => {
  it('commit normalizes native-shortcut output: <b>/<i> become <strong>/<em>', () => {
    // The latent v3 bug (review pin): native Cmd+B produces <b>, which the
    // save/render sanitizer drops WITH CHILDREN — bolded text vanishes at the
    // next apply. The commit-time pass must fix this with NO bar interaction.
    const w = proseWrapper('nm-a-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'nm-a-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.lemma-edit-region')!
    expect(region.getAttribute('contenteditable')).toBe('true')

    region.innerHTML = '<p><b>Bold</b> and <i>Italic</i></p>'
    posted.mockClear()
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('lemma:text-changed')).toMatchObject({
      id: 'nm-a-000001',
      field: 'body',
      html: '<p><strong>Bold</strong> and <em>Italic</em></p>',
    })
  })

  it('commit unwraps styled spans and handles nesting; the LIVE region is untouched', () => {
    const w = proseWrapper('nm-b-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'nm-b-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.lemma-edit-region')!

    const dirty = '<p><span style="font-weight:700">kept text</span> <b>outer <i>inner</i></b></p>'
    region.innerHTML = dirty
    posted.mockClear()
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('lemma:text-changed')).toMatchObject({
      html: '<p>kept text <strong>outer <em>inner</em></strong></p>',
    })
    // Commit normalizes a DETACHED CLONE (no live-DOM caret risk): the live
    // region kept its original markup. (The session has ended by now, so
    // inspecting it is safe.)
    expect(region.innerHTML).toBe(dirty)
  })

  it('normalization never leaves the region: theme <b>/<i>/<span style> elsewhere survive', () => {
    // Scope pin: theme markup may legitimately use b/i/styled spans OUTSIDE
    // editable content — the bridge must never walk the wrapper or document.
    const w = wrapper(
      'nm-c-000001',
      '<section><b class="theme-bold">theme</b><span style="color:red">styled</span>' +
        '<div class="lemma-edit-region" data-lemma-edit-block="nm-c-000001" ' +
        'data-lemma-edit-field="body"><p><b>mine</b></p></div></section>',
    )
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'nm-c-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.lemma-edit-region')!
    posted.mockClear()
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))

    expect(lastPost('lemma:text-changed')).toMatchObject({ html: '<p><strong>mine</strong></p>' })
    expect(w.querySelector('b.theme-bold')).not.toBeNull()
    expect(w.querySelector('span[style]')).not.toBeNull()
  })
})
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: the 3 new tests FAIL — posted `html` still carries `<b>`/`<i>`/`span[style]` (no normalization exists). All 36 existing tests PASS.

- [ ] **Step 3: Implement the normalizer + commit pass**

In `packages/lemma-render/assets/preview/preview-bridge.js`:

3a. Add after `singleRegionOf()` (~line 160):

```js
  /**
   * Rewrite rich-region HTML into the save/render sanitizer's allowlist shape
   * (format-bar spec §2): b -> strong, i -> em, span[style] unwrapped. The
   * sanitizer drops disallowed elements WITH CHILDREN, so unnormalized native
   * Cmd+B output makes the bolded text itself vanish at the next apply.
   * ONLY ever called with the active edit region or its detached clone (spec
   * pin): theme markup may legitimately use b/i/styled spans elsewhere.
   * Children are MOVED (not cloned), so live selections anchored in text
   * nodes survive when this runs against the live region.
   */
  function normalizeRichRegion(root) {
    var rename = { B: 'strong', I: 'em' }
    var el
    while ((el = root.querySelector('b, i'))) {
      var next = document.createElement(rename[el.tagName])
      while (el.firstChild) next.appendChild(el.firstChild)
      el.parentNode.replaceChild(next, el)
    }
    while ((el = root.querySelector('span[style]'))) {
      while (el.firstChild) el.parentNode.insertBefore(el.firstChild, el)
      el.parentNode.removeChild(el)
    }
  }
```

3b. In `commitEditing()`, replace the rich branch

```js
    if (editing.kind === 'rich') {
      post('text-changed', { id: editing.id, field: editing.field, html: editing.region.innerHTML })
    } else {
```

with

```js
    if (editing.kind === 'rich') {
      // Normalize a DETACHED CLONE (format-bar spec §2): commit must be
      // allowlist-shaped even for HTML the bar never produced (native
      // Cmd+B/Cmd+I, rich paste) — and rewriting the live DOM mid-typing
      // would move the caret.
      var clone = editing.region.cloneNode(true)
      normalizeRichRegion(clone)
      post('text-changed', { id: editing.id, field: editing.field, html: clone.innerHTML })
    } else {
```

- [ ] **Step 4: Run to verify all pass**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (39 tests).

---

### Task 2: The formatting bar (UI, actions, focus survival, link validation)

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Modify: `packages/lemma-render/assets/preview/preview.css`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts`

**Interfaces:**
- Consumes: `normalizeRichRegion(root)` (Task 1), existing `editing` session state, `onEditInput()` debounce scheduler, `stripCanvasState`'s `.lemma-canvas-shim` removal rule.
- Produces: `.lemma-canvas-format-bar` DOM with `data-format="bold|italic|link|unlink"` buttons; `isSafeLinkUrl(url)` predicate; `applyFormat(action)`.

- [ ] **Step 1: Write the failing tests**

Append another describe at the END of `admin/src/__tests__/preview-bridge-dom.spec.ts`:

```ts
describe('in-stage formatting bar (format-bar spec §1/§3/§4)', () => {
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

  it('a rich grant docks the bar before the region; plain kinds get none', () => {
    const w = grantRich('fb-a-000001')
    const region = w.querySelector('.lemma-edit-region')!
    const anchor = w.querySelector('.lemma-canvas-format-anchor')!
    expect(anchor).not.toBeNull()
    expect(anchor.nextElementSibling).toBe(region)
    const formats = [...anchor.querySelectorAll('[data-format]')].map((b) =>
      b.getAttribute('data-format'),
    )
    expect(formats).toEqual(['bold', 'italic', 'link', 'unlink'])

    // Ending the session removes the bar entirely.
    endSession(w)
    expect(w.querySelector('.lemma-canvas-format-anchor')).toBeNull()

    // A string-kind session never gets a bar.
    const s = wrapper(
      'fb-b-000001',
      '<section><h2><span class="lemma-edit-region" data-lemma-edit-block="fb-b-000001" ' +
        'data-lemma-edit-field="heading">Hello</span></h2></section>',
    )
    document.body.appendChild(s)
    sendToBridge({ type: 'lemma:edit-grant', id: 'fb-b-000001', field: 'heading', kind: 'string' })
    expect(s.querySelector('[contenteditable]')).not.toBeNull()
    expect(s.querySelector('.lemma-canvas-format-anchor')).toBeNull()
    endSession(s)
  })

  it('bold/italic clicks run execCommand, normalize the live region, and stay in-session', () => {
    let region: Element // assigned after grant; the stub only runs at click time
    const exec = vi.fn((cmd: string) => {
      // jsdom has no execCommand: emulate the engine's b/i output so the
      // post-action normalization has something real to rewrite.
      if (cmd === 'bold') region.innerHTML = '<p><b>sel</b> rest</p>'
      if (cmd === 'italic') region.innerHTML = '<p><i>sel</i> rest</p>'
      return true
    })
    document.execCommand = exec as unknown as typeof document.execCommand
    const w = grantRich('fb-c-000001')
    region = w.querySelector('.lemma-edit-region')!

    const bold = w.querySelector('[data-format="bold"]')!
    bold.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(exec).toHaveBeenCalledWith('bold')
    expect(region.innerHTML).toBe('<p><strong>sel</strong> rest</p>')

    w.querySelector('[data-format="italic"]')!.dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    )
    expect(region.innerHTML).toBe('<p><em>sel</em> rest</p>')

    // The click landed on the bar (outside the region) but the session
    // survives: no edit-end, contenteditable intact.
    expect(lastPost('lemma:edit-end')).toBeUndefined()
    expect(region.getAttribute('contenteditable')).toBe('true')
    endSession(w)
  })

  it('bar pointerdown AND mousedown are both default-prevented (focus never leaves)', () => {
    const w = grantRich('fb-d-000001')
    const bar = w.querySelector('.lemma-canvas-format-bar')!
    const pd = new MouseEvent('pointerdown', { bubbles: true, cancelable: true })
    bar.dispatchEvent(pd)
    expect(pd.defaultPrevented).toBe(true)
    const md = new MouseEvent('mousedown', { bubbles: true, cancelable: true })
    bar.dispatchEvent(md)
    expect(md.defaultPrevented).toBe(true)
    endSession(w)
  })

  it('a bar click posts text-changed WITHOUT any input event (deterministic commit)', () => {
    vi.useFakeTimers()
    try {
      document.execCommand = vi.fn(() => true) as unknown as typeof document.execCommand
      const w = grantRich('fb-e-000001')
      const region = w.querySelector('.lemma-edit-region')!
      region.innerHTML = '<p><b>x</b></p>' // pretend the engine mutated on execCommand
      posted.mockClear()

      w.querySelector('[data-format="bold"]')!.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
      )
      expect(lastPost('lemma:text-changed')).toBeUndefined() // debounced, not instant
      vi.advanceTimersByTime(450)
      expect(lastPost('lemma:text-changed')).toMatchObject({
        id: 'fb-e-000001',
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
    const w = grantRich('fb-f-000001')
    const link = w.querySelector('[data-format="link"]')!
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

  it('the bar never reaches committed HTML or duplicate clones', () => {
    document.execCommand = vi.fn(() => true) as unknown as typeof document.execCommand
    const w = grantRich('fb-g-000001')
    posted.mockClear()
    endSession(w) // commit + end
    const committed = lastPost('lemma:text-changed')!
    expect(String(committed.html)).not.toContain('lemma-canvas-format')

    // Mirror-duplicate while a session shows the bar: the clone is stripped
    // (the anchor carries lemma-canvas-shim, which stripCanvasState removes).
    sendToBridge({ type: 'lemma:edit-grant', id: 'fb-g-000001', field: 'body', kind: 'rich' })
    expect(w.querySelector('.lemma-canvas-format-anchor')).not.toBeNull()
    sendToBridge({
      type: 'lemma:mirror-duplicate',
      sourceId: 'fb-g-000001',
      idMap: { 'fb-g-000001': 'fb-g-000002' },
    })
    const clone = document.querySelector('[data-lemma-block="fb-g-000002"]')!
    expect(clone.querySelector('.lemma-canvas-format-anchor')).toBeNull()
    expect(clone.querySelector('.lemma-canvas-format-bar')).toBeNull()
    endSession(w)
  })
})
```

Implementer note: every test ends its session (`endSession`) — one-eval-per-file discipline; a leaked `editing` would trip the keyboard describe's guards and any later grant.

- [ ] **Step 2: Run to verify they fail**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: the 6 new tests FAIL (no `.lemma-canvas-format-anchor` is ever created). Tasks-1 tests and all older tests PASS.

- [ ] **Step 3: Implement the bar in the bridge**

In `packages/lemma-render/assets/preview/preview-bridge.js`:

3a. Add bar construction + actions after `normalizeRichRegion()`:

```js
  // ── In-stage formatting bar (format-bar spec §1/§3) ─────────────────────────
  var FORMAT_ACTIONS = [
    { format: 'bold', label: 'Bold', path: 'M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z' },
    { format: 'italic', label: 'Italic', path: 'M19 5h-8M13 19H5M15 5L9 19' },
    { format: 'link', label: 'Add link', path: 'M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1-1' },
    { format: 'unlink', label: 'Remove link', path: 'M15 7h2a5 5 0 0 1 0 10h-2M9 17H7A5 5 0 0 1 7 7h2M4 4l16 16' }
  ]

  function preventFocusSteal(e) {
    // Both pointerdown AND mousedown (spec pin): focus changes are
    // pointer-driven first on modern engines — cancelling only mousedown can
    // let the region blur (commit-and-exit) before the format action runs.
    e.preventDefault()
  }

  function showFormatBar(region) {
    var anchor = document.createElement('span')
    // lemma-canvas-anchor gives position:relative, lemma-canvas-shim gives the
    // zero-height inline-block AND puts the element under stripCanvasState's
    // existing shim-removal rule — clones can never carry the bar.
    anchor.className = 'lemma-canvas-anchor lemma-canvas-shim lemma-canvas-format-anchor'
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
    anchor.appendChild(bar)
    region.insertAdjacentElement('beforebegin', anchor)
    return anchor
  }

  function isSafeLinkUrl(url) {
    // Mirror of the safe_url/sanitizer posture (spec pin): the sanitizer
    // stays the authority — this check is UX honesty, a link that would be
    // stripped at save must never appear in the stage.
    if (typeof url !== 'string') return false
    var trimmed = url.replace(/^\s+|\s+$/g, '')
    if (trimmed === '') return false
    if (trimmed.slice(0, 2) === '//') return false // protocol-relative
    var m = /^([a-zA-Z][a-zA-Z0-9+.-]*):/.exec(trimmed)
    if (!m) return true // relative: /path, #anchor, ?q, bare path
    var scheme = m[1].toLowerCase()
    return scheme === 'http' || scheme === 'https' || scheme === 'mailto'
  }

  function applyFormat(action) {
    if (!editing || editing.kind !== 'rich') return
    if (action === 'link') {
      var url = window.prompt('Link URL')
      var trimmed = typeof url === 'string' ? url.replace(/^\s+|\s+$/g, '') : ''
      if (!isSafeLinkUrl(trimmed)) return // no-op BEFORE execCommand (spec pin)
      document.execCommand('createLink', false, trimmed)
    } else if (action === 'unlink') {
      document.execCommand('unlink')
    } else if (action === 'bold' || action === 'italic') {
      document.execCommand(action)
    } else {
      return
    }
    normalizeRichRegion(editing.region) // live pass: selection survives node MOVES
    onEditInput() // deterministic commit (spec §4): never rely on engines firing input
  }
```

3b. In `startEditing()`, extend the session object and dock the bar. Change

```js
    editing = { id: id, field: field, kind: kind, region: region, debounce: null }
```

to

```js
    editing = { id: id, field: field, kind: kind, region: region, debounce: null, formatAnchor: null }
```

and after the `region.classList.add('lemma-canvas-editing')` line add:

```js
    if (kind === 'rich') editing.formatAnchor = showFormatBar(region)
```

3c. In `endEditing()`, remove the bar. After the three `removeEventListener` lines add:

```js
    if (editing.formatAnchor && editing.formatAnchor.parentNode) {
      editing.formatAnchor.parentNode.removeChild(editing.formatAnchor)
    }
```

3d. In the capture `click` listener's editing branch, add the bar exemption. Change

```js
      if (editing) {
        // Caret placement inside the active region passes through untouched;
        // any click outside commits-and-exits, then v2 semantics resume.
        if (editing.region.contains(e.target)) return
        commitEditing()
        endEditing()
      }
```

to

```js
      if (editing) {
        // The formatting bar is the ONE outside-the-region click that acts
        // instead of ending the session (format-bar spec §3).
        var fmtBtn = e.target && e.target.closest
          ? e.target.closest('.lemma-canvas-format-bar [data-format]')
          : null
        if (fmtBtn) {
          e.preventDefault()
          e.stopPropagation()
          applyFormat(fmtBtn.getAttribute('data-format'))
          return
        }
        // Caret placement inside the active region passes through untouched;
        // any click outside commits-and-exits, then v2 semantics resume.
        if (editing.region.contains(e.target)) return
        commitEditing()
        endEditing()
      }
```

- [ ] **Step 4: Add the static CSS**

In `packages/lemma-render/assets/preview/preview.css`:

4a. Widen the shared button rules (three selectors change):

```css
.lemma-canvas-toolbar button,
.lemma-canvas-format-bar button {
```
(on the existing `.lemma-canvas-toolbar button` rule), and likewise
`.lemma-canvas-toolbar button:hover, .lemma-canvas-format-bar button:hover`
and `.lemma-canvas-toolbar svg, .lemma-canvas-format-bar svg`.

4b. Append at the end of the file:

```css
/* In-stage formatting bar (format-bar spec §1): DOM placement + static rules
   only (CSP pin). The anchor is a zero-height shim sibling BEFORE the region
   (position:relative + shim geometry come from the reused anchor/shim
   classes); the bar floats just above the region's first line. */
.lemma-canvas-format-bar {
  position: absolute;
  bottom: 4px;
  left: 0;
  z-index: 2147483000;
  display: flex;
  gap: 2px;
  padding: 2px;
  border-radius: 6px;
  background: #18181b;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
}
```

- [ ] **Step 5: Run to verify all pass**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (45 tests). Watch the edit-in-place describe: its rich-grant tests now also dock a bar — if any of its selectors (e.g. `:scope > *` shapes) break, the bar's placement BEFORE the region inside the host is the cause; fix the test only if its assertion was incidental, never by moving the bar inside the region.

---

### Task 3: Docs, full gates, STAGE

**Files:**
- Modify: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md`

**Interfaces:** none — closes the feature.

- [ ] **Step 1: README**

In `packages/lemma-render/README.md`, the prose-editing paragraph ends "…Typed HTML is sanitized at save and re-sanitized by `safe_html` at render." Append to that paragraph:

```
While a rich session is active, a small formatting bar docked above the
region offers bold, italic, and link/unlink; the bridge normalizes ALL
rich-region output (bar actions, native Cmd+B/Cmd+I, paste) into the
sanitizer's allowlist shape (`strong`/`em`, no styled spans) before it
flows back, so formatting survives save and re-render. Link URLs are
validated against the safe_url posture before they're applied.
```

- [ ] **Step 2: CHANGELOG**

In `CHANGELOG.md` under `## [Unreleased]`, after the canvas v7 bullet add:

```
- In-stage formatting bar (canvas v8): rich edit sessions dock a small
  bar above the region — bold, italic, link, unlink — applied in place and
  normalized into the sanitizer's allowlist shape (`b/i` → `strong/em`,
  styled spans unwrapped) both after each action and at commit. The
  commit-time pass also fixes a latent v3 bug: native Cmd+B output
  (`<b>`) was dropped WITH its text by the save/render sanitizer, so
  bolded text vanished at the next apply. Link URLs validate against the
  safe_url posture before execCommand runs; bad/empty input is a no-op.
  The bar cancels pointerdown/mousedown (the edit session never blurs)
  and every action schedules the debounced commit explicitly.
```

- [ ] **Step 3: Full gates**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run   # full admin suite
pnpm type-check
pnpm lint
cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit --filter Preview
```

Expected: all green.

- [ ] **Step 4: STAGE (no commit)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add packages/lemma-render/assets/preview/preview-bridge.js \
        packages/lemma-render/assets/preview/preview.css \
        packages/lemma-render/README.md \
        admin/src/__tests__/preview-bridge-dom.spec.ts \
        CHANGELOG.md \
        docs/superpowers/specs/2026-07-03-canvas-format-bar-design.md \
        docs/superpowers/plans/2026-07-03-canvas-format-bar.md
git status
```

Expected: exactly these files staged. STOP — the user commits.
