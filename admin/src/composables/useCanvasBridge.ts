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
}

export function useCanvasBridge(iframeRef: Ref<HTMLIFrameElement | null>) {
  const nonce = Array.from(crypto.getRandomValues(new Uint8Array(16)))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')

  let selectCb: ((id: string) => void) | null = null
  let hoverCb: ((id: string) => void) | null = null
  let indexCb: ((ids: string[]) => void) | null = null

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
    dispose(): void {
      window.removeEventListener('message', onMessage)
    },
  }
}

export type CanvasBridge = ReturnType<typeof useCanvasBridge>
