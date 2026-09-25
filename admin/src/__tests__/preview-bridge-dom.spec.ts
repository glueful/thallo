import { describe, it, expect, vi, afterEach, beforeAll, beforeEach } from 'vitest'
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

function wrapper(
  id: string,
  inner = `<section><a href="/x">link ${id}</a></section>`,
): HTMLElement {
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
    // The id alone: the Blocks tab arms "after this block" and needs no anchor (Phase C.1).
    expect(addAfter).toEqual({ type: 'thallo:block-add-after', id: 'blk-int-0001', nonce: NONCE })
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

describe('tabs on the canvas', () => {
  // The runtime's tabs module skips the canvas, so the CSS radio floor drives the panels; the
  // bridge must let a label click reach its radio (every other in-block click is inert) and
  // must bring a hidden panel forward when something inside it is selected.
  function tabsBlock(id: string): HTMLElement {
    const w = wrapper(
      id,
      `<div class="thallo-block thallo-block-tabs">` +
        `<input class="thallo-block-tabs__radio" type="radio" name="tabs-${id}" id="tabs-${id}-1" checked>` +
        `<input class="thallo-block-tabs__radio" type="radio" name="tabs-${id}" id="tabs-${id}-2">` +
        `<div class="thallo-block-tabs__list">` +
        `<label class="thallo-block-tabs__label" for="tabs-${id}-1">One</label>` +
        `<label class="thallo-block-tabs__label" for="tabs-${id}-2">Two</label></div>` +
        `<div class="thallo-block-tabs__panels">` +
        `<div class="thallo-block-tabs__panel"><div class="thallo-preview-block" data-thallo-block="${id}-t1"><div class="thallo-block thallo-block-tab"><p>p1</p></div></div></div>` +
        `<div class="thallo-block-tabs__panel"><div class="thallo-preview-block" data-thallo-block="${id}-t2"><div class="thallo-block thallo-block-tab"><p>p2</p></div></div></div>` +
        `</div></div>`,
    )
    document.body.appendChild(w)
    return w
  }

  it("a tab label click checks its radio and selects that panel's tab block", () => {
    const w = tabsBlock('tb-a-0000001')
    const two = w.querySelector<HTMLLabelElement>('label[for="tabs-tb-a-0000001-2"]')!
    two.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(w.querySelector<HTMLInputElement>('#tabs-tb-a-0000001-2')!.checked).toBe(true)
    expect(w.querySelector<HTMLInputElement>('#tabs-tb-a-0000001-1')!.checked).toBe(false)
    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'tb-a-0000001-t2' })
  })

  it('selecting a block inside a hidden panel (the outline) brings that panel forward', () => {
    const w = tabsBlock('tb-b-0000002')
    sendToBridge({ type: 'thallo:highlight', id: 'tb-b-0000002-t2' })
    expect(w.querySelector<HTMLInputElement>('#tabs-tb-b-0000002-2')!.checked).toBe(true)
    expect(
      w
        .querySelector('[data-thallo-block="tb-b-0000002-t2"]')!
        .classList.contains('thallo-canvas-selected'),
    ).toBe(true)
  })
})

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
    expect(lastPost('thallo:edit-request')).toMatchObject({
      id: 'es-a-0000001',
      field: 'body_text',
    })

    // Grant for ONE of two same-block regions edits exactly that region.
    sendToBridge({
      type: 'thallo:edit-grant',
      id: 'es-a-0000001',
      field: 'body_text',
      kind: 'text',
    })
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
    sendToBridge({
      type: 'thallo:edit-grant',
      id: 'es-d-0000001',
      field: 'heading',
      kind: 'string',
    })
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
    sendToBridge({
      type: 'thallo:edit-grant',
      id: 'es-e-0000001',
      field: 'body_text',
      kind: 'text',
    })
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

