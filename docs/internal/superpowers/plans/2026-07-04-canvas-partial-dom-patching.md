# Canvas v10: Partial DOM Patching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Successful applies patch the stage's DOM in place (wrapper-boundary swaps from a real working-copy render) instead of reloading the iframe; anything unprovable falls back to today's honest reload.

**Architecture:** Bridge does the fetch/gate/patch (`stage-refresh` → `stage-refreshed {refresh_id, mode}`); composable adds id-correlated `stageRefresh()`; the page's `runApply` success line becomes `await refreshStage()`. No server changes.

**Tech Stack:** Vanilla JS bridge asset, Vue 3 + TS admin, Vitest + jsdom (fetch/DOMParser stubs).

**Spec:** `docs/superpowers/specs/2026-07-04-canvas-partial-dom-patching-design.md`

## Global Constraints

- NO commits: stage at the end, STOP for "commit all".
- Honest-stage: patch ONLY after the full gate passes (fetch 2xx, not redirected, parseable, has body, id sequences match with no duplicates, shell skeletons identical); every violation acks `reload` with DOM untouched.
- Top-level wrapper = `data-lemma-block` with no `data-lemma-block` ancestor (spec pin).
- Mirrored structural ops patch (review P2): the gate compares against the LIVE (mirrored) DOM; matching id sequences proceed even after move/duplicate/delete mirrors.
- `refresh_id` correlation on both sides (spec pin); messages nonce-enveloped as always.
- `busy` (edit session or drag) → parent does NOTHING; failure-path reloads untouched.
- One-eval-per-file bridge tests: end sessions, restore `window.fetch` stubs in `finally`.

---

