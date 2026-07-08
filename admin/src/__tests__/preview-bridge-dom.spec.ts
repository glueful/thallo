import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// Direct tests for the STATIC bridge asset (stage-toolbar spec §6): the file is
// evaluated ONCE in this jsdom document and driven with synthetic message
// events. One eval per file — the IIFE registers window/document listeners we
// cannot remove — so the hello/session is established in beforeAll and every
// test builds its own uniquely-id'd fixtures.
//
// Vitest runs from the admin/ root and import.meta.url is not a file:// URL in
// the jsdom environment (same convention as schemaBoundary.spec.ts) — resolve
// from cwd.
const source = readFileSync(
  resolve(process.cwd(), '../packages/thallo-render/assets/preview/preview-bridge.js'),
  'utf8',
)

const NONCE = 'test-nonce-1'
const posted = vi.fn()

function sendToBridge(data: Record<string, unknown>, origin = 'https://admin.test'): void {
  window.dispatchEvent(new MessageEvent('message', { data: { nonce: NONCE, ...data }, origin }))
}

function wrapper(id: string, inner = `<section><a href="/x">link ${id}</a></section>`): HTMLElement {
  const el = document.createElement('div')
  el.className = 'thallo-preview-block'
  el.setAttribute('data-thallo-block', id)
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
      data: { type: 'thallo:canvas-hello', nonce: NONCE },
      origin: 'https://admin.test',
    }),
  )
})

beforeEach(() => {
  posted.mockClear()
  document.body.innerHTML = ''
})

function lastPost(type: string): Record<string, unknown> | undefined {
  // No Array.findLast — the tsconfig lib predates es2023.
  return posted.mock.calls
    .map((c) => c[0] as Record<string, unknown>)
    .reverse()
    .find((m) => m.type === type)
}

describe('preview bridge (direct eval)', () => {
  it('click selects, injects the toolbar into the anchor, and posts block-select', () => {
    const w = wrapper('blk-sel-0001')
    document.body.appendChild(w)
    w.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'blk-sel-0001', nonce: NONCE })
    expect(w.classList.contains('thallo-canvas-selected')).toBe(true)
    const host = w.firstElementChild!
    expect(host.classList.contains('thallo-canvas-anchor')).toBe(true)
    const toolbar = host.querySelector(':scope > .thallo-canvas-toolbar')
    expect(toolbar).not.toBeNull()
    // All five actions present.
    const actions = [...toolbar!.querySelectorAll('[data-action]')].map((b) =>
      b.getAttribute('data-action'),
    )
    expect(actions).toEqual(['drag', 'move-up', 'move-down', 'duplicate', 'delete', 'add-after'])
  })

  it('void-element blocks (hr dividers) get the toolbar via a positioned shim', () => {
    // Children of void elements (hr, img, …) never RENDER — inserting the
    // toolbar inside them makes it invisible. The bridge attaches a
    // bridge-owned shim sibling instead.
    const w = wrapper('void-a-00001', '<hr class="thallo-block-divider">')
    document.body.appendChild(w)
    w.querySelector('hr')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'void-a-00001' })

    const shim = w.querySelector('.thallo-canvas-shim')!
    expect(shim).not.toBeNull()
    expect(shim.previousElementSibling!.tagName).toBe('HR')
    expect(shim.classList.contains('thallo-canvas-anchor')).toBe(true)
    expect(shim.querySelector('.thallo-canvas-toolbar')).not.toBeNull()
    // The hr itself carries NO children and no anchor class.
    expect(w.querySelector('hr')!.childNodes).toHaveLength(0)

    // Deselecting (selecting elsewhere) removes the shim entirely.
    const other = wrapper('void-b-00001')
    document.body.appendChild(other)
    other.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(w.querySelector('.thallo-canvas-shim')).toBeNull()
  })

  it('a leading <style> child is skipped: the toolbar anchors to the visual content, not the <style>', () => {
    // Style-block spec P1: a block-owned <style> must never become the canvas host.
    // Render it FIRST here (worst case) to prove the bridge — not template order —
    // guarantees the invariant.
    const w = wrapper(
      'skin-a-00001',
      '<style>.thallo-skin-rose-none{--accent:#e11d48;}</style>' +
        '<div class="thallo-block-style__inner"><a href="/x">x</a></div>',
    )
    document.body.appendChild(w)
    w.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'skin-a-00001' })
    const style = w.querySelector('style')!
    const inner = w.querySelector('.thallo-block-style__inner')!
    expect(style.classList.contains('thallo-canvas-anchor')).toBe(false)
    expect(inner.classList.contains('thallo-canvas-anchor')).toBe(true)
    expect(inner.querySelector(':scope > .thallo-canvas-toolbar')).not.toBeNull()
  })

  it('toolbar clicks post intents and never re-select', () => {
    const w = wrapper('blk-int-0001')
    document.body.appendChild(w)
    w.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    posted.mockClear()

    const toolbar = w.querySelector('.thallo-canvas-toolbar')!
    const click = (action: string) =>
      toolbar
        .querySelector(`[data-action="${action}"]`)!
        .dispatchEvent(new MouseEvent('click', { bubbles: true }))

    click('move-up')
    expect(lastPost('thallo:block-move')).toMatchObject({ id: 'blk-int-0001', delta: -1 })
    click('move-down')
    expect(lastPost('thallo:block-move')).toMatchObject({ id: 'blk-int-0001', delta: 1 })
    click('duplicate')
    expect(lastPost('thallo:block-duplicate')).toMatchObject({ id: 'blk-int-0001' })
    click('delete')
    const del = lastPost('thallo:block-delete-request')!
    expect(del).toMatchObject({ id: 'blk-int-0001' })
    // The delete button's rect rides along so the parent anchors its confirm.
    expect(del.rect).toMatchObject({ x: expect.any(Number), y: expect.any(Number) })
    click('add-after')
    const addAfter = lastPost('thallo:block-add-after')!
    expect(addAfter).toMatchObject({ id: 'blk-int-0001' })
    // The + button's rect rides along so the parent can anchor its picker.
    expect(addAfter.rect).toMatchObject({ x: expect.any(Number), y: expect.any(Number) })
    expect(lastPost('thallo:block-select')).toBeUndefined()
  })

  it('mirror-move places the wrapper next to the named sibling (beforeId and afterId)', () => {
    const list = document.createElement('main')
    const a = wrapper('mv-a-0000001')
    const b = wrapper('mv-b-0000002')
    const c = wrapper('mv-c-0000003')
    list.append(a, b, c)
    document.body.appendChild(list)

    sendToBridge({ type: 'thallo:mirror-move', id: 'mv-c-0000003', beforeId: 'mv-a-0000001' })
    expect([...list.children].map((el) => el.getAttribute('data-thallo-block'))).toEqual([
      'mv-c-0000003',
      'mv-a-0000001',
      'mv-b-0000002',
    ])
    sendToBridge({ type: 'thallo:mirror-move', id: 'mv-c-0000003', afterId: 'mv-b-0000002' })
    expect([...list.children].map((el) => el.getAttribute('data-thallo-block'))).toEqual([
      'mv-a-0000001',
      'mv-b-0000002',
      'mv-c-0000003',
    ])
    // Missing wrapper -> ignored, no throw.
    sendToBridge({ type: 'thallo:mirror-move', id: 'nope', beforeId: 'mv-a-0000001' })
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
    sendToBridge({ type: 'thallo:mirror-move', id: 'gd-a-0000001', beforeId: 'gd-b-0000002' })
    expect(a.parentNode).toBe(listA)
    sendToBridge({ type: 'thallo:mirror-move', id: 'gd-a-0000001', afterId: 'gd-b-0000002' })
    expect(a.parentNode).toBe(listA)
    expect([...listB.children].map((el) => el.getAttribute('data-thallo-block'))).toEqual([
      'gd-b-0000002',
    ])
  })

  it('mirror-remove drops the wrapper and detaches the toolbar when it was selected', () => {
    const w = wrapper('rm-a-0000001')
    document.body.appendChild(w)
    w.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(document.querySelector('.thallo-canvas-toolbar')).not.toBeNull()

    sendToBridge({ type: 'thallo:mirror-remove', id: 'rm-a-0000001' })
    expect(document.querySelector('[data-thallo-block="rm-a-0000001"]')).toBeNull()
    expect(document.querySelector('.thallo-canvas-toolbar')).toBeNull()
  })

  it('mirror-duplicate clones, STRIPS canvas UI state, and rewrites ids via idMap', () => {
    const w = wrapper(
      'dup-a-000001',
      '<section><div class="thallo-preview-block" data-thallo-block="dup-child-01"><p>inner</p></div></section>',
    )
    document.body.appendChild(w)
    // Select the source so its clone WOULD carry toolbar/anchor/ring state.
    w.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    sendToBridge({
      type: 'thallo:mirror-duplicate',
      sourceId: 'dup-a-000001',
      idMap: { 'dup-a-000001': 'dup-b-000002', 'dup-child-01': 'dup-child-02' },
    })
    const copy = document.querySelector('[data-thallo-block="dup-b-000002"]')
    expect(copy).not.toBeNull()
    expect(copy!.previousElementSibling).toBe(w)
    // Subtree id rewritten via the map.
    expect(copy!.querySelector('[data-thallo-block="dup-child-02"]')).not.toBeNull()
    expect(copy!.querySelector('[data-thallo-block="dup-child-01"]')).toBeNull()
    // Canvas UI state stripped from the clone (review P2).
    expect(copy!.querySelector('.thallo-canvas-toolbar')).toBeNull()
    expect(copy!.classList.contains('thallo-canvas-selected')).toBe(false)
    expect(copy!.querySelector('.thallo-canvas-anchor')).toBeNull()
    // The SOURCE keeps its selected state untouched.
    expect(w.classList.contains('thallo-canvas-selected')).toBe(true)
  })

  it('drops messages with a wrong nonce or origin', () => {
    const list = document.createElement('main')
    const a = wrapper('sec-a-000001')
    const b = wrapper('sec-b-000002')
    list.append(a, b)
    document.body.appendChild(list)
    window.dispatchEvent(
      new MessageEvent('message', {
        data: {
          type: 'thallo:mirror-move',
          id: 'sec-b-000002',
          beforeId: 'sec-a-000001',
          nonce: 'wrong',
        },
        origin: 'https://admin.test',
      }),
    )
    window.dispatchEvent(
      new MessageEvent('message', {
        data: {
          type: 'thallo:mirror-move',
          id: 'sec-b-000002',
          beforeId: 'sec-a-000001',
          nonce: NONCE,
        },
        origin: 'https://evil.test',
      }),
    )
    expect([...list.children].map((el) => el.getAttribute('data-thallo-block'))).toEqual([
      'sec-a-000001',
      'sec-b-000002',
    ])
  })
})

