import { test, expect, type Page } from '@playwright/test'
import { openRegionsStage, regionsStage, type RegionsRecorded } from '../helpers'

// The regions' styling, in a real browser on the stage: the header's Style on the Region tab opens
// with the controls the server declared and a choice is applied as `settings.style`; a header
// block's settings open by selecting it on the stage; the inspector — the Design page's scrolling
// column — never scrolls sideways nor lets its content under the scrollbar. These edits are not
// scenarios with a rendered stage, so the proofs assert what the page sent.

/** The header's settings or blocks in the last apply. */
async function lastHeader(recorded: RegionsRecorded, key: 'settings' | 'blocks') {
  return recorded.applies.at(-1)?.regions.header[key]
}

async function openRegionTab(page: Page): Promise<void> {
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Region', exact: true })
    .click()
}

test('the header’s Style on the Region tab edits the bar, and the apply carries what was chosen', async ({
  page,
}) => {
  const recorded = await openRegionsStage(page)
  await openRegionTab(page)
  const style = page.locator('[data-test="region-style-header"]')
  await expect(style.locator('[data-test="style-group-colors"]')).toBeVisible()
  await expect(style.locator('[data-test="style-group-typography"]')).toHaveCount(0)
  await expect(style.locator('[data-test="save-as-style-class"]')).toHaveCount(0)

  await style
    .locator('[data-test="style-field-colors.surface_opacity"] [data-test="choice-60"]')
    .click()
  await style.locator('[data-test="style-field-backdrop.blur"] [data-test="choice-lg"]').click()
  await style.locator('[data-test="style-field-border.sides"] [data-test="choice-bottom"]').click()

  await expect
    .poll(() => lastHeader(recorded, 'settings'))
    .toEqual({
      style: {
        colors: { surface_opacity: { type: 'choice', value: '60' } },
        backdrop: { blur: { type: 'choice', value: 'lg' } },
        border: { sides: { type: 'choice', value: 'bottom' } },
      },
    })
  await expect(page.locator('[data-test="regions-save"]')).toBeVisible()

  // The scrolling column: no sideways scroll, and its content kept clear of the edge it scrolls at.
  const panel = await page.evaluate(() => {
    const aside = document.querySelector('[data-test="canvas-inspector"]') as HTMLElement
    const edge = aside.getBoundingClientRect().right
    let rightmost = 0
    let who = ''
    for (const el of aside.querySelectorAll<HTMLElement>('*')) {
      const r = el.getBoundingClientRect()
      if (r.width > 0 && r.height > 0 && r.right > rightmost) {
        rightmost = r.right
        who = `${el.tagName.toLowerCase()}.${el.className.toString().split(' ').slice(0, 4).join('.')}`
      }
    }
    return {
      scrollWidth: aside.scrollWidth,
      clientWidth: aside.clientWidth,
      clearance: Math.round(edge - rightmost),
      who,
    }
  })
  expect(panel.scrollWidth).toBe(panel.clientWidth)
  // A 16px gutter, less the 2px a breakpoint chip's "declared here" dot overhangs it by.
  expect(panel.clearance, `${panel.who} reaches the scrollbar`).toBeGreaterThanOrEqual(16 - 2)
})

test('a header block’s settings open by selecting it on the stage, and an edit reaches the apply', async ({
  page,
}) => {
  const recorded = await openRegionsStage(page)
  await regionsStage(page)
    .locator('[data-thallo-block="e2ehdr000004"] > *')
    .first()
    .click({ position: { x: 4, y: 4 } })
  const panel = page.locator('[data-test="block-inspector"]')
  await expect(panel.locator('[data-test="block-inspector-title"]')).toHaveText('Button')
  // The Design page's four tabs, opening on Content.
  const tabs = panel.locator('[data-test="block-inspector-tabs"]').getByRole('tab')
  await expect(tabs).toHaveText(['Content', 'Layout', 'Style', 'Advanced'])
  await expect(tabs.first()).toHaveAttribute('aria-selected', 'true')

  await tabs.getByText('Style', { exact: true }).click()
  await panel.locator('[data-test="style-field-radius"] [data-test="token-radius.none"]').click()
  await expect
    .poll(async () => {
      const blocks = (await lastHeader(recorded, 'blocks')) as { id: string; settings: unknown }[]
      return blocks?.find((b) => b.id === 'e2ehdr000004')?.settings
    })
    .toEqual({ style: { radius: { type: 'token', value: 'radius.none' } } })
})

test('the Tablet and Mobile stages of the header and footer have their viewports', async ({
  page,
}) => {
  await openRegionsStage(page)
  const frame = page.locator('[data-test="regions-stage"]')
  await page.locator('[data-test="regions-viewport-tablet"]').click()
  await expect.poll(() => frame.evaluate((el) => el.clientWidth)).toBe(768)
  await page.locator('[data-test="regions-viewport-mobile"]').click()
  await expect.poll(() => frame.evaluate((el) => el.clientWidth)).toBe(390)
})
