import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createPaletteDrag, type PaletteDrag } from '@/editor/palette/usePaletteDrag'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

// The palette drag owns the whole gesture (Phase C.1 plan, reviewed twice): click versus drag is
// decided once at the threshold; a token guards every await; the drop is the bridge's answer for
// the released pointer, never a remembered proposal; the helper is the one owner of that answer.

function harness(opts: { threshold?: number } = {}) {
  const bridge = { dragBegin: vi.fn(), dragHover: vi.fn(), dragDrop: vi.fn(), dragEnd: vi.fn() }
  let resolveFactory: (b: BlockInstance) => void = () => {}
  let rejectFactory: (e: unknown) => void = () => {}
  const factory = {
    instance: vi.fn(
      () =>
        new Promise<BlockInstance>((res, rej) => {
          resolveFactory = res
          rejectFactory = rej
        }),
    ),
  }
  let sessions = 0
  const coordinator = { begin: vi.fn(() => `s${++sessions}`), cancel: vi.fn() }
  const cb = {
    onClick: vi.fn(),
    onSession: vi.fn(),
    onDrop: vi.fn(),
    onCancel: vi.fn(),
    notify: vi.fn(),
  }
  const tile = document.createElement('button')
  tile.setPointerCapture = vi.fn()
  tile.releasePointerCapture = vi.fn()
  document.body.appendChild(tile)
  const drag: PaletteDrag = createPaletteDrag({
    bridge,
    iframeRect: () => ({ left: 100, top: 50, width: 400, height: 300 }) as DOMRect,
    factory,
    coordinator,
    notify: cb.notify,
    onClick: cb.onClick,
    onSession: cb.onSession,
    onDrop: cb.onDrop,
    onCancel: cb.onCancel,
    threshold: opts.threshold,
  })
  const ev = (x: number, y: number) =>
    ({
      clientX: x,
      clientY: y,
      pointerId: 7,
      button: 0,
      currentTarget: tile,
      preventDefault() {},
    }) as unknown as PointerEvent
  const block = (id = 'new000000001'): BlockInstance => ({
    id,
    type: 'heading',
    data: {},
    settings: {},
  })
  return {
    bridge,
    factory,
    coordinator,
    cb,
    tile,
    drag,
    ev,
    block,
    resolve: (b = block()) => resolveFactory(b),
    reject: (e: unknown) => rejectFactory(e),
  }
}

async function flush() {
  await Promise.resolve()
  await Promise.resolve()
  await Promise.resolve()
}