function proseWrapper(id: string, field = 'body', html = '<p>hello</p>'): HTMLElement {
  return wrapper(
    id,
    `<section><div class="thallo-edit-region" data-thallo-edit-block="${id}" ` +
      `data-thallo-edit-field="${field}">${html}</div></section>`,
  )
}

describe('edit-in-place session', () => {
  it('double-click posts edit-request; grant enables contenteditable on the ONE region', () => {
    const w = proseWrapper('eip-a-000001')
    document.body.appendChild(w)
    w.querySelector('p')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('thallo:edit-request')).toMatchObject({ id: 'eip-a-000001', field: 'body' })

    sendToBridge({ type: 'thallo:edit-grant', id: 'eip-a-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.thallo-edit-region')!
    expect(region.getAttribute('contenteditable')).toBe('true')
    expect(region.classList.contains('thallo-canvas-editing')).toBe(true)
    // Toolbar detached for the duration (block may have been selected before).
    expect(w.querySelector('.thallo-canvas-toolbar')).toBeNull()
    // Escape commits and ends.
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('thallo:text-changed')).toMatchObject({
      id: 'eip-a-000001',
      field: 'body',
      html: '<p>hello</p>',
    })
    expect(lastPost('thallo:edit-end')).toMatchObject({ id: 'eip-a-000001' })
    expect(region.getAttribute('contenteditable')).toBeNull()
  })

  it('window blur (focus leaving the stage) commits and ENDS the session', () => {
    // The wedge (auto-apply bug hunt): clicking from the stage into the admin
    // inspector moves focus CROSS-FRAME, which doesn't reliably fire the
    // region's own blur — the session would outlive stage focus and pin the
    // parent's editSessionActive forever, silently suppressing every
    // inspector-driven auto-apply.
    const w = proseWrapper('wb-a-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'wb-a-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.thallo-edit-region')!
    expect(region.getAttribute('contenteditable')).toBe('true')
    posted.mockClear()

    window.dispatchEvent(new Event('blur'))
    expect(lastPost('thallo:text-changed')).toMatchObject({ id: 'wb-a-000001' })
    expect(lastPost('thallo:edit-end')).toMatchObject({ id: 'wb-a-000001' })
    expect(region.getAttribute('contenteditable')).toBeNull()

    // And with no session, a later window blur is inert (listener removed).
    posted.mockClear()
    window.dispatchEvent(new Event('blur'))
    expect(posted).not.toHaveBeenCalled()
  })

  it('grant field mismatch or multiple regions -> no editing (fail-safe)', () => {
    const w = proseWrapper('eip-b-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'eip-b-000001', field: 'other', kind: 'rich' })
    expect(w.querySelector('[contenteditable]')).toBeNull()

    const two = wrapper(
      'eip-c-000001',
      '<section>' +
        '<div class="thallo-edit-region" data-thallo-edit-block="eip-c-000001" data-thallo-edit-field="body">a</div>' +
        '<div class="thallo-edit-region" data-thallo-edit-block="eip-c-000001" data-thallo-edit-field="body">b</div>' +
        '</section>',
    )
    document.body.appendChild(two)
    sendToBridge({ type: 'thallo:edit-grant', id: 'eip-c-000001', field: 'body', kind: 'rich' })
    expect(two.querySelector('[contenteditable]')).toBeNull()
  })

  it('typing commits on the debounce; clicks INSIDE the region are not swallowed', () => {
    vi.useFakeTimers()
    try {
      const w = proseWrapper('eip-d-000001')
      document.body.appendChild(w)
      sendToBridge({ type: 'thallo:edit-grant', id: 'eip-d-000001', field: 'body', kind: 'rich' })
      const region = w.querySelector('.thallo-edit-region')!
      region.innerHTML = '<p>typed</p>'
      region.dispatchEvent(new Event('input', { bubbles: true }))
      vi.advanceTimersByTime(450)
      expect(lastPost('thallo:text-changed')).toMatchObject({ html: '<p>typed</p>' })

      // Caret-placement click inside the ACTIVE region passes through.
      const inside = new MouseEvent('click', { bubbles: true, cancelable: true })
      region.querySelector('p')!.dispatchEvent(inside)
      expect(inside.defaultPrevented).toBe(false)

      // A click OUTSIDE commits and exits, then behaves as v2 (select).
      posted.mockClear()
      const other = wrapper('eip-e-000001')
      document.body.appendChild(other)
      other
        .querySelector('a')!
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(lastPost('thallo:edit-end')).toMatchObject({ id: 'eip-d-000001' })
      expect(lastPost('thallo:block-select')).toMatchObject({ id: 'eip-e-000001' })
      expect(region.getAttribute('contenteditable')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('edit-flush commits an active session and ALWAYS acks with edit-flushed', () => {
    // No active session: ack only.
    posted.mockClear()
    sendToBridge({ type: 'thallo:edit-flush' })
    expect(lastPost('thallo:edit-flushed')).toBeDefined()
    expect(lastPost('thallo:text-changed')).toBeUndefined()

    // Active session: final text-changed + edit-end BEFORE the ack.
    const w = proseWrapper('eip-f-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'eip-f-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.thallo-edit-region')!
    region.innerHTML = '<p>flush me</p>'
    posted.mockClear()
    sendToBridge({ type: 'thallo:edit-flush' })
    const types = posted.mock.calls.map((c) => (c[0] as { type: string }).type)
    expect(types).toEqual(['thallo:text-changed', 'thallo:edit-end', 'thallo:edit-flushed'])
    expect(region.getAttribute('contenteditable')).toBeNull()
  })

  it('mirror-duplicate clones never carry contenteditable or the editing class', () => {
    const w = proseWrapper('eip-g-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'eip-g-000001', field: 'body', kind: 'rich' })
    sendToBridge({
      type: 'thallo:mirror-duplicate',
      sourceId: 'eip-g-000001',
      idMap: { 'eip-g-000001': 'eip-h-000002' },
    })
    const copy = document.querySelector('[data-thallo-block="eip-h-000002"]')!
    expect(copy.querySelector('[contenteditable]')).toBeNull()
    expect(copy.querySelector('.thallo-canvas-editing')).toBeNull()
    sendToBridge({ type: 'thallo:edit-flush' }) // clean up the session for later tests
  })

  it('a DUPLICATED prose block is immediately editable under its NEW id (review P1)', () => {
    const w = proseWrapper('eip-i-000001')
    document.body.appendChild(w)
    sendToBridge({
      type: 'thallo:mirror-duplicate',
      sourceId: 'eip-i-000001',
      idMap: { 'eip-i-000001': 'eip-j-000002' },
    })
    const copy = document.querySelector('[data-thallo-block="eip-j-000002"]')!
    // The edit region's id was rewritten alongside the wrapper's — without
    // this, edit-grant for the new id can never find its region until the
    // next Apply re-renders truth.
    const region = copy.querySelector('.thallo-edit-region')!
    expect(region.getAttribute('data-thallo-edit-block')).toBe('eip-j-000002')
    sendToBridge({ type: 'thallo:edit-grant', id: 'eip-j-000002', field: 'body', kind: 'rich' })
    expect(region.getAttribute('contenteditable')).toBe('true')
    sendToBridge({ type: 'thallo:edit-flush' }) // clean up the session for later tests

    // v4: the field-addressed request from the CLONE emits the NEW id.
    posted.mockClear()
    region.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('thallo:edit-request')).toMatchObject({ id: 'eip-j-000002', field: 'body' })
  })

  function stringWrapper(id: string, field = 'heading', value = 'Hello'): HTMLElement {
    return wrapper(
      id,
      `<section><h1><span class="thallo-edit-region" data-thallo-edit-block="${id}" ` +
        `data-thallo-edit-field="${field}">${value}</span></h1></section>`,
    )
  }

  it('request field comes from the region under the double-click; two fields coexist', () => {
    const w = wrapper(
      'es-a-0000001',
      '<section>' +
        '<h1><span class="thallo-edit-region" data-thallo-edit-block="es-a-0000001" data-thallo-edit-field="heading">H</span></h1>' +
        '<p><span class="thallo-edit-region" data-thallo-edit-block="es-a-0000001" data-thallo-edit-field="body_text">B</span></p>' +
        '</section>',
    )
    document.body.appendChild(w)
    w.querySelector('p .thallo-edit-region')!.dispatchEvent(
      new MouseEvent('dblclick', { bubbles: true }),
    )
    expect(lastPost('thallo:edit-request')).toMatchObject({ id: 'es-a-0000001', field: 'body_text' })

    // Grant for ONE of two same-block regions edits exactly that region.
    sendToBridge({ type: 'thallo:edit-grant', id: 'es-a-0000001', field: 'body_text', kind: 'text' })
    const region = w.querySelector('[data-thallo-edit-field="body_text"]')!
    expect(region.getAttribute('contenteditable')).not.toBeNull()
    expect(
      w.querySelector('[data-thallo-edit-field="heading"]')!.getAttribute('contenteditable'),
    ).toBeNull()
    sendToBridge({ type: 'thallo:edit-flush' })
  })

  it('wrapper-level double-click falls back to the SINGLE region; none with two', () => {
    const single = stringWrapper('es-b-0000001')
    document.body.appendChild(single)
    posted.mockClear()
    single.querySelector('section')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('thallo:edit-request')).toMatchObject({ id: 'es-b-0000001', field: 'heading' })

    const two = wrapper(
      'es-c-0000001',
      '<section>' +
        '<span class="thallo-edit-region" data-thallo-edit-block="es-c-0000001" data-thallo-edit-field="a">1</span>' +
        '<span class="thallo-edit-region" data-thallo-edit-block="es-c-0000001" data-thallo-edit-field="b">2</span>' +
        '</section>',
    )
    document.body.appendChild(two)
    posted.mockClear()
    two.querySelector('section')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('thallo:edit-request')).toBeUndefined()
  })

  it('string kind: Enter commits-and-exits with the TEXT payload (markup never persists)', () => {
    const w = stringWrapper('es-d-0000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'es-d-0000001', field: 'heading', kind: 'string' })
    const region = w.querySelector('.thallo-edit-region')!
    expect(['plaintext-only', 'true']).toContain(region.getAttribute('contenteditable'))

    region.innerHTML = 'New <b>title</b>'
    const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    region.dispatchEvent(enter)
    expect(enter.defaultPrevented).toBe(true) // single-line convention
    const commit = lastPost('thallo:text-changed')!
    expect(commit).toMatchObject({ id: 'es-d-0000001', field: 'heading', text: 'New title' })
    expect(commit.html).toBeUndefined()
    expect(lastPost('thallo:edit-end')).toMatchObject({ id: 'es-d-0000001' })
    expect(region.getAttribute('contenteditable')).toBeNull()
  })

  it('a session that actually starts posts edit-start; a failed grant posts nothing', () => {
    const w = proseWrapper('sc-a-0000001')
    document.body.appendChild(w)
    posted.mockClear()
    sendToBridge({ type: 'thallo:edit-grant', id: 'sc-a-0000001', field: 'body', kind: 'rich' })
    expect(lastPost('thallo:edit-start')).toMatchObject({ id: 'sc-a-0000001' })
    sendToBridge({ type: 'thallo:edit-flush' })

    // Grant for a block with NO matching region: no session, no edit-start.
    posted.mockClear()
    sendToBridge({ type: 'thallo:edit-grant', id: 'sc-a-0000001', field: 'nope', kind: 'string' })
    expect(lastPost('thallo:edit-start')).toBeUndefined()
  })

  it('text kind: Enter does NOT exit; commit carries the text payload', () => {
    const w = stringWrapper('es-e-0000001', 'body_text', 'line')
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'es-e-0000001', field: 'body_text', kind: 'text' })
    const region = w.querySelector('.thallo-edit-region')!
    const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    region.dispatchEvent(enter)
    expect(enter.defaultPrevented).toBe(false)
    expect(region.getAttribute('contenteditable')).not.toBeNull() // still editing
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('thallo:text-changed')).toMatchObject({
      id: 'es-e-0000001',
      field: 'body_text',
      text: 'line',
    })
  })
})

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
      expect(lastPost('thallo:scroll')).toBeUndefined()
      vi.advanceTimersByTime(300)
      // ONE post, carrying the latest y.
      const scrolls = posted.mock.calls
        .map((c) => c[0] as { type: string; y?: number })
        .filter((m) => m.type === 'thallo:scroll')
      expect(scrolls).toHaveLength(1)
      expect(scrolls[0]!.y).toBe(340)
    } finally {
      vi.useRealTimers()
    }
  })

  it('restore-scroll jumps instantly via window.scrollTo', () => {
    const scrollTo = vi.fn()
    window.scrollTo = scrollTo as unknown as typeof window.scrollTo
    sendToBridge({ type: 'thallo:restore-scroll', y: 480 })
    expect(scrollTo).toHaveBeenCalledWith(0, 480)
    // Non-number y is dropped.
    scrollTo.mockClear()
    sendToBridge({ type: 'thallo:restore-scroll', y: 'x' })
    expect(scrollTo).not.toHaveBeenCalled()
  })
})

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
      .filter((el) => el.hasAttribute('data-thallo-block'))
      .map((el) => el.getAttribute('data-thallo-block'))
  }

  it('live-reorders on pointermove WITHOUT posting; pointerup posts ONE block-move-to', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()

    pointerMove(160) // past b's midpoint (150) -> a moves after b
    expect(order(list)).toEqual(['fd-b-0000002', 'fd-a-0000001', 'fd-c-0000003'])
    expect(lastPost('thallo:block-move-to')).toBeUndefined() // visual only

    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    const moves = posted.mock.calls
      .map((c) => c[0] as { type: string })
      .filter((m) => m.type === 'thallo:block-move-to')
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
    expect(lastPost('thallo:block-move-to')).toMatchObject({
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
    expect(lastPost('thallo:block-move-to')).toBeUndefined()
  })

  it('Escape rolls back the order, posts nothing, and does NOT swallow the next click', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()
    pointerMove(160)
    expect(order(list)[0]).toBe('fd-b-0000002')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(order(list)).toEqual(['fd-a-0000001', 'fd-b-0000002', 'fd-c-0000003'])
    expect(a.classList.contains('thallo-canvas-dragging')).toBe(false)
    expect(lastPost('thallo:block-move-to')).toBeUndefined()

    // Rollback must not arm the click suppressor: the next click still selects.
    const other = wrapper('fd-d-0000004')
    document.body.appendChild(other)
    other.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'fd-d-0000004' })
  })

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
    expect(lastPost('thallo:block-move')).toBeUndefined()
    expect(lastPost('thallo:block-delete-request')).toBeUndefined()
    expect(lastPost('thallo:block-duplicate')).toBeUndefined()

    // Escape belongs to the DRAG while one is active: order rolls back, the
    // block STAYS selected, and no block-deselect posts.
    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
    )
    expect(order(list)).toEqual(['fd-a-0000001', 'fd-b-0000002', 'fd-c-0000003'])
    expect(lastPost('thallo:block-deselect')).toBeUndefined()
    expect(a.classList.contains('thallo-canvas-selected')).toBe(true)
  })

  it('swaps are direction-gated: an against-direction slot never triggers (no oscillation)', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()

    pointerMove(160) // moving DOWN past b's midpoint -> swap
    expect(order(list)).toEqual(['fd-b-0000002', 'fd-a-0000001', 'fd-c-0000003'])
    // Same Y again: no direction -> no re-evaluation, order stable.
    pointerMove(160)
    expect(order(list)).toEqual(['fd-b-0000002', 'fd-a-0000001', 'fd-c-0000003'])
    // Moving DOWN a hair more must never take an UP slot even if geometry
    // momentarily suggests one (the oscillation case with unequal heights).
    pointerMove(161)
    expect(order(list)).toEqual(['fd-b-0000002', 'fd-a-0000001', 'fd-c-0000003'])
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  })

  it('reorders FLIP-animate displaced blocks when element.animate exists', () => {
    const { list, a, b, c } = dragList()
    const animations: unknown[] = []
    // POSITION-DEPENDENT rects (unlike stubRects' static bands): FLIP measures
    // before/after the DOM move, so the rect must derive from the element's
    // CURRENT index or the delta is always zero.
    ;[a, b, c].forEach((w) => {
      const host = w.firstElementChild as HTMLElement & { animate?: unknown }
      Object.defineProperty(host, 'getBoundingClientRect', {
        configurable: true,
        value: () => {
          const idx = [...list.children].indexOf(w)
          return {
            top: idx * 100,
            bottom: (idx + 1) * 100,
            height: 100,
            left: 0,
            right: 500,
            width: 500,
            x: 0,
            y: idx * 100,
            toJSON: () => ({}),
          }
        },
      })
      Object.defineProperty(host, 'animate', {
        configurable: true,
        value: (...args: unknown[]) => {
          animations.push(args)
          return { finished: Promise.resolve() }
        },
      })
    })
    gripDown(a)
    pointerMove(160) // a and b both displace -> both animate
    expect(order(list)[0]).toBe('fd-b-0000002')
    expect(animations.length).toBeGreaterThan(0)
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  })

  it('the click after a completed drag is swallowed once', () => {
    const { a } = dragList()
    gripDown(a)
    pointerMove(160)
    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    posted.mockClear()

    // The post-drag click: swallowed (no select), exactly once.
    a.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(lastPost('thallo:block-select')).toBeUndefined()
    a.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'fd-a-0000001' })
  })
})

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
    expect(lastPost('thallo:block-move')).toMatchObject({ id: 'kb-mv-000001', delta: -1 })
    expect(up.defaultPrevented).toBe(true)

    pressKey({ key: 'ArrowDown', altKey: true })
    expect(lastPost('thallo:block-move')).toMatchObject({ id: 'kb-mv-000001', delta: 1 })

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
    const req = lastPost('thallo:block-delete-request')!
    expect(req).toMatchObject({ id: 'kb-del-00001' })
    expect(req.rect).toBeUndefined()

    posted.mockClear()
    pressKey({ key: 'Delete' })
    expect(lastPost('thallo:block-delete-request')).toMatchObject({ id: 'kb-del-00001' })
  })

  it('Cmd/Ctrl+D posts block-duplicate and beats the browser bookmark', () => {
    const w = wrapper('kb-dup-00001')
    document.body.appendChild(w)
    selectByClick(w)
    posted.mockClear()

    const meta = pressKey({ key: 'd', metaKey: true })
    expect(lastPost('thallo:block-duplicate')).toMatchObject({ id: 'kb-dup-00001' })
    expect(meta.defaultPrevented).toBe(true)

    posted.mockClear()
    pressKey({ key: 'D', ctrlKey: true })
    expect(lastPost('thallo:block-duplicate')).toMatchObject({ id: 'kb-dup-00001' })

    posted.mockClear()
    pressKey({ key: 'd' }) // unmodified d: plain typing, no intent
    expect(posted).not.toHaveBeenCalled()
  })

  it('Enter posts edit-request ONLY for a single-owned-region block (spec pin)', () => {
    const one = proseWrapper('kb-ent-00001')
    document.body.appendChild(one)
    selectByClick(one)
    posted.mockClear()
    pressKey({ key: 'Enter' })
    expect(lastPost('thallo:edit-request')).toMatchObject({ id: 'kb-ent-00001', field: 'body' })

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
        '<span class="thallo-edit-region" data-thallo-edit-block="kb-ent-00003" data-thallo-edit-field="heading">H</span>' +
        '<span class="thallo-edit-region" data-thallo-edit-block="kb-ent-00003" data-thallo-edit-field="label">L</span>' +
        '</section>',
    )
    document.body.appendChild(two)
    selectByClick(two)
    posted.mockClear()
    pressKey({ key: 'Enter' })
    expect(lastPost('thallo:edit-request')).toBeUndefined()

    // Container block (nested blocks()): the CHILD's region does not count as
    // the parent's — Enter on the selected parent stays inert (review P1).
    const parent = wrapper(
      'kb-ent-00004',
      '<section><div class="thallo-preview-block" data-thallo-block="kb-ent-child1">' +
        '<section><div class="thallo-edit-region" data-thallo-edit-block="kb-ent-child1" ' +
        'data-thallo-edit-field="body"><p>child</p></div></section></div></section>',
    )
    document.body.appendChild(parent)
    selectByClick(parent) // querySelector('section') hits the OUTER section -> parent selected
    posted.mockClear()
    pressKey({ key: 'Enter' })
    expect(lastPost('thallo:edit-request')).toBeUndefined()
  })

  it('wrapper-level double-click on a container no longer adopts a CHILD region', () => {
    // The shared owned-region helper aligns the POINTER fallback with Enter
    // (review P1): before it, a container double-click posted edit-request
    // for the child block while the parent was the click target.
    const parent = wrapper(
      'kb-dbl-00001',
      '<section><div class="thallo-preview-block" data-thallo-block="kb-dbl-child1">' +
        '<section><div class="thallo-edit-region" data-thallo-edit-block="kb-dbl-child1" ' +
        'data-thallo-edit-field="body"><p>child</p></div></section></div></section>',
    )
    document.body.appendChild(parent)
    posted.mockClear()
    parent.querySelector('section')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('thallo:edit-request')).toBeUndefined()

    // Double-click INSIDE the child's region still addresses the child directly.
    parent.querySelector('p')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('thallo:edit-request')).toMatchObject({
      id: 'kb-dbl-child1',
      field: 'body',
    })
  })

  it('Escape clears the selection locally and posts block-deselect', () => {
    const w = wrapper('kb-esc-00001')
    document.body.appendChild(w)
    selectByClick(w)
    expect(w.querySelector('.thallo-canvas-toolbar')).not.toBeNull()
    posted.mockClear()

    pressKey({ key: 'Escape' })
    expect(lastPost('thallo:block-deselect')).toMatchObject({ id: 'kb-esc-00001' })
    expect(w.classList.contains('thallo-canvas-selected')).toBe(false)
    expect(w.querySelector('.thallo-canvas-toolbar')).toBeNull()

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
    const dupBtn = w.querySelector('.thallo-canvas-toolbar [data-action="duplicate"]')!
    const tev = pressKey({ key: 'Enter' }, dupBtn)
    expect(lastPost('thallo:edit-request')).toBeUndefined()
    expect(tev.defaultPrevented).toBe(false)
    pressKey({ key: 'Backspace' }, dupBtn)
    expect(lastPost('thallo:block-delete-request')).toBeUndefined()

    // Theme form control guard: Backspace in an input is typing, not delete.
    const formW = wrapper('kb-grd-00002', '<section><input type="text"></section>')
    document.body.appendChild(formW)
    selectByClick(formW)
    posted.mockClear()
    pressKey({ key: 'Backspace' }, formW.querySelector('input')!)
    expect(lastPost('thallo:block-delete-request')).toBeUndefined()

    // Edit-session guard: typing must never move/delete blocks. Re-select the
    // prose wrapper, grant an edit, then hammer the shortcuts.
    selectByClick(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'kb-grd-00001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.thallo-edit-region')!
    expect(region.getAttribute('contenteditable')).toBe('true')
    posted.mockClear()
    pressKey({ key: 'ArrowUp', altKey: true }, region)
    pressKey({ key: 'Backspace' }, region)
    pressKey({ key: 'd', metaKey: true }, region)
    expect(lastPost('thallo:block-move')).toBeUndefined()
    expect(lastPost('thallo:block-delete-request')).toBeUndefined()
    expect(lastPost('thallo:block-duplicate')).toBeUndefined()
    // Escape during editing keeps its commit-and-exit meaning (region handler).
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('thallo:edit-end')).toMatchObject({ id: 'kb-grd-00001' })
    expect(lastPost('thallo:block-deselect')).toBeUndefined()
  })
})

