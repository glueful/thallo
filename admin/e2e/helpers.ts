// The proofs' world (visual builder spec §5.6): every request the Design page makes is answered
// from the captured fixtures — the API from admin/e2e/fixtures/api/*.json, the stage iframe from
// the rendered canvas.html, the theme and bridge assets from the repo — and every apply and save
// body is recorded, so a proof asserts what the editor SENT, not only what it shows.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import type { Page, Route } from '@playwright/test'

const ROOT = resolve(__dirname, '../..')
const FIXTURES = resolve(__dirname, 'fixtures')

function fixture(name: string): string {
  return readFileSync(resolve(FIXTURES, name), 'utf8')
}
function repoFile(rel: string): string {
  return readFileSync(resolve(ROOT, rel), 'utf8')
}

export interface Recorded {
  applies: {
    fields: Record<string, unknown>
    operations: Operation[]
    base_revision: number | null
    epoch: string | null
  }[]
  saves: { fields: Record<string, unknown> }[]
}

export interface Operation {
  type: string
  transaction_id: string
  [key: string]: unknown
}

export interface Entry {
  uuid: string
  locale: string
  type: string
}

export const entry: Entry = JSON.parse(fixture('entry.json')) as Entry

/** The Design page's URL for the fixture entry. */
export const designPath = `/admin/content/${entry.type}/${entry.uuid}/design/${entry.locale}`

const json = (route: Route, body: string, status = 200) =>
  route.fulfill({ status, contentType: 'application/json', body })
const text = (route: Route, body: string, contentType: string) =>
  route.fulfill({ status: 200, contentType, body })

/**
 * Route the world. Returns the recorder: `applies` and `saves` in the order the page sent them.
 * Every accepted apply answers with the next revision and no fragments (the stage refreshes
 * itself from the fixture page), so the page's accepted pair advances like the server's would.
 */
export async function routeWorld(page: Page): Promise<Recorded> {
  const recorded: Recorded = { applies: [], saves: [] }
  let revision = 0
  const unknown: string[] = []

  // The app shell and its runtime config.
  await page.route('**/admin/config', (route) => json(route, fixture('api/config.json')))
  await page.route('**/v1/auth/login', (route) => json(route, fixture('api/login.json')))
  await page.route('**/v1/auth/refresh-token', (route) => json(route, fixture('api/login.json')))

  // The stage: the rendered canvas and everything it links.
  await page.route('**/_preview/**', (route) => text(route, fixture('canvas.html'), 'text/html'))
  await page.route('**/_thallo/layers.css*', (route) =>
    text(route, fixture('layers.css'), 'text/css'),
  )
  // Playwright matches the LAST matching route first, so the catch-all for fonts and other theme
  // assets is registered BEFORE the two stylesheets it would otherwise swallow. With it after
  // them the stage rendered unstyled, and any proof that measures geometry measured nothing.
  await page.route('**/theme-assets/**', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/theme-assets/theme-*.css', (route) =>
    text(route, fixture('theme.css'), 'text/css'),
  )
  await page.route('**/theme-assets/settings-*.css', (route) =>
    text(route, fixture('settings.css'), 'text/css'),
  )
  await page.route('**/_thallo/preview.css*', (route) =>
    text(route, repoFile('packages/thallo-render/assets/preview/preview.css'), 'text/css'),
  )
  await page.route('**/_thallo/preview-bridge.js*', (route) =>
    text(
      route,
      repoFile('packages/thallo-render/assets/preview/preview-bridge.js'),
      'text/javascript',
    ),
  )
  await page.route('**/_thallo/runtime/runtime.js*', (route) =>
    text(route, repoFile('packages/thallo-render/runtime/runtime.js'), 'text/javascript'),
  )

  // The admin API.
  await page.route('**/v1/admin/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname.replace(/^.*\/v1\/admin/, '')
    const method = request.method()
    if (method === 'GET' && path === '/capabilities')
      return json(route, fixture('api/capabilities.json'))
    if (method === 'GET' && path === '/content-types')
      return json(route, fixture('api/content-types.json'))
    if (method === 'GET' && path === '/block-types')
      return json(route, fixture('api/block-types.json'))
    if (method === 'GET' && path === '/render/style-schema')
      return json(route, fixture('api/style-schema.json'))
    if (method === 'GET' && path === '/style-classes')
      return json(route, fixture('api/style-classes.json'))
    const factory = /^\/block-types\/([^/]+)\/instance$/.exec(path)
    if (method === 'POST' && factory) {
      return json(
        route,
        JSON.stringify({
          success: true,
          data: {
            block: { type: factory[1], data: {}, settings: {} },
            // A heading ships starter text, so a proof can assert the starter rode along.
            starter: factory[1] === 'heading' ? { text: 'Heading' } : {},
          },
        }),
      )
    }
    if (method === 'GET' && path === `/entries/${entry.uuid}/draft/${entry.locale}`) {
      return json(route, fixture('api/draft.json'))
    }
    if (method === 'PUT' && path === `/entries/${entry.uuid}/draft/${entry.locale}`) {
      recorded.saves.push(request.postDataJSON() as { fields: Record<string, unknown> })
      return json(
        route,
        JSON.stringify({ success: true, data: { preview_cleared: false, lock_version: 2 } }),
      )
    }
    if (method === 'POST' && path === `/entries/${entry.uuid}/preview/${entry.locale}`) {
      return json(route, fixture('api/mint.json'))
    }
    if (method === 'POST' && path === `/entries/${entry.uuid}/preview/${entry.locale}/apply`) {
      const body = request.postDataJSON() as Recorded['applies'][number]
      recorded.applies.push(body)
      revision += 1
      return json(
        route,
        JSON.stringify({
          success: true,
          data: {
            epoch: 'proof',
            revision,
            baseline: revision - 1,
            style_generation: 0,
            applied_at: new Date().toISOString(),
            fragments: null,
          },
        }),
      )
    }
    unknown.push(`${method} ${path}`)
    return json(route, JSON.stringify({ success: true, data: [] }))
  })
  page.on('close', () => {
    if (unknown.length > 0) console.warn('unrouted admin requests answered empty:', unknown)
  })
  return recorded
}

