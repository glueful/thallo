import { test, expect, type Page } from '@playwright/test'
import {
  dragTileTo,
  layoutAcceptedIs,
  layoutStage,
  openLayoutStage,
  type LayoutRecorded,
} from '../helpers'

// The post listing on the layout stage (type layouts plan B, B6), in a real browser against the
// stages the real renderer made around the listing's first page. The Entry list's first card is the
// card: its blocks select, and a block dropped into it lands in the card — `parent` the Entry list,
// field `card` — and shows in every card of the refreshed stage. The other cards are copies: nothing
// in them selects, and nothing drops there. A card block cannot leave the card, and the Entry list
// cannot be deleted. The stage serves each state only for exactly the document the renderer
// rendered.

const LOOP = 'e2elistloop1'
const CARD_TITLE = 'e2ecardtitle'
const PAGE_TITLE = 'e2elisttitle'

const block = (page: Page, id: string) => layoutStage(page).locator(`[data-thallo-block="${id}"]`)
/** A block's rendered host: the wrapper itself has no box of its own. */
const host = (page: Page, id: string) => block(page, id).locator('> *').first()
/** The second card: a copy of the first, rendered for the page's second entry. */
const copyTitle = (page: Page) =>
  layoutStage(page).locator('[data-thallo-card-copy] .thallo-block-entry_title').first()
const cardSlot = (page: Page) => layoutStage(page).locator('[data-thallo-slot="card"]').first()
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

/** The Entry list selected: a click in a copy card finds no block of its own, only the list. */
async function selectLoop(page: Page): Promise<void> {
  await copyTitle(page).click()
  await expect(block(page, LOOP)).toHaveClass(/thallo-canvas-selected/)
  await expect(inspectorTitle(page)).toHaveText('Entry list')
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

test('the first card selects; a copy card does not', async ({ page }) => {
  const recorded = await openLayoutStage(page, { world: 'listing' })
  await expect(page.locator('[data-test="layout-reach"]')).toHaveText(
    'Applies to every page of the post listing',
  )
  const cards = layoutStage(page).locator('.thallo-loop-card')
  expect(await cards.count()).toBeGreaterThan(1)
  await expect(block(page, CARD_TITLE)).toHaveCount(1)

  await host(page, CARD_TITLE).click({ position: { x: 4, y: 4 } })
  await expect(block(page, CARD_TITLE)).toHaveClass(/thallo-canvas-selected/)
  await expect(inspectorTitle(page)).toHaveText('Entry title')

  // The second card's title is the same block rendered again: selecting there selects the list.
  await selectLoop(page)
  await expect(block(page, CARD_TITLE)).not.toHaveClass(/thallo-canvas-selected/)
  served(recorded)
})

test('an Entry date dropped on the card title lands in the card and shows in every card', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'listing' })
  await queueIds(page, ['e2edatenew01'])
  await openBlocksTab(page)
  // Onto the lower part of the first card's title: the drop lands after it.
  await dragTileTo(page, 'entry_date', host(page, CARD_TITLE), true, 0.85)

  await layoutAcceptedIs(page, recorded, 'dated', 'listing')
  expect(lastInsert(recorded)).toMatchObject({
    position: { parent: LOOP, slot: 'card', index: 1 },
    block: { id: 'e2edatenew01', type: 'entry_date' },
  })
  await expect(block(page, 'e2edatenew01')).toHaveCount(1)
  const cards = await layoutStage(page).locator('.thallo-loop-card').count()
  await expect(layoutStage(page).locator('.thallo-block-entry_date')).toHaveCount(cards)
  served(recorded)
})

test('nothing drops over a copy card', async ({ page }) => {
  const recorded = await openLayoutStage(page, { world: 'listing' })
  const applies = recorded.applies.length
  await openBlocksTab(page)
  await dragTileTo(page, 'entry_date', copyTitle(page), false)
  await expect(indicator(page)).toHaveCount(0)
  await page.mouse.up()
  await expect(page.locator('.thallo-palette-ghost')).toHaveCount(0)
  await page.waitForTimeout(500)
  expect(recorded.applies).toHaveLength(applies)
  await expect(block(page, CARD_TITLE)).toHaveCount(1)
  served(recorded)
})