describe('rich-region normalization (format-bar spec §2)', () => {
  it('commit normalizes native-shortcut output: <b>/<i> become <strong>/<em>', () => {
    // The latent v3 bug (review pin): native Cmd+B produces <b>, which the
    // save/render sanitizer drops WITH CHILDREN — bolded text vanishes at the
    // next apply. The commit-time pass must fix this with NO bar interaction.
    const w = proseWrapper('nm-a-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'nm-a-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.thallo-edit-region')!
    expect(region.getAttribute('contenteditable')).toBe('true')

    region.innerHTML = '<p><b>Bold</b> and <i>Italic</i></p>'
    posted.mockClear()
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('thallo:text-changed')).toMatchObject({
      id: 'nm-a-000001',
      field: 'body',
      html: '<p><strong>Bold</strong> and <em>Italic</em></p>',
    })
  })

  it('commit unwraps styled spans and handles nesting; the LIVE region is untouched', () => {
    const w = proseWrapper('nm-b-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'nm-b-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.thallo-edit-region')!

    const dirty = '<p><span style="font-weight:700">kept text</span> <b>outer <i>inner</i></b></p>'
    region.innerHTML = dirty
    posted.mockClear()
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('thallo:text-changed')).toMatchObject({
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
        '<div class="thallo-edit-region" data-thallo-edit-block="nm-c-000001" ' +
        'data-thallo-edit-field="body"><p><b>mine</b></p></div></section>',
    )
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id: 'nm-c-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.thallo-edit-region')!
    posted.mockClear()
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))

    expect(lastPost('thallo:text-changed')).toMatchObject({ html: '<p><strong>mine</strong></p>' })
    expect(w.querySelector('b.theme-bold')).not.toBeNull()
    expect(w.querySelector('span[style]')).not.toBeNull()
  })
})

