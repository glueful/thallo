import { test, expect, type Page } from '@playwright/test'
import {
  fixture,
  layoutAcceptedIs,
  layoutStage,
  openLayoutStage,
  type LayoutRecorded,
} from '../helpers'

// The layout editor's Sections and Templates (sections and templates design §4), in a real browser
// against stages the real renderer made: the Previous / next section inserted, and the Full width
// template replacing the layout and its Frame. The admin mints the ids queued here — the ones the
// fixture build gave those documents (api/layout-template-ids.json) — so every accepted document has
// its own rendered stage, and a document without one fails the proof instead of showing a stale stage.

const ids = JSON.parse(fixture('api/layout-template-ids.json')) as Record<string, string[]>
const block = (page: Page, id: string) => layoutStage(page).locator(`[data-thallo-block="${id}"]`)

async function queueIds(page: Page, queued: string[]): Promise<void> {
  await page.evaluate((list) => {
    ;(window as unknown as { __thalloE2eBlockIds: string[] }).__thalloE2eBlockIds = list
  }, queued)
}

async function openView(page: Page, view: 'sections' | 'pages'): Promise<void> {
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Blocks', exact: true })
    .click()
  await page.locator(`[data-test="palette-view-${view}"]`).click()
}

function served(recorded: LayoutRecorded): void {
  expect(recorded.unmatched, 'every stage request had its rendered fixture').toEqual([])
}

test('a section lands in the layout and the stage shows it; undo and redo keep its id', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page)
  const [neighbours] = ids['entry-neighbours']!
  await openView(page, 'sections')
  const card = page.locator('[data-test="pattern-card-entry-neighbours"]')
  await expect(card).toBeVisible()
  // Pressing a card readies a drag of the section (one instance), and the click inserts another:
  // the id goes in the queue for both.
  await queueIds(page, [...ids['entry-neighbours']!, ...ids['entry-neighbours']!])
  await card.click()
  await layoutAcceptedIs(page, recorded, 'with-neighbours')
  await expect(block(page, neighbours!)).toHaveCount(1)

  await page.locator('[data-test="layout-undo"]').click()
  await layoutAcceptedIs(page, recorded, 'baseline')
  await expect(block(page, neighbours!)).toHaveCount(0)

  await page.locator('[data-test="layout-redo"]').click()
  await layoutAcceptedIs(page, recorded, 'with-neighbours')
  await expect(block(page, neighbours!)).toHaveCount(1)
  served(recorded)
})

test('a template replaces the layout and its frame; undo brings the baseline back, redo the same ids', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page)
  const fullWidth = ids['entry-page-full']!
  await openView(page, 'pages')
  const card = page.locator('[data-test="pattern-card-entry-page-full"]')
  await expect(card).toBeVisible()
  await queueIds(page, fullWidth)
  // A clean layout: no question asked.
  await card.click()
  await expect(page.locator('[data-test="layout-template-replace"]')).toHaveCount(0)
  await layoutAcceptedIs(page, recorded, 'full-width')
  expect(recorded.applies.at(-1)!.layout.settings).toEqual({ width: 'full' })
  for (const id of fullWidth) await expect(block(page, id)).toHaveCount(1)

  await page.locator('[data-test="layout-undo"]').click()
  await layoutAcceptedIs(page, recorded, 'baseline')
  expect(recorded.applies.at(-1)!.layout.settings).toEqual({})
  await expect(block(page, 'e2elayout001')).toHaveCount(1)

  await page.locator('[data-test="layout-redo"]').click()
  await layoutAcceptedIs(page, recorded, 'full-width')
  for (const id of fullWidth) await expect(block(page, id)).toHaveCount(1)
  served(recorded)
})
