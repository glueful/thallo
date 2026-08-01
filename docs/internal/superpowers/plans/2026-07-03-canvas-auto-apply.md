# Canvas v5: Auto-Apply — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The canvas applies the working tree automatically on an 800ms trailing debounce, with an Auto toggle, edit-session suppression, one-banner-then-suspend failures, and scroll preservation across all stage reloads.

**Architecture:** Auto-apply is a scheduler over ONE shared `runApply()` core (the refactored `applyWorking`) — never a second write path. The bridge gains three messages: `edit-start` (session tracking that can't wedge on a failed grant), throttled `scroll {y}` reports, and inbound `restore-scroll {y}`. The page owns the debounce, the coalescing boolean, suspension, the toggle + localStorage, and scroll memory.

**Tech Stack:** Vue 3 admin, vanilla-JS bridge, vitest (fake timers for the scheduler + throttle).

**Spec:** `docs/superpowers/specs/2026-07-03-canvas-auto-apply-design.md`

## Global Constraints

- **One write path (hard pin):** auto is a scheduler over the same `runApply()` core; token retry, reset-on-failure, banners, and stash semantics live in the core only.
- **No concurrent applies (review pin):** a debounce firing while `applyInFlight` sets `applyQueued = true` and RETURNS; the settle-time follow-up is the only path for queued changes.
- **Coalesced follow-up:** one boolean, at most one follow-up applying the LATEST tree; a failed run clears the flag.
- **Suspend after the FINAL retry only:** a dead-token attempt whose re-mint retry succeeds is TTL churn, never suspension.
- **Timings:** debounce 800ms trailing; scroll throttle 250ms trailing; scroll restore is an instant jump (never smooth).
- **Toggle:** on by default; `localStorage['lemma.canvas.auto_apply']` (`'1'`/`'0'`, absent = on); suspension is session-local (never persisted) and clears on successful manual Apply or toggle click.
- **Edit-session suppression:** tracked from the bridge's `edit-start` (NOT from the grant — a grant that fails region-matching must not wedge suppression) to `edit-end`; edit-end re-arms the debounce when stale.
- **Save draft semantics untouched.**
- **Commit gate:** STAGE at the end of Task 3 only; commit ONLY on explicit authorization. No attribution trailers.
- **Verification:** admin `cd admin && pnpm type-check && pnpm test`; PHP gates in Task 3 (bridge asset changes only — no server code).

---

### Task 1: Bridge + composable — edit-start, scroll report, restore-scroll

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Modify: `admin/src/composables/useCanvasBridge.ts`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts`, `admin/src/__tests__/canvas-bridge.spec.ts`

**Interfaces:**
- Produces (Task 2 relies on):
  - Bridge outbound: `lemma:edit-start {id}` (posted ONLY when a session actually starts), `lemma:scroll {y}` (throttled 250ms trailing, latest `window.scrollY` at fire time).
  - Bridge inbound: `lemma:restore-scroll {y}` → `window.scrollTo(0, y)`.
  - Composable: `onEditStart(cb: (id: string) => void)`, `onEditEnd(cb: (id: string) => void)`, `onScroll(cb: (y: number) => void)`, `restoreScroll(y: number): void`.

- [ ] **Step 1: Write the failing direct tests**

Append inside `describe('edit-in-place session', …)` in `admin/src/__tests__/preview-bridge-dom.spec.ts`:

```ts
  it('a session that actually starts posts edit-start; a failed grant posts nothing', () => {
    const w = proseWrapper('sc-a-0000001')
    document.body.appendChild(w)
    posted.mockClear()
    sendToBridge({ type: 'lemma:edit-grant', id: 'sc-a-0000001', field: 'body', kind: 'rich' })
    expect(lastPost('lemma:edit-start')).toMatchObject({ id: 'sc-a-0000001' })
    sendToBridge({ type: 'lemma:edit-flush' })

    // Grant for a block with NO matching region: no session, no edit-start.
    posted.mockClear()
    sendToBridge({ type: 'lemma:edit-grant', id: 'sc-a-0000001', field: 'nope', kind: 'string' })
    expect(lastPost('lemma:edit-start')).toBeUndefined()
  })
```

And a new describe after it:

```ts
describe('scroll preservation', () => {
  it('scroll posts are trailing-throttled with the LATEST position', () => {
    vi.useFakeTimers()
    try {
      posted.mockClear()
      Object.defineProperty(window, 'scrollY', { value: 100, configurable: true })
      window.dispatchEvent(new Event('scroll'))
      Object.defineProperty(window, 'scrollY', { value: 340, configurable: true })
      window.dispatchEvent(new Event('scroll'))
      // Nothing posted before the throttle window closes.
      expect(lastPost('lemma:scroll')).toBeUndefined()
      vi.advanceTimersByTime(300)
      // ONE post, carrying the latest y.
      const scrolls = posted.mock.calls
        .map((c) => c[0] as { type: string; y?: number })
        .filter((m) => m.type === 'lemma:scroll')
      expect(scrolls).toHaveLength(1)
      expect(scrolls[0]!.y).toBe(340)
    } finally {
      vi.useRealTimers()
    }
  })

  it('restore-scroll jumps instantly via window.scrollTo', () => {
    const scrollTo = vi.fn()
    window.scrollTo = scrollTo as unknown as typeof window.scrollTo
    sendToBridge({ type: 'lemma:restore-scroll', y: 480 })
    expect(scrollTo).toHaveBeenCalledWith(0, 480)
    // Non-number y is dropped.
    scrollTo.mockClear()
    sendToBridge({ type: 'lemma:restore-scroll', y: 'x' })
    expect(scrollTo).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 2: Write the failing composable tests**

Append to the `useCanvasBridge` describe in `admin/src/__tests__/canvas-bridge.spec.ts`:

```ts
  it('scroll + edit lifecycle messages dispatch and restoreScroll posts', () => {
    const postSpy = vi.fn()
    const iframe = ref({
      src: 'https://site.test/_preview/tok123',
      contentWindow: { postMessage: postSpy },
    } as unknown as HTMLIFrameElement)
    const bridge = useCanvasBridge(iframe as Ref<HTMLIFrameElement | null>)
    const scroll = vi.fn()
    const start = vi.fn()
    const end = vi.fn()
    bridge.onScroll(scroll)
    bridge.onEditStart(start)
    bridge.onEditEnd(end)

    window.dispatchEvent(
      new MessageEvent('message', { data: { type: 'lemma:scroll', y: 120, nonce: bridge.nonce } }),
    )
    expect(scroll).toHaveBeenCalledWith(120)
    window.dispatchEvent(
      new MessageEvent('message', { data: { type: 'lemma:scroll', y: 'x', nonce: bridge.nonce } }),
    )
    expect(scroll).toHaveBeenCalledTimes(1) // non-number dropped

    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:edit-start', id: 'b1', nonce: bridge.nonce },
      }),
    )
    expect(start).toHaveBeenCalledWith('b1')
    window.dispatchEvent(
      new MessageEvent('message', { data: { type: 'lemma:edit-end', id: 'b1', nonce: bridge.nonce } }),
    )
    expect(end).toHaveBeenCalledWith('b1')

    bridge.restoreScroll(480)
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:restore-scroll', y: 480, nonce: bridge.nonce },
      'https://site.test',
    )
    bridge.dispose()
  })
```

- [ ] **Step 3: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts src/__tests__/canvas-bridge.spec.ts`
Expected: FAIL — no edit-start/scroll/restore machinery.

- [ ] **Step 4: Implement the bridge**

In `packages/lemma-render/assets/preview/preview-bridge.js`:

**(a)** At the end of `startEditing` (after the caret placement block), post the
lifecycle signal — ONLY reachable when a session actually started:

```js
    post('edit-start', { id: id })
```

**(b)** In `activate()`, add the throttled scroll reporter (before the
`post('blocks-index', …)` line):

```js
    // Scroll preservation (auto-apply spec §3): trailing-throttled reports;
    // the parent restores after every stage reload.
    var scrollTimer = null
    window.addEventListener('scroll', function () {
      if (scrollTimer) return
      scrollTimer = setTimeout(function () {
        scrollTimer = null
        post('scroll', { y: window.scrollY || 0 })
      }, 250)
    })
```

**(c)** In the message listener, add the restore branch (next to the other
inbound branches):

```js
    if (data.type === 'lemma:restore-scroll' && typeof data.y === 'number') {
      window.scrollTo(0, data.y) // instant — a reload restore must not visibly travel
    }
```

- [ ] **Step 5: Implement the composable**

In `admin/src/composables/useCanvasBridge.ts`: `BridgeMessage` gains `y?: number`. Add slots:

```ts
  let editStartCb: ((id: string) => void) | null = null
  let editEndCb: ((id: string) => void) | null = null
  let scrollCb: ((y: number) => void) | null = null
```

`onMessage` branches (next to the edit branches):

```ts
    if (data.type === 'lemma:edit-start' && typeof data.id === 'string') {
      editStartCb?.(data.id)
    }
    if (data.type === 'lemma:edit-end' && typeof data.id === 'string') {
      editEndCb?.(data.id)
    }
    if (data.type === 'lemma:scroll' && typeof data.y === 'number') {
      scrollCb?.(data.y)
    }
```

Returned API additions (before `dispose`):

```ts
    onEditStart(cb: (id: string) => void): void {
      editStartCb = cb
    },
    onEditEnd(cb: (id: string) => void): void {
      editEndCb = cb
    },
    onScroll(cb: (y: number) => void): void {
      scrollCb = cb
    },
    restoreScroll(y: number): void {
      post({ type: 'lemma:restore-scroll', y })
    },
```

- [ ] **Step 6: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts src/__tests__/canvas-bridge.spec.ts`
Expected: PASS.

---

### Task 2: Page — runApply core, scheduler, toggle, scroll wiring

**Files:**
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`
- Test: `admin/src/__tests__/canvas-page.spec.ts`

**Interfaces:**
- Consumes: Task 1's composable API; existing `applyPreview`, `mintAndLoad`, `reloadStage`, `stageStale`, `lastApplied`.
- Produces: `data-test="canvas-auto-toggle"`; localStorage key `lemma.canvas.auto_apply`.

- [ ] **Step 1: Extend the bridge mock + write the failing tests**

In `admin/src/__tests__/canvas-page.spec.ts`:

**(a)** Mock additions — callbacks type gains:

```ts
    editStart?: (id: string) => void
    editEnd?: (id: string) => void
    scroll?: (y: number) => void
```

`instance` gains:

```ts
      onEditStart: (cb: (id: string) => void) => (callbacks.editStart = cb),
      onEditEnd: (cb: (id: string) => void) => (callbacks.editEnd = cb),
      onScroll: (cb: (y: number) => void) => (callbacks.scroll = cb),
      restoreScroll: vi.fn(),
```

`beforeEach` gains:

```ts
  bridge.instance.restoreScroll.mockClear()
  notify.error.mockReset() // the suspension test counts error banners
  localStorage.clear()
```

**(b)** New describe (real-timer tests stay untouched; these use fake timers).
`advance` wraps the async-timer dance:

```ts
describe('auto-apply', () => {
  async function mountAuto() {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    return wrapper
  }

  it('a tree change auto-applies ONCE after the debounce; a burst coalesces', async () => {
    const wrapper = await mountAuto()
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(400)
      bridge.callbacks.move?.('blockaaa0001', 1) // restarts the debounce
      await vi.advanceTimersByTimeAsync(400)
      expect(applyMock).not.toHaveBeenCalled() // still inside the window
      await vi.advanceTimersByTimeAsync(500)
      expect(applyMock).toHaveBeenCalledTimes(1)
      expect(applyMock).toHaveBeenCalledWith('entry0000001', 'en', 'tok1', expect.anything())
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('no concurrent applies: a change during flight queues EXACTLY one follow-up', async () => {
    const wrapper = await mountAuto()
    let release!: () => void
    applyMock.mockImplementationOnce(
      () => new Promise<void>((resolve) => (release = resolve)),
    )
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(900) // first run: now in flight
      expect(applyMock).toHaveBeenCalledTimes(1)

      // Two NON-CANCELLING changes during flight (two cancelling moves would
      // legitimately skip the follow-up: honest lastApplied bookkeeping means
      // stageStale re-derives false when the tree returns to the sent state).
      bridge.callbacks.move?.('blockaaa0001', 1)
      bridge.callbacks.textChanged?.('prose0000003', 'body', { html: '<p>mid-flight</p>' })
      await vi.advanceTimersByTimeAsync(900) // debounce fires -> queued, returns
      expect(applyMock).toHaveBeenCalledTimes(1) // STILL one — no overlap

      release()
      await vi.advanceTimersByTimeAsync(100) // settle + follow-up
      expect(applyMock).toHaveBeenCalledTimes(2) // exactly one follow-up
      // The follow-up carries the LATEST tree (snapshot honesty, review P1).
      const followUp = applyMock.mock.calls[1]![3] as {
        body: { id: string; data: Record<string, unknown> }[]
      }
      expect(followUp.body.find((b) => b.id === 'prose0000003')!.data.body).toBe(
        '<p>mid-flight</p>',
      )
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('edit sessions suppress auto-apply; edit-end re-arms it', async () => {
    const wrapper = await mountAuto()
    vi.useFakeTimers()
    try {
      bridge.callbacks.editStart?.('prose0000003')
      bridge.callbacks.textChanged?.('prose0000003', 'body', { html: '<p>typing</p>' })
      await vi.advanceTimersByTimeAsync(2000)
      expect(applyMock).not.toHaveBeenCalled() // suppressed while editing

      bridge.callbacks.editEnd?.('prose0000003')
      await vi.advanceTimersByTimeAsync(900) // edit-end re-armed the debounce
      expect(applyMock).toHaveBeenCalledTimes(1)
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('final failure suspends (one banner, no further autos); manual success re-arms', async () => {
    const wrapper = await mountAuto()
    applyMock.mockRejectedValueOnce(new ApiError('validation failed', 422, {}, { success: false }))
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledTimes(1)
      expect(notify.error).toHaveBeenCalledTimes(1) // one banner

      bridge.callbacks.move?.('blockaaa0001', -1) // suspended: nothing schedules
      await vi.advanceTimersByTimeAsync(2000)
      expect(applyMock).toHaveBeenCalledTimes(1)
    } finally {
      vi.useRealTimers()
    }

    // Manual Apply succeeds -> auto re-arms.
    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(applyMock).toHaveBeenCalledTimes(2)
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledTimes(3)
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('a dead-token retry that SUCCEEDS does not suspend', async () => {
    const wrapper = await mountAuto()
    mintMock.mockResolvedValue({ token: 'tok2', themeUrl: 'https://site.test/_preview/tok2' })
    applyMock
      .mockRejectedValueOnce(new ApiError('expired', 410, {}, { success: false }))
      .mockResolvedValue(undefined)
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledTimes(2) // attempt + retry (TTL churn)

      bridge.callbacks.move?.('blockaaa0001', -1) // NOT suspended
      await vi.advanceTimersByTimeAsync(900)
      expect(applyMock).toHaveBeenCalledTimes(3)
    } finally {
      vi.useRealTimers()
    }
    wrapper.unmount()
  })

  it('the toggle disables auto, persists, and suspension clears on click', async () => {
    const wrapper = await mountAuto()
    await wrapper.find('[data-test="canvas-auto-toggle"]').trigger('click')
    expect(localStorage.getItem('lemma.canvas.auto_apply')).toBe('0')
    vi.useFakeTimers()
    try {
      bridge.callbacks.move?.('blockaaa0001', 1)
      await vi.advanceTimersByTimeAsync(2000)
      expect(applyMock).not.toHaveBeenCalled()
    } finally {
      vi.useRealTimers()
    }
    await wrapper.find('[data-test="canvas-auto-toggle"]').trigger('click')
    expect(localStorage.getItem('lemma.canvas.auto_apply')).toBe('1')
    wrapper.unmount()
  })

  it('scroll is remembered and restored after reloads', async () => {
    const wrapper = await mountAuto()
    bridge.callbacks.scroll?.(560)
    // Any reload path re-fires @load -> onIframeLoad -> hello + restore.
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    await iframe.trigger('load')
    expect(bridge.instance.restoreScroll).toHaveBeenCalledWith(560)
    wrapper.unmount()
  })
})
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-page.spec.ts`
Expected: FAIL — no toggle, no scheduler, `restoreScroll` never called.

- [ ] **Step 3: Implement the page**

In `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`:

**(a)** State (next to `applying`/`lastApplied`):

```ts
// ── Auto-apply (auto-apply spec §1): a SCHEDULER over the one runApply core ──
const autoEnabled = ref(localStorage.getItem('lemma.canvas.auto_apply') !== '0')
const autoSuspended = ref(false) // session-local; never persisted
const editSessionActive = ref(false)
const applyQueued = ref(false) // the coalescing boolean — never a counter
let autoTimer: ReturnType<typeof setTimeout> | null = null

function cancelAutoTimer(): void {
  if (autoTimer) {
    clearTimeout(autoTimer)
    autoTimer = null
  }
}

function scheduleAuto(): void {
  cancelAutoTimer()
  autoTimer = setTimeout(() => {
    autoTimer = null
    if (!autoEnabled.value || autoSuspended.value || editSessionActive.value) return
    if (renderDisabled.value || mintFailed.value || previewToken.value === '') return
    if (!stageStale.value) return
    if (applying.value) {
      // No concurrent applies (spec pin): queue ONE follow-up and return.
      applyQueued.value = true
      return
    }
    void runApply(true)
  }, 800)
}

watch(
  fields,
  () => {
    if (autoEnabled.value && !autoSuspended.value) scheduleAuto()
  },
  { deep: true },
)

function toggleAuto(): void {
  if (autoSuspended.value) {
    // Click-while-suspended clears suspension and keeps auto enabled.
    autoSuspended.value = false
    if (stageStale.value) scheduleAuto()
    return
  }
  autoEnabled.value = !autoEnabled.value
  localStorage.setItem('lemma.canvas.auto_apply', autoEnabled.value ? '1' : '0')
  if (!autoEnabled.value) cancelAutoTimer()
  else if (stageStale.value) scheduleAuto()
}
```

**(b)** Replace `applyWorking` with the core + two callers (delete the old
function body; keep its name for the button):

```ts
/**
 * The ONE apply path (auto-apply spec §2): token retry, failure reset,
 * banners, and stash bookkeeping live HERE — auto vs manual only differ in
 * flush, suspension, and re-arm side effects.
 */
async function runApply(auto: boolean): Promise<void> {
  applyQueued.value = false
  applying.value = true
  let succeeded = false
  // Immutable payload snapshot (review P1): the request AND lastApplied must
  // describe the SAME tree. Snapshot through JSON — NOT structuredClone:
  // fields.value is a Vue reactive proxy, which structuredClone rejects
  // with DataCloneError.
  const appliedJson = JSON.stringify(fields.value)
  const payload = JSON.parse(appliedJson) as Record<string, unknown>
  try {
    try {
      await applyPreview(uuid.value, locale.value, previewToken.value, payload)
    } catch (e: unknown) {
      // Dead token: re-mint ONCE and retry — TTL churn, never a failure
      // (suspension counts only the FINAL outcome, spec pin). The retry sends
      // the SAME snapshot: one run applies one tree.
      if (e instanceof ApiError && (e.status === 410 || e.status === 403)) {
        await mintAndLoad()
        await applyPreview(uuid.value, locale.value, previewToken.value, payload)
      } else {
        throw e
      }
    }
    lastApplied.value = appliedJson
    reloadStage() // same-URL reload — the stash is behind the SAME token URL
    succeeded = true
    if (!auto) autoSuspended.value = false // manual success re-arms auto
  } catch (e: unknown) {
    // Final failure: discard mirror-only DOM; keep dirty fields (v2/loop C pins).
    reloadStage()
    if (auto) autoSuspended.value = true // one banner now, then quiet until re-armed
    if (e instanceof ApiError && apiErrorCode(e) === 'BLOCK_MIGRATION_IN_PROGRESS') {
      const blockType = String(apiErrorDetails(e)?.block_type ?? 'a block type')
      warning(
        `Block type “${blockType}” is being migrated`,
        'Apply is blocked until the migration completes — try again shortly.',
      )
    } else {
      notifyError(e, 'Couldn’t apply the preview')
    }
  } finally {
    applying.value = false
  }
  // Coalesced follow-up (spec §1): at most one, latest tree, success-path only.
  if (succeeded && applyQueued.value && stageStale.value && !editSessionActive.value) {
    void runApply(true)
  } else {
    applyQueued.value = false
  }
}

async function applyWorking(): Promise<void> {
  if (applying.value) return
  cancelAutoTimer()
  await bridge.editFlush() // commit any in-stage typing before reading fields
  await runApply(false)
}
```

**(c)** Session tracking + scroll wiring (next to the other bridge callbacks):

```ts
// Session suppression keys off ACTUAL session starts (a failed grant never
// posts edit-start, so it can never wedge suppression); edit-end re-arms.
bridge.onEditStart(() => {
  editSessionActive.value = true
  cancelAutoTimer()
})
bridge.onEditEnd(() => {
  editSessionActive.value = false
  if (autoEnabled.value && !autoSuspended.value && stageStale.value) scheduleAuto()
})

// Scroll preservation (spec §3): remember the stage's last position, restore
// after every reload's hello. Reset when the entry/locale changes.
let lastScrollY = 0
bridge.onScroll((y) => {
  lastScrollY = y
})
watch([uuid, locale], () => {
  lastScrollY = 0
})
```

Update `onIframeLoad`:

```ts
function onIframeLoad(): void {
  bridge.hello()
  if (lastScrollY > 0) bridge.restoreScroll(lastScrollY)
}
```

Update the unmount hook (replace the existing `onBeforeUnmount` line):

```ts
onBeforeUnmount(() => {
  cancelAutoTimer()
  bridge.dispose()
})
```

**(d)** Template — the Auto toggle directly before the Apply chip:

```html
          <UButton
            variant="outline"
            :color="autoSuspended ? 'warning' : 'neutral'"
            size="xs"
            icon="i-lucide-zap"
            :aria-label="autoSuspended ? 'Auto-apply paused after an error — click to resume' : 'Toggle auto-apply'"
            data-test="canvas-auto-toggle"
            :class="{ 'bg-elevated': autoEnabled && !autoSuspended }"
            @click="toggleAuto()"
          >
            Auto
          </UButton>
```

- [ ] **Step 4: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-page.spec.ts && pnpm type-check`
Expected: PASS (all prior + 7 new), type-check clean. If a pre-existing
real-timer test flakes from a stray scheduled auto (tree change → 800ms timer),
the unmount-cancel in (c) is the guard — verify the failing test unmounts.

---

### Task 3: Docs, full gates, STAGE (stop for commit authorization)

**Files:**
- Modify: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: README**

Append to the canvas paragraphs in `packages/lemma-render/README.md`:

```markdown
Applies are automatic by default: the admin re-applies the working tree on a
short debounce after edits (suppressed while typing in-place) and restores
the stage's scroll position across reloads. An Auto toggle beside Apply
turns this off per browser; failures pause it until a manual Apply succeeds.
```

- [ ] **Step 2: CHANGELOG**

Append to `[Unreleased]` after the canvas v4 bullet:

```markdown
- Auto-apply (canvas v5): the Design view re-applies the working tree
  automatically on an 800ms debounce — suppressed during in-place edit
  sessions, coalesced to one in-flight request, suspended (with one banner)
  on failure until a manual Apply succeeds, and toggleable per browser. The
  stage's scroll position now survives every reload, including manual
  Apply's.
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
  "admin/src/pages/content/[type]/[uuid]/design/[locale].vue" \
  admin/src/__tests__ \
  CHANGELOG.md \
  docs/superpowers
git status --short
```

Then STOP and report, awaiting explicit commit authorization. Prepared message:

```
feat(admin): auto-apply — the canvas stage keeps itself current

- Scheduler over the ONE runApply core (never a second write path):
  800ms trailing debounce, no concurrent applies (in-flight changes
  coalesce to one follow-up with the latest tree), suppressed during
  in-place edit sessions (edit-start/edit-end lifecycle from the
  bridge), suspended with one banner on final failure until a manual
  Apply succeeds; dead-token re-mint retries never suspend
- Auto toggle beside Apply, on by default, persisted per browser
- Scroll preservation: throttled bridge reports + instant restore
  after every stage reload (fixes manual Apply's scroll snap too)
```

Recorded manual/browser acceptance (report as outstanding): typing rhythm vs
the 800ms debounce on a real theme, scroll restore below the fold, suspension
UX under a forced 409, toggle persistence — plus the earlier canvas items.