describe('selection-following format bubble (format-bubble spec §1/§2)', () => {
  function grantRich(id: string): HTMLElement {
    const w = proseWrapper(id)
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id, field: 'body', kind: 'rich' })
    return w
  }

  function endSession(w: HTMLElement): void {
    const region = w.querySelector('.thallo-edit-region')
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
        getBoundingClientRect: () => ({
          ...rect,
          right: rect.left + rect.width,
          x: rect.left,
          y: rect.top,
        }),
      }),
    }) as unknown as typeof window.getSelection
  }

  function fireSelectionChange(): void {
    document.dispatchEvent(new Event('selectionchange'))
  }

  function bubble(): HTMLElement | null {
    return document.querySelector('body > .thallo-canvas-format-bar')
  }

  it('a rich grant creates a hidden bubble on body; plain kinds get none; end removes it', () => {
    const w = grantRich('fb-a-000001')
    const bar = bubble()!
    expect(bar).not.toBeNull()
    expect(bar.classList.contains('thallo-canvas-format-visible')).toBe(false)
    const formats = [...bar.querySelectorAll('[data-format]')].map((b) =>
      b.getAttribute('data-format'),
    )
    expect(formats).toEqual(['bold', 'italic', 'underline', 'strikethrough', 'link', 'unlink'])

    endSession(w)
    expect(bubble()).toBeNull()

    const s = wrapper(
      'fb-b-000001',
      '<section><h2><span class="thallo-edit-region" data-thallo-edit-block="fb-b-000001" ' +
        'data-thallo-edit-field="heading">Hello</span></h2></section>',
    )
    document.body.appendChild(s)
    sendToBridge({ type: 'thallo:edit-grant', id: 'fb-b-000001', field: 'heading', kind: 'string' })
    expect(s.querySelector('[contenteditable]')).not.toBeNull()
    expect(bubble()).toBeNull()
    endSession(s)
  })

  it('shows over a non-collapsed in-region selection, positioned off the selection rect', () => {
    try {
      const w = grantRich('fb-c-000001')
      const region = w.querySelector('.thallo-edit-region')!
      const bar = bubble()!

      // In-region, non-collapsed: visible, centered above the rect (jsdom
      // bubble rect is all zeros, so x = left + width/2, y = top - 8).
      stubSelection({ collapsed: false, container: region.querySelector('p')! })
      fireSelectionChange()
      expect(bar.classList.contains('thallo-canvas-format-visible')).toBe(true)
      expect(bar.style.transform).toBe('translate(125px, 192px)')

      // Collapsed: hidden.
      stubSelection({ collapsed: true, container: region.querySelector('p')! })
      fireSelectionChange()
      expect(bar.classList.contains('thallo-canvas-format-visible')).toBe(false)

      // Non-collapsed but OUTSIDE the region (strict containment — review
      // caution: a partially-outside selection resolves its common ancestor
      // above the region and must hide).
      stubSelection({ collapsed: false, container: document.body })
      fireSelectionChange()
      expect(bar.classList.contains('thallo-canvas-format-visible')).toBe(false)

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
      const region = w.querySelector('.thallo-edit-region')!
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
        // jsdom has no execCommand: emulate the engine's b/i output so the
        // post-action normalization has something real to rewrite.
        if (cmd === 'bold') region.innerHTML = '<p><b>sel</b> rest</p>'
        if (cmd === 'italic') region.innerHTML = '<p><i>sel</i> rest</p>'
        if (cmd === 'underline') region.innerHTML = '<p><u>sel</u> rest</p>'
        if (cmd === 'strikeThrough') region.innerHTML = '<p><strike>sel</strike> rest</p>'
        return true
      })
      document.execCommand = exec as unknown as typeof document.execCommand
      const w = grantRich('fb-e-000001')
      region = w.querySelector('.thallo-edit-region')!
      stubSelection({ collapsed: false, container: region })

      const bar = bubble()!
      bar
        .querySelector('[data-format="bold"]')!
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(exec).toHaveBeenCalledWith('bold')
      expect(region.innerHTML).toBe('<p><strong>sel</strong> rest</p>')
      // Post-action re-anchor (review caution): the bubble repositioned from
      // the stubbed selection WITHOUT a selectionchange event.
      expect(bar.classList.contains('thallo-canvas-format-visible')).toBe(true)
      expect(bar.style.transform).toBe('translate(125px, 192px)')

      bar
        .querySelector('[data-format="italic"]')!
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(region.innerHTML).toBe('<p><em>sel</em> rest</p>')

      // <u> is allowlisted as-is; <strike> is NOT — it must normalize to <s>.
      bar
        .querySelector('[data-format="underline"]')!
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(exec).toHaveBeenCalledWith('underline')
      expect(region.innerHTML).toBe('<p><u>sel</u> rest</p>')
      bar
        .querySelector('[data-format="strikethrough"]')!
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(exec).toHaveBeenCalledWith('strikeThrough')
      expect(region.innerHTML).toBe('<p><s>sel</s> rest</p>')

      // The click landed on the bubble (outside the region) but the session
      // survives: no edit-end, contenteditable intact.
      expect(lastPost('thallo:edit-end')).toBeUndefined()
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
      const region = w.querySelector('.thallo-edit-region')!
      region.innerHTML = '<p><b>x</b></p>' // pretend the engine mutated on execCommand
      posted.mockClear()

      bubble()!
        .querySelector('[data-format="bold"]')!
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(lastPost('thallo:text-changed')).toBeUndefined() // debounced, not instant
      vi.advanceTimersByTime(450)
      expect(lastPost('thallo:text-changed')).toMatchObject({
        id: 'fb-g-000001',
        field: 'body',
        html: '<p><strong>x</strong></p>',
      })
      endSession(w)
    } finally {
      vi.useRealTimers()
    }
  })

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

  it('the bubble never reaches committed HTML or duplicate clones', () => {
    document.execCommand = vi.fn(() => true) as unknown as typeof document.execCommand
    const w = grantRich('fb-i-000001')
    posted.mockClear()
    endSession(w) // commit + end
    const committed = lastPost('thallo:text-changed')!
    expect(String(committed.html)).not.toContain('thallo-canvas-format')

    // The bubble lives on document.body — structurally outside every wrapper —
    // so a duplicate clone can never carry it.
    sendToBridge({ type: 'thallo:edit-grant', id: 'fb-i-000001', field: 'body', kind: 'rich' })
    expect(bubble()).not.toBeNull()
    sendToBridge({
      type: 'thallo:mirror-duplicate',
      sourceId: 'fb-i-000001',
      idMap: { 'fb-i-000001': 'fb-i-000002' },
    })
    const clone = document.querySelector('[data-thallo-block="fb-i-000002"]')!
    expect(clone.querySelector('.thallo-canvas-format-bar')).toBeNull()
    endSession(w)
  })
})