/** Sign in through the real login page (the session store is persisted, encrypted) and open the Design page. */
export async function openDesignPage(page: Page): Promise<Recorded> {
  const recorded = await routeWorld(page)
  await page.addInitScript(() => {
    // Manual apply: a proof decides when the server judges the tree.
    localStorage.setItem('thallo.canvas.auto_apply', '0')
  })
  async function signIn(): Promise<void> {
    await page.goto('/admin/login')
    await page.locator('input[type="email"]').fill('proofs@thallo.test')
    await page.locator('input[type="password"]').fill('builder-proofs')
    await page.locator('button[type="submit"]').click()
    await page.waitForURL((url) => !url.pathname.endsWith('/login'))
    // Let the shell finish its own startup requests: the session is persisted during them, and a
    // hard navigation before they settle is what the guard races against.
    await page.waitForLoadState('networkidle').catch(() => undefined)
  }
  // The persisted session rehydrates asynchronously, so on a slow load the route guard can run
  // first and bounce the design page back to sign-in. Whichever arrives is waited for, and a
  // bounce is answered by signing in again rather than by failing the proof on the app's startup
  // race. Three attempts: past that it is not a race any more.
  for (let attempt = 0; ; attempt++) {
    await signIn()
    await page.goto(designPath)
    const landed = await Promise.race([
      page
        .locator('[data-test="canvas-iframe"]')
        .waitFor({ timeout: 20_000 })
        .then(() => 'design' as const)
        .catch(() => 'timeout' as const),
      page
        .locator('input[type="password"]')
        .waitFor({ timeout: 20_000 })
        .then(() => 'login' as const)
        .catch(() => 'timeout' as const),
    ])
    if (landed === 'design') break
    if (attempt >= 2) throw new Error(`the Design page never opened (last state: ${landed})`)
  }
  await stage(page).locator('[data-thallo-block]').first().waitFor()
  return recorded
}

/** The stage iframe's document. */
export function stage(page: Page) {
  return page.frameLocator('[data-test="canvas-iframe"]')
}

export interface Hooks {
  history: { sequence: number; transaction_id: string; ops: Operation[] }[]
  currentSequence: number
  document: Record<string, unknown>
  accepted: { epoch: string; revision: number } | null
  selection: { ids: string[]; parent: string | null; slot: string | null; anchor: string | null }
}

