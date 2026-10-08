import { test, expect } from '@playwright/test'
import { applyNow, openDesignPage, selectViaOutline } from '../helpers'

// The Product grid on the stage (product grid spec §7): its Style tab lists the eight parts, and a
// Card background set there is applied as a part setting of the grid. The re-rendered stage itself
// is proven server-side (ProductGridBlockTest) and in the runtime browser (product-grid stage.html).

const PARTS = ['card', 'image', 'details', 'title', 'price', 'meta', 'button', 'badge']

test("styles the grid's Card part from the Style tab", async ({ page }) => {
  const recorded = await openDesignPage(page)
  await selectViaOutline(page, 'gridblock0e2')
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Block', exact: true })
    .click()
  await page
    .locator('[data-test="block-inspector-tabs"]')
    .getByRole('tab', { name: 'Style', exact: true })
    .click()
  for (const part of PARTS) {
    await expect(page.locator(`[data-test="style-part-${part}"]`)).toHaveCount(1)
  }

  await page
    .locator(
      '[data-test="style-part-card"] [data-test="style-field-colors.surface"] [data-test="token-color.surface"]',
    )
    .first()
    .click()
  await applyNow(page, recorded)
  const ops = recorded.applies[recorded.applies.length - 1].operations
  expect(ops).toContainEqual(
    expect.objectContaining({
      type: 'SetSetting',
      block: 'gridblock0e2',
      part: 'card',
      path: 'colors.surface',
      to: { present: true, value: { type: 'token', value: 'color.surface' } },
    }),
  )
})
