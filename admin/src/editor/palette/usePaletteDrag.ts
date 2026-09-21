// A palette drag onto the stage (visual builder spec §5.1/§5.3 — Phase C.1). The helper owns the
// whole gesture: idle → pending (pointer down, capture taken, the factory asked) → click (released
// below the threshold) or dragging (the ghost, one session once the factory answers, hovers in
// iframe coordinates) → awaiting-drop (released over the stage: the bridge is asked for the zone
// under THAT point) → idle. A gesture token guards every await, so a late factory answer or a
// late bridge answer cannot resurrect a gesture that ended, and the helper is the one owner of
// the bridge's drop answer.
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { DropZone } from '@/editor/structure/coordinator'

export type PaletteDragState = 'idle' | 'pending' | 'dragging' | 'awaiting-drop'

export interface PaletteDragDeps {
  bridge: {
    dragBegin(session: string, blocks: string[]): void
    dragHover(session: string, x: number, y: number): void
    dragDrop(session: string, x: number, y: number): void
    dragEnd(session: string): void
  }
  /** The stage iframe's rect in the main viewport, or null while the stage is not mounted. */
  iframeRect: () => DOMRect | null
  factory: { instance(slug: string): Promise<BlockInstance> }
  /** What the ghost says for a slug; the slug itself when not given. */
  labelOf?: (slug: string) => string
  coordinator: { begin(source: 'palette', payload: { block: BlockInstance }): string }
  notify: (err: unknown, title: string) => void
  /** A release below the threshold: the gesture was a click. */
  onClick: (slug: string) => void
  /** The coordinator session began with the factory's instance. */
  onSession: (session: string, block: BlockInstance) => void
  /** The bridge answered the release with the zone under the released pointer. */
  onDrop: (zone: DropZone) => void
  /** The gesture ended without a drop after a session began, or the drag was abandoned. */
  onCancel: (reason: string) => void
  /** Pixels of movement that turn a pending gesture into a drag. */
  threshold?: number
  /** How long a release waits for the bridge's answer. */
  answerTimeoutMs?: number
}

export interface PaletteDrag {
  begin(slug: string, event: PointerEvent): void
  move(event: PointerEvent): void
  end(event: PointerEvent): void
  cancel(reason?: string): void
  /** Pointer capture left the tile: fatal while pending or dragging, expected once released. */
  lostCapture(): void
  /** Whether `session` is this helper's — the page routes the bridge's answers by it. */
  owns(session: string): boolean
  /** The bridge's answer to the release: the zone under the point, or null for none. */
  answer(zone: DropZone | null): void
  state(): PaletteDragState
}

