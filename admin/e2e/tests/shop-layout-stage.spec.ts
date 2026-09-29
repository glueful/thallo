import { test, expect, type Page } from '@playwright/test'
import {
  dragTileTo,
  layoutAcceptedIs,
  layoutStage,
  openLayoutStage,
  type LayoutRecorded,
} from '../helpers'

// The shop home on the layout stage (type layouts plan C2, S5), in a real browser against the stages
// the real renderer made around the seeded shop's first page (24 cards). The Product list's first
// card is the card: its blocks select, and a block dropped into it lands in the card; the other
// cards are copies. The card is today's grid card — the tile, then a Grid body container holding the
// name above a row of the rating and the price — so a drop beside the name belongs to that container
// (the bridge appends to a grid), and placing right after the name is the Blocks tab's insert-after.
// The Product list's cards are the shop's adaptive grid until arranged, and the Layout tab says so.

const LOOP = 'e2eshoploop1'
const TILE = 'e2eshoptile1'
const BODY = 'e2eshopbody1'
const NAME = 'e2eshopname1'
const TITLE = 'e2eshoptitle'

const block = (page: Page, id: string) => layoutStage(page).locator(`[data-thallo-block="${id}"]`)
/** A block's rendered host: the wrapper itself has no box of its own. */
const host = (page: Page, id: string) => block(page, id).locator('> *').first()
const copyName = (page: Page) =>
  layoutStage(page).locator('[data-thallo-card-copy] .thallo-block-product_name').first()
const cardSlot = (page: Page) => layoutStage(page).locator('[data-thallo-slot="card"]').first()
const cards = (page: Page) => layoutStage(page).locator('.thallo-loop-card')
const indicator = (page: Page) => layoutStage(page).locator('.thallo-canvas-drop-line')
const inspectorTitle = (page: Page) => page.locator('[data-test="block-inspector-title"]')

async function queueIds(page: Page, ids: string[]): Promise<void> {
  await page.evaluate((queued) => {
    ;(window as unknown as { __thalloE2eBlockIds: string[] }).__thalloE2eBlockIds = queued
  }, ids)
}

async function openBlocksTab(page: Page): Promise<void> {
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Blocks', exact: true })
    .click()
}

async function openLayoutTab(page: Page): Promise<void> {
  await page.locator('[data-test="block-inspector"]').waitFor()
  await page.locator('[data-test="block-inspector-tabs"] button', { hasText: 'Layout' }).click()
  await page.locator('[data-test="layout-tab"]').waitFor()
}

/** The Product list's selection wrapper, whatever its id (a fresh starter's ids are the server's). */
const loopBlock = (page: Page) =>
  layoutStage(page).locator('[data-thallo-block]:has(> .thallo-block-product_loop)')

/** The Product list selected: a click in a copy card finds no block of its own, only the list. */
async function selectLoop(page: Page): Promise<void> {
  await copyName(page).click()
  await expect(loopBlock(page)).toHaveClass(/thallo-canvas-selected/)
  await expect(inspectorTitle(page)).toHaveText('Product list')
}

async function select(page: Page, id: string, title: string): Promise<void> {
  await host(page, id).click({ position: { x: 4, y: 4 } })
  await expect(block(page, id)).toHaveClass(/thallo-canvas-selected/)
  await expect(inspectorTitle(page)).toHaveText(title)
}

function served(recorded: LayoutRecorded): void {
  expect(recorded.unmatched, 'every stage request had its rendered fixture').toEqual([])
}

/** The one InsertBlock the last apply carried. */
function lastInsert(recorded: LayoutRecorded) {
  const ops = recorded.applies.at(-1)!.operations as unknown as {
    type: string
    position?: { parent: string | null; slot: string | null; index: number }
    block?: { id: string; type: string }
  }[]
  const inserts = ops.filter((op) => op.type === 'InsertBlock')
  expect(inserts).toHaveLength(1)
  return inserts[0]!
}

/**
 * The adaptive grid's first row, derived — never a written-down count: the list's content width,
 * its computed column gap and the track minimum the shop stylesheet specifies (15rem, at the root
 * font size) give how many cards fit, and that many share the first card's top.
 */