describe('proposal drag (visual builder spec §5.3/§5.4)', () => {
  const realFromPoint = document.elementFromPoint
  const realFetch = window.fetch
  afterEach(() => {
    document.elementFromPoint = realFromPoint
    window.fetch = realFetch
  })

  type Band = { top: number; bottom: number; left: number; right: number }
  function stubRect(el: HTMLElement, band: Band): void {
    Object.defineProperty(el, 'getBoundingClientRect', {
      configurable: true,
      value: () => ({
        ...band,
        height: band.bottom - band.top,
        width: band.right - band.left,
        x: band.left,
        y: band.top,
        toJSON: () => ({}),
      }),
    })
  }
  /** Vertical bands of 100px per wrapper host, in order. */
  function stubRects(wrappers: HTMLElement[]): void {
    wrappers.forEach((w, i) =>
      stubRect(w.firstElementChild as HTMLElement, {
        top: i * 100,
        bottom: (i + 1) * 100,
        left: 0,
        right: 500,
      }),
    )
  }
  /**
   * Hit testing: the host whose band contains the point, else the slot itself when the point
   * is inside it, else nothing. jsdom has no layout, so the stub IS the geometry.
   */
  function stubHits(slot: HTMLElement, slotBand: Band): void {
    document.elementFromPoint = (x: number, y: number) => {
      const hosts = [...slot.querySelectorAll(':scope > [data-thallo-block] > *:first-child')]
      for (const host of hosts) {
        const r = host.getBoundingClientRect()
        if (x >= r.left && x < r.right && y >= r.top && y < r.bottom) return host
      }
      const inside =
        x >= slotBand.left && x < slotBand.right && y >= slotBand.top && y < slotBand.bottom
      return inside ? slot : null
    }
  }

  function dragList(): { list: HTMLElement; a: HTMLElement; b: HTMLElement; c: HTMLElement } {
    const list = document.createElement('main')
    list.setAttribute('data-thallo-slot', 'body')
    const a = wrapper('fd-a-0000001')
    const b = wrapper('fd-b-0000002')
    const c = wrapper('fd-c-0000003')
    list.append(a, b, c)
    document.body.appendChild(list)
    stubRects([a, b, c])
    stubHits(list, { top: 0, bottom: 1000, left: 0, right: 500 })
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

  function pointerMove(y: number, x = 10): void {
    document.dispatchEvent(
      new MouseEvent('pointermove', { bubbles: true, clientY: y, clientX: x } as MouseEventInit),
    )
  }

  function order(list: HTMLElement): (string | null)[] {
    return [...list.children]
      .filter((el) => el.hasAttribute('data-thallo-block'))
      .map((el) => el.getAttribute('data-thallo-block'))
  }

  function proposals(): Record<string, unknown>[] {
    return posted.mock.calls
      .map((c) => c[0] as Record<string, unknown>)
      .filter((m) => m.type === 'thallo:drag-propose')
  }

  function indicator(list: HTMLElement): HTMLElement | null {
    return list.querySelector('.thallo-canvas-drop-line')
  }

  it('pointermove derives the zone under the pointer and posts ONE proposal per zone; the tree never reorders', () => {
    const { list, a, b, c } = dragList()
    gripDown(a)
    posted.mockClear()

    pointerMove(160) // past b's midpoint (150), before c's (250): index 1 of [b, c]
    expect(order(list)).toEqual(['fd-a-0000001', 'fd-b-0000002', 'fd-c-0000003'])
    expect(proposals()).toHaveLength(1)
    expect(proposals()[0]).toMatchObject({
      blocks: ['fd-a-0000001'],
      zone: { parent: null, slot: 'body', index: 1, layout: 'linear-vertical' },
    })
    expect(typeof proposals()[0]!.session).toBe('string')
    // The indicator is a real element between b and c, so the slot's own layout places it.
    const line = indicator(list)!
    expect(line.previousElementSibling).toBe(b)
    expect(line.nextElementSibling).toBe(c)

    pointerMove(170) // same zone: no second proposal
    expect(proposals()).toHaveLength(1)

    pointerMove(500) // below every midpoint: the end of the slot
    expect(proposals()).toHaveLength(2)
    expect(proposals()[1]).toMatchObject({ zone: { parent: null, slot: 'body', index: 2 } })
    expect(indicator(list)!.previousElementSibling).toBe(c)

    pointerMove(-50) // outside every slot: no zone, no indicator — and ONE null proposal
    expect(indicator(list)).toBeNull()
    expect(proposals()).toHaveLength(3)
    expect(proposals()[2]).toMatchObject({ zone: null })
    pointerMove(-60) // still outside: nothing more
    expect(proposals()).toHaveLength(3)
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  })

  it('a legality answer for the session paints the indicator: refused is red and titled', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()
    pointerMove(160)
    const session = proposals()[0]!.session as string
    const line = indicator(list)!

    sendToBridge({ type: 'thallo:drag-legality', session, legal: false, reason: 'Too deep' })
    expect(line.classList.contains('thallo-canvas-drop-line--refused')).toBe(true)
    expect(line.getAttribute('title')).toBe('Too deep')

    sendToBridge({ type: 'thallo:drag-legality', session: 'other', legal: true })
    expect(line.classList.contains('thallo-canvas-drop-line--refused')).toBe(true) // not ours

    sendToBridge({ type: 'thallo:drag-legality', session, legal: true })
    expect(line.classList.contains('thallo-canvas-drop-line--refused')).toBe(false)
    expect(line.hasAttribute('title')).toBe(false)
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  })

  it('pointerup posts ONE block-drop for the proposed zone and swallows the next click once', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()
    pointerMove(500)
    const session = proposals()[0]!.session as string

    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    const drops = posted.mock.calls
      .map((c) => c[0] as Record<string, unknown>)
      .filter((m) => m.type === 'thallo:block-drop')
    expect(drops).toHaveLength(1)
    expect(drops[0]).toMatchObject({
      session,
      blocks: ['fd-a-0000001'],
      zone: { parent: null, slot: 'body', index: 2, layout: 'linear-vertical' },
    })
    expect(lastPost('thallo:drag-cancel')).toBeUndefined()
    expect(indicator(list)).toBeNull()
    expect(a.classList.contains('thallo-canvas-dragging')).toBe(false)
    expect(order(list)).toEqual(['fd-a-0000001', 'fd-b-0000002', 'fd-c-0000003']) // untouched

    // The post-drag click: swallowed (no select), exactly once.
    posted.mockClear()
    a.querySelector('a')!.dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    )
    expect(lastPost('thallo:block-select')).toBeUndefined()
    a.querySelector('a')!.dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    )
    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'fd-a-0000001' })
  })

  it('pointerup over a refused zone, or over no zone, posts drag-cancel and never a drop', () => {
    const { a } = dragList()
    gripDown(a)
    posted.mockClear()
    pointerMove(160)
    const session = proposals()[0]!.session as string
    sendToBridge({ type: 'thallo:drag-legality', session, legal: false, reason: 'No' })
    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    expect(lastPost('thallo:block-drop')).toBeUndefined()
    expect(lastPost('thallo:drag-cancel')).toMatchObject({ session })

    // Second drag: released outside every slot.
    gripDown(a)
    posted.mockClear()
    pointerMove(-50)
    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    expect(lastPost('thallo:block-drop')).toBeUndefined()
    expect(lastPost('thallo:drag-cancel')).toBeDefined()
  })

  it('Escape posts drag-cancel, clears the indicator, keeps the selection, and does NOT swallow the next click', () => {
    const { list, a } = dragList()
    gripDown(a)
    posted.mockClear()
    pointerMove(160)
    const session = proposals()[0]!.session as string
    expect(indicator(list)).not.toBeNull()

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

    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
    )
    expect(lastPost('thallo:drag-cancel')).toMatchObject({ session })
    expect(lastPost('thallo:block-drop')).toBeUndefined()
    expect(lastPost('thallo:block-deselect')).toBeUndefined() // Escape belonged to the drag
    expect(indicator(list)).toBeNull()
    expect(a.classList.contains('thallo-canvas-dragging')).toBe(false)
    expect(a.classList.contains('thallo-canvas-selected')).toBe(true)

    // Cancel must not arm the click suppressor: the next click still selects.
    const other = wrapper('fd-d-0000004')
    document.body.appendChild(other)
    other.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'fd-d-0000004' })
  })

  it('a stage refresh mid-drag acks busy and the session survives (the tree was never touched)', async () => {
    const { list, a } = dragList()
    window.fetch = vi.fn().mockResolvedValue({
      ok: true,
      redirected: false,
      text: () =>
        Promise.resolve('<html><body><main data-thallo-slot="body"></main></body></html>'),
    }) as unknown as typeof window.fetch
    gripDown(a)
    posted.mockClear()
    pointerMove(160)
    const session = proposals()[0]!.session as string
    sendToBridge({ type: 'thallo:stage-refresh', refresh_id: 'r-drag' })
    await new Promise((r) => setTimeout(r, 0))
    await new Promise((r) => setTimeout(r, 0))
    expect(lastPost('thallo:stage-refreshed')).toMatchObject({ refresh_id: 'r-drag', mode: 'busy' })
    expect(lastPost('thallo:drag-cancel')).toBeUndefined()
    expect(indicator(list)).not.toBeNull()
    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true }))
    expect(lastPost('thallo:block-drop')).toMatchObject({ session })
  })

  it('a horizontal slot splits on x; a grid slot proposes its end with the outline hint', () => {
    const parent = wrapper('fd-p-0000009', '<section></section>')
    const row = document.createElement('div')
    row.setAttribute('data-thallo-slot', 'items')
    row.style.display = 'flex'
    const x1 = wrapper('fd-x-0000011')
    const x2 = wrapper('fd-x-0000012')
    row.append(x1, x2)
    parent.querySelector('section')!.appendChild(row)
    const grid = document.createElement('div')
    grid.setAttribute('data-thallo-slot', 'cells')
    grid.style.display = 'grid'
    const g1 = wrapper('fd-g-0000021')
    grid.append(g1)
    parent.querySelector('section')!.appendChild(grid)
    const mover = wrapper('fd-m-0000031')
    document.body.append(parent, mover)
    stubRect(x1.firstElementChild as HTMLElement, { top: 0, bottom: 100, left: 0, right: 100 })
    stubRect(x2.firstElementChild as HTMLElement, { top: 0, bottom: 100, left: 100, right: 200 })
    stubRect(g1.firstElementChild as HTMLElement, { top: 200, bottom: 300, left: 0, right: 100 })
    stubRect(mover.firstElementChild as HTMLElement, { top: 900, bottom: 950, left: 0, right: 100 })
    document.elementFromPoint = (x: number, y: number) => {
      if (y < 100) return x < 100 ? x1.firstElementChild : x < 200 ? x2.firstElementChild : row
      if (y >= 200 && y < 300) return x < 100 ? g1.firstElementChild : grid
      return null
    }

    gripDown(mover)
    posted.mockClear()
    pointerMove(50, 130) // right of x1's midpoint (50), left of x2's (150): index 1
    expect(proposals()[0]).toMatchObject({
      zone: { parent: 'fd-p-0000009', slot: 'items', index: 1, layout: 'linear-horizontal' },
    })
    const line = row.querySelector('.thallo-canvas-drop-line')!
    expect(line.classList.contains('thallo-canvas-drop-line--horizontal')).toBe(true)
    expect(line.previousElementSibling).toBe(x1)

    pointerMove(250, 20) // over g1 in the grid: no split, always the end
    expect(proposals()[1]).toMatchObject({
      zone: { parent: 'fd-p-0000009', slot: 'cells', index: 1, layout: 'other' },
    })
    const hint = grid.querySelector('.thallo-canvas-drop-line')!
    expect(hint.classList.contains('thallo-canvas-drop-line--other')).toBe(true)
    expect(hint.getAttribute('data-hint')).toMatch(/outline/)
    expect(row.querySelector('.thallo-canvas-drop-line')).toBeNull() // one indicator at a time
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  })

  it('a wrapping row whose blocks share one line splits on x; once it has wrapped it proposes its end', () => {
    const row = document.createElement('div')
    row.setAttribute('data-thallo-slot', 'wrapped')
    row.style.display = 'flex'
    row.style.flexWrap = 'wrap'
    const w1 = wrapper('fd-w-0000041')
    const w2 = wrapper('fd-w-0000042')
    row.append(w1, w2)
    const mover = wrapper('fd-m-0000043')
    document.body.append(row, mover)
    stubRect(w1.firstElementChild as HTMLElement, { top: 0, bottom: 100, left: 0, right: 100 })
    stubRect(w2.firstElementChild as HTMLElement, { top: 10, bottom: 90, left: 100, right: 200 })
    stubRect(mover.firstElementChild as HTMLElement, { top: 900, bottom: 950, left: 0, right: 100 })
    document.elementFromPoint = (x: number, y: number) =>
      y < 300 ? (x < 100 ? w1.firstElementChild : x < 200 ? w2.firstElementChild : row) : null

    gripDown(mover)
    posted.mockClear()
    pointerMove(50, 30) // left of w1's midpoint: before it
    expect(proposals()[0]).toMatchObject({
      zone: { slot: 'wrapped', index: 0, layout: 'linear-horizontal' },
    })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))

    // w2 wraps onto a second line: positions in the row are no longer a single axis.
    stubRect(w2.firstElementChild as HTMLElement, { top: 120, bottom: 220, left: 0, right: 100 })
    gripDown(mover)
    posted.mockClear()
    pointerMove(50, 30)
    expect(proposals()[0]).toMatchObject({ zone: { slot: 'wrapped', index: 2, layout: 'other' } })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    row.remove()
    mover.remove()
  })

  it('a zone inside the dragged subtree is refused locally: no proposal, no indicator', () => {
    const { list, a } = dragList()
    const inner = document.createElement('div')
    inner.setAttribute('data-thallo-slot', 'children')
    a.querySelector('section')!.appendChild(inner)
    const realHit = document.elementFromPoint
    document.elementFromPoint = (x: number, y: number) => (y >= 990 ? inner : realHit(x, y))
    gripDown(a)
    posted.mockClear()
    pointerMove(995)
    expect(proposals()).toHaveLength(0)
    expect(list.querySelector('.thallo-canvas-drop-line')).toBeNull()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  })

  it('a parent-originated drag proposes on hover and clears on end; a foreign session is ignored', () => {
    const { list } = dragList()
    posted.mockClear()
    sendToBridge({ type: 'thallo:drag-begin', session: 'ext1', blocks: ['new-block-0001'] })
    sendToBridge({ type: 'thallo:drag-hover', session: 'ext1', x: 10, y: 160 })
    expect(proposals()).toHaveLength(1)
    expect(proposals()[0]).toMatchObject({
      session: 'ext1',
      blocks: ['new-block-0001'],
      zone: { parent: null, slot: 'body', index: 2, layout: 'linear-vertical' }, // of [a, b, c]
    })
    expect(indicator(list)).not.toBeNull()

    sendToBridge({ type: 'thallo:drag-hover', session: 'ext2', x: 10, y: 500 })
    expect(proposals()).toHaveLength(1)

    sendToBridge({ type: 'thallo:drag-end', session: 'ext1' })
    expect(indicator(list)).toBeNull()
    sendToBridge({ type: 'thallo:drag-hover', session: 'ext1', x: 10, y: 500 })
    expect(proposals()).toHaveLength(1) // the session is over
  })

  it('a parent-originated session leaving every slot posts one null proposal; rapid hovers post in order', () => {
    const { list } = dragList()
    posted.mockClear()
    sendToBridge({ type: 'thallo:drag-begin', session: 'ext3', blocks: [] })
    sendToBridge({ type: 'thallo:drag-hover', session: 'ext3', x: 10, y: 40 })
    sendToBridge({ type: 'thallo:drag-hover', session: 'ext3', x: 10, y: 500 })
    expect(proposals().map((p) => (p.zone as { index: number } | null)?.index)).toEqual([0, 3])
    sendToBridge({ type: 'thallo:drag-hover', session: 'ext3', x: 10, y: -50 })
    sendToBridge({ type: 'thallo:drag-hover', session: 'ext3', x: 10, y: -80 })
    expect(proposals()).toHaveLength(3)
    expect(proposals()[2]).toMatchObject({ session: 'ext3', zone: null })
    expect(indicator(list)).toBeNull()
    sendToBridge({ type: 'thallo:drag-end', session: 'ext3' })
  })

  it('drag-drop answers with the zone under the RELEASED point, whatever the last hover or legality said', () => {
    const { list } = dragList()
    const drops = () =>
      posted.mock.calls
        .map((c) => c[0] as Record<string, unknown>)
        .filter((m) => m.type === 'thallo:block-drop' || m.type === 'thallo:drag-cancel')
    // Hovered blank canvas last, refused earlier: the release over the list still drops there.
    posted.mockClear()
    sendToBridge({ type: 'thallo:drag-begin', session: 'ext4', blocks: [] })
    sendToBridge({ type: 'thallo:drag-hover', session: 'ext4', x: 10, y: 60 })
    sendToBridge({ type: 'thallo:drag-legality', session: 'ext4', legal: false, reason: 'No' })
    sendToBridge({ type: 'thallo:drag-hover', session: 'ext4', x: 10, y: -50 })
    sendToBridge({ type: 'thallo:drag-drop', session: 'ext4', x: 10, y: 500 })
    expect(drops()).toEqual([
      expect.objectContaining({
        type: 'thallo:block-drop',
        session: 'ext4',
        zone: { parent: null, slot: 'body', index: 3, layout: 'linear-vertical' },
      }),
    ])
    expect(indicator(list)).toBeNull() // the session ended with the answer
    // A release over blank canvas cancels; an unknown session is ignored.
    posted.mockClear()
    sendToBridge({ type: 'thallo:drag-begin', session: 'ext5', blocks: [] })
    sendToBridge({ type: 'thallo:drag-hover', session: 'ext5', x: 10, y: 60 })
    sendToBridge({ type: 'thallo:drag-legality', session: 'ext5', legal: true })
    sendToBridge({ type: 'thallo:drag-drop', session: 'other', x: 10, y: 60 })
    expect(drops()).toEqual([])
    sendToBridge({ type: 'thallo:drag-drop', session: 'ext5', x: 10, y: -50 })
    expect(drops()).toEqual([
      expect.objectContaining({ type: 'thallo:drag-cancel', session: 'ext5' }),
    ])
  })
})

