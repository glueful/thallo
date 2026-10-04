import { test, expect, type Page } from '@playwright/test'
import {
  acceptedIs,
  dragTileTo,
  openRegionsStage,
  regionsStage,
  type RegionsRecorded,
} from '../helpers'

// The Search block in the header (search block spec §3.1, §3.9, §4): offered in the header's Blocks
// tab, inserted on the stage the real renderer made, and its scope chosen from the server's own
// choices. The accepted document must equal admin/e2e/regions-scenarios.json's `search-inserted`.

const block = (page: Page, id: string) => regionsStage(page).locator(`[data-thallo-block="${id}"]`)
const host = (page: Page, id: string) => block(page, id).locator('> *').first()

async function queueIds(page: Page, ids: string[]): Promise<void> {
  await page.evaluate((queued) => {
    ;(window as unknown as { __thalloE2eBlockIds: string[] }).__thalloE2eBlockIds = queued
  }, ids)
}

async function insertSearch(page: Page): Promise<RegionsRecorded> {
  const recorded = await openRegionsStage(page)
  await queueIds(page, ['e2enew000003'])
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Blocks', exact: true })
    .click()
  await expect(page.locator('[data-test="palette-card-search"]')).toBeVisible()
  await dragTileTo(page, 'search', host(page, 'e2ehdr000004'))
  await acceptedIs(page, recorded, 'search-inserted')
  return recorded
}

test('the Search block is offered in the header and inserted on the stage', async ({ page }) => {
  const recorded = await insertSearch(page)
  await expect(block(page, 'e2enew000003').locator('form[role="search"]')).toBeVisible()
  expect(recorded.unmatched, 'every stage request had its rendered fixture').toEqual([])
})

test('its scope offers every kind the server lists', async ({ page }) => {
  await insertSearch(page)
  await host(page, 'e2enew000003').click({ position: { x: 4, y: 4 } })
  await expect(page.locator('[data-test="block-inspector-title"]')).toHaveText('Search')
  await page
    .locator('[data-test="block-inspector-tabs"] [data-test="options-source-select-scope"]')
    .click()
  const listbox = page.getByRole('listbox')
  await expect(listbox.getByRole('option', { name: 'All results' })).toBeVisible()
  await expect(listbox.getByRole('option', { name: 'Pages & posts' })).toBeVisible()
  await expect(listbox.getByRole('option', { name: 'Products' })).toBeVisible()
})
