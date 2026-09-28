import { describe, it, expect, beforeAll } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// The stage tells the editor what it shows (review of aa2801ec): a layout stage that fell back to its
// placeholder page — the sample unpublished mid-session — says so when it loads, so the sample picker
// stops naming a sample the stage no longer shows. The notice sits in the page's frame, outside every
// block, so it only comes or goes with a change of the page's shell, which reloads the stage whole:
// told on load, it is always current. (Its own file: the bridge is evaluated once per document.)
const source = readFileSync(
  resolve(process.cwd(), '../packages/thallo-render/assets/preview/preview-bridge.js'),
  'utf8',
)
// A plain list, not a mock: the suite clears mocks before each test, and these posts come before it.
const posted: Record<string, unknown>[] = []

beforeAll(() => {
  document.body.innerHTML =
    '<main><div class="thallo-layout-placeholder-notice" data-thallo-placeholder>No published posts yet — showing a placeholder</div></main>'
  window.postMessage = ((message: Record<string, unknown>) => {
    posted.push(message)
  }) as unknown as typeof window.postMessage
  new Function(source)()
  window.dispatchEvent(
    new MessageEvent('message', {
      data: { type: 'thallo:canvas-hello', nonce: 'stage-state-nonce' },
      origin: 'https://admin.test',
    }),
  )
})

describe('the stage state', () => {
  it('a stage showing its placeholder says so when it loads', () => {
    expect(posted.find((m) => m.type === 'thallo:stage-state')).toMatchObject({
      placeholder: true,
      nonce: 'stage-state-nonce',
    })
  })
})
