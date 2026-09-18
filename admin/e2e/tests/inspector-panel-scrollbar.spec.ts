import { test, expect } from '@playwright/test'
import { openDesignPage, selectOnStage } from '../helpers'

// The companion to inspector-panel.spec.ts, in its own file because launch options are per file.
// Windows and Linux, and macOS with "Always show scroll bars": the bar takes 15px of layout, so the
// content is that much narrower. Playwright hides scrollbars by default; this run does not.
test.use({
  launchOptions: { ignoreDefaultArgs: ['--hide-scrollbars'] },
  viewport: { width: 1440, height: 800 },
})

test('the narrower column still fits: no sideways scroll, nothing under the bar', async ({
  page,
}) => {
  await openDesignPage(page)
  await selectOnStage(page, 'grid00000001')
  await page
    .locator('[data-test="block-inspector-tabs"]')
    .getByRole('tab', { name: 'Layout', exact: true })
    .click()
  await page.locator('[data-test="layout-tab"]').waitFor()
  await page.locator('[data-test="style-field-layout.display"] [data-test="choice-grid"]').click()
  await page.addStyleTag({
    content: '[data-test="canvas-inspector"]::-webkit-scrollbar{width:15px}',
  })

  const panel = await page.evaluate(() => {
    const aside = document.querySelector('[data-test="canvas-inspector"]') as HTMLElement
    const inner = aside.getBoundingClientRect().left + aside.clientWidth
    const past = [...aside.querySelectorAll<HTMLElement>('*')].filter((el) => {
      const r = el.getBoundingClientRect()
      return r.width > 0 && r.height > 0 && r.right > inner + 0.5
    })
    return {
      bar: aside.offsetWidth - aside.clientWidth,
      overflows: aside.scrollHeight > aside.clientHeight,
      scrollWidth: aside.scrollWidth,
      clientWidth: aside.clientWidth,
      past: past.length,
    }
  })
  expect(panel.overflows).toBe(true) // the Layout tab is taller than the panel: the bar is real
  expect(panel.bar).toBe(15)
  expect(panel.scrollWidth).toBe(panel.clientWidth)
  expect(panel.past).toBe(0)
})
