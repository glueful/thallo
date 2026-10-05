// The proofs' world (visual builder spec §5.6): every request the Design page makes is answered
// from the captured fixtures — the API from admin/e2e/fixtures/api/*.json, the stage iframe from
// the rendered canvas.html, the theme and bridge assets from the repo — and every apply and save
// body is recorded, so a proof asserts what the editor SENT, not only what it shows.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import type { Page, Route } from '@playwright/test'

const ROOT = resolve(__dirname, '../..')
const FIXTURES = resolve(__dirname, 'fixtures')

export function fixture(name: string): string {
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
  /** How many times the stage document was requested: a whole refresh adds one. */
  stageLoads: number
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
/** What a proof may put in the world beyond the captured fixtures. */
export interface World {
  /** The site's style classes; the captured install has none. */
  styleClasses?: Record<string, unknown>[]
  /**
   * The entry's type has a layout (type layouts spec §6.3): the stage is the entry rendered inside
   * it, and the mint and every apply name it.
   */
  underLayout?: boolean
  /**
   * Commerce is off: the library is the server's answer from a boot with the capability off
   * (api/patterns-commerce-off.json), so no shop section or template is in it.
   */
  commerceOff?: boolean
}

export async function routeWorld(page: Page, world: World = {}): Promise<Recorded> {
  const recorded: Recorded = { applies: [], saves: [], stageLoads: 0 }
  let revision = 0
  const unknown: string[] = []

  // The app shell and its runtime config.
  await page.route('**/admin/config', (route) => json(route, fixture('api/config.json')))
  await page.route('**/v1/auth/login', (route) => json(route, fixture('api/login.json')))
  await page.route('**/v1/auth/refresh-token', (route) => json(route, fixture('api/login.json')))

  // The stage: the rendered canvas and everything it links.
  await page.route('**/_preview/**', (route) => {
    recorded.stageLoads += 1
    return text(
      route,
      fixture(world.underLayout ? 'layouts/canvas-under-layout.html' : 'canvas.html'),
      'text/html',
    )
  })
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
  // The appearance fingerprint of the last stage document served (block typeface plan Task 11).
  let renderedFingerprint: string | null = null
  page.on('response', async (response) => {
    if (response.request().resourceType() !== 'document') return
    const body = await response.text().catch(() => '')
    const rendered = /data-thallo-appearance-fingerprint="([^"]+)"/.exec(body)
    if (rendered) renderedFingerprint = rendered[1]!
  })
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
    if (method === 'GET' && path === '/patterns') {
      // A layout's library (sections and templates design §4): the server's answer for the post layout.
      if (url.searchParams.get('surface') === 'entry' && url.searchParams.get('target') === 'post')
        return json(route, fixture('api/patterns-layout-entry-post.json'))
      return json(
        route,
        fixture(world.commerceOff ? 'api/patterns-commerce-off.json' : 'api/patterns.json'),
      )
    }
    if (method === 'GET' && path === '/render/style-schema')
      return json(route, fixture('api/style-schema.json'))
    // The font library as the server answers it, with one family read from a real .woff2 file
    // (block typeface plan Task 11); and the stage's freshness check, which never changes here.
    if (method === 'GET' && path === '/fonts') return json(route, fixture('api/fonts.json'))
    if (method === 'GET' && /^\/fonts\/[^/]+\/usage$/.test(path)) {
      return json(
        route,
        JSON.stringify({
          success: true,
          data: {
            entries: [],
            regions: [],
            layouts: [],
            saved_sections: [],
            style_classes: [],
            appearance: { text: false, headings: false },
          },
        }),
      )
    }
    if (method === 'GET' && path === '/render/appearance-fingerprint') {
      // What the stage on screen was rendered with: nothing changed elsewhere, so no stage reloads.
      // Until that is known, the check fails — as offline does — and the stage is left alone.
      if (renderedFingerprint === null) return route.fulfill({ status: 503, body: '' })
      return json(
        route,
        JSON.stringify({ success: true, data: { appearance_fingerprint: renderedFingerprint } }),
      )
    }
    // A block field's server-provided choices (search block spec §3.9), as the server answers.
    const options = /^\/field-options\/([^/]+)$/.exec(path)
    if (method === 'GET' && options)
      return json(route, fixture(`api/field-options-${decodeURIComponent(options[1]!)}.json`))
    if (method === 'GET' && path === '/style-classes') {
      if (world.styleClasses === undefined) return json(route, fixture('api/style-classes.json'))
      return json(
        route,
        JSON.stringify({
          success: true,
          data: { generation: 1, style_classes: world.styleClasses },
        }),
      )
    }
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
      return json(route, fixture(world.underLayout ? 'layouts/entry-mint.json' : 'api/mint.json'))
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
            // Under a layout every accepted apply names it, as the mint did.
            ...(world.underLayout
              ? {
                  layout: (
                    JSON.parse(fixture('layouts/entry-mint.json')) as { data: { layout: unknown } }
                  ).data.layout,
                }
              : {}),
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