### Task 1: Bridge `stage-refresh` handler + direct tests

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts`

**Interfaces:**
- Consumes: `stripCanvasState`, `findBlock`, `selectWrapper`, `clearSelection`, `editing`/`drag` state, `post()`.
- Produces: inbound `lemma:stage-refresh {refresh_id}` branch; outbound `lemma:stage-refreshed {refresh_id, mode}`.

- [ ] **Step 1: Write the failing tests**

Append a describe to `preview-bridge-dom.spec.ts`:

```ts
describe('stage refresh / partial DOM patching (dom-patching spec §2)', () => {
  const realFetch = window.fetch

  function acked(): { refresh_id?: string; mode?: string } | undefined {
    return lastPost('lemma:stage-refreshed') as { refresh_id?: string; mode?: string } | undefined
  }

  function stubFetch(html: string, opts: { ok?: boolean; redirected?: boolean } = {}): void {
    window.fetch = vi.fn().mockResolvedValue({
      ok: opts.ok ?? true,
      redirected: opts.redirected ?? false,
      text: () => Promise.resolve(html),
    }) as unknown as typeof window.fetch
  }

  /** Build a page-shaped live body and return its pieces. */
  function liveStage(): { a: HTMLElement; b: HTMLElement } {
    document.body.innerHTML =
      '<header><h1>Shell title</h1></header><main></main>'
    const main = document.body.querySelector('main')!
    const a = wrapper('pd-a-0000001', '<section><p>Alpha v1</p></section>')
    const b = wrapper('pd-b-0000002', '<section><p>Beta v1</p></section>')
    main.append(a, b)
    return { a, b }
  }

  /** The fetched render: same shell, block contents parameterizable. */
  function renderedHtml(alpha: string, beta: string, shellTitle = 'Shell title'): string {
    return (
      `<html><body><header><h1>${shellTitle}</h1></header><main>` +
      `<div class="lemma-preview-block" data-lemma-block="pd-a-0000001"><section><p>${alpha}</p></section></div>` +
      `<div class="lemma-preview-block" data-lemma-block="pd-b-0000002"><section><p>${beta}</p></section></div>` +
      `</main></body></html>`
    )
  }

  async function refresh(id = 'r1'): Promise<void> {
    sendToBridge({ type: 'lemma:stage-refresh', refresh_id: id })
    await new Promise((r) => setTimeout(r, 0)) // let the fetch promise chain settle
    await new Promise((r) => setTimeout(r, 0))
  }

  it('swaps ONLY the changed wrapper and acks patched with the echoed id', async () => {
    try {
      const { a, b } = liveStage()
      stubFetch(renderedHtml('Alpha v2', 'Beta v1'))
      posted.mockClear()
      await refresh('r-alpha')

      expect(acked()).toMatchObject({ refresh_id: 'r-alpha', mode: 'patched' })
      const newA = document.querySelector('[data-lemma-block="pd-a-0000001"]')!
      expect(newA.textContent).toContain('Alpha v2')
      expect(newA).not.toBe(a) // swapped
      expect(document.querySelector('[data-lemma-block="pd-b-0000002"]')).toBe(b) // identity kept
      expect(document.querySelector('h1')!.textContent).toBe('Shell title')
    } finally {
      window.fetch = realFetch
    }
  })

  it('shell drift reloads with the DOM untouched', async () => {
    try {
      const { a } = liveStage()
      stubFetch(renderedHtml('Alpha v2', 'Beta v1', 'NEW shell title'))
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'reload' })
      expect(document.querySelector('[data-lemma-block="pd-a-0000001"]')).toBe(a) // untouched
      expect(a.textContent).toContain('Alpha v1')
    } finally {
      window.fetch = realFetch
    }
  })

  it('unmirrored structural drift reloads: extra id, order mismatch, duplicates', async () => {
    try {
      liveStage()
      // Extra wrapper in the render (add-after shape).
      stubFetch(
        renderedHtml('Alpha v1', 'Beta v1').replace(
          '</main>',
          '<div class="lemma-preview-block" data-lemma-block="pd-c-0000003"><p>New</p></div></main>',
        ),
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'reload' })

      // Duplicate id on the fetched side.
      stubFetch(
        renderedHtml('Alpha v1', 'Beta v1').replace(
          'data-lemma-block="pd-b-0000002"',
          'data-lemma-block="pd-a-0000001"',
        ),
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'reload' })
    } finally {
      window.fetch = realFetch
    }
  })

  it('mirrored structural ops patch: a mirror-matched live order is NOT drift (review P2)', async () => {
    try {
      const { a, b } = liveStage()
      // Mirror a move (b before a) the way the parent would after a commit.
      sendToBridge({ type: 'lemma:mirror-move', id: 'pd-b-0000002', beforeId: 'pd-a-0000001' })
      expect(b.nextElementSibling).toBe(a)
      // The render agrees with the mirrored order.
      stubFetch(
        `<html><body><header><h1>Shell title</h1></header><main>` +
          `<div class="lemma-preview-block" data-lemma-block="pd-b-0000002"><section><p>Beta SERVER</p></section></div>` +
          `<div class="lemma-preview-block" data-lemma-block="pd-a-0000001"><section><p>Alpha v1</p></section></div>` +
          `</main></body></html>`,
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'patched' })
      // The optimistic mirror's content is swapped to the RENDERED truth.
      expect(
        document.querySelector('[data-lemma-block="pd-b-0000002"]')!.textContent,
      ).toContain('Beta SERVER')
      expect(document.querySelector('[data-lemma-block="pd-a-0000001"]')).toBe(a)
    } finally {
      window.fetch = realFetch
    }
  })

  it('canvas UI never poisons the gate; a swapped selected wrapper re-selects', async () => {
    try {
      const { a } = liveStage()
      a.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      expect(a.querySelector('.lemma-canvas-toolbar')).not.toBeNull() // live UI present
      stubFetch(renderedHtml('Alpha v2', 'Beta v1'))
      posted.mockClear()
      await refresh()

      expect(acked()).toMatchObject({ mode: 'patched' })
      const newA = document.querySelector('[data-lemma-block="pd-a-0000001"]')!
      expect(newA.textContent).toContain('Alpha v2')
      // Selection survived the swap: ring + toolbar re-anchored on the NEW wrapper.
      expect(newA.classList.contains('lemma-canvas-selected')).toBe(true)
      expect(newA.querySelector('.lemma-canvas-toolbar')).not.toBeNull()
      // Deselect cleanly for later tests.
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
      )
    } finally {
      window.fetch = realFetch
    }
  })

  it('a selected NESTED block dropped by its swapped parent deselects honestly', async () => {
    // Top-level ids can never vanish past the sequence gate — but a selected
    // NESTED block can, when its parent wrapper is swapped and the new render
    // no longer contains it (spec §2.6: clear selection + post block-deselect).
    try {
      document.body.innerHTML = '<main></main>'
      const parent = wrapper(
        'pd-vp-00001',
        '<section><div class="lemma-preview-block" data-lemma-block="pd-vc-00001"><p>child</p></div></section>',
      )
      document.body.querySelector('main')!.appendChild(parent)
      const child = document.querySelector('[data-lemma-block="pd-vc-00001"]')!
      child.querySelector('p')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      expect(lastPost('lemma:block-select')).toMatchObject({ id: 'pd-vc-00001' })

      stubFetch(
        `<html><body><main>` +
          `<div class="lemma-preview-block" data-lemma-block="pd-vp-00001"><section><p>childless now</p></section></div>` +
          `</main></body></html>`,
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'patched' })
      expect(lastPost('lemma:block-deselect')).toMatchObject({ id: 'pd-vc-00001' })
      expect(document.querySelector('.lemma-canvas-toolbar')).toBeNull()
    } finally {
      window.fetch = realFetch
    }
  })

  it('busy during an edit session and during a drag; DOM untouched', async () => {
    try {
      // Edit session.
      const w = proseWrapper('pd-ed-00001')
      document.body.appendChild(w)
      sendToBridge({ type: 'lemma:edit-grant', id: 'pd-ed-00001', field: 'body', kind: 'rich' })
      stubFetch(renderedHtml('x', 'y'))
      posted.mockClear()
      await refresh('r-busy')
      expect(acked()).toMatchObject({ refresh_id: 'r-busy', mode: 'busy' })
      w.querySelector('.lemma-edit-region')!.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )

      // Drag (grip a fresh list).
      document.body.innerHTML = ''
      const list = document.createElement('main')
      const d1 = wrapper('pd-dr-00001')
      const d2 = wrapper('pd-dr-00002')
      list.append(d1, d2)
      document.body.appendChild(list)
      document.body.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      d1.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      d1.querySelector('[data-action="drag"] svg')!.dispatchEvent(
        new MouseEvent('pointerdown', { bubbles: true, cancelable: true }),
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'busy' })
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    } finally {
      window.fetch = realFetch
    }
  })

  it('fetch failures reload with DOM untouched: rejection, non-2xx, redirect, bodyless, annotation-less', async () => {
    try {
      const { a } = liveStage()
      const cases: Array<() => void> = [
        () => {
          window.fetch = vi.fn().mockRejectedValue(new Error('net')) as unknown as typeof window.fetch
        },
        () => stubFetch(renderedHtml('x', 'y'), { ok: false }),
        () => stubFetch(renderedHtml('x', 'y'), { redirected: true }),
        () => stubFetch(''), // parses to an empty body: zero wrappers while live has two
        () => stubFetch('<html><body><p>login page</p></body></html>'), // annotation-less
      ]
      for (const arm of cases) {
        arm()
        posted.mockClear()
        await refresh()
        expect(acked()).toMatchObject({ mode: 'reload' })
        expect(document.querySelector('[data-lemma-block="pd-a-0000001"]')).toBe(a)
      }
    } finally {
      window.fetch = realFetch
    }
  })

  it('a nested-only change swaps the top-level parent exactly once', async () => {
    try {
      document.body.innerHTML = '<main></main>'
      const parent = wrapper(
        'pd-np-00001',
        '<section><div class="lemma-preview-block" data-lemma-block="pd-nc-00001"><p>child v1</p></div></section>',
      )
      document.body.querySelector('main')!.appendChild(parent)
      stubFetch(
        `<html><body><main>` +
          `<div class="lemma-preview-block" data-lemma-block="pd-np-00001"><section>` +
          `<div class="lemma-preview-block" data-lemma-block="pd-nc-00001"><p>child v2</p></div>` +
          `</section></div></main></body></html>`,
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'patched' })
      expect(
        document.querySelector('[data-lemma-block="pd-nc-00001"]')!.textContent,
      ).toContain('child v2')
      // ONE top-level wrapper for the id in the document (no double insert).
      expect(document.querySelectorAll('[data-lemma-block="pd-np-00001"]')).toHaveLength(1)
    } finally {
      window.fetch = realFetch
    }
  })
})
```

Implementer notes: `liveStage` resets `document.body.innerHTML` itself (this file's beforeEach also clears it); the selected-wrapper test must end deselected (Escape) so selection can't leak; `refresh()`'s double `setTimeout(0)` drains the two-promise fetch chain — if flaky, `await vi.waitFor(() => expect(acked()).toBeDefined())` instead.

- [ ] **Step 2: Run to verify all fail** — the bridge ignores `lemma:stage-refresh` entirely, so every test fails on a missing ack.

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`

