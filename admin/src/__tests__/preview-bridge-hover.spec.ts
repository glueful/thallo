import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// The stage's forced hover (hover state spec §6.3), driven against the STATIC bridge asset in jsdom
// as preview-bridge-dom.spec.ts drives it: every element the block owns for the forced targets or
// part gets [data-thallo-hover] — its own elements (scope `own`) or its direct child blocks' (scope
// `children`), never a nested or grandchild block's — and an in-place swap re-applies it.
const source = readFileSync(
  resolve(process.cwd(), '../packages/thallo-render/assets/preview/preview-bridge.js'),
  'utf8',
)
const NONCE = 'hover-nonce-1'
const posted = vi.fn()

function send(data: Record<string, unknown>): void {
  window.dispatchEvent(
    new MessageEvent('message', { data: { nonce: NONCE, ...data }, origin: 'https://admin.test' }),
  )
}
function force(
  id: string | null,
  rest: { targets?: string[]; part?: string | null; scope?: string } = {},
): void {
  send({ type: 'thallo:force-hover', id, targets: [], part: null, scope: 'own', ...rest })
}
const block = (id: string, inner: string) =>
  `<div class="thallo-preview-block" data-thallo-block="${id}">${inner}</div>`
const forced = () =>
  [...document.querySelectorAll('[data-thallo-hover]')].map((el) => el.getAttribute('data-k'))

beforeAll(() => {
  window.postMessage = posted as unknown as typeof window.postMessage
  new Function(source)()
  window.dispatchEvent(
    new MessageEvent('message', {
      data: { type: 'thallo:canvas-hello', nonce: NONCE },
      origin: 'https://admin.test',
    }),
  )
})

beforeEach(() => {
  force(null)
  document.body.innerHTML = ''
})

describe('forced hover', () => {
  it('own scope marks the block’s own part, not a direct child’s with the same name', () => {
    document.body.innerHTML = block(
      'hvA',
      `<a class="thallo-stage-part--icon" data-k="a"></a>` +
        block(
          'hvB',
          `<a class="thallo-stage-part--icon" data-k="b"></a>` +
            block('hvC', `<a class="thallo-stage-part--icon" data-k="c"></a>`),
        ),
    )
    force('hvA', { part: 'icon', scope: 'own' })
    expect(forced()).toEqual(['a'])
    force('hvA', { part: 'icon', scope: 'children' })
    expect(forced()).toEqual(['b'])
  })

  it('reaches every repeated owned element and none of a nested block’s', () => {
    document.body.innerHTML = block(
      'hvL',
      ['1', '2', '3'].map((k) => `<a class="thallo-stage-part--link" data-k="${k}"></a>`).join('') +
        block('hvL2', `<a class="thallo-stage-part--link" data-k="inner"></a>`),
    )
    force('hvL', { part: 'link' })
    expect(forced()).toEqual(['1', '2', '3'])
  })

  it('forces every named target', () => {
    document.body.innerHTML = block(
      'hvT',
      `<div class="thallo-stage-target--root" data-k="root"><h3 class="thallo-stage-target--title" data-k="title"></h3>` +
        block('hvT2', `<h3 class="thallo-stage-target--title" data-k="nested"></h3>`) +
        `</div>`,
    )
    force('hvT', { targets: ['root', 'title'] })
    expect(forced()).toEqual(['root', 'title'])
  })

  it('a new force replaces the old one, and null clears', () => {
    document.body.innerHTML =
      block('hvR1', `<a class="thallo-stage-target--control" data-k="one"></a>`) +
      block('hvR2', `<a class="thallo-stage-target--control" data-k="two"></a>`)
    force('hvR1', { targets: ['control'] })
    expect(forced()).toEqual(['one'])
    force('hvR2', { targets: ['control'] })
    expect(forced()).toEqual(['two'])
    force(null)
    expect(forced()).toEqual([])
  })

  it('ignores a junk name or scope', () => {
    document.body.innerHTML = block('hvJ', `<a class="thallo-stage-part--link" data-k="j"></a>`)
    force('hvJ', { part: 'li nk' })
    expect(forced()).toEqual([])
    force('hvJ', { part: 'link', scope: 'everything' })
    expect(forced()).toEqual([])
  })
})

describe('forced hover across an in-place swap', () => {
  const page = (epoch: string, revision: number, inner: string) =>
    `<main data-thallo-epoch="${epoch}" data-thallo-revision="${revision}">${inner}</main>`

  it('re-applies to the new nodes after a fragment swap', async () => {
    const links = (tag: string) =>
      ['1', '2', '3']
        .map((k) => `<a class="thallo-stage-part--link" data-k="${tag}${k}"></a>`)
        .join('')
    const inner = block('hvS', links('old'))
    // The stage learns its displayed pair from a refresh, as the fragment tests establish it.
    document.body.innerHTML = page('HOV', 1, inner)
    const html = `<!doctype html><html><body>${page('HOV', 1, inner)}</body></html>`
    window.fetch = vi.fn().mockResolvedValue({
      ok: true,
      redirected: false,
      text: () => Promise.resolve(html),
    }) as unknown as typeof window.fetch
    send({ type: 'thallo:stage-refresh', refresh_id: 'establish' })
    await new Promise((r) => setTimeout(r, 0))
    await new Promise((r) => setTimeout(r, 0))

    force('hvS', { part: 'link' })
    const before = [...document.querySelectorAll('[data-thallo-hover]')]
    expect(before).toHaveLength(3)
    send({
      type: 'thallo:fragments',
      refresh_id: 'swap',
      epoch: 'HOV',
      revision: 2,
      baseline_epoch: 'HOV',
      baseline_revision: 1,
      fragments: { hvS: block('hvS', links('new')) },
    })
    const after = [...document.querySelectorAll('[data-thallo-hover]')]
    expect(after.map((el) => el.getAttribute('data-k'))).toEqual(['new1', 'new2', 'new3'])
    for (const el of after) expect(before).not.toContain(el)
  })
})
