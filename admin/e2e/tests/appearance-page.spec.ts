import { test, expect, type Page } from '@playwright/test'
import { openDesignPage } from '../helpers'

// Site › Appearance, in a real browser: reached from the Site group in the sidebar, it shows how the
// site looks — theme colours, design, logos — and a change is saved with ITS OWN keys only, so it
// can never write a stale copy of Settings › General's settings back. General, in turn, points here.
const SETTINGS = {
  site_name: 'Proofs',
  site_preview_url: '',
  default_locale: 'en',
  default_per_page: 20,
  max_per_page: 100,
  cache_ttl: 60,
  scheduler_enabled: true,
  webhooks_enabled: true,
  search_enabled: false,
  homepage_entry: 'homeentry001',
  site_logo: '',
  site_logo_dark: '',
  site_favicon: '',
  theme: 'default',
  theme_accent: 'blue',
  theme_neutral: 'slate',
  theme_radius: 'round',
  theme_font: 'sans',
  theme_background: 'plain',
  admin_url: '',
  listing_types: [],
}

/** The preview mint: records what look was asked for, and answers with a page to frame. */
async function routePreview(page: Page): Promise<Record<string, unknown>[]> {
  const looks: Record<string, unknown>[] = []
  await page.route('**/v1/admin/entries/*/preview/*', (route) => {
    looks.push(route.request().postDataJSON() as Record<string, unknown>)
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { token: 't', theme_url: `/_preview/look${looks.length}` },
      }),
    })
  })
  return looks
}

async function routeSettings(page: Page): Promise<Record<string, unknown>[]> {
  const saves: Record<string, unknown>[] = []
  await page.route('**/v1/admin/settings/general', (route) => {
    if (route.request().method() === 'PUT') {
      saves.push(route.request().postDataJSON() as Record<string, unknown>)
    }
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { settings: SETTINGS } }),
    })
  })
  return saves
}

test('Appearance is in the Site group, and saves only its own settings', async ({ page }) => {
  await openDesignPage(page) // signs in
  const saves = await routeSettings(page)
  const looks = await routePreview(page)
  await page.goto('/admin/appearance')
  await expect(page.locator('[data-test="theme-colors-card"]')).toBeVisible({ timeout: 20_000 })
  for (const card of ['theme-design-card', 'logos-card']) {
    await expect(page.locator(`[data-test="${card}"]`)).toBeVisible()
  }
  // The sidebar lists it in the Site group, first among the pages that shape the site. (The
  // harness opens with the sidebar collapsed to icons, and the group folded.)
  await page.getByRole('button', { name: 'Expand sidebar' }).click()
  const appearanceLink = page.getByRole('link', { name: 'Appearance', exact: true })
  if (!(await appearanceLink.isVisible())) {
    await page.getByRole('button', { name: 'Site', exact: true }).click()
  }
  await expect(appearanceLink).toBeVisible()
  const siteLinks = await page
    .locator('a[href="/admin/appearance"], a[href="/admin/regions"], a[href="/admin/templates"]')
    .evaluateAll((links) => links.map((a) => a.getAttribute('href')))
  expect(siteLinks[0]).toBe('/admin/appearance')

  // Choose another corner style and save.
  await page.locator('[data-test="theme-radius"]').click()
  await page.getByRole('option', { name: /^Sharp/ }).click()
  // The preview frames the homepage with the PENDING look: asked for again once the choice settled,
  // with the new corners, and nothing saved yet.
  await expect.poll(() => looks.at(-1)?.radius).toBe('sharp')
  expect(looks.at(-1)).toEqual({
    accent: 'blue',
    neutral: 'slate',
    radius: 'sharp',
    font: 'sans',
    background: 'plain',
  })
  await expect(page.locator('[data-test="appearance-preview-frame"]')).toHaveAttribute(
    'src',
    `/_preview/look${looks.length}`,
  )
  expect(saves).toEqual([])

  await page.locator('[data-test="appearance-save"]').click()
  await expect.poll(() => saves.length).toBe(1)
  expect(saves[0]).toEqual({
    theme: 'default',
    theme_accent: 'blue',
    theme_neutral: 'slate',
    theme_radius: 'sharp',
    theme_font: 'sans',
    theme_background: 'plain',
    site_logo: '',
    site_logo_dark: '',
    site_favicon: '',
  })
})

test('Settings › General no longer holds the appearance cards, and links to where they went', async ({
  page,
}) => {
  await openDesignPage(page)
  await routeSettings(page)
  await page.goto('/admin/settings/general')
  const pointer = page.locator('[data-test="appearance-pointer"]')
  await expect(pointer).toBeVisible({ timeout: 20_000 })
  await expect(page.locator('[data-test="theme-colors-card"]')).toHaveCount(0)
  await pointer.getByRole('link').click()
  await expect(page).toHaveURL(/\/admin\/appearance$/)
  await expect(page.locator('[data-test="theme-colors-card"]')).toBeVisible()
})