describe('inline link panel (link-panel spec §1–§4)', () => {
  const realGetSelection = window.getSelection

  function grantRich(id: string, inner?: string): HTMLElement {
    const w = inner ? wrapper(id, inner) : proseWrapper(id)
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id, field: 'body', kind: 'rich' })
    return w
  }

  function endSession(w: HTMLElement): void {
    const region = w.querySelector('.thallo-edit-region')
    region?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  }

  function bubble(): HTMLElement {
    return document.querySelector('body > .thallo-canvas-format-bar') as HTMLElement
  }

  function panelEl(): Element {
    return bubble().querySelector('.thallo-canvas-link-panel')!
  }

  interface SelectionSpies {
    removeAllRanges: ReturnType<typeof vi.fn>
    addRange: ReturnType<typeof vi.fn>
    range: object
    collapse: (collapsed: boolean) => void
  }

  function stubRichSelection(container: Node): SelectionSpies {
    const range = {
      commonAncestorContainer: container,
      cloneRange(): object {
        return this
      },
      getBoundingClientRect: () => ({
        left: 100,
        top: 200,
        width: 50,
        height: 20,
        bottom: 220,
        right: 150,
        x: 100,
        y: 200,
      }),
    }
    const removeAllRanges = vi.fn()
    const addRange = vi.fn()
    const state = { isCollapsed: false }
    window.getSelection = vi.fn().mockImplementation(() => ({
      isCollapsed: state.isCollapsed,
      rangeCount: 1,
      getRangeAt: () => range,
      removeAllRanges,
      addRange,
    })) as unknown as typeof window.getSelection
    return {
      removeAllRanges,
      addRange,
      range,
      collapse: (collapsed: boolean) => {
        state.isCollapsed = collapsed
      },
    }
  }

  function openPanel(): HTMLInputElement {
    bubble()
      .querySelector('[data-format="link"]')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    return bubble().querySelector('.thallo-canvas-link-panel input') as HTMLInputElement
  }

  it('opens on link click, prefills from a REGION-contained <a> only, and freezes visibility', () => {
    try {
      // Region wrapped by a theme-level <a> OUTSIDE it: prefill must ignore it.
      const wOutside = grantRich(
        'lp-a-000001',
        '<section><a href="https://theme.test/outer"><div class="thallo-edit-region" ' +
          'data-thallo-edit-block="lp-a-000001" data-thallo-edit-field="body">' +
          '<p>text</p></div></a></section>',
      )
      const regionA = wOutside.querySelector('.thallo-edit-region')!
      const spiesA = stubRichSelection(regionA.querySelector('p')!)
      // Make the bubble visible the way a real selection does, THEN open.
      document.dispatchEvent(new Event('selectionchange'))
      expect(bubble().classList.contains('thallo-canvas-format-visible')).toBe(true)
      const inputA = openPanel()
      expect(inputA).not.toBeNull()
      expect(inputA.value).toBe('') // outside link ignored (spec pin)
      expect(panelEl().classList.contains('thallo-canvas-link-open')).toBe(true)

      // Freeze (spec §4): a COLLAPSED selectionchange while the panel is open
      // must neither hide the bubble nor close the panel — that is exactly
      // what happens when focus enters the input.
      spiesA.collapse(true)
      document.dispatchEvent(new Event('selectionchange'))
      expect(bubble().classList.contains('thallo-canvas-format-visible')).toBe(true)
      expect(panelEl().classList.contains('thallo-canvas-link-open')).toBe(true)

      // After close, the same collapsed selection hides the bubble.
      inputA.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
      )
      document.dispatchEvent(new Event('selectionchange'))
      expect(bubble().classList.contains('thallo-canvas-format-visible')).toBe(false)
      endSession(wOutside)

      // Region-contained <a>: prefill picks up its href.
      const wInside = grantRich(
        'lp-b-000001',
        '<section><div class="thallo-edit-region" data-thallo-edit-block="lp-b-000001" ' +
          'data-thallo-edit-field="body"><p><a href="https://x.test/old">old</a></p></div></section>',
      )
      const anchor = wInside.querySelector('.thallo-edit-region a')!
      stubRichSelection(anchor.firstChild!)
      const inputB = openPanel()
      expect(inputB.value).toBe('https://x.test/old')
      endSession(wInside)
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('input mousedown is allowed to focus; format-button mousedown is still cancelled', () => {
    try {
      const w = grantRich('lp-c-000001')
      stubRichSelection(w.querySelector('.thallo-edit-region p')!)
      const input = openPanel()

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
      const region = w.querySelector('.thallo-edit-region')!
      stubRichSelection(region.querySelector('p')!)
      const input = openPanel()
      posted.mockClear()

      region.dispatchEvent(new FocusEvent('blur', { relatedTarget: input }))
      expect(lastPost('thallo:edit-end')).toBeUndefined()
      expect(region.getAttribute('contenteditable')).toBe('true')

      // Null relatedTarget = REAL outside blur (review caution): commit-and-exit.
      region.dispatchEvent(new FocusEvent('blur'))
      expect(lastPost('thallo:edit-end')).toMatchObject({ id: 'lp-d-000001' })
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
      const region = w.querySelector('.thallo-edit-region')!
      const spies = stubRichSelection(region.querySelector('p')!)
      const input = openPanel()

      input.value = '  https://x.test/new  '
      posted.mockClear()
      input.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
      )

      expect(spies.addRange).toHaveBeenCalledWith(spies.range)
      expect(exec).toHaveBeenCalledWith('createLink', false, 'https://x.test/new')
      // Observable order pin: range restored BEFORE createLink ran.
      expect(spies.addRange.mock.invocationCallOrder[0]).toBeLessThan(
        exec.mock.invocationCallOrder[0],
      )
      // Success closes the panel AFTER command/normalize/commit scheduling.
      expect(panelEl().classList.contains('thallo-canvas-link-open')).toBe(false)
      vi.advanceTimersByTime(450)
      expect(lastPost('thallo:text-changed')).toMatchObject({ id: 'lp-e-000001' })
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
      stubRichSelection(w.querySelector('.thallo-edit-region p')!)
      const input = openPanel()

      for (const value of ['', '   ', '//evil.test/x', 'javascript:alert(1)', 'data:text/html,x']) {
        input.value = value
        input.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
        )
        expect(panelEl().classList.contains('thallo-canvas-link-open')).toBe(true)
        expect(panelEl().classList.contains('thallo-canvas-link-invalid')).toBe(true)
        expect(input.value).toBe(value) // preserved (review caution)
      }
      expect(exec).not.toHaveBeenCalled()

      // The next keystroke clears the invalid mark.
      input.dispatchEvent(new Event('input', { bubbles: true }))
      expect(panelEl().classList.contains('thallo-canvas-link-invalid')).toBe(false)
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
    }
  })

  it('Escape closes the panel, refocuses the region, and the session survives', () => {
    try {
      const w = grantRich('lp-g-000001')
      const region = w.querySelector('.thallo-edit-region') as HTMLElement
      stubRichSelection(region.querySelector('p')!)
      const input = openPanel()

      input.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
      )
      expect(panelEl().classList.contains('thallo-canvas-link-open')).toBe(false)
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
      const spies1 = stubRichSelection(w1.querySelector('.thallo-edit-region p')!)
      openPanel()
      endSession(w1) // closes panel via endEditing -> closeLinkPanel

      const w2 = grantRich('lp-i-000001')
      const spies2 = stubRichSelection(w2.querySelector('.thallo-edit-region p')!)
      const input2 = openPanel()
      input2.value = 'https://x.test/two'
      input2.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
      )
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
      stubRichSelection(w.querySelector('.thallo-edit-region p')!)
      const input = openPanel()
      posted.mockClear()
      input.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Backspace', bubbles: true, cancelable: true }),
      )
      expect(lastPost('thallo:block-delete-request')).toBeUndefined()
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
    }
  })
})