/**
 * Sign in through the real login page and open `path`, waiting for `ready`. The persisted session
 * rehydrates asynchronously, so on a slow load the route guard can run first and bounce the page
 * back to sign-in. Whichever arrives is waited for, and a bounce is answered by signing in again
 * rather than by failing the proof on the app's startup race. Three attempts: past that it is not
 * a race any more.
 */
async function signInAndOpen(page: Page, path: string, ready: string, what: string): Promise<void> {
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
  for (let attempt = 0; ; attempt++) {
    await signIn()
    await page.goto(path)
    const landed = await Promise.race([
      page
        .locator(ready)
        .waitFor({ timeout: 20_000 })
        .then(() => 'ready' as const)
        .catch(() => 'timeout' as const),
      page
        .locator('input[type="password"]')
        .waitFor({ timeout: 20_000 })
        .then(() => 'login' as const)
        .catch(() => 'timeout' as const),
    ])
    if (landed === 'ready') return
    if (attempt >= 2) throw new Error(`${what} never opened (last state: ${landed})`)
  }
}

/** Sign in through the real login page (the session store is persisted, encrypted) and open the Design page. */
export async function openDesignPage(page: Page, world: World = {}): Promise<Recorded> {
  const recorded = await routeWorld(page, world)
  await page.addInitScript(() => {
    // Manual apply: a proof decides when the server judges the tree.
    localStorage.setItem('thallo.canvas.auto_apply', '0')
  })
  await signInAndOpen(page, designPath, '[data-test="canvas-iframe"]', 'the Design page')
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
  // By role: the Blocks tab's own view switch has a "Blocks" button too.
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Blocks', exact: true })
    .click()
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
  /** Where on the target to aim, as a fraction of its height: the middle by default. */
  at = 0.5,
): Promise<void> {
  return dragCardTo(page, `[data-test="palette-card-${slug}"]`, target, release, at)
}

/** The same gesture from any palette card: a block tile, or a section of the library. */
export async function dragCardTo(
  page: Page,
  card: string,
  target: ReturnType<ReturnType<typeof stage>['locator']>,
  release = true,
  at = 0.5,
): Promise<void> {
  const aim = async () => {
    const box = await target.boundingBox()
    if (!box) throw new Error('element has no box')
    return { x: box.x + box.width / 2, y: box.y + box.height * at }
  }
  const tile = page.locator(card)
  await tile.scrollIntoViewIfNeeded()
  // The target must sit in the iframe's viewport, clear of the bridge's edge auto-scroll zones:
  // a hover past the edge finds no element, and one near it scrolls the content away.
  await target.evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'instant' }))
  await page.waitForTimeout(150)
  const from = await centerOf(tile)
  await page.mouse.move(from.x, from.y)
  await page.mouse.down()
  const to = await aim()
  const steps = 12
  for (let i = 1; i <= steps; i++) {
    await page.mouse.move(
      from.x + ((to.x - from.x) * i) / steps,
      from.y + ((to.y - from.y) * i) / steps,
    )
  }
  let last = to
  await page.locator('.thallo-palette-ghost').waitFor({ timeout: 2000 })
  // The stage scrolls itself while the pointer sits near its edge, so the target may have moved
  // out from under the point the moves aimed at. Re-aim at where it is now, until it stops moving;
  // two moves, because the zone is proposed on a move and answered a round-trip later.
  for (let i = 0; i < 4; i++) {
    await page.waitForTimeout(250)
    const now = await aim()
    if (i > 0 && Math.abs(now.x - last.x) < 2 && Math.abs(now.y - last.y) < 2) break
    await page.mouse.move(now.x, now.y - 1)
    await page.mouse.move(now.x, now.y)
    last = now
  }
  if (release) await page.mouse.up()
}

