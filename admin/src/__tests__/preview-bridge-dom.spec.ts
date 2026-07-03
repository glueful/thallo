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

  it('void-element blocks (hr dividers) get the toolbar via a positioned shim', () => {
    // Children of void elements (hr, img, …) never RENDER — inserting the
    // toolbar inside them makes it invisible. The bridge attaches a
    // bridge-owned shim sibling instead.
    const w = wrapper('void-a-00001', '<hr class="lemma-block-divider">')
    document.body.appendChild(w)
    w.querySelector('hr')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(lastPost('lemma:block-select')).toMatchObject({ id: 'void-a-00001' })

    const shim = w.querySelector('.lemma-canvas-shim')!
    expect(shim).not.toBeNull()
    expect(shim.previousElementSibling!.tagName).toBe('HR')
    expect(shim.classList.contains('lemma-canvas-anchor')).toBe(true)
    expect(shim.querySelector('.lemma-canvas-toolbar')).not.toBeNull()
    // The hr itself carries NO children and no anchor class.
    expect(w.querySelector('hr')!.childNodes).toHaveLength(0)

    // Deselecting (selecting elsewhere) removes the shim entirely.
    const other = wrapper('void-b-00001')
    document.body.appendChild(other)
    other.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(w.querySelector('.lemma-canvas-shim')).toBeNull()
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
    const del = lastPost('lemma:block-delete-request')!
    expect(del).toMatchObject({ id: 'blk-int-0001' })
    // The delete button's rect rides along so the parent anchors its confirm.
    expect(del.rect).toMatchObject({ x: expect.any(Number), y: expect.any(Number) })
    click('add-after')
    const addAfter = lastPost('lemma:block-add-after')!
    expect(addAfter).toMatchObject({ id: 'blk-int-0001' })
    // The + button's rect rides along so the parent can anchor its picker.
    expect(addAfter.rect).toMatchObject({ x: expect.any(Number), y: expect.any(Number) })
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
        data: {
          type: 'lemma:mirror-move',
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
          type: 'lemma:mirror-move',
          id: 'sec-b-000002',
          beforeId: 'sec-a-000001',
          nonce: NONCE,
        },
        origin: 'https://evil.test',
      }),
    )
    expect([...list.children].map((el) => el.getAttribute('data-lemma-block'))).toEqual([
      'sec-a-000001',
      'sec-b-000002',
    ])
  })
})

function proseWrapper(id: string, field = 'body', html = '<p>hello</p>'): HTMLElement {
  return wrapper(
    id,
    `<section><div class="lemma-edit-region" data-lemma-edit-block="${id}" ` +
      `data-lemma-edit-field="${field}">${html}</div></section>`,
  )
}