describe('sibling multi-selection on the stage (visual builder spec §5.5)', () => {
  function click(w: HTMLElement, init: MouseEventInit = {}): void {
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    w.querySelector('section')!.dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true, ...init }),
    )
  }

  it('a click posts its modifiers: shift extends, cmd/ctrl toggles, plain neither', () => {
    const a = wrapper('ms-a-0000001')
    document.body.appendChild(a)
    click(a)
    expect(lastPost('thallo:block-select')).toMatchObject({
      id: 'ms-a-0000001',
      shift: false,
      meta: false,
    })
    click(a, { shiftKey: true })
    expect(lastPost('thallo:block-select')).toMatchObject({
      id: 'ms-a-0000001',
      shift: true,
      meta: false,
    })
    click(a, { metaKey: true })
    expect(lastPost('thallo:block-select')).toMatchObject({ shift: false, meta: true })
    click(a, { ctrlKey: true })
    expect(lastPost('thallo:block-select')).toMatchObject({ shift: false, meta: true })
  })

  it('a highlight naming several ids rings every one, tools only the anchor, and drags them together', () => {
    const list = document.createElement('main')
    list.setAttribute('data-thallo-slot', 'body')
    const a = wrapper('ms-b-0000001')
    const b = wrapper('ms-b-0000002')
    const c = wrapper('ms-b-0000003')
    list.append(a, b, c)
    document.body.appendChild(list)

    sendToBridge({
      type: 'thallo:highlight',
      id: 'ms-b-0000001',
      ids: ['ms-b-0000001', 'ms-b-0000003'],
    })
    expect(a.classList.contains('thallo-canvas-selected')).toBe(true)
    expect(c.classList.contains('thallo-canvas-selected')).toBe(true)
    expect(b.classList.contains('thallo-canvas-selected')).toBe(false)
    expect(a.querySelector('.thallo-canvas-toolbar')).not.toBeNull()
    expect(c.querySelector('.thallo-canvas-toolbar')).toBeNull()

    // The grip drags the whole selection: the session names every ring — and takes focus, so
    // Escape reaches the stage even when the selection came from the parent's outline.
    posted.mockClear()
    const focus = vi.spyOn(window, 'focus').mockImplementation(() => undefined)
    a.querySelector('[data-action="drag"] svg')!.dispatchEvent(
      new MouseEvent('pointerdown', { bubbles: true, cancelable: true }),
    )
    expect(focus).toHaveBeenCalledTimes(1)
    focus.mockRestore()
    expect(c.classList.contains('thallo-canvas-dragging')).toBe(true)
    document.elementFromPoint = () => list
    document.dispatchEvent(
      new MouseEvent('pointermove', { bubbles: true, clientY: 900, clientX: 10 } as MouseEventInit),
    )
    expect(lastPost('thallo:drag-propose')).toMatchObject({
      blocks: ['ms-b-0000001', 'ms-b-0000003'],
    })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(c.classList.contains('thallo-canvas-dragging')).toBe(false)
    document.elementFromPoint = undefined as unknown as typeof document.elementFromPoint

    // A plain highlight of one id rings that one alone.
    sendToBridge({ type: 'thallo:highlight', id: 'ms-b-0000002' })
    expect(a.classList.contains('thallo-canvas-selected')).toBe(false)
    expect(c.classList.contains('thallo-canvas-selected')).toBe(false)
    expect(b.classList.contains('thallo-canvas-selected')).toBe(true)
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

  // A click on the stage leaves focus in the stage, so ⌘Z pressed next lands there. History is the
  // editor's: the stage forwards the intent and the page runs its own undo or redo.
  it('Cmd/Ctrl+Z asks the editor to undo and Shift adds redo — with or without a selection', () => {
    pressKey({ key: 'Escape' }) // nothing selected: a stage Fill or a + leaves it that way
    posted.mockClear()
    const undo = pressKey({ key: 'z', metaKey: true })
    expect(lastPost('thallo:history')).toMatchObject({ direction: 'undo' })
    expect(undo.defaultPrevented).toBe(true)

    posted.mockClear()
    const redo = pressKey({ key: 'Z', metaKey: true, shiftKey: true }) // Shift upper-cases the key
    expect(lastPost('thallo:history')).toMatchObject({ direction: 'redo' })
    expect(redo.defaultPrevented).toBe(true)

    posted.mockClear()
    pressKey({ key: 'z', ctrlKey: true })
    expect(lastPost('thallo:history')).toMatchObject({ direction: 'undo' })

    const w = wrapper('kb-undo-0001')
    document.body.appendChild(w)
    selectByClick(w)
    posted.mockClear()
    pressKey({ key: 'z', metaKey: true })
    expect(lastPost('thallo:history')).toMatchObject({ direction: 'undo' })
    // The toolbar's buttons keep Enter and Space; ⌘Z is nobody's native key there.
    posted.mockClear()
    pressKey({ key: 'z', metaKey: true }, w.querySelector('.thallo-canvas-toolbar button')!)
    expect(lastPost('thallo:history')).toMatchObject({ direction: 'undo' })
  })

  it("leaves Z alone where it is not the editor's: unmodified, with Alt, in a form field, while editing text", () => {
    pressKey({ key: 'Escape' })
    posted.mockClear()
    const plain = pressKey({ key: 'z' })
    const alt = pressKey({ key: 'z', metaKey: true, altKey: true })
    expect(lastPost('thallo:history')).toBeUndefined()
    expect(plain.defaultPrevented).toBe(false)
    expect(alt.defaultPrevented).toBe(false)

    // A theme's own input: native undo of what was typed there.
    const formW = wrapper('kb-undo-0002', '<section><input type="text"></section>')
    document.body.appendChild(formW)
    const typed = pressKey({ key: 'z', metaKey: true }, formW.querySelector('input')!)
    expect(lastPost('thallo:history')).toBeUndefined()
    expect(typed.defaultPrevented).toBe(false)

    // An edit session: ⌘Z undoes typing, natively. Undoing a block under the caret would be a disaster.
    const prose = proseWrapper('kb-undo-0003')
    document.body.appendChild(prose)
    selectByClick(prose)
    sendToBridge({ type: 'thallo:edit-grant', id: 'kb-undo-0003', field: 'body', kind: 'rich' })
    const region = prose.querySelector('.thallo-edit-region')!
    posted.mockClear()
    const editing = pressKey({ key: 'z', metaKey: true }, region)
    expect(lastPost('thallo:history')).toBeUndefined()
    expect(editing.defaultPrevented).toBe(false)
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
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
          left: 100,
          top: 200,
          width: 50,
          height: 20,
          bottom: 220,
          right: 150,
          x: 100,
          y: 200,
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

  it("carries the fetched page's style generation in the ack and writes it to <main>", async () => {
    try {
      liveStage()
      document.body.querySelector('main')!.setAttribute('data-thallo-epoch', 'E1')
      document.body.querySelector('main')!.setAttribute('data-thallo-revision', '1')
      document.body.querySelector('main')!.setAttribute('data-thallo-style-generation', '3')
      stubFetch(
        renderedHtml('Alpha v2', 'Beta v1').replace(
          '<main>',
          '<main data-thallo-epoch="E1" data-thallo-revision="2" data-thallo-style-generation="4">',
        ),
      )
      posted.mockClear()
      await refresh('r-gen')

      // A generation-only difference on <main> is bookkeeping, never shell drift.
      expect(acked()).toMatchObject({
        refresh_id: 'r-gen',
        mode: 'patched',
        revision: 2,
        style_generation: 4,
      })
      expect(
        document.body.querySelector('main')!.getAttribute('data-thallo-style-generation'),
      ).toBe('4')
    } finally {
      window.fetch = realFetch
    }
  })

  it('marks a slot that holds no wrapper as empty, at boot and again after a patch', async () => {
    try {
      liveStage()
      const main = document.body.querySelector('main')!
      const slot = document.createElement('div')
      slot.setAttribute('data-thallo-slot', 'content')
      main.append(slot)
      stubFetch(
        renderedHtml('Alpha v2', 'Beta v1').replace(
          '</main>',
          '<div data-thallo-slot="content"><div class="thallo-preview-block" data-thallo-block="pd-c-0000003"><p>c</p></div></div></main>',
        ),
      )
      posted.mockClear()
      await refresh('r-slot')
      // The fetched page's slot holds a wrapper: after the patch it is no longer marked empty.
      const after = document.body.querySelector('[data-thallo-slot="content"]')!
      expect(after.hasAttribute('data-thallo-slot-empty')).toBe(false)
    } finally {
      window.fetch = realFetch
    }
  })

  it('a root slot always ends in the placeholder; a slot inside a block carries one only while empty', async () => {
    try {
      liveStage()
      const main = document.body.querySelector('main')!
      const ownerHtml = (inner: string, rootInner = '') =>
        `<div class="thallo-preview-block" data-thallo-block="pd-o-0000009"><section><div data-thallo-slot="content">${inner}</div></section></div>` +
        `<div data-thallo-slot="body">${rootInner}</div>`
      main.insertAdjacentHTML('beforeend', ownerHtml(''))
      stubFetch(renderedHtml('Alpha v1', 'Beta v1').replace('</main>', ownerHtml('') + '</main>'))
      posted.mockClear()
      await refresh('r-ph')
      const inner = main.querySelector(
        '[data-thallo-block="pd-o-0000009"] [data-thallo-slot="content"]',
      )!
      const add = inner.querySelector<HTMLButtonElement>('.thallo-slot-placeholder [data-slot-add]')
      expect(add).not.toBeNull()
      expect(inner.textContent).toContain('Drag a block here')
      add!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(lastPost('thallo:slot-add')).toMatchObject({ parent: 'pd-o-0000009', slot: 'content' })
      // The click is the placeholder's, not a selection of the owning block.
      expect(lastPost('thallo:block-select')).toBeUndefined()
      // The root slot names no parent.
      main
        .querySelector<HTMLButtonElement>('[data-thallo-slot="body"] [data-slot-add]')!
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(lastPost('thallo:slot-add')).toMatchObject({ parent: null, slot: 'body' })
      // A slot that gains a wrapper on the next render has no placeholder and is not marked empty.
      stubFetch(
        renderedHtml('Alpha v1', 'Beta v1').replace(
          '</main>',
          ownerHtml(
            '<div class="thallo-preview-block" data-thallo-block="pd-n-0000010"><p>n</p></div>',
          ) + '</main>',
        ),
      )
      await refresh('r-ph2')
      const filled = main.querySelector(
        '[data-thallo-block="pd-o-0000009"] [data-thallo-slot="content"]',
      )!
      expect(filled.querySelector('[data-thallo-block="pd-n-0000010"]')).not.toBeNull()
      expect(filled.hasAttribute('data-thallo-slot-empty')).toBe(false)
      // A slot inside a block shows the placeholder only while empty: once it holds a block the
      // frame would sit after every nested block on the page. The page's own slot keeps it.
      expect(filled.querySelector('.thallo-slot-placeholder')).toBeNull()
      const nested =
        '<div class="thallo-preview-block" data-thallo-block="pd-n-0000010"><p>n</p></div>'
      const rootBlock =
        '<div class="thallo-preview-block" data-thallo-block="pd-r-0000011"><p>r</p></div>'
      // The live page gains the same root block (a mirror would), so the render patches in place.
      const liveRoot = main.querySelector('[data-thallo-slot="body"]')!
      liveRoot.insertBefore(wrapper('pd-r-0000011', '<p>r</p>'), liveRoot.firstChild)
      stubFetch(
        renderedHtml('Alpha v1', 'Beta v1').replace(
          '</main>',
          ownerHtml(nested, rootBlock) + '</main>',
        ),
      )
      await refresh('r-ph3')
      const root = main.querySelector('[data-thallo-slot="body"]')!
      expect(root.hasAttribute('data-thallo-slot-empty')).toBe(false)
      expect(root.lastElementChild!.classList.contains('thallo-slot-placeholder')).toBe(true)
      expect(root.querySelectorAll('.thallo-slot-placeholder')).toHaveLength(1)
    } finally {
      window.fetch = realFetch
    }
  })

  it('a block that paints nothing is marked empty with a label, and unmarked once it has content', async () => {
    try {
      liveStage()
      const main = document.body.querySelector('main')!
      const emptyHtml =
        '<div class="thallo-preview-block" data-thallo-block="pd-e-0000012"><div class="thallo-block thallo-block-feature"><div class="thallo-block-feature__body"></div></div></div>'
      main.insertAdjacentHTML('beforeend', emptyHtml)
      stubFetch(renderedHtml('Alpha v1', 'Beta v1').replace('</main>', emptyHtml + '</main>'))
      posted.mockClear()
      await refresh('r-empty')
      const w = main.querySelector('[data-thallo-block="pd-e-0000012"]')!
      expect(w.hasAttribute('data-thallo-block-empty')).toBe(true)
      expect(w.firstElementChild!.getAttribute('data-thallo-empty-label')).toBe('Empty feature')
      // A block with text, and a container whose slot holds a placeholder, are not empty.
      expect(
        main
          .querySelector('[data-thallo-block="pd-a-0000001"]')!
          .hasAttribute('data-thallo-block-empty'),
      ).toBe(false)
      // A block that paints with CSS alone (a separator's line, a spacer's height) has a box:
      // it is never empty, whatever its DOM holds.
      const sepHtml =
        '<div class="thallo-preview-block" data-thallo-block="pd-s-0000013"><div class="thallo-block thallo-block-separator"><span class="thallo-block-separator__line"></span></div></div>'
      main.insertAdjacentHTML('beforeend', sepHtml)
      const sepHost = main.querySelector<HTMLElement>(
        '[data-thallo-block="pd-s-0000013"] > .thallo-block',
      )!
      sepHost.getBoundingClientRect = () =>
        ({
          width: 800,
          height: 1,
          top: 0,
          left: 0,
          right: 800,
          bottom: 1,
          x: 0,
          y: 0,
          toJSON: () => ({}),
        }) as DOMRect
      stubFetch(
        renderedHtml('Alpha v1', 'Beta v1').replace('</main>', emptyHtml + sepHtml + '</main>'),
      )
      await refresh('r-empty-sep')
      expect(
        main
          .querySelector('[data-thallo-block="pd-s-0000013"]')!
          .hasAttribute('data-thallo-block-empty'),
      ).toBe(false)
      expect(
        main
          .querySelector('[data-thallo-block="pd-e-0000012"]')!
          .hasAttribute('data-thallo-block-empty'),
      ).toBe(true)
      const filledHtml =
        '<div class="thallo-preview-block" data-thallo-block="pd-e-0000012"><div class="thallo-block thallo-block-feature"><div class="thallo-block-feature__body"><h3>Now titled</h3></div></div></div>'
      stubFetch(
        renderedHtml('Alpha v1', 'Beta v1').replace('</main>', filledHtml + sepHtml + '</main>'),
      )
      await refresh('r-empty2')
      const filled = main.querySelector('[data-thallo-block="pd-e-0000012"]')!
      expect(filled.hasAttribute('data-thallo-block-empty')).toBe(false)
      expect(filled.firstElementChild!.hasAttribute('data-thallo-empty-label')).toBe(false)
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

describe('fragment swaps (visual builder spec §3.5)', () => {
  const page = (epoch: string, revision: number, inner: string) =>
    `<main data-thallo-epoch="${epoch}" data-thallo-revision="${revision}">${inner}</main>`
  const frag = (id: string, inner: string) =>
    `<div class="thallo-preview-block" data-thallo-block="${id}">${inner}</div>`

  /**
   * The stage learns its displayed pair from a real refresh: stub the fetch with the same
   * body. The evaluated bridge keeps its pair across tests, so every test uses its own epoch.
   */
  async function establish(epoch: string, inner: string): Promise<void> {
    document.body.innerHTML = page(epoch, 1, inner)
    const html = `<!doctype html><html><body>${page(epoch, 1, inner)}</body></html>`
    window.fetch = vi.fn().mockResolvedValue({
      ok: true,
      redirected: false,
      text: () => Promise.resolve(html),
    }) as unknown as typeof window.fetch
    sendToBridge({ type: 'thallo:stage-refresh', refresh_id: 'establish' })
    await new Promise((resolve) => setTimeout(resolve, 0))
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(lastPost('thallo:stage-refreshed')).toMatchObject({
      refresh_id: 'establish',
      mode: 'patched',
      epoch,
      revision: 1,
    })
    posted.mockClear()
  }

  function fragments(epoch: string, data: Record<string, unknown>): void {
    sendToBridge({
      type: 'thallo:fragments',
      refresh_id: 'frag-1',
      epoch,
      revision: 2,
      baseline_epoch: epoch,
      baseline_revision: 1,
      ...data,
    })
  }

  it('a fragment patch carries the style generation and writes it to <main>', async () => {
    await establish('GEN', frag('g-a-00000001', '<p>one</p>'))
    fragments('GEN', {
      style_generation: 9,
      fragments: { 'g-a-00000001': frag('g-a-00000001', '<p>two</p>') },
    })
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(lastPost('thallo:stage-refreshed')).toMatchObject({
      mode: 'patched',
      revision: 2,
      style_generation: 9,
    })
    expect(document.querySelector('main')!.getAttribute('data-thallo-style-generation')).toBe('9')
  })

  it('a whole-page patch and a fragment swap both hand the new wrapper to the theme runtime', async () => {
    const enhanced: string[] = []
    ;(window as unknown as { ThalloRuntime: unknown }).ThalloRuntime = {
      enhance: (el: Element) => enhanced.push(el.getAttribute('data-thallo-block') ?? '?'),
    }
    try {
      // The whole-page path: a changed wrapper is replaced by the fetched one and enhanced.
      await establish('ee', frag('ra000000001', '<p>old</p>') + frag('rb000000001', 'b'))
      const html = `<!doctype html><html><body>${page('ee', 2, frag('ra000000001', '<p>new</p>') + frag('rb000000001', 'b'))}</body></html>`
      window.fetch = vi.fn().mockResolvedValue({
        ok: true,
        redirected: false,
        text: () => Promise.resolve(html),
      }) as unknown as typeof window.fetch
      sendToBridge({ type: 'thallo:stage-refresh', refresh_id: 'page-patch' })
      await new Promise((resolve) => setTimeout(resolve, 0))
      await new Promise((resolve) => setTimeout(resolve, 0))
      expect(lastPost('thallo:stage-refreshed')).toMatchObject({
        refresh_id: 'page-patch',
        mode: 'patched',
      })
      expect(enhanced).toEqual(['ra000000001'])
      // The fragment path: the same hand-over.
      fragments('ee', {
        revision: 3,
        baseline_revision: 2,
        fragments: { rb000000001: frag('rb000000001', 'b2') },
      })
      expect(enhanced).toEqual(['ra000000001', 'rb000000001'])
    } finally {
      delete (window as unknown as { ThalloRuntime?: unknown }).ThalloRuntime
    }
  })

  it('swaps every validated root, advances the displayed pair and acks patched', async () => {
    await establish('ea', frag('fa000000001', '<p>old a</p>') + frag('fb000000001', '<p>old b</p>'))
    fragments('ea', {
      fragments: {
        fa000000001: frag('fa000000001', '<p>new a</p>'),
        fb000000001: frag('fb000000001', '<p>new b</p>'),
      },
    })
    expect(document.querySelector('[data-thallo-block="fa000000001"]')!.textContent).toBe('new a')
    expect(document.querySelector('[data-thallo-block="fb000000001"]')!.textContent).toBe('new b')
    expect(lastPost('thallo:stage-refreshed')).toMatchObject({
      refresh_id: 'frag-1',
      mode: 'patched',
      detail: 'fragments:2',
      epoch: 'ea',
      revision: 2,
    })
    expect(document.querySelector('main')!.getAttribute('data-thallo-revision')).toBe('2')

    // The displayed pair moved on: the same patch is now behind it, and one that names the
    // old baseline is refused.
    posted.mockClear()
    fragments('ea', { baseline_revision: 2, fragments: { fa000000001: frag('fa000000001', 'x') } })
    expect(lastPost('thallo:stage-refreshed')).toMatchObject({
      refresh_id: 'frag-1',
      mode: 'stale',
    })
    fragments('ea', { revision: 3, fragments: { fa000000001: frag('fa000000001', 'x') } })
    expect(lastPost('thallo:fragments-failed')).toMatchObject({
      refresh_id: 'frag-1',
      reason: 'baseline',
    })
    expect(document.querySelector('[data-thallo-block="fa000000001"]')!.textContent).toBe('new a')
  })

  it('refuses an obsolete epoch, a missing target, foreign or malformed markup and nested targets', async () => {
    await establish(
      'eb',
      frag('ga000000001', frag('gi000000001', '<p>inner</p>')) + frag('gb000000001', 'b'),
    )
    fragments('eb', { epoch: 'e0', fragments: { ga000000001: frag('ga000000001', 'x') } })
    expect(lastPost('thallo:fragments-failed')).toMatchObject({ reason: 'epoch' })
    fragments('eb', { fragments: { gz000000001: frag('gz000000001', 'x') } })
    expect(lastPost('thallo:fragments-failed')).toMatchObject({ reason: 'target' })
    fragments('eb', { fragments: { ga000000001: frag('gb000000001', 'x') } })
    expect(lastPost('thallo:fragments-failed')).toMatchObject({ reason: 'markup' })
    fragments('eb', { fragments: { ga000000001: '<p>one</p><p>two</p>' } })
    expect(lastPost('thallo:fragments-failed')).toMatchObject({ reason: 'markup' })
    fragments('eb', { fragments: {} })
    expect(lastPost('thallo:fragments-failed')).toMatchObject({ reason: 'empty' })
    fragments('eb', {
      fragments: { ga000000001: frag('ga000000001', 'x'), gi000000001: frag('gi000000001', 'y') },
    })
    expect(lastPost('thallo:fragments-failed')).toMatchObject({ reason: 'overlap' })
    // Nothing moved.
    expect(document.querySelector('[data-thallo-block="gi000000001"]')!.textContent).toBe('inner')
    expect(document.querySelector('main')!.getAttribute('data-thallo-revision')).toBe('1')
    expect(lastPost('thallo:stage-refreshed')).toBeUndefined()
  })

  it('re-anchors the selection on the swapped wrapper, or deselects honestly when it vanished', async () => {
    await establish(
      'ec',
      frag('ha000000001', frag('hi000000001', '<a href="/x">inner</a>')) + frag('hb000000001', 'b'),
    )
    document
      .querySelector('[data-thallo-block="ha000000001"] a')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'hi000000001' })
    posted.mockClear()
    // The parent root re-renders without the nested block: the selection is gone.
    fragments('ec', { fragments: { ha000000001: frag('ha000000001', '<p>flattened</p>') } })
    expect(lastPost('thallo:block-deselect')).toMatchObject({ id: 'hi000000001' })
    expect(lastPost('thallo:stage-refreshed')).toMatchObject({ mode: 'patched', revision: 2 })

    // Select the second root, then swap it: the new wrapper carries the selection.
    document
      .querySelector('[data-thallo-block="hb000000001"]')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true }))
    fragments('ec', {
      revision: 3,
      baseline_revision: 2,
      fragments: { hb000000001: frag('hb000000001', '<p>b2</p>') },
    })
    const swapped = document.querySelector('[data-thallo-block="hb000000001"]')!
    expect(swapped.textContent).toBe('b2')
    expect(swapped.classList.contains('thallo-canvas-selected')).toBe(true)
  })
})

describe('the structure picker on the stage (container-layout spec §6.2)', () => {
  /** A container wrapper with an empty content slot, the shape an offer is made for. */
  function offeredContainer(id: string): HTMLElement {
    document.body.innerHTML = '<main></main>'
    const main = document.body.querySelector('main')!
    const el = document.createElement('div')
    el.className = 'thallo-preview-block'
    el.setAttribute('data-thallo-block', id)
    el.innerHTML = `<section><div data-thallo-slot="content"></div></section>`
    main.append(el)
    return el
  }
  const offer = (id: string, presets: Record<string, unknown>[]) =>
    sendToBridge({ type: 'thallo:structure-offer', offers: [{ id, presets }] })
  const tilesIn = (el: HTMLElement) => el.querySelector('.thallo-structure-picker')

  beforeEach(() => posted.mockClear())

  it('replaces the ordinary placeholder with the tiles, and puts it back when the offer ends', () => {
    const el = offeredContainer('pk-a-0000001')
    // Before any offer the slot carries the plain placeholder.
    sendToBridge({ type: 'thallo:structure-offer', offers: [] })
    expect(el.querySelector('.thallo-slot-placeholder')).not.toBeNull()
    expect(tilesIn(el)).toBeNull()

    offer('pk-a-0000001', [
      { key: 'stack', label: 'Stack', enabled: true },
      { key: 'cols-50-50', label: 'Two columns', enabled: true },
    ])
    const picker = tilesIn(el)!
    expect(picker).not.toBeNull()
    expect(picker.querySelectorAll('[data-structure-tile]')).toHaveLength(2)
    expect(picker.querySelector('[data-slot-add]')).toBeNull()

    // The complete list is republished without this offer: the tiles come off and the ordinary
    // placeholder returns, with no second message type needed.
    sendToBridge({ type: 'thallo:structure-offer', offers: [] })
    expect(tilesIn(el)).toBeNull()
    expect(el.querySelector('.thallo-slot-placeholder [data-slot-add]')).not.toBeNull()
  })

  it('shows a disabled tile with its reason, and clicking it posts nothing', () => {
    const el = offeredContainer('pk-b-0000002')
    offer('pk-b-0000002', [
      { key: 'stack', label: 'Stack', enabled: true },
      {
        key: 'section',
        label: 'Section',
        enabled: false,
        reason: 'Would nest deeper than 5 levels',
      },
    ])
    const disabled = el.querySelector<HTMLButtonElement>('[data-structure-tile="section"]')!
    expect(disabled.disabled).toBe(true)
    expect(disabled.title).toContain('5 levels')

    disabled.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(lastPost('thallo:structure-choose')).toBeUndefined()
  })

  it('a tile click posts the choice and never a selection', () => {
    const el = offeredContainer('pk-c-0000003')
    offer('pk-c-0000003', [{ key: 'cols-33-67', label: '33 / 67', enabled: true }])
    el.querySelector<HTMLButtonElement>('[data-structure-tile="cols-33-67"]')!.dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    )
    expect(lastPost('thallo:structure-choose')).toMatchObject({
      id: 'pk-c-0000003',
      preset: 'cols-33-67',
    })
    // Choosing must not take the inspector away from the choice being made.
    expect(lastPost('thallo:block-select')).toBeUndefined()
  })

  it('Skip posts a skip, also without selecting', () => {
    const el = offeredContainer('pk-d-0000004')
    offer('pk-d-0000004', [{ key: 'stack', label: 'Stack', enabled: true }])
    el.querySelector<HTMLButtonElement>('[data-structure-skip]')!.dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    )
    expect(lastPost('thallo:structure-skip')).toMatchObject({ id: 'pk-d-0000004' })
    expect(lastPost('thallo:block-select')).toBeUndefined()
  })

  it('a container that already holds a block shows no tiles', () => {
    const el = offeredContainer('pk-e-0000005')
    el.querySelector('[data-thallo-slot="content"]')!.innerHTML =
      '<div class="thallo-preview-block" data-thallo-block="pk-child-001"><p>x</p></div>'
    offer('pk-e-0000005', [{ key: 'stack', label: 'Stack', enabled: true }])
    expect(tilesIn(el)).toBeNull()
  })

  it('offers only the named container, and drops a malformed message', () => {
    document.body.innerHTML = '<main></main>'
    const main = document.body.querySelector('main')!
    for (const id of ['pk-f-0000006', 'pk-g-0000007']) {
      const el = document.createElement('div')
      el.className = 'thallo-preview-block'
      el.setAttribute('data-thallo-block', id)
      el.innerHTML = '<section><div data-thallo-slot="content"></div></section>'
      main.append(el)
    }
    offer('pk-f-0000006', [{ key: 'stack', label: 'Stack', enabled: true }])
    expect(tilesIn(main.children[0] as HTMLElement)).not.toBeNull()
    expect(tilesIn(main.children[1] as HTMLElement)).toBeNull()

    // A message with no offers array changes nothing rather than clearing the stage.
    sendToBridge({ type: 'thallo:structure-offer' })
    expect(tilesIn(main.children[0] as HTMLElement)).not.toBeNull()
  })
})

