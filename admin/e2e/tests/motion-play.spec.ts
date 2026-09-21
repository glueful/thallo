import { test, expect } from '@playwright/test'
import { openDesignPage, selectOnStage, stage } from '../helpers'

// A block with an entrance hides until it scrolls into view — on the live page. On the stage it
// must not: a hidden block cannot be edited. So the stage holds motion still, and the Style tab's
// Play replays the selected block once. Only a real browser can show the replay happening: it is
// a transition, which starts only if the starting state was painted before it was released.
test('the stage shows an entering block at rest, and Play replays its entrance', async ({
  page,
}) => {
  await openDesignPage(page)
  const heading = stage(page).locator('[data-thallo-block="gridhead0003"] > h2')
  await expect(heading).toHaveClass(/t-enter-fade-up/)
  const opacity = () => heading.evaluate((el) => Number(getComputedStyle(el).opacity))
  const shift = () => heading.evaluate((el) => new DOMMatrix(getComputedStyle(el).transform).m42)
  expect(await opacity()).toBe(1)
  expect(await shift()).toBe(0)

  await selectOnStage(page, 'gridhead0003')
  await page
    .locator('[data-test="block-inspector-tabs"]')
    .getByRole('tab', { name: 'Style', exact: true })
    .click()
  await page.locator('[data-test="motion-play"]').click()

  // Mid-flight: part transparent and still below its place. The entrance is the slow one (1s).
  await expect.poll(opacity, { timeout: 900, intervals: [20] }).toBeLessThan(1)
  expect(await shift()).toBeGreaterThan(0)
  // And it comes to rest where it was.
  await expect.poll(opacity, { timeout: 3000 }).toBe(1)
  expect(await shift()).toBe(0)
})