- [ ] **Step 3: Implement in the bridge**

3a. Helpers after `stripCanvasState` (they lean on it):

```js
  // ── Partial DOM patching (dom-patching spec §2) ─────────────────────────────
  function topLevelWrappers(root) {
    // Spec pin: data-lemma-block with NO data-lemma-block ancestor.
    return Array.prototype.filter.call(
      root.querySelectorAll('[data-lemma-block]'),
      function (el) {
        return !(el.parentElement && el.parentElement.closest('[data-lemma-block]'))
      }
    )
  }

  function wrapperIds(tops) {
    return tops.map(function (el) { return el.getAttribute('data-lemma-block') })
  }

  function hasDuplicates(ids) {
    var seen = {}
    for (var i = 0; i < ids.length; i++) {
      if (seen[ids[i]]) return true
      seen[ids[i]] = true
    }
    return false
  }

  function cleanedLiveBody() {
    var clone = document.body.cloneNode(true)
    stripCanvasState(clone)
    // Body-mounted bridge UI never participates in comparisons.
    Array.prototype.forEach.call(
      clone.querySelectorAll('.lemma-canvas-format-bar, .lemma-canvas-drag-ghost'),
      function (el) { el.parentNode.removeChild(el) }
    )
    return clone
  }

  function shellSkeleton(body) {
    var clone = body.cloneNode(true)
    topLevelWrappers(clone).forEach(function (el) { el.innerHTML = '' })
    return clone.innerHTML
  }

  function applyStagePatch(newBody, refreshId) {
    var liveClean = cleanedLiveBody()
    var liveTops = topLevelWrappers(liveClean)
    var newTops = topLevelWrappers(newBody)
    var liveIds = wrapperIds(liveTops)
    var newIds = wrapperIds(newTops)
    // Gate (spec pins): identical, duplicate-free id sequences — the LIVE side
    // already carries structural mirrors, so a mirror-matched order patches
    // (review P2); only unmirrored drift (add-after, disagreements) reloads.
    if (
      hasDuplicates(liveIds) || hasDuplicates(newIds)
      || liveIds.join(' ') !== newIds.join(' ')
      || (liveIds.length > 0 && newIds.length === 0)
    ) {
      post('stage-refreshed', { refresh_id: refreshId, mode: 'reload' })
      return
    }
    if (shellSkeleton(liveClean) !== shellSkeleton(newBody)) {
      post('stage-refreshed', { refresh_id: refreshId, mode: 'reload' })
      return
    }
    for (var i = 0; i < liveIds.length; i++) {
      if (liveTops[i].outerHTML === newTops[i].outerHTML) continue
      var liveEl = findBlock(liveIds[i]) // the REAL wrapper (ids are entry-unique)
      if (!liveEl || !liveEl.parentNode) continue
      liveEl.parentNode.replaceChild(document.importNode(newTops[i], true), liveEl)
    }
    // Selection survives a swap (spec §2.6): re-anchor, or clear honestly.
    if (selectedId !== null) {
      var sel = findBlock(selectedId)
      if (!sel) {
        var goneId = selectedId
        clearSelection()
        post('block-deselect', { id: goneId })
      } else if (!sel.classList.contains('lemma-canvas-selected')) {
        selectWrapper(sel)
      }
    }
    post('stage-refreshed', { refresh_id: refreshId, mode: 'patched' })
  }

  function onStageRefresh(refreshId) {
    if (editing || drag) {
      post('stage-refreshed', { refresh_id: refreshId, mode: 'busy' })
      return
    }
    if (!window.fetch || !window.DOMParser) {
      post('stage-refreshed', { refresh_id: refreshId, mode: 'reload' })
      return
    }
    window.fetch(window.location.href)
      .then(function (res) {
        // Fetch-failure rules (spec pin): non-2xx or redirected → reload.
        if (!res || !res.ok || res.redirected) throw new Error('bad response')
        return res.text()
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(String(html), 'text/html')
        if (!doc || !doc.body) throw new Error('unparseable')
        applyStagePatch(doc.body, refreshId)
      })
      .catch(function () {
        post('stage-refreshed', { refresh_id: refreshId, mode: 'reload' })
      })
  }
```