/** Open a new style class in the style class editor (block typeface plan Task 11). */
export async function openStyleClassEditor(page: Page): Promise<Recorded> {
  const recorded = await routeWorld(page)
  await signInAndOpen(
    page,
    '/admin/settings/style-classes/new',
    '[data-test="style-class-editor"]',
    'the style class editor',
  )
  return recorded
}

// ── The regions stage (regions stage plan R8) ─────────────────────────────────────────────────

export interface RegionsDocument {
  header: { blocks: unknown[]; settings: unknown }
  footer: { blocks: unknown[]; settings: unknown }
}

export interface RegionsRecorded {
  sessions: { page: string | null }[]
  applies: {
    token: string
    regions: RegionsDocument
    epoch: string | null
    base_revision: number | null
    operations: Operation[]
  }[]
  saves: {
    regions: Partial<RegionsDocument>
    expected: Record<string, number | null>
    token: string | null
    preview_revision: { epoch: string; revision: number } | null
  }[]
  /** Stage requests no fixture could answer: a proof fails on any. */
  unmatched: string[]
}

interface StageFixture {
  page: 'home' | 'pageb'
  document: RegionsDocument
  file: string
}

/** Keys sorted, and an empty list the same as an empty object: PHP writes `{}` as `[]`. */
function canonical(value: unknown): unknown {
  if (Array.isArray(value)) return value.length === 0 ? {} : value.map(canonical)
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(
      Object.keys(value)
        .sort()
        .map((key) => [key, canonical((value as Record<string, unknown>)[key])]),
    )
  }
  return value
}
const same = (a: unknown, b: unknown) =>
  JSON.stringify(canonical(a)) === JSON.stringify(canonical(b))

/**
 * Open the Header & footer page on the stage, in a world built from the regions fixtures. The
 * session, apply and save endpoints are routed and recorded; each mocked session keeps its own
 * `{epoch, revision}` as the server does (a stale pair answers PREVIEW_REVISION_STALE); and the
 * stage is served from the fixture whose document equals the session's last accepted document —
 * before any apply, its own baseline (`baseline`, or the `container`, `links` or `empty` session's) — on the
 * session's page, with its revision metadata rewritten to the pair just accepted. A combination no
 * fixture renders is recorded in `unmatched` and answered 500, never with a stale stage.
 */
