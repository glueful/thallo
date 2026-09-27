import { test, expect, type Page } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import {
  dragTileTo,
  layoutAcceptedIs,
  layoutStage,
  openLayoutStage,
  type LayoutRecorded,
} from '../helpers'

// The shop's product page on the layout stage (type layouts plan C1), in a real browser against the
// stages the real renderer made around a product: the stage shows the product, the Product buy box selects
// and cannot be deleted, and a Product rating dragged in lands where it was dropped — the stage
// serves the rated state only for exactly the document the renderer rendered, so the newly inserted
// block (its id queued, `e2eratingnew`) must appear on the refreshed stage, distinct from the
// starter's own rating.

interface Block {
  id: string
  type: string
  data: Record<string, unknown> | unknown[]
}

/** The product session fixture's blocks, by type (the ids are made at mint). */
function sessionBlock(type: string): string {
  const session = JSON.parse(
    readFileSync(join(__dirname, '..', 'fixtures', 'layouts', 'product-session.json'), 'utf8'),
  ) as { data: { layout: { blocks: Block[] } } }
  const find = (blocks: Block[]): Block | null => {
    for (const block of blocks) {
      if (block.type === type) return block
      for (const value of Array.isArray(block.data) ? [] : Object.values(block.data)) {
        if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'object') {
          const inner = find(value as Block[])
          if (inner) return inner
        }
      }
    }
    return null
  }
  const found = find(session.data.layout.blocks)
  if (!found) throw new Error(`the product session has no ${type}`)
  return found.id
}

const block = (page: Page, id: string) => layoutStage(page).locator(`[data-thallo-block="${id}"]`)
/** A block's rendered host: the wrapper itself has no box of its own. */
const host = (page: Page, id: string) => block(page, id).locator('> *').first()
const activeTab = (page: Page) =>
  page.locator('[data-test="inspector-tabs"]').getByRole('tab', { selected: true }).first()

async function queueIds(page: Page, ids: string[]): Promise<void> {
  await page.evaluate((queued) => {
    ;(window as unknown as { __thalloE2eBlockIds: string[] }).__thalloE2eBlockIds = queued
  }, ids)
}

function served(recorded: LayoutRecorded): void {
  expect(recorded.unmatched, 'every stage request had its rendered fixture').toEqual([])
}

async function openBlocksTab(page: Page): Promise<void> {
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Blocks', exact: true })
    .click()
}

test('the stage shows the product; the Product buy box selects and cannot be deleted, saying why', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'product' })
  await expect(page.locator('[data-test="layout-reach"]')).toHaveText('Applies to every product')
  await expect(layoutStage(page).locator('.shop-product__name')).toHaveText('Linen table lamp')

  const buy = sessionBlock('product_buy')
  await host(page, buy).click({ position: { x: 4, y: 4 } })
  await expect(block(page, buy)).toHaveClass(/thallo-canvas-selected/)
  await expect(activeTab(page)).toHaveText('Block')
  await expect(page.locator('[data-test="block-inspector-title"]')).toHaveText('Product buy box')

  await host(page, buy).press('Delete')
  await expect(page.locator('[data-test="layout-required-refusal"]')).toHaveText(
    'Every one of the products shows its Product buy box here, so the layout keeps this block. Move it instead.',
  )
  await expect(page.locator('[data-test="canvas-delete-confirm-yes"]')).toHaveCount(0)
  served(recorded)
})

test('a Product rating dragged in after the name appears on the refreshed stage, selectable', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'product' })
  const starterRating = sessionBlock('product_rating')
  await expect(block(page, 'e2eratingnew')).toHaveCount(0)
  await queueIds(page, ['e2eratingnew'])
  await openBlocksTab(page)
  await expect(page.locator('[data-test^="palette-group-"]').first()).toHaveAttribute(
    'data-test',
    'palette-group-Fields',
  )
  // A drop on a block's middle lands after it: onto the name.
  await dragTileTo(page, 'product_rating', host(page, sessionBlock('product_name')))

  // The accepted document is exactly the one the renderer rendered: the rating, new id, after the name.
  await layoutAcceptedIs(page, recorded, 'rated', 'product')
  const inserted = block(page, 'e2eratingnew')
  await expect(inserted).toHaveCount(1)
  await expect(inserted.locator('.shop-product__rating')).toBeVisible()
  // The starter's own rating is still there, a different block.
  await expect(block(page, starterRating)).toHaveCount(1)
  await expect(layoutStage(page).locator('.shop-product__rating')).toHaveCount(2)

  await host(page, 'e2eratingnew').click({ position: { x: 4, y: 4 } })
  await expect(inserted).toHaveClass(/thallo-canvas-selected/)
  await expect(activeTab(page)).toHaveText('Block')
  await expect(page.locator('[data-test="block-inspector-title"]')).toHaveText('Product rating')
  served(recorded)
})

test('a rating dropped anywhere else is not served the rated stage', async ({ page }) => {
  const recorded = await openLayoutStage(page, { world: 'product' })
  await queueIds(page, ['e2eratingnew'])
  await openBlocksTab(page)
  // Before the breadcrumb, not after the name: no fixture renders that document.
  await dragTileTo(page, 'product_rating', host(page, sessionBlock('product_breadcrumb')))
  await expect
    .poll(() => recorded.applies.at(-1)?.layout !== undefined, { timeout: 10_000 })
    .toBe(true)
  await expect.poll(() => recorded.unmatched.length, { timeout: 10_000 }).toBeGreaterThan(0)
  await expect(block(page, 'e2eratingnew')).toHaveCount(0)
})
