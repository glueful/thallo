import { test, expect, type Page } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
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
  theme_font_body: '',
  theme_font_display: '',
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
    theme_font_body: '',
    theme_font_display: '',
    theme_background: 'plain',
    site_logo: '',
    site_logo_dark: '',
    site_favicon: '',
  })
})

test('the theme gallery shows each theme, and choosing one previews it before it is saved', async ({
  page,
}) => {
  await openDesignPage(page)
  const saves = await routeSettings(page)
  const looks = await routePreview(page)
  const card = (name: string, more: Record<string, unknown>) => ({
    name,
    title: name,
    version: null,
    description: null,
    author: null,
    tags: [],
    colors: null,
    screenshot_url: null,
    ...more,
  })
  await page.route('**/v1/admin/render/themes', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          themes: ['default', 'aurora'],
          active: 'default',
          cards: [
            card('default', {
              title: 'Default',
              version: '1.0.0',
              author: 'Thallo',
              screenshot_url: '/_thallo/theme-screenshot/default?v=1',
            }),
            card('aurora', {
              colors: { background: '#0b1020', text: '#ffffff', accent: '#7c3aed' },
            }),
          ],
        },
      }),
    }),
  )
  // The REAL screenshot the default theme ships, served as the site serves it.
  await page.route('**/_thallo/theme-screenshot/default*', (route) =>
    route.fulfill({
      contentType: 'image/jpeg',
      body: readFileSync(
        resolve(__dirname, '../../../packages/thallo-render/themes/default/screenshot.jpg'),
      ),
    }),
  )
  await page.goto('/admin/appearance')

  const live = page.getByRole('radio', { name: /Default/ })
  await expect(live).toBeVisible({ timeout: 20_000 })
  await expect(live).toBeChecked()
  await expect(live.locator('[data-test="theme-live"]')).toBeVisible()
  // The screenshot really loaded and fills its 4:3 frame.
  const shot = live.locator('img')
  await expect.poll(() => shot.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBe(1200)
  const frame = await shot.evaluate((img) => {
    const r = img.getBoundingClientRect()
    return r.width / r.height
  })
  expect(frame).toBeCloseTo(4 / 3, 1)

  // A theme with no screenshot is drawn in its own colours, never blank.
  const aurora = page.getByRole('radio', { name: /aurora/ })
  const drawn = aurora.locator('[data-test="theme-thumbnail-drawn"]')
  await expect(drawn).toBeVisible()
  expect(await drawn.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(11, 16, 32)')

  // Two cards side by side in the settings column, neither overflowing it.
  const [a, b] = await Promise.all([live.boundingBox(), aurora.boundingBox()])
  expect(a!.y).toBeCloseTo(b!.y, 0)
  expect(b!.x).toBeGreaterThan(a!.x + a!.width - 1)

  // Choosing it is only a choice: the preview is asked for THAT theme, and nothing is saved.
  await aurora.click()
  await expect(aurora).toBeChecked()
  await expect(aurora.locator('[data-test="theme-pending"]')).toBeVisible()
  await expect.poll(() => looks.at(-1)?.theme).toBe('aurora')
  expect(saves).toEqual([])
  // The keyboard moves the choice back, as in any radio group.
  await page.keyboard.press('ArrowLeft')
  await expect(live).toBeChecked()
  await expect(live).toBeFocused()

  await aurora.click()
  await page.locator('[data-test="appearance-save"]').click()
  await expect.poll(() => saves.length).toBe(1)
  expect(saves[0]).toMatchObject({ theme: 'aurora' })
})

test('a brand colour says how it will read, and the site’s own font shows itself', async ({
  page,
}) => {
  await openDesignPage(page)
  const saves = await routeSettings(page)
  const looks = await routePreview(page)
  // The upload is the framework's blob route; the font it "stores" is the theme's real woff2,
  // served back from the media URL as the site would serve it.
  const FONT = readFileSync(
    resolve(
      __dirname,
      '../../../packages/thallo-render/themes/default/assets/fonts/figtree-roman-latin.woff2',
    ),
  )
  const uploads: string[] = []
  await page.route('**/v1/blobs', (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    uploads.push(route.request().headers()['content-type'] ?? '')
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { uuid: 'fontbody0001', blob_uuid: 'fontbody0001' },
      }),
    })
  })
  await page.route('**/v1/blobs/fontbody0001*', (route) =>
    route.fulfill({ contentType: 'font/woff2', body: FONT }),
  )
  await page.goto('/admin/appearance')
  await expect(page.locator('[data-test="theme-colors-card"]')).toBeVisible({ timeout: 20_000 })

  // ── The brand colour ──
  await page.locator('[data-test="theme-accent"]').click()
  await page.getByRole('option', { name: 'Brand colour…' }).click()
  const hex = page.getByLabel('Brand colour, as a hex')
  await expect(hex).toHaveValue('#3b82f6') // starts from the colour the site has now
  await hex.fill('#facc15')
  const report = page.locator('[data-test="brand-report"]')
  await expect(report).toContainText('black text')
  await expect(page.locator('[data-test="brand-report-text"]')).toHaveAttribute(
    'data-level',
    'poor',
  )
  // The sample IS the button a visitor gets: the colour, with the ink the site will use.
  const sample = page.locator('[data-test="brand-sample"]')
  expect(await sample.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
    'rgb(250, 204, 21)',
  )
  expect(await sample.evaluate((el) => getComputedStyle(el).color)).toBe('rgb(0, 0, 0)')
  await hex.fill('#1e3a')
  await expect(page.locator('[data-test="brand-hex-error"]')).toBeVisible()
  await hex.fill('#1e3a8a')
  await expect(report).toContainText('white text')
  await expect.poll(() => looks.at(-1)?.accent).toBe('#1e3a8a') // previewed, not saved
  expect(saves).toEqual([])

  // ── The site's own font ──
  await page.locator('[data-test="theme-font"]').click()
  await page.getByRole('option', { name: /^Custom/ }).click()
  await expect(page.locator('[data-test="custom-fonts"]')).toBeVisible()
  await page
    .locator('[data-test="font-upload-body"]')
    .setInputFiles({ name: 'Brand.woff2', mimeType: 'font/woff2', buffer: FONT })
  const specimen = page.locator('[data-test="font-specimen-body"]')
  await expect(specimen).toBeVisible()
  expect(uploads).toHaveLength(1)
  // The specimen is really set in the uploaded face: the browser loaded it from the media URL.
  await expect
    .poll(() => page.evaluate(() => document.fonts.check('16px "thallo-admin-face-body"')))
    .toBe(true)
  expect(await specimen.evaluate((el) => getComputedStyle(el).fontFamily)).toContain(
    'thallo-admin-face-body',
  )
  await expect.poll(() => looks.at(-1)?.font_body).toBe('fontbody0001')
  expect(looks.at(-1)).toMatchObject({ font: 'custom', font_display: 'none' })

  await page.locator('[data-test="appearance-save"]').click()
  await expect.poll(() => saves.length).toBe(1)
  expect(saves[0]).toMatchObject({
    theme_accent: '#1e3a8a',
    theme_font: 'custom',
    theme_font_body: 'fontbody0001',
    theme_font_display: '',
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