export async function openRegionsStage(
  page: Page,
  options: { session?: 'baseline' | 'container' | 'links' | 'empty' } = {},
): Promise<RegionsRecorded> {
  await routeWorld(page)
  // The site's pages live at its root, as a new install's `pages` type does: the page picker lists
  // that type's entries, whatever its slug (registered after routeWorld, so it answers first).
  await page.route('**/v1/admin/content-types', (route) => {
    const types = JSON.parse(fixture('api/content-types.json')) as {
      data: { content_types: Record<string, unknown>[] }
    }
    types.data.content_types.push({
      ...types.data.content_types[0],
      uuid: 'pagestype001',
      slug: 'pages',
      name: 'Pages',
      public_delivery: true,
      mount_at_root: true,
    })
    return json(route, JSON.stringify(types))
  })
  const recorded: RegionsRecorded = { sessions: [], applies: [], saves: [], unmatched: [] }
  const stages = JSON.parse(fixture('regions/stages.json')) as Record<string, StageFixture>
  const pages = JSON.parse(fixture('regions/pages.json')) as Record<'home' | 'pageb', string>
  const pageName = (uuid: string | null | undefined): 'home' | 'pageb' =>
    uuid === pages.pageb ? 'pageb' : 'home'
  const first = options.session ?? 'baseline'
  /**
   * A session response opening on the named state: the captured session, or — for `empty` — the
   * captured one with both regions as that scenario holds them (its versions kept).
   */
  const openingFor = (name: string) => {
    const opening = JSON.parse(
      fixture(
        name === 'container' || name === 'links'
          ? `regions/session-${name}.json`
          : 'regions/session.json',
      ),
    ) as { data: { regions: Record<string, Record<string, unknown>> } & Record<string, unknown> }
    if (name === 'empty') {
      for (const slug of ['header', 'footer'] as const) {
        opening.data.regions[slug] = {
          ...opening.data.regions[slug],
          ...stages.empty!.document[slug],
        }
      }
    }
    return opening
  }
  const baselineSession = openingFor(first) as unknown as {
    data: { regions: Record<string, { lock_version: number | null }> }
  }
  const versions: Record<string, number | null> = {
    header: baselineSession.data.regions.header?.lock_version ?? null,
    footer: baselineSession.data.regions.footer?.lock_version ?? null,
  }

  interface MockSession {
    page: 'home' | 'pageb'
    baseline: RegionsDocument
    epoch: string
    revision: number
    accepted: RegionsDocument | null
  }
  const sessions = new Map<string, MockSession>()

  await page.route('**/v1/admin/regions', async (route) => {
    const request = route.request()
    if (request.method() === 'PUT') {
      const body = request.postDataJSON() as RegionsRecorded['saves'][number]
      recorded.saves.push(body)
      const moved = Object.entries(body.expected).some(([slug, v]) => versions[slug] !== v)
      if (moved) {
        return json(
          route,
          JSON.stringify({
            success: false,
            message: 'A region changed since it was loaded.',
            error: { details: { code: 'REGION_VERSION_CONFLICT', moved: [] } },
          }),
          409,
        )
      }
      const committed: Record<string, unknown> = {}
      for (const slug of ['header', 'footer'] as const) {
        const posted = body.regions[slug]
        if (posted) versions[slug] = (versions[slug] ?? -1) + 1
        committed[slug] = {
          ...(posted ?? { blocks: [], settings: {} }),
          lock_version: versions[slug],
        }
      }
      return json(
        route,
        JSON.stringify({ success: true, data: { regions: committed, preview_cleared: false } }),
      )
    }
    return json(route, fixture('regions/regions.json'))
  })
  await page.route('**/v1/admin/entries?*', (route) =>
    new URL(route.request().url()).searchParams.get('type') !== 'pages'
      ? route.fulfill({ status: 404, body: '{"success":false}' })
      : json(
          route,
          JSON.stringify({
            success: true,
            data: {
              entries: [
                {
                  uuid: pages.pageb,
                  display_title: 'Page B',
                  status: 'published',
                  locales: ['en'],
                  updated_at: null,
                },
              ],
              total: 1,
              current_page: 1,
              per_page: 100,
            },
          }),
        ),
  )
  await page.route('**/v1/admin/block-types/*/instance', (route) => {
    const slug = /block-types\/([^/]+)\/instance/.exec(route.request().url())![1]!
    return json(route, fixture(`api/instance-${slug}.json`))
  })
  await page.route('**/v1/admin/regions/preview/session', (route) => {
    const body = (route.request().postDataJSON() ?? {}) as { page?: string }
    recorded.sessions.push({ page: body.page ?? null })
    const n = recorded.sessions.length
    const token = `proof-session-${n}`
    // The first session is the page's opening one; later ones (a switch, a renewal) are restored
    // by an apply before they are shown.
    const baselineName = n === 1 ? first : 'baseline'
    sessions.set(token, {
      page: pageName(body.page),
      baseline: stages[baselineName]!.document,
      epoch: `proof-epoch-${n}`,
      revision: 0,
      accepted: null,
    })
    const opening = openingFor(baselineName)
    return json(
      route,
      JSON.stringify({
        success: true,
        data: { ...opening.data, token, theme_url: `/_preview/${token}` },
      }),
    )
  })
  await page.route('**/v1/admin/regions/preview/apply', (route) => {
    const body = route.request().postDataJSON() as RegionsRecorded['applies'][number]
    recorded.applies.push(body)
    const session = sessions.get(body.token)
    if (!session) return json(route, JSON.stringify({ success: false, message: 'expired' }), 410)
    const current =
      session.revision === 0 ? null : { epoch: session.epoch, revision: session.revision }
    const matches =
      current === null
        ? body.epoch === null || body.epoch === session.epoch
        : body.epoch === current.epoch && body.base_revision === current.revision
    if (!matches) {
      return json(
        route,
        JSON.stringify({
          success: false,
          message: 'The preview working copy moved on.',
          error: { details: { code: 'PREVIEW_REVISION_STALE', current } },
        }),
        409,
      )
    }
    session.revision += 1
    session.accepted = body.regions
    return json(
      route,
      JSON.stringify({
        success: true,
        data: {
          epoch: session.epoch,
          revision: session.revision,
          baseline: session.revision - 1,
          style_generation: 0,
          applied_at: new Date().toISOString(),
        },
      }),
    )
  })
  await page.route('**/_preview/**', (route) => {
    const token = new URL(route.request().url()).pathname.split('/').pop() ?? ''
    const session = sessions.get(token)
    if (!session) return route.fulfill({ status: 410, body: 'expired' })
    const doc = session.accepted ?? session.baseline
    const hit = Object.entries(stages).find(
      ([, s]) => s.page === session.page && same(s.document, doc),
    )
    if (!hit) {
      recorded.unmatched.push(
        `no rendered fixture for page ${session.page} and document ${JSON.stringify(canonical(doc))}`,
      )
      return route.fulfill({ status: 500, body: 'no rendered fixture' })
    }
    const html = fixture(`regions/${hit[1].file}`)
      .replace(/data-thallo-epoch="[^"]*"/, `data-thallo-epoch="${session.epoch}"`)
      .replace(/data-thallo-revision="[^"]*"/, `data-thallo-revision="${session.revision}"`)
    return text(route, html, 'text/html')
  })

  await signInAndOpen(page, '/admin/regions', '[data-test="regions-stage"]', 'the Regions page')
  await regionsStage(page).locator('[data-thallo-slot="header"]').first().waitFor()
  return recorded
}

