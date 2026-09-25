import { test, expect, type Page } from '@playwright/test'
import {
  acceptedIs,
  centerOf,
  dragTileTo,
  openRegionsStage,
  regionsStage,
  type RegionsRecorded,
} from '../helpers'

// The header and footer edited on the stage (regions stage spec §3–§5), in a real browser against
// stages the real renderer made. Every step's accepted document must equal one scenario in
// admin/e2e/regions-scenarios.json exactly, and the stage is served only from the fixture that
// renders that document — a wrong operation fails loudly, never with a stale stage.

const block = (page: Page, id: string) => regionsStage(page).locator(`[data-thallo-block="${id}"]`)
/** A block's rendered host: the wrapper itself has no box of its own. */
const host = (page: Page, id: string) => block(page, id).locator('> *').first()
const activeTab = (page: Page) =>
  page.locator('[data-test="inspector-tabs"]').getByRole('tab', { selected: true }).first()

async function select(page: Page, id: string): Promise<void> {
  await host(page, id).click({ position: { x: 4, y: 4 } })
  await expect(block(page, id)).toHaveClass(/thallo-canvas-selected/)
}

async function tab(page: Page, name: string): Promise<void> {
  await page.locator('[data-test="inspector-tabs"]').getByRole('tab', { name, exact: true }).click()
}

/** Queue the ids the next inserted blocks take (an E2E build only). */
async function queueIds(page: Page, ids: string[]): Promise<void> {
  await page.evaluate((queued) => {
    ;(window as unknown as { __thalloE2eBlockIds: string[] }).__thalloE2eBlockIds = queued
  }, ids)
}

function served(recorded: RegionsRecorded): void {
  expect(recorded.unmatched, 'every stage request had its rendered fixture').toEqual([])
}

test('selecting a header block on the stage opens its Block tab', async ({ page }) => {
  const recorded = await openRegionsStage(page)
  await select(page, 'e2ehdr000004')
  await expect(activeTab(page)).toHaveText('Block')
  await expect(page.locator('[data-test="block-inspector-title"]')).toHaveText('Button')
  served(recorded)
})

test('a click on a body link navigates nowhere and selects nothing', async ({ page }) => {
  const recorded = await openRegionsStage(page)
  const before = page.url()
  await regionsStage(page).locator('main a[href="/elsewhere"]').click()
  await page.waitForTimeout(300)
  expect(page.url()).toBe(before)
  await expect(regionsStage(page).locator('main a[href="/elsewhere"]')).toBeVisible()
  await expect(activeTab(page)).toHaveText('Blocks')
  served(recorded)
})

/** Drag a stage block's grip to before another block in a row, in steps. */
async function dragGripBefore(page: Page, id: string, onto: string): Promise<void> {
  await select(page, id)
  const from = await centerOf(block(page, id).locator('[data-action="drag"]').first())
  const box = (await host(page, onto).boundingBox())!
  const to = { x: box.x + box.width * 0.2, y: box.y + box.height / 2 }
  await page.mouse.move(from.x, from.y)
  await page.mouse.down()
  for (let i = 1; i <= 12; i++) {
    await page.mouse.move(from.x + ((to.x - from.x) * i) / 12, from.y + ((to.y - from.y) * i) / 12)
  }
  await regionsStage(page).locator('.thallo-canvas-dragging').first().waitFor({ timeout: 2000 })
  await page.waitForTimeout(250)
  await page.mouse.move(to.x, to.y + 1)
  await page.mouse.up()
}

test('a drag reorders the header, and undo takes it back at the advanced revision', async ({
  page,
}) => {
  const recorded = await openRegionsStage(page)
  await dragGripBefore(page, 'e2ehdr000002', 'e2ehdr000001')
  await acceptedIs(page, recorded, 'reordered')
  await page.locator('[data-test="regions-undo"]').click()
  await acceptedIs(page, recorded, 'baseline')
  const last = recorded.applies.at(-1)!
  expect(last.base_revision).toBe(1) // the second apply on this session, not a fresh start
  served(recorded)
})

test('a rich text dragged from the Blocks tab into the footer inserts the queued id', async ({
  page,
}) => {
  const recorded = await openRegionsStage(page)
  await queueIds(page, ['e2enew000001'])
  await page.locator('[data-test="regions-switch-footer"]').click()
  await tab(page, 'Blocks')
  await dragTileTo(page, 'rich_text', host(page, 'e2eftr000001'))
  await acceptedIs(page, recorded, 'inserted')
  served(recorded)
})

