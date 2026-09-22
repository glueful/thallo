// Vitest global setup.
import { afterEach, beforeEach, vi } from 'vitest'
import { enableAutoUnmount } from '@vue/test-utils'
import { Tooltip } from '@unovis/ts'

// Unmount every @vue/test-utils wrapper after each test. Without this, wrappers stay mounted
// for the rest of the file while specs share module-level data refs — the next test's
// beforeEach reset re-renders the STALE wrappers against DOM jsdom has already torn down,
// surfacing as unhandled rejections ("Cannot set properties of null (setting '__vnode')")
// that fail the run (exit 1) even though every assertion passed — the same
// after-the-test-resolves failure class as the @unovis rAF/getBBox shim below.
enableAutoUnmount(afterEach)

// `openapi-fetch`'s createClient() captures `globalThis.fetch`/`globalThis.Request` once, at
// construction. Tests dynamically `await import('@/api/client')` per case and re-stub fetch in
// `beforeEach`, so without a module reset the cached singleton client keeps the first test's
// fetch mock (yielding stale/consumed responses). Resetting the module registry before each test
// makes every `await import()` re-create the client against the current per-test global fetch.
beforeEach(() => {
  vi.resetModules()
})

// In the jsdom environment the global `Request`/`fetch` come from Node's undici, which — unlike
// a real browser — does NOT resolve relative URLs against `location`. openapi-fetch builds a
// `new Request(baseUrl + path)` internally, and the app's runtime config uses a relative apiBase
// (e.g. '/v1/admin'), so the constructor would throw "Invalid URL" before any test assertion.
// This shim makes relative request URLs resolve against the jsdom origin, matching browser behavior.
const OriginalRequest = globalThis.Request

class BaseAwareRequest extends OriginalRequest {
  constructor(input: RequestInfo | URL, init?: RequestInit) {
    if (typeof input === 'string' && input.startsWith('/')) {
      input = new URL(input, globalThis.location?.origin ?? 'http://localhost').toString()
    }
    super(input, init)
  }
}

globalThis.Request = BaseAwareRequest as unknown as typeof Request

// Reka UI scrolls the selected listbox option into view when a SelectMenu opens.
// jsdom has no layout/scroll implementation, so provide the browser method as a no-op.
if (typeof globalThis.HTMLElement?.prototype.scrollIntoView !== 'function') {
  globalThis.HTMLElement.prototype.scrollIntoView = () => undefined
}

// @unovis's Tooltip positions itself from a throttled callback (throttle-debounce), which can
// fire AFTER the test that mounted the chart has resolved and jsdom has taken `document` away:
// `ReferenceError: document is not defined`, unhandled, failing the whole run (exit 1) with every
// assertion passed. It surfaced on CI, where the slower run let the timer outlive the
// environment. Same family as the rAF/getBBox shim below: make the late callback inert rather
// than let it reach a torn-down DOM.
const tooltipProto = Tooltip.prototype as unknown as Record<string, unknown>
const setContainerPosition = tooltipProto._setContainerPosition
if (typeof setContainerPosition === 'function') {
  tooltipProto._setContainerPosition = function (this: unknown, ...args: unknown[]): unknown {
    if (typeof document === 'undefined') return undefined
    return (setContainerPosition as (...a: unknown[]) => unknown).apply(this, args)
  }
}

// jsdom implements no SVG layout engine, so @unovis's axis auto-margin pass — which calls
// SVGGraphicsElement.getBBox()/getComputedTextLength() from inside a requestAnimationFrame —
// throws `getBBox is not a function`. Because it runs in a rAF callback it fires AFTER the test
// resolves, surfacing as an *unhandled* error that fails the whole run (exit 1) even though every
// assertion passed. Provide inert geometry so the chart render path completes silently in tests.
const svgProto = globalThis.SVGElement?.prototype as unknown as Record<string, unknown> | undefined
if (svgProto) {
  if (typeof svgProto.getBBox !== 'function') {
    svgProto.getBBox = () => ({ x: 0, y: 0, width: 0, height: 0 })
  }
  if (typeof svgProto.getComputedTextLength !== 'function') {
    svgProto.getComputedTextLength = () => 0
  }
}