describe('the palette drag gesture', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
  })

  it('a release below the threshold is a click, exactly once, whatever the factory does later', async () => {
    const h = harness()
    h.drag.begin('heading', h.ev(10, 10))
    expect(h.tile.setPointerCapture).toHaveBeenCalledWith(7)
    h.drag.move(h.ev(12, 11))
    h.drag.end(h.ev(12, 11))
    expect(h.cb.onClick).toHaveBeenCalledWith('heading')
    expect(h.drag.state()).toBe('idle')
    h.resolve()
    await flush()
    expect(h.cb.onClick).toHaveBeenCalledTimes(1)
    expect(h.cb.onSession).not.toHaveBeenCalled()
    expect(h.bridge.dragBegin).not.toHaveBeenCalled()
  })

  it('threshold crossed, released before the factory answers, factory answers later: nothing at all', async () => {
    const h = harness()
    h.drag.begin('heading', h.ev(10, 10))
    h.drag.move(h.ev(30, 10))
    expect(h.drag.state()).toBe('dragging')
    expect(document.querySelector('.thallo-palette-ghost')).not.toBeNull()
    h.drag.end(h.ev(200, 100)) // inside the iframe, but no session yet
    expect(h.cb.onCancel).toHaveBeenCalledTimes(1)
    expect(document.querySelector('.thallo-palette-ghost')).toBeNull()
    h.resolve()
    await flush()
    expect(h.cb.onClick).not.toHaveBeenCalled()
    expect(h.cb.onSession).not.toHaveBeenCalled()
    expect(h.bridge.dragBegin).not.toHaveBeenCalled()
    expect(h.cb.onDrop).not.toHaveBeenCalled()
  })

  it('several moves while the factory loads start ONE session at the latest position, in iframe coordinates', async () => {
    const h = harness()
    h.drag.begin('heading', h.ev(10, 10))
    h.drag.move(h.ev(30, 10))
    h.drag.move(h.ev(150, 90))
    h.drag.move(h.ev(160, 95))
    expect(h.factory.instance).toHaveBeenCalledTimes(1)
    h.resolve()
    await flush()
    expect(h.coordinator.begin).toHaveBeenCalledTimes(1)
    expect(h.coordinator.begin).toHaveBeenCalledWith('palette', { block: h.block() })
    expect(h.cb.onSession).toHaveBeenCalledWith('s1', h.block())
    expect(h.bridge.dragBegin).toHaveBeenCalledWith('s1', [])
    expect(h.bridge.dragHover).toHaveBeenCalledTimes(1)
    expect(h.bridge.dragHover).toHaveBeenCalledWith('s1', 60, 45) // 160-100, 95-50
    h.drag.move(h.ev(170, 100))
    expect(h.bridge.dragHover).toHaveBeenLastCalledWith('s1', 70, 50)
    h.drag.move(h.ev(20, 20)) // outside the iframe: sent as it is, so the bridge clears its indicator
    expect(h.bridge.dragHover).toHaveBeenLastCalledWith('s1', -80, -30)
  })

  it('the real release: pointerup, capture loss, then the bridge answers — exactly one drop', async () => {
    const h = harness()
    h.drag.begin('heading', h.ev(10, 10))
    h.drag.move(h.ev(150, 90))
    h.resolve()
    await flush()
    h.drag.end(h.ev(200, 100))
    expect(h.drag.state()).toBe('awaiting-drop')
    expect(h.bridge.dragDrop).toHaveBeenCalledWith('s1', 100, 50)
    expect(h.tile.releasePointerCapture).toHaveBeenCalledWith(7)
    h.drag.lostCapture() // expected after a release: changes nothing
    expect(h.drag.state()).toBe('awaiting-drop')
    expect(h.drag.owns('s1')).toBe(true)
    expect(h.drag.owns('s9')).toBe(false)
    const zone = { parent: 'cols00000002', slot: 'col_2', index: 0 }
    h.drag.answer(zone)
    expect(h.cb.onDrop).toHaveBeenCalledTimes(1)
    expect(h.cb.onDrop).toHaveBeenCalledWith(zone)
    expect(h.bridge.dragEnd).toHaveBeenCalledWith('s1')
    expect(h.drag.state()).toBe('idle')
    expect(h.cb.onCancel).not.toHaveBeenCalled()
    h.drag.answer(zone) // a second answer changes nothing
    expect(h.cb.onDrop).toHaveBeenCalledTimes(1)
  })

  it('the answer window: a timeout cancels once, and a late answer afterwards is ignored', async () => {
    const h = harness()
    h.drag.begin('heading', h.ev(10, 10))
    h.drag.move(h.ev(150, 90))
    h.resolve()
    await flush()
    h.drag.end(h.ev(200, 100))
    vi.advanceTimersByTime(499)
    expect(h.cb.onCancel).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    expect(h.cb.onCancel).toHaveBeenCalledTimes(1)
    expect(h.bridge.dragEnd).toHaveBeenCalledWith('s1')
    h.drag.answer({ parent: null, slot: 'body', index: 0 })
    expect(h.cb.onDrop).not.toHaveBeenCalled()
    expect(h.cb.onCancel).toHaveBeenCalledTimes(1)
  })

  it('a drag-cancel answer, a release outside the iframe, Escape and pointercancel each cancel once', async () => {
    for (const how of ['answer-null', 'outside', 'escape', 'pointercancel'] as const) {
      const h = harness()
      h.drag.begin('heading', h.ev(10, 10))
      h.drag.move(h.ev(150, 90))
      h.resolve()
      await flush()
      if (how === 'answer-null') {
        h.drag.end(h.ev(200, 100))
        h.drag.answer(null)
      } else if (how === 'outside') {
        h.drag.end(h.ev(20, 20))
      } else if (how === 'escape') {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
      } else {
        h.tile.dispatchEvent(new Event('pointercancel'))
      }
      expect(h.cb.onCancel, how).toHaveBeenCalledTimes(1)
      expect(h.cb.onDrop, how).not.toHaveBeenCalled()
      expect(h.bridge.dragEnd, how).toHaveBeenCalledWith('s1')
      expect(h.drag.state(), how).toBe('idle')
      expect(document.querySelector('.thallo-palette-ghost'), how).toBeNull()
      document.body.innerHTML = ''
    }
  })

  it('capture lost while dragging cancels; a factory rejection cancels and notifies', async () => {
    const h = harness()
    h.drag.begin('heading', h.ev(10, 10))
    h.drag.move(h.ev(150, 90))
    h.drag.lostCapture()
    expect(h.cb.onCancel).toHaveBeenCalledTimes(1)
    expect(h.drag.state()).toBe('idle')

    const g = harness()
    g.drag.begin('heading', g.ev(10, 10))
    g.drag.move(g.ev(150, 90))
    g.reject(new Error('Block type not found.'))
    await flush()
    expect(g.cb.notify).toHaveBeenCalledWith(expect.any(Error), "Couldn't add block")
    expect(g.cb.onCancel).toHaveBeenCalledTimes(1)
    expect(g.drag.state()).toBe('idle')
  })

  it('a second pointerdown during a pending gesture replaces it; the first cannot resume', async () => {
    const h = harness()
    h.drag.begin('heading', h.ev(10, 10))
    h.drag.move(h.ev(150, 90))
    const firstResolve = h.resolve
    h.drag.begin('button', h.ev(10, 10)) // replaced while the first factory call is pending
    firstResolve(h.block('first0000001'))
    await flush()
    expect(h.cb.onSession).not.toHaveBeenCalled()
    h.drag.end(h.ev(11, 10))
    expect(h.cb.onClick).toHaveBeenCalledWith('button')
    expect(h.cb.onClick).toHaveBeenCalledTimes(1)
  })
})
