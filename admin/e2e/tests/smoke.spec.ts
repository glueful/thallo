import { test, expect } from '@playwright/test'
import { hooks, openDesignPage, stage } from '../helpers'

test('the Design page loads the fixture entry and its stage', async ({ page }) => {
  await openDesignPage(page)
  // Fifteen from the five-deep composition and its two root siblings, plus the grid container and
  // its three headings, which the Layout tab's proof works on (container-layout spec §5).
  await expect(stage(page).locator('[data-thallo-block]')).toHaveCount(19)
  const h = await hooks(page)
  expect(h.currentSequence).toBe(0)
  expect(h.accepted).toEqual({ epoch: 'proof', revision: 1 }) // the page's first apply
})