/** The regions stage iframe's document. */
export function regionsStage(page: Page) {
  return page.frameLocator('[data-test="regions-stage"]')
}

/** Wait until the last accepted apply's document is the named scenario's, exactly. */
export async function acceptedIs(
  page: Page,
  recorded: RegionsRecorded,
  name: string,
): Promise<void> {
  const stages = JSON.parse(fixture('regions/stages.json')) as Record<string, StageFixture>
  const want = stages[name]!.document
  const deadline = Date.now() + 10_000
  for (;;) {
    const last = recorded.applies.at(-1)?.regions
    if (last && same(last, want)) return
    if (Date.now() > deadline) {
      throw new Error(
        `the accepted document is not '${name}':\n  want ${JSON.stringify(canonical(want))}\n  got  ${JSON.stringify(canonical(last))}`,
      )
    }
    await page.waitForTimeout(100)
  }
}

// ── The layout stage (type layouts plan L9) ───────────────────────────────────────────────────

export interface LayoutDocument {
  blocks: unknown[]
  settings: unknown
}

export interface LayoutRecorded {
  sessions: { surface: string; target: string; sample: string | null }[]
  applies: {
    token: string
    layout: LayoutDocument
    epoch: string | null
    base_revision: number | null
    operations: Operation[]
  }[]
  saves: {
    token: string
    layout: LayoutDocument
    expected_lock_version: number
    preview_revision: { epoch: string; revision: number } | null
  }[]
  /** Stage requests no fixture could answer: a proof fails on any. */
  unmatched: string[]
}

interface LayoutStageFixture {
  layout: LayoutDocument
  file: string
}

