import type { Ref } from 'vue'

// Canvas-side half of the preview bridge (visual-canvas spec §3). The iframe may
// be cross-origin, so everything is postMessage: the parent says hello with a
// crypto-random NONCE; the bridge echoes it on every message; this composable
// DROPS anything without the nonce (correlation, not auth — stale frames and
// same-window noise can never impersonate the active canvas session).
//
// targetOrigin pin (spec review P2): derived LAZILY from the iframe's actual
// src (the server-decided theme_url), never from sitePreviewUrl — and computed
// per post, so re-mints that change the src are respected automatically. '*'
// only when parsing is impossible (the messages carry no secrets).

interface BridgeMessage {
  type?: string
  nonce?: string
  id?: string
  ids?: string[]
  delta?: number
  field?: string
  html?: string
  text?: string
  y?: number
  beforeId?: string
  afterId?: string
  rect?: { x?: number; y?: number }
}

/** Iframe-viewport anchor point forwarded with stage intents (add-after picker). */
export interface BridgeAnchor {
  x: number
  y: number
}

/** Grant kinds (editable-string-fields spec §4) — decided by the parent's matrix. */
export type EditKind = 'rich' | 'string' | 'text'

export function useCanvasBridge(iframeRef: Ref<HTMLIFrameElement | null>) {
  const nonce = Array.from(crypto.getRandomValues(new Uint8Array(16)))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')

  let selectCb: ((id: string) => void) | null = null
  let deselectCb: ((id: string) => void) | null = null
  let hoverCb: ((id: string) => void) | null = null
  let indexCb: ((ids: string[]) => void) | null = null
  let moveCb: ((id: string, delta: 1 | -1) => void) | null = null
  let moveToCb:
    | ((id: string, neighbor: { beforeId: string } | { afterId: string }) => void)
    | null = null
  let duplicateCb: ((id: string) => void) | null = null
  let deleteRequestCb: ((id: string, anchor: BridgeAnchor | null) => void) | null = null
  let addAfterCb: ((id: string, anchor: BridgeAnchor | null) => void) | null = null
  let editRequestCb: ((id: string, field: string) => void) | null = null
  let editStartCb: ((id: string) => void) | null = null
  let editEndCb: ((id: string) => void) | null = null
  let scrollCb: ((y: number) => void) | null = null
  let textChangedCb:
    | ((id: string, field: string, payload: { html?: string; text?: string }) => void)
    | null = null
  let flushResolve: (() => void) | null = null

  function targetOrigin(): string {
    const src = iframeRef.value?.src ?? ''
    try {
      return new URL(src, window.location.href).origin
    } catch {
      return '*'
    }
  }

  function post(message: Record<string, unknown>): void {
    iframeRef.value?.contentWindow?.postMessage({ ...message, nonce }, targetOrigin())
  }

  function onMessage(event: MessageEvent): void {
    const data = (event.data ?? {}) as BridgeMessage
    if (data.nonce !== nonce) return
    if (data.type === 'lemma:block-select' && typeof data.id === 'string') selectCb?.(data.id)
    // Stage Escape (keyboard-shortcuts spec §3): notification-only — the
    // bridge already cleared its own ring/toolbar.
    if (data.type === 'lemma:block-deselect' && typeof data.id === 'string') deselectCb?.(data.id)
    if (data.type === 'lemma:block-hover' && typeof data.id === 'string') hoverCb?.(data.id)
    if (data.type === 'lemma:blocks-index' && Array.isArray(data.ids)) {
      indexCb?.(data.ids.filter((v): v is string => typeof v === 'string'))
    }
    // Stage toolbar intents (stage-toolbar spec §1).
    if (data.type === 'lemma:block-move' && typeof data.id === 'string') {
      if (data.delta === 1 || data.delta === -1) moveCb?.(data.id, data.delta)
    }
    if (data.type === 'lemma:block-move-to' && typeof data.id === 'string') {
      // XOR (review P2): exactly one neighbor key — both or neither is
      // malformed and dropped, never a silent preference.
      const hasBefore = typeof data.beforeId === 'string'
      const hasAfter = typeof data.afterId === 'string'
      if (hasBefore && !hasAfter) moveToCb?.(data.id, { beforeId: data.beforeId as string })
      else if (hasAfter && !hasBefore) moveToCb?.(data.id, { afterId: data.afterId as string })
    }
    if (data.type === 'lemma:block-duplicate' && typeof data.id === 'string') {
      duplicateCb?.(data.id)
    }
    if (data.type === 'lemma:block-delete-request' && typeof data.id === 'string') {
      const deleteAnchor =
        typeof data.rect?.x === 'number' && typeof data.rect?.y === 'number'
          ? { x: data.rect.x, y: data.rect.y }
          : null
      deleteRequestCb?.(data.id, deleteAnchor)
    }
    if (data.type === 'lemma:block-add-after' && typeof data.id === 'string') {
      const anchor =
        typeof data.rect?.x === 'number' && typeof data.rect?.y === 'number'
          ? { x: data.rect.x, y: data.rect.y }
          : null
      addAfterCb?.(data.id, anchor)
    }
    // Edit-in-place (edit-in-place spec §3/§4; v4 field-addressed shapes).
    if (
      data.type === 'lemma:edit-request' &&
      typeof data.id === 'string' &&
      typeof data.field === 'string'
    ) {
      editRequestCb?.(data.id, data.field)
    }
    if (
      data.type === 'lemma:text-changed' &&
      typeof data.id === 'string' &&
      typeof data.field === 'string'
    ) {
      if (typeof data.html === 'string') {
        textChangedCb?.(data.id, data.field, { html: data.html })
      } else if (typeof data.text === 'string') {
        textChangedCb?.(data.id, data.field, { text: data.text })
      }
    }
    if (data.type === 'lemma:edit-flushed') {
      flushResolve?.()
      flushResolve = null
    }
    // Auto-apply lifecycle + scroll preservation (auto-apply spec §1/§3).
    if (data.type === 'lemma:edit-start' && typeof data.id === 'string') {
      editStartCb?.(data.id)
    }
    if (data.type === 'lemma:edit-end' && typeof data.id === 'string') {
      editEndCb?.(data.id)
    }
    if (data.type === 'lemma:scroll' && typeof data.y === 'number') {
      scrollCb?.(data.y)
    }
  }

  window.addEventListener('message', onMessage)

  return {
    nonce,
    hello(): void {
      post({ type: 'lemma:canvas-hello' })
    },
    onBlockSelect(cb: (id: string) => void): void {
      selectCb = cb
    },
    onBlockDeselect(cb: (id: string) => void): void {
      deselectCb = cb
    },
    onBlockHover(cb: (id: string) => void): void {
      hoverCb = cb
    },
    onBlocksIndex(cb: (ids: string[]) => void): void {
      indexCb = cb
    },
    highlight(id: string): void {
      post({ type: 'lemma:highlight', id })
    },
    scrollTo(id: string): void {
      post({ type: 'lemma:scroll-to', id })
    },
    onBlockMove(cb: (id: string, delta: 1 | -1) => void): void {
      moveCb = cb
    },
    onBlockMoveTo(
      cb: (id: string, neighbor: { beforeId: string } | { afterId: string }) => void,
    ): void {
      moveToCb = cb
    },
    onBlockDuplicate(cb: (id: string) => void): void {
      duplicateCb = cb
    },
    onBlockDeleteRequest(cb: (id: string, anchor: BridgeAnchor | null) => void): void {
      deleteRequestCb = cb
    },
    onBlockAddAfter(cb: (id: string, anchor: BridgeAnchor | null) => void): void {
      addAfterCb = cb
    },
    // Mirrors (stage-toolbar spec §1): posted ONLY after the tree committed.
    mirrorMove(id: string, neighbor: { beforeId: string } | { afterId: string }): void {
      post({ type: 'lemma:mirror-move', id, ...neighbor })
    },
    mirrorRemove(id: string): void {
      post({ type: 'lemma:mirror-remove', id })
    },
    mirrorDuplicate(sourceId: string, idMap: Record<string, string>): void {
      post({ type: 'lemma:mirror-duplicate', sourceId, idMap })
    },
    onEditRequest(cb: (id: string, field: string) => void): void {
      editRequestCb = cb
    },
    onTextChanged(
      cb: (id: string, field: string, payload: { html?: string; text?: string }) => void,
    ): void {
      textChangedCb = cb
    },
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
    editGrant(id: string, field: string, kind: EditKind): void {
      post({ type: 'lemma:edit-grant', id, field, kind })
    },
    /**
     * Flush any in-stage editing session before Apply (spec §4): resolves on
     * the bridge's unconditional edit-flushed ack, or after 200ms when no
     * bridge answers (a mid-reload stage must not wedge Apply).
     */
    editFlush(): Promise<void> {
      post({ type: 'lemma:edit-flush' })
      return new Promise((resolve) => {
        flushResolve = () => resolve()
        setTimeout(() => {
          flushResolve = null
          resolve()
        }, 200)
      })
    },
    dispose(): void {
      window.removeEventListener('message', onMessage)
    },
  }
}

export type CanvasBridge = ReturnType<typeof useCanvasBridge>
