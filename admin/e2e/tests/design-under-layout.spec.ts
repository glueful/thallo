import { test, expect } from '@playwright/test'
import { applyNow, openDesignPage, selectOnStage, stage } from '../helpers'

// The entry's Design view when its type has a layout (type layouts spec §6.3), in a real browser
// against the stage the real renderer made: the entry's body sits inside the layout, the body's
// blocks select as always and the layout's own blocks do not (they are edited on the layout's
// page), and an accepted edit refreshes the stage whole — an entry under a layout gets no fragments.

const activeTab = (page: import('@playwright/test').Page) =>
  page.locator('[data-test="inspector-tabs"]').getByRole('tab', { selected: true }).first()

test('under a layout: a body block selects, a layout block does not, an edit refreshes the stage', async ({
  page,
}) => {
  const recorded = await openDesignPage(page, { underLayout: true })
  await expect(page.locator('[data-test="design-layout-strip"]')).toContainText(
    /This page uses the Pages? layout/,
  )
  // The layout's heading renders around the body, and carries nothing to select.
  const layoutHeading = stage(page).getByText('A heading the layout adds')
  await expect(layoutHeading).toBeVisible()
  await expect(stage(page).locator('[data-thallo-block="e2elayouthd1"]')).toHaveCount(0)
  await layoutHeading.click()
  await page.waitForTimeout(300)
  await expect(
    page.locator('[data-test="inspector-tabs"]').getByRole('tab', { name: 'Block', exact: true }),
  ).toHaveCount(0)

  // A body block selects as it always does.
  await selectOnStage(page, 'head00000001')
  await expect(stage(page).locator('[data-thallo-block="head00000001"]')).toHaveClass(
    /thallo-canvas-selected/,
  )
  await expect(activeTab(page)).toHaveText('Block')

  // An edit — here the page's width — is applied, and the stage reloads whole to show it.
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Page', exact: true })
    .click()
  const loads = recorded.stageLoads
  await page.locator('[data-test="pres-layout-full"]').click()
  const applied = await applyNow(page, recorded)
  expect((applied.fields._presentation as Record<string, unknown>).layout).toBe('full')
  await expect.poll(() => recorded.stageLoads).toBeGreaterThan(loads)
})