/** The page's test hooks (VITE_E2E): history, the document, the accepted pair, the selection. */
export async function hooks(page: Page): Promise<Hooks> {
  return page.evaluate(() => {
    const w = window as unknown as { __thalloBuilder?: { snapshot: () => unknown } }
    if (!w.__thalloBuilder) throw new Error('the Design page exposes no test hooks (VITE_E2E)')
    return w.__thalloBuilder.snapshot() as unknown
  }) as Promise<Hooks>
}

/** Apply now and wait for the server's (routed) answer. */
export async function applyNow(
  page: Page,
  recorded: Recorded,
): Promise<Recorded['applies'][number]> {
  const before = recorded.applies.length
  await page.locator('[data-test="canvas-apply"]').click()
  await page.waitForFunction(
    (n) =>
      (
        window as unknown as { __thalloBuilder?: { applies: () => number } }
      ).__thalloBuilder?.applies() === n,
    before + 1,
  )
  return recorded.applies[before]!
}

/** The ids of a slot's blocks in document order, read from the document snapshot. */
export function idsIn(doc: Record<string, unknown>, path: (string | number)[]): string[] {
  let cursor: unknown = doc
  for (const step of path) cursor = (cursor as Record<string | number, unknown>)[step]
  return ((cursor as { id: string }[]) ?? []).map((b) => b.id)
}

/** Select a block on the stage by clicking its host; returns the toolbar's grip. */
export async function selectOnStage(page: Page, id: string) {
  const host = stage(page).locator(`[data-thallo-block="${id}"] > *`).first()
  await host.click({ position: { x: 4, y: 4 } })
  return stage(page).locator(`[data-thallo-block="${id}"] [data-action="drag"]`)
}

/** Drag the selected block's grip to a point over the stage (main-viewport coordinates), in steps. */
export async function dragGripTo(
  page: Page,
  grip: ReturnType<ReturnType<typeof stage>['locator']>,
  target: ReturnType<ReturnType<typeof stage>['locator']>,
  release = true,
): Promise<void> {
  // Both ends must sit in the iframe's viewport at once, still, and clear of the bridge's edge
  // auto-scroll zones (48px at the top and bottom): the outline selection scrolls the stage to
  // the anchor smoothly, and a pointer parked in an edge zone scrolls the content under itself,
  // so a point measured against one scroll position lands on a different slot.
  const frameBox = async () => (await page.locator('[data-test="canvas-iframe"]').boundingBox())!
  const same = (a: { x: number; y: number } | null, b: { x: number; y: number } | null) =>
    !!a && !!b && a.x === b.x && a.y === b.y
  const settle = async () => {
    let g = await grip.boundingBox()
    let t = await target.boundingBox()
    for (let i = 0; i < 30; i++) {
      await page.waitForTimeout(100)
      const ng = await grip.boundingBox()
      const nt = await target.boundingBox()
      if (same(g, ng) && same(t, nt)) break
      g = ng
      t = nt
    }
    if (!g) throw new Error('grip has no box')
    if (!t) throw new Error('target has no box')
    return { g, t }
  }
  await target.scrollIntoViewIfNeeded()
  await grip.scrollIntoViewIfNeeded()
  let { g, t } = await settle()
  const EDGE = 64
  const frame = await frameBox()
  const clear = (py: number) => py > frame.y + EDGE && py < frame.y + frame.height - EDGE
  const mid = (b: { y: number; height: number }) => b.y + b.height / 2
  if (!clear(mid(g)) || !clear(mid(t))) {
    // Scroll the stage so the pair's midpoint sits at its centre, then settle and measure again.
    const delta = (mid(g) + mid(t)) / 2 - (frame.y + frame.height / 2)
    await stage(page)
      .locator('body')
      .evaluate((_, d) => window.scrollBy({ top: d as number, behavior: 'instant' }), delta)
    ;({ g, t } = await settle())
  }
  if (!clear(mid(g)) || !clear(mid(t))) {
    throw new Error(
      `a drag end sits in the stage's auto-scroll zone (grip y=${mid(g)}, target y=${mid(t)}, stage ${frame.y}–${frame.y + frame.height})`,
    )
  }
  const startX = g.x + g.width / 2
  const startY = mid(g)
  const endX = t.x + t.width / 2
  const endY = mid(t)
  await page.mouse.move(startX, startY)
  await page.mouse.down()
  const steps = 12
  for (let i = 1; i <= steps; i++) {
    await page.mouse.move(
      startX + ((endX - startX) * i) / steps,
      startY + ((endY - startY) * i) / steps,
    )
  }
  await stage(page).locator('.thallo-canvas-dragging').first().waitFor({ timeout: 2000 })
  if (release) await page.mouse.up()
}