3b. Message branch (with the other inbound handlers):

```js
    if (data.type === 'lemma:stage-refresh') {
      onStageRefresh(typeof data.refresh_id === 'string' ? data.refresh_id : '')
    }
```

- [ ] **Step 4: Run to verify green** (same command; whole file passes).

---

### Task 2: Composable `stageRefresh()`

**Files:**
- Modify: `admin/src/composables/useCanvasBridge.ts`
- Test: `admin/src/__tests__/canvas-bridge.spec.ts`

- [ ] **Step 1: Failing tests** — matching-ack resolution per mode, foreign `refresh_id` ignored, timeout resolves `'reload'` (fake timers):

```ts
  it('stageRefresh resolves on the MATCHING ack; foreign ids ignored; timeout reloads', async () => {
    vi.useFakeTimers()
    try {
      const bridge = useCanvasBridge(ref(null))
      const p = bridge.stageRefresh()
      // A foreign ack must not resolve it.
      window.dispatchEvent(
        new MessageEvent('message', {
          data: { type: 'lemma:stage-refreshed', refresh_id: 'someone-else', mode: 'patched', nonce: bridge.nonce },
        }),
      )
      // The matching ack does. (Grab the posted refresh_id via a second bridge
      // with a stubbed iframe if needed — or post through an iframe stub and
      // capture; mirror the existing post-capture pattern in this file.)
      // ... capture refreshId from the posted message ...
      window.dispatchEvent(
        new MessageEvent('message', {
          data: { type: 'lemma:stage-refreshed', refresh_id: refreshId, mode: 'busy', nonce: bridge.nonce },
        }),
      )
      await expect(p).resolves.toBe('busy')

      // Timeout path.
      const p2 = bridge.stageRefresh()
      vi.advanceTimersByTime(4100)
      await expect(p2).resolves.toBe('reload')
      bridge.dispose()
    } finally {
      vi.useRealTimers()
    }
  })
```

