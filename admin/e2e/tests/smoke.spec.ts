import { test, expect } from '@playwright/test'
import { hooks, openDesignPage, stage } from '../helpers'

test('the Design page loads the fixture entry and its stage', async ({ page }) => {
  await openDesignPage(page)
  await expect(stage(page).locator('[data-thallo-block]')).toHaveCount(15)
  const h = await hooks(page)
  expect(h.currentSequence).toBe(0)
  expect(h.accepted).toEqual({ epoch: 'proof', revision: 1 }) // the page's first apply
})