test('an Entry date dropped into an empty card is the card’s first block', async ({ page }) => {
  const recorded = await openLayoutStage(page, { world: 'listing' })
  await host(page, CARD_TITLE).click({ position: { x: 4, y: 4 } })
  // Selected first: the confirmation removes the selection it was asked about.
  await expect(block(page, CARD_TITLE)).toHaveClass(/thallo-canvas-selected/)
  await expect(inspectorTitle(page)).toHaveText('Entry title')
  await host(page, CARD_TITLE).press('Delete')
  await page.locator('[data-test="canvas-delete-confirm-yes"]').click()
  await layoutAcceptedIs(page, recorded, 'empty-card', 'listing')
  await expect(block(page, CARD_TITLE)).toHaveCount(0)

  const applies = recorded.applies.length
  await queueIds(page, ['e2edatenew01'])
  await openBlocksTab(page)
  await dragTileTo(page, 'entry_date', cardSlot(page))
  await expect.poll(() => recorded.applies.length).toBeGreaterThan(applies)
  expect(lastInsert(recorded)).toMatchObject({
    position: { parent: LOOP, slot: 'card', index: 0 },
    block: { id: 'e2edatenew01', type: 'entry_date' },
  })
})

test('an Entry date dropped into the placeholder page’s card is the card’s first block', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'listing-placeholder' })
  await expect(layoutStage(page).locator('[data-thallo-placeholder]')).toHaveText(
    'No published posts yet — showing a placeholder',
  )
  await expect(layoutStage(page).locator('.thallo-loop-card')).toHaveCount(1)
  const applies = recorded.applies.length

  await queueIds(page, ['e2edatenew01'])
  await openBlocksTab(page)
  await dragTileTo(page, 'entry_date', cardSlot(page))
  await expect.poll(() => recorded.applies.length).toBeGreaterThan(applies)
  expect(lastInsert(recorded)).toMatchObject({
    position: { parent: LOOP, slot: 'card', index: 0 },
    block: { id: 'e2edatenew01', type: 'entry_date' },
  })
})

test('an Entry title cannot leave the card, and the Entry list cannot be deleted', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'listing' })
  const applies = recorded.applies.length
  await openBlocksTab(page)
  await dragTileTo(page, 'entry_title', host(page, PAGE_TITLE), false)
  await expect(indicator(page)).toHaveClass(/thallo-canvas-drop-line--refused/)
  await expect(indicator(page)).toHaveAttribute(
    'title',
    "Entry title goes inside the Entry list's card",
  )
  await page.mouse.up()
  await expect(page.locator('.thallo-palette-ghost')).toHaveCount(0)
  await page.waitForTimeout(500)
  expect(recorded.applies).toHaveLength(applies)

  await selectLoop(page)
  await copyTitle(page).press('Delete')
  await expect(page.locator('[data-test="layout-required-refusal"]')).toHaveText(
    'Every page of the post listing shows its Entry list here, so the layout keeps this block. Move it instead.',
  )
  await expect(page.locator('[data-test="canvas-delete-confirm-yes"]')).toHaveCount(0)
  served(recorded)
})

test('in a grid or a flex list, a card’s blocks have no item controls; the list arranges the cards', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { world: 'listing' })
  for (const mode of ['grid', 'flex'] as const) {
    await selectLoop(page)
    await openLayoutTab(page)
    await page
      .locator(`[data-test="style-field-layout.display"] [data-test="choice-${mode}"]`)
      .click()
    await layoutAcceptedIs(page, recorded, mode, 'listing')
    await expect(page.locator('[data-test="layout-group-container"] h4')).toContainText(
      'Arrange the cards',
    )

    await host(page, CARD_TITLE).click({ position: { x: 4, y: 4 } })
    await expect(inspectorTitle(page)).toHaveText('Entry title')
    await openLayoutTab(page)
    await expect(page.locator('[data-test="layout-group-item"]')).toHaveCount(0)
    await expect(page.locator('[data-test="style-field-layout.span"]')).toHaveCount(0)
    await expect(page.locator('[data-test="style-field-layout.basis"]')).toHaveCount(0)
  }
  served(recorded)
})