describe('the grid on the stage (container-layout spec §11.2)', () => {
  // jsdom lays nothing out, so the slot's resolved tracks and every rectangle are stubbed; the
  // real geometry — inert and aligned — is proven in the browser (admin/e2e grid-outline.spec).
  const realComputed = window.getComputedStyle
  let styles = new Map<Element, Record<string, string>>()

  beforeEach(() => {
    styles = new Map()
    window.getComputedStyle = ((el: Element) => {
      const own = styles.get(el)
      if (!own) return realComputed(el)
      return {
        ...own,
        getPropertyValue: (p: string) => own[p] ?? '',
      } as unknown as CSSStyleDeclaration
    }) as typeof window.getComputedStyle
  })
  afterEach(() => {
    window.getComputedStyle = realComputed
  })

  const rectOf = (left: number, top: number, width: number, height: number) =>
    ({
      left,
      top,
      width,
      height,
      right: left + width,
      bottom: top + height,
      x: left,
      y: top,
    }) as DOMRect
  const pin = (el: Element, r: DOMRect) =>
    Object.defineProperty(el, 'getBoundingClientRect', { configurable: true, value: () => r })

  /** A container whose content slot is a grid of `cols`, 40px rows, 10px gaps, at (0, 0). */
  function grid(
    id: string,
    cols: number[],
    children: { id: string; col: number; span?: number; row?: number }[],
  ) {
    document.body.innerHTML = '<main></main>'
    const main = document.body.querySelector('main')!
    const el = document.createElement('div')
    el.className = 'thallo-preview-block'
    el.setAttribute('data-thallo-block', id)
    el.innerHTML = '<section><div data-thallo-slot="content"></div></section>'
    main.append(el)
    const slot = el.querySelector<HTMLElement>('[data-thallo-slot]')!
    const rows = Math.max(1, ...children.map((c) => (c.row ?? 0) + 1))
    const x = (col: number) => cols.slice(0, col).reduce((a, b) => a + b + 10, 0)
    for (const c of children) {
      const child = wrapper(c.id, '<h2>child</h2>')
      slot.append(child)
      const span = c.span ?? 1
      const width = cols.slice(c.col, c.col + span).reduce((a, b) => a + b, 0) + (span - 1) * 10
      pin(child.firstElementChild!, rectOf(x(c.col), (c.row ?? 0) * 50, width, 40))
    }
    const total = cols.reduce((a, b) => a + b, 0) + (cols.length - 1) * 10
    pin(slot, rectOf(0, 0, total, rows * 40 + (rows - 1) * 10))
    styles.set(slot, {
      display: 'grid',
      gridTemplateColumns: cols.map((c) => `${c}px`).join(' '),
      gridTemplateRows: Array(rows).fill('40px').join(' '),
      columnGap: '10px',
      rowGap: '10px',
      paddingLeft: '0px',
      paddingTop: '0px',
      borderLeftWidth: '0px',
      borderTopWidth: '0px',
    })
    return { el, slot }
  }
  const remark = () => sendToBridge({ type: 'thallo:structure-offer', offers: [] })
  const select = (id: string) => sendToBridge({ type: 'thallo:highlight', id, ids: [id] })
  const layer = () => document.querySelector<HTMLElement>('.thallo-grid-outline')
  const cells = () =>
    [...document.querySelectorAll<HTMLElement>('.thallo-grid-outline__cell')].map((c) => ({
      free: c.classList.contains('thallo-grid-outline__cell--free'),
      width: c.style.width,
      height: c.style.height,
      at: c.style.transform,
    }))

  it('an empty grid is outlined track by track, with its placeholder marked to take one cell', () => {
    const { slot } = grid('go-a-0000001', [100, 100, 100], [])
    remark()
    expect(slot.hasAttribute('data-thallo-slot-grid')).toBe(true)
    expect(slot.querySelector(':scope > .thallo-slot-placeholder')).not.toBeNull()
    expect(cells()).toEqual([
      { free: true, width: '100px', height: '40px', at: 'translate(0px, 0px)' },
      { free: true, width: '100px', height: '40px', at: 'translate(110px, 0px)' },
      { free: true, width: '100px', height: '40px', at: 'translate(220px, 0px)' },
    ])
  })

  it("the layer is the body's, never inside a slot, so no structural rule can see it", () => {
    grid('go-b-0000002', [100, 100], [])
    remark()
    expect(layer()!.parentElement).toBe(document.body)
    expect(layer()!.closest('[data-thallo-slot]')).toBeNull()
    expect(document.querySelectorAll('.thallo-grid-outline')).toHaveLength(1)
  })

  it('a populated grid is outlined only while it or one of its children is selected', () => {
    const { slot } = grid('go-c-0000003', [100, 100, 100], [{ id: 'go-c-child-01', col: 0 }])
    remark()
    // Populated and nothing selected: no outline, and — as ever — no placeholder.
    expect(layer()).toBeNull()
    expect(slot.querySelector(':scope > .thallo-slot-placeholder')).toBeNull()

    select('go-c-child-01') // a child
    expect(cells().map((c) => c.free)).toEqual([false, true, true])
    expect(slot.querySelector(':scope > .thallo-slot-placeholder')).toBeNull()

    select('go-c-0000003') // the container itself
    expect(cells()).toHaveLength(3)

    sendToBridge({ type: 'thallo:highlight', id: '', ids: [] })
    expect(layer()).toBeNull()
  })

  it('a full row is outlined and nothing is added to it', () => {
    const { slot } = grid(
      'go-d-0000004',
      [100, 100],
      [
        { id: 'go-d-child-01', col: 0 },
        { id: 'go-d-child-02', col: 1 },
      ],
    )
    select('go-d-0000004')
    expect(cells().map((c) => c.free)).toEqual([false, false])
    expect(slot.children).toHaveLength(2)
  })

  it('a child spanning two of three tracks is one cell across both, and one stays free', () => {
    grid('go-e-0000005', [100, 100, 100], [{ id: 'go-e-child-01', col: 0, span: 2 }])
    select('go-e-0000005')
    expect(cells()).toEqual([
      { free: false, width: '210px', height: '40px', at: 'translate(0px, 0px)' },
      { free: true, width: '100px', height: '40px', at: 'translate(220px, 0px)' },
    ])
  })

  it('outlines the proportions of an asymmetric split', () => {
    grid('go-f-0000006', [75, 150, 75], [])
    remark()
    expect(cells().map((c) => c.width)).toEqual(['75px', '150px', '75px'])
  })

  it('draws every row of a grid that has wrapped', () => {
    grid(
      'go-g-0000007',
      [100, 100],
      [
        { id: 'go-g-child-01', col: 0, row: 0 },
        { id: 'go-g-child-02', col: 1, row: 0 },
        { id: 'go-g-child-03', col: 0, row: 1 },
      ],
    )
    select('go-g-0000007')
    expect(cells().map((c) => [c.free, c.at])).toEqual([
      [false, 'translate(0px, 0px)'],
      [false, 'translate(110px, 0px)'],
      [false, 'translate(0px, 50px)'],
      [true, 'translate(110px, 50px)'],
    ])
  })

  it('a flex slot gets no outline and keeps the full-row placeholder', () => {
    const { slot } = grid('go-h-0000008', [300], [])
    styles.set(slot, { ...styles.get(slot)!, display: 'flex' })
    remark()
    expect(layer()).toBeNull()
    expect(slot.hasAttribute('data-thallo-slot-grid')).toBe(false)
    expect(slot.querySelector(':scope > .thallo-slot-placeholder')).not.toBeNull()
  })

  it('follows the slot when the mode changes under it', () => {
    const { slot } = grid('go-i-0000009', [100, 100], [])
    remark()
    expect(cells()).toHaveLength(2)
    styles.set(slot, { ...styles.get(slot)!, display: 'flex' })
    remark()
    expect(layer()).toBeNull()
    expect(slot.hasAttribute('data-thallo-slot-grid')).toBe(false)
  })

  it('takes no part in the markup the bridge serialises or the slots it marks', () => {
    const { slot } = grid('go-j-0000010', [100, 100], [])
    remark()
    // Still empty by the bridge's own reckoning, with the outline drawn.
    expect(slot.hasAttribute('data-thallo-slot-empty')).toBe(true)
    expect(layer()!.getAttribute('style')).toBeNull() // CSP pin: appearance is preview.css's
    expect(layer()!.getAttribute('aria-hidden')).toBe('true')
  })
})

