import { test, expect, type Page } from '@playwright/test'
import {
  acceptedIs,
  fixture,
  openRegionsStage,
  openStyleClassEditor,
  regionsStage,
} from '../helpers'

// A typeface from the site's own library (block typeface plan Task 11), in a real browser: the
// family the fixture build read from a real variable .woff2 file is offered by the Typeface control,
// a footer Links title set in it carries its utility on a stage the real renderer made, and the
// style class editor — which renders nowhere — offers the control without a computed notice.

interface Family {
  id: string
  kind: string
  removed: boolean
}
const family = (): Family =>
  (JSON.parse(fixture('api/fonts.json')) as { data: { families: Family[] } }).data.families.find(
    (f) => f.kind === 'uploaded' && !f.removed,
  )!

const block = (page: Page, id: string) => regionsStage(page).locator(`[data-thallo-block="${id}"]`)

test('a footer Links title set in an uploaded family carries it on the stage', async ({ page }) => {
  const { id } = family()
  const recorded = await openRegionsStage(page, { session: 'links' })
  await block(page, 'e2eftr000002')
    .locator('> *')
    .first()
    .click({ position: { x: 4, y: 4 } })
  await expect(block(page, 'e2eftr000002')).toHaveClass(/thallo-canvas-selected/)
  await page
    .locator('[data-test="block-inspector-tabs"]')
    .getByText('Style', { exact: true })
    .click()
  const field = page.locator('[data-test="style-field-typography.family"]').first()
  await field.locator(`[data-test="typeface-option-${id}"]`).click()
  await acceptedIs(page, recorded, 'typeface-applied')
  await expect(block(page, 'e2eftr000002').locator('.thallo-block-links__title')).toHaveClass(
    new RegExp(`(^|\\s)t-font-${id}(\\s|$)`),
  )
  expect(recorded.unmatched, 'every stage request had its rendered fixture').toEqual([])
})

test('the style class editor offers the Typeface control with no computed notice', async ({
  page,
}) => {
  const { id } = family()
  await openStyleClassEditor(page)
  const field = page.locator('[data-test="style-field-typography.family"]').first()
  await field.locator(`[data-test="typeface-option-${id}"]`).click()
  await expect(field.locator(`[data-test="typeface-option-${id}"]`)).toHaveAttribute(
    'aria-pressed',
    'true',
  )
  await expect(field.locator('[data-test="typeface-faces"]')).toContainText('variable')
  await expect(page.locator('[data-test="typeface-notice"]')).toHaveCount(0)
})