describe('bubble active-state (polish batch §1)', () => {
  const realGetSelection = window.getSelection
  const realQueryCommandState = document.queryCommandState

  function grantRich(id: string, inner?: string): HTMLElement {
    const w = inner ? wrapper(id, inner) : proseWrapper(id)
    document.body.appendChild(w)
    sendToBridge({ type: 'thallo:edit-grant', id, field: 'body', kind: 'rich' })
    return w
  }

  function endSession(w: HTMLElement): void {
    const region = w.querySelector('.thallo-edit-region')
    region?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  }

  function bubble(): HTMLElement {
    return document.querySelector('body > .thallo-canvas-format-bar') as HTMLElement
  }

  function stubSel(container: Node, collapsed = false): { collapse: (c: boolean) => void } {
    const state = { isCollapsed: collapsed }
    window.getSelection = vi.fn().mockImplementation(() => ({
      isCollapsed: state.isCollapsed,
      rangeCount: 1,
      getRangeAt: () => ({
        commonAncestorContainer: container,
        cloneRange(): object {
          return this
        },
        getBoundingClientRect: () => ({
          left: 100, top: 200, width: 50, height: 20, bottom: 220, right: 150, x: 100, y: 200,
        }),
      }),
      removeAllRanges: vi.fn(),
      addRange: vi.fn(),
    })) as unknown as typeof window.getSelection
    return { collapse: (c: boolean) => (state.isCollapsed = c) }
  }

  function active(format: string): boolean {
    return bubble()
      .querySelector(`[data-format="${format}"]`)!
      .classList.contains('thallo-canvas-format-active')
  }

  it('marks buttons from queryCommandState; link/unlink from region <a> containment', () => {
    try {
      document.queryCommandState = vi.fn(
        (cmd: string) => cmd === 'bold',
      ) as unknown as typeof document.queryCommandState
      const w = grantRich(
        'as-a-000001',
        '<section><div class="thallo-edit-region" data-thallo-edit-block="as-a-000001" ' +
          'data-thallo-edit-field="body"><p><a href="/x">linked</a></p></div></section>',
      )
      const anchor = w.querySelector('.thallo-edit-region a')!
      stubSel(anchor.firstChild!)
      document.dispatchEvent(new Event('selectionchange'))

      expect(active('bold')).toBe(true)
      expect(active('italic')).toBe(false)
      expect(active('underline')).toBe(false)
      expect(active('strikethrough')).toBe(false)
      expect(active('link')).toBe(true)
      expect(active('unlink')).toBe(true)
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
      document.queryCommandState = realQueryCommandState
    }
  })

  it('no-stale pin: hiding clears active classes; reopen recomputes from the live state', () => {
    try {
      let boldState = true
      document.queryCommandState = vi.fn(
        (cmd: string) => cmd === 'bold' && boldState,
      ) as unknown as typeof document.queryCommandState
      const w = grantRich('as-b-000001')
      const sel = stubSel(w.querySelector('.thallo-edit-region p')!)
      document.dispatchEvent(new Event('selectionchange'))
      expect(active('bold')).toBe(true)

      // Collapse -> hidden -> classes CLEARED (review P2).
      sel.collapse(true)
      document.dispatchEvent(new Event('selectionchange'))
      expect(bubble().classList.contains('thallo-canvas-format-visible')).toBe(false)
      expect(bubble().querySelector('.thallo-canvas-format-active')).toBeNull()

      // Reopen over plain text: recomputed, stays inactive.
      boldState = false
      sel.collapse(false)
      document.dispatchEvent(new Event('selectionchange'))
      expect(bubble().classList.contains('thallo-canvas-format-visible')).toBe(true)
      expect(active('bold')).toBe(false)
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
      document.queryCommandState = realQueryCommandState
    }
  })

  it('a missing or throwing queryCommandState leaves buttons inactive without crashing', () => {
    try {
      const w = grantRich('as-c-000001')
      stubSel(w.querySelector('.thallo-edit-region p')!)

      document.queryCommandState = undefined as unknown as typeof document.queryCommandState
      document.dispatchEvent(new Event('selectionchange'))
      expect(bubble().classList.contains('thallo-canvas-format-visible')).toBe(true)
      expect(bubble().querySelector('.thallo-canvas-format-active')).toBeNull()

      document.queryCommandState = vi.fn(() => {
        throw new Error('detached selection')
      }) as unknown as typeof document.queryCommandState
      document.dispatchEvent(new Event('selectionchange'))
      expect(bubble().querySelector('.thallo-canvas-format-active')).toBeNull()
      endSession(w)
    } finally {
      window.getSelection = realGetSelection
      document.queryCommandState = realQueryCommandState
    }
  })
})