async function adaptiveRow(page: Page): Promise<{ expected: number; actual: number }> {
  return layoutStage(page)
    .locator('.thallo-block-product_loop__cards')
    .evaluate((list) => {
      const style = getComputedStyle(list)
      const width =
        list.getBoundingClientRect().width -
        parseFloat(style.paddingLeft) -
        parseFloat(style.paddingRight)
      const gap = parseFloat(style.columnGap)
      const min = 15 * parseFloat(getComputedStyle(document.documentElement).fontSize)
      const expected = Math.max(1, Math.floor((width + gap) / (min + gap)))
      const items = Array.from(list.children) as HTMLElement[]
      const top = items[0]!.getBoundingClientRect().top
      const actual = items.filter((li) => Math.abs(li.getBoundingClientRect().top - top) < 1).length
      return { expected, actual }
    })
}

test('the first card selects; a copy card selects the Product list', async ({ page }) => {
  const recorded = await openLayoutStage(page, { world: 'shop' })
  await expect(page.locator('[data-test="layout-reach"]')).toHaveText(
    'Applies to every page of the shop home',
  )
  expect(await cards(page).count()).toBe(24)
  await expect(block(page, NAME)).toHaveCount(1)
  await select(page, NAME, 'Product name')
  await selectLoop(page)
  await expect(block(page, NAME)).not.toHaveClass(/thallo-canvas-selected/)
  served(recorded)
})

test('a Product rating dropped below the tile lands directly in the card, in every card', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'shop' })
  await queueIds(page, ['e2eratingnew'])
  await openBlocksTab(page)
  await dragTileTo(page, 'product_rating', host(page, TILE), true, 0.85)
  await layoutAcceptedIs(page, recorded, 'tile-rated', 'shop')
  expect(lastInsert(recorded)).toMatchObject({
    position: { parent: LOOP, slot: 'card', index: 1 },
    block: { id: 'e2eratingnew', type: 'product_rating' },
  })
  const count = await cards(page).count()
  await expect(layoutStage(page).locator('.thallo-block-product_rating')).toHaveCount(count * 2)
  served(recorded)
})

test('a drop beside the name belongs to its Grid container, which appends', async ({ page }) => {
  const recorded = await openLayoutStage(page, { world: 'shop' })
  await queueIds(page, ['e2eratingnew'])
  await openBlocksTab(page)
  await dragTileTo(page, 'product_rating', host(page, NAME), false, 0.85)
  await expect(indicator(page)).toHaveClass(/thallo-canvas-drop-line--other/)
  await page.mouse.up()
  await layoutAcceptedIs(page, recorded, 'nested-rated', 'shop')
  expect(lastInsert(recorded)).toMatchObject({
    position: { parent: BODY, slot: 'content', index: 2 },
    block: { id: 'e2eratingnew', type: 'product_rating' },
  })
  served(recorded)
})

test('the Blocks tab places a Product rating right after the selected name', async ({ page }) => {
  const recorded = await openLayoutStage(page, { world: 'shop' })
  await select(page, NAME, 'Product name')
  const applies = recorded.applies.length
  await openBlocksTab(page)
  // No target armed: the selection is where the Blocks tab inserts — right after it.
  await page.locator('[data-test="palette-card-product_rating"]').click()
  await expect.poll(() => recorded.applies.length).toBeGreaterThan(applies)
  expect(lastInsert(recorded)).toMatchObject({
    position: { parent: BODY, slot: 'content', index: 1 },
    block: { type: 'product_rating' },
  })
})

test('nothing drops over a copy card', async ({ page }) => {
  const recorded = await openLayoutStage(page, { world: 'shop' })
  const applies = recorded.applies.length
  await openBlocksTab(page)
  await dragTileTo(page, 'product_rating', copyName(page), false)
  await expect(indicator(page)).toHaveCount(0)
  await page.mouse.up()
  await expect(page.locator('.thallo-palette-ghost')).toHaveCount(0)
  await page.waitForTimeout(500)
  expect(recorded.applies).toHaveLength(applies)
  served(recorded)
})

for (const world of ['shop', 'shop-placeholder'] as const) {
  test(`a Product price dropped at the top of the card is its first block (${world})`, async ({
    page,
  }) => {
    const recorded = await openLayoutStage(page, {
      world,
      ...(world === 'shop' ? { opening: 'empty-card' } : {}),
    })
    if (world === 'shop-placeholder') {
      await expect(layoutStage(page).locator('[data-thallo-placeholder]')).toHaveText(
        'No published products yet — showing a placeholder',
      )
      await expect(cards(page)).toHaveCount(1)
    }
    const applies = recorded.applies.length
    await queueIds(page, ['e2epricenew1'])
    await openBlocksTab(page)
    // An empty card is the slot itself; the placeholder's card holds the starter's blocks, so the
    // drop aims at the top of its tile.
    if (world === 'shop') await dragTileTo(page, 'product_price', cardSlot(page))
    else await dragTileTo(page, 'product_price', host(page, TILE), true, 0.15)
    await expect.poll(() => recorded.applies.length).toBeGreaterThan(applies)
    expect(lastInsert(recorded)).toMatchObject({
      position: { parent: LOOP, slot: 'card', index: 0 },
      block: { id: 'e2epricenew1', type: 'product_price' },
    })
  })
}

