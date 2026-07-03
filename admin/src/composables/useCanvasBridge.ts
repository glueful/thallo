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
}

export function useCanvasBridge(iframeRef: Ref<HTMLIFrameElement | null>) {
  const nonce = Array.from(crypto.getRandomValues(new Uint8Array(16)))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')

  let selectCb: ((id: string) => void) | null = null
  let hoverCb: ((id: string) => void) | null = null
  let indexCb: ((ids: string[]) => void) | null = null
  let moveCb: ((id: string, delta: 1 | -1) => void) | null = null
  let duplicateCb: ((id: string) => void) | null = null
  let deleteRequestCb: ((id: string) => void) | null = null
  let addAfterCb: ((id: string) => void) | null = null
  let editRequestCb: ((id: string) => void) | null = null
  let textChangedCb: ((id: string, field: string, html: string) => void) | null = null
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
    if (data.type === 'lemma:block-hover' && typeof data.id === 'string') hoverCb?.(data.id)
    if (data.type === 'lemma:blocks-index' && Array.isArray(data.ids)) {
      indexCb?.(data.ids.filter((v): v is string => typeof v === 'string'))
    }
    // Stage toolbar intents (stage-toolbar spec §1).
    if (data.type === 'lemma:block-move' && typeof data.id === 'string') {
      if (data.delta === 1 || data.delta === -1) moveCb?.(data.id, data.delta)
    }
    if (data.type === 'lemma:block-duplicate' && typeof data.id === 'string') {
      duplicateCb?.(data.id)
    }
    if (data.type === 'lemma:block-delete-request' && typeof data.id === 'string') {
      deleteRequestCb?.(data.id)
    }
    if (data.type === 'lemma:block-add-after' && typeof data.id === 'string') {
      addAfterCb?.(data.id)
    }
    // Edit-in-place (edit-in-place spec §3/§4).
    if (data.type === 'lemma:edit-request' && typeof data.id === 'string') {
      editRequestCb?.(data.id)
    }
    if (
      data.type === 'lemma:text-changed' &&
      typeof data.id === 'string' &&
      typeof data.field === 'string' &&
      typeof data.html === 'string'
    ) {
      textChangedCb?.(data.id, data.field, data.html)
    }
    if (data.type === 'lemma:edit-flushed') {
      flushResolve?.()
      flushResolve = null
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
    onBlockDuplicate(cb: (id: string) => void): void {
      duplicateCb = cb
    },
    onBlockDeleteRequest(cb: (id: string) => void): void {
      deleteRequestCb = cb
    },
    onBlockAddAfter(cb: (id: string) => void): void {
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
    onEditRequest(cb: (id: string) => void): void {
      editRequestCb = cb
    },
    onTextChanged(cb: (id: string, field: string, html: string) => void): void {
      textChangedCb = cb
    },
    editGrant(id: string, field: string): void {
      post({ type: 'lemma:edit-grant', id, field })
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
