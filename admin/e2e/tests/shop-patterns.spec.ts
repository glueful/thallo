import { test, expect, type Page } from '@playwright/test'
import { historyLength, idsIn, openBlocksTab, openDesignPage } from '../helpers'

// The shop's page templates, against the REAL library (the fixture is the server's own answer, built
// with Commerce on): offered in the Templates view, a template that needs a product saying so on its
// card, and one inserting its sections whole, in order, as one transaction; with Commerce off (the
// server's answer from a boot with the capability off), none of them.
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
  // The hover preview's blocks (hover-preview.spec.ts).
  'hovlinks0001',
  'hovnest00001',
  'hovsocial001',
  'gridblock0e2',
]

type Block = { id: string; type: string; data: Record<string, unknown> }

/** Every block type in a block tree. */
function typesIn(block: Block): string[] {
  const nested = Object.values(block.data).flatMap((value) =>
    Array.isArray(value) && value.every((v) => v && typeof v === 'object' && 'type' in v)
      ? (value as Block[]).flatMap(typesIn)
      : [],
  )
  return [block.type, ...nested]
}

async function openTemplates(page: Page): Promise<void> {
  await openBlocksTab(page)
  await page.locator('[data-test="palette-view-pages"]').click()
}

test('the shop templates are offered with their note', async ({ page }) => {
  await openDesignPage(page)
  await openTemplates(page)
  for (const [slug, label] of [
    ['shop-landing', 'Shop landing'],
    ['shop-product-launch', 'Product launch'],
    ['shop-sale', 'Sale / collection'],
    ['shop-new-arrivals-page', 'New arrivals'],
  ]) {
    await expect(page.locator(`[data-test="pattern-card-${slug}"]`)).toContainText(label)
  }
  await expect(
    page.locator('[data-test="pattern-card-shop-landing"] [data-test="pattern-requires"]'),
  ).toHaveText('Choose a product after inserting it')
  // A template with no featured product or add-to-cart has nothing to choose.
  await expect(
    page.locator('[data-test="pattern-card-shop-sale"] [data-test="pattern-requires"]'),
  ).toHaveCount(0)
})

test('with Commerce off no shop section or template is offered', async ({ page }) => {
  await openDesignPage(page, { commerceOff: true })
  await openTemplates(page)
  // The core templates are still there; the shop's are not.
  await expect(page.locator('[data-test="pattern-card-page-pricing"]')).toBeVisible()
  await expect(page.locator('[data-test^="pattern-card-shop-"]')).toHaveCount(0)
  await page.locator('[data-test="palette-view-sections"]').click()
  await expect(page.locator('[data-test="pattern-card-faq"]')).toBeVisible()
  await expect(page.locator('[data-test="pattern-group-Shop"]')).toHaveCount(0)
  await expect(page.locator('[data-test^="pattern-card-shop-"]')).toHaveCount(0)
})

test('a shop template inserts its sections', async ({ page }) => {
  await openDesignPage(page)
  await openTemplates(page)
  await page.locator('[data-test="pattern-card-shop-sale"]').click()

  const h = await historyLength(page, 1)
  const inserts = (h.history[0]!.ops as unknown as { type: string; block: Block }[]).filter(
    (op) => op.type === 'InsertBlock',
  )
  // A sale banner, the collection's grid, the reasons to buy and a call to action: in that order,
  // at the end of the body, one undo.
  expect(inserts.map((op) => op.block.type)).toEqual([
    'hero',
    'container',
    'container',
    'container',
  ])
  const [, collection, reasons, closing] = inserts.map((op) => typesIn(op.block))
  expect(collection).toContain('product-grid')
  expect(reasons!.filter((type) => type === 'feature')).toHaveLength(3)
  expect(closing).toContain('cta')
  expect(idsIn(h.document, ['body'])).toEqual([...BODY, ...inserts.map((op) => op.block.id)])
})