(Use the file's existing iframe-stub post-capture pattern — `contentWindow: { postMessage: postSpy }` — to read the outgoing `refresh_id`; the assertions above are the contract.)

- [ ] **Step 2: Implement** — module state `let pendingRefresh: { id: string; resolve: (m) => void } | null`, sequence counter for ids, `stageRefresh()` posting `{type: 'lemma:stage-refresh', refresh_id}` and resolving on the matching `lemma:stage-refreshed` (mode validated against the three literals, anything else → `'reload'`) or after a 4000ms timeout; message branch clears `pendingRefresh` only on an id match.

- [ ] **Step 3: Run** `pnpm vitest run src/__tests__/canvas-bridge.spec.ts` — green.

---

### Task 3: Page `refreshStage()` + mock + page tests

**Files:**
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`
- Modify: `admin/src/__tests__/canvas-page.spec.ts`

- [ ] **Step 1: Extend the hoisted bridge mock** with `stageRefresh: vi.fn().mockResolvedValue('patched')` (default: patch succeeds).

- [ ] **Step 2: Failing page tests** — check how existing apply tests detect a reload (iframe remount / `restoreScroll` after hello / iframe `src` flip) and mirror that mechanism:

```ts
  it('apply success PATCHES instead of reloading; reload-mode and failure still reload', async () => {
    // 1) stageRefresh -> 'patched': apply succeeds, iframe NOT remounted.
    // 2) stageRefresh -> 'reload': iframe remounts (same detection the
    //    existing apply tests use).
    // 3) apply FAILURE (applyPreview rejects): iframe remounts, stageRefresh
    //    NOT called (failure paths keep the direct reload).
  })
```

(Write the three arms as real assertions against the suite's existing reload-detection idiom at execution time — the behavioral contract is fixed above.)

- [ ] **Step 3: Implement in the page**

```ts
/**
 * Post-apply stage refresh (dom-patching spec §4): try the in-place patch;
 * only an explicit 'reload' answer (or the composable's timeout, which
 * resolves 'reload') falls back to the full iframe reload. 'busy' does
 * nothing — the edit-end re-arm re-applies whatever the stage missed.
 */
async function refreshStage(): Promise<void> {
  const mode = await bridge.stageRefresh()
  if (mode === 'reload') reloadStage()
}
```

and in `runApply`, replace the success-path `reloadStage() // same-URL reload — the stash is behind the SAME token URL` with `await refreshStage()`. Failure-path reloads stay.

- [ ] **Step 4: Run** `pnpm vitest run src/__tests__/canvas-page.spec.ts` — green.

---

### Task 4: Docs, full gates, STAGE

- [ ] **Step 1: README** — extend the Apply paragraph ("Applies are automatic by default…") with:

```
Successful applies update the stage in place when the change is provably
confined to block wrappers (a real re-render is fetched and compared —
never a client-side guess); anything else, including added or removed
blocks and theme-shell changes, falls back to a full reload.
```

- [ ] **Step 2: CHANGELOG** — new `[Unreleased]` bullet:

```
- Partial DOM patching (canvas v10): successful Apply/auto-apply no longer
  reloads the stage iframe — the bridge fetches a real render of the
  working copy from the stage's own URL, proves the page shell and the
  top-level block skeleton identical (live mirrors count: mirrored
  move/duplicate/delete orders patch; unmirrored drift reloads), and swaps
  only the wrappers whose HTML changed. Typing never flickers; scroll,
  selection, and the session survive untouched. Anything unprovable —
  fetch failures, shell drift, added/removed blocks — answers with an
  honest full reload, and failure paths keep today's reload semantics.
  New nonce-enveloped, refresh_id-correlated stage-refresh/stage-refreshed
  message pair.
```

- [ ] **Step 3: Full gates** — `pnpm vitest run` (admin), `pnpm type-check`, `pnpm lint`, `vendor/bin/phpunit --filter Preview` (adjacency).

- [ ] **Step 4: STAGE (no commit)** — bridge asset, composable, page, both admin test files, README, CHANGELOG, spec, plan. `git status`, STOP.
