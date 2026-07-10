// Vitest global setup.
import { beforeEach, vi } from 'vitest'

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