test('a Product name cannot leave the card, and the Product list cannot be deleted', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'shop' })
  const applies = recorded.applies.length
  await openBlocksTab(page)
  await dragTileTo(page, 'product_name', host(page, TITLE), false)
  await expect(indicator(page)).toHaveClass(/thallo-canvas-drop-line--refused/)
  await expect(indicator(page)).toHaveAttribute(
    'title',
    "Product name goes inside the Product list's card",
  )
  await page.mouse.up()
  await expect(page.locator('.thallo-palette-ghost')).toHaveCount(0)
  await page.waitForTimeout(500)
  expect(recorded.applies).toHaveLength(applies)

  await selectLoop(page)
  await copyName(page).press('Delete')
  await expect(page.locator('[data-test="layout-required-refusal"]')).toHaveText(
    'Every page of the shop home shows its Product list here, so the layout keeps this block. Move it instead.',
  )
  await expect(page.locator('[data-test="canvas-delete-confirm-yes"]')).toHaveCount(0)
  served(recorded)
})

for (const opening of ['baseline', 'grid', 'flex']) {
  test(`the card boundary (${opening}): the tile has no item controls; the name is its Grid container’s item`, async ({
    page,
  }) => {
    const recorded = await openLayoutStage(page, { world: 'shop', opening })
    await select(page, TILE, 'Product tile')
    await openLayoutTab(page)
    await expect(page.locator('[data-test="layout-group-item"]')).toHaveCount(0)

    await select(page, NAME, 'Product name')
    await openLayoutTab(page)
    await expect(page.locator('[data-test="style-field-layout.span"]')).toHaveCount(1)
    await expect(page.locator('[data-test="style-field-layout.align_self"]')).toHaveCount(1)
    await expect(page.locator('[data-test="style-field-layout.basis"]')).toHaveCount(0)

    await selectLoop(page)
    await openLayoutTab(page)
    await expect(page.locator('[data-test="layout-group-container"] h4')).toContainText(
      'Arrange the cards',
    )
    served(recorded)
  })
}

for (const world of ['shop', 'shop-after-remove'] as const) {
  test(`the Product list untouched (${world}): the tab reads the adaptive grid the stage renders`, async ({
    page,
  }) => {
    const recorded = await openLayoutStage(page, { world })
    await selectLoop(page)
    await openLayoutTab(page)
    await expect(page.locator('[data-test="layout-display-theme-default"]')).toHaveText(
      'Theme default: Grid',
    )
    await expect(page.locator('[data-test="layout-columns-theme-default"]')).toHaveText(
      'Theme default: Adaptive — as many 15rem columns as fit',
    )
    const row = await adaptiveRow(page)
    expect(row.expected, 'the stage is wide enough for more than one track').toBeGreaterThan(1)
    expect(row.actual, `${world}: the cards fill the adaptive tracks`).toBe(row.expected)
    served(recorded)
  })
}

test('the Product list switched to Flex: the tab reads Flex and the cards are a row', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'shop', opening: 'flex' })
  await selectLoop(page)
  await openLayoutTab(page)
  await expect(page.locator('[data-test="layout-display-theme-default"]')).toHaveCount(0)
  await expect(page.locator('[data-test="layout-field-layout.columns"]')).toHaveCount(0)
  const tops = await cards(page).evaluateAll((items) =>
    items.slice(0, 2).map((li) => Math.round(li.getBoundingClientRect().top)),
  )
  expect(tops[0], 'a flex row: the first two cards side by side').toBe(tops[1])
  served(recorded)
})

test('the Product list on two columns: the tab presses 2 and the cards sit two to a row', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'shop', opening: 'grid' })
  await selectLoop(page)
  await openLayoutTab(page)
  await expect(page.locator('[data-test="track-2"]')).toHaveAttribute('aria-pressed', 'true')
  await expect(page.locator('[data-test="layout-columns-theme-default"]')).toHaveCount(0)
  const perRow = await cards(page).evaluateAll((items) => {
    const top = items[0]!.getBoundingClientRect().top
    return items.filter((li) => Math.abs(li.getBoundingClientRect().top - top) < 1).length
  })
  expect(perRow, 'two columns: two cards to a row').toBe(2)
  served(recorded)
})