describe('Fill empty cells on the stage (container-layout spec §11.3)', () => {
  // The button has no state of its own: the parent publishes the complete list, built from the
  // same availability the inspector's button reads, and the stage draws only what is in it — so it
  // can never offer what the inspector refuses.
  function emptyContainer(id: string, inner = ''): HTMLElement {
    document.body.innerHTML = '<main></main>'
    const el = document.createElement('div')
    el.className = 'thallo-preview-block'
    el.setAttribute('data-thallo-block', id)
    el.innerHTML = `<section><div data-thallo-slot="content">${inner}</div></section>`
    document.body.querySelector('main')!.append(el)
    return el
  }
  const publish = (states: Record<string, unknown>[]) =>
    sendToBridge({ type: 'thallo:grid-fill-state', states })
  const button = (el: HTMLElement) => el.querySelector<HTMLButtonElement>('[data-grid-fill]')

  beforeEach(() => posted.mockClear())

  it('draws no button until the parent publishes one for the container', () => {
    const el = emptyContainer('gf-a-0000001')
    publish([])
    expect(el.querySelector('.thallo-slot-placeholder')).not.toBeNull()
    expect(button(el)).toBeNull()
  })

  it('an enabled entry draws a button that asks the parent to fill, and selects nothing', () => {
    const el = emptyContainer('gf-b-0000002')
    publish([{ id: 'gf-b-0000002', enabled: true, preparing: false }])
    const fill = button(el)!
    expect(fill).not.toBeNull()
    expect(fill.disabled).toBe(false)
    expect(fill.textContent).toContain('Fill empty cells')
    fill.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(lastPost('thallo:grid-fill')).toMatchObject({ id: 'gf-b-0000002' })
    expect(lastPost('thallo:block-select')).toBeUndefined()
    expect(lastPost('thallo:slot-add')).toBeUndefined()
  })

  it('a disabled entry shows its reason and posts nothing', () => {
    const el = emptyContainer('gf-c-0000003')
    publish([
      {
        id: 'gf-c-0000003',
        enabled: false,
        preparing: false,
        reason: 'A cell here could not hold a block: blocks nest at most 5 levels deep',
      },
    ])
    const fill = button(el)!
    expect(fill.disabled).toBe(true)
    expect(fill.getAttribute('title')).toContain('could not hold a block')
    fill.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(lastPost('thallo:grid-fill')).toBeUndefined()
  })

  it('a preparing entry is busy and posts nothing', () => {
    const el = emptyContainer('gf-d-0000004')
    publish([{ id: 'gf-d-0000004', enabled: true, preparing: true }])
    const fill = button(el)!
    expect(fill.disabled).toBe(true)
    expect(fill.getAttribute('aria-busy')).toBe('true')
    fill.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(lastPost('thallo:grid-fill')).toBeUndefined()
  })

  it('follows the list: a later one without the container removes the button, and a changed entry redraws it', () => {
    const el = emptyContainer('gf-e-0000005')
    publish([{ id: 'gf-e-0000005', enabled: true, preparing: false }])
    expect(button(el)!.disabled).toBe(false)
    publish([
      {
        id: 'gf-e-0000005',
        enabled: false,
        preparing: false,
        reason: 'No empty cells in the last row',
      },
    ])
    expect(button(el)!.disabled).toBe(true)
    expect(el.querySelectorAll('[data-grid-fill]')).toHaveLength(1)
    publish([])
    expect(button(el)).toBeNull()
    expect(el.querySelector('.thallo-slot-placeholder [data-slot-add]')).not.toBeNull()
  })

  it('draws nothing for a container that is not empty: there is no placeholder to carry it', () => {
    const el = emptyContainer(
      'gf-f-0000006',
      '<div class="thallo-preview-block" data-thallo-block="gf-f-child-01"><h2>x</h2></div>',
    )
    publish([{ id: 'gf-f-0000006', enabled: true, preparing: false }])
    expect(button(el)).toBeNull()
  })

  it('ignores malformed entries', () => {
    const el = emptyContainer('gf-g-0000007')
    publish([{ enabled: true }, null as unknown as Record<string, unknown>, { id: 7 }])
    expect(button(el)).toBeNull()
  })

  it('motion-play replays a block’s entrance: from its starting state, then released, then cleaned up', () => {
    vi.useFakeTimers()
    try {
      document.body.innerHTML = ''
      const w = wrapper(
        'motion000001',
        '<h2 class="thallo-block t-enter-fade-up">Title</h2><p class="t-enter-none">Still</p>',
      )
      const other = wrapper('motion000002', '<h2 class="thallo-block t-enter-fade">Other</h2>')
      document.body.append(w, other)
      const title = w.querySelector('h2')!

      sendToBridge({ type: 'thallo:motion-play', id: 'motion000001' })
      // Put at its starting state and released in one go (a forced reflow between the two is what
      // makes the browser animate): what is left to see is the released state.
      expect(title.getAttribute('data-thallo-motion-play')).toBe('to')
      // `none` names no entrance, and another block is not this one.
      expect(w.querySelector('p')!.hasAttribute('data-thallo-motion-play')).toBe(false)
      expect(other.querySelector('h2')!.hasAttribute('data-thallo-motion-play')).toBe(false)

      vi.advanceTimersByTime(5000)
      expect(title.hasAttribute('data-thallo-motion-play')).toBe(false)
    } finally {
      vi.useRealTimers()
    }
  })

  it('motion-play lets a Ken Burns frame run for a while, then stills it again', () => {
    vi.useFakeTimers()
    try {
      document.body.innerHTML = ''
      const w = wrapper(
        'motion000003',
        '<div class="thallo-block t-kenburns-zoom-in"><img alt=""></div>',
      )
      document.body.append(w)
      const frame = w.querySelector('div')!
      sendToBridge({ type: 'thallo:motion-play', id: 'motion000003' })
      expect(frame.hasAttribute('data-thallo-motion-play')).toBe(true)
      vi.advanceTimersByTime(6000)
      expect(
        frame.hasAttribute('data-thallo-motion-play'),
        'a drift is slow: it plays longer',
      ).toBe(true)
      vi.advanceTimersByTime(2500)
      expect(frame.hasAttribute('data-thallo-motion-play')).toBe(false)
    } finally {
      vi.useRealTimers()
    }
  })
})