export function createPaletteDrag(deps: PaletteDragDeps): PaletteDrag {
  const threshold = deps.threshold ?? 4
  const answerTimeoutMs = deps.answerTimeoutMs ?? 500

  let state: PaletteDragState = 'idle'
  let gestureId = 0
  let slug = ''
  let origin = { x: 0, y: 0 }
  let last = { x: 0, y: 0 }
  let tile: Element | null = null
  let pointerId: number | null = null
  let pending: Promise<BlockInstance> | null = null
  let session: string | null = null
  let ghost: HTMLElement | null = null
  let answerTimer: ReturnType<typeof setTimeout> | null = null

  const onPointerMove = (e: Event) => move(e as PointerEvent)
  const onPointerUp = (e: Event) => end(e as PointerEvent)
  const onPointerCancel = () => cancel('pointercancel')
  const onLostCapture = () => lostCapture()
  const onKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && state !== 'idle') {
      e.preventDefault()
      cancel('escape')
    }
  }

  function listen(el: Element): void {
    el.addEventListener('pointermove', onPointerMove)
    el.addEventListener('pointerup', onPointerUp)
    el.addEventListener('pointercancel', onPointerCancel)
    el.addEventListener('lostpointercapture', onLostCapture)
    window.addEventListener('keydown', onKeydown, true)
  }
  function unlisten(): void {
    if (tile) {
      tile.removeEventListener('pointermove', onPointerMove)
      tile.removeEventListener('pointerup', onPointerUp)
      tile.removeEventListener('pointercancel', onPointerCancel)
      tile.removeEventListener('lostpointercapture', onLostCapture)
    }
    window.removeEventListener('keydown', onKeydown, true)
  }

  function releaseCapture(): void {
    if (tile && pointerId !== null && 'releasePointerCapture' in tile) {
      try {
        ;(tile as HTMLElement).releasePointerCapture(pointerId)
      } catch {
        // already released
      }
    }
  }

  function showGhost(label: string): void {
    ghost = document.createElement('div')
    ghost.className = 'thallo-palette-ghost'
    ghost.textContent = label
    Object.assign(ghost.style, {
      position: 'fixed',
      top: '0',
      left: '0',
      pointerEvents: 'none',
      zIndex: '60',
    })
    document.body.appendChild(ghost)
  }
  function moveGhost(x: number, y: number): void {
    if (ghost) ghost.style.transform = `translate(${x + 12}px, ${y + 12}px)`
  }
  function removeGhost(): void {
    ghost?.parentNode?.removeChild(ghost)
    ghost = null
  }

  /** Main-viewport → iframe-viewport coordinates, and whether the point is inside the stage. */
  function toIframe(x: number, y: number): { x: number; y: number; inside: boolean } {
    const r = deps.iframeRect()
    if (!r) return { x, y, inside: false }
    return {
      x: x - r.left,
      y: y - r.top,
      inside: x >= r.left && x < r.left + r.width && y >= r.top && y < r.top + r.height,
    }
  }

  /** Back to idle: listeners, capture, ghost and timer gone; the session id kept for late answers. */
  function reset(): void {
    if (answerTimer !== null) {
      clearTimeout(answerTimer)
      answerTimer = null
    }
    unlisten()
    releaseCapture()
    removeGhost()
    state = 'idle'
    pending = null
    tile = null
    pointerId = null
  }

  function endSession(): void {
    if (session !== null) deps.bridge.dragEnd(session)
  }

  async function startSession(gesture: number): Promise<void> {
    let block: BlockInstance
    try {
      block = await pending!
    } catch (err) {
      if (gesture !== gestureId || state !== 'dragging') return
      deps.notify(err, "Couldn't add block")
      cancel('factory')
      return
    }
    if (gesture !== gestureId || state !== 'dragging') return
    session = deps.coordinator.begin('palette', { block })
    deps.onSession(session, block)
    deps.bridge.dragBegin(session, [])
    hover()
  }

  function hover(): void {
    if (session === null || state !== 'dragging') return
    const p = toIframe(last.x, last.y)
    deps.bridge.dragHover(session, p.x, p.y)
  }

  function begin(nextSlug: string, event: PointerEvent): void {
    if (state !== 'idle') cancel('replaced')
    gestureId++
    slug = nextSlug
    state = 'pending'
    session = null
    origin = { x: event.clientX, y: event.clientY }
    last = origin
    tile = event.currentTarget as Element
    pointerId = event.pointerId
    if ('setPointerCapture' in tile) {
      try {
        ;(tile as HTMLElement).setPointerCapture(event.pointerId)
      } catch {
        // an engine without capture: moves still reach the tile while the pointer is over it
      }
    }
    listen(tile)
    const asked = deps.factory.instance(slug)
    asked.catch(() => undefined) // the gesture reports the failure if it is still the one waiting
    pending = asked
  }

  function move(event: PointerEvent): void {
    if (state === 'idle' || state === 'awaiting-drop') return
    last = { x: event.clientX, y: event.clientY }
    if (state === 'pending') {
      const dx = last.x - origin.x
      const dy = last.y - origin.y
      if (Math.hypot(dx, dy) < threshold) return
      state = 'dragging'
      showGhost(deps.labelOf?.(slug) ?? slug)
      moveGhost(last.x, last.y)
      void startSession(gestureId)
      return
    }
    moveGhost(last.x, last.y)
    hover()
  }

  function end(event: PointerEvent): void {
    if (state === 'pending') {
      const wasSlug = slug
      gestureId++ // a late factory answer belongs to no gesture
      reset()
      deps.onClick(wasSlug)
      return
    }
    if (state !== 'dragging') return
    last = { x: event.clientX, y: event.clientY }
    if (session === null) {
      cancel('released before the block was ready')
      return
    }
    const p = toIframe(last.x, last.y)
    if (!p.inside) {
      cancel('released outside the stage')
      return
    }
    // Awaiting the bridge's answer: capture is released now (a normal pointerup does that too).
    state = 'awaiting-drop'
    const gesture = gestureId
    unlisten()
    releaseCapture()
    removeGhost()
    deps.bridge.dragDrop(session, p.x, p.y)
    answerTimer = setTimeout(() => {
      answerTimer = null
      if (gesture !== gestureId || state !== 'awaiting-drop') return
      finish(null, 'no answer from the stage')
    }, answerTimeoutMs)
  }

  /** The end of an awaited release: the helper's one exit for the bridge's answer. */
  function finish(zone: DropZone | null, reason: string): void {
    gestureId++
    const s = session
    reset()
    if (s !== null) deps.bridge.dragEnd(s)
    if (zone) deps.onDrop(zone)
    else deps.onCancel(reason)
  }

  function answer(zone: DropZone | null): void {
    if (state !== 'awaiting-drop') return
    finish(zone, 'the stage cancelled')
  }

  function cancel(reason = 'cancelled'): void {
    if (state === 'idle') return
    const had = state
    gestureId++
    reset()
    if (had === 'pending') return // nothing began: nothing to report
    endSession()
    deps.onCancel(reason)
  }

  function lostCapture(): void {
    if (state === 'pending' || state === 'dragging') cancel('pointer capture lost')
  }

  return {
    begin,
    move,
    end,
    cancel,
    lostCapture,
    owns: (s) => session !== null && s === session,
    answer,
    state: () => state,
  }
}