/**
 * The layout worlds the fixtures capture: the post type's layout, the shop's product page, and the
 * post listing's pages — around its first page, and around the placeholder page while nothing is
 * published.
 */
type LayoutWorld =
  | 'post'
  | 'product'
  | 'listing'
  | 'listing-placeholder'
  | 'shop'
  | 'shop-placeholder'
  | 'shop-after-remove'
const LAYOUT_WORLDS: Record<
  LayoutWorld,
  {
    session: string
    samples: string
    stages: string
    endpoint: string
    path: string
    ready: string
  }
> = {
  post: {
    session: 'layouts/session.json',
    samples: 'layouts/samples.json',
    stages: 'layouts/stages.json',
    endpoint: '**/v1/admin/layouts/entry/post',
    path: '/admin/layouts/entry/post',
    ready: '.thallo-layout--entry',
  },
  product: {
    session: 'layouts/product-session.json',
    samples: 'layouts/product-samples.json',
    stages: 'layouts/product-stages.json',
    // `@site` travels percent-encoded; a glob segment matches it.
    endpoint: '**/v1/admin/layouts/product/*',
    path: '/admin/layouts/product/@site',
    ready: '.shop-product--layout',
  },
  listing: {
    session: 'layouts/listing-session.json',
    samples: 'layouts/listing-samples.json',
    stages: 'layouts/listing-stages.json',
    endpoint: '**/v1/admin/layouts/listing/post',
    path: '/admin/layouts/listing/post',
    ready: '.thallo-block-entry_loop',
  },
  'listing-placeholder': {
    session: 'layouts/listing-placeholder-session.json',
    samples: 'layouts/listing-placeholder-samples.json',
    stages: 'layouts/listing-placeholder-stages.json',
    endpoint: '**/v1/admin/layouts/listing/post',
    path: '/admin/layouts/listing/post',
    ready: '.thallo-block-entry_loop',
  },
  // The shop home (type layouts plan C2): the seeded shop's first page, a shop with no products,
  // and the starter a fresh session opens on once a saved layout is removed.
  shop: {
    session: 'layouts/shop-session.json',
    samples: 'layouts/shop-samples.json',
    stages: 'layouts/shop-stages.json',
    endpoint: '**/v1/admin/layouts/shop_index/*',
    path: '/admin/layouts/shop_index/@site',
    ready: '.thallo-block-product_loop',
  },
  'shop-placeholder': {
    session: 'layouts/shop-placeholder-session.json',
    samples: 'layouts/shop-placeholder-samples.json',
    stages: 'layouts/shop-placeholder-stages.json',
    endpoint: '**/v1/admin/layouts/shop_index/*',
    path: '/admin/layouts/shop_index/@site',
    ready: '.thallo-block-product_loop',
  },
  'shop-after-remove': {
    session: 'layouts/shop-after-remove-session.json',
    samples: 'layouts/shop-samples.json',
    stages: 'layouts/shop-after-remove-stages.json',
    endpoint: '**/v1/admin/layouts/shop_index/*',
    path: '/admin/layouts/shop_index/@site',
    ready: '.thallo-block-product_loop',
  },
}

/**
 * Open the post layout's editor on the stage, in a world built from the layout fixtures. The
 * session, apply, samples and save endpoints are routed and recorded; each mocked session keeps its
 * own `{epoch, revision}` as the server does; a save whose expected version is not the stored one
 * answers LAYOUT_VERSION_CONFLICT (`options.moved` moves the stored version before the first save,
 * as another editor's save would); and the stage is served from the fixture whose layout equals the
 * session's last accepted layout, with its revision metadata rewritten to the pair just accepted.
 */