test('the button’s corners set on its Block tab restyle it', async ({ page }) => {
  const recorded = await openRegionsStage(page)
  await select(page, 'e2ehdr000004')
  const inspector = page.locator('[data-test="block-inspector"]')
  await inspector
    .locator('[data-test="block-inspector-tabs"]')
    .getByText('Style', { exact: true })
    .click()
  await inspector.locator('[data-test="style-field-radius"] [data-test="token-radius.lg"]').click()
  await acceptedIs(page, recorded, 'restyled')
  served(recorded)
})

test('Sticky on the Region tab', async ({ page }) => {
  const recorded = await openRegionsStage(page)
  await tab(page, 'Region')
  await page.locator('[data-test="region-header-sticky"]').click()
  await acceptedIs(page, recorded, 'sticky')
  served(recorded)
})

test('text edited in place in a header block, then saved in one call', async ({ page }) => {
  const recorded = await openRegionsStage(page)
  const region = regionsStage(page).locator('[data-thallo-edit-block="e2ehdr000003"]')
  await region.dblclick()
  await expect(region).toHaveAttribute('contenteditable', 'true')
  await region.press('ControlOrMeta+a')
  await region.pressSequentially('Edited header')
  await region.press('Escape')
  await acceptedIs(page, recorded, 'edited')

  await page.locator('[data-test="regions-save"]').click()
  await expect.poll(() => recorded.saves.length).toBe(1)
  const save = recorded.saves[0]!
  expect(Object.keys(save.regions)).toEqual(['header'])
  expect(save.expected).toEqual({ header: 0, footer: 0 })
  expect(save.token).toBe('proof-session-1')
  served(recorded)
})

test('switching to another page keeps the unsaved header and shows it there', async ({ page }) => {
  const recorded = await openRegionsStage(page)
  const region = regionsStage(page).locator('[data-thallo-edit-block="e2ehdr000003"]')
  await region.dblclick()
  await region.press('ControlOrMeta+a')
  await region.pressSequentially('Edited header')
  await region.press('Escape')
  await acceptedIs(page, recorded, 'edited')

  await page.locator('[data-test="regions-page-picker"]').click()
  await page.getByRole('option', { name: 'Page B' }).click()
  await acceptedIs(page, recorded, 'edited-pageb')
  await expect(regionsStage(page).locator('main')).toContainText('Page B body')
  await expect(regionsStage(page).locator('[data-thallo-slot="header"]')).toContainText(
    'Edited header',
  )
  expect(recorded.sessions.at(-1)!.page).not.toBeNull()
  served(recorded)
})

test('a heading is refused at the header’s root and accepted in its container, in one gesture', async ({
  page,
}) => {
  const recorded = await openRegionsStage(page, { session: 'container' })
  await queueIds(page, ['e2enew000002'])
  await tab(page, 'Blocks')
  // Over the header's root first: the strip says no, and nothing is applied.
  await dragTileTo(page, 'heading', host(page, 'e2ehdr000001'), false)
  await expect(regionsStage(page).locator('.thallo-canvas-drop-line')).toHaveClass(
    /thallo-canvas-drop-line--refused/,
  )
  expect(recorded.applies).toHaveLength(0)
  // On into the container's slot, and released there.
  const slot = block(page, 'e2ehdr000005').locator('[data-thallo-slot="content"]').first()
  const to = await centerOf(slot)
  for (let i = 0; i < 3; i++) {
    await page.mouse.move(to.x, to.y - i)
    await page.waitForTimeout(150)
  }
  await page.mouse.up()
  await acceptedIs(page, recorded, 'container-heading')
  served(recorded)
})

test('both regions empty still loads as a stage', async ({ page }) => {
  // A session whose saved header and footer are both empty: the stage is still a stage, with a
  // slot for each region to drop into.
  const recorded = await openRegionsStage(page, { session: 'empty' })
  await expect(regionsStage(page).locator('html')).toHaveAttribute('data-thallo-canvas', 'regions')
  await expect(regionsStage(page).locator('[data-thallo-slot="header"]')).toHaveCount(1)
  await expect(regionsStage(page).locator('[data-thallo-slot="footer"]')).toHaveCount(1)
  await expect(regionsStage(page).locator('[data-thallo-block]')).toHaveCount(0)
  expect(recorded.applies).toHaveLength(0)
  served(recorded)
})