describe('drag ghost + edge auto-scroll (polish batch §2/§3)', () => {
  // Reuses the free-drag fixtures: wrappers with fixed rect bands.
  function stubRects2(wrappers: HTMLElement[], height = 100): void {
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

  function ghostList(): { list: HTMLElement; a: HTMLElement } {
    const list = document.createElement('main')
    const a = wrapper('gh-a-0000001')
    const b = wrapper('gh-b-0000002')
    const c = wrapper('gh-c-0000003')
    list.append(a, b, c)
    document.body.appendChild(list)
    stubRects2([a, b, c])
    return { list, a }
  }

  function gripDown2(w: HTMLElement): void {
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    w.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    const gripSvg = w.querySelector('[data-action="drag"] svg')!
    gripSvg.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, cancelable: true }))
  }

  function move(y: number, x = 0): void {
    document.dispatchEvent(
      new MouseEvent('pointermove', { bubbles: true, clientY: y, clientX: x } as MouseEventInit),
    )
  }

  function ghost(): HTMLElement | null {
    return document.querySelector('body > .thallo-canvas-drag-ghost')
  }

  it('a ghost appears on the first move, follows the pointer, and dies with the drag', () => {
    const { a } = ghostList()
    gripDown2(a)
    expect(ghost()).toBeNull() // never on gripDown alone (click w/o movement)

    move(160, 40)
    const g = ghost()!
    expect(g).not.toBeNull()
    expect(g.style.transform).toBe('translate(52px, 172px)') // pointer + 12px offset
    // Strip applied: the selected host's toolbar never rides the ghost.
    expect(g.querySelector('.thallo-canvas-toolbar')).toBeNull()
    expect(g.querySelector('.thallo-canvas-anchor')).toBeNull()

    move(200, 60)
    expect(g.style.transform).toBe('translate(72px, 212px)')

    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    expect(ghost()).toBeNull()
  })

  it('Escape rollback removes the ghost too', () => {
    const { a } = ghostList()
    gripDown2(a)
    move(160)
    expect(ghost()).not.toBeNull()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(ghost()).toBeNull()
  })

  it('edge zones start a scroll interval; leaving stops it; direction follows the zone', () => {
    vi.useFakeTimers()
    const realInnerHeight = window.innerHeight
    const scrollBy = vi.fn()
    const realScrollBy = window.scrollBy
    try {
      Object.defineProperty(window, 'innerHeight', { configurable: true, value: 800 })
      window.scrollBy = scrollBy as unknown as typeof window.scrollBy

      const { a } = ghostList()
      gripDown2(a)
      move(780) // bottom 48px zone
      vi.advanceTimersByTime(100)
      expect(scrollBy).toHaveBeenCalledWith(0, 12)
      expect(scrollBy.mock.calls.length).toBeGreaterThan(2)

      scrollBy.mockClear()
      move(400) // out of both zones
      vi.advanceTimersByTime(100)
      expect(scrollBy).not.toHaveBeenCalled()

      move(20) // top zone
      vi.advanceTimersByTime(100)
      expect(scrollBy).toHaveBeenCalledWith(0, -12)

      scrollBy.mockClear()
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
      vi.advanceTimersByTime(100)
      expect(scrollBy).not.toHaveBeenCalled() // cleared with the drag
    } finally {
      vi.useRealTimers()
      window.scrollBy = realScrollBy
      Object.defineProperty(window, 'innerHeight', { configurable: true, value: realInnerHeight })
    }
  })
})