export async function openLayoutStage(
  page: Page,
  options: { moved?: boolean; world?: LayoutWorld; opening?: string } = {},
): Promise<LayoutRecorded> {
  const world = LAYOUT_WORLDS[options.world ?? 'post']
  await routeWorld(page)
  const recorded: LayoutRecorded = { sessions: [], applies: [], saves: [], unmatched: [] }
  const stages = JSON.parse(fixture(world.stages)) as Record<string, LayoutStageFixture>
  const opening = JSON.parse(fixture(world.session)) as {
    data: { layout: LayoutDocument & { lock_version: number } } & Record<string, unknown>
  }
  let stored = opening.data.layout.lock_version + (options.moved ? 1 : 0)
  // The state the session opens on: the world's baseline, or another of its rendered states (a
  // saved layout the page opens with, as the server would hand it).
  const openingStage = stages[options.opening ?? 'baseline']!
  interface MockSession {
    baseline: LayoutDocument
    epoch: string
    revision: number
    accepted: LayoutDocument | null
  }
  const sessions = new Map<string, MockSession>()

  await page.route(`${world.endpoint}/samples*`, (route) => json(route, fixture(world.samples)))
  await page.route(world.endpoint, (route) => {
    const request = route.request()
    if (request.method() !== 'PUT') return route.fulfill({ status: 405, body: '{}' })
    const body = request.postDataJSON() as LayoutRecorded['saves'][number]
    recorded.saves.push(body)
    if (body.expected_lock_version !== stored) {
      return json(
        route,
        JSON.stringify({
          success: false,
          message: 'This layout changed since it was loaded.',
          error: { details: { code: 'LAYOUT_VERSION_CONFLICT', current: stored } },
        }),
        409,
      )
    }
    stored += 1
    return json(
      route,
      JSON.stringify({
        success: true,
        data: { layout: { ...body.layout, lock_version: stored }, preview_cleared: false },
      }),
    )
  })
  await page.route('**/v1/admin/block-types/*/instance', (route) => {
    const slug = /block-types\/([^/]+)\/instance/.exec(route.request().url())![1]!
    return json(route, fixture(`api/instance-${slug}.json`))
  })
  await page.route('**/v1/admin/layouts/preview/session', (route) => {
    const body = (route.request().postDataJSON() ?? {}) as {
      surface: string
      target: string
      sample?: string
    }
    recorded.sessions.push({
      surface: body.surface,
      target: body.target,
      sample: body.sample ?? null,
    })
    const n = recorded.sessions.length
    const token = `proof-layout-${n}`
    sessions.set(token, {
      baseline: openingStage.layout,
      epoch: `proof-epoch-${n}`,
      revision: 0,
      accepted: null,
    })
    // The page opens on the captured version; a later mint (Reload after a conflict) opens on the
    // stored one, as a fresh mint would after another editor's save.
    const layout = {
      ...opening.data.layout,
      blocks: openingStage.layout.blocks,
      settings: openingStage.layout.settings,
      lock_version: n === 1 ? opening.data.layout.lock_version : stored,
    }
    return json(
      route,
      JSON.stringify({
        success: true,
        data: { ...opening.data, layout, token, theme_url: `/_preview/${token}` },
      }),
    )
  })
  await page.route('**/v1/admin/layouts/preview/apply', (route) => {
    const body = route.request().postDataJSON() as LayoutRecorded['applies'][number]
    recorded.applies.push(body)
    const session = sessions.get(body.token)
    if (!session) {
      return json(
        route,
        JSON.stringify({ success: false, error: { details: { code: 'LAYOUT_SESSION_EXPIRED' } } }),
        410,
      )
    }
    const current =
      session.revision === 0 ? null : { epoch: session.epoch, revision: session.revision }
    const matches =
      current === null
        ? body.epoch === null || body.epoch === session.epoch
        : body.epoch === current.epoch && body.base_revision === current.revision
    if (!matches) {
      return json(
        route,
        JSON.stringify({
          success: false,
          message: 'The preview working copy moved on.',
          error: { details: { code: 'PREVIEW_REVISION_STALE', current } },
        }),
        409,
      )
    }
    session.revision += 1
    session.accepted = body.layout
    return json(
      route,
      JSON.stringify({
        success: true,
        data: {
          epoch: session.epoch,
          revision: session.revision,
          baseline: session.revision - 1,
          style_generation: 0,
          applied_at: new Date().toISOString(),
          fragments: null,
        },
      }),
    )
  })
  await page.route('**/_preview/**', (route) => {
    const token = new URL(route.request().url()).pathname.split('/').pop() ?? ''
    const session = sessions.get(token)
    if (!session) return route.fulfill({ status: 410, body: 'expired' })
    const doc = session.accepted ?? session.baseline
    const hit = Object.entries(stages).find(([, s]) => same(s.layout, doc))
    if (!hit) {
      recorded.unmatched.push(`no rendered fixture for layout ${JSON.stringify(canonical(doc))}`)
      return route.fulfill({ status: 500, body: 'no rendered fixture' })
    }
    const html = fixture(`layouts/${hit[1].file}`)
      .replace(/data-thallo-epoch="[^"]*"/, `data-thallo-epoch="${session.epoch}"`)
      .replace(/data-thallo-revision="[^"]*"/, `data-thallo-revision="${session.revision}"`)
    return text(route, html, 'text/html')
  })

  await signInAndOpen(page, world.path, '[data-test="layout-stage"]', 'the layout editor')
  await layoutStage(page).locator(world.ready).first().waitFor()
  return recorded
}

