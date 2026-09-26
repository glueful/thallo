import { test, expect, type Page } from '@playwright/test'
import {
  dragTileTo,
  layoutAcceptedIs,
  layoutStage,
  openLayoutStage,
  type LayoutRecorded,
} from '../helpers'

// A layout edited on the stage (type layouts spec §6.2), in a real browser against stages the real
// renderer made around a published post. Every accepted layout must equal one scenario in
// admin/e2e/layouts-scenarios.json exactly, and the stage is served only from the fixture that
// renders that layout — a wrong operation fails loudly, never with a stale stage.

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

test('selecting the title on the stage opens its Block tab; the post’s own body selects nothing', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page)
  await host(page, 'e2elayout001').click({ position: { x: 4, y: 4 } })
  await expect(block(page, 'e2elayout001')).toHaveClass(/thallo-canvas-selected/)
  await expect(activeTab(page)).toHaveText('Block')
  // The sample post's body renders inside the slot but belongs to the post, not the layout.
  await expect(layoutStage(page).locator('.entry-blocks [data-thallo-block]')).toHaveCount(0)
  served(recorded)
})

test('a date dragged in from the Blocks tab lands after the title', async ({ page }) => {
  const recorded = await openLayoutStage(page)
  await queueIds(page, ['e2elayout003'])
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Blocks', exact: true })
    .click()
  // The Fields lead the palette.
  await expect(page.locator('[data-test^="palette-group-"]').first()).toHaveAttribute(
    'data-test',
    'palette-group-Fields',
  )
  await dragTileTo(page, 'entry_date', host(page, 'e2elayout002'))
  await layoutAcceptedIs(page, recorded, 'dated')
  served(recorded)
})

test('Save posts the version loaded; someone else’s save shows the conflict, and Reload opens theirs', async ({
  page,
}) => {
  const recorded = await openLayoutStage(page, { moved: true })
  await expect(page.locator('[data-test="layout-reach"]')).toHaveText('Applies to every post')
  await page.locator('[data-test="layout-save"]').click()
  await expect(page.locator('[data-test="layout-conflict"]')).toBeVisible()
  expect(recorded.saves).toHaveLength(1)
  expect(recorded.saves[0]!.expected_lock_version).toBe(1)
  await page.locator('[data-test="layout-conflict-reload"]').click()
  await expect(page.locator('[data-test="layout-conflict"]')).toHaveCount(0)
  await expect(page.locator('[data-test="layout-save"]')).toBeVisible()
  expect(recorded.sessions.length).toBeGreaterThanOrEqual(2)
  served(recorded)
})
