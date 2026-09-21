import { test, expect } from '@playwright/test'
import { openDesignPage, stage } from '../helpers'

// The Tablet and Mobile stages stand for a device: the page inside must get that device's
// viewport. The Tablet breakpoint begins at 768px, so a frame that is 768px wide on the OUTSIDE
// but draws a border inside that width hands the page 766px — and the page renders its phone
// layout while the editor says Tablet. Only a real browser lays the frame out.
test('the Tablet stage gives the page a tablet’s viewport, and Mobile a phone’s', async ({
  page,
}) => {
  await openDesignPage(page)
  const frame = page.locator('[data-test="canvas-iframe"]')
  const inside = () =>
    stage(page)
      .locator('body')
      .evaluate(() => ({
        width: window.innerWidth,
        tablet: window.matchMedia('(min-width: 768px)').matches,
      }))

  await page.locator('[data-test="canvas-viewport-tablet"]').click()
  await expect.poll(() => frame.evaluate((el) => el.clientWidth)).toBe(768)
  expect(await inside()).toEqual({ width: 768, tablet: true })

  await page.locator('[data-test="canvas-viewport-mobile"]').click()
  await expect.poll(() => frame.evaluate((el) => el.clientWidth)).toBe(390)
  expect(await inside()).toEqual({ width: 390, tablet: false })
})