export interface LayoutsPageRecorded {
  /** How many times the list was read: a removal reads it again. */
  lists: number
  sessions: { surface: string; target: string }[]
  removes: { path: string; body: { token: string; expected_lock_version: number } }[]
}

/**
 * Open Site › Layouts in a world where the post listing's layout is kept while its pages are off the
 * site (the type taken off the listing types): the list, the closed session its Remove opens and the
 * removal are routed from the fixtures the real controllers wrote, and recorded.
 */
export async function openLayoutsPage(page: Page): Promise<LayoutsPageRecorded> {
  await routeWorld(page)
  const recorded: LayoutsPageRecorded = { lists: 0, sessions: [], removes: [] }
  await page.route('**/v1/admin/layouts', (route) => {
    recorded.lists += 1
    // The fixture script reads the list with no signed-in user; the proof's user may edit layouts
    // (templates.manage), as the list answers such an editor.
    const list = JSON.parse(fixture('layouts/layouts-kept.json')) as { data: { can_edit: boolean } }
    list.data.can_edit = true
    return json(route, JSON.stringify(list))
  })
  await page.route('**/v1/admin/layouts/preview/session', (route) => {
    const body = (route.request().postDataJSON() ?? {}) as { surface: string; target: string }
    recorded.sessions.push({ surface: body.surface, target: body.target })
    return json(route, fixture('layouts/listing-closed-session.json'))
  })
  await page.route('**/v1/admin/layouts/listing/post', (route) => {
    const request = route.request()
    if (request.method() !== 'DELETE') return route.fulfill({ status: 405, body: '{}' })
    const body = request.postDataJSON() as LayoutsPageRecorded['removes'][number]['body']
    recorded.removes.push({ path: new URL(request.url()).pathname, body })
    return json(
      route,
      JSON.stringify({
        success: true,
        data: { lock_version: body.expected_lock_version + 1 },
      }),
    )
  })
  await signInAndOpen(page, '/admin/layouts', '[data-test="layouts-list"]', 'the Layouts page')
  return recorded
}

/** The layout stage iframe's document. */
export function layoutStage(page: Page) {
  return page.frameLocator('[data-test="layout-stage"]')
}

/** Wait until the last accepted apply's layout is the named scenario's, exactly. */
export async function layoutAcceptedIs(
  page: Page,
  recorded: LayoutRecorded,
  name: string,
  world: LayoutWorld = 'post',
): Promise<void> {
  const stages = JSON.parse(fixture(LAYOUT_WORLDS[world].stages)) as Record<
    string,
    LayoutStageFixture
  >
  const want = stages[name]!.layout
  const deadline = Date.now() + 10_000
  for (;;) {
    const last = recorded.applies.at(-1)?.layout
    if (last && same(last, want)) return
    if (Date.now() > deadline) {
      throw new Error(
        `the accepted layout is not '${name}':\n  want ${JSON.stringify(canonical(want))}\n  got  ${JSON.stringify(canonical(last))}`,
      )
    }
    await page.waitForTimeout(100)
  }
}