// The header & footer stage (regions-stage spec §5.4): with <html data-thallo-canvas="regions">,
// only the header and footer are the stage's; the page body between them is inert — no navigation,
// no submission, no keyboard activation, no selection — while scrolling is untouched.
describe('region-only mode', () => {
  beforeEach(() => {
    document.documentElement.setAttribute('data-thallo-canvas', 'regions')
    document.body.innerHTML = `
      <header><div data-thallo-slot="header" id="ro-header-slot"></div></header>
      <main id="ro-main">
        <a href="/elsewhere" id="ro-body-link">a body link</a>
        <button type="button" id="ro-body-button">a body button</button>
        <form id="ro-body-form" action="/submit"><input name="q"></form>
      </main>`
    document
      .getElementById('ro-header-slot')!
      .appendChild(
        wrapper('ro-hdr-00001', '<p><a href="/h" id="ro-header-link">header link</a></p>'),
      )
  })
  afterEach(() => {
    document.documentElement.removeAttribute('data-thallo-canvas')
  })

  function fire(el: Element, event: Event): Event {
    el.dispatchEvent(event)
    return event
  }

  it('a click on a body link navigates nowhere and selects nothing', () => {
    const click = fire(
      document.getElementById('ro-body-link')!,
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    )
    expect(click.defaultPrevented).toBe(true)
    expect(lastPost('thallo:block-select')).toBeUndefined()
  })

  it('a body form does not submit', () => {
    const submit = fire(
      document.getElementById('ro-body-form')!,
      new Event('submit', { bubbles: true, cancelable: true }),
    )
    expect(submit.defaultPrevented).toBe(true)
  })

  it('Enter on a body link and Space on a body button are inert; Enter in the header is not', () => {
    const enter = fire(
      document.getElementById('ro-body-link')!,
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
    )
    const space = fire(
      document.getElementById('ro-body-button')!,
      new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true }),
    )
    expect(enter.defaultPrevented).toBe(true)
    expect(space.defaultPrevented).toBe(true)

    const inHeader = fire(
      document.getElementById('ro-header-link')!,
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
    )
    expect(inHeader.defaultPrevented).toBe(false)
  })

  it('a wheel over the body still scrolls', () => {
    const wheel = fire(
      document.getElementById('ro-main')!,
      new WheelEvent('wheel', { bubbles: true, cancelable: true, deltaY: 40 }),
    )
    expect(wheel.defaultPrevented).toBe(false)
  })

  it('a header block still selects', () => {
    document
      .getElementById('ro-header-link')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'ro-hdr-00001' })
  })

  it('on the Design view stage the body is the stage’s as before', () => {
    document.documentElement.setAttribute('data-thallo-canvas', 'entry')
    const click = fire(
      document.getElementById('ro-body-link')!,
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    )
    // Outside any block wrapper the entry stage lets a click through, as it always has.
    expect(click.defaultPrevented).toBe(false)
  })

  it('the stage’s own controls outside the regions still work: the format bar and its link panel', () => {
    const bar = document.createElement('div')
    bar.className = 'thallo-canvas-format-bar'
    bar.innerHTML =
      '<button type="button" id="ro-bold">B</button>' +
      '<div class="thallo-canvas-link-panel"><input id="ro-url" /></div>'
    document.body.appendChild(bar)
    const clicked = vi.fn()
    document.getElementById('ro-bold')!.addEventListener('click', clicked)
    const click = fire(
      document.getElementById('ro-bold')!,
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    )
    expect(clicked).toHaveBeenCalledTimes(1)
    expect(click.defaultPrevented).toBe(false)
    const enter = fire(
      document.getElementById('ro-url')!,
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
    )
    const space = fire(
      document.getElementById('ro-url')!,
      new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true }),
    )
    expect(enter.defaultPrevented).toBe(false)
    expect(space.defaultPrevented).toBe(false)
    bar.remove()
  })

  it('with a header block selected, Enter on the page asks to edit it; Space on the page is not cancelled', () => {
    const slot = document.getElementById('ro-header-slot')!
    const prose = wrapper(
      'ro-hdr-00002',
      '<section><div class="thallo-edit-region" data-thallo-edit-block="ro-hdr-00002" ' +
        'data-thallo-edit-field="body"><p>header note</p></div></section>',
    )
    slot.appendChild(prose)
    prose.querySelector('section')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'ro-hdr-00002' })
    posted.mockClear()
    // Selection moves no focus: the key arrives on the body.
    fire(
      document.body,
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
    )
    expect(lastPost('thallo:edit-request')).toMatchObject({ id: 'ro-hdr-00002', field: 'body' })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    const space = fire(
      document.body,
      new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true }),
    )
    expect(space.defaultPrevented).toBe(false)
  })
})