/** The main-viewport point at the middle of a stage element. */
export async function centerOf(locator: {
  boundingBox: () => Promise<{ x: number; y: number; width: number; height: number } | null>
}): Promise<{ x: number; y: number }> {
  const box = await locator.boundingBox()
  if (!box) throw new Error('element has no box')
  return { x: box.x + box.width / 2, y: box.y + box.height / 2 }
}

/** Open the inspector's Outline tab. */
export async function openOutline(page: Page): Promise<void> {
  await page.locator('[data-test="inspector-tabs"] button', { hasText: 'Outline' }).click()
  await page.locator('[data-test="outline-tab"]').waitFor()
}

/** Select through the outline (the page judges it and rings the stage); modifiers extend or toggle. */
export async function selectViaOutline(
  page: Page,
  id: string,
  modifiers: ('Shift' | 'Meta')[] = [],
): Promise<void> {
  await openOutline(page)
  await page.locator(`[data-test="canvas-outline-item-${id}"]`).click({ modifiers })
  await stage(page).locator(`[data-thallo-block="${id}"].thallo-canvas-selected`).waitFor()
}

/** The selected anchor's grip on the stage. */
export function gripOf(page: Page, id: string) {
  return stage(page).locator(`[data-thallo-block="${id}"] [data-action="drag"]`)
}

/** A slot element on the stage: the block's slot by field name. */
export function slotOf(page: Page, block: string, slot: string) {
  return stage(page).locator(`[data-thallo-block="${block}"] [data-thallo-slot="${slot}"]`).first()
}

/** Wait until history holds `n` entries and return the hooks. */
export async function historyLength(page: Page, n: number): Promise<Hooks> {
  await page.waitForFunction(
    (expected) =>
      (
        window as unknown as { __thalloBuilder: { snapshot: () => Hooks } }
      ).__thalloBuilder.snapshot().history.length === expected,
    n,
  )
  return hooks(page)
}

/** The stage's drop indicator. */
export function indicator(page: Page) {
  return stage(page).locator('.thallo-canvas-drop-line')
}

/** Open the inspector's Blocks tab (the Design page's one palette, Phase C.1). */
export async function openBlocksTab(page: Page): Promise<void> {
  await page.locator('[data-test="inspector-tabs"] button', { hasText: 'Blocks' }).click()
  await page.locator('[data-test="blocks-tab"]').waitFor()
}

/**
 * Drag a palette tile onto a stage element (main-viewport coordinates, in steps). The tile holds
 * pointer capture, so the parent keeps receiving the moves over the iframe. `release` false
 * leaves the pointer down over the target.
 */
export async function dragTileTo(
  page: Page,
  slug: string,
  target: ReturnType<ReturnType<typeof stage>['locator']>,
  release = true,
): Promise<void> {
  const tile = page.locator(`[data-test="palette-card-${slug}"]`)
  await tile.scrollIntoViewIfNeeded()
  // The target must sit in the iframe's viewport, clear of the bridge's edge auto-scroll zones:
  // a hover past the edge finds no element, and one near it scrolls the content away.
  await target.evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'instant' }))
  await page.waitForTimeout(150)
  const from = await centerOf(tile)
  await page.mouse.move(from.x, from.y)
  await page.mouse.down()
  const to = await centerOf(target)
  const steps = 12
  for (let i = 1; i <= steps; i++) {
    await page.mouse.move(
      from.x + ((to.x - from.x) * i) / steps,
      from.y + ((to.y - from.y) * i) / steps,
    )
  }
  await page.locator('.thallo-palette-ghost').waitFor({ timeout: 2000 })
  if (release) await page.mouse.up()
}
