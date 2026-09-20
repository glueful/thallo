import { test, expect, type Page } from '@playwright/test'
import { openDesignPage } from '../helpers'

// The Regions page's Style tab, in a real browser: the header's Style tab opens with the controls
// the server declared, a choice reaches the live preview's request as `settings.style`, and the
// panel — the same scrolling column as the Design page's inspector — never scrolls sideways nor
// lets its content under the scrollbar.
const CAPS = ['spacing', 'shadow', 'radius', 'colors', 'border', 'backdrop']
const BUTTON = {
  id: 'hdrbutton001',
  type: 'button',
  data: { label: 'Contact', url: '/contact' },
  settings: {},
}
const region = (slug: string, settings: Record<string, unknown>) => ({
  slug,
  blocks: slug === 'header' ? [BUTTON] : [],
  settings,
  palette: ['logo', 'navigation', 'button'],
  settings_keys: slug === 'header' ? ['sticky', 'width', 'style'] : ['width', 'style'],
  style_capabilities: CAPS,
})

async function openRegionsPage(page: Page): Promise<Record<string, unknown>[]> {
  // Signs in and lands on the Design page; the regions routes are registered after the world's,
  // so they win.
  await openDesignPage(page)
  const previews: Record<string, unknown>[] = []
  await page.route('**/v1/admin/regions', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          regions: [
            region('header', { sticky: true, width: 'contained' }),
            region('footer', { width: 'contained' }),
          ],
        },
      }),
    }),
  )
  await page.route('**/v1/admin/regions/preview', (route) => {
    previews.push(route.request().postDataJSON() as Record<string, unknown>)
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { html: '<!doctype html><html><body><header>Bar</header></body></html>' },
      }),
    })
  })
  await page.goto('/admin/regions')
  await page.locator('[data-test="regions-inspector"]').waitFor({ timeout: 20_000 })
  return previews
}

test('the header’s Style tab edits the bar, and the preview is asked for what was chosen', async ({
  page,
}) => {
  const previews = await openRegionsPage(page)
  await page
    .locator('[data-test="region-header-tabs"]')
    .getByRole('tab', { name: 'Style', exact: true })
    .click()
  const style = page.locator('[data-test="region-style-header"]')
  await expect(style.locator('[data-test="style-group-colors"]')).toBeVisible()
  await expect(style.locator('[data-test="style-group-typography"]')).toHaveCount(0)
  await expect(style.locator('[data-test="save-as-style-class"]')).toHaveCount(0)

  await style
    .locator('[data-test="style-field-colors.surface_opacity"] [data-test="choice-60"]')
    .click()
  await style.locator('[data-test="style-field-backdrop.blur"] [data-test="choice-lg"]').click()
  await style.locator('[data-test="style-field-border.sides"] [data-test="choice-bottom"]').click()

  // The preview is debounced; the last request carries all three, beside the settings it had.
  await expect
    .poll(
      () => (previews.at(-1)?.regions as Record<string, { settings: unknown }>)?.header?.settings,
    )
    .toEqual({
      sticky: true,
      width: 'contained',
      style: {
        colors: { surface_opacity: { type: 'choice', value: '60' } },
        backdrop: { blur: { type: 'choice', value: 'lg' } },
        border: { sides: { type: 'choice', value: 'bottom' } },
      },
    })
  await expect(page.locator('[data-test="save-region-header"]')).toBeVisible()

  // The scrolling column: no sideways scroll, and its content kept clear of the edge it scrolls at.
  const panel = await page.evaluate(() => {
    const aside = document.querySelector('[data-test="regions-inspector"]') as HTMLElement
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
  // A 16px gutter, less the 3px the Save button's "unsaved" dot overhangs its button by.
  expect(panel.clearance, `${panel.who} reaches the scrollbar`).toBeGreaterThanOrEqual(16 - 3)
})

test('a header block’s settings open from its card, and an edit reaches the preview on that block', async ({
  page,
}) => {
  const previews = await openRegionsPage(page)
  await page.locator(`[data-test="block-settings-${BUTTON.id}"]`).click()
  const panel = page.locator('[data-test="region-block-settings-header"]')
  await expect(panel.locator('[data-test="block-inspector-title"]')).toHaveText('Button')
  // Layout, Style and Advanced: the card is the block's content form already.
  const tabs = panel.locator('[data-test="block-inspector-tabs"]').getByRole('tab')
  await expect(tabs).toHaveText(['Layout', 'Style', 'Advanced'])
  await expect(page.locator('[data-test="region-header-tabs"]')).toBeHidden()

  await panel.locator('[data-test="style-field-radius"] [data-test="token-radius.none"]').click()
  await expect
    .poll(() => {
      const header = (previews.at(-1)?.regions as Record<string, { blocks: unknown[] }>)?.header
      return (header?.blocks[0] as { settings?: unknown } | undefined)?.settings
    })
    .toEqual({ style: { radius: { type: 'token', value: 'radius.none' } } })

  await panel.locator('[data-test="region-block-settings-back"]').click()
  await expect(page.locator('[data-test="region-header-tabs"]')).toBeVisible()
  await expect(page.locator(`[data-test="block-settings-${BUTTON.id}"]`)).toBeVisible()
})