describe('edit-in-place session', () => {
  it('double-click posts edit-request; grant enables contenteditable on the ONE region', () => {
    const w = proseWrapper('eip-a-000001')
    document.body.appendChild(w)
    w.querySelector('p')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'eip-a-000001', field: 'body' })

    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-a-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.lemma-edit-region')!
    expect(region.getAttribute('contenteditable')).toBe('true')
    expect(region.classList.contains('lemma-canvas-editing')).toBe(true)
    // Toolbar detached for the duration (block may have been selected before).
    expect(w.querySelector('.lemma-canvas-toolbar')).toBeNull()
    // Escape commits and ends.
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('lemma:text-changed')).toMatchObject({
      id: 'eip-a-000001',
      field: 'body',
      html: '<p>hello</p>',
    })
    expect(lastPost('lemma:edit-end')).toMatchObject({ id: 'eip-a-000001' })
    expect(region.getAttribute('contenteditable')).toBeNull()
  })

  it('grant field mismatch or multiple regions -> no editing (fail-safe)', () => {
    const w = proseWrapper('eip-b-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-b-000001', field: 'other', kind: 'rich' })
    expect(w.querySelector('[contenteditable]')).toBeNull()

    const two = wrapper(
      'eip-c-000001',
      '<section>' +
        '<div class="lemma-edit-region" data-lemma-edit-block="eip-c-000001" data-lemma-edit-field="body">a</div>' +
        '<div class="lemma-edit-region" data-lemma-edit-block="eip-c-000001" data-lemma-edit-field="body">b</div>' +
        '</section>',
    )
    document.body.appendChild(two)
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-c-000001', field: 'body', kind: 'rich' })
    expect(two.querySelector('[contenteditable]')).toBeNull()
  })

  it('typing commits on the debounce; clicks INSIDE the region are not swallowed', () => {
    vi.useFakeTimers()
    try {
      const w = proseWrapper('eip-d-000001')
      document.body.appendChild(w)
      sendToBridge({ type: 'lemma:edit-grant', id: 'eip-d-000001', field: 'body', kind: 'rich' })
      const region = w.querySelector('.lemma-edit-region')!
      region.innerHTML = '<p>typed</p>'
      region.dispatchEvent(new Event('input', { bubbles: true }))
      vi.advanceTimersByTime(450)
      expect(lastPost('lemma:text-changed')).toMatchObject({ html: '<p>typed</p>' })

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
      expect(lastPost('lemma:edit-end')).toMatchObject({ id: 'eip-d-000001' })
      expect(lastPost('lemma:block-select')).toMatchObject({ id: 'eip-e-000001' })
      expect(region.getAttribute('contenteditable')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('edit-flush commits an active session and ALWAYS acks with edit-flushed', () => {
    // No active session: ack only.
    posted.mockClear()
    sendToBridge({ type: 'lemma:edit-flush' })
    expect(lastPost('lemma:edit-flushed')).toBeDefined()
    expect(lastPost('lemma:text-changed')).toBeUndefined()

    // Active session: final text-changed + edit-end BEFORE the ack.
    const w = proseWrapper('eip-f-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-f-000001', field: 'body', kind: 'rich' })
    const region = w.querySelector('.lemma-edit-region')!
    region.innerHTML = '<p>flush me</p>'
    posted.mockClear()
    sendToBridge({ type: 'lemma:edit-flush' })
    const types = posted.mock.calls.map((c) => (c[0] as { type: string }).type)
    expect(types).toEqual(['lemma:text-changed', 'lemma:edit-end', 'lemma:edit-flushed'])
    expect(region.getAttribute('contenteditable')).toBeNull()
  })

  it('mirror-duplicate clones never carry contenteditable or the editing class', () => {
    const w = proseWrapper('eip-g-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-g-000001', field: 'body', kind: 'rich' })
    sendToBridge({
      type: 'lemma:mirror-duplicate',
      sourceId: 'eip-g-000001',
      idMap: { 'eip-g-000001': 'eip-h-000002' },
    })
    const copy = document.querySelector('[data-lemma-block="eip-h-000002"]')!
    expect(copy.querySelector('[contenteditable]')).toBeNull()
    expect(copy.querySelector('.lemma-canvas-editing')).toBeNull()
    sendToBridge({ type: 'lemma:edit-flush' }) // clean up the session for later tests
  })

  it('a DUPLICATED prose block is immediately editable under its NEW id (review P1)', () => {
    const w = proseWrapper('eip-i-000001')
    document.body.appendChild(w)
    sendToBridge({
      type: 'lemma:mirror-duplicate',
      sourceId: 'eip-i-000001',
      idMap: { 'eip-i-000001': 'eip-j-000002' },
    })
    const copy = document.querySelector('[data-lemma-block="eip-j-000002"]')!
    // The edit region's id was rewritten alongside the wrapper's — without
    // this, edit-grant for the new id can never find its region until the
    // next Apply re-renders truth.
    const region = copy.querySelector('.lemma-edit-region')!
    expect(region.getAttribute('data-lemma-edit-block')).toBe('eip-j-000002')
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-j-000002', field: 'body', kind: 'rich' })
    expect(region.getAttribute('contenteditable')).toBe('true')
    sendToBridge({ type: 'lemma:edit-flush' }) // clean up the session for later tests

    // v4: the field-addressed request from the CLONE emits the NEW id.
    posted.mockClear()
    region.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'eip-j-000002', field: 'body' })
  })

  function stringWrapper(id: string, field = 'heading', value = 'Hello'): HTMLElement {
    return wrapper(
      id,
      `<section><h1><span class="lemma-edit-region" data-lemma-edit-block="${id}" ` +
        `data-lemma-edit-field="${field}">${value}</span></h1></section>`,
    )
  }

  it('request field comes from the region under the double-click; two fields coexist', () => {
    const w = wrapper(
      'es-a-0000001',
      '<section>' +
        '<h1><span class="lemma-edit-region" data-lemma-edit-block="es-a-0000001" data-lemma-edit-field="heading">H</span></h1>' +
        '<p><span class="lemma-edit-region" data-lemma-edit-block="es-a-0000001" data-lemma-edit-field="body_text">B</span></p>' +
        '</section>',
    )
    document.body.appendChild(w)
    w.querySelector('p .lemma-edit-region')!.dispatchEvent(
      new MouseEvent('dblclick', { bubbles: true }),
    )
    expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'es-a-0000001', field: 'body_text' })

    // Grant for ONE of two same-block regions edits exactly that region.
    sendToBridge({ type: 'lemma:edit-grant', id: 'es-a-0000001', field: 'body_text', kind: 'text' })
    const region = w.querySelector('[data-lemma-edit-field="body_text"]')!
    expect(region.getAttribute('contenteditable')).not.toBeNull()
    expect(
      w.querySelector('[data-lemma-edit-field="heading"]')!.getAttribute('contenteditable'),
    ).toBeNull()
    sendToBridge({ type: 'lemma:edit-flush' })
  })

  it('wrapper-level double-click falls back to the SINGLE region; none with two', () => {
    const single = stringWrapper('es-b-0000001')
    document.body.appendChild(single)
    posted.mockClear()
    single.querySelector('section')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'es-b-0000001', field: 'heading' })

    const two = wrapper(
      'es-c-0000001',
      '<section>' +
        '<span class="lemma-edit-region" data-lemma-edit-block="es-c-0000001" data-lemma-edit-field="a">1</span>' +
        '<span class="lemma-edit-region" data-lemma-edit-block="es-c-0000001" data-lemma-edit-field="b">2</span>' +
        '</section>',
    )
    document.body.appendChild(two)
    posted.mockClear()
    two.querySelector('section')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toBeUndefined()
  })

  it('string kind: Enter commits-and-exits with the TEXT payload (markup never persists)', () => {
    const w = stringWrapper('es-d-0000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'es-d-0000001', field: 'heading', kind: 'string' })
    const region = w.querySelector('.lemma-edit-region')!
    expect(['plaintext-only', 'true']).toContain(region.getAttribute('contenteditable'))

    region.innerHTML = 'New <b>title</b>'
    const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    region.dispatchEvent(enter)
    expect(enter.defaultPrevented).toBe(true) // single-line convention
    const commit = lastPost('lemma:text-changed')!
    expect(commit).toMatchObject({ id: 'es-d-0000001', field: 'heading', text: 'New title' })
    expect(commit.html).toBeUndefined()
    expect(lastPost('lemma:edit-end')).toMatchObject({ id: 'es-d-0000001' })
    expect(region.getAttribute('contenteditable')).toBeNull()
  })

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

  it('text kind: Enter does NOT exit; commit carries the text payload', () => {
    const w = stringWrapper('es-e-0000001', 'body_text', 'line')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'es-e-0000001', field: 'body_text', kind: 'text' })
    const region = w.querySelector('.lemma-edit-region')!
    const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    region.dispatchEvent(enter)
    expect(enter.defaultPrevented).toBe(false)
    expect(region.getAttribute('contenteditable')).not.toBeNull() // still editing
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('lemma:text-changed')).toMatchObject({
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