describe('stage refresh / partial DOM patching (dom-patching spec §2)', () => {
  const realFetch = window.fetch

  function acked(): { refresh_id?: string; mode?: string } | undefined {
    return lastPost('thallo:stage-refreshed') as { refresh_id?: string; mode?: string } | undefined
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
    document.body.innerHTML = '<header><h1>Shell title</h1></header><main></main>'
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
      `<div class="thallo-preview-block" data-thallo-block="pd-a-0000001"><section><p>${alpha}</p></section></div>` +
      `<div class="thallo-preview-block" data-thallo-block="pd-b-0000002"><section><p>${beta}</p></section></div>` +
      `</main></body></html>`
    )
  }

  async function refresh(id = 'r1'): Promise<void> {
    sendToBridge({ type: 'thallo:stage-refresh', refresh_id: id })
    await new Promise((r) => setTimeout(r, 0)) // let the fetch promise chain settle
    await new Promise((r) => setTimeout(r, 0))
    await new Promise((r) => setTimeout(r, 0))
  }

  it('swaps ONLY the changed wrapper and acks patched with the echoed id', async () => {
    try {
      const { a, b } = liveStage()
      stubFetch(renderedHtml('Alpha v2', 'Beta v1'))
      posted.mockClear()
      await refresh('r-alpha')

      expect(acked()).toMatchObject({ refresh_id: 'r-alpha', mode: 'patched' })
      const newA = document.querySelector('[data-thallo-block="pd-a-0000001"]')!
      expect(newA.textContent).toContain('Alpha v2')
      expect(newA).not.toBe(a) // swapped
      expect(document.querySelector('[data-thallo-block="pd-b-0000002"]')).toBe(b) // identity kept
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
      expect(document.querySelector('[data-thallo-block="pd-a-0000001"]')).toBe(a) // untouched
      expect(a.textContent).toContain('Alpha v1')
    } finally {
      window.fetch = realFetch
    }
  })

  it('unmirrored structural drift reloads: extra id and duplicate ids', async () => {
    try {
      liveStage()
      // Extra wrapper in the render (add-after shape).
      stubFetch(
        renderedHtml('Alpha v1', 'Beta v1').replace(
          '</main>',
          '<div class="thallo-preview-block" data-thallo-block="pd-c-0000003"><p>New</p></div></main>',
        ),
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'reload' })

      // Duplicate id on the fetched side.
      stubFetch(
        renderedHtml('Alpha v1', 'Beta v1').replace(
          'data-thallo-block="pd-b-0000002"',
          'data-thallo-block="pd-a-0000001"',
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
      sendToBridge({ type: 'thallo:mirror-move', id: 'pd-b-0000002', beforeId: 'pd-a-0000001' })
      expect(b.nextElementSibling).toBe(a)
      // The render agrees with the mirrored order.
      stubFetch(
        `<html><body><header><h1>Shell title</h1></header><main>` +
          `<div class="thallo-preview-block" data-thallo-block="pd-b-0000002"><section><p>Beta SERVER</p></section></div>` +
          `<div class="thallo-preview-block" data-thallo-block="pd-a-0000001"><section><p>Alpha v1</p></section></div>` +
          `</main></body></html>`,
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'patched' })
      // The optimistic mirror's content is swapped to the RENDERED truth.
      expect(document.querySelector('[data-thallo-block="pd-b-0000002"]')!.textContent).toContain(
        'Beta SERVER',
      )
      expect(document.querySelector('[data-thallo-block="pd-a-0000001"]')).toBe(a)
    } finally {
      window.fetch = realFetch
    }
  })

  it('canvas UI never poisons the gate; a swapped selected wrapper re-selects', async () => {
    try {
      const { a } = liveStage()
      a.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      expect(a.querySelector('.thallo-canvas-toolbar')).not.toBeNull() // live UI present
      stubFetch(renderedHtml('Alpha v2', 'Beta v1'))
      posted.mockClear()
      await refresh()

      expect(acked()).toMatchObject({ mode: 'patched' })
      const newA = document.querySelector('[data-thallo-block="pd-a-0000001"]')!
      expect(newA.textContent).toContain('Alpha v2')
      // Selection survived the swap: ring + toolbar re-anchored on the NEW wrapper.
      expect(newA.classList.contains('thallo-canvas-selected')).toBe(true)
      expect(newA.querySelector('.thallo-canvas-toolbar')).not.toBeNull()
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
        '<section><div class="thallo-preview-block" data-thallo-block="pd-vc-00001"><p>child</p></div></section>',
      )
      document.body.querySelector('main')!.appendChild(parent)
      const child = document.querySelector('[data-thallo-block="pd-vc-00001"]')!
      child.querySelector('p')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      expect(lastPost('thallo:block-select')).toMatchObject({ id: 'pd-vc-00001' })

      stubFetch(
        `<html><body><main>` +
          `<div class="thallo-preview-block" data-thallo-block="pd-vp-00001"><section><p>childless now</p></section></div>` +
          `</main></body></html>`,
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'patched' })
      expect(lastPost('thallo:block-deselect')).toMatchObject({ id: 'pd-vc-00001' })
      expect(document.querySelector('.thallo-canvas-toolbar')).toBeNull()
    } finally {
      window.fetch = realFetch
    }
  })

  it('busy during an edit session and during a drag; DOM untouched', async () => {
    try {
      // Edit session.
      const w = proseWrapper('pd-ed-00001')
      document.body.appendChild(w)
      sendToBridge({ type: 'thallo:edit-grant', id: 'pd-ed-00001', field: 'body', kind: 'rich' })
      stubFetch(renderedHtml('x', 'y'))
      posted.mockClear()
      await refresh('r-busy')
      expect(acked()).toMatchObject({ refresh_id: 'r-busy', mode: 'busy' })
      w.querySelector('.thallo-edit-region')!.dispatchEvent(
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
          window.fetch = vi
            .fn()
            .mockRejectedValue(new Error('net')) as unknown as typeof window.fetch
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
        expect(document.querySelector('[data-thallo-block="pd-a-0000001"]')).toBe(a)
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
        '<section><div class="thallo-preview-block" data-thallo-block="pd-nc-00001"><p>child v1</p></div></section>',
      )
      document.body.querySelector('main')!.appendChild(parent)
      stubFetch(
        `<html><body><main>` +
          `<div class="thallo-preview-block" data-thallo-block="pd-np-00001"><section>` +
          `<div class="thallo-preview-block" data-thallo-block="pd-nc-00001"><p>child v2</p></div>` +
          `</section></div></main></body></html>`,
      )
      posted.mockClear()
      await refresh()
      expect(acked()).toMatchObject({ mode: 'patched' })
      expect(document.querySelector('[data-thallo-block="pd-nc-00001"]')!.textContent).toContain(
        'child v2',
      )
      // ONE top-level wrapper for the id in the document (no double insert).
      expect(document.querySelectorAll('[data-thallo-block="pd-np-00001"]')).toHaveLength(1)
    } finally {
      window.fetch = realFetch
    }
  })
})
