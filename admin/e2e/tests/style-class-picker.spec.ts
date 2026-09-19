import { test, expect } from '@playwright/test'
import { openDesignPage, selectOnStage } from '../helpers'

// One class, many blocks: a "Panel" class goes on every tab's container, a "Card" on every card.
// The Advanced tab's picker is an action, not a field — it applies what is chosen and empties — so
// choosing the SAME class on the next block is its ordinary use. It failed from the second pick on:
// the select kept the last choice inside itself after the tab emptied its own value, so choosing
// that class again was no change to it and nothing was emitted. Only a real browser shows it: the
// unit suite stubs the select.
const PANEL = {
  id: 'cls000000001',
  version: 1,
  name: 'Panel',
  description: null,
  style: { radius: { type: 'token', value: 'radius.lg' } },
  archived: false,
  archived_at: null,
  locked_by_job: null,
  created_at: null,
  updated_at: null,
}

async function applyPanel(page: import('@playwright/test').Page, id: string): Promise<void> {
  await selectOnStage(page, id)
  await page
    .locator('[data-test="block-inspector-tabs"]')
    .getByRole('tab', { name: 'Advanced', exact: true })
    .click()
  await expect(page.locator('[data-test="style-classes-empty"]')).toBeVisible()
  await page.locator('[data-test="style-class-picker"]').click()
  await page.getByRole('option', { name: 'Panel', exact: true }).click()
  await expect(page.locator(`[data-test="style-class-${PANEL.id}"]`)).toBeVisible()
  // Applied, it leaves the picker's offer; the picker shows its prompt again, not the last choice.
  await expect(page.locator('[data-test="style-class-picker"]')).toContainText(
    'Apply a style class',
  )
}

test('the same style class is applied to one block after another', async ({ page }) => {
  await openDesignPage(page, { styleClasses: [PANEL] })
  await applyPanel(page, 'grid00000001')
  await applyPanel(page, 'gridempty001')
  await applyPanel(page, 'gridspan0001')
})
