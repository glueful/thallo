import { test, expect, type Page } from '@playwright/test'
import {
  dragCardTo,
  historyLength,
  hooks,
  idsIn,
  openBlocksTab,
  openDesignPage,
  selectOnStage,
  stage,
} from '../helpers'

// The section and page library, against the REAL library (the fixture is the server's own answer)
// and the thumbnails the admin ships. A section is one block and takes the palette's own paths —
// click and drag; a page is several sections and lands as one transaction.
const BODY = [
  'sect00000001',
  'sect00000002',
  'head0000000a',
  'head0000000b',
  'grid00000001',
  'ctaa00000001',
  'gridempty001',
  'gridspan0001',
  'prose0000001',
]

async function openView(page: Page, view: 'sections' | 'pages'): Promise<void> {
  await openBlocksTab(page)
  await page.locator(`[data-test="palette-view-${view}"]`).click()
}

/** Every id in a block tree. */
function allIds(block: { id: string; data: Record<string, unknown> }): string[] {
  const nested = Object.values(block.data).flatMap((value) =>
    Array.isArray(value) && value.every((v) => v && typeof v === 'object' && 'type' in v)
      ? (value as (typeof block)[]).flatMap(allIds)
      : [],
  )
  return [block.id, ...nested]
}

test('a section is shown by its thumbnail and inserted with a click, as one block', async ({
  page,
}) => {
  await openDesignPage(page)
  await openView(page, 'sections')
  // Grouped as the library groups them, each with the picture the admin ships.
  await expect(page.locator('[data-test="pattern-group-Hero"]')).toBeVisible()
  const faq = page.locator('[data-test="pattern-card-faq"]')
  await faq.scrollIntoViewIfNeeded()
  await expect
    .poll(() => faq.locator('img').evaluate((img: HTMLImageElement) => img.naturalWidth))
    .toBe(600)

  await faq.click()
  const h = await historyLength(page, 1)
  const op = h.history[0]!.ops[0] as unknown as {
    type: string
    position: unknown
    block: { id: string; type: string; data: Record<string, unknown> }
  }
  expect(h.history[0]!.ops).toHaveLength(1)
  expect(op.type).toBe('InsertBlock')
  // Nothing selected, nothing armed: the page's default place, the end of the body.
  expect(op.position).toEqual({ parent: null, slot: 'body', index: BODY.length })
  expect(op.block.type).toBe('container')
  expect(op.block.data.element).toBe('section')
  // Fresh ids all the way down — the header's three blocks, the accordion and its five items.
  const ids = allIds(op.block)
  expect(ids.length).toBeGreaterThan(10)
  expect(new Set(ids).size).toBe(ids.length)
  for (const id of ids) expect(id).toMatch(/^[A-Za-z0-9_-]{12}$/)
  expect(idsIn(h.document, ['body'])).toEqual([...BODY, op.block.id])
  // It is the selection, like any block just added.
  expect(h.selection.ids).toEqual([op.block.id])
})

test('a section drags onto the stage like a tile, under its own name', async ({ page }) => {
  await openDesignPage(page)
  await openView(page, 'sections')
  const target = stage(page).locator('[data-thallo-block="head0000000b"] > *').first()
  await dragCardTo(page, '[data-test="pattern-card-cta-band"]', target, false)
  await expect(page.locator('.thallo-palette-ghost')).toHaveText('Call to action')
  await page.mouse.up()

  const h = await historyLength(page, 1)
  const op = h.history[0]!.ops[0] as unknown as {
    type: string
    position: { parent: string | null; slot: string; index: number }
    block: { type: string }
  }
  expect(op.type).toBe('InsertBlock')
  expect(op.block.type).toBe('container')
  expect(op.position.parent).toBeNull()
  // Beside the heading it was dropped on: before or after it, never somewhere else.
  expect([3, 4]).toContain(op.position.index)
})

test('a page lands whole, as one transaction', async ({ page }) => {
  await openDesignPage(page)
  await openView(page, 'pages')
  const pricing = page.locator('[data-test="pattern-card-page-pricing"]')
  await expect(pricing).toContainText('Pricing')
  await pricing.click()

  const h = await historyLength(page, 1)
  const all = h.history[0]!.ops as unknown as { type: string }[]
  // A page header, the plans, an FAQ and a call to action, then the page title hidden because the
  // header carries the heading: five operations, one undo.
  expect(all.map((op) => op.type)).toEqual([...Array(4).fill('InsertBlock'), 'SetPageSettings'])

  const ops = all.filter((op) => op.type === 'InsertBlock') as unknown as {
    position: { index: number }
    block: { id: string; type: string }
  }[]
  expect(ops.map((op) => op.position.index)).toEqual([9, 10, 11, 12])
  expect(ops.map((op) => op.block.type)).toEqual(['hero', 'container', 'container', 'container'])
  expect(idsIn(h.document, ['body'])).toEqual([...BODY, ...ops.map((op) => op.block.id)])
  expect(
    (h.document as { _presentation?: { show_title?: boolean } })._presentation?.show_title,
  ).toBe(false)

  await page.keyboard.press('ControlOrMeta+z')
  await expect.poll(async () => idsIn((await hooks(page)).document, ['body'])).toEqual(BODY)
  // The one undo takes the hidden title back with the blocks.
  const undone = (await hooks(page)).document as { _presentation?: { show_title?: boolean } }
  expect(undone._presentation?.show_title).toBeUndefined()
})

test('a section that would nest too deep where it would land is refused, with the reason', async ({
  page,
}) => {
  await openDesignPage(page)
  // Beside a heading five blocks down: a section is three deep itself.
  await selectOnStage(page, 'head00000001')
  await openView(page, 'sections')
  const faq = page.locator('[data-test="pattern-card-faq"]')
  await expect(faq).toHaveAttribute('aria-disabled', 'true')
  await expect(faq).toHaveAttribute('title', /deep|nest|level/i)
  await faq.click({ force: true }) // a user can still click it; nothing may come of it
  await page.waitForTimeout(300)
  expect((await hooks(page)).history).toHaveLength(0)
})
