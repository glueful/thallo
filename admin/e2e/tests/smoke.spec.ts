import { test, expect } from '@playwright/test'
import { hooks, openDesignPage, stage } from '../helpers'

test('the Design page loads the fixture entry and its stage', async ({ page }) => {
  await openDesignPage(page)
  // Sixteen from the five-deep composition and its two root siblings, plus the grid container and
  // its three headings, which the Layout tab's proof works on (container-layout spec §5), plus the
  // call to action and its two buttons, whose button-only slot the refusal proof drops onto, plus the
  // two grids the outline proof measures: an empty one, and one with a heading spanning two tracks,
  // plus the hover preview's blocks: a Links block, a Container holding another, and a Social links
  // row with its two links.
  await expect(stage(page).locator('[data-thallo-block]')).toHaveCount(33)
  const h = await hooks(page)
  expect(h.currentSequence).toBe(0)
  expect(h.accepted).toEqual({ epoch: 'proof', revision: 1 }) // the page's first apply
})
